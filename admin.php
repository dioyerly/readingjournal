<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/paypal.php';
require_once __DIR__ . '/lib/export.php';
/* Panel del dueño: resumen, landing (visitas y clicks), clientes, pagos y diagnóstico del sistema.
   NO muestra la biblioteca de cada lectora (libros, notas, citas): solo cuenta y pago. */
sesion('bw_admin');
$pdo = db(); $adminEmail = normalizar_email((string)cfg('ADMIN_EMAIL')); $adminHash = (string)cfg('ADMIN_PASSWORD_HASH'); $adminPlain = (string)cfg('ADMIN_PASSWORD');
header('X-Robots-Tag: noindex');

function plata(int $cent, string $mon = 'ARS'): string { return ($mon === 'ARS' ? '$ ' : $mon . ' ') . number_format($cent / 100, 0, ',', '.'); }
function cuando(?string $s): string { return $s ? esc(substr($s, 0, 16)) : '—'; }
function fcorta(?string $s): string { return $s ? esc(date('d/m H:i', strtotime($s))) : '—'; }
function n(int $x): string { return number_format($x, 0, ',', '.'); }
function pct(int $a, int $b): string { return $b > 0 ? number_format($a * 100 / $b, 1, ',', '.') . '%' : '—'; }
function redir(string $q = ''): void { header('Location: admin.php' . $q); exit; }
function flash(string $tipo, string $html): void { $_SESSION['flash'] = [$tipo, $html]; }
function qa(PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function q1(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function tabla_existe(PDO $pdo, string $t): bool { try { $pdo->query('SELECT 1 FROM `' . $t . '` LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

if (($_GET['salir'] ?? '') === '1' && isset($_SESSION['admin'])) { $_SESSION = []; session_destroy(); redir(); }

// ---------- login ----------
if (empty($_SESSION['admin']) || (time() - (int)($_SESSION['admin_t'] ?? 0)) > 8 * 3600) {
  unset($_SESSION['admin']);
  $err = '';
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $e = normalizar_email((string)($_POST['email'] ?? '')); $p = (string)($_POST['clave'] ?? '');
    if (!csrf_ok()) $err = 'La sesión venció. Recargá la página.';
    elseif (freno_bloqueado('admin', '', 5, 15)) $err = 'Demasiados intentos. Esperá unos minutos.';
    elseif ($adminEmail !== '' && hash_equals($adminEmail, $e) && (($adminHash !== '' && password_verify($p, $adminHash)) || ($adminPlain !== '' && hash_equals($adminPlain, $p)))) {
      session_regenerate_id(true); $_SESSION['admin'] = true; $_SESSION['admin_t'] = time(); freno_limpiar('admin', ''); auditar($e, 'admin_login'); redir();
    } else { freno_falla('admin', ''); auditar($e ?: '-', 'admin_login_fallido'); $err = 'Datos incorrectos.'; }
  }
  pagina('Panel · Bookwat', '<h1>Panel del dueño</h1>' . ($err ? '<div class="e">' . esc($err) . '</div>' : '')
    . '<form method="post" action="admin.php"><input type="hidden" name="csrf" value="' . esc(csrf_token()) . '"><label for="e">Email</label><input id="e" name="email" type="email" required autocomplete="username"><label for="c">Clave</label><input id="c" name="clave" type="password" required autocomplete="current-password"><button type="submit">Entrar</button></form>');
  exit;
}
$_SESSION['admin_t'] = time();

// ---------- descargas ----------
if (($_GET['descargar'] ?? '') === 'centro') {
  auditar($adminEmail, 'admin_exporta_centro');
  header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="bookwat-eventos-' . date('Y-m-d') . '.json"'); header('Cache-Control: no-store');
  echo json_encode(eventos_centro($pdo), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); exit;
}
if (($_GET['descargar'] ?? '') === 'clientes') {
  auditar($adminEmail, 'admin_exporta_clientes');
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="bookwat-clientes-' . date('Y-m-d') . '.csv"'); header('Cache-Control: no-store');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['Nombre', 'Email', 'Acceso', 'Motivo', 'Vence/próximo cobro', 'Suscripción', 'Alta', 'Último ingreso']);
  foreach (qa($pdo, 'SELECT * FROM cuentas ORDER BY id DESC') as $c) { $x = cargar_acceso($pdo, $c); $s = $x['sub'];
    fputcsv($o, [$c['nombre'], $c['email'], $x['acceso'], $x['motivo'], $x['hasta'] ? date('Y-m-d H:i', (int)$x['hasta']) : '', $s['estado'] ?? '', $c['creada'], $c['ultimo_login']]); }
  fclose($o); exit;
}

// ---------- acciones (POST + CSRF) → siempre redirigen, así F5 no repite nada ----------
$vista = in_array($_GET['v'] ?? '', ['resumen', 'landing', 'clientes', 'pagos', 'sistema'], true) ? (string)$_GET['v'] : 'resumen';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $volver = '?v=' . (in_array($_POST['volver'] ?? '', ['resumen', 'landing', 'clientes', 'pagos', 'sistema'], true) ? $_POST['volver'] : 'clientes');
  if (!csrf_ok()) { flash('e', 'La sesión venció. Recargá la página e intentá de nuevo.'); redir($volver); }
  $a = (string)($_POST['a'] ?? ''); $cid = (int)($_POST['cuenta'] ?? 0);
  try {
    if ($a === 'crear_gracia') {
      $em = normalizar_email((string)($_POST['email'] ?? '')); $nom = trim((string)($_POST['nombre'] ?? '')); $cl = (string)($_POST['clave'] ?? '');
      $dias = max(1, min(3650, (int)($_POST['dias'] ?? 7))); $mot = mb_substr(trim((string)($_POST['motivo'] ?? '')), 0, 80) ?: 'Cuenta de gracia';
      $gen = false;
      if ($cl === '') { $cl = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12) . '7'; $gen = true; }
      if (!email_valido($em)) throw new RuntimeException('El email no es válido.');
      if ($nom === '' || mb_strlen($nom) > 120) throw new RuntimeException('Escribí el nombre (hasta 120 caracteres).');
      if (!$gen && ($m = clave_valida($cl)) !== null) throw new RuntimeException($m);
      if (q1($pdo, 'SELECT id FROM cuentas WHERE email = ?', [$em])) throw new RuntimeException('Ese email ya tiene una cuenta. Buscala en Clientes y usá “Dar gracia”.');
      $pdo->beginTransaction();
      $now = ahora(); $vence = date('Y-m-d H:i:s', time() + $dias * 86400);
      $pdo->prepare('INSERT INTO cuentas (email, nombre, pass_hash, creada) VALUES (?,?,?,?)')->execute([$em, $nom, password_hash($cl, PASSWORD_DEFAULT), $now]);
      $nid = (int)$pdo->lastInsertId();
      $pdo->prepare('INSERT INTO suscripciones (preapproval_id, cuenta_id, payer_email, estado, motivo, monto_cent, moneda, periodo_dias, proximo_cobro, creada, actualizada) VALUES (?,?,?,"authorized",?,0,"ARS",?,?,?,?)')
          ->execute(['manual-' . $nid . '-' . bin2hex(random_bytes(4)), $nid, $em, $mot, $dias, $vence, $now, $now]);
      $pdo->commit();
      auditar($adminEmail, 'admin_crear_gracia', "$em · $dias días · $mot");
      flash('o', 'Cuenta de gracia creada para <b>' . esc($em) . '</b> con acceso hasta el <b>' . esc(date('d/m/Y', strtotime($vence))) . '</b>.' . ($gen ? '<br>Clave generada (se muestra una sola vez): <code>' . esc($cl) . '</code>' : ''));
    } elseif ($a === 'dar_gracia' && $cid > 0) {
      $dias = max(1, min(3650, (int)($_POST['dias'] ?? 7)));
      $c = qa($pdo, 'SELECT * FROM cuentas WHERE id = ?', [$cid])[0] ?? null; if (!$c) throw new RuntimeException('No existe esa cuenta.');
      $mp = q1($pdo, 'SELECT COUNT(*) FROM suscripciones WHERE cuenta_id = ? AND preapproval_id NOT LIKE "manual-%" AND estado = "authorized"', [$cid]);
      if ((int)$mp > 0) throw new RuntimeException('Esa cuenta ya tiene una suscripción activa en Mercado Pago. Para regalar tiempo usá “Cortesía” en Acceso.');
      $man = qa($pdo, 'SELECT * FROM suscripciones WHERE cuenta_id = ? AND preapproval_id LIKE "manual-%" ORDER BY id DESC LIMIT 1', [$cid])[0] ?? null;
      $now = ahora();
      if ($man) {
        $base = max(time(), !empty($man['proximo_cobro']) ? strtotime((string)$man['proximo_cobro']) : 0); $vence = date('Y-m-d H:i:s', $base + $dias * 86400);
        $pdo->prepare('UPDATE suscripciones SET estado = "authorized", proximo_cobro = ?, periodo_dias = ?, actualizada = ? WHERE id = ?')->execute([$vence, $dias, $now, $man['id']]);
      } else {
        $vence = date('Y-m-d H:i:s', time() + $dias * 86400);
        $pdo->prepare('INSERT INTO suscripciones (preapproval_id, cuenta_id, payer_email, estado, motivo, monto_cent, moneda, periodo_dias, proximo_cobro, creada, actualizada) VALUES (?,?,?,"authorized","Cuenta de gracia",0,"ARS",?,?,?,?)')
            ->execute(['manual-' . $cid . '-' . bin2hex(random_bytes(4)), $cid, $c['email'], $dias, $vence, $now, $now]);
      }
      auditar($adminEmail, 'admin_dar_gracia', $c['email'] . " · +$dias días → $vence");
      flash('o', 'Listo: <b>' . esc($c['email']) . '</b> tiene acceso hasta el <b>' . esc(date('d/m/Y', strtotime($vence))) . '</b>.');
    } elseif ($a === 'quitar_gracia' && $cid > 0) {
      $pdo->prepare('UPDATE suscripciones SET estado = "cancelled", proximo_cobro = ?, actualizada = ? WHERE cuenta_id = ? AND preapproval_id LIKE "manual-%"')->execute([ahora(), ahora(), $cid]);
      auditar($adminEmail, 'admin_quitar_gracia', "cuenta $cid"); flash('o', 'Gracia retirada: la cuenta pasó a solo lectura.');
    } elseif ($a === 'override' && $cid > 0 && in_array($_POST['valor'] ?? '', ['auto', 'activo', 'bloqueado'], true)) {
      $pdo->prepare('UPDATE cuentas SET override = ?, session_ver = session_ver + IF(? = "bloqueado", 1, 0) WHERE id = ?')->execute([$_POST['valor'], $_POST['valor'], $cid]);
      auditar($adminEmail, 'admin_override', "cuenta $cid => " . $_POST['valor']); flash('o', 'Acceso actualizado.');
    } elseif ($a === 'link_clave' && $cid > 0) {
      $em = q1($pdo, 'SELECT email FROM cuentas WHERE id = ?', [$cid]);
      if ($em) { $tok = crear_token_recuperacion($pdo, $cid, 60); auditar($adminEmail, 'admin_link_clave', "cuenta $cid");
        flash('o', 'Link de un solo uso (1 hora) para ' . esc((string)$em) . ':<br><code style="word-break:break-all">' . esc(rtrim((string)cfg('APP_URL'), '/') . '/recuperar.php?t=' . $tok) . '</code>'); }
    } elseif ($a === 'centro_token') {
      $tok = bin2hex(random_bytes(24));
      ajuste_set('centro_token_hash', hash('sha256', $tok)); auditar($adminEmail, 'admin_token_centro', 'token nuevo generado');
      $url = rtrim((string)cfg('APP_URL'), '/') . '/export.php';
      flash('o', '<b>Conexión lista.</b> En tu Centro de operaciones → <b>Conectar app</b>, completá:<br>Nombre: <code>Bookwat</code><br>URL: <code>' . esc($url) . '</code><br>Token: <code style="word-break:break-all;font-size:15px">' . esc($tok) . '</code><br><small>Copialo ahora: por seguridad no se vuelve a mostrar. Si generás otro, el anterior deja de funcionar.</small>');
    } elseif ($a === 'eliminar_cuenta' && $cid > 0) {
      $em = (string)q1($pdo, 'SELECT email FROM cuentas WHERE id = ?', [$cid]);
      if ($em === '' || normalizar_email((string)($_POST['confirmar'] ?? '')) !== $em) throw new RuntimeException('Para eliminar la cuenta escribí su email exacto en el casillero.');
      $pdo->beginTransaction();
      $pdo->prepare('UPDATE suscripciones SET cuenta_id = NULL WHERE cuenta_id = ?')->execute([$cid]);
      $pdo->prepare('DELETE FROM biblioteca_respaldos WHERE cuenta_id = ?')->execute([$cid]);
      $pdo->prepare('DELETE FROM cuentas WHERE id = ?')->execute([$cid]);
      $pdo->commit();
      foreach (['covers', 'books'] as $dir) { $d = APP_ROOT . '/private/' . $dir . '/' . $cid; foreach (glob($d . '/*') ?: [] as $f) @unlink($f); @rmdir($d); }
      auditar($adminEmail, 'admin_elimina_cuenta', $em); flash('o', 'Cuenta ' . esc($em) . ' eliminada con su biblioteca y sus libros.');
    } elseif ($a === 'resync') {
      $pre = (string)($_POST['pre'] ?? '');
      es_paypal($pre) ? pp_sync_suscripcion($pdo, $pre) : mp_sync_preapproval($pdo, $pre); auditar($adminEmail, 'admin_resync', $pre); flash('o', 'Sincronizada con ' . (es_paypal($pre) ? 'PayPal' : 'Mercado Pago') . '.');
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!$e instanceof RuntimeException) error_log('admin: ' . $e->getMessage());
    flash('e', $e instanceof RuntimeException ? esc($e->getMessage()) : 'No se pudo completar la acción: ' . esc($e->getMessage()));
  }
  redir($volver);
}
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// ---------- datos comunes ----------
$hay_land = tabla_existe($pdo, 'landing_eventos');
$dias_rango = (int)($_GET['d'] ?? 30); if (!in_array($dias_rango, [7, 30, 90], true)) $dias_rango = 30;
$desde = date('Y-m-d', time() - ($dias_rango - 1) * 86400); $hoyD = date('Y-m-d');
$cuentas = qa($pdo, 'SELECT * FROM cuentas ORDER BY id DESC LIMIT 1000');
$info = []; $cnt = ['activo' => 0, 'prueba' => 0, 'manual' => 0, 'solo_lectura' => 0, 'bloqueado' => 0, 'pago' => 0]; $mrr = 0; $porVencer = [];
foreach ($cuentas as $c) {
  $x = cargar_acceso($pdo, $c); $s = $x['sub']; $x['cat'] = $x['acceso'] === 'bloqueado' ? 'bloqueado' : ($x['acceso'] === 'solo_lectura' ? 'solo_lectura' : (!empty($x['manual']) ? 'manual' : (!empty($x['prueba']) ? 'prueba' : 'pago')));
  $info[$c['id']] = $x; $cnt[$x['cat']]++;
  if ($x['acceso'] === 'activo') $cnt['activo']++;
  if ($s && $s['estado'] === 'authorized' && $x['cat'] === 'pago') $mrr += (int)round($s['monto_cent'] * 30 / max(1, (int)$s['periodo_dias']));
  if ($x['acceso'] === 'activo' && !empty($x['hasta']) && in_array($x['cat'], ['prueba', 'manual'], true) && $x['hasta'] - time() <= 3 * 86400) $porVencer[] = [$c, $x];
}
$sinCuenta = qa($pdo, 'SELECT * FROM suscripciones WHERE cuenta_id IS NULL AND preapproval_id NOT LIKE "manual-%" ORDER BY id DESC LIMIT 50');
$sinCuentaPagaron = array_values(array_filter($sinCuenta, fn($s) => $s['estado'] === 'authorized'));
$ev24 = qa($pdo, 'SELECT resultado, COUNT(*) c FROM eventos_mp WHERE recibido > ? GROUP BY resultado', [date('Y-m-d H:i:s', time() - 86400)]);
$evMal = 0; foreach ($ev24 as $r) if (in_array($r['resultado'], ['firma_invalida', 'error'], true)) $evMal += (int)$r['c'];
$cuotasMal = (int)q1($pdo, 'SELECT COUNT(*) FROM pagos WHERE estado = "recycling" AND actualizada > ?', [date('Y-m-d H:i:s', time() - 14 * 86400)]);

// landing
$L = ['v' => 0, 'c' => 0, 'w' => 0, 'p' => 0, 's5' => 0, 's9' => 0, 'app' => 0, 'dias' => [], 'origen' => [], 'disp' => [], 'boton' => [], 'hoyV' => 0, 'hoyC' => 0, 'ultimo' => null];
if ($hay_land) {
  $L['dias'] = [];
  for ($i = $dias_rango - 1; $i >= 0; $i--) $L['dias'][date('Y-m-d', time() - $i * 86400)] = ['v' => 0, 'c' => 0, 'w' => 0];
  foreach (qa($pdo, 'SELECT dia, COUNT(DISTINCT IF(tipo="visita",visitante,NULL)) v, COUNT(DISTINCT IF(tipo="click_sub",visitante,NULL)) c, COUNT(DISTINCT IF(tipo="click_wa",visitante,NULL)) w, COUNT(DISTINCT IF(tipo="precio",visitante,NULL)) p,
      COUNT(DISTINCT IF(tipo="scroll50",visitante,NULL)) s5, COUNT(DISTINCT IF(tipo="scroll90",visitante,NULL)) s9, COUNT(DISTINCT IF(tipo="click_app",visitante,NULL)) a FROM landing_eventos WHERE dia >= ? GROUP BY dia', [$desde]) as $r) {
    $L['dias'][$r['dia']] = ['v' => (int)$r['v'], 'c' => (int)$r['c'], 'w' => (int)$r['w']];
    foreach (['v', 'c', 'w', 'p', 's5', 's9'] as $k) $L[$k] += (int)$r[$k]; $L['app'] += (int)$r['a'];
    if ($r['dia'] === $hoyD) { $L['hoyV'] = (int)$r['v']; $L['hoyC'] = (int)$r['c']; }
  }
  $L['origen'] = qa($pdo, 'SELECT IF(origen="","directo / sin dato",origen) o, COUNT(DISTINCT IF(tipo="visita",CONCAT(dia,visitante),NULL)) v, COUNT(DISTINCT IF(tipo="click_sub",CONCAT(dia,visitante),NULL)) c FROM landing_eventos WHERE dia >= ? GROUP BY o ORDER BY v DESC, c DESC LIMIT 12', [$desde]);
  $L['disp'] = qa($pdo, 'SELECT disp d, COUNT(DISTINCT IF(tipo="visita",CONCAT(dia,visitante),NULL)) v, COUNT(DISTINCT IF(tipo="click_sub",CONCAT(dia,visitante),NULL)) c FROM landing_eventos WHERE dia >= ? AND disp <> "" GROUP BY d ORDER BY v DESC', [$desde]);
  $L['boton'] = qa($pdo, 'SELECT IF(detalle="","—",detalle) b, COUNT(*) c FROM landing_eventos WHERE dia >= ? AND tipo = "click_sub" GROUP BY b ORDER BY c DESC', [$desde]);
  $L['ultimo'] = q1($pdo, 'SELECT MAX(ts) FROM landing_eventos') ?: null;
}
$subsIni = (int)q1($pdo, 'SELECT COUNT(*) FROM suscripciones WHERE preapproval_id NOT LIKE "manual-%" AND creada >= ?', [$desde . ' 00:00:00']);
$subsAut = (int)q1($pdo, 'SELECT COUNT(*) FROM suscripciones WHERE preapproval_id NOT LIKE "manual-%" AND estado IN ("authorized") AND creada >= ?', [$desde . ' 00:00:00']);
$conCuenta = (int)q1($pdo, 'SELECT COUNT(*) FROM suscripciones WHERE preapproval_id NOT LIKE "manual-%" AND cuenta_id IS NOT NULL AND creada >= ?', [$desde . ' 00:00:00']);
$conPago = (int)q1($pdo, 'SELECT COUNT(DISTINCT p.preapproval_id) FROM pagos p JOIN suscripciones s ON s.preapproval_id = p.preapproval_id WHERE p.estado = "processed" AND s.creada >= ?', [$desde . ' 00:00:00']);

cabeceras_seguras();
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');

function csrf_in(string $a, string $volver, array $extra = []): string {
  $h = '<input type="hidden" name="csrf" value="' . esc(csrf_token()) . '"><input type="hidden" name="a" value="' . esc($a) . '"><input type="hidden" name="volver" value="' . esc($volver) . '">';
  foreach ($extra as $k => $v) $h .= '<input type="hidden" name="' . esc($k) . '" value="' . esc((string)$v) . '">';
  return $h;
}
function barras(array $dias, string $k, string $k2 = ''): string {
  $max = 1; foreach ($dias as $d) $max = max($max, $d[$k]);
  $h = '<div class="bars">';
  foreach ($dias as $f => $d) {
    $alto = $d[$k] > 0 ? max(4, (int)round($d[$k] * 100 / $max)) : 1; $alto2 = ($k2 && $d[$k2] > 0) ? max(4, (int)round($d[$k2] * 100 / $max)) : 0;
    $h .= '<div class="b" title="' . esc(date('d/m', strtotime($f))) . ': ' . $d[$k] . ($k2 ? ' · clicks ' . $d[$k2] : '') . '"><i style="height:' . $alto . '%"></i>' . ($alto2 ? '<u style="height:' . $alto2 . '%"></u>' : '') . '</div>';
  }
  return $h . '</div>';
}
function embudo(array $pasos): string {
  $top = max(1, (int)($pasos[0][1] ?? 1)); $h = '<div class="emb">';
  foreach ($pasos as $i => $p) {
    $w = $top > 0 ? max(2, (int)round($p[1] * 100 / $top)) : 2; $prev = $i > 0 ? (int)$pasos[$i - 1][1] : 0;
    $h .= '<div class="e1"><div class="el"><b>' . esc($p[0]) . '</b><small>' . esc($p[2] ?? '') . '</small></div><div class="eb"><i style="width:' . min(100, $w) . '%"></i></div><div class="ev"><b>' . n((int)$p[1]) . '</b>' . ($i > 0 ? '<small>' . pct((int)$p[1], $prev) . ' del paso anterior</small>' : '') . '</div></div>';
  }
  return $h . '</div>';
}
$TABS = ['resumen' => 'Resumen', 'landing' => 'Landing', 'clientes' => 'Clientes', 'pagos' => 'Pagos', 'sistema' => 'Sistema'];
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Panel · Bookwat</title><style>
:root{--bg:#f6f1fa;--ink:#3e2f4f;--mut:#7a6a8c;--ac:#8e5cb8;--card:#fff;--line:#e3d3f2;--ok:#17602b;--bad:#8a1f17;--warn:#8a5a00;--soft:#f1e7f8}
@media(prefers-color-scheme:dark){:root{--bg:#1d1030;--ink:#f3eafb;--mut:#b7a6c9;--ac:#c9a7ec;--card:#2a1840;--line:#45305e;--ok:#6fd08a;--bad:#ff8c80;--warn:#f0c060;--soft:#34204d}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif;padding:16px 16px 60px}
.w{max-width:1240px;margin:0 auto}h1{margin:0;font-size:1.4rem}h2{margin:26px 0 10px;font-size:1.05rem}h3{margin:0 0 10px;font-size:.95rem}
.top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.nav{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid var(--line);margin-bottom:18px}.nav a{padding:10px 16px;text-decoration:none;color:var(--mut);font-weight:600;border-bottom:3px solid transparent;margin-bottom:-1px}.nav a.on{color:var(--ink);border-color:var(--ac)}.nav a:hover{color:var(--ink)}
.k{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:12px 0}.k div{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px}.k b{display:block;font-size:1.6rem;line-height:1.2}.k span{color:var(--mut);font-size:.82rem}.k small{display:block;color:var(--mut);margin-top:2px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:14px}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:860px){.g2{grid-template-columns:1fr}}
.tw{overflow-x:auto;background:var(--card);border:1px solid var(--line);border-radius:14px}table{width:100%;border-collapse:collapse;min-width:680px}th,td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--line);vertical-align:top}tr:last-child td{border-bottom:0}th{font-size:.74rem;text-transform:uppercase;letter-spacing:.05em;color:var(--mut);background:var(--soft)}
small,.mut{color:var(--mut)}.t-activo,.ok{color:var(--ok)}.t-solo_lectura,.warn{color:var(--warn)}.t-bloqueado,.bad{color:var(--bad)}
.pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.75rem;font-weight:700;background:var(--soft)}.pill.ok{background:#e1f2e6;color:#17602b}.pill.warn{background:#fbeccb;color:#8a5a00}.pill.bad{background:#fbe0dd;color:#8a1f17}.pill.ac{background:#f6dccd;color:#9a3f17}
@media(prefers-color-scheme:dark){.pill.ok{background:#1f3a27;color:#8fe0a6}.pill.warn{background:#41361a;color:#f0c060}.pill.bad{background:#472320;color:#ff9a8f}.pill.ac{background:#442a1d;color:#f0a070}}
.o,.e,.al{padding:11px 14px;border-radius:12px;margin:0 0 12px}.o{background:#e1f2e6;color:#17602b}.e{background:#fbe0dd;color:#8a1f17}.al{background:#fbeccb;color:#6e4700}
@media(prefers-color-scheme:dark){.o{background:#1f3a27;color:#8fe0a6}.e{background:#472320;color:#ff9a8f}.al{background:#41361a;color:#f0c060}}
form.in{display:flex;gap:4px;margin:0 0 4px;flex-wrap:wrap}select,input,button{font:inherit;padding:6px 9px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink)}input{width:100%}form.in input{width:auto}
button{background:var(--ac);color:#fff;border:0;cursor:pointer;font-weight:600}button.g{background:var(--bg);color:var(--ink);border:1px solid var(--line)}button.r{background:transparent;color:var(--bad);border:1px solid var(--line)}
a.btn{background:var(--ac);color:#fff;padding:8px 14px;border-radius:10px;text-decoration:none;font-weight:600;display:inline-block}a.btn.g{background:var(--card);color:var(--ink);border:1px solid var(--line)}a{color:var(--ac)}
label{display:block;font-size:.82rem;font-weight:600;margin:10px 0 3px;color:var(--mut)}code{background:var(--soft);padding:2px 6px;border-radius:6px}
.bars{display:flex;align-items:flex-end;gap:3px;height:120px;padding-top:6px}.bars .b{flex:1;height:100%;display:flex;align-items:flex-end;position:relative;min-width:3px}.bars i{display:block;width:100%;background:var(--ac);opacity:.35;border-radius:3px 3px 0 0;position:absolute;bottom:0}.bars u{display:block;width:100%;background:var(--ac);border-radius:3px 3px 0 0;position:absolute;bottom:0}
.emb .e1{display:grid;grid-template-columns:200px 1fr 150px;gap:12px;align-items:center;padding:8px 0;border-bottom:1px dashed var(--line)}.emb .e1:last-child{border:0}.el small,.ev small{display:block;color:var(--mut);font-size:.76rem}.eb{background:var(--soft);border-radius:8px;height:22px;overflow:hidden}.eb i{display:block;height:100%;background:var(--ac);border-radius:8px}.ev b{font-size:1.15rem}
@media(max-width:700px){.emb .e1{grid-template-columns:1fr}}
.rng a{padding:5px 12px;border:1px solid var(--line);border-radius:999px;text-decoration:none;color:var(--ink);font-size:.85rem;margin-right:4px}.rng a.on{background:var(--ink);color:var(--bg);border-color:var(--ink)}
.hb{height:8px;background:var(--soft);border-radius:6px;overflow:hidden;min-width:70px}.hb i{display:block;height:100%;background:var(--ac)}
details.card>summary{cursor:pointer;font-weight:700;list-style:none}details.card>summary::before{content:'+ ';color:var(--ac)}details[open].card>summary::before{content:'– '}
.act form.in{margin-bottom:6px}
</style></head><body><div class="w">
<div class="top"><h1>Bookwat · Panel</h1><div><a class="btn g" href="admin.php?descargar=centro">Centro de operaciones</a> <a class="btn g" href="admin.php?descargar=clientes">Clientes (CSV)</a> &nbsp; <a href="admin.php?salir=1">Salir</a></div></div>
<div class="nav"><?php foreach ($TABS as $k => $t): ?><a class="<?= $vista === $k ? 'on' : '' ?>" href="admin.php?v=<?= $k ?>"><?= esc($t) ?><?php if ($k === 'resumen' && ($sinCuentaPagaron || $evMal)): ?> <span class="pill bad">!</span><?php endif; ?></a><?php endforeach; ?></div>
<?php if ($flash): ?><div class="<?= esc($flash[0]) ?>"><?= $flash[1] ?></div><?php endif; ?>

<?php if ($vista === 'resumen'): ?>
  <?php $alertas = [];
    if ($sinCuentaPagaron) $alertas[] = '<b>' . count($sinCuentaPagaron) . ' persona(s) se suscribieron pero no crearon su acceso.</b> Mirá la lista en Pagos y reenviales el link de alta.';
    if ($evMal) $alertas[] = '<b>' . $evMal . ' notificación(es) de Mercado Pago con error o firma inválida en las últimas 24 h.</b> Revisá Sistema.';
    if ($cuotasMal) $alertas[] = '<b>' . $cuotasMal . ' cobro(s) rechazados en reintento</b> en los últimos 14 días.';
    if ($porVencer) $alertas[] = '<b>' . count($porVencer) . ' cuenta(s) de prueba/gracia vencen en 3 días o menos.</b> Mirá la lista abajo.';
    if (!$hay_land || ($L['ultimo'] === null)) $alertas[] = 'Todavía no llegó ninguna visita de la landing. Falta agregar <code>&lt;script src="track.js" defer&gt;&lt;/script&gt;</code> antes de <code>&lt;/body&gt;</code> en tu index.html (mirá Landing).';
    elseif ($L['ultimo'] && time() - strtotime($L['ultimo']) > 3 * 86400) $alertas[] = 'La landing no registra visitas desde hace más de 3 días (última: ' . esc(substr($L['ultimo'], 0, 16)) . ').';
  ?>
  <?php foreach ($alertas as $al): ?><div class="al"><?= $al ?></div><?php endforeach; ?>
  <?php if (!$alertas): ?><div class="o">Todo en orden: sin alertas.</div><?php endif; ?>
  <div class="k">
    <div><span>Visitantes hoy</span><b><?= n($L['hoyV']) ?></b><small>últimos <?= $dias_rango ?> d: <?= n($L['v']) ?></small></div>
    <div><span>Clicks en Suscribirme (<?= $dias_rango ?> d)</span><b><?= n($L['c']) ?></b><small><?= pct($L['c'], $L['v']) ?> de los visitantes</small></div>
    <div><span>Suscripciones iniciadas (<?= $dias_rango ?> d)</span><b><?= n($subsIni) ?></b><small><?= n($subsAut) ?> autorizadas en MP</small></div>
    <div><span>Clientes con acceso</span><b><?= n($cnt['activo']) ?></b><small><?= n($cnt['pago']) ?> pagando · <?= n($cnt['prueba']) ?> en prueba · <?= n($cnt['manual']) ?> gracia</small></div>
    <div><span>Ingreso mensual (pagando)</span><b><?= esc(plata($mrr)) ?></b><small><?= n($cnt['solo_lectura']) ?> solo lectura · <?= n($cnt['bloqueado']) ?> bloqueados</small></div>
  </div>
  <div class="card"><h3>Embudo de los últimos <?= $dias_rango ?> días</h3>
    <?= embudo([['Visitaron la landing', $L['v'], 'visitantes únicos por día'], ['Vieron el precio', $L['p'], 'llegaron a la sección de precio'], ['Tocaron “Suscribirme”', $L['c'], 'click en cualquier botón'], ['Iniciaron suscripción en MP', $subsIni, 'checkout de Mercado Pago'], ['Crearon su cuenta', $conCuenta, 'usuario y clave en la app'], ['Ya les cobraron', $conPago, 'primer pago aprobado']]) ?>
    <p class="mut" style="margin:10px 0 0;font-size:.85rem">Las visitas cuentan visitantes únicos por día. Los pasos de Mercado Pago salen de las suscripciones reales, así que son exactos.</p></div>
  <div class="g2">
    <div class="card"><h3>Visitantes por día <span class="mut">(barra clara = visitantes, oscura = clicks en Suscribirme)</span></h3><?= $hay_land ? barras($L['dias'], 'v', 'c') : '<p class="mut">Sin datos todavía.</p>' ?></div>
    <div class="card"><h3>Cuentas que vencen pronto</h3>
      <?php if (!$porVencer): ?><p class="mut">Ninguna en los próximos 3 días.</p><?php else: ?><table style="min-width:0"><?php foreach ($porVencer as [$c, $x]): ?><tr><td><?= esc($c['nombre']) ?><br><small><?= esc($c['email']) ?></small></td><td><span class="pill warn"><?= $x['cat'] === 'prueba' ? 'prueba' : 'gracia' ?></span></td><td><?= esc(date('d/m H:i', (int)$x['hasta'])) ?></td></tr><?php endforeach; ?></table><?php endif; ?></div>
  </div>
  <div class="card"><h3>¿Qué me dicen estos números?</h3><ul style="margin:0;padding-left:18px">
    <?php if ($L['v'] >= 20 && $L['c'] === 0): ?><li><b>Hay visitas pero nadie toca “Suscribirme”.</b> Probá desde tu celular que los botones abran el link de Mercado Pago.</li><?php endif; ?>
    <?php if ($L['c'] >= 5 && $subsIni === 0): ?><li><b>Tocan “Suscribirme” pero no se inicia ninguna suscripción.</b> Abrí el link del plan en una ventana privada y fijate si Mercado Pago lo muestra bien. También revisá que el webhook llegue (Sistema).</li><?php endif; ?>
    <?php if ($subsIni > 0 && $conCuenta < $subsIni): ?><li><b>Hay <?= $subsIni - $conCuenta ?> suscripción(es) sin cuenta creada.</b> Esa gente pagó o se suscribió y no completó el alta.</li><?php endif; ?>
    <?php if ($L['v'] > 0 && $L['p'] * 100 < $L['v'] * 25): ?><li>Menos del 25% llega a ver el precio: la portada puede no estar invitando a seguir bajando.</li><?php endif; ?>
    <?php if ($L['v'] < 20 && $L['c'] < 5 && $subsIni < 1): ?><li>Todavía hay pocos datos para sacar conclusiones. Mirá de nuevo con más visitas.</li><?php endif; ?>
  </ul></div>

<?php elseif ($vista === 'landing'): ?>
  <div class="rng" style="margin-bottom:12px">Período: <?php foreach ([7, 30, 90] as $d): ?><a class="<?= $dias_rango === $d ? 'on' : '' ?>" href="admin.php?v=landing&d=<?= $d ?>"><?= $d ?> días</a><?php endforeach; ?></div>
  <?php if (!$hay_land || $L['ultimo'] === null): ?>
    <div class="card"><h3>Todavía no hay datos de la landing</h3><p>Para empezar a contar visitas y clicks:</p><ol><li>Subí <code>track.php</code> y <code>track.js</code> a la raíz del sitio (junto a <code>index.html</code>).</li><li>En tu <code>index.html</code> agregá esta línea justo antes de <code>&lt;/body&gt;</code>:<br><code>&lt;script src="track.js" defer&gt;&lt;/script&gt;</code></li><li>Entrá a tu landing desde otro dispositivo y volvé a esta pantalla.</li></ol><p class="mut">No usa cookies ni guarda tu IP: cuenta visitantes únicos por día.</p></div>
  <?php else: ?>
  <div class="k">
    <div><span>Visitantes (únicos por día)</span><b><?= n($L['v']) ?></b><small>hoy: <?= n($L['hoyV']) ?></small></div>
    <div><span>Llegaron al precio</span><b><?= n($L['p']) ?></b><small><?= pct($L['p'], $L['v']) ?> de los visitantes</small></div>
    <div><span>Clicks en “Suscribirme”</span><b><?= n($L['c']) ?></b><small><?= pct($L['c'], $L['v']) ?> de los visitantes · hoy <?= n($L['hoyC']) ?></small></div>
    <div><span>Clicks en WhatsApp</span><b><?= n($L['w']) ?></b><small><?= pct($L['w'], $L['v']) ?></small></div>
    <div><span>Scroll hasta 90%</span><b><?= n($L['s9']) ?></b><small><?= pct($L['s9'], $L['v']) ?> · 50%: <?= pct($L['s5'], $L['v']) ?></small></div>
    <div><span>“Ya tengo cuenta”</span><b><?= n($L['app']) ?></b><small>clicks hacia la app</small></div>
  </div>
  <div class="card"><h3>Visitantes y clicks por día</h3><?= barras($L['dias'], 'v', 'c') ?><p class="mut" style="margin:6px 0 0;font-size:.82rem">Último evento recibido: <?= esc(substr((string)$L['ultimo'], 0, 16)) ?></p></div>
  <div class="g2">
    <div class="card"><h3>De dónde llegan</h3><table style="min-width:0"><tr><th>Origen</th><th>Visitas</th><th>Clicks</th><th>Conv.</th></tr><?php foreach ($L['origen'] as $r): ?><tr><td><?= esc($r['o']) ?></td><td><?= n((int)$r['v']) ?></td><td><?= n((int)$r['c']) ?></td><td><?= pct((int)$r['c'], (int)$r['v']) ?></td></tr><?php endforeach; ?></table><p class="mut" style="margin:8px 0 0;font-size:.82rem">Tip: en el link de tu bio de Instagram usá <code>?utm_source=instagram_bio</code> y en el de cada Reel <code>?utm_source=reel</code>.</p></div>
    <div class="card"><h3>Dispositivo</h3><table style="min-width:0"><tr><th></th><th>Visitas</th><th>Clicks</th><th>Conv.</th></tr><?php $nm = ['movil' => 'Celular', 'tablet' => 'Tablet', 'pc' => 'Computadora']; foreach ($L['disp'] as $r): ?><tr><td><?= esc($nm[$r['d']] ?? $r['d']) ?></td><td><?= n((int)$r['v']) ?></td><td><?= n((int)$r['c']) ?></td><td><?= pct((int)$r['c'], (int)$r['v']) ?></td></tr><?php endforeach; ?></table>
      <h3 style="margin-top:18px">Qué botón tocan</h3><table style="min-width:0"><?php $bn = ['menu' => 'Menú superior', 'portada' => 'Portada', 'precio' => 'Sección de precio']; foreach ($L['boton'] as $r): ?><tr><td><?= esc($bn[$r['b']] ?? $r['b']) ?></td><td><?= n((int)$r['c']) ?></td></tr><?php endforeach; if (!$L['boton']) echo '<tr><td class="mut">Todavía ninguno.</td></tr>'; ?></table></div>
  </div>
  <div class="card"><h3>Detalle por día</h3><div class="tw" style="border:0"><table style="min-width:0"><tr><th>Día</th><th>Visitantes</th><th>Clicks Suscribirme</th><th>Conv.</th><th>WhatsApp</th></tr><?php foreach (array_reverse($L['dias'], true) as $f => $d): ?><tr><td><?= esc(date('d/m (D)', strtotime($f))) ?></td><td><?= n($d['v']) ?></td><td><?= n($d['c']) ?></td><td><?= pct($d['c'], $d['v']) ?></td><td><?= n($d['w']) ?></td></tr><?php endforeach; ?></table></div></div>
  <?php endif; ?>

<?php elseif ($vista === 'clientes'): ?>
  <?php $q = trim((string)($_GET['q'] ?? '')); $f = (string)($_GET['f'] ?? 'todos'); ?>
  <details class="card" <?= isset($_GET['nuevo']) ? 'open' : '' ?>><summary>Crear cuenta de gracia (prueba o regalo)</summary>
    <form method="post" action="admin.php" style="max-width:560px;margin-top:6px"><?= csrf_in('crear_gracia', 'clientes') ?>
      <div class="g2" style="gap:10px"><div><label for="n1">Nombre</label><input id="n1" name="nombre" required maxlength="120"></div><div><label for="e1">Email</label><input id="e1" name="email" type="email" required maxlength="190"></div></div>
      <div class="g2" style="gap:10px"><div><label for="d1">Días de acceso</label><input id="d1" name="dias" type="number" value="7" min="1" max="3650" required></div><div><label for="m1">Motivo (solo para vos)</label><input id="m1" name="motivo" maxlength="80" placeholder="Regalo, prueba, influencer…"></div></div>
      <label for="c1">Clave (opcional)</label><input id="c1" name="clave" type="text" autocomplete="off" placeholder="Vacío = se genera una segura y te la muestro"><small>Mínimo 10 caracteres si la escribís vos. Después la persona puede cambiarla desde “Olvidé mi clave”.</small>
      <div style="margin-top:12px"><button type="submit">Activar cuenta</button></div></form></details>
  <form method="get" action="admin.php" class="in" style="margin:6px 0 12px"><input type="hidden" name="v" value="clientes"><input name="q" value="<?= esc($q) ?>" placeholder="Buscar por nombre o email" style="min-width:240px"><select name="f"><?php foreach (['todos' => 'Todos', 'pago' => 'Pagando', 'prueba' => 'En prueba (MP)', 'manual' => 'Gracia', 'solo_lectura' => 'Solo lectura', 'bloqueado' => 'Bloqueados'] as $k => $l): ?><option value="<?= $k ?>" <?= $f === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><button class="g">Filtrar</button></form>
  <div class="tw"><table><tr><th>Cliente</th><th>Estado</th><th>Vence / próximo cobro</th><th>Último pago</th><th>Alta · ingreso</th><th>Acciones</th></tr>
  <?php $mostradas = 0; foreach ($cuentas as $c): $x = $info[$c['id']]; $s = $x['sub'];
    if ($q !== '' && stripos($c['nombre'] . ' ' . $c['email'], $q) === false) continue; if ($f !== 'todos' && $x['cat'] !== $f) continue; $mostradas++;
    $pill = ['pago' => ['ok', 'Pagando'], 'prueba' => ['ac', 'Prueba MP'], 'manual' => ['ac', 'Gracia'], 'solo_lectura' => ['warn', 'Solo lectura'], 'bloqueado' => ['bad', 'Bloqueado']][$x['cat']];
    $opts = ''; foreach (['auto' => 'Automático', 'activo' => 'Cortesía (siempre activo)', 'bloqueado' => 'Bloqueado'] as $k => $l) $opts .= '<option value="' . $k . '"' . ($c['override'] === $k ? ' selected' : '') . '>' . $l . '</option>'; ?>
    <tr><td><b><?= esc($c['nombre']) ?></b><br><small><?= esc($c['email']) ?></small></td>
      <td><span class="pill <?= $pill[0] ?>"><?= $pill[1] ?></span><?= $c['override'] !== 'auto' ? ' <span class="pill">' . esc($c['override']) . '</span>' : '' ?><br><small><?= esc($x['motivo']) ?></small></td>
      <td><?php if (!empty($x['hasta'])): ?><?= esc(date('d/m/Y H:i', (int)$x['hasta'])) ?><?php if ($x['acceso'] === 'activo'): $r = (int)ceil(((int)$x['hasta'] - time()) / 86400); ?><br><small>faltan <?= $r ?> día(s)</small><?php endif; else: ?>—<?php endif; ?><?php if ($s && $x['cat'] === 'pago'): ?><br><small><?= esc(plata((int)$s['monto_cent'], $s['moneda'])) ?> · próx. <?= cuando($s['proximo_cobro']) ?></small><?php endif; ?></td>
      <td><?= cuando($x['ultimo_pago']) ?></td><td><?= cuando($c['creada']) ?><br><small>ingresó <?= cuando($c['ultimo_login']) ?></small></td>
      <td class="act">
        <form method="post" action="admin.php" class="in"><?= csrf_in('dar_gracia', 'clientes', ['cuenta' => $c['id']]) ?><input name="dias" type="number" value="7" min="1" max="3650" style="width:70px" aria-label="Días"><button class="g">Dar / extender gracia</button></form>
        <form method="post" action="admin.php" class="in"><?= csrf_in('override', 'clientes', ['cuenta' => $c['id']]) ?><select name="valor"><?= $opts ?></select><button class="g">Guardar</button></form>
        <details class="in"><summary class="g" style="cursor:pointer;font-size:13px;color:#a33">Eliminar cuenta…</summary><form method="post" action="admin.php" style="margin-top:6px"><?= csrf_in('eliminar_cuenta', 'clientes', ['cuenta' => $c['id']]) ?><input name="confirmar" placeholder="escribí su email" style="width:170px"> <button class="g" style="color:#a33">Eliminar para siempre</button></form></details>
        <form method="post" action="admin.php" class="in"><?= csrf_in('link_clave', 'clientes', ['cuenta' => $c['id']]) ?><button class="g">Link de clave</button><?php if ($s && strpos((string)$s['preapproval_id'], 'manual-') !== 0): ?></form><form method="post" action="admin.php" class="in"><?= csrf_in('resync', 'clientes', ['pre' => $s['preapproval_id']]) ?><button class="g">Sincronizar con MP</button><?php elseif ($s && $x['cat'] === 'manual'): ?></form><form method="post" action="admin.php" class="in"><?= csrf_in('quitar_gracia', 'clientes', ['cuenta' => $c['id']]) ?><button class="r">Quitar gracia</button><?php endif; ?></form></td></tr>
  <?php endforeach; if (!$mostradas) echo '<tr><td colspan="6" class="mut">' . ($cuentas ? 'Ningún cliente coincide con el filtro.' : 'Todavía no hay clientes.') . '</td></tr>'; ?></table></div>

<?php elseif ($vista === 'pagos'): ?>
  <?php if ($sinCuenta): ?><h2>Se suscribieron pero no crearon su acceso</h2><div class="tw"><table><tr><th>Email del pago</th><th>Estado</th><th>Fecha</th><th>Link de alta para reenviarle</th></tr>
    <?php foreach ($sinCuenta as $s): ?><tr><td><?= esc($s['payer_email'] ?: '—') ?></td><td><span class="pill <?= $s['estado'] === 'authorized' ? 'warn' : '' ?>"><?= esc($s['estado']) ?></span></td><td><?= cuando($s['creada']) ?></td><td><?php if ($s['estado'] === 'authorized'): ?><code style="word-break:break-all"><?= esc(rtrim((string)cfg('APP_URL'), '/') . '/alta.php?pid=' . $s['preapproval_id']) ?></code><?php else: ?><small>sin autorizar</small><?php endif; ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
  <h2>Cuotas cobradas</h2><div class="tw"><table><tr><th>Fecha</th><th>Cliente</th><th>Estado</th><th>Monto</th></tr>
  <?php $pagos = qa($pdo, 'SELECT p.*, c.email FROM pagos p LEFT JOIN suscripciones s ON s.preapproval_id = p.preapproval_id LEFT JOIN cuentas c ON c.id = s.cuenta_id ORDER BY p.id DESC LIMIT 100'); foreach ($pagos as $p): ?>
    <tr><td><?= cuando($p['fecha_debito'] ?: $p['creada']) ?></td><td><?= esc((string)$p['email']) ?></td><td><span class="pill <?= $p['estado'] === 'processed' ? 'ok' : ($p['estado'] === 'recycling' ? 'bad' : '') ?>"><?= esc($p['estado']) ?></span><?= $p['reintento'] ? ' (reintento ' . (int)$p['reintento'] . ')' : '' ?><br><small><?= esc($p['detalle']) ?></small></td><td><?= esc(plata((int)$p['monto_cent'], $p['moneda'])) ?></td></tr>
  <?php endforeach; if (!$pagos) echo '<tr><td colspan="4" class="mut">Sin pagos todavía. Si hay gente en prueba, el primer cobro aparece cuando termine.</td></tr>'; ?></table></div>

<?php else: /* sistema */ ?>
  <?php $ult = q1($pdo, 'SELECT MAX(recibido) FROM eventos_mp'); $okc = fn(bool $b, string $si, string $no) => '<span class="pill ' . ($b ? 'ok' : 'bad') . '">' . ($b ? esc($si) : esc($no)) . '</span>'; ?>
  <div class="card"><h3>Centro de operaciones</h3>
    <p class="mut">Conectá Bookwat a tu Centro (panel.estrategia.site) para ver clientas, pagos, visitas de la landing y suspender o activar cuentas desde ahí.</p>
    <p>Estado: <?= token_centro_configurado() ? '<span class="pill ok">token generado</span>' : '<span class="pill warn">sin conectar</span>' ?></p>
    <form method="post" action="admin.php" onsubmit="return <?= token_centro_configurado() ? "confirm('Se genera un token nuevo y el anterior deja de funcionar. ¿Seguimos?')" : 'true' ?>"><?= csrf_in('centro_token', 'sistema') ?><button><?= token_centro_configurado() ? 'Generar token nuevo' : 'Conectar con el Centro (generar token)' ?></button></form>
  </div>
  <div class="card"><h3>Diagnóstico</h3><table style="min-width:0">
    <tr><td>Credencial de Mercado Pago</td><td><?= $okc((string)cfg('MP_ACCESS_TOKEN') !== '', 'cargada', 'falta') ?></td></tr>
    <tr><td>Clave secreta del webhook</td><td><?= $okc((string)cfg('MP_WEBHOOK_SECRET') !== '', 'cargada', 'falta') ?></td></tr>
    <tr><td>Link de suscripción (SUSCRIBIRME_URL)</td><td><?= $okc(strpos((string)cfg('SUSCRIBIRME_URL'), 'TU_PLAN') === false && (string)cfg('SUSCRIBIRME_URL') !== '', 'configurado', 'sin completar') ?></td></tr>
    <tr><td>PayPal (claves y webhook)</td><td><?= $okc(pp_configurado() && (string)cfg('PAYPAL_WEBHOOK_ID') !== '', 'configurado', 'falta') ?></td></tr>
    <tr><td>Link de suscripción de PayPal</td><td><?= $okc(strpos((string)cfg('PAYPAL_SUSCRIBIRME_URL'), 'TU_PLAN') === false && (string)cfg('PAYPAL_SUSCRIBIRME_URL') !== '', 'configurado', 'sin completar') ?></td></tr>
    <tr><td>Sitio con HTTPS</td><td><?= $okc(strpos((string)cfg('APP_URL'), 'https://') === 0, 'sí', 'no') ?></td></tr>
    <tr><td>Envío de mails</td><td><?= $okc((string)cfg('MAIL_MODE') === 'mail', 'real', 'modo prueba (no envía)') ?></td></tr>
    <tr><td>Última notificación de Mercado Pago</td><td><?= $ult ? esc(substr((string)$ult, 0, 16)) : '<span class="pill warn">ninguna todavía</span>' ?></td></tr>
    <tr><td>Notificaciones con error (24 h)</td><td><?= $evMal ? '<span class="pill bad">' . $evMal . '</span>' : '<span class="pill ok">0</span>' ?></td></tr>
    <tr><td>Contador de landing</td><td><?= $hay_land && $L['ultimo'] ? $okc(true, 'recibiendo (último ' . substr((string)$L['ultimo'], 0, 16) . ')', '') : '<span class="pill warn">sin datos: falta track.js en la landing</span>' ?></td></tr>
  </table></div>
  <h2>Notificaciones de Mercado Pago</h2><div class="tw"><table><tr><th>Recibido</th><th>Tema</th><th>ID</th><th>Firma</th><th>Resultado</th></tr>
  <?php foreach (qa($pdo, 'SELECT * FROM eventos_mp ORDER BY id DESC LIMIT 40') as $e): ?><tr><td><?= cuando($e['recibido']) ?></td><td><?= esc($e['topic']) ?></td><td><?= esc($e['data_id']) ?></td><td><?= $e['firma_ok'] ? 'ok' : '<b class="bad">no</b>' ?></td><td><span class="pill <?= $e['resultado'] === 'ok' ? 'ok' : (in_array($e['resultado'], ['error', 'firma_invalida'], true) ? 'bad' : '') ?>"><?= esc($e['resultado']) ?></span><br><small><?= esc($e['detalle']) ?></small></td></tr><?php endforeach; ?></table></div>
  <h2>Auditoría</h2><div class="tw"><table><tr><th>Fecha</th><th>Quién</th><th>Acción</th><th>Detalle</th></tr>
  <?php foreach (qa($pdo, 'SELECT * FROM auditoria ORDER BY id DESC LIMIT 60') as $e): ?><tr><td><?= cuando($e['ts']) ?></td><td><?= esc($e['actor']) ?></td><td><?= esc($e['accion']) ?></td><td><small><?= esc($e['detalle']) ?></small></td></tr><?php endforeach; ?></table></div>
<?php endif; ?>
</div></body></html>