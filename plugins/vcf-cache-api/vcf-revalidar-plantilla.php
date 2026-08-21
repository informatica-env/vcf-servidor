<?php
/**
 * VCF — Refrescar la web cuando cambian la plantilla o el menú
 * ------------------------------------------------------------
 * Parte del plugin `vcf-cache-api`. NO llevar esto a functions.php: una
 * actualización de plantilla del tema se lo llevaría por delante.
 *
 * QUÉ RESUELVE
 * La plantilla y el menú se guardan en caché una hora, y eso está bien: casi
 * nunca cambian. El problema era no poder forzarlo el día que SÍ cambian — un
 * fichaje, una reorganización del menú —, que suele ser el día de más visitas.
 *
 * HAY DOS CACHÉS EN SERIE Y EL ORDEN IMPORTA
 *
 *     WordPress  →  Varnish (300 s)  →  Data Cache de Next (1 h)  →  visitante
 *
 * Si se invalida Next primero, Next va a buscar el dato, Varnish le entrega su
 * copia vieja, y Next se la guarda OTRA HORA. Es decir: pulsar el botón antes
 * de tiempo empeora las cosas. Verificado el 19/08/2026 en producción.
 * Por eso aquí SIEMPRE se purga Varnish primero y solo después se avisa a
 * Vercel. No invertir el orden.
 *
 * NO hay que tocar wp-config.php: reutiliza la constante REVALIDATE_SECRET que
 * ya está definida ahí y que usan también el tema y el plugin de entradas.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// La BARRA FINAL no es cosmética: el frontend tiene `trailingSlash: true`, así
// que sin ella Vercel responde 308. Verificado: sin barra 308, con barra 200.
if ( ! defined( 'VCF_REVALIDATE_URL' ) ) {
    define( 'VCF_REVALIDATE_URL', 'https://villarrealcf.es/api/revalidate/' );
}

if ( ! defined( 'VCF_PANEL_ORIGEN' ) ) {
    define( 'VCF_PANEL_ORIGEN', 'https://panel.villarrealcf.es' );
}

/** El equipo cuya lista de jugadores pinta /primer-equipo/. */
if ( ! defined( 'VCF_EQUIPO_ID' ) ) {
    define( 'VCF_EQUIPO_ID', 11612 );
}

/** Tipos de contenido que afectan a lo que muestra /primer-equipo/. */
function vcf_tipos_plantilla() {
    // sp_player: nombre, dorsal, foto, ficha de cada jugador.
    // sp_team:   la LISTA de jugadores. La página lee el equipo, así que altas
    //            y bajas se guardan aquí, no en la ficha del jugador.
    return array( 'sp_player', 'sp_team' );
}

// ─── Paso 1: Varnish ────────────────────────────────────────────────────────

/**
 * Purga una ruta del API en Varnish.
 *
 * OJO CON LA CODIFICACIÓN. El frontend construye estas URLs con
 * `encodeURIComponent(ruta)` (ver puertaCache() en lib/wp-api.ts), así que las
 * barras viajan como %2F. Varnish indexa por cadena literal: purgar la misma
 * ruta con las barras sin codificar limpia OTRA entrada distinta y no sirve de
 * nada — parece que funciona y no hace nada. `rawurlencode` produce la misma
 * forma que `encodeURIComponent` para estos caracteres.
 *
 * @param string $ruta  p. ej. '/sportspress/v2/teams/11612'
 * @param string $extra cadena de consulta adicional, tal cual la pide el
 *                      frontend, empezando por '&'. Si el frontend cambia sus
 *                      parámetros, hay que cambiarlos aquí o la purga fallará
 *                      en silencio.
 */
function vcf_purgar_varnish( $ruta, $extra = '' ) {
    $url = VCF_PANEL_ORIGEN . '/index.php?rest_route=' . rawurlencode( $ruta ) . $extra;

    $res = wp_remote_request( $url, array(
        'method'  => 'PURGE',
        'timeout' => 5,
        // El User-Agent por defecto de cURL está bloqueado en este servidor
        // (devuelve 403). Sin esto la purga falla sin avisar.
        'user-agent' => 'Mozilla/5.0',
    ) );

    return ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res );
}

// ─── Paso 2: Vercel ─────────────────────────────────────────────────────────

