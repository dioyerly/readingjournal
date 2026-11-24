<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';
/* Cliente y sincronización con Mercado Pago (Suscripciones / preapproval).
   Regla de oro: nunca confiamos en el cuerpo del webhook. Solo usamos el id y
   pedimos el estado real a la API de MP con nuestro access token. Por eso
   repetir o desordenar notificaciones es inofensivo (idempotente).          */

class MpNoEncontrado extends RuntimeException {}

function mp_id_valido(string $id): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id); }

function mp_get(string $ruta): array {
  $tok = (string)cfg('MP_ACCESS_TOKEN');
  if ($tok === '') throw new RuntimeException('Falta MP_ACCESS_TOKEN en config.php');
  $url = rtrim((string)cfg('MP_API_BASE', 'https://api.mercadopago.com'), '/') . $ruta;
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Accept: application/json'], CURLOPT_FOLLOWLOCATION => false]);
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($r === false) throw new RuntimeException('No se pudo contactar a Mercado Pago: ' . $err);
  if ($code === 404) throw new MpNoEncontrado($ruta);
  if ($code < 200 || $code >= 300) throw new RuntimeException("Mercado Pago respondió $code en $ruta");
  $j = json_decode($r, true);
  if (!is_array($j)) throw new RuntimeException('Respuesta de Mercado Pago ilegible');
  return $j;
}

function a_centavos($v): int { return (int)round(((float)$v) * 100); }

/* x-signature: "ts=...,v1=..."; manifest "id:{data.id};request-id:{x-request-id};ts:{ts};" */
function mp_firma_valida(string $dataId, string $xSignature, string $xRequestId, string $secret): bool {
  if ($secret === '' || $xSignature === '') return false;
  $ts = $v1 = '';
  foreach (explode(',', $xSignature) as $p) {
    $kv = explode('=', trim($p), 2); if (count($kv) !== 2) continue;
    if ($kv[0] === 'ts') $ts = $kv[1]; elseif ($kv[0] === 'v1') $v1 = $kv[1];
  }
  if ($ts === '' || $v1 === '') return false;
  foreach (array_unique([$dataId, strtolower($dataId)]) as $id) {
    $manifest = "id:$id;request-id:$xRequestId;ts:$ts;";
    if (hash_equals(hash_hmac('sha256', $manifest, $secret), strtolower($v1))) return true;
  }
  return false;
}

/* Trae la suscripción de MP y la guarda. Devuelve la fila local. */
function mp_sync_preapproval(PDO $pdo, string $id): array {
  if (!mp_id_valido($id)) throw new InvalidArgumentException('id inválido');
  $p = mp_get('/preapproval/' . rawurlencode($id));
  if ((string)($p['id'] ?? '') !== $id) throw new RuntimeException('La API devolvió otra suscripción');
  $ar = is_array($p['auto_recurring'] ?? null) ? $p['auto_recurring'] : [];
  $n = max(1, (int)($ar['frequency'] ?? 1)); $tipo = (string)($ar['frequency_type'] ?? 'months');
  $dias = $tipo === 'days' ? $n : $n * 30;
  $email = normalizar_email((string)($p['payer_email'] ?? ''));
  if (!email_valido($email)) $email = '';
  $estado = in_array(($p['status'] ?? ''), ['pending', 'authorized', 'paused', 'cancelled'], true) ? (string)$p['status'] : 'pending';
  $datos = [$email, $estado, mb_substr((string)($p['reason'] ?? ''), 0, 255), a_centavos($ar['transaction_amount'] ?? 0),
    mb_substr((string)($ar['currency_id'] ?? 'ARS'), 0, 8), $dias, fecha_mp($p['next_payment_date'] ?? null), fecha_mp($p['date_created'] ?? null), fecha_mp($p['last_modified'] ?? null)];
  return guardar_suscripcion($pdo, $id, $datos);
}

