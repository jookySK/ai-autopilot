<?php
/**
 * Plugin Name: Jookas AI Autopilot
 * Description: Automatically generates and publishes SEO articles and product content with AI (Claude / OpenAI / Gemini). Learns from your existing content, adds internal links, optimized images (AI or free stock), and SEO fields (Yoast, Rank Math, SEOPress, AIOSEO).
 * Version: 1.9.14
 * Author: Jookas
 * Text Domain: jookas-ai-autopilot
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }


define( 'O3DAI_VERSION', '1.9.14' );
// v1.7.0 – jediný build, všetky funkcie sú free; Freemius SDK odstránený (flag zostáva pre kompatibilitu volaní).
define( 'O3DAI_FREE_BUILD', true );
define( 'O3DAI_DIR', plugin_dir_path( __FILE__ ) );

require_once O3DAI_DIR . 'includes/class-i18n.php';
require_once O3DAI_DIR . 'includes/class-license.php';
require_once O3DAI_DIR . 'includes/class-providers.php';
require_once O3DAI_DIR . 'includes/class-seo.php';
require_once O3DAI_DIR . 'includes/class-stock.php';
// v1.7.0 – GSC a SERP sú súčasťou jedného free buildu — načítajú sa bezpodmienečne.
require_once O3DAI_DIR . 'includes/class-gsc.php';
require_once O3DAI_DIR . 'includes/class-serp.php';
require_once O3DAI_DIR . 'includes/class-generator.php';
require_once O3DAI_DIR . 'includes/class-admin.php';
// v1.7.0 – produktový modul je súčasťou jedného free buildu — načítava sa bezpodmienečne.
require_once O3DAI_DIR . 'includes/class-products.php';
require_once O3DAI_DIR . 'includes/class-progress.php';
require_once O3DAI_DIR . 'includes/class-wizard.php';

/**
 * v1.5.2 — Freemius lepiaca hláška „We made a few tweaks … Opt in to make AI Autopilot better!".
 *
 * Keď je licencia Pro už aktívna (vidno odznak PRO), táto hláška je len prečkaný
 * stav z aktivačného procesu (príznak require_license_activation v úložisku SDK)
 * a slúži ku ničomu — navyše v 1.5.0 viedol jej odkaz na neexistujúcu stránku
 * (page=ai-autopilot) a vrátil „Sorry, you are not allowed to access this page.".
 * Ak je Pro aktívne, hlášku preto ticho odstránime (jednorázovo — zmaže ju
 * aj z trvalého úložiska, kde si SDK hlášky udržiava).
 * Free používateľom hláška ostáva — pre nich je legitímna výzva na pripojenie.
 */
add_action( 'admin_init', 'o3dai_maybe_clear_optin_notice', 20 );

function o3dai_maybe_clear_optin_notice() {
	if ( ! is_admin() || ! class_exists( 'O3DAI_License' ) ) {
		return;
	}
	if ( ! function_exists( 'o3dai_fs' ) ) {
		return;
	}

	// Iba keď je v našich pravidlách Pro reálne aktívne (platná licencia / trial,
	// a nevypnuté simulate_free) — presne tak, ako to rozhoduje odznak PRO.
	if ( ! O3DAI_License::is_pro() ) {
		return;
	}

	$fs = o3dai_fs();

	// 1) Koreň príčiny: SDK pri aktivácii Pro ponechalo príznak
	//    require_license_activation = true. Ten je jediný, kto u registrovaného
	//    používateľa hlášku vôbec spúšťa. SDK samo takto príznaky resetuje
	//    (napr. po aktivácii licencie), my to robíme po aktivácii Pro.
	$storage = method_exists( $fs, 'get_storage' ) ? $fs->get_storage() : null;
	if ( $storage && true === $storage->require_license_activation ) {
		$storage->require_license_activation = false;
	}

	// 2) Odstránenie samotnej uloženej lepiacej hlášky (jej ID v SDK je
	//    'connect_account'). remove_sticky() ju zmaže aj z trvalého úložiska.
	if ( method_exists( $fs, 'remove_sticky' ) ) {
		$fs->remove_sticky( 'connect_account' );
	}
}

