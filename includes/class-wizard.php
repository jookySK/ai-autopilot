<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Sprievodca prvým nastavením (onboarding wizard).
 * Zobrazí sa, kým nie je 'onboarded' = 1.
 */
class O3DAI_Wizard {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_init', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_submenu_page( null, o3dai_t( 'w_title' ), o3dai_t( 'w_title' ), 'manage_options', 'o3dai-wizard', array( __CLASS__, 'render' ) );
	}

	/** Po aktivácii presmeruj na sprievodcu, ak ešte nebol dokončený */
	public static function maybe_redirect() {
		if ( ! get_option( 'o3dai_do_redirect' ) ) {
			return;
		}
		delete_option( 'o3dai_do_redirect' );
		// nepresmeruj počas ukladania formulárov ani pri AJAX/cron
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only checks that some form was submitted (so saving is not interrupted); no data is read here.
		if ( ! empty( $_POST ) || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		$s = o3dai_get_settings();
		if ( empty( $s['onboarded'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=o3dai-wizard' ) );
			exit;
		}
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// check_admin_referer() musí bežať IBA pre požiadavky nášho formulára — pri cudzom
		// POST-e (Settings, CF7...) by zabil celú admin požiadavku ("The link you followed
		// has expired."). Preto najprv marker formulára, potom nonce — v tom istom riadku.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified on this same line, right after the form marker check.
		if ( ! isset( $_POST['o3dai_wizard'] ) || ! check_admin_referer( 'o3dai_wizard' ) ) {
			return;
		}

		$s = o3dai_get_settings();
		foreach ( array( 'brand_name', 'brand_desc', 'brand_lang', 'brand_lang_code', 'cta_text', 'image_subject_hint', 'model', 'api_key_claude', 'api_key_openai', 'api_key_gemini', 'api_key_openrouter' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$s[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		if ( isset( $_POST['provider'] ) ) {
			$s['provider'] = sanitize_key( wp_unslash( $_POST['provider'] ) );
		}
		foreach ( array( 'image_subjects', 'instructions', 'image_style' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$s[ $key ] = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		foreach ( array( 'color_primary', 'color_accent', 'color_accent2', 'color_dark' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$s[ $key ] = sanitize_hex_color( wp_unslash( $_POST[ $key ] ) );
			}
		}
		// Doplň rozumné neutrálne defaulty, ak zákazník nechal kľúčové polia prázdne,
		// aby plugin fungoval aj bez ich vyplnenia.
		$fallbacks = array(
			'brand_lang'      => 'English',
			'brand_lang_code' => 'en',
			'cta_text'        => o3dai_t( 'w_ph_cta_val' ),
			'model'           => 'claude-sonnet-4-6',
			'provider'        => 'claude',
			'image_style'     => 'soft watercolor illustration, warm pastel palette, soft light, no text, no watermark',
			'image_subject_hint' => 'friendly characters',
			'instructions'    => 'Write in a clear, friendly tone. 600–900 words, H2 subheadings, practical tips, and natural internal links.',
			'color_primary'   => '#3858e9',
			'color_accent'    => '#2bc6b4',
			'color_accent2'   => '#f5a623',
			'color_dark'      => '#1e1e2e',
		);
		foreach ( $fallbacks as $k => $def ) {
			if ( empty( $s[ $k ] ) ) {
				$s[ $k ] = $def;
			}
		}

		$s['onboarded'] = 1;
		update_option( 'o3dai_settings', $s );

		wp_safe_redirect( admin_url( 'admin.php?page=o3dai&o3dai_welcome=1' ) );
		exit;
	}

	/** v1.6.0 – wizard CSS/JS cez wp_enqueue_* (wp.org plugin-check), len na stránke sprievodcu. */
	public static function assets() {
		if ( ! isset( $_GET['page'] ) || 'o3dai-wizard' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin page detection, not form data
			return;
		}
		wp_enqueue_style( 'o3dai-wizard', plugins_url( '../assets/css/wizard.css', __FILE__ ), array(), O3DAI_VERSION );

		// dynamické brand farby (inline :root premené) — rovnaké defaulty ako v save()
		$s       = o3dai_get_settings();
		$neutral = array( 'color_primary' => '#3858e9', 'color_accent' => '#2bc6b4', 'color_accent2' => '#f5a623', 'color_dark' => '#1e1e2e' );
		foreach ( $neutral as $o3d_wk => $o3d_wdef ) {
			if ( empty( $s[ $o3d_wk ] ) ) {
				$s[ $o3d_wk ] = $o3d_wdef;
			}
		}
		wp_add_inline_style( 'o3dai-wizard', ':root{--o3dw-p:' . esc_html( sanitize_hex_color( $s['color_primary'] ) ?: '#3858e9' ) . ';--o3dw-a:' . esc_html( sanitize_hex_color( $s['color_accent'] ) ?: '#2bc6b4' ) . ';}' );

		wp_enqueue_script( 'o3dai-wizard-model', plugins_url( '../assets/js/model-select.js', __FILE__ ), array(), O3DAI_VERSION, true );
		$fresh = empty( $s['onboarded'] );
		wp_add_inline_script(
			'o3dai-wizard-model',
			'window.O3DAI_MODEL_CFG=' . wp_json_encode(
				array(
					'provId'      => 'o3dw-provider',
					'modelId'     => 'o3dw-model',
					'models'      => o3dai_models(),
					'current'     => ( $fresh ? 'claude-sonnet-4-6' : (string) $s['model'] ),
					'customLabel' => '',
				)
			)
		);
	}

	public static function render() {
		$s = o3dai_get_settings();
		// Čerstvá inštalácia = ešte neonboardovaná → polia nechaj prázdne (len nápovedy),
		// aby nový zákazník nevidel prednastavené hodnoty z vývoja.
		$fresh = empty( $s['onboarded'] );
		$v = function ( $key, $default_when_returning = '' ) use ( $s, $fresh ) {
			return $fresh ? '' : ( $s[ $key ] ?? $default_when_returning );
		};
		// pre neutrálne farby pri čerstvej inštalácii (nie fialová z vývoja)
		$neutral = array( 'color_primary' => '#3858e9', 'color_accent' => '#2bc6b4', 'color_accent2' => '#f5a623', 'color_dark' => '#1e1e2e' );
		$cv = function ( $key ) use ( $s, $fresh, $neutral ) {
			return $fresh ? $neutral[ $key ] : ( $s[ $key ] ?? $neutral[ $key ] );
		};
		$p = $cv( 'color_primary' ); $a = $cv( 'color_accent' );
		?>
		<div class="o3dw">
			<div class="o3dw-hero">
				<h1><?php echo esc_html( o3dai_t( 'w_welcome' ) ); ?></h1>
				<p><?php echo esc_html( o3dai_t( 'w_intro' ) ); ?></p>
			</div>
			<form method="post">
				<?php wp_nonce_field( 'o3dai_wizard' ); ?>
				<input type="hidden" name="o3dai_wizard" value="1">

				<div class="o3dw-card">
					<h2><?php echo esc_html( o3dai_t( 'w_s1' ) ); ?></h2>
					<p class="sub"><?php echo esc_html( o3dai_t( 'w_s1_sub' ) ); ?></p>
					<div class="o3dw-row">
						<div class="o3dw-field">
							<label><?php echo esc_html( o3dai_t( 'brand_name' ) ); ?></label>
							<input type="text" name="brand_name" value="<?php echo esc_attr( $v( 'brand_name' ) ); ?>" placeholder="<?php echo esc_attr( o3dai_t( 'w_ph_brand' ) ); ?>">
						</div>
						<div class="o3dw-field">
							<label><?php echo esc_html( o3dai_t( 'brand_desc' ) ); ?></label>
							<input type="text" name="brand_desc" value="<?php echo esc_attr( $v( 'brand_desc' ) ); ?>" placeholder="<?php echo esc_attr( o3dai_t( 'w_ph_desc' ) ); ?>">
						</div>
					</div>
					<div class="o3dw-field">
						<label><?php echo esc_html( o3dai_t( 'w_how_write' ) ); ?></label>
						<textarea name="instructions" rows="3" placeholder="<?php echo esc_attr( o3dai_t( 'w_ph_instr' ) ); ?>"><?php echo esc_textarea( $v( 'instructions' ) ); ?></textarea>
						<div class="hint"><?php echo esc_html( o3dai_t( 'w_how_write_hint' ) ); ?></div>
					</div>
				</div>

				<div class="o3dw-card">
					<h2><?php echo esc_html( o3dai_t( 'w_s2' ) ); ?></h2>
					<div class="o3dw-row">
						<div class="o3dw-field">
							<label><?php echo esc_html( o3dai_t( 'content_lang' ) ); ?></label>
							<input type="text" name="brand_lang" value="<?php echo esc_attr( $v( 'brand_lang' ) ); ?>" placeholder="<?php echo esc_attr( o3dai_t( 'w_ph_lang' ) ); ?>">
						</div>
						<div class="o3dw-field">
							<label><?php echo esc_html( o3dai_t( 'lang_code' ) ); ?></label>
							<input type="text" name="brand_lang_code" value="<?php echo esc_attr( $v( 'brand_lang_code' ) ); ?>" placeholder="en">
							<div class="hint"><?php echo esc_html( o3dai_t( 'w_lang_code_hint' ) ); ?></div>
						</div>
					</div>
					<div class="o3dw-field">
						<label><?php echo esc_html( o3dai_t( 'cta_text' ) ); ?></label>
						<input type="text" name="cta_text" value="<?php echo esc_attr( $v( 'cta_text' ) ); ?>" placeholder="<?php echo esc_attr( o3dai_t( 'w_ph_cta' ) ); ?>">
						<div class="hint"><?php echo esc_html( o3dai_t( 'w_cta_hint' ) ); ?></div>
					</div>
				</div>

				<div class="o3dw-card">
					<h2><?php echo esc_html( o3dai_t( 'w_s3' ) ); ?></h2>
					<p class="sub"><?php echo esc_html( o3dai_t( 'w_s3_sub' ) ); ?></p>
					<div class="o3dw-row">
						<div class="o3dw-field">
							<label><?php echo esc_html( o3dai_t( 'provider_text' ) ); ?></label>
							<select name="provider" id="o3dw-provider">
								<option value="claude" <?php selected( $s['provider'], 'claude' ); ?>>Anthropic Claude</option>
								<option value="openai" <?php selected( $s['provider'], 'openai' ); ?>>OpenAI</option>
								<option value="gemini" <?php selected( $s['provider'], 'gemini' ); ?>>Google Gemini</option>
								<option value="openrouter" <?php selected( $s['provider'], 'openrouter' ); ?>>OpenRouter (200+ modelov, 1 kľúč)</option>
							</select>
						</div>
						<div class="o3dw-field">
							<label><?php echo esc_html( o3dai_t( 'model' ) ); ?></label>
							<select name="model" id="o3dw-model"></select>
						</div>
					</div>
					<div class="o3dw-field">
						<label><?php echo esc_html( o3dai_t( 'key_claude' ) ); ?></label>
						<input type="password" name="api_key_claude" value="<?php echo esc_attr( $v( 'api_key_claude' ) ); ?>">
					</div>
					<div class="o3dw-row">
						<div class="o3dw-field">
							<label><?php echo esc_html( o3dai_t( 'key_openai' ) ); ?></label>
							<input type="password" name="api_key_openai" value="<?php echo esc_attr( $v( 'api_key_openai' ) ); ?>">
						</div>
						<div class="o3dw-field">
							<label><?php echo esc_html( o3dai_t( 'key_gemini' ) ); ?></label>
							<input type="password" name="api_key_gemini" value="<?php echo esc_attr( $v( 'api_key_gemini' ) ); ?>">
						</div>
						<div class="o3dw-field">
							<label><?php echo esc_html( o3dai_t( 'key_openrouter' ) ); ?></label>
							<input type="password" name="api_key_openrouter" value="<?php echo esc_attr( $v( 'api_key_openrouter' ) ); ?>">
						</div>
					</div>
				</div>

				<div class="o3dw-card">
					<h2><?php echo esc_html( o3dai_t( 'w_s4' ) ); ?></h2>
					<div class="o3dw-field">
						<label><?php echo esc_html( o3dai_t( 'image_style_l' ) ); ?></label>
						<textarea name="image_style" rows="3" placeholder="<?php echo esc_attr( o3dai_t( 'w_ph_style' ) ); ?>"><?php echo esc_textarea( $v( 'image_style' ) ); ?></textarea>
						<div class="hint"><?php echo esc_html( o3dai_t( 'w_style_hint' ) ); ?></div>
					</div>
					<div class="o3dw-row">
						<div class="o3dw-field">
							<label><?php echo esc_html( o3dai_t( 'image_subject_hint_l' ) ); ?></label>
							<input type="text" name="image_subject_hint" value="<?php echo esc_attr( $v( 'image_subject_hint' ) ); ?>" placeholder="e.g. friendly cartoon characters">
						</div>
					</div>
					<div class="o3dw-field">
						<label><?php echo esc_html( o3dai_t( 'image_subjects_l' ) ); ?></label>
						<textarea name="image_subjects" rows="3" placeholder="<?php echo esc_attr( o3dai_t( 'w_ph_subjects' ) ); ?>"><?php echo esc_textarea( $v( 'image_subjects' ) ); ?></textarea>
						<div class="hint"><?php echo esc_html( o3dai_t( 'w_subjects_hint' ) ); ?></div>
					</div>
				</div>

				<div class="o3dw-card">
					<h2><?php echo esc_html( o3dai_t( 'w_s5' ) ); ?></h2>
					<p class="sub"><?php echo esc_html( o3dai_t( 'w_colors_hint' ) ); ?></p>
					<div class="o3dw-colors">
						<label><?php echo esc_html( o3dai_t( 'color_primary' ) ); ?><br><input type="color" name="color_primary" value="<?php echo esc_attr( $cv( 'color_primary' ) ); ?>"></label>
						<label><?php echo esc_html( o3dai_t( 'color_accent' ) ); ?><br><input type="color" name="color_accent" value="<?php echo esc_attr( $cv( 'color_accent' ) ); ?>"></label>
						<label><?php echo esc_html( o3dai_t( 'color_accent2' ) ); ?><br><input type="color" name="color_accent2" value="<?php echo esc_attr( $cv( 'color_accent2' ) ); ?>"></label>
						<label><?php echo esc_html( o3dai_t( 'color_dark' ) ); ?><br><input type="color" name="color_dark" value="<?php echo esc_attr( $cv( 'color_dark' ) ); ?>"></label>
					</div>
				</div>

				<p style="text-align:center;margin:24px 0">
					<button type="submit" class="o3dw-submit"><?php echo esc_html( o3dai_t( 'w_finish' ) ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}

O3DAI_Wizard::init();
