<?php
/**
 * CPT-based donation repository.
 *
 * Implements DonationRepositoryInterface using the donadosu_donation custom
 * post type and wp_postmeta for all storage and retrieval operations.
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
 * Class CptDonationRepository
 *
 * Custom post type implementation of the donation repository.
 *
 * @since 1.0.0
 */
class CptDonationRepository implements DonationRepositoryInterface {

	/**
	 * SQL fragment matching the donation statuses that represent
	 * successfully-received revenue.
	 *
	 * Subscription activations (`donadosu_sub_active` / `donadosu_sub_paused`)
	 * cover the initial payment of a recurring donation: that charge is real
	 * money collected, and the parent post never transitions to
	 * `donadosu_completed`. Excluding them from report aggregates causes the
	 * Reports page to under-count revenue relative to what the admin list
	 * shows. Mirrors the behaviour already used by get_campaign_total() and
	 * CampaignStatsService::rebuild_stats().
	 *
	 * The fragment assumes the posts table is aliased as `p`.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const SUCCESS_STATUSES_SQL = "p.post_status IN ('donadosu_completed', 'donadosu_sub_active', 'donadosu_sub_paused')";

	/**
	 * Find a donation post by its PayPal order ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $order_id The PayPal order ID.
	 * @return int|null The post ID, or null if not found.
	 */
	public function find_by_order_id( string $order_id ): ?int {
		$posts = get_posts(
			array(
				'post_type'        => 'donadosu_donation',
				'post_status'      => 'any',
				'meta_key'         => DonationMeta::ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for donation lookup by meta field.
				'meta_value'       => $order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for donation lookup by meta field.
				'numberposts'      => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
			)
		);
		return $posts ? (int) $posts[0] : null;
	}

	/**
	 * Find a donation post by its PayPal subscription ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subscription_id The PayPal subscription ID.
	 * @return int|null The post ID, or null if not found.
	 */
	public function find_by_subscription_id( string $subscription_id ): ?int {
		$posts = get_posts(
			array(
				'post_type'        => 'donadosu_donation',
				'post_status'      => 'any',
				'meta_key'         => DonationMeta::SUBSCRIPTION_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for donation lookup by meta field.
				'meta_value'       => $subscription_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for donation lookup by meta field.
				'numberposts'      => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
			)
		);
		return $posts ? (int) $posts[0] : null;
	}

	/**
	 * Create a new donation post or update an existing one by order ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $order_id The PayPal order ID.
	 * @param array<string, mixed> $data     Optional meta data to store.
	 * @return int The post ID, or 0 on failure.
	 */
	public function create_or_update_by_order_id( string $order_id, array $data = array() ): int {
		$post_id = $this->find_by_order_id( $order_id );
		if ( ! $post_id ) {
			$result = wp_insert_post(
				array(
					'post_type'   => 'donadosu_donation',
					'post_status' => 'donadosu_created',
					/* translators: %s: date and time in Y-m-d H:i:s format */
				'post_title'  => sprintf( __( 'Donation %s', 'donateocean-donation-suite' ), gmdate( 'Y-m-d H:i:s' ) ),
				)
			);

			if ( is_wp_error( $result ) || 0 === $result ) {
				return 0;
			}

			$post_id = (int) $result;
		}

		update_post_meta( $post_id, DonationMeta::ORDER_ID, $order_id );
		foreach ( $data as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * Set the post status of a donation.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id The donation post ID.
	 * @param string $status  The new post status.
	 * @return void
	 */
	public function set_status( int $post_id, string $status ): void {
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $status,
			)
		);
	}

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
	public function append_history( int $post_id, string $status, array $context = array() ): void {
		$history   = get_post_meta( $post_id, DonationMeta::STATUS_HISTORY, true );
		$history   = is_array( $history ) ? $history : array();
		$history[] = array(
			'status'  => $status,
			'time'    => gmdate( 'c' ),
			'context' => $context,
		);
		update_post_meta( $post_id, DonationMeta::STATUS_HISTORY, $history );
	}

	/**
	 * Mark a donation's receipt as sent and record the timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id The donation post ID.
	 * @param string $status  The receipt email status (sent, failed, etc.).
	 * @return void
	 */
	public function set_receipt_sent( int $post_id, string $status ): void {
		update_post_meta( $post_id, DonationMeta::RECEIPT_EMAIL_STATUS, $status );
		update_post_meta( $post_id, DonationMeta::RECEIPT_SENT_AT, gmdate( 'c' ) );
	}

