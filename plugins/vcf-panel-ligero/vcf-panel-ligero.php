<?php
/**
 * Plugin Name: VCF - Panel ligero
 * Description: Quita del panel de administracion el trabajo caro que los editores no usan. Desactivalo para volver al comportamiento anterior.
 * Version: 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1) Escritorio sin widgets. Eran: los conteos de "De un vistazo" sobre
//    355.000 adjuntos, el chequeo de Site Health con llamadas loopback al
//    propio sitio, y una descarga RSS bloqueante de wordpress.org.
add_action( 'wp_dashboard_setup', function () {
    global $wp_meta_boxes;
    $wp_meta_boxes['dashboard'] = array();
}, 9999 );

// 2) El desplegable de meses lanza un DISTINCT YEAR/MONTH sobre wp_posts sin
//    indice util. Con esta tabla es la consulta mas cara de edit.php.
add_filter( 'disable_months_dropdown', '__return_true' );

// 3) Heartbeat cada 60 s en lugar de 15: admin-ajax baja de 4 a 1 llamada por
//    minuto y por pestana abierta.
add_filter( 'heartbeat_settings', function ( $settings ) {
    $settings['interval'] = 60;
    return $settings;
} );

// 4) Site Health deja de ejecutar sus pruebas en segundo plano.
add_filter( 'site_status_tests', function ( $tests ) {
    unset( $tests['async'] );
    return $tests;
} );
