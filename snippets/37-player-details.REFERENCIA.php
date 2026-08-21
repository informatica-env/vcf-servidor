<?php
/*
Plugin Name: Player API FINAL
Version: 7.1 — caché, timeouts cortos y sin HTTP interno para SportsPress

CAMBIOS RESPECTO A LA 6.1 (y solo estos, a propósito):

  1. CACHÉ de la respuesta completa (10 min) y de la plantilla (30 min).
     Redis ya está activo, así que sale gratis. Es el cambio que evita
     el bloqueo: de ~4 procesos PHP por visitante a ~4 cada 10 minutos.
  2. TIMEOUTS de 15 s → 4 s, más CONNECTTIMEOUT de 2 s.
  3. La PLANTILLA ya no se pide en cada llamada. Era idéntica para todos
     los jugadores y se descargaba una vez por visita.
  4. EQUIPO leido de post meta (sp_current_team). Ni HTTP ni REST:
     dos procesos PHP menos por llamada, y el dato es el de origen.
  5. CORTAFUEGOS anti-recursión por cabecera.

Todo lo demás —las funciones de parseo, la forma de la respuesta— queda
EXACTAMENTE igual que en la 6.1. Cuanto menos se toque, menos puede
romperse, y hoy no es día de refactorizar.
*/

add_action('rest_api_init', function () {
    register_rest_route('v1', '/player/(?P<slug>[a-zA-Z0-9-]+)', [
        'methods'             => 'GET',
        'callback'            => 'api_player_final',
        'permission_callback' => '__return_true',
    ]);
});

function api_player_final($data) {
    $slug = $data['slug'];
    $lang = isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : 'es';
    $valid_langs = ['es', 'en', 'val'];
    if (!in_array($lang, $valid_langs)) $lang = 'es';

    // ── 1. CACHÉ ─────────────────────────────────────────────────────────
    $cache_key = 'vcf_player_v2_' . $slug . '_' . $lang;
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    // ── 5. CORTAFUEGOS ANTI-RECURSIÓN ────────────────────────────────────
    // Si la petición la ha originado este mismo snippet, no volvemos a
    // salir. Barato, y cierra la puerta a cualquier bucle futuro.
    if (isset($_SERVER['HTTP_X_VCF_INTERNAL'])) {
        return ['error' => 'internal loop blocked'];
    }

    $lang_prefix = ($lang === 'es') ? '' : '/' . $lang;
    $base = 'https://panel.villarrealcf.es';
    $player_url = $base . $lang_prefix . '/player/' . $slug . '/';

    $html = api_curl_get($player_url, $lang);

    if (!$html) {
        // Caché negativa corta: que un fallo no lo repitan 500 visitantes
        // seguidos 500 veces.
        $fail = ['error' => 'fetch failed', 'url' => $player_url];
        set_transient($cache_key, $fail, 60);
        return $fail;
    }

    $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m);
    $body = $m[1] ?? '';
    $player = parse_player($html, $body, $slug);

    // ── 3. PLANTILLA CACHEADA APARTE ─────────────────────────────────────
    $squad = vcf_squad_cached($lang);

    // ── 4. EQUIPO DESDE LA BASE DE DATOS, SIN REST NI HTTP ───────────────
    // SportsPress guarda el equipo en post meta: sp_current_team (y sp_team).
    // Leerlo directamente es exacto, instantaneo y no gasta ni un proceso PHP.
    $team_name = null;
    $team_slug = null;

    $player_post = get_page_by_path($slug, OBJECT, 'sp_player');

    if ($player_post) {
        $team_id = (int) get_post_meta($player_post->ID, 'sp_current_team', true);

        if (!$team_id) {
            $teams = get_post_meta($player_post->ID, 'sp_team', false);
            $team_id = !empty($teams) ? (int) $teams[0] : 0;
        }

        if ($team_id) {
            $team_post = get_post($team_id);
            if ($team_post) {
                $team_name = $team_post->post_title;
                $team_slug = $team_post->post_name;
            }
        }
    }

    $out = [
        'playerData'       => $player,
        'firstTeamPlayers' => $squad,
        'team'             => [
            'name' => $team_name,
            'slug' => $team_slug,
        ],
        '_debug' => ['lang' => $lang, 'player_url' => $player_url],
    ];

    set_transient($cache_key, $out, 10 * MINUTE_IN_SECONDS);

    return $out;
}

/**
 * La plantilla es idéntica para todos los jugadores: una sola copia
 * en caché, compartida por todas las fichas.
 */
function vcf_squad_cached(string $lang): array {
    $key  = 'vcf_squad_v2_' . $lang;
    $data = get_transient($key);
    if ($data !== false) return $data;

    $lang_prefix = ($lang === 'es') ? '' : '/' . $lang;
    $squad_url = 'https://panel.villarrealcf.es' . $lang_prefix . '/primer-equipo/';

    $sq_html = api_curl_get($squad_url, $lang);
    $data = $sq_html ? parse_squad($sq_html) : [];

    set_transient($key, $data, 30 * MINUTE_IN_SECONDS);
    return $data;
}

