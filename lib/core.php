<?php
declare(strict_types=1);
/* Núcleo compartido: configuración, base de datos, sesiones, mails, acceso por pago. */

date_default_timezone_set('America/Argentina/Buenos_Aires');

function cfg(string $k, $def = '') {
  static $c = null;
  if ($c === null) { $f = getenv('NC_CONFIG') ?: dirname(__DIR__) . '/config.php'; $c = is_file($f) ? (require $f) : []; if (!is_array($c)) $c = []; }
  return $c[$k] ?? $def;
}
function ahora(): string { return date('Y-m-d H:i:s'); }
function ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

define('APP_ROOT', dirname(__DIR__));

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = (string)cfg('DB_DSN');
    if ($dsn === '') $dsn = 'mysql:host=' . cfg('DB_HOST', 'localhost') . (cfg('DB_PORT') ? ';port=' . cfg('DB_PORT') : '') . ';dbname=' . cfg('DB_NAME') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)cfg('DB_USER'), (string)cfg('DB_PASS'), [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET time_zone = '-03:00'");
    asegurar_tablas($pdo);
  }
  return $pdo;
}

/* Las tablas se crean solas la primera vez (no hace falta importar nada). */
function asegurar_tablas(PDO $pdo): void {
  try { $pdo->query('SELECT 1 FROM ajustes LIMIT 1'); return; } catch (Throwable $e) {}
  $sql = (string)file_get_contents(APP_ROOT . '/schema.sql');
  foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sql)))) as $q) $pdo->exec($q);
}
function ajuste(string $k, ?string $def = null): ?string {
  $st = db()->prepare('SELECT v FROM ajustes WHERE k = ?'); $st->execute([$k]); $v = $st->fetchColumn();
  return $v === false ? $def : (string)$v;
}
function ajuste_set(string $k, string $v): void {
  db()->prepare('INSERT INTO ajustes (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}
/* Token del Centro de operaciones: el de config.php (EXPORT_TOKEN) o el que se genera desde el panel. */
function token_centro_ok(string $dado): bool {
  if ($dado === '' || strlen($dado) < 32) return false;
  $cfgTok = (string)cfg('EXPORT_TOKEN');
  if ($cfgTok !== '' && strlen($cfgTok) >= 32 && hash_equals($cfgTok, $dado)) return true;
  $eco = (string)cfg('ECOSYSTEM_KEY'); /* clave compartida del Centro de operaciones (una sola para todas las apps) */
  if ($eco !== '' && strlen($eco) >= 32 && hash_equals($eco, $dado)) return true;
  $h = ajuste('centro_token_hash');
  return $h !== null && hash_equals($h, hash('sha256', $dado));
}
/* ---- Conexión por código con el Centro de operaciones (sin copiar claves a mano) ---- */
function centro_codigo_generar(): string {
  $c = (string)random_int(10000000, 99999999);
  ajuste_set('centro_codigo', hash('sha256', $c) . '|' . (time() + 600));
  return $c;
}
function centro_emparejar(string $codigo, string $clave): bool {
  $v = (string)ajuste('centro_codigo'); if ($v === '' || strpos($v, '|') === false) return false;
  [$h, $exp] = explode('|', $v, 2);
  if ((int)$exp < time() || !hash_equals($h, hash('sha256', $codigo))) return false;
  if (strlen($clave) < 32 || strlen($clave) > 200 || !preg_match('/^[A-Za-z0-9_-]+$/', $clave)) return false;
  ajuste_set('centro_token_hash', hash('sha256', $clave));
  ajuste_set('centro_codigo', ''); /* el código sirve una sola vez */
  return true;
}
function token_centro_configurado(): bool { return strlen((string)cfg('EXPORT_TOKEN')) >= 32 || strlen((string)cfg('ECOSYSTEM_KEY')) >= 32 || ajuste('centro_token_hash') !== null; }

function cabeceras_seguras(): void {
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('Referrer-Policy: same-origin');
}

function sesion(string $nombre = 'bw_sess'): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  session_name($nombre);
  $larga = $nombre === 'bw_sess';
  if ($larga) ini_set('session.gc_maxlifetime', (string)(60 * 86400));
  session_set_cookie_params(['lifetime' => $larga ? 60 * 86400 : 0, 'path' => '/', 'httponly' => true, 'samesite' => $larga ? 'Lax' : 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')]);
  session_start();
}

function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function csrf_ok(): bool { $t = (string)($_POST['csrf'] ?? ''); return $t !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t); }

function auditar(string $actor, string $accion, string $detalle = ''): void {
  try { db()->prepare('INSERT INTO auditoria (ts, actor, accion, detalle, ip) VALUES (?,?,?,?,?)')->execute([ahora(), mb_substr($actor, 0, 190), mb_substr($accion, 0, 60), mb_substr($detalle, 0, 500), ip()]); }
  catch (Throwable $e) { error_log('auditoria: ' . $e->getMessage()); }
}

/* ---- freno a intentos repetidos (por base de datos, no por sesión) ---- */
function _clave_freno(string $ambito, string $quien): string { return hash('sha256', $ambito . '|' . mb_strtolower($quien) . '|' . ip()); }
function freno_bloqueado(string $ambito, string $quien, int $max = 5, int $minutos = 15): bool {
  $st = db()->prepare('SELECT COUNT(*) FROM login_intentos WHERE clave = ? AND ts > ?');
  $st->execute([_clave_freno($ambito, $quien), date('Y-m-d H:i:s', time() - $minutos * 60)]);
  return (int)$st->fetchColumn() >= $max;
}
function freno_falla(string $ambito, string $quien): void {
  db()->prepare('INSERT INTO login_intentos (clave, ambito, ts) VALUES (?,?,?)')->execute([_clave_freno($ambito, $quien), $ambito, ahora()]);
  if (random_int(1, 20) === 1) db()->prepare('DELETE FROM login_intentos WHERE ts < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
}
function freno_limpiar(string $ambito, string $quien): void { db()->prepare('DELETE FROM login_intentos WHERE clave = ?')->execute([_clave_freno($ambito, $quien)]); }

/* ---- contraseñas ---- */
function clave_valida(string $p): ?string {
  if (mb_strlen($p) < 10) return 'La clave debe tener al menos 10 caracteres.';
  if (mb_strlen($p) > 200) return 'La clave es demasiado larga.';
  if (preg_match('/^(.)\1+$/u', $p)) return 'Elegí una clave menos obvia.';
  return null;
}
function email_valido(string $e): bool { return strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n]/', $e); }
function normalizar_email(string $e): string { return mb_strtolower(trim($e)); }

/* ---- mails ---- */
function enviar_mail(string $para, string $asunto, string $texto): bool {
  if (!email_valido($para)) return false;
  $from = (string)cfg('MAIL_FROM', 'Bookwat <no-responder@localhost>');
  if (cfg('MAIL_MODE', 'mail') === 'log') {
    $f = dirname(__DIR__) . '/storage/mail.log';
    return file_put_contents($f, "=== " . ahora() . " | para: $para | asunto: $asunto\n$texto\n\n", FILE_APPEND | LOCK_EX) !== false;
  }
  $h = "From: $from\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nMIME-Version: 1.0\r\n";
  return @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $texto, $h);
}

/* ---- fechas de Mercado Pago -> hora local ---- */
function fecha_mp($s): ?string {
  if (!is_string($s) || $s === '') return null;
  try { $d = new DateTime($s); $d->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires')); return $d->format('Y-m-d H:i:s'); }
  catch (Throwable $e) { return null; }
}

/* ---- ACCESO POR PAGO ----------------------------------------------------
   Devuelve: 'activo' (todo), 'solo_lectura' (ve y descarga, no guarda), 'bloqueado'.
   Reglas:
   - override del dueño manda (bloqueado / activo=cortesía).
   - authorized: activo mientras MP siga programando cobros o el último pago
     esté dentro de su período + gracia.
   - paused/cancelled/pending: activo solo hasta el fin del período ya pagado.
   - sin suscripción: solo lectura.
   $ahora y $gracia se inyectan para poder probarlo.                         */
function calcular_acceso(array $cuenta, ?array $sub, ?string $ultimoPagoOk, ?int $ahora = null, ?int $gracia = null): array {
  $ahora ??= time(); $gracia ??= (int)cfg('GRACIA_DIAS', 5);
  if (($cuenta['override'] ?? 'auto') === 'bloqueado') return ['acceso' => 'bloqueado', 'motivo' => 'Cuenta bloqueada por el administrador', 'hasta' => null];
  if (($cuenta['override'] ?? 'auto') === 'activo') return ['acceso' => 'activo', 'motivo' => 'Acceso de cortesía', 'hasta' => null];
  if (!$sub) return ['acceso' => 'solo_lectura', 'motivo' => 'Sin suscripción', 'hasta' => null];
  $periodo = max(1, (int)($sub['periodo_dias'] ?? 30)) * 86400; $g = $gracia * 86400;
  $base = $ultimoPagoOk ? strtotime($ultimoPagoOk) : strtotime((string)($sub['creada'] ?? 'now'));
  $hastaPago = $base + ($ultimoPagoOk ? $periodo : 0) + $g;
  $estado = (string)$sub['estado'];
  $prox0 = !empty($sub['proximo_cobro']) ? strtotime((string)$sub['proximo_cobro']) : 0;
  // Cuenta creada a mano desde el panel (prueba o regalo): vence exactamente en la fecha cargada, sin gracia ni cobros.
  if (strpos((string)($sub['preapproval_id'] ?? ''), 'manual-') === 0) {
    $ok = $prox0 > 0 && $ahora <= $prox0 && in_array($estado, ['authorized', 'cancelled'], true);
    return ['acceso' => $ok ? 'activo' : 'solo_lectura', 'motivo' => ($ok ? 'Acceso manual: ' : 'Acceso manual vencido: ') . (string)($sub['motivo'] ?: 'prueba o regalo'), 'hasta' => $prox0 ?: null, 'prueba' => false, 'manual' => true];
  }
  if ($estado === 'authorized') {
    $prox = $prox0 ? $prox0 + $g : 0;
    $hasta = max($hastaPago, $prox);
    // Prueba gratis de Mercado Pago: autorizada, todavía sin cobros y con el primer cobro en el futuro.
    $enPrueba = !$ultimoPagoOk && $prox0 > $ahora;
    if ($enPrueba) return ['acceso' => 'activo', 'motivo' => 'Prueba gratis', 'hasta' => $prox0, 'prueba' => true, 'manual' => false];
    return $ahora <= $hasta ? ['acceso' => 'activo', 'motivo' => 'Suscripción al día', 'hasta' => $hasta, 'prueba' => false, 'manual' => false]
                            : ['acceso' => 'solo_lectura', 'motivo' => 'Cobro pendiente: la suscripción no registra pagos recientes', 'hasta' => $hasta, 'prueba' => false, 'manual' => false];
  }
  // Dio de baja durante la prueba gratis: conserva los días de prueba que le quedan.
  if ($estado === 'cancelled' && !$ultimoPagoOk && $prox0 > 0 && $ahora <= $prox0)
    return ['acceso' => 'activo', 'motivo' => 'Prueba gratis (baja pedida, sin cobros)', 'hasta' => $prox0, 'prueba' => true, 'manual' => false];
  if ($ultimoPagoOk && $ahora <= $hastaPago - $g + 0) return ['acceso' => 'activo', 'motivo' => 'Período pagado vigente (suscripción ' . $estado . ')', 'hasta' => $hastaPago - $g, 'prueba' => false, 'manual' => false];
  return ['acceso' => 'solo_lectura', 'motivo' => 'Suscripción ' . $estado, 'hasta' => $ultimoPagoOk ? $hastaPago - $g : null, 'prueba' => false, 'manual' => false];
}

function cargar_acceso(PDO $pdo, array $cuenta): array {
  $st = $pdo->prepare('SELECT * FROM suscripciones WHERE cuenta_id = ? ORDER BY (estado = "authorized") DESC, actualizada DESC LIMIT 1');
  $st->execute([$cuenta['id']]); $sub = $st->fetch() ?: null;
  $ult = null;
  if ($sub) {
    $q = $pdo->prepare('SELECT MAX(COALESCE(fecha_debito, actualizada)) FROM pagos WHERE preapproval_id = ? AND estado = "processed"');
    $q->execute([$sub['preapproval_id']]); $ult = $q->fetchColumn() ?: null;
  }
  $r = calcular_acceso($cuenta, $sub, $ult); $r['sub'] = $sub; $r['ultimo_pago'] = $ult; return $r;
}

/* ---- tokens de recuperación ---- */
function crear_token_recuperacion(PDO $pdo, int $cuentaId, int $minutos = 60): string {
  $tok = bin2hex(random_bytes(32));
  $pdo->prepare('UPDATE recuperaciones SET usado = 1 WHERE cuenta_id = ? AND usado = 0')->execute([$cuentaId]);
  $pdo->prepare('INSERT INTO recuperaciones (cuenta_id, token_hash, expira) VALUES (?,?,?)')->execute([$cuentaId, hash('sha256', $tok), date('Y-m-d H:i:s', time() + $minutos * 60)]);
  return $tok;
}

function pagina(string $titulo, string $cuerpo): void {
  cabeceras_seguras();
  header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
  header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc($titulo) . '</title><style>
:root{--bg:#f4ecfa;--ink:#3e2f4f;--mut:#7a6a8c;--ac:#9b6fc0;--card:#fffcff;--line:#e3d3f2}
@media(prefers-color-scheme:dark){:root{--bg:#1d1030;--ink:#f3eafb;--mut:#b7a6c9;--ac:#c9a7ec;--card:#2a1840;--line:#45305e}}
h1{font-family:Georgia,serif;font-weight:600;font-size:1.9rem!important}.brand{font:600 22px Georgia,serif;color:var(--ac);margin-bottom:14px;display:block}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;display:grid;place-items:center;min-height:100vh;padding:16px}
.c{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:28px;width:100%;max-width:440px}
h1{margin:0 0 6px;font-size:1.5rem}p{color:var(--mut);margin:0 0 16px}label{display:block;font-weight:600;font-size:.9rem;margin:12px 0 4px}
input{width:100%;padding:12px;border:1px solid var(--line);border-radius:12px;font:inherit;background:var(--bg);color:var(--ink)}
button,a.b{display:block;width:100%;margin-top:18px;padding:13px;border:0;border-radius:12px;background:var(--ac);color:#fff;font:inherit;font-weight:700;cursor:pointer;text-align:center;text-decoration:none}
.e{background:#fdeceb;color:#8a1f17;padding:10px 12px;border-radius:10px;margin:0 0 12px}.o{background:#e9f6ec;color:#17602b;padding:10px 12px;border-radius:10px;margin:0 0 12px}
small{color:var(--mut)}table{width:100%;border-collapse:collapse}
</style></head><body><div class="c"><span class="brand">📖 Bookwat</span>' . $cuerpo . '</div></body></html>';
}