# Mi Biblioteca · Reading Journal — Guía de instalación

App web (PHP 8 + MySQL/MariaDB) para Hostinger. Sitio: https://readingjournal.estrategia.site

## 1) Despliegue desde GitHub
hPanel → Sitios web → readingjournal.estrategia.site → **Git** → conectar el repo `dioyerly/readingjournal`, rama `main`, y activar **Auto-deploy**. Cada `git push` actualiza el sitio.

## 2) Base de datos
La base ya está creada en hPanel (`u748338755_readingjournal`, usuario `u748338755_readingjournal`). No hace falta importar nada: las tablas se crean solas la primera vez que se abre la app. (Opcional: importar `schema.sql` en phpMyAdmin.)

## 3) Tu contraseña (una sola vez, solo en el servidor)
En el Administrador de archivos, dentro de la carpeta del sitio (`public_html` del subdominio):
1. Copiá `config.example.php` y llamá a la copia **`config.php`**.
2. Abrilo y reemplazá `PONER_AQUI` por la contraseña de la base.
3. Revisá que `name` y `user` coincidan con hPanel (si no conecta, probá `host` = `srv801.hstgr.io`, el que muestre hPanel).

`config.php` está en `.gitignore`: **nunca se sube a GitHub** y el auto-deploy no lo toca.

## 4) Probar
Abrí https://readingjournal.estrategia.site → Ajustes → **Cuenta** → Crear cuenta. Entrá desde otro dispositivo con el mismo correo: la biblioteca y las portadas aparecen iguales.

## Lector de libros (EPUB y PDF)
En la app: pestaña **Mi lector** → subir un EPUB o PDF (sin DRM). Se lee en el celular, la tablet o la computadora, con el tema (Lila/Clásica) y el idioma elegidos. Lleva el tiempo de lectura, el progreso, el estado del libro (Leyendo → Terminado) y la racha; las frases subrayadas pasan solas a **Citas**.
- Los archivos viven en el dispositivo y, con cuenta, también en `private/books/<usuaria>/` (bloqueada por `.htaccess`).
- Las librerías del lector están en `vendor/` (PDF.js y JSZip, locales: no usan CDN). El lector se carga solo cuando se abre (`reader/`).
- Límites en `config.php`: `max_book_mb` (por libro, 90 MB) y `quota_mb` (por usuaria, 600 MB). Si tu hosting limita las subidas, subí `upload_max_filesize` y `post_max_size` en hPanel → PHP.

## Cómo funciona
- Sin cuenta, la app guarda todo en el navegador (como siempre).
- Con cuenta, cada cambio se sube solo a los pocos segundos y se baja al abrir la app desde otro dispositivo. Si no hay internet, sigue funcionando y sube después.
- Si dos dispositivos cambian a la vez, la app pregunta con cuál versión quedarse.
- Portadas: `private/covers/<usuaria>/` (bloqueada por `.htaccess`).
- Para cerrar los registros: `'allow_register' => false` en `config.php`.

## Seguridad
Contraseñas con hash (bcrypt), sesión con cookie httponly/secure, límite de intentos por IP, carpetas `lib/`, `private/` y `data/` sin acceso web, HTTPS forzado.
