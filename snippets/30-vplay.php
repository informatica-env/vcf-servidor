add_action('rest_api_init', function () {
    register_rest_route('vplay/v1', '/grid/(?P<alias>[a-zA-Z0-9-_]+)', array(
        'methods' => 'GET',
        'callback' => 'get_vplay_grid_data',
        'permission_callback' => '__return_true', // Adjust for security if needed
    ));
});

function get_vplay_grid_data($data) {
    $alias = $data['alias'];
    
    // Check if Essential Grid class exists
    if (!class_exists('Essential_Grid')) {
        return new WP_Error('plugin_missing', 'Essential Grid plugin is not active', array('status' => 404));
    }

    $grid = new Essential_Grid();
    $db = new Essential_Grid_Db();
    
    // Fetch grid by alias
    $grid_data = $db->get_grid_by_alias($alias);
    if (empty($grid_data)) {
        return new WP_Error('no_grid', 'Grid not found', array('status' => 404));
    }

    // Initialize grid to process its items
    $id = $grid_data['id'];
    $items = $grid->get_posts_by_grid_id($id); // Fetches the actual posts/items for the grid

    $response = array();

    foreach ($items as $item) {
        $post_id = $item['ID'];
        $video_url = get_post_meta($post_id, 'eg-vplay-video-url', true); // Replace with your actual meta key
        $youtube_id = ''; // Logic to extract ID from video_url if needed

        $response[] = array(
            "image" => get_the_post_thumbnail_url($post_id, 'full'),
            "text"  => get_the_title($post_id),
            "link"  => get_permalink($post_id),
            "video_url" => $video_url,
            "data" => array(
                "youtube_id" => $youtube_id,
                "poster_url" => get_the_post_thumbnail_url($post_id, 'full'),
                "logo_url"   => "https://panel.villarrealcf.es/wp-content/uploads/2026/01/VPLAY_Blanco.png.webp", // Static or dynamic
                "embed_url"  => "https://www.youtube.com/embed/" . $youtube_id
            )
        );
    }

    return rest_ensure_response($response);
}
