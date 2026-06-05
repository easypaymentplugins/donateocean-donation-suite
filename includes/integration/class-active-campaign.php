<?php
/**
 * ActiveCampaign integration.
 *
 * Subscribes donors to an ActiveCampaign list when a donation is completed,
 * using the ActiveCampaign v3 API.
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
 * Class ActiveCampaign
 *
 * Subscribes donors to an ActiveCampaign list on donation completion.
 *
 * @since 1.0.0
 */
class ActiveCampaign {

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
	 * Register WordPress hooks for the ActiveCampaign integration.
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
	 * Creates or updates a contact in ActiveCampaign and adds them
	 * to the configured list.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id The donation post ID.
	 * @param array $payload The webhook payload from PayPal (if any).
	 * @return void
	 */
	public function handle_donation_completed( int $post_id, $payload = array() ): void {
		$settings = $this->config->get_all();

		if ( empty( $settings['ac_auto_subscribe'] ) ) {
			return;
		}

		$api_url = rtrim( (string) ( $settings['ac_api_url'] ?? '' ), '/' );
		$api_key = (string) ( $settings['ac_api_key'] ?? '' );
		$list_id = (string) ( $settings['ac_list_id'] ?? '' );

		if ( '' === $api_url || '' === $api_key || '' === $list_id ) {
			return;
		}

		// Only subscribe donors who explicitly opted in to marketing (GDPR).
		if ( ! \DonationSuite\Core\Consent::has_marketing_consent( $post_id ) ) {
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
		$first_name = $name_parts[0] ?? '';
		$last_name  = $name_parts[1] ?? '';

		$this->logger->info(
			sprintf( 'ActiveCampaign: subscribing %s for donation #%d', $email, $post_id )
		);

		// Step 1: Create or update the contact via sync endpoint.
		$contact_result = $this->sync_contact( $api_url, $api_key, $email, $first_name, $last_name );

		if ( ! $contact_result['success'] ) {
			$this->logger->error(
				sprintf( 'ActiveCampaign: failed to sync contact %s — %s', $email, $contact_result['message'] )
			);
			return;
		}

		$contact_id = $contact_result['contact_id'];

		// Step 2: Add the contact to the list.
		$list_result = $this->add_to_list( $api_url, $api_key, $contact_id, $list_id );

		if ( $list_result['success'] ) {
			$this->logger->info(
				sprintf( 'ActiveCampaign: contact %s added to list %s', $email, $list_id )
			);
		} else {
			$this->logger->error(
				sprintf( 'ActiveCampaign: failed to add %s to list — %s', $email, $list_result['message'] )
			);
		}
	}

	/**
	 * Create or update a contact in ActiveCampaign.
	 *
	 * Uses the contact/sync endpoint which creates if not found,
	 * or updates if the email already exists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_url    The ActiveCampaign API base URL.
	 * @param string $api_key    The ActiveCampaign API key.
	 * @param string $email      The contact email.
	 * @param string $first_name The contact first name.
	 * @param string $last_name  The contact last name.
	 * @return array{success: bool, contact_id: string, message: string}
	 */
	private function sync_contact( string $api_url, string $api_key, string $email, string $first_name, string $last_name ): array {
		$response = wp_remote_post(
			$api_url . '/api/3/contact/sync',
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => array(
					'Api-Token'    => $api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => (string) wp_json_encode(
					array(
						'contact' => array(
							'email'     => $email,
							'firstName' => $first_name,
							'lastName'  => $last_name,
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success'    => false,
				'contact_id' => '',
				'message'    => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ( 200 === $code || 201 === $code ) && ! empty( $body['contact']['id'] ) ) {
			return array(
				'success'    => true,
				'contact_id' => (string) $body['contact']['id'],
				'message'    => '',
			);
		}

		$error = '';
		if ( is_array( $body ) && ! empty( $body['message'] ) ) {
			$error = (string) $body['message'];
		}

		return array(
			'success'    => false,
			'contact_id' => '',
			'message'    => $error ?: sprintf( 'HTTP %d', $code ),
		);
	}

	/**
	 * Add a contact to a list in ActiveCampaign.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_url    The ActiveCampaign API base URL.
	 * @param string $api_key    The ActiveCampaign API key.
	 * @param string $contact_id The ActiveCampaign contact ID.
	 * @param string $list_id    The ActiveCampaign list ID.
	 * @return array{success: bool, message: string}
	 */
	private function add_to_list( string $api_url, string $api_key, string $contact_id, string $list_id ): array {
		$response = wp_remote_post(
			$api_url . '/api/3/contactLists',
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => array(
					'Api-Token'    => $api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => (string) wp_json_encode(
					array(
						'contactList' => array(
							'list'    => $list_id,
							'contact' => $contact_id,
							'status'  => 1, // 1 = subscribed.
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
		if ( is_array( $body ) && ! empty( $body['message'] ) ) {
			$error = (string) $body['message'];
		}

		return array(
			'success' => false,
			'message' => $error ?: sprintf( 'HTTP %d', $code ),
		);
	}

	/**
	 * Test the ActiveCampaign API connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_url The ActiveCampaign API base URL.
	 * @param string $api_key The ActiveCampaign API key.
	 * @return array{success: bool, message: string}
	 */
	public static function test_connection( string $api_url, string $api_key ): array {
		$api_url = rtrim( $api_url, '/' );

		$response = wp_remote_get(
			$api_url . '/api/3/users/me',
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => array(
					'Api-Token' => $api_key,
					'Accept'    => 'application/json',
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
				'message' => __( 'ActiveCampaign connection successful.', 'donateocean-donation-suite' ),
			);
		}

		if ( 403 === $code || 401 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid API key. Please check your credentials.', 'donateocean-donation-suite' ),
			);
		}

		return array(
			'success' => false,
			/* translators: %d: HTTP status code returned by ActiveCampaign. */
			'message' => sprintf( __( 'ActiveCampaign returned HTTP %d.', 'donateocean-donation-suite' ), $code ),
		);
	}
}
