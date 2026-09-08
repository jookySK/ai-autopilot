<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Sledovanie priebehu úloh na pozadí + AJAX polling pre progress bar.
 * Úloha si na začiatku zavolá O3DAI_Progress::start($key) a na konci ::done($key).
 * Front-end sa pýta cez AJAX, či je hotová, a keď áno, obnoví stránku.
 */
class O3DAI_Progress {

	public static function init() {
		add_action( 'wp_ajax_o3dai_progress', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_footer', array( __CLASS__, 'script' ) );
	}

	/** Označí úlohu ako bežiacu (key = napr. 'product_123') */
	public static function start( $key ) {
		$jobs = get_option( 'o3dai_jobs', array() );
		$jobs[ $key ] = array( 'status' => 'running', 'ts' => time() );
		update_option( 'o3dai_jobs', $jobs, false );
	}

	/** Označí úlohu ako hotovú */
	public static function done( $key, $ok = true ) {
		$jobs = get_option( 'o3dai_jobs', array() );
		$jobs[ $key ] = array( 'status' => $ok ? 'done' : 'error', 'ts' => time() );
		// starýšie ako hodina vyčisti
		foreach ( $jobs as $k => $j ) {
			if ( ( time() - intval( $j['ts'] ?? 0 ) ) > HOUR_IN_SECONDS ) {
				unset( $jobs[ $k ] );
			}
		}
		update_option( 'o3dai_jobs', $jobs, false );
	}

	public static function status( $key ) {
		$jobs = get_option( 'o3dai_jobs', array() );
		return $jobs[ $key ]['status'] ?? 'unknown';
	}

	/** AJAX: vráti stav zoznamu úloh */
	public static function ajax() {
		check_ajax_referer( 'o3dai_progress', 'nonce' );
		$keys = isset( $_GET['keys'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['keys'] ) ) : array();
		$out  = array();
		foreach ( $keys as $k ) {
			$out[ $k ] = self::status( $k );
		}
		wp_send_json_success( $out );
	}

	/** JS na admin stránkach: keď úloha dobehne, obnoví stránku */
	public static function script() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		// beží len tam, kde môžu byť naše úlohy (produkty, kategórie, editor, plugin)
		$allowed = array( 'edit-product', 'product', 'edit-product_cat', 'toplevel_page_o3dai', 'edit-post', 'post' );
		if ( ! in_array( $screen->id, $allowed, true ) ) {
			return;
		}
		$nonce   = wp_create_nonce( 'o3dai_progress' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- self-set watch flag from our own redirect; the AJAX endpoint verifies the 'o3dai_progress' nonce
		$pending = isset( $_GET['o3dai_watch'] ) ? sanitize_text_field( wp_unslash( $_GET['o3dai_watch'] ) ) : '';
		$s       = o3dai_get_settings();
		$mode    = $s['progress_mode'] ?? 'background';
		?>
		<?php $fg = ( 'foreground' === $mode ); ?>
		<div id="o3dai-progress-overlay" style="display:none;position:fixed;inset:0;z-index:99998;background:rgba(43,35,80,.45);backdrop-filter:blur(2px)"></div>
		<div id="o3dai-progress" style="display:none;<?php echo $fg
			? 'position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);z-index:99999;padding:26px 30px;border-radius:16px;min-width:340px;text-align:center;'
			: 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;padding:14px 20px;border-radius:12px;min-width:300px;'; ?>background:#2B2350;color:#fff;box-shadow:0 12px 40px rgba(0,0,0,.35);font-size:14px">
			<?php if ( $fg ) : ?><div style="font-size:34px;margin-bottom:12px">🤖</div><?php endif; ?>
			<div id="o3dai-progress-label" style="margin-bottom:10px;font-weight:600"><?php echo esc_html( $fg ? o3dai_t( 'pr_working' ) : o3dai_t( 'pr_working_bg' ) ); ?></div>
			<div style="height:<?php echo $fg ? '10' : '6'; ?>px;background:rgba(255,255,255,.18);border-radius:99px;overflow:hidden">
				<div id="o3dai-progress-bar" style="height:100%;width:8%;background:linear-gradient(90deg,#6C4AB6,#2BC6B4);border-radius:99px;transition:width .4s"></div>
			</div>
			<?php if ( $fg ) : ?><div style="margin-top:12px;font-size:12px;opacity:.7"><?php echo esc_html( o3dai_t( 'pr_dont_close' ) ); ?></div>
			<?php else : ?><div style="margin-top:8px;font-size:11px;opacity:.6"><?php echo esc_html( o3dai_t( 'pr_keep_working' ) ); ?></div><?php endif; ?>
		</div>

	<?php
		wp_register_script( 'o3dai-progress', plugins_url( '../assets/js/progress.js', __FILE__ ), array(), O3DAI_VERSION );
		wp_enqueue_script( 'o3dai-progress' );
		wp_add_inline_script(
			'o3dai-progress',
			'window.O3DAI_PROGRESS=' . wp_json_encode(
				array(
					'keys'  => $pending ? explode( ',', $pending ) : array(),
					'i18n'  => array(
						'progress'     => o3dai_t( 'pr_progress' ),
						'done_refresh' => o3dai_t( 'pr_done_refresh' ),
						'done_bg'      => o3dai_t( 'pr_done' ),
						'show'         => o3dai_t( 'pr_show' ),
					),
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => $nonce,
					'fg'    => (bool) $fg,
				)
			)
		);
		?>
		<?php
	}
}

O3DAI_Progress::init();
