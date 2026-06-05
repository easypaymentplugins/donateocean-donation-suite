<?php
/**
 * PayPal webhook event handler.
 *
 * Receives incoming PayPal webhook events, verifies their signatures,
 * deduplicates, and routes each event to the appropriate donation
 * status transition.
 *
 * @package    Donation_Suite
 * @subpackage PayPal
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\PayPal;

use DonationSuite\Core\ConfigService;
use DonationSuite\Donation\DonationMeta;
use DonationSuite\Donation\DonationRepositoryInterface;
use DonationSuite\Donation\StateMachine;
use DonationSuite\Email\ReceiptEmailService;
use DonationSuite\Logging\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WebhookHandler
 *
 * Processes PayPal webhook notifications by verifying signatures,
 * deduplicating events, and applying the corresponding donation
 * status transitions.
 *
 * @since 1.0.0
 */
class WebhookHandler {

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
	 * @param StateMachine                $state_machine Donation status state machine.
	 * @param Logger                      $logger        Logger instance.
	 */
	public function __construct(
		DonationRepositoryInterface $repository,
		ConfigService $config,
		PayPalClient $paypal,
		StateMachine $state_machine,
		Logger $logger
	) {
		$this->repository    = $repository;
		$this->config        = $config;
		$this->paypal        = $paypal;
		$this->state_machine = $state_machine;
		$this->logger        = $logger;
	}

	/**
	 * Handle an incoming PayPal webhook request.
	 *
	 * Verifies the webhook signature, extracts the event, deduplicates
	 * against previously processed events, and routes to apply_event().
	 *
	 * @since 1.0.0
	 *
	 * @param string                              $raw_body Raw request body JSON.
	 * @param array<string, array<int, string>>   $headers  Request headers.
	 * @return array{status: int, body: array<string, mixed>}
	 */
	public function handle( string $raw_body, array $headers ): array {
		$this->logger->debug( 'Webhook handler invoked', array( 'body_length' => strlen( $raw_body ) ) );

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			$this->logger->warn( 'Webhook ignored — invalid JSON body' );
			return $this->ok( array( 'ignored' => 'invalid_json' ) );
		}

		// WP_REST_Request::get_headers() normalizes header names to lowercase
		// with hyphens replaced by underscores (e.g. PAYPAL-TRANSMISSION-ID
		// becomes paypal_transmission_id). Fall back to the hyphenated form
		// in case the handler is invoked with a non-canonicalized header map
		// (e.g. from the retry queue or tests).
		$verify_meta = array(
			'auth_algo'         => $this->header_value( $headers, 'paypal-auth-algo' ),
			'cert_url'          => $this->header_value( $headers, 'paypal-cert-url' ),
			'transmission_id'   => $this->header_value( $headers, 'paypal-transmission-id' ),
			'transmission_sig'  => $this->header_value( $headers, 'paypal-transmission-sig' ),
			'transmission_time' => $this->header_value( $headers, 'paypal-transmission-time' ),
			'webhook_id'        => (string) $this->config->get_webhook_id(),
		);

		if ( '' === $verify_meta['transmission_id'] ) {
			$this->logger->warn(
				'Webhook missing PayPal signature headers',
				array( 'header_keys' => array_keys( $headers ) )
			);
		}

		if ( '' === $verify_meta['webhook_id'] ) {
			$this->logger->error( 'Webhook received but webhook_id is missing in settings' );
			return $this->ok( array( 'ignored' => 'missing_webhook_id' ) );
		}

		// Send the raw body as webhook_event — PayPal's signature is bound to
		// a CRC32 of the original bytes, so any re-encoding invalidates it.
		$this->logger->debug( 'Verifying webhook signature', array( 'transmission_id' => $verify_meta['transmission_id'] ) );
		$verify              = $this->paypal->verify_webhook_signature( $verify_meta, $raw_body );
		$verification_status = $verify['data']['verification_status'] ?? 'FAILURE';

		if ( 'SUCCESS' !== $verification_status ) {
			$this->logger->warn(
				'Webhook signature verification failed',
				array(
					'verification_status' => $verification_status,
					'transmission_id'     => $verify_meta['transmission_id'],
				)
			);

			return array(
				'status' => 400,
				'body'   => array( 'error' => 'signature_verification_failed' ),
			);
		}

		$this->logger->info( 'Webhook signature verified successfully', array( 'transmission_id' => $verify_meta['transmission_id'] ) );

		$event_id   = sanitize_text_field( (string) ( $payload['id'] ?? '' ) );
		$event_type = sanitize_text_field( (string) ( $payload['event_type'] ?? '' ) );

		$this->logger->info( 'Webhook event received', array( 'event_id' => $event_id, 'event_type' => $event_type ) );

		update_option(
			'donadosu_webhook_health',
			array(
				'event_id'            => $event_id,
				'event_type'          => $event_type,
				'received_at'         => gmdate( 'c' ),
				'verification_status' => $verification_status,
			),
			false
		);

		if ( '' === $event_id ) {
			$this->logger->warn( 'Webhook ignored — missing event_id' );
			return $this->ok( array( 'ignored' => 'missing_event_id' ) );
		}

