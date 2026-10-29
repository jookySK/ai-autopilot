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
			case 'local': // v1.9.x – Lokálny PC (Ollama / LM Studio)
				return self::text_local( $s, $system, $user, $max_tokens );
			case 'comfyui': // v1.9.x – Lokálny PC: text priamo cez ComfyUI (TextGenerate uzol — Gemma 4 a pod.)
				return self::text_comfy( $s, $system, $user, $max_tokens );
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
				'model'      => $s['model'] ?: 'claude-sonnet-5',
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
		o3dai_track_text( 'claude', $s['model'] ?: 'claude-sonnet-5',
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
			case 'local':
			case 'comfyui': // v1.9.x – lokálne textové modely (Ollama / LM Studio / ComfyUI) bez vision podpory
				return new WP_Error( 'o3dai', o3dai_t( 'local_text_no_vision' ) );
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
				'model'      => $s['model'] ?: 'claude-sonnet-5',
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
		o3dai_track_text( 'claude', $s['model'] ?: 'claude-sonnet-5',
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
				'model'                 => $s['model'] ?: 'gpt-5.6-terra',
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
		o3dai_track_text( 'openai', $s['model'] ?: 'gpt-5.6-terra',
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
		$s = o3dai_get_settings();
		if ( 'local' === (string) ( $s['image_mode'] ?? '' ) ) {
			return self::img_local( $s, $prompt ); // v1.9.0 – lokálny PC (Stability Matrix / ComfyUI), bez cloud failoveru
		}
		$model = $s['image_model'] ?? 'gpt-image-2.5-flare';
		$cands = ( 0 === strpos( $model, 'gemini-' ) )
			? array( array( 'gemini', $model ), array( 'openai', 'gpt-image-2.5-flare' ) )
			: array( array( 'openai', $model ), array( 'gemini', 'gemini-3-pro-image' ) );
		$first = null;
		foreach ( $cands as $i => $c ) {
			$has_key = ( 'gemini' === $c[0] ) ? ! empty( $s['api_key_gemini'] ) : ! empty( $s['api_key_openai'] );
			if ( ! $has_key ) {
				continue;
			}
			$r = ( 'gemini' === $c[0] ) ? self::img_gemini( $s, $prompt, $c[1] ) : self::img_openai( $s, $prompt, $c[1] );
			if ( ! is_wp_error( $r ) ) {
				if ( null !== $first ) {
					o3dai_log( o3dai_t( 'log_img_failover', array( 'x' => ( 'gemini' === $c[0] ? 'Gemini' : 'OpenAI' ), 'err' => $c[1] ) ) );
				}
				return $r;
			}
			if ( null === $first ) {
				$first = $r;
			}
			if ( $i < count( $cands ) - 1 ) {
				o3dai_log( o3dai_t( 'log_img_next_provider', array( 'err' => $r->get_error_message() ) ) );
			}
		}
		if ( null !== $first ) {
			return $first;
		}
		return new WP_Error( 'o3dai', 'Na generovanie obrázkov chýba API kľúč (OpenAI alebo Gemini).' );
	}

	/** v1.9.x – Lokálny PC: zoznam modelov z ComfyUI — checkpointy + difúzne modely (FLUX 3 Klein a pod.). */
	private static function local_comfy_model_lists( $base_url ) {
		$base = rtrim( (string) $base_url, '/' );
		if ( '' === $base ) { return array( 'checkpoints' => array(), 'unets' => array() ); }
		$out  = array( 'checkpoints' => array(), 'unets' => array() );
		$ok   = false;
		$r    = wp_remote_get( $base . '/object_info/CheckpointLoaderSimple', array( 'timeout' => 15 ) );
		if ( ! is_wp_error( $r ) && 200 === wp_remote_retrieve_response_code( $r ) ) {
			$b    = json_decode( wp_remote_retrieve_body( $r ), true );
			$list = $b['CheckpointLoaderSimple']['input']['required']['ckpt_name'][0] ?? array();
			if ( is_array( $list ) && $list ) {
				$out['checkpoints'] = array_values( array_map( 'strval', $list ) );
				$ok                 = true;
			}
		}
		foreach ( array( array( 'UNETLoader', 'unet_name' ), array( 'DiffusionModelLoader', 'model_name' ) ) as $o3d_lr ) { // v1.9.x – FLUX 3 Klein a pod. ležia v models/diffusion_models/, nie v checkpoints
			if ( ! empty( $out['unets'] ) ) { break; }
			$r2 = wp_remote_get( $base . '/object_info/' . $o3d_lr[0], array( 'timeout' => 15 ) );
			if ( is_wp_error( $r2 ) || 200 !== wp_remote_retrieve_response_code( $r2 ) ) { continue; }
			$b2   = json_decode( wp_remote_retrieve_body( $r2 ), true );
			$list = $b2[ $o3d_lr[0] ]['input']['required'][ $o3d_lr[1] ][0] ?? array();
			if ( is_array( $list ) && $list ) {
				$out['unets'] = array_values( array_map( 'strval', $list ) );
				$ok           = true;
			}
		}
		return $ok ? $out : new WP_Error( 'o3dai', o3dai_t( 'local_err_unreachable', array( 'url' => $base ) ) );
	}

	public static function local_comfy_models( $base_url ) {
		$lists = self::local_comfy_model_lists( $base_url );
		if ( is_wp_error( $lists ) ) { return $lists; }
		return array_values( array_unique( array_merge( $lists['checkpoints'], $lists['unets'] ) ) );
	}

	/** v1.9.x – Lokálny PC TEXT: chat completion cez OpenAI-kompatibilný endpoint (Ollama / LM Studio / vLLM…).
	 *  URL môže byť s aj bez /v1 — doplní sa automaticky. Timeout 600 s: lokálna inferencia dlhých
	 *  textov na veľkom modeli vie trvať minúty. */
	private static function text_local( $s, $system, $user, $max_tokens ) {
		$base = rtrim( (string) ( $s['text_local_url'] ?? '' ), '/' );
		if ( '' === $base ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_text_err_nourl' ) );
		}
		if ( ! preg_match( '#/v\d+$#i', $base ) ) {
			$base .= '/v1'; // http://IP:11434 → http://IP:11434/v1
		}
		$model = trim( (string) ( $s['text_local_model'] ?? '' ) );
		if ( '' === $model ) {
			$model = (string) ( $s['model'] ?? '' ); // fallback: model z všeobecného výberu
		}
		if ( '' === $model ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_text_err_model' ) );
		}
		// v1.9.12 - Qwen3 rodina na llama.cpp backendoch (LM Studio / Ollama): chat_template_kwargs tam ticho ignorujú, takže sa použije soft switch /no_think priamo v prompte
		if ( false !== stripos( $model, 'qwen3' ) && false === stripos( $user, '/no_think' ) ) {
			$user .= "\n/no_think";
			o3dai_log( o3dai_t( 'local_text_nothink_on' ) ); // v1.9.12 - log, že soft switch bol doplnený
		}
		if ( false !== stripos( $model, 'qwen3' ) && false === stripos( $system, '/no_think' ) ) {
			$system .= "\n/no_think"; // v1.9.13 – Qwen3 akceptuje /no_think aj v system správe (Qwen docs); zdvojenie zvyšuje spoľahlivosť vypnutia uvažovania
		}
		$resp = wp_remote_post( $base . '/chat/completions', array(
			'timeout' => 600,
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'model'      => $model,
				'max_tokens' => $max_tokens,
				// v1.9.11 - thinking modely (Qwen3 a pod.): vypni uvažovanie, aby sa odpoveď zmestila do max_tokens; Ollama / LM Studio / vLLM toto pole buď pošlú do chat template, alebo ho ignorujú
				'chat_template_kwargs' => array( 'enable_thinking' => false ),
				'messages'   => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $user ),
				),
			) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_text_err_conn' ) . ': ' . $resp->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		$code = wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return new WP_Error( 'o3dai', 'Local PC text: ' . ( $body['error']['message'] ?? ( 'HTTP ' . $code ) ) );
		}
		$msg     = $body['choices'][0]['message'] ?? array();
		$content = trim( (string) ( $msg['content'] ?? '' ) );
		if ( '' === $content && '' !== trim( (string) ( $msg['reasoning_content'] ?? '' ) ) ) {
			o3dai_log( o3dai_t( 'local_text_thinking_fallback' ) ); // v1.9.11 - thinking model dal odpoveď do reasoning_content
			$content = trim( (string) $msg['reasoning_content'] );
		}
		if ( '' === $content ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_text_err_empty', array( 'model' => $model, 'ft' => (string) ( $body['choices'][0]['finish_reason'] ?? '?' ) ) ) ); // v1.9.11 - zrozumiteľná hláška namiesto "HTTP 200"
		}
		o3dai_track_text( 'local', $model, intval( $body['usage']['prompt_tokens'] ?? 0 ), intval( $body['usage']['completion_tokens'] ?? 0 ) );
		return $content;
	}

	/** v1.9.x – Lokálny PC TEXT: zoznam modelov z OpenAI-kompatibilného servera (GET /v1/models). */
	public static function text_local_models( $url ) {
		$base = rtrim( (string) $url, '/' );
		if ( '' === $base ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_text_err_nourl' ) );
		}
		if ( ! preg_match( '#/v\d+$#i', $base ) ) {
			$base .= '/v1';
		}
		$r = wp_remote_get( $base . '/models', array( 'timeout' => 15 ) );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_text_err_conn' ) . ': ' . $r->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $r ), true );
		$out  = array();
		foreach ( (array) ( $body['data'] ?? array() ) as $m ) {
			if ( ! empty( $m['id'] ) ) {
				$out[] = (string) $m['id'];
			}
		}
		sort( $out );
		return $out;
	}

	/** v1.9.x – Spoločné ComfyUI API: POST /prompt (validate=true) + poll /history/{id} do hotova (~10 min).
	 *  Vráti history záznam (array) alebo WP_Error; status 'error' rozbalí volajúci. */
	private static function comfyui_post_and_wait( $base, array $workflow ) {
		$resp = wp_remote_post( $base . '/prompt', array(
			'timeout' => 30,
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'client_id' => uniqid( 'o3dai_', true ), 'prompt' => $workflow, 'validate' => true ) ), // validate=true: pri zlyhaní vráti citelnú chybu uzla
		) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_unreachable', array( 'url' => $base ) ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) || empty( $body['prompt_id'] ) ) {
			$detail = '';
			if ( is_array( $body ) && isset( $body['error'] ) ) {
				$err = $body['error'];
				if ( is_scalar( $err ) ) {
					$detail = (string) $err;
				} elseif ( is_array( $err ) ) {
					$parts = array();
					foreach ( array( 'type', 'message', 'details' ) as $o3d_ek ) {
						if ( isset( $err[ $o3d_ek ] ) && is_scalar( $err[ $o3d_ek ] ) ) { $parts[] = (string) $err[ $o3d_ek ]; }
					}
					$detail = '' !== implode( ': ', array_filter( $parts ) ) ? implode( ': ', array_filter( $parts ) ) : wp_json_encode( $err );
				}
			}
			if ( '' === $detail && is_array( $body ) && ! empty( $body['node_errors'] ) && is_array( $body['node_errors'] ) ) {
				$first  = reset( $body['node_errors'] );
				$detail = is_scalar( $first ) ? (string) $first : wp_json_encode( $first );
			}
			if ( '' === trim( (string) $detail ) ) {
				$raw    = trim( (string) wp_remote_retrieve_body( $resp ) );
				$detail = '' !== $raw ? $raw : 'HTTP ' . wp_remote_retrieve_response_code( $resp );
			}
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_queue', array( 'x' => substr( (string) $detail, 0, 200 ) ) ) );
		}
		$pid   = (string) $body['prompt_id'];
		@set_time_limit( 0 ); // lokálna inferencia vie trvať minúty
		$start = microtime( true );
		$o3d_ticks = 0; // v1.9.x – živý stav: aktualizácia čakacieho popisku ~každých 10 s
		while ( ( microtime( true ) - $start ) < 600 ) {
			$hr = wp_remote_get( $base . '/history/' . rawurlencode( $pid ), array( 'timeout' => 20 ) );
			if ( ! is_wp_error( $hr ) && 200 === wp_remote_retrieve_response_code( $hr ) ) {
				$hb = json_decode( wp_remote_retrieve_body( $hr ), true );
				if ( is_array( $hb ) && isset( $hb[ $pid ] ) && is_array( $hb[ $pid ] ) ) {
					// novy ComfyUI history format: status.completed=false = stale beži; starsie verzie bez tohto poľa = záznam existuje až po dokončení
					$statusObj  = (array) ( $hb[ $pid ]['status'] ?? array() );
					$isComplete = ! array_key_exists( 'completed', $statusObj ) || true === (bool) $statusObj['completed'];
					if ( $isComplete ) {
						return $hb[ $pid ];
					}
				}
			}
			if ( 0 === $o3d_ticks % 5 ) { // v1.9.x – každých ~10 s: ako dlho už ComfyUI beží
				O3DAI_Progress::set_running( o3dai_t( 'step_wait_comfy', array( 's' => intval( microtime( true ) - $start ) ) ) );
			}
			$o3d_ticks ++;
			sleep( 2 );
		}
		return new WP_Error( 'o3dai', o3dai_t( 'local_err_timeout' ) );
	}

	/** v1.9.x – Lokálny PC TEXT priamo cez ComfyUI (Stability Matrix): oficiálna šablóna llm_gemma4_text_gen —
	 *  CLIPLoader(type=stable_diffusion) → TextGenerate(sampling) → PreviewAny (výstupný uzol, bez neho
	 *  ComfyUI hlási prompt_no_outputs). Model = súbor z models/text_encoders (gemma4_e4b_it_fp8_scaled a pod.).
	 *  Ak je text_comfy_url prázdne, použije sa adresa lokálneho PC z nastavení obrázkov. */
	private static function text_comfy( $s, $system, $user, $max_tokens ) {
		$base = rtrim( (string) ( $s['text_comfy_url'] ?? '' ), '/' );
		if ( '' === $base ) {
			$base = rtrim( (string) ( $s['image_local_url'] ?? '' ), '/' ); // fallback: ten istý ComfyUI ako na obrázky
		}
		if ( '' === $base ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_text_err_nourl' ) );
		}
		$model = trim( (string) ( $s['text_comfy_model'] ?? '' ) );
		if ( '' === $model ) {
			// len reálne textové LLM; umt5/qwen3vl a pod. sú enkódery pre obrázky — tie sa nevyberajú slepo
			// v1.9.3 – 'qwen3': reálne súbory sa volajú qwen3_5_* (bez podčiarkovníka medzi qwen a 3)
			$model = self::local_pick_model( $base, 'CLIPLoader', 'clip_name', array( 'gemma4_e4b', 'gemma4_e2b', 'qwen3', 'qwen_3_', 'gemma_3' ) );
			if ( is_wp_error( $model ) || '' === $model ) {
				return new WP_Error( 'o3dai', o3dai_t( 'text_comfy_err_model' ) );
			}
		}
		$max_len = min( 32768, max( 128, (int) $max_tokens ) ); // TextGenerate: max_length = tokeny výstupu
		$workflow = array(
			'3' => array( 'class_type' => 'CLIPLoader', 'inputs' => array( 'clip_name' => $model, 'type' => 'stable_diffusion', 'device' => 'default' ) ),
			'2' => array( 'class_type' => 'TextGenerate', 'inputs' => array(
				'clip'                             => array( '3', 0 ),
				'prompt'                           => $user,
				// v1.9.14 – Gemma 3/4: explicitne vypnutý thinking režim; bez neho model píše uvažovanie priamo do výstupu ("ghost thoughts", najmä E2B/E4B)
				'thinking'                         => false,
				'max_length'                       => $max_len,
				'sampling_mode'                    => 'on', // oficiálne predvolené hodnoty (Comfy-Org llm_gemma4_text_gen)
				// v API mode sa sub-parametre samplingu posielajú s bodkovaným menom (sampling_mode.*), inak prompt_outputs_failed_validation
				'sampling_mode.temperature'        => 0.7,
				'sampling_mode.top_k'              => 64,
				'sampling_mode.seed'               => wp_rand( 0, 2147483647 ),
				'sampling_mode.top_p'              => 0.95,
				'sampling_mode.min_p'              => 0.05,
				'sampling_mode.repetition_penalty' => 1.05,
				'system_prompt'                    => $system, // nahradí systémový prompt modelu v chat template
			) ),
			'1' => array( 'class_type' => 'PreviewAny', 'inputs' => array( 'source' => array( '2', 0 ) ) ), // výstupný uzol
		);
		$entry = self::comfyui_post_and_wait( $base, $workflow );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$o3d_st = (array) ( $entry['status'] ?? array() );
		if ( in_array( 'error', array( (string) ( $o3d_st['status'] ?? '' ), (string) ( $o3d_st['status_str'] ?? '' ) ), true ) ) {
			$detail = '';
			foreach ( (array) ( $entry['status']['messages'] ?? array() ) as $m ) {
				if ( is_array( $m ) && count( $m ) >= 2 ) { $detail .= ' ' . implode( ': ', array_map( 'strval', $m ) ); }
			}
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_workflow', array( 'x' => substr( trim( $detail ), 0, 200 ) ) ) );
		}
		$text = '';
		$o3d_avail = array(); // v1.9.x – pre chybovú hlášku: čo ComfyUI reálne vrátil (uzol:kľúče)
		foreach ( (array) ( $entry['outputs'] ?? array() ) as $o3d_nid => $nodeOut ) {
			if ( ! is_array( $nodeOut ) ) { continue; }
			$o3d_avail[] = $o3d_nid . ':' . implode( ',', array_keys( $nodeOut ) );
			foreach ( array( 'generated_text', 'result', 'text' ) as $o3d_key ) { // TextGenerate: generated_text / PreviewAny: result – rôzne verzie ComfyUI vracajú iný kľúč
				if ( empty( $nodeOut[ $o3d_key ] ) ) { continue; }
				$o3d_v = $nodeOut[ $o3d_key ];
				if ( is_string( $o3d_v ) && '' !== trim( $o3d_v ) ) { $text = $o3d_v; break 2; }
				if ( is_array( $o3d_v ) ) {
					$o3d_parts = array();
					foreach ( $o3d_v as $item ) { if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) { $o3d_parts[] = (string) $item; } }
					if ( $o3d_parts ) { $text = implode( "\n", $o3d_parts ); break 2; }
				}
			}
		}
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'o3dai', o3dai_t( 'text_comfy_err_notext', array( 'x' => substr( implode( ' | ', $o3d_avail ), 0, 200 ) ) ) );
		}
		// v1.9.14 – TextGenerate má samostatný výstup "thinking" (uvažovanie modelu); ak sa vrátil, je odhodený od odpovede → zaloguj dĺžku
		foreach ( (array) ( $entry['outputs'] ?? array() ) as $o3d_nid2 => $nodeOut2 ) {
			if ( is_array( $nodeOut2 ) && ! empty( $nodeOut2['thinking'] ) && is_string( $nodeOut2['thinking'] ) && '' !== trim( $nodeOut2['thinking'] ) ) {
				o3dai_log( o3dai_t( 'text_comfy_thinking_dropped', array( 'x' => mb_strlen( trim( (string) $nodeOut2['thinking'] ) ) ) ) ); // v1.9.14 – vidno, či sa uvažovanie oddelilo alebo nie
				break;
			}
		}
		o3dai_track_text( 'local', $model, 0, max( 1, intval( strlen( $text ) / 4 ) ) ); // lokálne = žiadne API náklady; tokeny odhadnuté
		return $text;
	}

	/** v1.9.x – Lokálny PC TEXT cez ComfyUI: zoznam textových modelov (súbory models/text_encoders, CLIPLoader.clip_name). */
	public static function text_comfy_models( $url ) {
		$base = rtrim( (string) $url, '/' );
		if ( '' === $base ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_text_err_nourl' ) );
		}
		$r    = wp_remote_get( $base . '/object_info/CLIPLoader', array( 'timeout' => 15 ) );
		if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_unreachable', array( 'url' => $base ) ) );
		}
		$b    = json_decode( wp_remote_retrieve_body( $r ), true );
		$list = $b['CLIPLoader']['input']['required']['clip_name'][0] ?? array();
		if ( ! is_array( $list ) || ! $list ) {
			return new WP_Error( 'o3dai', o3dai_t( 'text_comfy_err_model' ) );
		}
		$out  = array_values( array_map( 'strval', $list ) );
		sort( $out );
		return $out;
	}

	/** v1.9.x – Vyberie súbor z object_info uzla: najprv podľa preferencií (podreťazce), inak prvý dostupný. */
	private static function local_pick_model( $base_url, $node_type, $input_name, array $prefers ) {
		$base = rtrim( (string) $base_url, '/' );
		$r    = wp_remote_get( $base . '/object_info/' . $node_type, array( 'timeout' => 15 ) );
		if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_unreachable', array( 'url' => $base ) ) );
		}
		$b    = json_decode( wp_remote_retrieve_body( $r ), true );
		$list = $b[ $node_type ]['input']['required'][ $input_name ][0] ?? array();
		if ( ! is_array( $list ) || ! $list ) { return ''; }
		foreach ( $prefers as $needle ) {
			foreach ( $list as $cand ) {
				if ( false !== stripos( (string) $cand, $needle ) ) { return (string) $cand; }
			}
		}
		// v1.9.x – fallback: preskočí LTX / projekčné varianty (enkódery pre difúznu kondíciu videa), ak je iná voľba
		foreach ( $list as $cand ) {
			if ( false === stripos( strtolower( (string) $cand ), 'ltx' ) && false === strpos( strtolower( (string) $cand ), 'with-proj' ) ) { return (string) $cand; }
		}
		return (string) $list[0];
	}

	/** v1.9.x – Kvalitnejší sampler pre FLUX 3 (er_sde), ak ho server má, inak euler. */
	private static function local_pick_sampler( $base_url ) {
		$base = rtrim( (string) $base_url, '/' );
		$r    = wp_remote_get( $base . '/object_info/KSampler', array( 'timeout' => 15 ) );
		if ( ! is_wp_error( $r ) && 200 === wp_remote_retrieve_response_code( $r ) ) {
			$b        = json_decode( wp_remote_retrieve_body( $r ), true );
			$samplers = $b['KSampler']['input']['required']['sampler_name'][0] ?? array();
			if ( is_array( $samplers ) && $samplers ) {
				if ( in_array( 'er_sde', $samplers, true ) ) { return 'er_sde'; }
				return in_array( 'euler', $samplers, true ) ? 'euler' : (string) $samplers[0];
			}
		}
		return 'euler';
	}

	/** v1.9.0 – Lokálny PC: txt2img cez ComfyUI API (Stability Matrix). Vracia binárku obrázka alebo WP_Error.
	 *  Minimálny workflow: CheckpointLoaderSimple → CLIPTextEncode (+-) → EmptyLatentImage → KSampler.
	 *  LoRA pridáme neskôr (extra LoraLoader uzol medzi checkpoint a KSampler). */
	private static function img_local( $s, $prompt ) {
		$base = rtrim( (string) ( $s['image_local_url'] ?? '' ), '/' );
		if ( '' === $base ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_nourl' ) );
		}
		// v1.9.x – zoznam modelov (checkpointy + difúzne) — aj na detekciu typu zvoleného modelu
		$lists = self::local_comfy_model_lists( $base );
		if ( is_wp_error( $lists ) ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_unreachable', array( 'url' => $base ) ) );
		}
		$model = trim( (string) ( $s['image_local_model'] ?? '' ) );
		if ( '' === $model ) {
			$all   = array_merge( $lists['checkpoints'], $lists['unets'] );
			if ( empty( $all[0] ) ) {
				return new WP_Error( 'o3dai', o3dai_t( 'local_err_nomodel' ) );
			}
			$model = $all[0]; // prvý dostupný model
		}
		$is_flux3 = in_array( $model, $lists['unets'], true ); // v1.9.x – FLUX 3 Klein a pod.: iný graf workflowu
		$neg   = trim( (string) ( $s['image_local_negative'] ?? '' ) );
		if ( '' === $neg ) { $neg = 'text, watermark, logo, signature'; }
		$steps = min( 150, max( 1, intval( $s['image_local_steps'] ?? 20 ) ) );
		$cfg   = min( 30.0, max( 1.0, (float) ( $s['image_local_cfg'] ?? 7 ) ) );
		$w     = min( 2048, max( 64, intval( $s['image_local_width'] ?? 1152 ) ) );
		$h     = min( 2048, max( 64, intval( $s['image_local_height'] ?? 768 ) ) );
		$w     = (int) round( $w / 8 ) * 8; // ComfyUI: rozmery násobkom 8
		$h     = (int) round( $h / 8 ) * 8;

		// v1.9.0 – workflow MUSÍ končiť výstupným uzlom (SaveImage), inak ComfyUI: prompt_no_outputs.
		if ( $is_flux3 ) {
			// v1.9.x – FLUX 3 Klein a podobné difúzne modely: UNETLoader + CLIPLoader(flux3) + VAELoader + EmptyFlux2LatentImage
			// (oficiálna šablóna Comfy-Org image_flux3_klein_t2i). Textový enkoder aj VAE sa vyberajú zo servera;
			// pre distilled verziu platí optimum 16 krokov / CFG 1, preto sa tu ignorujú polia Kroky/CFG z nastavení.
			$clip_name = self::local_pick_model( $base, 'CLIPLoader', 'clip_name', array( 'qwen3vl' ) );
			if ( is_wp_error( $clip_name ) || '' === $clip_name ) {
				return new WP_Error( 'o3dai', o3dai_t( 'local_err_clip' ) );
			}
			$vae_name = self::local_pick_model( $base, 'VAELoader', 'vae_name', array( 'flux2-vae', 'flux' ) );
			if ( is_wp_error( $vae_name ) || '' === $vae_name ) {
				return new WP_Error( 'o3dai', o3dai_t( 'local_err_vae' ) );
			}
			$sampler  = self::local_pick_sampler( $base ); // er_sde ak ho server má, inak euler
			$steps    = 16;
			$cfg      = 1.0;
			$workflow = array(
				'9' => array( 'class_type' => 'UNETLoader', 'inputs' => array( 'unet_name' => $model, 'weight_dtype' => 'default' ) ),
				'8' => array( 'class_type' => 'CLIPLoader', 'inputs' => array( 'clip_name' => $clip_name, 'type' => 'flux3', 'device' => 'default' ) ),
				'7' => array( 'class_type' => 'VAELoader', 'inputs' => array( 'vae_name' => $vae_name ) ),
				'6' => array( 'class_type' => 'CLIPTextEncode', 'inputs' => array( 'text' => $prompt, 'clip' => array( '8', 0 ) ) ),
				'5' => array( 'class_type' => 'CLIPTextEncode', 'inputs' => array( 'text' => $neg, 'clip' => array( '8', 0 ) ) ),
				'4' => array( 'class_type' => 'EmptyFlux2LatentImage', 'inputs' => array( 'width' => $w, 'height' => $h, 'batch_size' => 1 ) ),
				'3' => array( 'class_type' => 'KSampler', 'inputs' => array(
					'seed'         => wp_rand( 0, 2147483647 ),
					'steps'        => $steps,
					'cfg'          => $cfg,
					'sampler_name' => $sampler,
					'scheduler'    => 'simple',
					'positive'     => array( '6', 0 ),
					'negative'     => array( '5', 0 ),
					'model'        => array( '9', 0 ), // v1.9.x – UNETLoader output 0 = MODEL (chyba prompt_outputs_failed_validation)
					'latent_image' => array( '4', 0 ),
					'denoise'      => 1.0,
				) ),
				'2' => array( 'class_type' => 'VAEDecode', 'inputs' => array( 'samples' => array( '3', 0 ), 'vae' => array( '7', 0 ) ) ),
				'1' => array( 'class_type' => 'SaveImage', 'inputs' => array( 'filename_prefix' => 'o3dai', 'images' => array( '2', 0 ) ) ),
			);
		} else {
			// CheckpointLoaderSimple výstupy: 0=MODEL, 1=CLIP, 2=VAE; KSampler → LATENT → VAEDecode → IMAGE.
			$workflow = array(
				'3' => array( 'class_type' => 'CheckpointLoaderSimple', 'inputs' => array( 'ckpt_name' => $model ) ),
				'6' => array( 'class_type' => 'CLIPTextEncode', 'inputs' => array( 'text' => $prompt, 'clip' => array( '3', 1 ) ) ),
				'7' => array( 'class_type' => 'CLIPTextEncode', 'inputs' => array( 'text' => $neg, 'clip' => array( '3', 1 ) ) ),
				'5' => array( 'class_type' => 'EmptyLatentImage', 'inputs' => array( 'width' => $w, 'height' => $h, 'batch_size' => 1 ) ),
				'4' => array( 'class_type' => 'KSampler', 'inputs' => array(
					'seed'         => wp_rand( 0, 2147483647 ),
					'steps'        => $steps,
					'cfg'          => $cfg,
					'sampler_name' => 'euler',
					'scheduler'    => 'normal',
					'positive'     => array( '6', 0 ),
					'negative'     => array( '7', 0 ),
					'model'        => array( '3', 0 ), // v1.9.x – CheckpointLoaderSimple output 0 = MODEL
					'latent_image' => array( '5', 0 ),
					'denoise'      => 1.0,
				) ),
				'2' => array( 'class_type' => 'VAEDecode', 'inputs' => array( 'samples' => array( '4', 0 ), 'vae' => array( '3', 2 ) ) ),
				'1' => array( 'class_type' => 'SaveImage', 'inputs' => array( 'filename_prefix' => 'o3dai', 'images' => array( '2', 0 ) ) ),
			);
		}

		$resp = wp_remote_post( $base . '/prompt', array(
			'timeout' => 30,
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'client_id' => uniqid( 'o3dai_', true ), 'prompt' => $workflow, 'validate' => true ) ), // validate=true: pri zlyhaní vrati citelnu chybu uzla
		) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_unreachable', array( 'url' => $base ) ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) || empty( $body['prompt_id'] ) ) {
			// v1.9.0 – error moze byt string aj objekt; extrahuj cititelnu spravu, inak raw body
			$detail = '';
			if ( is_array( $body ) && isset( $body['error'] ) ) {
				$err = $body['error'];
				if ( is_scalar( $err ) ) {
					$detail = (string) $err;
				} elseif ( is_array( $err ) ) {
					$parts = array();
					foreach ( array( 'type', 'message', 'details' ) as $o3d_ek ) {
						if ( isset( $err[ $o3d_ek ] ) && is_scalar( $err[ $o3d_ek ] ) ) { $parts[] = (string) $err[ $o3d_ek ]; }
					}
					$detail = '' !== implode( ': ', array_filter( $parts ) ) ? implode( ': ', $parts ) : wp_json_encode( $err );
				}
			}
			if ( '' === $detail && is_array( $body ) && ! empty( $body['node_errors'] ) && is_array( $body['node_errors'] ) ) {
				$first = reset( $body['node_errors'] );
				$detail = is_scalar( $first ) ? (string) $first : wp_json_encode( $first );
			}
			if ( '' === trim( (string) $detail ) ) {
				$raw    = trim( (string) wp_remote_retrieve_body( $resp ) );
				$detail = '' !== $raw ? $raw : 'HTTP ' . wp_remote_retrieve_response_code( $resp );
			}
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_queue', array( 'x' => substr( (string) $detail, 0, 200 ) ) ) );
		}
		$pid = (string) $body['prompt_id'];

		// ComfyUI beží aj na CPU – generovanie môže trvať minúty; celkový limit ~10 minút
		@set_time_limit( 0 );
		$start = microtime( true );
		$entry = null;
		while ( ( microtime( true ) - $start ) < 600 ) {
			$hr = wp_remote_get( $base . '/history/' . rawurlencode( $pid ), array( 'timeout' => 20 ) );
			if ( ! is_wp_error( $hr ) && 200 === wp_remote_retrieve_response_code( $hr ) ) {
				$hb = json_decode( wp_remote_retrieve_body( $hr ), true );
				if ( is_array( $hb ) && isset( $hb[ $pid ] ) ) { $entry = $hb[ $pid ]; break; }
			}
			sleep( 2 );
		}
		if ( null === $entry ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_timeout' ) );
		}
		$status = (string) ( $entry['status']['status'] ?? 'success' );
		if ( 'error' === $status ) {
			$detail = '';
			foreach ( (array) ( $entry['status']['messages'] ?? array() ) as $m ) {
				if ( is_array( $m ) && count( $m ) >= 2 ) { $detail .= ' ' . implode( ': ', array_map( 'strval', $m ) ); }
			}
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_workflow', array( 'x' => substr( trim( $detail ), 0, 200 ) ) ) );
		}

		$fn = ''; $sf = ''; $tp = '';
		foreach ( (array) ( $entry['outputs'] ?? array() ) as $nodeOut ) {
			if ( ! empty( $nodeOut['images'][0]['filename'] ) ) {
				$fn = (string) $nodeOut['images'][0]['filename'];
				$sf = (string) ( $nodeOut['images'][0]['subfolder'] ?? '' );
				$tp = (string) ( $nodeOut['images'][0]['type'] ?? 'output' );
				break;
			}
		}
		if ( '' === $fn ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_noimage' ) );
		}

		$vr = wp_remote_get( add_query_arg( array( 'filename' => $fn, 'subfolder' => $sf, 'type' => $tp ), $base . '/view' ), array( 'timeout' => 60 ) );
		if ( is_wp_error( $vr ) || 200 !== wp_remote_retrieve_response_code( $vr ) ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_noimage' ) );
		}
		$bin = wp_remote_retrieve_body( $vr );
		if ( ! is_string( $bin ) || strlen( $bin ) < 1024 ) {
			return new WP_Error( 'o3dai', o3dai_t( 'local_err_noimage' ) );
		}
		o3dai_track_image( $model, 0 ); // lokálne = žiadne API náklady
		return $bin;
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

	private static function img_gemini( $s, $prompt, $model = 'gemini-3-pro-image' ) {
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
				'model'                 => $s['model'] ?: 'gpt-5.6-terra',
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
		o3dai_track_text( 'openai', $s['model'] ?: 'gpt-5.6-terra',
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
		$out = self::generate( $system, $text, 8000 ); // v1.9.14 – 4000 orezávalo dlhé články uprostad vetou (Gemma 4 s uvažovaním potrebuje viac)
		if ( is_wp_error( $out ) ) {
			return $text; // radšej pôvodný než nič
		}
		$out = trim( (string) $out );
		// bezpečnostná poistka: ak by model vrátil prázdno alebo drasticky kratší text, ponechaj pôvodný
		if ( '' === $out || mb_strlen( $out ) < mb_strlen( $text ) * 0.5 ) {
			return $text;
		}
		// v1.9.13 – thinking model môže vrátiť vlastné uvažovanie namiesto opraveného HTML (bez žiadnej značky → nie je to článok)
		if ( false !== strpos( $text, '<' ) && false === strpos( $out, '<' ) ) {
			return $text;
		}
		// v1.9.14 – thinking model bez samostatného reasoning kanála napíše uvažovanie priamo do odpovede (Gemma 4 a pod.): korektúra + poznámky = výrazne dlhší text ako vstup
		if ( mb_strlen( $out ) > mb_strlen( $text ) * 1.3 ) {
			o3dai_log( o3dai_t( 'log_proofread_thinking_kept' ) ); // v1.9.14 – do korektúry sa dostalo uvažovanie modelu → pôvodný text
			return $text;
		}
		return $out;
	}

}