// ─── cURL helper con cookies de idioma WPML ───────────────────────────────────

function api_curl_get(string $url, string $lang): string|false {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,

        // ── 2. TIMEOUTS CORTOS ───────────────────────────────────────────
        CURLOPT_TIMEOUT        => 4,   // antes 15
        CURLOPT_CONNECTTIMEOUT => 2,

        CURLOPT_SSL_VERIFYPEER => false,  // se queda como estaba: hoy no
                                          // tocamos nada que no haga falta
        CURLOPT_HTTPHEADER     => [
            'Accept-Language: ' . $lang,
            'X-WP-Lang: ' . $lang,
            'X-VCF-Internal: 1',   // marca para el cortafuegos
        ],
        CURLOPT_COOKIE         => implode('; ', [
            '_icl_current_language=' . $lang,
            'wpml_browser_redirect_test=1',
            'wpml_referer_url=' . urlencode($url),
        ]),
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; WP-Internal/1.0)',
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body ?: false;
}

/**
 * PARSE PLAYER
 */
function parse_player($full, $body, $slug) {

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body);
    $xp = new DOMXPath($dom);

    function t($x, $q) { $n = $x->query($q); return $n->length ? trim($n->item(0)->textContent) : null; }
    function a($x, $q, $attr) { $n = $x->query($q); return $n->length ? $n->item(0)->getAttribute($attr) : null; }

    // ===== BASIC =====
    $namePart1 = t($xp, "//div[@class='nombre-jugador']") ?? '';
    $namePart2 = t($xp, "//div[@class='nombre-jugador-big']") ?? '';
    $name = trim($namePart1 . ' ' . $namePart2);

    // Si la página no tiene nombre (jugador nuevo sin ficha completa),
    // construimos uno legible a partir del slug: "c-romero" → "C ROMERO"
    if ($name === '') {
        $name = implode(' ', array_map('strtoupper', explode('-', $slug)));
    }

    $position = trim(t($xp, "//div[@class='posicion-jugador']") ?? '');
    $country  = t($xp, "//div[@class='pais-jugador']");
    $number   = (int) (t($xp, "//div[@class='dorsal-jugador']") ?? 0);
    // jerseyNumber = 0 cuando no hay dorsal — el front lo gestiona correctamente

    preg_match('/\d+/', t($xp, "//div[@class='edad-jugador']") ?? '', $age);
    $age = isset($age[0]) ? (int) $age[0] : null;

    // ===== STATS =====
    $matches = (int) t($xp, "//div[@class='partidos-jugador']/p[2]");
    $minutes = (int) t($xp, "//div[@class='minutos-jugador']/p[2]");
    $goals   = (int) t($xp, "//div[@class='goles-jugador']/p[2]");
    $assists = (int) t($xp, "//div[@class='asistencias-jugador']/p[2]");

    // ===== HEIGHT / WEIGHT =====
    $hw = t($xp, "//div[@class='altura-peso-jugador']//p");
    preg_match('/([\d\.]+)\s*m/', $hw, $h);
    preg_match('/(\d+)\s*kg/', $hw, $w);

    // ===== PREVIOUS TEAMS + PALMARÉS =====
    // Hay que extraer esto ANTES de tocar el texto de la biografía: $xp y
    // $dom comparten el mismo árbol, así que si borráramos estos <ul> del
    // DOM primero, ya no los encontraríamos aquí.
    $uls = $xp->query("(//div[@class='biografia-jugador'])[1]//ul");

    $extractUlItems = function ($ul) use ($xp) {
        $items = [];
        foreach ($xp->query(".//li", $ul) as $li) {
            $t = trim($li->textContent);
            if ($t !== '') $items[] = $t;
        }
        return $items;
    };

    $teams = $uls->length >= 1
        ? array_slice($extractUlItems($uls->item(0)), 0, 1)
        : [];

    $achievements = $uls->length >= 2
        ? $extractUlItems($uls->item(1))
        : [];

    // ===== BIO =====
    $bioNode = $xp->query("(//div[@class='biografia-jugador'])[1]//div[@class='text']");
    $bioParagraphs = [];

    if ($bioNode->length) {
        $textDiv = $bioNode->item(0);

        $ulsInText = $xp->query(".//ul", $textDiv);
        foreach (iterator_to_array($ulsInText) as $ulNode) {
            $ulNode->parentNode->removeChild($ulNode);
        }

        $pNodes = $xp->query(".//p", $textDiv);

        if ($pNodes->length) {
            foreach ($pNodes as $p) {
                $t = trim(preg_replace('/\s+/', ' ', $p->textContent));
                if ($t !== '') $bioParagraphs[] = $t;
            }
        } else {
            $bioHTML = $dom->saveHTML($textDiv);
            $brParts = preg_split('/<br\s*\/?>/i', $bioHTML);
            foreach ($brParts as $part) {
                $flat = trim(preg_replace('/\s+/', ' ', strip_tags($part)));
                if ($flat === '') continue;
                $withBreaks = preg_replace('/\.(?=[A-ZÀ-ÖØ-Þ])/u', ".\n", $flat);
                foreach (explode("\n", $withBreaks) as $piece) {
                    $piece = trim($piece);
                    if ($piece !== '') $bioParagraphs[] = $piece;
                }
            }
        }
    }

    foreach ($bioParagraphs as $i => $p) {
        if (preg_match('/^(EQUIPOS ANTERIORES|EQUIPS ANTERIORS|PREVIOUS TEAMS|PALMAR[ÉE]S|TROPHIES|HONOURS)\s*:?/iu', $p)) {
            $bioParagraphs = array_slice($bioParagraphs, 0, $i);
            break;
        }
    }

    // ===== IMAGE =====
    $image = null;
    $imgNode = $xp->query("//div[contains(@class,'container-imagen-jugador')]//img[contains(@class,'player-inside-img')]");

    if ($imgNode->length) {
        $img = $imgNode->item(0);
        $image = $img->getAttribute("data-src-webp")
            ?: $img->getAttribute("data-src")
            ?: $img->getAttribute("src");

        if ($image && strpos($image, 'data:image') !== false) {
            $image = null;
        }
        if ($image && strpos($image, 'http') === false) {
            $image = home_url($image);
        }
    }

    // ===== COMPETITION =====
    $byCompetition = parse_competition($xp);

    return [
        "basicInfo" => [
            "slug"         => $slug,
            "name"         => $name,
            "position"     => $position,
            "jerseyNumber" => $number,
            "age"          => $age,
            "country"      => $country,
        ],
        "physicalAttributes" => [
            "height" => isset($h[1]) ? $h[1] . " m" : null,
            "weight" => isset($w[1]) ? $w[1] . " kg" : null,
        ],
        "season2025_26" => [
            "overall" => [
                "matches"     => $matches,
                "minutes"     => $minutes,
                "goals"       => $goals,
                "assists"     => $assists,
                "yellowCards" => 0,
                "redCards"    => 0,
            ],
            "byCompetition" => $byCompetition,
        ],
        "biography" => [
            "paragraphs"    => $bioParagraphs,
            "previousTeams" => $teams,
            "achievements"  => $achievements,
        ],
        "images" => [
            "player" => $image,
        ],
    ];
}

