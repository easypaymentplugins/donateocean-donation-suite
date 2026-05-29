<?php
/**
 * Twilio SMS integration.
 *
 * Sends SMS notifications via the Twilio REST API whenever key donation
 * events occur (completed, refunded, disputed).
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
 * Class Twilio
 *
 * Hooks into donation lifecycle events and sends SMS messages
 * via the Twilio API to configured phone numbers.
 *
 * @since 1.0.0
 */
class Twilio {

	/**
	 * Twilio API base URL.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const API_BASE = 'https://api.twilio.com/2010-04-01';

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
	 * Register WordPress hooks for the Twilio integration.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'donadosu_donation_completed', array( $this, 'handle_donation_completed' ), 50, 2 );
		add_action( 'donadosu_donation_disputed', array( $this, 'handle_donation_disputed' ), 50, 2 );
		add_action( 'transition_post_status', array( $this, 'handle_status_transition' ), 10, 3 );
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
		$this->send_sms( 'donation_completed', $post_id );
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
		$this->send_sms( 'donation_disputed', $post_id );
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
		if ( 'donadosu_donation' !== $post->post_type || $new_status === $old_status ) {
			return;
		}

		if ( 'donadosu_refunded' === $new_status ) {
			$this->send_sms( 'donation_refunded', $post->ID );
		}
	}

	/**
	 * Send an SMS notification for a donation event.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type The event type identifier.
	 * @param int    $post_id    The donation post ID.
	 * @return bool True on success, false on failure or if integration is disabled.
	 */
	private function send_sms( string $event_type, int $post_id ): bool {
		$settings = $this->config->get_all();
		$enabled  = ! empty( $settings['twilio_enabled'] );

		if ( ! $enabled ) {
			return false;
		}

		$account_sid = (string) ( $settings['twilio_account_sid'] ?? '' );
		$auth_token  = (string) ( $settings['twilio_auth_token'] ?? '' );
		$from_number = (string) ( $settings['twilio_from_number'] ?? '' );
		$to_number   = (string) ( $settings['twilio_to_number'] ?? '' );

		if ( '' === $account_sid || '' === $auth_token || '' === $from_number || '' === $to_number ) {
			return false;
		}

		// Check per-event toggles.
		$event_toggles = array(
			'donation_completed' => 'twilio_on_completed',
			'donation_refunded'  => 'twilio_on_refunded',
			'donation_disputed'  => 'twilio_on_disputed',
		);

		$toggle_key = $event_toggles[ $event_type ] ?? '';
		if ( '' !== $toggle_key && empty( $settings[ $toggle_key ] ) ) {
			return false;
		}

		$message_body = $this->build_message( $event_type, $post_id );

		$this->logger->info(
			sprintf( 'Twilio: sending %s SMS for donation #%d', $event_type, $post_id )
		);

		$result = self::send_via_api( $account_sid, $auth_token, $from_number, $to_number, $message_body );

		if ( $result['success'] ) {
			$this->logger->info(
				sprintf( 'Twilio: %s SMS sent successfully for donation #%d', $event_type, $post_id )
			);
		} else {
			$this->logger->error(
				sprintf( 'Twilio: failed to send %s SMS for donation #%d — %s', $event_type, $post_id, $result['message'] )
			);
		}

		return $result['success'];
	}

	/**
	 * Build an SMS message body for a donation event.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type The event type identifier.
	 * @param int    $post_id    The donation post ID.
	 * @return string The SMS message body.
	 */
	private function build_message( string $event_type, int $post_id ): string {
		$meta = static function ( string $key ) use ( $post_id ) {
			return (string) get_post_meta( $post_id, $key, true );
		};

		$amount      = $meta( DonationMeta::AMOUNT );
		$currency    = $meta( DonationMeta::CURRENCY ) ?: 'USD';
		$donor_name  = $meta( DonationMeta::DONOR_NAME ) ?: 'Anonymous';
		$is_anon     = '1' === $meta( DonationMeta::IS_ANONYMOUS );
		$display     = $is_anon ? 'Anonymous' : $donor_name;
		$frequency   = $meta( DonationMeta::DONATION_FREQUENCY ) ?: 'one_time';
		$freq_label  = 'one_time' === $frequency ? 'one-time' : $frequency;
		$site_name   = get_bloginfo( 'name' );

		$event_labels = array(
			'donation_completed' => 'New donation',
			'donation_refunded'  => 'Donation refunded',
			'donation_disputed'  => 'Donation disputed',
		);

		$label = $event_labels[ $event_type ] ?? 'Donation event';

		return sprintf(
			"[%s] %s: %s %s (%s) from %s. #%d",
			$site_name,
			$label,
			$currency,
			$amount,
			$freq_label,
			$display,
			$post_id
		);
	}

	/**
	 * Send a message via the Twilio REST API.
	 *
	 * @since 1.0.0
	 *
	 * @param string $account_sid Twilio Account SID.
	 * @param string $auth_token  Twilio Auth Token.
	 * @param string $from        The Twilio phone number to send from.
	 * @param string $to          The phone number to send to.
	 * @param string $body        The SMS message body.
	 * @return array{success: bool, message: string} Result of the API call.
	 */
	public static function send_via_api( string $account_sid, string $auth_token, string $from, string $to, string $body ): array {
		$url = self::API_BASE . '/Accounts/' . rawurlencode( $account_sid ) . '/Messages.json';

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( $account_sid . ':' . $auth_token ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'        => array(
					'From' => $from,
					'To'   => $to,
					'Body' => $body,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'success' => true,
				'message' => __( 'SMS sent successfully.', 'donateocean-donation-suite' ),
			);
		}

		$error_message = '';
		if ( is_array( $response_body ) && ! empty( $response_body['message'] ) ) {
			$error_message = (string) $response_body['message'];
		}

		return array(
			'success' => false,
			/* translators: %d: HTTP status code returned by Twilio. */
			'message' => $error_message ?: sprintf( __( 'Twilio returned HTTP %d.', 'donateocean-donation-suite' ), $code ),
		);
	}
}
