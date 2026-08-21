# Snippets de Code Snippets (tabla `wp_snippets`)

Estos trozos de PHP se ejecutan con `eval()` desde el plugin **Code Snippets**.
No están en ningún fichero: viven en la base de datos. Son **invisibles a
`grep`, a las copias como código y a cualquier revisión**, y son el mayor riesgo
de caída silenciosa que queda en el servidor. Por eso son la prioridad de
versionar.

Inventario leído del panel el **21/08/2026** (`admin.php?page=snippets`):

Inventario leído del panel el **21/08/2026**. **Los 14 snippets están capturados
y verificados byte a byte por SHA-256** contra el servidor (y `php -l`). Los
inactivos llevan sufijo `.INACTIVO` en el nombre del fichero.

| ID | Nombre en el panel | Estado | Fichero aquí | Qué hace |
|---:|---|---|---|---|
| 16 | `modulo_deportivo_nuevo` | inactivo | `16-modulo_deportivo_nuevo.INACTIVO.php` | Registra `v1/homepage`: parsea el shortcode `modulo_deportivo_nuevo`. Versión vieja. |
| **29** | Custom REST API | **ACTIVO** | `29-custom-rest-api.php` | Registra `v2/page-by-path`, `custom/v1/search-page`, `custom/v1/player-gallery`. Resuelve páginas traducidas vía `wpml_object_id`. |
| **30** | vplay | **ACTIVO** | `30-vplay.php` | Registra `vplay/v1/grid/<alias>`: lee un grid de Essential Grid y arma la respuesta. |
| **31** | `[ess_grid alias="vplay-portada"]` | **ACTIVO** | `31-ess_grid-vplay-portada.php` | Registra `vplay/v1/portada`: renderiza los grids `vplay-portada-2` y `vplay-portada`, hace scraping de `data-youtube`. |
| 32 | `[ess_grid alias="vplay-portada-2"]` | inactivo | `32-ess_grid-vplay-portada-2.INACTIVO.php` | Registra `vplay/v1/vplay-portada-2`: variante del 31 (un solo grid por alias). |
| **33** | `[clasificacion_villarreal_b …]` | **ACTIVO** | `33-clasificacion-femenino.php` | ⚠️ **El nombre engaña**: el código registra la ruta **`femenino/clasificacion`** y ejecuta el shortcode `clasificacion_femenino`. |
| 34 | `[ess_grid alias="resumenes-25-26"]` | inactivo | `34-ess_grid-data.INACTIVO.php` | Registra `ess-grid/v1/data/<alias>`: lee un grid de la tabla `eg_grids`. Versión vieja. |
| 35 | `Statistics` | inactivo | `35-statistics.INACTIVO.php` | Registra `sportspress/v2/player-statistics`. Versión vieja/segura. |
| 36 | `SportsPress Player` | inactivo | `36-sportspress-player.INACTIVO.php` | Registra `custom/v1/player-full-data/<id>` y `players-list`. Ficha de jugador (versión vieja, sustituida por el 37). |
| **37** | `player-details` | **ACTIVO** | `37-player-details.php` | **Caché de ficha de jugador**. v7.0: transitorios (`vcf_player_*`, `_ok` de respaldo a 1 semana), timeouts 10s/2s, cortafuegos anti-recursión, equipo desde `sp_current_team`. |
| 38 | `player` | inactivo | `38-player-html.INACTIVO.php` | Registra `custom/v1/player-html/<slug>`: devuelve el `<body>` scrapeado. Versión vieja. |
| **41** | `[clasificacion_femenino …]` | **ACTIVO** | `41-clasificacion-villarreal-b.php` | ⚠️ **El nombre engaña**: el código registra **`villarreal_b/clasificacion`** y ejecuta `clasificacion_villarreal_b`. |
| **42** | `get_wpforms_structure` | **ACTIVO** | `42-get_wpforms_structure.php` | Rutas `headless/v1/form/<id>`, `form-submit`, `form-nonce`: esquema y envío de formularios WPForms para Next. |
| **43** | WPML REST API Language Switcher | **ACTIVO** | `43-wpml-rest-language-switcher.php` | Lee `?locale=` y hace `wpml_switch_language()` en cada petición REST. La pieza que hace que la API respete el idioma. |

> ⚠️ **Nombres cruzados (33 y 41).** En el panel, el snippet 33 se llama como el
> del Villarreal B pero su código es el del **Femenino**, y el 41 al revés. Las
> rutas en el código son correctas (`femenino/…` y `villarreal_b/…`), solo están
> mal las etiquetas del panel. Los ficheros aquí se nombran por la ruta REAL.

## Cómo se capturó

Se leyó el código del editor de cada snippet, se volcó en base64 a la página y
se decodificó en local. Cada fichero se verificó comparando su **SHA-256** con
el del código en el servidor: **coincidencia byte a byte en los 14**.

Para reinstalar un snippet: Code Snippets → *Añadir* → pegar el contenido del
`.php` → activar. O importar de golpe con *Importar* si generas un
`.code-snippets.json`.
