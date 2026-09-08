<?php
/**
 * Plugin Name: Jookas AI Autopilot
 * Description: Automatically generates and publishes SEO articles and product content with AI (Claude / OpenAI / Gemini). Learns from your existing content, adds internal links, optimized images (AI or free stock), and SEO fields (Yoast, Rank Math, SEOPress, AIOSEO).
 * Version: 1.6.2
 * Author: Jookas
 * Text Domain: jookas-ai-autopilot
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Freemius SDK — bootstrap (v1.5.1).
 * Vložený AS IS z Freemius wizardu (plugin ID 38271, premium slug: ai-autopilot-pro).
 */
if ( function_exists( 'o3dai_fs' ) ) {
	o3dai_fs()->set_basename( true, __FILE__ );
} else {
	/**
	 * DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE
	 * `function_exists` CALL ABOVE TO PROPERLY WORK.
	 */
	if ( ! function_exists( 'o3dai_fs' ) ) {
    // Create a helper function for easy SDK access.
    function o3dai_fs() {
        global $o3dai_fs;

        if ( ! isset( $o3dai_fs ) ) {
            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

            $o3dai_fs = fs_dynamic_init( array(
                'id'                  => '38271',
                'slug'                => 'ai-autopilot',
                'premium_slug'        => 'ai-autopilot-pro',
                'type'                => 'plugin',
                'public_key'          => 'pk_f5c0b5d094276b186cc6f5e601ac4',
                'is_premium'          => true,
                'premium_suffix'      => 'Pro',
                // If your plugin is a serviceware, set this option to false.
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                // Automatically removed in the free version. If you're not using the
                // auto-generated free version, delete this line before uploading to wp.org.
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'trial'               => array(
                    'days'               => 14,
                    'is_require_payment' => false,
                ),
                'menu'                => array(
                    // Náš skutočný menu slug (add_menu_page v class-admin.php), nie slug pluginu.
                    'slug'           => 'o3dai',
                ),
            ) );
        }

        return $o3dai_fs;
    }

    // Init Freemius.
    o3dai_fs();
    // Signal that SDK was initiated.
		do_action( 'o3dai_fs_loaded' );
	}
}


define( 'O3DAI_VERSION', '1.6.2' );
// v1.6.0 – FREE build (wp.org) má true; build skript to prepne. Pro moduly sa vo FREE zipi fyzicky neposielajú.
define( 'O3DAI_FREE_BUILD', true );
define( 'O3DAI_DIR', plugin_dir_path( __FILE__ ) );

require_once O3DAI_DIR . 'includes/class-i18n.php';
require_once O3DAI_DIR . 'includes/class-license.php';
require_once O3DAI_DIR . 'includes/class-providers.php';
require_once O3DAI_DIR . 'includes/class-seo.php';
require_once O3DAI_DIR . 'includes/class-stock.php';
// v1.6.0 – GSC a SERP sú Pro moduly; vo FREE buildi (wp.org) sa tieto súbory neposielajú,
// aby tu neboli lokálne funkcie „zamknuté za licenčiou“ (trialware pravidlo directory).
if ( ! O3DAI_License::is_free_build() ) {
	require_once O3DAI_DIR . 'includes/class-gsc.php';
	require_once O3DAI_DIR . 'includes/class-serp.php';
}
require_once O3DAI_DIR . 'includes/class-generator.php';
require_once O3DAI_DIR . 'includes/class-admin.php';
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
		'model'          => 'claude-sonnet-4-6',
		'publish_status' => 'draft',
		'hour'           => 7,
		'weekdays'       => '1,2,3,4,5,6,7',
		'category_id'    => 0,
		'featured_ids'   => '',
		'image_mode'     => 'ids',
		'image_model'    => 'gpt-image-1',
		'body_images'    => 0,
		'internal_linking' => 0,
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
		'news_keywords'  => 'best, top 10, tips, guide',
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
		'image_style'    => 'Soft, friendly illustration, warm pastel palette, soft light, no text, no watermark.',
		'instructions'   => "Write in a clear, friendly tone. 600-900 words, H2 subheadings, practical tips, and natural internal links.",
	);
}

