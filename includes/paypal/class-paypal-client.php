<?php
/**
 * PayPal REST API client.
 *
 * Provides methods for creating and capturing orders, managing
 * subscriptions, verifying webhooks, and issuing refunds via the
 * PayPal v2/v1 REST APIs.
 *
 * @package    Donation_Suite
 * @subpackage PayPal
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\PayPal;

use DonationSuite\Core\ConfigService;
use DonationSuite\Core\Currency;
use DonationSuite\Logging\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PayPalClient
 *
 * Wraps PayPal REST API endpoints for orders, subscriptions, webhooks,
 * and refunds. All HTTP communication uses wp_remote_request().
 *
 * @since 1.0.0
 */
class PayPalClient {

	/**
	 * Plugin configuration service.
	 *
	 * @since 1.0.0
	 * @var ConfigService
	 */
	private ConfigService $config;

	/**
	 * OAuth token cache for bearer tokens.
	 *
	 * @since 1.0.0
	 * @var OAuthTokenCache
	 */
	private OAuthTokenCache $token_cache;

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
	 * @param ConfigService   $config      Plugin configuration service.
	 * @param OAuthTokenCache $token_cache OAuth token cache instance.
	 * @param Logger|null     $logger      Optional logger instance.
	 */
	public function __construct( ConfigService $config, OAuthTokenCache $token_cache, ?Logger $logger = null ) {
		$this->config      = $config;
		$this->token_cache = $token_cache;
		$this->logger      = $logger;
	}

