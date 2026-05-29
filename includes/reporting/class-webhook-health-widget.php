<?php
/**
 * Webhook health dashboard widget.
 *
 * Displays the most recent webhook event information on the WordPress
 * dashboard so administrators can quickly verify that webhooks are
 * being received and verified correctly.
 *
 * @package    Donation_Suite
 * @subpackage Reporting
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Reporting;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WebhookHealthWidget
 *
 * Registers and renders a dashboard widget showing the last received
 * webhook event type, event ID, timestamp, and verification status.
 *
 * @since 1.0.0
 */
class WebhookHealthWidget {

	/**
	 * Register the dashboard widget hook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * Add the webhook health widget to the WordPress dashboard.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'donadosu_webhook_health',
			__( 'Donation Suite Webhook Health', 'donateocean-donation-suite' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Render the webhook health widget content.
	 *
	 * Displays the last event type, event ID, received timestamp, and
	 * verification status from the stored webhook health option.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		$health = (array) get_option( 'donadosu_webhook_health', array() );

		if ( empty( $health ) ) {
			echo '<p>' . esc_html__( 'No webhook events have been recorded yet.', 'donateocean-donation-suite' ) . '</p>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'Last event type:', 'donateocean-donation-suite' ) . '</strong> ' . esc_html( (string) ( $health['event_type'] ?? '' ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Last event ID:', 'donateocean-donation-suite' ) . '</strong> ' . esc_html( (string) ( $health['event_id'] ?? '' ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Received at:', 'donateocean-donation-suite' ) . '</strong> ' . esc_html( (string) ( $health['received_at'] ?? '' ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Verification:', 'donateocean-donation-suite' ) . '</strong> ' . esc_html( (string) ( $health['verification_status'] ?? '' ) ) . '</p>';
	}
}