function o3dai_get_settings() {
	$s = wp_parse_args( get_option( 'o3dai_settings', array() ), o3dai_defaults() );
	// migrácia zrušených Imagen ID na funkčné Nano Banana
	if ( in_array( $s['image_model'], array( 'imagen-fast', 'imagen-standard', 'imagen-ultra', 'gemini-flash-image' ), true ) ) {
		$s['image_model'] = 'gemini-2.5-flash-image';
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
		o3dai_log( 'Preskočené — nie je vybraný žiadny deň v týždni.' );
		return;
	}
	$today = intval( current_time( 'N' ) );
	if ( ! in_array( $today, $days, true ) ) {
		o3dai_log( o3dai_t( 'n_skip_day' ) . ' (vybrané: ' . implode( ',', array_map( 'intval', $days ) ) . ')' );
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
	if ( is_wp_error( $res ) ) { o3dai_log( 'CHYBA (beh): ' . $res->get_error_message() ); }
} );
add_action( 'o3dai_topics_once', function () {
	$res = O3DAI_Generator::generate_topics();
	if ( is_wp_error( $res ) ) { o3dai_log( 'CHYBA (témy): ' . $res->get_error_message() ); }
} );
add_action( 'o3dai_series_once', function ( $theme ) {
	$res = O3DAI_Generator::generate_series( (string) $theme );
	if ( is_wp_error( $res ) ) { o3dai_log( 'CHYBA (séria): ' . $res->get_error_message() ); }
} );
// Návrhy tém a sérií bežia cez O3DAI_SERP (Pro modul – vo FREE buildi trieda nemusí existovať)
if ( class_exists( 'O3DAI_SERP' ) ) {
	add_action( 'o3dai_suggest_once', function () {
		$res = O3DAI_SERP::suggest_topics( 8 );
		if ( is_wp_error( $res ) ) { o3dai_log( 'CHYBA (návrhy): ' . $res->get_error_message() ); }
	} );
	add_action( 'o3dai_suggest_series_once', function () {
		$res = O3DAI_SERP::suggest_series( 5 );
		if ( is_wp_error( $res ) ) { o3dai_log( 'CHYBA (návrhy sérií): ' . $res->get_error_message() ); }
	} );
}
add_action( 'o3dai_news_once', function () {
	$res = O3DAI_Generator::generate_news_topics();
	if ( is_wp_error( $res ) ) { o3dai_log( 'CHYBA (news): ' . $res->get_error_message() ); }
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
		if ( ! in_array( $o3d_tab_keep, array( 'overview', 'topics', 'products', 'settings', 'debug' ), true ) ) {
			$o3d_tab_keep = '';
		}
	}
	wp_enqueue_script( 'o3dai-tabs', plugins_url( 'assets/js/tabs.js', __FILE__ ), array(), O3DAI_VERSION, true );
	wp_add_inline_script( 'o3dai-tabs', 'window.O3DAI_TABS=' . wp_json_encode( array( 'keep' => $o3d_tab_keep ) ) );

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
				'customLabel' => o3dai_t( 'model_custom' ),
			)
		)
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
		)
	);
} );

/** Aktuálne odporúčané textové modely podľa providera (08/2026).
 *  „…-latest" aliasy ukazujú na najnovší stabilný model, takže sa nezastarajú. */
function o3dai_models() {
	return array(
		'claude' => array(
			'claude-sonnet-4-6'  => 'Claude Sonnet 4.6 (odporúčané)',
			'claude-haiku-4-5'   => 'Claude Haiku 4.5 (lacný, rýchly)',
			'claude-opus-4-8'    => 'Claude Opus 4.8 (najsilnejší)',
		),
		'openai' => array(
			'gpt-4o'             => 'GPT-4o (odporúčané)',
			'gpt-4o-mini'        => 'GPT-4o mini (lacný)',
			'gpt-4.1'            => 'GPT-4.1',
		),
		'gemini' => array(
			'gemini-flash-latest'    => 'Gemini Flash (najnovší, odporúčané)',
			'gemini-3.7-flash'       => 'Gemini 3.7 Flash',
			'gemini-3.6-flash'       => 'Gemini 3.6 Flash',
			'gemini-flash-lite-latest' => 'Gemini Flash-Lite (lacný)',
		),
		'openrouter' => array(
			'openrouter/auto'                   => 'Auto – najlepší model pre danú úlohu (odporúčané)',
			'anthropic/claude-sonnet-4.5'       => 'Claude Sonnet 4.5',
			'anthropic/claude-haiku-4.5'        => 'Claude Haiku 4.5 (lacný)',
			'openai/gpt-5'                      => 'GPT-5',
			'openai/gpt-4o'                     => 'GPT-4o',
			'google/gemini-2.5-flash'           => 'Gemini 2.5 Flash',
			'meta-llama/llama-3.3-70b-instruct' => 'Llama 3.3 70B (lacný)',
			'deepseek/deepseek-chat'            => 'DeepSeek (veľmi lacný)',
		),
	);
}

/** Cenník ($ za milión tokenov pre text, $ za obrázok) */
function o3dai_prices() {
	return array(
		'text' => array( // [input, output] za 1M tokenov
			'claude-sonnet-4-6' => array( 3.0, 15.0 ),
			'claude-haiku-4-5'  => array( 1.0, 5.0 ),
			'claude-opus-4-8'   => array( 5.0, 25.0 ),
			'gpt-4o'            => array( 2.5, 10.0 ),
			'gpt-4o-mini'       => array( 0.15, 0.6 ),
			'gpt-4.1'           => array( 2.0, 8.0 ),
			'gemini-flash-latest' => array( 1.5, 7.5 ),
			'gemini-3.7-flash'  => array( 1.5, 7.5 ),
			'gemini-3.6-flash'  => array( 1.5, 7.5 ),
			'gemini-flash-lite-latest' => array( 0.3, 2.5 ),
			'openrouter/auto'                   => array( 3.0, 15.0 ),
			'anthropic/claude-sonnet-4.5'       => array( 3.0, 15.0 ),
			'anthropic/claude-haiku-4.5'        => array( 1.0, 5.0 ),
			'openai/gpt-5'                      => array( 1.25, 10.0 ),
			'openai/gpt-4o'                     => array( 2.5, 10.0 ),
			'google/gemini-2.5-flash'           => array( 0.3, 2.5 ),
			'meta-llama/llama-3.3-70b-instruct' => array( 0.3, 1.2 ),
			'deepseek/deepseek-chat'            => array( 0.3, 1.2 ),
			'_default'          => array( 3.0, 15.0 ),
		),
		'image' => array( // $ za obrázok
			'gpt-image-1'                    => 0.04,
			'gpt-image-1-mini'               => 0.005,
			'gemini-2.5-flash-image'         => 0.039,
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

function o3dai_track_image( $model ) {
	$p    = o3dai_prices();
	$cost = $p['image'][ $model ] ?? $p['image']['_default'];
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
