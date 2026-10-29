<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class O3DAI_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		// uloženie nastavení — spracuje sa PRED výstupom stránky (redirect musí ísť pred head)
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
		// akcia v riadku článku
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_action_o3dai_reimg', array( __CLASS__, 'handle_row_reimg' ) );
		// hromadná akcia
		add_filter( 'bulk_actions-edit-post', array( __CLASS__, 'bulk_register' ) );
		add_filter( 'handle_bulk_actions-edit-post', array( __CLASS__, 'bulk_handle' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
		// AJAX: návrh news kľúčových slov podľa webu
		add_action( 'wp_ajax_o3dai_news_kw_suggest', array( __CLASS__, 'suggest_news_keywords' ) );
		// v1.8.0 – AJAX: prepis jednej sekcie článku (náhľad)
		add_action( 'wp_ajax_o3dai_regen_section', array( __CLASS__, 'ajax_regen_section' ) );
		// v1.9.0 – AJAX: zoznam checkpointov z lokálneho ComfyUI (Stability Matrix)
		add_action( 'wp_ajax_o3dai_local_models', array( __CLASS__, 'ajax_local_models' ) );
		add_action( 'wp_ajax_o3dai_test_image', array( __CLASS__, 'ajax_test_image' ) );
		// v1.9.x – AJAX: pregenerovanie ilustračného obrázka podľa obsahu článku
		add_action( 'wp_ajax_o3dai_regen_image', array( __CLASS__, 'ajax_regen_image' ) );
		// v1.9.x – AJAX: zoznam textových modelov z lokálneho PC (Ollama / LM Studio)
		add_action( 'wp_ajax_o3dai_local_text_models', array( __CLASS__, 'ajax_local_text_models' ) );
		// v1.9.x – AJAX: zoznam textových modelov priamo zo ComfyUI (models/text_encoders)
		add_action( 'wp_ajax_o3dai_text_comfy_models', array( __CLASS__, 'ajax_text_comfy_models' ) );
	}

	/** Uloženie nastavení — beží na admin_init, teda PRED výstupom admin stránky.
	 *  Keby sa spracovalo pri renderi (v page()), redirect zlyhá: „headers already sent“,
	 *  lebo head stránky (vrátane @font-face) je už odoslaný. */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = isset( $_POST['o3dai_action'] ) ? sanitize_key( $_POST['o3dai_action'] ) : '';
		if ( ! in_array( $action, array( 'save', 'save_webhook_products', 'test_webhook', 'test_webhook_products', 'share_now', 'save_template', 'apply_template', 'del_template', 'approve_proposal', 'reject_proposal', 'remove_proposal', 'approve_all_proposals' ), true ) ) {
			return;
		}
		// Do ktorej záložky sa vrátiť po uložení (o3d_tab z formulára)
		$tab = isset( $_POST['o3d_tab'] ) ? sanitize_key( wp_unslash( $_POST['o3d_tab'] ) ) : '';
		if ( ! in_array( $tab, array( 'overview', 'topics', 'products', 'settings', 'debug' ), true ) ) {
			$tab = 'settings';
		}
		if ( ! check_admin_referer( 'o3dai' ) ) {
			return;
		}
		$s = o3dai_get_settings();
		if ( 'save_webhook_products' === $action ) {
			// Malý nezávislý formulár (záložka Produkty) — uloží sa len toto pole, ostatné nastavenia sa nedotknú
			$s['webhook_products'] = esc_url_raw( wp_unslash( $_POST['webhook_products'] ?? $s['webhook_products'] ) );
			update_option( 'o3dai_settings', $s, false );
			if ( class_exists( 'O3DAI_I18n' ) ) { O3DAI_I18n::reset(); }
			wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_saved=1&o3d_tab=products' ) );
			exit;
		}
		// Test webhooku (v1.4.13) — blokuje, vráti HTTP kód; testuje aj ešte neuloženú hodnotu z formulára
		if ( 'test_webhook' === $action || 'test_webhook_products' === $action ) {
			$kind   = ( 'test_webhook_products' === $action ) ? 'products' : 'articles';
			$field  = ( 'products' === $kind ) ? 'webhook_products' : 'webhook_url';
			$test_url = isset( $_POST[ $field ] ) ? esc_url_raw( wp_unslash( $_POST[ $field ] ) ) : '';
			$res = O3DAI_Generator::test_webhook( $kind, $test_url );
			if ( is_wp_error( $res ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_test=' . $kind . '&o3dai_testerr=' . rawurlencode( $res->get_error_message() ) . '&o3d_tab=settings' ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_test=' . $kind . '&o3dai_testcode=' . intval( $res ) . '&o3d_tab=settings' ) );
			}
			exit;
		}
		// Propagovať produkt teraz (v1.4.15) — manuálne okno, rovnaký postup ako plánované sloty
		if ( 'share_now' === $action ) {
			$res = O3DAI_Generator::reshare_product();
			$st  = (string) ( $res['status'] ?? 'skip_nohook' );
			if ( 'sent' === $st ) {
				wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_share=sent&o3dai_shareid=' . intval( $res['id'] ?? 0 ) . '&o3dai_sharetitle=' . rawurlencode( (string) ( $res['title'] ?? '' ) ) . '&o3d_tab=settings' ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_share=' . $st . '&o3d_tab=settings' ) );
			}
			exit;
		}
		// Šablóny článkov: aplikovanie / vymazanie (nezávislé malé formuláre mimo hlavného)
		if ( 'apply_template' === $action || 'del_template' === $action ) {
			$i    = intval( $_POST['tpl'] ?? -1 );
			$tpls = o3dai_templates();
			if ( array_key_exists( $i, $tpls ) ) {
				if ( 'apply_template' === $action ) {
					foreach ( (array) ( $tpls[ $i ]['data'] ?? array() ) as $k => $v ) {
						$s[ $k ] = $v;
					}
					update_option( 'o3dai_settings', $s, false );
				} else {
					unset( $tpls[ $i ] );
					$tpls = array_values( $tpls );
					update_option( 'o3dai_templates', $tpls, false );
				}
			}
			if ( class_exists( 'O3DAI_I18n' ) ) { O3DAI_I18n::reset(); }
			wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_tpl=' . ( 'apply_template' === $action ? 'applied' : 'deleted' ) . '&o3d_tab=settings' ) );
			exit;
		}
		// Hromadná úprava produktov — schválenie / odmietnutie / odstránenie návrhov (v1.4.11)
		if ( in_array( $action, array( 'approve_proposal', 'reject_proposal', 'remove_proposal' ), true ) ) {
			$pid  = intval( $_POST['prop'] ?? 0 );
			$flag = 'applied_err';
			if ( class_exists( 'O3DAI_Products' ) ) { // v1.6.7 – vo FREE buildi produktový modul neexistuje
				if ( 'approve_proposal' === $action && $pid && O3DAI_Products::apply_proposal( $pid ) ) {
					$flag = 'applied';
				} elseif ( 'reject_proposal' === $action && $pid ) {
					O3DAI_Products::reject_proposal( $pid );
					$flag = 'rejected';
				} elseif ( 'remove_proposal' === $action && $pid ) {
					O3DAI_Products::remove_proposal( $pid );
					$flag = 'removed';
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_prop=' . $flag . '&o3d_tab=products' ) );
			exit;
		}
		if ( 'approve_all_proposals' === $action && class_exists( 'O3DAI_Products' ) ) { // v1.6.7 – vo FREE buildi produktový modul neexistuje
			$ok = 0;
			foreach ( O3DAI_Products::pending_proposals() as $o3d_pid ) {
				if ( O3DAI_Products::apply_proposal( $o3d_pid ) ) { $ok++; }
			}
			wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_prop=applied_all&n=' . $ok . '&o3d_tab=products' ) );
			exit;
		}

		$s['enabled']        = isset( $_POST['enabled'] ) ? 1 : 0;
		$s['provider']       = sanitize_key( $_POST['provider'] ?? $s['provider'] );
		$s['api_key_claude'] = sanitize_text_field( wp_unslash( $_POST['api_key_claude'] ?? $s['api_key_claude'] ) );
		$s['api_key_openai'] = sanitize_text_field( wp_unslash( $_POST['api_key_openai'] ?? $s['api_key_openai'] ) );
		$s['api_key_gemini'] = sanitize_text_field( wp_unslash( $_POST['api_key_gemini'] ?? $s['api_key_gemini'] ) );
		$s['api_key_openrouter'] = sanitize_text_field( wp_unslash( $_POST['api_key_openrouter'] ?? $s['api_key_openrouter'] ) );
		// v1.9.x – Lokálny PC pre text (Ollama / LM Studio)
		$s['text_local_url']   = esc_url_raw( wp_unslash( $_POST['text_local_url'] ?? '' ) );
		$s['text_local_model'] = sanitize_text_field( wp_unslash( $_POST['text_local_model'] ?? '' ) );
		// v1.9.x – Lokálny PC pre text priamo cez ComfyUI (TextGenerate)
		$s['text_comfy_url']   = esc_url_raw( wp_unslash( $_POST['text_comfy_url'] ?? '' ) );
		$s['text_comfy_model'] = sanitize_text_field( wp_unslash( $_POST['text_comfy_model'] ?? '' ) );
		$s['model']          = sanitize_text_field( wp_unslash( $_POST['model'] ?? $s['model'] ) );
		$s['publish_status'] = sanitize_key( $_POST['publish_status'] ?? $s['publish_status'] );
		$s['hour']           = max( 0, min( 23, intval( $_POST['hour'] ?? $s['hour'] ) ) );
		// Dni v týždni (v1.4.10) — ak žiadny nebol zaškrtnutý, autopilot sa v daný deň nebeží
		$o3d_days = array();
		if ( isset( $_POST['weekdays'] ) && is_array( $_POST['weekdays'] ) ) {
			foreach ( array_map( 'intval', (array) $_POST['weekdays'] ) as $o3d_d ) {
				if ( $o3d_d >= 1 && $o3d_d <= 7 ) { $o3d_days[] = $o3d_d; }
			}
			sort( $o3d_days );
			$s['weekdays'] = implode( ',', $o3d_days );
		}
		$s['category_id']    = intval( $_POST['category_id'] ?? 0 );
		$s['featured_ids']   = sanitize_text_field( wp_unslash( $_POST['featured_ids'] ?? '' ) );
		$s['image_mode']     = in_array( sanitize_key( $_POST['image_mode'] ?? 'ids' ), array( 'ids', 'ai', 'local', 'stock', 'none' ), true ) ? sanitize_key( $_POST['image_mode'] ) : 'ids';
		$s['image_model']    = sanitize_text_field( wp_unslash( $_POST['image_model'] ?? $s['image_model'] ) );
		// v1.9.0 – Lokálny PC: Stability Matrix / ComfyUI
		$s['image_local_url']      = esc_url_raw( wp_unslash( $_POST['image_local_url'] ?? '' ) );
		$s['image_local_model']    = sanitize_text_field( wp_unslash( $_POST['image_local_model'] ?? '' ) );
		$s['image_local_negative'] = sanitize_text_field( wp_unslash( $_POST['image_local_negative'] ?? '' ) );
		$s['image_local_steps']    = min( 150, max( 1, intval( $_POST['image_local_steps'] ?? 20 ) ) );
		$s['image_local_cfg']      = min( 30, max( 1, (float) ( $_POST['image_local_cfg'] ?? 7 ) ) );
		$s['image_local_width']    = min( 2048, max( 64, intval( $_POST['image_local_width'] ?? 1152 ) ) );
		$s['image_local_height']   = min( 2048, max( 64, intval( $_POST['image_local_height'] ?? 768 ) ) );
		// Zadarmo fotobanky (režim „stock“) + optimalizácia obrázkov
		$s['stock_provider']    = in_array( sanitize_key( $_POST['stock_provider'] ?? 'pexels' ), array( 'pexels', 'pixabay', 'openverse' ), true ) ? sanitize_key( $_POST['stock_provider'] ) : 'pexels';
		$s['stock_key_pexels']  = sanitize_text_field( wp_unslash( $_POST['stock_key_pexels'] ?? '' ) );
		$s['stock_key_pixabay'] = sanitize_text_field( wp_unslash( $_POST['stock_key_pixabay'] ?? '' ) );
		$s['stock_query_extra'] = sanitize_text_field( wp_unslash( $_POST['stock_query_extra'] ?? '' ) );
		$s['optimize_images']   = isset( $_POST['optimize_images'] ) ? 1 : 0;
		$s['progress_mode']  = ( isset( $_POST['progress_mode'] ) && 'foreground' === $_POST['progress_mode'] ) ? 'foreground' : 'background';
		$s['ui_lang']        = ( isset( $_POST['ui_lang'] ) && 'sk' === $_POST['ui_lang'] ) ? 'sk' : 'en';
		$s['simulate_free']  = isset( $_POST['simulate_free'] ) ? 1 : 0;
		// brand
		$s['brand_name']     = sanitize_text_field( wp_unslash( $_POST['brand_name'] ?? $s['brand_name'] ) );
		$s['brand_desc']     = sanitize_text_field( wp_unslash( $_POST['brand_desc'] ?? $s['brand_desc'] ) );
		$s['brand_lang']     = sanitize_text_field( wp_unslash( $_POST['brand_lang'] ?? $s['brand_lang'] ) );
		$s['brand_lang_code'] = sanitize_text_field( wp_unslash( $_POST['brand_lang_code'] ?? $s['brand_lang_code'] ) );
		$s['cta_text']       = sanitize_text_field( wp_unslash( $_POST['cta_text'] ?? $s['cta_text'] ) );
		$s['image_subject_hint'] = sanitize_text_field( wp_unslash( $_POST['image_subject_hint'] ?? $s['image_subject_hint'] ) );
		$s['image_subjects'] = sanitize_textarea_field( wp_unslash( $_POST['image_subjects'] ?? $s['image_subjects'] ) );
		foreach ( array( 'color_primary', 'color_accent', 'color_accent2', 'color_dark' ) as $ck ) {
			if ( isset( $_POST[ $ck ] ) ) { $s[ $ck ] = sanitize_hex_color( wp_unslash( $_POST[ $ck ] ) ); }
		}
		$s['image_style']    = sanitize_textarea_field( wp_unslash( $_POST['image_style'] ?? $s['image_style'] ) );
		$s['body_images']      = max( 0, min( 2, intval( $_POST['body_images'] ?? $s['body_images'] ) ) );
		$s['internal_linking'] = isset( $_POST['internal_linking'] ) ? 1 : 0;
		$s['product_links']    = isset( $_POST['product_links'] ) ? 1 : 0;
		$s['use_outline']      = isset( $_POST['use_outline'] ) ? 1 : 0;
		$s['proofread']        = isset( $_POST['proofread'] ) ? 1 : 0;
		$s['gsc_queries']      = sanitize_textarea_field( wp_unslash( $_POST['gsc_queries'] ?? $s['gsc_queries'] ) );
		$s['webhook_url']      = esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? $s['webhook_url'] ) );
		$s['webhook_products'] = esc_url_raw( wp_unslash( $_POST['webhook_products'] ?? $s['webhook_products'] ) );
		// Automatická propagácia produktov (v1.4.13) — hodiny 0–23, prázdne = okno vypnuté
		foreach ( array( 'share_hour1', 'share_hour2', 'share_hour3' ) as $o3d_sh ) {
			$o3d_raw = sanitize_text_field( wp_unslash( $_POST[ $o3d_sh ] ?? '' ) );
			$s[ $o3d_sh ] = ( '' === $o3d_raw ) ? '' : max( 0, min( 23, intval( $o3d_raw ) ) );
		}
		// AI text pre príspevky produktov (v1.4.14)
		$s['ai_social_text'] = isset( $_POST['ai_social_text'] ) ? 1 : 0;
		$s['gsc_client_id']     = sanitize_text_field( wp_unslash( $_POST['gsc_client_id'] ?? $s['gsc_client_id'] ) );
		$s['gsc_client_secret'] = sanitize_text_field( wp_unslash( $_POST['gsc_client_secret'] ?? $s['gsc_client_secret'] ) );
		$s['gsc_site']          = sanitize_text_field( wp_unslash( $_POST['gsc_site'] ?? $s['gsc_site'] ) );
		$s['serper_key']        = sanitize_text_field( wp_unslash( $_POST['serper_key'] ?? $s['serper_key'] ) );
		$s['instructions']   = sanitize_textarea_field( wp_unslash( $_POST['instructions'] ?? $s['instructions'] ) );
		$s['news_keywords']  = sanitize_text_field( wp_unslash( $_POST['news_keywords'] ?? $s['news_keywords'] ) );
		$s['news_count']     = max( 3, min( 15, intval( $_POST['news_count'] ?? 8 ) ) );
		$s['news_use_ai']    = isset( $_POST['news_use_ai'] ) ? 1 : 0;
		$s['news_context']   = sanitize_textarea_field( wp_unslash( $_POST['news_context'] ?? $s['news_context'] ) );
		update_option( 'o3dai_settings', $s, false );
		o3dai_schedule();
		// Uložiť ako šablónu (v1.4.10) — zachytí len generovacie nastavenia, bez API kľúčov
		if ( 'save_template' === $action && '' !== sanitize_text_field( wp_unslash( $_POST['template_name'] ?? '' ) ) ) {
			$name = sanitize_text_field( wp_unslash( $_POST['template_name'] ) );
			$data = array();
			foreach ( o3dai_template_fields() as $f ) {
				if ( isset( $s[ $f ] ) ) { $data[ $f ] = $s[ $f ]; }
			}
			$tpls = o3dai_templates();
			foreach ( $tpls as $ti => $tt ) {
				if ( 0 === strcasecmp( (string) ( $tt['name'] ?? '' ), $name ) ) { unset( $tpls[ $ti ] ); break; }
			}
			$tpls[] = array( 'name' => $name, 'data' => $data, 'ts' => time() );
			update_option( 'o3dai_templates', array_slice( array_values( $tpls ), -10 ), false );
			if ( class_exists( 'O3DAI_I18n' ) ) { O3DAI_I18n::reset(); }
			wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_tpl=saved&o3d_tab=' . $tab ) );
			exit;
		}
		if ( class_exists( 'O3DAI_I18n' ) ) { O3DAI_I18n::reset(); }
		wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_saved=1&o3d_tab=' . $tab ) );
		exit;
	}

	/** Odkaz „AI obrázok" v riadku článku */
	public static function row_action( $actions, $post ) {
		if ( 'post' === $post->post_type && current_user_can( 'edit_post', $post->ID ) ) {
			$url = wp_nonce_url( admin_url( 'admin.php?action=o3dai_reimg&post=' . $post->ID ), 'o3dai_reimg_' . $post->ID );
			$actions['o3dai_reimg'] = '<a href="' . esc_url( $url ) . '">' . esc_html( o3dai_t( 'a_ai_image' ) ) . '</a>';
		}
		return $actions;
	}

	public static function handle_row_reimg() {
		$post_id = intval( $_GET['post'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! check_admin_referer( 'o3dai_reimg_' . $post_id ) ) {
			wp_die( 'Neplatná požiadavka.' );
		}
		wp_schedule_single_event( time() + 1, 'o3dai_regen_image', array( $post_id ) );
		spawn_cron();
		wp_safe_redirect( add_query_arg( array( 'o3dai_img' => '1' ), wp_get_referer() ?: admin_url( 'edit.php' ) ) );
		exit;
	}

	/** AJAX: návrh news kľúčových slov podľa webu (názov, popis, články) */
	public static function suggest_news_keywords() {
		check_ajax_referer( 'o3dai', 'nonce' );
		if ( ! O3DAI_License::has_api_key() ) {
			wp_send_json_error( array( 'message' => o3dai_t( 'no_api_key' ) ) );
		}
		$ctx    = O3DAI_Generator::site_context();
		$system = 'Si SEO stratég. Odpovedaj VÝHRADNE platným JSON poľom reťazcov, bez markdownu a komentárov.';
		$user   = 'Web: ' . $ctx['site'] . "\n";
		if ( ! empty( $ctx['titles'] ) ) {
			$user .= 'Už publikované články:\n- ' . implode( "\n- ", array_slice( $ctx['titles'], 0, 15 ) ) . "\n";
		}
		$user .= "Navrhni 5–6 krátkych kľúčových slov/fráz (1–3 slová) pre stahovanie aktuálnych správ, ktoré presne kopírujú tematickú oblasť tohto webu. Žiadne všeobecné slová ako „najnovšie“, „populárne“, „aktuality“. Vráť JSON pole reťazcov.";
		$raw = O3DAI_Providers::generate( $system, $user, 300 );
		if ( is_wp_error( $raw ) ) {
			wp_send_json_error( array( 'message' => $raw->get_error_message() ) );
		}
		$words  = array();
		$parsed = O3DAI_Generator::parse_json( $raw );
		$items  = is_array( $parsed ) ? $parsed : array( $parsed );
		foreach ( $items as $w ) {
			$w = trim( (string) $w );
			if ( '' === $w ) { continue; }
			// AI môže vrátiť zoznam ako jeden reťazec s čiarkami — rozoberieme
			foreach ( array_map( 'trim', explode( ',', $w ) ) as $part ) {
				$part = sanitize_text_field( $part );
				if ( '' === $part || in_array( $part, $words, true ) ) { continue; }
				$words[] = $part;
				if ( 8 <= count( $words ) ) { break 2; }
			}
		}
		if ( empty( $words ) ) {
			wp_send_json_error( array( 'message' => o3dai_t( 'news_suggest_failed' ) ) );
		}
		o3dai_log( o3dai_t( 'log_news_ai_kw', array( 'x' => implode( ', ', $words ) ) ) );
		wp_send_json_success( array( 'keywords' => implode( ', ', $words ) ) );
	}

	public static function bulk_register( $actions ) {
		$actions['o3dai_reimg_bulk'] = o3dai_t( 'a_bulk_reimg' );
		return $actions;
	}

	public static function bulk_handle( $redirect, $doaction, $ids ) {
		if ( 'o3dai_reimg_bulk' === $doaction ) {
			foreach ( $ids as $pid ) {
				wp_schedule_single_event( time() + 1, 'o3dai_regen_image', array( intval( $pid ) ) );
			}
			spawn_cron();
			$redirect = add_query_arg( 'o3dai_img', count( $ids ), $redirect );
		}
		return $redirect;
	}

	public static function bulk_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect flag set by our own nonce-protected admin action
		if ( ! empty( $_GET['o3dai_img'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( o3dai_t( 'n_img_bg' ) ) . '</p></div>';
		}
		// v1.7.0 – mäkký fair-use limit (všetko je teraz free) — neutrálna výzva bez odznaku PRO / odkazu na upgrade
		$o3d_limit = get_option( 'o3dai_limit_hit', '' );
		if ( $o3d_limit ) {
			$o3d_scr = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $o3d_scr && 'toplevel_page_o3dai' === $o3d_scr->id ) {
				if ( 'images' === $o3d_limit ) {
					$o3d_msg = o3dai_t( 'limit_hit_images_advisory', array( 'limit' => O3DAI_License::FREE_IMAGES ) );
				} elseif ( 'articles' === $o3d_limit ) {
					$o3d_msg = o3dai_t( 'limit_hit_articles_advisory', array( 'limit' => O3DAI_License::FREE_ARTICLES ) );
				} else {
					$o3d_msg = '';
				}
				if ( $o3d_msg ) {
					echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $o3d_msg ) . '</p></div>';
				}
			}
		}
	}

	public static function menu() {
		add_menu_page( 'AI Autopilot', 'AI Autopilot', 'manage_options', 'o3dai', array( __CLASS__, 'page' ), 'dashicons-superhero', 58 );
		// v1.8.0 – náhľad článku s možnosťou prepísať jednotlivé H2 sekcie
		add_submenu_page( 'o3dai', o3dai_t( 'preview_menu' ), o3dai_t( 'a_preview' ), 'manage_options', 'o3dai-article', array( __CLASS__, 'article_preview' ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Akcie
		if ( isset( $_POST['topic_op'] ) && check_admin_referer( 'o3dai' ) ) {
			list( $op, $i ) = array_pad( explode( ':', sanitize_text_field( wp_unslash( $_POST['topic_op'] ) ) ), 2, -1 );
			$i = intval( $i );
			$q = get_option( 'o3dai_topics', array() );
			if ( isset( $q[ $i ] ) ) {
				if ( 'up' === $op && $i > 0 ) {
					$tmp = $q[ $i - 1 ]; $q[ $i - 1 ] = $q[ $i ]; $q[ $i ] = $tmp;
				} elseif ( 'down' === $op && $i < count( $q ) - 1 ) {
					$tmp = $q[ $i + 1 ]; $q[ $i + 1 ] = $q[ $i ]; $q[ $i ] = $tmp;
				} elseif ( 'top' === $op ) {
					$item = $q[ $i ]; array_splice( $q, $i, 1 ); array_unshift( $q, $item );
				} elseif ( 'skip' === $op ) {
					$q[ $i ]['status'] = ( 'skipped' === $q[ $i ]['status'] ) ? 'pending' : 'skipped';
				} elseif ( 'del' === $op ) {
					array_splice( $q, $i, 1 );
				} elseif ( 'retext' === $op && ! empty( $q[ $i ]['post_id'] ) ) {
					wp_schedule_single_event( time() + 2, 'o3dai_regen_text', array( $q[ $i ]['post_id'] ) );
					spawn_cron();
				} elseif ( 'reimg' === $op && ! empty( $q[ $i ]['post_id'] ) ) {
					wp_schedule_single_event( time() + 2, 'o3dai_regen_image', array( $q[ $i ]['post_id'] ) );
					spawn_cron();
				}
				update_option( 'o3dai_topics', array_values( $q ), false );
			}
		}

		if ( isset( $_POST['o3dai_action'] ) && check_admin_referer( 'o3dai' ) ) {
			$action = sanitize_key( $_POST['o3dai_action'] );

			if ( in_array( $action, array( 'topics', 'run', 'suggest', 'suggest_series', 'series' ), true ) && ! O3DAI_License::has_api_key() ) {
				echo '<div class="notice notice-error"><p>' . esc_html( o3dai_t( 'no_api_key' ) ) . '</p></div>';
				$action = ''; // zastav, nespúšťaj úlohu
			}

			if ( 'topics' === $action ) {
				wp_schedule_single_event( time() + 2, 'o3dai_topics_once' );
				spawn_cron();
				echo '<div class="notice notice-info"><p>' . esc_html( o3dai_t( 'n_topics_bg' ) ) . '</p></div>';
			}

			if ( 'run' === $action ) {
				wp_schedule_single_event( time() + 2, 'o3dai_run_once' );
				spawn_cron();
				echo '<div class="notice notice-info"><p>' . esc_html( o3dai_t( 'n_article_bg' ) ) . '</p></div>';
			}

			if ( 'news' === $action ) {
				wp_schedule_single_event( time() + 2, 'o3dai_news_once' );
				spawn_cron();
				echo '<div class="notice notice-info"><p>' . esc_html( o3dai_t( 'n_news_bg' ) ) . '</p></div>';
			}

			if ( 'suggest' === $action ) {
				wp_schedule_single_event( time() + 2, 'o3dai_suggest_once' );
				spawn_cron();
				echo '<div class="notice notice-info"><p>' . esc_html( o3dai_t( 'n_suggest_bg' ) ) . '</p></div>';
			}

			if ( 'suggest_series' === $action ) {
				wp_schedule_single_event( time() + 2, 'o3dai_suggest_series_once' );
				spawn_cron();
				echo '<div class="notice notice-info"><p>' . esc_html( o3dai_t( 'n_suggest_series_bg' ) ) . '</p></div>';
			}

			if ( 'make_suggested_series' === $action && ! empty( $_POST['sugg_series'] ) ) {
				$theme = sanitize_text_field( wp_unslash( $_POST['sugg_series'] ) );
				wp_schedule_single_event( time() + 2, 'o3dai_series_once', array( $theme ) );
				spawn_cron();
				// odstráň z návrhov
				$ss = array_values( array_diff( get_option( 'o3dai_series_suggestions', array() ), array( $theme ) ) );
				update_option( 'o3dai_series_suggestions', $ss, false );
				echo '<div class="notice notice-info"><p>' . esc_html( o3dai_t( 'n_series_bg', array( 'theme' => $theme ) ) ) . '</p></div>';
			}

			if ( 'add_suggested' === $action && ! empty( $_POST['sugg'] ) ) {
				$chosen = array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['sugg'] ) );
				$q = get_option( 'o3dai_topics', array() );
				$exist = wp_list_pluck( $q, 'title' );
				$added = 0;
				foreach ( $chosen as $t ) {
					if ( $t && ! in_array( $t, $exist, true ) ) {
						$q[] = array( 'title' => $t, 'status' => 'pending', 'post_id' => 0 );
						$added ++;
					}
				}
				update_option( 'o3dai_topics', $q, false );
				// odstráň pridané z návrhov
				$sugg = array_values( array_diff( get_option( 'o3dai_topic_suggestions', array() ), $chosen ) );
				update_option( 'o3dai_topic_suggestions', $sugg, false );
				echo '<div class="notice notice-success"><p>' . esc_html( o3dai_t( 'n_added_count', array( 'n' => intval( $added ) ) ) ) . '</p></div>';
			}

			if ( 'gsc_fetch' === $action && class_exists( 'O3DAI_GSC' ) ) {
				$r = O3DAI_GSC::fetch_queries();
				echo is_wp_error( $r )
					? '<div class="notice notice-error"><p>' . esc_html( $r->get_error_message() ) . '</p></div>'
					: '<div class="notice notice-success"><p>' . esc_html( o3dai_t( 'n_fetched', array( 'n' => intval( $r ) ) ) ) . '</p></div>';
			}

			if ( 'gsc_disconnect' === $action ) {
				delete_option( 'o3dai_gsc_refresh_token' );
				delete_option( 'o3dai_gsc_access_token' );
				delete_option( 'o3dai_gsc_expires' );
				echo '<div class="notice notice-success"><p>' . esc_html( o3dai_t( 'gsc_disconnected_msg' ) ) . '</p></div>';
			}

			if ( 'series' === $action && ! empty( $_POST['series_theme'] ) ) {
				$theme = sanitize_text_field( wp_unslash( $_POST['series_theme'] ) );
				wp_schedule_single_event( time() + 2, 'o3dai_series_once', array( $theme ) );
				spawn_cron();
				echo '<div class="notice notice-info"><p>' . esc_html( o3dai_t( 'n_series_bg', array( 'theme' => $theme ) ) ) . '</p></div>';
			}

			if ( 'add_topic' === $action && ! empty( $_POST['new_topic'] ) ) {
				$q = get_option( 'o3dai_topics', array() );
				array_unshift( $q, array( 'title' => sanitize_text_field( wp_unslash( $_POST['new_topic'] ) ), 'status' => 'pending', 'post_id' => 0 ) );
				update_option( 'o3dai_topics', $q, false );
				echo '<div class="notice notice-success"><p>' . esc_html( o3dai_t( 'n_topic_added' ) ) . '</p></div>';
			}

			if ( 'clear_topics' === $action ) {
				update_option( 'o3dai_topics', array(), false );
				echo '<div class="notice notice-success"><p>' . esc_html( o3dai_t( 'n_queue_cleared' ) ) . '</p></div>';
			}
		}

		$s      = o3dai_get_settings();
		if ( ! empty( $_GET['o3dai_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( o3dai_t( 'n_saved' ) ) . '</p></div>';
		}
		// Výsledok testu webhooku (v1.4.13)
		if ( isset( $_GET['o3dai_test'] ) && in_array( sanitize_key( $_GET['o3dai_test'] ), array( 'articles', 'products' ), true ) ) {
			if ( ! empty( $_GET['o3dai_testcode'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( o3dai_t( 'test_ok', array( 'code' => intval( $_GET['o3dai_testcode'] ) ) ) ) . '</p></div>';
			} elseif ( isset( $_GET['o3dai_testerr'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( o3dai_t( 'test_fail', array( 'err' => sanitize_text_field( wp_unslash( $_GET['o3dai_testerr'] ) ) ) ) ) . '</p></div>';
			}
		}
		// Výsledok „Propagovať produkt teraz“ (v1.4.15)
		if ( isset( $_GET['o3dai_share'] ) && in_array( sanitize_key( $_GET['o3dai_share'] ), array( 'sent', 'skip_nohook', 'skip_notype', 'skip_done' ), true ) ) {
			$st = sanitize_key( $_GET['o3dai_share'] );
			if ( 'sent' === $st ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( o3dai_t( 'share_sent', array( 'id' => intval( $_GET['o3dai_shareid'] ?? 0 ), 'title' => sanitize_text_field( wp_unslash( $_GET['o3dai_sharetitle'] ?? '' ) ) ) ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( o3dai_t( 'share_' . $st ) ) . '</p></div>';
			}
		}
		if ( isset( $_GET['o3dai_tpl'] ) && in_array( sanitize_key( $_GET['o3dai_tpl'] ), array( 'saved', 'applied', 'deleted' ), true ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( o3dai_t( 'tpl_' . sanitize_key( $_GET['o3dai_tpl'] ) ) ) . '</p></div>';
		}

		// Hromadná úprava produktov — hlášky po akcii (v1.4.11)
		$o3d_prop = isset( $_GET['o3dai_prop'] ) ? sanitize_key( $_GET['o3dai_prop'] ) : '';
		if ( $o3d_prop && in_array( $o3d_prop, array( 'applied', 'applied_all', 'rejected', 'removed', 'ready', 'applied_err' ), true ) ) {
			$o3d_msg = ( 'applied_all' === $o3d_prop ) ? o3dai_t( 'prop_applied_all', array( 'n' => isset( $_GET['n'] ) ? intval( $_GET['n'] ) : 0 ) ) : o3dai_t( 'prop_' . $o3d_prop );
			$o3d_cls = ( 'applied_err' === $o3d_prop ) ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $o3d_cls ) . ' is-dismissible"><p>' . esc_html( $o3d_msg ) . '</p></div>';
		}
		// v1.9.x – živý stav bežiacej úlohy (generovanie článku/temy) – hore nad všetkými záložkami, auto-obnovenie cez AJAX
		$o3d_live = O3DAI_Progress::running();
		if ( $o3d_live ) {
			$o3d_ln   = wp_create_nonce( 'o3dai_progress' );
			$o3d_lurl = admin_url( 'admin-ajax.php?action=o3dai_running&nonce=' . rawurlencode( $o3d_ln ) );
			echo '<div class="notice notice-info" id="o3d-live-notice"><p><strong>' . esc_html( o3dai_t( 'live_title' ) ) . ':</strong> <span id="o3d-live-label">' . esc_html( $o3d_live['label'] ) . '</span> <span id="o3d-live-el" style="opacity:.65">(' . intval( time() - $o3d_live['ts'] ) . ' s)</span></p></div>';
			echo '<script>(function(){var a=' . wp_json_encode( $o3d_lurl ) . ',l=document.getElementById("o3d-live-label"),e=document.getElementById("o3d-live-el"),c=document.getElementById("o3d-live-notice");setInterval(function(){fetch(a,{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){if(d&&d.success&&d.data.label){l.textContent=d.data.label;e.textContent="("+d.data.elapsed+" s)";}else if(c&&c.parentNode){c.parentNode.removeChild(c);}}).catch(function(){});},10000);})();</script>';
		}

		$topics = get_option( 'o3dai_topics', array() );
		$log    = array_reverse( get_option( 'o3dai_log', array() ) );
		$next   = o3dai_next_run_ts();
		$u      = get_option( 'o3dai_usage_' . gmdate( 'Y-m' ), array() );
		// Ktorú záložku aktivovať po akcii / uložení (príde cez o3d_tab)
		$o3d_tab_keep = '';
		if ( isset( $_POST['o3d_tab'] ) ) {
			$o3d_tab_keep = sanitize_key( wp_unslash( $_POST['o3d_tab'] ) );
			if ( ! in_array( $o3d_tab_keep, array( 'overview', 'topics', 'products', 'settings', 'debug' ), true ) ) {
				$o3d_tab_keep = '';
			}
		}
		?>
		<div class="wrap o3d-wrap<?php echo O3DAI_License::is_free_build() ? ' o3d-free' : ''; ?>">
			<div class="o3d-hero">
				<h1>🤖 <?php echo esc_html( $s['brand_name'] ?? '' ); ?> <?php echo esc_html( o3dai_t( 'plugin_title' ) ); ?></h1>
				<div class="o3d-next">📅 <?php echo esc_html( o3dai_t( 'next_run' ) ); ?>: <strong><?php echo $next ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'j.n.Y H:i' ) ) : '—'; ?></strong></div>
			</div>

			
		<!-- Zalozky (v1.4.9) -->
		<div class="o3d-tabbar" role="tablist">
			<button type="button" class="o3d-tab" data-tab="overview"><span class="dashicons dashicons-dashboard"></span> <?php echo esc_html( o3dai_t( 'tab_overview' ) ); ?></button>
			<button type="button" class="o3d-tab" data-tab="topics"><span class="dashicons dashicons-visibility"></span> <?php echo esc_html( o3dai_t( 'tab_topics' ) ); ?></button>
			<button type="button" class="o3d-tab" data-tab="products"><span class="dashicons dashicons-products"></span> <?php echo esc_html( o3dai_t( 'tab_products' ) ); ?></button>
			<button type="button" class="o3d-tab" data-tab="settings"><span class="dashicons dashicons-admin-settings"></span> <?php echo esc_html( o3dai_t( 'tab_settings' ) ); ?></button>
			<button type="button" class="o3d-tab" data-tab="test"><span class="dashicons dashicons-experiments"></span> <?php echo esc_html( o3dai_t( 'tab_test' ) ); ?></button>
			<button type="button" class="o3d-tab" data-tab="debug"><span class="dashicons dashicons-search"></span> <?php echo esc_html( o3dai_t( 'tab_debug' ) ); ?></button>
		</div>

			<div class="o3d-tabpanel" data-panel="overview" role="tabpanel">
				<div class="o3d-actions-big">
				<form method="post" class="o3d-action-card">
					<?php wp_nonce_field( 'o3dai' ); ?>
					<input type="hidden" name="o3d_tab" value="overview">
					<h3><?php echo esc_html( o3dai_t( 'act_gen_now' ) ); ?></h3>
					<p class="o3d-act-desc"><?php echo esc_html( o3dai_t( 'act_run_desc' ) ); ?></p>
					<button type="submit" class="o3d-btn o3d-btn-primary" name="o3dai_action" value="run"><?php echo esc_html( o3dai_t( 'act_go' ) ); ?></button>
				</form>
				<form method="post" class="o3d-action-card">
					<?php wp_nonce_field( 'o3dai' ); ?>
					<input type="hidden" name="o3d_tab" value="overview">
					<h3><?php echo esc_html( o3dai_t( 'act_gen_topics' ) ); ?></h3>
					<p class="o3d-act-desc"><?php echo esc_html( o3dai_t( 'act_topics_desc' ) ); ?></p>
					<button type="submit" class="o3d-btn o3d-btn-primary" name="o3dai_action" value="topics"><?php echo esc_html( o3dai_t( 'act_go' ) ); ?></button>
				</form>
				<form method="post" class="o3d-action-card">
					<?php wp_nonce_field( 'o3dai' ); ?>
					<input type="hidden" name="o3d_tab" value="overview">
					<h3><?php echo esc_html( o3dai_t( 'act_news_topics' ) ); ?></h3>
					<p class="o3d-act-desc"><?php echo esc_html( o3dai_t( 'act_news_desc' ) ); ?></p>
					<button type="submit" class="o3d-btn o3d-btn-primary" name="o3dai_action" value="news"><?php echo esc_html( o3dai_t( 'act_go' ) ); ?></button>
				</form>
<form method="post" class="o3d-action-card">
					<?php wp_nonce_field( 'o3dai' ); ?>
					<input type="hidden" name="o3d_tab" value="overview">
					<h3><?php echo esc_html( o3dai_t( 'act_suggest_series' ) ); ?></h3>
					<p class="o3d-act-desc"><?php echo esc_html( o3dai_t( 'act_series_desc' ) ); ?></p>
					<button type="submit" class="o3d-btn o3d-btn-primary" name="o3dai_action" value="suggest_series"><?php echo esc_html( o3dai_t( 'act_go' ) ); ?></button>
				</form>
				</div>
			<p class="description o3d-ov-hint"><?php echo esc_html( o3dai_t( 'ov_hint' ) ); ?></p>
				<div class="o3d-overview-grid">
					<div>
					<div class="o3d-card">
						<h2><span class="dashicons dashicons-chart-bar"></span> <?php echo esc_html( o3dai_t( 'card_usage' ) ); ?> – <?php echo esc_html( date_i18n( 'F Y' ) ); ?></h2>
						<?php $u = get_option( 'o3dai_usage_' . gmdate( 'Y-m' ), array() );
						$tcost = $u['text_cost'] ?? 0; $icost = $u['image_cost'] ?? 0; ?>
						<div class="o3d-stats">
							<div class="o3d-stat"><div class="n"><?php echo intval( $u['text_calls'] ?? 0 ); ?></div><div class="l"><?php echo esc_html( o3dai_t( 'u_articles' ) ); ?></div></div>
							<div class="o3d-stat"><div class="n"><?php echo intval( $u['images'] ?? 0 ); ?></div><div class="l"><?php echo esc_html( o3dai_t( 'u_images' ) ); ?></div></div>
							<div class="o3d-stat"><div class="n">~$<?php echo number_format( $tcost, 2 ); ?></div><div class="l"><?php echo esc_html( o3dai_t( 'u_cost_text' ) ); ?></div></div>
							<div class="o3d-stat"><div class="n">~$<?php echo number_format( $icost, 2 ); ?></div><div class="l"><?php echo esc_html( o3dai_t( 'u_cost_img' ) ); ?></div></div>
							<div class="o3d-stat"><div class="n"><?php echo intval( $u['products'] ?? 0 ); ?></div><div class="l"><?php echo esc_html( o3dai_t( 'u_products' ) ); ?></div></div>
							<div class="o3d-stat"><div class="n"><?php echo intval( $u['categories'] ?? 0 ); ?></div><div class="l"><?php echo esc_html( o3dai_t( 'u_categories' ) ); ?></div></div>
														<?php $o3d_atm = O3DAI_License::articles_this_month(); ?>
							<div class="o3d-stat" style="<?php echo $o3d_atm >= O3DAI_License::FREE_ARTICLES ? 'background:#fffaf0' : ''; ?>">
								<div class="n"><?php echo esc_html( $o3d_atm ); ?></div>
								<div class="l"><?php echo esc_html( o3dai_t( 'articles_this_month_l' ) . ( $o3d_atm >= O3DAI_License::FREE_ARTICLES ? ' ⚠' : '' ) ); ?></div>
							</div>
							<div class="o3d-stat total"><div class="n">~$<?php echo esc_html( number_format( $tcost + $icost, 2 ) ); ?></div><div class="l"><?php echo esc_html( o3dai_t( 'u_total' ) ); ?> · <?php echo esc_html( number_format_i18n( ( $u['in_tokens'] ?? 0 ) + ( $u['out_tokens'] ?? 0 ) ) ); ?> <?php echo esc_html( o3dai_t( 'u_tokens' ) ); ?></div></div>
						</div>
						<p class="description" style="margin-top:12px"><?php echo esc_html( o3dai_t( 'u_note' ) ); ?></p>
<details style="margin-top:10px">
	<summary style="cursor:pointer;font-size:12px;color:#6C4AB6"><?php echo esc_html( o3dai_t( 'usage_history_btn' ) ); ?></summary>
	<?php $o3d_hist = O3DAI_License::usage_history(); ?>
	<?php if ( $o3d_hist ) :
		$o3d_hta = 0; $o3d_him = 0; $o3d_hpp = 0; $o3d_hcat = 0; $o3d_hcost = 0.0;
		foreach ( $o3d_hist as $o3d_hr ) {
			$o3d_hta += $o3d_hr['text_calls']; $o3d_him += $o3d_hr['images']; $o3d_hpp += $o3d_hr['products']; $o3d_hcat += $o3d_hr['categories']; $o3d_hcost += $o3d_hr['cost'];
		}
	?>
	<table class="o3d-tt" style="margin-top:8px">
		<thead><tr>
			<th><?php echo esc_html( o3dai_t( 'hist_month' ) ); ?></th>
			<th style="width:64px"><?php echo esc_html( o3dai_t( 'u_articles' ) ); ?></th>
			<th style="width:60px"><?php echo esc_html( o3dai_t( 'u_images' ) ); ?></th>
			<th style="width:64px"><?php echo esc_html( o3dai_t( 'u_products' ) ); ?></th>
			<th style="width:72px"><?php echo esc_html( o3dai_t( 'u_categories' ) ); ?></th>
			<th style="width:80px"><?php echo esc_html( o3dai_t( 'hist_cost' ) ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $o3d_hist as $o3d_ym => $o3d_hr ) : ?>
			<tr>
				<td><?php echo esc_html( date_i18n( 'F Y', strtotime( $o3d_ym . '-01 00:00:00' ) ) ); ?></td>
				<td><?php echo (int) $o3d_hr['text_calls']; ?></td>
				<td><?php echo (int) $o3d_hr['images']; ?></td>
				<td><?php echo (int) $o3d_hr['products']; ?></td>
				<td><?php echo (int) $o3d_hr['categories']; ?></td>
				<td>~$<?php echo esc_html( number_format( $o3d_hr['cost'], 2 ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		<tr style="font-weight:600;background:#f7f5fb">
			<td><?php echo esc_html( o3dai_t( 'hist_total' ) ); ?></td>
			<td><?php echo (int) $o3d_hta; ?></td>
			<td><?php echo (int) $o3d_him; ?></td>
			<td><?php echo (int) $o3d_hpp; ?></td>
			<td><?php echo (int) $o3d_hcat; ?></td>
			<td>~$<?php echo esc_html( number_format( $o3d_hcost, 2 ) ); ?></td>
		</tr>
		</tbody>
	</table>
	<?php endif; ?>
</details>
					</div>
					</div>
					<div>
					<div class="o3d-card">
						<h2><span class="dashicons dashicons-clock"></span> <?php echo esc_html( o3dai_t( 'card_runs' ) ); ?></h2>
						<pre class="o3d-log"><?php echo esc_html( $log ? implode( "\n", $log ) : o3dai_t( 'no_runs' ) ); ?></pre>
					</div>
					</div>
				</div>
				<div class="o3d-card">
						<?php $hist = get_option( 'o3dai_history', array() );
						$tot = count( $hist );
						$avg = $tot ? round( array_sum( wp_list_pluck( $hist, 'words' ) ) / $tot ) : 0; ?>
						<h2><span class="dashicons dashicons-media-document"></span> <?php echo esc_html( o3dai_t( 'card_history' ) ); ?> <span class="o3d-pill"><?php echo esc_html( $tot ); ?></span></h2>
						<?php if ( $tot ) : ?>
							<p class="description" style="margin:0 0 10px"><?php echo esc_html( o3dai_t( 'avg_length' ) ); ?> <strong><?php echo esc_html( $avg ); ?></strong> <?php echo esc_html( o3dai_t( 'words_unit' ) ); ?></p>
							<table class="o3d-tt">
								<thead><tr><th><?php echo esc_html( o3dai_t( 'article_col' ) ); ?></th><th style="width:52px"><?php echo esc_html( o3dai_t( 'words' ) ); ?></th><th style="width:42px">🔗</th></tr></thead>
								<tbody>
								<?php foreach ( array_slice( $hist, 0, 15 ) as $h ) : ?>
									<tr>
										<td><a href="<?php echo esc_url( get_edit_post_link( $h['id'] ) ); ?>"><?php echo esc_html( $h['title'] ); ?></a><br><span class="description" style="font-size:11px"><?php echo esc_html( $h['date'] ); ?><?php echo $h['kw'] ? ' · ' . esc_html( $h['kw'] ) : ''; ?></span></td>
										<td><?php echo intval( $h['words'] ); ?></td>
										<td><?php echo intval( $h['links'] ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<p class="description"><?php echo esc_html( o3dai_t( 'no_history' ) ); ?></p>
						<?php endif; ?>
					</div>
			</div>

			<div class="o3d-tabpanel" data-panel="topics" role="tabpanel">
					<div class="o3d-card">
						<h2><span class="dashicons dashicons-list-view"></span> <?php echo esc_html( o3dai_t( 'card_queue' ) ); ?> <span class="o3d-pill"><?php echo count( wp_filter_object_list( $topics, array( 'status' => 'pending' ) ) ); ?> <?php echo esc_html( o3dai_t( 'q_waiting' ) ); ?></span></h2>
						<form method="post" style="margin-bottom:14px">
							<?php wp_nonce_field( 'o3dai' ); ?>
							<input type="hidden" name="o3d_tab" value="topics">
							<div class="o3d-actions">
								<button class="o3d-btn o3d-btn-accent" name="o3dai_action" value="run"><?php echo esc_html( o3dai_t( 'act_gen_now' ) ); ?></button>
								<button class="o3d-btn o3d-btn-ghost" name="o3dai_action" value="topics"><?php echo esc_html( o3dai_t( 'act_gen_topics' ) ); ?></button>
								<button class="o3d-btn o3d-btn-ghost" name="o3dai_action" value="news" title="<?php echo esc_attr( o3dai_t( 'news_btn_title' ) ); ?>"><?php echo esc_html( o3dai_t( 'act_news_topics' ) ); ?></button>
							</div>
						</form>
						<form method="post" class="o3d-addbar">
							<?php wp_nonce_field( 'o3dai' ); ?>
							<input type="hidden" name="o3d_tab" value="topics">
							<input type="text" name="new_topic" placeholder="<?php echo esc_attr( o3dai_t( 'ph_topic' ) ); ?>">
							<button class="o3d-btn o3d-btn-ghost" name="o3dai_action" value="add_topic"><?php echo esc_html( o3dai_t( 'act_add' ) ); ?></button>
						</form>
<form method="post" class="o3d-addbar">
							<?php wp_nonce_field( 'o3dai' ); ?>
							<input type="hidden" name="o3d_tab" value="topics">
							<input type="text" name="series_theme" placeholder="<?php echo esc_attr( o3dai_t( 'ph_series' ) ); ?>">
							<button class="o3d-btn o3d-btn-ghost" name="o3dai_action" value="series"><?php echo esc_html( o3dai_t( 'act_make_series' ) ); ?></button>
						</form>
<form method="post" style="margin-bottom:14px">
							<?php wp_nonce_field( 'o3dai' ); ?>
							<input type="hidden" name="o3d_tab" value="topics">
							<button class="o3d-btn o3d-btn-ghost" name="o3dai_action" value="suggest_series"><?php echo esc_html( o3dai_t( 'act_suggest_series' ) ); ?></button>
							<?php $ssugg = get_option( 'o3dai_series_suggestions', array() ); ?>
							<?php if ( $ssugg ) : ?>
								<div style="margin-top:14px;background:#f4f1fb;border:1px solid #e6dcf7;border-radius:12px;padding:16px">
									<strong style="color:#2B2350"><?php echo esc_html( o3dai_t( 'suggested_series_l' ) ); ?></strong>
									<div style="margin-top:10px;display:flex;flex-direction:column;gap:8px">
										<?php foreach ( $ssugg as $t ) : ?>
											<button class="o3d-btn o3d-btn-primary" name="o3dai_action" value="make_suggested_series" style="text-align:left" onclick="this.form.querySelector('input[name=sugg_series]').value=<?php echo esc_attr( wp_json_encode( $t ) ); ?>">📚 <?php echo esc_html( $t ); ?></button>
										<?php endforeach; ?>
									</div>
									<input type="hidden" name="sugg_series" value="">
								</div>
							<?php endif; ?>
						</form>
<form method="post" style="margin-bottom:14px">
							<?php wp_nonce_field( 'o3dai' ); ?>
							<input type="hidden" name="o3d_tab" value="topics">
							<button class="o3d-btn o3d-btn-accent" name="o3dai_action" value="suggest"><?php echo esc_html( o3dai_t( 'act_suggest' ) ); ?></button>
							<?php $sugg = get_option( 'o3dai_topic_suggestions', array() ); ?>
							<?php if ( $sugg ) : ?>
								<div style="margin-top:14px;background:#faf8ff;border:1px solid #ece9f3;border-radius:12px;padding:16px">
									<strong style="color:#2B2350"><?php echo esc_html( o3dai_t( 'suggested_topics_l' ) ); ?></strong>
									<div style="margin-top:10px">
										<?php foreach ( $sugg as $idx => $t ) : ?>
											<label style="display:flex;align-items:flex-start;gap:8px;padding:6px 0;border-bottom:1px solid #f2eefb">
												<input type="checkbox" name="sugg[]" value="<?php echo esc_attr( $t ); ?>" style="margin-top:3px">
												<span><?php echo esc_html( $t ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
									<button class="o3d-btn o3d-btn-primary" name="o3dai_action" value="add_suggested" style="margin-top:12px"><?php echo esc_html( o3dai_t( 'act_add_selected' ) ); ?></button>
								</div>
							<?php endif; ?>
						</form>
						<form method="post">
							<?php wp_nonce_field( 'o3dai' ); ?>
							<input type="hidden" name="o3d_tab" value="topics">
							<table class="o3d-tt">
								<thead><tr><th><?php echo esc_html( o3dai_t( 'col_topic' ) ); ?></th><th style="width:60px"><?php echo esc_html( o3dai_t( 'col_status' ) ); ?></th><th style="width:90px"><?php echo esc_html( o3dai_t( 'col_article' ) ); ?></th><th style="width:250px"><?php echo esc_html( o3dai_t( 'col_actions' ) ); ?></th></tr></thead>
								<tbody>
								<?php if ( ! $topics ) : ?>
									<tr><td colspan="4" style="color:#8b83a3"><?php echo esc_html( o3dai_t( 'q_empty' ) ); ?></td></tr>
							<?php else : ?>
							<?php
								// v1.6.0 – fronta: oddelené sekcie (na publikovanie / zverejnené); tlačidlá si zachovávajú pôvodné indexy $topics
								$o3d_sections = array( 'pending' => array(), 'done' => array() );
								foreach ( $topics as $o3d_i => $o3d_t ) {
									$o3d_sections[ 'done' === $o3d_t['status'] ? 'done' : 'pending' ][] = array( $o3d_i, $o3d_t );
								}
									// v1.6.2 – paginácia sekcií (10 na stránku); len zobrazenie, indexy tlačilá zostávajú originálne
									$o3d_page_size = 10;
									// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only pagination state from our own admin page URL
									$o3d_pp = max( 1, isset( $_GET['o3d_pp'] ) ? absint( $_GET['o3d_pp'] ) : 1 );
									// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only pagination state from our own admin page URL
									$o3d_dp = max( 1, isset( $_GET['o3d_dp'] ) ? absint( $_GET['o3d_dp'] ) : 1 );
									$o3d_p_pages = max( 1, (int) ceil( count( $o3d_sections['pending'] ) / $o3d_page_size ) );
									$o3d_d_pages = max( 1, (int) ceil( count( $o3d_sections['done'] ) / $o3d_page_size ) );
									if ( $o3d_pp > $o3d_p_pages ) { $o3d_pp = $o3d_p_pages; }
									if ( $o3d_dp > $o3d_d_pages ) { $o3d_dp = $o3d_d_pages; }
									$o3d_pending_view = array_slice( $o3d_sections['pending'], ( $o3d_pp - 1 ) * $o3d_page_size, $o3d_page_size );
									$o3d_done_view    = array_slice( $o3d_sections['done'], ( $o3d_dp - 1 ) * $o3d_page_size, $o3d_page_size );
								$o3d_topic_row = function ( $i, $t ) {
									$badge   = 'done' === $t['status'] ? '✅' : ( 'skipped' === $t['status'] ? '🚫' : '⏳' );
									$pills   = '';
									if ( ! empty( $t['series'] ) ) { $pills .= ' <span class="o3d-pill" style="font-size:10px">📚 ' . esc_html( $t['series'] ) . ' · ' . intval( $t['series_part'] ) . '.&nbsp;</span>'; }
									if ( 'news' === ( $t['source'] ?? '' ) ) { $pills .= ' <span class="o3d-pill" style="font-size:10px">📰</span>'; }
									$article = ! empty( $t['post_id'] ) ? '<a href="' . esc_url( get_edit_post_link( $t['post_id'] ) ) . '" title="' . esc_attr( o3dai_t( 'edit_arrow' ) ) . '">' . esc_html( o3dai_t( 'edit_arrow' ) ) . '</a> <a href="' . esc_url( admin_url( 'admin.php?page=o3dai-article&id=' . (int) $t['post_id'] ) ) . '" title="' . esc_attr( o3dai_t( 'a_preview' ) ) . '">👁</a>' : '—';
									$actions = '';
									if ( 'done' !== $t['status'] ) {
										$actions .= '<button class="o3d-minibtn" name="topic_op" value="top:' . (int) $i . '" title="' . esc_attr( o3dai_t( 'a_top' ) ) . '">⇧</button>';
										$actions .= '<button class="o3d-minibtn" name="topic_op" value="up:' . (int) $i . '" title="' . esc_attr( o3dai_t( 'a_up' ) ) . '">↑</button>';
										$actions .= '<button class="o3d-minibtn" name="topic_op" value="down:' . (int) $i . '" title="' . esc_attr( o3dai_t( 'a_down' ) ) . '">↓</button>';
										$actions .= '<button class="o3d-minibtn" name="topic_op" value="skip:' . (int) $i . '" title="' . esc_attr( o3dai_t( 'a_skip' ) ) . '">🚫</button>';
									} elseif ( ! empty( $t['post_id'] ) ) {
										$actions .= '<button class="o3d-minibtn" name="topic_op" value="retext:' . (int) $i . '" title="' . esc_attr( o3dai_t( 'a_retext' ) ) . '">🔄</button>';
										$actions .= '<button class="o3d-minibtn" name="topic_op" value="reimg:' . (int) $i . '" title="' . esc_attr( o3dai_t( 'a_reimg' ) ) . '">🖼</button>';
									}
									$actions .= '<button class="o3d-minibtn warn" name="topic_op" value="del:' . (int) $i . '" title="' . esc_attr( o3dai_t( 'a_delete' ) ) . '" onclick="return confirm(\'' . esc_js( o3dai_t( 'a_del_confirm' ) ) . '\')">🗑</button>';
									return '<tr style="' . ( 'skipped' === $t['status'] ? 'opacity:.45' : '' ) . '">' .
										'<td>' . esc_html( $t['title'] ) . $pills . '</td>' .
										'<td><span class="o3d-badge">' . $badge . '</span></td>' .
										'<td>' . $article . '</td>' .
										'<td>' . $actions . '</td></tr>';
								};
									$o3d_pager_base = add_query_arg( array( 'page' => 'o3dai', 'o3d_tab' => 'topics' ), admin_url( 'admin.php' ) );
									$o3d_pager_link = function ( $key, $n ) use ( $o3d_pp, $o3d_dp, $o3d_pager_base ) {
										$args = array();
										if ( 'p' === $key ) { $args['o3d_pp'] = max( 1, $n ); $args['o3d_dp'] = $o3d_dp; } else { $args['o3d_dp'] = max( 1, $n ); $args['o3d_pp'] = $o3d_pp; }
										return add_query_arg( $args, $o3d_pager_base );
									};
									$o3d_pager_row = function ( $key, $cur, $pages ) use ( $o3d_pager_link ) {
										if ( $pages < 2 ) { return ''; }
										$row = '<tr><td colspan="4" style="text-align:center;padding:8px 0;font-size:12px">';
										$row .= ( $cur > 1 ) ? '<a class="button button-small" href="' . esc_url( $o3d_pager_link( $key, $cur - 1 ) ) . '">&laquo; ' . esc_html( o3dai_t( 'pager_prev' ) ) . '"</a> ' : '';
										$row .= '<span style="color:#6c6480">' . esc_html( o3dai_t( 'page_of', array( 'p' => (int) $cur, 'n' => (int) $pages ) ) ) . '</span> ';
										$row .= ( $cur < $pages ) ? '<a class="button button-small" href="' . esc_url( $o3d_pager_link( $key, $cur + 1 ) ) . '">' . esc_html( o3dai_t( 'pager_next' ) ) . '" &raquo;</a>' : '';
										return $row . '</td></tr>';
									};
							?>
								<?php if ( $o3d_sections['pending'] ) : ?>
								<tr class="o3d-sec"><td colspan="4"><span class="o3d-sech">⏳ <?php echo esc_html( o3dai_t( 'q_sec_pending' ) ); ?></span> <span class="o3d-pill"><?php echo esc_html( count( $o3d_sections['pending'] ) ); ?></span></td></tr>
								<?php foreach ( $o3d_pending_view as $o3d_p ) : ?>
									<?php echo wp_kses_post( $o3d_topic_row( $o3d_p[0], $o3d_p[1] ) ); ?>
								<?php endforeach; ?>
								<?php echo wp_kses_post( $o3d_pager_row( 'p', $o3d_pp, $o3d_p_pages ) ); ?>
								<?php endif; ?>
								<?php if ( $o3d_sections['done'] ) : ?>
								<tr class="o3d-sec"><td colspan="4"><span class="o3d-sech">✅ <?php echo esc_html( o3dai_t( 'q_sec_published' ) ); ?></span> <span class="o3d-pill"><?php echo esc_html( count( $o3d_sections['done'] ) ); ?></span></td></tr>
								<?php foreach ( $o3d_done_view as $o3d_p ) : ?>
									<?php echo wp_kses_post( $o3d_topic_row( $o3d_p[0], $o3d_p[1] ) ); ?>
								<?php endforeach; ?>
								<?php echo wp_kses_post( $o3d_pager_row( 'd', $o3d_dp, $o3d_d_pages ) ); ?>
								<?php endif; ?>
							<?php endif; ?>
								</tbody>
							</table>
							<div class="o3d-actions" style="margin-top:12px">
								<button class="o3d-btn o3d-btn-warn" name="o3dai_action" value="clear_topics" onclick="return confirm('<?php echo esc_js( o3dai_t( 'a_clear_confirm' ) ); ?>')"><?php echo esc_html( o3dai_t( 'act_clear_queue' ) ); ?></button>
							</div>
						</form>
					</div>
				
			</div>

			<div class="o3d-tabpanel" data-panel="products" role="tabpanel">
				<div class="o3d-card">
					<h2><span class="dashicons dashicons-lightbulb"></span> <?php echo esc_html( o3dai_t( 'products_howto_title' ) ); ?></h2>
					<ol class="o3d-steps">
						<li><?php echo esc_html( o3dai_t( 'products_howto_1' ) ); ?></li>
						<li><?php echo esc_html( o3dai_t( 'products_howto_2' ) ); ?></li>
						<li><?php echo esc_html( o3dai_t( 'products_howto_3' ) ); ?></li>
					</ol>
				</div>

				<div class="o3d-card">
					<h2><span class="dashicons dashicons-edit-page"></span> <?php echo esc_html( o3dai_t( 'prop_card' ) ); ?></h2>
					<p class="description"><?php echo esc_html( o3dai_t( 'prop_hint' ) ); ?></p>
					<?php
						$o3d_props = get_option( 'o3dai_proposals', array() );
						if ( ! is_array( $o3d_props ) ) { $o3d_props = array(); }
						$o3d_pend = 0;
						foreach ( $o3d_props as $o3d_pp ) {
							if ( 'pending' === ( isset( $o3d_pp['status'] ) ? $o3d_pp['status'] : 'pending' ) ) { $o3d_pend++; }
						}
					?>
					<div class="o3d-addbar">
						<?php if ( post_type_exists( 'product' ) ) : ?>
							<a class="o3d-btn o3d-btn-ghost" style="text-decoration:none" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>"><?php echo esc_html( o3dai_t( 'prop_open_products' ) ); ?></a>
						<?php endif; ?>
						<?php if ( $o3d_pend > 0 ) : ?>
							<form method="post" class="o3d-inline">
								<?php wp_nonce_field( 'o3dai' ); ?>
								<input type="hidden" name="o3d_tab" value="products">
								<button type="submit" class="o3d-btn o3d-btn-primary" name="o3dai_action" value="approve_all_proposals" onclick="return confirm('<?php echo esc_js( o3dai_t( 'prop_approve_all_confirm' ) ); ?>')"><?php echo esc_html( o3dai_t( 'prop_approve_all' ) ); ?> (<?php echo (int) $o3d_pend; ?>)</button>
							</form>
						<?php endif; ?>
					</div>
					<?php if ( empty( $o3d_props ) ) : ?>
						<p class="o3d-tpl-empty"><?php echo esc_html( o3dai_t( 'prop_empty' ) ); ?></p>
					<?php else : ?>
						<table class="o3d-tt">
							<thead>
								<tr>
									<th><?php echo esc_html( o3dai_t( 'prop_col_product' ) ); ?></th>
									<th><?php echo esc_html( o3dai_t( 'prop_col_status' ) ); ?></th>
									<th><?php echo esc_html( o3dai_t( 'prop_col_seo' ) ); ?></th>
									<th><?php echo esc_html( o3dai_t( 'prop_col_proposal' ) ); ?></th>
									<th><?php echo esc_html( o3dai_t( 'prop_col_date' ) ); ?></th>
									<th><?php echo esc_html( o3dai_t( 'prop_col_actions' ) ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( array_reverse( $o3d_props, true ) as $o3d_pid => $o3d_p ) :
									$o3d_pid    = intval( $o3d_pid );
									$o3d_status = isset( $o3d_p['status'] ) ? $o3d_p['status'] : 'pending';
									$o3d_pname  = isset( $o3d_p['name'] ) ? $o3d_p['name'] : '';
									$o3d_cur    = isset( $o3d_p['cur_name'] ) ? $o3d_p['cur_name'] : '';
								?>
									<tr>
										<td>
											<strong><?php echo esc_html( $o3d_pname ? $o3d_pname : ( '#' . $o3d_pid ) ); ?></strong>
											<?php if ( post_type_exists( 'product' ) ) : ?>
												<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $o3d_pid . '&action=edit' ) ); ?>" style="font-size:12px">#<?php echo (int) $o3d_pid; ?> &rarr;</a>
											<?php endif; ?>
											<?php if ( $o3d_cur && $o3d_cur !== $o3d_pname ) : ?>
												<div class="o3d-prop-cur"><?php echo esc_html( o3dai_t( 'prop_cur', array( 'x' => $o3d_cur ) ) ); ?></div>
											<?php endif; ?>
										</td>
										<td><span class="o3d-st o3d-st-<?php echo esc_attr( $o3d_status ); ?>"><?php echo esc_html( o3dai_t( 'prop_st_' . $o3d_status ) ); ?></span></td>
										<td class="o3d-prop-seo">
											<?php if ( ! empty( $o3d_p['meta_title'] ) ) : ?><div><strong><?php echo esc_html( o3dai_t( 'prop_mt' ) ); ?>:</strong> <?php echo esc_html( $o3d_p['meta_title'] ); ?></div><?php endif; ?>
											<?php if ( ! empty( $o3d_p['meta_desc'] ) ) : ?><div><strong><?php echo esc_html( o3dai_t( 'prop_md' ) ); ?>:</strong> <?php echo esc_html( $o3d_p['meta_desc'] ); ?></div><?php endif; ?>
											<?php if ( ! empty( $o3d_p['focus_kw'] ) ) : ?><div><strong><?php echo esc_html( o3dai_t( 'prop_kw' ) ); ?>:</strong> <?php echo esc_html( $o3d_p['focus_kw'] ); ?></div><?php endif; ?>
											<?php if ( ! empty( $o3d_p['cats'] ) ) : ?><div><strong><?php echo esc_html( o3dai_t( 'prop_cats' ) ); ?>:</strong> <?php echo esc_html( implode( ', ', (array) $o3d_p['cats'] ) ); ?></div><?php endif; ?>
										</td>
										<td class="o3d-prop-desc">
											<details>
												<summary><?php echo esc_html( o3dai_t( 'prop_col_proposal' ) ); ?></summary>
												<?php if ( ! empty( $o3d_p['short'] ) ) : ?><p style="font-style:italic;margin:8px 0 2px"><?php echo esc_html( $o3d_p['short'] ); ?></p><?php endif; ?>
												<div class="o3d-desc-body"><?php echo wp_kses_post( isset( $o3d_p['description'] ) ? $o3d_p['description'] : '' ); ?></div>
											</details>
										</td>
										<td><?php echo esc_html( date_i18n( 'j.n.Y H:i', isset( $o3d_p['ts'] ) ? (int) $o3d_p['ts'] : 0 ) ); ?></td>
										<td style="white-space:nowrap">
											<?php if ( 'pending' === $o3d_status ) : ?>
												<form method="post" class="o3d-inline" onsubmit="return confirm('<?php echo esc_js( o3dai_t( 'prop_approve_confirm' ) ); ?>')">
													<?php wp_nonce_field( 'o3dai' ); ?>
													<input type="hidden" name="o3d_tab" value="products">
													<input type="hidden" name="prop" value="<?php echo (int) $o3d_pid; ?>">
													<button type="submit" class="o3d-minibtn ok" name="o3dai_action" value="approve_proposal"><?php echo esc_html( o3dai_t( 'prop_approve' ) ); ?></button>
												</form>
												<form method="post" class="o3d-inline" onsubmit="return confirm('<?php echo esc_js( o3dai_t( 'prop_reject_confirm' ) ); ?>')">
													<?php wp_nonce_field( 'o3dai' ); ?>
													<input type="hidden" name="o3d_tab" value="products">
													<input type="hidden" name="prop" value="<?php echo (int) $o3d_pid; ?>">
													<button type="submit" class="o3d-minibtn warn" name="o3dai_action" value="reject_proposal"><?php echo esc_html( o3dai_t( 'prop_reject' ) ); ?></button>
												</form>
											<?php elseif ( 'rejected' === $o3d_status ) : ?>
												<form method="post" class="o3d-inline" onsubmit="return confirm('<?php echo esc_js( o3dai_t( 'prop_remove_confirm' ) ); ?>')">
													<?php wp_nonce_field( 'o3dai' ); ?>
													<input type="hidden" name="o3d_tab" value="products">
													<input type="hidden" name="prop" value="<?php echo (int) $o3d_pid; ?>">
													<button type="submit" class="o3d-minibtn warn" name="o3dai_action" value="remove_proposal"><?php echo esc_html( o3dai_t( 'prop_remove' ) ); ?></button>
												</form>
											<?php else : ?>
												<span class="description">&mdash;</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
				<div class="o3d-card">
					<h2><span class="dashicons dashicons-chart-bar"></span> <?php echo esc_html( o3dai_t( 'tab_products' ) ); ?> &ndash; <?php echo esc_html( date_i18n( 'F Y' ) ); ?></h2>
					<div class="o3d-stats">
						<div class="o3d-stat"><div class="n"><?php echo intval( $u['products'] ?? 0 ); ?></div><div class="l"><?php echo esc_html( o3dai_t( 'u_products' ) ); ?></div></div>
						<div class="o3d-stat"><div class="n"><?php echo intval( $u['categories'] ?? 0 ); ?></div><div class="l"><?php echo esc_html( o3dai_t( 'u_categories' ) ); ?></div></div>
					</div>
					<p class="description" style="margin-top:10px"><?php echo esc_html( o3dai_t( 'products_webhook_hint' ) ); ?></p>
				</div>
			</div>

			<div class="o3d-tabpanel" data-panel="settings" role="tabpanel">
					<form method="post">
						<?php wp_nonce_field( 'o3dai' ); ?>
						<input type="hidden" name="o3d_tab" value="settings">
						<div class="o3d-savebar">
							<button class="o3d-btn o3d-btn-primary o3d-btn-lg" name="o3dai_action" value="save">💾 <?php echo esc_html( o3dai_t( 'save_settings' ) ); ?></button>
							<input type="text" class="o3d-tpl-name" name="template_name" placeholder="<?php echo esc_attr( o3dai_t( 'tpl_name_ph' ) ); ?>">
							<button type="submit" class="o3d-btn o3d-btn-ghost" name="o3dai_action" value="save_template">📋 <?php echo esc_html( o3dai_t( 'tpl_save_btn' ) ); ?></button>
							<span class="o3d-savebar-hint"><?php echo esc_html( o3dai_t( 'savebar_hint' ) ); ?></span>
						</div>
						<div class="o3d-card">
							<h2><span class="dashicons dashicons-admin-generic"></span> <?php echo esc_html( o3dai_t( 'card_basic' ) ); ?></h2>
							<div class="o3d-field">
								<div class="o3d-toggle">
									<input type="checkbox" id="o3d-en" name="enabled" <?php checked( $s['enabled'], 1 ); ?>>
									<label for="o3d-en" class="o3d-lbl" style="margin:0"><?php echo esc_html( o3dai_t( 'autopilot_on' ) ); ?> <span class="description" style="font-weight:400">— <?php echo esc_html( o3dai_t( 'autopilot_hint' ) ); ?></span></label>
								</div>
							</div>
							<div class="o3d-row2">
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'provider_l' ) ); ?></label>
									<select name="provider" id="o3d-provider">
										<option value="claude" <?php selected( $s['provider'], 'claude' ); ?>>Anthropic Claude</option>
										<option value="openai" <?php selected( $s['provider'], 'openai' ); ?>>OpenAI</option>
										<option value="gemini" <?php selected( $s['provider'], 'gemini' ); ?>>Google Gemini</option>
										<option value="openrouter" <?php selected( $s['provider'], 'openrouter' ); ?>><?php echo esc_html( o3dai_t( 'prov_openrouter' ) ); ?></option>
									<option value="local" <?php selected( $s['provider'], 'local' ); ?>><?php echo esc_html( o3dai_t( 'prov_local' ) ); ?></option>
									<option value="comfyui" <?php selected( $s['provider'], 'comfyui' ); ?>><?php echo esc_html( o3dai_t( 'prov_comfy' ) ); ?></option>
									</select>
								</div>
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'model_l' ) ); ?></label>
									<select name="model" id="o3d-model"></select>
									<p class="description"><?php echo esc_html( o3dai_t( 'model_dropdown_hint' ) ); ?></p>
								</div>
							</div>
							<div class="o3d-row2">
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'new_post_status' ) ); ?></label>
									<select name="publish_status">
										<option value="draft" <?php selected( $s['publish_status'], 'draft' ); ?>><?php echo esc_html( o3dai_t( 'st_draft' ) ); ?></option>
										<option value="publish" <?php selected( $s['publish_status'], 'publish' ); ?>><?php echo esc_html( o3dai_t( 'st_publish_now' ) ); ?></option>
										<option value="approval" <?php selected( $s['publish_status'], 'approval' ); ?>><?php echo esc_html( o3dai_t( 'st_approval' ) ); ?></option>
									</select>
								</div>
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'daily_hour' ) ); ?></label>
									<input type="number" name="hour" min="0" max="23" value="<?php echo esc_attr( $s['hour'] ); ?>">
									<p class="description"><?php echo esc_html( o3dai_t( 'daily_hour_hint' ) ); ?></p>
								</div>
							</div>
						<div class="o3d-field">
							<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'weekdays_l' ) ); ?></label>
							<div class="o3d-weekdays">
								<?php foreach ( array( 1, 2, 3, 4, 5, 6, 7 ) as $o3d_d ) : ?>
								<label class="o3d-day"><input type="checkbox" name="weekdays[]" value="<?php echo (int) $o3d_d; ?>" <?php checked( in_array( $o3d_d, array_map( 'intval', explode( ',', (string) ( $s['weekdays'] ?? '1,2,3,4,5,6,7' ) ) ), true ) ); ?>><?php echo esc_html( o3dai_t( 'day_' . $o3d_d ) ); ?></label>
								<?php endforeach; ?>
							</div>
							<p class="description"><?php echo esc_html( o3dai_t( 'weekdays_hint' ) ); ?></p>
						</div>
							<div class="o3d-row2">
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'post_category' ) ); ?></label>
									<?php wp_dropdown_categories( array( 'name' => 'category_id', 'selected' => $s['category_id'], 'hide_empty' => 0, 'show_option_none' => o3dai_t( 'cat_default' ), 'option_none_value' => 0 ) ); ?>
								</div>
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'progress_label' ) ); ?></label>
									<select name="progress_mode">
										<option value="background" <?php selected( $s['progress_mode'] ?? 'background', 'background' ); ?>><?php echo esc_html( o3dai_t( 'progress_bg' ) ); ?></option>
										<option value="foreground" <?php selected( $s['progress_mode'] ?? 'background', 'foreground' ); ?>><?php echo esc_html( o3dai_t( 'progress_fg' ) ); ?></option>
									</select>
									<p class="description"><?php echo esc_html( o3dai_t( 'progress_hint' ) ); ?></p>
								</div>
							</div>
							<div class="o3d-row2">
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'ui_language' ) ); ?></label>
									<select name="ui_lang">
										<option value="en" <?php selected( $s['ui_lang'] ?? 'en', 'en' ); ?>>English</option>
										<option value="sk" <?php selected( $s['ui_lang'] ?? 'en', 'sk' ); ?>><?php echo esc_html( o3dai_t( 'sk_lang_name' ) ); ?></option>
									</select>
								</div>
								<div class="o3d-field"></div>
							</div>
						</div>

