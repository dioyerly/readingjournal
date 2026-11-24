<?php
declare(strict_types=1);

/** Conexión PDO a MySQL, o null si todavía no está configurada o falla. */
function db(): ?PDO {
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    $pdo = null;
    $c = cfg('db');
    if (!is_array($c) || empty($c['pass']) || $c['pass'] === 'PONER_AQUI') { $GLOBALS['db_error'] = 'Falta completar config.php'; return null; }
    try {
        $pdo = new PDO(
            'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4',
            (string)$c['user'], (string)$c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        try { $pdo->exec("SET time_zone = '-03:00'"); } catch (Throwable $e) {}
    } catch (Throwable $e) {
        error_log('DB: ' . $e->getMessage());
        $GLOBALS['db_error'] = $e->getMessage();
        $pdo = null;
    }
    return $pdo;
}

/** Crea las tablas si todavía no existen (no borra nada). */
function db_install(PDO $pdo): void {
    $sql = (string)file_get_contents(__DIR__ . '/../schema.sql');
    foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql)))) as $q) $pdo->exec($q);
}

function db_ready(): ?PDO {
    static $ok = null;
    if ($ok !== null) return $ok ?: null;
    $pdo = db();
    if (!$pdo) { $ok = false; return null; }
    try { $pdo->query('SELECT 1 FROM users LIMIT 1'); $pdo->query('SELECT 1 FROM states LIMIT 1'); $pdo->query('SELECT 1 FROM login_attempts LIMIT 1'); }
    catch (Throwable $e) {
        try { db_install($pdo); } catch (Throwable $e2) { error_log('DB install: ' . $e2->getMessage()); $ok = false; return null; }
    }
    $ok = $pdo; return $pdo;
}
