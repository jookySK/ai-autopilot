<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Jednotné rozhranie pre AI providerov.
 * O3DAI_Providers::generate( $system, $user ) → string|WP_Error
 */
class O3DAI_Providers {

	public static function generate( $system, $user, $max_tokens = 4000, $prefill = '' ) {
		$s = o3dai_get_settings();
		switch ( $s['provider'] ) {
			case 'openai':
				return self::openai( $s, $system, $user, $max_tokens );
			case 'gemini':
				return self::gemini( $s, $system, $user, $max_tokens );
			case 'openrouter':
				return self::openrouter( $s, $system, $user, $max_tokens );
			case 'claude':
			default:
				return self::claude( $s, $system, $user, $max_tokens, $prefill );
		}
	}

	private static function claude( $s, $system, $user, $max_tokens, $prefill = '' ) {
		if ( empty( $s['api_key_claude'] ) ) {
			return new WP_Error( 'o3dai', 'Chýba Claude API kľúč.' );
		}
		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 180,
			'headers' => array(
				'content-type'      => 'application/json',
				'x-api-key'         => $s['api_key_claude'],
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode( array(
				'model'      => $s['model'] ?: 'claude-sonnet-4-6',
				'max_tokens' => $max_tokens,
				'system'     => $system,
				'messages'   => array( array( 'role' => 'user', 'content' => $user ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'o3dai', 'Claude API: ' . ( $body['error']['message'] ?? 'neznáma chyba' ) );
		}
		$out = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$out .= $block['text'];
			}
		}
		o3dai_track_text( 'claude', $s['model'] ?: 'claude-sonnet-4-6',
			intval( $body['usage']['input_tokens'] ?? 0 ),
			intval( $body['usage']['output_tokens'] ?? 0 ) );
		return $out;
	}

	/**
	 * Analýza obrázka + text (vision). $image = binárne dáta, $mime = image/jpeg|image/png|image/webp
	 */
	public static function generate_vision( $system, $user, $image, $mime = 'image/jpeg', $max_tokens = 3000 ) {
		$s = o3dai_get_settings();

		// $image môže byť jeden reťazec (binárka) alebo pole [ ['data'=>…, 'mime'=>…], … ]
		$imgs = array();
		if ( is_array( $image ) && isset( $image[0]['data'] ) ) {
			foreach ( $image as $one ) {
				$imgs[] = array( 'b64' => base64_encode( $one['data'] ), 'mime' => $one['mime'] ?? 'image/jpeg' );
			}
		} else {
			$imgs[] = array( 'b64' => base64_encode( $image ), 'mime' => $mime );
		}

		switch ( $s['provider'] ) {
			case 'openai':
				return self::vision_openai( $s, $system, $user, $imgs, $max_tokens );
			case 'gemini':
				return self::vision_gemini( $s, $system, $user, $imgs, $max_tokens );
			case 'openrouter':
				return self::vision_openrouter( $s, $system, $user, $imgs, $max_tokens );
			case 'claude':
			default:
				return self::vision_claude( $s, $system, $user, $imgs, $max_tokens );
		}
	}

	private static function vision_claude( $s, $system, $user, $imgs, $max_tokens ) {
		if ( empty( $s['api_key_claude'] ) ) {
			return new WP_Error( 'o3dai', 'Chýba Claude API kľúč.' );
		}
		$content = array();
		foreach ( $imgs as $im ) {
			$content[] = array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $im['mime'], 'data' => $im['b64'] ) );
		}
		$content[] = array( 'type' => 'text', 'text' => $user );
		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 180,
			'headers' => array(
				'content-type'      => 'application/json',
				'x-api-key'         => $s['api_key_claude'],
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode( array(
				'model'      => $s['model'] ?: 'claude-sonnet-4-6',
				'max_tokens' => $max_tokens,
				'system'     => $system,
				'messages'   => array( array( 'role' => 'user', 'content' => $content ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) ) { return $resp; }
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'o3dai', 'Claude vision: ' . ( $body['error']['message'] ?? 'neznáma chyba' ) );
		}
		$out = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) { $out .= $block['text']; }
		}
		o3dai_track_text( 'claude', $s['model'] ?: 'claude-sonnet-4-6',
			intval( $body['usage']['input_tokens'] ?? 0 ), intval( $body['usage']['output_tokens'] ?? 0 ) );
		return $out;
	}

	private static function vision_openai( $s, $system, $user, $imgs, $max_tokens ) {
		if ( empty( $s['api_key_openai'] ) ) {
			return new WP_Error( 'o3dai', 'Chýba OpenAI API kľúč.' );
		}
		$uc = array( array( 'type' => 'text', 'text' => $user ) );
		foreach ( $imgs as $im ) {
			$uc[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:' . $im['mime'] . ';base64,' . $im['b64'] ) );
		}
		$resp = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 180,
			'headers' => array( 'content-type' => 'application/json', 'authorization' => 'Bearer ' . $s['api_key_openai'] ),
			'body'    => wp_json_encode( array(
				'model'                 => $s['model'] ?: 'gpt-4o',
				'max_completion_tokens' => $max_tokens,
				'messages'              => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $uc ),
				),
			) ),
		) );
		if ( is_wp_error( $resp ) ) { return $resp; }
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'o3dai', 'OpenAI vision: ' . ( $body['error']['message'] ?? 'neznáma chyba' ) );
		}
		o3dai_track_text( 'openai', $s['model'] ?: 'gpt-4o',
			intval( $body['usage']['prompt_tokens'] ?? 0 ), intval( $body['usage']['completion_tokens'] ?? 0 ) );
		return $body['choices'][0]['message']['content'] ?? '';
	}

	private static function vision_gemini( $s, $system, $user, $imgs, $max_tokens ) {
		if ( empty( $s['api_key_gemini'] ) ) {
			return new WP_Error( 'o3dai', 'Chýba Gemini API kľúč.' );
		}
		$model = $s['model'] ?: 'gemini-flash-latest';
		$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $s['api_key_gemini'] );
		$parts = array();
		foreach ( $imgs as $im ) {
			$parts[] = array( 'inline_data' => array( 'mime_type' => $im['mime'], 'data' => $im['b64'] ) );
		}
		$parts[] = array( 'text' => $user );
		$resp  = wp_remote_post( $url, array(
			'timeout' => 180,
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
				'contents'          => array( array( 'role' => 'user', 'parts' => $parts ) ),
				'generationConfig'  => array( 'maxOutputTokens' => $max_tokens ),
			) ),
		) );
		if ( is_wp_error( $resp ) ) { return $resp; }
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'o3dai', 'Gemini vision: ' . ( $body['error']['message'] ?? 'neznáma chyba' ) );
		}
		$out = '';
		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			$out .= $part['text'] ?? '';
		}
		o3dai_track_text( 'gemini', $model,
			intval( $body['usageMetadata']['promptTokenCount'] ?? 0 ), intval( $body['usageMetadata']['candidatesTokenCount'] ?? 0 ) );
		return $out;
	}

	/** Vygeneruje obrázok podľa vybraného image_model; vráti binárne dáta alebo WP_Error.
	 *  Ak primárny provider zlyhá (napr. vyčerpaná kvóta) a druhý má vyplnený kľúč,
	 *  automaticky sa presunie na neho (failover) — článok tak nedostane obrázok len pre to,
	 *  že jeden API limit bol dočasne preplnený. */
	public static function generate_image( $prompt ) {
		$s     = o3dai_get_settings();
		$model = $s['image_model'] ?? 'gpt-image-1';
		$cands = ( 0 === strpos( $model, 'gemini-' ) )
			? array( array( 'gemini', $model ), array( 'openai', 'gpt-image-1' ) )
			: array( array( 'openai', $model ), array( 'gemini', 'gemini-2.5-flash-image' ) );
		$first = null;
		foreach ( $cands as $i => $c ) {
			$has_key = ( 'gemini' === $c[0] ) ? ! empty( $s['api_key_gemini'] ) : ! empty( $s['api_key_openai'] );
			if ( ! $has_key ) {
				continue;
			}
			$r = ( 'gemini' === $c[0] ) ? self::img_gemini( $s, $prompt, $c[1] ) : self::img_openai( $s, $prompt, $c[1] );
			if ( ! is_wp_error( $r ) ) {
				if ( null !== $first ) {
					o3dai_log( 'Obrázok: primárny provider zlyhal — failover na ' . ( 'gemini' === $c[0] ? 'Gemini' : 'OpenAI' ) . ' (' . $c[1] . ') úspešný.' );
				}
				return $r;
			}
			if ( null === $first ) {
				$first = $r;
			}
			if ( $i < count( $cands ) - 1 ) {
				o3dai_log( 'Obrázok: provider zlyhal (' . $r->get_error_message() . ') — skúsam ďalší dostupý…' );
			}
		}
		if ( null !== $first ) {
			return $first;
		}
		return new WP_Error( 'o3dai', 'Na generovanie obrázkov chýba API kľúč (OpenAI alebo Gemini).' );
	}

	private static function img_openai( $s, $prompt, $model ) {
		if ( empty( $s['api_key_openai'] ) ) {
			return new WP_Error( 'o3dai', 'Na OpenAI obrázky treba OpenAI API kľúč.' );
		}
		$resp = wp_remote_post( 'https://api.openai.com/v1/images/generations', array(
			'timeout' => 180,
			'headers' => array( 'content-type' => 'application/json', 'authorization' => 'Bearer ' . $s['api_key_openai'] ),
			'body'    => wp_json_encode( array( 'model' => $model, 'prompt' => $prompt, 'size' => '1536x1024' ) ),
		) );
		if ( is_wp_error( $resp ) ) { return $resp; }
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'o3dai', 'OpenAI Images: ' . ( $body['error']['message'] ?? 'neznáma chyba' ) );
		}
		$b64 = $body['data'][0]['b64_json'] ?? '';
		if ( ! $b64 ) { return new WP_Error( 'o3dai', 'OpenAI: prázdny obrázok.' ); }
		o3dai_track_image( $model );
		return base64_decode( $b64 );
	}

	private static function img_gemini( $s, $prompt, $model = 'gemini-2.5-flash-image' ) {
		if ( empty( $s['api_key_gemini'] ) ) {
			return new WP_Error( 'o3dai', 'Na Gemini obrázky treba Google (Gemini) API kľúč.' );
		}
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode( $s['api_key_gemini'] );
		$resp = wp_remote_post( $url, array(
			'timeout' => 180,
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) ) { return $resp; }
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'o3dai', 'Gemini image: ' . ( $body['error']['message'] ?? 'neznáma chyba' ) );
		}
		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( ! empty( $part['inlineData']['data'] ) ) {
				o3dai_track_image( $model );
				return base64_decode( $part['inlineData']['data'] );
			}
		}
		return new WP_Error( 'o3dai', 'Gemini: v odpovedi nebol obrázok.' );
	}

	private static function openai( $s, $system, $user, $max_tokens ) {
		if ( empty( $s['api_key_openai'] ) ) {
			return new WP_Error( 'o3dai', 'Chýba OpenAI API kľúč.' );
		}
		$resp = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 180,
			'headers' => array(
				'content-type'  => 'application/json',
				'authorization' => 'Bearer ' . $s['api_key_openai'],
			),
			'body'    => wp_json_encode( array(
				'model'                 => $s['model'] ?: 'gpt-4o',
				'max_completion_tokens' => $max_tokens,
				'messages'              => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $user ),
				),
			) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'o3dai', 'OpenAI API: ' . ( $body['error']['message'] ?? 'neznáma chyba' ) );
		}
		o3dai_track_text( 'openai', $s['model'] ?: 'gpt-4o',
			intval( $body['usage']['prompt_tokens'] ?? 0 ),
			intval( $body['usage']['completion_tokens'] ?? 0 ) );
		return $body['choices'][0]['message']['content'] ?? '';
	}

	private static function openrouter( $s, $system, $user, $max_tokens ) {
		if ( empty( $s['api_key_openrouter'] ) ) {
			return new WP_Error( 'o3dai', 'Chýba OpenRouter API kľúč.' );
		}
		$resp = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
			'timeout' => 180,
			'headers' => array(
				'content-type'  => 'application/json',
				'authorization' => 'Bearer ' . $s['api_key_openrouter'],
				'http-referer'  => home_url( '/' ),
				'x-title'       => 'AI Autopilot',
			),
			'body'    => wp_json_encode( array(
				'model'      => $s['model'] ?: 'openrouter/auto',
				'max_tokens' => $max_tokens,
				'messages'   => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $user ),
				),
			) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'o3dai', 'OpenRouter API: ' . ( $body['error']['message'] ?? 'neznáma chyba' ) );
		}
		o3dai_track_text( 'openrouter', $s['model'] ?: 'openrouter/auto',
			intval( $body['usage']['prompt_tokens'] ?? 0 ),
			intval( $body['usage']['completion_tokens'] ?? 0 ) );
		return $body['choices'][0]['message']['content'] ?? '';
	}

	private static function vision_openrouter( $s, $system, $user, $imgs, $max_tokens ) {
		if ( empty( $s['api_key_openrouter'] ) ) {
			return new WP_Error( 'o3dai', 'Chýba OpenRouter API kľúč.' );
		}
		$uc = array( array( 'type' => 'text', 'text' => $user ) );
		foreach ( $imgs as $im ) {
			$uc[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:' . $im['mime'] . ';base64,' . $im['b64'] ) );
		}
		$resp = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
			'timeout' => 180,
			'headers' => array(
				'content-type'  => 'application/json',
				'authorization' => 'Bearer ' . $s['api_key_openrouter'],
				'http-referer'  => home_url( '/' ),
				'x-title'       => 'AI Autopilot',
			),
			'body'    => wp_json_encode( array(
				'model'      => $s['model'] ?: 'openrouter/auto',
				'max_tokens' => $max_tokens,
				'messages'   => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $uc ),
				),
			) ),
		) );
		if ( is_wp_error( $resp ) ) { return $resp; }
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'o3dai', 'OpenRouter vision: ' . ( $body['error']['message'] ?? 'neznáma chyba' ) );
		}
		o3dai_track_text( 'openrouter', $s['model'] ?: 'openrouter/auto',
			intval( $body['usage']['prompt_tokens'] ?? 0 ), intval( $body['usage']['completion_tokens'] ?? 0 ) );
		return $body['choices'][0]['message']['content'] ?? '';
	}

	private static function gemini( $s, $system, $user, $max_tokens ) {
		if ( empty( $s['api_key_gemini'] ) ) {
			return new WP_Error( 'o3dai', 'Chýba Gemini API kľúč.' );
		}
		$model = $s['model'] ?: 'gemini-flash-latest';
		$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $s['api_key_gemini'] );
		$resp  = wp_remote_post( $url, array(
			'timeout' => 180,
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
				'contents'          => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $user ) ) ) ),
				'generationConfig'  => array( 'maxOutputTokens' => $max_tokens ),
			) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'o3dai', 'Gemini API: ' . ( $body['error']['message'] ?? 'neznáma chyba' ) );
		}
		$out = '';
		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			$out .= $part['text'] ?? '';
		}
		o3dai_track_text( 'gemini', $model,
			intval( $body['usageMetadata']['promptTokenCount'] ?? 0 ),
			intval( $body['usageMetadata']['candidatesTokenCount'] ?? 0 ) );
		return $out;
	}

	/**
	 * Gramatická/štylistická korektúra textu (zachová HTML značky).
	 * Vráti opravený text alebo pôvodný, ak korektúra zlyhá.
	 */
	public static function proofread( $text, $lang = '' ) {
		if ( '' === $lang ) {
			$lang = o3dai_get_settings()['brand_lang'] ?? 'slovenčina';
		}
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return $text;
		}
		$system = 'Si korektor jazyka ' . $lang . '. Dostaneš text (môže obsahovať HTML značky). '
			. 'Oprav gramatiku, skloňovanie, diakritiku a preklepy. Zachovaj význam, tón aj všetky HTML značky a odkazy nedotknuté. '
			. 'NEPRIDÁVAJ nič nové, nekomentuj, nevysvetľuj. Vráť VÝHRADNE opravený text a nič iné.';
		$out = self::generate( $system, $text, 4000 );
		if ( is_wp_error( $out ) ) {
			return $text; // radšej pôvodný než nič
		}
		$out = trim( (string) $out );
		// bezpečnostná poistka: ak by model vrátil prázdno alebo drasticky kratší text, ponechaj pôvodný
		if ( '' === $out || mb_strlen( $out ) < mb_strlen( $text ) * 0.5 ) {
			return $text;
		}
		return $out;
	}

}
