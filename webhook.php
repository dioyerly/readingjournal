<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/mp.php';
/* Webhook de Mercado Pago. Configurar en Tus integraciones > Webhooks:
   URL https://TU-DOMINIO/webhook.php  · eventos: "Planes y suscripciones" (preapproval y pagos recurrentes).
   Responde 200 solo si procesó (o si no corresponde procesar); 5xx para que MP reintente. */

header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function fin(int $c, array $d = []): void { http_response_code($c); echo json_encode($d); exit; }
function bitacora(string $topic, string $id, bool $firma, string $res, string $det = ''): void {
  try { db()->prepare('INSERT INTO eventos_mp (recibido, topic, data_id, firma_ok, resultado, detalle) VALUES (?,?,?,?,?,?)')->execute([ahora(), mb_substr($topic, 0, 60), mb_substr($id, 0, 80), $firma ? 1 : 0, $res, mb_substr($det, 0, 255)]); }
  catch (Throwable $e) { error_log('eventos_mp: ' . $e->getMessage()); }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fin(405, ['error' => 'POST']);
$raw = file_get_contents('php://input', false, null, 0, 65537);
if ($raw === false || strlen($raw) > 65536) fin(413, ['error' => 'grande']);
$j = json_decode($raw, true); if (!is_array($j)) $j = [];

$topic = (string)($_GET['type'] ?? $_GET['topic'] ?? $j['type'] ?? $j['topic'] ?? '');
$dataId = (string)($_GET['data_id'] ?? $_GET['data.id'] ?? ($j['data']['id'] ?? $j['id'] ?? ''));
$dataId = trim($dataId);

$secret = (string)cfg('MP_WEBHOOK_SECRET');
if ($secret === '') { bitacora($topic, $dataId, false, 'error', 'Falta MP_WEBHOOK_SECRET'); fin(503, ['error' => 'sin configurar']); }
$firma = mp_firma_valida($dataId, (string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''), (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''), $secret);
if (!$firma) { bitacora($topic, $dataId, false, 'firma_invalida'); fin(401, ['error' => 'firma']); }
if (!mp_id_valido($dataId)) { bitacora($topic, $dataId, true, 'ignorado', 'id inválido'); fin(200, ['ok' => true]); }

try {
  $pdo = db();
  if ($topic === 'subscription_preapproval') {
    $sub = mp_sync_preapproval($pdo, $dataId);
    if ($sub['_nueva'] || ($sub['_estado_anterior'] !== 'authorized' && $sub['estado'] === 'authorized')) mp_avisar_alta($sub);
    if ($sub['_estado_anterior'] !== null && $sub['_estado_anterior'] !== $sub['estado']) auditar('mercadopago', 'suscripcion_' . $sub['estado'], $dataId);
    bitacora($topic, $dataId, true, 'ok', $sub['estado']);
  } elseif ($topic === 'subscription_authorized_payment') {
    $r = mp_sync_cuota($pdo, $dataId);
    bitacora($topic, $dataId, true, 'ok', $r['estado']);
  } else {
    bitacora($topic, $dataId, true, 'ignorado', 'tema no manejado');
  }
} catch (MpNoEncontrado $e) {
  // p. ej. la notificación de prueba del panel de MP trae un id inexistente
  bitacora($topic, $dataId, true, 'ignorado', 'MP no conoce ese id'); fin(200, ['ok' => true]);
} catch (Throwable $e) {
  bitacora($topic, $dataId, true, 'error', $e->getMessage()); error_log('webhook: ' . $e->getMessage()); fin(500, ['error' => 'reintentar']);
}
fin(200, ['ok' => true]);
