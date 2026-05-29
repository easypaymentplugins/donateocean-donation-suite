<?php
/**
 * Constant Contact integration.
 *
 * Adds donors to a Constant Contact list when a donation is completed,
 * using the Constant Contact v3 API.
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
 * Class ConstantContact
 *
 * Adds donors to a Constant Contact list on donation completion.
 *
 * @since 1.0.0
 */
class ConstantContact {

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
	 * Register WordPress hooks for the Constant Contact integration.
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
	 * Adds the donor to the configured Constant Contact list.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id The donation post ID.
	 * @param array $payload The webhook payload from PayPal (if any).
	 * @return void
	 */
	public function handle_donation_completed( int $post_id, $payload = array() ): void {
		$settings = $this->config->get_all();

		if ( empty( $settings['cc_auto_subscribe'] ) ) {
			return;
		}

		$api_key  = (string) ( $settings['cc_api_key'] ?? '' );
		$list_id = (string) ( $settings['cc_list_id'] ?? '' );

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
			sprintf( 'Constant Contact: adding %s for donation #%d', $email, $post_id )
		);

		// Add the contact to the list.
		$result = $this->add_contact( $api_key, $list_id, $email, $first_name, $last_name );

		if ( $result['success'] ) {
			$this->logger->info(
				sprintf( 'Constant Contact: contact %s added to list %s', $email, $list_id )
			);
		} else {
			$this->logger->error(
				sprintf( 'Constant Contact: failed to add %s — %s', $email, $result['message'] )
			);
		}
	}

	/**
	 * Add a contact to a Constant Contact list.
	 *
	 * Uses the Constant Contact contacts endpoint to create or update a contact,
	 * then adds them to the specified list.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key    The Constant Contact API key.
	 * @param string $list_id    The Constant Contact list UUID.
	 * @param string $email      The contact email.
	 * @param string $first_name The contact first name.
	 * @param string $last_name  The contact last name.
	 * @return array{success: bool, message: string}
	 */
	private function add_contact( string $api_key, string $list_id, string $email, string $first_name, string $last_name ): array {
		$api_url = 'https://api.cc.email/v3/contacts/sign_up_form';

		$response = wp_remote_post(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'email_address' => $email,
						'first_name'    => $first_name,
						'last_name'     => $last_name,
						'list_memberships' => array( $list_id ),
						'create_source' => array(
							'source' => 'Contact',
							'source_details' => 'Donation Suite Plugin',
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

		// 201 Created or 200 OK both indicate success.
		if ( ( 200 === $code || 201 === $code ) ) {
			return array(
				'success' => true,
				'message' => '',
			);
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$error = '';

		if ( is_array( $body ) ) {
			if ( ! empty( $body['message'] ) ) {
				$error = (string) $body['message'];
			} elseif ( ! empty( $body['error_message'] ) ) {
				$error = (string) $body['error_message'];
			}
		}

		return array(
			'success' => false,
			'message' => $error ?: sprintf( 'HTTP %d', $code ),
		);
	}

	/**
	 * Test the Constant Contact API connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key The Constant Contact API key.
	 * @param string $list_id The Constant Contact list UUID.
	 * @return array{success: bool, message: string}
	 */
	public static function test_connection( string $api_key, string $list_id ): array {
		if ( '' === $api_key || '' === $list_id ) {
			return array(
				'success' => false,
				'message' => __( 'API key and list ID are required.', 'donateocean-donation-suite' ),
			);
		}

		$response = wp_remote_get(
			'https://api.cc.email/v3/contact_lists/' . $list_id,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
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
				'message' => __( 'Constant Contact connection successful.', 'donateocean-donation-suite' ),
			);
		}

		if ( 403 === $code || 401 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid API key. Please check your credentials.', 'donateocean-donation-suite' ),
			);
		}

		if ( 404 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'List not found. Please verify your list ID.', 'donateocean-donation-suite' ),
			);
		}

		return array(
			'success' => false,
			/* translators: %d: HTTP status code returned by Constant Contact. */
			'message' => sprintf( __( 'Constant Contact returned HTTP %d.', 'donateocean-donation-suite' ), $code ),
		);
	}
}
