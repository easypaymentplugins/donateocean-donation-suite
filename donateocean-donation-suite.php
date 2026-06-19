<?php
/**
 * Plugin Name:       DonateOcean – Donations via PayPal
 * Plugin URI:        https://wordpress.org/plugins/donateocean-donation-suite
 * Description:       Accept secure PayPal donations in WordPress with webhook-verified completion, donation tracking, automated receipts, and a full charity-ready admin suite.
 * Version:           1.0.6
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            easypayment
 * Author URI:        https://profiles.wordpress.org/easypayment/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       donateocean-donation-suite
 * Domain Path:       /languages
 *
 * @package Donation_Suite
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * @since 1.0.0
 * @var string
 */
define( 'DONADOSU_VERSION', '1.0.6' );

/**
 * Absolute path to the main plugin file.
 *
 * @since 1.0.0
 * @var string
 */
define( 'DONADOSU_FILE', __FILE__ );

/**
 * Absolute path to the plugin directory (with trailing slash).
 *
 * @since 1.0.0
 * @var string
 */
define( 'DONADOSU_PATH', plugin_dir_path( __FILE__ ) );

/**
 * URL to the plugin directory (with trailing slash).
 *
 * @since 1.0.0
 * @var string
 */
define( 'DONADOSU_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename (folder/file.php).
 *
 * Used by the deactivation feedback modal to build the deactivate link.
 *
 * @since 1.0.6
 * @var string
 */
if ( ! defined( 'DONADOSU_BASENAME' ) ) {
	define( 'DONADOSU_BASENAME', plugin_basename( DONADOSU_FILE ) );
}

define( 'DONADOSU_FEEDBACK_ENDPOINT', 'https://api.airtable.com/v0/appxxiU87VQWG6rOO/Sheet1' );
define( 'DONADOSU_FEEDBACK_TOKEN', 'patgeqj8DJfPjqZbS.9223810d432db4efccf27354c08513a7725e4a08d11a85fba75de07a539c8aeb' );

/**
 * Minimum PHP version requirement check.
 *
 * @since 1.0.0
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Donation Suite requires PHP 7.4 or later.', 'donateocean-donation-suite' );
			echo '</p></div>';
		}
	);
	return;
}

/**
 * Minimum WordPress version requirement check.
 *
 * @since 1.0.0
 */
if ( version_compare( get_bloginfo( 'version' ), '6.0', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Donation Suite requires WordPress 6.0 or later.', 'donateocean-donation-suite' );
			echo '</p></div>';
		}
	);
	return;
}

/**
 * Load the autoloader and bootstrap the plugin.
 *
 * Prefers the Composer autoloader when available (development),
 * otherwise falls back to the built-in WP-style autoloader.
 *
 * @since 1.0.0
 */
$donadosu_autoloader = DONADOSU_PATH . 'vendor/autoload.php';

if ( file_exists( $donadosu_autoloader ) ) {
	require_once $donadosu_autoloader;
} else {
	require_once DONADOSU_PATH . 'includes/class-autoloader.php';
	Donadosu_Donation_Suite_Autoloader::register();
}

/**
 * Register the activation hook.
 *
 * @since 1.0.0
 */
register_activation_hook( (string) DONADOSU_FILE, array( 'DonationSuite\\Core\\Installer', 'activate' ) );

/**
 * Register the deactivation hook.
 *
 * Clears scheduled cron events so they do not fire while the plugin is inactive.
 *
 * @since 1.0.0
 */
register_deactivation_hook(
	(string) DONADOSU_FILE,
	static function (): void {
		wp_clear_scheduled_hook( 'donadosu_donation_retention' );
		wp_clear_scheduled_hook( 'donadosu_donation_reconcile' );
		wp_clear_scheduled_hook( 'donadosu_donation_year_end_summary' );
		wp_clear_scheduled_hook( 'donadosu_webhook_retry_cron' );
		wp_clear_scheduled_hook( 'donadosu_renewal_charges' );
		wp_clear_scheduled_hook( 'donadosu_scheduled_export' );
	}
);

/**
 * Redirect to settings page on first activation.
 *
 * @since 1.0.0
 */
add_action(
	'activated_plugin',
	static function ( string $plugin ): void {
		if ( $plugin === plugin_basename( (string) DONADOSU_FILE ) ) {
			if ( ! wp_doing_ajax() && ! wp_doing_cron() && is_admin() ) {
				wp_safe_redirect( admin_url( 'admin.php?page=donadosu-settings' ) );
				exit;
			}
		}
	}
);

/**
 * Add Settings and Donation History links to the plugin action links.
 *
 * @since 1.0.0
 */
add_filter(
	'plugin_action_links_' . plugin_basename( (string) DONADOSU_FILE ),
	static function ( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=donadosu-settings' ) ),
			esc_html__( 'Settings', 'donateocean-donation-suite' )
		);
		$donations_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'edit.php?post_type=donadosu_donation' ) ),
			esc_html__( 'Donation History', 'donateocean-donation-suite' )
		);
		array_unshift( $links, $settings_link, $donations_link );
		return $links;
	}
);

/**
 * Add community support and review links to the plugin row meta.
 *
 * @since 1.0.6
 */
add_filter(
	'plugin_row_meta',
	static function ( array $meta, string $file ): array {
		if ( DONADOSU_BASENAME === $file ) {
			$meta[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( 'https://wordpress.org/support/plugin/donateocean-donation-suite/' ),
				esc_html__( 'Community Support', 'donateocean-donation-suite' )
			);
			$meta[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( 'https://wordpress.org/support/plugin/donateocean-donation-suite/reviews/#new-post' ),
				esc_html__( 'Rate this Plugin', 'donateocean-donation-suite' )
			);
		}
		return $meta;
	},
	10,
	2
);

/**
 * Initialize the plugin.
 *
 * @since 1.0.0
 */
DonationSuite\Core\Bootstrap::init();