	/**
	 * Create a PayPal order.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $payload Order creation payload.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function create_order( array $payload, string $idempotency_key = '' ): array {
		$this->log(
			'debug',
			'PayPal API: creating order',
			array(
				'intent' => $payload['intent'] ?? '',
				'amount' => $payload['purchase_units'][0]['amount'] ?? '',
			)
		);

		$request_id = '' !== $idempotency_key
			? 'create:' . substr( hash( 'sha256', $idempotency_key ), 0, 32 )
			: '';
		$result = $this->request( 'POST', '/v2/checkout/orders', $payload, $request_id );

		$this->log(
			empty( $result['success'] ) ? 'error' : 'info',
			'PayPal API: create order ' . ( empty( $result['success'] ) ? 'failed' : 'succeeded' ),
			array(
				'order_id' => $result['data']['id'] ?? '',
				'status'   => $result['status'] ?? '',
				'error'    => $result['error'] ?? '',
			)
		);

		return $result;
	}

	/**
	 * Capture a PayPal order.
	 *
	 * @since 1.0.0
	 *
	 * @param string $order_id PayPal order ID to capture.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function capture_order( string $order_id ): array {
		$this->log( 'debug', 'PayPal API: capturing order', array( 'order_id' => $order_id ) );

		// Deterministic Request-Id makes capture retries idempotent at PayPal.
		$result = $this->request(
			'POST',
			'/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture',
			array(),
			'capture:' . substr( hash( 'sha256', $order_id ), 0, 32 )
		);

		$this->log(
			empty( $result['success'] ) ? 'error' : 'info',
			'PayPal API: capture order ' . ( empty( $result['success'] ) ? 'failed' : 'succeeded' ),
			array(
				'order_id'   => $order_id,
				'status'     => $result['status'] ?? '',
				'capture_id' => $result['data']['purchase_units'][0]['payments']['captures'][0]['id'] ?? '',
				'error'      => $result['error'] ?? '',
			)
		);

		return $result;
	}

	/**
	 * Get PayPal order details.
	 *
	 * @since 1.0.0
	 *
	 * @param string $order_id PayPal order ID.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function get_order( string $order_id ): array {
		$this->log( 'debug', 'PayPal API: fetching order details', array( 'order_id' => $order_id ) );

		$result = $this->request( 'GET', '/v2/checkout/orders/' . rawurlencode( $order_id ) );

		$this->log(
			empty( $result['success'] ) ? 'warn' : 'debug',
			'PayPal API: get order ' . ( empty( $result['success'] ) ? 'failed' : 'succeeded' ),
			array(
				'order_id'     => $order_id,
				'status'       => $result['status'] ?? '',
				'order_status' => $result['data']['status'] ?? '',
			)
		);

		return $result;
	}

	/**
	 * Verify a PayPal webhook signature.
	 *
	 * The PayPal webhook signature is bound to a CRC32 of the exact raw
	 * body bytes delivered to the webhook listener. Decoding the JSON and
	 * re-encoding it (as wp_json_encode would do via the generic request()
	 * path) can produce a byte-different representation — different key
	 * ordering, unicode escaping, number formatting, etc. — which shifts
	 * the CRC32 and causes PayPal to return verification_status UNKNOWN
	 * or FAILURE. To guarantee a byte-perfect match we splice the raw
	 * webhook event JSON directly into the verification request body.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $meta              Verification metadata: auth_algo, cert_url,
	 *                                                 transmission_id, transmission_sig,
	 *                                                 transmission_time, webhook_id.
	 * @param string                $raw_webhook_event Raw JSON bytes of the webhook event
	 *                                                 exactly as received from PayPal.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function verify_webhook_signature( array $meta, string $raw_webhook_event ): array {
		$this->log(
			'debug',
			'PayPal API: verifying webhook signature',
			array( 'transmission_id' => $meta['transmission_id'] ?? '' )
		);

		// Build the request body manually so webhook_event is byte-identical
		// to what PayPal originally signed. Encode the metadata first, then
		// splice the raw event JSON in before the closing brace.
		$meta_json = wp_json_encode( $meta );
		if ( ! is_string( $meta_json ) || '' === $meta_json ) {
			return array(
				'success' => false,
				'error'   => 'Failed to encode webhook verification metadata',
			);
		}

		$raw_event = '' !== trim( $raw_webhook_event ) ? $raw_webhook_event : '{}';
		$raw_body  = '{}' === $meta_json
			? '{"webhook_event":' . $raw_event . '}'
			: rtrim( $meta_json, '}' ) . ',"webhook_event":' . $raw_event . '}';

		$result              = $this->request( 'POST', '/v1/notifications/verify-webhook-signature', array(), '', $raw_body );
		$verification_status = $result['data']['verification_status'] ?? 'UNKNOWN';

		$this->log(
			'UNKNOWN' === $verification_status || 'SUCCESS' !== $verification_status ? 'warn' : 'debug',
			'PayPal API: webhook signature verification ' . $verification_status,
			array(
				'transmission_id'     => $meta['transmission_id'] ?? '',
				'verification_status' => $verification_status,
			)
		);

		return $result;
	}

	/**
	 * Get or create the PayPal catalog product used for recurring donations.
	 *
	 * Creates a DIGITAL / SERVICE product on the first call and caches the
	 * product ID in a WordPress option so subsequent calls reuse it. If the
	 * cached product is no longer valid (e.g. deleted in PayPal), a new one
	 * is created automatically.
	 *
	 * @since 1.0.0
	 *
	 * @return string PayPal product ID, or empty string on failure.
	 */
	private function get_or_create_product(): string {
		$option_key = 'donadosu_paypal_product_id';
		$product_id = (string) get_option( $option_key, '' );

		if ( $product_id ) {
			$this->log( 'debug', 'PayPal API: using cached product', array( 'product_id' => $product_id ) );
			return $product_id;
		}

		$this->log( 'info', 'PayPal API: creating catalog product for recurring donations' );

		$result = $this->request(
			'POST',
			'/v1/catalogs/products',
			array(
				'name'        => 'Recurring Donation',
				'description' => 'Recurring donation managed by Donation Suite',
				'type'        => 'SERVICE',
				'category'    => 'CHARITY',
			)
		);

		if ( empty( $result['success'] ) || empty( $result['data']['id'] ) ) {
			$this->log(
				'error',
				'PayPal API: catalog product creation failed',
				array(
					'status' => $result['status'] ?? '',
					'error'  => $result['error'] ?? '',
				)
			);
			return '';
		}

		$product_id = (string) $result['data']['id'];
		update_option( $option_key, $product_id, false );

		$this->log( 'info', 'PayPal API: catalog product created', array( 'product_id' => $product_id ) );

		return $product_id;
	}

