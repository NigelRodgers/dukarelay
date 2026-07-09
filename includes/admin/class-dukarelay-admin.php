<?php
/**
 * Admin: the settings/connection screen. Lets the owner paste WhatsApp Cloud API
 * credentials, set the Primary Number and forward/auto-reply preferences, test
 * the connection, and sync approved templates. First HTML-rendering surface —
 * every output is escaped, every write is nonce- and capability-guarded.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings/connection admin page + actions.
 */
class DukaRelay_Admin {

	const PAGE_SLUG = 'dukarelay';

	/**
	 * Services.
	 *
	 * @var DukaRelay_Connection
	 */
	private $connection;

	/**
	 * Settings service.
	 *
	 * @var DukaRelay_Settings
	 */
	private $settings;

	/**
	 * Token-health service.
	 *
	 * @var DukaRelay_Token_Health
	 */
	private $token_health;

	/**
	 * Templates service.
	 *
	 * @var DukaRelay_Templates
	 */
	private $templates;

	/**
	 * Constructor. Registers the menu, form handlers, and notices.
	 *
	 * @param DukaRelay_Connection   $connection   Connection service.
	 * @param DukaRelay_Settings     $settings     Settings service.
	 * @param DukaRelay_Token_Health $token_health Token-health service.
	 * @param DukaRelay_Templates    $templates    Templates service.
	 */
	public function __construct( $connection, $settings, $token_health, $templates ) {
		$this->connection   = $connection;
		$this->settings     = $settings;
		$this->token_health = $token_health;
		$this->templates    = $templates;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_dukarelay_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_dukarelay_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_dukarelay_sync', array( $this, 'handle_sync' ) );
		add_action( 'admin_notices', array( $this, 'render_flash' ) );
	}

