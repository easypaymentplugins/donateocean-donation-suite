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
	public const DB_VERSION = '2';

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

		// 3. Normalized donations table for fast reporting queries.
		$table_donations = $wpdb->prefix . 'donadosu_donations';
		$sql_donations   = "CREATE TABLE {$table_donations} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(20,6) NOT NULL DEFAULT 0,
			gross_amount DECIMAL(20,6) NOT NULL DEFAULT 0,
			fee_amount DECIMAL(20,6) NOT NULL DEFAULT 0,
			currency VARCHAR(3) NOT NULL DEFAULT '',
			status VARCHAR(30) NOT NULL DEFAULT '',
			donor_email VARCHAR(320) NOT NULL DEFAULT '',
			donor_name VARCHAR(255) NOT NULL DEFAULT '',
			campaign VARCHAR(255) NOT NULL DEFAULT '',
			purpose VARCHAR(255) NOT NULL DEFAULT '',
			frequency VARCHAR(20) NOT NULL DEFAULT 'one_time',
			payment_source VARCHAR(30) NOT NULL DEFAULT 'paypal',
			is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			UNIQUE KEY post_id (post_id),
			KEY status (status),
			KEY donor_email (donor_email(191)),
			KEY campaign (campaign(191)),
			KEY created_at (created_at),
			KEY status_created (status, created_at),
			KEY status_campaign (status, campaign(191))
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_events );
		dbDelta( $sql_campaigns );
		dbDelta( $sql_donations );

		self::backfill_normalized_table();

		update_option( 'donadosu_db_version', self::DB_VERSION, false );
	}

	/**
	 * Backfill the normalized donations table from existing postmeta.
	 *
	 * Runs in batches to avoid memory exhaustion.
	 *
	 * @since 1.0.5
	 *
	 * @return int Number of rows synced.
	 */
	public static function backfill_normalized_table(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'donadosu_donations';
		$count = 0;
		$page  = 1;

		do {
			$posts = get_posts( array(
				'post_type'      => 'donadosu_donation',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'paged'          => $page,
				'fields'         => 'ids',
			) );

			if ( empty( $posts ) ) {
				break;
			}

			update_meta_cache( 'post', $posts );

			foreach ( $posts as $post_id ) {
				$post = get_post( $post_id );
				if ( ! $post ) {
					continue;
				}

				$meta = static function ( string $key ) use ( $post_id ) {
					return (string) get_post_meta( $post_id, $key, true );
				};

				$amount       = (float) $meta( \DonationSuite\Donation\DonationMeta::AMOUNT );
				$gross_amount = (float) ( $meta( \DonationSuite\Donation\DonationMeta::GROSS_AMOUNT ) ?: $amount );
				$fee_amount   = (float) $meta( \DonationSuite\Donation\DonationMeta::FEE_AMOUNT );
				$frequency    = $meta( \DonationSuite\Donation\DonationMeta::DONATION_FREQUENCY ) ?: 'one_time';

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Upsert into a plugin-owned table; table name from $wpdb->prefix, all values parameterised.
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$table}
							(post_id, amount, gross_amount, fee_amount, currency, status,
							 donor_email, donor_name, campaign, purpose, frequency,
							 payment_source, is_anonymous, created_at)
						VALUES (%d, %f, %f, %f, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s)
						ON DUPLICATE KEY UPDATE
							amount         = VALUES(amount),
							gross_amount   = VALUES(gross_amount),
							fee_amount     = VALUES(fee_amount),
							currency       = VALUES(currency),
							status         = VALUES(status),
							donor_email    = VALUES(donor_email),
							donor_name     = VALUES(donor_name),
							campaign       = VALUES(campaign),
							purpose        = VALUES(purpose),
							frequency      = VALUES(frequency),
							payment_source = VALUES(payment_source),
							is_anonymous   = VALUES(is_anonymous)",
						$post_id,
						$amount,
						$gross_amount,
						$fee_amount,
						$meta( \DonationSuite\Donation\DonationMeta::CURRENCY ),
						$post->post_status,
						$meta( \DonationSuite\Donation\DonationMeta::DONOR_EMAIL ),
						$meta( \DonationSuite\Donation\DonationMeta::DONOR_NAME ),
						$meta( \DonationSuite\Donation\DonationMeta::CAMPAIGN ),
						$meta( \DonationSuite\Donation\DonationMeta::PURPOSE ),
						$frequency,
						$meta( \DonationSuite\Donation\DonationMeta::PAYMENT_SOURCE ) ?: 'paypal',
						'1' === $meta( \DonationSuite\Donation\DonationMeta::IS_ANONYMOUS ) ? 1 : 0,
						$post->post_date_gmt
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$count++;
			}

			wp_cache_flush();
			$page++;
		} while ( true );

		return $count;
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
			// Never autoload: this option holds PayPal and integration secrets,
			// so it should not be loaded into memory on every front-end request.
			update_option( ConfigService::OPTION_KEY, $defaults, false );
		} else {
			// Merge new defaults into existing settings without overwriting.
			update_option( ConfigService::OPTION_KEY, wp_parse_args( (array) $existing, $defaults ), false );
		}

		// Flip the autoload flag off for installs that saved the option before
		// it was marked non-autoloaded. wp_set_option_autoload() arrived in WP 6.4, so it
		// is called indirectly (via a variable) to satisfy static analysis on the 6.0
		// minimum, while function_exists() still guards the call on older installs.
		$set_autoload = 'wp_set_option_autoload';
		if ( function_exists( $set_autoload ) ) {
			$set_autoload( ConfigService::OPTION_KEY, false );
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

		// Scheduled CSV export — daily tick that decides internally whether the
		// configured cadence (weekly/monthly) is due before building/emailing.
		if ( ! wp_next_scheduled( 'donadosu_scheduled_export' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'donadosu_scheduled_export' );
		}

		// Register custom roles on activation.
		Capabilities::register_roles();

		// Create the log directory so it is ready when logging is enabled.
		$logger = new \DonationSuite\Logging\Logger();
		$logger->ensure_log_directory();
	}
}
