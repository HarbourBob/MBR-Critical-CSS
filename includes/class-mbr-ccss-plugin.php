<?php
/**
 * Core plugin wiring: shortcode, assets, REST endpoint, rate limiting and settings.
 *
 * @package MBR_Critical_CSS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class MBR_CCSS_Plugin {

	const OPTION  = 'mbr_ccss_settings';
	const REST_NS = 'mbr-ccss/v1';

	/**
	 * Seconds after which a job slot is considered abandoned (a job is capped at 30s).
	 */
	const SLOT_TTL = 90;

	/**
	 * Singleton instance.
	 *
	 * @var MBR_CCSS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether the front-end config has already been printed.
	 *
	 * @var bool
	 */
	private $config_added = false;

	/**
	 * Get the singleton.
	 *
	 * @return MBR_CCSS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook everything up.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_shortcode( 'mbr_critical_css', array( $this, 'render_shortcode' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MBR_CCSS_FILE ), array( $this, 'action_links' ) );
		add_action( 'mbr_ccss_cleanup', array( __CLASS__, 'cleanup' ) );
		add_action( 'init', array( $this, 'schedule_cleanup' ) );
	}

	/**
	 * Make sure the hourly clean-up of expired rate-limit and job-slot rows is scheduled.
	 */
	public function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'mbr_ccss_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'mbr_ccss_cleanup' );
		}
	}

	/**
	 * Delete expired rate-limit counters and abandoned job slots.
	 */
	public static function cleanup() {
		global $wpdb;
		// Counter names embed the window start as a 10-digit timestamp, so they sort by age.
		$cutoff = sprintf( '%010d', time() - DAY_IN_SECONDS - HOUR_IN_SECONDS );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
				$wpdb->esc_like( 'mbr_ccss_rl_' ) . '%',
				'mbr_ccss_rl_' . $cutoff
			)
		);
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %s",
				$wpdb->esc_like( 'mbr_ccss_slot_' ) . '%',
				sprintf( '%010d', time() - self::SLOT_TTL )
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Settings helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'require_login' => 0,
			'rate_limit'    => 10,
			'rate_window'   => 10,
			'max_jobs'      => 3,
		);
	}

	/**
	 * Saved settings merged with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/* ------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------ */

	/**
	 * Register (but don't enqueue) assets. They load only where the shortcode is used.
	 */
	public function register_assets() {
		wp_register_style( 'mbr-ccss', MBR_CCSS_URL . 'assets/css/mbr-ccss.css', array(), MBR_CCSS_VERSION );
		wp_register_script( 'mbr-ccss', MBR_CCSS_URL . 'assets/js/mbr-ccss.js', array(), MBR_CCSS_VERSION, true );
	}

	/**
	 * Render the [mbr_critical_css] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'width'  => 1300,
				'height' => 900,
			),
			$atts,
			'mbr_critical_css'
		);

		$settings = self::settings();
		if ( $settings['require_login'] && ! is_user_logged_in() ) {
			return '<p class="mbr-ccss-notice">' . esc_html__( 'Please log in to use the Critical CSS Generator.', 'mbr-critical-css' ) . '</p>';
		}

		wp_enqueue_style( 'mbr-ccss' );
		wp_enqueue_script( 'mbr-ccss' );

		if ( ! $this->config_added ) {
			// Anonymous visitors get no nonce at all: none is needed, and a cached page can't
			// serve them a stale one. Logged-in users get a nonce plus a way to refresh it.
			$config = array(
				'endpoint' => esc_url_raw( rest_url( self::REST_NS . '/fetch' ) ),
			);
			if ( is_user_logged_in() ) {
				$config['nonce']        = wp_create_nonce( 'wp_rest' );
				$config['nonceRefresh'] = esc_url_raw( admin_url( 'admin-ajax.php?action=rest-nonce' ) );
			}
			wp_add_inline_script( 'mbr-ccss', 'window.mbrCcssConfig = ' . wp_json_encode( $config ) . ';', 'before' );
			$this->config_added = true;
		}

		$width  = min( 3840, max( 240, absint( $atts['width'] ) ) );
		$height = min( 2400, max( 240, absint( $atts['height'] ) ) );
		$id     = wp_unique_id( 'mbr-ccss-' );

		$presets = array(
			array( __( 'Mobile', 'mbr-critical-css' ), 375, 812 ),
			array( __( 'Tablet', 'mbr-critical-css' ), 768, 1024 ),
			array( __( 'Laptop', 'mbr-critical-css' ), 1366, 768 ),
			array( __( 'Desktop', 'mbr-critical-css' ), 1920, 1080 ),
		);

		ob_start();
		?>
		<div class="mbr-ccss" data-mbr-ccss>
			<form class="mbr-ccss__form" novalidate>
				<div class="mbr-ccss__field mbr-ccss__field--url">
					<label for="<?php echo esc_attr( $id ); ?>-url"><?php esc_html_e( 'Page URL', 'mbr-critical-css' ); ?></label>
					<input type="url" id="<?php echo esc_attr( $id ); ?>-url" name="url" placeholder="https://example.com/" inputmode="url" autocomplete="url" spellcheck="false" required>
				</div>

				<fieldset class="mbr-ccss__viewport">
					<legend><?php esc_html_e( 'Viewport', 'mbr-critical-css' ); ?></legend>
					<div class="mbr-ccss__dims">
						<div class="mbr-ccss__field">
							<label for="<?php echo esc_attr( $id ); ?>-w"><?php esc_html_e( 'Width (px)', 'mbr-critical-css' ); ?></label>
							<input type="number" id="<?php echo esc_attr( $id ); ?>-w" name="width" min="240" max="3840" step="1" value="<?php echo esc_attr( $width ); ?>" required>
						</div>
						<span class="mbr-ccss__times" aria-hidden="true">&times;</span>
						<div class="mbr-ccss__field">
							<label for="<?php echo esc_attr( $id ); ?>-h"><?php esc_html_e( 'Height (px)', 'mbr-critical-css' ); ?></label>
							<input type="number" id="<?php echo esc_attr( $id ); ?>-h" name="height" min="240" max="2400" step="1" value="<?php echo esc_attr( $height ); ?>" required>
						</div>
					</div>
					<div class="mbr-ccss__presets" role="group" aria-label="<?php esc_attr_e( 'Viewport presets', 'mbr-critical-css' ); ?>">
						<?php foreach ( $presets as $preset ) : ?>
							<button type="button" class="mbr-ccss__preset" data-w="<?php echo esc_attr( $preset[1] ); ?>" data-h="<?php echo esc_attr( $preset[2] ); ?>">
								<?php echo esc_html( $preset[0] ); ?> <span><?php echo esc_html( $preset[1] . '×' . $preset[2] ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<div class="mbr-ccss__options">
					<label><input type="checkbox" name="minify" checked> <?php esc_html_e( 'Minify output', 'mbr-critical-css' ); ?></label>
					<label><input type="checkbox" name="fonts" checked> <?php esc_html_e( 'Include @font-face rules for fonts in use', 'mbr-critical-css' ); ?></label>
				</div>

				<button type="submit" class="mbr-ccss__submit"><?php esc_html_e( 'Generate Critical CSS', 'mbr-critical-css' ); ?></button>
			</form>

			<p class="mbr-ccss__status" role="status" aria-live="polite"></p>

			<div class="mbr-ccss__result" hidden>
				<div class="mbr-ccss__output">
					<div class="mbr-ccss__output-head">
						<label for="<?php echo esc_attr( $id ); ?>-out"><?php esc_html_e( 'Critical CSS', 'mbr-critical-css' ); ?></label>
						<div class="mbr-ccss__actions">
							<button type="button" data-action="copy"><?php esc_html_e( 'Copy', 'mbr-critical-css' ); ?></button>
							<button type="button" data-action="download"><?php esc_html_e( 'Download .css', 'mbr-critical-css' ); ?></button>
						</div>
					</div>
					<textarea id="<?php echo esc_attr( $id ); ?>-out" readonly spellcheck="false" rows="12"></textarea>
					<p class="mbr-ccss__stats"></p>
				</div>

				<details class="mbr-ccss__notes" hidden>
					<summary><?php esc_html_e( 'Notes from this run', 'mbr-critical-css' ); ?></summary>
					<ul></ul>
				</details>

				<figure class="mbr-ccss__preview">
					<figcaption><?php esc_html_e( 'What the generator saw above the fold (scripts disabled)', 'mbr-critical-css' ); ?></figcaption>
					<div class="mbr-ccss__stage"></div>
				</figure>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * REST endpoint
	 * ------------------------------------------------------------------ */

	/**
	 * Register the fetch route.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/fetch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_fetch' ),
				'permission_callback' => array( $this, 'can_fetch' ),
				'args'                => array(
					'url' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission check: optional login requirement, plus a same-site Origin check
	 * so other websites can't use your server as their fetch proxy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_fetch( WP_REST_Request $request ) {
		$settings = self::settings();

		if ( $settings['require_login'] && ! is_user_logged_in() ) {
			return new WP_Error( 'mbr_ccss_login_required', __( 'Please log in to use the Critical CSS Generator.', 'mbr-critical-css' ), array( 'status' => 401 ) );
		}

		// Browsers always send Origin on a POST, so a request without one didn't come from
		// the generator page. This is a supplementary check: non-browser clients can forge
		// it, which is why the rate limit and job cap below do the real work.
		$origin = self::normalise_origin( (string) $request->get_header( 'origin' ) );
		if ( '' === $origin || ! in_array( $origin, self::allowed_origins(), true ) ) {
			return new WP_Error( 'mbr_ccss_bad_origin', __( 'Requests to this tool must come from this website.', 'mbr-critical-css' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * scheme://host[:port] with default ports dropped, or '' if invalid.
	 *
	 * @param string $url URL or origin.
	 * @return string
	 */
	private static function normalise_origin( $url ) {
		$p = wp_parse_url( trim( $url ) );
		if ( ! $p || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $p['scheme'] );
		$port   = isset( $p['port'] ) ? (int) $p['port'] : 0;
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = 0;
		}
		return $scheme . '://' . strtolower( $p['host'] ) . ( $port ? ':' . $port : '' );
	}

	/**
	 * Exact origins this site serves the generator from.
	 *
	 * @return string[]
	 */
	private static function allowed_origins() {
		$origins = array_filter(
			array(
				self::normalise_origin( home_url() ),
				self::normalise_origin( site_url() ),
			)
		);
		return array_values( array_unique( (array) apply_filters( 'mbr_ccss_allowed_origins', $origins ) ) );
	}

	/**
	 * Fetch a page and its stylesheets for the browser to render.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_fetch( WP_REST_Request $request ) {
		$limited = $this->check_rate_limit();
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$slot = $this->acquire_slot();
		if ( is_wp_error( $slot ) ) {
			return $slot;
		}

		try {
			$fetcher = new MBR_CCSS_Fetcher();
			$result  = $fetcher->fetch_page( (string) $request->get_param( 'url' ) );
		} finally {
			$this->release_slot( $slot );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * Per-IP rate limit. The counter is incremented atomically in the database
	 * (INSERT ... ON DUPLICATE KEY UPDATE), so simultaneous requests can't overwrite
	 * each other's counts. Administrators are exempt.
	 *
	 * @return true|WP_Error
	 */
	private function check_rate_limit() {
		global $wpdb;

		$settings = self::settings();
		$limit    = (int) $settings['rate_limit'];
		if ( $limit <= 0 || current_user_can( 'manage_options' ) ) {
			return true;
		}

		$window = max( 1, (int) $settings['rate_window'] ) * MINUTE_IN_SECONDS;
		$now    = time();
		$start  = $now - ( $now % $window );
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$name   = 'mbr_ccss_rl_' . sprintf( '%010d', $start ) . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );

		// The increment takes a row lock that is held until COMMIT, so the read inside the
		// same transaction sees exactly this request's count, never a neighbour's.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
				ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$name
			)
		);
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		$wpdb->query( 'COMMIT' );
		// phpcs:enable

		if ( 0 === wp_rand( 0, 49 ) ) {
			self::cleanup(); // Occasional tidy-up, in case WP-Cron rarely runs.
		}

		if ( $count > $limit ) {
			$minutes = max( 1, (int) ceil( ( $start + $window - $now ) / MINUTE_IN_SECONDS ) );
			return new WP_Error(
				'mbr_ccss_rate_limited',
				sprintf(
					/* translators: %d: minutes to wait */
					_n( 'You have reached the usage limit. Try again in %d minute.', 'You have reached the usage limit. Try again in %d minutes.', $minutes, 'mbr-critical-css' ),
					$minutes
				),
				array( 'status' => 429 )
			);
		}
		return true;
	}

	/**
	 * Claim one of the site-wide job slots, so only a few generations run at once
	 * whoever is asking. A slot is claimed with INSERT IGNORE, which only one request
	 * can win; slots left behind by a crashed request expire after SLOT_TTL seconds.
	 *
	 * @return array|WP_Error Slot handle.
	 */
	private function acquire_slot() {
		global $wpdb;

		$max   = max( 1, (int) self::settings()['max_jobs'] );
		$token = sprintf( '%010d', time() ) . ':' . wp_generate_password( 12, false );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		for ( $i = 0; $i < $max; $i++ ) {
			$name = 'mbr_ccss_slot_' . $i;
			for ( $try = 0; $try < 2; $try++ ) {
				$won = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
						$name,
						$token
					)
				);
				if ( 1 === (int) $won ) {
					return array(
						'name'  => $name,
						'token' => $token,
					);
				}
				// Occupied. If its holder is long gone, free it (only if unchanged) and try once more.
				$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
				if ( '' !== $held && (int) substr( $held, 0, 10 ) < time() - self::SLOT_TTL ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $held ) );
					continue;
				}
				break;
			}
		}
		// phpcs:enable

		$response = new WP_Error( 'mbr_ccss_busy', __( 'The generator is busy right now. Please try again in a few seconds.', 'mbr-critical-css' ), array( 'status' => 503 ) );
		return $response;
	}

	/**
	 * Release a job slot (only if we still hold it).
	 *
	 * @param array $slot Slot handle.
	 */
	private function release_slot( $slot ) {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $slot['name'], $slot['token'] )
		);
	}

	/* ------------------------------------------------------------------
	 * Admin settings
	 * ------------------------------------------------------------------ */

	/**
	 * Add the settings page under Settings.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'MBR Critical CSS Generator', 'mbr-critical-css' ),
			__( 'Critical CSS Generator', 'mbr-critical-css' ),
			'manage_options',
			'mbr-critical-css',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the option.
	 */
	public function register_settings() {
		register_setting(
			'mbr_ccss',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitise settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		return array(
			'require_login' => empty( $input['require_login'] ) ? 0 : 1,
			'rate_limit'    => min( 1000, absint( isset( $input['rate_limit'] ) ? $input['rate_limit'] : 10 ) ),
			'rate_window'   => min( 1440, max( 1, absint( isset( $input['rate_window'] ) ? $input['rate_window'] : 10 ) ) ),
			'max_jobs'      => min( 20, max( 1, absint( isset( $input['max_jobs'] ) ? $input['max_jobs'] : 3 ) ) ),
		);
	}

	/**
	 * Settings page markup.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MBR Critical CSS Generator', 'mbr-critical-css' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: shortcode */
					esc_html__( 'Add %s to any page or post to show the generator. Optional attributes set the starting viewport, for example width="375" height="812".', 'mbr-critical-css' ),
					'<code>[mbr_critical_css]</code>'
				);
				?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'mbr_ccss' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Access', 'mbr-critical-css' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[require_login]" value="1" <?php checked( (int) $s['require_login'], 1 ); ?>>
								<?php esc_html_e( 'Only logged-in users can use the generator', 'mbr-critical-css' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mbr-ccss-rate-limit"><?php esc_html_e( 'Rate limit', 'mbr-critical-css' ); ?></label></th>
						<td>
							<input type="number" id="mbr-ccss-rate-limit" class="small-text" min="0" max="1000" name="<?php echo esc_attr( self::OPTION ); ?>[rate_limit]" value="<?php echo esc_attr( $s['rate_limit'] ); ?>">
							<?php esc_html_e( 'requests every', 'mbr-critical-css' ); ?>
							<input type="number" class="small-text" min="1" max="1440" name="<?php echo esc_attr( self::OPTION ); ?>[rate_window]" value="<?php echo esc_attr( $s['rate_window'] ); ?>" aria-label="<?php esc_attr_e( 'Rate limit window in minutes', 'mbr-critical-css' ); ?>">
							<?php esc_html_e( 'minutes, per visitor IP address', 'mbr-critical-css' ); ?>
							<p class="description"><?php esc_html_e( 'Set the request count to 0 to turn the limit off. Administrators are never limited.', 'mbr-critical-css' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mbr-ccss-max-jobs"><?php esc_html_e( 'Simultaneous jobs', 'mbr-critical-css' ); ?></label></th>
						<td>
							<input type="number" id="mbr-ccss-max-jobs" class="small-text" min="1" max="20" name="<?php echo esc_attr( self::OPTION ); ?>[max_jobs]" value="<?php echo esc_attr( $s['max_jobs'] ); ?>">
							<p class="description"><?php esc_html_e( 'How many generations may run at the same time across all visitors. Each one can occupy a PHP worker for up to 30 seconds, so keep this well below your hosting plan\'s worker limit. Extra visitors are asked to try again in a few seconds.', 'mbr-critical-css' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=mbr-critical-css' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'mbr-critical-css' ) . '</a>' );
		return $links;
	}
}