<div class="o3d-card">
							<h2><span class="dashicons dashicons-share"></span> <?php echo esc_html( o3dai_t( 'card_social' ) ); ?></h2>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'webhook_social' ) ); ?> <span class="description" style="font-weight:400"><?php echo esc_html( o3dai_t( 'optional' ) ); ?></span></label>
								<input type="text" name="webhook_url" value="<?php echo esc_attr( $s['webhook_url'] ?? '' ); ?>" placeholder="https://hook.eu2.make.com/…">
								<p class="description"><?php echo wp_kses_post( o3dai_t( 'webhook_social_hint' ) ); ?></p>
								<button type="submit" class="o3d-btn o3d-btn-ghost o3d-btn-sm" name="o3dai_action" value="test_webhook" style="margin-top:8px"><?php echo esc_html( o3dai_t( 'test_btn' ) ); ?></button>
								<p class="description"><?php echo esc_html( o3dai_t( 'test_hint' ) ); ?></p>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'webhook_products' ) ); ?> <span class="description" style="font-weight:400"><?php echo esc_html( o3dai_t( 'optional' ) ); ?></span></label>
								<input type="text" name="webhook_products" value="<?php echo esc_attr( $s['webhook_products'] ?? '' ); ?>" placeholder="https://hook.eu2.make.com/…">
								<p class="description"><?php echo wp_kses_post( o3dai_t( 'webhook_products_hint' ) ); ?></p>
								<button type="submit" class="o3d-btn o3d-btn-ghost o3d-btn-sm" name="o3dai_action" value="test_webhook_products" style="margin-top:8px"><?php echo esc_html( o3dai_t( 'test_btn' ) ); ?></button>
								<p class="description"><?php echo esc_html( o3dai_t( 'test_hint' ) ); ?></p>
							</div>
							<div class="o3d-field">
								<div class="o3d-toggle">
									<input type="checkbox" id="o3d-aisoc" name="ai_social_text" <?php checked( ! empty( $s['ai_social_text'] ) ); ?>>
									<label for="o3d-aisoc" class="o3d-lbl" style="margin:0"><?php echo esc_html( o3dai_t( 'ai_social_text_l' ) ); ?></label>
								</div>
								<p class="description"><?php echo wp_kses_post( o3dai_t( 'ai_social_text_hint' ) ); ?></p>
							</div>
							<hr style="border:0;border-top:1px dashed #d8d2ea;margin:18px 0">
							<span class="o3d-lbl" style="display:block;margin-bottom:5px"><?php echo esc_html( o3dai_t( 'share_auto_l' ) ); ?></span>
							<p class="description" style="margin-top:0"><?php echo esc_html( o3dai_t( 'share_auto_hint' ) ); ?></p>
							<div class="o3d-row2" style="margin-bottom:15px">
								<div class="o3d-field" style="margin-bottom:0"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'share_slot', array( 'n' => 1 ) ) ); ?></label><input type="number" name="share_hour1" value="<?php echo esc_attr( $s['share_hour1'] ?? '' ); ?>" min="0" max="23" placeholder="<?php echo esc_attr( o3dai_t( 'share_slot_empty' ) ); ?>"></div>
								<div class="o3d-field" style="margin-bottom:0"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'share_slot', array( 'n' => 2 ) ) ); ?></label><input type="number" name="share_hour2" value="<?php echo esc_attr( $s['share_hour2'] ?? '' ); ?>" min="0" max="23" placeholder="<?php echo esc_attr( o3dai_t( 'share_slot_empty' ) ); ?>"></div>
								<div class="o3d-field" style="margin-bottom:0"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'share_slot', array( 'n' => 3 ) ) ); ?></label><input type="number" name="share_hour3" value="<?php echo esc_attr( $s['share_hour3'] ?? '' ); ?>" min="0" max="23" placeholder="<?php echo esc_attr( o3dai_t( 'share_slot_empty' ) ); ?>"></div>
							</div>
							<?php
							$o3d_unshared = 0;
							$o3d_ptotal   = 0;
							if ( post_type_exists( 'product' ) ) {
								$o3d_unshared = count( (array) get_posts( array(
									'post_type'   => 'product',
									'post_status' => 'publish',
									'fields'      => 'ids',
									'numberposts' => -1,
									'meta_query'  => array( array( 'key' => '_o3dai_shared', 'compare' => 'NOT EXISTS' ) ),
								) ) );
								$o3d_pc     = wp_count_posts( 'product' );
								$o3d_ptotal = isset( $o3d_pc->publish ) ? (int) $o3d_pc->publish : 0;
							}
							?>
							<p style="margin:0"><strong><?php echo esc_html( o3dai_t( 'share_pool', array( 'n' => $o3d_unshared, 't' => $o3d_ptotal ) ) ); ?></strong></p>
							<button type="submit" class="o3d-btn o3d-btn-primary o3d-btn-sm" name="o3dai_action" value="share_now" style="margin-top:10px"><?php echo esc_html( o3dai_t( 'share_now_btn' ) ); ?></button>
							<p class="description"><?php echo esc_html( o3dai_t( 'share_now_hint' ) ); ?></p>
						</div>

						<div class="o3d-card">
							<h2><span class="dashicons dashicons-admin-network"></span> <?php echo esc_html( o3dai_t( 'card_keys' ) ); ?></h2>
							<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'key_claude' ) ); ?></label><input type="password" name="api_key_claude" value="<?php echo esc_attr( $s['api_key_claude'] ); ?>"></div>
							<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'key_openai' ) ); ?></label><input type="password" name="api_key_openai" value="<?php echo esc_attr( $s['api_key_openai'] ); ?>"></div>
							<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'key_gemini' ) ); ?></label><input type="password" name="api_key_gemini" value="<?php echo esc_attr( $s['api_key_gemini'] ); ?>"></div>
							<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'key_openrouter' ) ); ?></label><input type="password" name="api_key_openrouter" value="<?php echo esc_attr( $s['api_key_openrouter'] ?? '' ); ?>"></div>
							<hr style="border:0;border-top:1px dashed #d8d2ea;margin:18px 0">
							<span class="o3d-lbl" style="display:block;margin-bottom:5px"><?php echo esc_html( o3dai_t( 'prov_local' ) ); ?></span>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'text_local_url_l' ) ); ?></label>
								<input type="text" name="text_local_url" value="<?php echo esc_attr( $s['text_local_url'] ?? '' ); ?>" placeholder="http://192.168.0.10:11434">
								<p class="description"><?php echo esc_html( o3dai_t( 'text_local_hint' ) ); ?></p>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'text_local_model_l' ) ); ?></label>
								<select name="text_local_model" id="o3dai-select-text-local-model">
									<option value="" <?php selected( empty( $s['text_local_model'] ?? '' ) ); ?>><?php echo esc_html( o3dai_t( 'local_sel_auto_text' ) ); ?></option>
								<?php if ( ! empty( $s['text_local_model'] ?? '' ) ) : ?>
									<option value="<?php echo esc_attr( $s['text_local_model'] ); ?>" selected><?php echo esc_html( $s['text_local_model'] ); ?></option>
									<?php endif; ?>
								</select>
								<p style="margin:8px 0 0"><button type="button" class="o3d-btn o3d-btn-ghost o3d-btn-sm" id="o3dai-load-local-text-models"><?php echo esc_html( o3dai_t( 'local_models_btn' ) ); ?></button></p>
								<p class="description"><?php echo esc_html( o3dai_t( 'text_local_model_hint' ) ); ?></p>
							</div>
					<hr style="border:0;border-top:1px dashed #d8d2ea;margin:18px 0">
					<span class="o3d-lbl" style="display:block;margin-bottom:5px"><?php echo esc_html( o3dai_t( 'prov_comfy' ) ); ?></span>
					<div class="o3d-field">
						<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'text_comfy_url_l' ) ); ?></label>
						<input type="text" name="text_comfy_url" value="<?php echo esc_attr( $s['text_comfy_url'] ?? '' ); ?>" placeholder="http://192.168.0.10:8188">
						<p class="description"><?php echo esc_html( o3dai_t( 'text_comfy_url_hint' ) ); ?></p>
					</div>
					<div class="o3d-field">
						<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'text_comfy_model_l' ) ); ?></label>
						<select name="text_comfy_model" id="o3dai-select-text-comfy-model">
							<option value="" <?php selected( empty( $s['text_comfy_model'] ?? '' ) ); ?>><?php echo esc_html( o3dai_t( 'local_sel_auto_comfy' ) ); ?></option>
						<?php if ( ! empty( $s['text_comfy_model'] ?? '' ) ) : ?>
							<option value="<?php echo esc_attr( $s['text_comfy_model'] ); ?>" selected><?php echo esc_html( $s['text_comfy_model'] ); ?></option>
							<?php endif; ?>
						</select>
						<p style="margin:8px 0 0"><button type="button" class="o3d-btn o3d-btn-ghost o3d-btn-sm" id="o3dai-load-text-comfy-models"><?php echo esc_html( o3dai_t( 'local_models_btn' ) ); ?></button></p>
						<p class="description"><?php echo esc_html( o3dai_t( 'text_comfy_model_hint' ) ); ?></p>
					</div>
				</div>

						<div class="o3d-card">
							<h2><span class="dashicons dashicons-store"></span> <?php echo esc_html( o3dai_t( 'card_brand' ) ); ?></h2>
							<div class="o3d-row2">
								<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'brand_name' ) ); ?></label><input type="text" name="brand_name" value="<?php echo esc_attr( $s['brand_name'] ?? '' ); ?>"></div>
								<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'brand_desc' ) ); ?></label><input type="text" name="brand_desc" value="<?php echo esc_attr( $s['brand_desc'] ?? '' ); ?>"></div>
							</div>
							<div class="o3d-row2">
								<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'content_lang' ) ); ?></label><input type="text" name="brand_lang" value="<?php echo esc_attr( $s['brand_lang'] ?? '' ); ?>"></div>
								<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'lang_code' ) ); ?></label><input type="text" name="brand_lang_code" value="<?php echo esc_attr( $s['brand_lang_code'] ?? '' ); ?>"></div>
								<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'cta_text' ) ); ?></label><input type="text" name="cta_text" value="<?php echo esc_attr( $s['cta_text'] ?? '' ); ?>"></div>
							</div>
							<div class="o3d-row2">
								<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'image_subject_hint_l' ) ); ?></label><input type="text" name="image_subject_hint" value="<?php echo esc_attr( $s['image_subject_hint'] ?? '' ); ?>"></div>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'image_subjects_l' ) ); ?></label>
								<textarea name="image_subjects" rows="3"><?php echo esc_textarea( $s['image_subjects'] ?? '' ); ?></textarea>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'brand_colors' ) ); ?></label>
								<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
									<label style="font-size:12px"><?php echo esc_html( o3dai_t( 'color_primary' ) ); ?> <input type="color" name="color_primary" value="<?php echo esc_attr( $s['color_primary'] ?? '#6C4AB6' ); ?>"></label>
									<label style="font-size:12px"><?php echo esc_html( o3dai_t( 'color_accent' ) ); ?> <input type="color" name="color_accent" value="<?php echo esc_attr( $s['color_accent'] ?? '#2BC6B4' ); ?>"></label>
									<label style="font-size:12px"><?php echo esc_html( o3dai_t( 'color_accent2' ) ); ?> <input type="color" name="color_accent2" value="<?php echo esc_attr( $s['color_accent2'] ?? '#FFC93C' ); ?>"></label>
									<label style="font-size:12px"><?php echo esc_html( o3dai_t( 'color_dark' ) ); ?> <input type="color" name="color_dark" value="<?php echo esc_attr( $s['color_dark'] ?? '#2B2350' ); ?>"></label>
								</div>
							</div>
							<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=o3dai-wizard' ) ); ?>" class="o3d-btn o3d-btn-ghost" style="text-decoration:none;display:inline-block"><?php echo esc_html( o3dai_t( 'rerun_wizard' ) ); ?></a></p>
						</div>

						<div class="o3d-card">
							<h2><span class="dashicons dashicons-format-image"></span> <?php echo esc_html( o3dai_t( 'card_image' ) ); ?></h2>
							<div class="o3d-row2">
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'image_mode' ) ); ?></label>
									<select name="image_mode">
										<option value="ids" <?php selected( $s['image_mode'], 'ids' ); ?>><?php echo esc_html( o3dai_t( 'mode_ids' ) ); ?></option>
									<option value="ai" <?php selected( $s['image_mode'], 'ai' ); ?>><?php echo esc_html( o3dai_t( 'mode_ai' ) ); ?></option>
						<option value="local" <?php selected( $s['image_mode'], 'local' ); ?>><?php echo esc_html( o3dai_t( 'mode_local' ) ); ?></option>
