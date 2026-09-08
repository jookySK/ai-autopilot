<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Vyplnenie produktu z fotografie – názov, popisy, kategórie, SEO polia (Yoast/Rank Math/SEOPress/AIOSEO).
 */
class O3DAI_Products {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_action( 'admin_action_o3dai_fill_product', array( __CLASS__, 'handle_fill' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_register' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'bulk_handle' ), 10, 3 );
		add_action( 'o3dai_fill_product_job', array( __CLASS__, 'fill_from_image' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		// kategórie produktov
		add_filter( 'product_cat_row_actions', array( __CLASS__, 'cat_row_action' ), 10, 2 );
		add_action( 'admin_action_o3dai_fill_cat', array( __CLASS__, 'handle_fill_cat' ) );
		add_action( 'o3dai_fill_cat_job', array( __CLASS__, 'fill_category' ) );
		// Hromadná úprava produktov — náhľad + schválenie (v1.4.11)
		add_action( 'admin_action_o3dai_propose', array( __CLASS__, 'handle_propose' ) );
		add_action( 'o3dai_propose_product_job', array( __CLASS__, 'propose_product' ) );
	}

	public static function metabox() {
		if ( ! post_type_exists( 'product' ) || O3DAI_License::is_free_build() ) {
			return;
		}
		add_meta_box( 'o3dai_product', o3dai_t( 'p_box_title' ), array( __CLASS__, 'render_box' ), 'product', 'side', 'high' );
	}

	public static function render_box( $post ) {
		$has_img = (bool) get_post_thumbnail_id( $post->ID );
		$url     = wp_nonce_url( admin_url( 'admin.php?action=o3dai_fill_product&post=' . $post->ID ), 'o3dai_fill_' . $post->ID );
		echo '<p style="margin-top:0">' . esc_html( o3dai_t( 'p_box_desc' ) ) . '</p>';
		if ( $has_img ) {
			echo '<a href="' . esc_url( $url ) . '" class="button button-primary" style="width:100%;text-align:center">' . esc_html( o3dai_t( 'p_fill_btn' ) ) . '</a>';
			echo '<p class="description" style="margin-top:8px">' . esc_html( o3dai_t( 'p_bg_note' ) ) . '</p>';
		} else {
			echo '<p style="color:#c00"><strong>' . esc_html( o3dai_t( 'p_need_image' ) ) . '</strong></p>';
		}
	}

	public static function row_action( $actions, $post ) {
		if ( 'product' === $post->post_type && ! O3DAI_License::is_free_build() && current_user_can( 'edit_post', $post->ID ) ) {
			$url = wp_nonce_url( admin_url( 'admin.php?action=o3dai_fill_product&post=' . $post->ID ), 'o3dai_fill_' . $post->ID );
			$actions['o3dai_fill'] = '<a href="' . esc_url( $url ) . '">' . esc_html( o3dai_t( 'p_row_fill' ) ) . '</a>';
			$url2 = wp_nonce_url( admin_url( 'admin.php?action=o3dai_propose&post=' . $post->ID ), 'o3dai_propose_' . $post->ID );
			$actions['o3dai_propose'] = '<a href="' . esc_url( $url2 ) . '">' . esc_html( o3dai_t( 'p_row_propose' ) ) . '</a>';
		}
		return $actions;
	}

	public static function handle_fill() {
		$post_id = intval( $_GET['post'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! check_admin_referer( 'o3dai_fill_' . $post_id ) ) {
			wp_die( esc_html( o3dai_t( 'bad_request' ) ) );
		}
		if ( ! O3DAI_License::has_api_key() ) {
			wp_safe_redirect( add_query_arg( 'o3dai_nokey', 1, wp_get_referer() ?: admin_url( 'edit.php?post_type=product' ) ) );
			exit;
		}
		O3DAI_Progress::start( 'product_' . $post_id );
		wp_schedule_single_event( time() + 1, 'o3dai_fill_product_job', array( $post_id ) );
		spawn_cron();
		$back = wp_get_referer() ?: admin_url( 'edit.php?post_type=product' );
		wp_safe_redirect( add_query_arg( 'o3dai_watch', 'product_' . $post_id, $back ) );
		exit;
	}

	public static function bulk_register( $actions ) {
		if ( O3DAI_License::is_free_build() ) {
			return $actions; // AI vyplnenie produktov je Pro funkcia — hromadné akcie v FREE buildi skryté
		}
		$actions['o3dai_fill_bulk'] = o3dai_t( 'p_bulk_fill' );
		$actions['o3dai_propose_bulk'] = o3dai_t( 'p_bulk_propose' );
		return $actions;
	}

	public static function bulk_handle( $redirect, $doaction, $ids ) {
		if ( 'o3dai_fill_bulk' === $doaction ) {
			$i = 0; $watch = array();
			foreach ( $ids as $pid ) {
				$pid = intval( $pid );
				O3DAI_Progress::start( 'product_' . $pid );
				$watch[] = 'product_' . $pid;
				wp_schedule_single_event( time() + 5 + ( $i * 45 ), 'o3dai_fill_product_job', array( $pid ) );
				$i ++;
			}
			spawn_cron();
			$redirect = add_query_arg( 'o3dai_watch', implode( ',', $watch ), $redirect );
		}

		if ( 'o3dai_propose_bulk' === $doaction ) {
			$i = 0; $watch = array();
			foreach ( $ids as $pid ) {
				$pid = intval( $pid );
				O3DAI_Progress::start( 'prop_' . $pid );
				$watch[] = 'prop_' . $pid;
				wp_schedule_single_event( time() + 5 + ( $i * 45 ), 'o3dai_propose_product_job', array( $pid ) );
				$i ++;
			}
			spawn_cron();
			// Výsledky sa schvaľujú na záložke Produkty (progress sleduje o3dai_watch)
			$redirect = admin_url( 'admin.php?page=o3dai&o3d_tab=products&o3dai_prop=ready&o3dai_watch=' . implode( ',', $watch ) );
		}
		return $redirect;
	}

	public static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect flags set by our own nonce-protected admin actions
		if ( ! empty( $_GET['o3dai_filled'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( o3dai_t( 'p_notice_bg' ) ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect flag set by our own nonce-protected admin action
		if ( ! empty( $_GET['o3dai_nokey'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( o3dai_t( 'no_api_key' ) ) . '</p></div>';
		}
	}

	public static function cat_row_action( $actions, $term ) {
		if ( ! O3DAI_License::is_free_build() && current_user_can( 'manage_categories' ) ) {
			$url = wp_nonce_url( admin_url( 'admin.php?action=o3dai_fill_cat&term=' . $term->term_id ), 'o3dai_cat_' . $term->term_id );
			$actions['o3dai_fill_cat'] = '<a href="' . esc_url( $url ) . '">' . esc_html( o3dai_t( 'c_row_fill' ) ) . '</a>';
		}
		return $actions;
	}

	public static function handle_fill_cat() {
		$term_id = intval( $_GET['term'] ?? 0 );
		if ( ! $term_id || ! current_user_can( 'manage_categories' ) || ! check_admin_referer( 'o3dai_cat_' . $term_id ) ) {
			wp_die( esc_html( o3dai_t( 'bad_request' ) ) );
		}
		if ( ! O3DAI_License::has_api_key() ) {
			wp_safe_redirect( add_query_arg( 'o3dai_nokey', 1, wp_get_referer() ?: admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ) );
			exit;
		}
		O3DAI_Progress::start( 'cat_' . $term_id );
		wp_schedule_single_event( time() + 1, 'o3dai_fill_cat_job', array( $term_id ) );
		spawn_cron();
		$back = wp_get_referer() ?: admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' );
		wp_safe_redirect( add_query_arg( 'o3dai_watch', 'cat_' . $term_id, $back ) );
		exit;
	}

	/** Vygeneruje popis kategórie + Yoast SEO polia pre taxonómiu */
	public static function fill_category( $term_id ) {
		if ( ! O3DAI_License::feature( 'categories' ) ) {
			o3dai_log( o3dai_t( 'pro_locked' ) );
			return;
		}
		$term_id = intval( $term_id );
		$term    = get_term( $term_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$s = o3dai_get_settings();

		// produkty v kategórii – aby popis vychádzal z reálnej ponuky
		$names = array();
		foreach ( get_posts( array(
			'post_type'   => 'product',
			'numberposts' => 15,
			'post_status' => 'publish',
			'tax_query'   => array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $term_id ) ),
		) ) as $p ) {
			$names[] = $p->post_title;
		}

		o3dai_log( 'Vypĺňam kategóriu „' . $term->name . '"…' );

		$system = 'Si copywriter a SEO špecialista pre e-shop. ' . $s['instructions'] . ' '
			. 'Píšeš text kategórie, ktorý má uspieť vo vyhľadávaní a zároveň pomôcť zákazníkovi vybrať. '
			. 'Odpovedaj PRESNE v tomto formáte, bez textu pred a po:' . "\n"
			. '===DESCRIPTION===' . "\n" . '(HTML: 2-3 odseky <p>. Prvý o tom, čo v kategórii nájdu a pre koho je vhodná; druhý o materiáli a bezpečnosti; tretí praktická rada pri výbere. Môžeš použiť aj <ul><li> so 3-4 tipmi.)' . "\n"
			. '===META_TITLE===' . "\n" . '(max 58 znakov)' . "\n"
			. '===META_DESC===' . "\n" . '(max 155 znakov)' . "\n"
			. '===FOCUS_KW===' . "\n" . '(2-4 slová, čo ľudia zadávajú do Googlu)' . "\n"
			. '===END===';

		$user = 'Kategória produktov: ' . $term->name . "\n"
			. 'Web: ' . get_bloginfo( 'name' ) . ' – ' . get_bloginfo( 'description' ) . "\n"
			. 'Počet produktov v kategórii: ' . intval( $term->count ) . "\n\n"
			. ( $names ? "Produkty v tejto kategórii:\n- " . implode( "\n- ", $names ) . "\n\n" : '' )
			. 'Napíš text kategórie. Vychádzaj z reálnych produktov vyššie, nevymýšľaj sortiment, ktorý tam nie je.';

		$raw = O3DAI_Providers::generate( $system, $user, 2000 );
		if ( is_wp_error( $raw ) ) {
			o3dai_log( 'Kategória „' . $term->name . '" – chyba: ' . $raw->get_error_message() );
			return;
		}
		update_option( 'o3dai_last_raw', mb_substr( (string) $raw, 0, 6000 ), false );

		$a = array();
		if ( preg_match_all( '/===([A-Z_]+)===\s*(.*?)(?====[A-Z_]+===|$)/s', (string) $raw, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $seg ) {
				if ( 'END' !== $seg[1] ) {
					$a[ $seg[1] ] = trim( $seg[2] );
				}
			}
		}
		if ( empty( $a['DESCRIPTION'] ) ) {
			o3dai_log( 'Kategória „' . $term->name . '": odpoveď sa nepodarilo naparsovať.' );
			return;
		}

		if ( ! empty( $s['proofread'] ) ) {
			$a['DESCRIPTION'] = O3DAI_Providers::proofread( $a['DESCRIPTION'] );
		}
		wp_update_term( $term_id, 'product_cat', array( 'description' => wp_kses_post( $a['DESCRIPTION'] ) ) );

		// SEO pre taxonómiu – naplní všetky aktívne SEO pluginy
		O3DAI_SEO::set_term( 'product_cat', $term_id, $a['META_TITLE'] ?? '', $a['META_DESC'] ?? '', $a['FOCUS_KW'] ?? '' );

		// obrázok kategórie (rovnaký štýl ako featured obrázky článkov)
		self::category_image( $term_id, $term->name, $names );

		o3dai_track_kind( 'categories' );
		O3DAI_Progress::done( 'cat_' . $term_id, true );
		o3dai_log( 'Kategória „' . $term->name . '" vyplnená (popis + SEO).' );
	}

	/** Vygeneruje a priradí obrázok kategórie (WooCommerce thumbnail taxonómie) */
	public static function category_image( $term_id, $cat_name, $product_names = array() ) {
		$s = o3dai_get_settings();
		if ( 'ai' !== ( $s['image_mode'] ?? 'ids' ) ) {
			return; // obrázky generujeme len keď je zapnutý AI režim
		}
		// námet: z názvov produktov v kategórii poskladáme scénu
		$subject = $cat_name;
		if ( $product_names ) {
			$subject = 'a cheerful collection of ' . $cat_name . ' – ' . implode( ', ', array_slice( $product_names, 0, 4 ) );
		}
		$prompt = 'Category banner image for an online shop (' . ( $s['brand_desc'] ?? '' ) . '). '
			. 'Subject: ' . $subject . ', arranged together as a group. '
			. 'Composition: several items grouped nicely, wide banner-friendly layout, plenty of soft background space. '
			. 'Style: ' . stripslashes( $s['image_style'] );
		update_option( 'o3dai_last_img_prompt', $prompt, false );

		$png = O3DAI_Providers::generate_image( $prompt );
		if ( is_wp_error( $png ) ) {
			o3dai_log( 'Obrázok kategórie „' . $cat_name . '": ' . $png->get_error_message() );
			return;
		}
		$att = O3DAI_Generator::attach_image( 0, $png, $cat_name . ' – kategória' );
		if ( ! $att ) {
			o3dai_log( 'Obrázok kategórie „' . $cat_name . '": uloženie zlyhalo.' );
			return;
		}
		update_term_meta( $term_id, 'thumbnail_id', intval( $att ) ); // WooCommerce obrázok kategórie
		o3dai_log( 'Obrázok kategórie „' . $cat_name . '" vytvorený.' );
	}

	/** Zmenší obrázok, aby sme neposielali zbytočne veľké dáta do API */
	private static function prepare_image( $att_id ) {
		$file = get_attached_file( $att_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}
		$editor = wp_get_image_editor( $file );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 1024, 1024, false );
			$tmp = wp_tempnam( 'o3dai-img' );
			$saved = $editor->save( $tmp, 'image/jpeg' );
			if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
				$data = file_get_contents( $saved['path'] );
				wp_delete_file( $saved['path'] );
				if ( file_exists( $tmp ) ) { wp_delete_file( $tmp ); }
				return array( 'data' => $data, 'mime' => 'image/jpeg' );
			}
		}
		$data = file_get_contents( $file );
		$mime = get_post_mime_type( $att_id ) ?: 'image/jpeg';
		return $data ? array( 'data' => $data, 'mime' => $mime ) : null;
	}

	/** Hlavná úloha: analyzuj fotku a vyplň produkt */
	public static function fill_from_image( $post_id ) {
		if ( ! O3DAI_License::feature( 'products' ) ) {
			O3DAI_Progress::done( 'product_' . intval( $post_id ), false );
			o3dai_log( o3dai_t( 'pro_locked' ) );
			return;
		}
		$post_id = intval( $post_id );
		$product = get_post( $post_id );
		if ( ! $product || 'product' !== $product->post_type ) {
			return;
		}
		$att_id = get_post_thumbnail_id( $post_id );
		if ( ! $att_id ) {
			o3dai_log( 'Produkt #' . $post_id . ': chýba hlavný obrázok.' );
			O3DAI_Progress::done( 'product_' . $post_id, false );
			return;
		}
		o3dai_log( 'Vypĺňam produkt #' . $post_id . ' z fotiek…' );

		// hlavný obrázok + galéria (max 4 spolu, nech nemíňame veľa)
		$att_ids = array( $att_id );
		$gallery = get_post_meta( $post_id, '_product_image_gallery', true );
		if ( $gallery ) {
			foreach ( array_filter( array_map( 'intval', explode( ',', $gallery ) ) ) as $gid ) {
				if ( ! in_array( $gid, $att_ids, true ) ) {
					$att_ids[] = $gid;
				}
			}
		}
		$att_ids = array_slice( $att_ids, 0, 4 );

		$imgs = array();
		foreach ( $att_ids as $aid ) {
			$one = self::prepare_image( $aid );
			if ( $one ) {
				$imgs[] = $one;
			}
		}
		if ( ! $imgs ) {
			o3dai_log( 'Produkt #' . $post_id . ': obrázky sa nepodarilo načítať.' );
			O3DAI_Progress::done( 'product_' . $post_id, false );
			return;
		}

		$s = o3dai_get_settings();

		// existujúce kategórie, nech AI vyberá z reálnych
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'names' ) );
		$cats = is_wp_error( $cats ) ? array() : $cats;

		// ukážka existujúcich popisov, aby sedel tón
		$samples = array();
		foreach ( get_posts( array( 'post_type' => 'product', 'numberposts' => 3, 'post_status' => 'publish', 'post__not_in' => array( $post_id ) ) ) as $p ) {
			$txt = trim( wp_strip_all_tags( $p->post_content ) );
			if ( $txt ) {
				$samples[] = $p->post_title . ': ' . mb_substr( $txt, 0, 300 );
			}
		}

		$existing_title = trim( $product->post_title );
		$existing_price = get_post_meta( $post_id, '_regular_price', true );

		$system = 'Si copywriter pre e-shop. ' . $s['instructions'] . ' '
			. 'Pozri sa na fotografiu produktu a napíš preň kompletné údaje. '
			. 'Odpovedaj PRESNE v tomto formáte s oddeľovačmi, bez textu pred a po:' . "\n"
			. '===NAME===' . "\n" . '(pútavý názov produktu, 3-8 slov, bez emoji)' . "\n"
			. '===SHORT===' . "\n" . '(krátky popis, 1-2 vety, do 160 znakov)' . "\n"
			. '===DESCRIPTION===' . "\n" . '(plný popis: 2 odseky oddelené prázdnym riadkom – prvý o tom, čo to je a čím poteší, druhý o materiáli, bezpečnosti a pre koho je vhodný)' . "\n"
			. '===CATEGORIES===' . "\n" . '(1-2 najpresnejšie kategórie oddelené čiarkou, VÝHRADNE zo zoznamu nižšie. Vyber len tie, ktoré naozaj sedia – radšej jednu presnú než tri približné. Kategóriu pre vzdelávacie/Montessori pomôcky priraď LEN ak je produkt naozaj edukatívna pomôcka, nie bežná figúrka.)' . "\n"
			. '===TAGS===' . "\n" . '(4-7 značiek oddelených čiarkou: druh produktu, materiál, vlastnosti, príležitosť. Malými písmenami.)' . "\n"
			. '===META_TITLE===' . "\n" . '(max 58 znakov)' . "\n"
			. '===META_DESC===' . "\n" . '(max 155 znakov)' . "\n"
			. '===FOCUS_KW===' . "\n" . '(2-4 slová, čo by človek zadal do Googlu)' . "\n"
			. '===END===';

		$user = 'Fotografie produktu sú v prílohe (' . count( $imgs ) . ' – hlavná plus varianty/detaily z galérie; opíš produkt komplexne vrátane farebných variantov, ak sú).' . "\n\n"
			. ( $existing_title ? 'Pracovný názov (môžeš vylepšiť): ' . $existing_title . "\n" : '' )
			. ( $existing_price ? 'Cena: ' . $existing_price . ' €' . "\n" : '' )
			. "\n" . 'DOSTUPNÉ KATEGÓRIE (vyber len z nich):' . "\n- " . implode( "\n- ", $cats ) . "\n\n"
			. ( $samples ? 'UKÁŽKY EXISTUJÚCICH POPISOV (drž sa rovnakého tónu):' . "\n" . implode( "\n\n", $samples ) . "\n\n" : '' )
			. 'Popíš presne to, čo vidíš na fotke – farby, tvar, detaily. Nevymýšľaj vlastnosti, ktoré na fotke nie sú.';

		$raw = O3DAI_Providers::generate_vision( $system, $user, $imgs, 'image/jpeg', 2800 );
		if ( is_wp_error( $raw ) ) {
			o3dai_log( 'Produkt #' . $post_id . ' – chyba: ' . $raw->get_error_message() );
			O3DAI_Progress::done( 'product_' . $post_id, false );
			return;
		}
		update_option( 'o3dai_last_raw', mb_substr( (string) $raw, 0, 6000 ), false );

		$a = array();
		if ( preg_match_all( '/===([A-Z_]+)===\s*(.*?)(?====[A-Z_]+===|$)/s', (string) $raw, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $seg ) {
				if ( 'END' !== $seg[1] ) {
					$a[ $seg[1] ] = trim( $seg[2] );
				}
			}
		}
		if ( empty( $a['NAME'] ) || empty( $a['DESCRIPTION'] ) ) {
			o3dai_log( 'Produkt #' . $post_id . ': odpoveď sa nepodarilo naparsovať.' );
			O3DAI_Progress::done( 'product_' . $post_id, false );
			return;
		}

		$s2 = o3dai_get_settings();
		if ( ! empty( $s2['proofread'] ) ) {
			$a['DESCRIPTION'] = O3DAI_Providers::proofread( $a['DESCRIPTION'] );
			$a['SHORT']       = O3DAI_Providers::proofread( $a['SHORT'] ?? '' );
		}

		// popis na odseky
		$paras = preg_split( '/\n\s*\n/', $a['DESCRIPTION'] );
		$html  = '';
		foreach ( $paras as $para ) {
			$para = trim( $para );
			if ( $para ) {
				$html .= '<p>' . esc_html( $para ) . '</p>' . "\n";
			}
		}

		wp_update_post( array(
			'ID'           => $post_id,
			'post_title'   => sanitize_text_field( $a['NAME'] ),
			'post_content' => wp_kses_post( $html ),
			'post_excerpt' => sanitize_text_field( $a['SHORT'] ?? '' ),
		) );

		// kategórie – len tie, čo naozaj existujú
		if ( ! empty( $a['CATEGORIES'] ) ) {
			$want = array_filter( array_map( 'trim', explode( ',', $a['CATEGORIES'] ) ) );
			$ids  = array();
			foreach ( $want as $name ) {
				$term = get_term_by( 'name', $name, 'product_cat' );
				if ( $term ) {
					$ids[] = intval( $term->term_id );
				}
			}
			if ( $ids ) {
				wp_set_object_terms( $post_id, $ids, 'product_cat' );
			}
		}

		// značky (tagy) produktu
		if ( ! empty( $a['TAGS'] ) ) {
			$tags = array_filter( array_map( 'trim', explode( ',', $a['TAGS'] ) ) );
			$tags = array_slice( $tags, 0, 8 );
			if ( $tags ) {
				wp_set_object_terms( $post_id, $tags, 'product_tag' ); // neexistujúce sa vytvoria
			}
		}

		// SEO polia – naplní všetky aktívne SEO pluginy (Yoast / Rank Math / SEOPress / AIOSEO)
		O3DAI_SEO::set_post( $post_id, $a['META_TITLE'] ?? '', $a['META_DESC'] ?? '', $a['FOCUS_KW'] ?? '' );

		o3dai_track_kind( 'products' );
		O3DAI_Progress::done( 'product_' . $post_id, true );
		o3dai_log( 'Produkt #' . $post_id . ' vyplnený z fotky: ' . $a['NAME'] );
	}


	/** Spustí prípravu AI návrhu pre jeden produkt — odkaz v riadku zoznamu produktov (v1.4.11) */
	public static function handle_propose() {
		$post_id = intval( $_GET['post'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! check_admin_referer( 'o3dai_propose_' . $post_id ) ) {
			wp_die( esc_html( o3dai_t( 'bad_request' ) ) );
		}
		if ( ! O3DAI_License::has_api_key() ) {
			wp_safe_redirect( add_query_arg( 'o3dai_nokey', 1, admin_url( 'admin.php?page=o3dai&o3d_tab=products' ) ) );
			exit;
		}
		O3DAI_Progress::start( 'prop_' . $post_id );
		wp_schedule_single_event( time() + 1, 'o3dai_propose_product_job', array( $post_id ) );
		spawn_cron();
		wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3d_tab=products&o3dai_prop=ready&o3dai_watch=prop_' . $post_id ) );
		exit;
	}

	/** ID produktov s otvorenými (pending) návrhmi */
	public static function pending_proposals() {
		$props = get_option( 'o3dai_proposals', array() );
		$ids   = array();
		if ( is_array( $props ) ) {
			foreach ( $props as $pid => $p ) {
				if ( 'pending' === ( $p['status'] ?? 'pending' ) ) {
					$ids[] = intval( $pid );
				}
			}
		}
		return $ids;
	}

	/** Úloha na pozadí: vygeneruje AI návrh pre produkt a ULOŽÍ HO. Produkt sa nedotkne,
	 * kým sa návrh neschváli na záložke Produkty (v1.4.11). */
	public static function propose_product( $post_id ) {
		$post_id = intval( $post_id );
		$product = get_post( $post_id );
		if ( ! $product || 'product' !== $product->post_type ) {
			return;
		}
		if ( ! O3DAI_License::feature( 'products' ) ) {
			O3DAI_Progress::done( 'prop_' . $post_id, false );
			o3dai_log( o3dai_t( 'pro_locked' ) );
			return;
		}
		if ( ! O3DAI_License::has_api_key() ) {
			O3DAI_Progress::done( 'prop_' . $post_id, false );
			o3dai_log( 'Produkt #' . $post_id . ': chýba API kľúč.' );
			return;
		}
		$att_id = get_post_thumbnail_id( $post_id );
		if ( ! $att_id ) {
			o3dai_log( 'Produkt #' . $post_id . ': chýba hlavný obrázok.' );
			O3DAI_Progress::done( 'prop_' . $post_id, false );
			return;
		}
		o3dai_log( 'Prípravám návrh pre produkt #' . $post_id . '…' );
		// hlavný obrázok + galéria (max 4, rovnaké obmedzenie ako pri vypĺňaní)
		$att_ids = array( $att_id );
		$gallery = get_post_meta( $post_id, '_product_image_gallery', true );
		if ( $gallery ) {
			foreach ( array_filter( array_map( 'intval', explode( ',', $gallery ) ) ) as $gid ) {
				if ( ! in_array( $gid, $att_ids, true ) ) { $att_ids[] = $gid; }
			}
		}
		$att_ids = array_slice( $att_ids, 0, 4 );
		$imgs = array();
		foreach ( $att_ids as $aid ) {
			$one = self::prepare_image( $aid );
			if ( $one ) { $imgs[] = $one; }
		}
		if ( ! $imgs ) {
			o3dai_log( 'Produkt #' . $post_id . ': obrázky sa nepodarilo načítať.' );
			O3DAI_Progress::done( 'prop_' . $post_id, false );
			return;
		}
		$s = o3dai_get_settings();
		// existujúce kategórie, nech AI vyberá len z reálnych
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'names' ) );
		$cats = is_wp_error( $cats ) ? array() : $cats;
		// ukážka existujúcich popisov, aby sedel tón
		$samples = array();
		foreach ( get_posts( array( 'post_type' => 'product', 'numberposts' => 3, 'post_status' => 'publish', 'post__not_in' => array( $post_id ) ) ) as $p ) {
			$txt = trim( wp_strip_all_tags( $p->post_content ) );
			if ( $txt ) {
				$samples[] = $p->post_title . ': ' . mb_substr( $txt, 0, 300 );
			}
		}
		$existing_title = trim( $product->post_title );
		$existing_price = get_post_meta( $post_id, '_regular_price', true );
		$system = 'Si copywriter pre e-shop. ' . $s['instructions'] . ' '
		. 'Pozri sa na fotografiu produktu a navrhni preň kompletné údaje. '
		. 'Odpovedaj PRESNE v tomto formáte s oddeľovačmi, bez textu pred a po:' . "\n"
		. '===NAME===' . "\n" . '(pútavý názov produktu, 3-8 slov, bez emoji)' . "\n"
		. '===SHORT===' . "\n" . '(krátky popis, 1-2 vety, do 160 znakov)' . "\n"
		. '===DESCRIPTION===' . "\n" . '(plný popis: 2 odseky oddelené prázdnym riadkom – prvý o tom, čo to je a čím poteší, druhý o materiáli, bezpečnosti a pre koho je vhodný)' . "\n"
		. '===CATEGORIES===' . "\n" . '(1-2 najpresnejšie kategórie oddelené čiarkou, VÝHRADNE zo zoznamu nižšie.)' . "\n"
		. '===TAGS===' . "\n" . '(4-7 značiek oddelených čiarkou: druh produktu, materiál, vlastnosti, príležitosť. Malými písmenami.)' . "\n"
		. '===META_TITLE===' . "\n" . '(max 58 znakov)' . "\n"
		. '===META_DESC===' . "\n" . '(max 155 znakov)' . "\n"
		. '===FOCUS_KW===' . "\n" . '(2-4 slová, čo by človek zadal do Googlu)' . "\n"
		. '===END===';
		$user = 'Fotografie produktu sú v prílohe (' . count( $imgs ) . ' – hlavná plus varianty/detaily z galérie; opíš produkt komplexne vrátane farebných variantov, ak sú).' . "\n\n"
		. ( $existing_title ? 'Pracovný názov (môžeš vylepšiť): ' . $existing_title . "\n" : '' )
		. ( $existing_price ? 'Cena: ' . $existing_price . ' €' . "\n" : '' )
		. "\n" . 'DOSTUPNÉ KATEGÓRIE (vyber len z nich):' . "\n- " . implode( "\n- ", $cats ) . "\n\n"
		. ( $samples ? 'UKÁŽKY EXISTUJÚCICH POPISOV (drž sa rovnakého tónu):' . "\n" . implode( "\n\n", $samples ) . "\n\n" : '' )
		. 'Popíš presne to, čo vidíš na fotke – farby, tvar, detaily. Nevymýšľaj vlastnosti, ktoré na fotke nie sú.';
		$raw = O3DAI_Providers::generate_vision( $system, $user, $imgs, 'image/jpeg', 2800 );
		if ( is_wp_error( $raw ) ) {
			o3dai_log( 'Produkt #' . $post_id . ' (návrh) – chyba: ' . $raw->get_error_message() );
			O3DAI_Progress::done( 'prop_' . $post_id, false );
			return;
		}
		$a = array();
		if ( preg_match_all( '/===([A-Z_]+)===\s*(.*?)(?====[A-Z_]+===|$)/s', (string) $raw, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $seg ) {
				if ( 'END' !== $seg[1] ) { $a[ $seg[1] ] = trim( $seg[2] ); }
			}
		}
		if ( empty( $a['NAME'] ) || empty( $a['DESCRIPTION'] ) ) {
			o3dai_log( 'Produkt #' . $post_id . ' (návrh): odpoveď sa nepodarilo naparsovať.' );
			O3DAI_Progress::done( 'prop_' . $post_id, false );
			return;
		}
		if ( ! empty( $s['proofread'] ) ) {
			$a['DESCRIPTION'] = O3DAI_Providers::proofread( $a['DESCRIPTION'] );
			$a['SHORT']       = O3DAI_Providers::proofread( $a['SHORT'] ?? '' );
		}
		$paras = preg_split( '/\n\s*\n/', $a['DESCRIPTION'] );
		$html  = '';
		foreach ( $paras as $para ) {
			$para = trim( $para );
			if ( $para ) {
				$html .= '<p>' . esc_html( $para ) . '</p>' . "\n";
			}
		}
		// ULOŽÍM NÁVRH — produkt sa nedotkne, kým sa neschváli
		$props = get_option( 'o3dai_proposals', array() );
		if ( ! is_array( $props ) ) { $props = array(); }
		$props[ $post_id ] = array(
			'ts'          => time(),
			'name'        => $a['NAME'],
			'short'       => $a['SHORT'] ?? '',
			'description' => $html,
			'cats'        => isset( $a['CATEGORIES'] ) ? array_values( array_filter( array_map( 'trim', explode( ',', $a['CATEGORIES'] ) ) ) ) : array(),
			'tags'        => isset( $a['TAGS'] ) ? array_slice( array_values( array_filter( array_map( 'trim', explode( ',', $a['TAGS'] ) ) ) ), 0, 8 ) : array(),
			'meta_title'  => $a['META_TITLE'] ?? '',
			'meta_desc'   => $a['META_DESC'] ?? '',
			'focus_kw'    => $a['FOCUS_KW'] ?? '',
			'status'      => 'pending',
			'cur_name'    => $existing_title,
			'cur_short'   => trim( (string) get_post_field( 'post_excerpt', $post_id ) ),
		);
		$props = array_slice( $props, -50, null, true );
		update_option( 'o3dai_proposals', $props, false );
		O3DAI_Progress::done( 'prop_' . $post_id, true );
		o3dai_log( 'Návrh pre produkt #' . $post_id . ' pripravený — čaká na schválenie.' );
	}

	/** Schváli návrh a zapíše ho do produktu (v1.4.11) */
	public static function apply_proposal( $post_id ) {
		$post_id = intval( $post_id );
		$props   = get_option( 'o3dai_proposals', array() );
		if ( ! is_array( $props ) || ! isset( $props[ $post_id ] ) ) {
			o3dai_log( 'Schvaľovanie zlyhalo: produkt #' . $post_id . ' nemá uložený návrh.' );
			return false;
		}
		$p = $props[ $post_id ];
		if ( 'applied' === ( $p['status'] ?? 'pending' ) ) {
			return true; // už schválený
		}
		if ( empty( $p['description'] ) || empty( $p['name'] ) ) {
			o3dai_log( 'Schvaľovanie zlyhalo: návrh produktu #' . $post_id . ' je neúplný.' );
			return false;
		}
		wp_update_post( array(
			'ID'           => $post_id,
			'post_title'   => sanitize_text_field( $p['name'] ),
			'post_content' => wp_kses_post( $p['description'] ),
			'post_excerpt' => sanitize_text_field( $p['short'] ?? '' ),
		) );
		// kategórie — len tie, čo naozaj existujú (rovnaké správanie ako pri vypĺňaní)
		if ( ! empty( $p['cats'] ) ) {
			$ids = array();
			foreach ( (array) $p['cats'] as $name ) {
				$term = get_term_by( 'name', $name, 'product_cat' );
				if ( $term ) { $ids[] = intval( $term->term_id ); }
			}
			if ( $ids ) { wp_set_object_terms( $post_id, $ids, 'product_cat' ); }
		}
		if ( ! empty( $p['tags'] ) ) {
			$tags = array_slice( array_filter( array_map( 'trim', (array) $p['tags'] ) ), 0, 8 );
			if ( $tags ) { wp_set_object_terms( $post_id, $tags, 'product_tag' ); }
		}
		// SEO polia — všetky aktívne SEO pluginy
		O3DAI_SEO::set_post( $post_id, $p['meta_title'] ?? '', $p['meta_desc'] ?? '', $p['focus_kw'] ?? '' );
		$p['status']     = 'applied';
		$p['applied_ts'] = time();
		$props[ $post_id ] = $p;
		update_option( 'o3dai_proposals', $props, false );
		o3dai_track_kind( 'products' );
		o3dai_log( 'Produkt #' . $post_id . ' schválený a uložený: ' . $p['name'] );
		return true;
	}

	/** Odmietne návrh (produkt sa nezmení) */
	public static function reject_proposal( $post_id ) {
		$post_id = intval( $post_id );
		$props   = get_option( 'o3dai_proposals', array() );
		if ( ! is_array( $props ) || ! isset( $props[ $post_id ] ) ) { return; }
		$props[ $post_id ]['status'] = 'rejected';
		update_option( 'o3dai_proposals', $props, false );
		o3dai_log( 'Návrh produktu #' . $post_id . ' odmietnutý.' );
	}

	/** Odstráni návrh zo zoznamu (produkt sa nezmení) */
	public static function remove_proposal( $post_id ) {
		$post_id = intval( $post_id );
		$props   = get_option( 'o3dai_proposals', array() );
		if ( ! is_array( $props ) || ! isset( $props[ $post_id ] ) ) { return; }
		unset( $props[ $post_id ] );
		update_option( 'o3dai_proposals', $props, false );
		o3dai_log( 'Návrh produktu #' . $post_id . ' odstránený.' );
	}
}

O3DAI_Products::init();