/** Predvolené nastavenia */
function o3dai_defaults() {
	return array(
		'enabled'        => 0,
		'provider'       => 'claude',
		'api_key_claude' => '',
		'api_key_openai' => '',
		'api_key_gemini' => '',
		'api_key_openrouter' => '',
		'model'          => 'claude-sonnet-5', // v1.6.11 – aktuálna generácia (staré modely ostávajú dostupné cez „Vlastný model…“)
		'publish_status' => 'draft',
		'hour'           => 7,
		'weekdays'       => '1,2,3,4,5,6,7',
		'category_id'    => 0,
		'featured_ids'   => '',
		'image_mode'     => 'ids',
		'image_model'    => 'gpt-image-2.5-flare', // v1.6.11 – aktuálna generácia (rýchly/bežný); Sunburst = kvalita
		// v1.9.0 – Lokálny PC: generovanie obrázkov cez Stability Matrix / ComfyUI
		'image_local_url'      => '',   // napr. http://192.168.x.x:8188 (ComfyUI)
		'image_local_model'    => '',   // checkpoint; prázdne = prvý dostupný zo servera
		'image_local_negative' => '',   // prázdne = default "text, watermark, logo"
		'image_local_steps'    => 20,
		'image_local_cfg'      => 7,
		'image_local_width'    => 1152,
		'image_local_height'   => 768,
		// v1.9.x – Lokálny PC pre text (Ollama / LM Studio)
		'text_local_url'     => '',
		'text_local_model'   => '',
		// v1.9.x – Lokálny PC pre text priamo cez ComfyUI (TextGenerate — Gemma 4 a pod.)
		'text_comfy_url'     => '', // prázdne = použiť image_local_url (ten istý ComfyUI)
		'text_comfy_model'   => '', // súbor z models/text_encoders; prázdne = auto (gemma4_e4b a pod.)
		'body_images'    => 0,
		'internal_linking' => 0,
		// v1.6.6 – produktové odkazy len pri reálnej relevance (vypnuté = CTA vedie na kontakt/úvodnú)
		'product_links'   => 1,
		'gsc_queries'    => '',
		'webhook_url'    => '',
		'webhook_products' => '',
		// --- Automatická propagácia produktov (v1.4.13) — až 3 denné časové okná (hodina 0-23, prázdne = vypnuté) ---
		'share_hour1'    => '',
		'share_hour2'    => '',
		'share_hour3'    => '',
		// --- AI text pre príspevky produktov (v1.4.14) ---
		'ai_social_text' => 1,
		'use_outline'    => 1,
		'proofread'      => 1,
		'gsc_client_id'  => '',
		'gsc_client_secret' => '',
		'gsc_site'       => '',
		'serper_key'     => '',
		'progress_mode'  => 'background',
		// --- Témy z internetu (aktuálne správy / RSS) ---
		'news_keywords'  => '', // prázdne = news témy sa nesťahujú; v UI je placeholder s príkladom
		'news_count'     => 8,
		'news_use_ai'    => 1,
		'news_context'   => '',
		// --- Zadarmo fotobanky (obrázky bez AI nákladov) ---
		'stock_provider'   => 'pexels',
		'stock_key_pexels' => '',
		'stock_key_pixabay' => '',
		'stock_query_extra' => '',
		// --- Optimalizácia obrázkov ---
		'optimize_images'  => 1,
		// --- BRAND (zovšeobecnené nastavenia) ---
		'brand_name'     => '',
		'brand_lang'     => 'English',
		'brand_lang_code' => 'en',
		'brand_desc'     => '',
		'cta_text'       => 'Buy now',
		'image_subjects' => "a cute cartoon character\na friendly mascot\na cheerful illustrated animal",
        'image_subject_hint' => 'friendly characters',
		'color_primary'  => '#3858e9',
		'color_accent'   => '#2bc6b4',
		'color_accent2'  => '#f5a623',
		'color_dark'     => '#1e1e2e',
		'onboarded'      => 0,
		'ui_lang'        => 'en',
		'simulate_free'  => 0,
		'image_style'    => '', // prázdne = prompt bez Style časti; v UI je placeholder s príkladom
		'instructions'   => "Write in a clear, friendly tone. 600-900 words, H2 subheadings, practical tips, and natural internal links.",
	);
}

