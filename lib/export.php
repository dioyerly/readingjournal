<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';
/* Eventos en el formato del "Centro de operaciones" (source, external_id, type, app, date, amount...). */

function eventos_centro(PDO $pdo, ?string $desde = null): array {
  $ev = [];
  $app = 'Bookwat';
  $subs = $pdo->query('SELECT s.*, c.nombre AS c_nombre, c.email AS c_email FROM suscripciones s LEFT JOIN cuentas c ON c.id = s.cuenta_id ORDER BY s.id')->fetchAll();
  $pagosPor = [];
  foreach ($pdo->query('SELECT * FROM pagos ORDER BY COALESCE(fecha_debito, creada), id')->fetchAll() as $p) $pagosPor[$p['preapproval_id']][] = $p;
  foreach ($subs as $s) {
    $email = $s['c_email'] ?: $s['payer_email']; $nombre = (string)$s['c_nombre'];
    $base = ['source' => 'bookwat', 'app' => $app, 'currency' => $s['moneda'], 'customer_email' => $email, 'customer_name' => $nombre, 'country' => 'Argentina'];
    $primero = true;
    foreach ($pagosPor[$s['preapproval_id']] ?? [] as $p) {
      $fecha = substr((string)($p['fecha_debito'] ?: $p['creada']), 0, 10);
      if ($p['estado'] === 'processed') {
        $ev[] = $base + ['external_id' => 'bw_pay_' . $p['mp_id'], 'type' => $primero ? 'subscription_started' : 'renewal', 'plan' => $s['motivo'] ?: 'Suscripción', 'date' => $fecha, 'amount' => $p['monto_cent'] / 100];
        $primero = false;
      } elseif ($p['estado'] === 'recycling') {
        $ev[] = $base + ['external_id' => 'bw_fail_' . $p['mp_id'] . '_' . $p['reintento'], 'type' => 'payment_failed', 'date' => $fecha, 'amount' => $p['monto_cent'] / 100];
      }
    }
    if ($s['estado'] === 'cancelled') $ev[] = $base + ['external_id' => 'bw_cancel_' . $s['preapproval_id'], 'type' => 'cancellation', 'date' => substr((string)($s['mp_modificada'] ?: $s['actualizada']), 0, 10)];
  }
  // foto de usuarios de hoy
  $reg = (int)$pdo->query('SELECT COUNT(*) FROM cuentas')->fetchColumn(); $act = 0;
  foreach ($pdo->query('SELECT * FROM cuentas')->fetchAll() as $c) if (cargar_acceso($pdo, $c)['acceso'] === 'activo') $act++;
  $ev[] = ['source' => 'bookwat', 'external_id' => 'bw_users_' . date('Y-m-d'), 'type' => 'users', 'app' => $app, 'date' => date('Y-m-d'), 'registered' => $reg, 'active' => $act];
  if ($desde) $ev = array_values(array_filter($ev, fn($e) => $e['date'] >= $desde));
  return $ev;
}