<?php
/**
 * Zapier integration.
 *
 * Sends outbound webhook payloads to a configured Zapier webhook URL
 * whenever key donation events occur (completed, refunded, disputed,
 * subscription changes). Also exposes a REST endpoint that Zapier can
 * poll for sample data during Zap setup.
 *
 * @package    Donation_Suite
 * @subpackage Integration
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Integration;

use DonationSuite\Core\ConfigService;
use DonationSuite\Donation\DonationMeta;
use DonationSuite\Logging\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Zapier
 *
 * Hooks into donation lifecycle events and sends structured JSON
 * payloads to a Zapier webhook URL configured in plugin settings.
 *
 * @since 1.0.0
 */
class Zapier {

	/**
	 * REST API namespace.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const REST_NAMESPACE = 'donadosu/v1';

	/**
	 * Plugin configuration service.
	 *
	 * @since 1.0.0
	 * @var ConfigService
	 */
	private ConfigService $config;

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param ConfigService $config Plugin configuration service.
	 * @param Logger        $logger Logger instance.
	 */
	public function __construct( ConfigService $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * Register WordPress hooks for the Zapier integration.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		// Donation lifecycle events — use late priority so core processing finishes first.
		add_action( 'donadosu_donation_completed', array( $this, 'handle_donation_completed' ), 50, 2 );
		add_action( 'donadosu_donation_disputed', array( $this, 'handle_donation_disputed' ), 50, 2 );

		// Status transitions for refunds — hook into the post status transition.
		add_action( 'transition_post_status', array( $this, 'handle_status_transition' ), 10, 3 );

		// REST endpoint for Zapier to poll sample data.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes for Zapier polling.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/zapier/sample',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_sample_data' ),
				'permission_callback' => array( $this, 'rest_permission_check' ),
			)
		);
	}

	/**
	 * Permission check for the Zapier sample endpoint.
	 *
	 * Validates the request using a secret key parameter to avoid
	 * exposing donation data publicly.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool True if the request is authorised.
	 */
	public function rest_permission_check( \WP_REST_Request $request ): bool {
		$settings   = $this->config->get_all();
		$secret_key = (string) ( $settings['zapier_secret_key'] ?? '' );

		if ( '' === $secret_key ) {
			return false;
		}

		$header_secret = $request->get_header( 'x_donadosu_secret' );
		if ( null !== $header_secret ) {
			return hash_equals( $secret_key, $header_secret );
		}

		// Deprecated: URL param fallback for existing setups.
		$param_secret = (string) $request->get_param( 'secret' );
		return '' !== $param_secret && hash_equals( $secret_key, $param_secret );
	}

	/**
	 * Return sample donation data for Zapier Zap setup.
	 *
	 * Zapier polls this endpoint during trigger configuration to show
	 * the user which fields are available.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response Sample donation payload.
	 */
	public function rest_sample_data( \WP_REST_Request $request ): \WP_REST_Response {
		$sample = array(
			'event'            => 'donation_completed',
			'donation_id'      => 12345,
			'amount'           => '50.00',
			'currency'         => 'USD',
			'donor_name'       => 'Jane Doe',
			'donor_email'      => 'jane@example.com',
			'donor_message'    => 'Keep up the great work!',
			'donation_date'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'frequency'        => 'one_time',
			'campaign'         => '',
			'purpose'          => '',
			'is_anonymous'     => false,
			'is_tribute'       => false,
			'tribute_type'     => '',
			'tribute_name'     => '',
			'fee_covered'      => false,
			'gross_amount'     => '50.00',
			'payment_source'   => 'paypal',
			'receipt_number'   => 'RCPT-00012345',
			'order_id'         => 'PAYPAL-ORDER-ABC123',
			'status'           => 'donadosu_completed',
			'site_name'        => get_bloginfo( 'name' ),
			'site_url'         => home_url(),
		);

		return new \WP_REST_Response( array( $sample ), 200 );
	}

	/**
	 * Handle the donation_completed event.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id The donation post ID.
	 * @param array $payload The webhook payload from PayPal (if any).
	 * @return void
	 */
	public function handle_donation_completed( int $post_id, $payload = array() ): void {
		$this->send_event( 'donation_completed', $post_id );
	}

	/**
	 * Handle the donation_disputed event.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id The donation post ID.
	 * @param array $payload The webhook payload from PayPal (if any).
	 * @return void
	 */
	public function handle_donation_disputed( int $post_id, $payload = array() ): void {
		$this->send_event( 'donation_disputed', $post_id );
	}

	/**
	 * Handle post status transitions to catch refunds.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $new_status The new post status.
	 * @param string   $old_status The old post status.
	 * @param \WP_Post $post       The post object.
	 * @return void
	 */
	public function handle_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'donadosu_donation' !== $post->post_type ) {
			return;
		}

		if ( $new_status === $old_status ) {
			return;
		}

		if ( 'donadosu_refunded' === $new_status ) {
			$this->send_event( 'donation_refunded', $post->ID );
		}
	}

	/**
	 * Send an event payload to the configured Zapier webhook URL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type The event type identifier.
	 * @param int    $post_id    The donation post ID.
	 * @return bool True on success, false on failure or if integration is disabled.
	 */
	private function send_event( string $event_type, int $post_id ): bool {
		$settings    = $this->config->get_all();
		$webhook_url = (string) ( $settings['zapier_webhook_url'] ?? '' );
		$enabled     = ! empty( $settings['zapier_enabled'] );

		if ( ! $enabled || '' === $webhook_url ) {
			return false;
		}

		// Check per-event toggles.
		$event_toggles = array(
			'donation_completed' => 'zapier_on_completed',
			'donation_refunded'  => 'zapier_on_refunded',
			'donation_disputed'  => 'zapier_on_disputed',
		);

		$toggle_key = $event_toggles[ $event_type ] ?? '';
		if ( '' !== $toggle_key && empty( $settings[ $toggle_key ] ) ) {
			return false;
		}

		$payload = $this->build_payload( $event_type, $post_id );

		$this->logger->info(
			sprintf( 'Zapier: sending %s event for donation #%d', $event_type, $post_id )
		);

		$json_body = (string) wp_json_encode( $payload );
		$secret_key = (string) ( $settings['zapier_secret_key'] ?? '' );
		$signature  = '' !== $secret_key ? hash_hmac( 'sha256', $json_body, $secret_key ) : '';

		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $signature ) {
			$headers['X-Donadosu-Signature'] = $signature;
		}

		$response = wp_remote_post(
			$webhook_url,
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => $headers,
				'body'        => $json_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				sprintf(
					'Zapier: failed to send %s event for donation #%d — %s',
					$event_type,
					$post_id,
					$response->get_error_message()
				)
			);
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->logger->error(
				sprintf(
					'Zapier: unexpected HTTP %d response for %s event on donation #%d',
					$code,
					$event_type,
					$post_id
				)
			);
			return false;
		}

		$this->logger->info(
			sprintf( 'Zapier: %s event sent successfully for donation #%d', $event_type, $post_id )
		);

		return true;
	}

	/**
	 * Build a structured payload for a donation event.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type The event type identifier.
	 * @param int    $post_id    The donation post ID.
	 * @return array<string, mixed> The event payload.
	 */
	private function build_payload( string $event_type, int $post_id ): array {
		$meta = static function ( string $key ) use ( $post_id ) {
			return (string) get_post_meta( $post_id, $key, true );
		};

		$post = get_post( $post_id );

		$is_anonymous = '1' === $meta( DonationMeta::IS_ANONYMOUS );

		return array(
			'event'            => $event_type,
			'donation_id'      => $post_id,
			'amount'           => $meta( DonationMeta::AMOUNT ),
			'currency'         => $meta( DonationMeta::CURRENCY ),
			'donor_name'       => $is_anonymous ? __( 'Anonymous', 'donateocean-donation-suite' ) : $meta( DonationMeta::DONOR_NAME ),
			'donor_email'      => $is_anonymous ? '' : $meta( DonationMeta::DONOR_EMAIL ),
			'donor_message'    => $is_anonymous ? '' : $meta( DonationMeta::DONOR_MESSAGE ),
			'donation_date'    => $post ? $post->post_date_gmt : '',
			'frequency'        => $meta( DonationMeta::DONATION_FREQUENCY ) ?: 'one_time',
			'campaign'         => $meta( DonationMeta::CAMPAIGN ),
			'purpose'          => $meta( DonationMeta::PURPOSE ),
			'is_anonymous'     => '1' === $meta( DonationMeta::IS_ANONYMOUS ),
			'is_tribute'       => '1' === $meta( DonationMeta::IS_TRIBUTE ),
			'tribute_type'     => $meta( DonationMeta::TRIBUTE_TYPE ),
			'tribute_name'     => $meta( DonationMeta::TRIBUTE_NAME ),
			'fee_covered'      => '1' === $meta( DonationMeta::FEE_COVERED ),
			'gross_amount'     => $meta( DonationMeta::GROSS_AMOUNT ) ?: $meta( DonationMeta::AMOUNT ),
			'payment_source'   => $meta( DonationMeta::PAYMENT_SOURCE ) ?: 'paypal',
			'receipt_number'   => $meta( DonationMeta::RECEIPT_NO ),
			'order_id'         => $meta( DonationMeta::ORDER_ID ),
			'status'           => $post ? $post->post_status : '',
			'site_name'        => get_bloginfo( 'name' ),
			'site_url'         => home_url(),
		);
	}
}
