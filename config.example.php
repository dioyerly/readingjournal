<?php
// Copiá este archivo como config.php (en la misma carpeta, en el hosting) y completalo.
// config.php NO se sube a GitHub: queda solo en tu hosting. Las claves las pegás vos acá, nunca en un chat.
return [
  // --- Base de datos (hPanel → Bases de datos) ---
  'DB_HOST' => 'localhost',
  'DB_NAME' => 'u748338755_readingjournal',
  'DB_USER' => 'u748338755_readingjournal',
  'DB_PASS' => 'PONER_AQUI',

  // Dirección pública, sin barra final. Se usa en los links de los mails.
  'APP_URL' => 'https://readingjournal.estrategia.site',

  // --- Mercado Pago (suscripción en pesos argentinos) ---
  // Link del plan de suscripción (con 1 mes de prueba) que creás en Mercado Pago. back_url del plan: https://readingjournal.estrategia.site/alta.php
  'SUSCRIBIRME_URL'   => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_plan_id=TU_PLAN',
  'MP_ACCESS_TOKEN'   => '',   // Credenciales > Access Token
  'MP_WEBHOOK_SECRET' => '',   // Tus integraciones > Webhooks > Clave secreta (URL: https://readingjournal.estrategia.site/webhook.php)
  'MP_API_BASE'       => 'https://api.mercadopago.com',
  'GRACIA_DIAS'       => 5,    // días de acceso si falla un cobro, antes de pasar a solo lectura

  // --- PayPal (suscripción en dólares, USD 7,60) ---
  'PAYPAL_SUSCRIBIRME_URL' => 'https://www.paypal.com/webapps/billing/plans/subscribe?plan_id=TU_PLAN',
  'PAYPAL_CLIENT_ID'  => '',
  'PAYPAL_SECRET'     => '',
  'PAYPAL_WEBHOOK_ID' => '',   // webhook con URL https://readingjournal.estrategia.site/paypal-webhook.php
  'PAYPAL_MODE'       => 'live',   // 'sandbox' para probar

  // --- Tu panel (admin.php) ---
  'ADMIN_EMAIL'         => 'estrategia.datos@estrategia.site',
  // php -r "echo password_hash('TU-CLAVE-LARGA', PASSWORD_DEFAULT);"
  'ADMIN_PASSWORD_HASH' => '',
  'ADMIN_PASSWORD'      => '', // alternativa simple: tu clave escrita acá (solo si no usás el hash)

  // --- Centro de operaciones ---
  // No hace falta completarlo: el token se genera desde admin.php → "Conectar con el Centro".
  'EXPORT_TOKEN'  => '',
  'EXPORT_ORIGIN' => '',

  // --- Mails ---
  'MAIL_FROM' => 'Bookwat <no-responder@estrategia.site>',
  'MAIL_MODE' => 'mail',   // 'mail' = Hostinger | 'log' = solo escribe en storage/mail.log (pruebas)

  // --- Límites de espacio ---
  'MAX_STATE_KB' => 4096,  // biblioteca de una lectora (texto)
  'MAX_COVER_KB' => 700,   // cada portada
  'MAX_BOOK_MB'  => 90,    // cada libro EPUB/PDF
  'QUOTA_MB'     => 600,   // espacio total de libros por lectora
];