function o3dai_get_settings() {
	$s = wp_parse_args( get_option( 'o3dai_settings', array() ), o3dai_defaults() );
	// migrácia zrušených Imagen ID na aktuálny Gemini image model (v1.6.11)
	if ( in_array( $s['image_model'], array( 'imagen-fast', 'imagen-standard', 'imagen-ultra', 'gemini-flash-image' ), true ) ) {
		$s['image_model'] = 'gemini-3-pro-image';
	}
	return $s;
}

/** Cron plánovanie */
register_activation_hook( __FILE__, 'o3dai_schedule' );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'o3dai_daily_event' );
	wp_clear_scheduled_hook( 'o3dai_gsc_weekly' );
	wp_clear_scheduled_hook( 'o3dai_share_slot_1' );
	wp_clear_scheduled_hook( 'o3dai_share_slot_2' );
	wp_clear_scheduled_hook( 'o3dai_share_slot_3' );
} );

function o3dai_schedule() {
	wp_clear_scheduled_hook( 'o3dai_daily_event' );
	$s    = o3dai_get_settings();
	$hour = intval( $s['hour'] );
	$ts   = mktime( $hour, 0, 0 );
	if ( $ts <= time() ) {
		$ts += DAY_IN_SECONDS;
	}
	wp_schedule_event( $ts, 'daily', 'o3dai_daily_event' );

	// Sprievodca len pri úplne novej inštalácii – existujúce weby nemá otravovať
	$existing = get_option( 'o3dai_settings' );
	if ( empty( $existing ) ) {
		add_option( 'o3dai_do_redirect', 1 );
	} elseif ( ! isset( $existing['onboarded'] ) || ! $existing['onboarded'] ) {
		// migrácia: web už plugin používal → považuj za nastavený
		$existing['onboarded'] = 1;
		update_option( 'o3dai_settings', $existing );
	}

	if ( ! wp_next_scheduled( 'o3dai_gsc_weekly' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'o3dai_gsc_weekly' );
	}

	// Automatická propagácia produktov (v1.4.13) — až 3 denné časové okná
	foreach ( array( 'share_hour1', 'share_hour2', 'share_hour3' ) as $o3d_sh ) {
		$o3d_hook = 'o3dai_share_slot_' . substr( $o3d_sh, -1 );
		$o3d_val  = trim( (string) ( $s[ $o3d_sh ] ?? '' ) );
		$o3d_next = wp_next_scheduled( $o3d_hook );
		if ( '' === $o3d_val ) {
			if ( $o3d_next ) { wp_unschedule_event( $o3d_next, $o3d_hook ); }
			continue;
		}
		$o3d_hour = max( 0, min( 23, intval( $o3d_val ) ) );
		$o3d_ts   = mktime( $o3d_hour, 0, 0 );
		if ( $o3d_ts <= time() ) {
			$o3d_ts += DAY_IN_SECONDS;
		}
		if ( $o3d_next && $o3d_next !== $o3d_ts ) {
			wp_unschedule_event( $o3d_next, $o3d_hook );
			$o3d_next = false;
		}
		if ( ! $o3d_next ) {
			wp_schedule_event( $o3d_ts, 'daily', $o3d_hook );
		}
	}
}

/* WordPress nemá 'weekly' interval natívne vo všetkých verziách – doplníme ho */
add_filter( 'cron_schedules', function ( $sch ) {
	if ( ! isset( $sch['weekly'] ) ) {
		$sch['weekly'] = array( 'interval' => WEEK_IN_SECONDS, 'display' => 'Raz týždenne' );
	}
	return $sch;
} );

