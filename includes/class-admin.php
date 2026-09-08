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
			if ( 'approve_proposal' === $action && $pid && O3DAI_Products::apply_proposal( $pid ) ) {
				$flag = 'applied';
			} elseif ( 'reject_proposal' === $action && $pid ) {
				O3DAI_Products::reject_proposal( $pid );
				$flag = 'rejected';
			} elseif ( 'remove_proposal' === $action && $pid ) {
				O3DAI_Products::remove_proposal( $pid );
				$flag = 'removed';
			}
			wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_prop=' . $flag . '&o3d_tab=products' ) );
			exit;
		}
		if ( 'approve_all_proposals' === $action ) {
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
		$s['image_mode']     = in_array( sanitize_key( $_POST['image_mode'] ?? 'ids' ), array( 'ids', 'ai', 'stock', 'none' ), true ) ? sanitize_key( $_POST['image_mode'] ) : 'ids';
		$s['image_model']    = sanitize_text_field( wp_unslash( $_POST['image_model'] ?? $s['image_model'] ) );
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
		o3dai_log( 'News: AI navrhla kľúčové slová: ' . implode( ', ', $words ) );
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
		// Free limit dosiahnutý — výzva na upgrade (v1.5.0)
		if ( ! O3DAI_License::is_pro() ) {
			$o3d_limit = get_option( 'o3dai_limit_hit', '' );
			if ( $o3d_limit ) {
				$o3d_scr = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				if ( $o3d_scr && 'toplevel_page_o3dai' === $o3d_scr->id ) {
					echo wp_kses_post( O3DAI_License::upgrade_box( o3dai_t( 'limit_hit_' . $o3d_limit ) ) );
				}
			}
		}
	}

	public static function menu() {
		add_menu_page( 'AI Autopilot', 'AI Autopilot', 'manage_options', 'o3dai', array( __CLASS__, 'page' ), 'dashicons-superhero', 58 );
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
				<h1>🤖 <?php echo esc_html( $s['brand_name'] ?? '' ); ?> <?php echo esc_html( o3dai_t( 'plugin_title' ) ); ?>
												<?php if ( ! O3DAI_License::is_free_build() ) : ?>
<?php if ( O3DAI_License::is_pro() ) : ?>
							<span class="o3d-plan o3d-plan-pro" style="margin-left:10px">★ <?php echo esc_html( o3dai_t( 'plan_pro' ) ); ?></span>
						<?php else : ?>
							<span class="o3d-plan o3d-plan-free" style="margin-left:10px"><?php echo esc_html( o3dai_t( 'plan_free' ) ); ?></span>
						<?php endif; ?>
						<?php endif; ?></h1>
				<div class="o3d-next">📅 <?php echo esc_html( o3dai_t( 'next_run' ) ); ?>: <strong><?php echo $next ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'j.n.Y H:i' ) ) : '—'; ?></strong></div>
			</div>

			
		<!-- Zalozky (v1.4.9) -->
		<div class="o3d-tabbar" role="tablist">
			<button type="button" class="o3d-tab" data-tab="overview"><span class="dashicons dashicons-dashboard"></span> <?php echo esc_html( o3dai_t( 'tab_overview' ) ); ?></button>
			<button type="button" class="o3d-tab" data-tab="topics"><span class="dashicons dashicons-visibility"></span> <?php echo esc_html( o3dai_t( 'tab_topics' ) ); ?></button>
			<button type="button" class="o3d-tab" data-tab="products"><span class="dashicons dashicons-products"></span> <?php echo esc_html( o3dai_t( 'tab_products' ) ); ?></button>
			<button type="button" class="o3d-tab" data-tab="settings"><span class="dashicons dashicons-admin-settings"></span> <?php echo esc_html( o3dai_t( 'tab_settings' ) ); ?></button>
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
					<?php if ( ! O3DAI_License::is_free_build() ) : ?>
