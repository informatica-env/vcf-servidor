add_action('rest_api_init', function () {
    register_rest_route('ess-grid/v1', '/data/(?P<alias>[a-zA-Z0-9-_]+)', array(
        'methods'  => 'GET',
        'callback' => 'get_ess_grid_object_data_simple',
        'permission_callback' => '__return_true',
    ));
});

function get_ess_grid_object_data_simple($data) {
    if (!shortcode_exists('ess_grid')) {
        return new WP_Error('plugin_missing', 'Essential Grid is not active', array('status' => 500));
    }
    
    $alias = sanitize_text_field($data['alias']);
    
    // Get grid ID from database directly
    global $wpdb;
    $table_name = $wpdb->prefix . 'eg_grids';
    
    $grid = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE handle = %s",
        $alias
    ), ARRAY_A);
    
    if (!$grid) {
        return new WP_Error('no_grid', 'Grid not found with alias: ' . $alias, array('status' => 404));
    }
    
    // Get grid posts from postmeta or try to parse params
    $params = json_decode($grid['params'], true);
    $items = array();
    
    if ($params && isset($params['post_ids'])) {
        $post_ids = explode(',', $params['post_ids']);
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish') {
                $items[] = array(
                    'id' => $post->ID,
                    'title' => get_the_title($post),
                    'link' => get_permalink($post),
                    'date' => get_the_date('c', $post),
                    'excerpt' => get_the_excerpt($post),
                    'image' => get_the_post_thumbnail_url($post, 'large')
                );
            }
        }
    }
    
    return rest_ensure_response(array(
        'alias' => $alias,
        'grid_name' => $grid['name'],
        'total_items' => count($items),
        'items' => $items
    ));
}