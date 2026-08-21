/**
 * Register Custom REST API Route with Alias Parameter
 */
add_action('rest_api_init', function () {
    register_rest_route('vplay/v1', '/vplay-portada-2', array(
        'methods'  => 'GET',
        'callback' => 'vplay_get_grid_as_object',
        'permission_callback' => '__return_true',
    ));
});

/**
 * Callback to parse Essential Grid shortcode by Alias
 */
function vplay_get_grid_as_object($request) {
    // Get alias from URL param, default to vplay-portada-2
    $alias = $request->get_param('alias') ? sanitize_text_field($request->get_param('alias')) : 'vplay-portada-2';
    
    // Render the specific shortcode
    $grid_html = do_shortcode('[ess_grid alias="' . $alias . '"][/ess_grid]');
    
    if (empty($grid_html) || strpos($grid_html, 'Grid Not Found') !== false) {
        return new WP_Error('no_grid', 'Grid alias not found.', array('status' => 404));
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $grid_html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $items = array();

    // Target the list items
    $nodes = $xpath->query("//li[contains(@class, 'eg-vplay-portada-wrapper')]");

    foreach ($nodes as $node) {
        $title_node = $xpath->query(".//a[contains(@class, 'eg-vplay-portada-element-0')]", $node)->item(0);
        $media_node = $xpath->query(".//div[contains(@class, 'esg-media-video')]", $node)->item(0);
        $icon_node  = $xpath->query(".//a[contains(@class, 'eg-vplay-portada-element-25')]//img", $node)->item(0);

        $items[] = array(
            'id'         => $node->getAttribute('id'),
            'title'      => $title_node ? trim($title_node->nodeValue) : '',
            'video_url'  => $title_node ? $title_node->getAttribute('href') : '',
            'youtube_id' => $media_node ? $media_node->getAttribute('data-youtube') : '',
            'poster_img' => $media_node ? $media_node->getAttribute('data-poster') : '',
            'icon_img'   => $icon_node  ? $icon_node->getAttribute('src') : '',
            'timestamp'  => $node->getAttribute('data-date')
        );
    }

    return array(
        'status'  => 'success',
        'alias'   => $alias,
        'results' => $items
    );
}
