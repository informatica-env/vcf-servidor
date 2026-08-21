# Traspaso — stack villarrealcf.es

Estado a **21/08/2026**. Documento para quien coja el proyecto: cómo está
montado, qué se ha tocado estos días, cómo funcionan las cachés (que es donde
está casi toda la miga) y qué queda pendiente.

---

## 1. Arquitectura en dos plataformas

| | Frontend | Backend |
|---|---|---|
| **Qué** | Next.js 16 (App Router) | WordPress *headless* + WPML |
| **Dónde** | Vercel — `villarrealcf.es` | Cloudways — `panel.villarrealcf.es` |
| **Repo** | `github.com/informatica-env/villarrealcf-web` | `github.com/informatica-env/vcf-servidor` (este) |
| **Idiomas** | `es` (base), `en`, `val` | WPML: `es`, `en`, `val` (código WPML: `ca` para valenciano) |

El frontend **no** renderiza WordPress: consume su **REST API** y pinta. Casi
todo el contenido sale de endpoints REST custom (ver `snippets/`).

**Importante:** buena parte del código del backend **no vive en ficheros**, sino
en la **base de datos** (plugin *Code Snippets*, ejecutado con `eval()`), o en
el tema. Es invisible a `grep`, a las copias de seguridad como código y a
cualquier revisión. **Este repo (`vcf-servidor`) existe justo para eso**: tener
ese código a salvo y revisable. Ver `README.md` y `snippets/MANIFEST.md`.

---

## 2. Las cachés — léete esto antes de tocar nada

Hay **cinco capas** de caché en serie entre que un editor guarda algo y un
visitante lo ve:

```
WordPress → Breeze → Varnish (300 s) → Next Data Cache → CDN de Vercel → visitante
```

Reglas que hemos aprendido a base de romperlas:

- **El orden de purga importa.** Para refrescar algo ya: purgar **Varnish
  primero**, avisar a Vercel **después**. Al revés, Next va a buscar el dato,
  Varnish le da su copia vieja, y Next se la vuelve a guardar otra hora.
- **Varnish** no cachea URLs que contengan `wp-json`. Por eso el frontend pide
  por la "puerta nueva" `index.php?rest_route=/...` (ver `lib/wp-api.ts` →
  `puertaCache()`), que **sí** se cachea. Las dos puertas devuelven lo mismo.
- **Purga de Varnish**: método HTTP `PURGE`, y **User-Agent de navegador**
  (`Mozilla/5.0`) — con el UA por defecto de cURL, Varnish devuelve 403. La URL
  tiene que coincidir **carácter a carácter** con la que pide el frontend.
- **Redis (object-cache-pro)** guarda los *transients* en Redis, **no** en
  `wp_options`. Cualquier plugin que "limpie transients" haciendo `grep` sobre
  `wp_options` **no encuentra nada y no limpia** (nos pasó con Essential Grid).
- **`trailingSlash: true`** en Next: una URL sin barra final da **308**. Aplica
  también a las llamadas `/api/*`. Todos los enlaces internos y fetches deben
  llevar barra final y, si son de página, el prefijo de idioma (`/en/...`).

---

## 3. Qué se ha tocado estos días

### 3.1 · Traducciones de noticias en el idioma equivocado (definitivo, 21/08)
**Síntoma:** en `/en/` salía el titular de una noticia en castellano.
**Causa:** el editor crea la traducción duplicando la española (título aún en
castellano) y la traduce minutos/horas después. Si alguien entra en `/en/` en
ese hueco, `lib/translate-plain.ts` cacheaba ese título-placeholder **24 h**.
**Arreglo (mergeado a `main`):** la caché de la traducción lleva ahora la
**fecha de modificación** del post traducido en la clave; en cuanto el editor
toca la traducción, Next la relee sola (≤5 min). Cero traducción automática: la
traducción la sigue escribiendo el editor en WPML. Verificado en producción.
Fichero: `villarrealcf-web/lib/translate-plain.ts`.