<option value="stock" <?php selected( $s['image_mode'], 'stock' ); ?>><?php echo esc_html( o3dai_t( 'mode_stock' ) ); ?></option>
										<option value="none" <?php selected( $s['image_mode'], 'none' ); ?>><?php echo esc_html( o3dai_t( 'mode_none' ) ); ?></option>
									</select>
								</div>
<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'image_generator' ) ); ?></label>
									<select name="image_model">
										<optgroup label="<?php echo esc_attr( o3dai_t( 'grp_openai' ) ); ?>">
											<option value="gpt-image-2.5-flare" <?php selected( $s['image_model'], 'gpt-image-2.5-flare' ); ?>><?php echo esc_html( o3dai_t( 'img_gpt25f' ) ); ?></option>
											<option value="gpt-image-2.5-sunburst" <?php selected( $s['image_model'], 'gpt-image-2.5-sunburst' ); ?>><?php echo esc_html( o3dai_t( 'img_gpt25s' ) ); ?></option>
										</optgroup>
										<optgroup label="<?php echo esc_attr( o3dai_t( 'grp_google' ) ); ?>">
											<option value="gemini-3-pro-image" <?php selected( $s['image_model'], 'gemini-3-pro-image' ); ?>><?php echo esc_html( o3dai_t( 'img_gem3pro' ) ); ?></option>
											<option value="gemini-3.1-flash-image-preview" <?php selected( $s['image_model'], 'gemini-3.1-flash-image-preview' ); ?>><?php echo esc_html( o3dai_t( 'img_nb2' ) ); ?></option>
										</optgroup>
									</select>
								</div>
							</div>

							<!-- v1.9.0 – Lokálny PC (Stability Matrix / ComfyUI) -->
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'local_url_l' ) ); ?></label>
								<input type="text" name="image_local_url" value="<?php echo esc_attr( $s['image_local_url'] ?? '' ); ?>" placeholder="http://192.168.0.10:8188">
								<p class="description"><?php echo esc_html( o3dai_t( 'local_hint' ) ); ?></p>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'local_model_l' ) ); ?></label>
								<select name="image_local_model" id="o3dai-select-local-model">
									<option value="" <?php selected( empty( $s['image_local_model'] ?? '' ) ); ?>><?php echo esc_html( o3dai_t( 'local_sel_auto_img' ) ); ?></option>
								<?php if ( ! empty( $s['image_local_model'] ?? '' ) ) : ?>
									<option value="<?php echo esc_attr( $s['image_local_model'] ); ?>" selected><?php echo esc_html( $s['image_local_model'] ); ?></option>
									<?php endif; ?>
								</select>
								<p style="margin:8px 0 0"><button type="button" class="o3d-btn o3d-btn-ghost o3d-btn-sm" id="o3dai-load-local-models"><?php echo esc_html( o3dai_t( 'local_models_btn' ) ); ?></button></p>
							<p class="description"><?php echo esc_html( o3dai_t( 'local_klein_hint' ) ); ?></p>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'local_negative_l' ) ); ?></label>
								<input type="text" name="image_local_negative" value="<?php echo esc_attr( $s['image_local_negative'] ?? '' ); ?>" placeholder="text, watermark, logo">
							</div>
							<div class="o3d-row2">
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'local_steps' ) ); ?></label>
									<input type="number" name="image_local_steps" value="<?php echo esc_attr( $s['image_local_steps'] ?? 20 ); ?>" min="1" max="150">
								</div>
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'local_cfg' ) ); ?></label>
									<input type="number" name="image_local_cfg" value="<?php echo esc_attr( $s['image_local_cfg'] ?? 7 ); ?>" min="1" max="30" step="0.5">
								</div>
							</div>
							<div class="o3d-row2">
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'local_width' ) ); ?></label>
									<input type="number" name="image_local_width" value="<?php echo esc_attr( $s['image_local_width'] ?? 1152 ); ?>" min="64" max="2048">
								</div>
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'local_height' ) ); ?></label>
									<input type="number" name="image_local_height" value="<?php echo esc_attr( $s['image_local_height'] ?? 768 ); ?>" min="64" max="2048">
								</div>
							</div>

							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'stock_provider' ) ); ?></label>
								<select name="stock_provider">
									<option value="pexels" <?php selected( $s['stock_provider'], 'pexels' ); ?>><?php echo esc_html( o3dai_t( 'stock_pexels' ) ); ?></option>
									<option value="pixabay" <?php selected( $s['stock_provider'], 'pixabay' ); ?>><?php echo esc_html( o3dai_t( 'stock_pixabay' ) ); ?></option>
									<option value="openverse" <?php selected( $s['stock_provider'], 'openverse' ); ?>><?php echo esc_html( o3dai_t( 'stock_openverse' ) ); ?></option>
								</select>
								<p class="description"><?php echo esc_html( o3dai_t( 'stock_hint' ) ); ?></p>
							</div>
							<div class="o3d-row2">
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'stock_key_pexels' ) ); ?></label>
									<input type="text" name="stock_key_pexels" value="<?php echo esc_attr( $s['stock_key_pexels'] ?? '' ); ?>" placeholder="pexels.com/api">
								</div>
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'stock_key_pixabay' ) ); ?></label>
									<input type="text" name="stock_key_pixabay" value="<?php echo esc_attr( $s['stock_key_pixabay'] ?? '' ); ?>" placeholder="pixabay.com/api/docs">
								</div>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'stock_query_extra' ) ); ?></label>
								<input type="text" name="stock_query_extra" value="<?php echo esc_attr( $s['stock_query_extra'] ?? '' ); ?>" placeholder="<?php echo esc_attr( o3dai_t( 'stock_query_ph' ) ); ?>">
								<p class="description"><?php echo esc_html( o3dai_t( 'stock_query_hint' ) ); ?></p>
							</div>
							<div class="o3d-field">
								<div class="o3d-toggle">
									<input type="checkbox" id="o3d-optimg" name="optimize_images" <?php checked( $s['optimize_images'] ?? 1, 1 ); ?>>
									<label for="o3d-optimg" class="o3d-lbl" style="margin:0"><?php echo esc_html( o3dai_t( 'optimize_images' ) ); ?> <span class="description" style="font-weight:400">— <?php echo esc_html( o3dai_t( 'optimize_images_hint' ) ); ?></span></label>
								</div>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'body_images' ) ); ?></label>
								<select name="body_images">
									<option value="0" <?php selected( intval( $s['body_images'] ?? 0 ), 0 ); ?>><?php echo esc_html( o3dai_t( 'body_none' ) ); ?></option>
									<option value="1" <?php selected( intval( $s['body_images'] ?? 0 ), 1 ); ?>><?php echo esc_html( o3dai_t( 'body_one' ) ); ?></option>
									<option value="2" <?php selected( intval( $s['body_images'] ?? 0 ), 2 ); ?>><?php echo esc_html( o3dai_t( 'body_two' ) ); ?></option>
								</select>
								<p class="description"><?php echo esc_html( o3dai_t( 'body_img_hint' ) ); ?></p>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'image_style_l' ) ); ?></label>
								<textarea name="image_style" rows="3" placeholder="<?php echo esc_attr( o3dai_t( 'w_ph_style' ) ); ?>"><?php echo esc_textarea( $s['image_style'] ); ?></textarea>
								<p class="description"><?php echo esc_html( o3dai_t( 'image_style_hint' ) ); ?></p>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'featured_ids_l' ) ); ?></label>
								<input type="text" name="featured_ids" value="<?php echo esc_attr( $s['featured_ids'] ); ?>">
								<p class="description"><?php echo esc_html( o3dai_t( 'featured_ids_hint' ) ); ?></p>
							</div>
						</div>

						<div class="o3d-card">
							<h2><span class="dashicons dashicons-admin-links"></span> <?php echo esc_html( o3dai_t( 'card_seo' ) ); ?></h2>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'seo_plugin' ) ); ?></label>
								<?php if ( O3DAI_SEO::label() ) : ?>
								<p class="description" style="color:#1e7e34"><?php echo esc_html( '✅ ' . O3DAI_SEO::label() ); ?></p>
								<?php else : ?>
								<p class="description" style="color:#c0392b"><?php echo esc_html( o3dai_t( 'seo_plugin_none' ) ); ?></p>
								<?php endif; ?>
							</div>
							<div class="o3d-field">
								<div class="o3d-toggle">
									<input type="checkbox" id="o3d-il" name="internal_linking" <?php checked( $s['internal_linking'] ?? 0, 1 ); ?>>
									<label for="o3d-il" class="o3d-lbl" style="margin:0"><?php echo esc_html( o3dai_t( 'link_toggle' ) ); ?> <span class="description" style="font-weight:400">— <?php echo esc_html( o3dai_t( 'link_hint' ) ); ?></span></label>
								</div>
							</div>
							<div class="o3d-field">
								<div class="o3d-toggle">
									<input type="checkbox" id="o3d-pl" name="product_links" <?php checked( $s['product_links'] ?? 1, 1 ); ?>>
									<label for="o3d-pl" class="o3d-lbl" style="margin:0"><?php echo esc_html( o3dai_t( 'product_links' ) ); ?> <span class="description" style="font-weight:400">— <?php echo esc_html( o3dai_t( 'product_links_hint' ) ); ?></span></label>
								</div>
							</div>
