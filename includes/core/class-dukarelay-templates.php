<?php
/**
 * Templates: the catalog of Meta-approved WhatsApp message templates, stored as
 * a Custom Post Type and synced from the WhatsApp Business Management API. Lets
 * the settings UI offer real approved templates instead of hand-typed names.
 * See docs/dev/templates.md and 07-data-structures (CPT for templates; custom
 * table for logs).
 *
 * Shop-blind Core catalog.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Message-template catalog + Meta sync.
 */
class DukaRelay_Templates {

	const POST_TYPE = 'dukarelay_template';

	/**
	 * Credentials for the sync (WABA id + token).
	 *
	 * @var DukaRelay_Connection
	 */
	private $connection;

	/**
	 * Constructor. Registers the CPT on init.
	 *
	 * @param DukaRelay_Connection $connection Connection service.
	 */
	public function __construct( DukaRelay_Connection $connection ) {
		$this->connection = $connection;
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Register the template CPT (admin-managed, not public-facing).
	 *
	 * @return void
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'WhatsApp Templates', 'dukarelay' ),
					'singular_name' => __( 'WhatsApp Template', 'dukarelay' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'rewrite'         => false,
			)
		);
	}

	/**
	 * Sync approved templates from Meta into the CPT. Idempotent: existing
	 * records (matched on name + language) are updated, not duplicated.
	 *
	 * @return array{ok:bool,count:int,error:string}
	 */
	public function sync_from_meta() {
		$waba  = $this->connection->get( 'waba_id' );
		$token = $this->connection->get( 'access_token' );
		if ( '' === $waba || '' === $token ) {
			return array(
				'ok'    => false,
				'count' => 0,
				'error' => __( 'Not connected — missing WhatsApp Business Account id or token.', 'dukarelay' ),
			);
		}

		$version = apply_filters( 'dukarelay_graph_api_version', DukaRelay_Connection::API_VERSION );
		$url     = sprintf(
			'https://graph.facebook.com/%s/%s/message_templates?fields=name,status,category,language,components&limit=100',
			rawurlencode( $version ),
			rawurlencode( $waba )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'count' => 0,
				'error' => __( 'Could not reach WhatsApp to sync templates.', 'dukarelay' ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			$reason = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : __( 'Unexpected response.', 'dukarelay' );
			return array(
				'ok'    => false,
				'count' => 0,
				'error' => $reason,
			);
		}

		$count = 0;
		foreach ( $body['data'] as $template ) {
			if ( $this->upsert_template( $template ) ) {
				++$count;
			}
		}

		return array(
			'ok'    => true,
			'count' => $count,
			'error' => '',
		);
	}

	/**
	 * Insert or update a single template record from Meta's data.
	 *
	 * @param array $template One entry from the Meta message_templates response.
	 * @return bool True if stored.
	 */
	private function upsert_template( $template ) {
		if ( empty( $template['name'] ) ) {
			return false;
		}

		$name     = sanitize_text_field( $template['name'] );
		$language = isset( $template['language'] ) ? sanitize_text_field( $template['language'] ) : '';
		$status   = isset( $template['status'] ) ? sanitize_text_field( $template['status'] ) : '';
		$category = isset( $template['category'] ) ? sanitize_text_field( $template['category'] ) : '';

		$existing = $this->find_post( $name, $language );

		$postarr = array(
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		);
		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr );
		} else {
			$post_id = wp_insert_post( $postarr );
		}

		if ( ! $post_id || is_wp_error( $post_id ) ) {
			return false;
		}

		update_post_meta( $post_id, '_dukarelay_meta_name', $name );
		update_post_meta( $post_id, '_dukarelay_language', $language );
		update_post_meta( $post_id, '_dukarelay_status', $status );
		update_post_meta( $post_id, '_dukarelay_category', $category );
		update_post_meta( $post_id, '_dukarelay_components', wp_json_encode( isset( $template['components'] ) ? $template['components'] : array() ) );

		return true;
	}

	/**
	 * Find a template post id by Meta name (and optionally language).
	 *
	 * @param string $name     Meta template name.
	 * @param string $language Optional language code.
	 * @return int Post id, or 0.
	 */
	public function find_post( $name, $language = '' ) {
		$meta_query = array(
			array(
				'key'   => '_dukarelay_meta_name',
				'value' => $name,
			),
		);
		if ( '' !== $language ) {
			$meta_query[] = array(
				'key'   => '_dukarelay_language',
				'value' => $language,
			);
		}

		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- low-volume template catalog.
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * All templates as simple records (id, name, language, status, category).
	 *
	 * @param bool $approved_only Only return APPROVED templates.
	 * @return array[]
	 */
	public function all( $approved_only = false ) {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 100,
			)
		);

		$out = array();
		foreach ( (array) $ids as $id ) {
			$status = (string) get_post_meta( $id, '_dukarelay_status', true );
			if ( $approved_only && 'APPROVED' !== $status ) {
				continue;
			}
			$out[] = array(
				'id'       => (int) $id,
				'name'     => (string) get_post_meta( $id, '_dukarelay_meta_name', true ),
				'language' => (string) get_post_meta( $id, '_dukarelay_language', true ),
				'status'   => $status,
				'category' => (string) get_post_meta( $id, '_dukarelay_category', true ),
			);
		}

		return $out;
	}

	/**
	 * Whether an approved template with this name (and optional language) exists.
	 *
	 * @param string $name     Meta template name.
	 * @param string $language Optional language code.
	 * @return bool
	 */
	public function is_approved( $name, $language = '' ) {
		$id = $this->find_post( $name, $language );
		if ( ! $id ) {
			return false;
		}
		return 'APPROVED' === (string) get_post_meta( $id, '_dukarelay_status', true );
	}
}
