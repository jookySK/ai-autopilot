<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SEO abstrakcia – rovnaké SEO polia píše do VŠETKÝCH nainštalovaných SEO pluginov
 * (Yoast SEO, Rank Math, SEOPress, All in One SEO).
 * Zákazníka nezaujíma, ktorý plugin má – polia sa naplnia všade, kde bežia.
 */
class O3DAI_SEO {

	/** Ktoré SEO pluginy sú aktívne (priorita: Yoast > Rank Math > SEOPress > AIOSEO) */
	public static function active() {
		$out = array();
		if ( defined( 'WPSEO_VERSION' ) )     { $out[] = 'yoast'; }
		if ( defined( 'RANK_MATH_VERSION' ) ) { $out[] = 'rankmath'; }
		if ( defined( 'SEOPRESS_VERSION' ) )  { $out[] = 'seopress'; }
		if ( defined( 'AIOSEO_VERSION' ) )    { $out[] = 'aioseo'; }
		return $out;
	}

	/** Prepísateľná etiketa pre admin UI */
	public static function label() {
		$names = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'seopress' => 'SEOPress',
			'aioseo'   => 'All in One SEO',
		);
		$act = self::active();
		if ( ! $act ) {
			return '';
		}
		$labels = array();
		foreach ( $act as $a ) {
			$labels[] = $names[ $a ];
		}
		return implode( ' + ', $labels );
	}

	/**
	 * SEO polia pre článok / produkt (title, description, hlavné kľúčové slovo).
	 * Napíše do všetkých aktívnych SEO pluginov naraz.
	 */
	public static function set_post( $post_id, $title, $desc, $focus_kw = '' ) {
		$post_id  = intval( $post_id );
		if ( ! $post_id ) {
			return;
		}
		$title    = sanitize_text_field( (string) $title );
		$desc     = sanitize_text_field( (string) $desc );
		$focus_kw = sanitize_text_field( (string) $focus_kw );
		if ( ! $title && ! $desc && ! $focus_kw ) {
			return;
		}
		foreach ( self::active() as $plug ) {
			switch ( $plug ) {
				case 'yoast':
					if ( $title )    { update_post_meta( $post_id, '_yoast_wpseo_title', $title ); }
					if ( $desc )     { update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc ); }
					if ( $focus_kw ) { update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus_kw ); }
					break;
				case 'rankmath':
					if ( $title )    { update_post_meta( $post_id, 'rank_math_title', $title ); }
					if ( $desc )     { update_post_meta( $post_id, 'rank_math_description', $desc ); }
					if ( $focus_kw ) { update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_kw ); }
					break;
				case 'seopress':
					if ( $title )    { update_post_meta( $post_id, 'seopress_title_tag', $title ); }
					if ( $desc )     { update_post_meta( $post_id, 'seopress_metadesc', $desc ); }
					if ( $focus_kw ) { update_post_meta( $post_id, 'seopress_keywords', $focus_kw ); }
					break;
				case 'aioseo':
					if ( $title )    { update_post_meta( $post_id, '_aioseo_title', $title ); }
					if ( $desc )     { update_post_meta( $post_id, '_aioseo_description', $desc ); }
					if ( $focus_kw ) { update_post_meta( $post_id, '_aioseo_focus_keyword', $focus_kw ); }
					break;
			}
		}
	}

	/** Hlavné kľúčové slovo obsahu (pre interné odkazy) – z prvého aktívneho pluginu, kde je vyplnené */
	public static function get_focus_kw( $post_id ) {
		$post_id = intval( $post_id );
		if ( ! $post_id ) {
			return '';
		}
		foreach ( self::active() as $plug ) {
			switch ( $plug ) {
				case 'yoast':
					$v = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
					break;
				case 'rankmath':
					$v = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
					break;
				case 'seopress':
					$v = get_post_meta( $post_id, 'seopress_keywords', true );
					break;
				case 'aioseo':
					$v = get_post_meta( $post_id, '_aioseo_focus_keyword', true );
					break;
				default:
					$v = '';
			}
			if ( trim( (string) $v ) !== '' ) {
				return trim( (string) $v );
			}
		}
		return '';
	}

	/**
	 * SEO polia pre taxonómiu (napr. produktová kategória) – title + description (+ focus kw).
	 * Každý plugin ukladá taxonómie inak: Yoast a AIOSEO do option, Rank Math do option, SEOPress do option.
	 */
	public static function set_term( $taxonomy, $term_id, $title, $desc, $focus_kw = '' ) {
		$term_id  = intval( $term_id );
		if ( ! $term_id ) {
			return;
		}
		$title    = sanitize_text_field( (string) $title );
		$desc     = sanitize_text_field( (string) $desc );
		$focus_kw = sanitize_text_field( (string) $focus_kw );
		if ( ! $title && ! $desc && ! $focus_kw ) {
			return;
		}
		$active = self::active();

		if ( in_array( 'yoast', $active, true ) ) {
			$meta = get_option( 'wpseo_taxonomy_meta', array() );
			if ( ! isset( $meta[ $taxonomy ] ) || ! is_array( $meta[ $taxonomy ] ) ) { $meta[ $taxonomy ] = array(); }
			$entry = ( isset( $meta[ $taxonomy ][ $term_id ] ) && is_array( $meta[ $taxonomy ][ $term_id ] ) ) ? $meta[ $taxonomy ][ $term_id ] : array();
			if ( $title ) { $entry['title'] = $title; }
			if ( $desc )  { $entry['meta']  = $desc; }
			$meta[ $taxonomy ][ $term_id ] = $entry;
			update_option( 'wpseo_taxonomy_meta', $meta, false );
		}

		if ( in_array( 'rankmath', $active, true ) ) {
			$titles = get_option( 'rank_math_titles', array() );
			if ( ! isset( $titles[ $term_id ] ) || ! is_array( $titles[ $term_id ] ) ) { $titles[ $term_id ] = array(); }
			if ( $title )    { $titles[ $term_id ]['title'] = $title; }
			if ( $desc )     { $titles[ $term_id ]['description'] = $desc; }
			if ( $focus_kw ) { $titles[ $term_id ]['focus_keyword'] = $focus_kw; }
			update_option( 'rank_math_titles', $titles, false );
		}

		if ( in_array( 'seopress', $active, true ) ) {
			$titles = get_option( 'seopress_titles', array() );
			if ( ! isset( $titles[ $term_id ] ) || ! is_array( $titles[ $term_id ] ) ) { $titles[ $term_id ] = array(); }
			if ( $title ) { $titles[ $term_id ]['seopress_title_tag'] = $title; }
			if ( $desc )  { $titles[ $term_id ]['seopress_metadesc'] = $desc; }
			update_option( 'seopress_titles', $titles, false );
		}

		if ( in_array( 'aioseo', $active, true ) ) {
			$opts = get_option( '_aioseo_options', array() );
			if ( ! isset( $opts['posts'] ) || ! is_array( $opts['posts'] ) ) { $opts['posts'] = array(); }
			if ( ! isset( $opts['posts'][ $term_id ] ) || ! is_array( $opts['posts'][ $term_id ] ) ) { $opts['posts'][ $term_id ] = array(); }
			if ( $title )    { $opts['posts'][ $term_id ]['title'] = $title; }
			if ( $desc )     { $opts['posts'][ $term_id ]['description'] = $desc; }
			if ( $focus_kw ) { $opts['posts'][ $term_id ]['focus_keyword'] = $focus_kw; }
			update_option( '_aioseo_options', $opts, false );
		}
	}
}
