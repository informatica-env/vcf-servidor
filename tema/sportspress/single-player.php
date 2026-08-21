<?php
/**
 * Player Details
 *
 * @author 		ThemeBoy
 * @package 	SportsPress/Templates
 * @version   2.7.9
 */
get_header();

if ( ! defined( 'ABSPATH' ) ) exit;
if ( get_option( 'sportspress_player_show_details', 'yes' ) === 'no' ) return;

if ( ! isset( $id ) )
	$id = get_the_ID();

$defaults = array(
	'show_number' => get_option( 'sportspress_player_show_number', 'no' ) == 'yes' ? true : false,
	'show_name' => get_option( 'sportspress_player_show_name', 'no' ) == 'yes' ? true : false,
	'show_nationality' => get_option( 'sportspress_player_show_nationality', 'yes' ) == 'yes' ? true : false,
	'show_positions' => get_option( 'sportspress_player_show_positions', 'yes' ) == 'yes' ? true : false,
	'show_current_teams' => get_option( 'sportspress_player_show_current_teams', 'yes' ) == 'yes' ? true : false,
	'show_past_teams' => get_option( 'sportspress_player_show_past_teams', 'yes' ) == 'yes' ? true : false,
	'show_leagues' => get_option( 'sportspress_player_show_leagues', 'no' ) == 'yes' ? true : false,
	'show_seasons' => get_option( 'sportspress_player_show_seasons', 'no' ) == 'yes' ? true : false,
	'show_nationality_flags' => get_option( 'sportspress_player_show_flags', 'yes' ) == 'yes' ? true : false,
	'link_teams' => get_option( 'sportspress_link_teams', 'no' ) == 'yes' ? true : false,
);

function isMobileDevice() { 
    return preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $_SERVER["HTTP_USER_AGENT"]); 
}

extract( $defaults, EXTR_SKIP );

$countries = SP()->countries->countries;
$player = new SP_Player( $id );

$metrics_before = $player->metrics( true );
$metrics_after = $player->metrics( false );

$common = array();

if ( $show_number ):
	$common[ '#' ] = $player->number;
endif;

if ( $show_name ):
	$common[ __( 'Name', 'sportspress' ) ] = $player->post->post_title;
endif;

if ( $show_nationality ):
	$nationalities = $player->nationalities();
	if ( $nationalities && is_array( $nationalities ) ):
		$values = array();
		foreach ( $nationalities as $nationality ):
			$country_name = sp_array_value( $countries, $nationality, null );
			$values[] = $country_name ? ( $show_nationality_flags ? '<img src="' . plugin_dir_url( SP_PLUGIN_FILE ) . 'assets/images/flags/' . strtolower( $nationality ) . '.png" alt="' . $nationality . '"> ' : '' ) . $country_name : '&mdash;';
		endforeach;
		$common[ __( 'Nationality', 'sportspress' ) ] = implode( '<br>', $values );
	endif;
endif;

if ( $show_positions ):
	$positions = $player->positions();
	if ( $positions && is_array( $positions ) ):
		$position_names = array();
		foreach ( $positions as $position ):
			$position_names[] = $position->name;
		endforeach;
		$common[ __( 'Position', 'sportspress' ) ] = implode( ', ', $position_names );
	endif;
endif;

$data = array_merge( $metrics_before, $common, $metrics_after );

if ( $show_current_teams ):
	$current_teams = array_filter( $player->current_teams() );
	if ( $current_teams ):
		$teams = array();
		foreach ( $current_teams as $team ):
			$team_name = sp_team_short_name( $team );
			$equipo = sp_team_name( $team );
			if ( $link_teams ) $team_name = '<a href="' . get_post_permalink( $team ) . '">' . $team_name . '</a>';
			$teams[] = $team_name;
		endforeach;
		$data[ __( 'Current Team', 'sportspress' ) ] = implode( ', ', $teams );
	endif;
endif;

if ( $show_past_teams ):
	$past_teams = array_filter( $player->past_teams() );
	if ( $past_teams ):
		$teams = array();
		foreach ( $past_teams as $team ):
			$team_name = sp_team_short_name( $team );
			if ( $link_teams ) $team_name = '<a href="' . get_post_permalink( $team ) . '">' . $team_name . '</a>';
			$teams[] = $team_name;
		endforeach;
		$data[ __( 'Past Teams', 'sportspress' ) ] = implode( ', ', $teams );
	endif;
