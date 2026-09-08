<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Licenčná vrstva – rozhoduje Free vs Pro a stráži mesačné limity Free verzie.
 * Navrhnuté tak, aby po pridaní Freemius SDK stačilo doplniť jednu vetvu v is_pro().
 */
class O3DAI_License {

	/** Fair-use prahy Free verzie — mäkký limit (upozornenie, nie blok) od v1.5.11 */
	const FREE_ARTICLES = 5;
	const FREE_IMAGES   = 6;

	/** Má používateľ Pro? */
	public static function is_pro() {
		// 1) Testovací prepínač v nastaveniach (aby si vedel vidieť Free zážitok).
		$s = o3dai_get_settings();
		if ( ! empty( $s['simulate_free'] ) ) {
			return false;
		}
		// 2) Freemius — Pro = platná licencia alebo aktívny trial.
		//    Pozor: is_premium() sa NEpoužíva — v PRO buildi je to config flag a je vždy true,
		//    takže by licenciu zmyselovo zrušil.
		if ( function_exists( 'o3dai_fs' ) ) {
			$fs = o3dai_fs();
			return $fs->is_paying() || $fs->is_trial();
		}
		// 3) Bez Freemius (Free build): Free je default.
		return false;
	}

	public static function is_free() {
		return ! self::is_pro();
	}

	/** Free build (wp.org) – Pro moduly v ňom fyzicky neexistujú, nič nie je len „zamknuté“ (v1.6.0). */
	public static function is_free_build() {
		return defined( 'O3DAI_FREE_BUILD' ) && O3DAI_FREE_BUILD;
	}

	/** Má aktuálny provider vyplnený API kľúč? */
	public static function has_api_key() {
		$s = o3dai_get_settings();
		$prov = $s['provider'] ?? 'claude';
		$key  = $s[ 'api_key_' . $prov ] ?? '';
		return '' !== trim( (string) $key );
	}

	/** Koľko článkov už bolo tento mesiac vygenerovaných (z počítadla spotreby). */
	public static function articles_this_month() {
		$u = get_option( o3dai_usage_key(), array() );
		return intval( $u['text_calls'] ?? 0 );
	}

	/** Koľko AI obrázkov už bolo tento mesiac vygenerovaných. */
	public static function images_this_month() {
		$u = get_option( o3dai_usage_key(), array() );
		return intval( $u['images'] ?? 0 );
	}

	/** Free verzia ešte nepresiahla fair-use počet článkov? (Pro = vždy áno) */
	public static function can_make_article() {
		if ( self::is_pro() ) {
			return true;
		}
		return self::articles_this_month() < self::FREE_ARTICLES;
	}

	/** Smie Free vygenerovať ďalší AI obrázok? (Pro = vždy áno) */
	public static function can_make_image() {
		if ( self::is_pro() ) {
			return true;
		}
		return self::images_this_month() < self::FREE_IMAGES;
	}

	/** Efektívny režim obrázkov: Free verzia nemá AI generovanie — používa fotobanku (v1.5.11). */
	public static function eff_image_mode( $mode ) {
		if ( 'ai' === $mode && ! self::is_pro() ) {
			return 'stock';
		}
		return $mode;
	}

	/** Je daná Pro-only funkcia dostupná? */
	public static function feature( $key ) {
		// zoznam Pro-only funkcií
		$pro_only = array(
			'products',     // produkty z fotky
			'categories',   // popisy/obrázky kategórií
			'series',       // obsahové série
			'suggest',      // navrhovanie tém a sérií
			'gsc',          // Google Search Console
			'serp',         // SERP analýza
			'social',       // sociálne siete (webhooky)
			'multi_provider', // viac ako jeden provider
		);
		if ( ! in_array( $key, $pro_only, true ) ) {
			return true; // nie je to Pro-only funkcia
		}
		return self::is_pro();
	}

	/** URL na upgrade (Freemius neskôr doplní reálnu). */
	public static function upgrade_url() {
		if ( function_exists( 'o3dai_fs' ) ) {
			return o3dai_fs()->get_upgrade_url();
		}
		return admin_url( 'admin.php?page=o3dai&o3dai_upgrade=1' );
	}

	/** Malý HTML odznak „PRO" pre nedostupné funkcie. */
	public static function pro_badge() {
		return '<span class="o3d-pro-badge">PRO</span>';
	}

	/** Blok s výzvou na upgrade (do admin kariet). */
	public static function upgrade_box( $text = '' ) {
		$text = $text ?: o3dai_t( 'pro_locked' );
		return '<div class="o3d-pro-lock"><span class="o3d-pro-badge">PRO</span> '
			. esc_html( $text ) . ' <a href="' . esc_url( self::upgrade_url() ) . '" class="o3d-btn o3d-btn-primary" style="text-decoration:none;margin-left:8px">'
			. esc_html( o3dai_t( 'upgrade_now' ) ) . '</a></div>';
	}
}
