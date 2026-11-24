<?php
declare(strict_types=1);
require_once __DIR__ . '/mp.php';
/* Suscripciones con PayPal (API Subscriptions v1) para clientes del exterior. Se cobra en dólares.
   Igual que con Mercado Pago: nunca confiamos en el cuerpo del webhook. Solo usamos el id y
   le pedimos a PayPal el estado real de la suscripción y sus cobros. Todo es idempotente.
   Las suscripciones de PayPal se guardan en las MISMAS tablas (suscripciones / pagos):
   su id empieza con "I-" (los de Mercado Pago no), así se distinguen sin cambiar la base. */

function es_paypal(string $id): bool { return (bool)preg_match('/^I-[A-Za-z0-9]{6,60}$/', $id); }
function pp_configurado(): bool { return (string)cfg('PAYPAL_CLIENT_ID') !== '' && (string)cfg('PAYPAL_SECRET') !== ''; }
function pp_base(): string {
  $b = (string)cfg('PAYPAL_API_BASE', '');
  if ($b !== '') return rtrim($b, '/');
  return cfg('PAYPAL_MODE', 'live') === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
}

function pp_token(): string {
  static $tok = null, $exp = 0;
  if ($tok !== null && time() < $exp) return $tok;
  $ch = curl_init(pp_base() . '/v1/oauth2/token');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
    CURLOPT_USERPWD => cfg('PAYPAL_CLIENT_ID') . ':' . cfg('PAYPAL_SECRET'), CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['Accept: application/json']]);
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($r === false) throw new RuntimeException('No se pudo contactar a PayPal: ' . $err);
  $j = json_decode((string)$r, true);
  if ($code >= 400 || empty($j['access_token'])) { error_log("PayPal token -> $code"); throw new RuntimeException("PayPal rechazó las claves ($code). Revisá PAYPAL_CLIENT_ID y PAYPAL_SECRET en config.php."); }
  $exp = time() + max(60, (int)($j['expires_in'] ?? 300) - 60);
  return $tok = (string)$j['access_token'];
}

function pp_req(string $metodo, string $ruta, ?array $cuerpo = null): array {
  $ch = curl_init(pp_base() . $ruta);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . pp_token(), 'Content-Type: application/json', 'Accept: application/json', 'Prefer: return=representation']]);
  if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($r === false) throw new RuntimeException('No se pudo contactar a PayPal: ' . $err);
  if ($code === 404) throw new MpNoEncontrado($ruta);
  $j = json_decode((string)$r, true);
  if ($code < 200 || $code >= 300) {
    error_log("PayPal $metodo $ruta -> $code $r");
    $det = is_array($j) ? (string)($j['details'][0]['issue'] ?? $j['name'] ?? '') : '';
    throw new RuntimeException("PayPal respondió $code" . ($det !== '' ? ": $det" : ''));
  }
  return is_array($j) ? $j : [];
}

/* Estados de PayPal -> los de la app (pending | authorized | paused | cancelled) */
function pp_estado(string $s): string {
  return ['ACTIVE' => 'authorized', 'SUSPENDED' => 'paused', 'CANCELLED' => 'cancelled', 'EXPIRED' => 'cancelled'][$s] ?? 'pending';
}

function pp_aviso_dueno(string $asunto, string $texto): void {
  $to = normalizar_email((string)cfg('ADMIN_EMAIL'));
  if (email_valido($to)) enviar_mail($to, $asunto, $texto . "\n\nPanel: " . rtrim((string)cfg('APP_URL'), '/') . "/admin.php\n");
}

/* Trae la suscripción de PayPal, su plan y sus cobros; guarda todo. Devuelve la fila local (como mp_sync_preapproval). */
function pp_sync_suscripcion(PDO $pdo, string $id): array {
  if (!es_paypal($id)) throw new InvalidArgumentException('id inválido');
  $p = pp_req('GET', '/v1/billing/subscriptions/' . rawurlencode($id));
  if ((string)($p['id'] ?? '') !== $id) throw new RuntimeException('La API devolvió otra suscripción');
  // monto y período salen del plan (la suscripción solo informa el último cobro)
  $monto = 0; $mon = 'USD'; $dias = 30; $motivo = '';
  try {
    $plan = pp_req('GET', '/v1/billing/plans/' . rawurlencode((string)($p['plan_id'] ?? '')));
    $motivo = mb_substr((string)($plan['name'] ?? ''), 0, 255);
    foreach (($plan['billing_cycles'] ?? []) as $c) {
      if (($c['tenure_type'] ?? '') !== 'REGULAR') continue;
      $f = $c['pricing_scheme']['fixed_price'] ?? [];
      $monto = a_centavos($f['value'] ?? 0); $mon = mb_substr((string)($f['currency_code'] ?? 'USD'), 0, 8);
      $n = max(1, (int)($c['frequency']['interval_count'] ?? 1)); $u = (string)($c['frequency']['interval_unit'] ?? 'MONTH');
      $dias = $u === 'DAY' ? $n : ($u === 'WEEK' ? 7 * $n : ($u === 'YEAR' ? 365 * $n : 30 * $n));
      break;
    }
  } catch (Throwable $e) { error_log('pp plan: ' . $e->getMessage()); }
  $email = normalizar_email((string)($p['subscriber']['email_address'] ?? ''));
  if (!email_valido($email)) $email = '';
  $estado = pp_estado((string)($p['status'] ?? ''));
  $bi = is_array($p['billing_info'] ?? null) ? $p['billing_info'] : [];
  $datos = [$email, $estado, $motivo, $monto, $mon, $dias, fecha_mp($bi['next_billing_time'] ?? null), fecha_mp($p['create_time'] ?? $p['start_time'] ?? null), fecha_mp($p['status_update_time'] ?? $p['update_time'] ?? null)];
  $fila = guardar_suscripcion($pdo, $id, $datos);
  pp_sync_cobros($pdo, $fila, $p);
  if ($fila['_estado_anterior'] !== null && $fila['_estado_anterior'] !== $estado && in_array($estado, ['cancelled', 'paused'], true))
    pp_aviso_dueno('Bookwat: suscripción ' . ($estado === 'cancelled' ? 'cancelada' : 'suspendida') . ' (PayPal)', "Cliente: " . ($fila['payer_email'] ?: '—') . "\nSuscripción: $id\nEstado: $estado");
  return $fila;
}

