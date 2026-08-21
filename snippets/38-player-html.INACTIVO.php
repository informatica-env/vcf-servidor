add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/player-html/(?P<slug>[a-zA-Z0-9-]+)', array(
        'methods' => 'GET',
        'callback' => 'get_player_full_body_html',
    ));
});

function get_player_full_body_html($data) {
    $slug = $data['slug'];

    // Full page URL
    $url = home_url('/player/' . $slug);

    $response = wp_remote_get($url);

    if (is_wp_error($response)) {
        return ['error' => 'Failed to fetch page'];
    }

    $html = wp_remote_retrieve_body($response);

    // Extract <body> content
    preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches);

    $body = isset($matches[1]) ? $matches[1] : null;

    return [
        'slug' => $slug,
        'body_html' => $body
    ];
}