endif;

if ( $show_leagues ):
	$leagues = $player->leagues();
	if ( $leagues && ! is_wp_error( $leagues ) ):
		$terms = array();
		foreach ( $leagues as $league ) {
			$terms[] = $league->name;
		}
		$data[ __( 'Leagues', 'sportspress' ) ] = implode( ', ', $terms );
	endif;
endif;

if ( $show_seasons ):
	$seasons = $player->seasons();
	if ( $seasons && ! is_wp_error( $seasons ) ):
		$terms = array();
		foreach ( $seasons as $season ) {
			$terms[] = $season->name;
		}
		$data[ __( 'Seasons', 'sportspress' ) ] = implode( ', ', $terms );
	endif;
endif;

$data = apply_filters( 'sportspress_player_details', $data, $id );

if ( empty( $data ) )
	return;

// ─── Besoccer API ────────────────────────────────────────────────────────────

if ( ! isset( $equipo ) ) $equipo = '';

if ( $equipo == 'Villarreal CF' ) {
	$result = file_get_contents("https://apiclient.besoccerapps.com/scripts/api/api.php?key=__BESOCCER_API_KEY__&year=2027&format=json&req=team&id=2716", false);
} elseif ( $equipo == 'Villarreal CF Femenino' ) {
	$result = file_get_contents("https://apiclient.besoccerapps.com/scripts/api/api.php?key=__BESOCCER_API_KEY__&year=2027&format=json&req=team&id=12398", false);
} elseif ( $equipo == 'Villarreal B' ) {
	$result = file_get_contents("https://apiclient.besoccerapps.com/scripts/api/api.php?key=__BESOCCER_API_KEY__&year=2027&format=json&req=team&id=2717", false);
} else {
	$id_equipo = $data['ID Equipo'] ?? null;
	$result = $id_equipo
		? file_get_contents("https://apiclient.besoccerapps.com/scripts/api/api.php?key=__BESOCCER_API_KEY__&year=2027&format=json&req=team&id=$id_equipo", false)
		: false;
}

$array_jugadores = [];
if ( $result ) {
	$request = json_decode( $result );
	$array_jugadores = $request->team->squad ?? [];
}

// Inicializar variables con valores por defecto — evita errores si el jugador
// no tiene dorsal o no aparece en el squad de Besoccer
$partidos_jugados = 0;
$minutos_jugados  = 0;
$asistencias      = 0;
$goles            = 0;
$amarillas        = 0;
$rojas            = 0;
$pais             = '';
$flag             = '';
$anyo_nacimiento  = '';
$altura           = '';
$peso             = '';
$request_player   = null; // ← clave: inicializar aquí para evitar el error crítico
$nombre           = '';
$apellidos        = '';

$nombre_big  = $player->post->post_title;
$position_str = strpos( $nombre_big, ' ' );

if ( $position_str !== false ) {
	$nombre_big_1 = substr( $nombre_big, 0, $position_str );
	$nombre_big_2 = substr( $nombre_big, $position_str + 1 );
} else {
	$nombre_big_1 = $nombre_big;
	$nombre_big_2 = '';
}

