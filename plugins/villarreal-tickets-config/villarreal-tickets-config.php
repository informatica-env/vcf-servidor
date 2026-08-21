<?php
/**
 * Plugin Name: Villarreal CF - Configuración de Venta de Entradas
 * Description: Activa/desactiva partidos para los módulos de venta de entradas (home y página de venta de entradas) y configura, por partido, el enlace y color del botón de entradas, un botón VIP opcional, y un modal de "más información" opcional. La configuración es compartida por los dos shortcodes.
 * Version: 1.0.0
 * Author: Villarreal CF
 * Text Domain: vcf-tickets-config
 */

if (!defined('ABSPATH')) {
    exit; // No acceso directo.
}

// ─────────────────────────────────────────────────────────────────────────
// Constantes
// ─────────────────────────────────────────────────────────────────────────

define('VCF_TICKETS_OPTION_KEY', 'vcf_tickets_config');
define('VCF_TICKETS_BESOCCER_URL', 'https://apiclient.besoccerapps.com/scripts/api/api.php?key=__BESOCCER_API_KEY__&format=json&req=matches_team&id=2716&year=2027&extra=png');

// ─────────────────────────────────────────────────────────────────────────
// Fetch de partidos próximos desde BeSoccer (misma fuente que ya usan los
// dos shortcodes) — se usa solo para pintar la lista en el admin, con los
// nombres de equipo, fecha y competición ya legibles.
// ─────────────────────────────────────────────────────────────────────────