<form method="post" class="o3d-action-card">
					<?php wp_nonce_field( 'o3dai' ); ?>
					<input type="hidden" name="o3d_tab" value="overview">
					<h3><?php echo esc_html( o3dai_t( 'act_suggest_series' ) ); ?></h3>
					<p class="o3d-act-desc"><?php echo esc_html( o3dai_t( 'act_series_desc' ) ); ?></p>
					<button type="submit" class="o3d-btn o3d-btn-primary" name="o3dai_action" value="suggest_series"><?php echo esc_html( o3dai_t( 'act_go' ) ); ?></button>
				</form>
				<?php endif; ?>
				</div>
					<?php if ( O3DAI_License::is_free_build() ) : ?>
					<div class="o3d-card" style="border-left:4px solid #6C4AB6">
						<h2><span class="dashicons dashicons-star-filled"></span> <?php echo esc_html( o3dai_t( 'ov_pro_title' ) ); ?></h2>
						<p class="o3d-act-desc" style="margin:0 0 14px"><?php echo esc_html( o3dai_t( 'ov_pro_desc' ) ); ?></p>
						<a class="o3d-btn o3d-btn-primary" style="text-decoration:none;align-self:flex-start" href="<?php echo esc_url( O3DAI_License::upgrade_url() ); ?>">★ <?php echo esc_html( o3dai_t( 'ov_pro_btn' ) ); ?></a>
					</div>
				<?php endif; ?>
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
														<?php if ( ! O3DAI_License::is_pro() ) : $o3d_atm = O3DAI_License::articles_this_month(); ?>
							<div class="o3d-stat" style="<?php echo $o3d_atm >= O3DAI_License::FREE_ARTICLES ? 'background:#fffaf0' : ''; ?>">
								<div class="n"><?php echo esc_html( $o3d_atm ); ?></div>
								<div class="l"><?php echo esc_html( o3dai_t( 'articles_this_month_l' ) . ( $o3d_atm >= O3DAI_License::FREE_ARTICLES ? ' ⚠' : '' ) ); ?></div>
							</div>
							<div class="o3d-stat">
								<div class="n">∞</div>
								<div class="l"><?php echo esc_html( o3dai_t( 'pro_unlimited_l' ) ); ?></div>
							</div>
							<?php endif; ?>
							<div class="o3d-stat total"><div class="n">~$<?php echo esc_html( number_format( $tcost + $icost, 2 ) ); ?></div><div class="l"><?php echo esc_html( o3dai_t( 'u_total' ) ); ?> · <?php echo esc_html( number_format_i18n( ( $u['in_tokens'] ?? 0 ) + ( $u['out_tokens'] ?? 0 ) ) ); ?> <?php echo esc_html( o3dai_t( 'u_tokens' ) ); ?></div></div>
						</div>
						<p class="description" style="margin-top:12px"><?php echo esc_html( o3dai_t( 'u_note' ) ); ?></p>
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
							<?php if ( ! O3DAI_License::is_free_build() ) : ?>
