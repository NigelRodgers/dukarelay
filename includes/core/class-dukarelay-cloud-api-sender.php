<?php
/**
 * Cloud API Sender: delivers a message via Meta's official WhatsApp Cloud API
 * and reports a decoded result. Implements the DukaRelay_Sender contract so a
 * fallback sender can slot in later. See docs/dev/cloud-api-sender.md.
 *
 * Pure delivery: it asks Connection for auth, posts to Meta, and returns a
 * structured result. It does NOT write to the Ledger (the dispatcher owns the
 * single ledger row) and knows nothing about orders (shop-blind).
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Official WhatsApp Cloud API sender.
 */
class DukaRelay_Cloud_Api_Sender implements DukaRelay_Sender {

	/**
	 * Credential provider.
	 *
	 * @var DukaRelay_Connection
	 */
	private $connection;

	/**
	 * Constructor.
	 *
	 * @param DukaRelay_Connection $connection The connection/credential service.
	 */
	public function __construct( DukaRelay_Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_id() {
		return 'cloud_api';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_ready() {
		return $this->connection->is_configured();
	}

	/**
	 * Deliver a message via the Cloud API.
	 *
	 * @param array $message Provider-agnostic payload (see DukaRelay_Sender::send).
	 * @return array{ok:bool,wa_message_id:string,status:string,error:string}
	 */
	public function send( array $message ) {
		if ( ! $this->is_ready() ) {
			return $this->fail( __( 'Not connected — WhatsApp credentials are missing.', 'dukarelay' ) );
		}

		$to = isset( $message['to'] ) ? preg_replace( '/[^\d]/', '', (string) $message['to'] ) : '';
		if ( '' === $to ) {
			return $this->fail( __( 'No valid recipient number was provided.', 'dukarelay' ) );
		}

		$payload = $this->build_payload( $to, $message );
		if ( empty( $payload ) ) {
			return $this->fail( __( 'Message could not be built (unknown type or missing content).', 'dukarelay' ) );
		}

		$token           = $this->connection->get( 'access_token' );
		$phone_number_id = $this->connection->get( 'phone_number_id' );
		$version         = apply_filters( 'dukarelay_graph_api_version', DukaRelay_Connection::API_VERSION );

		$url = sprintf(
			'https://graph.facebook.com/%s/%s/messages',
			rawurlencode( $version ),
			rawurlencode( $phone_number_id )
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		// Transport failure — a connection problem, not a credential problem.
		if ( is_wp_error( $response ) ) {
			return $this->fail(
				__( 'Could not reach WhatsApp (network error). The message was not sent.', 'dukarelay' )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code || 201 === $code ) {
			$wa_id = '';
			if ( isset( $body['messages'][0]['id'] ) ) {
				$wa_id = (string) $body['messages'][0]['id'];
			}
			return array(
				'ok'            => true,
				'wa_message_id' => $wa_id,
				'status'        => 'sent',
				'error'         => '',
			);
		}

		return $this->fail( $this->decode_error( $code, $body ) );
	}

	/**
	 * Translate a provider-agnostic message into the Cloud API request body.
	 *
	 * @param string $to      Digits-only recipient.
	 * @param array  $message Provider-agnostic payload.
	 * @return array Empty array if the message can't be built.
	 */
	private function build_payload( $to, array $message ) {
		$type = isset( $message['type'] ) ? sanitize_key( $message['type'] ) : '';

		if ( 'template' === $type && ! empty( $message['template']['name'] ) ) {
			$template = $message['template'];
			$body     = array(
				'messaging_product' => 'whatsapp',
				'to'                => $to,
				'type'              => 'template',
				'template'          => array(
					'name'     => sanitize_text_field( $template['name'] ),
					'language' => array(
						'code' => ! empty( $template['language'] ) ? sanitize_text_field( $template['language'] ) : 'en_US',
					),
				),
			);
			if ( ! empty( $template['components'] ) && is_array( $template['components'] ) ) {
				$body['template']['components'] = $template['components'];
			}
			return $body;
		}

		if ( 'text' === $type && isset( $message['body'] ) && '' !== $message['body'] ) {
			return array(
				'messaging_product' => 'whatsapp',
				'to'                => $to,
				'type'              => 'text',
				'text'              => array(
					'preview_url' => ! empty( $message['preview_url'] ),
					'body'        => (string) $message['body'],
				),
			);
		}

		return array();
	}

	/**
	 * Turn a Meta error response into a plain-English reason.
	 *
	 * @param int   $code HTTP status code.
	 * @param mixed $body Decoded JSON body (array) or null.
	 * @return string
	 */
	private function decode_error( $code, $body ) {
		$meta_message = '';
		if ( is_array( $body ) && isset( $body['error']['message'] ) ) {
			$meta_message = (string) $body['error']['message'];
		}

		if ( 401 === $code || 403 === $code ) {
			return __( 'Authentication failed — the access token is invalid or expired.', 'dukarelay' );
		}
		if ( 404 === $code ) {
			return __( 'The Store Number (Phone Number ID) was not found.', 'dukarelay' );
		}
		if ( 429 === $code ) {
			return __( 'Rate limit or messaging cap reached — try again later.', 'dukarelay' );
		}

		if ( '' !== $meta_message ) {
			/* translators: %s: error message returned by WhatsApp. */
			return sprintf( __( 'WhatsApp rejected the message: %s', 'dukarelay' ), $meta_message );
		}

		/* translators: %d: HTTP status code from WhatsApp. */
		return sprintf( __( 'WhatsApp returned an unexpected error (HTTP %d).', 'dukarelay' ), $code );
	}

	/**
	 * Build a failed result with a decoded reason (never blank).
	 *
	 * @param string $reason Plain-English failure reason.
	 * @return array{ok:bool,wa_message_id:string,status:string,error:string}
	 */
	private function fail( $reason ) {
		return array(
			'ok'            => false,
			'wa_message_id' => '',
			'status'        => 'failed',
			'error'         => $reason,
		);
	}
}
