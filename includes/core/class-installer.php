<?php
/**
 * Plugin installer.
 *
 * Handles activation tasks such as scheduling cron events and
 * registering custom roles.
 *
 * @package    Donation_Suite
 * @subpackage Core
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Installer
 *
 * Runs one-time setup tasks on plugin activation.
 *
 * @since 1.0.0
 */
class Installer {

	/**
	 * Current schema version. Bump to trigger dbDelta on upgrade.
	 *
	 * @since 1.0.0
	 */
	public const DB_VERSION = '1';

	/**
	 * Create or upgrade custom database tables.
	 *
	 * Creates:
	 * 1. donadosu_processed_events - durable webhook deduplication table
	 * 2. donadosu_campaigns - normalized campaign statistics table
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function install_schema(): void {
		global $wpdb;

		$installed = (string) get_option( 'donadosu_db_version', '' );
		if ( $installed === self::DB_VERSION ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		// 1. Durable processed-events dedup table (webhook handler).
		$table_events = $wpdb->prefix . 'donadosu_processed_events';
		$sql_events   = "CREATE TABLE {$table_events} (
			event_id VARCHAR(64) NOT NULL,
			event_type VARCHAR(80) NOT NULL DEFAULT '',
			post_id BIGINT UNSIGNED NULL,
			received_at DATETIME NOT NULL,
			PRIMARY KEY  (event_id),
			KEY received_at (received_at)
		) {$charset_collate};";

		// 2. Campaign statistics table (optimized queries).
		$table_campaigns = $wpdb->prefix . 'donadosu_campaigns';
		$sql_campaigns   = "CREATE TABLE {$table_campaigns} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_name VARCHAR(255) NOT NULL,
			total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			total_count INT UNSIGNED NOT NULL DEFAULT 0,
			unique_donors INT UNSIGNED NOT NULL DEFAULT 0,
			unique_donors_json LONGTEXT,
			last_updated DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY campaign_name (campaign_name),
			KEY total_amount (total_amount),
			KEY last_updated (last_updated)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_events );
		dbDelta( $sql_campaigns );

		update_option( 'donadosu_db_version', self::DB_VERSION, false );
	}

	/**
	 * Activate the plugin.
	 *
	 * Records the activation timestamp, schedules cron events for
	 * retention cleanup, reconciliation, and year-end summaries,
	 * and registers custom roles.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Record activation time (used by the webhook health notice guard).
		if ( ! get_option( 'donadosu_activated_at' ) ) {
			update_option( 'donadosu_activated_at', time(), false );
		}

		// Save default settings to the database if not already present.
		$config   = new ConfigService();
		$defaults = $config->get_all();
		$existing = get_option( ConfigService::OPTION_KEY, false );
		if ( false === $existing ) {
			update_option( ConfigService::OPTION_KEY, $defaults );
		} else {
			// Merge new defaults into existing settings without overwriting.
			update_option( ConfigService::OPTION_KEY, wp_parse_args( (array) $existing, $defaults ) );
		}

		// Create / upgrade custom tables.
		self::install_schema();

		// Scheduled tasks.
		if ( ! wp_next_scheduled( 'donadosu_donation_retention' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'donadosu_donation_retention' );
		}

		if ( ! wp_next_scheduled( 'donadosu_donation_reconcile' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'donadosu_donation_reconcile' );
		}

		// Card-recurring renewals — daily tick charges any vaulted-card
		// subscription whose next billing date has passed.
		if ( ! wp_next_scheduled( 'donadosu_renewal_charges' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'donadosu_renewal_charges' );
		}

		// Year-end summary — schedule for Jan 1 at 08:00 UTC.
		if ( ! wp_next_scheduled( 'donadosu_donation_year_end_summary' ) ) {
			$next_jan_1 = gmmktime( 8, 0, 0, 1, 1, (int) gmdate( 'Y' ) + 1 );
			wp_schedule_event( $next_jan_1, 'donadosu_yearly', 'donadosu_donation_year_end_summary' );
		}

		// Register custom roles on activation.
		Capabilities::register_roles();

		// Create the log directory so it is ready when logging is enabled.
		$logger = new \DonationSuite\Logging\Logger();
		$logger->ensure_log_directory();
	}
}