add_action( 'o3dai_daily_event', function () {
	$s = o3dai_get_settings();
	if ( empty( $s['enabled'] ) ) {
		return;
	}
	// Vybrané dni v týždni (v1.4.10) — ak dnes nie je vybraný deň, beh sa pokojne preskočí
	$days  = o3dai_weekdays_list();
	if ( empty( $days ) ) {
		o3dai_log( o3dai_t( 'log_skip_noday' ) );
		return;
	}
	$today = intval( current_time( 'N' ) );
	if ( ! in_array( $today, $days, true ) ) {
		o3dai_log( o3dai_t( 'n_skip_day' ) . ' ' . o3dai_t( 'log_days_selected', array( 'x' => implode( ',', array_map( 'intval', $days ) ) ) ) );
		return;
	}
	O3DAI_Generator::run_daily();
} );

/* Automatická propagácia produktov (v1.4.13) — denné časové okná */
add_action( 'o3dai_share_slot_1', array( 'O3DAI_Generator', 'reshare_product' ) );
add_action( 'o3dai_share_slot_2', array( 'O3DAI_Generator', 'reshare_product' ) );
add_action( 'o3dai_share_slot_3', array( 'O3DAI_Generator', 'reshare_product' ) );

/** Vybrané dni v týždni pre autopilot (1=Pondelok … 7=Nedeľa). Poraz: každý deň. */
function o3dai_weekdays_list() {
	$s   = o3dai_get_settings();
	$raw = isset( $s['weekdays'] ) ? (string) $s['weekdays'] : '1,2,3,4,5,6,7';
	$days = array();
	foreach ( array_map( 'intval', explode( ',', $raw ) ) as $d ) {
		if ( $d >= 1 && $d <= 7 ) {
			$days[] = $d;
		}
	}
	sort( $days );
	return $days;
}

/** Časová pečiatka najbližšieho SKUTEČNÉho behu autopilotu (rešpektuje vybrané dni). 0 = žiaden. */
function o3dai_next_run_ts() {
	$s = o3dai_get_settings();
	if ( empty( $s['enabled'] ) ) {
		return 0;
	}
	$days = o3dai_weekdays_list();
	if ( empty( $days ) ) {
		return 0;
	}
	$base = wp_next_scheduled( 'o3dai_daily_event' );
	if ( ! $base ) {
		return 0;
	}
	for ( $i = 0; $i < 8; $i++ ) {
		$ts = $base + ( $i * DAY_IN_SECONDS );
		if ( in_array( intval( current_time( 'N', $ts ) ), $days, true ) ) {
			return $ts;
		}
	}
	return $base;
}

/** Uložené šablóny článkov (voľba o3dai_templates): pole array( name, data, ts ) */
function o3dai_templates() {
	$t = get_option( 'o3dai_templates', array() );
	return is_array( $t ) ? $t : array();
}

/** Polia nastavení, ktoré šablóna zachytáva (bez API kľúčov a bez brand farieb) */
function o3dai_template_fields() {
	return array(
		'instructions', 'provider', 'model', 'brand_lang', 'brand_lang_code',
		'publish_status', 'category_id', 'use_outline', 'proofread', 'internal_linking',
		'body_images', 'image_mode', 'image_model', 'image_style', 'stock_provider', 'stock_query_extra',
	);
}