	/**
	 * Register the top-level menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'DukaRelay', 'dukarelay' ),
			__( 'DukaRelay', 'dukarelay' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			58
		);
	}

	/**
	 * Render the settings/connection page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$creds       = $this->connection->get_credentials();
		$health      = $this->token_health->get_health();
		$webhook_url = rest_url( 'dukarelay/v1/webhook' );
		$reqs        = $this->requirements();
		$action_url  = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DukaRelay — WhatsApp Connection', 'dukarelay' ); ?></h1>

			<?php foreach ( $reqs as $req ) : ?>
				<?php if ( ! $req['ok'] ) : ?>
					<div class="notice notice-error"><p><strong><?php esc_html_e( 'Requirement not met:', 'dukarelay' ); ?></strong> <?php echo esc_html( $req['label'] ); ?></p></div>
				<?php endif; ?>
			<?php endforeach; ?>

			<div class="notice notice-<?php echo $health['ok'] ? 'success' : 'warning'; ?> inline">
				<p>
					<strong><?php esc_html_e( 'Connection status:', 'dukarelay' ); ?></strong>
					<?php echo $health['ok'] ? esc_html__( 'Connected', 'dukarelay' ) : esc_html__( 'Not connected / needs attention', 'dukarelay' ); ?>
					<?php if ( '' !== $health['reason'] ) : ?>
						— <?php echo esc_html( $health['reason'] ); ?>
					<?php endif; ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'Webhook (paste into the Meta dashboard)', 'dukarelay' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Callback URL', 'dukarelay' ); ?></th>
					<td><code><?php echo esc_html( $webhook_url ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Verify token', 'dukarelay' ); ?></th>
					<td><code><?php echo esc_html( $creds['verify_token'] ); ?></code>
						<?php if ( '' === $creds['verify_token'] ) : ?>
							<em><?php esc_html_e( '(one will be generated when you save)', 'dukarelay' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<input type="hidden" name="action" value="dukarelay_save" />
				<?php wp_nonce_field( 'dukarelay_save', 'dukarelay_nonce' ); ?>

				<h2><?php esc_html_e( 'Credentials', 'dukarelay' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="dr_pnid"><?php esc_html_e( 'Phone Number ID (Store Number)', 'dukarelay' ); ?></label></th>
						<td><input name="phone_number_id" id="dr_pnid" type="text" class="regular-text" value="<?php echo esc_attr( $creds['phone_number_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="dr_waba"><?php esc_html_e( 'WhatsApp Business Account ID', 'dukarelay' ); ?></label></th>
						<td><input name="waba_id" id="dr_waba" type="text" class="regular-text" value="<?php echo esc_attr( $creds['waba_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="dr_token"><?php esc_html_e( 'Access Token (System User)', 'dukarelay' ); ?></label></th>
						<td>
							<input name="access_token" id="dr_token" type="password" class="regular-text" placeholder="<?php echo '' !== $creds['access_token'] ? esc_attr( $this->connection->get_masked( 'access_token' ) ) : ''; ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Leave blank to keep the saved token.', 'dukarelay' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="dr_secret"><?php esc_html_e( 'App Secret', 'dukarelay' ); ?></label></th>
						<td>
							<input name="app_secret" id="dr_secret" type="password" class="regular-text" placeholder="<?php echo '' !== $creds['app_secret'] ? esc_attr( $this->connection->get_masked( 'app_secret' ) ) : ''; ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Leave blank to keep the saved secret. Used to verify incoming webhooks.', 'dukarelay' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Relay & replies', 'dukarelay' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="dr_primary"><?php esc_html_e( 'Primary Number (your existing WhatsApp)', 'dukarelay' ); ?></label></th>
						<td><input name="primary_number" id="dr_primary" type="text" class="regular-text" value="<?php echo esc_attr( $this->settings->get_primary_number() ); ?>" placeholder="+263..." />
							<p class="description"><?php esc_html_e( 'Inbound customer messages are forwarded here. Never register this number itself.', 'dukarelay' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Forward inbound to Primary', 'dukarelay' ); ?></th>
						<td><label><input type="checkbox" name="forward_enabled" value="1" <?php checked( (bool) $this->settings->get( 'forward_enabled' ) ); ?> /> <?php esc_html_e( 'Enabled', 'dukarelay' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Auto-reply to customers', 'dukarelay' ); ?></th>
						<td><label><input type="checkbox" name="auto_reply_enabled" value="1" <?php checked( (bool) $this->settings->get( 'auto_reply_enabled' ) ); ?> /> <?php esc_html_e( 'Enabled (once per 24h per customer)', 'dukarelay' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="dr_artext"><?php esc_html_e( 'Auto-reply text', 'dukarelay' ); ?></label></th>
						<td><textarea name="auto_reply_text" id="dr_artext" rows="3" class="large-text"><?php echo esc_textarea( (string) $this->settings->get( 'auto_reply_text' ) ); ?></textarea></td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'dukarelay' ) ); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Actions', 'dukarelay' ); ?></h2>
			<p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline">
					<input type="hidden" name="action" value="dukarelay_test" />
					<?php wp_nonce_field( 'dukarelay_test', 'dukarelay_nonce' ); ?>
					<?php submit_button( __( 'Test connection', 'dukarelay' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline">
					<input type="hidden" name="action" value="dukarelay_sync" />
					<?php wp_nonce_field( 'dukarelay_sync', 'dukarelay_nonce' ); ?>
					<?php submit_button( __( 'Sync templates from Meta', 'dukarelay' ), 'secondary', 'submit', false ); ?>
				</form>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the settings form submission.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'dukarelay' ) );
		}
		check_admin_referer( 'dukarelay_save', 'dukarelay_nonce' );

		$cred_fields = array( 'phone_number_id', 'waba_id', 'access_token', 'app_secret', 'verify_token' );
		$creds       = array();
		foreach ( $cred_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$creds[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			}
		}
		// Generate a verify token on first save if none was provided/stored.
		if ( empty( $creds['verify_token'] ) && '' === $this->connection->get( 'verify_token' ) ) {
			$creds['verify_token'] = wp_generate_password( 24, false );
		}
		$this->connection->save_credentials( $creds );

		$this->settings->update(
			array(
				'primary_number'     => isset( $_POST['primary_number'] ) ? sanitize_text_field( wp_unslash( $_POST['primary_number'] ) ) : '',
				'forward_enabled'    => ! empty( $_POST['forward_enabled'] ),
				'auto_reply_enabled' => ! empty( $_POST['auto_reply_enabled'] ),
				'auto_reply_text'    => isset( $_POST['auto_reply_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['auto_reply_text'] ) ) : '',
			)
		);

		$this->flash( 'success', __( 'Settings saved.', 'dukarelay' ) );
		$this->redirect_back();
	}

	/**
	 * Handle the "test connection" action.
	 *
	 * @return void
	 */
	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'dukarelay' ) );
		}
		check_admin_referer( 'dukarelay_test', 'dukarelay_nonce' );
		$result = $this->token_health->run_check();
		if ( ! empty( $result['ok'] ) ) {
			$this->flash( 'success', __( 'Connection is working.', 'dukarelay' ) );
		} else {
			$reason = isset( $result['reason'] ) ? (string) $result['reason'] : '';
			$this->flash( 'error', __( 'Connection failed: ', 'dukarelay' ) . $reason );
		}
		$this->redirect_back();
	}

	/**
	 * Handle the "sync templates" action.
	 *
	 * @return void
	 */
	public function handle_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'dukarelay' ) );
		}
		check_admin_referer( 'dukarelay_sync', 'dukarelay_nonce' );
		$result = $this->templates->sync_from_meta();
		if ( ! empty( $result['ok'] ) ) {
			/* translators: %d: number of templates synced. */
			$this->flash( 'success', sprintf( __( 'Synced %d templates from Meta.', 'dukarelay' ), (int) $result['count'] ) );
		} else {
			$this->flash( 'error', __( 'Template sync failed: ', 'dukarelay' ) . ( isset( $result['error'] ) ? (string) $result['error'] : '' ) );
		}
		$this->redirect_back();
	}

	/**
	 * Store a one-time admin notice.
	 *
	 * @param string $type success|error|warning.
	 * @param string $message Message text.
	 * @return void
	 */
	private function flash( $type, $message ) {
		set_transient(
			'dukarelay_flash_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			30
		);
	}

	/**
	 * Render (and clear) a stored admin notice on our page.
	 *
	 * @return void
	 */
	public function render_flash() {
		$flash = get_transient( 'dukarelay_flash_' . get_current_user_id() );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( 'dukarelay_flash_' . get_current_user_id() );
		$class = 'error' === $flash['type'] ? 'notice-error' : ( 'warning' === $flash['type'] ? 'notice-warning' : 'notice-success' );
		printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $flash['message'] ) );
	}

	/**
	 * Redirect back to the settings page.
	 *
	 * @return void
	 */
	private function redirect_back() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Server requirements (the compatibility gate surface).
	 *
	 * @return array[] Each: array('ok'=>bool,'label'=>string).
	 */
	private function requirements() {
		return array(
			array(
				'ok'    => version_compare( PHP_VERSION, '7.4', '>=' ),
				'label' => __( 'PHP 7.4 or newer is required.', 'dukarelay' ),
			),
			array(
				'ok'    => $this->connection->is_encryption_available(),
				'label' => __( 'OpenSSL is required to store credentials securely.', 'dukarelay' ),
			),
		);
	}
}
