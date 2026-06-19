<?php
/**
 * Donation Suite uninstall handler.
 *
 * Fired when the plugin is deleted via the WordPress Plugins screen.
 * Removes custom roles, scheduled cron events, and — when the
 * cleanup_on_uninstall setting is enabled — all plugin data
 * (donation posts, custom tables, options, transients, and the log
 * directory) so no data is left behind.
 *
 * @package Donation_Suite
 * @since   1.0.0
 * @version 1.1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$donadosu_settings = get_option( 'donadosu_settings', array() );
$donadosu_cleanup  = ! empty( $donadosu_settings['cleanup_on_uninstall'] );

/*
 * Always remove custom roles, capabilities, and scheduled cron
 * events regardless of the cleanup setting.
 */
wp_roles()->remove_role( 'donadosu_donation_viewer' );
wp_roles()->remove_role( 'donadosu_donation_manager' );

// Remove custom capabilities from the administrator role.
$donadosu_admin_role = get_role( 'administrator' );
if ( $donadosu_admin_role ) {
	$donadosu_admin_role->remove_cap( 'donadosu_view_donations' );
	$donadosu_admin_role->remove_cap( 'donadosu_export_donations' );
	$donadosu_admin_role->remove_cap( 'donadosu_manage_donations' );
}

wp_clear_scheduled_hook( 'donadosu_donation_retention' );
wp_clear_scheduled_hook( 'donadosu_donation_reconcile' );
wp_clear_scheduled_hook( 'donadosu_donation_year_end_summary' );
wp_clear_scheduled_hook( 'donadosu_webhook_retry_cron' );
wp_clear_scheduled_hook( 'donadosu_renewal_charges' );
wp_clear_scheduled_hook( 'donadosu_scheduled_export' );

delete_option( 'donadosu_activated_at' );
delete_option( 'donadosu_last_scheduled_export' );

// Review notice state (tiny options; cleaned regardless of the cleanup setting).
delete_option( 'donadosu_review_notice_hide' );
delete_option( 'donadosu_review_next_show' );

// Dismissed inline state banners (tiny option; cleaned regardless of the cleanup setting).
delete_option( 'donadosu_dismissed_notices' );

/*
 * If cleanup is enabled, delete all donation posts, custom tables,
 * options, transients, and the log directory.
 */
if ( $donadosu_cleanup ) {
	global $wpdb;

	// Delete donation posts in batches to avoid memory exhaustion.
	// Post meta is removed automatically via WordPress cascade.
	do {
		$donadosu_posts = get_posts(
			array(
				'post_type'   => 'donadosu_donation',
				'post_status' => 'any',
				'numberposts' => 200,
				'fields'      => 'ids',
			)
		);

		foreach ( $donadosu_posts as $donadosu_post_id ) {
			wp_delete_post( (int) $donadosu_post_id, true );
		}
	} while ( ! empty( $donadosu_posts ) );

	// Drop custom database tables.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}donadosu_processed_events" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}donadosu_campaigns" );

	// Delete all plugin options.
	delete_option( 'donadosu_settings' );
	delete_option( 'donadosu_webhook_health' );
	delete_option( 'donadosu_paypal_product_id' );
	delete_option( 'donadosu_db_version' );
	delete_option( 'donadosu_webhook_retry_queue' );
	delete_option( 'donadosu_campaign_goals' );
	delete_option( 'donadosu_cc_tokens' );

	// Clean up transients.
	delete_transient( 'donadosu_token_sandbox' );
	delete_transient( 'donadosu_token_live' );
	delete_transient( 'donadosu_analytics_stats' );
	delete_transient( 'donadosu_analytics_chart' );
	delete_transient( 'donadosu_campaign_tracking_stats' );

	// Clean up pattern-based transients (rate limiters, idempotency
	// caches, webhook dedup, portal tokens, year-end summary flags,
	// etc.) from the options table.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_donadosu_%' OR option_name LIKE '_transient_timeout_donadosu_%' OR option_name LIKE '_site_transient_donadosu_%' OR option_name LIKE '_site_transient_timeout_donadosu_%'"
	);

	// Remove the log directory (wp-content/uploads/donadosu-logs/).
	$donadosu_upload_dir = wp_upload_dir();
	if ( ! empty( $donadosu_upload_dir['basedir'] ) ) {
		$donadosu_log_dir = trailingslashit( $donadosu_upload_dir['basedir'] ) . 'donadosu-logs';

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! empty( $wp_filesystem ) && $wp_filesystem->is_dir( $donadosu_log_dir ) ) {
			// Recursively delete the log directory and its contents.
			$wp_filesystem->delete( $donadosu_log_dir, true );
		}
	}
}
