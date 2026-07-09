<?php
/**
 * Wizard: a resumable, step-by-step setup flow that walks a Store Owner through
 * connecting WhatsApp. Each step validates before advancing (per the decided
 * onboarding design). Reuses the same Connection/Settings/Templates services as
 * the settings screen; it just presents them one step at a time with progress.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guided setup wizard.
 */
class DukaRelay_Wizard {

	const PAGE_SLUG  = 'dukarelay-setup';
	const OPTION_KEY = 'dukarelay_setup';
	const STEPS      = 5;

	/**
	 * Connection service.
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
	 * Constructor.
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
		add_action( 'admin_post_dukarelay_wizard', array( $this, 'handle_post' ) );
		add_action( 'admin_notices', array( $this, 'first_run_notice' ) );
	}

	/**
	 * Register the (hidden) wizard submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'dukarelay',
			__( 'Setup', 'dukarelay' ),
			__( 'Setup', 'dukarelay' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Current wizard state.
	 *
	 * @return array{step:int,complete:bool}
	 */
	private function state() {
		$s = get_option( self::OPTION_KEY, array() );
		return array(
			'step'     => isset( $s['step'] ) ? max( 1, min( self::STEPS, (int) $s['step'] ) ) : 1,
			'complete' => ! empty( $s['complete'] ),
		);
	}

	/**
	 * Persist wizard state.
	 *
	 * @param int  $step     Step number.
	 * @param bool $complete Whether setup is finished.
	 * @return void
	 */
	private function set_state( $step, $complete = false ) {
		update_option(
			self::OPTION_KEY,
			array(
				'step'     => (int) $step,
				'complete' => (bool) $complete,
			),
			false
		);
	}

