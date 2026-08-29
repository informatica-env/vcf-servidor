<?php
/**
 * VCF - check-live cacheado y con timeout
 *
 * ---------------------------------------------------------------------------
 * INCIDENCIA (28/08/2026) — carga alta / "CPU al 100%"
 *
 * custom/v1/check-live (definido en wp-content/themes/smart-mag/functions.php,
 * ~línea 1500-1518) hacía un file_get_contents() SINCRONO, SIN TIMEOUT y SIN
 * CACHE contra la página del playlist de YouTube en cada llamada. La misma
 * insertar_precarga_y_video() (en wp_footer) repetía la misma llamada en cada
 * render. Con el polling de custom.js:471 en cada carga de página, cuando
 * YouTube va lento o limita, cada llamada bloqueaba un worker de PHP entero.
 * Medido: la ruta devolvía 404 el 67% de las veces (2.161 de 3.215) y dejaba
 * 1.049 respuestas 499 (cliente que aborta) repartidas en 24 h — sangraba
 * workers de PHP todo el día y agravaba las ráfagas de MySQL.
 *
 * Es el mismo fallo ya corregido en el snippet 37 (ficha de jugador): llamada
 * HTTP externa síncrona, sin timeout, sin caché, por una ruta que Varnish no
 * absorbe (pasa por /wp-json/).
 *
 * ---------------------------------------------------------------------------
 * QUÉ HACE ESTE FICHERO
 *
 * Sustituye las TRES apariciones de la llamada directa a YouTube por un único
 * helper con caché (transient, 30 s si hay directo / 10 s si no) y timeout
 * duro de 3 s. Encapsula la petición externa en un solo sitio: la ruta REST y
 * el wp_footer llaman ambos a vcf_youtube_live_id() en vez de hacer su propio
 * file_get_contents().
 *
 * ---------------------------------------------------------------------------
 * CÓMO INSTALARLO
 *
 * 1. En wp-content/themes/smart-mag/functions.php:
 *    - Localizar las TRES definiciones de custom/v1/check-live (dos están
 *      comentadas, la activa hacia la línea ~1500-1518) y sustituir el bloque
 *      activo por el de este fichero (o hacer require de este fichero desde
 *      functions.php y borrar las tres definiciones antiguas).
 *    - Localizar insertar_precarga_y_video() (enganchada a wp_footer) y
 *      cambiar su file_get_contents() propio por una llamada a
 *      vcf_youtube_live_id().
 * 2. No hace falta tocar Varnish para que esto funcione: el ahorro viene de
 *    que PHP deja de esperar a YouTube en cada visita. El punto 2 del plan de
 *    la incidencia (cachear /wp-json/ vía vcf-cache-api) es un ahorro
 *    adicional, no un requisito de este fix.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ID del vídeo en directo de YouTube, cacheado y con timeout.
 * Pregunta a YouTube UNA vez cada 30 s como mucho. Si va lento o falla, corta
 * a los 3 s y no bloquea PHP. Devuelve el videoId (string) o null.
 */
function vcf_youtube_live_id() {
	$cache_key = 'vcf_youtube_live_id';
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached === '__none__' ? null : $cached; // '__none__' = comprobado, no hay directo.
	}

	$playlist_id = 'PLOYpteA1AIavMcyk-y05S_Kc3yMY7WaTS';
	$url         = "https://www.youtube.com/playlist?list=$playlist_id";
	$ctx         = stream_context_create(
		array(
			'http' => array(
				'timeout'    => 3,
				'user_agent' => 'Mozilla/5.0',
			),
		)
	);

	$html = @file_get_contents( $url, false, $ctx );
	$id   = null;
	if ( $html !== false && preg_match( '/"videoId":"(.*?)"/', $html, $m ) ) {
		$id = $m[1];
	}

	set_transient( $cache_key, ( $id !== null ? $id : '__none__' ), ( $id !== null ? 30 : 10 ) );

	return $id;
}

/**
 * Endpoint REST: custom/v1/check-live
 * Ya no hace ninguna llamada HTTP externa por petición; delega en el helper
 * cacheado de arriba.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'custom/v1',
			'/check-live',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					$id = vcf_youtube_live_id();
					return array( 'needsUpdate' => ( $id !== null ) );
				},
			)
		);
	}
);
