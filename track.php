<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/core.php';
/* Contador de la landing: visitas y clicks. Sin cookies, sin guardar la IP. */
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
function fin(int $c): void { http_response_code($c); echo $c === 204 ? '' : '{"ok":false}'; exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fin(405);
$raw = file_get_contents('php://input', false, null, 0, 2048); $in = json_decode((string)$raw, true);
if (!is_array($in)) fin(400);
function sin_tabla(Throwable $e): bool { return (string)$e->getCode() === '42S02' || (int)($e instanceof PDOException ? ($e->errorInfo[1] ?? 0) : 0) === 1146 || strpos($e->getMessage(), '42S02') !== false; }

const CREAR_TABLA = 'CREATE TABLE IF NOT EXISTS landing_eventos (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ts DATETIME NOT NULL, dia DATE NOT NULL, tipo VARCHAR(20) NOT NULL, visitante CHAR(16) NOT NULL, origen VARCHAR(60) NOT NULL DEFAULT \'\', campana VARCHAR(60) NOT NULL DEFAULT \'\', disp VARCHAR(8) NOT NULL DEFAULT \'\', detalle VARCHAR(40) NOT NULL DEFAULT \'\', KEY ix_dia_tipo (dia, tipo), KEY ix_vis (visitante, dia, ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

$TIPOS = ['visita', 'click_sub', 'click_wa', 'click_app', 'precio', 'scroll50', 'scroll90'];
$tipo = (string)($in['t'] ?? ''); if (!in_array($tipo, $TIPOS, true)) fin(400);

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('~bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|uptime|headless|lighthouse|curl|wget|python|go-http|java/|okhttp~i', $ua)) fin(204);

$hoy = date('Y-m-d');
$vis = substr(hash_hmac('sha256', $hoy . '|' . ip() . '|' . $ua, (string)(cfg('MP_WEBHOOK_SECRET') ?: 'bookwat') . '|track'), 0, 16);

$origen = strtolower(trim((string)($in['u'] ?? '')));
if ($origen === '') {
  $host = strtolower((string)parse_url((string)($in['r'] ?? ''), PHP_URL_HOST)); $host = preg_replace('~^www\.~', '', $host);
  $propio = strtolower((string)parse_url((string)cfg('APP_URL'), PHP_URL_HOST));
  if ($host === '' || $host === $propio) $origen = '';
  elseif (preg_match('~instagram~', $host)) $origen = 'instagram';
  elseif (preg_match('~facebook|fb\.com|fb\.me|messenger~', $host)) $origen = 'facebook';
  elseif (preg_match('~google~', $host)) $origen = 'google';
  elseif (preg_match('~whatsapp|wa\.me~', $host)) $origen = 'whatsapp';
  elseif (preg_match('~tiktok~', $host)) $origen = 'tiktok';
  elseif (preg_match('~(^|\.)t\.co$|twitter|(^|\.)x\.com$~', $host)) $origen = 'x';
  else $origen = $host;
}
$origen = preg_replace('~[^a-z0-9._\- ]~', '', $origen); $origen = substr((string)$origen, 0, 60);
$camp = substr((string)preg_replace('~[^a-zA-Z0-9._\- ]~', '', (string)($in['c'] ?? '')), 0, 60);
$w = (int)($in['w'] ?? 0); $disp = $w > 0 && $w < 760 ? 'movil' : ($w < 1100 && $w > 0 ? 'tablet' : 'pc');
$det = substr((string)preg_replace('~[^a-zA-Z0-9._\- ]~', '', (string)($in['d'] ?? '')), 0, 40);

try {
  $pdo = db();
  try {
    $q = $pdo->prepare('SELECT COUNT(*) FROM landing_eventos WHERE visitante = ? AND dia = ? AND ts > ?'); $q->execute([$vis, $hoy, date('Y-m-d H:i:s', time() - 600)]);
    if ((int)$q->fetchColumn() >= 80) fin(204);
  } catch (PDOException $e) { if (!sin_tabla($e)) throw $e; }
  $val = [ahora(), $hoy, $tipo, $vis, $origen, $camp, $disp, $det];
  $sqlIns = 'INSERT INTO landing_eventos (ts, dia, tipo, visitante, origen, campana, disp, detalle) VALUES (?,?,?,?,?,?,?,?)';
  try { $pdo->prepare($sqlIns)->execute($val); }
  catch (PDOException $e) {
    if (!sin_tabla($e)) throw $e;
    $pdo->exec(CREAR_TABLA); $pdo->prepare($sqlIns)->execute($val);
  }
  if (random_int(1, 300) === 1) $pdo->prepare('DELETE FROM landing_eventos WHERE dia < ?')->execute([date('Y-m-d', time() - 400 * 86400)]);
} catch (Throwable $e) { error_log('track: ' . $e->getMessage()); fin(204); }
fin(204);