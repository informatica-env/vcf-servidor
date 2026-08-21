
/**
 * Custom REST API for SportsPress Player Statistics (Safe Version)
 */

add_action('rest_api_init', function () {
    register_rest_route('sportspress/v2', '/player-statistics', array(
        'methods' => 'GET',
        'callback' => 'get_player_statistics',
        'permission_callback' => '__return_true'
    ));
});

function get_player_statistics($request) {
    $player_id = isset($request['player']) ? intval($request['player']) : 0;

    if (!$player_id) {
        return new WP_Error('no_player', 'Player ID is missing', array('status' => 400));
    }

    // SportsPress stores players as custom post type 'sp_player'
    $player = get_post($player_id);
    if (!$player || $player->post_type !== 'sp_player') {
        return new WP_Error('invalid_player', 'No player found with ID: ' . $player_id, array('status' => 404));
    }

    // Try to get statistics using SportsPress function if available
    $statistics = array();
    if (function_exists('sp_get_player_statistics')) {
        $statistics = sp_get_player_statistics($player_id);
    } else {
        // Fallback: try to get statistics from post meta
        $statistics = get_post_meta($player_id, 'sp_statistics', true);
    }

    if (empty($statistics)) {
        return new WP_Error('no_stats', 'No statistics found for player ID: ' . $player_id, array('status' => 404));
    }

    return array(
        'status'      => 'success',
        'player_id'   => $player_id,
        'player_name' => get_the_title($player_id),
        'statistics'  => $statistics
    );
}
