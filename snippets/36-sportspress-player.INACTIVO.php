
/**
 * Custom REST API for SportsPress Player Data
 * Endpoint: /wp-json/custom/v1/player-full-data/{id}
 */

// Register REST API routes
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/player-full-data/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'get_complete_player_data',
        'permission_callback' => '__return_true', // Public access
        'args' => array(
            'id' => array(
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            )
        )
    ));
});

// Main callback function
function get_complete_player_data($request) {
    $player_id = $request['id'];
    
    // Get player object
    $player = new SP_Player($player_id);
    
    if (!$player->ID) {
        return new WP_Error('no_player', 'Player not found', array('status' => 404));
    }
    
    // Get basic player info
    $player_data = array(
        'id' => $player->ID,
        'name' => $player->post_title,
        'slug' => $player->post_name,
        'number' => get_post_meta($player->ID, 'sp_number', true),
        'link' => get_permalink($player->ID),
    );
    
    // Get position
    $positions = wp_get_post_terms($player->ID, 'sp_position');
    $player_data['position'] = !empty($positions) ? $positions[0]->name : '';
    
    // Get nationality
    $nationalities = get_post_meta($player->ID, 'sp_nationality', true);
    $player_data['nationality'] = $nationalities ? $nationalities : '';
    
    // Get team info
    $current_teams = get_post_meta($player->ID, 'sp_current_team', true);
    $player_data['current_team'] = array();
    if ($current_teams) {
        $team = get_post($current_teams[0]);
        if ($team) {
            $player_data['current_team'] = array(
                'id' => $team->ID,
                'name' => $team->post_title,
                'logo' => get_the_post_thumbnail_url($team->ID)
            );
        }
    }
    
    // Get images
    $player_data['profile_image'] = get_the_post_thumbnail_url($player->ID, 'full');
    $player_data['kit_image'] = get_field('imagen-jugador-interior', $player->ID)['url'] ?? '';
    
    // Get biography from content
    $player_data['biography'] = array(
        'full_text' => $player->post_content,
        'paragraphs' => array()
    );
    
    // Parse content blocks
    $blocks = parse_blocks($player->post_content);
    foreach ($blocks as $block) {
        if ($block['blockName'] === 'core/paragraph') {
            $player_data['biography']['paragraphs'][] = $block['innerHTML'];
        } elseif ($block['blockName'] === 'core/list') {
            $player_data['biography']['previous_teams'] = extract_list_items($block['innerHTML']);
        }
    }
    
    // Get STATISTICS (League & Season wise)
    $leagues = get_the_terms($player->ID, 'sp_league');
    $seasons = get_the_terms($player->ID, 'sp_season');
    
    $player_data['statistics'] = array();
    
    if ($leagues && $seasons) {
        foreach ($leagues as $league) {
            foreach ($seasons as $season) {
                $stats = $player->data($league->term_id, $season->term_id);
                if (!empty($stats)) {
                    $player_data['statistics'][] = array(
                        'league' => $league->name,
                        'league_id' => $league->term_id,
                        'season' => $season->name,
                        'season_id' => $season->term_id,
                        'stats' => $stats
                    );
                }
            }
        }
    }
    
    // Get PERFORMANCE METRICS
    $player_data['performance'] = array(
        'matches' => 0,
        'minutes' => 0,
        'goals' => 0,
        'assists' => 0,
        'yellow_cards' => 0,
        'red_cards' => 0,
        'saves' => 0,      // For goalkeeper
        'clean_sheets' => 0 // For goalkeeper
    );
    
    // Calculate from statistics
    foreach ($player_data['statistics'] as $stat_section) {
        $stats = $stat_section['stats'];
        if (isset($stats['matchesplayed'])) {
            $player_data['performance']['matches'] += intval($stats['matchesplayed']);
        }
        if (isset($stats['minutesplayed'])) {
            $player_data['performance']['minutes'] += intval($stats['minutesplayed']);
        }
        if (isset($stats['goals'])) {
            $player_data['performance']['goals'] += intval($stats['goals']);
        }
        if (isset($stats['assists'])) {
            $player_data['performance']['assists'] += intval($stats['assists']);
        }
        if (isset($stats['yellowcards'])) {
            $player_data['performance']['yellow_cards'] += intval($stats['yellowcards']);
        }
        if (isset($stats['redcards'])) {
            $player_data['performance']['red_cards'] += intval($stats['redcards']);
        }
        // Goalkeeper specific
        if (isset($stats['saves'])) {
            $player_data['performance']['saves'] += intval($stats['saves']);
        }
        if (isset($stats['cleansheets'])) {
            $player_data['performance']['clean_sheets'] += intval($stats['cleansheets']);
        }
    }
    
    // Get COMPETITION WISE breakdown
    $player_data['competition_stats'] = array();
    
    // Get all matches for this player
    $matches = get_posts(array(
        'post_type' => 'sp_event',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'sp_player',
                'value' => $player->ID,
                'compare' => 'LIKE'
            )
        )
    ));
    
    foreach ($matches as $match) {
        $league = get_the_terms($match->ID, 'sp_league');
        $league_name = $league ? $league[0]->name : 'Unknown';
        
        if (!isset($player_data['competition_stats'][$league_name])) {
            $player_data['competition_stats'][$league_name] = array(
                'matches' => 0,
                'minutes' => 0,
                'goals' => 0,
                'assists' => 0,
                'yellow_cards' => 0,
                'red_cards' => 0
            );
        }
        
        // Get match player performance
        $performance = get_post_meta($match->ID, 'sp_players', true);
        if ($performance && isset($performance[$player->ID])) {
            $player_data['competition_stats'][$league_name]['matches']++;
            // Add more details from match performance
        }
    }
    
    // Convert competition stats to array
    $player_data['competition_stats'] = array_map(function($stats, $name) {
        return array_merge(['competition' => $name], $stats);
    }, $player_data['competition_stats'], array_keys($player_data['competition_stats']));
    
    // Get previous teams
    $player_data['previous_teams'] = array();
    $past_teams = get_post_meta($player->ID, 'sp_past_team', true);
    if ($past_teams) {
        foreach ($past_teams as $team_id) {
            $team = get_post($team_id);
            if ($team) {
                $player_data['previous_teams'][] = $team->post_title;
            }
        }
    }
    
    // Get personal info from ACF if available
    $player_data['personal_info'] = array(
        'birth_date' => get_field('fecha_nacimiento', $player->ID),
        'height' => get_field('altura', $player->ID),
        'weight' => get_field('peso', $player->ID)
    );
    
    // Shop URL
    $player_data['shop_url'] = "https://shop.panel.villarrealcf.es/es/inicio/1703-10928-camiseta-jugador-{$player_data['number']}-25-26.html";
    
    return rest_ensure_response($player_data);
}

// Helper function to extract list items from HTML
function extract_list_items($html) {
    $items = array();
    preg_match_all('/<li>(.*?)<\/li>/', $html, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $item) {
            $items[] = strip_tags($item);
        }
    }
    return $items;
}

// Alternative: Get all players list with basic info
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/players-list', array(
        'methods' => 'GET',
        'callback' => 'get_all_players_list',
        'permission_callback' => '__return_true'
    ));
});

function get_all_players_list() {
    $players = get_posts(array(
        'post_type' => 'sp_player',
        'posts_per_page' => -1,
        'orderby' => 'menu_order',
        'order' => 'ASC'
    ));
    
    $players_list = array();
    foreach ($players as $player) {
        $players_list[] = array(
            'id' => $player->ID,
            'name' => $player->post_title,
            'slug' => $player->post_name,
            'number' => get_post_meta($player->ID, 'sp_number', true),
            'position' => wp_get_post_terms($player->ID, 'sp_position')[0]->name ?? '',
            'image' => get_the_post_thumbnail_url($player->ID, 'medium')
        );
    }
    
    return rest_ensure_response($players_list);
}
