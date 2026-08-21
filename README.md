# Cambios de servidor — panel.villarrealcf.es

Todo lo que se cambió **fuera del repositorio del frontend** los días 17 y 18 de
agosto de 2026. Vive únicamente en el servidor de Cloudways, así que si alguien
reinstala, migra o restaura una copia antigua, desaparece sin dejar rastro.

Este paquete existe para que eso no ocurra.

**Aplicación:** `wnevujfuhz` · **Ruta:** `~/applications/wnevujfuhz/public_html`

---

## Índice

| Qué | Dónde vive en el servidor | Aquí |
|---|---|---|
| Plugin de cabeceras de caché del API | `wp-content/plugins/vcf-cache-api/` | `plugins/vcf-cache-api/` |
| ↳ Refresco automático de V Play | *(mismo plugin)* | `plugins/vcf-cache-api/vcf-vplay-refresh.php` |
| ↳ Revalidación de plantilla → Vercel | *(mismo plugin)* | `plugins/vcf-cache-api/vcf-revalidar-plantilla.php` |
| Plugin de aligerado del panel | `wp-content/plugins/vcf-panel-ligero/` | `plugins/vcf-panel-ligero/` |
| Plugin de config. de entradas | `wp-content/plugins/villarreal-tickets-config/` | `plugins/villarreal-tickets-config/` |
| **Snippets de la base de datos** (Code Snippets) | tabla `wp_snippets` | `snippets/` (ver `MANIFEST.md`) |
| Ficha de jugador del tema | `wp-content/themes/.../single-player.php` | `tema/` |
| Bloque de rendimiento de Apache | `public_html/.htaccess` | `apache/bloque-vcf-rendimiento.htaccess` |
| Ajustes del panel de Cloudways | *(interfaz web, no hay fichero)* | `cloudways/ajustes.md` |

> **ESTADO (21/08/2026).** Capturado y verificado contra el servidor:
> - **Snippets de la BD** (8 activos) — `snippets/`, verificados con `php -l`.
> - **`villarreal-tickets-config/`** (php + css + js) — el php verificado **byte a
>   byte por SHA-256** contra el servidor.
> - **`tema/sportspress/single-player.php`** — verificado **byte a byte por
>   SHA-256** (year=2027 confirmado).
>
> **Claves redactadas.** En `villarreal-tickets-config.php` y en
> `single-player.php` la clave de la API de BeSoccer se ha sustituido por el
> placeholder **`__BESOCCER_API_KEY__`** — NO se versiona un secreto vivo. El
> valor real está solo en el servidor. Si algún día se redespliega desde git,
> hay que volver a poner la clave (y a estas alturas conviene **rotarla**).
>
> **Pendiente (baja prioridad):**
> - **`vcf-vplay-refresh.php`** — confirmar que quedó instalado y enganchado en
>   `vcf-cache-api.php` (`require_once`).
> - **6 snippets inactivos** (16, 32, 34, 35, 36, 38) — no corren.
> - **Rotar la clave de BeSoccer** (estuvo descargable hasta el 20/08).

---

## 1. `vcf-cache-api` — el plugin que hace posible cachear el API

**Qué hace:** decide, ruta por ruta, qué respuestas del API REST puede guardar
Varnish y durante cuánto tiempo. No cachea nada por sí mismo: solo emite la
cabecera `Cache-Control` que Varnish obedece.

- Listados y búsquedas → 60 s
- Recursos individuales → 300 s
- Nonces, formularios, autenticación y usuarios → `no-store`, siempre
- Cualquier respuesta que no sea 2xx → `no-store`
- Cualquier petición de un usuario identificado → `no-store`

**Por qué existe:** el plugin *WP Headless* emite un `Cache-Control: public,
max-age=600` **ciego a todas las respuestas del API**, incluidos los nonces de
formulario, que son tokens de un solo uso. Por eso alguien excluyó `/wp-json/`
entero de Varnish desde el panel de Cloudways: la intención era correcta, el
alcance no. Este plugin sustituye esa protección de brocha gorda por una regla
por ruta.

**El detalle que importa:** se engancha en `rest_post_dispatch` con **prioridad
9999**. WP Headless usa la 10. Si se baja esa prioridad, WP Headless vuelve a
ganar la cabecera y el nonce se cachea diez minutos. No tocar.

**Instalación:** copiar la carpeta a `wp-content/plugins/` y activar desde el
panel de WordPress, o `wp plugin activate vcf-cache-api`.

**Desactivación:** `wp plugin deactivate vcf-cache-api`. Sin efectos
secundarios: solo dejan de emitirse las cabeceras.