		// Atomic durable claim via UNIQUE index on wp_donadosu_processed_events.
		// INSERT IGNORE returns 0 affected rows when the event_id already exists,
		// which gives us a race-free dedup across concurrent webhook deliveries.
		$claimed = $this->claim_event_durable( $event_id, $event_type );
		if ( ! $claimed ) {
			$this->logger->info( 'Webhook deduplicated (durable claim)', array( 'event_id' => $event_id ) );
			return $this->ok( array( 'dedupe' => true ) );
		}

		if ( $this->is_event_globally_processed( $event_id ) ) {
			$this->logger->info( 'Webhook deduplicated (globally processed)', array( 'event_id' => $event_id ) );
			return $this->ok( array( 'dedupe' => true ) );
		}

		$order_id        = $this->extract_order_id( $payload );
		$subscription_id = $this->extract_subscription_id( $payload );

		$this->logger->debug( 'Webhook IDs extracted', array( 'order_id' => $order_id, 'subscription_id' => $subscription_id ) );

		// Try order ID first, then subscription ID.
		$post_id = null;
		if ( '' !== $order_id ) {
			$post_id = $this->repository->find_by_order_id( $order_id );
		}
		if ( ! $post_id && '' !== $subscription_id ) {
			$post_id = $this->repository->find_by_subscription_id( $subscription_id );
		}

		if ( ! $post_id || ( '' === $order_id && '' === $subscription_id ) ) {
			// Race condition: donation post hasn't been created yet.
			// Release the durable claim so a later delivery or the retry cron
			// can re-claim and actually process this event. Without the release
			// the INSERT-IGNORE claim would dedupe every retry and the donation
			// would be stranded in its pre-completion status forever.
			$this->release_event_durable( $event_id );
			$this->queue_webhook_for_retry( $event_id, $event_type, $raw_body, $headers );

			$this->logger->warn(
				'Webhook queued for delayed retry — donation not found',
				array(
					'event_id'        => $event_id,
					'event_type'      => $event_type,
					'order_id'        => $order_id,
					'subscription_id' => $subscription_id,
				)
			);

			// Return 202 Accepted to signal "processing delayed".
			return array(
				'status' => 202,
				'body'   => array( 'accepted' => 'queued_for_retry' ),
			);
		}

		$last_event_id = (string) get_post_meta( $post_id, DonationMeta::LAST_WEBHOOK_EVENT_ID, true );
		if ( $last_event_id === $event_id ) {
			$this->logger->info( 'Webhook deduplicated (per-post)', array( 'event_id' => $event_id, 'post_id' => $post_id ) );
			return $this->ok( array( 'dedupe' => true ) );
		}

		update_post_meta( $post_id, DonationMeta::LAST_WEBHOOK_EVENT_ID, $event_id );
		update_post_meta( $post_id, DonationMeta::LAST_WEBHOOK_EVENT_AT, gmdate( 'c' ) );
		$this->mark_event_globally_processed( $event_id );

		$this->logger->info(
			'Processing webhook event',
			array(
				'event_id'   => $event_id,
				'event_type' => $event_type,
				'post_id'    => $post_id,
			)
		);
		$this->apply_event( $post_id, $event_type, $event_id, $payload );

		if ( ! empty( $this->config->get_all()['store_raw_payload'] ) ) {
			update_post_meta( $post_id, DonationMeta::META_JSON, wp_json_encode( $payload ) );
			$this->logger->debug( 'Raw webhook payload stored', array( 'post_id' => $post_id ) );
		}

		$this->logger->info(
			'Webhook event processed successfully',
			array(
				'event_id'   => $event_id,
				'event_type' => $event_type,
				'post_id'    => $post_id,
			)
		);