	/**
	 * Create a billing plan for recurring donations.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name     Plan display name.
	 * @param float  $amount   Billing amount per cycle.
	 * @param string $currency ISO currency code.
	 * @param string $interval Billing interval: 'MONTH' or 'YEAR'.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function create_subscription_plan( string $name, float $amount, string $currency, string $interval = 'MONTH' ): array {
		$this->log(
			'debug',
			'PayPal API: creating subscription plan',
			array(
				'name'     => $name,
				'amount'   => $amount,
				'currency' => $currency,
				'interval' => $interval,
			)
		);

		$product_id = $this->get_or_create_product();
		if ( ! $product_id ) {
			return array(
				'success' => false,
				'error'   => 'Failed to resolve PayPal catalog product for subscriptions',
			);
		}

		$plan_body = array(
			'product_id'          => $product_id,
			'name'                => $name,
			'status'              => 'ACTIVE',
			'billing_cycles'      => array(
				array(
					'frequency'      => array(
						'interval_unit'  => $interval,
						'interval_count' => 1,
					),
					'tenure_type'    => 'REGULAR',
					'sequence'       => 1,
					'total_cycles'   => 0,
					'pricing_scheme' => array(
						'fixed_price' => array(
							'value'         => Currency::format_amount( $amount, $currency ),
							'currency_code' => $currency,
						),
					),
				),
			),
			'payment_preferences' => array(
				'auto_bill_outstanding'     => true,
				'payment_failure_threshold' => 3,
			),
		);

		$result = $this->request( 'POST', '/v1/billing/plans', $plan_body );

		// If the plan creation failed with 404, the cached product was likely
		// deleted in PayPal. Clear the cache, re-create the product, and retry once.
		if ( empty( $result['success'] ) && 404 === ( $result['status'] ?? 0 ) ) {
			$this->log( 'warn', 'PayPal API: cached product missing, recreating', array( 'stale_product_id' => $product_id ) );

			delete_option( 'donadosu_paypal_product_id' );

			$product_id = $this->get_or_create_product();
			if ( $product_id ) {
				$plan_body['product_id'] = $product_id;
				$result = $this->request( 'POST', '/v1/billing/plans', $plan_body );
			}
		}

		$this->log(
			empty( $result['success'] ) ? 'error' : 'info',
			'PayPal API: create subscription plan ' . ( empty( $result['success'] ) ? 'failed' : 'succeeded' ),
			array(
				'plan_id' => $result['data']['id'] ?? '',
				'status'  => $result['status'] ?? '',
				'error'   => $result['error'] ?? '',
			)
		);

		return $result;
	}

	/**
	 * Create a subscription for a donor under a plan.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plan_id    PayPal billing plan ID.
	 * @param string $return_url URL to redirect the donor after approval.
	 * @param string $cancel_url URL to redirect the donor on cancellation.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function create_subscription( string $plan_id, string $return_url, string $cancel_url, string $idempotency_key = '' ): array {
		$this->log( 'debug', 'PayPal API: creating subscription', array( 'plan_id' => $plan_id ) );

		$request_id = '' !== $idempotency_key
			? 'sub:' . substr( hash( 'sha256', $idempotency_key ), 0, 32 )
			: '';

		$result = $this->request(
			'POST',
			'/v1/billing/subscriptions',
			array(
				'plan_id'             => $plan_id,
				'application_context' => array(
					'return_url'  => $return_url,
					'cancel_url'  => $cancel_url,
					'user_action' => 'SUBSCRIBE_NOW',
				),
			),
			$request_id
		);

		$this->log(
			empty( $result['success'] ) ? 'error' : 'info',
			'PayPal API: create subscription ' . ( empty( $result['success'] ) ? 'failed' : 'succeeded' ),
			array(
				'plan_id'         => $plan_id,
				'subscription_id' => $result['data']['id'] ?? '',
				'status'          => $result['status'] ?? '',
				'error'           => $result['error'] ?? '',
			)
		);

		return $result;
	}

	/**
	 * Retrieve current subscription details.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subscription_id PayPal subscription ID.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function get_subscription( string $subscription_id ): array {
		$this->log( 'debug', 'PayPal API: fetching subscription details', array( 'subscription_id' => $subscription_id ) );

		$result = $this->request( 'GET', '/v1/billing/subscriptions/' . rawurlencode( $subscription_id ) );

		$this->log(
			empty( $result['success'] ) ? 'warn' : 'debug',
			'PayPal API: get subscription ' . ( empty( $result['success'] ) ? 'failed' : 'succeeded' ),
			array(
				'subscription_id' => $subscription_id,
				'status'          => $result['status'] ?? '',
				'sub_status'      => $result['data']['status'] ?? '',
			)
		);

		return $result;
	}

	/**
	 * Cancel an active subscription.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subscription_id PayPal subscription ID.
	 * @param string $reason          Cancellation reason.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function cancel_subscription( string $subscription_id, string $reason = 'Cancelled by admin' ): array {
		$this->log(
			'info',
			'PayPal API: cancelling subscription',
			array(
				'subscription_id' => $subscription_id,
				'reason'          => $reason,
			)
		);

		$result = $this->request(
			'POST',
			'/v1/billing/subscriptions/' . rawurlencode( $subscription_id ) . '/cancel',
			array( 'reason' => $reason )
		);

		$this->log(
			empty( $result['success'] ) ? 'error' : 'info',
			'PayPal API: cancel subscription ' . ( empty( $result['success'] ) ? 'failed' : 'succeeded' ),
			array(
				'subscription_id' => $subscription_id,
				'status'          => $result['status'] ?? '',
				'error'           => $result['error'] ?? '',
			)
		);

		return $result;
	}

	/**
	 * Suspend (pause) an active subscription.
	 *
	 * PayPal stops billing; the subscription can be reactivated later.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subscription_id PayPal subscription ID.
	 * @param string $reason          Suspension reason.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function suspend_subscription( string $subscription_id, string $reason = 'Paused by admin' ): array {
		$this->log(
			'info',
			'PayPal API: suspending subscription',
			array(
				'subscription_id' => $subscription_id,
				'reason'          => $reason,
			)
		);

		$result = $this->request(
			'POST',
			'/v1/billing/subscriptions/' . rawurlencode( $subscription_id ) . '/suspend',
			array( 'reason' => $reason )
		);

		$this->log(
			empty( $result['success'] ) ? 'error' : 'info',
			'PayPal API: suspend subscription ' . ( empty( $result['success'] ) ? 'failed' : 'succeeded' ),
			array(
				'subscription_id' => $subscription_id,
				'status'          => $result['status'] ?? '',
				'error'           => $result['error'] ?? '',
			)
		);

		return $result;
	}

	/**
	 * Reactivate a suspended (paused) subscription.
	 *
	 * Billing resumes from the next scheduled cycle.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subscription_id PayPal subscription ID.
	 * @param string $reason          Reactivation reason.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function activate_subscription( string $subscription_id, string $reason = 'Reactivated by admin' ): array {
		$this->log(
			'info',
			'PayPal API: reactivating subscription',
			array(
				'subscription_id' => $subscription_id,
				'reason'          => $reason,
			)
		);

		$result = $this->request(
			'POST',
			'/v1/billing/subscriptions/' . rawurlencode( $subscription_id ) . '/activate',
			array( 'reason' => $reason )
		);

		$this->log(
			empty( $result['success'] ) ? 'error' : 'info',
			'PayPal API: activate subscription ' . ( empty( $result['success'] ) ? 'failed' : 'succeeded' ),
			array(
				'subscription_id' => $subscription_id,
				'status'          => $result['status'] ?? '',
				'error'           => $result['error'] ?? '',
			)
		);

		return $result;
	}

	/**
	 * Create a merchant-initiated PayPal order paid by a previously vaulted card.
	 *
	 * PayPal's v1 Subscriptions API does not support vaulted cards (it accepts
	 * raw card data only, with no PAYMENT_METHOD_TOKEN field). To offer
	 * recurring card donations we instead vault the card during the first
	 * Orders v2 capture (via payment_source.card.attributes.vault.store_in_vault
	 * = ON_SUCCESS) and use the returned vault.id to create merchant-initiated
	 * orders for each subsequent renewal — no donor presence required.
	 *
	 * @since 1.0.0
	 *
	 * @param string $vault_id        The PAYMENT_METHOD_TOKEN id from a previous
	 *                                Orders v2 capture (vault.id in the response).
	 * @param float  $amount          Amount to charge for this renewal cycle.
	 * @param string $currency        ISO currency code (e.g. USD, GBP).
	 * @param string $idempotency_key Optional idempotency key. When provided the
	 *                                PayPal-Request-Id is derived from it so retried
	 *                                network calls do not create duplicate orders.
	 * @param string $description     Optional human-readable purchase description.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function create_order_with_vaulted_card(
		string $vault_id,
		float $amount,
		string $currency,
		string $idempotency_key = '',
		string $description = ''
	): array {
		$this->log(
			'debug',
			'PayPal API: creating merchant-initiated order with vaulted card',
			array(
				'vault_id' => $vault_id,
				'amount'   => $amount,
				'currency' => $currency,
			)
		);

		$purchase_unit = array(
			'amount' => array(
				'currency_code' => strtoupper( $currency ),
				'value'         => Currency::format_amount( $amount, $currency ),
			),
		);

		if ( '' !== $description ) {
			$purchase_unit['description'] = substr( $description, 0, 127 );
		}

		$body = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array( $purchase_unit ),
			// MIT path — pass the vaulted card token reference and flag the order
			// as a stored-credential merchant-initiated transaction so PayPal
			// processes it without donor SCA / 3DS.
			'payment_source' => array(
				'card' => array(
					'vault_id'                  => $vault_id,
					'stored_credential'         => array(
						'payment_initiator' => 'MERCHANT',
						'payment_type'      => 'RECURRING',
						'usage'             => 'SUBSEQUENT',
					),
					'experience_context'        => array(
						'shipping_preference' => 'NO_SHIPPING',
					),
				),
			),
		);

		$request_id = '' !== $idempotency_key
			? 'mit:' . substr( hash( 'sha256', $idempotency_key ), 0, 32 )
			: '';

		$result = $this->request( 'POST', '/v2/checkout/orders', $body, $request_id );

		$this->log(
			empty( $result['success'] ) ? 'error' : 'info',
			'PayPal API: create MIT order ' . ( empty( $result['success'] ) ? 'failed' : 'succeeded' ),
			array(
				'vault_id'  => $vault_id,
				'order_id'  => $result['data']['id'] ?? '',
				'status'    => $result['status'] ?? '',
				'order_st'  => $result['data']['status'] ?? '',
				'error'     => $result['error'] ?? '',
			)
		);

		return $result;
	}

	/**
	 * Delete a vaulted payment token.
	 *
	 * Used when a donor cancels a card-recurring donation so PayPal no longer
	 * stores the card on the merchant's behalf.
	 *
	 * @since 1.0.0
	 *
	 * @param string $vault_id The PAYMENT_METHOD_TOKEN id to delete.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function delete_payment_token( string $vault_id ): array {
		$this->log( 'debug', 'PayPal API: deleting vaulted payment token', array( 'vault_id' => $vault_id ) );

		$result = $this->request(
			'DELETE',
			'/v3/vault/payment-tokens/' . rawurlencode( $vault_id )
		);

		$this->log(
			empty( $result['success'] ) ? 'warn' : 'info',
			'PayPal API: delete payment token ' . ( empty( $result['success'] ) ? 'failed' : 'succeeded' ),
			array(
				'vault_id' => $vault_id,
				'status'   => $result['status'] ?? '',
				'error'    => $result['error'] ?? '',
			)
		);

		return $result;
	}

	/**
	 * Issue a full or partial refund against a PayPal capture.
	 *
	 * @since 1.0.0
	 *
	 * @param string $capture_id PayPal capture ID from the completed payment.
	 * @param float  $amount     Amount to refund. Pass 0 for a full refund.
	 * @param string $currency   ISO currency code (required when $amount > 0).
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	public function refund_capture( string $capture_id, float $amount = 0.0, string $currency = '' ): array {
		$this->log(
			'info',
			'PayPal API: issuing refund',
			array(
				'capture_id' => $capture_id,
				'amount'     => $amount,
				'currency'   => $currency,
			)
		);

		$body = array();

		if ( $amount > 0.0 && '' !== $currency ) {
			$body = array(
				'amount' => array(
					'value'         => Currency::format_amount( $amount, $currency ),
					'currency_code' => strtoupper( $currency ),
				),
			);
		}

		$result = $this->request( 'POST', '/v2/payments/captures/' . rawurlencode( $capture_id ) . '/refund', $body );

		$this->log(
			empty( $result['success'] ) ? 'error' : 'info',
			'PayPal API: refund ' . ( empty( $result['success'] ) ? 'failed' : 'succeeded' ),
			array(
				'capture_id' => $capture_id,
				'refund_id'  => $result['data']['id'] ?? '',
				'status'     => $result['status'] ?? '',
				'error'      => $result['error'] ?? '',
			)
		);

		return $result;
	}

	/**
	 * Send an HTTP request to the PayPal API with retry logic.
	 *
	 * Builds the Authorization header with a Bearer token, generates a
	 * unique PayPal-Request-Id, and uses wp_remote_request() for the
	 * actual HTTP call. Implements exponential backoff retry for transient
	 * failures (timeouts, 5xx errors).
	 *
	 * @since 1.0.0
	 *
	 * @param string               $method     HTTP method (GET, POST, etc.).
	 * @param string               $path       API endpoint path (e.g. /v2/checkout/orders).
	 * @param array<string, mixed> $body       Request body data (ignored if $raw_body is non-null).
	 * @param string               $request_id Optional idempotency key.
	 * @param string|null          $raw_body   Pre-serialized raw request body. When provided,
	 *                                         it is sent verbatim and $body is ignored.
	 *                                         Used by endpoints that require byte-exact bodies.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	private function request( string $method, string $path, array $body = array(), string $request_id = '', ?string $raw_body = null ): array {
		$max_retries = 3;
		$delays      = array( 2, 4, 8 ); // Exponential backoff: 2s, 4s, 8s
		$attempt     = 0;

		while ( $attempt < $max_retries ) {
			$result = $this->make_request( $method, $path, $body, $request_id, $raw_body );

			// Success - return immediately
			if ( $result['success'] ) {
				return $result;
			}

			// Check if error is retryable
			$status_code = $result['status'] ?? 0;
			$is_retryable = $this->is_retryable_error( $status_code, $result['error'] ?? '' );

			if ( ! $is_retryable || $attempt >= $max_retries - 1 ) {
				// Non-retryable error or all retries exhausted
				return $result;
			}

			// Calculate delay for next retry
			$delay = $delays[ $attempt ];
			$this->log(
				'warn',
				'PayPal API: retryable error, waiting before retry',
				array(
					'method'       => $method,
					'path'         => $path,
					'status_code'  => $status_code,
					'error'        => $result['error'] ?? '',
					'attempt'      => $attempt + 1,
					'next_attempt' => $attempt + 2,
					'delay_sec'    => $delay,
				)
			);

			// Sleep before retrying
			sleep( $delay );
			$attempt++;
		}

		// Should not reach here, but return failure just in case
		return array(
			'success' => false,
			'error'   => 'PayPal API request failed after all retries',
		);
	}

	/**
	 * Determine if an error is retryable (transient) vs permanent.
	 *
	 * Retryable errors include:
	 * - Connection timeouts (WP_Error with timeout message)
	 * - 503 Service Unavailable
	 * - 504 Gateway Timeout
	 * - 429 Too Many Requests (rate limited)
	 *
	 * @since 1.0.0
	 *
	 * @param int    $status_code HTTP status code (0 if error).
	 * @param string $error_msg   Error message.
	 * @return bool True if error should be retried.
	 */
	private function is_retryable_error( int $status_code, string $error_msg = '' ): bool {
		// Transient server errors
		if ( in_array( $status_code, array( 503, 504, 429 ), true ) ) {
			return true;
		}

		// Connection timeouts and temporary failures
		if ( stripos( $error_msg, 'timeout' ) !== false
			|| stripos( $error_msg, 'temporarily unavailable' ) !== false
			|| stripos( $error_msg, 'connection timed out' ) !== false
		) {
			return true;
		}

		return false;
	}