/* Jednorazové behy na pozadí (spúšťané tlačidlami v admine) – chyby sa vždy píšu do logu */
add_action( 'o3dai_run_once', function () {
	$res = O3DAI_Generator::run_daily();
	if ( is_wp_error( $res ) ) { o3dai_log( o3dai_t( 'log_err_run', array( 'err' => $res->get_error_message() ) ) ); }
} );
add_action( 'o3dai_topics_once', function () {
	$res = O3DAI_Generator::generate_topics();
	if ( is_wp_error( $res ) ) { o3dai_log( o3dai_t( 'log_err_topics', array( 'err' => $res->get_error_message() ) ) ); }
} );
add_action( 'o3dai_series_once', function ( $theme ) {
	$res = O3DAI_Generator::generate_series( (string) $theme );
	if ( is_wp_error( $res ) ) { o3dai_log( o3dai_t( 'log_err_series', array( 'err' => $res->get_error_message() ) ) ); }
} );
// Návrhy tém a sérií bežia cez O3DAI_SERP (Pro modul – vo FREE buildi trieda nemusí existovať)
if ( class_exists( 'O3DAI_SERP' ) ) {
	add_action( 'o3dai_suggest_once', function () {
		$res = O3DAI_SERP::suggest_topics( 8 );
		if ( is_wp_error( $res ) ) { o3dai_log( o3dai_t( 'log_err_suggest', array( 'err' => $res->get_error_message() ) ) ); }
	} );
	add_action( 'o3dai_suggest_series_once', function () {
		$res = O3DAI_SERP::suggest_series( 5 );
		if ( is_wp_error( $res ) ) { o3dai_log( o3dai_t( 'log_err_suggest_series', array( 'err' => $res->get_error_message() ) ) ); }
	} );
}
add_action( 'o3dai_news_once', function () {
	$res = O3DAI_Generator::generate_news_topics();
	if ( is_wp_error( $res ) ) { o3dai_log( o3dai_t( 'log_err_news', array( 'err' => $res->get_error_message() ) ) ); }
} );
add_action( 'o3dai_regen_text', function ( $post_id ) {
	O3DAI_Generator::regenerate_text( intval( $post_id ) );
} );
add_action( 'o3dai_regen_image', function ( $post_id ) {
	O3DAI_Generator::regenerate_image( intval( $post_id ) );
} );

/* IndexNow: sprístupni overovací kľúč na /{key}.txt */
add_action( 'init', function () {
	$key = get_option( 'o3dai_indexnow_key' );
	if ( $key ) {
		$req = trim( (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );
		if ( $req === $key . '.txt' ) {
			header( 'Content-Type: text/plain' );
			echo esc_html( $key );
			exit;
		}
	}
} );

/* Návrat z Google OAuth prihlásenia (Pro modul – trieda existuje len v PRO buildi) */
if ( class_exists( 'O3DAI_GSC' ) ) {
	add_action( 'admin_init', array( 'O3DAI_GSC', 'handle_callback' ) );
}

/* Týždenné sťahovanie fráz zo Search Console (Pro modul) */
add_action( 'o3dai_gsc_weekly', function () {
	if ( class_exists( 'O3DAI_GSC' ) && O3DAI_GSC::is_connected() ) {
		O3DAI_GSC::fetch_queries();
	}
} );
if ( class_exists( 'O3DAI_GSC' ) ) {
	add_action( 'o3dai_gsc_once', function () {
		O3DAI_GSC::fetch_queries();
	} );
}

/* Schvaľovací odkaz z e-mailu — identifikácia cez jednorazový token o3dai_tok, nie cez admin nonce. */
/* phpcs:disable WordPress.Security.NonceVerification.Recommended -- one-time approval token from e-mail */
add_action( 'init', function () {
	if ( isset( $_GET['o3dai_approve'], $_GET['o3dai_tok'] ) ) {
		O3DAI_Generator::handle_approval(
			intval( $_GET['o3dai_approve'] ),
			sanitize_text_field( wp_unslash( $_GET['o3dai_tok'] ) ),
			sanitize_key( $_GET['o3dai_do'] ?? 'publish' )
		);
	}
} );
/* phpcs:enable WordPress.Security.NonceVerification.Recommended */

/* Nový publikovaný produkt → webhook na sociálne siete (len raz) */
add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( 'product' !== $post->post_type ) {
		return;
	}
	if ( 'publish' !== $new || 'publish' === $old ) {
		return; // len skutočný prechod do publikovaného
	}
	if ( get_post_meta( $post->ID, '_o3dai_social_posted', true ) ) {
		return; // už postnuté
	}
	$s = o3dai_get_settings();
	if ( empty( $s['webhook_products'] ) ) {
		return;
	}
	update_post_meta( $post->ID, '_o3dai_social_posted', 1 );
	update_post_meta( $post->ID, '_o3dai_shared', time() ); // v1.4.13 — produkt je už propagovaný, reshare ho nepoužije
	O3DAI_Generator::send_product_webhook( $post->ID );
}, 10, 3 );