/** Invalida una etiqueta de caché en el frontend. Devuelve array( ok, mensaje ). */
function vcf_avisar_vercel( $etiqueta ) {
    if ( ! defined( 'REVALIDATE_SECRET' ) || ! REVALIDATE_SECRET ) {
        return array( false, 'Falta REVALIDATE_SECRET en wp-config.php' );
    }

    $url = add_query_arg(
        array( 'secret' => REVALIDATE_SECRET, 'tag' => $etiqueta ),
        VCF_REVALIDATE_URL
    );

    // Bloqueante a propósito: queremos saber si ha fallado. Un aviso "no
    // bloqueante" es más rápido pero falla en silencio, y entonces el día del
    // fichaje nadie se entera de que la web sigue mostrando lo viejo.
    $res = wp_remote_post( $url, array( 'timeout' => 8 ) );

    if ( is_wp_error( $res ) ) {
        return array( false, 'Error de red: ' . $res->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $res );
    if ( 200 === $code ) {
        return array( true, 'Web actualizada (' . $etiqueta . ')' );
    }

    // 401 = el secreto no coincide con el de Vercel.
    // 400 = Vercel no conoce esa etiqueta (¿despliegue antiguo?).
    return array( false, 'Vercel respondió ' . $code . ': ' . wp_remote_retrieve_body( $res ) );
}

/** Guarda el resultado para el aviso del panel y deja rastro si ha fallado. */
function vcf_registrar( $ok, $msg ) {
    update_option( 'vcf_revalidate_last', array(
        'ok'   => $ok,
        'msg'  => $msg,
        'when' => current_time( 'mysql' ),
    ), false );

    if ( ! $ok ) {
        error_log( '[vcf-revalidate] ' . $msg );
    }
}

// ─── Las dos operaciones completas ──────────────────────────────────────────

/**
 * Refresca la plantilla. Varnish primero, Vercel después.
 *
 * @param int|null $player_id Si el cambio viene de guardar una ficha concreta,
 *                            se purga además esa ficha.
 */
function vcf_revalidar_plantilla( $player_id = null ) {
    vcf_purgar_varnish( '/sportspress/v2/teams/' . VCF_EQUIPO_ID );

    if ( $player_id ) {
        // El frontend pide las fichas con ?_fields=id,link. La purga tiene que
        // llevar los mismos parámetros: otra combinación es otra entrada.
        vcf_purgar_varnish( '/sportspress/v2/players/' . (int) $player_id, '&_fields=id,link' );
    }

    list( $ok, $msg ) = vcf_avisar_vercel( 'plantilla' );
    vcf_registrar( $ok, $msg );
    return array( $ok, $msg );
}

/** Refresca el menú de navegación, en los tres idiomas. */
function vcf_revalidar_menu() {
    $ruta = '/wph/v2/menus/smartmag-main';
    vcf_purgar_varnish( $ruta );                 // castellano (sin ?lang=)
    vcf_purgar_varnish( $ruta, '&lang=en' );
    vcf_purgar_varnish( $ruta, '&lang=val' );

    list( $ok, $msg ) = vcf_avisar_vercel( 'menu' );
    vcf_registrar( $ok, $msg );
    return array( $ok, $msg );
}

// ─── Disparadores automáticos ───────────────────────────────────────────────

function vcf_al_guardar_plantilla( $post_id ) {
    // Guardas imprescindibles: sin ellas se dispara con cada autoguardado
    // mientras el redactor escribe, y con cada revisión.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }

    $tipo = get_post_type( $post_id );
    if ( ! in_array( $tipo, vcf_tipos_plantilla(), true ) ) {
        return;
    }

    $estado = get_post_status( $post_id );
    if ( 'publish' !== $estado && 'trash' !== $estado ) {
        return; // Un borrador no sale en la web: no hay nada que refrescar.
    }

    vcf_revalidar_plantilla( 'sp_player' === $tipo ? $post_id : null );
}
add_action( 'save_post', 'vcf_al_guardar_plantilla', 10, 1 );
add_action( 'trashed_post', 'vcf_al_guardar_plantilla', 10, 1 );
add_action( 'untrashed_post', 'vcf_al_guardar_plantilla', 10, 1 );

// Cualquier cambio en un menú de navegación.
add_action( 'wp_update_nav_menu', 'vcf_revalidar_menu', 10, 0 );

// ─── Botón manual, como salida de emergencia ────────────────────────────────

add_action( 'admin_bar_menu', function ( $barra ) {
    if ( ! current_user_can( 'edit_posts' ) ) {
        return;
    }
    $barra->add_node( array(
        'id'    => 'vcf-revalidar',
        'title' => 'Actualizar web',
        'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=vcf_revalidar' ), 'vcf_revalidar' ),
    ) );
}, 100 );

add_action( 'admin_post_vcf_revalidar', function () {
    if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'vcf_revalidar' ) ) {
        wp_die( 'No autorizado' );
    }

    list( $ok1, $m1 ) = vcf_revalidar_plantilla();
    list( $ok2, $m2 ) = vcf_revalidar_menu();
    $ok = $ok1 && $ok2;
    vcf_registrar( $ok, $ok ? 'Plantilla y menú actualizados en la web' : trim( $m1 . ' / ' . $m2 ) );

    wp_safe_redirect( add_query_arg( 'vcf_revalidado', $ok ? '1' : '0', wp_get_referer() ?: admin_url() ) );
    exit;
} );

/** Aviso con el resultado, para que el botón no sea un salto de fe. */
add_action( 'admin_notices', function () {
    if ( ! isset( $_GET['vcf_revalidado'] ) ) {
        return;
    }
    $ultimo = get_option( 'vcf_revalidate_last', array() );
    $msg    = isset( $ultimo['msg'] ) ? $ultimo['msg'] : '';
    $clase  = ( '1' === $_GET['vcf_revalidado'] ) ? 'notice-success' : 'notice-error';
    printf(
        '<div class="notice %s is-dismissible"><p>%s</p></div>',
        esc_attr( $clase ),
        esc_html( $msg )
    );
} );