	/**
	 * Perform a single HTTP request to the PayPal API.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $method     HTTP method (GET, POST, etc.).
	 * @param string               $path       API endpoint path (e.g. /v2/checkout/orders).
	 * @param array<string, mixed> $body       Request body data (ignored if $raw_body is non-null).
	 * @param string               $request_id Optional idempotency key.
	 * @param string|null          $raw_body   Pre-serialized raw request body. When provided,
	 *                                         it is sent verbatim and $body is ignored.
	 * @return array{success: bool, status?: int, data?: array<string, mixed>, error?: string}
	 */
	private function make_request( string $method, string $path, array $body = array(), string $request_id = '', ?string $raw_body = null ): array {
		$token = $this->token_cache->get_token();

		if ( ! $token ) {
			$this->log( 'error', 'PayPal API: missing OAuth token, cannot proceed', array( 'path' => $path ) );
			return array(
				'success' => false,
				'error'   => 'Missing OAuth token',
			);
		}

		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization'                => 'Bearer ' . $token,
				'Content-Type'                 => 'application/json',
				'PayPal-Request-Id'            => '' !== $request_id ? $request_id : wp_generate_uuid4(),
				'PayPal-Partner-Attribution-Id' => 'mbjtechnolabs_sp',
			),
		);

		if ( 'GET' !== $method ) {
			if ( null !== $raw_body ) {
				$args['body'] = $raw_body;
			} else {
				$args['body'] = empty( $body ) ? '{}' : wp_json_encode( $body );
			}
		}

		$this->log( 'debug', 'PayPal API: HTTP request', array( 'method' => $method, 'path' => $path ) );

		$response = wp_remote_request( $this->config->get_base_url() . $path, $args );

		if ( is_wp_error( $response ) ) {
			$this->log(
				'error',
				'PayPal API: HTTP error',
				array(
					'method' => $method,
					'path'   => $path,
					'error'  => $response->get_error_message(),
				)
			);
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $parsed ) ? $parsed : array();

		$result = array(
			'success' => $code >= 200 && $code < 300,
			'status'  => $code,
			'data'    => $data,
		);

		if ( ! $result['success'] ) {
			$result['error'] = $data['message']
				?? $data['error_description']
				?? $data['error']
				?? ( 'HTTP ' . $code );
		}

		return $result;
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