**Comprobación de que funciona:**

```
curl -sI "https://panel.villarrealcf.es/index.php?rest_route=/wph/v2/posts/search&tag=portada" | grep -i "cache-control\|x-vcf"
   -> Cache-Control: public, max-age=60, ...     X-VCF-Cache: 60s

curl -sI "https://panel.villarrealcf.es/wp-json/custom/v1/form-nonce/226664" | grep -i cache-control
   -> Cache-Control: no-store, no-cache, must-revalidate, private
```

Si la segunda devuelve `max-age` de cualquier valor, **los formularios están
rotos** y hay que revisar la prioridad del filtro.

> Nota: `curl` con su User-Agent por defecto está bloqueado en este servidor.
> Añadir `-A "Mozilla/5.0"` si devuelve 403.

---

## 2. `vcf-panel-ligero` — el panel de los editores

**Qué hace:** escritorio vacío, sin desplegable de meses en la lista de
entradas, *heartbeat* a 60 s, sin comprobaciones asíncronas de estado del sitio.

**Efecto medido:** el escritorio de wp-admin pasó de **30,75 s a ~2,7 s**.

**Incluido en el paquete**, verificado con `php -l`.

Cuatro cambios, cada uno con su motivo:

1. **Escritorio sin widgets.** Eran los conteos de «De un vistazo» sobre 355.000
   adjuntos, el chequeo de Site Health con llamadas *loopback* al propio sitio, y
   una descarga RSS bloqueante de wordpress.org.
2. **`disable_months_dropdown`.** El desplegable de meses lanza un
   `DISTINCT YEAR/MONTH` sobre `wp_posts` sin índice útil. Con esta tabla es la
   consulta más cara de `edit.php`.
3. **Heartbeat a 60 s** en lugar de 15: `admin-ajax` baja de 4 a 1 llamada por
   minuto y por pestaña abierta.
4. **Site Health** deja de ejecutar sus pruebas asíncronas en segundo plano.

**Instalación:** copiar la carpeta a `wp-content/plugins/` y activar.
**Desactivación:** `wp plugin deactivate vcf-panel-ligero`. El panel vuelve
exactamente al comportamiento anterior.

## 3. El bloque de Apache

Está en `apache/bloque-vcf-rendimiento.htaccess`. Va **al principio** de
`public_html/.htaccess`, antes del bloque de Breeze y antes del de WordPress.

Contiene dos reglas:

**a) 404 inmediato para rutas de medios con barra final.** `foo.jpg/` no puede
corresponder a ningún fichero, pero la reescritura de WordPress la mandaba a
`index.php` y arrancaba el CMS entero para devolver un 404. Medido: 1.675
peticiones y 22.501 s de CPU en dos horas, cero respuestas útiles.

Efecto: de **55,80 s a 0,042 s**, y sin entrada en el log de PHP.

> Intenté primero ponerlo en `wp-content/uploads/.htaccess` y **no funciona**:
> Cloudways solo aplica `AllowOverride` en la raíz del documento. Tiene que ir
> en el `.htaccess` de `public_html`.

**b) Bloqueo de `84.75.40.238`.** Un rastreador con 2.447 peticiones y 25.431 s
de CPU en dos horas, casi todo 404. Se comprueban `REMOTE_ADDR` y
`X-Forwarded-For` porque Nginx hace de proxy delante de Apache.

> **Esta regla nunca se ha ejercitado.** La IP dejó de venir por su cuenta a las
> 14:47 del 17/08 y no ha vuelto. No sabemos si funciona.

**Copia de seguridad en el servidor:** `.htaccess.bak-20260817-1650`

**Para revertir:** `cp .htaccess.bak-20260817-1650 .htaccess`

---

## 4. Ajustes del panel de Cloudways

Ver `cloudways/ajustes.md`. No son ficheros: son campos de la interfaz web, y
por eso son los más fáciles de perder.

---

## 5. Qué comprobar después de una migración o una restauración

1. Los dos plugins están **activos** (`wp plugin list | grep vcf`)
2. El bloque `VCF-rendimiento` está en `public_html/.htaccess`
3. El nonce sale `no-store` (comando del apartado 1)
4. Un listado por `?rest_route=` devuelve `x-cache: HIT` a la segunda petición
5. El *buffer pool* de InnoDB sigue en 4 GB, no en 10
6. La exclusión de Varnish es `\/wp-json/custom/v1/form-nonce`, no `\/wp-json/`

Si el punto 3 falla, los formularios del sitio están rotos aunque parezca que
todo va bien.
