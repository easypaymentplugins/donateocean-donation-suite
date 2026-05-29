<?php
/**
 * Mailchimp integration.
 *
 * Subscribes donors to a Mailchimp audience when a donation is completed,
 * using the Mailchimp v3 API.
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
 * Class Mailchimp
 *
 * Subscribes donors to a Mailchimp audience on donation completion.
 *
 * @since 1.0.0
 */
class Mailchimp {

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
	 * Register WordPress hooks for the Mailchimp integration.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'donadosu_donation_completed', array( $this, 'handle_donation_completed' ), 50, 2 );
	}

	/**
	 * Handle the donation_completed event.
	 *
	 * Subscribes the donor to the configured Mailchimp audience.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id The donation post ID.
	 * @param array $payload The webhook payload from PayPal (if any).
	 * @return void
	 */
	public function handle_donation_completed( int $post_id, $payload = array() ): void {
		$settings = $this->config->get_all();

		if ( empty( $settings['mailchimp_auto_subscribe'] ) ) {
			return;
		}

		$api_key = (string) ( $settings['mailchimp_api_key'] ?? '' );
		$list_id = (string) ( $settings['mailchimp_list_id'] ?? '' );

		if ( '' === $api_key || '' === $list_id ) {
			return;
		}

		// Do not subscribe anonymous donors to mailing lists.
		$is_anonymous = '1' === (string) get_post_meta( $post_id, DonationMeta::IS_ANONYMOUS, true );
		if ( $is_anonymous ) {
			return;
		}

		$email = (string) get_post_meta( $post_id, DonationMeta::DONOR_EMAIL, true );
		if ( '' === $email ) {
			return;
		}

		$donor_name = (string) get_post_meta( $post_id, DonationMeta::DONOR_NAME, true );
		$name_parts = explode( ' ', $donor_name, 2 );
		$first_name = sanitize_text_field( $name_parts[0] ?? '' );
		$last_name  = sanitize_text_field( $name_parts[1] ?? '' );

		$this->logger->info(
			sprintf( 'Mailchimp: subscribing %s for donation #%d', $email, $post_id )
		);

		// Subscribe the contact to the audience.
		$result = $this->subscribe_contact( $api_key, $list_id, $email, $first_name, $last_name );

		if ( $result['success'] ) {
			$this->logger->info(
				sprintf( 'Mailchimp: contact %s subscribed to list %s', $email, $list_id )
			);
		} else {
			$this->logger->error(
				sprintf( 'Mailchimp: failed to subscribe %s — %s', $email, $result['message'] )
			);
		}
	}

	/**
	 * Subscribe a contact to a Mailchimp audience.
	 *
	 * Uses the Mailchimp list members endpoint to add or update a subscriber.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key    The Mailchimp API key.
	 * @param string $list_id    The Mailchimp audience (list) ID.
	 * @param string $email      The contact email.
	 * @param string $first_name The contact first name.
	 * @param string $last_name  The contact last name.
	 * @return array{success: bool, message: string}
	 */
	private function subscribe_contact( string $api_key, string $list_id, string $email, string $first_name, string $last_name ): array {
		// Extract data center from API key (format: xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us1).
		$api_parts = explode( '-', $api_key );
		if ( count( $api_parts ) < 2 ) {
			return array(
				'success' => false,
				'message' => 'Invalid Mailchimp API key format.',
			);
		}
		$dc = array_pop( $api_parts );

		// Mailchimp endpoint for list members.
		$api_url  = "https://{$dc}.api.mailchimp.com/3.0/lists/{$list_id}/members";
		$email_md5 = md5( strtolower( $email ) );
		$endpoint = "{$api_url}/{$email_md5}";

		// Determine subscription status based on double opt-in setting.
		$settings            = $this->config->get_all();
		$double_optin        = ! empty( $settings['mailchimp_double_optin'] );
		$subscription_status = $double_optin ? 'pending' : 'subscribed';

		$response = wp_remote_request(
			$endpoint,
			array(
				'method'  => 'PUT',
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $api_key ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'email_address' => $email,
						'status'        => $subscription_status,
						'merge_fields'  => array(
							'FNAME' => $first_name,
							'LNAME' => $last_name,
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array(
				'success' => true,
				'message' => '',
			);
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$error = '';
		if ( is_array( $body ) && ! empty( $body['detail'] ) ) {
			$error = (string) $body['detail'];
		}

		return array(
			'success' => false,
			'message' => $error ?: sprintf( 'HTTP %d', $code ),
		);
	}

	/**
	 * Test the Mailchimp API connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key The Mailchimp API key.
	 * @param string $list_id The Mailchimp audience ID.
	 * @return array{success: bool, message: string}
	 */
	public static function test_connection( string $api_key, string $list_id ): array {
		if ( '' === $api_key || '' === $list_id ) {
			return array(
				'success' => false,
				'message' => __( 'API key and audience ID are required.', 'donateocean-donation-suite' ),
			);
		}

		// Extract data center from API key.
		$api_parts = explode( '-', $api_key );
		if ( count( $api_parts ) < 2 ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid Mailchimp API key format.', 'donateocean-donation-suite' ),
			);
		}
		$dc = array_pop( $api_parts );

		$response = wp_remote_get(
			"https://{$dc}.api.mailchimp.com/3.0/lists/{$list_id}",
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $api_key ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return array(
				'success' => true,
				'message' => __( 'Mailchimp connection successful.', 'donateocean-donation-suite' ),
			);
		}

		if ( 403 === $code || 401 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid API key or audience ID. Please check your credentials.', 'donateocean-donation-suite' ),
			);
		}

		if ( 404 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'Audience not found. Please verify your audience ID.', 'donateocean-donation-suite' ),
			);
		}

		return array(
			'success' => false,
			/* translators: %d: HTTP status code returned by Mailchimp. */
			'message' => sprintf( __( 'Mailchimp returned HTTP %d.', 'donateocean-donation-suite' ), $code ),
		);
	}
}