// Solo buscar por dorsal si el jugador tiene uno asignado
$dorsal = $player->number;
if ( $dorsal ) {
	for ( $i = 0; $i < count( $array_jugadores ); $i++ ) {
		if ( $array_jugadores[$i]->squadNumber == $dorsal ) {
			$id_jugador = $array_jugadores[$i]->id;
			$nombre     = $array_jugadores[$i]->name;
			$apellidos  = $array_jugadores[$i]->last_name ?? '';

			$result_player = file_get_contents(
				"https://apiclient.besoccerapps.com/scripts/api/api.php?key=__BESOCCER_API_KEY__&tz=Europe/Madrid&format=json&req=player&id=$id_jugador",
				false
			);

			if ( $result_player ) {
				$request_player  = json_decode( $result_player );
				$pais            = $request_player->country ?? '';
				$flag            = $request_player->country_flag ?? '';
				$anyo_nacimiento = $request_player->birthdate ?? '';
				$altura          = $request_player->height ?? '';
				$peso            = $request_player->weight ?? '';

				// Sumar estadísticas por competición
				foreach ( $request_player->statistics_resume ?? [] as $stat ) {
					$competicion = $stat->category_name;
					$temporada   = $stat->year;

					if ( $equipo == 'Villarreal CF' ) {
						if ( in_array( $competicion, ['Primera División', 'Champions League', 'Copa del Rey'] ) && $temporada == '2027' ) {
							$partidos_jugados += intval( $stat->games_played );
							$minutos_jugados  += intval( $stat->minutes_played );
							$asistencias      += intval( $stat->assists );
							$goles            += intval( $stat->goals );
							$amarillas        += intval( $stat->yellow_cards );
							$rojas            += intval( $stat->red_cards );
						}
					} elseif ( $equipo == 'Villarreal CF Femenino' ) {
						if ( in_array( $competicion, ['Primera División Femenina', 'Copa de la Reina'] ) && $temporada == '2027' ) {
							$partidos_jugados += intval( $stat->games_played );
							$minutos_jugados  += intval( $stat->minutes_played );
							$asistencias      += intval( $stat->assists );
							$goles            += intval( $stat->goals );
							$amarillas        += intval( $stat->yellow_cards );
							$rojas            += intval( $stat->red_cards );
						}
					} elseif ( $equipo == 'Villarreal B' ) {
						if ( $competicion == 'Primera Federación' && $temporada == '2027' ) {
							$partidos_jugados += intval( $stat->games_played );
							$minutos_jugados  += intval( $stat->minutes_played );
							$asistencias      += intval( $stat->assists );
							$goles            += intval( $stat->goals );
							$amarillas        += intval( $stat->yellow_cards );
							$rojas            += intval( $stat->red_cards );
						}
					}
				}
			}
			break; // Jugador encontrado, salir del bucle
		}
	}
}

// ─── Traducciones ─────────────────────────────────────────────────────────────

$ua    = strtolower( $_SERVER["HTTP_USER_AGENT"] );
$isMob = is_numeric( strpos( $ua, "mobile" ) );

if ( get_locale() == 'es_ES' ) {
	$posicion            = $data['Posición'] ?? '';
	$posicion_text       = 'Posición';
	$edad_text           = ( $anyo_nacimiento != '' ) ? 'Edad:' : '';
	$anyos_text          = ( $anyo_nacimiento != '' ) ? 'años' : '';
	$amp                 = 'y';
	$altura_text         = 'Altura';
	$peso_text           = 'Peso';
	$biografia_text      = 'Biografía';
	$partidos_text       = 'Partidos';
	$goles_text          = 'Goles';
	$asistencias_text    = 'Asist.';
	$minutos_text        = 'Minutos';
	$rendimiento_text    = 'Rendimiento Temporada 2026/27';
	$primer_equipo_txt   = 'Primer Equipo';
	$femenino_txt        = 'Villarreal Femenino';
	$villarreal_b_txt    = 'Villarreal B';
	$comprar_camiseta_txt = 'Comprar camiseta';
	$tr_competicion      = 'COMPETICIÓN';
	$tr_partidos         = 'PARTIDOS';
	$tr_minutos          = 'MINUTOS';
	$tr_goles            = 'GOLES';
	$tr_asistencias      = 'ASISTENCIAS';
	$tr_amarillas        = 'AMARILLAS';
	$tr_rojas            = 'ROJAS';
} elseif ( get_locale() == 'en_GB' ) {
	$posicion            = $data['Position'] ?? '';
	$posicion_text       = 'Position';
	$anyos_text          = '';
	$edad_text           = ( $anyo_nacimiento != '' ) ? 'Age:' : '';
	$amp                 = 'and';
	$altura_text         = 'Height';
	$peso_text           = 'Weight';
	$biografia_text      = 'Biography';
	$partidos_text       = 'Games';
	$goles_text          = 'Goals';
	$asistencias_text    = 'Assists';
	$minutos_text        = 'Minutes';
	$rendimiento_text    = 'Season 2026/27 Performance';
	$primer_equipo_txt   = 'First Team';
	$femenino_txt        = 'Villarreal Women';
	$villarreal_b_txt    = 'Villarreal B';
	$comprar_camiseta_txt = 'Buy shirt';
	$tr_competicion      = 'COMPETITION';
	$tr_partidos         = 'GAMES';
	$tr_minutos          = 'MINUTES';
	$tr_goles            = 'GOALS';
	$tr_asistencias      = 'ASSISTS';
	$tr_amarillas        = 'Y.CARDS';
	$tr_rojas            = 'R.CARDS';
} else {
	$posicion            = $data['Position'] ?? '';
	$posicion_text       = 'Posició';
	$edad_text           = ( $anyo_nacimiento != '' ) ? 'Edat:' : '';
	$anyos_text          = ( $anyo_nacimiento != '' ) ? 'anys' : '';
	$amp                 = 'i';
	$altura_text         = 'Altura';
	$peso_text           = 'Pes';
	$biografia_text      = 'Biografia';
	$partidos_text       = 'Partits';
	$goles_text          = 'Gols';
	$asistencias_text    = 'Asist.';
	$minutos_text        = 'Minuts';
	$rendimiento_text    = 'Rendiment Temporada 2026/27';
	$primer_equipo_txt   = 'Primer Equip';
	$femenino_txt        = 'Villarreal Femení';
	$villarreal_b_txt    = 'Villarreal B';
	$comprar_camiseta_txt = 'Comprar camiseta';
	$tr_competicion      = 'COMPETICIÓ';
	$tr_partidos         = 'PARTITS';
	$tr_minutos          = 'MINUTS';
	$tr_goles            = 'GOLS';
	$tr_asistencias      = 'ASISTÈNCIES';
	$tr_amarillas        = 'GROGUES';
	$tr_rojas            = 'ROGES';
}

