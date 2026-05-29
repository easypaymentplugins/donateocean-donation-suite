<?php
/**
 * Google Sheets integration.
 *
 * Appends a row to a configured Google Sheet whenever a donation is
 * completed, using the Google Sheets API v4 with a service account.
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
 * Class GoogleSheets
 *
 * Hooks into donation lifecycle events and appends rows to a Google
 * Sheet via the Sheets API v4.
 *
 * @since 1.0.0
 */
class GoogleSheets {

	/**
	 * Google Sheets API base URL.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

	/**
	 * Google OAuth2 token endpoint.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

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
	 * Register WordPress hooks for the Google Sheets integration.
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
		$this->append_row( 'Completed', $post_id );
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
		$this->append_row( 'Disputed', $post_id );
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
			$this->append_row( 'Refunded', $post->ID );
		}
	}

	/**
	 * Append a donation row to the configured Google Sheet.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_label Human-readable event label.
	 * @param int    $post_id     The donation post ID.
	 * @return bool True on success.
	 */
	private function append_row( string $event_label, int $post_id ): bool {
		$settings       = $this->config->get_all();
		$enabled        = ! empty( $settings['gsheets_enabled'] );
		$spreadsheet_id = (string) ( $settings['gsheets_spreadsheet_id'] ?? '' );
		$credentials    = (string) ( $settings['gsheets_credentials_json'] ?? '' );
		$sheet_name     = (string) ( $settings['gsheets_sheet_name'] ?? '' );

		if ( ! $enabled || '' === $spreadsheet_id || '' === $credentials ) {
			return false;
		}

		if ( '' === $sheet_name ) {
			$sheet_name = 'Sheet1';
		}

		$access_token = $this->get_access_token( $credentials );
		if ( '' === $access_token ) {
			$this->logger->error( 'Google Sheets: failed to obtain access token' );
			return false;
		}

		$row = $this->build_row( $event_label, $post_id );

		$this->logger->info(
			sprintf( 'Google Sheets: appending %s row for donation #%d', $event_label, $post_id )
		);

		$url = sprintf(
			'%s/%s/values/%s:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
			self::API_BASE,
			rawurlencode( $spreadsheet_id ),
			rawurlencode( $sheet_name )
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'        => (string) wp_json_encode(
					array(
						'values' => array( $row ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				sprintf( 'Google Sheets: API error — %s', $response->get_error_message() )
			);
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			$this->logger->info(
				sprintf( 'Google Sheets: row appended for donation #%d', $post_id )
			);
			return true;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$error = is_array( $body ) && ! empty( $body['error']['message'] )
			? (string) $body['error']['message']
			: "HTTP {$code}";

		$this->logger->error(
			sprintf( 'Google Sheets: append failed for donation #%d — %s', $post_id, $error )
		);

		return false;
	}

	/**
	 * Build a spreadsheet row from donation data.
	 *
	 * Column order: Date, Donation ID, Event, Amount, Currency, Donor Name,
	 * Donor Email, Frequency, Campaign, Purpose, Payment Source, Receipt #,
	 * Anonymous, Tribute.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_label Human-readable event label.
	 * @param int    $post_id     The donation post ID.
	 * @return array<int, string> The row values.
	 */
	private function build_row( string $event_label, int $post_id ): array {
		$meta = static function ( string $key ) use ( $post_id ) {
			return (string) get_post_meta( $post_id, $key, true );
		};

		$post = get_post( $post_id );
		$date = $post ? $post->post_date_gmt : gmdate( 'Y-m-d H:i:s' );

		$is_anonymous = '1' === $meta( DonationMeta::IS_ANONYMOUS );

		return array(
			$date,
			(string) $post_id,
			$event_label,
			$meta( DonationMeta::AMOUNT ),
			$meta( DonationMeta::CURRENCY ) ?: 'USD',
			$is_anonymous ? __( 'Anonymous', 'donateocean-donation-suite' ) : $meta( DonationMeta::DONOR_NAME ),
			$is_anonymous ? '' : $meta( DonationMeta::DONOR_EMAIL ),
			$meta( DonationMeta::DONATION_FREQUENCY ) ?: 'one_time',
			$meta( DonationMeta::CAMPAIGN ),
			$meta( DonationMeta::PURPOSE ),
			$meta( DonationMeta::PAYMENT_SOURCE ) ?: 'paypal',
			$meta( DonationMeta::RECEIPT_NO ),
			'1' === $meta( DonationMeta::IS_ANONYMOUS ) ? 'Yes' : 'No',
			'1' === $meta( DonationMeta::IS_TRIBUTE ) ? $meta( DonationMeta::TRIBUTE_TYPE ) : 'No',
		);
	}

	/**
	 * Obtain a Google API access token from a service account JSON key.
	 *
	 * Creates a self-signed JWT and exchanges it for an access token
	 * via Google's OAuth2 token endpoint. Caches the token in a transient.
	 *
	 * @since 1.0.0
	 *
	 * @param string $credentials_json The service account JSON key contents.
	 * @return string The access token, or empty string on failure.
	 */
	private function get_access_token( string $credentials_json ): string {
		// Scope the cache key to the credentials so changing the service
		// account immediately invalidates the old token.
		$cache_key = 'donadosu_gsheets_' . substr( md5( $credentials_json ), 0, 12 );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$creds = json_decode( $credentials_json, true );
		if ( ! is_array( $creds ) || empty( $creds['client_email'] ) || empty( $creds['private_key'] ) ) {
			$this->logger->error( 'Google Sheets: invalid service account credentials JSON' );
			return '';
		}

		$now    = time();
		$header = wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) );
		$claims = wp_json_encode(
			array(
				'iss'   => $creds['client_email'],
				'scope' => 'https://www.googleapis.com/auth/spreadsheets',
				'aud'   => self::TOKEN_URL,
				'iat'   => $now,
				'exp'   => $now + 3600,
			)
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$segments = array(
			rtrim( strtr( base64_encode( (string) $header ), '+/', '-_' ), '=' ),
			rtrim( strtr( base64_encode( (string) $claims ), '+/', '-_' ), '=' ),
		);

		$signing_input = implode( '.', $segments );
		$signature     = '';

		$private_key = openssl_pkey_get_private( $creds['private_key'] );
		if ( false === $private_key ) {
			$this->logger->error( 'Google Sheets: failed to parse private key' );
			return '';
		}

		if ( ! openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 ) ) {
			$this->logger->error( 'Google Sheets: JWT signing failed' );
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$segments[] = rtrim( strtr( base64_encode( $signature ), '+/', '-_' ), '=' );
		$jwt        = implode( '.', $segments );

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'        => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				sprintf( 'Google Sheets: token request failed — %s', $response->get_error_message() )
			);
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $body ) && ! empty( $body['access_token'] ) ) {
			$expires_in = (int) ( $body['expires_in'] ?? 3500 );
			set_transient( $cache_key, $body['access_token'], max( 60, $expires_in - 120 ) );
			return (string) $body['access_token'];
		}

		$error = is_array( $body ) && ! empty( $body['error_description'] )
			? (string) $body['error_description']
			: 'unknown error';
		$this->logger->error( sprintf( 'Google Sheets: token exchange failed — %s', $error ) );

		return '';
	}

	/**
	 * Test the Google Sheets connection by reading spreadsheet metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param string $credentials_json The service account JSON key.
	 * @param string $spreadsheet_id   The Google Spreadsheet ID.
	 * @return array{success: bool, message: string}
	 */
	public static function test_connection( string $credentials_json, string $spreadsheet_id ): array {
		$instance = new self( new ConfigService(), new \DonationSuite\Logging\Logger( 'error', false ) );

		// Delete any cached token for these credentials so the test always
		// verifies the current key material against Google's servers.
		$cache_key = 'donadosu_gsheets_' . substr( md5( $credentials_json ), 0, 12 );
		delete_transient( $cache_key );

		$access_token = $instance->get_access_token( $credentials_json );
		if ( '' === $access_token ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to authenticate. Check your service account JSON credentials.', 'donateocean-donation-suite' ),
			);
		}

		$url      = self::API_BASE . '/' . rawurlencode( $spreadsheet_id ) . '?fields=properties.title';
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => array(
					'Authorization' => 'Bearer ' . $access_token,
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
			$title = is_array( $body ) ? ( $body['properties']['title'] ?? '' ) : '';
			return array(
				'success' => true,
				'message' => '' !== $title
					/* translators: %s: Title of the connected Google spreadsheet. */
					? sprintf( __( 'Connected to spreadsheet: %s', 'donateocean-donation-suite' ), $title )
					: __( 'Connection successful.', 'donateocean-donation-suite' ),
			);
		}

		if ( 404 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'Spreadsheet not found. Check the Spreadsheet ID and ensure the service account has access.', 'donateocean-donation-suite' ),
			);
		}

		if ( 403 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'Access denied. Share the spreadsheet with the service account email address.', 'donateocean-donation-suite' ),
			);
		}

		return array(
			'success' => false,
			/* translators: %d: HTTP status code returned by the Google API. */
			'message' => sprintf( __( 'Google API returned HTTP %d.', 'donateocean-donation-suite' ), $code ),
		);
	}
}
