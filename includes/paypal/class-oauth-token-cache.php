<?php
/**
 * PayPal OAuth token cache.
 *
 * Retrieves and caches PayPal OAuth2 bearer tokens using WordPress
 * transients so that consecutive API calls reuse the same token
 * until it expires.
 *
 * @package    Donation_Suite
 * @subpackage PayPal
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\PayPal;

use DonationSuite\Core\ConfigService;
use DonationSuite\Logging\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OAuthTokenCache
 *
 * Handles retrieval and transient-based caching of PayPal OAuth2 access tokens.
 *
 * @since 1.0.0
 */
class OAuthTokenCache {

	/**
	 * Plugin configuration service.
	 *
	 * @since 1.0.0
	 * @var ConfigService
	 */
	private ConfigService $config;

	/**
	 * Optional logger instance.
	 *
	 * @since 1.0.0
	 * @var Logger|null
	 */
	private ?Logger $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param ConfigService $config Plugin configuration service.
	 * @param Logger|null   $logger Optional logger instance.
	 */
	public function __construct( ConfigService $config, ?Logger $logger = null ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * Get a valid OAuth2 bearer token.
	 *
	 * Checks for a cached token in a WordPress transient keyed by the
	 * current environment (sandbox or live). If no cached token exists,
	 * requests a new one from PayPal using client credentials and caches
	 * the result.
	 *
	 * @since 1.0.0
	 *
	 * @return string Bearer token string, or empty string on failure.
	 */
	public function get_token(): string {
		$env = $this->config->is_sandbox() ? 'sandbox' : 'live';
		$key = 'donadosu_token_' . $env;

		$cached = get_transient( $key );

		if ( is_array( $cached ) && ! empty( $cached['token'] ) ) {
			$this->log( 'debug', 'OAuth token retrieved from cache', array( 'env' => $env ) );
			return $cached['token'];
		}

		$this->log( 'info', 'OAuth token not cached, requesting new token from PayPal', array( 'env' => $env ) );

		$response = wp_remote_post(
			$this->config->get_base_url() . '/v1/oauth2/token',
			array(
				'headers' => array(
					'Authorization'                 => 'Basic ' . base64_encode( $this->config->get_client_id() . ':' . $this->config->get_secret() ),
					'PayPal-Partner-Attribution-Id' => 'mbjtechnolabs_sp',
				),
				'body'    => array( 'grant_type' => 'client_credentials' ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log(
				'error',
				'OAuth token request failed (HTTP error)',
				array(
					'env'   => $env,
					'error' => $response->get_error_message(),
				)
			);
			return '';
		}

		$body    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$token   = is_array( $body ) ? (string) ( $body['access_token'] ?? '' ) : '';
		$expires = max( 60, ( (int) ( is_array( $body ) ? ( $body['expires_in'] ?? 300 ) : 300 ) ) - 60 );

		if ( $token ) {
			set_transient( $key, array( 'token' => $token ), $expires );
			$this->log(
				'info',
				'OAuth token obtained and cached',
				array(
					'env'        => $env,
					'expires_in' => $expires,
				)
			);
		} else {
			$this->log(
				'error',
				'OAuth token request returned empty token',
				array(
					'env'           => $env,
					'response_code' => wp_remote_retrieve_response_code( $response ),
				)
			);
		}

		return $token;
	}

	/**
	 * Log a message if a logger is available.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $level   Log level (debug, info, warn, error).
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	private function log( string $level, string $message, array $context = array() ): void {
		if ( $this->logger ) {
			$this->logger->$level( $message, $context );
		}
	}
}