### 3.2 · Enlaces sin idioma + prefetch (grueso del ahorro de Vercel)
Los `<Link>` internos iban sin prefijo de idioma (`/primer-equipo/`) y el
middleware los redirigía a `/es/...`. Como Next prefetchea los Links, esa
redirección ocurría en cada carga **sin que nadie pulsara**. Arreglado:
cabecera, menú, pie, portada con `conIdioma(...)` + `prefetch={false}`
(`lib/enlace-idioma.ts`). **Pendiente el mismo patrón en `GlobalTabs.tsx`** (22
secciones) — ver §5.

### 3.3 · Venta de entradas — refresco instantáneo
Plugin **`villarreal-tickets-config`** (en este repo). Panel → *Venta de
Entradas* activa/desactiva partidos. Al guardar: purga Varnish (dos formas de
URL) y avisa a Vercel con `&tag=entradas` (revalidación por etiqueta; un
`revalidatePath` sobre rutas dinámicas respondía 200 y no refrescaba nada). El
resultado del aviso queda en `get_option('vcf_tickets_ultimo_aviso')` (ok +
código HTTP de Vercel). Endpoint que consume el front: `vcf/v1/tickets-config`.

### 3.4 · V Play — la portada no se actualizaba
**Causa:** la portada de V Play sale de un grid de Essential Grid que lee una
**lista de reproducción de YouTube** y cachea el resultado **24 h** (transient
`essgrid_…`, `transient_sec=86400`). Su auto-limpieza hace `grep` sobre
`wp_options` → rota bajo Redis → nunca se limpia. **Arreglo:** `vcf-vplay-
refresh.php` (en `plugins/vcf-cache-api/`), un cron cada 10 min que re-renderiza
el grid forzando `data[clear_cache]=youtube` y purga Varnish. **PENDIENTE
confirmar que quedó instalado y enganchado** (`require_once` en
`vcf-cache-api.php`) — ver §5.

### 3.5 · Fichas de jugador (snippet 37)
Se pedía a BeSoccer `year=2026` y se emparejaba por dorsal → datos de otro
jugador. Corregido a `year=2027`. Además el snippet 37 tenía `CURLOPT_TIMEOUT=4`:
al purgar Breeze (cada vez que se guarda un post) varias fichas frías se pasaban
de 4 s, se cacheaba el error y Varnish lo servía 300 s. Ahora: timeouts 10s/2s,
caché `_ok` de respaldo (1 semana), caché negativa corta, `no-store` en error,
equipo leído de `sp_current_team` (sin HTTP interno). Ver
`snippets/37-player-details.php`.

### 3.6 · Cuerpo técnico → SportsPress
El cuerpo técnico estaba escrito a mano en el frontend. Migrado a `sp_staff`
(post type de SportsPress) con `sp_role` (taxonomía "Trabajos"), 44 fichas
publicadas (primer equipo + Villarreal B + Femenino) y WPML configurado
(`sp_staff` no traducible, `sp_role` traducible, 18 cargos).

---

## 4. Cómo medir que las cachés van bien (sin esperar a la factura)

- **Cabeceras**: `x-vercel-cache` (HIT/MISS), `age`, `cache-control` en las
  respuestas de `/api/*`.
- **Un endpoint fresco** se fuerza añadiendo un query param cualquiera
  (`&_b=<timestamp>`) → cambia la clave de CDN → MISS.
- **Vercel → Observability → Middleware**: la proporción `redirect` vs `rewrite`
  cayó ~44% al arreglar los enlaces. `Functions` bajó memoria −70%.
- **Cloudways**: llamadas al API por visitante 28 → 10 (−62%).

---

## 5. Pendiente (por orden de lo que rinde / riesgo)