/**
 * COMPETITION PARSER
 */
function parse_competition($xp) {

    $list = [];

    foreach ($xp->query("//table//tr") as $r) {

        $td = $xp->query("./td", $r);
        if ($td->length < 5) continue;

        $competition = trim($td->item(0)->textContent);

        $img = $xp->query(".//img", $td->item(0));
        $logo = null;

        if ($img->length) {
            $el = $img->item(0);
            $logo = $el->getAttribute("data-src-webp")
                ?: $el->getAttribute("data-src")
                ?: $el->getAttribute("src");

            if (strpos($logo, 'data:image') !== false) {
                $logo = null;
            }
            if ($logo && strpos($logo, 'http') === false) {
                $logo = home_url($logo);
            }
        }

        $list[] = [
            "competition" => $competition ?: "Unknown",
            "matches"     => (int) $td->item(1)->textContent,
            "minutes"     => (int) $td->item(2)->textContent,
            "goals"       => (int) $td->item(3)->textContent,
            "assists"     => (int) $td->item(4)->textContent,
            "yellowCards" => $td->length > 5 ? (int) $td->item(5)->textContent : 0,
            "redCards"    => $td->length > 6 ? (int) $td->item(6)->textContent : 0,
            "logo"        => $logo,
        ];
    }

    return $list;
}

/**
 * SQUAD
 */
function parse_squad($html) {

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xp = new DOMXPath($dom);

    $out = [];

    foreach ($xp->query("//a[contains(@href,'/player/')]") as $c) {

        preg_match('/player\/([^\/]+)/', $c->getAttribute("href"), $m);
        $slug = $m[1] ?? null;
        if (!$slug) continue;

        $img = $xp->query(".//img", $c);
        $image = $img->length
            ? ($img->item(0)->getAttribute("data-src") ?: $img->item(0)->getAttribute("src"))
            : null;

        $out[] = [
            "id"       => crc32($slug),
            "name"     => $slug,
            "slug"     => $slug,
            "position" => null,
            "number"   => null,
            "image"    => $image,
        ];
    }

    return array_values(array_unique($out, SORT_REGULAR));
}