<form method="post" class="o3d-addbar">
							<?php wp_nonce_field( 'o3dai' ); ?>
							<input type="hidden" name="o3d_tab" value="topics">
							<input type="text" name="series_theme" placeholder="<?php echo esc_attr( o3dai_t( 'ph_series' ) ); ?>">
							<button class="o3d-btn o3d-btn-ghost" name="o3dai_action" value="series"><?php echo esc_html( o3dai_t( 'act_make_series' ) ); ?></button>
						</form>
						<?php endif; ?>
							<?php if ( ! O3DAI_License::is_free_build() ) : ?>
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
						<?php endif; ?>
							<?php if ( ! O3DAI_License::is_free_build() ) : ?>
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
						<?php endif; ?>
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
									$article = ! empty( $t['post_id'] ) ? '<a href="' . esc_url( get_edit_post_link( $t['post_id'] ) ) . '">' . esc_html( o3dai_t( 'edit_arrow' ) ) . '</a>' : '—';
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
									<?php echo $o3d_topic_row( $o3d_p[0], $o3d_p[1] ); ?>
								<?php endforeach; ?>
								<?php echo $o3d_pager_row( 'p', $o3d_pp, $o3d_p_pages ); ?>
								<?php endif; ?>
								<?php if ( $o3d_sections['done'] ) : ?>
								<tr class="o3d-sec"><td colspan="4"><span class="o3d-sech">✅ <?php echo esc_html( o3dai_t( 'q_sec_published' ) ); ?></span> <span class="o3d-pill"><?php echo esc_html( count( $o3d_sections['done'] ) ); ?></span></td></tr>
								<?php foreach ( $o3d_done_view as $o3d_p ) : ?>
									<?php echo $o3d_topic_row( $o3d_p[0], $o3d_p[1] ); ?>
								<?php endforeach; ?>
								<?php echo $o3d_pager_row( 'd', $o3d_dp, $o3d_d_pages ); ?>
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
						<?php $is_pro = O3DAI_License::is_pro(); ?>
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
									<label class="o3d-lbl">AI provider (text)</label>
									<select name="provider" id="o3d-provider">
										<option value="claude" <?php selected( $s['provider'], 'claude' ); ?>>Anthropic Claude</option>
										<option value="openai" <?php selected( $s['provider'], 'openai' ); ?>>OpenAI</option>
										<option value="gemini" <?php selected( $s['provider'], 'gemini' ); ?>>Google Gemini</option>
										<option value="openrouter" <?php selected( $s['provider'], 'openrouter' ); ?>>OpenRouter (200+ modelov, 1 kľúč)</option>
									</select>
								</div>
								<div class="o3d-field">
									<label class="o3d-lbl">Model</label>
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
									<?php if ( ! O3DAI_License::is_pro() && ! O3DAI_License::is_free_build() ) : ?>
										<div style="font-size:11px;color:#646970;margin-top:4px"><?php echo esc_html( o3dai_t( 'free_publish_hint' ) ); ?></div>
									<?php endif; ?>
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
								<?php if ( ! O3DAI_License::is_free_build() && ! function_exists( 'o3dai_fs' ) ) : ?>
								<div class="o3d-field">
									<label class="o3d-lbl">&nbsp;</label>
									<div class="o3d-toggle" style="padding-top:6px">
										<input type="checkbox" id="o3d-simfree" name="simulate_free" <?php checked( $s['simulate_free'] ?? 0, 1 ); ?>>
										<label for="o3d-simfree" style="margin:0;font-size:13px"><?php echo esc_html( o3dai_t( 'sim_free' ) ); ?></label>
									</div>
									<p class="description"><?php echo esc_html( o3dai_t( 'sim_free_hint' ) ); ?></p>
								</div>
								<?php else : ?>
								<div class="o3d-field"></div>
								<?php endif; ?>
							</div>
						</div>

							<?php if ( ! O3DAI_License::is_free_build() ) : ?>
<div class="o3d-card">
							<h2><span class="dashicons dashicons-share"></span> <?php echo esc_html( o3dai_t( 'card_social' ) ); ?> <?php if ( ! O3DAI_License::is_pro() ) { echo wp_kses_post( O3DAI_License::pro_badge() ); } ?></h2>
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
						<?php endif; ?>

						<div class="o3d-card">
							<h2><span class="dashicons dashicons-admin-network"></span> <?php echo esc_html( o3dai_t( 'card_keys' ) ); ?></h2>
							<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'key_claude' ) ); ?></label><input type="password" name="api_key_claude" value="<?php echo esc_attr( $s['api_key_claude'] ); ?>"></div>
							<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'key_openai' ) ); ?></label><input type="password" name="api_key_openai" value="<?php echo esc_attr( $s['api_key_openai'] ); ?>"></div>
							<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'key_gemini' ) ); ?></label><input type="password" name="api_key_gemini" value="<?php echo esc_attr( $s['api_key_gemini'] ); ?>"></div>
							<div class="o3d-field"><label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'key_openrouter' ) ); ?></label><input type="password" name="api_key_openrouter" value="<?php echo esc_attr( $s['api_key_openrouter'] ?? '' ); ?>"></div>
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
											<?php if ( ! O3DAI_License::is_free_build() ) : ?>
									<option value="ai" <?php selected( $s['image_mode'], 'ai' ); ?>><?php echo esc_html( o3dai_t( 'mode_ai' ) . ( O3DAI_License::is_pro() ? '' : ' — PRO' ) ); ?></option>
										<?php endif; ?>										<option value="stock" <?php selected( $s['image_mode'], 'stock' ); ?>><?php echo esc_html( o3dai_t( 'mode_stock' ) ); ?></option>
										<option value="none" <?php selected( $s['image_mode'], 'none' ); ?>><?php echo esc_html( o3dai_t( 'mode_none' ) ); ?></option>
									<?php if ( ! O3DAI_License::is_pro() && in_array( $s['image_mode'], array( 'ai', 'none' ), true ) ) : ?>
										<div style="font-size:11px;color:#646970;margin-top:4px"><?php echo esc_html( o3dai_t( 'free_images_hint' ) ); ?></div>
									<?php endif; ?>
									</select>
								</div>
									<?php if ( ! O3DAI_License::is_free_build() ) : ?>