		return $this->ok( array( 'ok' => true ) );
	}

	/**
	 * Extract the PayPal order ID from a webhook payload.
	 *
	 * Looks for the order ID in supplementary data first, then falls back
	 * to resource.id for checkout and capture events.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $payload Webhook event payload.
	 * @return string Order ID or empty string.
	 */
	public function extract_order_id( array $payload ): string {
		$order_id = (string) ( $payload['resource']['supplementary_data']['related_ids']['order_id'] ?? '' );
		if ( '' !== $order_id ) {
			return sanitize_text_field( $order_id );
		}

		// Only treat resource.id as order ID for checkout/capture events.
		$event_type = (string) ( $payload['event_type'] ?? '' );
		if ( 0 === strpos( $event_type, 'CHECKOUT.' ) ) {
			return sanitize_text_field( (string) ( $payload['resource']['id'] ?? '' ) );
		}

		return '';
	}

	/**
	 * Extract the PayPal subscription ID from a webhook payload.
	 *
	 * Subscription events carry the subscription ID in resource.id or
	 * resource.billing_agreement_id.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $payload Webhook event payload.
	 * @return string Subscription ID or empty string.
	 */
	public function extract_subscription_id( array $payload ): string {
		$event_type = (string) ( $payload['event_type'] ?? '' );
		if ( 0 === strpos( $event_type, 'BILLING.SUBSCRIPTION' ) || 'PAYMENT.SALE.COMPLETED' === $event_type ) {
			$sub_id = (string) ( $payload['resource']['billing_agreement_id'] ?? $payload['resource']['id'] ?? '' );
			return sanitize_text_field( $sub_id );
		}

		return '';
	}

	/**
	 * Apply a webhook event to a donation post.
	 *
	 * Routes the event to the appropriate status transition based on the
	 * PayPal event type.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $post_id    Donation post ID.
	 * @param string               $event_type PayPal event type.
	 * @param string               $event_id   PayPal event ID.
	 * @param array<string, mixed> $payload    Full webhook event payload.
	 * @return void
	 */
	public function apply_event( int $post_id, string $event_type, string $event_id, array $payload ): void {
		$context = array(
			'event_id'   => $event_id,
			'event_type' => $event_type,
		);

		if ( 'CHECKOUT.ORDER.APPROVED' === $event_type ) {
			$this->logger->info( 'Order approved via webhook', array( 'post_id' => $post_id, 'event_id' => $event_id ) );
			$this->transition( $post_id, 'donadosu_approved', $context );
			return;
		}

		if ( 'PAYMENT.CAPTURE.COMPLETED' === $event_type ) {
			$this->logger->info( 'Payment capture completed via webhook', array( 'post_id' => $post_id, 'event_id' => $event_id ) );

			// Check if we already have a local capture ID (from previous processing or local capture).
			$existing_capture_id = (string) get_post_meta( $post_id, DonationMeta::CAPTURE_ID, true );
			$webhook_capture_id  = (string) ( $payload['resource']['id'] ?? '' );

			// Use local capture ID if available, otherwise use webhook's.
			$capture_id = '' !== $existing_capture_id ? $existing_capture_id : $webhook_capture_id;

			// Server-side verification: reject and refund if PayPal completed
			// a capture with a different amount or currency than we recorded.
			$cap_value = (float) ( $payload['resource']['amount']['value'] ?? 0.0 );
			$cap_curr  = strtoupper( (string) ( $payload['resource']['amount']['currency_code'] ?? '' ) );
			if ( ! $this->verify_capture_amount_or_refund( $post_id, $cap_value, $cap_curr, $capture_id, $context ) ) {
				return;
			}

			// Store capture ID only if we didn't have one locally.
			if ( '' === $existing_capture_id && '' !== $webhook_capture_id ) {
				update_post_meta( $post_id, DonationMeta::CAPTURE_ID, $webhook_capture_id );
				$this->logger->debug( 'Capture ID stored from webhook', array( 'post_id' => $post_id, 'capture_id' => $webhook_capture_id ) );
			} elseif ( '' !== $existing_capture_id ) {
				$this->logger->debug( 'Using existing local capture ID', array( 'post_id' => $post_id, 'capture_id' => $existing_capture_id ) );
			}

			// Backfill donor details from PayPal if not provided during payment.
			$this->backfill_donor_from_order( $post_id );

			// Vault-recurring safeguard: if this capture belongs to a
			// card-recurring donation (first charge of an Orders-v2 vault
			// flow), route the post into the subscription-active state
			// instead of plain completed so the renewal cron can pick it up.
			$subscription_cycle = (string) get_post_meta( $post_id, DonationMeta::SUBSCRIPTION_CYCLE, true );
			$subscription_id    = (string) get_post_meta( $post_id, DonationMeta::SUBSCRIPTION_ID, true );
			$parent_sub_post_id = (string) get_post_meta( $post_id, DonationMeta::SUBSCRIPTION_PARENT_ID, true );
			$is_renewal_child   = '' !== $parent_sub_post_id;
			$is_card_recurring  = '' !== $subscription_cycle && '' === $subscription_id;

			// Renewal child posts (created by the cron after charging a
			// vaulted card) are one-shot captures belonging to a specific
			// billing cycle — they must stay at donadosu_completed and must
			// not re-run the vault backfill. The renewal cron has already
			// recorded this charge and fired donadosu_donation_completed, so
			// the matching webhook is redundant reconciliation only.
			if ( $is_card_recurring && ! $is_renewal_child ) {
				// Race fix: if the capture webhook beats the REST /order/capture
				// response for a card-recurring donation, the vault token hasn't
				// been extracted yet. Fetch the order directly to get the
				// payment_source.card.attributes.vault.id and customer.id so the
				// renewal cron can charge the card on the next cycle.
				$this->backfill_vault_from_order( $post_id, $subscription_cycle );
			}

			$target_status = ( '' !== $subscription_cycle && ! $is_renewal_child )
				? 'donadosu_sub_active'
				: 'donadosu_completed';

			if ( $this->transition( $post_id, $target_status, $context ) ) {
				do_action( 'donadosu_donation_completed', $post_id, $payload );
				$this->logger->info( 'Donation completed action fired', array( 'post_id' => $post_id, 'status' => $target_status ) );
			}
			return;
		}

		if ( 'PAYMENT.CAPTURE.DENIED' === $event_type ) {
			$this->logger->warn( 'Payment capture denied via webhook', array( 'post_id' => $post_id, 'event_id' => $event_id ) );
			$this->transition( $post_id, 'donadosu_failed', $context );
			return;
		}

		if ( 'PAYMENT.CAPTURE.REFUNDED' === $event_type ) {
			$this->logger->info( 'Payment capture refunded via webhook', array( 'post_id' => $post_id, 'event_id' => $event_id ) );
			$this->transition( $post_id, 'donadosu_refunded', $context );
			return;
		}

		if ( 'PAYMENT.CAPTURE.PENDING' === $event_type ) {
			$pending_reason = sanitize_text_field( (string) ( $payload['resource']['status_details']['reason'] ?? 'PENDING' ) );
			$this->transition( $post_id, 'donadosu_pending', $context + array( 'reason' => $pending_reason ) );
			$this->logger->info(
				'Capture pending — awaiting PayPal clearance',
				$context + array(
					'post_id' => $post_id,
					'reason'  => $pending_reason,
				)
			);
			return;
		}

		if ( 'CHECKOUT.ORDER.COMPLETED' === $event_type ) {
			$this->logger->info( 'Checkout order completed via webhook', array( 'post_id' => $post_id, 'event_id' => $event_id ) );

			// Server-side verification: an order-level completion carries its
			// captured amount under purchase_units[].payments.captures[]. Apply
			// the same amount/currency integrity check as the capture webhook so
			// a mismatched completion is refunded and failed, not silently
			// marked completed (with a receipt) for the wrong amount.
			$capture     = $payload['resource']['purchase_units'][0]['payments']['captures'][0] ?? array();
			$o_capture_id = (string) ( $capture['id'] ?? get_post_meta( $post_id, DonationMeta::CAPTURE_ID, true ) );
			$o_cap_value  = (float) ( $capture['amount']['value'] ?? 0.0 );
			$o_cap_curr   = strtoupper( (string) ( $capture['amount']['currency_code'] ?? '' ) );
			if ( $o_cap_value > 0 && ! $this->verify_capture_amount_or_refund( $post_id, $o_cap_value, $o_cap_curr, $o_capture_id, $context ) ) {
				return;
			}

			// Backfill donor details from PayPal if not provided during payment.
			$this->backfill_donor_from_order( $post_id );
			if ( $this->transition( $post_id, 'donadosu_completed', $context ) ) {
				do_action( 'donadosu_donation_completed', $post_id, $payload );
				$this->logger->info( 'Donation completed action fired', array( 'post_id' => $post_id ) );
			}
			return;
		}

		if ( 'CUSTOMER.DISPUTE.CREATED' === $event_type ) {
			$dispute_id     = sanitize_text_field( (string) ( $payload['resource']['dispute_id'] ?? '' ) );
			$dispute_reason = sanitize_text_field( (string) ( $payload['resource']['reason'] ?? 'UNKNOWN' ) );
			update_post_meta( $post_id, DonationMeta::DISPUTE_ID, $dispute_id );
			update_post_meta( $post_id, DonationMeta::DISPUTE_REASON, $dispute_reason );
			update_post_meta( $post_id, DonationMeta::DISPUTE_STATUS, 'OPEN' );
			$this->transition(
				$post_id,
				'donadosu_disputed',
				$context + array(
					'dispute_id' => $dispute_id,
					'reason'     => $dispute_reason,
				)
			);
			do_action( 'donadosu_donation_disputed', $post_id, $payload );
			ReceiptEmailService::handle_dispute_alert( $post_id, $dispute_id, $dispute_reason );
			$this->logger->warn(
				'Dispute opened',
				array(
					'post_id'    => $post_id,
					'dispute_id' => $dispute_id,
					'reason'     => $dispute_reason,
				)
			);
			return;
		}

		if ( 'CUSTOMER.DISPUTE.RESOLVED' === $event_type ) {
			$outcome = strtoupper( (string) ( $payload['resource']['dispute_outcome']['outcome_code'] ?? 'UNKNOWN' ) );
			update_post_meta( $post_id, DonationMeta::DISPUTE_STATUS, 'RESOLVED_' . $outcome );
			$to_status = in_array( $outcome, array( 'BUYER_FAVOUR', 'WITH_PAYOUT' ), true )
				? 'donadosu_refunded'
				: 'donadosu_completed';
			$this->transition( $post_id, $to_status, $context + array( 'outcome' => $outcome ) );
			$this->logger->info(
				'Dispute resolved',
				array(
					'post_id'    => $post_id,
					'outcome'    => $outcome,
					'new_status' => $to_status,
				)
			);
			return;
		}

		if ( 'PAYMENT.CAPTURE.REVERSED' === $event_type ) {
			update_post_meta( $post_id, DonationMeta::DISPUTE_STATUS, 'REVERSED' );
			$this->transition( $post_id, 'donadosu_refunded', $context );
			$this->logger->warn( 'Payment capture reversed', array( 'post_id' => $post_id, 'event_id' => $event_id ) );
			return;
		}

		if ( 'BILLING.SUBSCRIPTION.ACTIVATED' === $event_type ) {
			$subscription_id = sanitize_text_field( (string) ( $payload['resource']['id'] ?? '' ) );
			update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, 'active' );
			if ( $subscription_id ) {
				update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_ID, $subscription_id );
			}
			$this->transition( $post_id, 'donadosu_sub_active', $context );
			$this->logger->info( 'Subscription activated via webhook', array( 'post_id' => $post_id, 'subscription_id' => $subscription_id ) );
			return;
		}

		if ( 'BILLING.SUBSCRIPTION.CANCELLED' === $event_type ) {
			update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, 'cancelled' );
			$this->transition( $post_id, 'donadosu_sub_cancelled', $context );
			$this->logger->info( 'Subscription cancelled via webhook', array( 'post_id' => $post_id ) );
			return;
		}

		if ( 'BILLING.SUBSCRIPTION.SUSPENDED' === $event_type ) {
			update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, 'paused' );
			$this->transition( $post_id, 'donadosu_sub_paused', $context );
			$this->logger->info( 'Subscription suspended via webhook', array( 'post_id' => $post_id ) );
			return;
		}

		if ( 'PAYMENT.SALE.COMPLETED' === $event_type ) {
			$this->logger->info( 'Recurring payment sale completed', array( 'post_id' => $post_id, 'event_id' => $event_id ) );

			// Recurring charge succeeded — keep the parent subscription post's
			// status coherent first.
			$from_status = (string) get_post_status( $post_id );
			if ( in_array( $from_status, StateMachine::SUBSCRIPTION_STATUSES, true ) ) {
				update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, 'active' );
				// If a subscription was paused/failed and then billed again, move it back to active.
				$this->transition( $post_id, 'donadosu_sub_active', $context + array( 'source' => 'recurring_charge' ) );
			} else {
				// Fallback for historical records that still use one-time statuses.
				$this->transition( $post_id, 'donadosu_completed', $context + array( 'source' => 'recurring_charge' ) );
			}

			$sale_id       = sanitize_text_field( (string) ( $payload['resource']['id'] ?? '' ) );
			$sale_amount   = (float) ( $payload['resource']['amount']['total'] ?? $payload['resource']['amount']['value'] ?? get_post_meta( $post_id, DonationMeta::AMOUNT, true ) );
			$sale_currency = sanitize_text_field( (string) ( $payload['resource']['amount']['currency_code'] ?? $payload['resource']['amount']['currency'] ?? (string) get_post_meta( $post_id, DonationMeta::CURRENCY, true ) ) );
			$env           = sanitize_text_field( (string) get_post_meta( $post_id, DonationMeta::ENV, true ) );

			// First-charge deduplication: the initial billing cycle of a
			// wallet subscription is already represented by the parent
			// activation post itself, which subscription_callback has
			// already moved to donadosu_sub_active. Creating a separate
			// renewal post for this first sale (plus firing
			// donadosu_donation_completed on it) would send the donor a
			// duplicate receipt for the same charge — one for the parent
			// post, one for the renewal child.
			//
			// CAPTURE_ID on the parent is the flag: empty means no sale has
			// been linked yet (this is the first charge) so we attach this
			// sale to the parent and fire donation_completed on the parent
			// (receipt dedupe on RECEIPT_SENT_AT keeps it to one email
			// regardless of which event arrives first — subscription_callback
			// or this webhook). A non-empty CAPTURE_ID means the first sale
			// has been recorded and every subsequent sale spawns its own
			// renewal child with its own receipt number.
			$parent_capture_id = (string) get_post_meta( $post_id, DonationMeta::CAPTURE_ID, true );

			if ( '' !== $sale_id && '' === $parent_capture_id ) {
				update_post_meta( $post_id, DonationMeta::CAPTURE_ID, $sale_id );
				do_action( 'donadosu_donation_completed', $post_id, $payload );
				$this->logger->info(
					'First subscription charge linked to parent post (no renewal child created)',
					$context + array(
						'parent_post_id' => $post_id,
						'sale_id'        => $sale_id,
						'amount'         => $sale_amount,
						'currency'       => $sale_currency,
					)
				);
				return;
			}

			// Subsequent billing cycle: create its own renewal post so the
			// donor gets a fresh receipt and analytics count it as an
			// individual gift.
			if ( '' !== $sale_id ) {
				$renewal_id = $this->repository->create_renewal_payment( $post_id, $sale_id, $sale_amount, $sale_currency, $env );
				if ( $renewal_id > 0 ) {
					do_action( 'donadosu_donation_completed', $renewal_id, $payload );
					$this->logger->info(
						'Subscription renewal payment recorded',
						$context + array(
							'renewal_post_id' => $renewal_id,
							'parent_post_id'  => $post_id,
							'sale_id'         => $sale_id,
							'amount'          => $sale_amount,
							'currency'        => $sale_currency,
						)
					);
					return;
				}
				$this->logger->warn( 'Subscription renewal post creation failed', array( 'post_id' => $post_id, 'sale_id' => $sale_id ) );
			}

			// Fallback if no sale ID or renewal creation failed — fire on parent post.
			do_action( 'donadosu_donation_completed', $post_id, $payload );
			$this->logger->info( 'Donation completed action fired on parent post (renewal fallback)', array( 'post_id' => $post_id ) );
			return;
		}

		if ( 'BILLING.SUBSCRIPTION.PAYMENT_FAILED' === $event_type ) {
			$this->logger->warn( 'Subscription payment failed', array( 'post_id' => $post_id, 'event_id' => $event_id ) );

			// Extract failure details from the webhook payload
			$failure_reason = sanitize_text_field( (string) ( $payload['resource']['reason_code'] ?? $payload['resource']['status_details']['reason'] ?? 'UNKNOWN' ) );
			$next_billing_time = sanitize_text_field( (string) ( $payload['resource']['next_billing_time'] ?? '' ) );

			// Update post metadata with failure details
			update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, 'failed' );
			update_post_meta( $post_id, DonationMeta::LAST_SUBSCRIPTION_FAILURE_REASON, $failure_reason );
			if ( '' !== $next_billing_time ) {
				update_post_meta( $post_id, DonationMeta::NEXT_BILLING_TIME, $next_billing_time );
			}

			// Transition to failed status
			$this->transition(
				$post_id,
				'donadosu_sub_failed',
				$context + array(
					'reason' => $failure_reason,
					'next_billing' => $next_billing_time,
				)
			);

			// Fire action for integrations to respond (e.g., notify admin, send email)
			do_action( 'donadosu_subscription_payment_failed', $post_id, $failure_reason, $payload );

			return;
		}

		$this->logger->info( 'Unhandled webhook event type', $context + array( 'post_id' => $post_id ) );
	}

	/**
	 * Transition a donation post to a new status via the state machine.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $post_id   Donation post ID.
	 * @param string               $to_status Target donation status.
	 * @param array<string, mixed> $context   Transition context data.
	 * @return bool True if the transition was applied, false if it was rejected.
	 */
	/**
	 * Backfill missing donor details by fetching the PayPal order.
	 *
	 * When the donation was created without donor fields (e.g. the form
	 * had donor_fields disabled), this method fetches the order from
	 * PayPal to retrieve the payer's name, email, and address, then
	 * stores them in donation meta — but only for fields still empty.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Donation post ID.
	 * @return void
	 */
	private function backfill_donor_from_order( int $post_id ): void {
		// Only backfill if donor name or email is missing.
		$existing_name  = (string) get_post_meta( $post_id, DonationMeta::DONOR_NAME, true );
		$existing_email = (string) get_post_meta( $post_id, DonationMeta::DONOR_EMAIL, true );

		if ( '' !== $existing_name && '' !== $existing_email ) {
			return;
		}

		$order_id = (string) get_post_meta( $post_id, DonationMeta::ORDER_ID, true );
		if ( '' === $order_id ) {
			return;
		}

		$order_result = $this->paypal->get_order( $order_id );
		if ( empty( $order_result['success'] ) ) {
			$this->logger->debug(
				'Could not fetch order for donor backfill',
				array( 'post_id' => $post_id, 'order_id' => $order_id )
			);
			return;
		}

		$data     = $order_result['data'] ?? array();
		$payer    = $data['payer'] ?? array();
		$shipping = $data['purchase_units'][0]['shipping'] ?? array();

		$given_name  = sanitize_text_field( (string) ( $payer['name']['given_name'] ?? '' ) );
		$surname     = sanitize_text_field( (string) ( $payer['name']['surname'] ?? '' ) );
		$payer_name  = trim( $given_name . ' ' . $surname );
		$payer_email = sanitize_email( (string) ( $payer['email_address'] ?? '' ) );

		$address = $shipping['address'] ?? $payer['address'] ?? array();
		$payer_address_line = sanitize_text_field( (string) ( $address['address_line_1'] ?? '' ) );
		$address_line_2     = sanitize_text_field( (string) ( $address['address_line_2'] ?? '' ) );
		if ( '' !== $address_line_2 ) {
			$payer_address_line .= ( '' !== $payer_address_line ? ', ' : '' ) . $address_line_2;
		}
		$payer_city   = sanitize_text_field( (string) ( $address['admin_area_2'] ?? '' ) );
		$payer_postal = sanitize_text_field( (string) ( $address['postal_code'] ?? '' ) );

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
				'Donor details backfilled from PayPal order (webhook)',
				array(
					'post_id'       => $post_id,
					'order_id'      => $order_id,
					'filled_fields' => $filled,
				)
			);
		}
	}

	/**
	 * Backfill the vaulted payment-token info for a card-recurring donation.
	 *
	 * The PAYMENT.CAPTURE.COMPLETED webhook payload contains capture-level
	 * data only — the vault.id returned by Orders v2 lives at the order
	 * level under payment_source.card.attributes.vault. If this webhook
	 * arrives before the REST /order/capture response finishes processing
	 * (a real race in production), the vault token would never be stored
	 * and the renewal cron could not charge the card.
	 *
	 * This helper fetches the order via GET /v2/checkout/orders/{id} and
	 * populates the VAULT_PAYMENT_TOKEN_ID + VAULT_CUSTOMER_ID meta, plus
	 * the next billing date derived from the subscription cycle. Safe to
	 * call multiple times: skips the network round-trip if the vault ID
	 * is already stored.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id            Donation post ID.
	 * @param string $subscription_cycle Subscription cycle (monthly|annual).
	 * @return void
	 */
	private function backfill_vault_from_order( int $post_id, string $subscription_cycle ): void {
		$existing_vault = (string) get_post_meta( $post_id, DonationMeta::VAULT_PAYMENT_TOKEN_ID, true );
		if ( '' !== $existing_vault ) {
			return;
		}

		$order_id = (string) get_post_meta( $post_id, DonationMeta::ORDER_ID, true );
		if ( '' === $order_id ) {
			$this->logger->debug(
				'Vault backfill skipped — no order ID on post',
				array( 'post_id' => $post_id )
			);
			return;
		}

		$order_result = $this->paypal->get_order( $order_id );
		if ( empty( $order_result['success'] ) ) {
			$this->logger->warn(
				'Vault backfill failed — could not fetch order',
				array( 'post_id' => $post_id, 'order_id' => $order_id )
			);
			return;
		}

		$data        = $order_result['data'] ?? array();
		$vault       = $data['payment_source']['card']['attributes']['vault'] ?? array();
		$vault_id    = sanitize_text_field( (string) ( $vault['id'] ?? '' ) );
		$customer_id = sanitize_text_field( (string) ( $vault['customer']['id'] ?? '' ) );

		if ( '' === $vault_id ) {
			$this->logger->warn(
				'Vault backfill — order has no vault token',
				array( 'post_id' => $post_id, 'order_id' => $order_id )
			);
			return;
		}

		update_post_meta( $post_id, DonationMeta::VAULT_PAYMENT_TOKEN_ID, $vault_id );
		if ( '' !== $customer_id ) {
			update_post_meta( $post_id, DonationMeta::VAULT_CUSTOMER_ID, $customer_id );
		}
		update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, 'active' );

		// Schedule the next billing date so the renewal cron picks it up.
		$next_iso = $this->compute_next_billing_iso( $subscription_cycle );
		if ( '' !== $next_iso ) {
			$existing_next = (string) get_post_meta( $post_id, DonationMeta::SUBSCRIPTION_NEXT_BILLING, true );
			if ( '' === $existing_next ) {
				update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_NEXT_BILLING, $next_iso );
				update_post_meta( $post_id, DonationMeta::NEXT_BILLING_TIME, $next_iso );
			}
		}

		$this->logger->info(
			'Vault token backfilled from order (webhook race recovery)',
			array(
				'post_id'  => $post_id,
				'order_id' => $order_id,
				'vault_id' => $vault_id,
			)
		);
	}

	/**
	 * Compute the next ISO 8601 billing time for a subscription cycle.
	 *
	 * Mirrors RestController::compute_next_billing_iso so the webhook
	 * path can seed SUBSCRIPTION_NEXT_BILLING when it wins the race
	 * against /order/capture.
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

	public function transition( int $post_id, string $to_status, array $context ): bool {
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
			return false;
		}

		$this->repository->set_status( $post_id, $next_status );
		$this->repository->append_history( $post_id, $next_status, $context + array( 'from_status' => $from_status ) );
		$this->logger->info(
			'Webhook status transition applied',
			array(
				'post_id' => $post_id,
				'from'    => $from_status,
				'to'      => $next_status,
			)
		);
		return true;
	}

	/**
	 * Atomically claim a webhook event in the durable dedup table.
	 *
	 * Uses INSERT IGNORE on the UNIQUE event_id key. Returns true if this
	 * call inserted the row (caller owns processing); false if a previous
	 * delivery already claimed it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_id   PayPal event ID.
	 * @param string $event_type PayPal event type.
	 * @return bool True if claimed, false if already processed.
	 */
	/**
	 * Delete processed-event dedup rows older than the retention window.
	 *
	 * The wp_donadosu_processed_events table grew without bound (one row per
	 * webhook ever received). Pruning old rows keeps it from bloating while
	 * retaining a long-enough window to deduplicate any realistic redelivery.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function prune_processed_events(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'donadosu_processed_events';

		/**
		 * Filters how many days of webhook dedup rows to retain.
		 *
		 * @since 1.0.0
		 *
		 * @param int $days Retention window in days.
		 */
		$days   = (int) apply_filters( 'donadosu_processed_events_retention_days', 90 );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; value parameterised.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE received_at < %s", $cutoff ) );
	}

	private function claim_event_durable( string $event_id, string $event_type ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'donadosu_processed_events';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$inserted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internally constructed from $wpdb->prefix.
				"INSERT IGNORE INTO {$table} (event_id, event_type, received_at) VALUES (%s, %s, %s)",
				$event_id,
				$event_type,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return (int) $inserted === 1;
	}

	/**
	 * Release a previously claimed event so PayPal retries can process it later.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_id PayPal event ID.
	 * @return void
	 */
	private function release_event_durable( string $event_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'donadosu_processed_events';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'event_id' => $event_id ), array( '%s' ) );
	}

	/**
	 * Verify a captured amount/currency against the recorded gross, refunding
	 * and failing the donation on mismatch.
	 *
	 * Shared by every completion path (capture webhook, order-completed
	 * webhook) so a payment captured for a different amount than recorded is
	 * never silently marked completed.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $post_id    Donation post ID.
	 * @param float                $cap_value  Captured amount reported by PayPal.
	 * @param string               $cap_curr   Captured currency (uppercase).
	 * @param string               $capture_id Capture ID to refund on mismatch.
	 * @param array<string, mixed> $context    Transition context.
	 * @return bool True if the amount matches (safe to complete), false if a
	 *              mismatch was detected and the donation was failed/refunded.
	 */
	private function verify_capture_amount_or_refund( int $post_id, float $cap_value, string $cap_curr, string $capture_id, array $context ): bool {
		$expected_v = (float) get_post_meta( $post_id, DonationMeta::GROSS_AMOUNT, true );
		$expected_c = strtoupper( (string) get_post_meta( $post_id, DonationMeta::CURRENCY, true ) );

		if ( $expected_v <= 0 || ( abs( $cap_value - $expected_v ) <= 0.005 && $cap_curr === $expected_c ) ) {
			return true;
		}

		$this->logger->error(
			'Webhook capture amount/currency mismatch — auto-refunding',
			array(
				'post_id'           => $post_id,
				'expected_amount'   => $expected_v,
				'expected_currency' => $expected_c,
				'captured_amount'   => $cap_value,
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

		$this->transition( $post_id, 'donadosu_failed', $context + array( 'reason' => 'amount_mismatch' ) );
		return false;
	}

	public function is_event_globally_processed( string $event_id ): bool {
		return (bool) get_transient( 'donadosu_webhook_event_' . md5( $event_id ) );
	}

	/**
	 * Mark a webhook event as globally processed.
	 *
	 * Stores a transient for 24 hours to prevent reprocessing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_id PayPal event ID.
	 * @return void
	 */
	public function mark_event_globally_processed( string $event_id ): void {
		set_transient( 'donadosu_webhook_event_' . md5( $event_id ), 1, DAY_IN_SECONDS );
	}

	/**
	 * Queue a webhook for delayed retry via cron.
	 *
	 * Stores the webhook payload and headers in an options record for
	 * a cron job to reprocess later (useful for race conditions where
	 * the donation post hasn't been created yet).
	 *
	 * @since 1.0.0
	 *
	 * @param string                            $event_id   PayPal event ID.
	 * @param string                            $event_type PayPal event type.
	 * @param string                            $raw_body   Raw webhook body JSON.
	 * @param array<string, array<int, string>> $headers    Webhook request headers.
	 * @return void
	 */
	private function queue_webhook_for_retry( string $event_id, string $event_type, string $raw_body, array $headers ): void {
		$queued = get_option( 'donadosu_webhook_retry_queue', array() );
		if ( ! is_array( $queued ) ) {
			$queued = array();
		}

		// Store the webhook for retry with timestamp.
		$queued[ $event_id ] = array(
			'event_id'   => $event_id,
			'event_type' => $event_type,
			'raw_body'   => $raw_body,
			'headers'    => $headers,
			'queued_at'  => gmdate( 'c' ),
			'retry_count' => 0,
		);

		// Prune entries older than 7 days to prevent stale buildup.
		$cutoff = strtotime( '-7 days' );
		$queued = array_filter(
			$queued,
			static function ( array $entry ) use ( $cutoff ): bool {
				$queued_time = strtotime( (string) ( $entry['queued_at'] ?? '' ) );
				return false !== $queued_time && $queued_time > $cutoff;
			}
		);

		// Keep only last 100 queued webhooks to prevent unbounded growth.
		if ( count( $queued ) > 100 ) {
			$queued = array_slice( $queued, -100, 100, true );
		}

		update_option( 'donadosu_webhook_retry_queue', $queued, false );

		// Schedule a retry job if not already scheduled.
		if ( ! wp_next_scheduled( 'donadosu_webhook_retry_cron' ) ) {
			wp_schedule_event( time() + 300, 'donadosu_webhook_retry', 'donadosu_webhook_retry_cron' );
		}

		$this->logger->info(
			'Webhook queued for delayed retry',
			array(
				'event_id'   => $event_id,
				'event_type' => $event_type,
			)
		);
	}

	/**
	 * Read a header value from a WP REST headers array.
	 *
	 * WP_REST_Request::get_headers() canonicalizes header names to lowercase
	 * with hyphens replaced by underscores, but callers (and some test/queue
	 * paths) may supply the raw hyphenated form. Try both so we're resilient
	 * to either representation.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<int, string>> $headers Headers array.
	 * @param string                            $name    Header name in hyphenated lowercase form.
	 * @return string First header value, or empty string if not present.
	 */
	private function header_value( array $headers, string $name ): string {
		$underscored = str_replace( '-', '_', $name );
		if ( isset( $headers[ $underscored ][0] ) ) {
			return (string) $headers[ $underscored ][0];
		}
		if ( isset( $headers[ $name ][0] ) ) {
			return (string) $headers[ $name ][0];
		}
		return '';
	}

	/**
	 * Build a successful webhook response array.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $body Response body data.
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function ok( array $body ): array {
		return array(
			'status' => 200,
			'body'   => $body,
		);
	}
}
