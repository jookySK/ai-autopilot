<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Zadarmo fotobanky (Pexels, Pixabay, Openverse) – ilustračné obrázky bez AI nákladov.
 * Vracia binárku obrázka + credit autora (licencia zadarmo fotiek vyžaduje uvedenie zdroja,
 * plugin ho automaticky uloží k článku).
 */
class O3DAI_Stock {

	/**
	 * Vyhľadá a stiahne jeden obrázok.
	 * @return array|WP_Error { 'bin' => string (binárka), 'credit' => string, 'url' => string }
	 */
	public static function fetch( $query, $s ) {
		$prov = in_array( (string) ( $s['stock_provider'] ?? '' ), array( 'pexels', 'pixabay', 'openverse' ), true )
			? $s['stock_provider']
			: 'pexels';
		switch ( $prov ) {
			case 'pixabay':
				return self::pixabay( $query, $s );
			case 'openverse':
				return self::openverse( $query, $s );
			default:
				return self::pexels( $query, $s );
		}
	}

	private static function pexels( $query, $s ) {
		$key = trim( (string) ( $s['stock_key_pexels'] ?? '' ) );
		if ( '' === $key ) {
			return new WP_Error( 'o3dai', 'Pexels: chýba API kľúč (zadarmo na pexels.com/api).' );
		}
		$url  = add_query_arg(
			array( 'query' => $query, 'per_page' => 5, 'orientation' => 'landscape', 'size' => 'large' ),
			'https://api.pexels.com/v1/search'
		);
		$resp = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => array( 'Authorization' => $key ) ) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'o3dai', 'Pexels API: ' . $resp->get_error_message() );
		}
		$body  = json_decode( wp_remote_retrieve_body( $resp ), true );
		$photo = is_array( $body ) ? ( $body['photos'][0] ?? null ) : null;
		if ( ! is_array( $photo ) || empty( $photo['src']['large'] ) ) {
			return new WP_Error( 'o3dai', 'Pexels: žiadne výsledky pre „' . $query . '" – skús iné slová v „Extra hľadané slová".' );
		}
		return self::download( $photo['src']['large'], 'Pexels', trim( (string) ( $photo['photographer'] ?? '' ) ), (string) ( $photo['url'] ?? '' ) );
	}

	private static function pixabay( $query, $s ) {
		$key = trim( (string) ( $s['stock_key_pixabay'] ?? '' ) );
		if ( '' === $key ) {
			return new WP_Error( 'o3dai', 'Pixabay: chýba API kľúč (zadarmo na pixabay.com/api/docs/).' );
		}
		$url  = add_query_arg(
			array( 'key' => $key, 'q' => $query, 'per_page' => 5, 'safesearch' => 'true' ),
			'https://api.pixabay.com/v1/search'
		);
		$resp = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'o3dai', 'Pixabay API: ' . $resp->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		$hit  = is_array( $body ) ? ( $body['hits'][0] ?? null ) : null;
		if ( ! is_array( $hit ) || empty( $hit['largeImageURL'] ) ) {
			return new WP_Error( 'o3dai', 'Pixabay: žiadne výsledky pre „' . $query . '" – skús iné slová v „Extra hľadané slová".' );
		}
		return self::download( $hit['largeImageURL'], 'Pixabay', trim( (string) ( $hit['user'] ?? '' ) ), (string) ( $hit['pageURL'] ?? '' ) );
	}

	private static function openverse( $query, $s ) {
		$url  = add_query_arg( array( 'q' => $query, 'page_size' => 5 ), 'https://api.openverse.org/v1/images/' );
		$resp = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => array( 'User-Agent' => 'AI-Autopilot/1.4 (WordPress plugin)' ) ) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'o3dai', 'Openverse API: ' . $resp->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		$hit  = is_array( $body ) ? ( $body['results'][0] ?? null ) : null;
		if ( ! is_array( $hit ) || empty( $hit['url'] ) ) {
			return new WP_Error( 'o3dai', 'Openverse: žiadne výsledky pre „' . $query . '" – skús iné slová v „Extra hľadané slová".' );
		}
		return self::download( $hit['url'], 'Openverse', trim( (string) ( $hit['creator'] ?? '' ) ), (string) ( $hit['foreign_landing_url'] ?? '' ) );
	}

	/** Stiahne binárku obrázka a zloží credit */
	private static function download( $img_url, $source, $author, $page_url ) {
		$resp = wp_remote_get( $img_url, array(
			'timeout' => 45,
			'headers' => array( 'User-Agent' => 'Mozilla/5.0 (compatible; AI-Autopilot/1.4)' ),
		) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'o3dai', 'Stiahnutie obrázka z ' . $source . ' zlyhalo: ' . $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return new WP_Error( 'o3dai', 'Stiahnutie obrázka z ' . $source . ' zlyhalo (HTTP ' . $code . ').' );
		}
		$bin = wp_remote_retrieve_body( $resp );
		if ( ! is_string( $bin ) || strlen( $bin ) < 2000 ) {
			return new WP_Error( 'o3dai', 'Obrázok z ' . $source . ' bol prázdny alebo príliš malý.' );
		}
		$credit = $source . ( $author ? ': ' . $author : '' ) . ( $page_url ? ' – ' . $page_url : '' );
		return array( 'bin' => $bin, 'credit' => trim( $credit ), 'url' => $img_url );
	}
}
