<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Google Search Console – OAuth prihlásenie a sťahovanie reálnych vyhľadávacích fráz.
 */
class O3DAI_GSC {

	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API_BASE  = 'https://www.googleapis.com/webmasters/v3';
	const SCOPE     = 'https://www.googleapis.com/auth/webmasters.readonly';

	/** Presmerovacia adresa – musí PRESNE sedieť s tou v Google Cloud Console */
	public static function redirect_uri() {
		return admin_url( 'admin.php?page=o3dai' );
	}

	/** Je plugin prepojený s Googlom? */
	public static function is_connected() {
		return (bool) get_option( 'o3dai_gsc_refresh_token' );
	}

	/** Adresa, na ktorú používateľa pošleme sa prihlásiť */
	public static function auth_url() {
		$s = o3dai_get_settings();
		if ( empty( $s['gsc_client_id'] ) ) {
			return '';
		}
		return add_query_arg( array(
			'client_id'     => rawurlencode( $s['gsc_client_id'] ),
			'redirect_uri'  => rawurlencode( self::redirect_uri() ),
			'response_type' => 'code',
			'scope'         => rawurlencode( self::SCOPE ),
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => rawurlencode( wp_create_nonce( 'o3dai_gsc' ) ),
		), self::AUTH_URL );
	}

	/** Spracuje návrat z Googlu (?code=…) */
	public static function handle_callback() {
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['state'] ) ), 'o3dai_gsc' ) ) {
			o3dai_log( o3dai_t( 'log_gsc_bad_state' ) );
			return;
		}

		$s    = o3dai_get_settings();
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

		$resp = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 30,
			'body'    => array(
				'code'          => $code,
				'client_id'     => $s['gsc_client_id'],
				'client_secret' => $s['gsc_client_secret'],
				'redirect_uri'  => self::redirect_uri(),
				'grant_type'    => 'authorization_code',
			),
		) );
		if ( is_wp_error( $resp ) ) {
			o3dai_log( o3dai_t( 'log_gsc_login_fail', array( 'err' => $resp->get_error_message() ) ) );
			return;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['refresh_token'] ) ) {
			o3dai_log( o3dai_t( 'log_gsc_no_token', array( 'x' => ( $body['error_description'] ?? o3dai_t( 'log_unknown_err' ) ) ) ) );
			return;
		}

		update_option( 'o3dai_gsc_refresh_token', $body['refresh_token'], false );
		update_option( 'o3dai_gsc_access_token', $body['access_token'] ?? '', false );
		update_option( 'o3dai_gsc_expires', time() + intval( $body['expires_in'] ?? 3500 ), false );
		o3dai_log( o3dai_t( 'log_gsc_login_ok' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_gsc=ok' ) );
		exit;
	}

	/** Vráti platný access token (v prípade potreby ho obnoví) */
	public static function access_token() {
		$token   = get_option( 'o3dai_gsc_access_token' );
		$expires = intval( get_option( 'o3dai_gsc_expires', 0 ) );
		if ( $token && $expires > time() + 60 ) {
			return $token;
		}

		$refresh = get_option( 'o3dai_gsc_refresh_token' );
		if ( ! $refresh ) {
			return new WP_Error( 'o3dai', 'Search Console nie je prepojená.' );
		}
		$s    = o3dai_get_settings();
		$resp = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 30,
			'body'    => array(
				'refresh_token' => $refresh,
				'client_id'     => $s['gsc_client_id'],
				'client_secret' => $s['gsc_client_secret'],
				'grant_type'    => 'refresh_token',
			),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'o3dai', 'GSC token sa nepodarilo obnoviť: ' . ( $body['error_description'] ?? 'neznáma chyba' ) );
		}
		update_option( 'o3dai_gsc_access_token', $body['access_token'], false );
		update_option( 'o3dai_gsc_expires', time() + intval( $body['expires_in'] ?? 3500 ), false );
		return $body['access_token'];
	}

	/** Zoznam webov (properties), ku ktorým má účet prístup */
	public static function list_sites() {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$resp = wp_remote_get( self::API_BASE . '/sites', array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return wp_list_pluck( (array) ( $body['siteEntry'] ?? array() ), 'siteUrl' );
	}

	/**
	 * Stiahne dopyty za posledných 28 dní a uloží tie s najväčším potenciálom.
	 * Uprednostní pozície 8–20 (druhá strana Googlu = najbližšie k prielomu).
	 */
	public static function fetch_queries() {
		if ( ! O3DAI_License::feature( 'gsc' ) ) {
			return new WP_Error( 'o3dai_pro', o3dai_t( 'pro_locked' ) );
		}
		$s = o3dai_get_settings();
		$site = trim( (string) ( $s['gsc_site'] ?? '' ) );
		if ( '' === $site ) {
			return new WP_Error( 'o3dai', 'Nie je vybraný web (property) v Search Console.' );
		}
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$resp = wp_remote_post( self::API_BASE . '/sites/' . rawurlencode( $site ) . '/searchAnalytics/query', array(
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'content-type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'startDate'  => gmdate( 'Y-m-d', strtotime( '-28 days' ) ),
				'endDate'    => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
				'dimensions' => array( 'query' ),
				'rowLimit'   => 200,
			) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'o3dai', 'GSC API: ' . ( $body['error']['message'] ?? 'neznáma chyba' ) );
		}

		$rows = (array) ( $body['rows'] ?? array() );
		if ( ! $rows ) {
			update_option( 'o3dai_gsc_auto', '', false );
			update_option( 'o3dai_gsc_synced', current_time( 'Y-m-d H:i' ), false );
			o3dai_log( o3dai_t( 'log_gsc_no_data' ) );
			return 0;
		}

		// najväčší potenciál: pozícia 8–20, zoradené podľa zobrazení
		$near = array();
		$rest = array();
		foreach ( $rows as $r ) {
			$q   = trim( (string) ( $r['keys'][0] ?? '' ) );
			$pos = floatval( $r['position'] ?? 0 );
			$imp = intval( $r['impressions'] ?? 0 );
			if ( '' === $q ) {
				continue;
			}
			$line = $q . '  (poz. ' . round( $pos, 1 ) . ', ' . $imp . ' zobrazení)';
			if ( $pos >= 7.5 && $pos <= 20.5 ) {
				$near[] = array( 'line' => $line, 'imp' => $imp );
			} else {
				$rest[] = array( 'line' => $line, 'imp' => $imp );
			}
		}
		usort( $near, function ( $a, $b ) { return $b['imp'] - $a['imp']; } );
		usort( $rest, function ( $a, $b ) { return $b['imp'] - $a['imp']; } );

		$picked = array_merge(
			wp_list_pluck( array_slice( $near, 0, 20 ), 'line' ),
			wp_list_pluck( array_slice( $rest, 0, 10 ), 'line' )
		);

		update_option( 'o3dai_gsc_auto', implode( "\n", $picked ), false );
		update_option( 'o3dai_gsc_synced', current_time( 'Y-m-d H:i' ), false );
		o3dai_log( o3dai_t( 'log_gsc_fetched', array( 'n' => count( $picked ), 'm' => count( $near ) ) ) );
		return count( $picked );
	}

	/** Frázy pre generátor tém: automatické + ručne dopísané */
	public static function queries_for_prompt() {
		$s    = o3dai_get_settings();
		$auto = trim( (string) get_option( 'o3dai_gsc_auto', '' ) );
		$man  = trim( (string) ( $s['gsc_queries'] ?? '' ) );
		return trim( $auto . ( $auto && $man ? "\n" : '' ) . $man );
	}
}
