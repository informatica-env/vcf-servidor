<?php
/**
 * Plugin Name: VCF - Cabeceras de cache para el API REST
 * Description: Decide, ruta por ruta, que respuestas del API REST puede guardar Varnish y durante cuanto tiempo. No cachea nada por si mismo: solo emite la cabecera Cache-Control que Varnish obedece.
 * Version:     1.0
 * Author:      Envidea
 *
 * ---------------------------------------------------------------------------
 * POR QUE EXISTE ESTE PLUGIN
 *
 * Medido el 17/08/2026 sobre 30.000 peticiones del log (~2 h):
 *
 *   - 27.694 peticiones a /wp-json/ son GET  = 99,24% del total
 *     y suponen 91.493 s de tiempo de PHP    = 99,8% del coste
 *   - solo 33 son POST, todas a wpforms/v1/themes/custom/
 *   - de 18.560 peticiones al API solo 7.869 son URLs distintas: se pide
 *     2,4 veces lo mismo
 *   - el endpoint mas trivial que existe (wp/v2/types?_fields=post) tarda
 *     0,43 s: eso es solo arrancar WordPress con sus ~60 plugins, y lo pagan
 *     todas las peticiones
 *
 * Es decir: la inmensa mayoria del trabajo del servidor es recalcular
 * respuestas identicas para el frontend de Vercel.
 *
 * ---------------------------------------------------------------------------
 * POR QUE HACE FALTA CODIGO Y NO BASTA CON LA CONFIGURACION
 *
 * La VCL de Cloudways (/etc/varnish/cloudways.vcl, sub vcl_backend_response)
 * NO fija ningun TTL: solo marca como no cacheables las respuestas de error.
 * Para todo lo demas respeta lo que diga el backend. Asi que el TTL lo
 * decidimos aqui, en codigo versionado y comentado, en lugar de en un campo
 * de texto de un panel.
 *
 * ---------------------------------------------------------------------------
 * EL PELIGRO QUE ESTE PLUGIN NEUTRALIZA
 *
 * custom/v1/form-nonce/<id> es un GET que devuelve un token de un solo uso.
 * Si Varnish lo cachea, TODOS los visitantes reciben el mismo nonce y los
 * formularios dejan de funcionar. Por eso alguien excluyo /wp-json/ entero de
 * Varnish desde el panel de Cloudways: la intencion era correcta, el alcance
 * no (para proteger 151 peticiones se dejaron fuera 27.694).
 *
 * Aqui esa proteccion pasa a ser de codigo: aunque manana alguien borre la
 * exclusion del panel sin saber lo que hace, el nonce sigue sin cachearse.
 *
 * ---------------------------------------------------------------------------
 * POR QUE LOS LISTADOS DURAN MENOS
 *
 * Breeze purga Varnish al publicar (inc/cache/purge-varnish.php), pero solo
 * construye URLs del espacio de nombres wp/v2: el post, sus categorias, sus
 * etiquetas, su autor y la portada. No conoce wph/v2 ni vcf/v1 ni custom/v1,
 * que son los que mas usa el frontend. Y los purgados de Cloudways son de URL
 * exacta: no hay ni un ban() en toda la VCL, asi que no se puede invalidar por
 * prefijo ni con comodines.
 *
 * Conclusion: para los listados el TTL es la unica invalidacion real. 60 s es
 * el compromiso: una noticia recien publicada tarda como mucho un minuto en
 * salir, menos de lo que tarda hoy el ISR de Vercel con sus 300 s.
 *
 * Ademas la VCL tiene 300 s de "grace": Varnish sirve la copia vencida
 * mientras la refresca por detras, asi que ningun visitante espera nunca al
 * backend y nunca hay estampida.
 *
 * ---------------------------------------------------------------------------
 * PARA DESACTIVARLO
 *   wp plugin deactivate vcf-cache-api
 * Sin efectos secundarios: solo dejan de emitirse las cabeceras.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rutas que NUNCA se pueden cachear, pase lo que pase.
 * El nonce es de un solo uso; wpforms y jwt manejan sesion y autenticacion.
 */
const VCF_NUNCA_CACHEAR = '#/(custom/v1/form-nonce|wpforms|jwt-auth|wp/v2/users)#i';

/**
 * Listados y busquedas: nadie los purga al publicar, asi que viven poco.
 */
const VCF_ES_LISTADO = '#/(posts/search|pages/search|search-page|posts_swiper|post-by-slug|page-by-path)#i';

const VCF_TTL_LISTADO = 60;   // segundos
const VCF_TTL_RECURSO = 300;  // segundos

add_filter( 'rest_post_dispatch', 'vcf_cabeceras_cache_api', 9999, 3 );

/**
 * @param WP_HTTP_Response $response
 * @param WP_REST_Server   $server
 * @param WP_REST_Request  $request
 * @return WP_HTTP_Response
 */
function vcf_cabeceras_cache_api( $response, $server, $request ) {

	if ( ! is_object( $response ) || ! method_exists( $response, 'header' ) ) {
		return $response;
	}

	$privada = static function ( $response ) {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
		return $response;
	};

	// 1. Solo GET. Cualquier otra cosa modifica estado o lleva datos de sesion.
	if ( ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
		return $privada( $response );
	}

	// 2. Nunca cachear lo de un usuario identificado: los editores y el editor
	//    de bloques deben ver siempre el estado real, no una copia.
	if ( is_user_logged_in() ) {
		return $privada( $response );
	}

	// 3. Nunca cachear errores: una respuesta rota cacheada 5 minutos es peor
	//    que una respuesta rota.
	$status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 200;
	if ( $status < 200 || $status >= 300 ) {
		return $privada( $response );
	}

	$ruta = (string) $request->get_route();

	// 4. Nonces, formularios y autenticacion: fuera, por definicion.
	if ( preg_match( VCF_NUNCA_CACHEAR, $ruta ) ) {
		return $privada( $response );
	}

	// 5. El resto entra en cache. Los listados menos tiempo que los recursos
	//    individuales, porque a los listados no llega ningun purgado.
	$ttl = preg_match( VCF_ES_LISTADO, $ruta ) ? VCF_TTL_LISTADO : VCF_TTL_RECURSO;

	$response->header(
		'Cache-Control',
		sprintf( 'public, max-age=%1$d, s-maxage=%1$d, stale-while-revalidate=300', $ttl )
	);

	// Marca para poder comprobar desde fuera que la regla se aplico y cual.
	$response->header( 'X-VCF-Cache', $ttl . 's' );

	return $response;
}
