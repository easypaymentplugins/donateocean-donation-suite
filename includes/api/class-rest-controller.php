<?php
/**
 * REST API controller.
 *
 * Registers all public and authenticated REST endpoints for the
 * Donation Suite plugin, handling order creation, capture, webhooks,
 * subscriptions, and frontend configuration.
 *
 * @package    Donation_Suite
 * @subpackage Api
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Api;

use DonationSuite\Core\ConfigService;
use DonationSuite\Core\CustomFieldsManager;
use DonationSuite\Donation\DonationMeta;
use DonationSuite\Donation\DonationRepositoryInterface;
use DonationSuite\Donation\StateMachine;
use DonationSuite\Logging\Logger;
use DonationSuite\PayPal\PayPalClient;
use DonationSuite\PayPal\WebhookHandler;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestController
 *
 * Handles all REST API routes for the Donation Suite plugin including
 * order creation, capture, webhook processing, subscription management,
 * and frontend configuration delivery.
 *
 * @since 1.0.0
 */
class RestController {

	/**
	 * Donation repository instance.
	 *
	 * @since 1.0.0
	 * @var DonationRepositoryInterface
	 */
	private DonationRepositoryInterface $repository;

	/**
	 * Plugin configuration service.
	 *
	 * @since 1.0.0
	 * @var ConfigService
	 */
	private ConfigService $config;

	/**
	 * PayPal API client.
	 *
	 * @since 1.0.0
	 * @var PayPalClient
	 */
	private PayPalClient $paypal;

	/**
	 * Donation status state machine.
	 *
	 * @since 1.0.0
	 * @var StateMachine
	 */
	private StateMachine $state_machine;

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
	 * @param DonationRepositoryInterface $repository    Donation repository.
	 * @param ConfigService               $config        Plugin configuration service.
	 * @param PayPalClient                $paypal        PayPal API client.
	 * @param StateMachine|null           $state_machine Optional donation status state machine.
	 * @param Logger|null                 $logger        Optional logger instance.
	 */
	public function __construct(
		DonationRepositoryInterface $repository,
		ConfigService $config,
		PayPalClient $paypal,
		?StateMachine $state_machine = null,
		?Logger $logger = null
	) {
		$this->repository    = $repository;
		$this->config        = $config;
		$this->paypal        = $paypal;
		$this->state_machine = $state_machine ?? new StateMachine();

		$settings     = $this->config->get_all();
		$this->logger = $logger ?? new Logger(
			(string) ( $settings['logging_level'] ?? 'error' ),
			! empty( $settings['enable_logging'] )
		);
	}

	/**
	 * Register REST API routes under the donadosu/v1 namespace.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'rest_api_init',
			function () {
				// Public read-only endpoint that returns non-sensitive frontend
				// configuration (PayPal client ID, currency settings, preset
				// amounts, button labels). It must be reachable by anonymous
				// visitors so that the donation form can render before a donor
				// authenticates with PayPal — there are no secrets in the
				// payload and no state-changing operations.
				register_rest_route(
					'donadosu/v1',
					'/config',
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'config' ),
						'permission_callback' => '__return_true',
					)
				);

				register_rest_route(
					'donadosu/v1',
					'/order/create',
					array(
						'methods'             => 'POST',
						'permission_callback' => array( $this, 'check_nonce' ),
						'callback'            => array( $this, 'create_order' ),
					)
				);

				register_rest_route(
					'donadosu/v1',
					'/order/capture',
					array(
						'methods'             => 'POST',
						'permission_callback' => array( $this, 'check_nonce' ),
						'callback'            => array( $this, 'capture_order' ),
					)
				);

				// Public webhook endpoint that PayPal calls server-to-server
				// without WordPress credentials. A nonce or capability check
				// would lock PayPal out, so authenticity is enforced inside
				// the callback by verifying PayPal's transmission signature
				// against the configured webhook ID — see WebhookHandler::handle().
				register_rest_route(
					'donadosu/v1',
					'/webhook',
					array(
						'methods'             => 'POST',
						'permission_callback' => '__return_true',
						'callback'            => array( $this, 'webhook' ),
					)
				);

				register_rest_route(
					'donadosu/v1',
					'/webhook/test',
					array(
						'methods'             => 'POST',
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
						'callback'            => array( $this, 'test_webhook' ),
					)
				);

				// Public return URL that PayPal redirects donors to after they
				// approve a subscription on paypal.com. The donor lands here
				// from PayPal's domain in a top-level browser navigation, so a
				// REST nonce or login is not available. The supplied
				// subscription_id is verified server-side against PayPal's
				// API and matched against an existing donation record before
				// any state is updated.
				register_rest_route(
					'donadosu/v1',
					'/subscription/callback',
					array(
						'methods'             => 'GET',
						'permission_callback' => '__return_true',
						'callback'            => array( $this, 'subscription_callback' ),
					)
				);

				$this->logger->debug( 'REST routes registered' );
			}
		);
	}

	/**
	 * Verify the WordPress REST nonce from the request header or parameter.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return bool True if the nonce is valid, false otherwise.
	 */
	public function check_nonce( \WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'x_wp_nonce' ) ?: $request->get_param( 'nonce' );
		$valid = (bool) wp_verify_nonce( (string) $nonce, 'wp_rest' );

		if ( ! $valid ) {
			$this->logger->warn( 'Nonce verification failed', array( 'route' => $request->get_route() ) );
		}

