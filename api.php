<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$a = (string)($_GET['a'] ?? '');
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($post && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'reading-journal') out(['ok' => false, 'msg' => 'Solicitud no válida.'], 400);

$maxState = (int)cfg('max_state_kb', 4096) * 1024;
$maxCover = (int)cfg('max_cover_kb', 700) * 1024;
$coverId  = static fn($v): string => preg_match('/^[A-Za-z0-9]{3,24}$/', (string)$v) ? (string)$v : '';
$coverDir = static fn(int $uid): string => APP_ROOT . '/private/covers/' . $uid;

/* ---- ping: la app lo usa para saber si hay servidor ---- */
if ($a === 'ping' || $a === 'me') {
    $pdo = db_ready();
    $u = $pdo ? current_user() : null;
    out(['ok' => true, 'backend' => true, 'db' => (bool)$pdo, 'register' => (bool)cfg('allow_register', true), 'user' => $u ? ['email' => $u['email'], 'name' => $u['name']] : null]);
}

$pdo = db_ready();
if (!$pdo) out(['ok' => false, 'msg' => 'No se pudo conectar con la base de datos.'], 503);

if ($a === 'register' && $post) {
    if (!cfg('allow_register', true)) out(['ok' => false, 'msg' => 'El registro de cuentas nuevas está cerrado.'], 403);
    if (rate_blocked($pdo)) out(['ok' => false, 'msg' => 'Demasiados intentos. Esperá unos minutos y probá de nuevo.'], 429);
    $d = body();
    $email = strtolower(trim((string)($d['email'] ?? '')));
    $pass = (string)($d['pass'] ?? '');
    $name = mb_substr(trim(strip_tags((string)($d['name'] ?? ''))), 0, 80);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) out(['ok' => false, 'msg' => 'Escribí un correo válido.'], 422);
    if (strlen($pass) < 8 || strlen($pass) > 200) out(['ok' => false, 'msg' => 'La contraseña debe tener al menos 8 caracteres.'], 422);
    rate_fail($pdo); // también limita la creación masiva de cuentas
    try {
        $pdo->prepare('INSERT INTO users (email,name,pass_hash,created_at) VALUES (?,?,?,NOW())')->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') out(['ok' => false, 'msg' => 'Ese correo ya tiene una cuenta. Probá entrar.'], 409);
        throw $e;
    }
    session_boot(); session_regenerate_id(true);
    $_SESSION['uid'] = (int)$pdo->lastInsertId();
    out(['ok' => true, 'user' => ['email' => $email, 'name' => $name]]);
}

if ($a === 'login' && $post) {
    if (rate_blocked($pdo)) out(['ok' => false, 'msg' => 'Demasiados intentos. Esperá unos minutos y probá de nuevo.'], 429);
    $d = body();
    $email = strtolower(trim((string)($d['email'] ?? '')));
    $pass = (string)($d['pass'] ?? '');
    $st = $pdo->prepare('SELECT id,email,name,pass_hash FROM users WHERE email=?'); $st->execute([$email]);
    $u = $st->fetch();
    $hash = $u ? $u['pass_hash'] : '$2y$10$usesomesillystringforsalt.abcdefghijklmnopqrstuvwxyzABCDEF';
    if (!password_verify($pass, $hash) || !$u) { rate_fail($pdo); out(['ok' => false, 'msg' => 'Correo o contraseña incorrectos.'], 401); }
    if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) $pdo->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    session_boot(); session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    out(['ok' => true, 'user' => ['email' => $u['email'], 'name' => $u['name']]]);
}

if ($a === 'logout' && $post) {
    session_boot(); $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], true); }
    session_destroy();
    out(['ok' => true]);
}

/* ---- a partir de acá hace falta sesión ---- */
$u = require_user(); $uid = (int)$u['id'];

if ($a === 'state' && !$post) {
    $st = $pdo->prepare('SELECT version,data FROM states WHERE user_id=?'); $st->execute([$uid]);
    $r = $st->fetch();
    out(['ok' => true, 'version' => $r ? (int)$r['version'] : 0, 'data' => $r ? json_decode($r['data'], true) : null]);
}

if ($a === 'state' && $post) {
    $d = body();
    $data = $d['data'] ?? null;
    if (!is_array($data) || !isset($data['books']) || !is_array($data['books']) || !isset($data['settings'])) out(['ok' => false, 'msg' => 'Biblioteca no válida.'], 422);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > $maxState) out(['ok' => false, 'msg' => 'Tu biblioteca es demasiado grande para guardarla.'], 413);
    $base = (int)($d['base'] ?? 0); $force = !empty($d['force']);
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT version,data FROM states WHERE user_id=? FOR UPDATE'); $st->execute([$uid]);
    $r = $st->fetch(); $cur = $r ? (int)$r['version'] : 0;
    if ($cur !== $base && !$force) { $pdo->rollBack(); out(['ok' => false, 'conflict' => true, 'version' => $cur, 'data' => $r ? json_decode($r['data'], true) : null], 409); }
    if ($r) $pdo->prepare('UPDATE states SET version=version+1,data=?,updated_at=NOW() WHERE user_id=?')->execute([$json, $uid]);
    else $pdo->prepare('INSERT INTO states (user_id,version,data,updated_at) VALUES (?,1,?,NOW())')->execute([$uid, $json]);
    $pdo->commit();
    out(['ok' => true, 'version' => $cur + 1]);
}

if ($a === 'covers' && !$post) {
    $ids = [];
    foreach (glob($coverDir($uid) . '/*.jpg') ?: [] as $f) $ids[] = basename($f, '.jpg');
    out(['ok' => true, 'ids' => $ids]);
}

if ($a === 'cover' && !$post) {
    $id = $coverId($_GET['id'] ?? ''); $f = $id ? $coverDir($uid) . '/' . $id . '.jpg' : '';
    if (!$f || !is_file($f)) out(['ok' => false, 'msg' => 'No existe.'], 404);
    header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=3600'); header('X-Content-Type-Options: nosniff');
    readfile($f); exit;
}

if ($a === 'cover' && $post) {
    $d = body(); $id = $coverId($d['id'] ?? '');
    $m = [];
    if (!$id || !preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', (string)($d['data'] ?? ''), $m)) out(['ok' => false, 'msg' => 'Imagen no válida.'], 422);
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) > $maxCover) out(['ok' => false, 'msg' => 'Esa foto es demasiado pesada.'], 413);
    $info = @getimagesizefromstring($bin);
    if (!$info || $info[2] !== IMAGETYPE_JPEG) out(['ok' => false, 'msg' => 'Imagen no válida.'], 422);
    $dir = $coverDir($uid); if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/' . $id . '.jpg', $bin, LOCK_EX);
    out(['ok' => true]);
}

if ($a === 'cover_del' && $post) {
    $id = $coverId((body())['id'] ?? ''); $f = $id ? $coverDir($uid) . '/' . $id . '.jpg' : '';
    if ($f && is_file($f)) unlink($f);
    out(['ok' => true]);
}

out(['ok' => false, 'msg' => 'Acción desconocida.'], 404);