function vcf_tickets_fetch_upcoming_matches() {
    $cached = get_transient('vcf_tickets_upcoming_matches');
    if ($cached !== false) {
        return $cached;
    }

    $response = wp_remote_get(VCF_TICKETS_BESOCCER_URL, ['timeout' => 15]);
    if (is_wp_error($response)) {
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $request = json_decode($body);
    if (!isset($request->matches)) {
        return [];
    }

    $proximos = [];
    foreach ($request->matches as $competicion) {
        $nombre_competicion = $competicion->name ?? '';
        $partidos = isset($competicion->matches) ? $competicion->matches : [];
        if (!is_countable($partidos)) {
            continue;
        }
        foreach ($partidos as $partido) {
            if (($partido->status ?? null) != -1) {
                continue; // Solo próximos, igual que en los shortcodes.
            }
            $proximos[] = [
                'id_partido'       => $partido->id,
                'equipo_local'     => $partido->t1_name ?? '',
                'equipo_visitante' => $partido->t2_name ?? '',
                'fecha'            => $partido->date ?? '',
                'hora'             => $partido->hour ?? '',
                'minuto'           => $partido->minute ?? '',
                'competicion'      => $nombre_competicion,
            ];
        }
    }

    usort($proximos, fn($a, $b) => strtotime($a['fecha']) - strtotime($b['fecha']));

    // Cache 10 minutos — evita golpear la API de BeSoccer en cada carga del admin.
    set_transient('vcf_tickets_upcoming_matches', $proximos, 10 * MINUTE_IN_SECONDS);

    return $proximos;
}

// ─────────────────────────────────────────────────────────────────────────
// Acceso a la configuración guardada
// ─────────────────────────────────────────────────────────────────────────

/** Devuelve la configuración completa, keyed por id_partido. */
function vcf_tickets_get_config() {
    $config = get_option(VCF_TICKETS_OPTION_KEY, []);
    return is_array($config) ? $config : [];
}

/** Devuelve la configuración de UN partido, o null si no existe/no está activo. */
function vcf_tickets_get_match_config($id_partido) {
    $config = vcf_tickets_get_config();
    $id_partido = (string) $id_partido;
    if (!isset($config[$id_partido]) || empty($config[$id_partido]['active'])) {
        return null;
    }
    return $config[$id_partido];
}

/** Devuelve solo los ids de partido activos, en el mismo orden en que se guardaron. */
function vcf_tickets_get_active_ids() {
    $config = vcf_tickets_get_config();
    $active = [];
    foreach ($config as $id => $entry) {
        if (!empty($entry['active'])) {
            $active[] = (string) $id;
        }
    }
    return $active;
}

// ─────────────────────────────────────────────────────────────────────────
// Guardado desde el admin
// ─────────────────────────────────────────────────────────────────────────

function vcf_tickets_handle_save() {
    if (!isset($_POST['vcf_tickets_nonce']) || !wp_verify_nonce($_POST['vcf_tickets_nonce'], 'vcf_tickets_save')) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    $raw = $_POST['vcf_match'] ?? [];
    $config = [];

    if (is_array($raw)) {
        foreach ($raw as $id_partido => $entry) {
            $id_partido = sanitize_text_field($id_partido);
            $active = !empty($entry['active']);

            // Si no está activo, no hace falta guardar el resto de campos,
            // pero los conservamos por si lo vuelven a activar más tarde
            // sin tener que rellenarlo todo otra vez.
            $config[$id_partido] = [
                'active'          => $active,
                'equipo_local'     => sanitize_text_field($entry['equipo_local'] ?? ''),
                'equipo_visitante' => sanitize_text_field($entry['equipo_visitante'] ?? ''),
                'ticket_text'      => sanitize_text_field($entry['ticket_text'] ?? ''),
                'ticket_link'      => esc_url_raw($entry['ticket_link'] ?? ''),
                'ticket_color'     => sanitize_hex_color($entry['ticket_color'] ?? '') ?: '#129C00',
                'ticket_text_color'=> sanitize_hex_color($entry['ticket_text_color'] ?? '') ?: '#ffffff',
                'show_more_info'   => !empty($entry['show_more_info']),
                'more_info_title'  => sanitize_text_field($entry['more_info_title'] ?? ''),
                'more_info_text'   => wp_kses_post($entry['more_info_text'] ?? ''),
                'show_vip'         => !empty($entry['show_vip']),
                'vip_text'         => sanitize_text_field($entry['vip_text'] ?? ''),
                'vip_link'         => esc_url_raw($entry['vip_link'] ?? ''),
            ];
        }
    }

    update_option(VCF_TICKETS_OPTION_KEY, $config);
    delete_transient('vcf_tickets_upcoming_matches');
    vcf_tickets_notify_nextjs_revalidate();

    add_action('admin_notices', function () {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Configuración guardada.', 'vcf-tickets-config') . '</p></div>';
    });
}

/**
 * Avisa al frontend de Next.js para que revalide su caché tras guardar la
 * configuración de venta de entradas — mismo patrón que ya usa el sitio en
 * notify_nextjs_on_save() (hook save_post), pero disparado desde el guardado
 * de este plugin en vez de al publicar un post.
 */
/**
 * Purga una ruta del API en Varnish.
 *
 * La URL tiene que coincidir CARACTER A CARACTER con la que pide el frontend.
 * Varnish indexa por cadena literal: purgar la misma ruta escrita de otra
 * forma limpia otra entrada distinta y no sirve de nada — parece que funciona
 * y no hace nada. `rawurlencode` produce lo mismo que `encodeURIComponent`.
 */
function vcf_tickets_purgar_varnish($url) {
    $res = wp_remote_request($url, [
        'method'     => 'PURGE',
        'timeout'    => 5,
        // El User-Agent por defecto de cURL esta bloqueado en este servidor.
        'user-agent' => 'Mozilla/5.0',
    ]);

    return ! is_wp_error($res) && 200 === wp_remote_retrieve_response_code($res);
}

function vcf_tickets_notify_nextjs_revalidate() {
    // EL ORDEN IMPORTA. Varnish primero, Vercel despues. Al reves, Next va a
    // buscar el dato, Varnish le entrega su copia vieja, y Next se la guarda
    // otra vez. Verificado en produccion el 19/08/2026.
    $base = 'https://panel.villarrealcf.es/index.php?rest_route=';
    vcf_tickets_purgar_varnish($base . rawurlencode('/vcf/v1/tickets-config'));
    vcf_tickets_purgar_varnish(
        $base . rawurlencode('/wph/v2/shortcodes/venta_entradas_home')
        . '&tipo=villarreal-cf&idioma=es-ES&equipo=primer-equipo'
    );

    // CON etiqueta. Sin ella, Vercel ejecutaba un revalidatePath sobre rutas
    // dinamicas: respondia 200 y no refrescaba nada. Llevaba asi desde
    // siempre y nadie podia notarlo.
    $url = add_query_arg(
        ['secret' => REVALIDATE_SECRET, 'tag' => 'entradas'],
        'https://villarrealcf.es/api/revalidate/'
    );

    // Bloqueante a proposito. Antes era 'blocking' => false y un fallo se
    // perdia en silencio; son ocho segundos como mucho, y solo al guardar.
    $res = wp_remote_post($url, ['timeout' => 8]);

    $codigo = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
    $ok     = (200 === $codigo);
    $msg    = is_wp_error($res)
        ? 'Error de red: ' . $res->get_error_message()
        : 'Vercel respondio ' . $codigo;

    update_option('vcf_tickets_ultimo_aviso', [
        'ok'   => $ok,
        'msg'  => $msg,
        'when' => current_time('mysql'),
    ], false);

    if (! $ok) {
        error_log('[vcf-tickets] ' . $msg);
    }

    return $ok;
}

// ─────────────────────────────────────────────────────────────────────────
// Página de administración
// ─────────────────────────────────────────────────────────────────────────

function vcf_tickets_admin_menu() {
    add_menu_page(
        __('Venta de Entradas', 'vcf-tickets-config'),
        __('Venta de Entradas', 'vcf-tickets-config'),
        'manage_options',
        'vcf-tickets-config',
        'vcf_tickets_render_admin_page',
        'dashicons-tickets-alt',
        58
    );
}
add_action('admin_menu', 'vcf_tickets_admin_menu');

function vcf_tickets_enqueue_admin_assets($hook) {
    if ($hook !== 'toplevel_page_vcf-tickets-config') {
        return;
    }
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    wp_enqueue_script(
        'vcf-tickets-admin',
        plugins_url('admin.js', __FILE__),
        ['jquery', 'wp-color-picker'],
        '1.0.0',
        true
    );
    wp_enqueue_style(
        'vcf-tickets-admin',
        plugins_url('admin.css', __FILE__),
        [],
        '1.0.0'
    );
}
add_action('admin_enqueue_scripts', 'vcf_tickets_enqueue_admin_assets');

function vcf_tickets_render_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['vcf_tickets_save'])) {
        vcf_tickets_handle_save();
    }

    if (isset($_GET['vcf_refresh'])) {
        delete_transient('vcf_tickets_upcoming_matches');
    }

    $matches = vcf_tickets_fetch_upcoming_matches();
    $config = vcf_tickets_get_config();
    ?>
    <div class="wrap vcf-tickets-wrap">
        <h1><?php esc_html_e('Venta de Entradas — Partidos', 'vcf-tickets-config'); ?></h1>
        <p>
            <?php esc_html_e('Activa los partidos que quieres mostrar en los módulos de venta de entradas (home y página de venta de entradas). Solo se listan los próximos partidos según la API de calendario.', 'vcf-tickets-config'); ?>
        </p>
        <p>
            <a href="<?php echo esc_url(add_query_arg('vcf_refresh', '1')); ?>" class="button">
                <?php esc_html_e('Actualizar lista de partidos', 'vcf-tickets-config'); ?>
            </a>
        </p>

        <?php if (empty($matches)) : ?>
            <p><em><?php esc_html_e('No se han podido cargar próximos partidos ahora mismo.', 'vcf-tickets-config'); ?></em></p>
        <?php else : ?>
            <form method="post">
                <?php wp_nonce_field('vcf_tickets_save', 'vcf_tickets_nonce'); ?>

                <table class="widefat striped vcf-tickets-table">
                    <thead>
                        <tr>
                            <th style="width:40px;"><?php esc_html_e('Activo', 'vcf-tickets-config'); ?></th>
                            <th><?php esc_html_e('Partido', 'vcf-tickets-config'); ?></th>
                            <th><?php esc_html_e('Fecha', 'vcf-tickets-config'); ?></th>
                            <th><?php esc_html_e('Competición', 'vcf-tickets-config'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matches as $match) :
                            $id = (string) $match['id_partido'];
                            $entry = $config[$id] ?? [];
                            $active = !empty($entry['active']);
                            $fecha = $match['fecha'] ? date_i18n('d/m/Y', strtotime($match['fecha'])) : '';
                        ?>
                        <tr class="vcf-match-row">
                            <td>
                                <input
                                    type="checkbox"
                                    class="vcf-active-toggle"
                                    name="vcf_match[<?php echo esc_attr($id); ?>][active]"
                                    value="1"
                                    <?php checked($active); ?>
                                />
                                <input type="hidden" name="vcf_match[<?php echo esc_attr($id); ?>][equipo_local]" value="<?php echo esc_attr($match['equipo_local']); ?>" />
                                <input type="hidden" name="vcf_match[<?php echo esc_attr($id); ?>][equipo_visitante]" value="<?php echo esc_attr($match['equipo_visitante']); ?>" />
                            </td>
                            <td>
                                <strong><?php echo esc_html($match['equipo_local'] . ' vs ' . $match['equipo_visitante']); ?></strong>
                            </td>
                            <td><?php echo esc_html($fecha); ?></td>
                            <td><?php echo esc_html($match['competicion']); ?></td>
                        </tr>
                        <tr class="vcf-match-details <?php echo $active ? '' : 'vcf-hidden'; ?>" data-vcf-details-for="<?php echo esc_attr($id); ?>">
                            <td></td>
                            <td colspan="3">
                                <div class="vcf-fields-grid">

                                    <div class="vcf-field">
                                        <label><?php esc_html_e('Texto del botón de entradas', 'vcf-tickets-config'); ?></label>
                                        <input type="text" name="vcf_match[<?php echo esc_attr($id); ?>][ticket_text]" value="<?php echo esc_attr($entry['ticket_text'] ?? 'ON SALE'); ?>" placeholder="ON SALE" />
                                    </div>

                                    <div class="vcf-field">
                                        <label><?php esc_html_e('Enlace del botón de entradas', 'vcf-tickets-config'); ?></label>
                                        <input type="url" name="vcf_match[<?php echo esc_attr($id); ?>][ticket_link]" value="<?php echo esc_attr($entry['ticket_link'] ?? ''); ?>" placeholder="https://tickets.oneboxtds.com/..." />
                                        <p class="description"><?php esc_html_e('Déjalo vacío para mostrar "Coming Soon" en su lugar.', 'vcf-tickets-config'); ?></p>
                                    </div>

                                    <div class="vcf-field">
                                        <label><?php esc_html_e('Color de fondo del botón', 'vcf-tickets-config'); ?></label>
                                        <input type="text" class="vcf-color-field" name="vcf_match[<?php echo esc_attr($id); ?>][ticket_color]" value="<?php echo esc_attr($entry['ticket_color'] ?? '#129C00'); ?>" />
                                    </div>

                                    <div class="vcf-field">
                                        <label><?php esc_html_e('Color del texto del botón', 'vcf-tickets-config'); ?></label>
                                        <input type="text" class="vcf-color-field" name="vcf_match[<?php echo esc_attr($id); ?>][ticket_text_color]" value="<?php echo esc_attr($entry['ticket_text_color'] ?? '#ffffff'); ?>" />
                                    </div>

                                    <div class="vcf-field vcf-field-checkbox">
                                        <label>
                                            <input type="checkbox" class="vcf-vip-toggle" name="vcf_match[<?php echo esc_attr($id); ?>][show_vip]" value="1" <?php checked(!empty($entry['show_vip'])); ?> />
                                            <?php esc_html_e('Mostrar botón de entradas VIP', 'vcf-tickets-config'); ?>
                                        </label>
                                    </div>

                                    <div class="vcf-vip-fields <?php echo empty($entry['show_vip']) ? 'vcf-hidden' : ''; ?>" data-vcf-vip-for="<?php echo esc_attr($id); ?>">
                                        <div class="vcf-field">
                                            <label><?php esc_html_e('Texto del botón VIP', 'vcf-tickets-config'); ?></label>
                                            <input type="text" name="vcf_match[<?php echo esc_attr($id); ?>][vip_text]" value="<?php echo esc_attr($entry['vip_text'] ?? 'ENTRADAS VIP'); ?>" placeholder="ENTRADAS VIP" />
                                        </div>
                                        <div class="vcf-field">
                                            <label><?php esc_html_e('Enlace del botón VIP', 'vcf-tickets-config'); ?></label>
                                            <input type="url" name="vcf_match[<?php echo esc_attr($id); ?>][vip_link]" value="<?php echo esc_attr($entry['vip_link'] ?? ''); ?>" placeholder="https://tickets.oneboxtds.com/..." />
                                        </div>
                                    </div>

                                    <div class="vcf-field vcf-field-checkbox">
                                        <label>
                                            <input type="checkbox" class="vcf-moreinfo-toggle" name="vcf_match[<?php echo esc_attr($id); ?>][show_more_info]" value="1" <?php checked(!empty($entry['show_more_info'])); ?> />
                                            <?php esc_html_e('Mostrar botón de "Más información" (modal)', 'vcf-tickets-config'); ?>
                                        </label>
                                    </div>

                                    <div class="vcf-moreinfo-fields <?php echo empty($entry['show_more_info']) ? 'vcf-hidden' : ''; ?>" data-vcf-moreinfo-for="<?php echo esc_attr($id); ?>">
                                        <div class="vcf-field">
                                            <label><?php esc_html_e('Título del modal', 'vcf-tickets-config'); ?></label>
                                            <input type="text" name="vcf_match[<?php echo esc_attr($id); ?>][more_info_title]" value="<?php echo esc_attr($entry['more_info_title'] ?? __('Información del partido', 'vcf-tickets-config')); ?>" />
                                        </div>
                                        <div class="vcf-field vcf-field-wide">
                                            <label><?php esc_html_e('Texto del modal (admite HTML: <p>, <strong>, <ul>...)', 'vcf-tickets-config'); ?></label>
                                            <textarea name="vcf_match[<?php echo esc_attr($id); ?>][more_info_text]" rows="5"><?php echo esc_textarea($entry['more_info_text'] ?? ''); ?></textarea>
                                        </div>
                                    </div>

                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <input type="submit" name="vcf_tickets_save" class="button button-primary" value="<?php esc_attr_e('Guardar configuración', 'vcf-tickets-config'); ?>" />
                </p>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────────────────
