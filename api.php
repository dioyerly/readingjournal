<?php
declare(strict_types=1);
/* Bookwat · API de la app. Una biblioteca por cuenta.
   Las cuentas se crean solo después de pagar (alta.php) o desde el panel. El acceso depende de la suscripción:
   activo = todo · solo_lectura = ve y descarga, no guarda · bloqueado = no entra. */
require_once __DIR__ . '/lib/paypal.php';

cabeceras_seguras();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function body(): array { $raw = file_get_contents('php://input', false, null, 0, 12 * 1024 * 1024); $d = json_decode((string)$raw, true); return is_array($d) ? $d : []; }

$a = (string)($_GET['a'] ?? '');
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($post && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'reading-journal') out(['ok' => false, 'msg' => 'Solicitud no válida.'], 400);

$idOk = static fn($v): string => preg_match('/^[A-Za-z0-9]{3,24}$/', (string)$v) ? (string)$v : '';
$coverDir = static fn(int $cid): string => APP_ROOT . '/private/covers/' . $cid;
$bookDir = static fn(int $cid): string => APP_ROOT . '/private/books/' . $cid;
$maxState = (int)cfg('MAX_STATE_KB', 4096) * 1024;
$maxCover = (int)cfg('MAX_COVER_KB', 700) * 1024;
$maxBook = (int)cfg('MAX_BOOK_MB', 90) * 1048576;
$quota = (int)cfg('QUOTA_MB', 600) * 1048576;

sesion('bw_sess');
try { $pdo = db(); } catch (Throwable $e) {
  error_log('db: ' . $e->getMessage());
  if ($a === 'ping' || $a === 'me') out(['ok' => true, 'backend' => true, 'db' => false, 'user' => null]);
  out(['ok' => false, 'msg' => 'No se pudo conectar con la base de datos.'], 503);
}

function cuenta_actual(PDO $pdo): ?array {
  $cid = (int)($_SESSION['cid'] ?? 0); if ($cid <= 0) return null;
  $st = $pdo->prepare('SELECT * FROM cuentas WHERE id = ?'); $st->execute([$cid]); $c = $st->fetch();
  if (!$c || (int)$c['session_ver'] !== (int)($_SESSION['sv'] ?? -1)) { $_SESSION = []; return null; }
  return $c;
}
function info_acceso(array $c, array $acc): array {
  $s = $acc['sub'] ?? null; $pre = $s ? (string)$s['preapproval_id'] : '';
  return ['email' => $c['email'], 'name' => $c['nombre'], 'acceso' => $acc['acceso'], 'motivo' => $acc['motivo'],
    'hasta' => !empty($acc['hasta']) ? date('Y-m-d', (int)$acc['hasta']) : null, 'prueba' => !empty($acc['prueba']),
    'medio' => $pre === '' ? null : (strpos($pre, 'manual-') === 0 ? 'cortesia' : (es_paypal($pre) ? 'paypal' : 'mercadopago')),
    'puede_cancelar' => $s && $s['estado'] === 'authorized' && strpos($pre, 'manual-') !== 0,
    'renovar_mp' => (string)cfg('SUSCRIBIRME_URL'), 'renovar_pp' => (string)cfg('PAYPAL_SUSCRIBIRME_URL')];
}

/* ---- ping: la app lo usa para saber si hay servidor y quién está adentro ---- */
if ($a === 'ping' || $a === 'me') {
  $c = cuenta_actual($pdo);
  if (!$c) out(['ok' => true, 'backend' => true, 'db' => true, 'register' => false, 'user' => null]);
  $acc = cargar_acceso($pdo, $c);
  if ($acc['acceso'] === 'bloqueado') { $_SESSION = []; out(['ok' => true, 'backend' => true, 'db' => true, 'register' => false, 'user' => null, 'bloqueado' => true]); }
  out(['ok' => true, 'backend' => true, 'db' => true, 'register' => false, 'user' => info_acceso($c, $acc)]);
}

if ($a === 'login' && $post) {
  $d = body(); $email = normalizar_email((string)($d['email'] ?? '')); $pass = (string)($d['pass'] ?? '');
  if (freno_bloqueado('login', $email, 5, 15) || freno_bloqueado('login_ip', '', 40, 15)) out(['ok' => false, 'msg' => 'Demasiados intentos. Esperá unos minutos y probá de nuevo.'], 429);
  $st = $pdo->prepare('SELECT * FROM cuentas WHERE email = ?'); $st->execute([$email]); $c = $st->fetch();
  $ok = $c ? password_verify($pass, $c['pass_hash']) : (password_verify($pass, password_hash('relleno', PASSWORD_DEFAULT)) && false);
  if (!$ok) { freno_falla('login', $email); freno_falla('login_ip', ''); auditar($email ?: '-', 'login_fallido'); out(['ok' => false, 'msg' => 'Correo o contraseña incorrectos.'], 401); }
  $acc = cargar_acceso($pdo, $c);
  if ($acc['acceso'] === 'bloqueado') out(['ok' => false, 'msg' => 'Tu cuenta está suspendida. Escribinos a estrategia.datos@estrategia.site.'], 403);
  if (password_needs_rehash($c['pass_hash'], PASSWORD_DEFAULT)) $pdo->prepare('UPDATE cuentas SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $c['id']]);
  freno_limpiar('login', $email);
  session_regenerate_id(true);
  $_SESSION['cid'] = (int)$c['id']; $_SESSION['sv'] = (int)$c['session_ver'];
  $pdo->prepare('UPDATE cuentas SET ultimo_login = ? WHERE id = ?')->execute([ahora(), $c['id']]);
  auditar($email, 'login');
  out(['ok' => true, 'user' => info_acceso($c, $acc)]);
}

if ($a === 'logout' && $post) {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], true); }
  if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
  out(['ok' => true]);
}