<div class="o3d-field">
								<label class="o3d-lbl">Google Search Console <?php echo O3DAI_GSC::is_connected() ? '<span class="o3d-pill">' . esc_html( o3dai_t( 'gsc_connected' ) ) . '</span>' : '<span class="o3d-pill" style="background:#fff4f4;color:#c0392b">' . esc_html( o3dai_t( 'gsc_disconnected' ) ) . '</span>'; ?></label>
								<?php if ( ! O3DAI_GSC::is_connected() ) : ?>
									<p class="description" style="margin-bottom:8px"><?php echo esc_html( o3dai_t( 'gsc_setup_hint' ) ); ?><br>
									<code style="background:#f4f1fb;padding:3px 6px;border-radius:5px;display:inline-block;margin-top:4px"><?php echo esc_html( O3DAI_GSC::redirect_uri() ); ?></code></p>
									<input type="text" name="gsc_client_id" value="<?php echo esc_attr( $s['gsc_client_id'] ?? '' ); ?>" placeholder="Client ID (Google Cloud Console)" style="margin-bottom:6px">
									<input type="password" name="gsc_client_secret" value="<?php echo esc_attr( $s['gsc_client_secret'] ?? '' ); ?>" placeholder="Client Secret">
									<?php if ( ! empty( $s['gsc_client_id'] ) ) : ?>
										<p style="margin-top:10px"><a class="o3d-btn o3d-btn-primary" style="text-decoration:none;display:inline-block" href="<?php echo esc_url( O3DAI_GSC::auth_url() ); ?>"><?php echo esc_html( o3dai_t( 'gsc_login' ) ); ?></a></p>
									<?php else : ?>
										<p class="description"><?php echo esc_html( o3dai_t( 'gsc_after_save' ) ); ?></p>
									<?php endif; ?>
								<?php else : ?>
									<?php $sites = O3DAI_GSC::list_sites(); ?>
									<label class="o3d-lbl" style="font-weight:400;font-size:13px"><?php echo esc_html( o3dai_t( 'gsc_property' ) ); ?></label>
									<?php if ( is_wp_error( $sites ) ) : ?>
										<p class="description" style="color:#c0392b"><?php echo esc_html( $sites->get_error_message() ); ?></p>
										<input type="text" name="gsc_site" value="<?php echo esc_attr( $s['gsc_site'] ?? '' ); ?>" placeholder="sc-domain:yourdomain.com">
									<?php else : ?>
										<select name="gsc_site" style="max-width:520px;width:100%">
											<option value=""><?php echo esc_html( o3dai_t( 'gsc_pick_site' ) ); ?></option>
											<?php foreach ( $sites as $site ) : ?>
												<option value="<?php echo esc_attr( $site ); ?>" <?php selected( $s['gsc_site'] ?? '', $site ); ?>><?php echo esc_html( $site ); ?></option>
											<?php endforeach; ?>
										</select>
									<?php endif; ?>
									<input type="hidden" name="gsc_client_id" value="<?php echo esc_attr( $s['gsc_client_id'] ?? '' ); ?>">
									<input type="hidden" name="gsc_client_secret" value="<?php echo esc_attr( $s['gsc_client_secret'] ?? '' ); ?>">
									<p class="description" style="margin-top:8px"><?php echo esc_html( o3dai_t( 'gsc_auto_note' ) ); ?> <strong><?php echo esc_html( get_option( 'o3dai_gsc_synced', '—' ) ); ?></strong></p>
									<?php $auto = get_option( 'o3dai_gsc_auto', '' ); ?>
									<?php if ( $auto ) : ?>
										<details style="margin-top:8px"><summary style="cursor:pointer;color:#6C4AB6;font-weight:600"><?php echo esc_html( o3dai_t( 'gsc_show' ) ); ?></summary>
										<pre style="background:#faf8ff;border:1px solid #ece9f3;border-radius:8px;padding:10px;font-size:12px;white-space:pre-wrap;max-height:220px;overflow:auto"><?php echo esc_html( $auto ); ?></pre>
										</details>
									<?php endif; ?>
									<div class="o3d-actions" style="margin-top:12px">
										<button class="o3d-btn o3d-btn-ghost" name="o3dai_action" value="gsc_fetch"><?php echo esc_html( o3dai_t( 'gsc_fetch_btn' ) ); ?></button>
										<button class="o3d-btn o3d-btn-warn" name="o3dai_action" value="gsc_disconnect" onclick="return confirm('<?php echo esc_js( o3dai_t( 'gsc_confirm' ) ); ?>')"><?php echo esc_html( o3dai_t( 'gsc_disconnect' ) ); ?></button>
									</div>
								<?php endif; ?>
							</div>
