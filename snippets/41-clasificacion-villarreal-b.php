add_action('rest_api_init', function () {

    register_rest_route('villarreal_b', '/clasificacion', [
        'methods'  => 'GET',
        'callback' => 'get_clasificacion_data',
    ]);

});

function get_clasificacion_data($request) {

    // 🔹 Run shortcode → get HTML
    $html = do_shortcode('[clasificacion_villarreal_b equipo="Villarreal B"]');

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xp = new DOMXPath($dom);

    $teams = [];

    foreach ($xp->query("//tbody/tr") as $row) {

        $position = trim($xp->query(".//span[contains(@class,'position-row__number')]", $row)->item(0)->nodeValue ?? '');

        $team = trim($xp->query(".//span[contains(@class,'team-row__name--short')]", $row)->item(0)->nodeValue ?? '');

        $logo = $xp->query(".//img[contains(@class,'badge-image')]", $row)->item(0)->getAttribute("src") ?? '';

        $stats = $xp->query(".//td[contains(@class,'table-stat-row')]", $row);

        $points = trim($stats->item(0)->nodeValue ?? '');
        $played = trim($stats->item(1)->nodeValue ?? '');
        $wins   = trim($stats->item(2)->nodeValue ?? '');
        $draws  = trim($stats->item(3)->nodeValue ?? '');
        $losses = trim($stats->item(4)->nodeValue ?? '');
        $gf     = trim($stats->item(5)->nodeValue ?? '');
        $gc     = trim($stats->item(6)->nodeValue ?? '');
        $gd     = trim($stats->item(7)->nodeValue ?? '');

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
            "points"   => (int)$points,
            "played"   => (int)$played,
            "wins"     => (int)$wins,
            "draws"    => (int)$draws,
            "losses"   => (int)$losses,
            "gf"       => (int)$gf,
            "gc"       => (int)$gc,
            "gd"       => (int)$gd,
            "form"     => $form,
            "zone"     => $zone
        ];
    }

    // =========================
    // 🔥 FILTER SYSTEM
    // =========================

    $team_name = $request->get_param('team');
    $min_pos   = $request->get_param('min_pos');
    $max_pos   = $request->get_param('max_pos');
    $zone      = $request->get_param('zone');

    // 🔹 Filter by team name
    if ($team_name) {
        $teams = array_values(array_filter($teams, function ($t) use ($team_name) {
            return stripos($t['team'], $team_name) !== false;
        }));
    }

    // 🔹 Filter by position range
    if ($min_pos !== null && $max_pos !== null) {
        $teams = array_values(array_filter($teams, function ($t) use ($min_pos, $max_pos) {
            return $t['position'] >= $min_pos && $t['position'] <= $max_pos;
        }));
    }

    // 🔹 Filter by zone
    if ($zone) {
        $teams = array_values(array_filter($teams, function ($t) use ($zone) {
            return $t['zone'] === $zone;
        }));
    }

    return [
        "league" => "Primera Federación",
        "season" => "2025/2026",
        "total"  => count($teams),
        "data"   => $teams
    ];
}