// ─── Imagen y datos del post ──────────────────────────────────────────────────

$array_meta = get_post_meta( $id, 'imagen-jugador-interior' );
$image_url  = wp_get_attachment_image_src( $array_meta[0] ?? 0, 'full' )[0] ?? '';
$biografia  = $player->post->post_content;

// Calcular edad solo si hay fecha de nacimiento válida
$edad = 0;
if ( $anyo_nacimiento ) {
	try {
		$fecha_nacimiento_obj = new DateTime( $anyo_nacimiento );
		$fecha_actual         = new DateTime();
		$diferencia           = $fecha_nacimiento_obj->diff( $fecha_actual );
		$edad                 = $diferencia->y;
	} catch ( Exception $e ) {
		$edad = 0;
	}
}

$primer_apellido = $apellidos ? explode( " ", $apellidos ) : [];

$escudos_competiciones = [
	'Primera División'          => '/wp-content/uploads/2024/08/laliga-logo.png',
	'Europa League'             => '/wp-content/uploads/2023/09/2227253.png',
	'Champions League'          => '/wp-content/uploads/2025/08/Logo_UEFA_Champions_League_blanco.png',
	'Copa del Rey'              => '/wp-content/uploads/2023/01/Logo_copa_del_Rey_blanco.png',
	'Primera Federación Femenina' => '/wp-content/uploads/2024/09/primera_rfef_fem_2.png',
	'Copa de la Reina'          => '/wp-content/uploads/2022/11/Logo_Copa_de_la_Reina_2021.png',
	'Primera Federación'        => '/wp-content/uploads/2024/10/primera-ref.png',
];

// ─── HTML output ──────────────────────────────────────────────────────────────

$output = "<div class='container-jugador'>
                <div class='container-estadisticas'>
                    <div class='primera-fila'>
                        <div class='parte-izquierda'>
                            <div class='nombre-jugador'>$nombre_big_1</div>
                            <div class='nombre-jugador-big' style='font-size: 30px'>$nombre_big_2</div>
                            <div class='parte-izquierda-bottom'>
                                <div class='posicion-jugador'>$posicion&nbsp;</div>";

if ( $edad > 0 ) {
	$output .= "<span>|</span>
	<div class='edad-jugador'>$edad_text $edad $anyos_text</div>
	<span>|</span>
	<div class='pais-jugador'>$pais</div>";
} else {
	$output .= "<div class='pais-jugador'>$pais</div>";
}