<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'serper_key_l' ) ); ?> <span class="description" style="font-weight:400"><?php echo esc_html( o3dai_t( 'serper_opt' ) ); ?></span></label>
								<input type="password" name="serper_key" value="<?php echo esc_attr( $s['serper_key'] ?? '' ); ?>" placeholder="Serper.dev API key">
								<p class="description"><?php echo esc_html( o3dai_t( 'serper_hint' ) ); ?></p>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'own_queries' ) ); ?> <span class="description" style="font-weight:400"><?php echo esc_html( o3dai_t( 'optional' ) ); ?></span></label>
								<textarea name="gsc_queries" rows="3" placeholder="<?php echo esc_attr( o3dai_t( 'own_queries_ph' ) ); ?>"><?php echo esc_textarea( $s['gsc_queries'] ?? '' ); ?></textarea>
								<p class="description"><?php echo esc_html( o3dai_t( 'own_queries_hint' ) ); ?></p>
							</div>
						</div>

						<div class="o3d-card">
							<h2><span class="dashicons dashicons-megaphone"></span> <?php echo esc_html( o3dai_t( 'card_news' ) ); ?></h2>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'news_keywords_l' ) ); ?></label>
								<input type="text" name="news_keywords" value="<?php echo esc_attr( $s['news_keywords'] ?? '' ); ?>" placeholder="<?php echo esc_attr( o3dai_t( 'news_keywords_ph' ) ); ?>">
								<p class="description"><?php echo esc_html( o3dai_t( 'news_keywords_hint' ) ); ?></p>
								<button type="button" class="button button-small" id="o3d-suggest-kw" style="margin-top:8px"><?php echo esc_html( o3dai_t( 'news_suggest_btn' ) ); ?></button>
								<p class="description" id="o3d-suggest-kw-note" style="display:none;margin-top:6px"></p>
							</div>
							<div class="o3d-row2">
								<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'news_count_l' ) ); ?></label>
									<input type="number" name="news_count" min="3" max="15" value="<?php echo intval( $s['news_count'] ?? 8 ); ?>">
								</div>
								<div class="o3d-field">
									<label class="o3d-lbl">&nbsp;</label>
									<div class="o3d-toggle" style="padding-top:6px">
										<input type="checkbox" id="o3d-news-ai" name="news_use_ai" <?php checked( $s['news_use_ai'] ?? 1, 1 ); ?>>
										<label for="o3d-news-ai" style="margin:0;font-size:13px"><?php echo esc_html( o3dai_t( 'news_use_ai_l' ) ); ?></label>
									</div>
									<p class="description"><?php echo esc_html( o3dai_t( 'news_use_ai_hint' ) ); ?></p>
								</div>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'news_context_l' ) ); ?> <span class="description" style="font-weight:400"><?php echo esc_html( o3dai_t( 'optional' ) ); ?></span></label>
								<textarea name="news_context" rows="2" placeholder="<?php echo esc_attr( o3dai_t( 'news_context_ph' ) ); ?>"><?php echo esc_textarea( $s['news_context'] ?? '' ); ?></textarea>
								<p class="description"><?php echo esc_html( o3dai_t( 'news_context_hint' ) ); ?></p>
							</div>
						</div>

						<div class="o3d-card">
							<h2><span class="dashicons dashicons-edit"></span> <?php echo esc_html( o3dai_t( 'card_instructions' ) ); ?></h2>
							<div class="o3d-field">
								<textarea name="instructions" rows="4"><?php echo esc_textarea( $s['instructions'] ); ?></textarea>
								<p class="description"><?php echo esc_html( o3dai_t( 'instructions_hint' ) ); ?></p>
							</div>
							<div class="o3d-field">
								<div class="o3d-toggle">
									<input type="checkbox" id="o3d-outline" name="use_outline" <?php checked( $s['use_outline'] ?? 1, 1 ); ?>>
									<label for="o3d-outline" class="o3d-lbl" style="margin:0"><?php echo esc_html( o3dai_t( 'outline_toggle' ) ); ?> <span class="description" style="font-weight:400">— <?php echo esc_html( o3dai_t( 'outline_hint' ) ); ?></span></label>
								</div>
							</div>
							<div class="o3d-field">
								<div class="o3d-toggle">
									<input type="checkbox" id="o3d-proof" name="proofread" <?php checked( $s['proofread'] ?? 1, 1 ); ?>>
									<label for="o3d-proof" class="o3d-lbl" style="margin:0"><?php echo esc_html( o3dai_t( 'proof_toggle' ) ); ?> <span class="description" style="font-weight:400">— <?php echo esc_html( o3dai_t( 'proof_hint' ) ); ?></span></label>
								</div>
							</div>
						</div>
					</form>
				<div class="o3d-card o3d-tpl-card">
					<h2><span class="dashicons dashicons-screenoptions"></span> <?php echo esc_html( o3dai_t( 'card_templates' ) ); ?></h2>
					<p class="description"><?php echo esc_html( o3dai_t( 'tpl_hint' ) ); ?></p>
					<?php $o3d_tpls = o3dai_templates(); ?>
					<?php if ( empty( $o3d_tpls ) ) : ?>
						<p class="o3d-tpl-empty"><?php echo esc_html( o3dai_t( 'tpl_empty' ) ); ?></p>
					<?php else : ?>
						<?php foreach ( $o3d_tpls as $o3d_ti => $o3d_tt ) : ?>
						<div class="o3d-tpl-row">
							<span class="o3d-tpl-nm"><?php echo esc_html( $o3d_tt['name'] ?? '' ); ?></span>
							<span class="o3d-tpl-sum"><?php echo esc_html( ( $o3d_tt['data']['provider'] ?? 'claude' ) . ' · ' . ( $o3d_tt['data']['model'] ?? '' ) ); ?></span>
							<span class="o3d-tpl-btns">
								<form method="post" class="o3d-inline">
									<?php wp_nonce_field( 'o3dai' ); ?>
									<input type="hidden" name="o3d_tab" value="settings">
									<input type="hidden" name="tpl" value="<?php echo (int) $o3d_ti; ?>">
									<button type="submit" class="o3d-btn o3d-btn-ghost o3d-btn-sm" name="o3dai_action" value="apply_template"><?php echo esc_html( o3dai_t( 'tpl_apply' ) ); ?></button>
								</form>
								<form method="post" class="o3d-inline" onsubmit="return confirm('<?php echo esc_js( o3dai_t( 'tpl_del_confirm' ) ); ?>');">
									<?php wp_nonce_field( 'o3dai' ); ?>
									<input type="hidden" name="o3d_tab" value="settings">
									<input type="hidden" name="tpl" value="<?php echo (int) $o3d_ti; ?>">
									<button type="submit" class="o3d-btn o3d-btn-ghost o3d-btn-sm o3d-btn-danger" name="o3dai_action" value="del_template"><?php echo esc_html( o3dai_t( 'tpl_del' ) ); ?></button>
								</form>
							</span>
						</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<!-- v1.9.0 – Testy: generovanie obrazku bez clanku -->
		<div class="o3d-tabpanel" data-panel="test" role="tabpanel">
				<div class="o3d-card">
					<h2><span class="dashicons dashicons-experiments"></span> <?php echo esc_html( o3dai_t( 'card_test' ) ); ?></h2>
					<p class="description"><?php echo esc_html( o3dai_t( 'test_hint' ) ); ?></p>
					<div class="o3d-field">
						<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'test_prompt_l' ) ); ?></label>
						<textarea id="o3d-test-prompt" rows="3" placeholder="<?php echo esc_attr( o3dai_t( 'test_prompt_ph' ) ); ?>"></textarea>
					</div>
					<p style="margin:10px 0"><button type="button" class="o3d-btn o3d-btn-primary" id="o3d-test-run">▶ <?php echo esc_html( o3dai_t( 'test_btn' ) ); ?></button></p>
					<div id="o3d-test-out"></div>
				</div>

		<script>
		(function () {
			var btn = document.getElementById( 'o3d-test-run' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				var out = document.getElementById( 'o3d-test-out' );
				var promptEl = document.getElementById( 'o3d-test-prompt' );
				if ( ! promptEl || ! promptEl.value.trim() ) {
					out.innerHTML = '<div class="o3d-card" style="border-color:#e8b7b0;color:#c0392b;padding:12px 16px">' + <?php echo wp_json_encode( o3dai_t( 'test_empty_prompt' ) ); ?> + '</div>';
					return;
				}
				var orig = btn.textContent;
				btn.disabled = true;
				btn.textContent = '<?php echo esc_js( o3dai_t( 'test_busy' ) ); ?>';
				out.innerHTML = '';
				fetch( '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=o3dai_test_image&nonce=<?php echo esc_js( wp_create_nonce( 'o3dai_test' ) ); ?>&prompt=' + encodeURIComponent( promptEl.value.trim() ) } )
					.then(function ( r ) { return r.json(); })
					.then(function ( data ) {
						if ( data.success && data.data.img ) {
							out.innerHTML = '<img src="' + data.data.img + '" alt="" style="max-width:100%;border-radius:12px;border:1px solid #ece9f3;margin-top:4px"><p class="description" style="margin-top:8px">✅ ' + <?php echo wp_json_encode( o3dai_t( 'test_done' ) ); ?> + data.data.secs + '</p>';
						} else {
							out.innerHTML = '<div class="o3d-card" style="border-color:#e8b7b0;color:#c0392b;padding:12px 16px">' + ( data.data.message || '?' ) + '</div>';
						}
					})
					.catch(function () {
						out.innerHTML = '<div class="o3d-card" style="border-color:#e8b7b0;color:#c0392b;padding:12px 16px">⚠️ ' + <?php echo wp_json_encode( o3dai_t( 'test_net_err' ) ); ?> + '</div>';
					})
					.then(function () { btn.disabled = false; btn.textContent = orig; });
			});
		})();
		</script>
		</div>

		<div class="o3d-tabpanel" data-panel="debug" role="tabpanel">
					<div class="o3d-card o3d-debug">
						<h2><span class="dashicons dashicons-search"></span> <?php echo esc_html( o3dai_t( 'card_debug' ) ); ?></h2>
						<div class="o3d-field">
							<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'last_outline' ) ); ?></label>
							<pre><?php echo esc_html( get_option( 'o3dai_last_outline', o3dai_t( 'none_yet_f' ) ) ); ?></pre>
						</div>
						<div class="o3d-field">
							<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'last_img_prompt' ) ); ?></label>
							<pre><?php echo esc_html( get_option( 'o3dai_last_img_prompt', o3dai_t( 'none_yet' ) ) ); ?></pre>
						</div>
						<details><summary><?php echo esc_html( o3dai_t( 'last_raw_l' ) ); ?></summary>
						<pre><?php echo esc_html( get_option( 'o3dai_last_raw', '—' ) ); ?></pre>
						</details>
					</div>
			</div>

		<!-- v1.9.8 – load-models error shown visibly under the button -->
		<script>
		function o3daiLoadErr( btn, msg ) {
			var host = btn.parentNode;
			if ( ! host ) { return; }
			var oldEl = host.querySelector( '.o3d-load-err' );
			if ( oldEl && oldEl.parentNode ) { oldEl.parentNode.removeChild( oldEl ); }
			if ( ! msg ) { return; }
			var p = document.createElement( 'p' );
			p.className = 'o3d-load-err';
			p.style.cssText = 'color:#b3261e;margin:4px 0 0;font-size:12px';
			p.textContent = msg;
			host.appendChild( p );
		}
		</script>
		<script>
		(function () {
			var btn = document.getElementById( 'o3dai-load-local-models' );
			if ( ! btn ) { return; }
			var sel = document.querySelector( 'select[name="image_local_model"]' );
			btn.addEventListener( 'click', function () {
				var urlEl = document.querySelector( 'input[name="image_local_url"]' );
				if ( ! urlEl || ! urlEl.value ) { alert( <?php echo wp_json_encode( o3dai_t( 'local_err_nourl' ) ); ?> ); return; }
				var orig = btn.textContent;
				btn.disabled = true;
				btn.textContent = '⏳ …';
				fetch( '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=o3dai_local_models&nonce=<?php echo esc_js( wp_create_nonce( 'o3dai_local' ) ); ?>&url=' + encodeURIComponent( urlEl.value ) } )
					.then(function ( r ) { return r.json(); })
					.then(function ( data ) {
						if ( ! sel ) { return; }
						var prev = sel.value || '';
						while ( sel.options.length > 1 ) { sel.remove( 1 ); }
						if ( data.success && data.data.models.length > 0 ) {
							data.data.models.forEach(function ( m ) {
								var o = document.createElement( 'option' );
								o.text = m;
								o.value = m;
								sel.appendChild( o );
							} );
							var found = false;
							for ( var i = 0; i < sel.options.length; i++ ) { if ( prev !== '' && sel.options[i].value === prev ) { sel.selectedIndex = i; found = true; break; } }
							if ( ! found ) { sel.value = ''; }
							btn.textContent = '✅ ' + data.data.models.length;
							o3daiLoadErr( btn, '' );
						} else {
							var emsg = '';
							if ( data.success ) {
								emsg = <?php echo wp_json_encode( o3dai_t( 'local_err_no_models' ) ); ?>;
							} else if ( data.data && data.data.message ) {
								emsg = data.data.message;
							}
							o3daiLoadErr( btn, emsg );
							btn.textContent = '⚠️';
						}
					})
					.catch(function () { o3daiLoadErr( btn, <?php echo wp_json_encode( o3dai_t( 'local_err_fetchfail' ) ); ?> ); btn.textContent = '⚠️'; })
					.then(function () {
						btn.disabled = false;
						setTimeout( function () { btn.textContent = orig; }, 4000 );
					} );
			});
		})();
		</script>

		<!-- v1.9.x – Lokálny PC TEXT: načítanie zoznamu modelov zo servera -->
		<script>
		(function () {
			var btn = document.getElementById( 'o3dai-load-local-text-models' );
			if ( ! btn ) { return; }
			var sel = document.querySelector( 'select[name="text_local_model"]' );
			btn.addEventListener( 'click', function () {
				var urlEl = document.querySelector( 'input[name="text_local_url"]' );
				if ( ! urlEl || ! urlEl.value ) { alert( <?php echo wp_json_encode( o3dai_t( 'local_text_err_nourl' ) ); ?> ); return; }
				var orig = btn.textContent;
				btn.disabled = true;
				btn.textContent = '⏳ …';
				fetch( '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=o3dai_local_text_models&nonce=<?php echo esc_js( wp_create_nonce( 'o3dai_local' ) ); ?>&url=' + encodeURIComponent( urlEl.value ) } )
					.then(function ( r ) { return r.json(); })
					.then(function ( data ) {
						if ( ! sel ) { return; }
						var prev = sel.value || '';
						while ( sel.options.length > 1 ) { sel.remove( 1 ); }
						if ( data.success && data.data.models.length > 0 ) {
							data.data.models.forEach(function ( m ) {
								var o = document.createElement( 'option' );
								o.text = m;
								o.value = m;
								sel.appendChild( o );
							} );
							var found = false;
							for ( var i = 0; i < sel.options.length; i++ ) { if ( prev !== '' && sel.options[i].value === prev ) { sel.selectedIndex = i; found = true; break; } }
							if ( ! found ) { sel.value = ''; }
							btn.textContent = '✅ ' + data.data.models.length;
							o3daiLoadErr( btn, '' );
						} else {
							var emsg = '';
							if ( data.success ) {
								emsg = <?php echo wp_json_encode( o3dai_t( 'local_err_no_models' ) ); ?>;
							} else if ( data.data && data.data.message ) {
								emsg = data.data.message;
							}
							o3daiLoadErr( btn, emsg );
							btn.textContent = '⚠️';
						}
					})
					.catch(function () { o3daiLoadErr( btn, <?php echo wp_json_encode( o3dai_t( 'local_err_fetchfail' ) ); ?> ); btn.textContent = '⚠️'; })
					.then(function () {
						btn.disabled = false;
						setTimeout( function () { btn.textContent = orig; }, 4000 );
					} );
			});
		})();
		</script>

		<!-- v1.9.x – Lokálny PC TEXT cez ComfyUI: zoznam textových modelov (models/text_encoders) -->
		<script>
		(function () {
			var btn = document.getElementById( 'o3dai-load-text-comfy-models' );
			if ( ! btn ) { return; }
			var sel = document.querySelector( 'select[name="text_comfy_model"]' );
			btn.addEventListener( 'click', function () {
				var urlEl  = document.querySelector( 'input[name="text_comfy_url"]' );
				var imgUrl = document.querySelector( 'input[name="image_local_url"]' );
				var url    = ( urlEl && urlEl.value ) ? urlEl.value : ( imgUrl ? imgUrl.value : '' ); // fallback: ten istý ComfyUI ako na obrázky
				if ( ! url ) { alert( <?php echo wp_json_encode( o3dai_t( 'local_text_err_nourl' ) ); ?> ); return; }
				var orig = btn.textContent;
				btn.disabled = true;
				btn.textContent = '⏳ …';
				fetch( '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=o3dai_text_comfy_models&nonce=<?php echo esc_js( wp_create_nonce( 'o3dai_local' ) ); ?>&url=' + encodeURIComponent( url ) } )
					.then(function ( r ) { return r.json(); })
					.then(function ( data ) {
						if ( ! sel ) { return; }
						var prev = sel.value || '';
						while ( sel.options.length > 1 ) { sel.remove( 1 ); }
						if ( data.success && data.data.models.length > 0 ) {
							data.data.models.forEach(function ( m ) {
								var o = document.createElement( 'option' );
								o.text = m;
								o.value = m;
								sel.appendChild( o );
							} );
							var found = false;
							for ( var i = 0; i < sel.options.length; i++ ) { if ( prev !== '' && sel.options[i].value === prev ) { sel.selectedIndex = i; found = true; break; } }
							if ( ! found ) { sel.value = ''; }
							btn.textContent = '✅ ' + data.data.models.length;
							o3daiLoadErr( btn, '' );
						} else {
							var emsg = '';
							if ( data.success ) {
								emsg = <?php echo wp_json_encode( o3dai_t( 'local_err_no_models' ) ); ?>;
							} else if ( data.data && data.data.message ) {
								emsg = data.data.message;
							}
							o3daiLoadErr( btn, emsg );
							btn.textContent = '⚠️';
						}
					})
					.catch(function () { o3daiLoadErr( btn, <?php echo wp_json_encode( o3dai_t( 'local_err_fetchfail' ) ); ?> ); btn.textContent = '⚠️'; })
					.then(function () {
						btn.disabled = false;
						setTimeout( function () { btn.textContent = orig; }, 4000 );
					} );
			});
		})();
		</script>

		<?php
	}

	/** v1.8.0 – náhľad článku + regenerácia jednotlivých H2 sekcií */
	public static function article_preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display page, id comes from our own admin URL
		$post_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'post' !== $post->post_type ) {
			echo '<div class="wrap"><p>' . esc_html( o3dai_t( 'preview_missing' ) ) . '</p></div>';
			return;
		}
		$sections = O3DAI_Generator::article_sections( $post->post_content );
		$nonce    = wp_create_nonce( 'o3dai_regen' );
		?>
		<div class="wrap o3d-wrap">
			<style>
				.o3d-article-body h2 { margin: 18px 0 8px; font-size: 19px; color: #2B2350; }
				.o3d-article-body p { margin: 0 0 12px; line-height: 1.65; }
				.o3d-article-body ul, .o3d-article-body ol { margin: 0 0 14px; padding-left: 22px; }
			</style>
			<div class="o3d-hero" style="padding:14px 20px">
				<h1 style="margin-top:0;font-size:20px"><a href="<?php echo esc_url( admin_url( 'admin.php?page=o3dai' ) ); ?>" style="color:#6C4AB6">&larr;</a> 🤖 <?php echo esc_html( get_the_title( $post_id ) ); ?></h1>
				<p class="description"><?php echo esc_html( o3dai_t( 'sections_hint' ) ); ?> &nbsp; <a href="<?php echo esc_url( get_edit_post_link( $post_id, '' ) ); ?>">✏️ <?php echo esc_html( o3dai_t( 'edit_arrow' ) ); ?></a></p>
			</div>
			<div class="o3d-card" style="margin-bottom:16px">
				<p style="margin:0 0 8px;font-size:13px;color:#6c6480"><?php echo esc_html( o3dai_t( 'img_regen_hint' ) ); ?></p>
				<button type="button" class="o3d-btn" id="o3dai-regen-img">🖼️ <?php echo esc_html( o3dai_t( 'btn_regen_image' ) ); ?></button>
			</div>
			<?php if ( ! $sections ) : ?>
				<div class="o3d-card"><p><?php echo esc_html( o3dai_t( 'preview_empty' ) ); ?></p></div>
			<?php else : ?>
				<p style="margin:4px 0 12px;font-size:13px;color:#6c6480"><?php echo esc_html( o3dai_t( 'sections_title' ) ); ?></p>
				<?php foreach ( $sections as $o3d_si => $o3d_sec ) : ?>
					<div class="o3d-card" style="margin-bottom:16px">
						<h2 style="font-size:13px;color:#8b83a3;text-transform:uppercase;letter-spacing:.5px;margin-top:0"><?php echo esc_html( ( 0 === $o3d_si ) ? o3dai_t( 'section_intro' ) : ( intval( $o3d_si ) + 1 ) . '.&nbsp;' ); ?></h2>
						<div class="o3d-article-body"><?php echo wp_kses_post( $o3d_sec ); ?></div>
						<p style="margin-top:14px"><button type="button" class="button o3d-regen-sec" data-idx="<?php echo (int) $o3d_si; ?>">🔄 <?php echo esc_html( o3dai_t( 'btn_regen_section' ) ); ?></button></p>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
			<script>
			(function () {
				var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
				var nonce   = '<?php echo esc_js( $nonce ); ?>';
				var postId  = <?php echo (int) $post_id; ?>;
				var imgBtn = document.getElementById( 'o3dai-regen-img' );
				if ( imgBtn ) {
					imgBtn.addEventListener( 'click', function () {
						if ( imgBtn.disabled ) { return; }
						var label = imgBtn.innerHTML;
						imgBtn.disabled = true;
						imgBtn.textContent = '⏳ <?php echo esc_js( o3dai_t( 'img_regen_busy' ) ); ?>';
						fetch( ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=o3dai_regen_image&nonce=' + encodeURIComponent( nonce ) + '&post_id=' + postId } )
							.then(function ( r ) { return r.json(); })
							.then(function ( data ) {
								if ( data && data.success ) { location.reload(); }
								else { imgBtn.disabled = false; imgBtn.innerHTML = label; alert( ( data && data.data && data.data.message ) ? data.data.message : 'Error' ); }
							})
							.catch(function ( e ) { imgBtn.disabled = false; imgBtn.innerHTML = label; alert( String( e ) ); });
					});
				}
				document.querySelectorAll( '.o3d-regen-sec' ).forEach(function ( btn ) {
					btn.addEventListener( 'click', function () {
						if ( btn.disabled ) { return; }
						var label = btn.innerHTML;
						btn.disabled = true;
						btn.textContent = '⏳ <?php echo esc_js( o3dai_t( 'section_regen_busy' ) ); ?>';
						fetch( ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=o3dai_regen_section&nonce=' + encodeURIComponent( nonce ) + '&post_id=' + postId + '&idx=' + btn.getAttribute( 'data-idx' ) } )
							.then(function ( r ) { return r.json(); })
							.then(function ( data ) {
								if ( data && data.success ) { location.reload(); }
								else { btn.disabled = false; btn.innerHTML = label; alert( ( data && data.data && data.data.message ) ? data.data.message : 'Error' ); }
							})
							.catch(function ( e ) { btn.disabled = false; btn.innerHTML = label; alert( String( e ) ); });
					});
				});
			})();
			</script>
		</div>
		<?php
	}

	/** v1.8.0 – AJAX: prepis jednej sekcie článku */
	public static function ajax_regen_section() {
		check_ajax_referer( 'o3dai_regen', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$idx     = isset( $_POST['idx'] ) ? absint( $_POST['idx'] ) : -1;
		$res     = O3DAI_Generator::regen_section( $post_id, $idx );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => o3dai_t( 'section_done' ) ) );
	}

	/** v1.9.x – AJAX: pregenerovanie ilustračného obrázka článku (anglický prompt odvodený z obsahu) */
	public static function ajax_regen_image() {
		check_ajax_referer( 'o3dai_regen', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ) ); }
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$ok      = O3DAI_Generator::regenerate_image( $post_id );
		if ( ! $ok ) { wp_send_json_error( array( 'message' => o3dai_t( 'img_regen_fail' ) ) ); }
		wp_send_json_success( array( 'message' => o3dai_t( 'img_regen_done' ) ) );
	}

	/** v1.9.x – AJAX: zoznam textových modelov z lokálneho PC (Ollama / LM Studio). */
	public static function ajax_local_text_models() {
		check_ajax_referer( 'o3dai_local', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}
		$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$models = O3DAI_Providers::text_local_models( $url );
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ) );
		}
		wp_send_json_success( array( 'models' => $models ) );
	}

	/** v1.9.x – AJAX: zoznam textových modelov priamo zo ComfyUI (CLIPLoader.clip_name = models/text_encoders). */
	public static function ajax_text_comfy_models() {
		check_ajax_referer( 'o3dai_local', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$models = O3DAI_Providers::text_comfy_models( $url );
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ) );
		}
		wp_send_json_success( array( 'models' => $models ) );
	}

	/** v1.9.0 – AJAX: zoznam checkpointov z lokálneho ComfyUI (Stability Matrix). */
	public static function ajax_local_models() {
		check_ajax_referer( 'o3dai_local', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$models = O3DAI_Providers::local_comfy_models( $url );
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ) );
		}
		wp_send_json_success( array( 'models' => array_slice( $models, 0, 500 ) ) );
	}

	/** v1.9.0 – AJAX: test generovania obrazku bez clanku (zalozka Testy). */
	public static function ajax_test_image() {
		check_ajax_referer( 'o3dai_test', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}
		$s      = o3dai_get_settings();
		$mode   = (string) ( $s['image_mode'] ?? '' );
		if ( ! in_array( $mode, array( 'ai', 'local' ), true ) ) {
			wp_send_json_error( array( 'message' => o3dai_t( 'test_mode_err' ) ) );
		}
		$prompt = trim( sanitize_text_field( wp_unslash( $_POST['prompt'] ?? '' ) ) );
		if ( '' === $prompt ) {
			wp_send_json_error( array( 'message' => o3dai_t( 'test_empty_prompt' ) ) );
		}
		$started = microtime( true );
		$bin     = O3DAI_Providers::generate_image( $prompt );
		if ( is_wp_error( $bin ) ) {
			wp_send_json_error( array( 'message' => $bin->get_error_message() ) );
		}
		$mime   = ( 0 === strpos( (string) $bin, "\x89PNG" ) ) ? 'image/png' : 'image/jpeg';
		wp_send_json_success( array(
			'img'  => 'data:' . $mime . ';base64,' . base64_encode( (string) $bin ),
			'secs' => round( microtime( true ) - $started, 1 ),
		) );
	}
}

O3DAI_Admin::init();
