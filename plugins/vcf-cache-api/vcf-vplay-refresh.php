<?php
/**
 * VCF — Refresco automático de la portada de V Play
 * -------------------------------------------------
 * Parte del plugin `vcf-cache-api`. NO llevar esto a functions.php ni a
 * mu-plugins (ahí el usuario SSH no tiene permiso de escritura; aquí sí).
 *
 * EL PROBLEMA (diagnosticado el 20/08/2026)
 * La portada de V Play sale del endpoint `vplay/v1/portada` (snippet 31), que
 * renderiza un grid de Essential Grid cuyo origen es una lista de reproducción
 * de YouTube y le hace scraping a los `data-youtube`. Essential Grid guarda la
 * lectura de esa lista en un transitorio de 24 h:
 *
 *     essgrid_<md5(url & count & sec)>   con transient_sec = 86400
 *
 * Su propia limpieza (`clear_transients()`) hace un `grep` sobre `wp_options`,
 * pero bajo Redis (object-cache-pro) los transitorios viven en Redis y
 * `wp_options` está vacío, así que ESA LIMPIEZA NUNCA ENCUENTRA NADA y el
 * transitorio no se borra solo. Resultado: cuando un editor cambia la lista de
 * reproducción, la web puede tardar hasta 24 h en reflejarlo. Es exactamente
 * lo que reportaron los editores.
 *
 * LA SOLUCIÓN
 * Un evento de WP-Cron cada 10 minutos que vuelve a renderizar el grid forzando
 * `data[clear_cache] = youtube` — el mecanismo interno de Essential Grid para
 * saltarse el transitorio y volver a leer la lista — y después purga Varnish
 * para las dos formas de URL del endpoint.
 *
 * COSTE
 * 10 min → 144 renders/día. Cada render lee la lista con `playlistItems`
 * (1 unidad de cuota de la YouTube Data API). 144 unidades/día frente al límite
 * de 10.000: trivial. El render en sí es PHP local, no sale a Vercel.
 *
 * SEGURIDAD DE FALLO
 * Si algo falla (alias que no existe, YouTube caído), el render devuelve vacío
 * y no se toca nada: la copia anterior sigue sirviéndose. Nunca deja la portada
 * peor de como estaba.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alias del grid (o grids) que alimentan la portada de V Play.
 *
 * CONFIRMAR contra el snippet 31: el valor real es el que aparece en
 * `do_shortcode('[ess_grid alias="..."]')` de ese snippet. Se aceptan varios
 * por si conviven versiones; renderizar un alias inexistente es inofensivo
 * (devuelve cadena vacía). Cambiar aquí no requiere tocar nada más.
 */
function vcf_vplay_aliases() {
	return array( 'vplay-portada', 'vplay-portada-2' );
}

/** URLs del endpoint que Varnish cachea, en sus dos formas. */
function vcf_vplay_urls() {
	return array(
		'https://panel.villarrealcf.es/wp-json/vplay/v1/portada',
		'https://panel.villarrealcf.es/index.php?rest_route=/vplay/v1/portada',
	);
}

/**
 * Purga Varnish para el endpoint de la portada.
 * User-Agent de navegador: con el UA por defecto de cURL, Varnish devuelve 403.
 */
function vcf_vplay_purgar_varnish() {
	$resultado = array();
	foreach ( vcf_vplay_urls() as $url ) {
		$r = wp_remote_request(
			$url,
			array(
				'method'  => 'PURGE',
				'timeout' => 5,
				'headers' => array( 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' ),
			)
		);
		$resultado[ $url ] = is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_response_code( $r );
	}
	return $resultado;
}

/**
 * El trabajo: fuerza a Essential Grid a releer la lista de YouTube y purga.
 * Devuelve un pequeño informe (útil al ejecutarlo a mano para verificar).
 */
function vcf_vplay_refrescar() {
	if ( ! function_exists( 'do_shortcode' ) ) {
		return array( 'error' => 'do_shortcode no disponible' );
	}

	// Guardar el estado previo de las variables de petición y forzar el flag
	// que Essential Grid lee para saltarse el transitorio de YouTube.
	$prev_request = array_key_exists( 'data', $_REQUEST ) ? $_REQUEST['data'] : '__no__';
	$prev_post    = array_key_exists( 'data', $_POST )    ? $_POST['data']    : '__no__';

	$_REQUEST['data'] = array( 'clear_cache' => 'youtube' );
	$_POST['data']    = array( 'clear_cache' => 'youtube' );

	$render = array();
	foreach ( vcf_vplay_aliases() as $alias ) {
		$html = do_shortcode( '[ess_grid alias="' . $alias . '"]' );
		// Contar cuántos IDs de vídeo trae el render, como prueba de vida.
		$n = preg_match_all( '/data-youtube="[^"]+"/', (string) $html, $m );
		$render[ $alias ] = (int) $n;
	}

	// Restaurar el estado previo — no dejar el flag puesto para otras peticiones.
	if ( '__no__' === $prev_request ) {
		unset( $_REQUEST['data'] );
	} else {
		$_REQUEST['data'] = $prev_request;
	}
	if ( '__no__' === $prev_post ) {
		unset( $_POST['data'] );
	} else {
		$_POST['data'] = $prev_post;
	}

	$purga = vcf_vplay_purgar_varnish();

	update_option( 'vcf_vplay_last_refresh', current_time( 'mysql' ), false );

	return array(
		'cuando'  => current_time( 'mysql' ),
		'render'  => $render,   // alias => nº de data-youtube encontrados
		'purga'   => $purga,    // url => código HTTP de la purga
	);
}

/* --- Programación en WP-Cron ------------------------------------------- */

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		if ( ! isset( $schedules['vcf_10min'] ) ) {
			$schedules['vcf_10min'] = array(
				'interval' => 600,
				'display'  => 'Cada 10 minutos (VCF)',
			);
		}
		return $schedules;
	}
);

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'vcf_vplay_refresh_event' ) ) {
			// +120 s para no dispararlo en el mismo request del despliegue.
			wp_schedule_event( time() + 120, 'vcf_10min', 'vcf_vplay_refresh_event' );
		}
	}
);

add_action( 'vcf_vplay_refresh_event', 'vcf_vplay_refrescar' );

/* --- Limpieza al desactivar el plugin ---------------------------------- */

register_deactivation_hook(
	WP_PLUGIN_DIR . '/vcf-cache-api/vcf-cache-api.php',
	function () {
		$ts = wp_next_scheduled( 'vcf_vplay_refresh_event' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'vcf_vplay_refresh_event' );
		}
	}
);
