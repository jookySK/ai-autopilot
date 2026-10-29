<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Licenčná vrstva – v1.7.0 sú všetky funkcie free a Freemius SDK je odstránený.
 * Mäkké fair-use limity (5 článkov / 6 AI obrázkov za mesiac) zostávajú ako upozornenie —
 * nikdy generovanie neblokujú. Metódy sa zachovávajú pre kompatibilitu existujúcich volaní.
 */
class O3DAI_License {

	/** Fair-use prahy — mäkký limit (upozornenie, nie blok), od v1.5.11; v1.7.0 platia pre všetkých. */
	const FREE_ARTICLES = 5;
	const FREE_IMAGES   = 6;

	/** Má používateľ Pro? – v1.7.0 áno vždy (všetky funkcie sú free). */
	public static function is_pro() {
		return true;
	}

	public static function is_free() {
		return ! self::is_pro();
	}

	/** Build flag O3DAI_FREE_BUILD – v1.7.0 vždy true (jeden build, všetko free). */
	public static function is_free_build() {
		return defined( 'O3DAI_FREE_BUILD' ) && O3DAI_FREE_BUILD;
	}

	/** Má aktuálny provider vyplnený API kľúč? v1.9.3 – lokálni provideri (Ollama/LM Studio, ComfyUI) bežia cez LAN a žiaden kľúč nemajú. */
	public static function has_api_key() {
		$s    = o3dai_get_settings();
		$prov = $s['provider'] ?? 'claude';
		if ( in_array( $prov, array( 'local', 'comfyui' ), true ) ) {
			return true; // lokálny PC – stačí adresa servera z nastavení
		}
		$key  = $s[ 'api_key_' . $prov ] ?? '';
		return '' !== trim( (string) $key );
	}

	/** Historická spotreba za všetky mesiace s zaznamenanými dátami (najnovšie hore). v1.6.5 */
	public static function usage_history() {
		global $wpdb;
		$rows  = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- static option prefix (no user input; re-validated by the regex below); enumerates our own per-month o3dai_usage_YYYY-MM options — no core API lists option names
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'o3dai\\_usage\\_%'" );
		foreach ( $names as $name ) {
			if ( ! preg_match( '/^o3dai_usage_(\d{4}-\d{2})$/', $name, $m ) ) {
				continue;
			}
			$u = get_option( $name, array() );
			if ( ! is_array( $u ) ) { $u = array(); }
			$rows[ $m[1] ] = array(
				'text_calls' => intval( $u['text_calls'] ?? 0 ),
				'images'     => intval( $u['images'] ?? 0 ),
				'products'   => intval( $u['products'] ?? 0 ),
				'categories' => intval( $u['categories'] ?? 0 ),
				'cost'       => floatval( $u['text_cost'] ?? 0 ) + floatval( $u['image_cost'] ?? 0 ),
			);
		}
		krsort( $rows );
		return $rows;
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

	/** Fair-use: nepresiahnutý mesiacny počet článkov? – v1.7.0 čisté počítadlo (bez Pro skratky). */
	public static function can_make_article() {
		return self::articles_this_month() < self::FREE_ARTICLES;
	}

	/** Fair-use: ešte jeden AI obrázok? – v1.7.0 čisté počítadlo (bez Pro skratky). */
	public static function can_make_image() {
		return self::images_this_month() < self::FREE_IMAGES;
	}

	/** Efektívny režim obrázkov – v1.7.0 identita: AI cez vlastný kľúč všade, fotobanku už nenuťme. */
	public static function eff_image_mode( $mode ) {
		return $mode;
	}

	/** Je daná funkcia dostupná? – v1.7.0 áno vždy (všetky funkcie sú free). */
	public static function feature( $key ) {
		return true;
	}

	/** URL na „upgrade“ – Pro už nie je, odkaz vedie do nastavení pluginu (kompatibilita volaní). */
	public static function upgrade_url() {
		return admin_url( 'admin.php?page=o3dai' );
	}

	/** Malý HTML odznak „PRO“. Zachované pre kompatibilitu; v1.7.0 sa už nevykresľuje. */
	public static function pro_badge() {
		return '<span class="o3d-pro-badge">PRO</span>';
	}

	/** Blok s výzvou na upgrade – zachovaný pre kompatibilitu volaní (v1.7.0 bez odznaku PRO). */
	public static function upgrade_box( $text = '' ) {
		$text = $text ?: o3dai_t( 'pro_locked' );
		return '<div class="o3d-pro-lock">' . esc_html( $text ) . '</div>';
	}
}