		return $valid;
	}

	/**
	 * Return the frontend configuration for the donation form.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response Frontend configuration response.
	 */
	public function config(): \WP_REST_Response {
		$this->logger->debug( 'Frontend config requested' );

		return new \WP_REST_Response( $this->config->get_frontend_config() );
	}

	/**
	 * Create a new donation order or subscription.
	 *
	 * Performs honeypot, rate-limit, amount, currency, email, fraud,
	 * fee-coverage, frequency, and idempotency checks before delegating
	 * to PayPal for order or subscription creation.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response The order creation response.
	 */
	public function create_order( \WP_REST_Request $request ): \WP_REST_Response {
		$this->logger->info( 'Create order request received' );

		// Honeypot check.
		if ( null !== $request->get_param( '_confirm_email' ) && '' !== (string) $request->get_param( '_confirm_email' ) ) {
			$this->logger->warn( 'Honeypot field filled — request rejected as bot' );
			return new \WP_REST_Response( array( 'error' => __( 'Invalid request', 'donateocean-donation-suite' ) ), 400 );
		}

		// IP rate limit.
		$ip_hash = md5( sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ) );
		$ip_key  = 'donadosu_rate_' . $ip_hash;
		$hits    = (int) get_transient( $ip_key );

		if ( $hits >= 20 ) {
			$this->logger->warn(
				'IP rate limit exceeded',
				array(
					'ip_hash' => $ip_hash,
					'hits'    => $hits,
				)
			);
			return new \WP_REST_Response( array( 'error' => __( 'Rate limit exceeded', 'donateocean-donation-suite' ) ), 429 );
		}

		set_transient( $ip_key, $hits + 1, 5 * MINUTE_IN_SECONDS );

		// Amount validation.
		$amount_raw = trim( (string) $request->get_param( 'amount' ) );

		if ( ! preg_match( '/^\d+(?:\.\d{1,2})?$/', $amount_raw ) ) {
			$this->logger->warn( 'Invalid amount format', array( 'amount_raw' => $amount_raw ) );
			return new \WP_REST_Response( array( 'error' => __( 'Invalid amount format', 'donateocean-donation-suite' ) ), 400 );
		}

		$amount   = (float) $amount_raw;
		$currency = strtoupper( sanitize_text_field( (string) $request->get_param( 'currency' ) ) );
		$settings = $this->config->get_all();

		if ( $amount < (float) $settings['min_amount'] ) {
			$this->logger->warn(
				'Amount below minimum',
				array(
					'amount' => $amount,
					'min'    => $settings['min_amount'],
				)
			);
			$min_message = sprintf(
				/* translators: %s: formatted minimum donation amount */
				__( 'The minimum donation amount is %s.', 'donateocean-donation-suite' ),
				number_format( (float) $settings['min_amount'], 2 )
			);
			return new \WP_REST_Response(
				array( 'error' => $min_message ),
				400
			);
		}

		if ( $amount > (float) $settings['max_amount'] ) {
			$this->logger->warn(
				'Amount exceeds maximum',
				array(
					'amount' => $amount,
					'max'    => $settings['max_amount'],
				)
			);
			$max_message = sprintf(
				/* translators: %s: formatted maximum donation amount */
				__( 'The maximum donation amount is %s.', 'donateocean-donation-suite' ),
				number_format( (float) $settings['max_amount'], 2 )
			);
			return new \WP_REST_Response(
				array( 'error' => $max_message ),
				400
			);
		}

		// Currency validation.
		if ( ! in_array( $currency, (array) $settings['allowed_currencies'], true ) ) {
			$this->logger->warn(
				'Currency not allowed',
				array(
					'currency' => $currency,
					'allowed'  => $settings['allowed_currencies'],
				)
			);
			return new \WP_REST_Response( array( 'error' => __( 'Invalid currency', 'donateocean-donation-suite' ) ), 400 );
		}

		// Email rate limit.
		$donor_email = sanitize_email( (string) $request->get_param( 'donor_email' ) );

		if ( '' !== $donor_email ) {
			$email_key     = 'donadosu_email_rate_' . md5( $donor_email );
			$email_hits    = (int) get_transient( $email_key );
			$max_per_email = max( 1, (int) ( $settings['fraud_max_per_email'] ?? 5 ) );

			if ( $email_hits >= $max_per_email ) {
				$this->logger->warn(
					'Email rate limit exceeded',
					array(
						'donor_email' => $donor_email,
						'hits'        => $email_hits,
						'max'         => $max_per_email,
					)
				);
				return new \WP_REST_Response(
					array( 'error' => __( 'Too many donations from this email address. Please contact us if you need help.', 'donateocean-donation-suite' ) ),
					429
				);
			}

			set_transient( $email_key, $email_hits + 1, DAY_IN_SECONDS );
		}

		// Fraud flag.
		$fraud_threshold = (float) ( $settings['fraud_flag_threshold'] ?? 5000 );
		$fraud_flag      = $amount >= $fraud_threshold ? 1 : 0;

		if ( $fraud_flag ) {
			$this->logger->warn(
				'Donation flagged for fraud review (high value)',
				array(
					'amount'    => $amount,
					'threshold' => $fraud_threshold,
				)
			);
		}

		// Fee calculation.
		$fee_covered  = filter_var( $request->get_param( 'fee_covered' ), FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
		$fee_percent  = (float) ( $settings['fee_percentage'] ?? 2.9 ) / 100.0;
		$fee_amount   = $fee_covered ? round( ( $amount + 0.30 ) / ( 1.0 - $fee_percent ) - $amount, 2 ) : 0.0;
		$gross_amount = $fee_covered ? round( $amount + $fee_amount, 2 ) : $amount;

		// Frequency validation.
		$donation_frequency = sanitize_text_field( (string) $request->get_param( 'donation_frequency' ) );
		$donation_frequency = in_array( $donation_frequency, array( 'one_time', 'monthly', 'annual' ), true )
			? $donation_frequency
			: 'one_time';

		// Payment-method hint from the frontend (paypal|card). The PayPal Smart
		// Button flow leaves it empty / 'paypal'; the Card Fields flow sets it
		// to 'card' so we can pick the right recurring path below.
		$payment_method = sanitize_text_field( (string) $request->get_param( 'payment_method' ) );
		$payment_method = 'card' === $payment_method ? 'card' : 'paypal';

		// Custom fields: initialise registration and validate required fields
		// BEFORE any PayPal calls so we never create an order for a request
		// that is going to be rejected server-side.
		CustomFieldsManager::init();
		$missing_custom_fields = CustomFieldsManager::validate_required( $request );

		if ( ! empty( $missing_custom_fields ) ) {
			$this->logger->warn(
				'Required custom fields missing',
				array( 'missing' => $missing_custom_fields )
			);
			return new \WP_REST_Response(
				array(
					'error'          => __( 'Required custom fields are missing.', 'donateocean-donation-suite' ),
					'missing_fields' => $missing_custom_fields,
				),
				400
			);
		}

		// Idempotency: validate format and atomically claim a processing slot.
		$idempotency_key = sanitize_text_field( (string) $request->get_param( 'idempotency_key' ) );

		if ( '' !== $idempotency_key && ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $idempotency_key ) ) {
			return new \WP_REST_Response( array( 'error' => __( 'Invalid idempotency_key format', 'donateocean-donation-suite' ) ), 400 );
		}

		$idemp_cache_key = '';
		$idemp_lock_key  = '';

		if ( '' !== $idempotency_key ) {
			$idemp_cache_key = 'donadosu_create_idemp_' . md5( $idempotency_key );
			$idemp_lock_key  = 'donadosu_create_lock_' . md5( $idempotency_key );

			// Fast path: an earlier request already finished and stored the donation ID.
			$existing_donation_id = (int) get_transient( $idemp_cache_key );
			if ( $existing_donation_id > 0 ) {
				$existing_order = (string) get_post_meta( $existing_donation_id, DonationMeta::ORDER_ID, true );
				if ( '' !== $existing_order ) {
					$this->logger->info(
						'Idempotent create order — returning existing order',
						array(
							'order_id'    => $existing_order,
							'donation_id' => $existing_donation_id,
						)
					);
					return new \WP_REST_Response(
						array(
							'orderID'    => $existing_order,
							'donationId' => $existing_donation_id,
							'idempotent' => true,
						)
					);
				}
			}

			// Atomic claim: wp_cache_add returns false if another worker already holds the lock.
			if ( ! wp_cache_add( $idemp_lock_key, getmypid(), 'donateocean-donation-suite', 60 ) ) {
				// Another worker is creating this donation right now. Wait briefly for it.
				for ( $i = 0; $i < 10; $i++ ) {
					usleep( 500000 );
					$existing_donation_id = (int) get_transient( $idemp_cache_key );
					if ( $existing_donation_id > 0 ) {
						$existing_order = (string) get_post_meta( $existing_donation_id, DonationMeta::ORDER_ID, true );
						if ( '' !== $existing_order ) {
							return new \WP_REST_Response(
								array(
									'orderID'    => $existing_order,
									'donationId' => $existing_donation_id,
									'idempotent' => true,
								)
							);
						}
					}
				}
				$this->logger->warn( 'Idempotency lock contention — duplicate request rejected', array( 'key' => $idempotency_key ) );
				return new \WP_REST_Response( array( 'error' => 'duplicate_in_progress' ), 409 );
			}
		}

		$this->logger->info(
			'Processing donation',
			array(
				'amount'      => $amount,
				'gross'       => $gross_amount,
				'currency'    => $currency,
				'frequency'   => $donation_frequency,
				'fee_covered' => $fee_covered,
				'donor_email' => $donor_email,
			)
		);

		// Recurring donation handling.
		if ( 'one_time' !== $donation_frequency ) {
			if ( empty( $settings['enable_recurring'] ) ) {
				$this->logger->warn(
					'Recurring donation rejected — feature disabled',
					array( 'frequency' => $donation_frequency )
				);
				return new \WP_REST_Response( array( 'error' => __( 'Recurring donations are not enabled', 'donateocean-donation-suite' ) ), 400 );
			}

			// PayPal Smart Button recurring uses the legacy v1 Subscriptions API
			// (which works for PayPal wallet but not for vaulted cards). Card
			// Fields recurring falls through to the regular Orders v2 path below
			// with vault.store_in_vault=ON_SUCCESS so the first capture vaults
			// the card and a cron schedules merchant-initiated renewals.
			if ( 'card' !== $payment_method ) {
				return $this->create_subscription_order(
					$request,
					$amount,
					$gross_amount,
					$currency,
					$donation_frequency,
					$fee_covered,
					$fee_amount,
					$fraud_flag,
					$idempotency_key
				);
			}
		}

		// Build the Orders v2 payload. Recurring + card adds vault attributes
		// so PayPal stores the card on the merchant's behalf during the first
		// successful capture; the resulting vault.id is what powers MIT renewals.
		$order_payload = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'amount' => array(
						'currency_code' => $currency,
						'value'         => number_format( $gross_amount, 2, '.', '' ),
					),
				),
			),
		);

		$is_card_recurring = ( 'card' === $payment_method && 'one_time' !== $donation_frequency );

		if ( $is_card_recurring ) {
			$order_payload['payment_source'] = array(
				'card' => array(
					'attributes' => array(
						'vault'             => array(
							'store_in_vault' => 'ON_SUCCESS',
							'usage_type'     => 'MERCHANT',
							'customer_type'  => 'CONSUMER',
						),
						'verification' => array(
							// Run 3DS only if the issuer requires it for the
							// first charge; subsequent MIT renewals are exempt.
							'method' => 'SCA_WHEN_REQUIRED',
						),
					),
				),
			);
		}

		// One-time / first-charge order via PayPal. Pass the idempotency key so
		// PayPal-Request-Id is deterministic and retried network calls don't
		// create duplicate orders.
		$pp_result = $this->paypal->create_order( $order_payload, $idempotency_key );

		if ( empty( $pp_result['success'] ) ) {
			if ( '' !== $idemp_lock_key ) {
				wp_cache_delete( $idemp_lock_key, 'donateocean-donation-suite' );
			}
			$this->logger->error(
				'PayPal create order failed',
				array(
					'error'    => $pp_result['error'] ?? '',
					'amount'   => $gross_amount,
					'currency' => $currency,
				)
			);
			return new \WP_REST_Response(
				array( 'error' => $pp_result['error'] ?? __( 'PayPal create order failed', 'donateocean-donation-suite' ) ),
				500
			);
		}

		$order_id = (string) ( $pp_result['data']['id'] ?? '' );
		if ( '' === $order_id ) {
			if ( '' !== $idemp_lock_key ) {
				wp_cache_delete( $idemp_lock_key, 'donateocean-donation-suite' );
			}
			$this->logger->error( 'PayPal returned success but no order ID', array( 'response' => $pp_result ) );
			return new \WP_REST_Response( array( 'error' => __( 'PayPal order creation failed — no order ID returned', 'donateocean-donation-suite' ) ), 500 );
		}

		$meta = $this->build_meta(
			$request,
			$amount,
			$currency,
			$donation_frequency,
			$fee_covered,
			$fee_amount,
			$gross_amount,
			$fraud_flag
		);

		// Vault-recurring orders also need the subscription cycle + return page so
		// the renewal cron and the post-capture redirect logic know how to behave.
		if ( $is_card_recurring ) {
			$meta[ DonationMeta::SUBSCRIPTION_CYCLE ]       = $donation_frequency;
			$meta[ DonationMeta::SUBSCRIPTION_STATUS ]      = 'pending';
			$meta[ DonationMeta::SUBSCRIPTION_RETURN_PAGE ] = esc_url_raw( (string) $request->get_param( 'return_page' ) );
			$meta[ DonationMeta::PAYMENT_SOURCE ]           = 'paypal';
		}

		$post_id  = $this->repository->create_or_update_by_order_id( $order_id, $meta );

		$this->repository->append_history(
			$post_id,
			'donadosu_created',
			array(
				'order'         => $order_id,
				'recurring_card' => $is_card_recurring ? 1 : 0,
			)
		);

		if ( '' !== $idempotency_key ) {
			// Persist the real post ID for the idempotency window, then release the lock.
			set_transient( $idemp_cache_key, $post_id, HOUR_IN_SECONDS );
			wp_cache_delete( $idemp_lock_key, 'donateocean-donation-suite' );
		}

		$this->logger->info(
			'Order created successfully',
			array(
				'order_id'    => $order_id,
				'donation_id' => $post_id,
				'amount'      => $gross_amount,
				'currency'    => $currency,
			)
		);

		return new \WP_REST_Response(
			array(
				'orderID'    => $order_id,
				'donationId' => $post_id,
			)
		);
	}

	/**
	 * Build the donation meta array from request parameters.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request      The incoming REST request.
	 * @param float            $amount       The net donation amount.
	 * @param string           $currency     The currency code.
	 * @param string           $frequency    The donation frequency.
	 * @param int              $fee_covered  Whether the donor opted to cover fees.
	 * @param float            $fee_amount   The calculated fee amount.
	 * @param float            $gross_amount The gross amount including fees.
	 * @param int              $fraud_flag   Whether the donation is flagged for fraud.
	 * @return array<string, mixed> The donation meta array.
	 */
	private function build_meta(
		\WP_REST_Request $request,
		float $amount,
		string $currency,
		string $frequency,
		int $fee_covered,
		float $fee_amount,
		float $gross_amount,
		int $fraud_flag
	): array {
		// Ensure custom field registrations are available. init() is a no-op
		// on subsequent calls within the same request.
		CustomFieldsManager::init();

		// Build base donation meta.
		$meta = array(
			DonationMeta::ENV                  => $this->config->is_sandbox() ? 'sandbox' : 'live',
			DonationMeta::AMOUNT               => $amount,
			DonationMeta::CURRENCY             => $currency,
			DonationMeta::LOCALE               => sanitize_text_field( (string) $request->get_param( 'locale' ) ),
			DonationMeta::DONOR_EMAIL          => sanitize_email( (string) $request->get_param( 'donor_email' ) ),
			DonationMeta::DONOR_NAME           => sanitize_text_field( (string) $request->get_param( 'donor_name' ) ),
			DonationMeta::DONOR_PHONE          => sanitize_text_field( (string) $request->get_param( 'donor_phone' ) ),
			DonationMeta::DONOR_COMPANY        => sanitize_text_field( (string) $request->get_param( 'donor_company' ) ),
			DonationMeta::DONOR_ADDRESS        => sanitize_text_field( (string) $request->get_param( 'donor_address' ) ),
			DonationMeta::DONOR_CITY           => sanitize_text_field( (string) $request->get_param( 'donor_city' ) ),
			DonationMeta::DONOR_POSTAL         => sanitize_text_field( (string) $request->get_param( 'donor_postal' ) ),
			DonationMeta::DONATION_FREQUENCY   => $frequency,
			DonationMeta::CAMPAIGN             => sanitize_text_field( (string) $request->get_param( 'campaign' ) ),
			DonationMeta::PURPOSE              => sanitize_text_field( (string) $request->get_param( 'purpose' ) ),
			DonationMeta::DONOR_MESSAGE        => sanitize_textarea_field( (string) $request->get_param( 'donor_message' ) ),
			DonationMeta::FEE_COVERED          => $fee_covered,
			DonationMeta::FEE_AMOUNT           => $fee_amount,
			DonationMeta::GROSS_AMOUNT         => $gross_amount,
			DonationMeta::IS_TRIBUTE           => filter_var( $request->get_param( 'is_tribute' ), FILTER_VALIDATE_BOOLEAN ) ? 1 : 0,
			DonationMeta::TRIBUTE_TYPE         => sanitize_text_field( (string) $request->get_param( 'tribute_type' ) ),
			DonationMeta::TRIBUTE_NAME         => sanitize_text_field( (string) $request->get_param( 'tribute_name' ) ),
			DonationMeta::TRIBUTE_NOTIFY_EMAIL => sanitize_email( (string) $request->get_param( 'tribute_notify_email' ) ),
			DonationMeta::IS_ANONYMOUS         => filter_var( $request->get_param( 'is_anonymous' ), FILTER_VALIDATE_BOOLEAN ) ? 1 : 0,
			DonationMeta::GIVING_LEVEL         => sanitize_text_field( (string) $request->get_param( 'giving_level' ) ),
			DonationMeta::FRAUD_FLAG           => $fraud_flag,
		);

		// Extract and store custom field values.
		$custom_field_values = CustomFieldsManager::get_custom_field_values( $request );
		if ( ! empty( $custom_field_values ) ) {
			$meta['_donadosu_custom_fields'] = $custom_field_values;
		}

		/**
		 * Filters the complete donation meta array.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $meta     The meta array to be stored.
		 * @param \WP_REST_Request     $request  The REST request object.
		 */
		return apply_filters( 'donadosu_donation_meta', $meta, $request );
	}

	/**
	 * Create a subscription order for recurring donations.
	 *
	 * Creates a PayPal billing plan and subscription, then stores the
	 * donation post with subscription metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request        The incoming REST request.
	 * @param float            $amount         The net donation amount.
	 * @param float            $gross_amount   The gross amount including fees.
	 * @param string           $currency       The currency code.
	 * @param string           $frequency      The donation frequency (monthly|annual).
	 * @param int              $fee_covered    Whether the donor opted to cover fees.
	 * @param float            $fee_amount     The calculated fee amount.
	 * @param int              $fraud_flag     Whether the donation is flagged for fraud.
	 * @param string           $idempotency_key The idempotency key for deduplication.
	 * @return \WP_REST_Response The subscription creation response.
	 */
	private function create_subscription_order(
		\WP_REST_Request $request,
		float $amount,
		float $gross_amount,
		string $currency,
		string $frequency,
		int $fee_covered,
		float $fee_amount,
		int $fraud_flag,
		string $idempotency_key = ''
	): \WP_REST_Response {
		$this->logger->info(
			'Creating subscription order',
			array(
				'amount'    => $gross_amount,
				'currency'  => $currency,
				'frequency' => $frequency,
			)
		);

		$interval  = 'annual' === $frequency ? 'YEAR' : 'MONTH';
		$plan_name = sprintf(
			'%s %s Donation – %s %s',
			'annual' === $frequency ? 'Annual' : 'Monthly',
			$currency,
			number_format( $gross_amount, 2 ),
			$currency
		);

		$plan_result = $this->paypal->create_subscription_plan( $plan_name, $gross_amount, $currency, $interval );

		if ( empty( $plan_result['success'] ) ) {
			$this->logger->error(
				'Subscription plan creation failed',
				array(
					'error'    => $plan_result['error'] ?? '',
					'amount'   => $gross_amount,
					'currency' => $currency,
				)
			);
			return new \WP_REST_Response( array( 'error' => 'Could not create subscription plan' ), 500 );
		}

		$plan_id    = (string) ( $plan_result['data']['id'] ?? '' );
		$return_url = rest_url( 'donadosu/v1/subscription/callback' );
		$cancel_url = add_query_arg( 'donadosu_sub_cancelled', '1', home_url( '/' ) );

		$sub_result = $this->paypal->create_subscription( $plan_id, $return_url, $cancel_url );

		if ( empty( $sub_result['success'] ) ) {
			$this->logger->error(
				'Subscription creation failed',
				array(
					'plan_id' => $plan_id,
					'error'   => $sub_result['error'] ?? '',
				)
			);
			return new \WP_REST_Response( array( 'error' => 'Could not create subscription' ), 500 );
		}

		$subscription_id = (string) ( $sub_result['data']['id'] ?? '' );
		$approve_url     = '';

		foreach ( (array) ( $sub_result['data']['links'] ?? array() ) as $link ) {
			if ( isset( $link['rel'] ) && 'approve' === $link['rel'] ) {
				$approve_url = (string) ( $link['href'] ?? '' );
				break;
			}
		}

		if ( '' === $approve_url ) {
			$this->logger->error(
				'PayPal did not return subscription approval URL',
				array( 'subscription_id' => $subscription_id )
			);
			return new \WP_REST_Response(
				array( 'error' => 'PayPal did not return a subscription approval URL' ),
				500
			);
		}

		$meta = $this->build_meta( $request, $amount, $currency, $frequency, $fee_covered, $fee_amount, $gross_amount, $fraud_flag );

		$meta[ DonationMeta::SUBSCRIPTION_ID ]          = $subscription_id;
		$meta[ DonationMeta::SUBSCRIPTION_PLAN_ID ]     = $plan_id;
		$meta[ DonationMeta::SUBSCRIPTION_CYCLE ]       = $frequency;
		$meta[ DonationMeta::SUBSCRIPTION_STATUS ]      = 'pending';
		$meta[ DonationMeta::SUBSCRIPTION_RETURN_PAGE ] = esc_url_raw( (string) $request->get_param( 'return_page' ) );

		// Create donation post without storing subscription ID in ORDER_ID field.
		// Subscriptions don't have PayPal orders, so ORDER_ID should remain empty.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'donadosu_donation',
				'post_status' => 'donadosu_created',
				'post_title'  => sprintf( 'Donation %s', gmdate( 'Y-m-d H:i:s' ) ),
			)
		);

		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			$this->logger->error(
				'Failed to create donation post for subscription',
				array( 'subscription_id' => $subscription_id )
			);
			return new \WP_REST_Response( array( 'error' => 'Could not create donation' ), 500 );
		}

		$post_id = (int) $post_id;
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		$this->repository->append_history(
			$post_id,
			'donadosu_created',
			array(
				'subscription_id' => $subscription_id,
				'plan_id'         => $plan_id,
			)
		);

		if ( '' !== $idempotency_key ) {
			set_transient( 'donadosu_create_idemp_' . md5( $idempotency_key ), $post_id, HOUR_IN_SECONDS );
		}

		$this->logger->info(
			'Subscription order created successfully',
			array(
				'subscription_id' => $subscription_id,
				'plan_id'         => $plan_id,
				'donation_id'     => $post_id,
				'amount'          => $gross_amount,
				'currency'        => $currency,
				'frequency'       => $frequency,
			)
		);

		return new \WP_REST_Response(
			array(
				'subscriptionID' => $subscription_id,
				'approveUrl'     => $approve_url,
				'donationId'     => $post_id,
				'isSubscription' => true,
			)
		);
	}

	/**
	 * Capture a previously created PayPal order.
	 *
	 * Finds the donation by order ID, checks whether it has already been
	 * captured, and if not sends the capture request to PayPal.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response The capture result response.
	 */
	public function capture_order( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id = sanitize_text_field( (string) $request->get_param( 'order_id' ) );

		$this->logger->info( 'Capture order request received', array( 'order_id' => $order_id ) );

		$post_id = $this->repository->find_by_order_id( $order_id );

		if ( ! $post_id ) {
			$this->logger->warn( 'Capture failed — donation not found', array( 'order_id' => $order_id ) );
			return new \WP_REST_Response( array( 'error' => 'Donation not found' ), 404 );
		}

		$status = (string) get_post_status( $post_id );

		if ( in_array( $status, array( 'donadosu_captured', 'donadosu_completed', 'donadosu_sub_active' ), true ) ) {
			$this->logger->info(
				'Capture skipped — already captured/completed',
				array(
					'order_id' => $order_id,
					'post_id'  => $post_id,
					'status'   => $status,
				)
			);
			return new \WP_REST_Response(
				array(
					'ok'             => true,
					'status'         => $status,
					'isSubscription' => 'donadosu_sub_active' === $status,
				)
			);
		}

		$capture_idempotency_key = sanitize_text_field( (string) $request->get_param( 'idempotency_key' ) );

		if ( '' !== $capture_idempotency_key && get_transient( 'donadosu_capture_idemp_' . md5( $capture_idempotency_key ) ) ) {
			$this->logger->info(
				'Idempotent capture — returning cached result',
				array(
					'order_id' => $order_id,
					'post_id'  => $post_id,
				)
			);
			return new \WP_REST_Response(
				array(
					'ok'         => true,
					'status'     => (string) get_post_status( $post_id ),
					'idempotent' => true,
				)
			);
		}

		$this->logger->info(
			'Sending capture request to PayPal',
			array(
				'order_id' => $order_id,
				'post_id'  => $post_id,
			)
		);

		$result = $this->paypal->capture_order( $order_id );

		if ( empty( $result['success'] ) ) {
			$this->apply_status_transition( $post_id, 'donadosu_failed', array( 'source' => 'capture_order' ) );
			$this->logger->error(
				'PayPal order capture failed',
				array(
					'order_id'      => $order_id,
					'post_id'       => $post_id,
					'paypal_status' => $result['status'] ?? '',
					'paypal_error'  => $result['data'] ?? $result['error'] ?? '',
				)
			);
			return new \WP_REST_Response( array( 'error' => 'Capture failed' ), 500 );
		}

		$capture     = $result['data']['purchase_units'][0]['payments']['captures'][0] ?? array();
		$capture_id  = (string) ( $capture['id'] ?? '' );
		$cap_value   = (float) ( $capture['amount']['value'] ?? 0.0 );
		$cap_curr    = strtoupper( (string) ( $capture['amount']['currency_code'] ?? '' ) );
		$expected_v  = (float) get_post_meta( $post_id, DonationMeta::GROSS_AMOUNT, true );
		$expected_c  = strtoupper( (string) get_post_meta( $post_id, DonationMeta::CURRENCY, true ) );

		// CRITICAL: server-side verification of captured amount + currency.
		// Reject and immediately refund if PayPal captured anything other than what we recorded.
		if ( abs( $cap_value - $expected_v ) > 0.005 || $cap_curr !== $expected_c ) {
			$this->logger->error(
				'Capture amount/currency mismatch — auto-refunding',
				array(
					'post_id'          => $post_id,
					'order_id'         => $order_id,
					'expected_amount'  => $expected_v,
					'expected_currency' => $expected_c,
					'captured_amount'  => $cap_value,
					'captured_currency' => $cap_curr,
				)
			);
			if ( '' !== $capture_id ) {
				$refund_result = $this->paypal->refund_capture( $capture_id, $cap_value, $cap_curr );
				if ( empty( $refund_result['success'] ) ) {
					$this->logger->error(
						'Auto-refund failed after amount mismatch — manual intervention required',
						array(
							'post_id'    => $post_id,
							'capture_id' => $capture_id,
							'amount'     => $cap_value,
							'currency'   => $cap_curr,
							'error'      => $refund_result['error'] ?? '',
						)
					);
				}
			}
			$this->apply_status_transition(
				$post_id,
				'donadosu_failed',
				array( 'reason' => 'amount_mismatch', 'capture_id' => $capture_id )
			);
			return new \WP_REST_Response( array( 'error' => 'amount_mismatch' ), 400 );
		}

		update_post_meta( $post_id, DonationMeta::CAPTURE_ID, $capture_id );

		// Backfill donor details from PayPal payer data when the donor did
		// not provide them during payment (e.g. donor_fields disabled).
		$this->backfill_donor_from_paypal( $post_id, $result['data'] ?? array() );

		// Vault-recurring detection: when the order was created with
		// payment_source.card.attributes.vault.store_in_vault=ON_SUCCESS, PayPal
		// returns the resulting payment_token in the capture response.
		// Persist it so the renewal cron can charge the card later, and route
		// the donation post into the subscription state instead of the
		// one-time captured state.
		$vault       = $result['data']['payment_source']['card']['attributes']['vault'] ?? array();
		$vault_id    = sanitize_text_field( (string) ( $vault['id'] ?? '' ) );
		$customer_id = sanitize_text_field( (string) ( $vault['customer']['id'] ?? '' ) );
		$cycle       = (string) get_post_meta( $post_id, DonationMeta::SUBSCRIPTION_CYCLE, true );
		$is_recurring_capture = '' !== $vault_id && '' !== $cycle;

		if ( $is_recurring_capture ) {
			update_post_meta( $post_id, DonationMeta::VAULT_PAYMENT_TOKEN_ID, $vault_id );
			if ( '' !== $customer_id ) {
				update_post_meta( $post_id, DonationMeta::VAULT_CUSTOMER_ID, $customer_id );
			}
			update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, 'active' );

			// Schedule the next billing date so the cron picks it up.
			$next_iso = $this->compute_next_billing_iso( $cycle );
			if ( '' !== $next_iso ) {
				update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_NEXT_BILLING, $next_iso );
				update_post_meta( $post_id, DonationMeta::NEXT_BILLING_TIME, $next_iso );
			}

			$this->apply_status_transition(
				$post_id,
				'donadosu_sub_active',
				array(
					'capture_id' => $capture_id,
					'source'     => 'capture_order_vault',
					'vault_id'   => $vault_id,
				)
			);

			// Fire the donor-receipt action for the first charge.
			do_action(
				'donadosu_donation_completed',
				$post_id,
				array(
					'source'     => 'capture_order_vault',
					'capture_id' => $capture_id,
					'vault_id'   => $vault_id,
				)
			);
		} else {
			$this->apply_status_transition(
				$post_id,
				'donadosu_captured',
				array(
					'capture_id' => $capture_id,
					'source'     => 'capture_order',
				)
			);
		}

		if ( '' !== $capture_idempotency_key ) {
			set_transient( 'donadosu_capture_idemp_' . md5( $capture_idempotency_key ), 1, HOUR_IN_SECONDS );
		}

		$this->logger->info(
			'Order captured successfully',
			array(
				'order_id'    => $order_id,
				'post_id'     => $post_id,
				'capture_id'  => $capture_id,
				'is_recurring' => $is_recurring_capture,
				'vault_id'    => $vault_id,
			)
		);

		return new \WP_REST_Response(
			array(
				'ok'             => true,
				'status'         => $is_recurring_capture ? 'donadosu_sub_active' : 'donadosu_captured',
				'isSubscription' => $is_recurring_capture,
			)
		);
	}

	/**
	 * Compute the next ISO 8601 billing time for a subscription cycle.
	 *
	 * @since 1.0.0
	 *
	 * @param string $cycle Subscription cycle (monthly|annual).
	 * @return string ISO 8601 timestamp, or empty string for unknown cycles.
	 */
	private function compute_next_billing_iso( string $cycle ): string {
		$now = time();
		switch ( $cycle ) {
			case 'monthly':
				$next = strtotime( '+1 month', $now );
				break;
			case 'annual':
			case 'yearly':
				$next = strtotime( '+1 year', $now );
				break;
			default:
				return '';
		}

		return false === $next ? '' : gmdate( 'c', $next );
	}

	/**
	 * Backfill donor details from PayPal payer/shipping data.
	 *
	 * When a donor completes payment without filling in the optional donor
	 * fields on the form, this method extracts their name, email, and
	 * billing address from the PayPal order/capture response and stores
	 * them as donation meta — but only for fields that are still empty.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id     Donation post ID.
	 * @param array $paypal_data PayPal order or capture response data.
	 * @return void
	 */
	private function backfill_donor_from_paypal( int $post_id, array $paypal_data ): void {
		$payer    = $paypal_data['payer'] ?? array();
		$shipping = $paypal_data['purchase_units'][0]['shipping'] ?? array();

		// Build a map of PayPal-sourced values.
		$given_name  = sanitize_text_field( (string) ( $payer['name']['given_name'] ?? '' ) );
		$surname     = sanitize_text_field( (string) ( $payer['name']['surname'] ?? '' ) );
		$payer_name  = trim( $given_name . ' ' . $surname );
		$payer_email = sanitize_email( (string) ( $payer['email_address'] ?? '' ) );

		// Shipping address (PayPal's billing address for digital goods).
		$address = $shipping['address'] ?? $payer['address'] ?? array();
		$payer_address_line = sanitize_text_field( (string) ( $address['address_line_1'] ?? '' ) );
		$address_line_2     = sanitize_text_field( (string) ( $address['address_line_2'] ?? '' ) );
		if ( '' !== $address_line_2 ) {
			$payer_address_line .= ( '' !== $payer_address_line ? ', ' : '' ) . $address_line_2;
		}
		$payer_city   = sanitize_text_field( (string) ( $address['admin_area_2'] ?? '' ) );
		$payer_postal = sanitize_text_field( (string) ( $address['postal_code'] ?? '' ) );

		// Shipping name can also provide the full name.
		if ( '' === $payer_name ) {
			$payer_name = sanitize_text_field( (string) ( $shipping['name']['full_name'] ?? '' ) );
		}

		$backfill = array(
			DonationMeta::DONOR_NAME    => $payer_name,
			DonationMeta::DONOR_EMAIL   => $payer_email,
			DonationMeta::DONOR_ADDRESS => $payer_address_line,
			DonationMeta::DONOR_CITY    => $payer_city,
			DonationMeta::DONOR_POSTAL  => $payer_postal,
		);

		$filled = array();
		foreach ( $backfill as $meta_key => $paypal_value ) {
			if ( '' === $paypal_value ) {
				continue;
			}
			$existing = (string) get_post_meta( $post_id, $meta_key, true );
			if ( '' !== $existing ) {
				continue;
			}
			update_post_meta( $post_id, $meta_key, $paypal_value );
			$filled[] = $meta_key;
		}

		if ( ! empty( $filled ) ) {
			$this->logger->info(
				'Donor details backfilled from PayPal payer data',
				array(
					'post_id'       => $post_id,
					'filled_fields' => $filled,
				)
			);
		}
	}

	/**
	 * Handle the subscription approval redirect callback from PayPal.
	 *
	 * Retrieves the subscription status from PayPal and updates the
	 * donation post accordingly.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response The subscription callback response.
	 */
	public function subscription_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$subscription_id = sanitize_text_field( (string) $request->get_param( 'subscription_id' ) );

		$this->logger->info( 'Subscription callback received', array( 'subscription_id' => $subscription_id ) );

		if ( ! $subscription_id ) {
			$this->logger->warn( 'Subscription callback missing subscription_id' );
			return new \WP_REST_Response( array( 'error' => 'Missing subscription_id' ), 400 );
		}

		$post_id = $this->repository->find_by_subscription_id( $subscription_id );

		if ( ! $post_id ) {
			$this->logger->warn(
				'Subscription callback — subscription not found',
				array( 'subscription_id' => $subscription_id )
			);
			return new \WP_REST_Response( array( 'error' => 'Subscription not found' ), 404 );
		}

		$sub = $this->paypal->get_subscription( $subscription_id );

		if ( ! empty( $sub['success'] ) ) {
			$sub_status = strtoupper( (string) ( $sub['data']['status'] ?? '' ) );
			update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, strtolower( $sub_status ) );

			$this->logger->info(
				'Subscription status retrieved from PayPal',
				array(
					'subscription_id' => $subscription_id,
					'post_id'         => $post_id,
					'status'          => $sub_status,
				)
			);

			if ( 'ACTIVE' === $sub_status ) {
				$next_billing = (string) ( $sub['data']['billing_info']['next_billing_time'] ?? '' );

				if ( $next_billing ) {
					update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_NEXT_BILLING, $next_billing );
				}

				$this->apply_status_transition(
					$post_id,
					'donadosu_sub_active',
					array( 'source' => 'subscription_callback' )
				);

				do_action(
					'donadosu_donation_completed',
					$post_id,
					array(
						'source'          => 'subscription_callback',
						'subscription_id' => $subscription_id,
					)
				);

				$this->logger->info(
					'Subscription activated successfully',
					array(
						'subscription_id' => $subscription_id,
						'post_id'         => $post_id,
						'next_billing'    => $next_billing,
					)
				);
			}
		} else {
			$this->logger->warn(
				'Failed to retrieve subscription status from PayPal',
				array(
					'subscription_id' => $subscription_id,
					'post_id'         => $post_id,
				)
			);
		}

		// Redirect the donor back to the page they donated from (or the
		// configured thank-you URL).  Fall back to the home page when
		// no return page was stored with the donation.
		$return_page  = (string) get_post_meta( $post_id, DonationMeta::SUBSCRIPTION_RETURN_PAGE, true );
		$redirect_url = '' !== $return_page
			? add_query_arg( 'donadosu_subscription_confirmed', '1', $return_page )
			: home_url( '/?donadosu_subscription_confirmed=1' );

		// Validate redirect URL against this site's origin to prevent open redirects.
		$redirect_url = wp_validate_redirect( $redirect_url, home_url( '/?donadosu_subscription_confirmed=1' ) );

		// Use wp_safe_redirect + exit since WP_REST_Response does not support 302 redirects.
		wp_safe_redirect( esc_url_raw( $redirect_url ), 302 );
		exit;
	}

	/**
	 * Handle an incoming PayPal webhook event.
	 *
	 * Delegates to the WebhookHandler for signature verification,
	 * deduplication, and event routing.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response The webhook processing response.
	 */
	public function webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$this->logger->info( 'Webhook request received' );

		$handler = new WebhookHandler(
			$this->repository,
			$this->config,
			$this->paypal,
			$this->state_machine,
			$this->logger
		);

		$result = $handler->handle( $request->get_body(), $request->get_headers() );

		$this->logger->info(
			'Webhook processed',
			array(
				'response_status' => $result['status'],
				'response_body'   => $result['body'],
			)
		);

		return new \WP_REST_Response( $result['body'], $result['status'] );
	}

	/**
	 * Handle a test webhook request for validation purposes.
	 *
	 * Generates a synthetic PayPal webhook event payload and processes it
	 * through the webhook handler. Useful for admins to validate webhook
	 * setup without live transactions.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response The test webhook processing response.
	 */
	public function test_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$this->logger->info( 'Test webhook request received' );

		// Generate a test webhook payload
		$test_payload = $this->generate_test_webhook_payload();
		$test_body    = wp_json_encode( $test_payload );

		// Generate test webhook headers (signature will be verified against webhook_id).
		// WebhookHandler::handle() accepts either hyphenated or underscored keys.
		$test_headers = array(
			'paypal-transmission-id'   => array( wp_generate_uuid4() ),
			'paypal-transmission-time' => array( gmdate( 'Y-m-d\TH:i:s\Z' ) ),
			'paypal-cert-url'          => array( 'https://api.paypal.com/cert-test' ),
			'paypal-auth-algo'         => array( 'SHA256withRSA' ),
			// Note: Real signature verification will be skipped for test webhooks
			'paypal-transmission-sig'  => array( 'test-signature-' . wp_generate_uuid4() ),
		);

		$handler = new WebhookHandler(
			$this->repository,
			$this->config,
			$this->paypal,
			$this->state_machine,
			$this->logger
		);

		// Process the test payload through the handler
		// Note: Signature verification will fail for test webhooks, but the handler
		// will log the attempt and return a proper response.
		$result = $handler->handle( $test_body, $test_headers );

		$this->logger->info(
			'Test webhook processed',
			array(
				'response_status' => $result['status'],
				'response_body'   => $result['body'],
			)
		);

		// Return success message with test info
		return new \WP_REST_Response(
			array(
				'success'      => true,
				'message'      => 'Test webhook sent successfully. Check the webhook health status in settings.',
				'webhook_id'   => $test_payload['id'],
				'event_type'   => $test_payload['event_type'],
				'timestamp'    => $test_payload['create_time'],
			),
			200
		);
	}

	/**
	 * Generate a synthetic PayPal webhook payload for testing.
	 *
	 * Creates a realistic CHECKOUT.ORDER.COMPLETED event payload
	 * that can be used to validate webhook setup without live transactions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> The test webhook payload.
	 */
	private function generate_test_webhook_payload(): array {
		$order_id = 'TEST_ORDER_' . substr( wp_generate_uuid4(), 0, 12 );
		$timestamp = gmdate( 'Y-m-d\TH:i:s\Z' );

		return array(
			'id'           => 'TEST_WH_' . wp_generate_uuid4(),
			'event_version' => '1.0',
			'create_time'  => $timestamp,
			'event_type'   => 'CHECKOUT.ORDER.COMPLETED',
			'summary'      => 'Test webhook event for webhook validation',
			'resource_type' => 'order',
			'resource'     => array(
				'id'              => $order_id,
				'status'          => 'APPROVED',
				'payment_source'  => array(
					'paypal' => array(
						'email_address' => 'test-donor@example.com',
						'account_id'    => 'TEST_ACCOUNT_ID',
					),
				),
				'purchase_units' => array(
					array(
						'reference_id' => 'default',
						'amount'       => array(
							'currency_code' => 'USD',
							'value'         => '25.00',
						),
						'shipping'     => array(
							'name'    => array(
								'full_name' => 'Test Donor',
							),
							'address' => array(
								'address_line_1' => '123 Main St',
								'address_line_2' => 'Unit 1',
								'admin_area_2'   => 'San Jose',
								'admin_area_1'   => 'CA',
								'postal_code'    => '95131',
								'country_code'   => 'US',
							),
						),
					),
				),
				'payer'          => array(
					'email_address' => 'test-donor@example.com',
					'payer_id'      => 'TEST_PAYER_ID',
					'name'          => array(
						'given_name' => 'Test',
						'surname'    => 'Donor',
					),
					'address'       => array(
						'country_code' => 'US',
					),
				),
			),
			'links'        => array(
				array(
					'rel'  => 'self',
					'href' => 'https://api.paypal.com/test-webhook',
				),
			),
		);
	}

	/**
	 * Cron handler for merchant-initiated card-recurring renewals.
	 *
	 * Finds active card-recurring donations whose next billing date has
	 * passed, creates a merchant-initiated Orders v2 order against the
	 * stored vault token, captures it, and records the renewal payment.
	 *
	 * Designed to be idempotent — if an attempt fails or is interrupted,
	 * the next cron tick retries with a fresh idempotency key derived from
	 * the parent post ID + cycle counter.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function run_renewal_charges(): void {
		$config      = new ConfigService();
		$settings    = $config->get_all();
		$logger      = new Logger(
			(string) ( $settings['logging_level'] ?? 'error' ),
			! empty( $settings['enable_logging'] )
		);
		$token_cache = new \DonationSuite\PayPal\OAuthTokenCache( $config, $logger );
		$paypal      = new PayPalClient( $config, $token_cache, $logger );
		$repository  = new \DonationSuite\Donation\CptDonationRepository();

		$logger->info( 'Renewal charge cron started' );

		$now_iso = gmdate( 'c' );

		// Pull active card-recurring subscription posts whose next billing
		// time has already passed. Limit per run to keep memory bounded —
		// the cron repeats so anything left over is picked up next tick.
		$due = get_posts(
			array(
				'post_type'      => 'donadosu_donation',
				'post_status'    => 'donadosu_sub_active',
				'posts_per_page' => 25,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- unavoidable for cron lookup.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => DonationMeta::VAULT_PAYMENT_TOKEN_ID,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => DonationMeta::SUBSCRIPTION_NEXT_BILLING,
						'value'   => $now_iso,
						'compare' => '<=',
						'type'    => 'CHAR',
					),
				),
				'fields'         => 'ids',
			)
		);

		$logger->info( 'Renewal charge cron: due posts found', array( 'count' => count( $due ) ) );

		$state_machine = new \DonationSuite\Donation\StateMachine();

		foreach ( $due as $parent_id ) {
			$parent_id = (int) $parent_id;

			// Per-post lock to keep two cron workers from charging the same
			// subscription concurrently when wp-cron runs in parallel.
			$lock_key = 'donadosu_renewal_lock_' . $parent_id;
			if ( ! wp_cache_add( $lock_key, getmypid(), 'donateocean-donation-suite', 5 * MINUTE_IN_SECONDS ) ) {
				$logger->info( 'Renewal skipped — already locked', array( 'post_id' => $parent_id ) );
				continue;
			}

			try {
				$vault_id = (string) get_post_meta( $parent_id, DonationMeta::VAULT_PAYMENT_TOKEN_ID, true );
				$amount   = (float) get_post_meta( $parent_id, DonationMeta::GROSS_AMOUNT, true );
				if ( $amount <= 0.0 ) {
					$amount = (float) get_post_meta( $parent_id, DonationMeta::AMOUNT, true );
				}
				$currency = (string) get_post_meta( $parent_id, DonationMeta::CURRENCY, true );
				$cycle    = (string) get_post_meta( $parent_id, DonationMeta::SUBSCRIPTION_CYCLE, true );
				$env      = (string) get_post_meta( $parent_id, DonationMeta::ENV, true );

				if ( '' === $vault_id || $amount <= 0.0 || '' === $currency ) {
					$logger->warn( 'Renewal skipped — missing vault/amount/currency', array( 'post_id' => $parent_id ) );
					continue;
				}

				// Idempotency key tied to the next-billing timestamp so a retry
				// for the same cycle stays deduplicated at PayPal but a fresh
				// cycle gets a fresh key.
				$idempotency_key = sprintf(
					'renewal:%d:%s',
					$parent_id,
					(string) get_post_meta( $parent_id, DonationMeta::SUBSCRIPTION_NEXT_BILLING, true )
				);

				$logger->info(
					'Attempting renewal charge',
					array(
						'post_id'  => $parent_id,
						'amount'   => $amount,
						'currency' => $currency,
						'vault_id' => $vault_id,
					)
				);

				$order = $paypal->create_order_with_vaulted_card(
					$vault_id,
					$amount,
					$currency,
					$idempotency_key,
					sprintf( 'Recurring donation renewal #%d', $parent_id )
				);

				if ( empty( $order['success'] ) || empty( $order['data']['id'] ) ) {
					self::record_renewal_failure(
						$parent_id,
						$repository,
						$state_machine,
						(string) ( $order['error'] ?? 'create_order_failed' ),
						$logger
					);
					continue;
				}

				$order_id = (string) $order['data']['id'];

				// Some MIT orders capture inline; if not, capture explicitly.
				$capture_data = $order['data'];
				if ( 'COMPLETED' !== strtoupper( (string) ( $capture_data['status'] ?? '' ) ) ) {
					$capture = $paypal->capture_order( $order_id );
					if ( empty( $capture['success'] ) ) {
						self::record_renewal_failure(
							$parent_id,
							$repository,
							$state_machine,
							(string) ( $capture['error'] ?? 'capture_failed' ),
							$logger
						);
						continue;
					}
					$capture_data = $capture['data'];
				}

				$capture_node = $capture_data['purchase_units'][0]['payments']['captures'][0] ?? array();
				$sale_id      = (string) ( $capture_node['id'] ?? $order_id );
				$paid_value   = (float) ( $capture_node['amount']['value'] ?? $amount );
				$paid_curr    = strtoupper( (string) ( $capture_node['amount']['currency_code'] ?? $currency ) );

				// Record the renewal as its own donation post so the donor gets
				// a fresh receipt and analytics see it as an individual gift.
				// Pass the MIT order id so the PAYMENT.CAPTURE.COMPLETED webhook
				// can resolve back to this post (the webhook payload carries
				// the order_id in resource.supplementary_data.related_ids.order_id,
				// not the capture id).
				$renewal_id = $repository->create_renewal_payment(
					$parent_id,
					$sale_id,
					$paid_value,
					$paid_curr,
					$env,
					$order_id
				);

				if ( $renewal_id > 0 ) {
					do_action(
						'donadosu_donation_completed',
						$renewal_id,
						array(
							'source'     => 'renewal_cron',
							'parent_id'  => $parent_id,
							'capture_id' => $sale_id,
						)
					);
				} else {
					$logger->warn( 'Renewal post creation returned 0 — possible duplicate', array( 'post_id' => $parent_id, 'sale_id' => $sale_id ) );
				}

				// Advance next billing date from the previous scheduled
				// timestamp (not from NOW) so the cadence stays anchored to
				// the donor's original anniversary when a cron tick runs
				// late. compute_next_billing_iso_static falls back to NOW
				// if the previous value has drifted more than two cycles.
				$previous_next_billing = (string) get_post_meta( $parent_id, DonationMeta::SUBSCRIPTION_NEXT_BILLING, true );
				$next_iso              = self::compute_next_billing_iso_static( $cycle, $previous_next_billing );
				if ( '' !== $next_iso ) {
					update_post_meta( $parent_id, DonationMeta::SUBSCRIPTION_NEXT_BILLING, $next_iso );
					update_post_meta( $parent_id, DonationMeta::NEXT_BILLING_TIME, $next_iso );
				}
				delete_post_meta( $parent_id, DonationMeta::RENEWAL_FAIL_COUNT );
				delete_post_meta( $parent_id, DonationMeta::RENEWAL_LAST_ERROR );

				$logger->info(
					'Renewal charge completed',
					array(
						'post_id'    => $parent_id,
						'renewal_id' => $renewal_id,
						'sale_id'    => $sale_id,
						'amount'     => $paid_value,
						'currency'   => $paid_curr,
						'next'       => $next_iso,
					)
				);
			} finally {
				wp_cache_delete( $lock_key, 'donateocean-donation-suite' );
			}
		}

		$logger->info( 'Renewal charge cron finished' );
	}

	/**
	 * Record a failed renewal attempt and decide whether to mark the
	 * subscription as failed.
	 *
	 * Three consecutive failures move the parent post to donadosu_sub_failed
	 * so admins can intervene; earlier failures only update the failure
	 * counter and back the next attempt off by 24 hours.
	 *
	 * @since 1.0.0
	 *
	 * @param int                                              $post_id       Parent subscription post.
	 * @param \DonationSuite\Donation\CptDonationRepository    $repository    Donation repository.
	 * @param \DonationSuite\Donation\StateMachine             $state_machine Status state machine.
	 * @param string                                           $reason        PayPal-supplied error message.
	 * @param Logger                                           $logger        Logger instance.
	 * @return void
	 */
	private static function record_renewal_failure(
		int $post_id,
		\DonationSuite\Donation\CptDonationRepository $repository,
		\DonationSuite\Donation\StateMachine $state_machine,
		string $reason,
		Logger $logger
	): void {
		$count = (int) get_post_meta( $post_id, DonationMeta::RENEWAL_FAIL_COUNT, true ) + 1;
		update_post_meta( $post_id, DonationMeta::RENEWAL_FAIL_COUNT, $count );
		update_post_meta( $post_id, DonationMeta::RENEWAL_LAST_ERROR, $reason );
		update_post_meta( $post_id, DonationMeta::LAST_SUBSCRIPTION_FAILURE_REASON, $reason );

		$logger->warn(
			'Renewal charge failed',
			array(
				'post_id'    => $post_id,
				'reason'     => $reason,
				'fail_count' => $count,
			)
		);

		if ( $count >= 3 ) {
			$current = (string) get_post_status( $post_id );
			$next    = $state_machine->transition( $current, 'donadosu_sub_failed' );
			if ( $next !== $current ) {
				$repository->set_status( $post_id, $next );
				$repository->append_history(
					$post_id,
					$next,
					array(
						'source' => 'renewal_cron',
						'reason' => $reason,
					)
				);
				update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, 'failed' );
				do_action( 'donadosu_subscription_failed', $post_id, $reason );
			}
			return;
		}

		// Back off the next attempt by 24 hours so we don't hammer PayPal
		// (e.g. on a hard card decline) within a single day.
		$next_attempt = gmdate( 'c', time() + DAY_IN_SECONDS );
		update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_NEXT_BILLING, $next_attempt );
		update_post_meta( $post_id, DonationMeta::NEXT_BILLING_TIME, $next_attempt );
	}

	/**
	 * Static counterpart of compute_next_billing_iso() for use from the cron entry point.
	 *
	 * Advances the next-billing timestamp from the previous scheduled
	 * billing time (if supplied) so the cadence stays on the donor's
	 * original anniversary date even when a cron tick runs late. Falls
	 * back to "now" only if no previous timestamp is available or the
	 * previous timestamp is more than two cycles in the past (in which
	 * case the schedule has clearly diverged and we resynchronise to the
	 * current charge date instead of stacking many backdated cycles).
	 *
	 * @since 1.0.0
	 *
	 * @param string $cycle    Subscription cycle (monthly|annual).
	 * @param string $base_iso Optional ISO 8601 timestamp of the current next-billing value. Used as the base for the +1 month / +1 year advance.
	 * @return string ISO 8601 timestamp, or empty string for unknown cycles.
	 */
	private static function compute_next_billing_iso_static( string $cycle, string $base_iso = '' ): string {
		$now     = time();
		$base_ts = '' !== $base_iso ? strtotime( $base_iso ) : false;

		// If we don't have a base, or the base drifted so far into the past
		// that advancing from it would stack up several backdated cycles,
		// fall back to "now" to keep the schedule sane.
		if ( false === $base_ts ) {
			$base_ts = $now;
		} else {
			$max_lag = 'annual' === $cycle || 'yearly' === $cycle
				? strtotime( '-2 years', $now )
				: strtotime( '-2 months', $now );
			if ( false !== $max_lag && $base_ts < $max_lag ) {
				$base_ts = $now;
			}
		}

		switch ( $cycle ) {
			case 'monthly':
				$next = strtotime( '+1 month', $base_ts );
				break;
			case 'annual':
			case 'yearly':
				$next = strtotime( '+1 year', $base_ts );
				break;
			default:
				return '';
		}
		return false === $next ? '' : gmdate( 'c', $next );
	}

	/**
	 * Apply a status transition to a donation post via the state machine.
	 *
	 * If the transition is not valid, the status remains unchanged and
	 * an informational log entry is written.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $post_id   The donation post ID.
	 * @param string               $to_status The target donation status.
	 * @param array<string, mixed> $context   Optional transition context data.
	 * @return void
	 */
	private function apply_status_transition( int $post_id, string $to_status, array $context = array() ): void {
		$from_status = (string) get_post_status( $post_id );
		$next_status = $this->state_machine->transition( $from_status, $to_status );

		if ( $next_status === $from_status ) {
			$this->logger->info(
				'Ignored invalid status transition',
				array(
					'post_id'     => $post_id,
					'from_status' => $from_status,
					'to_status'   => $to_status,
				)
			);
			return;
		}

		$this->repository->set_status( $post_id, $next_status );
		$this->repository->append_history( $post_id, $next_status, $context + array( 'from_status' => $from_status ) );

		$this->logger->info(
			'Status transition applied',
			array(
				'post_id' => $post_id,
				'from'    => $from_status,
				'to'      => $next_status,
			)
		);
	}

	/**
	 * Reconcile stale donations by checking their status with PayPal.
	 *
	 * Finds donations older than 30 minutes that are still in the
	 * donadosu_created or donadosu_captured status and queries PayPal for
	 * updated order information.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function run_reconcile(): void {
		$repository  = new \DonationSuite\Donation\CptDonationRepository();
		$config      = new ConfigService();
		$settings    = $config->get_all();
		$logger      = new Logger(
			(string) ( $settings['logging_level'] ?? 'error' ),
			! empty( $settings['enable_logging'] )
		);
		$token_cache = new \DonationSuite\PayPal\OAuthTokenCache( $config, $logger );
		$paypal      = new PayPalClient( $config, $token_cache, $logger );

		$logger->info( 'Reconciliation started' );

		$stale = get_posts(
			array(
				'post_type'   => 'donadosu_donation',
				'post_status' => array( 'donadosu_created', 'donadosu_captured' ),
				'date_query'  => array(
					array(
						'before' => gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ),
					),
				),
				'numberposts' => 25,
			)
		);

		$state_machine = new \DonationSuite\Donation\StateMachine();

		$logger->info( 'Reconciliation: found stale donations', array( 'count' => count( $stale ) ) );

		foreach ( $stale as $post ) {
			$post_id  = (int) $post->ID;
			$order_id = (string) get_post_meta( $post_id, DonationMeta::ORDER_ID, true );

			if ( '' === $order_id ) {
				$logger->debug( 'Reconcile: skipping donation without order ID', array( 'post_id' => $post_id ) );
				continue;
			}

			$order = $paypal->get_order( $order_id );

			if ( empty( $order['success'] ) ) {
				$logger->warn(
					'Reconcile: PayPal order lookup failed',
					array(
						'post_id'  => $post_id,
						'order_id' => $order_id,
						'error'    => $order['error'] ?? 'api_failure',
					)
				);

				// If the order no longer exists (404), mark the donation as failed.
				if ( 404 === ( $order['status'] ?? 0 ) ) {
					$current_status = (string) get_post_status( $post_id );
					$next_status    = $state_machine->transition( $current_status, 'donadosu_failed' );
					if ( $next_status !== $current_status ) {
						$repository->set_status( $post_id, $next_status );
						$repository->append_history(
							$post_id,
							$next_status,
							array(
								'source'      => 'reconcile',
								'reason'      => 'order_not_found',
								'order_id'    => $order_id,
							)
						);
						$logger->info(
							'Reconcile: order not found in PayPal, marked as failed',
							array(
								'post_id'  => $post_id,
								'order_id' => $order_id,
							)
						);
					}
				}
				continue;
			}

			$order_status = strtoupper( (string) ( $order['data']['status'] ?? '' ) );
			$capture_id   = (string) ( $order['data']['purchase_units'][0]['payments']['captures'][0]['id'] ?? '' );

			if ( '' !== $capture_id ) {
				update_post_meta( $post_id, DonationMeta::CAPTURE_ID, $capture_id );
			}

			if ( in_array( $order_status, array( 'COMPLETED', 'APPROVED' ), true ) ) {
				$target_status  = 'COMPLETED' === $order_status ? 'donadosu_completed' : 'donadosu_approved';
				$current_status = (string) get_post_status( $post_id );
				$next_status    = $state_machine->transition( $current_status, $target_status );

				if ( $next_status !== $current_status ) {
					$repository->set_status( $post_id, $next_status );
					$repository->append_history(
						$post_id,
						$next_status,
						array(
							'source'       => 'reconcile',
							'order_status' => $order_status,
						)
					);

					$logger->info(
						'Reconcile: status updated',
						array(
							'post_id'    => $post_id,
							'order_id'   => $order_id,
							'new_status' => $next_status,
						)
					);

					if ( 'donadosu_completed' === $next_status ) {
						do_action(
							'donadosu_donation_completed',
							$post_id,
							array(
								'source' => 'reconcile',
								'order'  => $order['data'],
							)
						);
					}
				}
			}

			// Mark voided, denied, or expired orders as failed to stop re-querying them.
			if ( in_array( $order_status, array( 'VOIDED', 'DENIED', 'EXPIRED' ), true ) ) {
				$current_status = (string) get_post_status( $post_id );
				$next_status    = $state_machine->transition( $current_status, 'donadosu_failed' );
				if ( $next_status !== $current_status ) {
					$repository->set_status( $post_id, $next_status );
					$repository->append_history( $post_id, $next_status, array( 'source' => 'reconcile', 'order_status' => $order_status ) );
					$logger->info( 'Reconcile: order marked as failed', array( 'post_id' => $post_id, 'order_status' => $order_status ) );
				}
			}

			$logger->info(
				'Reconcile: order checked',
				array(
					'post_id'      => $post_id,
					'order_id'     => $order_id,
					'order_status' => $order_status,
				)
			);
		}

		$logger->info( 'Reconciliation completed', array( 'processed' => count( $stale ) ) );
	}
}
