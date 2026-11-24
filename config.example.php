<?php
// Copiá este archivo como config.php (en la misma carpeta, en el hosting) y completá la contraseña.
// config.php NO se sube a GitHub: queda solo en tu hosting.
return [
    // Base de datos MySQL (hPanel → Bases de datos)
    'db' => [
        'host' => 'localhost',                    // si no conecta, probá 'srv801.hstgr.io'
        'name' => 'u748338755_readingjournal',    // nombre de la base
        'user' => 'u748338755_readingjournal',    // usuario MySQL (revisá que coincida con hPanel)
        'pass' => 'PONER_AQUI',                   // la contraseña que elegiste
    ],

    // Dirección pública del sitio, sin barra final
    'site_url' => 'https://readingjournal.estrategia.site',

    // true = cualquiera puede crear una cuenta. Ponelo en false para cerrar los registros.
    'allow_register' => true,

    // Límites (en KB) para cuidar el espacio del hosting
    'max_state_kb' => 4096,   // biblioteca completa de una usuaria (texto)
    'max_cover_kb' => 700,    // cada foto de portada
    'max_book_mb'  => 90,     // cada libro digital (EPUB/PDF)
    'quota_mb'     => 600,    // espacio total de libros por usuaria
];