$output .= "            </div>
                        </div>
                        <div class='parte-derecha'>
                            <div class='dorsal-jugador'>$dorsal</div>
                        </div>
                    </div>
                    <div class='segunda-fila' style='display: none'>
                        <div class='posicion-jugador'>
                            <p class='titulo'>$posicion_text</p>
                            <p class='text'>$posicion</p>
                        </div>
                        <div class='altura-peso-jugador'>
                            <p class='titulo'>$altura_text $amp $peso_text</p>
                            <p class='text'>$altura m $amp $peso kg</p>
                        </div>
                    </div>";

if ( $equipo != 'Villarreal CF Femenino' ) {
	$output .= "<div class='tercera-fila'>
		<div class='partidos-jugador'><p class='titulo'>$partidos_text</p><p>$partidos_jugados</p></div>
		<div class='minutos-jugador'><p class='titulo'>$minutos_text</p><p>$minutos_jugados</p></div>
		<div class='goles-jugador'><p class='titulo'>$goles_text</p><p>$goles</p></div>
		<div class='asistencias-jugador'><p class='titulo'>$asistencias_text</p><p>$asistencias</p></div>
	</div>";
}

$output .= "<div class='cuarta-fila'>
		<div class='biografia-jugador'>
			<p class='titulo'>$biografia_text</p>
			<div class='text'>$biografia</div>
		</div>
	</div>
	<div class='quinta-fila'>
		<div class='comprar-camiseta-btn'>
			<a href='https://shop.villarrealcf.es/es/inicio/1703-10928-camiseta-jugador-1-25-26.html' target='_blank'>$comprar_camiseta_txt</a>
		</div>
	</div>
</div>
<div class='container-imagen-jugador'>
	<img src='$image_url' class='player-inside-img'>
</div>
</div>";

// Versión móvil
$output .= "<div class='container-jugador movil'>
	<div class='container-imagen-jugador'>
		<img src='$image_url' class='player-inside-img'>
	</div>
	<div class='container-estadisticas'>
		<div class='primera-fila'>
			<div class='parte-izquierda'>
				<div class='nombre-jugador'>$nombre_big_1</div>
				<div class='nombre-jugador-big'>$nombre_big_2</div>
				<div class='parte-izquierda-bottom'>
					<div class='posicion-jugador'>$posicion&nbsp;</div>";

if ( $edad > 0 ) {
	$output .= "<span>|</span>
	<div class='edad-jugador'>$edad_text $edad $anyos_text</div>
	<span>|</span>
	<div class='pais-jugador'>$pais</div>";
} else {
	$output .= "<div class='pais-jugador'>$pais</div>";
}

$output .= "		</div>
			</div>
			<div class='parte-derecha'>
				<div class='dorsal-jugador'>$dorsal</div>
			</div>
		</div>
		<div class='segunda-fila' style='display: none'>
			<div class='posicion-jugador'>
				<p class='titulo'>$posicion_text</p>
				<p class='text'>$posicion</p>
			</div>
			<div class='altura-peso-jugador'>
				<p class='titulo'>$altura_text y $peso_text</p>
				<p class='text'>$altura m y $peso kg</p>
			</div>
		</div>";

if ( $equipo != 'Villarreal CF Femenino' ) {
	$output .= "<div class='tercera-fila'>
		<div class='partidos-jugador'><p class='titulo'>$partidos_text</p><p>$partidos_jugados</p></div>
		<div class='minutos-jugador'><p class='titulo'>$minutos_text</p><p>$minutos_jugados</p></div>
		<div class='goles-jugador'><p class='titulo'>$goles_text</p><p>$goles</p></div>
		<div class='asistencias-jugador'><p class='titulo'>$asistencias_text</p><p>$asistencias</p></div>
	</div>";
}

$output .= "<div class='cuarta-fila' style='margin-bottom: 30px'>
		<div class='biografia-jugador'>
			<p class='titulo'>$biografia_text</p>
			<div class='text'>$biografia</div>
		</div>
	</div>
	<div class='quinta-fila'>
		<div class='comprar-camiseta-btn movil'>
			<a href='https://shop.villarrealcf.es/es/inicio/1703-10928-camiseta-jugador-1-25-26.html' target='_blank'>$comprar_camiseta_txt</a>
		</div>
	</div>