	/**
	 * Show a one-time prompt to finish setup, until it's complete.
	 *
	 * @return void
	 */
	public function first_run_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = $this->state();
		if ( $state['complete'] ) {
			return;
		}
		// Don't nag on the wizard page itself.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check.
		if ( self::PAGE_SLUG === $page ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s" class="button button-primary">%3$s</a></p></div>',
			esc_html__( 'DukaRelay needs a quick setup to start sending WhatsApp notifications.', 'dukarelay' ),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Finish setup', 'dukarelay' )
		);
	}

	/**
	 * Render the current step.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state      = $this->state();
		$step       = $state['step'];
		$action_url = admin_url( 'admin-post.php' );
		$flash      = get_transient( 'dukarelay_wiz_flash_' . get_current_user_id() );
		if ( $flash ) {
			delete_transient( 'dukarelay_wiz_flash_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DukaRelay Setup', 'dukarelay' ); ?></h1>
			<p class="description">
				<?php
				/* translators: 1: current step, 2: total steps. */
				echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'dukarelay' ), $step, self::STEPS ) );
				?>
			</p>
			<?php if ( is_array( $flash ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'error' === $flash['type'] ? 'error' : 'success' ); ?>"><p><?php echo esc_html( $flash['message'] ); ?></p></div>
			<?php endif; ?>

			<?php
			switch ( $step ) {
				case 1:
					$this->step_requirements( $action_url );
					break;
				case 2:
					$this->step_credentials( $action_url );
					break;
				case 3:
					$this->step_webhook( $action_url );
					break;
				case 4:
					$this->step_preferences( $action_url );
					break;
				default:
					$this->step_done();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Step 1: requirements.
	 *
	 * @param string $action_url admin-post URL.
	 * @return void
	 */
	private function step_requirements( $action_url ) {
		require_once DUKARELAY_PLUGIN_DIR . 'includes/class-dukarelay-requirements.php';
		$unmet = DukaRelay_Requirements::unmet();
		?>
		<h2><?php esc_html_e( 'Server check', 'dukarelay' ); ?></h2>
		<?php if ( empty( $unmet ) ) : ?>
			<p><?php esc_html_e( 'Your server meets all requirements.', 'dukarelay' ); ?></p>
			<?php $this->next_form( $action_url, 1, 'continue', __( 'Continue', 'dukarelay' ) ); ?>
		<?php else : ?>
			<div class="notice notice-error"><ul>
				<?php foreach ( $unmet as $item ) : ?>
					<li><?php echo esc_html( $item ); ?></li>
				<?php endforeach; ?>
			</ul></div>
			<p><?php esc_html_e( 'Please resolve these with your host before continuing.', 'dukarelay' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Step 2: credentials + validate.
	 *
	 * @param string $action_url admin-post URL.
	 * @return void
	 */
	private function step_credentials( $action_url ) {
		$creds = $this->connection->get_credentials();
		?>
		<h2><?php esc_html_e( 'Connect WhatsApp', 'dukarelay' ); ?></h2>
		<p><?php esc_html_e( 'Enter the details from your Meta WhatsApp app. We check them before moving on.', 'dukarelay' ); ?></p>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<input type="hidden" name="action" value="dukarelay_wizard" />
			<input type="hidden" name="wstep" value="2" />
			<input type="hidden" name="waction" value="save" />
			<?php wp_nonce_field( 'dukarelay_wizard', 'dukarelay_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Phone Number ID', 'dukarelay' ); ?></th><td><input name="phone_number_id" type="text" class="regular-text" value="<?php echo esc_attr( $creds['phone_number_id'] ); ?>" /></td></tr>
				<tr><th><?php esc_html_e( 'WhatsApp Business Account ID', 'dukarelay' ); ?></th><td><input name="waba_id" type="text" class="regular-text" value="<?php echo esc_attr( $creds['waba_id'] ); ?>" /></td></tr>
				<tr><th><?php esc_html_e( 'Access Token', 'dukarelay' ); ?></th><td><input name="access_token" type="password" class="regular-text" autocomplete="off" placeholder="<?php echo '' !== $creds['access_token'] ? esc_attr( $this->connection->get_masked( 'access_token' ) ) : ''; ?>" /></td></tr>
				<tr><th><?php esc_html_e( 'App Secret', 'dukarelay' ); ?></th><td><input name="app_secret" type="password" class="regular-text" autocomplete="off" placeholder="<?php echo '' !== $creds['app_secret'] ? esc_attr( $this->connection->get_masked( 'app_secret' ) ) : ''; ?>" /></td></tr>
			</table>
			<?php submit_button( __( 'Validate & continue', 'dukarelay' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Step 3: webhook instructions.
	 *
	 * @param string $action_url admin-post URL.
	 * @return void
	 */
	private function step_webhook( $action_url ) {
		$creds = $this->connection->get_credentials();
		?>
		<h2><?php esc_html_e( 'Set up the webhook', 'dukarelay' ); ?></h2>
		<p><?php esc_html_e( 'In the Meta dashboard, add this Callback URL and Verify token to your WhatsApp webhook, and subscribe to the "messages" field.', 'dukarelay' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Callback URL', 'dukarelay' ); ?></th><td><code><?php echo esc_html( rest_url( 'dukarelay/v1/webhook' ) ); ?></code></td></tr>
			<tr><th><?php esc_html_e( 'Verify token', 'dukarelay' ); ?></th><td><code><?php echo esc_html( $creds['verify_token'] ); ?></code></td></tr>
		</table>
		<?php $this->next_form( $action_url, 3, 'done', __( 'I have added the webhook — continue', 'dukarelay' ) ); ?>
		<?php
	}

	/**
	 * Step 4: preferences + template sync.
	 *
	 * @param string $action_url admin-post URL.
	 * @return void
	 */
	private function step_preferences( $action_url ) {
		?>
		<h2><?php esc_html_e( 'Preferences', 'dukarelay' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<input type="hidden" name="action" value="dukarelay_wizard" />
			<input type="hidden" name="wstep" value="4" />
			<input type="hidden" name="waction" value="save" />
			<?php wp_nonce_field( 'dukarelay_wizard', 'dukarelay_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Primary Number', 'dukarelay' ); ?></th><td><input name="primary_number" type="text" class="regular-text" value="<?php echo esc_attr( $this->settings->get_primary_number() ); ?>" placeholder="+263..." /></td></tr>
				<tr><th><?php esc_html_e( 'Forward inbound to Primary', 'dukarelay' ); ?></th><td><label><input type="checkbox" name="forward_enabled" value="1" <?php checked( (bool) $this->settings->get( 'forward_enabled' ) ); ?> /> <?php esc_html_e( 'Enabled', 'dukarelay' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Auto-reply to customers', 'dukarelay' ); ?></th><td><label><input type="checkbox" name="auto_reply_enabled" value="1" <?php checked( (bool) $this->settings->get( 'auto_reply_enabled' ) ); ?> /> <?php esc_html_e( 'Enabled', 'dukarelay' ); ?></label></td></tr>
			</table>
			<?php submit_button( __( 'Save & finish', 'dukarelay' ) ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top:1em">
			<input type="hidden" name="action" value="dukarelay_wizard" />
			<input type="hidden" name="wstep" value="4" />
			<input type="hidden" name="waction" value="sync" />
			<?php wp_nonce_field( 'dukarelay_wizard', 'dukarelay_nonce' ); ?>
			<?php submit_button( __( 'Sync approved templates from Meta (optional)', 'dukarelay' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Step 5: done.
	 *
	 * @return void
	 */
	private function step_done() {
		?>
		<h2><?php esc_html_e( 'Setup complete', 'dukarelay' ); ?></h2>
		<p><?php esc_html_e( 'DukaRelay is ready. Configure order notifications in settings, and watch messages in the delivery log.', 'dukarelay' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=dukarelay' ) ); ?>"><?php esc_html_e( 'Settings', 'dukarelay' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=dukarelay-log' ) ); ?>"><?php esc_html_e( 'Delivery Log', 'dukarelay' ); ?></a>
		</p>
		<?php
	}

	/**
	 * A small single-button "advance" form.
	 *
	 * @param string $action_url admin-post URL.
	 * @param int    $step       Current step.
	 * @param string $waction    Action keyword.
	 * @param string $label      Button label.
	 * @return void
	 */
	private function next_form( $action_url, $step, $waction, $label ) {
		?>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<input type="hidden" name="action" value="dukarelay_wizard" />
			<input type="hidden" name="wstep" value="<?php echo esc_attr( (string) $step ); ?>" />
			<input type="hidden" name="waction" value="<?php echo esc_attr( $waction ); ?>" />
			<?php wp_nonce_field( 'dukarelay_wizard', 'dukarelay_nonce' ); ?>
			<?php submit_button( $label ); ?>
		</form>
		<?php
	}

	/**
	 * Handle a wizard step submission.
	 *
	 * @return void
	 */
	public function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'dukarelay' ) );
		}
		check_admin_referer( 'dukarelay_wizard', 'dukarelay_nonce' );

		$wstep   = isset( $_POST['wstep'] ) ? absint( wp_unslash( $_POST['wstep'] ) ) : 1;
		$waction = isset( $_POST['waction'] ) ? sanitize_key( wp_unslash( $_POST['waction'] ) ) : '';

		if ( 1 === $wstep && 'continue' === $waction ) {
			require_once DUKARELAY_PLUGIN_DIR . 'includes/class-dukarelay-requirements.php';
			if ( DukaRelay_Requirements::met() ) {
				$this->set_state( 2 );
			}
		} elseif ( 2 === $wstep && 'save' === $waction ) {
			$this->save_credentials_from_post();
			$result = $this->token_health->run_check();
			if ( ! empty( $result['ok'] ) ) {
				$this->set_state( 3 );
			} else {
				$this->flash( 'error', __( 'Could not connect: ', 'dukarelay' ) . ( isset( $result['reason'] ) ? (string) $result['reason'] : '' ) );
			}
		} elseif ( 3 === $wstep && 'done' === $waction ) {
			$this->set_state( 4 );
		} elseif ( 4 === $wstep && 'save' === $waction ) {
			$this->settings->update(
				array(
					'primary_number'     => isset( $_POST['primary_number'] ) ? sanitize_text_field( wp_unslash( $_POST['primary_number'] ) ) : '',
					'forward_enabled'    => ! empty( $_POST['forward_enabled'] ),
					'auto_reply_enabled' => ! empty( $_POST['auto_reply_enabled'] ),
				)
			);
			$this->set_state( 5, true );
		} elseif ( 4 === $wstep && 'sync' === $waction ) {
			$result = $this->templates->sync_from_meta();
			if ( ! empty( $result['ok'] ) ) {
				/* translators: %d: number synced. */
				$this->flash( 'success', sprintf( __( 'Synced %d templates.', 'dukarelay' ), (int) $result['count'] ) );
			} else {
				$this->flash( 'error', isset( $result['error'] ) ? (string) $result['error'] : '' );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Save the credential fields present in the POST (merges with stored).
	 *
	 * @return void
	 */
	private function save_credentials_from_post() {
		$creds = array();
		foreach ( array( 'phone_number_id', 'waba_id', 'access_token', 'app_secret' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in handle_post().
				$creds[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in handle_post().
			}
		}
		if ( '' === $this->connection->get( 'verify_token' ) ) {
			$creds['verify_token'] = wp_generate_password( 24, false );
		}
		$this->connection->save_credentials( $creds );
	}

	/**
	 * Store a one-time wizard notice.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 * @return void
	 */
	private function flash( $type, $message ) {
		set_transient(
			'dukarelay_wiz_flash_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			30
		);
	}
}
