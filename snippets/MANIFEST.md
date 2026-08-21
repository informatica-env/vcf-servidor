# Snippets de Code Snippets (tabla `wp_snippets`)

Estos trozos de PHP se ejecutan con `eval()` desde el plugin **Code Snippets**.
No están en ningún fichero: viven en la base de datos. Son **invisibles a
`grep`, a las copias como código y a cualquier revisión**, y son el mayor riesgo
de caída silenciosa que queda en el servidor. Por eso son la prioridad de
versionar.

Inventario leído del panel el **21/08/2026** (`admin.php?page=snippets`):

| ID | Nombre | Estado | Qué hace (lo que sabemos) |
|---:|---|---|---|
| 16 | `modulo_deportivo_nuevo` | inactivo | módulo deportivo (versión vieja) |
| **29** | **Custom REST API** | **ACTIVO** | registra rutas REST para Next: rutas anidadas profundas de `endavant/...`. **Probablemente aquí viven `/wph/v2/posts/search` y `vcf/v1/post-by-slug`**, de los que depende toda la web y el arreglo de traducciones. |
| **30** | **vplay** | **ACTIVO** | lógica de V Play |
| **31** | `[ess_grid alias="vplay-portada"]` | **ACTIVO** | registra `vplay/v1/portada`: renderiza el grid de Essential Grid y hace scraping de `data-youtube`. |
| 32 | `[ess_grid alias="vplay-portada-2"]` | inactivo | variante del anterior |
| **33** | `[clasificacion_villarreal_b]` | **ACTIVO** | shortcode de clasificación del Villarreal B |
| 34 | `[ess_grid alias="resumenes-25-26"]` | inactivo | grid de resúmenes |
| 35 | `Statistics` | inactivo | — |
| 36 | `SportsPress Player` | inactivo | ficha de jugador (versión vieja) |
| **37** | `player-details` | **ACTIVO** | **caché de ficha de jugador (BeSoccer)**. Es el que tocamos: timeout, caché `_ok` de respaldo, `year=2027`. Referencia en `37-player-details.REFERENCIA.php` (verificar contra la BD). |
| 38 | `player` | inactivo | jugador (versión vieja) |
| **41** | `[clasificacion_femenino]` | **ACTIVO** | shortcode de clasificación del Femenino |
| **42** | `get_wpforms_structure` | **ACTIVO** | estructura de formularios WPForms para Next |
| **43** | `WPML REST API Language Switcher` | **ACTIVO** | hace que la REST API respete `?lang=`. Relevante para i18n. |

**8 activos** (29, 30, 31, 33, 37, 41, 42, 43) — los que corren de verdad y hay
que capturar sí o sí. **6 inactivos** — conviene guardarlos igual por si se
reactivan, pero sin prisa.

## Cómo capturar el código (pendiente)

El código no se puede extraer por automatización del navegador (lo bloquea como
posible exfiltración). Dos vías, la primera es la más fácil:

**A) Exportar desde el panel (1 minuto, recomendado)**
1. Panel → *Fragmentos de código* (Code Snippets).
2. Marca la casilla de la cabecera para seleccionar todos.
3. *Acciones en lote* → **Exportar** → *Aplicar*.
4. Se descarga un `.code-snippets.json` con TODO el código. Pásamelo (o déjalo
   en Descargas) y yo lo reparto en un fichero `.php` por snippet aquí.

**B) Por SSH (si prefieres):**
```
wp db query "SELECT id,name,active FROM $(wp db prefix)snippets" --skip-column-names
wp db export snippets-backup.sql --tables=$(wp db prefix)snippets
```
El `.sql` también me vale.

> Mientras no estén capturados, este MANIFEST ya deja constancia de QUÉ existe,
> su ID y si está activo — que es la mitad del valor: si un día desaparecen,
> sabemos exactamente qué faltaba.
