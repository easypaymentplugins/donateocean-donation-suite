<?php
/**
 * Campaign statistics service.
 *
 * Manages a dedicated database table for fast campaign statistics queries.
 * Instead of expensive JOINs on postmeta, this service maintains a normalized
 * campaigns table with pre-calculated totals.
 *
 * @package    Donation_Suite
 * @subpackage Campaign
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Campaign;

use DonationSuite\Donation\DonationMeta;
use DonationSuite\Logging\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignStatsService
 *
 * Maintains campaign statistics in a dedicated table for fast retrieval.
 *
 * @since 1.0.0
 */
class CampaignStatsService {

	/**
	 * Logger instance.
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
	 * @param Logger|null $logger Optional logger instance.
	 */
	public function __construct( ?Logger $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * Register hooks for campaign stats updates.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		// Update campaign stats when a donation is completed.
		add_action( 'donadosu_donation_completed', array( $this, 'update_campaign_stats_on_completion' ), 5, 2 );

		// Update when donations are manually created.
		add_action( 'donadosu_manual_donation_created', array( $this, 'update_campaign_stats_on_completion' ), 5, 2 );
	}

	/**
	 * Update campaign statistics when a donation is completed.
	 *
	 * Called after a donation is completed. Updates the campaign stats table
	 * to reflect the new donation amount and count.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id The donation post ID.
	 * @param array $payload The webhook payload (if from webhook).
	 * @return void
	 */
	public function update_campaign_stats_on_completion( int $post_id, $payload = array() ): void {
		$campaign = (string) get_post_meta( $post_id, DonationMeta::CAMPAIGN, true );

		// Skip if no campaign is set (e.g., anonymous or non-campaign donations).
		if ( '' === $campaign ) {
			return;
		}

		$amount = (float) get_post_meta( $post_id, DonationMeta::GROSS_AMOUNT, true );
		if ( 0 === $amount ) {
			$amount = (float) get_post_meta( $post_id, DonationMeta::AMOUNT, true );
		}

		$donor_email = (string) get_post_meta( $post_id, DonationMeta::DONOR_EMAIL, true );

		$this->update_campaign_stats( $campaign, $amount, $donor_email );
	}