</div>
</div>";

// ─── Tabla de rendimiento — solo si se encontraron stats en Besoccer ──────────

if ( $equipo != 'Villarreal CF Femenino' && $request_player !== null ) {
	$output .= "<div class='estadisticas-completas-final' style='text-align: center; background-color: #000d27;'>
		<h2 class='noticias-header' style='padding-top: 30px'>$rendimiento_text</h2>
		<table class='tabla-stats-completas' style='margin-top: 35px; width: 1300px; margin: 0 auto'>
		<tr>
			<th id='cabecera-competicion'>$tr_competicion</th>
			<th id='cabecera-partidos'>$tr_partidos</th>
			<th id='cabecera-minutos'>$tr_minutos</th>
			<th id='cabecera-goles'>$tr_goles</th>
			<th id='cabecera-asistencias'>$tr_asistencias</th>
			<th id='cabecera-amarillas'>$tr_amarillas</th>
			<th id='cabecera-rojas'>$tr_rojas</th>
		</tr>";

	foreach ( $request_player->statistics_resume ?? [] as $stat ) {
		$temporada        = $stat->year;
		$competicion_name = $stat->category_name;
		$show_row         = false;

		if ( $equipo == 'Villarreal CF' && in_array( $competicion_name, ['Primera División', 'Champions League', 'Copa del Rey'] ) && $temporada == '2027' ) {
			$show_row = true;
		} elseif ( $equipo == 'Villarreal B' && $competicion_name == 'Primera Federación' && $temporada == '2027' ) {
			$show_row = true;
		}

		if ( $show_row ) {
			$logo = $escudos_competiciones[$competicion_name] ?? '';
			$output .= "<tr>
				<td><p class='stats-valor competicion-logo'>" . ( $logo ? "<img src='$logo' width=50px title='$competicion_name'>" : $competicion_name ) . "</p></td>
				<td><p class='stats-valor'>" . intval($stat->games_played)   . "</p></td>
				<td><p class='stats-valor'>" . intval($stat->minutes_played) . "</p></td>
				<td><p class='stats-valor'>" . intval($stat->goals)          . "</p></td>
				<td><p class='stats-valor'>" . intval($stat->assists)        . "</p></td>
				<td><p class='stats-valor'>" . intval($stat->yellow_cards)   . "</p></td>
				<td><p class='stats-valor'>" . intval($stat->red_cards)      . "</p></td>
			</tr>";
		}
	}

	$output .= '</table></div>';
}

// ─── Squad carousel ───────────────────────────────────────────────────────────

if ( $equipo == 'Villarreal CF' ) {
	$output .= "<div class='slide-jugadores' style='background-color: #000d27; padding-bottom: 30px; padding-top: 30px'>
		<h2 class='noticias-header'>$primer_equipo_txt</h2>";
	$output .= do_shortcode('[ess_grid alias="jugadores"][/ess_grid]') . '</div>';
} elseif ( $equipo == 'Villarreal CF Femenino' ) {
	$output .= "<div class='slide-jugadores' style='background-color: #000d27; padding-bottom: 30px; padding-top: 30px'>
		<h2 class='noticias-header'>$femenino_txt</h2>";
	$output .= do_shortcode('[ess_grid alias="jugadores-villarreal-femenino"][/ess_grid]') . '</div>';
} elseif ( $equipo == 'Villarreal B' ) {
	$output .= "<div class='slide-jugadores' style='background-color: #000d27; padding-bottom: 30px; padding-top: 30px'>
		<h2 class='noticias-header'>$villarreal_b_txt</h2>";
	$output .= do_shortcode('[ess_grid alias="jugadores-villarreal-b"][/ess_grid]') . '</div>';
}

$output .= '</div>';

echo $output;

get_footer();

function containsAnyWord($text, $words) {
	$wordsArray = explode(" ", $words);
	foreach ($wordsArray as $word) {
		if (stripos($text, $word) !== false) {
			return true;
		}
	}
	return false;
}