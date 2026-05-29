<?php
/**
 * Donation meta key constants.
 *
 * Centralises every post-meta key used by the plugin so that typos are
 * caught at compile time and IDE auto-completion works everywhere.
 *
 * @package    Donation_Suite
 * @subpackage Donation
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Donation;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DonationMeta
 *
 * String constants for every donation post-meta key.
 *
 * @since 1.0.0
 */
class DonationMeta {

	/**
	 * The PayPal environment the donation was created in (sandbox|live).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const ENV = '_donadosu_env';

	/**
	 * The PayPal order ID.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const ORDER_ID = '_donadosu_order_id';

	/**
	 * The PayPal capture ID.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const CAPTURE_ID = '_donadosu_capture_id';

	/**
	 * The donation amount (net of any fee coverage).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const AMOUNT = '_donadosu_amount';

	/**
	 * The donation currency code.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const CURRENCY = '_donadosu_currency';

	/**
	 * The donor locale at the time of donation.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const LOCALE = '_donadosu_locale';

	/**
	 * The donor email address.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const DONOR_EMAIL = '_donadosu_donor_email';

	/**
	 * The donor full name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const DONOR_NAME = '_donadosu_donor_name';

	/**
	 * An optional message from the donor.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const DONOR_MESSAGE = '_donadosu_donor_message';

	/**
	 * The donor phone number.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const DONOR_PHONE = '_donadosu_donor_phone';

	/**
	 * The donor company name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const DONOR_COMPANY = '_donadosu_donor_company';

	/**
	 * The donor street address.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const DONOR_ADDRESS = '_donadosu_donor_address';

	/**
	 * The donor city.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const DONOR_CITY = '_donadosu_donor_city';

	/**
	 * The donor postal / ZIP code.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const DONOR_POSTAL = '_donadosu_donor_postal';

	/**
	 * The donation frequency (one-time, monthly, annual).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const DONATION_FREQUENCY = '_donadosu_donation_frequency';

	/**
	 * The campaign slug associated with the donation.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const CAMPAIGN = '_donadosu_campaign';

	/**
	 * The stated purpose / fund designation.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const PURPOSE = '_donadosu_purpose';

	/**
	 * The generated receipt number.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const RECEIPT_NO = '_donadosu_receipt_no';

	/**
	 * ISO 8601 timestamp when the receipt email was sent.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const RECEIPT_SENT_AT = '_donadosu_receipt_sent_at';

	/**
	 * The receipt email delivery status (sent, failed, etc.).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const RECEIPT_EMAIL_STATUS = '_donadosu_receipt_email_status';

	/**
	 * Serialised array of status transition history entries.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const STATUS_HISTORY = '_donadosu_status_history';

	/**
	 * The ID of the last webhook event processed for this donation.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const LAST_WEBHOOK_EVENT_ID = '_donadosu_last_webhook_event_id';

	/**
	 * ISO 8601 timestamp of the last webhook event.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const LAST_WEBHOOK_EVENT_AT = '_donadosu_last_webhook_event_at';

	/**
	 * Serialised JSON blob of additional metadata.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const META_JSON = '_donadosu_meta_json';

	// ── Feature 1: Recurring / Subscriptions ─────────────────────────────────

	/**
	 * The PayPal subscription ID.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SUBSCRIPTION_ID = '_donadosu_subscription_id';

	/**
	 * The PayPal subscription plan ID.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SUBSCRIPTION_PLAN_ID = '_donadosu_subscription_plan_id';

	/**
	 * The current subscription status.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SUBSCRIPTION_STATUS = '_donadosu_subscription_status';

	/**
	 * ISO 8601 date of the next billing cycle.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SUBSCRIPTION_NEXT_BILLING = '_donadosu_subscription_next_billing';

	/**
	 * The subscription billing cycle (monthly|annual).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SUBSCRIPTION_CYCLE = '_donadosu_subscription_cycle';

	// ── Feature 2: Fee Coverage ───────────────────────────────────────────────

	/**
	 * Whether the donor opted to cover processing fees (1|0).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const FEE_COVERED = '_donadosu_fee_covered';

	/**
	 * The numeric fee amount the donor added.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const FEE_AMOUNT = '_donadosu_fee_amount';

	/**
	 * The gross amount sent to PayPal (amount + fee).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const GROSS_AMOUNT = '_donadosu_gross_amount';

	// ── Feature 3: Tribute Donations ─────────────────────────────────────────

	/**
	 * Whether this is a tribute donation (1|0).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const IS_TRIBUTE = '_donadosu_is_tribute';

	/**
	 * The tribute type (in_honor|in_memory).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const TRIBUTE_TYPE = '_donadosu_tribute_type';

	/**
	 * The name of the person being honoured or remembered.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const TRIBUTE_NAME = '_donadosu_tribute_name';

	/**
	 * The email address to notify about the tribute.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const TRIBUTE_NOTIFY_EMAIL = '_donadosu_tribute_notify_email';

	// ── Feature 4: Anonymous Donation ────────────────────────────────────────

	/**
	 * Whether the donation is anonymous (1|0).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const IS_ANONYMOUS = '_donadosu_is_anonymous';

	// ── Feature 5: Disputes ───────────────────────────────────────────────────

	/**
	 * The PayPal dispute ID.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const DISPUTE_ID = '_donadosu_dispute_id';

	/**
	 * The current dispute status.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const DISPUTE_STATUS = '_donadosu_dispute_status';

	/**
	 * The reason for the dispute.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const DISPUTE_REASON = '_donadosu_dispute_reason';

	// ── Feature 7: Giving Level ───────────────────────────────────────────────

	/**
	 * The giving level label (e.g. "Supporter").
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const GIVING_LEVEL = '_donadosu_giving_level';

	// ── Feature 10: Fraud ─────────────────────────────────────────────────────

	/**
	 * Whether the donation is flagged for fraud review (1|0).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const FRAUD_FLAG = '_donadosu_fraud_flag';

	// ── Milestone 2: Offline / manual donation source ─────────────────────────

	/**
	 * The payment source (paypal|cash|cheque|bank_transfer|other).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const PAYMENT_SOURCE = '_donadosu_payment_source';

	/**
	 * Reference or cheque number for offline donations.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const OFFLINE_REFERENCE = '_donadosu_offline_reference';

	// ── Subscription redirect ─────────────────────────────────────────────────

	/**
	 * The URL to redirect the donor to after subscription approval.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SUBSCRIPTION_RETURN_PAGE = '_donadosu_subscription_return_page';

	// ── Milestone 3: Subscription renewal payments ────────────────────────────

	/**
	 * Links a renewal payment post back to the original subscription activation post.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SUBSCRIPTION_PARENT_ID = '_donadosu_subscription_parent_id';

	/**
	 * The reason code for a failed subscription payment.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const LAST_SUBSCRIPTION_FAILURE_REASON = '_donadosu_last_subscription_failure_reason';

	/**
	 * ISO 8601 timestamp of the next scheduled billing attempt.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const NEXT_BILLING_TIME = '_donadosu_next_billing_time';

	// ── Milestone 4: Vaulted-card recurring (Orders API v2 + MIT) ─────────────

	/**
	 * The PayPal vaulted payment token ID returned by Orders v2 with
	 * payment_source.card.attributes.vault.store_in_vault=ON_SUCCESS.
	 *
	 * Used to create merchant-initiated renewal orders without donor
	 * presence (PayPal v1 Subscriptions does not support vaulted cards,
	 * so card-based recurring uses Orders v2 + MIT instead).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const VAULT_PAYMENT_TOKEN_ID = '_donadosu_vault_payment_token_id';

	/**
	 * The PayPal-issued customer ID associated with the vaulted card,
	 * required when creating merchant-initiated renewal orders.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const VAULT_CUSTOMER_ID = '_donadosu_vault_customer_id';

	/**
	 * Reason the vaulted-card subscription was last marked failed
	 * (e.g. card_declined, vault_token_invalid, network_error).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const RENEWAL_LAST_ERROR = '_donadosu_renewal_last_error';

	/**
	 * Count of consecutive failed renewal attempts for the current cycle.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const RENEWAL_FAIL_COUNT = '_donadosu_renewal_fail_count';
}
