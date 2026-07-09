<?php
/**
 * Delivery Log admin page: a read-only view of every message the plugin has
 * sent or received, with its delivery status and — crucially — the decoded
 * reason when something failed. This is the transparency wedge made visible.
 * Reads via the Ledger; renders an escaped table with simple filters + paging.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivery-log submenu page.
 */
class DukaRelay_Log_Page {

	const PAGE_SLUG = 'dukarelay-log';
	const PER_PAGE  = 30;

	/**
	 * Message ledger.
	 *
	 * @var DukaRelay_Ledger
	 */
	private $ledger;

	/**
	 * Constructor.
	 *
	 * @param DukaRelay_Ledger $ledger Ledger service.
	 */
	public function __construct( DukaRelay_Ledger $ledger ) {
		$this->ledger = $ledger;
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
	}

	/**
	 * Register the Delivery Log submenu under the DukaRelay menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'dukarelay',
			__( 'Delivery Log', 'dukarelay' ),
			__( 'Delivery Log', 'dukarelay' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the delivery-log table.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Read-only filters from the query string (no state change → no nonce).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['dr_status'] ) ? sanitize_key( wp_unslash( $_GET['dr_status'] ) ) : '';
		$dir    = isset( $_GET['dr_dir'] ) ? sanitize_key( wp_unslash( $_GET['dr_dir'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array(
			'limit'  => self::PER_PAGE,
			'offset' => ( $paged - 1 ) * self::PER_PAGE,
		);
		if ( '' !== $status ) {
			$args['status'] = $status;
		}
		if ( 'in' === $dir || 'out' === $dir ) {
			$args['direction'] = $dir;
		}

		$rows          = $this->ledger->query_messages( $args );
		$statuses      = array( 'queued', 'sent', 'delivered', 'read', 'failed', 'received' );
		$base_page_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DukaRelay — Delivery Log', 'dukarelay' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Every message, its delivery status, and the reason if it failed. Nothing fails silently.', 'dukarelay' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<label for="dr_dir"><?php esc_html_e( 'Direction:', 'dukarelay' ); ?></label>
				<select name="dr_dir" id="dr_dir">
					<option value=""><?php esc_html_e( 'All', 'dukarelay' ); ?></option>
					<option value="out" <?php selected( 'out', $dir ); ?>><?php esc_html_e( 'Outgoing', 'dukarelay' ); ?></option>
					<option value="in" <?php selected( 'in', $dir ); ?>><?php esc_html_e( 'Incoming', 'dukarelay' ); ?></option>
				</select>
				<label for="dr_status"><?php esc_html_e( 'Status:', 'dukarelay' ); ?></label>
				<select name="dr_status" id="dr_status">
					<option value=""><?php esc_html_e( 'All', 'dukarelay' ); ?></option>
					<?php foreach ( $statuses as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $s, $status ); ?>><?php echo esc_html( $s ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Filter', 'dukarelay' ), 'secondary', 'filter', false ); ?>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time (UTC)', 'dukarelay' ); ?></th>
						<th><?php esc_html_e( 'Dir', 'dukarelay' ); ?></th>
						<th><?php esc_html_e( 'Kind', 'dukarelay' ); ?></th>
						<th><?php esc_html_e( 'Number', 'dukarelay' ); ?></th>
						<th><?php esc_html_e( 'Status', 'dukarelay' ); ?></th>
						<th><?php esc_html_e( 'Detail', 'dukarelay' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No messages yet.', 'dukarelay' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$is_failed = isset( $row['status'] ) && 'failed' === $row['status'];
							$detail    = ! empty( $row['error'] ) ? $row['error'] : ( isset( $row['body'] ) ? $row['body'] : '' );
							?>
							<tr>
								<td><?php echo esc_html( isset( $row['created_at'] ) ? $row['created_at'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $row['direction'] ) ? $row['direction'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $row['kind'] ) ? $row['kind'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $row['peer_number'] ) ? $row['peer_number'] : '' ); ?></td>
								<td>
									<?php if ( $is_failed ) : ?>
										<span style="color:#b32d2e;font-weight:600;"><?php echo esc_html( $row['status'] ); ?></span>
									<?php else : ?>
										<?php echo esc_html( isset( $row['status'] ) ? $row['status'] : '' ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $detail ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php
			$filter_args = array();
			if ( '' !== $status ) {
				$filter_args['dr_status'] = $status;
			}
			if ( '' !== $dir ) {
				$filter_args['dr_dir'] = $dir;
			}
			?>
			<p>
				<?php if ( $paged > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $filter_args, array( 'paged' => $paged - 1 ) ), $base_page_url ) ); ?>">&laquo; <?php esc_html_e( 'Newer', 'dukarelay' ); ?></a>
				<?php endif; ?>
				<?php if ( count( $rows ) === self::PER_PAGE ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $filter_args, array( 'paged' => $paged + 1 ) ), $base_page_url ) ); ?>"><?php esc_html_e( 'Older', 'dukarelay' ); ?> &raquo;</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