/* ---- a partir de acá hace falta sesión ---- */
$c = cuenta_actual($pdo);
if (!$c) out(['ok' => false, 'auth' => true, 'msg' => 'Necesitás iniciar sesión.'], 401);
$cid = (int)$c['id'];
$acc = cargar_acceso($pdo, $c);
if ($acc['acceso'] === 'bloqueado') { $_SESSION = []; out(['ok' => false, 'auth' => true, 'msg' => 'Tu cuenta está suspendida.'], 403); }
$escribir = static function () use ($acc): void {
  if ($acc['acceso'] !== 'activo') out(['ok' => false, 'readonly' => true, 'msg' => 'Tu suscripción no está activa: podés ver y descargar tu biblioteca, pero no guardar cambios. Renovala para seguir.'], 402);
};

if ($a === 'state' && !$post) {
  $st = $pdo->prepare('SELECT version, data FROM biblioteca_estado WHERE cuenta_id = ?'); $st->execute([$cid]); $r = $st->fetch();
  out(['ok' => true, 'version' => $r ? (int)$r['version'] : 0, 'data' => $r ? json_decode($r['data'], true) : null, 'user' => info_acceso($c, $acc)]);
}

if ($a === 'state' && $post) {
  $escribir();
  $d = body(); $data = $d['data'] ?? null;
  if (!is_array($data) || !isset($data['books']) || !is_array($data['books']) || !isset($data['settings'])) out(['ok' => false, 'msg' => 'Biblioteca no válida.'], 422);
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false || strlen($json) > $maxState) out(['ok' => false, 'msg' => 'Tu biblioteca es demasiado grande para guardarla.'], 413);
  $base = (int)($d['base'] ?? 0); $force = !empty($d['force']);
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('SELECT version, data FROM biblioteca_estado WHERE cuenta_id = ? FOR UPDATE'); $st->execute([$cid]);
    $r = $st->fetch(); $cur = $r ? (int)$r['version'] : 0;
    if ($cur !== $base && !$force) { $pdo->rollBack(); out(['ok' => false, 'conflict' => true, 'version' => $cur, 'data' => $r ? json_decode($r['data'], true) : null], 409); }
    $new = $cur + 1;
    if ($r) $pdo->prepare('UPDATE biblioteca_estado SET version = ?, data = ?, updated_at = ? WHERE cuenta_id = ?')->execute([$new, $json, ahora(), $cid]);
    else $pdo->prepare('INSERT INTO biblioteca_estado (cuenta_id, version, data, updated_at) VALUES (?,?,?,?)')->execute([$cid, $new, $json, ahora()]);
    // respaldo automático: una copia por día (se guardan las últimas 30)
    $hoy = (int)date('Ymd');
    $pdo->prepare('INSERT INTO biblioteca_respaldos (cuenta_id, version, data, created_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE data = VALUES(data), created_at = VALUES(created_at)')->execute([$cid, $hoy, $json, ahora()]);
    $pdo->prepare('DELETE FROM biblioteca_respaldos WHERE cuenta_id = ? AND version < ?')->execute([$cid, (int)date('Ymd', time() - 30 * 86400)]);
    $pdo->commit();
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('save: ' . $e->getMessage()); out(['ok' => false, 'msg' => 'No se pudo guardar.'], 500); }
  out(['ok' => true, 'version' => $new]);
}

if ($a === 'cancelar' && $post) {
  $sub = $acc['sub'] ?? null;
  if (!$sub || $sub['estado'] !== 'authorized') out(['ok' => false, 'msg' => 'No tenés una suscripción activa para dar de baja.'], 400);
  $pre = (string)$sub['preapproval_id'];
  if (strpos($pre, 'manual-') === 0) out(['ok' => false, 'msg' => 'Tu acceso es de cortesía: no tiene cobros que cancelar.'], 400);
  try { es_paypal($pre) ? pp_cancelar($pdo, $pre) : mp_cancelar_preapproval($pdo, $pre); }
  catch (Throwable $e) { error_log('cancelar: ' . $e->getMessage()); out(['ok' => false, 'msg' => 'No se pudo dar de baja ahora. Probá de nuevo en unos minutos.'], 502); }
  auditar((string)$c['email'], 'baja_suscripcion', $pre);
  pp_aviso_dueno('Bookwat: una lectora se dio de baja', 'Cuenta: ' . $c['email'] . "\nSuscripción: $pre");
  $st = $pdo->prepare('SELECT * FROM cuentas WHERE id = ?'); $st->execute([$cid]);
  out(['ok' => true, 'user' => info_acceso($c, cargar_acceso($pdo, $st->fetch()))]);
}

