<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/paypal.php';
/* Alta de credenciales después de pagar. Mercado Pago (back_url) o PayPal (return_url, con ?subscription_id=) redirigen acá y además
   enviamos el mismo link por mail. El pago SIEMPRE se verifica contra la API de MP. */
sesion('bw_alta');
$app = rtrim((string)cfg('APP_URL'), '/') . '/app.html';
$pid = (string)($_POST['pid'] ?? $_GET['pid'] ?? $_GET['preapproval_id'] ?? $_GET['subscription_id'] ?? '');

function pantalla_aviso(string $t, string $msg, string $cls = 'e', string $extra = ''): void { pagina($t, '<h1>' . esc($t) . '</h1><div class="' . $cls . '">' . esc($msg) . '</div>' . $extra); exit; }

if ($pid === '' || !mp_id_valido($pid)) {
  pantalla_aviso('Revisá tu correo', 'Te enviamos un mail con el link para crear tu usuario y tu clave. Si no lo ves, mirá en spam o escribinos.', 'o');
}
$pdo = db();
if (freno_bloqueado('alta_ip', '', 30, 15)) pantalla_aviso('Demasiados intentos', 'Esperá unos minutos y volvé a intentar.');
freno_falla('alta_ip', '');   // cada visita cuenta: limita el uso de la API de MP

try { $sub = es_paypal($pid) ? pp_sync_suscripcion($pdo, $pid) : mp_sync_preapproval($pdo, $pid); }
catch (MpNoEncontrado $e) { pantalla_aviso('No encontramos tu suscripción', 'Revisá el link que te llegó por mail.'); }
catch (Throwable $e) { error_log('alta: ' . $e->getMessage()); pantalla_aviso('Estamos verificando tu pago', 'No pudimos confirmarlo en este momento. Recargá esta página en un minuto.', 'e'); }

if (!empty($sub['cuenta_id'])) pantalla_aviso('Tu acceso ya está creado', 'Ingresá con tu email y tu clave.', 'o', '<a class="b" href="' . esc($app) . '">Ir a Bookwat</a><p style="margin-top:14px"><small>¿Olvidaste tu clave? <a href="' . esc($app) . 'recuperar.php">Recuperarla</a></small></p>');
if ($sub['estado'] !== 'authorized') pantalla_aviso('Esperando la confirmación del pago', (es_paypal($pid) ? 'PayPal' : 'Mercado Pago') . ' todavía no confirmó tu suscripción. Recargá esta página en un minuto.', 'e');

$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $nombre = trim((string)($_POST['nombre'] ?? '')); $pass = (string)($_POST['clave'] ?? ''); $pass2 = (string)($_POST['clave2'] ?? '');
  $email = $sub['payer_email'] !== '' ? $sub['payer_email'] : normalizar_email((string)($_POST['email'] ?? ''));
  if (!csrf_ok()) $err = 'La sesión venció. Recargá la página.';
  elseif ($nombre === '' || mb_strlen($nombre) > 120) $err = 'Escribí tu nombre.';
  elseif (!email_valido($email)) $err = 'Escribí un email válido.';
  elseif ($pass !== $pass2) $err = 'Las dos claves no coinciden.';
  elseif (($m = clave_valida($pass)) !== null) $err = $m;
  else {
    $pdo->beginTransaction();
    try {
      $q = $pdo->prepare('SELECT cuenta_id FROM suscripciones WHERE preapproval_id = ? FOR UPDATE'); $q->execute([$pid]);
      if ($q->fetchColumn() !== null) { $pdo->rollBack(); pantalla_aviso('Tu acceso ya está creado', 'Ingresá con tu email y tu clave.', 'o', '<a class="b" href="' . esc($app) . '">Ir a Bookwat</a>'); }
      $q = $pdo->prepare('SELECT id FROM cuentas WHERE email = ?'); $q->execute([$email]);
      if ($q->fetchColumn()) { $pdo->rollBack(); $err = 'Ese email ya tiene una cuenta. Usá "Recuperar clave" para entrar.'; }
      else {
        $pdo->prepare('INSERT INTO cuentas (email, nombre, pass_hash, creada) VALUES (?,?,?,?)')->execute([$email, $nombre, password_hash($pass, PASSWORD_DEFAULT), ahora()]);
        $cid = (int)$pdo->lastInsertId();
        $u = $pdo->prepare('UPDATE suscripciones SET cuenta_id = ? WHERE preapproval_id = ? AND cuenta_id IS NULL'); $u->execute([$cid, $pid]);
        if ($u->rowCount() !== 1) { $pdo->rollBack(); pantalla_aviso('Tu acceso ya está creado', 'Ingresá con tu email y tu clave.', 'o', '<a class="b" href="' . esc($app) . '">Ir a Bookwat</a>'); }
        $pdo->commit();
        auditar($email, 'alta_cuenta', (es_paypal($pid) ? 'paypal ' : 'preapproval ') . $pid);
        session_regenerate_id(true); $_SESSION = [];
        enviar_mail($email, 'Tu cuenta de Bookwat está lista', "Hola $nombre,\n\nTu cuenta quedó creada. Entrá desde:\n$app\n\nUsuario: $email\n");
        pantalla_aviso('¡Listo!', 'Tu cuenta quedó creada. Ya podés entrar con tu email y tu clave.', 'o', '<a class="b" href="' . esc($app) . '">Entrar a Bookwat</a>');
      }
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('alta: ' . $e->getMessage()); $err = 'No pudimos crear la cuenta. Probá de nuevo.'; }
  }
}
$emailFijo = $sub['payer_email'] !== '';
$form = '<h1>Creá tu acceso</h1><p>Tu suscripción está confirmada. Elegí la clave con la que vas a entrar.</p><div class="o">Tu primer mes es gratis: Mercado Pago o PayPal recién te cobran cuando termina.</div>'
  . ($err ? '<div class="e">' . esc($err) . '</div>' : '')
  . '<form method="post" action="alta.php"><input type="hidden" name="csrf" value="' . esc(csrf_token()) . '"><input type="hidden" name="pid" value="' . esc($pid) . '">'
  . '<label for="n">Tu nombre</label><input id="n" name="nombre" required maxlength="120" autocomplete="name" value="' . esc((string)($_POST['nombre'] ?? '')) . '">'
  . '<label for="e">Email</label><input id="e" name="email" type="email" required maxlength="190" autocomplete="email" value="' . esc($emailFijo ? $sub['payer_email'] : (string)($_POST['email'] ?? '')) . '"' . ($emailFijo ? ' readonly' : '') . '>'
  . ($emailFijo ? '<small>Es el email de tu pago en ' . (es_paypal($pid) ? 'PayPal' : 'Mercado Pago') . '.</small>' : '')
  . '<label for="c">Clave (mínimo 10 caracteres)</label><input id="c" name="clave" type="password" required minlength="10" autocomplete="new-password">'
  . '<label for="c2">Repetí la clave</label><input id="c2" name="clave2" type="password" required minlength="10" autocomplete="new-password">'
  . '<button type="submit">Crear mi cuenta</button></form>';
pagina('Creá tu acceso · Bookwat', $form);
