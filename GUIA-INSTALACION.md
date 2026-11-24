# Bookwat · puesta en marcha (Hostinger, readingjournal.estrategia.site)

Todo se actualiza solo desde GitHub (dioyerly/readingjournal). Lo único que hacés a mano está en esta guía.

## Qué hay en el sitio
| Dirección | Qué es |
|---|---|
| `/` (index.html) | Landing de Bookwat con precio y botones de Mercado Pago y PayPal |
| `/demo.html` | Demo: la app completa con libros de ejemplo, sin cuenta. Botón para borrar todo y empezar de cero |
| `/app.html` | La app oficial. Pide entrar con correo y contraseña. Cada suscriptora arranca con su biblioteca vacía |
| `/alta.php` | A donde vuelve la lectora después de pagar: crea su contraseña |
| `/recuperar.php` | "Olvidé mi contraseña" |
| `/admin.php` | **Tu panel**: clientas, pagos, visitas de la landing, cortesía/bloqueo, eliminar cuentas, conectar con el Centro |
| `/export.php` | Lo lee tu Centro de operaciones (con token) |
| `/webhook.php` · `/paypal-webhook.php` | Avisos de Mercado Pago y de PayPal |

## 1. config.php (una sola vez)
En el administrador de archivos de Hostinger copiá `config.example.php` como `config.php` y completá:
- `DB_PASS` (y nombre/usuario si cambian). Las tablas se crean solas.
- `ADMIN_PASSWORD_HASH`: generalo con `php -r "echo password_hash('TU-CLAVE-LARGA', PASSWORD_DEFAULT);"`.
- Mercado Pago: `MP_ACCESS_TOKEN`, `MP_WEBHOOK_SECRET`, `SUSCRIBIRME_URL`.
- PayPal: `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`, `PAYPAL_WEBHOOK_ID`, `PAYPAL_SUSCRIBIRME_URL`.

## 2. Mercado Pago (pesos argentinos)
1. Plan de suscripción mensual con **1 mes de prueba gratis**. `back_url`: `https://readingjournal.estrategia.site/alta.php`
2. Webhooks → URL `https://readingjournal.estrategia.site/webhook.php`, eventos "Planes y suscripciones". Copiá la clave secreta a `MP_WEBHOOK_SECRET`.

## 3. PayPal (USD 7,60)
1. Plan mensual de USD 7,60 con 1 mes de prueba. Return URL: `https://readingjournal.estrategia.site/alta.php`
2. Webhook de la app → `https://readingjournal.estrategia.site/paypal-webhook.php`, eventos `BILLING.SUBSCRIPTION.*` y `PAYMENT.SALE.*`.

## 4. Landing
Al final de `index.html` está el bloque `CONFIG`: pegá ahí los mismos dos links de suscripción (Mercado Pago y PayPal). El precio (USD 7,60) y los tipos de cambio también se cambian ahí.

## 5. Centro de operaciones
`admin.php` → **Sistema** → **Conectar con el Centro** → copiás Nombre, URL y Token en el Centro → *Conectar app*.

## Cómo se decide quién entra
- Suscripción activa o en prueba: todo.
- Deja de pagar o se da de baja: conserva el acceso hasta el fin de lo pagado; después **solo lectura** (ve y descarga, no guarda). Nunca pierde sus datos.
- Desde el panel o el Centro: **Cortesía** (gratis), **Bloqueada**, días de gracia, link para cambiar contraseña, eliminar cuenta.

## Antes de cobrar de verdad: probá en modo prueba
- [ ] Suscribirte con una cuenta de prueba de MP: ¿vuelve a alta.php y te deja crear la contraseña?
- [ ] En el panel → Sistema: ¿aparece la notificación con **Firma: ok**?
- [ ] Lo mismo con PayPal en `PAYPAL_MODE => 'sandbox'`.
