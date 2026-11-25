<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/paypal.php';
require_once __DIR__ . '/lib/export.php';
/* Lectura y acciones para el Centro de operaciones, con token Bearer.
   GET  export.php?view=events|summary|customers|pagos|sistema[&desde=AAAA-MM-DD][&d=7|30|90]
   POST export.php  JSON {"accion": ..., ...}
     set_acceso   {cuenta, valor: auto|activo|bloqueado}
     link_clave   {cuenta}
     crear_gracia {nombre, email, dias, motivo, clave (opcional)}
     dar_gracia   {cuenta, dias}
     quitar_gracia{cuenta}
     resync       {cuenta} */
cabeceras_seguras(); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$origen = (string)($_SERVER['HTTP_ORIGIN'] ?? ''); $permitido = (string)cfg('EXPORT_ORIGIN');
if ($permitido !== '' && $origen === $permitido) { header('Access-Control-Allow-Origin: ' . $origen); header('Vary: Origin'); header('Access-Control-Allow-Headers: Authorization, Content-Type'); header('Access-Control-Allow-Methods: GET, POST'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
function f(int $c, array $d): void { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
$pdo = db();
/* Conexión por código: el Centro manda el código de 8 dígitos (de un solo uso, 10 min) y una clave nueva. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $inP = json_decode((string)file_get_contents('php://input', false, null, 0, 1025), true);
  if (is_array($inP) && ($inP['accion'] ?? '') === 'emparejar') {
    if (freno_bloqueado('emparejar', '', 5, 15)) f(429, ['error' => 'Demasiados intentos']);
    if (!centro_emparejar(preg_replace('/\D+/', '', (string)($inP['codigo'] ?? '')), (string)($inP['clave'] ?? ''))) { freno_falla('emparejar', ''); f(401, ['error' => 'Código incorrecto o vencido']); }
    auditar('centro', 'centro_emparejado', 'conexión por código'); f(200, ['ok' => true]);
  }
}
if (!token_centro_configurado()) f(503, ['error' => 'Exportación no configurada']);
if (freno_bloqueado('export', '', 20, 15)) f(429, ['error' => 'Demasiados intentos']);
$h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$dado = preg_match('/^Bearer\s+(\S+)$/', $h, $m) ? $m[1] : '';
if (!token_centro_ok($dado)) { freno_falla('export', ''); f(401, ['error' => 'No autorizado']); }
$hoyTs = time();

function cat_de(array $x): string {
  if ($x['acceso'] === 'bloqueado') return 'bloqueado';
  if ($x['acceso'] === 'solo_lectura') return 'solo_lectura';
  if (!empty($x['manual'])) return 'manual';
  if (!empty($x['prueba'])) return 'prueba';
  return 'pago';
}
function cnt1(PDO $pdo, string $sql, array $p = []): int { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }

// ---------- acciones (POST) ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $in = json_decode((string)file_get_contents('php://input', false, null, 0, 8193), true);
  if (!is_array($in)) f(400, ['error' => 'pedido_invalido']);
  $accion = (string)($in['accion'] ?? '');
  $cid = (int)($in['cuenta'] ?? 0);
  try {
    if ($accion === 'crear_gracia') {
      $em = normalizar_email((string)($in['email'] ?? ''));
      $nom = trim((string)($in['nombre'] ?? ''));
      $cl = (string)($in['clave'] ?? '');
      $dias = max(1, min(3650, (int)($in['dias'] ?? 7)));
      $mot = mb_substr(trim((string)($in['motivo'] ?? '')), 0, 80) ?: 'Cuenta de gracia';
      if (!email_valido($em)) f(400, ['error' => 'email_invalido']);
      if ($nom === '' || mb_strlen($nom) > 120) f(400, ['error' => 'nombre_invalido']);
      $gen = ($cl === '');
      if ($gen) { $cl = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12) . '7'; }
      elseif (($m2 = clave_valida($cl)) !== null) f(400, ['error' => 'clave_invalida', 'detalle' => $m2]);
      $ex = $pdo->prepare('SELECT id FROM cuentas WHERE email = ?'); $ex->execute([$em]);
      if ($ex->fetch()) f(409, ['error' => 'email_ya_tiene_cuenta']);
      $now = ahora(); $vence = date('Y-m-d H:i:s', $hoyTs + $dias * 86400);
      $pdo->beginTransaction();
      $pdo->prepare('INSERT INTO cuentas (email, nombre, pass_hash, creada) VALUES (?,?,?,?)')->execute([$em, $nom, password_hash($cl, PASSWORD_DEFAULT), $now]);
      $nid = (int)$pdo->lastInsertId();
      $pdo->prepare('INSERT INTO suscripciones (preapproval_id, cuenta_id, payer_email, estado, motivo, monto_cent, moneda, periodo_dias, proximo_cobro, creada, actualizada) VALUES (?,?,?,"authorized",?,0,"ARS",?,?,?,?)')
          ->execute(['manual-' . $nid . '-' . bin2hex(random_bytes(4)), $nid, $em, $mot, $dias, $vence, $now, $now]);
      $pdo->commit();
      auditar('centro', 'centro_crear_gracia', "$em · $dias días · $mot");
      $out = ['ok' => true, 'cuenta' => $nid, 'vence' => $vence];
      if ($gen) $out['clave_generada'] = $cl;
      f(200, $out);
    }

    if ($cid <= 0) f(400, ['error' => 'cuenta_invalida']);
    $st = $pdo->prepare('SELECT * FROM cuentas WHERE id = ?'); $st->execute([$cid]); $c = $st->fetch();
    if (!$c) f(404, ['error' => 'cuenta_no_encontrada']);

    if ($accion === 'set_acceso') {
      $valor = (string)($in['valor'] ?? '');
      if (!in_array($valor, ['auto', 'activo', 'bloqueado'], true)) f(400, ['error' => 'valor_invalido']);
      $pdo->prepare('UPDATE cuentas SET override = ?, session_ver = session_ver + IF(? = "bloqueado", 1, 0) WHERE id = ?')->execute([$valor, $valor, $cid]);
      auditar('centro', 'centro_acceso', "cuenta $cid => $valor");
      f(200, ['ok' => true, 'acceso' => $valor]);
    }

    if ($accion === 'link_clave') {
      $tok = crear_token_recuperacion($pdo, $cid, 60);
      auditar('centro', 'centro_link_clave', "cuenta $cid");
      f(200, ['ok' => true, 'link' => rtrim((string)cfg('APP_URL'), '/') . '/recuperar.php?t=' . $tok, 'vence' => '1 hora']);
    }

    if ($accion === 'dar_gracia') {
      $dias = max(1, min(3650, (int)($in['dias'] ?? 7)));
      $st = $pdo->prepare('SELECT COUNT(*) FROM suscripciones WHERE cuenta_id = ? AND preapproval_id NOT LIKE "manual-%" AND estado = "authorized"'); $st->execute([$cid]);
      if ((int)$st->fetchColumn() > 0) f(409, ['error' => 'tiene_suscripcion_mp']);
      $st = $pdo->prepare('SELECT * FROM suscripciones WHERE cuenta_id = ? AND preapproval_id LIKE "manual-%" ORDER BY id DESC LIMIT 1'); $st->execute([$cid]); $man = $st->fetch();
      $now = ahora();
      if ($man) {
        $base = max($hoyTs, !empty($man['proximo_cobro']) ? strtotime((string)$man['proximo_cobro']) : 0);
        $vence = date('Y-m-d H:i:s', $base + $dias * 86400);
        $pdo->prepare('UPDATE suscripciones SET estado = "authorized", proximo_cobro = ?, periodo_dias = ?, actualizada = ? WHERE id = ?')->execute([$vence, $dias, $now, $man['id']]);
      } else {
        $vence = date('Y-m-d H:i:s', $hoyTs + $dias * 86400);
        $pdo->prepare('INSERT INTO suscripciones (preapproval_id, cuenta_id, payer_email, estado, motivo, monto_cent, moneda, periodo_dias, proximo_cobro, creada, actualizada) VALUES (?,?,?,"authorized","Cuenta de gracia",0,"ARS",?,?,?,?)')
            ->execute(['manual-' . $cid . '-' . bin2hex(random_bytes(4)), $cid, $c['email'], $dias, $vence, $now, $now]);
      }
      auditar('centro', 'centro_dar_gracia', $c['email'] . " · +$dias días → $vence");
      f(200, ['ok' => true, 'vence' => $vence]);
    }

    if ($accion === 'quitar_gracia') {
      $pdo->prepare('UPDATE suscripciones SET estado = "cancelled", proximo_cobro = ?, actualizada = ? WHERE cuenta_id = ? AND preapproval_id LIKE "manual-%"')->execute([ahora(), ahora(), $cid]);
      auditar('centro', 'centro_quitar_gracia', "cuenta $cid");
      f(200, ['ok' => true]);
    }

    if ($accion === 'resync') {
      $st = $pdo->prepare('SELECT preapproval_id FROM suscripciones WHERE cuenta_id = ? AND preapproval_id NOT LIKE "manual-%" ORDER BY id DESC LIMIT 1'); $st->execute([$cid]);
      $pre = (string)$st->fetchColumn();
      if ($pre === '') f(409, ['error' => 'sin_suscripcion_mp']);
      es_paypal($pre) ? pp_sync_suscripcion($pdo, $pre) : mp_sync_preapproval($pdo, $pre);
      auditar('centro', 'centro_resync', $pre);
      f(200, ['ok' => true]);
    }

    f(400, ['error' => 'accion_invalida']);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('export: ' . $e->getMessage());
    f(500, ['error' => 'fallo_interno']);
  }
}

// ---------- lectura (GET) ----------
$view = (string)($_GET['view'] ?? 'events');
if (!in_array($view, ['events', 'summary', 'customers', 'pagos', 'sistema'], true)) f(404, ['error' => 'vista_no_disponible']);
$desde = isset($_GET['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['desde']) ? (string)$_GET['desde'] : null;
if ($view === 'events') f(200, ['generado' => date('c'), 'events' => eventos_centro($pdo, $desde)]);

// ---------- pagos ----------
if ($view === 'pagos') {
  $sin = [];
  foreach ($pdo->query('SELECT preapproval_id, payer_email, estado, creada FROM suscripciones WHERE cuenta_id IS NULL AND preapproval_id NOT LIKE "manual-%" ORDER BY id DESC LIMIT 50')->fetchAll() as $s) {
    $sin[] = [
      'email_pago' => (string)$s['payer_email'], 'estado' => (string)$s['estado'], 'creada' => (string)$s['creada'],
      'link_alta' => $s['estado'] === 'authorized' ? rtrim((string)cfg('APP_URL'), '/') . '/alta.php?pid=' . $s['preapproval_id'] : null,
    ];
  }
  $cuotas = [];
  foreach ($pdo->query('SELECT p.estado, p.reintento, p.detalle, p.monto_cent, p.moneda, p.fecha_debito, p.creada, c.email FROM pagos p LEFT JOIN suscripciones s ON s.preapproval_id = p.preapproval_id LEFT JOIN cuentas c ON c.id = s.cuenta_id ORDER BY p.id DESC LIMIT 100')->fetchAll() as $p) {
    $cuotas[] = [
      'fecha' => (string)($p['fecha_debito'] ?: $p['creada']), 'email' => (string)($p['email'] ?? ''), 'estado' => (string)$p['estado'],
      'reintento' => (int)$p['reintento'], 'detalle' => (string)($p['detalle'] ?? ''), 'monto_cent' => (int)$p['monto_cent'], 'moneda' => (string)$p['moneda'],
    ];
  }
  f(200, ['generado' => date('c'), 'sin_cuenta' => $sin, 'cuotas' => $cuotas]);
}

// ---------- sistema (solo diagnóstico: nunca devuelve valores de claves) ----------
if ($view === 'sistema') {
  $suscribirme = (string)cfg('SUSCRIBIRME_URL');
  $diag = [
    'mp_token_cargado' => (string)cfg('MP_ACCESS_TOKEN') !== '',
    'webhook_secret_cargado' => (string)cfg('MP_WEBHOOK_SECRET') !== '',
    'link_suscripcion_ok' => $suscribirme !== '' && strpos($suscribirme, 'TU_PLAN') === false,
    'paypal_configurado' => pp_configurado() && (string)cfg('PAYPAL_WEBHOOK_ID') !== '',
    'link_paypal_ok' => strpos((string)cfg('PAYPAL_SUSCRIBIRME_URL'), 'TU_PLAN') === false && (string)cfg('PAYPAL_SUSCRIBIRME_URL') !== '',
    'https_ok' => strpos((string)cfg('APP_URL'), 'https://') === 0,
    'mail_real' => (string)cfg('MAIL_MODE') === 'mail',
  ];
  $ultMp = $pdo->query('SELECT MAX(recibido) FROM eventos_mp')->fetchColumn();
  $malMp = cnt1($pdo, 'SELECT COUNT(*) FROM eventos_mp WHERE recibido > ? AND resultado IN ("firma_invalida", "error")', [date('Y-m-d H:i:s', $hoyTs - 86400)]);
  $ultLand = null;
  try { $ultLand = $pdo->query('SELECT MAX(ts) FROM landing_eventos')->fetchColumn() ?: null; } catch (Throwable $e) { $ultLand = null; }
  $notif = [];
  foreach ($pdo->query('SELECT recibido, topic, data_id, firma_ok, resultado, detalle FROM eventos_mp ORDER BY id DESC LIMIT 40')->fetchAll() as $n) {
    $notif[] = ['recibido' => (string)$n['recibido'], 'topic' => (string)$n['topic'], 'data_id' => (string)$n['data_id'], 'firma_ok' => (bool)$n['firma_ok'], 'resultado' => (string)$n['resultado'], 'detalle' => (string)($n['detalle'] ?? '')];
  }
  $aud = [];
  foreach ($pdo->query('SELECT ts, actor, accion, detalle FROM auditoria ORDER BY id DESC LIMIT 60')->fetchAll() as $a) {
    $aud[] = ['ts' => (string)$a['ts'], 'actor' => (string)$a['actor'], 'accion' => (string)$a['accion'], 'detalle' => (string)($a['detalle'] ?? '')];
  }
  f(200, [
    'generado' => date('c'), 'diagnostico' => $diag, 'ultima_notificacion_mp' => $ultMp ?: null,
    'notificaciones_error_24h' => $malMp, 'landing_ultimo_evento' => $ultLand,
    'notificaciones' => $notif, 'auditoria' => $aud,
  ]);
}

$periodo = in_array((int)($_GET['d'] ?? 30), [7, 30, 90], true) ? (int)$_GET['d'] : 30;

$cuentas = $pdo->query('SELECT * FROM cuentas ORDER BY id DESC')->fetchAll();
$cnt = ['cuentas_total' => 0, 'con_acceso' => 0, 'pagando' => 0, 'en_prueba' => 0, 'gracia' => 0, 'solo_lectura' => 0, 'bloqueados' => 0];
$lista = []; $mrr = 0; $porVencerLista = [];
foreach ($cuentas as $c) {
  $x = cargar_acceso($pdo, $c); $s = $x['sub'] ?: null; $cat = cat_de($x);
  $cnt['cuentas_total']++;
  if ($x['acceso'] === 'activo') $cnt['con_acceso']++;
  if ($x['acceso'] === 'bloqueado') $cnt['bloqueados']++;
  elseif ($x['acceso'] === 'solo_lectura') $cnt['solo_lectura']++;
  elseif (!empty($x['manual'])) $cnt['gracia']++;
  elseif (!empty($x['prueba'])) $cnt['en_prueba']++;
  elseif ($x['acceso'] === 'activo') $cnt['pagando']++;
  if ($s && $s['estado'] === 'authorized' && $cat === 'pago') $mrr += (int)round($s['monto_cent'] * 30 / max(1, (int)$s['periodo_dias']));
  if ($x['acceso'] === 'activo' && !empty($x['hasta']) && in_array($cat, ['prueba', 'manual'], true) && (int)$x['hasta'] - $hoyTs <= 3 * 86400) {
    $porVencerLista[] = ['id' => (int)$c['id'], 'nombre' => (string)$c['nombre'], 'email' => (string)$c['email'], 'categoria' => $cat, 'vence' => date('c', (int)$x['hasta'])];
  }
  if ($view === 'customers' && count($lista) < 1000) {
    $lista[] = [
      'id' => (int)$c['id'], 'nombre' => (string)$c['nombre'], 'email' => (string)$c['email'],
      'categoria' => $cat, 'estado' => (string)$x['acceso'], 'motivo' => (string)($x['motivo'] ?? ''),
      'override' => (string)($c['override'] ?? 'auto'),
      'vence' => !empty($x['hasta']) ? date('c', (int)$x['hasta']) : null,
      'dias_restantes' => !empty($x['hasta']) ? (int)ceil(((int)$x['hasta'] - $hoyTs) / 86400) : null,
      'monto_cent' => $s ? (int)$s['monto_cent'] : null,
      'moneda' => $s ? (string)$s['moneda'] : null,
      'proximo_cobro' => $s ? ($s['proximo_cobro'] ?? null) : null,
      'ultimo_pago' => $x['ultimo_pago'] ?? null,
      'alta' => (string)$c['creada'], 'ultimo_ingreso' => $c['ultimo_login'] ?? null,
    ];
  }
}
if ($view === 'customers') f(200, ['generado' => date('c'), 'customers' => $lista]);

// ---------- resumen ----------
$hoy = date('Y-m-d'); $desde30 = date('Y-m-d', $hoyTs - 29 * 86400);
$desdeP = date('Y-m-d', $hoyTs - ($periodo - 1) * 86400); $desdeP00 = $desdeP . ' 00:00:00';

// campos planos de siempre (30 días), para compatibilidad
$land = ['landing_visitas_hoy' => 0, 'landing_visitas_30d' => 0, 'landing_llegaron_precio_30d' => 0, 'landing_clicks_suscribirme_30d' => 0, 'landing_clicks_whatsapp_30d' => 0, 'landing_clicks_app_30d' => 0, 'landing_conversion_pct' => null, 'landing_ultimo_evento' => null, 'landing_estado' => 'recibiendo'];
try {
  $q = $pdo->prepare('SELECT COUNT(DISTINCT IF(tipo="visita",CONCAT(dia,visitante),NULL)) v, COUNT(DISTINCT IF(tipo="precio",CONCAT(dia,visitante),NULL)) p, COUNT(DISTINCT IF(tipo="click_sub",CONCAT(dia,visitante),NULL)) c, COUNT(DISTINCT IF(tipo="click_wa",CONCAT(dia,visitante),NULL)) w, COUNT(DISTINCT IF(tipo="click_app",CONCAT(dia,visitante),NULL)) a FROM landing_eventos WHERE dia >= ?');
  $q->execute([$desde30]); $r = $q->fetch();
  $qh = $pdo->prepare('SELECT COUNT(DISTINCT visitante) FROM landing_eventos WHERE dia = ? AND tipo = "visita"'); $qh->execute([$hoy]);
  $land['landing_visitas_hoy'] = (int)$qh->fetchColumn();
  $land['landing_visitas_30d'] = (int)$r['v'];
  $land['landing_llegaron_precio_30d'] = (int)$r['p'];
  $land['landing_clicks_suscribirme_30d'] = (int)$r['c'];
  $land['landing_clicks_whatsapp_30d'] = (int)$r['w'];
  $land['landing_clicks_app_30d'] = (int)$r['a'];
  $land['landing_conversion_pct'] = (int)$r['v'] > 0 ? round((int)$r['c'] * 100 / (int)$r['v'], 1) : null;
  $ult30 = $pdo->query('SELECT MAX(ts) FROM landing_eventos')->fetchColumn();
  $land['landing_ultimo_evento'] = $ult30 ?: null;
  if (!$ult30) $land['landing_estado'] = 'sin datos: falta track.js en la landing';
} catch (Throwable $e) {
  $land['landing_estado'] = 'sin datos: falta track.js en la landing';
}
$subs = ['suscripciones_iniciadas_30d' => cnt1($pdo, 'SELECT COUNT(*) FROM suscripciones WHERE preapproval_id NOT LIKE "manual-%" AND creada >= ?', [$desde30 . ' 00:00:00'])];

// bloque del período elegido (7, 30 o 90 días)
$subsIni = cnt1($pdo, 'SELECT COUNT(*) FROM suscripciones WHERE preapproval_id NOT LIKE "manual-%" AND creada >= ?', [$desdeP00]);
$subsAut = cnt1($pdo, 'SELECT COUNT(*) FROM suscripciones WHERE preapproval_id NOT LIKE "manual-%" AND estado = "authorized" AND creada >= ?', [$desdeP00]);
$conCuenta = cnt1($pdo, 'SELECT COUNT(*) FROM suscripciones WHERE preapproval_id NOT LIKE "manual-%" AND cuenta_id IS NOT NULL AND creada >= ?', [$desdeP00]);
$conPago = cnt1($pdo, 'SELECT COUNT(DISTINCT p.preapproval_id) FROM pagos p JOIN suscripciones s ON s.preapproval_id = p.preapproval_id WHERE p.estado = "processed" AND s.creada >= ?', [$desdeP00]);
$sinCuentaPag = cnt1($pdo, 'SELECT COUNT(*) FROM suscripciones WHERE cuenta_id IS NULL AND preapproval_id NOT LIKE "manual-%" AND estado = "authorized"');
$evMal = cnt1($pdo, 'SELECT COUNT(*) FROM eventos_mp WHERE recibido > ? AND resultado IN ("firma_invalida", "error")', [date('Y-m-d H:i:s', $hoyTs - 86400)]);
$cuotasMal = cnt1($pdo, 'SELECT COUNT(*) FROM pagos WHERE estado = "recycling" AND actualizada > ?', [date('Y-m-d H:i:s', $hoyTs - 14 * 86400)]);
$ultMp = $pdo->query('SELECT MAX(recibido) FROM eventos_mp')->fetchColumn();

$L = ['tiene_datos' => false, 'ultimo_evento' => null, 'hoy' => ['visitas' => 0, 'clicks' => 0],
  'totales' => ['visitas' => 0, 'precio' => 0, 'clicks_suscribirme' => 0, 'clicks_whatsapp' => 0, 'clicks_app' => 0, 'scroll50' => 0, 'scroll90' => 0],
  'por_dia' => [], 'origen' => [], 'dispositivo' => [], 'boton' => []];
try {
  $dias = [];
  for ($i = $periodo - 1; $i >= 0; $i--) { $fd = date('Y-m-d', $hoyTs - $i * 86400); $dias[$fd] = ['dia' => $fd, 'visitas' => 0, 'clicks' => 0, 'whatsapp' => 0]; }
  $s = $pdo->prepare('SELECT dia, COUNT(DISTINCT IF(tipo="visita",visitante,NULL)) v, COUNT(DISTINCT IF(tipo="click_sub",visitante,NULL)) c, COUNT(DISTINCT IF(tipo="click_wa",visitante,NULL)) w, COUNT(DISTINCT IF(tipo="precio",visitante,NULL)) p, COUNT(DISTINCT IF(tipo="scroll50",visitante,NULL)) s5, COUNT(DISTINCT IF(tipo="scroll90",visitante,NULL)) s9, COUNT(DISTINCT IF(tipo="click_app",visitante,NULL)) a FROM landing_eventos WHERE dia >= ? GROUP BY dia');
  $s->execute([$desdeP]);
  foreach ($s->fetchAll() as $r) {
    $k = (string)$r['dia'];
    if (isset($dias[$k])) { $dias[$k]['visitas'] = (int)$r['v']; $dias[$k]['clicks'] = (int)$r['c']; $dias[$k]['whatsapp'] = (int)$r['w']; }
    $L['totales']['visitas'] += (int)$r['v']; $L['totales']['precio'] += (int)$r['p']; $L['totales']['clicks_suscribirme'] += (int)$r['c'];
    $L['totales']['clicks_whatsapp'] += (int)$r['w']; $L['totales']['clicks_app'] += (int)$r['a']; $L['totales']['scroll50'] += (int)$r['s5']; $L['totales']['scroll90'] += (int)$r['s9'];
    if ($k === $hoy) $L['hoy'] = ['visitas' => (int)$r['v'], 'clicks' => (int)$r['c']];
  }
  $L['por_dia'] = array_values($dias);
  $o = $pdo->prepare('SELECT IF(origen="","directo / sin dato",origen) o, COUNT(DISTINCT IF(tipo="visita",CONCAT(dia,visitante),NULL)) v, COUNT(DISTINCT IF(tipo="click_sub",CONCAT(dia,visitante),NULL)) c FROM landing_eventos WHERE dia >= ? GROUP BY o ORDER BY v DESC, c DESC LIMIT 12');
  $o->execute([$desdeP]);
  foreach ($o->fetchAll() as $r) $L['origen'][] = ['origen' => (string)$r['o'], 'visitas' => (int)$r['v'], 'clicks' => (int)$r['c']];
  $o = $pdo->prepare('SELECT disp d, COUNT(DISTINCT IF(tipo="visita",CONCAT(dia,visitante),NULL)) v, COUNT(DISTINCT IF(tipo="click_sub",CONCAT(dia,visitante),NULL)) c FROM landing_eventos WHERE dia >= ? AND disp <> "" GROUP BY d ORDER BY v DESC');
  $o->execute([$desdeP]);
  foreach ($o->fetchAll() as $r) $L['dispositivo'][] = ['dispositivo' => (string)$r['d'], 'visitas' => (int)$r['v'], 'clicks' => (int)$r['c']];
  $o = $pdo->prepare('SELECT IF(detalle="","—",detalle) b, COUNT(*) c FROM landing_eventos WHERE dia >= ? AND tipo = "click_sub" GROUP BY b ORDER BY c DESC');
  $o->execute([$desdeP]);
  foreach ($o->fetchAll() as $r) $L['boton'][] = ['boton' => (string)$r['b'], 'clicks' => (int)$r['c']];
  $ult = $pdo->query('SELECT MAX(ts) FROM landing_eventos')->fetchColumn();
  $L['ultimo_evento'] = $ult ?: null;
  $L['tiene_datos'] = (bool)$ult;
} catch (Throwable $e) {
  error_log('export landing: ' . $e->getMessage());
}

f(200, ['generado' => date('c'), 'periodo_dias' => $periodo] + $cnt + $subs + $land + [
  'mrr_cent' => $mrr,
  'por_vencer_3d' => count($porVencerLista), 'por_vencer' => array_slice($porVencerLista, 0, 20),
  'subs_iniciadas' => $subsIni, 'subs_autorizadas' => $subsAut, 'subs_con_cuenta' => $conCuenta, 'subs_con_pago' => $conPago,
  'sin_cuenta_pagaron' => $sinCuentaPag, 'eventos_mp_con_error_24h' => $evMal, 'cobros_rechazados_14d' => $cuotasMal,
  'mp_ultima_notificacion' => $ultMp ?: null,
  'landing' => $L,
]);