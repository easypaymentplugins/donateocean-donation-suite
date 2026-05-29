<?php
/**
 * Brevo (Sendinblue) integration.
 *
 * Subscribes donors to a Brevo contact list when a donation is completed,
 * using the Brevo API v3.
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
 * Class Brevo
 *
 * Subscribes donors to a Brevo contact list on donation completion.
 *
 * @since 1.0.0
 */
class Brevo {

	/**
	 * Brevo API base URL.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const API_BASE = 'https://api.brevo.com/v3';

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
	 * Register WordPress hooks for the Brevo integration.
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
	 * Creates or updates a contact in Brevo and adds them to the
	 * configured list.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id The donation post ID.
	 * @param array $payload The webhook payload from PayPal (if any).
	 * @return void
	 */
	public function handle_donation_completed( int $post_id, $payload = array() ): void {
		$settings = $this->config->get_all();

		if ( empty( $settings['brevo_auto_subscribe'] ) ) {
			return;
		}

		$api_key = (string) ( $settings['brevo_api_key'] ?? '' );
		$list_id = (int) ( $settings['brevo_list_id'] ?? 0 );

		if ( '' === $api_key || 0 === $list_id ) {
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

		$double_optin = ! empty( $settings['brevo_double_optin'] );

		$this->logger->info(
			sprintf( 'Brevo: subscribing %s for donation #%d', $email, $post_id )
		);

		$result = $this->create_or_update_contact( $api_key, $email, $first_name, $last_name, $list_id, $double_optin );

		if ( $result['success'] ) {
			$this->logger->info(
				sprintf( 'Brevo: contact %s added to list %d', $email, $list_id )
			);
		} else {
			$this->logger->error(
				sprintf( 'Brevo: failed to subscribe %s — %s', $email, $result['message'] )
			);
		}
	}

	/**
	 * Create or update a contact in Brevo and add to a list.
	 *
	 * Uses the contacts endpoint which creates if not found,
	 * or updates if the email already exists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key      The Brevo API key.
	 * @param string $email        The contact email.
	 * @param string $first_name   The contact first name.
	 * @param string $last_name    The contact last name.
	 * @param int    $list_id      The Brevo list ID.
	 * @param bool   $double_optin Whether to use double opt-in.
	 * @return array{success: bool, message: string}
	 */
	private function create_or_update_contact( string $api_key, string $email, string $first_name, string $last_name, int $list_id, bool $double_optin ): array {
		$body = array(
			'email'            => $email,
			'attributes'       => array(
				'FIRSTNAME' => $first_name,
				'LASTNAME'  => $last_name,
			),
			'listIds'          => array( $list_id ),
			'updateEnabled'    => true,
		);

		if ( $double_optin ) {
			// Use the DOI (Double Opt-In) contacts endpoint.
			$body['includeListIds'] = array( $list_id );
			$body['templateId']     = (int) apply_filters( 'donadosu_brevo_doi_template_id', 1 );
			$body['redirectionUrl'] = home_url();
			unset( $body['listIds'] );

			$url = self::API_BASE . '/contacts/doubleOptinConfirmation';
		} else {
			$url = self::API_BASE . '/contacts';
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => (string) wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code          = (int) wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		// 201 = created, 204 = updated (already exists).
		if ( $code >= 200 && $code < 300 ) {
			return array(
				'success' => true,
				'message' => '',
			);
		}

		// 400 with "Contact already exist" is actually a success case when
		// the contact exists but is already on the list.
		if ( 400 === $code && is_array( $response_body ) ) {
			$error_msg = (string) ( $response_body['message'] ?? '' );
			if ( false !== stripos( $error_msg, 'already exist' ) ) {
				return array(
					'success' => true,
					'message' => '',
				);
			}
		}

		$error = '';
		if ( is_array( $response_body ) && ! empty( $response_body['message'] ) ) {
			$error = (string) $response_body['message'];
		}

		return array(
			'success' => false,
			'message' => $error ?: sprintf( 'HTTP %d', $code ),
		);
	}

	/**
	 * Test the Brevo API connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key The Brevo API key.
	 * @return array{success: bool, message: string}
	 */
	public static function test_connection( string $api_key ): array {
		$response = wp_remote_get(
			self::API_BASE . '/account',
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => array(
					'api-key' => $api_key,
					'Accept'  => 'application/json',
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
			$body  = json_decode( wp_remote_retrieve_body( $response ), true );
			$email = is_array( $body ) ? ( $body['email'] ?? '' ) : '';
			return array(
				'success' => true,
				'message' => '' !== $email
					/* translators: %s: Email address of the connected Brevo account. */
					? sprintf( __( 'Connected to Brevo account: %s', 'donateocean-donation-suite' ), $email )
					: __( 'Brevo connection successful.', 'donateocean-donation-suite' ),
			);
		}

		if ( 401 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid API key. Please check your Brevo credentials.', 'donateocean-donation-suite' ),
			);
		}

		return array(
			'success' => false,
			/* translators: %d: HTTP status code returned by Brevo. */
			'message' => sprintf( __( 'Brevo returned HTTP %d.', 'donateocean-donation-suite' ), $code ),
		);
	}
}
