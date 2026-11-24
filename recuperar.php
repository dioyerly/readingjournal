<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/core.php';
/* Recuperar clave: pide el email, manda un link de un solo uso (1 hora) y permite elegir otra clave. */
sesion('bw_alta');
$pdo = db(); $base = rtrim((string)cfg('APP_URL'), '/') . '/'; $app = $base . 'app.html';
$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');

if ($t !== '') {
  $tok = preg_match('/^[a-f0-9]{64}$/', $t) ? $t : '';
  $fila = null;
  if ($tok !== '') { $q = $pdo->prepare('SELECT r.id, r.cuenta_id, c.email FROM recuperaciones r JOIN cuentas c ON c.id = r.cuenta_id WHERE r.token_hash = ? AND r.usado = 0 AND r.expira > ?'); $q->execute([hash('sha256', $tok), ahora()]); $fila = $q->fetch() ?: null; }
  if (!$fila) pagina('Link vencido', '<h1>Link vencido</h1><div class="e">Este link ya se usó o venció.</div><a class="b" href="recuperar.php">Pedir uno nuevo</a>');
  $err = '';
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $p1 = (string)($_POST['clave'] ?? ''); $p2 = (string)($_POST['clave2'] ?? '');
    if (!csrf_ok()) $err = 'La sesión venció. Recargá la página.';
    elseif ($p1 !== $p2) $err = 'Las dos claves no coinciden.';
    elseif (($m = clave_valida($p1)) !== null) $err = $m;
    else {
      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE recuperaciones SET usado = 1 WHERE id = ? AND usado = 0'); $u->execute([$fila['id']]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); pagina('Link vencido', '<h1>Link vencido</h1><div class="e">Este link ya se usó.</div>'); }
      $pdo->prepare('UPDATE cuentas SET pass_hash = ?, session_ver = session_ver + 1 WHERE id = ?')->execute([password_hash($p1, PASSWORD_DEFAULT), $fila['cuenta_id']]);
      $pdo->commit();
      freno_limpiar('login', (string)$fila['email']);
      auditar((string)$fila['email'], 'clave_restablecida');
      pagina('Clave cambiada', '<h1>Clave cambiada</h1><div class="o">Ya podés entrar con tu nueva clave. Cerramos las sesiones abiertas por seguridad.</div><a class="b" href="' . esc($app) . '">Entrar</a>');
    }
  }
  pagina('Nueva clave · Bookwat', '<h1>Elegí una clave nueva</h1><p>' . esc((string)$fila['email']) . '</p>' . ($err ? '<div class="e">' . esc($err) . '</div>' : '')
    . '<form method="post" action="recuperar.php"><input type="hidden" name="csrf" value="' . esc(csrf_token()) . '"><input type="hidden" name="t" value="' . esc($tok) . '">'
    . '<label for="c">Clave nueva (mínimo 10 caracteres)</label><input id="c" name="clave" type="password" required minlength="10" autocomplete="new-password">'
    . '<label for="c2">Repetí la clave</label><input id="c2" name="clave2" type="password" required minlength="10" autocomplete="new-password">'
    . '<button type="submit">Guardar clave</button></form>');
}

$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $email = normalizar_email((string)($_POST['email'] ?? ''));
  if (!csrf_ok()) $msg = '<div class="e">La sesión venció. Recargá la página.</div>';
  else {
    // siempre la misma respuesta, exista o no el email (no revelamos quién tiene cuenta)
    $msg = '<div class="o">Si ese email tiene una cuenta, te enviamos un link para elegir una clave nueva. Vence en 1 hora.</div>';
    if (email_valido($email) && !freno_bloqueado('recup', $email, 3, 60) && !freno_bloqueado('recup_ip', '', 15, 60)) {
      freno_falla('recup', $email); freno_falla('recup_ip', '');
      $q = $pdo->prepare('SELECT id FROM cuentas WHERE email = ?'); $q->execute([$email]); $cid = $q->fetchColumn();
      if ($cid) {
        $tok = crear_token_recuperacion($pdo, (int)$cid);
        enviar_mail($email, 'Recuperá tu clave de Bookwat', "Pediste cambiar tu clave. Entrá acá (el link vale 1 hora y se usa una sola vez):\n\n{$base}recuperar.php?t=$tok\n\nSi no fuiste vos, ignorá este mensaje: tu clave actual sigue igual.\n");
        auditar($email, 'recuperacion_pedida');
      }
    }
  }
}
pagina('Recuperar clave · Bookwat', '<h1>Recuperar clave</h1><p>Escribí el email con el que te suscribiste.</p>' . $msg
  . '<form method="post" action="recuperar.php"><input type="hidden" name="csrf" value="' . esc(csrf_token()) . '"><label for="e">Email</label><input id="e" name="email" type="email" required maxlength="190" autocomplete="email">'
  . '<button type="submit">Enviarme el link</button></form><p style="margin-top:14px"><small><a href="' . esc($app) . '">Volver</a></small></p>');