	/**
	 * Update campaign statistics in the dedicated table.
	 *
	 * Inserts or updates the campaign record with new totals.
	 *
	 * @since 1.0.0
	 *
	 * @param string $campaign_name The campaign slug/name.
	 * @param float  $amount        The donation amount to add.
	 * @param string $donor_email   The donor email (for unique donor tracking).
	 * @return bool True on success.
	 */
	public function update_campaign_stats( string $campaign_name, float $amount, string $donor_email = '' ): bool {
		global $wpdb;

		$table_name = $this->get_table_name();

		// Get the current stats for this campaign.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$current = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internally constructed from $wpdb->prefix.
				"SELECT total_amount, total_count, unique_donors_json FROM {$table_name} WHERE campaign_name = %s",
				$campaign_name
			)
		);

		if ( $current ) {
			// Campaign exists, update it.
			$new_total_count = (int) $current->total_count + 1;
			$new_total_amount = (float) $current->total_amount + $amount;

			// Track unique donors (store as JSON to avoid normalization complexity).
			$unique_donors = json_decode( (string) $current->unique_donors_json, true );
			if ( ! is_array( $unique_donors ) ) {
				$unique_donors = array();
			}
			if ( '' !== $donor_email && ! isset( $unique_donors[ $donor_email ] ) ) {
				$unique_donors[ $donor_email ] = true;
			}

			$unique_count = count( $unique_donors );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$table_name,
				array(
					'total_amount'        => $new_total_amount,
					'total_count'         => $new_total_count,
					'unique_donors'       => $unique_count,
					'unique_donors_json'  => wp_json_encode( $unique_donors ),
					'last_updated'        => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'campaign_name' => $campaign_name ),
				array( '%f', '%d', '%d', '%s', '%s' ),
				array( '%s' )
			);

			if ( false === $updated ) {
				$this->log( 'error', 'Failed to update campaign stats', array( 'campaign' => $campaign_name ) );
				return false;
			}

			wp_cache_delete( 'donadosu_all_campaign_stats', 'donateocean-donation-suite' );
			$this->log( 'debug', 'Campaign stats updated', array( 'campaign' => $campaign_name, 'amount' => $amount ) );
			return true;
		} else {
			// Campaign doesn't exist, insert it.
			$unique_donors = array();
			if ( '' !== $donor_email ) {
				$unique_donors[ $donor_email ] = true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert(
				$table_name,
				array(
					'campaign_name'      => $campaign_name,
					'total_amount'       => $amount,
					'total_count'        => 1,
					'unique_donors'      => count( $unique_donors ),
					'unique_donors_json' => wp_json_encode( $unique_donors ),
					'last_updated'       => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%f', '%d', '%d', '%s', '%s' )
			);

			if ( false === $inserted ) {
				$this->log( 'error', 'Failed to insert campaign stats', array( 'campaign' => $campaign_name ) );
				return false;
			}

			wp_cache_delete( 'donadosu_all_campaign_stats', 'donateocean-donation-suite' );
			$this->log( 'debug', 'Campaign stats inserted', array( 'campaign' => $campaign_name, 'amount' => $amount ) );
			return true;
		}
	}

	/**
	 * Get all campaign statistics from the optimized table.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{campaign: string, total_amount: float, donation_count: int, donor_count: int}> Array of campaign stats.
	 */
	public function get_all_campaign_stats(): array {
		global $wpdb;

		$table_name = $this->get_table_name();

		$cache_key   = 'donadosu_all_campaign_stats';
		$cache_group = 'donateocean-donation-suite';
		$cached      = wp_cache_get( $cache_key, $cache_group );

		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
			$wpdb->prepare( 'SELECT campaign_name, total_amount, total_count, unique_donors FROM %i ORDER BY total_amount DESC', $table_name ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$result = array_map(
			static fn( array $row ): array => array(
				'campaign'       => (string) $row['campaign_name'],
				'total_amount'   => (float) $row['total_amount'],
				'donation_count' => (int) $row['total_count'],
				'donor_count'    => (int) $row['unique_donors'],
			),
			$rows
		);

		wp_cache_set( $cache_key, $result, $cache_group );

		return $result;
	}

	/**
	 * Rebuild campaign statistics from donations.
	 *
	 * Useful after plugin updates or data migrations. Clears existing campaign
	 * stats and recalculates from all completed/active donations.
	 *
	 * @since 1.0.0
	 *
	 * @return int The number of campaigns processed.
	 */
	public function rebuild_stats(): int {
		global $wpdb;

		$table_name = $this->get_table_name();

		// Clear existing stats.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name ) );

		$this->log( 'info', 'Campaign stats table cleared for rebuild' );

		// Re-aggregate all completed donations.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				     meta_campaign.meta_value AS campaign,
				     meta_amount.meta_value   AS amount,
				     meta_email.meta_value    AS email
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
				 WHERE p.post_type = 'donadosu_donation'
				   AND (p.post_status = 'donadosu_completed' OR p.post_status = 'donadosu_sub_active' OR p.post_status = 'donadosu_sub_paused')
				   AND meta_campaign.meta_value != ''
				 ORDER BY p.ID",
				DonationMeta::CAMPAIGN,
				DonationMeta::GROSS_AMOUNT,
				DonationMeta::DONOR_EMAIL
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			$this->log( 'info', 'No donations found for campaign stats rebuild' );
			return 0;
		}

		// Group and aggregate.
		$campaigns = array();
		foreach ( $rows as $row ) {
			$campaign = (string) $row['campaign'];
			$amount   = (float) ( $row['amount'] ?? 0 );
			$email    = (string) ( $row['email'] ?? '' );

			if ( ! isset( $campaigns[ $campaign ] ) ) {
				$campaigns[ $campaign ] = array(
					'total_amount'   => 0,
					'total_count'    => 0,
					'unique_donors'  => array(),
				);
			}

			$campaigns[ $campaign ]['total_amount'] += $amount;
			$campaigns[ $campaign ]['total_count']  += 1;
			if ( '' !== $email ) {
				$campaigns[ $campaign ]['unique_donors'][ $email ] = true;
			}
		}

		// Insert into the table.
		$count = 0;
		foreach ( $campaigns as $name => $stats ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert(
				$table_name,
				array(
					'campaign_name'      => $name,
					'total_amount'       => $stats['total_amount'],
					'total_count'        => $stats['total_count'],
					'unique_donors'      => count( $stats['unique_donors'] ),
					'unique_donors_json' => wp_json_encode( $stats['unique_donors'] ),
					'last_updated'       => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%f', '%d', '%d', '%s', '%s' )
			);

			if ( $inserted ) {
				$count++;
			}
		}

		wp_cache_delete( 'donadosu_all_campaign_stats', 'donateocean-donation-suite' );
		$this->log( 'info', 'Campaign stats rebuilt', array( 'campaigns' => $count ) );

		return $count;
	}

	/**
	 * Get the database table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'donadosu_campaigns';
	}

	/**
	 * Log a message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 * @return void
	 */
	private function log( string $level, string $message, array $context = array() ): void {
		if ( $this->logger ) {
			$this->logger->$level( $message, $context );
		}
	}
}