/* Guarda (crea o actualiza) una suscripción de cualquier medio de pago. $datos = [email, estado, motivo, monto_cent, moneda, periodo_dias, proximo_cobro, creada_en_origen, modificada_en_origen]. */
function guardar_suscripcion(PDO $pdo, string $id, array $datos): array {
  $now = ahora();
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('SELECT * FROM suscripciones WHERE preapproval_id = ? FOR UPDATE'); $st->execute([$id]); $cur = $st->fetch();
    if ($cur) {
      // el email del pagador solo se pisa si MP lo informa
      if ($datos[0] === '') $datos[0] = (string)$cur['payer_email'];
      $pdo->prepare('UPDATE suscripciones SET payer_email=?, estado=?, motivo=?, monto_cent=?, moneda=?, periodo_dias=?, proximo_cobro=COALESCE(?, proximo_cobro), mp_creada=?, mp_modificada=?, actualizada=? WHERE preapproval_id=?')
          ->execute(array_merge($datos, [$now, $id]));
    } else {
      $pdo->prepare('INSERT INTO suscripciones (payer_email, estado, motivo, monto_cent, moneda, periodo_dias, proximo_cobro, mp_creada, mp_modificada, creada, actualizada, preapproval_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute(array_merge($datos, [$now, $now, $id]));
    }
    $st = $pdo->prepare('SELECT * FROM suscripciones WHERE preapproval_id = ?'); $st->execute([$id]); $fila = $st->fetch();
    // ¿ya existe una cuenta con ese email? (re-suscripción) -> la vinculamos sola
    if ($fila['cuenta_id'] === null && $fila['payer_email'] !== '') {
      $q = $pdo->prepare('SELECT id FROM cuentas WHERE email = ?'); $q->execute([$fila['payer_email']]); $cid = $q->fetchColumn();
      if ($cid) { $pdo->prepare('UPDATE suscripciones SET cuenta_id = ? WHERE id = ? AND cuenta_id IS NULL')->execute([$cid, $fila['id']]); $fila['cuenta_id'] = (int)$cid; }
    }
    $pdo->commit();
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
  $fila['_nueva'] = !$cur;
  $fila['_estado_anterior'] = $cur['estado'] ?? null;
  return $fila;
}

/* Trae una cuota (authorized_payment) y la guarda; refresca también su suscripción. */
function mp_sync_cuota(PDO $pdo, string $id): array {
  if (!mp_id_valido($id)) throw new InvalidArgumentException('id inválido');
  $c = mp_get('/authorized_payments/' . rawurlencode($id));
  if ((string)($c['id'] ?? '') !== $id) throw new RuntimeException('La API devolvió otra cuota');
  $pre = (string)($c['preapproval_id'] ?? '');
  if (!mp_id_valido($pre)) throw new RuntimeException('La cuota no informa su suscripción');
  $pago = is_array($c['payment'] ?? null) ? $c['payment'] : [];
  $estado = (string)($c['status'] ?? '');
  if (($pago['status'] ?? '') === 'approved') $estado = 'processed';
  $estado = in_array($estado, ['scheduled', 'processed', 'recycling', 'cancelled'], true) ? $estado : 'scheduled';
  $sub = mp_sync_preapproval($pdo, $pre);   // asegura que la suscripción exista localmente
  $now = ahora();
  $v = [$pre, $estado, mb_substr((string)($pago['status_detail'] ?? $pago['status'] ?? ''), 0, 80), a_centavos($c['transaction_amount'] ?? 0),
    mb_substr((string)($c['currency_id'] ?? 'ARS'), 0, 8), (int)($c['retry_attempt'] ?? 0), fecha_mp($c['debit_date'] ?? null)];
  $st = $pdo->prepare('SELECT id FROM pagos WHERE mp_id = ?'); $st->execute([$id]);
  if ($st->fetchColumn()) {
    $pdo->prepare('UPDATE pagos SET preapproval_id=?, estado=?, detalle=?, monto_cent=?, moneda=?, reintento=?, fecha_debito=?, actualizada=? WHERE mp_id=?')->execute(array_merge($v, [$now, $id]));
  } else {
    $pdo->prepare('INSERT INTO pagos (preapproval_id, estado, detalle, monto_cent, moneda, reintento, fecha_debito, creada, actualizada, mp_id) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($v, [$now, $now, $id]));
  }
  return ['sub' => $sub, 'estado' => $estado];
}

/* Mail de bienvenida con el link para crear las credenciales (si todavía no hay cuenta). */
function mp_avisar_alta(array $sub): void {
  if (!empty($sub['cuenta_id']) || ($sub['estado'] ?? '') !== 'authorized' || empty($sub['payer_email'])) return;
  $url = rtrim((string)cfg('APP_URL'), '/') . '/alta.php?pid=' . rawurlencode($sub['preapproval_id']);
  enviar_mail($sub['payer_email'], 'Bienvenida a Bookwat: creá tu acceso',
    "¡Gracias por suscribirte!\n\nPara crear tu usuario y tu clave entrá acá:\n$url\n\nSi ya lo hiciste, ignorá este mensaje.\n");
}

/* Cancela una suscripción en Mercado Pago y la sincroniza. */
function mp_cancelar_preapproval(PDO $pdo, string $id): array {
  if (!mp_id_valido($id)) throw new InvalidArgumentException('id inválido');
  // Suscripciones creadas a mano desde el panel: no existen en Mercado Pago, se dan de baja solo acá.
  if (strpos($id, 'manual-') === 0 || strpos($id, 'test-') === 0) {
    $pdo->prepare('UPDATE suscripciones SET estado = "cancelled", actualizada = ? WHERE preapproval_id = ?')->execute([ahora(), $id]);
    $st = $pdo->prepare('SELECT * FROM suscripciones WHERE preapproval_id = ?'); $st->execute([$id]);
    return $st->fetch() ?: [];
  }
  $tok = (string)cfg('MP_ACCESS_TOKEN');
  if ($tok === '') throw new RuntimeException('Falta MP_ACCESS_TOKEN en config.php');
  $url = rtrim((string)cfg('MP_API_BASE', 'https://api.mercadopago.com'), '/') . '/preapproval/' . rawurlencode($id);
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => json_encode(['status' => 'cancelled']),
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_FOLLOWLOCATION => false]);
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($r === false) throw new RuntimeException('No se pudo contactar a Mercado Pago: ' . $err);
  if ($code < 200 || $code >= 300) throw new RuntimeException("Mercado Pago respondió $code al cancelar");
  $j = json_decode($r, true);
  if (!is_array($j)) throw new RuntimeException('Respuesta de Mercado Pago ilegible');
  // Sincronizar el estado actualizado
  return mp_sync_preapproval($pdo, $id);
}