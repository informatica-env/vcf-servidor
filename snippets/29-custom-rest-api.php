
/**
 * Final Clean REST API Endpoint for Next.js
 * Supports Deep Nested Routes: /endavant/endavant-provincia/dinosaurio-groguet/
 */

add_action('rest_api_init', function () {
    register_rest_route('v2', '/page-by-path', array(
        'methods' => 'GET',
        'callback' => 'get_final_clean_page_data',
        'permission_callback' => '__return_true'
    ));
});

/*function get_final_clean_page_data($data) {
    // Sanitize and trim the incoming path
    $path = isset($data['path']) ? trim($data['path'], '/') : '';
    
    if (empty($path)) {
        return new WP_Error('no_path', 'Path parameter is missing', array('status' => 400));
    }

    // This handles single slugs or deep nested paths like /a/b/c/
    $page = get_page_by_path($path);

    if (!$page) {
        return new WP_Error('no_page', 'No page found for path: ' . $path, array('status' => 404));
    }

    // 1. Get content and apply WP filters
    $content = apply_filters('the_content', $page->post_content);
    
    // 2. Convert HTML Entities (e.g. \u003C to ]*>(.*?)<\/svg>/is', "", $content);

    // 5. Remove all inline Style attributes (style="...")
    $content = preg_replace('/ style="[^"]*"/', '', $content);

    // 6. Strip Script and Style tags entirely
    $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $content);
    $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $content);

    // 7. Remove Elementor data attributes (data-id, data-element_type, etc.)
    $content = preg_replace('/ data-[a-zA-Z0-9-]+="[^"]*"/', '', $content);

    // 8. Final clean: Collapse extra spaces and remove empty paragraphs
    $content = preg_replace('/\s+/', ' ', $content);
    $content = preg_replace('/<p>\s*<\/p>/', '', $content);

    return array(
        'status'    => 'success',
        'id'        => $page->ID,
        'title'     => get_the_title($page->ID),
        'slug'      => $page->post_name,
        'full_path' => $path,
        'content'   => trim($content)
    );
}*/

function get_final_clean_page_data($data) {
    $path = isset($data['path']) ? trim($data['path'], '/') : '';
    $lang = isset($data['lang']) ? sanitize_text_field($data['lang']) : '';

    if (empty($path)) {
        return new WP_Error('no_path', 'Path parameter is missing', array('status' => 400));
    }

    $default_lang = 'es';
    $valid_langs  = ['es', 'en', 'val'];
    $active_lang  = in_array($lang, $valid_langs, true) ? $lang : $default_lang;

    // 1. Buscar siempre por el path en el idioma por defecto
    $page = get_page_by_path($path);

    if (!$page) {
        return new WP_Error('no_page', 'No page found for path: ' . $path, array('status' => 404));
    }

    $page_id = $page->ID;

    // 2. Si se pide otro idioma, obtener el ID traducido via WPML
    if ($active_lang !== $default_lang && function_exists('apply_filters')) {
        $translated_id = apply_filters('wpml_object_id', $page_id, 'page', false, $active_lang);

        if ($translated_id) {
            $page_id = $translated_id;
            $page    = get_post($translated_id);
        }
        // si false → fallback a la página original en español
    }

    if (!$page) {
        return new WP_Error('no_page', 'No translation found', array('status' => 404));
    }

    $content = apply_filters('the_content', $page->post_content);
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $content = preg_replace('/<svg[\s\S]*?<\/svg>/i', '', $content);
    $content = preg_replace('/ style="[^"]*"/', '', $content);
    $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);
    $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
    $content = preg_replace('/ data-[a-zA-Z0-9-]+="[^"]*"/', '', $content);
    $content = preg_replace('/\s+/', ' ', $content);
    $content = preg_replace('/<p>\s*<\/p>/', '', $content);

    return array(
        'status'    => 'success',
        'id'        => $page_id,
        'title'     => get_the_title($page_id),
        'slug'      => $page->post_name,
        'full_path' => $path,
        'lang'      => $active_lang,
        'content'   => trim($content)
    );
}

/**
 * Remove WP default bloat from header
 */
add_action('init', function() {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('wp_head', 'wp_generator');
});

add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/search-page/', array(
        'methods' => 'GET',
        'callback' => 'search_elementor_page',
        'permission_callback' => '__return_true'
    ));
});

function search_elementor_page($request) {
    $name = sanitize_text_field($request->get_param('name'));
    $lang = sanitize_text_field($request->get_param('lang')) ?: 'es';

    // Cambiar idioma activo de WPML
    do_action('wpml_switch_language', $lang);

    $page = get_page_by_path($name);
    if (!$page) {
        return new WP_Error('not_found', 'Page not found', array('status' => 404));
    }

    // Obtener el ID traducido para el idioma solicitado
    $translated_id = apply_filters('wpml_object_id', $page->ID, 'page', true, $lang);
    $translated_page = get_post($translated_id);

    if (!$translated_page) {
        return new WP_Error('not_found', 'Translated page not found', array('status' => 404));
    }

    $elementor_data = get_post_meta($translated_id, '_elementor_data', true);

    // Restaurar idioma original
    do_action('wpml_switch_language', 'es');

    return array(
        'id' => $translated_page->ID,
        'title' => $translated_page->post_title,
        'slug' => $translated_page->post_name,
        'widgets' => json_decode($elementor_data, true)
    );
}

add_action('rest_api_init', function () {
  register_rest_route('custom/v1', '/player-gallery/(?P<id>\d+)', [
    'methods'             => 'GET',
    'permission_callback' => '__return_true',
    'callback'            => function (WP_REST_Request $req) {
      $id   = (int) $req['id'];
      $html = do_shortcode(
        "[player_gallery id=\"{$id}\" number=\"-1\" columns=\"1\" grouptag=\"h3\" size=\"full\" show_all_players_link=\"0\"]"
      );
      return rest_ensure_response(['html' => $html]);
    },
  ]);
});