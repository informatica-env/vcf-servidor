# Ajustes del panel de Cloudways

No son ficheros. Son campos de una interfaz web, sin control de versiones y sin
historial. Son, con diferencia, lo más fácil de perder de todo este paquete.

Servidor: **DigitalOcean, 8 vCPU / 16 GB** · Aplicación: `wnevujfuhz`

---

## 1. Buffer pool de InnoDB — **el cambio que más pesó de todo el 17/08**

**Server Management → Settings & Packages → Advanced → `innodb_buffer_pool_size`**

```
antes:  10 GB          →   ahora:  4 GB
```

**Por qué.** Con 10 GB de *buffer pool* había **16.288 MB comprometidos en una
máquina de 15.991 MB**. MySQL empujaba permanentemente a *swap*, y con él todo
lo demás. El `load average` llegó a **82**.

**Por qué 4 GB y no más.** La tasa de acierto del *buffer pool* está en
**99,954%**. Subirlo no compra nada y vuelve a arriesgar la memoria. **No
subirlo.**

Resultado combinado con el punto 2: de load 82 a **1,15**.

---

## 2. Copia de seguridad — reprogramada

**Server Management → Backups**

El `duplicity` llevaba **cuatro horas** recorriendo 355.409 adjuntos **en
horario de redacción**, vaciando la caché de ficheros del sistema operativo.
Cada petición que llegaba durante ese rato tenía que volver a leer de disco.

Se paró y se reprogramó fuera del horario de trabajo. Si alguien la devuelve a
media mañana, el problema vuelve entero.

---

## 3. Exclusiones de Varnish

**Application Settings → Varnish → Excluded URLs**

```
antes:  \/wp-json/
ahora:  \/wp-json/custom/v1/form-nonce
```

**Por qué estaba la primera.** El plugin *WP Headless* emitía `max-age=600` en
todas las respuestas del API, incluido el nonce de formularios. Excluir
`/wp-json/` entero evitaba que Varnish cachease ese token de un solo uso — pero
para proteger 151 peticiones dejaba fuera 27.694.

**Por qué se puede estrechar ahora.** El plugin `vcf-cache-api` marca el nonce
como `no-store` desde el código, con prioridad 9999. La exclusión del panel es
ya el segundo cinturón, no el primero.

> **Ojo con la línea 62 de `/etc/varnish/recv/wordpress.vcl`.** Esa regla excluye
> `wp-json` de la caché **para todo el servidor**, es de `root` y la gestiona
> Cloudways: no se puede editar. Por eso el frontend usa la puerta alternativa
> `/index.php?rest_route=/...`, que devuelve exactamente lo mismo y no contiene
> esa cadena. Ver `lib/wp-api.ts` en el repositorio del frontend.

---

## 4. Plugins de WordPress desactivados

- **JWT Authentication** — cero peticiones en todo el día y registraba rutas en
  las 128.000 llamadas diarias al API. Coste fijo, beneficio nulo.

---

## 5. Slow Query Log de MySQL — pendiente de activar (incidencia 28/08/2026)

**Server Management → Manage Services → MySQL → Slow Query Log**

**Por qué.** El 28/08 hubo un pico de `load average` (60+) sin CPU, disco ni
MySQL saturados en el muestreo: eran ráfagas de segundos, y `mysqld` acumulaba
**4h 06min de CPU en 8h 39min de vida** — el mayor consumidor del servidor —
sin que se cazara ninguna consulta en vivo (el `PROCESSLIST` salía casi vacío
cada vez que se miró). Sin el slow log activo seguimos a ciegas sobre cuál es
la consulta que dispara las ráfagas.

**Acción.** Activarlo desde el panel para que la próxima ráfaga quede
registrada con la consulta exacta. Candidatas por el slow log ya visto:
`WP_Query` / `get_posts` / `get_col` contra una `wp_posts` con **355.964
adjuntos**. No es autoload (las opciones autoload suman 0,41 MB, sano).

## 6. Imunify360 — bajar frecuencia / excluir `uploads` (incidencia 28/08/2026)

**Server Management → Security → Imunify360** (o soporte de Cloudways si no
está expuesto en el panel)

**Por qué.** Tres procesos (`rustbolit`, `wafd_imunify_da`,
`imunify-residen`) escanean en segundo plano una biblioteca de **356.000
ficheros** en `wp-content/uploads`. Es un consumo constante de CPU, no un
pico, pero da tirones cuando coincide con las ráfagas de MySQL.

**Acción propuesta.** Bajar la frecuencia del escaneo y/o excluir
`wp-content/uploads` (son medios ya servidos por Varnish/CDN, no ejecutables:
el riesgo de no escanearlos es bajo frente al coste de escanear 356k ficheros
en caliente).

## 7. Lo que **no** se tocó, y por qué conviene saberlo

- **Cloudflare está descartado.** Bloquea IPs y los días de partido tira la web.
  No proponerlo.
- **La `grace` de Varnish son 300 s.** Sirve la copia vencida mientras la
  refresca por detrás: ningún visitante espera nunca al backend y no hay
  estampidas. Es la red de seguridad de todos los TTL cortos.
- **Los purgados de Cloudways son de URL exacta.** No hay ni un `ban()` en toda
  la VCL, así que no se puede invalidar por prefijo ni con comodines. Para los
  listados, el TTL es la única invalidación real.
- **Varnish borra el `Cache-Control` de las respuestas de error** y las cachea
  **120 s** de todas formas. El plugin las marca `no-store` correctamente — se
  comprueba por la puerta clásica — pero Varnish lo ignora. No es un fallo
  nuestro y no tiene arreglo desde nuestro lado.
