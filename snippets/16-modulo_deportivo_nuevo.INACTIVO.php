
add_action('rest_api_init', function () {
    register_rest_route('v1', '/homepage', array(
        'methods'  => 'GET',
        'callback' => 'custom_get_homepage_data',
    ));
});

function custom_get_homepage_data() {

    // Change slug if needed
    $page = get_page_by_path('homepage-nueva');

    if (!$page) {
        return [
            'status' => 'error',
            'message' => 'Page not found'
        ];
    }

    $content = $page->post_content;

    // Extract all shortcode instances
    preg_match_all('/\[modulo_deportivo_nuevo([^\]]*)\]/', $content, $matches);

    $modules = [];

    if (!empty($matches[0])) {
        foreach ($matches[0] as $index => $shortcode) {

            // Parse attributes
            $atts_string = $matches[1][$index];
            $atts = shortcode_parse_atts($atts_string);

            $modules[] = build_modulo_deportivo_module($atts);
        }
    }

    return [
        'page_id'   => $page->ID,
        'page_slug' => $page->post_name,
        'modules'   => $modules
    ];
}


// Build module data
function build_modulo_deportivo_module($atts = []) {

    // Default values (override from shortcode)
    $posts_per_page = isset($atts['posts']) ? intval($atts['posts']) : 5;
    $category       = isset($atts['category']) ? sanitize_text_field($atts['category']) : '';

    $args = [
        'post_type'      => 'post',
        'posts_per_page' => $posts_per_page,
    ];

    if (!empty($category)) {
        $args['category_name'] = $category;
    }

    $query = new WP_Query($args);

    $posts = [];

    while ($query->have_posts()) {
        $query->the_post();

        $posts[] = [
            'id'        => get_the_ID(),
            'title'     => get_the_title(),
            'slug'      => get_post_field('post_name'),
            'excerpt'   => wp_strip_all_tags(get_the_excerpt()),
            'content'   => wp_strip_all_tags(get_the_content()),
            'image'     => get_the_post_thumbnail_url(get_the_ID(), 'full'),
            'date'      => get_the_date('c'),
            'author'    => get_the_author(),
            'categories'=> wp_get_post_categories(get_the_ID(), ['fields' => 'names']),
            'tags'      => wp_get_post_tags(get_the_ID(), ['fields' => 'names']),
        ];
    }

    wp_reset_postdata();

    return [
        'type'  => 'modulo_deportivo_nuevo',
        'config'=> [
            'posts_per_page' => $posts_per_page,
            'category'       => $category,
        ],
        'posts' => $posts
    ];
}