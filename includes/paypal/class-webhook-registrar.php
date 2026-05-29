<?php
/**
 * PayPal webhook registrar.
 *
 * Validates PayPal API credentials and registers a webhook endpoint
 * with the PayPal Notifications API so that the site receives
 * real-time event notifications.
 *
 * @package    Donation_Suite
 * @subpackage PayPal
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\PayPal;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WebhookRegistrar
 *
 * Handles credential validation and webhook registration against
 * the PayPal REST API.
 *
 * @since 1.0.0
 */
class WebhookRegistrar {

	/**
	 * Validate PayPal API credentials by attempting an OAuth token request.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Plugin settings array.
	 * @param bool                 $sandbox  Whether to validate sandbox credentials.
	 * @return array{success: bool, token?: string, error?: string}
	 */
	public function validate_credentials( array $settings, bool $sandbox ): array {
		$prefix    = $sandbox ? 'sandbox_' : 'live_';
		$client_id = (string) ( $settings[ $prefix . 'client_id' ] ?? '' );
		$secret    = (string) ( $settings[ $prefix . 'secret' ] ?? '' );

		if ( '' === $client_id || '' === $secret ) {
			return array(
				'success' => false,
				'error'   => 'missing_credentials',
			);
		}

		$token_response = wp_remote_post(
			$this->get_base_url( $sandbox ) . '/v1/oauth2/token',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ),
				),
				'body'    => array( 'grant_type' => 'client_credentials' ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $token_response ) ) {
			return array(
				'success' => false,
				'error'   => $token_response->get_error_message(),
			);
		}

		$token_body = json_decode( (string) wp_remote_retrieve_body( $token_response ), true );
		$token      = is_array( $token_body ) ? (string) ( $token_body['access_token'] ?? '' ) : '';

		if ( '' === $token ) {
			return array(
				'success' => false,
				'error'   => 'missing_access_token',
			);
		}

		return array(
			'success' => true,
			'token'   => $token,
		);
	}

	/**
	 * Register a webhook endpoint with PayPal.
	 *
	 * Validates credentials first, then creates the webhook via the
	 * PayPal Notifications API. Returns the new webhook ID on success.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings    Plugin settings array.
	 * @param bool                 $sandbox     Whether to use sandbox environment.
	 * @param string               $webhook_url Public URL for the webhook endpoint.
	 * @param array<int, string>   $event_types PayPal event types to subscribe to.
	 * @return array{success: bool, webhook_id?: string, error?: string}
	 */
	public function register(
		array $settings,
		bool $sandbox,
		string $webhook_url,
		array $event_types = array(
			'CHECKOUT.ORDER.APPROVED',
			'CHECKOUT.ORDER.COMPLETED',
			'PAYMENT.CAPTURE.COMPLETED',
			'PAYMENT.CAPTURE.DENIED',
			'PAYMENT.CAPTURE.PENDING',
			'PAYMENT.CAPTURE.REFUNDED',
			'PAYMENT.CAPTURE.REVERSED',
			'CUSTOMER.DISPUTE.CREATED',
			'CUSTOMER.DISPUTE.RESOLVED',
			'BILLING.SUBSCRIPTION.ACTIVATED',
			'BILLING.SUBSCRIPTION.CANCELLED',
			'BILLING.SUBSCRIPTION.SUSPENDED',
			'PAYMENT.SALE.COMPLETED',
		)
	): array {
		$validation = $this->validate_credentials( $settings, $sandbox );

		if ( empty( $validation['success'] ) || empty( $validation['token'] ) ) {
			return array(
				'success' => false,
				'error'   => (string) ( $validation['error'] ?? 'missing_access_token' ),
			);
		}

		$token    = (string) $validation['token'];
		$base_url = $this->get_base_url( $sandbox );

		$mapped_events = array_map(
			static function ( string $name ): array {
				return array( 'name' => $name );
			},
			$event_types
		);

		$response = wp_remote_post(
			$base_url . '/v1/notifications/webhooks',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'url'         => $webhook_url,
						'event_types' => $mapped_events,
					)
				),
				'timeout' => 25,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status     = (int) wp_remote_retrieve_response_code( $response );
		$body       = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$webhook_id = is_array( $body ) ? (string) ( $body['id'] ?? '' ) : '';

		if ( $status < 200 || $status >= 300 || '' === $webhook_id ) {
			// If PayPal rejected because this URL is already registered, retrieve
			// the existing webhook ID rather than treating it as a hard failure.
			$error_name = is_array( $body ) ? (string) ( $body['name'] ?? '' ) : '';
			if ( 'WEBHOOK_URL_ALREADY_EXISTS' === $error_name ) {
				$existing_id = $this->find_webhook_by_url( $token, $sandbox, $webhook_url );
				if ( '' !== $existing_id ) {
					return array(
						'success'    => true,
						'webhook_id' => $existing_id,
					);
				}
			}

			return array(
				'success' => false,
				'error'   => 'webhook_registration_failed',
			);
		}

		return array(
			'success'    => true,
			'webhook_id' => $webhook_id,
		);
	}

	/**
	 * Find an existing PayPal webhook by URL and return its ID.
	 *
	 * Called as a fallback when webhook creation fails because the URL is
	 * already registered. Lists all webhooks for the app and returns the ID
	 * of the first one whose URL matches.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token       A valid OAuth2 bearer token.
	 * @param bool   $sandbox     Whether to use the sandbox environment.
	 * @param string $webhook_url The webhook URL to search for.
	 * @return string The webhook ID, or empty string if not found.
	 */
	private function find_webhook_by_url( string $token, bool $sandbox, string $webhook_url ): string {
		$response = wp_remote_get(
			$this->get_base_url( $sandbox ) . '/v1/notifications/webhooks',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['webhooks'] ) || ! is_array( $body['webhooks'] ) ) {
			return '';
		}

		foreach ( $body['webhooks'] as $webhook ) {
			if ( isset( $webhook['url'] ) && (string) $webhook['url'] === $webhook_url && ! empty( $webhook['id'] ) ) {
				return (string) $webhook['id'];
			}
		}

		return '';
	}

	/**
	 * Fetch the PayPal account email associated with the given credentials.
	 *
	 * Uses the PayPal Identity API to retrieve the merchant email address.
	 * Falls back gracefully if the endpoint is unavailable or the scope is
	 * insufficient.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token   A valid OAuth2 bearer token.
	 * @param bool   $sandbox Whether to use the sandbox environment.
	 * @return string The account email, or empty string on failure.
	 */
	public function get_account_email( string $token, bool $sandbox ): string {
		if ( '' === $token ) {
			return '';
		}

		$response = wp_remote_get(
			$this->get_base_url( $sandbox ) . '/v1/identity/oauth2/userinfo?schema=payPalv1.1',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return '';
		}

		// Try primary email from emails array first.
		if ( ! empty( $body['emails'] ) && is_array( $body['emails'] ) ) {
			foreach ( $body['emails'] as $email_entry ) {
				if ( ! empty( $email_entry['primary'] ) && ! empty( $email_entry['value'] ) ) {
					return sanitize_email( (string) $email_entry['value'] );
				}
			}
			// Fall back to first email in the array.
			$first = reset( $body['emails'] );
			if ( ! empty( $first['value'] ) ) {
				return sanitize_email( (string) $first['value'] );
			}
		}

		// Fall back to top-level email field.
		if ( ! empty( $body['email'] ) ) {
			return sanitize_email( (string) $body['email'] );
		}

		return '';
	}

	/**
	 * Get the PayPal API base URL for a given environment.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $sandbox Whether to use the sandbox environment.
	 * @return string PayPal API base URL.
	 */
	private function get_base_url( bool $sandbox ): string {
		return $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
	}
}