/* Frontend CSS pre pekné bloky v článkoch (v1.6.0 – správne wp_enqueue_style) */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	wp_enqueue_style( 'o3dai-article', plugins_url( 'assets/css/article.css', __FILE__ ), array(), O3DAI_VERSION );
	$bs = o3dai_get_settings();
	wp_add_inline_style(
		'o3dai-article',
		':root{--o3d-primary:' . esc_html( $bs['color_primary'] ) . ';--o3d-accent:' . esc_html( $bs['color_accent'] ) . ';--o3d-accent2:' . esc_html( $bs['color_accent2'] ) . ';--o3d-dark:' . esc_html( $bs['color_dark'] ) . ';}'
	);
} );

/* v1.6.0 – admin CSS/JS cez wp_enqueue_* (wp.org plugin-check); len na našej stránke nastavení */
add_action( 'admin_enqueue_scripts', function () {
	if ( ! isset( $_GET['page'] ) || 'o3dai' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin page detection, not form data
		return;
	}
	wp_enqueue_style( 'o3dai-admin', plugins_url( 'assets/css/admin.css', __FILE__ ), array(), O3DAI_VERSION );

	$o3d_tab_keep = '';
	if ( isset( $_POST['o3d_tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read again in handle_save() where the nonce is checked
		$o3d_tab_keep = sanitize_key( wp_unslash( $_POST['o3d_tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in handle_save(); re-read only to keep the active tab after redirect
		if ( ! in_array( $o3d_tab_keep, array( 'overview', 'topics', 'products', 'settings', 'test', 'debug' ), true ) ) {
			$o3d_tab_keep = '';
		}
	}
	wp_enqueue_script( 'o3dai-tabs', plugins_url( 'assets/js/tabs.js', __FILE__ ), array(), O3DAI_VERSION, true );
	wp_add_inline_script( 'o3dai-tabs', 'window.O3DAI_TABS=' . wp_json_encode( array( 'keep' => $o3d_tab_keep ) ), 'before' ); // v1.6.10 – config MUSÍ byť pred JS: default "after" sa vypíše po spustení IIFE, ktoré cfg hneď číta

	$o3d_s = o3dai_get_settings();
	wp_enqueue_script( 'o3dai-model', plugins_url( 'assets/js/model-select.js', __FILE__ ), array(), O3DAI_VERSION, true );
	wp_add_inline_script(
		'o3dai-model',
		'window.O3DAI_MODEL_CFG=' . wp_json_encode(
			array(
				'provId'      => 'o3d-provider',
				'modelId'     => 'o3d-model',
				'models'      => o3dai_models(),
				'current'     => (string) $o3d_s['model'],
				'customLabel'  => o3dai_t( 'model_custom' ),
				'customSuffix' => o3dai_t( 'model_custom_suffix' ), // v1.6.13 – prívlastok k vlastnému modelu (bol napevno SK aj v JS)
				// v1.9.x – lokálni provideri: dropdown modelov sa doplní reálnym zoznamom zo servera (ComfyUI / Ollama / LM Studio)
				'localAjax'   => array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonce'        => wp_create_nonce( 'o3dai_local' ),
					'loadingLabel' => o3dai_t( 'mdl_loading' ),
					'providers'    => array(
						'comfyui'  => array( 'action' => 'o3dai_text_comfy_models', 'urlInput' => 'text_comfy_url', 'fallbackUrlInput' => 'image_local_url', 'mirrorTo' => 'select[name="text_comfy_model"]' ),
						'local'    => array( 'action' => 'o3dai_local_text_models', 'urlInput' => 'text_local_url', 'mirrorTo' => 'select[name="text_local_model"]' ),
					),
				),
			)
		),
		'before' // v1.6.10 – config pred spustením model-select.js (default "after" = po spustení IIFE → dropdown bol vždy prázdny)
	);

	wp_enqueue_script( 'o3dai-news-suggest', plugins_url( 'assets/js/news-suggest.js', __FILE__ ), array(), O3DAI_VERSION, true );
	wp_add_inline_script(
		'o3dai-news-suggest',
		'window.O3DAI_NEWS_SUGGEST=' . wp_json_encode(
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'action' => 'o3dai_news_kw_suggest',
				'nonce'  => wp_create_nonce( 'o3dai' ),
				'i18n'   => array(
					'loading' => o3dai_t( 'news_suggest_loading' ),
					'done'    => o3dai_t( 'news_suggest_done' ),
					'error'   => o3dai_t( 'news_suggest_error' ),
				),
			)
		),
		'before' // v1.6.10 – config pred spustením news-suggest.js (rovnaký dôvod ako model-select; inak XHR letí do undefined URL)
	);
} );

/** Aktuálne odporúčané textové modely podľa providera (09/2026).
 *  „…-latest" aliasy ukazujú na najnovší stabilný model, takže sa nezastarajú. */
function o3dai_models() {
	// v1.6.13 – popisky cez i18n (o3dai_t): predtým boli napevno slovensky aj pri EN rozhraní
	return array(
		'claude' => array(
			'claude-sonnet-5'   => o3dai_t( 'mdl_claude_sonnet5' ),
			'claude-fable-5-1'  => o3dai_t( 'mdl_claude_fable5' ),
			'claude-opus-5'     => o3dai_t( 'mdl_claude_opus5' ),
			'claude-haiku-4-5'  => o3dai_t( 'mdl_claude_haiku45' ),
		),
		'openai' => array(
			'gpt-5.6-terra'     => o3dai_t( 'mdl_gpt_terra' ),
			'gpt-6-astra'       => o3dai_t( 'mdl_gpt_astra' ),
			'gpt-5.6-sol'       => o3dai_t( 'mdl_gpt_sol' ),
			'gpt-5.6-luna'      => o3dai_t( 'mdl_gpt_luna' ),
		),
		'gemini' => array(
			'gemini-flash-latest'    => o3dai_t( 'mdl_gem_flash_latest' ),
			'gemini-3.8-flash'       => o3dai_t( 'mdl_gem_38flash' ),
			'gemini-flash-lite-latest' => o3dai_t( 'mdl_gem_flashlite' ),
		),
		'openrouter' => array(
			'openrouter/auto'                   => o3dai_t( 'mdl_or_auto' ),
			'anthropic/claude-sonnet-5'         => o3dai_t( 'mdl_or_sonnet5' ),
			'anthropic/claude-haiku-4.5'        => o3dai_t( 'mdl_or_haiku45' ),
			'openai/gpt-6-astra'                => o3dai_t( 'mdl_or_astra' ),
			'google/gemini-2.5-flash'           => o3dai_t( 'mdl_or_gem25flash' ),
			'meta-llama/llama-3.3-70b-instruct' => o3dai_t( 'mdl_or_llama33' ),
			'deepseek/deepseek-chat'            => o3dai_t( 'mdl_or_deepseek' ),
		),
		// v1.9.x – Lokálny PC (Ollama / LM Studio) — predvoľby; ľubovoľný iný model cez pole URL/model v Nastaveniach
		'local' => array(
			'qwen3:14b'         => o3dai_t( 'mdl_local_qwen3_14b' ),
			'gemma3:27b-it-qat' => o3dai_t( 'mdl_local_gemma_27b' ),
			'deepseek-r1:14b'   => o3dai_t( 'mdl_local_deepseek_r1' ),
			'llama3.1:8b'       => o3dai_t( 'mdl_local_llama_8b' ),
		),
		// v1.9.x – Lokálny PC cez ComfyUI (TextGenerate): konkrétny súbor sa vyberá z models/text_encoders priamo na serveri
		'comfyui' => array(
			''                  => o3dai_t( 'mdl_comfy_auto' ), // auto: gemma4_e4b → gemma4_e2b → qwen_3_ → gemma_3 (prvý nájdený)
		),
	);
}

/** Cenník ($ za milión tokenov pre text, $ za obrázok) */
function o3dai_prices() {
	return array(
		'text' => array( // [input, output] za 1M tokenov
			'claude-sonnet-5'         => array( 3.0, 15.0 ),
			'claude-fable-5-1'        => array( 10.0, 50.0 ),
			'claude-opus-5'           => array( 5.0, 25.0 ),
			'gpt-6-astra'             => array( 10.0, 50.0 ),
			'gpt-5.6-sol'             => array( 4.0, 20.0 ),
			'gpt-5.6-terra'           => array( 2.0, 12.0 ),
			'gpt-5.6-luna'            => array( 0.2, 1.2 ),
			'gemini-flash-latest'     => array( 1.5, 7.5 ),
			'gemini-3.8-flash'        => array( 1.5, 7.5 ),
			'gemini-flash-lite-latest' => array( 0.3, 2.5 ),
			'openrouter/auto'                   => array( 3.0, 15.0 ),
			'anthropic/claude-sonnet-5'         => array( 3.0, 15.0 ),
			'anthropic/claude-haiku-4.5'        => array( 1.0, 5.0 ),
			'openai/gpt-6-astra'                => array( 10.0, 50.0 ),
			'google/gemini-2.5-flash'           => array( 0.3, 2.5 ),
			'meta-llama/llama-3.3-70b-instruct' => array( 0.3, 1.2 ),
			'deepseek/deepseek-chat'            => array( 0.3, 1.2 ),
			'_default'          => array( 3.0, 15.0 ),
		),
		'image' => array( // $ za obrázok (odhady, v1.6.11 – aktuálna generácia)
			'gpt-image-2.5-sunburst'         => 0.06,
			'gpt-image-2.5-flare'            => 0.015,
			'gemini-3-pro-image'             => 0.04,
			'gemini-3.1-flash-image-preview' => 0.045,
			'_default'                       => 0.04,
		),
	);
}

function o3dai_usage_key() {
	return 'o3dai_usage_' . gmdate( 'Y-m' );
}

function o3dai_track_text( $provider, $model, $in, $out ) {
	$p   = o3dai_prices();
	$rate = $p['text'][ $model ] ?? $p['text']['_default'];
	$cost = ( $in / 1e6 ) * $rate[0] + ( $out / 1e6 ) * $rate[1];
	$u = get_option( o3dai_usage_key(), array() );
	$u['text_calls']  = ( $u['text_calls'] ?? 0 ) + 1;
	$u['in_tokens']   = ( $u['in_tokens'] ?? 0 ) + $in;
	$u['out_tokens']  = ( $u['out_tokens'] ?? 0 ) + $out;
	$u['text_cost']   = ( $u['text_cost'] ?? 0 ) + $cost;
	update_option( o3dai_usage_key(), $u, false );
}

function o3dai_track_kind( $kind ) {
	$u = get_option( o3dai_usage_key(), array() );
	$u[ $kind ] = ( $u[ $kind ] ?? 0 ) + 1;
	update_option( o3dai_usage_key(), $u, false );
}

function o3dai_track_image( $model, $cost = null ) {
	if ( null === $cost ) { // v1.9.0 – lokálne obrázky bežia s explicitnou cenou 0
		$p    = o3dai_prices();
		$cost = $p['image'][ $model ] ?? $p['image']['_default'];
	}
	$u = get_option( o3dai_usage_key(), array() );
	$u['images']     = ( $u['images'] ?? 0 ) + 1;
	$u['image_cost'] = ( $u['image_cost'] ?? 0 ) + $cost;
	update_option( o3dai_usage_key(), $u, false );
}

/** Log posledných behov */
function o3dai_log( $msg ) {
	$log   = get_option( 'o3dai_log', array() );
	$log[] = current_time( 'Y-m-d H:i' ) . ' — ' . $msg;
	update_option( 'o3dai_log', array_slice( $log, -20 ), false );
}
