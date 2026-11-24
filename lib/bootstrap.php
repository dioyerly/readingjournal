<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

function out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function body(): array {
    $raw = file_get_contents('php://input', false, null, 0, 12 * 1024 * 1024);
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 45); }

function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('rjsess');
    session_set_cookie_params(['lifetime' => 60 * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.gc_maxlifetime', (string)(60 * 86400));
    session_start();
}
function current_user(): ?array {
    session_boot();
    $id = (int)($_SESSION['uid'] ?? 0);
    if (!$id) return null;
    $pdo = db_ready(); if (!$pdo) return null;
    $st = $pdo->prepare('SELECT id,email,name FROM users WHERE id=?'); $st->execute([$id]);
    $u = $st->fetch();
    return $u ?: null;
}
function require_user(): array {
    $u = current_user();
    if (!$u) out(['ok' => false, 'msg' => 'Necesitás iniciar sesión.'], 401);
    return $u;
}
function rate_blocked(PDO $pdo): bool {
    $pdo->prepare('DELETE FROM login_attempts WHERE at < (NOW() - INTERVAL 1 DAY)')->execute();
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND at > (NOW() - INTERVAL 15 MINUTE)');
    $st->execute([client_ip()]);
    return (int)$st->fetchColumn() >= 10;
}
function rate_fail(PDO $pdo): void { $pdo->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, NOW())')->execute([client_ip()]); }
