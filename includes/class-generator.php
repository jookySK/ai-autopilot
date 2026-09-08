<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class O3DAI_Generator {

	/** Kontext webu: existujúce články + produkty na interné odkazy */
	public static function site_context() {
		$titles = array();
		foreach ( get_posts( array( 'post_type' => 'post', 'numberposts' => 200, 'post_status' => 'publish', 'fields' => 'ids' ) ) as $pid ) {
			$titles[] = get_the_title( $pid );
		}

		$products = array();
		if ( post_type_exists( 'product' ) ) {
			foreach ( get_posts( array( 'post_type' => 'product', 'numberposts' => 30, 'post_status' => 'publish', 'orderby' => 'rand' ) ) as $p ) {
				$products[] = $p->post_title . ' | ' . get_permalink( $p );
			}
		}

		$recent_links = array();
		foreach ( get_posts( array( 'post_type' => 'post', 'numberposts' => 30, 'post_status' => 'publish' ) ) as $p ) {
			$recent_links[] = $p->post_title . ' | ' . get_permalink( $p );
		}

		return array(
			'site'     => get_bloginfo( 'name' ) . ' — ' . get_bloginfo( 'description' ),
			'titles'   => $titles,
			'products' => $products,
			'posts'    => $recent_links,
		);
	}

	/** Vygeneruje zásobník tém do fronty */
	public static function generate_topics( $count = 15 ) {
		if ( ! O3DAI_License::has_api_key() ) {
			o3dai_log( o3dai_t( 'no_api_key' ) );
			return new WP_Error( 'o3dai', o3dai_t( 'no_api_key' ) );
		}
		$s   = o3dai_get_settings();
		$ctx = self::site_context();

		$system = "Si SEO stratég pre slovenský web. Odpovedaj VÝHRADNE platným JSON poľom reťazcov, bez markdownu a komentárov.";
		$gsc = class_exists( 'O3DAI_GSC' ) ? O3DAI_GSC::queries_for_prompt() : trim( (string) ( $s['gsc_queries'] ?? '' ) );
		$user   = "Web: {$ctx['site']}\n"
			. "Mesiac: " . date_i18n( 'F Y' ) . " (zohľadni sezónu a sviatky na Slovensku)\n"
			. ( $gsc ? "REÁLNE VYHĽADÁVANÉ FRÁZY z Google Search Console (uprednostni ich, píš články presne na ne):\n" . $gsc . "\n" : '' )
			. "Už napísané články (neopakuj témy):\n- " . implode( "\n- ", $ctx['titles'] ) . "\n\n"
			. "Navrhni {$count} nových tém blogových článkov, ktoré môžu priviesť z Googlu nových návštevníkov a zákazníkov. "
			. "Mix: informačné (čo je / ako na to), nákupné (tipy na darčeky, výbery) a sezónne témy. "
			. "Vráť JSON pole reťazcov.";

		$raw = O3DAI_Providers::generate( $system, $user, 2000 );
		if ( is_wp_error( $raw ) ) {
			o3dai_log( 'CHYBA (témy): ' . $raw->get_error_message() );
			return $raw;
		}
		$topics = self::parse_json( $raw );
		if ( ! is_array( $topics ) ) {
			o3dai_log( 'CHYBA (témy): odpoveď AI sa nepodarilo naparsovať.' );
			return new WP_Error( 'o3dai', 'Témy sa nepodarilo naparsovať.' );
		}

		$queue    = get_option( 'o3dai_topics', array() );
		$existing = wp_list_pluck( $queue, 'title' );
		$added    = 0;
		foreach ( $topics as $t ) {
			$t = trim( (string) $t );
			if ( $t && ! in_array( $t, $existing, true ) ) {
				$queue[] = array( 'title' => $t, 'status' => 'pending', 'post_id' => 0 );
				$added ++;
			}
		}
		update_option( 'o3dai_topics', $queue, false );
		o3dai_log( "Pridaných {$added} tém do fronty." );
		return $added;
	}

	/** Vygeneruje obsahovú sériu prepojených tém (napr. 5 dielov o jednej oblasti) */
	public static function generate_series( $theme, $count = 5 ) {
		if ( ! O3DAI_License::feature( 'series' ) ) {
			update_option( 'o3dai_limit_hit', 'series', false );
			o3dai_log( o3dai_t( 'pro_locked' ) );
			return;
		}
		if ( ! O3DAI_License::has_api_key() ) {
			o3dai_log( o3dai_t( 'no_api_key' ) );
			return new WP_Error( 'o3dai', o3dai_t( 'no_api_key' ) );
		}
		$s   = o3dai_get_settings();
		$ctx = self::site_context();

		$system = "Si SEO stratég. Odpovedaj VÝHRADNE platným JSON poľom reťazcov (názvy článkov), bez markdownu.";
		$user   = "Web: {$ctx['site']}\n"
			. "Vytvor obsahovú sériu {$count} nadväzujúcich článkov na tému: \"{$theme}\".\n"
			. "Články majú tvoriť logický celok (od základov po detaily), nemajú sa obsahovo prekrývať a čitateľ nimi má prejsť postupne. "
			. "Každý názov nech je konkrétny a lákavý pre Google. Vráť JSON pole {$count} reťazcov v poradí od 1. dielu.";

		$raw = O3DAI_Providers::generate( $system, $user, 1500 );
		if ( is_wp_error( $raw ) ) {
			o3dai_log( 'CHYBA (séria): ' . $raw->get_error_message() );
			return $raw;
		}
		$topics = self::parse_json( $raw );
		if ( ! is_array( $topics ) || ! $topics ) {
			o3dai_log( 'CHYBA (séria): odpoveď AI sa nepodarilo naparsovať.' );
			return new WP_Error( 'o3dai', 'Sériu sa nepodarilo naparsovať.' );
		}

		$queue  = get_option( 'o3dai_topics', array() );
		$exist  = wp_list_pluck( $queue, 'title' );
		$label  = sanitize_text_field( $theme );
		$added  = 0;
		$part   = 1;
		foreach ( $topics as $t ) {
			$t = trim( (string) $t );
			if ( $t && ! in_array( $t, $exist, true ) ) {
				$queue[] = array(
					'title'       => $t,
					'status'      => 'pending',
					'post_id'     => 0,
					'series'      => $label,
					'series_part' => $part,
				);
				$added ++;
				$part ++;
			}
		}
		update_option( 'o3dai_topics', $queue, false );
		o3dai_log( 'Pridaná séria „' . $label . '" – ' . $added . ' dielov.' );
		return $added;
	}

	/** Stiahne aktuálne témy z Google News (RSS, SK) a pridá do fronty.
	 *  Voliteľne ich AI prepíše na témy prispôsobené webu; bez API kľúča sa použijú nadpisy priamo. */
	public static function generate_news_topics() {
		$s = o3dai_get_settings();
		$keywords = trim( (string) ( $s['news_keywords'] ?? '' ) );
		if ( '' === $keywords ) {
			o3dai_log( 'News: chýbajú kľúčové slová v nastavení.' );
			return new WP_Error( 'o3dai', o3dai_t( 'news_no_keywords' ) );
		}
		$count = max( 3, min( 15, intval( $s['news_count'] ?? 8 ) ) );

		// 1) RSS z Google News (slovenčina) — každé kľúčové slovo ako SAMOSTATNÝ dotaz
		//    (jedna veľká fráza s viacerými slovami Google News nenajde, overené)
		$parts = array_values( array_filter( array_map( 'trim', explode( ',', $keywords ) ) ) );
		$parts = array_slice( $parts, 0, 5 );
		o3dai_log( 'News: stahujem aktuálne témy z Google News („' . $keywords . '“)…' );
		$headlines   = array();
		$seen        = array();
		$requests_ok = 0;
		foreach ( $parts as $kw ) {
			$url  = 'https://news.google.com/rss/search?q=' . rawurlencode( $kw ) . '&hl=sk&gl=SK&ceid=sk:sk';
			$resp = wp_remote_get( $url, array( 'timeout' => 30, 'user-agent' => 'Mozilla/5.0 (compatible; O3DAI/1.4)' ) );
			if ( is_wp_error( $resp ) ) {
				o3dai_log( 'News CHYBA („' . $kw . '“): ' . $resp->get_error_message() );
				continue;
			}
			$code = wp_remote_retrieve_response_code( $resp );
			if ( 200 !== $code ) {
				o3dai_log( 'News CHYBA („' . $kw . '“): HTTP ' . $code );
				continue;
			}
			$requests_ok++;
			$xml = simplexml_load_string( wp_remote_retrieve_body( $resp ) );
			if ( $xml && isset( $xml->channel->item ) ) {
				foreach ( $xml->channel->item as $item ) {
					$title = trim( (string) $item->title );
					if ( '' !== $title ) {
						$key = strtolower( $title );
						if ( ! isset( $seen[ $key ] ) ) {
							$seen[ $key ] = true;
							$headlines[] = $title;
						}
					}
				}
			}
		}
		if ( 0 === $requests_ok ) {
			o3dai_log( 'News CHYBA: žiadny dotaz na Google News neprešiel (sieť / blokácia).' );
			return new WP_Error( 'o3dai', o3dai_t( 'news_empty' ) );
		}
		if ( empty( $headlines ) ) {
			o3dai_log( 'News: žiadne výsledky pre „' . $keywords . '“.' );
			return new WP_Error( 'o3dai', o3dai_t( 'news_empty' ) );
		}
		o3dai_log( 'News: nájdených ' . count( $headlines ) . ' aktuálnych nadpisov.' );

		// 3) Prepísanie na témy webu cez AI (ak je kľúč a zapnuté)
		$topics = array();
		if ( ! empty( $s['news_use_ai'] ) && O3DAI_License::has_api_key() ) {
			$ctx     = self::site_context();
			$context = trim( (string) ( $s['news_context'] ?? '' ) );
			$site    = ( '' !== $context ) ? $context : $ctx['site'];
			o3dai_log( 'News: kontext pre AI: „' . $site . '“' );
			o3dai_log( 'News: nadpisy pre AI: ' . implode( ' | ', array_slice( $headlines, 0, 5 ) ) );
			$system  = 'Si SEO stratég. Navrhni VŠEOBECNÉ témy blogových článkov, inšpirované aktuálnymi spravodajskými nadpismi a prispôsobené tematickému zameraniu webu. ZAKÁZANÉ v názvoch článkov: akékoľvek názvy konkrétnych firiem, značiek, produktov, služieb alebo technológií — aj keď sa vyskytli v nadpisoch (napr. pri výpadku služby píš „cloudová služba“, nie názov poskytovateľa). Názov webu v názvoch článkov nepoužívaj. Odpovedaj VÝHRADNE platným JSON poľom reťazcov (názvy článkov), bez markdownu.';
			$user    = 'Web: ' . $site . "\n"
				. "Navrhni {$count} tém článkov inšpirovaných týmito aktuálnymi spravodajskými nadpisami (slovenčina):\n- "
				. implode( "\n- ", array_slice( $headlines, 0, 20 ) )
				. "\n\nPOZOR: v názvoch článkov nepoužívaj žiadne konkrétne značky, firmy, produkty ani služby (ani z nadpisov, ani názov webu) — len všeobecné témy.\nVráť JSON pole {$count} reťazcov (názvy článkov).";
			$raw = O3DAI_Providers::generate( $system, $user, 1500 );
			if ( ! is_wp_error( $raw ) ) {
				$parsed = self::parse_json( $raw );
				if ( is_array( $parsed ) ) {
					// poistka: ak AI zapracuje do názvu názov webu/kontext, odstráň ho
					$site_strip = ( '' !== $site && ( function_exists( 'mb_strlen' ) ? mb_strlen( $site ) : strlen( $site ) ) >= 10 ) ? $site : '';
					foreach ( $parsed as $t ) {
						$t = trim( (string) $t );
						if ( ! $t ) { continue; }
						if ( '' !== $site_strip && false !== stripos( $t, $site_strip ) ) {
							$t = trim( preg_replace( '/\s+/', ' ', str_ireplace( $site_strip, '', $t ) ) );
							o3dai_log( 'News: z témy odstránený názov webu: „' . $t . '“' );
						}
						if ( $t ) { $topics[] = $t; }
					}
				}
			}
			if ( empty( $topics ) ) {
				o3dai_log( 'News: AI prepis zlyhal – použijem nadpisy priamo.' );
			} else {
				o3dai_log( 'News: AI prepísala ' . count( $topics ) . ' tém.' );
			}
		}

		// 4) Fallback: nadpisy priamo
		if ( empty( $topics ) ) {
			$topics = array_slice( $headlines, 0, $count );
		}

		// 5) Pridanie do fronty (bez duplicit)
		$queue = get_option( 'o3dai_topics', array() );
		$exist = wp_list_pluck( $queue, 'title' );
		$added = 0;
		foreach ( $topics as $t ) {
			if ( $t && ! in_array( $t, $exist, true ) ) {
				$queue[] = array( 'title' => $t, 'status' => 'pending', 'post_id' => 0, 'source' => 'news' );
				$added++;
			}
		}
		update_option( 'o3dai_topics', array_values( $queue ), false );
		o3dai_log( 'News: pridaných ' . $added . ' tém do fronty.' );
		return $added;
	}

	/** Denný beh: vezmi tému a vytvor článok */
	public static function run_daily() {
		if ( ! O3DAI_License::has_api_key() ) {
			o3dai_log( o3dai_t( 'no_api_key' ) );
			return;
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running generation job; harmless no-op when the host disables it
			set_time_limit( 300 );
		}
		o3dai_log( 'Štart generovania…' );
		$queue = get_option( 'o3dai_topics', array() );

		$idx = null;
		foreach ( $queue as $i => $t ) {
			if ( 'pending' === $t['status'] ) {
				$idx = $i;
				break;
			}
		}

		if ( null === $idx ) {
			$res = self::generate_topics();
			if ( is_wp_error( $res ) ) {
				o3dai_log( 'CHYBA (témy): ' . $res->get_error_message() );
				return $res;
			}
			$queue = get_option( 'o3dai_topics', array() );
			foreach ( $queue as $i => $t ) {
				if ( 'pending' === $t['status'] ) {
					$idx = $i;
					break;
				}
			}
			if ( null === $idx ) {
				return new WP_Error( 'o3dai', 'Prázdna fronta tém.' );
			}
		}

		$result = self::write_article( $queue[ $idx ]['title'] );
		if ( is_wp_error( $result ) ) {
			o3dai_log( 'CHYBA (článok): ' . $result->get_error_message() );
			return $result;
		}

		$queue[ $idx ]['status']  = 'done';
		$queue[ $idx ]['post_id'] = $result;
		update_option( 'o3dai_topics', $queue, false );
		o3dai_log( 'Vytvorený článok #' . $result . ': ' . get_the_title( $result ) );
		return $result;
	}

	/** Krok 1: vygeneruje osnovu článku (H2 sekcie + čo v nich má byť) */
	public static function make_outline( $topic, $ctx, $series_note = '' ) {
		$s = o3dai_get_settings();

		$system = 'Si SEO stratég a editor. Navrhuješ osnovy článkov, ktoré logicky plynú a pokrývajú tému úplne. '
			. 'Odpovedz VÝHRADNE osnovou v tomto tvare, bez úvodu a bez záveru:' . "\n"
			. 'H2: názov sekcie | čo v nej stručne pokryť (1 veta)' . "\n"
			. 'Každý riadok = jedna sekcia. Navrhni 4-6 sekcií.';

		$user = 'Téma článku: ' . $topic . "\n\n"
			. ( $series_note ? $series_note . "\n\n" : '' )
			. 'Web: ' . $ctx['site'] . "\n"
			. 'Jazyk: ' . $s['brand_lang'] . '.' . "\n\n"
			. 'Už napísané články (neopakuj ich obsah, nadviaž inak):' . "\n- " . implode( "\n- ", array_slice( $ctx['titles'], 0, 60 ) ) . "\n\n"
			. 'Navrhni osnovu, ktorá čitateľa prevedie témou od úvodu po praktický záver. '
			. 'Sekcie nech nie sú generické – nech zodpovedajú konkrétne na to, čo hľadá človek, ktorý si tému vygooglil.';

		$raw = O3DAI_Providers::generate( $system, $user, 1200 );
		if ( is_wp_error( $raw ) ) {
			return '';
		}
		$raw = trim( (string) $raw );
		update_option( 'o3dai_last_outline', mb_substr( $raw, 0, 3000 ), false );
		return $raw;
	}

	/** Vygeneruje článok na tému a uloží ho */
	public static function write_article( $topic, $retry = false ) {
		$s   = o3dai_get_settings();

		// Fair-use Free verzie (v1.5.11): nad limitom len upozornenie do logu — generovanie pokračuje
		if ( ! O3DAI_License::can_make_article() ) {
			o3dai_log( o3dai_t( 'free_articles_over', array( 'limit' => O3DAI_License::FREE_ARTICLES ) ) );
			update_option( 'o3dai_limit_hit', 'articles', false );
		}

		$ctx = self::site_context();

		// sériový kontext (ak téma patrí do série)
		$series_note = '';
		foreach ( get_option( 'o3dai_topics', array() ) as $qt ) {
			if ( isset( $qt['title'] ) && $qt['title'] === $topic && ! empty( $qt['series'] ) ) {
				$series_note = 'Tento článok je ' . intval( $qt['series_part'] ) . '. diel série „' . $qt['series'] . '". '
					. 'Na začiatku sa krátko odvolaj na sériu a v závere motivuj na ďalšie diely. ';
				break;
			}
		}

		// Krok 1: osnova (ak je zapnutá)
		$outline = '';
		if ( ! empty( $s['use_outline'] ) ) {
			$outline = self::make_outline( $topic, $ctx, $series_note );
			if ( $outline ) {
				o3dai_log( 'Osnova pripravená pre: ' . $topic );
			}
		}

		$system = "Si skúsený slovenský copywriter a SEO špecialista. "
			. $s['instructions'] . " "
			. "Odpovedaj PRESNE v tomto formáte s oddeľovačmi, bez akéhokoľvek textu pred či po:\n"
			. "===TITLE===\n(titulok článku)\n"
			. "===EXCERPT===\n(1-2 vety zhrnutia)\n"
			. "===META_TITLE===\n(max 58 znakov, končí ' | " . $s['brand_name'] . "')\n"
			. "===META_DESC===\n(max 155 znakov)\n"
			. "===FOCUS_KW===\n(2-4 slová)\n"
			. "===IMAGE_SUBJECT===\n(in ENGLISH: 1 short phrase, 1-2 concrete " . ( $s['image_subject_hint'] ?? 'characters' ) . " that fit this article, with colors)\n"
			. "===CONTENT===\n"
			. "(HTML článku. Pravidlá formátovania pre pekný vzhľad:\n"
			. " - úvodný odsek zabaľ do <p class=\"o3d-lead\">…</p> (výraznejší úvod),\n"
			. " - sekcie členi cez <h2>, text v <p>, zoznamy <ul><li>,\n"
			. " - aspoň jeden zvýraznený tip vlož ako <div class=\"o3d-tip\"><strong>Tip:</strong> …</div>,\n"
			. " - jeden odporúčací blok na produkt ako <div class=\"o3d-cta\"><p>krátky text</p><a class=\"o3d-cta-btn\" href=\"PRESNÁ_URL\">" . $s['cta_text'] . "</a></div>,\n"
			. " - POVINNÉ: 2-3 interné odkazy <a href=\"URL\">text</a> na produkty zo zoznamu PRODUKTY (presné URL) a 1 odkaz na článok zo zoznamu ČLÁNKY.\n"
			. " Bez interných odkazov je článok neplatný.)\n"
			. "===END===";

		$user = "Téma článku: {$topic}\n\n"
			. ( $series_note ? $series_note . "\n\n" : '' )
			. ( $outline ? "=== OSNOVA (drž sa jej, každý riadok = jedna <h2> sekcia v tomto poradí) ===\n" . $outline . "\n\n" : '' )
			. "Web: {$ctx['site']}\n\n"
			. "=== PRODUKTY (odkáž na 2-3 relevantné, použi PRESNÉ URL za znakom |) ===\n" . implode( "\n", array_slice( $ctx['products'], 0, 25 ) ) . "\n\n"
			. "=== ČLÁNKY (odkáž na 1, použi URL za znakom |) ===\n" . implode( "\n", $ctx['posts'] ) . "\n\n"
			. "Napíš článok podľa inštrukcií, dodrž formát s oddeľovačmi a NEZABUDNI na interné odkazy s presnými URL.";

		$raw = O3DAI_Providers::generate( $system, $user, 8000 );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		update_option( 'o3dai_last_raw', mb_substr( (string) $raw, 0, 6000 ), false );

		$a = self::parse_markers( $raw );
		if ( empty( $a['TITLE'] ) || empty( $a['CONTENT'] ) ) {
			return new WP_Error( 'o3dai', 'Odpoveď AI sa nepodarilo naparsovať na článok (pozri Debug na stránke pluginu).' );
		}

		if ( ! empty( $s['proofread'] ) ) {
			$a['CONTENT'] = O3DAI_Providers::proofread( $a['CONTENT'] );
			o3dai_log( 'Korektúra článku hotová: ' . $topic );
		}

		$mode      = $s['publish_status'];
		$is_appr   = ( 'approval' === $mode );
		$db_status = $is_appr ? 'pending' : ( in_array( $mode, array( 'publish', 'draft' ), true ) ? $mode : 'draft' );

		// Free PLÁN v PRO zipi: vždy koncept — automatické zverejňovanie je Pro funkcia.
		// FREE build (wp.org) má live publishing plne dostupný, takže ho netreba nútiť (v1.6.0).
		if ( ! O3DAI_License::is_pro() && ! O3DAI_License::is_free_build() && 'draft' !== $db_status ) {
			$db_status = 'draft';
			$is_appr   = false;
			o3dai_log( o3dai_t( 'free_publish_forced_draft' ) );
		}

		$postarr = array(
			'post_type'    => 'post',
			'post_status'  => $db_status,
			'post_title'   => wp_strip_all_tags( $a['TITLE'] ),
			'post_content' => wp_kses_post( $a['CONTENT'] ),
			'post_excerpt' => sanitize_text_field( $a['EXCERPT'] ?? '' ),
		);
		if ( intval( $s['category_id'] ) > 0 ) {
			$postarr['post_category'] = array( intval( $s['category_id'] ) );
		}

		// --- Kontrola kvality ---
		$issues    = self::quality_check( $a );
		$has_error = in_array( 'no_content', $issues, true ) || in_array( 'too_short', $issues, true );
		if ( $has_error && empty( $retry ) ) {
			o3dai_log( 'Kvalita nízka (' . implode( ', ', $issues ) . ') – skúšam ešte raz…' );
			return self::write_article( $topic, true ); // jeden opakovaný pokus
		}
		if ( $issues ) {
			$postarr['post_status'] = 'draft'; // pri probléme radšej koncept na kontrolu
			o3dai_log( 'Upozornenie na kvalitu: ' . implode( ', ', $issues ) . ' → uložené ako koncept.' );
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// SEO polia – naplní všetky aktívne SEO pluginy (Yoast / Rank Math / SEOPress / AIOSEO)
		O3DAI_SEO::set_post( $post_id, $a['META_TITLE'] ?? '', $a['META_DESC'] ?? '', $a['FOCUS_KW'] ?? '' );

		$subject = $a['IMAGE_SUBJECT'] ?? '';
		if ( ! empty( $subject ) ) { update_post_meta( $post_id, '_o3dai_subject', sanitize_text_field( $subject ) ); }
		self::set_featured( $post_id, $a['TITLE'], $s, $subject );

		// obrázky do tela článku
		$with_imgs = self::insert_body_images( $post_id, $a['CONTENT'], $a['TITLE'], $subject, $s );
		if ( $with_imgs !== $a['CONTENT'] ) {
			wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_kses_post( $with_imgs ) ) );
		}

		// prelinkovanie starších článkov na tento nový (len ak sa reálne publikuje)
		if ( 'publish' === $db_status && ! empty( $s['internal_linking'] ) ) {
			self::link_old_posts( $post_id, $a['TITLE'], $a['FOCUS_KW'] ?? '' );
		}

		// režim schvaľovania: pošli e-mail s odkazmi
		if ( $is_appr ) {
			self::send_approval_email( $post_id, $a['TITLE'] );
		}

		// história (štatistiky)
		self::record_history( $post_id, $a );

		// ping vyhľadávačom + webhook na soc. siete – len ak je článok reálne publikovaný
		if ( 'publish' === get_post_status( $post_id ) ) {
			self::ping_search_engines( $post_id );
			self::send_webhook( $post_id );
		}

		return $post_id;
	}

	/** Kontrola kvality vygenerovaného článku; vráti pole kódov problémov */
	public static function quality_check( $a ) {
		$issues = array();
		$content = (string) ( $a['CONTENT'] ?? '' );
		$text    = trim( wp_strip_all_tags( $content ) );
		$words   = $text ? preg_match_all( '/\p{L}+/u', $text ) : 0;

		if ( '' === $text ) {
			$issues[] = 'no_content';
		} elseif ( $words < 350 ) {
			$issues[] = 'too_short';
		}
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( false === strpos( $content, $home ) && false === strpos( $content, 'href="/' ) ) {
			$issues[] = 'no_internal_link';
		}
		if ( false === stripos( $content, '<h2' ) ) {
			$issues[] = 'no_headings';
		}
		$mt = mb_strlen( (string) ( $a['META_TITLE'] ?? '' ) );
		if ( $mt < 10 || $mt > 65 ) {
			$issues[] = 'meta_title_len';
		}
		$md = mb_strlen( (string) ( $a['META_DESC'] ?? '' ) );
		if ( $md < 50 || $md > 165 ) {
			$issues[] = 'meta_desc_len';
		}
		return $issues;
	}

	/** Ping vyhľadávačom, že pribudol/aktualizoval sa obsah (sitemap + IndexNow) */
	public static function ping_search_engines( $post_id ) {
		$url = get_permalink( $post_id );
		if ( ! $url ) {
			return;
		}
		// 1) Google – ping na sitemapu
		$sitemap = home_url( '/sitemap.xml' );
		wp_remote_get( 'https://www.google.com/ping?sitemap=' . rawurlencode( $sitemap ), array( 'timeout' => 15, 'blocking' => false ) );

		// 2) IndexNow (Bing, Seznam, Yandex…) – priama notifikácia o URL
		$key = get_option( 'o3dai_indexnow_key' );
		if ( ! $key ) {
			$key = wp_generate_password( 32, false );
			update_option( 'o3dai_indexnow_key', $key, false );
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		wp_remote_post( 'https://api.indexnow.org/indexnow', array(
			'timeout'  => 15,
			'blocking' => false,
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array(
				'host'        => $host,
				'key'         => $key,
				'keyLocation' => home_url( '/' . $key . '.txt' ),
				'urlList'     => array( $url ),
			) ),
		) );
		o3dai_log( 'Ping vyhľadávačom pre #' . $post_id );
	}

	/** Pošle webhook s dátami PRODUKTU (odkaz vedie na vlastný web zákazníka) */
	public static function send_product_webhook( $post_id ) {
		if ( ! O3DAI_License::feature( 'social' ) ) {
			return;
		}
		$s   = o3dai_get_settings();
		$url = trim( (string) ( $s['webhook_products'] ?? '' ) );
		if ( '' === $url ) {
			return;
		}
		$product   = get_post( $post_id );
		$name      = get_the_title( $post_id );
		$web_url   = get_permalink( $post_id ); // VLASTNÝ web zákazníka
		$price     = get_post_meta( $post_id, '_regular_price', true );
		$price_txt = $price ? ( rtrim( rtrim( number_format( (float) $price, 2, ',', ' ' ), '0' ), ',' ) . ' €' ) : '';
		$thumb     = get_the_post_thumbnail_url( $post_id, 'large' );
		$square    = self::square_image_url( $post_id );
		$pin       = self::pin_image_url( $post_id );
		$short     = wp_strip_all_tags( $product ? $product->post_excerpt : '' );
		if ( '' === $short ) {
			$short = wp_trim_words( wp_strip_all_tags( $product ? $product->post_content : '' ), 30 );
		}

		// v1.4.14 — AI text príspevku (ak je zapnutý a AI dostupná), inak pôvodný bežný text
		$ai_text = self::social_caption_for_product( $post_id );
		if ( '' !== $ai_text ) {
			$message = $ai_text . "\n\n" . $web_url;
			o3dai_log( o3dai_t( 'share_ai_used', array( 'id' => intval( $post_id ) ) ) );
		} else {
			$message = '🎉 Novinka v ponuke: ' . $name
				. ( $price_txt ? ' – ' . $price_txt : '' ) . "\n\n"
				. ( $short ? $short . "\n\n" : '' )
				. '👉 Pozri u nás: ' . $web_url;
			o3dai_log( o3dai_t( 'share_ai_fallback', array( 'id' => intval( $post_id ) ) ) );
		}

		$payload = array(
			'title'   => $name,
			'url'     => $web_url,
			'price'   => $price_txt,
			'excerpt' => $short,
			'image'   => $thumb ? $thumb : '',
			'image_square' => $square ? $square : ( $thumb ? $thumb : '' ),
			'image_pin'  => $pin ? $pin : ( $square ? $square : ( $thumb ? $thumb : '' ) ),
			'message' => $message,
			'caption' => $ai_text,
			'site'    => get_bloginfo( 'name' ),
			'date'    => get_the_date( 'c', $post_id ),
			'type'    => 'product',
		);
		wp_remote_post( $url, array(
			'timeout'  => 20,
			'blocking' => false,
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $payload ),
		) );
	}

	/** AI text pre sociálny príspevok k produktu (v1.4.14).
	 *  Krátky, prirodzený text z názvu, popisu a fotky produktu.
	 *  Ak je funkcia vypnutá, chýba API kľúč, alebo volanie zlyhá → vráti ''
	 *  a volajúci použije bežný (fallback) text. */
	public static function social_caption_for_product( $post_id ) {
		$s = o3dai_get_settings();
		if ( empty( $s['ai_social_text'] ) ) {
			return '';
		}
		if ( ! O3DAI_License::has_api_key() ) {
			return '';
		}
		$name  = get_the_title( $post_id );
		$prod  = get_post( $post_id );
		$desc  = wp_strip_all_tags( $prod ? (string) $prod->post_excerpt : '' );
		if ( '' === trim( $desc ) ) {
			$desc = wp_strip_all_tags( $prod ? (string) $prod->post_content : '' );
		}
		$desc  = trim( preg_replace( '/\s+/u', ' ', (string) $desc ) );
		$desc  = mb_substr( $desc, 0, 500 );
		$price = get_post_meta( $post_id, '_regular_price', true );
		$price_txt = $price ? ( rtrim( rtrim( number_format( (float) $price, 2, ',', ' ' ), '0' ), ',' ) . ' €' ) : '';

		$system = 'Si skúsený copywriter pre sociálne siete (Facebook, Instagram). '
			. 'Napíš 2-4 krátke, prirodzené a ľudské vety — priateľský tón, bez nadmerne marketingových sľubov, '
			. 'bez zmienky o AI, bez zoznamu bodov, bez markdownu. Len samotný text príspevku, bez odkazu (odkaz doplní systém).' . "\n"
			. 'Jazyk: ' . trim( (string) ( $s['brand_lang'] ?: 'slovenčina' ) ) . '.';
		$user = 'Produkt: ' . $name . "\n"
			. ( $desc ? 'Popis: ' . $desc . "\n" : '' )
			. ( $price_txt ? 'Cena: ' . $price_txt . "\n" : '' )
			. ( trim( (string) ( $s['brand_name'] ?? '' ) ) ? 'Značka/web: ' . trim( (string) $s['brand_name'] ) . "\n" : '' )
			. "\nNapíš text príspevku, ktorým tento produkt oslovia ľudí, pre ktorých je určený. Ak je v prílohe fotka produktu, zohľadni, čo na nej vidno, a nevymýšľaj vlastnosti, ktoré na nej nie sú.";

		// Fotka produktu (ak je) → vision volanie; ak zlyhá, skúsime text bez obrázka
		$img_bin = '';
		$mime    = '';
		$att_id  = get_post_thumbnail_id( $post_id );
		if ( $att_id ) {
			$file = get_attached_file( $att_id );
			$mime = (string) get_post_mime_type( $att_id );
			if ( $file && is_file( $file ) && 0 === strpos( $mime, 'image/' ) ) {
				$img_bin = (string) @file_get_contents( $file );
			}
		}

		$raw = null;
		if ( '' !== $img_bin ) {
			$raw = O3DAI_Providers::generate_vision( $system, $user, $img_bin, $mime ?: 'image/jpeg', 250 );
			if ( is_wp_error( $raw ) ) {
				$raw2 = O3DAI_Providers::generate( $system, $user, 250 );
				if ( ! is_wp_error( $raw2 ) ) {
					$raw = $raw2;
				}
			}
		}
		if ( null === $raw ) {
			$raw = O3DAI_Providers::generate( $system, $user, 250 );
		}
		if ( is_wp_error( $raw ) ) {
			o3dai_log( o3dai_t( 'share_ai_error', array( 'id' => intval( $post_id ), 'err' => $raw->get_error_message() ) ) );
			return '';
		}
		$txt = trim( (string) $raw );
		$txt = trim( preg_replace( '/^(text|caption|odpove|post)\s*[:\-]\s*/iu', '', $txt ) );
		$txt = trim( preg_replace( '/\s+/u', ' ', $txt ) );
		if ( mb_strlen( $txt ) > 600 ) {
			$txt = mb_substr( $txt, 0, 600 );
		}
		return $txt;
	}

	/** Vytvorí verziu obrázka s cieľovými rozmermi (1080×1080 IG / 1000×1500 Pinterest),
	 *  pri ktorej je VŠETKÝ obsah viditeľný (v1.5.9): pôvodný obrázok v plnom pomere
	 *  je vycentrovaný na tmavom pozadí (rozmazaná + ztmavená verzia seba) s tenkým
	 *  bielym rámom. Široké fotky sa už netrešú orezom. Ak GD nie je dostupné,
	 *  vráti '' a volajúci použije fallback (stredový orez). */
	private static function fit_image( $src_file, $tw, $th, $out_name ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}
		$mime = wp_check_filetype( $src_file )['type'] ?? '';
		$src  = null;
		if ( 'image/png' === $mime ) {
			$src = @imagecreatefrompng( $src_file );
		} elseif ( 'image/webp' === $mime ) {
			$src = @imagecreatefromwebp( $src_file );
		} else {
			$src = @imagecreatefromjpeg( $src_file );
		}
		if ( ! $src || ! imagesx( $src ) || ! imagesy( $src ) ) {
			if ( $src ) { imagedestroy( $src ); }
			return '';
		}
		$w = imagesx( $src );
		$h = imagesy( $src );
		$canvas = imagecreatetruecolor( $tw, $th );

		// --- Pozadie: cover (rozmazané + ztmavené) ---
		$cs = max( $tw / $w, $th / $h );
		$cw = max( $tw, (int) ceil( $w * $cs ) );
		$ch = max( $th, (int) ceil( $h * $cs ) );
		$bg = imagecreatetruecolor( $cw, $ch );
		imagecopyresampled( $bg, $src, 0, 0, 0, 0, $cw, $ch, $w, $h );
		$dark = imagecreatetruecolor( $cw, $ch );
		$dc   = imagecolorallocate( $dark, 22, 22, 36 );
		imagefilledrectangle( $dark, 0, 0, $cw, $ch, $dc );
		imagecopymerge( $bg, $dark, 0, 0, 0, 0, $cw, $ch, 60 ); // ztmavnutie 60%
		imagedestroy( $dark );
		if ( defined( 'GD_FILTER_BLUR' ) ) {
			@imagefilter( $bg, GD_FILTER_BLUR );
			@imagefilter( $bg, GD_FILTER_SELECTIVE_BLUR );
		}
		$bx = (int) floor( ( $cw - $tw ) / 2 );
		$by = (int) floor( ( $ch - $th ) / 2 );
		imagecopy( $canvas, $bg, 0, 0, $bx, $by, $tw, $th );
		imagedestroy( $bg );

		// --- Popredie: contain (celý obrázok) + tenký biely rám ---
		$fs = min( $tw / $w, $th / $h );
		$fw = max( 1, (int) floor( $w * $fs ) );
		$fh = max( 1, (int) floor( $h * $fs ) );
		$fg = imagecreatetruecolor( $fw, $fh );
		if ( 'image/png' === $mime ) {
			imagealphablending( $fg, false );
			imagesavealpha( $fg, true );
		}
		imagecopyresampled( $fg, $src, 0, 0, 0, 0, $fw, $fh, $w, $h );
		$fx = (int) floor( ( $tw - $fw ) / 2 );
		$fy = (int) floor( ( $th - $fh ) / 2 );
		if ( ( $fx > 0 || $fy > 0 ) && min( $fw, $fh ) >= 80 ) {
			$bd    = 6;
			$white = imagecolorallocate( $canvas, 255, 255, 255 );
			imagefilledrectangle( $canvas, $fx - $bd, $fy - $bd, $fx + $fw + $bd, $fy + $fh + $bd, $white );
		}
		imagecopy( $canvas, $fg, $fx, $fy, 0, 0, $fw, $fh );

		$dir  = wp_upload_dir();
		$path = trailingslashit( $dir['path'] ) . $out_name;
		$ok   = imagejpeg( $canvas, $path, 88 );
		imagedestroy( $src );
		imagedestroy( $fg );
		imagedestroy( $canvas );
		if ( ! $ok || ! file_exists( $path ) ) {
			return '';
		}
		return trailingslashit( $dir['url'] ) . $out_name;
	}

	/** Fallback (stará metóda): stredový orez na cieľové rozmery. */
	private static function crop_center_url( $file, $tw, $th, $name ) {
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return '';
		}
		$size  = $editor->get_size();
		$w     = intval( $size['width'] );
		$h     = intval( $size['height'] );
		$ratio = $tw / max( 1, $th );
		$cur   = $w / max( 1, $h );
		if ( $cur > $ratio ) {
			$src_w = intval( $h * $ratio );
			$src_h = $h;
		} else {
			$src_w = $w;
			$src_h = intval( $w / $ratio );
		}
		$src_x = intval( ( $w - $src_w ) / 2 );
		$src_y = intval( ( $h - $src_h ) / 2 );
		$editor->crop( $src_x, $src_y, $src_w, $src_h, $tw, $th );
		$dir   = wp_upload_dir();
		$path  = trailingslashit( $dir['path'] ) . $name;
		$saved = $editor->save( $path, 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return '';
		}
		return trailingslashit( $dir['url'] ) . basename( $saved['path'] );
	}

	/** Vráti URL štvorcovej (1080×1080) verzie hlavného obrázka – pre Instagram. Vytvorí ju raz a uloží. */
	public static function square_image_url( $post_id ) {
		$att_id = get_post_thumbnail_id( $post_id );
		if ( ! $att_id ) {
			return '';
		}
		// už vytvorené? (_v2 = nová fit metóda; staré cache sa pri ďalšom zdieľaní preklikne)
		$cached = get_post_meta( $att_id, '_o3dai_square_url_v2', true );
		if ( $cached ) {
			return $cached;
		}
		$file = get_attached_file( $att_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return '';
		}
		$url = self::fit_image( $file, 1080, 1080, 'o3dai-sq-' . $att_id . '.jpg' );
		if ( '' === $url ) {
			$url = self::crop_center_url( $file, 1080, 1080, 'o3dai-sq-' . $att_id . '.jpg' );
		}
		if ( '' !== $url ) {
			update_post_meta( $att_id, '_o3dai_square_url_v2', $url );
		}
		return $url;
	}

	/** Vráti URL vertikálnej (1000×1500, pomer 2:3) verzie hlavného obrázka – pre Pinterest. Vytvorí ju raz a uloží. */
	public static function pin_image_url( $post_id ) {
		$att_id = get_post_thumbnail_id( $post_id );
		if ( ! $att_id ) {
			return '';
		}
		// už vytvorené? (_v2 = nová fit metóda; staré cache sa pri ďalšom zdieľaní preklikne)
		$cached = get_post_meta( $att_id, '_o3dai_pin_url_v2', true );
		if ( $cached ) {
			return $cached;
		}
		$file = get_attached_file( $att_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return '';
		}
		$url = self::fit_image( $file, 1000, 1500, 'o3dai-pin-' . $att_id . '.jpg' );
		if ( '' === $url ) {
			$url = self::crop_center_url( $file, 1000, 1500, 'o3dai-pin-' . $att_id . '.jpg' );
		}
		if ( '' !== $url ) {
			update_post_meta( $att_id, '_o3dai_pin_url_v2', $url );
		}
		return $url;
	}

	/** Pošle webhook (Make.com / Buffer / Zapier) s dátami článku na zdieľanie */
	public static function send_webhook( $post_id ) {
		if ( ! O3DAI_License::feature( 'social' ) ) {
			return;
		}
		$s   = o3dai_get_settings();
		$url = trim( (string) ( $s['webhook_url'] ?? '' ) );
		if ( '' === $url ) {
			return;
		}
		$thumb = get_the_post_thumbnail_url( $post_id, 'large' );
		$square = self::square_image_url( $post_id );
		$pin    = self::pin_image_url( $post_id );
		// krátky text na sociálnu sieť z inštrukcií AI netreba – použijeme excerpt
		$excerpt = wp_strip_all_tags( get_the_excerpt( $post_id ) );
		$payload = array(
			'title'     => get_the_title( $post_id ),
			'url'       => get_permalink( $post_id ),
			'excerpt'   => $excerpt,
			'image'     => $thumb ? $thumb : '',
			'image_square' => $square ? $square : ( $thumb ? $thumb : '' ),
			'image_pin'  => $pin ? $pin : ( $square ? $square : ( $thumb ? $thumb : '' ) ),
			'message'   => get_the_title( $post_id ) . "\n\n" . $excerpt . "\n\n" . get_permalink( $post_id ),
			'site'      => get_bloginfo( 'name' ),
			'date'      => get_the_date( 'c', $post_id ),
		);
		$r = wp_remote_post( $url, array(
			'timeout'  => 20,
			'blocking' => false,
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $payload ),
		) );
		o3dai_log( 'Webhook (soc. siete) odoslaný pre #' . $post_id );
	}

	/** Automatická propagácia produktov (v1.4.13).
	 *  Vyberie najnovší publikovaný produkt, ktorý ešte NIKDE nebol propagovaný
	 *  (ani pri publikovaní, ani reshareom), pošle ho na produktový webhook a označí,
	 *  aby sa nikdy neopakoval. Ak už všetky produkty boli propagované, slot sa preskočí. */
	public static function reshare_product() {
		if ( ! O3DAI_License::feature( 'social' ) ) {
			return array( 'status' => 'no_feature' );
		}
		$s   = o3dai_get_settings();
		$url = trim( (string) ( $s['webhook_products'] ?? '' ) );
		if ( '' === $url ) {
			o3dai_log( o3dai_t( 'share_skip_nohook' ) );
			return array( 'status' => 'skip_nohook' );
		}
		if ( ! post_type_exists( 'product' ) ) {
			o3dai_log( o3dai_t( 'share_skip_notype' ) );
			return array( 'status' => 'skip_notype' );
		}
		$cand = get_posts( array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'numberposts' => 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'meta_query'  => array(
				array( 'key' => '_o3dai_shared', 'compare' => 'NOT EXISTS' ),
			),
		) );
		if ( empty( $cand ) ) {
			o3dai_log( o3dai_t( 'share_skip_done' ) );
			return array( 'status' => 'skip_done' );
		}
		$pid = $cand[0]->ID;
		// Najprv označíme (anti-duplikát), až potom odosielame
		update_post_meta( $pid, '_o3dai_shared', time() );
		self::send_product_webhook( $pid );
		o3dai_log( o3dai_t( 'share_sent', array( 'id' => intval( $pid ), 'title' => get_the_title( $pid ) ) ) );
		return array( 'status' => 'sent', 'id' => intval( $pid ), 'title' => get_the_title( $pid ) );
	}

	/** Odosle testovú správu na webhook (blocking) — vráti HTTP kód alebo WP_Error. (v1.4.13)
	 *  $url_override — ak je vyplnený, testuje sa presne táto URL (napr. ešte neuložená hodnota z formulára). */
	public static function test_webhook( $kind, $url_override = '' ) {
		$s   = o3dai_get_settings();
		$url = ( 'products' === $kind ) ? trim( (string) ( $s['webhook_products'] ?? '' ) ) : trim( (string) ( $s['webhook_url'] ?? '' ) );
		if ( '' !== trim( (string) $url_override ) ) {
			$url = trim( (string) $url_override );
		}
		if ( '' === $url ) {
			return new WP_Error( 'o3dai', o3dai_t( 'test_no_url' ) );
		}
		// Reálne dáta: najnovší publikovaný článok / produkt (ak existuje)
		$post_id = 0;
		$ptype   = ( 'products' === $kind ) ? 'product' : 'post';
		if ( 'products' !== $kind || post_type_exists( 'product' ) ) {
			$c = get_posts( array( 'post_type' => $ptype, 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
			if ( $c ) { $post_id = $c[0]->ID; }
		}
		$payload = array(
			'type'    => ( 'products' === $kind ? 'product' : 'article' ) . '_test',
			'title'   => o3dai_t( 'test_title', array( 'kind' => ( 'products' === $kind ? o3dai_t( 'test_kind_product' ) : o3dai_t( 'test_kind_article' ) ) ) ),
			'message' => o3dai_t( 'test_message' ),
			'site'    => get_bloginfo( 'name' ),
			'date'    => current_time( 'c' ),
		);
		if ( $post_id ) {
			$payload['title']   = 'TEST: ' . get_the_title( $post_id );
			$payload['url']     = get_permalink( $post_id );
			$payload['image']   = get_the_post_thumbnail_url( $post_id, 'large' ) ? get_the_post_thumbnail_url( $post_id, 'large' ) : '';
			$payload['message'] .= "\n\n" . o3dai_t( 'test_real', array( 'title' => get_the_title( $post_id ), 'url' => get_permalink( $post_id ) ) );
			if ( 'products' === $kind ) {
				$payload['caption'] = self::social_caption_for_product( $post_id ); // v1.4.14 — ukážka AI textu
				if ( '' !== $payload['caption'] ) {
					$payload['message'] .= "\n\n" . $payload['caption'];
				}
			}
		}
		$r = wp_remote_post( $url, array(
			'timeout'  => 20,
			'blocking' => true,
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = wp_remote_retrieve_response_code( $r );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'o3dai', o3dai_t( 'test_http', array( 'code' => intval( $code ) ) ) );
		}
		return intval( $code );
	}

	/** Zaznamenaj článok do histórie (na štatistiky) */
	public static function record_history( $post_id, $a ) {
		$content = (string) ( $a['CONTENT'] ?? '' );
		$words   = preg_match_all( '/\p{L}+/u', wp_strip_all_tags( $content ) );
		$links   = preg_match_all( '/<a\s[^>]*href/i', $content );
		$hist    = get_option( 'o3dai_history', array() );
		array_unshift( $hist, array(
			'id'    => $post_id,
			'title' => wp_strip_all_tags( $a['TITLE'] ?? get_the_title( $post_id ) ),
			'date'  => current_time( 'Y-m-d H:i' ),
			'words' => intval( $words ),
			'links' => intval( $links ),
			'kw'    => sanitize_text_field( $a['FOCUS_KW'] ?? '' ),
		) );
		update_option( 'o3dai_history', array_slice( $hist, 0, 200 ), false );
	}

	/** Nájde staršie články na príbuznú tému a vloží do nich odkaz na nový článok */
	public static function link_old_posts( $new_id, $new_title, $focus_kw ) {
		$words = array_filter( preg_split( '/\s+/', mb_strtolower( $focus_kw . ' ' . $new_title ) ), function ( $w ) {
			return mb_strlen( $w ) >= 4;
		} );
		if ( ! $words ) {
			return;
		}
		$candidates = get_posts( array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'numberposts'  => 8,
			'post__not_in' => array( $new_id ),
			's'            => implode( ' ', array_slice( $words, 0, 3 ) ),
		) );
		$new_url = get_permalink( $new_id );
		$done    = 0;
		foreach ( $candidates as $old ) {
			if ( $done >= 2 ) {
				break;
			}
			if ( get_post_meta( $old->ID, '_o3dai_linked_' . $new_id, true ) ) {
				continue;
			}
			$box = "\n<div class=\"o3d-related\"><strong>Mohlo by vás zaujímať:</strong> <a href=\"" . esc_url( $new_url ) . "\">" . esc_html( $new_title ) . "</a></div>\n";
			wp_update_post( array( 'ID' => $old->ID, 'post_content' => $old->post_content . $box ) );
			update_post_meta( $old->ID, '_o3dai_linked_' . $new_id, 1 );
			$done ++;
		}
		if ( $done ) {
			o3dai_log( 'Prelinkované staršie články: ' . $done );
		}

		// článok → príbuzný produkt: vlož do nového článku odkaz na najrelevantnejší produkt
		self::link_article_to_product( $new_id, $words );
	}

	/** Do nového článku vloží box s odkazom na najrelevantnejší produkt */
	public static function link_article_to_product( $post_id, $words ) {
		if ( ! post_type_exists( 'product' ) || ! $words ) {
			return;
		}
		$prods = get_posts( array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'numberposts'  => 1,
			's'            => implode( ' ', array_slice( $words, 0, 3 ) ),
		) );
		if ( ! $prods ) {
			return;
		}
		$p = $prods[0];
		$post = get_post( $post_id );
		// ak už na produkt odkazuje priamo v texte, nepridávaj box
		if ( $post && false !== strpos( $post->post_content, get_permalink( $p->ID ) ) ) {
			return;
		}
		$img = get_the_post_thumbnail( $p->ID, 'thumbnail' );
		$box = "\n<div class=\"o3d-related o3d-product-cta\"><strong>Z našej ponuky:</strong> "
			. '<a href="' . esc_url( get_permalink( $p->ID ) ) . '">' . esc_html( get_the_title( $p->ID ) ) . '</a></div>' . "\n";
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $post->post_content . $box ) );
		o3dai_log( 'Do článku #' . $post_id . ' pridaný odkaz na produkt: ' . get_the_title( $p->ID ) );
	}

	/** Pošle e-mail so schvaľovacími odkazmi */
	public static function send_approval_email( $post_id, $title ) {
		$token = wp_generate_password( 20, false );
		update_post_meta( $post_id, '_o3dai_token', $token );
		$base = admin_url( 'admin.php?page=o3dai' );
		$pub  = add_query_arg( array( 'o3dai_approve' => $post_id, 'o3dai_tok' => $token, 'o3dai_do' => 'publish' ), home_url( '/' ) );
		$del  = add_query_arg( array( 'o3dai_approve' => $post_id, 'o3dai_tok' => $token, 'o3dai_do' => 'trash' ), home_url( '/' ) );
		$edit = get_edit_post_link( $post_id, '' );
		$body = "Nový článok čaká na schválenie:\n\n" . $title . "\n\n"
			. "✅ Publikovať: " . $pub . "\n\n"
			. "🗑 Zahodiť: " . $del . "\n\n"
			. "✏️ Najprv upraviť: " . $edit . "\n";
		wp_mail( get_option( 'admin_email' ), 'AI Autopilot: článok na schválenie – ' . $title, $body );
		o3dai_log( 'Odoslaný e-mail na schválenie #' . $post_id );
	}

	/** Spracuje kliknutie z e-mailu (publish / trash) */
	public static function handle_approval( $post_id, $token, $do ) {
		$stored = get_post_meta( $post_id, '_o3dai_token', true );
		if ( ! $stored || ! hash_equals( $stored, $token ) ) {
			wp_die( 'Neplatný alebo už použitý odkaz.' );
		}
		delete_post_meta( $post_id, '_o3dai_token' );
		if ( 'trash' === $do ) {
			wp_trash_post( $post_id );
			o3dai_log( 'Článok #' . $post_id . ' zahodený cez e-mail.' );
			wp_die( 'Článok bol zahodený (presunutý do koša). Toto okno môžete zavrieť.' );
		}
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		$s = o3dai_get_settings();
		if ( ! empty( $s['internal_linking'] ) ) {
			self::link_old_posts( $post_id, get_the_title( $post_id ), O3DAI_SEO::get_focus_kw( $post_id ) );
		}
		self::ping_search_engines( $post_id );
		self::send_webhook( $post_id );
		o3dai_log( 'Článok #' . $post_id . ' publikovaný cez e-mail.' );
		wp_safe_redirect( get_permalink( $post_id ) );
		exit;
	}

	private static function random_subject() {
		$s   = o3dai_get_settings();
		$list = array_filter( array_map( 'trim', explode( "\n", (string) ( $s['image_subjects'] ?? '' ) ) ) );
		if ( ! $list ) {
			$list = array( 'a cute mascot character' );
		}
		return $list[ array_rand( $list ) ];
	}

	/**
	 * Obrázok zo zadarmo fotobanky (Pexels/Pixabay/Openverse) – bez AI nákladov.
	 * Vracia array { bin, credit, url } alebo WP_Error.
	 */
	private static function stock_image( $title, $subject, $s ) {
		if ( ! class_exists( 'O3DAI_Stock' ) ) {
			return new WP_Error( 'o3dai', 'Fotobanky – trieda nenačítaná.' );
		}
		// Dotaz v angličtine: námet článku (subject) + z titulu slová + voliteľné doplnky
		$q = trim( (string) $subject );
		if ( '' === $q ) {
			$q = self::title_to_words( $title );
		}
		$extra = trim( (string) ( $s['stock_query_extra'] ?? '' ) );
		if ( $extra ) {
			$q .= ' ' . $extra;
		}
		$q = preg_replace( '/\s+/', ' ', mb_substr( trim( (string) $q ), 0, 90 ) );
		if ( '' === $q ) {
			return new WP_Error( 'o3dai', 'Fotobanka: nedalo sa zostaviť hľadaný výraz (vyplni „Extra hľadané slová").' );
		}
		o3dai_log( 'Fotobanka: hľadám „' . $q . '"…' );
		return O3DAI_Stock::fetch( $q, $s );
	}

	/** Z titulu článku vytiahne hľadáateľné slová (bez krátkych/slovenských predložiek) */
	private static function title_to_words( $title ) {
		$title = wp_strip_all_tags( (string) $title );
		$words = preg_split( '/\s+/', $title ) ?: array();
		$stop  = array( 'ako', 'pre', 'na', 'z', 'v', 's', 'a', 'do', 'o', 'je', 'čo', 'kedy', 'kde', 'prečo', 'to', 'sa', 'som', 'the', 'and', 'for', 'of', 'to', 'how', 'why', 'what', 'when', 'where', 'with', 'your', 'best', 'top' );
		$out   = array();
		foreach ( $words as $w ) {
			$w = strtolower( trim( $w ) );
			if ( mb_strlen( $w ) >= 4 && ! in_array( $w, $stop, true ) ) {
				$out[] = $w;
			}
		}
		return implode( ' ', array_slice( $out, 0, 5 ) );
	}

	/** Zloží prompt a vygeneruje obrázok; vráti binárku alebo WP_Error */
	private static function make_image( $title, $subject, $s ) {
		if ( '' === trim( $subject ) ) {
			$subject = self::random_subject();
		}
		$variations = array(
			'the main subject centered on a clean, soft studio background',
			'flat lay composition photographed from above on a light neutral surface',
			'the main subject on a wooden surface with soft morning window light',
			'the main subject in a natural lifestyle setting, close-up, no faces visible',
			'the main subject with related props, minimal warm background',
			'the main subject on a windowsill next to a small plant, dreamy light',
		);
		$moods = array( 'top-down flat lay angle', 'close-up macro angle', 'wide establishing angle',
			'low three-quarter angle', 'cozy diagonal composition', 'centered symmetrical composition' );
		$prompt = 'Article topic: "' . $title . '". '
			. 'Main subject(s): ' . $subject . '. '
			. 'Composition and scene: ' . $variations[ array_rand( $variations ) ] . '. '
			. 'Camera: ' . $moods[ array_rand( $moods ) ] . '. '
			. 'Style: ' . stripslashes( $s['image_style'] );
		update_option( 'o3dai_last_img_prompt', $prompt, false );
		return O3DAI_Providers::generate_image( $prompt );
	}

	/** Uloží binárku obrázka ako prílohu; vráti attachment ID alebo 0 (články, produkty, kategórie) */
	public static function attach_image( $post_id, $png, $title ) {
		if ( is_wp_error( $png ) || ! is_string( $png ) || '' === $png ) {
			return 0;
		}
		if ( ! empty( o3dai_get_settings()['optimize_images'] ) ) {
			self::optimize_image( $png );
		}
		$is_jpeg = ( "\xFF\xD8" === substr( $png, 0, 2 ) );
		$is_webp = ( 'RIFF' === substr( $png, 0, 4 ) && 'WEBP' === substr( $png, 8, 4 ) );
		$ext     = $is_jpeg ? 'jpg' : ( $is_webp ? 'webp' : 'png' );
		$mime    = $is_jpeg ? 'image/jpeg' : ( $is_webp ? 'image/webp' : 'image/png' );
		$upload  = wp_upload_bits( sanitize_title( $title ) . '-' . wp_generate_password( 5, false ) . '.' . $ext, null, $png );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$att = wp_insert_attachment( array(
			'post_mime_type' => $mime,
			'post_title'     => $title,
			'post_status'    => 'inherit',
		), $upload['file'], $post_id );
		if ( is_wp_error( $att ) || ! $att ) {
			return 0;
		}
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $upload['file'] ) );
		return intval( $att );
	}

	/**
	 * Optimalizácia obrázka: max šírka 1600 px + konverzia do WebP (kvalita 82).
	 * Menšie súbory = rýchlejší web = lepšie SEO. Ak server nepodporuje GD/WebP, ticho sa preskočí.
	 * @param string $bin binárka obrázka (pass by reference)
	 */
	private static function optimize_image( &$bin ) {
		if ( ! is_string( $bin ) || strlen( $bin ) < 1000 ) {
			return;
		}
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagewebp' ) ) {
			o3dai_log( 'Optimalizácia obrázkov: server nepodporuje GD/WebP – preskočené.' );
			return;
		}
		$im = @imagecreatefromstring( $bin );
		if ( false === $im ) {
			return;
		}
		$w    = imagesx( $im );
		$h    = imagesy( $im );
		$maxw = 1600;
		if ( $w > $maxw ) {
			$newh   = max( 1, (int) round( $h * $maxw / $w ) );
			$canvas = imagecreatetruecolor( $maxw, $newh );
			if ( function_exists( 'imagealphablending' ) ) {
				imagealphablending( $canvas, false );
			}
			if ( function_exists( 'imagesavealpha' ) ) {
				imagesavealpha( $canvas, true );
			}
			imagecopyresampled( $canvas, $im, 0, 0, 0, 0, $maxw, $newh, $w, $h );
			imagedestroy( $im );
			$im = $canvas;
		}
		ob_start();
		@imagewebp( $im, null, 82 );
		$out = ob_get_clean();
		imagedestroy( $im );
		if ( is_string( $out ) && '' !== $out && strlen( $out ) < strlen( $bin ) ) {
			$bin = $out; // WebP je väčšie? Potom ostáva pôvodný
		}
	}

	/** Hlavný ilustračný obrázok: AI generovanie (s failoverom) / zadarmo fotobanka; záloha rotácia ID */
	private static function set_featured( $post_id, $title, $s, $subject = '' ) {
		$raw_mode   = (string) ( $s['image_mode'] ?? 'ids' );
		$mode       = O3DAI_License::eff_image_mode( $raw_mode );
		if ( $mode !== $raw_mode ) {
			o3dai_log( o3dai_t( 'free_images_stock_fallback' ) );
		}
		$ai_mode    = ( 'ai' === $mode );
		$stock_mode = ( 'stock' === $mode );
		if ( $stock_mode ) {
			$stock = self::stock_image( $title, $subject, $s );
			if ( ! is_wp_error( $stock ) ) {
				$att = self::attach_image( $post_id, $stock['bin'], $title );
				if ( $att ) {
					set_post_thumbnail( $post_id, $att );
					update_post_meta( $post_id, '_o3dai_image_credit', sanitize_text_field( $stock['credit'] ) );
					o3dai_log( 'Hlavný obrázok: zadarmo fotobanka – ' . $stock['credit'] );
					return true;
				}
			} else {
				o3dai_log( 'Hlavný obrázok: fotobanka zlyhala — ' . $stock->get_error_message() );
			}
		}
		if ( $ai_mode ) {
			// Free limit AI obrázkov: nechaj článok bez obrázka
			if ( ! O3DAI_License::can_make_image() ) {
				o3dai_log( o3dai_t( 'free_images_used', array( 'limit' => O3DAI_License::FREE_IMAGES ) ) );
				update_option( 'o3dai_limit_hit', 'images', false );
				return false;
			}
			$png = self::make_image( $title, $subject, $s );
			if ( ! is_wp_error( $png ) ) {
				$att = self::attach_image( $post_id, $png, $title );
				if ( $att ) {
					set_post_thumbnail( $post_id, $att );
					o3dai_log( 'Hlavný obrázok: vygenerovaný AI.' );
					return true;
				}
			} else {
				o3dai_log( 'Hlavný obrázok: AI zlyhalo — ' . self::img_fail_hint( $png->get_error_message() ) );
			}
		}
		$ids = array_filter( array_map( 'intval', explode( ',', (string) $s['featured_ids'] ) ) );
		if ( $ids ) {
			set_post_thumbnail( $post_id, $ids[ $post_id % count( $ids ) ] );
			o3dai_log( 'Hlavný obrázok: AI neprebehlo — použil som rotáciu ID z médií.' );
		} elseif ( $ai_mode ) {
			o3dai_log( 'Hlavný obrázok: AI zlyhalo a nie je nastavený zoznam ID na rotáciu — článok bez hlavného obrázka.' );
		}
		return false;
	}

	/** Pridá čitateľný doplnok k chybe obrázka (kvóta, kľúč…) */
	private static function img_fail_hint( $msg ) {
		$msg = (string) $msg;
		if ( preg_match( '/quota|exceeded|429|rate.?limit|billing/i', $msg ) ) {
			return $msg . ' [kvóta API vyčerpaná — skús iný image model / API kľúč, alebo nastav zoznam ID na rotáciu]';
		}
		return $msg;
	}

	/** Vloží 1-2 obrázky do tela článku za vybrané <h2> sekcie */
	public static function insert_body_images( $post_id, $content, $title, $subject, $s ) {
		$count    = intval( $s['body_images'] ?? 0 );
		$img_mode = O3DAI_License::eff_image_mode( (string) ( $s['image_mode'] ?? 'ids' ) );
		if ( $count < 1 || ! in_array( $img_mode, array( 'ai', 'stock' ), true ) ) {
			return $content;
		}
		// pozície za </h2>...</p> – vložíme za prvý odsek nasledujúci po H2
		$parts = preg_split( '/(<h2[^>]*>.*?<\/h2>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( count( $parts ) < 3 ) {
			return $content; // málo sekcií
		}
		// indexy H2 blokov v $parts sú nepárne (1,3,5…)
		$h2_idx = array();
		for ( $i = 1; $i < count( $parts ); $i += 2 ) {
			$h2_idx[] = $i;
		}
		// vyber max $count sekcií, rovnomerne, preskoč úplne prvú (hneď za úvodom je hlavný obrázok)
		$targets = array();
		if ( count( $h2_idx ) >= 2 ) {
			$targets[] = $h2_idx[1];
			if ( $count >= 2 && isset( $h2_idx[ intval( count( $h2_idx ) / 2 ) + 1 ] ) ) {
				$targets[] = $h2_idx[ intval( count( $h2_idx ) / 2 ) + 1 ];
			}
		}
		foreach ( $targets as $ti ) {
			if ( 'stock' === $img_mode ) {
				$stock = self::stock_image( $title, $subject, $s );
				if ( is_wp_error( $stock ) ) {
					o3dai_log( 'Obrázky v texte: fotobanka zlyhala — ' . $stock->get_error_message() . ' — článok bez vkladných obrázkov.' );
					break;
				}
				$bin = $stock['bin'];
				update_post_meta( $post_id, '_o3dai_image_credit', sanitize_text_field( $stock['credit'] ) );
			} else {
				if ( ! O3DAI_License::can_make_image() ) {
					o3dai_log( o3dai_t( 'free_images_used', array( 'limit' => O3DAI_License::FREE_IMAGES ) ) );
					break; // Free limit obrázkov vyčerpaný – nedopĺňaj ďalšie
				}
				$bin = self::make_image( $title, $subject, $s );
				if ( is_wp_error( $bin ) ) {
					// Ak prvý zlyhá (napr. kvóta), ďalšie zlyhajú tiež — nahlas do logu, bez zbytočných API volaní
					o3dai_log( 'Obrázky v texte: AI zlyhalo — ' . self::img_fail_hint( $bin->get_error_message() ) . ' — článok bez vkladných obrázkov.' );
					break;
				}
			}
			$att = self::attach_image( $post_id, $bin, $title );
			if ( $att ) {
				$url = wp_get_attachment_image_url( $att, 'large' );
				$fig = "\n<figure class=\"o3d-figure\"><img src=\"" . esc_url( $url ) . "\" alt=\"" . esc_attr( $title ) . "\" loading=\"lazy\"></figure>\n";
				// vlož za text nasledujúci hneď po tomto H2 (t.j. za $parts[$ti+1] pridáme na začiatok? radšej za celý blok)
				$parts[ $ti ] .= $fig;
			}
		}
		return implode( '', $parts );
	}

	/** Parsovanie odpovede s ===MARKER=== oddeľovačmi */
	private static function parse_markers( $raw ) {
		$out = array();
		if ( preg_match_all( '/===([A-Z_]+)===\s*(.*?)(?====[A-Z_]+===|$)/s', (string) $raw, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $seg ) {
				if ( 'END' !== $seg[1] ) {
					$out[ $seg[1] ] = trim( $seg[2] );
				}
			}
		}
		return $out;
	}

	/** Znova vygeneruje ilustračný obrázok pre existujúci článok (podľa nastaveného režimu) */
	public static function regenerate_image( $post_id ) {
		$s = o3dai_get_settings();
		if ( 'none' === ( $s['image_mode'] ?? '' ) ) {
			$s['image_mode'] = 'ai'; // režim „žiadny" → skús AI
		}
		$subject = get_post_meta( $post_id, '_o3dai_subject', true );
		o3dai_log( 'Regenerujem obrázok pre #' . $post_id . ' (' . get_the_title( $post_id ) . ')…' );
		$ok = self::set_featured( $post_id, get_the_title( $post_id ), $s, $subject );
		o3dai_log( $ok ? 'Obrázok #' . $post_id . ' hotový (' . ( $s['image_model'] ?? '?' ) . ').' : 'Obrázok #' . $post_id . ' – pozri log vyššie.' );
		return $post_id;
	}

	/** Znova vygeneruje text článku na jeho pôvodnú/aktuálnu tému */
	public static function regenerate_text( $post_id ) {
		if ( ! O3DAI_License::has_api_key() ) {
			o3dai_log( 'Regenerácia textu #' . intval( $post_id ) . ': ' . o3dai_t( 'no_api_key' ) );
			return new WP_Error( 'o3dai', o3dai_t( 'no_api_key' ) );
		}
		o3dai_log( 'Regenerujem text článku #' . intval( $post_id ) . '…' );
		$topic       = get_the_title( $post_id );
		$s           = o3dai_get_settings();
		$ctx         = self::site_context();
		$series_note = ''; // regenerácia textu nemá sériu

		// Krok 1: osnova (ak je zapnutá)
		$outline = '';
		if ( ! empty( $s['use_outline'] ) ) {
			$outline = self::make_outline( $topic, $ctx, $series_note );
			if ( $outline ) {
				o3dai_log( 'Osnova pripravená pre: ' . $topic );
			}
		}

		$system = "Si skúsený slovenský copywriter a SEO špecialista. "
			. $s['instructions'] . " "
			. "Odpovedaj PRESNE v tomto formáte s oddeľovačmi, bez akéhokoľvek textu pred či po:\n"
			. "===TITLE===\n(titulok článku)\n"
			. "===EXCERPT===\n(1-2 vety zhrnutia)\n"
			. "===META_TITLE===\n(max 58 znakov, končí ' | " . $s['brand_name'] . "')\n"
			. "===META_DESC===\n(max 155 znakov)\n"
			. "===FOCUS_KW===\n(2-4 slová)\n"
			. "===IMAGE_SUBJECT===\n(in ENGLISH: 1 short phrase, 1-2 concrete " . ( $s['image_subject_hint'] ?? 'characters' ) . " that fit this article, with colors)\n"
			. "===CONTENT===\n"
			. "(HTML článku: úvod <p class=\"o3d-lead\">, sekcie <h2>, text <p>, zoznamy <ul>, aspoň jeden <div class=\"o3d-tip\">, jeden <div class=\"o3d-cta\">…<a class=\"o3d-cta-btn\" href=\"URL\">" . $s['cta_text'] . "</a></div>. POVINNÉ: 2-3 interné odkazy na produkty a 1 na iný článok.)\n"
			. "===END===";
		$outline = ! empty( $s['use_outline'] ) ? self::make_outline( $topic, $ctx ) : '';

		$user = "Téma článku: {$topic}\n\n"
			. ( $outline ? "=== OSNOVA (drž sa jej, každý riadok = jedna <h2> sekcia) ===\n" . $outline . "\n\n" : '' )
			. "Web: {$ctx['site']}\n\n"
			. "=== PRODUKTY ===\n" . implode( "\n", array_slice( $ctx['products'], 0, 25 ) ) . "\n\n"
			. "=== ČLÁNKY ===\n" . implode( "\n", $ctx['posts'] ) . "\n\n"
			. "Napíš NOVÚ verziu článku na túto tému, iný uhol/štruktúra než predtým.";

		$raw = O3DAI_Providers::generate( $system, $user, 8000 );
		if ( is_wp_error( $raw ) ) { return $raw; }
		$a = self::parse_markers( $raw );
		if ( empty( $a['TITLE'] ) || empty( $a['CONTENT'] ) ) {
			return new WP_Error( 'o3dai', 'Regenerácia: odpoveď sa nepodarilo naparsovať.' );
		}
		wp_update_post( array(
			'ID'           => $post_id,
			'post_title'   => wp_strip_all_tags( $a['TITLE'] ),
			'post_content' => wp_kses_post( $a['CONTENT'] ),
			'post_excerpt' => sanitize_text_field( $a['EXCERPT'] ?? '' ),
		) );
		O3DAI_SEO::set_post( $post_id, $a['META_TITLE'] ?? '', $a['META_DESC'] ?? '', $a['FOCUS_KW'] ?? '' );
		if ( ! empty( $a['IMAGE_SUBJECT'] ) ) { update_post_meta( $post_id, '_o3dai_subject', sanitize_text_field( $a['IMAGE_SUBJECT'] ) ); }
		o3dai_log( 'Pregenerovaný text článku #' . $post_id );
		return $post_id;
	}

	/** Vytiahne JSON aj z odpovede obalenej v ```json ... ``` */
	public static function parse_json( $raw ) {
		$raw = trim( (string) $raw );
		$raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
		$raw = preg_replace( '/\s*```$/', '', $raw );
		$data = json_decode( $raw, true );
		if ( null === $data ) {
			// posledný pokus: nájdi prvý { alebo [ a posledný } alebo ]
			$start = strcspn( $raw, '{[' );
			$data  = json_decode( substr( $raw, $start ), true );
		}
		return $data;
	}
}
