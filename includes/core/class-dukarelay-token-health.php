<?php
/**
 * Token-health: the reliability watchdog. A twice-daily cron heartbeat validates
 * the WhatsApp connection and, the moment it breaks, alerts the owner (email +
 * admin notice + best-effort WhatsApp) BEFORE a customer message fails. See
 * docs/dev/token-health.md.
 *
 * Alerting is transition-based (alarm once on break, once on recovery — no
 * nagging). Email is the reliable channel because a dead token can't send a
 * WhatsApp alert. Shop-blind.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduled connection-health monitor.
 */
class DukaRelay_Token_Health {

	const CRON_HOOK  = 'dukarelay_token_health_check';
	const HEALTH_KEY = 'dukarelay_health';

	/**
	 * Credential/connection validator.
	 *
	 * @var DukaRelay_Connection
	 */
	private $connection;

	/**
	 * Settings (for the Primary Number).
	 *
	 * @var DukaRelay_Settings
	 */
	private $settings;

	/**
	 * Dispatcher (best-effort WhatsApp alert).
	 *
	 * @var DukaRelay_Dispatcher
	 */
	private $dispatcher;

	/**
	 * Constructor. Registers the cron handler + admin notice and ensures the
	 * heartbeat is scheduled.
	 *
	 * @param DukaRelay_Connection $connection Connection service.
	 * @param DukaRelay_Settings   $settings   Settings service.
	 * @param DukaRelay_Dispatcher $dispatcher Dispatcher service.
	 */
	public function __construct( DukaRelay_Connection $connection, DukaRelay_Settings $settings, DukaRelay_Dispatcher $dispatcher ) {
		$this->connection = $connection;
		$this->settings   = $settings;
		$this->dispatcher = $dispatcher;

		add_action( self::CRON_HOOK, array( $this, 'run_check' ) );
		add_action( 'admin_notices', array( $this, 'maybe_admin_notice' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * The stored health state.
	 *
	 * @return array{ok:bool,reason:string,checked_at:string,configured:bool}
	 */
	public function get_health() {
		$stored = get_option( self::HEALTH_KEY, array() );
		return array(
			'ok'         => ! empty( $stored['ok'] ),
			'reason'     => isset( $stored['reason'] ) ? (string) $stored['reason'] : '',
			'checked_at' => isset( $stored['checked_at'] ) ? (string) $stored['checked_at'] : '',
			'configured' => ! empty( $stored['configured'] ),
		);
	}

	/**
	 * Run the scheduled health check (also callable directly, e.g. from the
	 * wizard's "test connection" button). Updates health state and, on a
	 * healthy<->unhealthy transition, alerts once.
	 *
	 * @return array The validation result (see DukaRelay_Connection::validate()).
	 */
	public function run_check() {
		$previous = get_option( self::HEALTH_KEY, array() );
		$was_ok   = ! empty( $previous['ok'] );
		$was_bad  = isset( $previous['ok'] ) && false === (bool) $previous['ok'];

		if ( ! $this->connection->is_configured() ) {
			update_option(
				self::HEALTH_KEY,
				array(
					'ok'         => false,
					'reason'     => __( 'Not connected yet.', 'dukarelay' ),
					'checked_at' => gmdate( 'Y-m-d H:i:s' ),
					'configured' => false,
					'alerted'    => ! empty( $previous['alerted'] ),
				),
				false
			);
			return array(
				'ok'     => false,
				'reason' => __( 'Not connected yet.', 'dukarelay' ),
			);
		}

		$result  = $this->connection->validate();
		$ok      = ! empty( $result['ok'] );
		$alerted = ! empty( $previous['alerted'] );

		if ( ! $ok && ! $alerted ) {
			$this->raise_alarm( isset( $result['reason'] ) ? (string) $result['reason'] : '' );
			$alerted = true;
		} elseif ( $ok && $was_bad ) {
			$this->clear_alarm();
			$alerted = false;
		}

		update_option(
			self::HEALTH_KEY,
			array(
				'ok'         => $ok,
				'reason'     => isset( $result['reason'] ) ? (string) $result['reason'] : '',
				'checked_at' => gmdate( 'Y-m-d H:i:s' ),
				'configured' => true,
				'alerted'    => $alerted,
			),
			false
		);

		unset( $was_ok );
		return $result;
	}

	/**
	 * Alert the owner that the connection has broken. Email is the reliable
	 * channel (a dead token can't send a WhatsApp alert); the WhatsApp heads-up
	 * to the Primary is best-effort (helps for near-expiry).
	 *
	 * @param string $reason Decoded failure reason.
	 * @return void
	 */
	private function raise_alarm( $reason ) {
		$site = get_bloginfo( 'name' );

		// Reliable, out-of-band: email the site admin.
		$admin_email = get_option( 'admin_email' );
		if ( $admin_email ) {
			/* translators: %s: site name. */
			$subject = sprintf( __( '[%s] WhatsApp connection needs attention', 'dukarelay' ), $site );
			$message = sprintf(
				/* translators: %s: failure reason. */
				__( "DukaRelay could not reach WhatsApp during a routine check.\n\nReason: %s\n\nCustomers may not be receiving notifications. Please reconnect from the DukaRelay settings.", 'dukarelay' ),
				$reason
			);
			wp_mail( $admin_email, $subject, $message );
		}

		// Best-effort WhatsApp heads-up to the Primary (works if the token is
		// merely near-expiry, not fully dead). Dispatcher logs failure honestly.
		$primary = $this->settings->get_primary_number();
		if ( '' !== $primary ) {
			$this->dispatcher->dispatch(
				array(
					'to'       => $primary,
					'type'     => 'text',
					'body'     => __( 'DukaRelay: your store\'s WhatsApp connection needs attention. Please reconnect from wp-admin.', 'dukarelay' ),
					'kind'     => 'notification',
					'category' => 'utility',
				)
			);
		}

		/**
		 * Fires when the connection transitions to unhealthy.
		 *
		 * @param string $reason Decoded failure reason.
		 */
		do_action( 'dukarelay_token_unhealthy', $reason );
	}

	/**
	 * Clear the alarm on recovery.
	 *
	 * @return void
	 */
	private function clear_alarm() {
		/**
		 * Fires when the connection recovers.
		 */
		do_action( 'dukarelay_token_recovered' );
	}

	/**
	 * Show an admin notice while the connection is unhealthy.
	 *
	 * @return void
	 */
	public function maybe_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$health = $this->get_health();
		if ( $health['ok'] || ! $health['configured'] ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'DukaRelay: WhatsApp connection problem.', 'dukarelay' ),
			esc_html( $health['reason'] )
		);
	}
}