<div class="o3d-field">
									<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'image_generator' ) ); ?></label>
									<select name="image_model">
										<optgroup label="<?php echo esc_attr( o3dai_t( 'grp_openai' ) ); ?>">
											<option value="gpt-image-1" <?php selected( $s['image_model'], 'gpt-image-1' ); ?>><?php echo esc_html( o3dai_t( 'img_gpt1' ) ); ?></option>
											<option value="gpt-image-1-mini" <?php selected( $s['image_model'], 'gpt-image-1-mini' ); ?>><?php echo esc_html( o3dai_t( 'img_gpt1mini' ) ); ?></option>
										</optgroup>
										<optgroup label="<?php echo esc_attr( o3dai_t( 'grp_google' ) ); ?>">
											<option value="gemini-2.5-flash-image" <?php selected( $s['image_model'], 'gemini-2.5-flash-image' ); ?>><?php echo esc_html( o3dai_t( 'img_nb1' ) ); ?></option>
											<option value="gemini-3.1-flash-image-preview" <?php selected( $s['image_model'], 'gemini-3.1-flash-image-preview' ); ?>><?php echo esc_html( o3dai_t( 'img_nb2' ) ); ?></option>
										</optgroup>
									</select>
								</div>
								<?php endif; ?>
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
								<textarea name="image_style" rows="3"><?php echo esc_textarea( $s['image_style'] ); ?></textarea>
								<p class="description"><?php echo esc_html( o3dai_t( 'image_style_hint' ) ); ?></p>
							</div>
							<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'featured_ids_l' ) ); ?></label>
								<input type="text" name="featured_ids" value="<?php echo esc_attr( $s['featured_ids'] ); ?>">
								<p class="description"><?php echo esc_html( o3dai_t( 'featured_ids_hint' ) ); ?></p>
							</div>
						</div>

						<div class="o3d-card">
							<h2><span class="dashicons dashicons-admin-links"></span> <?php echo esc_html( o3dai_t( 'card_seo' ) ); ?> <?php if ( ! O3DAI_License::is_pro() ) { echo wp_kses_post( O3DAI_License::pro_badge() ); } ?></h2>
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
								<?php if ( ! O3DAI_License::is_free_build() ) : ?>
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
							<?php endif; ?>
								<?php if ( ! O3DAI_License::is_free_build() ) : ?>
<div class="o3d-field">
								<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'serper_key_l' ) ); ?> <span class="description" style="font-weight:400"><?php echo esc_html( o3dai_t( 'serper_opt' ) ); ?></span></label>
								<input type="password" name="serper_key" value="<?php echo esc_attr( $s['serper_key'] ?? '' ); ?>" placeholder="Serper.dev API key">
								<p class="description"><?php echo esc_html( o3dai_t( 'serper_hint' ) ); ?></p>
							</div>
							<?php endif; ?>
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

			<div class="o3d-tabpanel" data-panel="debug" role="tabpanel">
					<div class="o3d-card o3d-debug">
						<h2><span class="dashicons dashicons-search"></span> <?php echo esc_html( o3dai_t( 'card_debug' ) ); ?></h2>
						<div class="o3d-field">
							<label class="o3d-lbl"><?php echo esc_html( o3dai_t( 'last_outline' ) ); ?></label>
							<pre><?php echo esc_html( get_option( 'o3dai_last_outline', '— zatiaľ žiadna —' ) ); ?></pre>
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

		<?php
	}
}

O3DAI_Admin::init();