/* ---- portadas ---- */
if ($a === 'covers' && !$post) {
  $ids = []; foreach (glob($coverDir($cid) . '/*.jpg') ?: [] as $f) $ids[] = basename($f, '.jpg');
  out(['ok' => true, 'ids' => $ids]);
}
if ($a === 'cover' && !$post) {
  $id = $idOk($_GET['id'] ?? ''); $f = $id ? $coverDir($cid) . '/' . $id . '.jpg' : '';
  if (!$f || !is_file($f)) out(['ok' => false, 'msg' => 'No existe.'], 404);
  header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=3600'); readfile($f); exit;
}
if ($a === 'cover' && $post) {
  $escribir();
  $d = body(); $id = $idOk($d['id'] ?? ''); $m = [];
  if (!$id || !preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', (string)($d['data'] ?? ''), $m)) out(['ok' => false, 'msg' => 'Imagen no válida.'], 422);
  $bin = base64_decode($m[1], true);
  if ($bin === false || strlen($bin) > $maxCover) out(['ok' => false, 'msg' => 'Esa foto es demasiado pesada.'], 413);
  $info = @getimagesizefromstring($bin);
  if (!$info || $info[2] !== IMAGETYPE_JPEG) out(['ok' => false, 'msg' => 'Imagen no válida.'], 422);
  $dir = $coverDir($cid); if (!is_dir($dir)) mkdir($dir, 0755, true);
  file_put_contents($dir . '/' . $id . '.jpg', $bin, LOCK_EX);
  out(['ok' => true]);
}
if ($a === 'cover_del' && $post) {
  $escribir();
  $id = $idOk((body())['id'] ?? ''); $f = $id ? $coverDir($cid) . '/' . $id . '.jpg' : '';
  if ($f && is_file($f)) unlink($f);
  out(['ok' => true]);
}

/* ---- libros digitales (EPUB/PDF) ---- */
if ($a === 'files' && !$post) {
  $o = []; foreach (glob($bookDir($cid) . '/*.bin') ?: [] as $f) $o[] = ['id' => basename($f, '.bin'), 'size' => filesize($f)];
  out(['ok' => true, 'files' => $o]);
}
if ($a === 'file' && !$post) {
  $id = $idOk($_GET['id'] ?? ''); $f = $id ? $bookDir($cid) . '/' . $id . '.bin' : '';
  if (!$f || !is_file($f)) out(['ok' => false, 'msg' => 'No existe.'], 404);
  header('Content-Type: application/octet-stream'); header('Content-Length: ' . filesize($f)); header('Cache-Control: private, max-age=86400');
  readfile($f); exit;
}
if ($a === 'file' && $post) {
  $escribir();
  $id = $idOk($_GET['id'] ?? ''); if (!$id) out(['ok' => false, 'msg' => 'Libro no válido.'], 422);
  $dir = $bookDir($cid); if (!is_dir($dir)) mkdir($dir, 0755, true);
  $dest = $dir . '/' . $id . '.bin'; $tmp = $dest . '.part';
  $in = fopen('php://input', 'rb'); $o = fopen($tmp, 'wb'); $n = 0;
  while (!feof($in)) {
    $chunk = fread($in, 1048576); if ($chunk === false) break;
    $n += strlen($chunk);
    if ($n > $maxBook) { fclose($in); fclose($o); unlink($tmp); out(['ok' => false, 'msg' => 'Ese libro pesa demasiado (máx. ' . (int)($maxBook / 1048576) . ' MB).'], 413); }
    fwrite($o, $chunk);
  }
  fclose($in); fclose($o);
  $head = (string)file_get_contents($tmp, false, null, 0, 5);
  if ($n < 100 || !(strncmp($head, '%PDF', 4) === 0 || strncmp($head, 'PK', 2) === 0)) { unlink($tmp); out(['ok' => false, 'msg' => 'Solo se aceptan archivos EPUB o PDF.'], 422); }
  $used = 0; foreach (glob($dir . '/*.bin') ?: [] as $f) if ($f !== $dest) $used += filesize($f);
  if ($used + $n > $quota) { unlink($tmp); out(['ok' => false, 'msg' => 'Llegaste al límite de espacio de tu biblioteca digital.'], 413); }
  rename($tmp, $dest);
  out(['ok' => true]);
}
if ($a === 'file_del' && $post) {
  $escribir();
  $id = $idOk((body())['id'] ?? ''); $f = $id ? $bookDir($cid) . '/' . $id . '.bin' : '';
  if ($f && is_file($f)) unlink($f);
  out(['ok' => true]);
}

out(['ok' => false, 'msg' => 'Acción desconocida.'], 404);
