<?php
/**
 * Donation repository interface.
 *
 * Defines the contract for all donation persistence operations so that
 * the storage back-end can be swapped without touching business logic.
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
 * Interface DonationRepositoryInterface
 *
 * Contract for donation storage and retrieval.
 *
 * @since 1.0.0
 */
interface DonationRepositoryInterface {

	/**
	 * Find a donation post by its PayPal order ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $order_id The PayPal order ID.
	 * @return int|null The post ID, or null if not found.
	 */
	public function find_by_order_id( string $order_id ): ?int;

	/**
	 * Find a donation post by its PayPal subscription ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subscription_id The PayPal subscription ID.
	 * @return int|null The post ID, or null if not found.
	 */
	public function find_by_subscription_id( string $subscription_id ): ?int;

	/**
	 * Create a new donation post or update an existing one by order ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $order_id The PayPal order ID.
	 * @param array<string, mixed> $data     Optional meta data to store.
	 * @return int The post ID, or 0 on failure.
	 */
	public function create_or_update_by_order_id( string $order_id, array $data = array() ): int;

	/**
	 * Set the post status of a donation.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id The donation post ID.
	 * @param string $status  The new post status.
	 * @return void
	 */
	public function set_status( int $post_id, string $status ): void;

	/**
	 * Append a status transition entry to the donation's history log.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $post_id The donation post ID.
	 * @param string               $status  The new status.
	 * @param array<string, mixed> $context Optional context data.
	 * @return void
	 */
	public function append_history( int $post_id, string $status, array $context = array() ): void;

	/**
	 * Mark a donation's receipt as sent and record the timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id The donation post ID.
	 * @param string $status  The receipt email status (sent, failed, etc.).
	 * @return void
	 */
	public function set_receipt_sent( int $post_id, string $status ): void;

	/**
	 * Sum the total completed donation amounts for a named campaign.
	 *
	 * Used by the shortcode for automatic goal progress calculation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $campaign The campaign slug.
	 * @return float The total amount.
	 */
	public function get_campaign_total( string $campaign ): float;

	/**
	 * Return all donation post IDs associated with a donor email address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email The donor email address.
	 * @param int    $limit Maximum number of results.
	 * @return int[] Array of post IDs.
	 */
	public function find_by_donor_email( string $email, int $limit = 100 ): array;

	/**
	 * Return aggregate statistics for the analytics dashboard widget.
	 *
	 * @since 1.0.0
	 *
	 * @return array{total_count:int,total_amount:float,month_count:int,month_amount:float,top_campaigns:array} Stats array.
	 */
	public function get_stats(): array;

	/**
	 * Return aggregate donor stats in a single query.
	 *
	 * Avoids N+1 on the donor profile page when a donor has many donations.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email The donor email address.
	 * @return array{total_completed:int,total_amount:float,currency:string,donor_name:string,first_date:string,last_date:string} Donor stats.
	 */
	public function get_donor_stats( string $email ): array;

	/**
	 * Return completed-donation counts and amounts grouped by calendar month.
	 *
	 * Returns data for the last $months months, most recent first.
	 *
	 * @since 1.0.0
	 *
	 * @param int $months Number of months to include.
	 * @return list<array{year:int,month:int,count:int,amount:float}> Monthly totals.
	 */
	public function get_monthly_totals( int $months = 12 ): array;

	/**
	 * Create a new donation post representing one renewal billing cycle.
	 *
	 * Copies donor / campaign meta from the parent post. For vault/MIT
	 * renewals the PayPal order ID is stored as ORDER_ID (so the
	 * PAYMENT.CAPTURE.COMPLETED webhook can resolve back to this post) and
	 * the capture/sale ID is stored separately. For wallet renewals
	 * (PAYMENT.SALE.COMPLETED, no order id issued) the sale ID is stored
	 * as ORDER_ID to preserve the historical lookup.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $parent_post_id The original subscription activation post ID.
	 * @param string $sale_id        The PayPal sale / transaction ID.
	 * @param float  $amount         The renewal amount.
	 * @param string $currency       The currency code.
	 * @param string $env            The PayPal environment (sandbox|live).
	 * @param string $order_id       Optional PayPal order ID for MIT renewals. When empty, the sale ID is used in its place.
	 * @return int The new post ID, or 0 on failure.
	 */
	public function create_renewal_payment( int $parent_post_id, string $sale_id, float $amount, string $currency, string $env, string $order_id = '' ): int;

	/**
	 * Return aggregate stats for every campaign that has at least one completed donation.
	 *
	 * Each row includes the campaign slug, total raised amount, donation count,
	 * unique donor count, and first/last donation dates.
	 *
	 * @since 1.0.0
	 *
	 * @return list<array{campaign:string,total_amount:float,donation_count:int,donor_count:int,first_donation:string,last_donation:string}> Campaign stats.
	 */
	public function get_all_campaign_stats(): array;
}
