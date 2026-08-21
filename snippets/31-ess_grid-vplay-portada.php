/**
 * Register Unified REST API Route
 */
add_action('rest_api_init', function () {
    register_rest_route('vplay/v1', '/portada', array(
        'methods'  => 'GET',
        'callback' => 'vplay_get_merged_grids',
        'permission_callback' => '__return_true',
    ));
});

/**
 * Callback to merge multiple Essential Grids into one Object
 */
function vplay_get_merged_grids() {
    // Define the aliases you want to merge
    $aliases = array('vplay-portada-2', 'vplay-portada');
    $merged_results = array();

    foreach ($aliases as $alias) {
        $grid_html = do_shortcode('[ess_grid alias="' . $alias . '"][/ess_grid]');
        
        if (empty($grid_html) || strpos($grid_html, 'Grid Not Found') !== false) {
            continue;
        }

        // Parse HTML
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $grid_html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        
        // Note: Using a generic li query if skins differ, 
        // or keep 'eg-vplay-portada-wrapper' if they use the same skin.
        $nodes = $xpath->query("//li[contains(@class, 'eg-vplay-portada-wrapper')]");

        foreach ($nodes as $node) {
            $title_node = $xpath->query(".//a[contains(@class, 'eg-vplay-portada-element-0')]", $node)->item(0);
            $media_node = $xpath->query(".//div[contains(@class, 'esg-media-video')]", $node)->item(0);
            $icon_node  = $xpath->query(".//a[contains(@class, 'eg-vplay-portada-element-25')]//img", $node)->item(0);

            $merged_results[] = array(
                'source_alias' => $alias,
                'id'           => $node->getAttribute('id'),
                'title'        => $title_node ? trim($title_node->nodeValue) : '',
                'video_url'    => $title_node ? $title_node->getAttribute('href') : '',
                'youtube_id'   => $media_node ? $media_node->getAttribute('data-youtube') : '',
                'poster_img'   => $media_node ? $media_node->getAttribute('data-poster') : '',
                'icon_img'     => $icon_node  ? $icon_node->getAttribute('src') : '',
                'timestamp'    => $node->getAttribute('data-date')
            );
        }
    }

    // Optional: Sort by timestamp (newest first)
    usort($merged_results, function($a, $b) {
        return $b['timestamp'] <=> $a['timestamp'];
    });

    return array(
        'status'  => 'success',
        'total'   => count($merged_results),
        'results' => $merged_results
    );
}
