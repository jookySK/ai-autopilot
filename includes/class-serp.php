<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SERP analýza (Serper.dev) + generovanie návrhov tém na výber.
 */
class O3DAI_SERP {

	const ENDPOINT = 'https://google.serper.dev/search';

	/** Je nastavený Serper API kľúč? */
	public static function enabled() {
		$s = o3dai_get_settings();
		return ! empty( $s['serper_key'] );
	}

	/**
	 * Stiahne z Googlu top výsledky pre frázu (organické + "People also ask" + súvisiace).
	 * Vráti pole ['titles'=>[], 'questions'=>[], 'related'=>[]] alebo WP_Error.
	 */
	public static function analyze( $query ) {
		if ( ! O3DAI_License::feature( 'serp' ) ) {
			return new WP_Error( 'o3dai_pro', o3dai_t( 'pro_locked' ) );
		}
		$s = o3dai_get_settings();
		if ( empty( $s['serper_key'] ) ) {
			return new WP_Error( 'o3dai', 'Chýba Serper.dev API kľúč.' );
		}
		$resp = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 30,
			'headers' => array(
				'X-API-KEY'    => $s['serper_key'],
				'content-type' => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'q'  => $query,
				'gl' => $s['brand_lang_code'] ?? 'sk',
				'hl' => $s['brand_lang_code'] ?? 'sk',
				'num' => 10,
			) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'o3dai', 'Serper: ' . ( $body['message'] ?? ( 'chyba ' . $code ) ) );
		}

		$titles = array();
		foreach ( (array) ( $body['organic'] ?? array() ) as $r ) {
			$t = trim( (string) ( $r['title'] ?? '' ) );
			$sn = trim( (string) ( $r['snippet'] ?? '' ) );
			if ( $t ) {
				$titles[] = $t . ( $sn ? ' — ' . $sn : '' );
			}
		}
		$questions = array();
		foreach ( (array) ( $body['peopleAlsoAsk'] ?? array() ) as $q ) {
			$qq = trim( (string) ( $q['question'] ?? '' ) );
			if ( $qq ) {
				$questions[] = $qq;
			}
		}
		$related = array();
		foreach ( (array) ( $body['relatedSearches'] ?? array() ) as $r ) {
			$rr = trim( (string) ( $r['query'] ?? '' ) );
			if ( $rr ) {
				$related[] = $rr;
			}
		}
		return array(
			'titles'    => array_slice( $titles, 0, 10 ),
			'questions' => array_slice( $questions, 0, 8 ),
			'related'   => array_slice( $related, 0, 10 ),
		);
	}

	/**
	 * Vygeneruje návrhy tém článkov (na výber). Spojí: frázy z GSC + SERP kontext + už napísané.
	 * Vráti pole reťazcov (návrhy) alebo WP_Error.
	 */
	public static function suggest_topics( $count = 8 ) {
		if ( ! O3DAI_License::feature( 'suggest' ) ) {
			return new WP_Error( 'o3dai_pro', o3dai_t( 'pro_locked' ) );
		}
		$s   = o3dai_get_settings();
		$ctx = O3DAI_Generator::site_context();

		// reálne frázy z GSC (auto + ručné)
		$gsc = class_exists( 'O3DAI_GSC' ) ? O3DAI_GSC::queries_for_prompt() : trim( (string) ( $s['gsc_queries'] ?? '' ) );

		// SERP kontext: vezmi 1-2 najsilnejšie frázy a pozri, čo na ne píše konkurencia
		$serp_ctx = '';
		if ( self::enabled() && $gsc ) {
			$lines = array_slice( array_filter( array_map( 'trim', explode( "\n", $gsc ) ) ), 0, 2 );
			foreach ( $lines as $line ) {
				// z riadku "fráza (poz. X, Y zobrazení)" vytiahni len frázu
				$q = trim( preg_replace( '/\s*\(.*$/', '', $line ) );
				if ( '' === $q ) {
					continue;
				}
				$a = self::analyze( $q );
				if ( ! is_wp_error( $a ) ) {
					$serp_ctx .= "\nFráza \"" . $q . "\" – čo už je na 1. strane Googlu:\n";
					$serp_ctx .= "Nadpisy konkurencie: " . implode( ' | ', array_slice( $a['titles'], 0, 6 ) ) . "\n";
					if ( $a['questions'] ) {
						$serp_ctx .= "Ľudia sa tiež pýtajú: " . implode( ' | ', $a['questions'] ) . "\n";
					}
					if ( $a['related'] ) {
						$serp_ctx .= "Súvisiace hľadania: " . implode( ', ', $a['related'] ) . "\n";
					}
				}
			}
		}

		$system = 'Si SEO stratég pre obsahový marketing. Navrhuješ témy článkov, ktoré majú reálnu šancu uspieť vo vyhľadávaní. '
			. 'Odpovedaj VÝHRADNE platným JSON poľom reťazcov (názvy článkov po slovensky) — prvý znak odpovedi musí byť [ a posledný ], bez markdownu, bez komentárov.'; // v1.9.7

		$user = 'Web: ' . $ctx['site'] . ' – ' . get_bloginfo( 'description' ) . "\n\n"
			. ( $gsc ? "REÁLNE VYHĽADÁVANÉ FRÁZY (Google Search Console – toto ľudia naozaj hľadajú, stavaj na nich):\n" . $gsc . "\n\n" : '' )
			. ( $serp_ctx ? "ANALÝZA KONKURENCIE (čo už je na 1. strane Googlu):\n" . $serp_ctx . "\n"
				. "Navrhni témy, ktoré pokryjú medzery – to, čo konkurencia nemá, alebo uhol, ktorý spracujú lepšie.\n\n" : '' )
			. "UŽ NAPÍSANÉ ČLÁNKY (neopakuj ich):\n- " . implode( "\n- ", array_slice( $ctx['titles'], 0, 60 ) ) . "\n\n"
			. 'Navrhni ' . intval( $count ) . ' konkrétnych, lákavých tém článkov. Každá nech cieli na reálny dopyt a nech sa dá prirodzene prepojiť s našimi produktmi. '
			. 'Vráť len JSON pole ' . intval( $count ) . ' reťazcov.';

		$raw = O3DAI_Providers::generate( $system, $user, 1500 );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$topics = O3DAI_Generator::parse_json( $raw );
		if ( ! is_array( $topics ) || ! $topics ) {
			O3DAI_Generator::log_parse_preview( $raw ); // v1.9.7 – vidno, čo model reálne napísal
			return new WP_Error( 'o3dai', 'Návrhy tém sa nepodarilo naparsovať.' );
		}
		$clean = array();
		foreach ( $topics as $t ) {
			$t = trim( (string) $t );
			if ( $t ) {
				$clean[] = $t;
			}
		}
		update_option( 'o3dai_topic_suggestions', $clean, false );
		o3dai_log( o3dai_t( 'log_serp_topics', array( 'n' => count( $clean ), 'extra' => ( $serp_ctx ? o3dai_t( 'log_serp_extra' ) : '' ) ) ) );
		return $clean;
	}

	/**
	 * Navrhne niekoľko TÉM SÉRIÍ (celých obsahových blokov) na výber.
	 * Vráti pole reťazcov (názvy sérií) alebo WP_Error.
	 */
	public static function suggest_series( $count = 5 ) {
		if ( ! O3DAI_License::feature( 'suggest' ) ) {
			return new WP_Error( 'o3dai_pro', o3dai_t( 'pro_locked' ) );
		}
		$s   = o3dai_get_settings();
		$ctx = O3DAI_Generator::site_context();
		$gsc = class_exists( 'O3DAI_GSC' ) ? O3DAI_GSC::queries_for_prompt() : trim( (string) ( $s['gsc_queries'] ?? '' ) );

		$system = 'Si obsahový stratég. Navrhuješ TÉMY SÉRIÍ – väčšie oblasti, ktoré sa dajú rozpísať do 5 nadväzujúcich článkov. '
			. 'Odpovedaj VÝHRADNE platným JSON poľom reťazcov (názvy tém sérií po slovensky) — prvý znak odpovedi musí byť [ a posledný ], bez markdownu a komentárov.'; // v1.9.7

		$user = 'Web: ' . $ctx['site'] . ' – ' . get_bloginfo( 'description' ) . "

"
			. ( $gsc ? "REÁLNE VYHĽADÁVANÉ FRÁZY (Google Search Console):
" . $gsc . "

" : '' )
			. "UŽ NAPÍSANÉ ČLÁNKY (séria má prinášať nové oblasti, neopakuj):
- " . implode( "
- ", array_slice( $ctx['titles'], 0, 50 ) ) . "

"
			. 'Navrhni ' . intval( $count ) . ' tém SÉRIÍ. Každá téma je širšia oblasť (nie jeden článok), ktorá sa dá zmysluplne rozpísať do 5 nadväzujúcich článkov a prirodzene prepojiť s našimi produktmi. '
			. 'Formuluj ich ako názov série (napr. „Jemná motorika u detí podľa veku"). Vráť len JSON pole ' . intval( $count ) . ' reťazcov.';

		$raw = O3DAI_Providers::generate( $system, $user, 1200 );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$topics = O3DAI_Generator::parse_json( $raw );
		if ( ! is_array( $topics ) || ! $topics ) {
			O3DAI_Generator::log_parse_preview( $raw ); // v1.9.7 – vidno, čo model reálne napísal
			return new WP_Error( 'o3dai', 'Návrhy sérií sa nepodarilo naparsovať.' );
		}
		$clean = array();
		foreach ( $topics as $t ) {
			$t = trim( (string) $t );
			if ( $t ) {
				$clean[] = $t;
			}
		}
		update_option( 'o3dai_series_suggestions', $clean, false );
		o3dai_log( o3dai_t( 'log_serp_series', array( 'n' => count( $clean ) ) ) );
		return $clean;
	}

}
