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

		$list_id = (string) ( $settings['cc_list_id'] ?? '' );
		if ( '' === $list_id ) {
			return;
		}

		// Constant Contact v3 requires an OAuth2 access token (it does not
		// accept a static API key). Obtain a valid token, refreshing it
		// transparently when expired. Bail quietly when not yet connected.
		$oauth        = new ConstantContactOAuth( $this->config, $this->logger );
		$access_token = $oauth->get_valid_access_token();
		if ( '' === $access_token ) {
			$this->logger->warn( 'Constant Contact: no valid access token — connect the account in settings' );
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
		$first_name = sanitize_text_field( $name_parts[0] ?? '' );
		$last_name  = sanitize_text_field( $name_parts[1] ?? '' );

		$this->logger->info(
			sprintf( 'Constant Contact: adding %s for donation #%d', $email, $post_id )
		);

		// Add the contact to the list.
		$result = $this->add_contact( $access_token, $list_id, $email, $first_name, $last_name );

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
	 * Uses the v3 sign_up_form endpoint to create or update a contact and add
	 * them to the specified list in a single call.
	 *
	 * @since 1.0.0
	 *
	 * @param string $access_token The OAuth2 access token.
	 * @param string $list_id      The Constant Contact list UUID.
	 * @param string $email        The contact email.
	 * @param string $first_name   The contact first name.
	 * @param string $last_name    The contact last name.
	 * @return array{success: bool, message: string}
	 */
	private function add_contact( string $access_token, string $list_id, string $email, string $first_name, string $last_name ): array {
		$api_url = 'https://api.cc.email/v3/contacts/sign_up_form';

		$response = wp_remote_post(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'email_address'    => $email,
						'first_name'       => $first_name,
						'last_name'        => $last_name,
						'list_memberships' => array( $list_id ),
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
}
