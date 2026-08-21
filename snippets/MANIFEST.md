# Snippets de Code Snippets (tabla `wp_snippets`)

Estos trozos de PHP se ejecutan con `eval()` desde el plugin **Code Snippets**.
No están en ningún fichero: viven en la base de datos. Son **invisibles a
`grep`, a las copias como código y a cualquier revisión**, y son el mayor riesgo
de caída silenciosa que queda en el servidor. Por eso son la prioridad de
versionar.

Inventario leído del panel el **21/08/2026** (`admin.php?page=snippets`):

Inventario leído del panel el **21/08/2026**. Los 8 activos están **capturados y
verificados con `php -l`** (fichero `.php` por snippet en esta carpeta). Los 6
inactivos quedan pendientes (no corren; menor prioridad).

| ID | Nombre en el panel | Estado | Fichero aquí | Qué hace |
|---:|---|---|---|---|
| 16 | `modulo_deportivo_nuevo` | inactivo | — (pendiente) | módulo deportivo (versión vieja) |
| **29** | Custom REST API | **ACTIVO** | `29-custom-rest-api.php` | Registra `v2/page-by-path`, `custom/v1/search-page`, `custom/v1/player-gallery`. Resuelve páginas traducidas vía `wpml_object_id`. |
| **30** | vplay | **ACTIVO** | `30-vplay.php` | Registra `vplay/v1/grid/<alias>`: lee un grid de Essential Grid y arma la respuesta. |
| **31** | `[ess_grid alias="vplay-portada"]` | **ACTIVO** | `31-ess_grid-vplay-portada.php` | Registra `vplay/v1/portada`: renderiza los grids `vplay-portada-2` y `vplay-portada`, hace scraping de `data-youtube`. |
| 32 | `[ess_grid alias="vplay-portada-2"]` | inactivo | — (pendiente) | variante del anterior |
| **33** | `[clasificacion_villarreal_b …]` | **ACTIVO** | `33-clasificacion-femenino.php` | ⚠️ **El nombre engaña**: el código registra la ruta **`femenino/clasificacion`** y ejecuta el shortcode `clasificacion_femenino`. |
| 34 | `[ess_grid alias="resumenes-25-26"]` | inactivo | — (pendiente) | grid de resúmenes |
| 35 | `Statistics` | inactivo | — (pendiente) | — |
| 36 | `SportsPress Player` | inactivo | — (pendiente) | ficha de jugador (versión vieja) |
| **37** | `player-details` | **ACTIVO** | `37-player-details.php` | **Caché de ficha de jugador**. v7.0: transitorios (`vcf_player_*`, `_ok` de respaldo a 1 semana), timeouts 10s/2s, cortafuegos anti-recursión, equipo desde `sp_current_team`. |
| 38 | `player` | inactivo | — (pendiente) | jugador (versión vieja) |
| **41** | `[clasificacion_femenino …]` | **ACTIVO** | `41-clasificacion-villarreal-b.php` | ⚠️ **El nombre engaña**: el código registra **`villarreal_b/clasificacion`** y ejecuta `clasificacion_villarreal_b`. |
| **42** | `get_wpforms_structure` | **ACTIVO** | `42-get_wpforms_structure.php` | Rutas `headless/v1/form/<id>`, `form-submit`, `form-nonce`: esquema y envío de formularios WPForms para Next. |
| **43** | WPML REST API Language Switcher | **ACTIVO** | `43-wpml-rest-language-switcher.php` | Lee `?locale=` y hace `wpml_switch_language()` en cada petición REST. La pieza que hace que la API respete el idioma. |

> ⚠️ **Nombres cruzados (33 y 41).** En el panel, el snippet 33 se llama como el
> del Villarreal B pero su código es el del **Femenino**, y el 41 al revés. Las
> rutas en el código son correctas (`femenino/…` y `villarreal_b/…`), solo están
> mal las etiquetas del panel. Los ficheros aquí se nombran por la ruta REAL.

## Cómo se capturó

El código no se puede sacar por el retorno del script del navegador (lo bloquea
como posible exfiltración). Se leyó del editor de cada snippet volcándolo en
base64 a la página y decodificándolo — fiel byte a byte, verificado con `php -l`.

## Pendiente

Los **6 inactivos** (16, 32, 34, 35, 36, 38). No corren, así que es menor
prioridad, pero para tenerlo completo se capturan igual cuando quieras. Alterna­
tiva de golpe: Code Snippets → *Exportar* → me pasas el `.code-snippets.json`.