1. **`GlobalTabs.tsx`** (frontend, 22 secciones): mismo arreglo de idioma +
   `prefetch={false}` + barra final que §3.2. Es el siguiente en ahorro.
2. **Renderizado estático** (frontend): el layout raíz lee el idioma de una
   cabecera → las 166 rutas son dinámicas y ninguna tiene `generateStaticParams`.
   Ataca funciones y origin transfer. Migración grande; tantear con una sección.
3. **Confirmar `vcf-vplay-refresh.php`** instalado (§3.4).
4. **Rotar la clave de la API de BeSoccer.** Estuvo descargable en `.bak` hasta
   el 20/08. En este repo va **redactada** como `__BESOCCER_API_KEY__` en
   `villarreal-tickets-config.php` y `tema/sportspress/single-player.php`; el
   valor real vive solo en el servidor. Al rotarla, cambiarla también en el
   snippet 37 (BeSoccer) y donde aparezca.
5. **Medios fuera del origen**: 53 de 147 peticiones de portada van del navegador
   directo a WordPress (1,16 TB/mes de Cloudways). Bloqueado por decisión de
   infra (Cloudflare está descartado).
6. **6 snippets inactivos** ya capturados (no corren; sufijo `.INACTIVO`).

---

## 6. Trampas / cosas que sorprenden

- **Snippets 33 y 41 tienen los nombres cruzados** en el panel: el 33 se llama
  "villarreal_b" pero sirve **Femenino**, y el 41 al revés. Las rutas del código
  (`femenino/…`, `villarreal_b/…`) están bien. Aquí se nombran por la ruta real.
- **`tema/sportspress/single-player.php`** es un *override* de plantilla de
  SportsPress metido dentro del tema comercial **`smart-mag`**. Una actualización
  del tema se lo puede llevar. Ahora está en git como respaldo.
- **Code Snippets = PHP en la base de datos** ejecutado con `eval()`. No sale en
  `grep` ni en backups de ficheros. Si algo REST se rompe y no lo encuentras en
  el código, míralo en *Fragmentos de código*.
- **Cron**: Cloudways usa `DISABLE_WP_CRON=true` + cron de sistema; wp-cron está
  vivo (los eventos tienen `next_run` futuro).
- **Cloudflare está descartado** por decisión del cliente. No lo propongas como
  solución de caché/CDN sin hablarlo.

---

## 7. Reglas de seguridad que se han seguido

- **Ningún secreto en git ni en el chat.** Claves de BeSoccer redactadas. El
  secreto de revalidación (`REVALIDATE_SECRET`) se generó en el propio servidor,
  nunca pasó por el chat.
- Copias de seguridad `.php.bak*` que estaban **descargables en texto plano**
  desde la web (una con una clave viva) se movieron fuera del *web root* el
  20/08 (`~/copias-php-20260820/` en el servidor).

---

## 8. Mapa rápido de ficheros

**En este repo (`vcf-servidor`):**
- `plugins/vcf-cache-api/` — cabeceras Cache-Control por ruta + refresco V Play +
  revalidación de plantilla.
- `plugins/vcf-panel-ligero/` — aligera el wp-admin de los editores.
- `plugins/villarreal-tickets-config/` — venta de entradas.
- `snippets/` — los 14 snippets de Code Snippets (`MANIFEST.md` los explica).
- `tema/sportspress/single-player.php` — ficha de jugador (override del tema).
- `apache/`, `cloudways/` — bloque de rendimiento y ajustes del panel.

**En el frontend (`villarrealcf-web`):**
- `lib/translate-plain.ts` — traducción WPML de noticias (el arreglo de §3.1).
- `lib/wp-api.ts` — construcción de URLs y `puertaCache()` (Varnish).
- `lib/enlace-idioma.ts` — prefijo de idioma en enlaces.
- `app/api/posts/route.ts` — proxy de noticias + localización.
- `app/api/revalidate/route.ts` — revalidación por etiqueta desde WordPress.
