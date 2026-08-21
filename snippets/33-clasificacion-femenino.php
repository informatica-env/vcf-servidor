add_action('rest_api_init', function () {
    register_rest_route('femenino', '/clasificacion', [
        'methods'             => 'GET',
        'callback'            => 'get_clasificacion_data2', // Matches function name below
        'permission_callback' => '__return_true',        // Required in newer WP versions
    ]);
});

function get_clasificacion_data2($request) { // Fixed name

    // 🔹 Run shortcode → get HTML
    $html = do_shortcode('[clasificacion_femenino equipo="Villarreal Femenino"]');

    if (empty($html)) {
        return new WP_Error('no_data', 'Shortcode returned no content', ['status' => 404]);
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    // Use mb_convert_encoding to ensure DOMDocument handles UTF-8 correctly
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xp = new DOMXPath($dom);

    $teams = [];

    foreach ($xp->query("//tbody/tr") as $row) {
        $position = trim($xp->query(".//span[contains(@class,'position-row__number')]", $row)->item(0)->nodeValue ?? '');
        $team     = trim($xp->query(".//span[contains(@class,'team-row__name--short')]", $row)->item(0)->nodeValue ?? '');
        
        $logo_node = $xp->query(".//img[contains(@class,'badge-image')]", $row)->item(0);
        $logo      = $logo_node ? $logo_node->getAttribute("src") : '';

        $stats = $xp->query(".//td[contains(@class,'table-stat-row')]", $row);

        // 🔹 Form (V/E/D)
        $form = [];
        foreach ($xp->query(".//ul[contains(@class,'team-form')]//abbr", $row) as $f) {
            $form[] = trim($f->nodeValue);
        }

        // 🔹 Zone detection
        $rowClass = $row->getAttribute("class");
        $zone = "normal";
        if (strpos($rowClass, "champions") !== false) $zone = "champions";
        elseif (strpos($rowClass, "europa") !== false) $zone = "europa";
        elseif (strpos($rowClass, "relegation") !== false) $zone = "relegation";

        $teams[] = [
            "position" => (int)$position,
            "team"     => $team,
            "logo"     => $logo,
            "points"   => (int)($stats->item(0)->nodeValue ?? 0),
            "played"   => (int)($stats->item(1)->nodeValue ?? 0),
            "wins"     => (int)($stats->item(2)->nodeValue ?? 0),
            "draws"    => (int)($stats->item(3)->nodeValue ?? 0),
            "losses"   => (int)($stats->item(4)->nodeValue ?? 0),
            "gf"       => (int)($stats->item(5)->nodeValue ?? 0),
            "gc"       => (int)($stats->item(6)->nodeValue ?? 0),
            "gd"       => (int)($stats->item(7)->nodeValue ?? 0),
            "form"     => $form,
            "zone"     => $zone
        ];
    }
    
    libxml_clear_errors();

    // =========================
    // 🔥 FILTER SYSTEM
    // =========================

    $team_name = $request->get_param('team');
    $min_pos   = $request->get_param('min_pos');
    $max_pos   = $request->get_param('max_pos');
    $zone_param = $request->get_param('zone');

    // Filter Logic
    if ($team_name || $min_pos !== null || $max_pos !== null || $zone_param) {
        $teams = array_values(array_filter($teams, function ($t) use ($team_name, $min_pos, $max_pos, $zone_param) {
            $match = true;
            if ($team_name && stripos($t['team'], $team_name) === false) $match = false;
            if ($min_pos !== null && $t['position'] < (int)$min_pos) $match = false;
            if ($max_pos !== null && $t['position'] > (int)$max_pos) $match = false;
            if ($zone_param && $t['zone'] !== $zone_param) $match = false;
            return $match;
        }));
    }

    return [
        "league" => "Primera Federación",
        "season" => "2025/2026",
        "total"  => count($teams),
        "data"   => $teams
    ];
}