// Endpoint REST — para que el frontend (Next.js) pueda consumir esta
// configuración directamente, sin depender de que el scraper de HTML que
// alimenta wph/v2/shortcodes capture correctamente el color/VIP/modal.
// ─────────────────────────────────────────────────────────────────────────

function vcf_tickets_register_rest_route() {
    register_rest_route('vcf/v1', '/tickets-config', [
        'methods'             => 'GET',
        'callback'            => 'vcf_tickets_rest_get_config',
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'vcf_tickets_register_rest_route');

function vcf_tickets_rest_get_config() {
    $config = vcf_tickets_get_config();

    // Solo devolvemos los partidos activos, y solo los campos que necesita
    // el frontend (nunca hace falta exponer más de la cuenta).
    $active = [];
    foreach ($config as $id => $entry) {
        if (empty($entry['active'])) {
            continue;
        }
        $active[$id] = [
            'equipo_local'      => $entry['equipo_local'] ?? '',
            'equipo_visitante'  => $entry['equipo_visitante'] ?? '',
            'ticket_text'       => $entry['ticket_text'] ?? '',
            'ticket_link'       => $entry['ticket_link'] ?? '',
            'ticket_color'      => $entry['ticket_color'] ?? '#129C00',
            'ticket_text_color' => $entry['ticket_text_color'] ?? '#ffffff',
            'show_vip'          => !empty($entry['show_vip']),
            'vip_text'          => $entry['vip_text'] ?? '',
            'vip_link'          => $entry['vip_link'] ?? '',
            'show_more_info'    => !empty($entry['show_more_info']),
            'more_info_title'   => $entry['more_info_title'] ?? '',
            'more_info_text'    => $entry['more_info_text'] ?? '',
        ];
    }

    return rest_ensure_response(['matches' => $active]);
}
