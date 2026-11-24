<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/paypal.php';
/* Webhook de PayPal. En developer.paypal.com > tu app > Webhooks: URL https://TU-DOMINIO/paypal-webhook.php
   eventos: BILLING.SUBSCRIPTION.* (activada, cancelada, suspendida, vencida, pago fallido) y PAYMENT.SALE.* (cobros).
   Responde 200 si procesó (o no corresponde); 5xx para que PayPal reintente. */
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function fin(int $c, array $d = []): void { http_response_code($c); echo json_encode($d); exit; }
function bitacora(string $topic, string $id, bool $firma, string $res, string $det = ''): void {
  try { db()->prepare('INSERT INTO eventos_mp (recibido, topic, data_id, firma_ok, resultado, detalle) VALUES (?,?,?,?,?,?)')->execute([ahora(), mb_substr('paypal:' . $topic, 0, 60), mb_substr($id, 0, 80), $firma ? 1 : 0, $res, mb_substr($det, 0, 255)]); }
  catch (Throwable $e) { error_log('eventos_mp: ' . $e->getMessage()); }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fin(405, ['error' => 'POST']);
$raw = file_get_contents('php://input', false, null, 0, 65537);
if ($raw === false || strlen($raw) > 65536) fin(413, ['error' => 'grande']);
$j = json_decode($raw, true); if (!is_array($j)) $j = [];
$topic = (string)($j['event_type'] ?? '');
$r = is_array($j['resource'] ?? null) ? $j['resource'] : [];
$id = str_starts_with($topic, 'BILLING.SUBSCRIPTION.') ? (string)($r['id'] ?? '') : (string)($r['billing_agreement_id'] ?? '');
if (!pp_configurado() || (string)cfg('PAYPAL_WEBHOOK_ID') === '') { bitacora($topic, $id, false, 'error', 'Falta configurar PayPal / PAYPAL_WEBHOOK_ID'); fin(503, ['error' => 'sin configurar']); }
if (!pp_firma_valida($raw)) { bitacora($topic, $id, false, 'firma_invalida'); fin(401, ['error' => 'firma']); }
if (!es_paypal($id)) { bitacora($topic, $id, true, 'ignorado', 'sin suscripción'); fin(200, ['ok' => true]); }
try {
  $pdo = db();
  $sub = pp_sync_suscripcion($pdo, $id);
  if ($sub['_nueva'] || ($sub['_estado_anterior'] !== 'authorized' && $sub['estado'] === 'authorized')) mp_avisar_alta($sub);
  if ($sub['_estado_anterior'] !== null && $sub['_estado_anterior'] !== $sub['estado']) auditar('paypal', 'suscripcion_' . $sub['estado'], $id);
  if ($topic === 'BILLING.SUBSCRIPTION.PAYMENT.FAILED') pp_aviso_dueno('Bookwat: falló un cobro (PayPal)', "PayPal avisó que falló el cobro de la suscripción $id.\nCliente: " . ($sub['payer_email'] ?: '—'));
  bitacora($topic, $id, true, 'ok', $sub['estado']);
} catch (MpNoEncontrado $e) { bitacora($topic, $id, true, 'ignorado', 'PayPal no conoce ese id'); fin(200, ['ok' => true]); }
catch (Throwable $e) { bitacora($topic, $id, true, 'error', $e->getMessage()); error_log('paypal-webhook: ' . $e->getMessage()); fin(500, ['error' => 'reintentar']); }
fin(200, ['ok' => true]);