	/**
	 * Sum the total completed donation amounts for a named campaign.
	 *
	 * Uses a single JOIN query so there are no N+1 issues even on large datasets.
	 *
	 * @since 1.0.0
	 *
	 * @param string $campaign The campaign slug.
	 * @return float The total amount.
	 */
	public function get_campaign_total( string $campaign ): float {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate query; object cache not appropriate for dynamic financial summaries.
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(meta_amount.meta_value + 0)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} meta_campaign
				         ON p.ID = meta_campaign.post_id
				        AND meta_campaign.meta_key = %s
				        AND meta_campaign.meta_value = %s
				 INNER JOIN {$wpdb->postmeta} meta_amount
				         ON p.ID = meta_amount.post_id
				        AND meta_amount.meta_key = %s
				 WHERE p.post_type   = 'donadosu_donation'
				   AND (p.post_status = 'donadosu_completed' OR p.post_status = 'donadosu_sub_active' OR p.post_status = 'donadosu_sub_paused')",
				DonationMeta::CAMPAIGN,
				$campaign,
				DonationMeta::AMOUNT
			)
		);

		return (float) ( $result ?? 0.0 );
	}

	/**
	 * Return all donation post IDs associated with a donor email address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email The donor email address.
	 * @param int    $limit Maximum number of results.
	 * @return int[] Array of post IDs.
	 */
	public function find_by_donor_email( string $email, int $limit = 100 ): array {
		$posts = get_posts(
			array(
				'post_type'   => 'donadosu_donation',
				'post_status' => 'any',
				'meta_key'    => DonationMeta::DONOR_EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for donation lookup by meta field.
				'meta_value'  => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for donation lookup by meta field.
				'numberposts' => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'fields'      => 'ids',
			)
		);

		return array_map( 'intval', $posts );
	}

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
	public function get_donor_stats( string $email ): array {
		global $wpdb;

		// One query to get completed-donation aggregates for this donor.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate query; object cache not appropriate for dynamic financial summaries.
		$completed = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				     COUNT(*)                              AS total_completed,
				     SUM(meta_amount.meta_value + 0)      AS total_amount,
				     MIN(p.post_date_gmt)                 AS first_date,
				     MAX(p.post_date_gmt)                 AS last_date,
				     (SELECT meta_value
				      FROM {$wpdb->postmeta} mc
				      INNER JOIN {$wpdb->posts} pc ON pc.ID = mc.post_id
				      WHERE mc.meta_key = %s
				        AND pc.post_type = 'donadosu_donation'
				        AND pc.post_status = 'donadosu_completed'
				        AND EXISTS (
				            SELECT 1 FROM {$wpdb->postmeta} me
				            WHERE me.post_id = pc.ID AND me.meta_key = %s AND me.meta_value = %s
				        )
				      ORDER BY pc.post_date_gmt ASC
				      LIMIT 1)                            AS currency
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} meta_email
				         ON p.ID = meta_email.post_id
				        AND meta_email.meta_key = %s
				        AND meta_email.meta_value = %s
				 INNER JOIN {$wpdb->postmeta} meta_amount
				         ON p.ID = meta_amount.post_id
				        AND meta_amount.meta_key = %s
				 WHERE p.post_type   = 'donadosu_donation'
				   AND p.post_status = 'donadosu_completed'",
				DonationMeta::CURRENCY,
				DonationMeta::DONOR_EMAIL,
				$email,
				DonationMeta::DONOR_EMAIL,
				$email,
				DonationMeta::AMOUNT
			),
			ARRAY_A
		);

		// Separate query for donor name and date range (all statuses).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate query; object cache not appropriate for dynamic financial summaries.
		$overview = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT MIN(p.post_date_gmt) AS first_date,
				        MAX(p.post_date_gmt) AS last_date,
				        (SELECT meta_value
				         FROM {$wpdb->postmeta} mn
				         INNER JOIN {$wpdb->posts} pn ON pn.ID = mn.post_id
				         WHERE mn.meta_key = %s AND mn.meta_value != ''
				           AND pn.post_type = 'donadosu_donation'
				           AND EXISTS (
				               SELECT 1 FROM {$wpdb->postmeta} me2
				               WHERE me2.post_id = pn.ID AND me2.meta_key = %s AND me2.meta_value = %s
				           )
				         ORDER BY pn.post_date_gmt ASC
				         LIMIT 1)            AS donor_name
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} meta_email
				         ON p.ID = meta_email.post_id
				        AND meta_email.meta_key = %s
				        AND meta_email.meta_value = %s
				 WHERE p.post_type = 'donadosu_donation'",
				DonationMeta::DONOR_NAME,
				DonationMeta::DONOR_EMAIL,
				$email,
				DonationMeta::DONOR_EMAIL,
				$email
			),
			ARRAY_A
		);

		return array(
			'total_completed' => (int) ( $completed['total_completed'] ?? 0 ),
			'total_amount'    => (float) ( $completed['total_amount'] ?? 0.0 ),
			'currency'        => (string) ( $completed['currency'] ?? '' ),
			'donor_name'      => (string) ( $overview['donor_name'] ?? '' ),
			'first_date'      => (string) ( $overview['first_date'] ?? '' ),
			'last_date'       => (string) ( $overview['last_date'] ?? '' ),
		);
	}

	/**
	 * Return aggregate statistics for the analytics dashboard widget.
	 *
	 * All queries run against indexed columns (post_type, post_status, meta_key).
	 *
	 * @since 1.0.0
	 *
	 * @return array{total_count:int,total_amount:float,month_count:int,month_amount:float,top_campaigns:array} Stats array.
	 */
	public function get_stats(): array {
		global $wpdb;

		// All-time completed totals.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate query; object cache not appropriate for dynamic financial summaries.
		$total_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
				'donadosu_donation',
				'donadosu_completed'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate query; object cache not appropriate for dynamic financial summaries.
		$total_amount = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(pm.meta_value + 0)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				 WHERE p.post_type = 'donadosu_donation' AND p.post_status = 'donadosu_completed'",
				DonationMeta::AMOUNT
			)
		);

		// This calendar month.
		$month_start = gmdate( 'Y-m-01 00:00:00' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate query; object cache not appropriate for dynamic financial summaries.
		$month_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->posts}
				 WHERE post_type     = 'donadosu_donation'
				   AND post_status   = 'donadosu_completed'
				   AND post_date_gmt >= %s",
				$month_start
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate query; object cache not appropriate for dynamic financial summaries.
		$month_amount = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(pm.meta_value + 0)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				 WHERE p.post_type     = 'donadosu_donation'
				   AND p.post_status   = 'donadosu_completed'
				   AND p.post_date_gmt >= %s",
				DonationMeta::AMOUNT,
				$month_start
			)
		);

		// Top 5 campaigns by total amount.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate query; object cache not appropriate for dynamic financial summaries.
		$top_campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_campaign.meta_value AS campaign,
				        COUNT(*)                 AS donation_count,
				        SUM(meta_amount.meta_value + 0) AS total_amount
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} meta_campaign
				         ON p.ID = meta_campaign.post_id AND meta_campaign.meta_key = %s
				 INNER JOIN {$wpdb->postmeta} meta_amount
				         ON p.ID = meta_amount.post_id AND meta_amount.meta_key = %s
				 WHERE p.post_type   = 'donadosu_donation'
				   AND p.post_status = 'donadosu_completed'
				   AND meta_campaign.meta_value != ''
				 GROUP BY meta_campaign.meta_value
				 ORDER BY total_amount DESC
				 LIMIT 5",
				DonationMeta::CAMPAIGN,
				DonationMeta::AMOUNT
			),
			ARRAY_A
		);

		return array(
			'total_count'   => $total_count,
			'total_amount'  => $total_amount,
			'month_count'   => $month_count,
			'month_amount'  => $month_amount,
			'top_campaigns' => is_array( $top_campaigns ) ? $top_campaigns : array(),
		);
	}

	/**
	 * Return completed-donation counts and amounts grouped by calendar month.
	 *
	 * Single aggregation query that groups completed donations by year + month
	 * over a rolling window so the analytics chart never scans beyond what
	 * it needs.
	 *
	 * @since 1.0.0
	 *
	 * @param int $months Number of months to include.
	 * @return list<array{year:int,month:int,count:int,amount:float}> Monthly totals.
	 */
	public function get_monthly_totals( int $months = 12 ): array {
		global $wpdb;

		$since = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( $months - 1 ) . ' months', strtotime( gmdate( 'Y-m-01' ) ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate query; object cache not appropriate for dynamic financial summaries.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				     YEAR(p.post_date_gmt)           AS year,
				     MONTH(p.post_date_gmt)          AS month,
				     COUNT(*)                        AS count,
				     SUM(pm.meta_value + 0)          AS amount
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm
				         ON p.ID = pm.post_id AND pm.meta_key = %s
				 WHERE p.post_type     = 'donadosu_donation'
				   AND p.post_status   = 'donadosu_completed'
				   AND p.post_date_gmt >= %s
				 GROUP BY YEAR(p.post_date_gmt), MONTH(p.post_date_gmt)
				 ORDER BY year DESC, month DESC",
				DonationMeta::AMOUNT,
				$since
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn( array $row ): array => array(
				'year'   => (int) $row['year'],
				'month'  => (int) $row['month'],
				'count'  => (int) $row['count'],
				'amount' => (float) $row['amount'],
			),
			$rows
		);
	}

	/**
	 * Create a new donation post representing one renewal billing cycle.
	 *
	 * Copies donor / campaign meta from the parent subscription activation
	 * post so the renewal has its own receipt, its own post ID, and its own
	 * history -- but the donor does not have to re-enter their details.
	 *
	 * For MIT/vault renewals (where PayPal issues a distinct order id) the
	 * order id is stored as ORDER_ID and the capture/sale id as CAPTURE_ID.
	 * This lets the PAYMENT.CAPTURE.COMPLETED webhook resolve back to this
	 * post via find_by_order_id instead of being queued for retry. For
	 * PayPal wallet renewals (PAYMENT.SALE.COMPLETED — no order id) the
	 * sale id is stored in ORDER_ID to preserve existing lookups.
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
	public function create_renewal_payment( int $parent_post_id, string $sale_id, float $amount, string $currency, string $env, string $order_id = '' ): int {
		// Guard: don't create a duplicate if this sale/order ID was already recorded.
		// Look up by every identifier we know about so the dedup is robust whether
		// the caller is the wallet webhook (sale_id only) or the vault cron (both).
		$lookup_ids = array_unique( array_filter( array( $order_id, $sale_id ) ) );
		foreach ( $lookup_ids as $lookup_id ) {
			$existing = $this->find_by_order_id( $lookup_id );
			if ( $existing ) {
				return $existing;
			}
		}

		// Also check CAPTURE_ID for vault renewals recorded by prior cron runs.
		if ( '' !== $sale_id ) {
			$by_capture = get_posts(
				array(
					'post_type'     => 'donadosu_donation',
					'post_status'   => 'any',
					'meta_key'      => DonationMeta::CAPTURE_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required dedup lookup.
					'meta_value'    => $sale_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required dedup lookup.
					'numberposts'   => 1,
					'fields'        => 'ids',
					'no_found_rows' => true,
				)
			);
			if ( $by_capture ) {
				return (int) $by_capture[0];
			}
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'donadosu_donation',
				'post_status' => 'donadosu_completed',
				/* translators: %s: date and time in Y-m-d H:i:s format */
				'post_title'  => sprintf( __( 'Renewal %s', 'donateocean-donation-suite' ), gmdate( 'Y-m-d H:i:s' ) ),
			)
		);

		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			return 0;
		}

		$post_id = (int) $post_id;

		// Copy donor + campaign meta from the parent subscription post.
		$inherit_keys = array(
			DonationMeta::DONOR_EMAIL,
			DonationMeta::DONOR_NAME,
			DonationMeta::DONOR_PHONE,
			DonationMeta::DONOR_COMPANY,
			DonationMeta::DONOR_ADDRESS,
			DonationMeta::DONOR_CITY,
			DonationMeta::DONOR_POSTAL,
			DonationMeta::CAMPAIGN,
			DonationMeta::PURPOSE,
			DonationMeta::SUBSCRIPTION_ID,
			DonationMeta::SUBSCRIPTION_CYCLE,
			DonationMeta::IS_ANONYMOUS,
			DonationMeta::GIVING_LEVEL,
		);

		foreach ( $inherit_keys as $key ) {
			$value = get_post_meta( $parent_post_id, $key, true );
			if ( '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		// Renewal-specific meta.
		// For MIT/vault renewals: store the order ID here (webhook uses it);
		// capture ID is kept separately. For wallet renewals: sale_id serves
		// as the order ID since PayPal issues no distinct order id.
		$stored_order_id = '' !== $order_id ? $order_id : $sale_id;
		update_post_meta( $post_id, DonationMeta::ORDER_ID, $stored_order_id );
		if ( '' !== $sale_id && $sale_id !== $stored_order_id ) {
			update_post_meta( $post_id, DonationMeta::CAPTURE_ID, $sale_id );
		}
		update_post_meta( $post_id, DonationMeta::AMOUNT, $amount );
		update_post_meta( $post_id, DonationMeta::GROSS_AMOUNT, $amount );
		update_post_meta( $post_id, DonationMeta::CURRENCY, $currency );
		update_post_meta( $post_id, DonationMeta::ENV, $env );
		update_post_meta(
			$post_id,
			DonationMeta::DONATION_FREQUENCY,
			(string) get_post_meta( $parent_post_id, DonationMeta::SUBSCRIPTION_CYCLE, true ) ?: 'monthly'
		);
		update_post_meta( $post_id, DonationMeta::PAYMENT_SOURCE, 'paypal' );
		update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_PARENT_ID, $parent_post_id );
		update_post_meta( $post_id, DonationMeta::RECEIPT_NO, sprintf( 'RCPT-%s-%06d', gmdate( 'Y' ), $post_id ) );

		$this->append_history(
			$post_id,
			'donadosu_completed',
			array(
				'source'                 => 'subscription_renewal',
				'sale_id'                => $sale_id,
				'subscription_parent_id' => $parent_post_id,
			)
		);

		return $post_id;
	}

	/**
	 * Return aggregate stats for every campaign with at least one completed donation.
	 *
	 * Backed by the optimized campaign stats table, so only totals, counts,
	 * and unique donor counts are returned (no per-campaign date range).
	 *
	 * @since 1.0.0
	 *
	 * @return list<array{campaign:string,total_amount:float,donation_count:int,donor_count:int}> Campaign stats.
	 */
	public function get_all_campaign_stats(): array {
		// Use optimized campaign stats service instead of expensive postmeta joins.
		$stats_service = new \DonationSuite\Campaign\CampaignStatsService();
		return $stats_service->get_all_campaign_stats();
	}

	/**
	 * Return aggregate summary metrics for the reports page over a date range.
	 *
	 * Computes completed-donation counts, amounts, averages, unique donor count,
	 * new-donor count (donors whose earliest-ever donation falls in the window),
	 * and refund count/amount -- all in a handful of aggregate queries so the
	 * report loads quickly even on sites with tens of thousands of donations.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from     Start of window in 'Y-m-d H:i:s' GMT format (inclusive).
	 * @param string $to       End of window in 'Y-m-d H:i:s' GMT format (inclusive).
	 * @param string $currency Optional ISO-4217 currency to scope to. Empty = no filter.
	 *                         Without a filter, multi-currency sites end up summing
	 *                         amounts in different currencies as if they were the same.
	 * @return array{count:int,amount:float,avg_amount:float,unique_donors:int,new_donors:int,refund_count:int,refund_amount:float} Summary metrics.
	 */
	public function get_report_summary( string $from, string $to, string $currency = '' ): array {
		global $wpdb;

		$currency_join = '';
		$currency_args = array();
		if ( '' !== $currency ) {
			$currency_join = "INNER JOIN {$wpdb->postmeta} meta_currency
			         ON p.ID = meta_currency.post_id
			        AND meta_currency.meta_key = %s
			        AND meta_currency.meta_value = %s";
			$currency_args = array( DonationMeta::CURRENCY, $currency );
		}

		// Completed-donation aggregates (count, amount, unique donors).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Aggregate report query; caching handled by caller; SUCCESS_STATUSES_SQL is a hardcoded class constant with no user input.
		$summary = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				     COUNT(*)                                      AS count,
				     COALESCE(SUM(meta_amount.meta_value + 0), 0) AS amount,
				     COUNT(DISTINCT meta_email.meta_value)         AS unique_donors
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} meta_amount
				         ON p.ID = meta_amount.post_id
				        AND meta_amount.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} meta_email
				         ON p.ID = meta_email.post_id
				        AND meta_email.meta_key = %s
				        AND meta_email.meta_value != ''
				 " . $currency_join . "
				 WHERE p.post_type     = 'donadosu_donation'
				   AND " . self::SUCCESS_STATUSES_SQL . "
				   AND p.post_date_gmt BETWEEN %s AND %s",
				array_merge(
					array( DonationMeta::AMOUNT, DonationMeta::DONOR_EMAIL ),
					$currency_args,
					array( $from, $to )
				)
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$count         = (int) ( $summary['count'] ?? 0 );
		$amount        = (float) ( $summary['amount'] ?? 0.0 );
		$unique_donors = (int) ( $summary['unique_donors'] ?? 0 );
		$avg_amount    = $count > 0 ? $amount / $count : 0.0;

		// New donors: donors whose earliest-ever completed donation falls in the window.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Aggregate report query; caching handled by caller; SUCCESS_STATUSES_SQL is a hardcoded class constant with no user input.
		$new_donors = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
				     SELECT meta_email.meta_value AS email,
				            MIN(p.post_date_gmt)  AS first_donation
				     FROM {$wpdb->posts} p
				     INNER JOIN {$wpdb->postmeta} meta_email
				             ON p.ID = meta_email.post_id
				            AND meta_email.meta_key = %s
				            AND meta_email.meta_value != ''
				     " . $currency_join . "
				     WHERE p.post_type = 'donadosu_donation'
				       AND " . self::SUCCESS_STATUSES_SQL . "
				     GROUP BY meta_email.meta_value
				 ) first_times
				 WHERE first_times.first_donation BETWEEN %s AND %s",
				array_merge(
					array( DonationMeta::DONOR_EMAIL ),
					$currency_args,
					array( $from, $to )
				)
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		// Refunds in window.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate report query; caching handled by caller.
		$refunds = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				     COUNT(*)                                      AS count,
				     COALESCE(SUM(meta_amount.meta_value + 0), 0) AS amount
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} meta_amount
				        ON p.ID = meta_amount.post_id
				       AND meta_amount.meta_key = %s
				 " . $currency_join . "
				 WHERE p.post_type     = 'donadosu_donation'
				   AND p.post_status   = 'donadosu_refunded'
				   AND p.post_date_gmt BETWEEN %s AND %s",
				array_merge(
					array( DonationMeta::AMOUNT ),
					$currency_args,
					array( $from, $to )
				)
			),
			ARRAY_A
		);

		return array(
			'count'         => $count,
			'amount'        => $amount,
			'avg_amount'    => $avg_amount,
			'unique_donors' => $unique_donors,
			'new_donors'    => $new_donors,
			'refund_count'  => (int) ( $refunds['count'] ?? 0 ),
			'refund_amount' => (float) ( $refunds['amount'] ?? 0.0 ),
		);
	}

	/**
	 * Return completed-donation counts and amounts grouped by calendar day.
	 *
	 * Single aggregation query intended to back the daily trend chart.
	 * Returned rows are sorted oldest first; callers should fill gaps for
	 * days with no donations.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from     Start of window in 'Y-m-d H:i:s' GMT (inclusive).
	 * @param string $to       End of window in 'Y-m-d H:i:s' GMT (inclusive).
	 * @param string $currency Optional ISO-4217 currency to scope to.
	 * @return list<array{date:string,count:int,amount:float}> Daily totals.
	 */
	public function get_daily_totals( string $from, string $to, string $currency = '' ): array {
		global $wpdb;

		$currency_join = '';
		$currency_args = array();
		if ( '' !== $currency ) {
			$currency_join = "INNER JOIN {$wpdb->postmeta} meta_currency
			         ON p.ID = meta_currency.post_id
			        AND meta_currency.meta_key = %s
			        AND meta_currency.meta_value = %s";
			$currency_args = array( DonationMeta::CURRENCY, $currency );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Aggregate report query; caching handled by caller; SUCCESS_STATUSES_SQL is a hardcoded class constant with no user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				     DATE(p.post_date_gmt)           AS day,
				     COUNT(*)                        AS count,
				     COALESCE(SUM(pm.meta_value + 0), 0) AS amount
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm
				         ON p.ID = pm.post_id AND pm.meta_key = %s
				 " . $currency_join . "
				 WHERE p.post_type     = 'donadosu_donation'
				   AND " . self::SUCCESS_STATUSES_SQL . "
				   AND p.post_date_gmt BETWEEN %s AND %s
				 GROUP BY DATE(p.post_date_gmt)
				 ORDER BY day ASC",
				array_merge(
					array( DonationMeta::AMOUNT ),
					$currency_args,
					array( $from, $to )
				)
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn( array $row ): array => array(
				'date'   => (string) $row['day'],
				'count'  => (int) $row['count'],
				'amount' => (float) $row['amount'],
			),
			$rows
		);
	}

	/**
	 * Group completed donations in a window by an arbitrary meta key value.
	 *
	 * Powers the frequency, payment-source, purpose, and giving-level
	 * breakdowns on the reports page. Empty meta values are bucketed under
	 * 'unknown' so every completed donation is accounted for.
	 *
	 * @since 1.0.0
	 *
	 * @param string $meta_key The postmeta key to group by.
	 * @param string $from     Start of window in 'Y-m-d H:i:s' GMT (inclusive).
	 * @param string $to       End of window in 'Y-m-d H:i:s' GMT (inclusive).
	 * @param string $currency Optional ISO-4217 currency to scope to.
	 * @return list<array{value:string,count:int,amount:float}> Breakdown rows sorted by amount descending.
	 */
	public function get_breakdown_by_meta( string $meta_key, string $from, string $to, string $currency = '' ): array {
		global $wpdb;

		$currency_join = '';
		$currency_args = array();
		if ( '' !== $currency ) {
			$currency_join = "INNER JOIN {$wpdb->postmeta} meta_currency
			         ON p.ID = meta_currency.post_id
			        AND meta_currency.meta_key = %s
			        AND meta_currency.meta_value = %s";
			$currency_args = array( DonationMeta::CURRENCY, $currency );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Aggregate report query; caching handled by caller; SUCCESS_STATUSES_SQL is a hardcoded class constant with no user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				     COALESCE(NULLIF(meta_group.meta_value, ''), 'unknown') AS value,
				     COUNT(*)                                                AS count,
				     COALESCE(SUM(meta_amount.meta_value + 0), 0)           AS amount
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} meta_amount
				         ON p.ID = meta_amount.post_id
				        AND meta_amount.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} meta_group
				         ON p.ID = meta_group.post_id
				        AND meta_group.meta_key = %s
				 " . $currency_join . "
				 WHERE p.post_type     = 'donadosu_donation'
				   AND " . self::SUCCESS_STATUSES_SQL . "
				   AND p.post_date_gmt BETWEEN %s AND %s
				 GROUP BY value
				 ORDER BY amount DESC",
				array_merge(
					array( DonationMeta::AMOUNT, $meta_key ),
					$currency_args,
					array( $from, $to )
				)
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn( array $row ): array => array(
				'value'  => (string) $row['value'],
				'count'  => (int) $row['count'],
				'amount' => (float) $row['amount'],
			),
			$rows
		);
	}

	/**
	 * Bucket completed donations into standard fundraising gift-size bands.
	 *
	 * Uses the widely-used fundraising gift-range cohorts (grassroots,
	 * small, mid, leadership, major) so nonprofits can see revenue
	 * composition at a glance -- a core cultivation / major-gift KPI.
	 *
	 * Buckets are expressed in the site's reporting currency. Any mixed-
	 * currency edge cases are treated at face value; multi-currency sites
	 * should filter by currency client-side.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from     Start of window in 'Y-m-d H:i:s' GMT (inclusive).
	 * @param string $to       End of window in 'Y-m-d H:i:s' GMT (inclusive).
	 * @param string $currency Optional ISO-4217 currency to scope to. Strongly
	 *                         recommended on multi-currency sites: the cohort
	 *                         thresholds are raw amount numbers (25/100/500/1000),
	 *                         so without a currency filter a ¥1,000 donation
	 *                         (~$7) lands in the same band as a $1,000 donation.
	 * @return list<array{bucket:string,count:int,amount:float}> Bucket rows ordered from smallest to largest band.
	 */
	public function get_gift_size_breakdown( string $from, string $to, string $currency = '' ): array {
		global $wpdb;

		$currency_join = '';
		$currency_args = array();
		if ( '' !== $currency ) {
			$currency_join = "INNER JOIN {$wpdb->postmeta} meta_currency
			         ON p.ID = meta_currency.post_id
			        AND meta_currency.meta_key = %s
			        AND meta_currency.meta_value = %s";
			$currency_args = array( DonationMeta::CURRENCY, $currency );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Aggregate report query; caching handled by caller; SUCCESS_STATUSES_SQL is a hardcoded class constant with no user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				     CASE
				         WHEN (pm.meta_value + 0) < 25    THEN 'a_under_25'
				         WHEN (pm.meta_value + 0) < 100   THEN 'b_25_99'
				         WHEN (pm.meta_value + 0) < 500   THEN 'c_100_499'
				         WHEN (pm.meta_value + 0) < 1000  THEN 'd_500_999'
				         ELSE                                  'e_1000_plus'
				     END AS bucket,
				     COUNT(*)                                      AS count,
				     COALESCE(SUM(pm.meta_value + 0), 0)          AS amount
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm
				         ON p.ID = pm.post_id
				        AND pm.meta_key = %s
				 " . $currency_join . "
				 WHERE p.post_type     = 'donadosu_donation'
				   AND " . self::SUCCESS_STATUSES_SQL . "
				   AND p.post_date_gmt BETWEEN %s AND %s
				 GROUP BY bucket
				 ORDER BY bucket ASC",
				array_merge(
					array( DonationMeta::AMOUNT ),
					$currency_args,
					array( $from, $to )
				)
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn( array $row ): array => array(
				'bucket' => (string) $row['bucket'],
				'count'  => (int) $row['count'],
				'amount' => (float) $row['amount'],
			),
			$rows
		);
	}

	/**
	 * Return the top campaigns by total amount within a date range.
	 *
	 * Used by the reports page. Unlike get_all_campaign_stats() this is
	 * window-scoped and cannot be served from the denormalized stats table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from     Start of window in 'Y-m-d H:i:s' GMT (inclusive).
	 * @param string $to       End of window in 'Y-m-d H:i:s' GMT (inclusive).
	 * @param int    $limit    Maximum number of rows to return.
	 * @param string $currency Optional ISO-4217 currency to scope to.
	 * @return list<array{campaign:string,count:int,amount:float,donor_count:int}> Campaign rows.
	 */
	public function get_top_campaigns( string $from, string $to, int $limit = 10, string $currency = '' ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );

		$currency_join = '';
		$currency_args = array();
		if ( '' !== $currency ) {
			$currency_join = "INNER JOIN {$wpdb->postmeta} meta_currency
			         ON p.ID = meta_currency.post_id
			        AND meta_currency.meta_key = %s
			        AND meta_currency.meta_value = %s";
			$currency_args = array( DonationMeta::CURRENCY, $currency );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Aggregate report query; caching handled by caller; SUCCESS_STATUSES_SQL is a hardcoded class constant with no user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				     meta_campaign.meta_value                     AS campaign,
				     COUNT(*)                                      AS count,
				     COALESCE(SUM(meta_amount.meta_value + 0), 0) AS amount,
				     COUNT(DISTINCT meta_email.meta_value)         AS donor_count
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} meta_campaign
				         ON p.ID = meta_campaign.post_id
				        AND meta_campaign.meta_key = %s
				 INNER JOIN {$wpdb->postmeta} meta_amount
				         ON p.ID = meta_amount.post_id
				        AND meta_amount.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} meta_email
				         ON p.ID = meta_email.post_id
				        AND meta_email.meta_key = %s
				        AND meta_email.meta_value != ''
				 " . $currency_join . "
				 WHERE p.post_type     = 'donadosu_donation'
				   AND " . self::SUCCESS_STATUSES_SQL . "
				   AND p.post_date_gmt BETWEEN %s AND %s
				   AND meta_campaign.meta_value != ''
				 GROUP BY meta_campaign.meta_value
				 ORDER BY amount DESC
				 LIMIT %d",
				array_merge(
					array( DonationMeta::CAMPAIGN, DonationMeta::AMOUNT, DonationMeta::DONOR_EMAIL ),
					$currency_args,
					array( $from, $to, $limit )
				)
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn( array $row ): array => array(
				'campaign'    => (string) $row['campaign'],
				'count'       => (int) $row['count'],
				'amount'      => (float) $row['amount'],
				'donor_count' => (int) $row['donor_count'],
			),
			$rows
		);
	}
}