/* Cobros de la suscripción -> tabla pagos. Los fallidos avisan al dueño una sola vez. */
function pp_sync_cobros(PDO $pdo, array $sub, array $p): void {
  $desde = gmdate('Y-m-d\TH:i:s.000\Z', strtotime((string)($p['start_time'] ?? $p['create_time'] ?? 'now')) - 86400);
  $hasta = gmdate('Y-m-d\TH:i:s.000\Z', time() + 86400);
  try { $t = pp_req('GET', '/v1/billing/subscriptions/' . rawurlencode($sub['preapproval_id']) . '/transactions?start_time=' . rawurlencode($desde) . '&end_time=' . rawurlencode($hasta)); }
  catch (Throwable $e) { error_log('pp cobros: ' . $e->getMessage()); return; }
  $now = ahora();
  foreach (($t['transactions'] ?? []) as $x) {
    $tid = (string)($x['id'] ?? ''); if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', $tid)) continue;
    $st = (string)($x['status'] ?? '');
    $estado = $st === 'COMPLETED' ? 'processed' : (in_array($st, ['DECLINED', 'FAILED'], true) ? 'recycling' : (in_array($st, ['REFUNDED', 'PARTIALLY_REFUNDED'], true) ? 'cancelled' : 'scheduled'));
    $g = $x['amount_with_breakdown']['gross_amount'] ?? [];
    $v = [$sub['preapproval_id'], $estado, mb_substr(strtolower($st), 0, 80), a_centavos($g['value'] ?? 0), mb_substr((string)($g['currency_code'] ?? 'USD'), 0, 8), 0, fecha_mp($x['time'] ?? null)];
    $q = $pdo->prepare('SELECT estado FROM pagos WHERE mp_id = ?'); $q->execute([$tid]); $prev = $q->fetchColumn();
    if ($prev !== false) $pdo->prepare('UPDATE pagos SET preapproval_id=?, estado=?, detalle=?, monto_cent=?, moneda=?, reintento=?, fecha_debito=?, actualizada=? WHERE mp_id=?')->execute(array_merge($v, [$now, $tid]));
    else $pdo->prepare('INSERT INTO pagos (preapproval_id, estado, detalle, monto_cent, moneda, reintento, fecha_debito, creada, actualizada, mp_id) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($v, [$now, $now, $tid]));
    if ($estado === 'recycling' && $prev !== 'recycling')
      pp_aviso_dueno('Bookwat: cobro FALLIDO por PayPal', "Un cobro de suscripción no se pudo hacer.\nCliente: " . ($sub['payer_email'] ?: '—') . "\nMonto: " . number_format(a_centavos($g['value'] ?? 0) / 100, 2) . ' ' . ($g['currency_code'] ?? 'USD') . "\nMotivo: " . strtolower($st));
  }
}

/* Firma del webhook: se la pedimos a PayPal (necesita PAYPAL_WEBHOOK_ID). */
function pp_firma_valida(string $raw): bool {
  $wid = (string)cfg('PAYPAL_WEBHOOK_ID'); if ($wid === '') return false;
  $h = fn(string $k) => (string)($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] ?? '');
  $ev = json_decode($raw, true); if (!is_array($ev)) return false;
  try {
    $r = pp_req('POST', '/v1/notifications/verify-webhook-signature', ['auth_algo' => $h('PAYPAL-AUTH-ALGO'), 'cert_url' => $h('PAYPAL-CERT-URL'),
      'transmission_id' => $h('PAYPAL-TRANSMISSION-ID'), 'transmission_sig' => $h('PAYPAL-TRANSMISSION-SIG'), 'transmission_time' => $h('PAYPAL-TRANSMISSION-TIME'), 'webhook_id' => $wid, 'webhook_event' => $ev]);
    return ($r['verification_status'] ?? '') === 'SUCCESS';
  } catch (Throwable $e) { error_log('pp firma: ' . $e->getMessage()); return false; }
}

/* Da de baja una suscripción de PayPal y la sincroniza. */
function pp_cancelar(PDO $pdo, string $id): array {
  if (!es_paypal($id)) throw new InvalidArgumentException('id inválido');
  pp_req('POST', '/v1/billing/subscriptions/' . rawurlencode($id) . '/cancel', ['reason' => 'La lectora pidió la baja desde Bookwat']);
  return pp_sync_suscripcion($pdo, $id);
}
