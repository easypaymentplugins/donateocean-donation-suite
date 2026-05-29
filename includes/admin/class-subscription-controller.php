<?php
/**
 * Subscription controller for the Donation Suite admin.
 *
 * Handles admin-initiated PayPal subscription management actions including
 * cancel, pause (suspend), and resume (reactivate). All forms are rendered
 * in templates/admin-donation-detail.php.
 *
 * @package    Donation_Suite
 * @subpackage Admin
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Admin;

use DonationSuite\Core\Capabilities;
use DonationSuite\Core\ConfigService;
use DonationSuite\Donation\CptDonationRepository;
use DonationSuite\Donation\DonationMeta;
use DonationSuite\Donation\StateMachine;
use DonationSuite\Logging\Logger;
use DonationSuite\PayPal\OAuthTokenCache;
use DonationSuite\PayPal\PayPalClient;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SubscriptionController
 *
 * Processes subscription cancel, pause, and resume actions through the
 * PayPal API and transitions the donation status accordingly.
 *
 * @since 1.0.0
 */
class SubscriptionController {

	/**
	 * Register admin-post actions for subscription management.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_donadosu_cancel_subscription', array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_donadosu_pause_subscription', array( $this, 'handle_pause' ) );
		add_action( 'admin_post_donadosu_resume_subscription', array( $this, 'handle_resume' ) );
	}

	/**
	 * Handle a subscription cancellation request.
	 *
	 * Validates permissions, nonce, and subscription status. Calls the
	 * PayPal API to cancel the subscription and transitions the status.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_cancel(): void {
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Forbidden', 'donateocean-donation-suite' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below; post_id needed to construct nonce action.
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_die( esc_html__( 'Invalid donation ID.', 'donateocean-donation-suite' ) );
		}

		check_admin_referer( 'donadosu_cancel_subscription_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post || 'donadosu_donation' !== $post->post_type ) {
			wp_die( esc_html__( 'Donation not found.', 'donateocean-donation-suite' ) );
		}

		$detail_url = $this->detail_url( $post_id );

		$current_status = (string) get_post_status( $post_id );
		if ( ! in_array( $current_status, array( 'donadosu_sub_active', 'donadosu_sub_paused' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'donadosu_error', 'not_cancellable', $detail_url ) );
			exit;
		}

		$subscription_id = $this->get_subscription_id( $post_id );
		$vault_id        = $this->get_vault_token_id( $post_id );

		if ( '' === $subscription_id && '' === $vault_id ) {
			wp_safe_redirect( add_query_arg( 'donadosu_error', 'no_subscription_id', $detail_url ) );
			exit;
		}

		// PayPal wallet subscription → cancel via v1 Subscriptions API.
		// Vault-card subscription → delete the stored payment token so the
		// renewal cron can no longer charge it (PayPal v1 Subscriptions does
		// not back this flow, so there is no subscription to cancel there).
		if ( '' !== $subscription_id ) {
			$result = $this->paypal()->cancel_subscription( $subscription_id, 'Cancelled by admin' );
		} else {
			$result = $this->paypal()->delete_payment_token( $vault_id );
		}

		if ( empty( $result['success'] ) ) {
			wp_safe_redirect( add_query_arg( 'donadosu_error', 'cancel_failed', $detail_url ) );
			exit;
		}

		$this->transition( $post_id, $current_status, 'donadosu_sub_cancelled', 'admin_cancel' );
		update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, 'cancelled' );

		// Stop the renewal cron from re-queuing this subscription. The vault
		// meta is intentionally retained for audit history.
		delete_post_meta( $post_id, DonationMeta::SUBSCRIPTION_NEXT_BILLING );
		delete_post_meta( $post_id, DonationMeta::NEXT_BILLING_TIME );

		wp_safe_redirect( add_query_arg( 'donadosu_msg', 'subscription_cancelled', $detail_url ) );
		exit;
	}

	/**
	 * Handle a subscription pause (suspend) request.
	 *
	 * Validates permissions, nonce, and subscription status. Calls the
	 * PayPal API to suspend the subscription and transitions the status.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_pause(): void {
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Forbidden', 'donateocean-donation-suite' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below; post_id needed to construct nonce action.
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_die( esc_html__( 'Invalid donation ID.', 'donateocean-donation-suite' ) );
		}

		check_admin_referer( 'donadosu_pause_subscription_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post || 'donadosu_donation' !== $post->post_type ) {
			wp_die( esc_html__( 'Donation not found.', 'donateocean-donation-suite' ) );
		}

		$detail_url     = $this->detail_url( $post_id );
		$current_status = (string) get_post_status( $post_id );

		if ( 'donadosu_sub_active' !== $current_status ) {
			wp_safe_redirect( add_query_arg( 'donadosu_error', 'not_pausable', $detail_url ) );
			exit;
		}

		$subscription_id = $this->get_subscription_id( $post_id );
		$vault_id        = $this->get_vault_token_id( $post_id );

		if ( '' === $subscription_id && '' === $vault_id ) {
			wp_safe_redirect( add_query_arg( 'donadosu_error', 'no_subscription_id', $detail_url ) );
			exit;
		}

		// PayPal wallet: suspend via the v1 Subscriptions API. Vault-card
		// subscriptions are paused purely in WordPress — the renewal cron
		// skips anything not in donadosu_sub_active so clearing the next
		// billing date is enough to keep PayPal from being charged.
		if ( '' !== $subscription_id ) {
			$result = $this->paypal()->suspend_subscription( $subscription_id, 'Paused by admin' );
			if ( empty( $result['success'] ) ) {
				wp_safe_redirect( add_query_arg( 'donadosu_error', 'pause_failed', $detail_url ) );
				exit;
			}
		}

		$this->transition( $post_id, $current_status, 'donadosu_sub_paused', 'admin_pause' );
		update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, 'paused' );

		wp_safe_redirect( add_query_arg( 'donadosu_msg', 'subscription_paused', $detail_url ) );
		exit;
	}

	/**
	 * Handle a subscription resume (reactivate) request.
	 *
	 * Validates permissions, nonce, and subscription status. Calls the
	 * PayPal API to reactivate the subscription and transitions the status.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_resume(): void {
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Forbidden', 'donateocean-donation-suite' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below; post_id needed to construct nonce action.
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_die( esc_html__( 'Invalid donation ID.', 'donateocean-donation-suite' ) );
		}

		check_admin_referer( 'donadosu_resume_subscription_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post || 'donadosu_donation' !== $post->post_type ) {
			wp_die( esc_html__( 'Donation not found.', 'donateocean-donation-suite' ) );
		}

		$detail_url     = $this->detail_url( $post_id );
		$current_status = (string) get_post_status( $post_id );

		if ( 'donadosu_sub_paused' !== $current_status ) {
			wp_safe_redirect( add_query_arg( 'donadosu_error', 'not_resumable', $detail_url ) );
			exit;
		}

		$subscription_id = $this->get_subscription_id( $post_id );
		$vault_id        = $this->get_vault_token_id( $post_id );

		if ( '' === $subscription_id && '' === $vault_id ) {
			wp_safe_redirect( add_query_arg( 'donadosu_error', 'no_subscription_id', $detail_url ) );
			exit;
		}

		// PayPal wallet: reactivate via the v1 Subscriptions API. Vault-card
		// resumes only require flipping the local state back to active —
		// the next cron tick will pick the post up once a billing date exists.
		if ( '' !== $subscription_id ) {
			$result = $this->paypal()->activate_subscription( $subscription_id, 'Reactivated by admin' );
			if ( empty( $result['success'] ) ) {
				wp_safe_redirect( add_query_arg( 'donadosu_error', 'resume_failed', $detail_url ) );
				exit;
			}
		}

		$this->transition( $post_id, $current_status, 'donadosu_sub_active', 'admin_resume' );
		update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, 'active' );

		// Restart the renewal cycle immediately for vault-card flows that had
		// their next billing date cleared during pause.
		if ( '' === $subscription_id ) {
			$next_iso = gmdate( 'c', time() + HOUR_IN_SECONDS );
			update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_NEXT_BILLING, $next_iso );
			update_post_meta( $post_id, DonationMeta::NEXT_BILLING_TIME, $next_iso );
		}

		wp_safe_redirect( add_query_arg( 'donadosu_msg', 'subscription_resumed', $detail_url ) );
		exit;
	}

	/**
	 * Create a PayPal client instance with current configuration.
	 *
	 * @since 1.0.0
	 *
	 * @return PayPalClient The configured PayPal client.
	 */
	private function paypal(): PayPalClient {
		$config   = new ConfigService();
		$settings = $config->get_all();
		$logger   = new Logger(
			(string) ( $settings['logging_level'] ?? 'error' ),
			! empty( $settings['enable_logging'] )
		);
		return new PayPalClient( $config, new OAuthTokenCache( $config, $logger ), $logger );
	}

	/**
	 * Build the donation detail page URL for redirects.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The donation post ID.
	 * @return string The detail page URL.
	 */
	private function detail_url( int $post_id ): string {
		return add_query_arg(
			array(
				'page' => 'donadosu-detail',
				'id'   => $post_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Retrieve the PayPal subscription ID for a donation.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The donation post ID.
	 * @return string The subscription ID, or empty string if not found.
	 */
	private function get_subscription_id( int $post_id ): string {
		return sanitize_text_field( (string) get_post_meta( $post_id, DonationMeta::SUBSCRIPTION_ID, true ) );
	}

	/**
	 * Retrieve the PayPal vaulted payment-token ID for a donation.
	 *
	 * Populated on the first successful capture of a card-recurring donation
	 * (Orders v2 + vault.store_in_vault=ON_SUCCESS). Used to drive
	 * merchant-initiated renewal orders via the cron.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The donation post ID.
	 * @return string The vault payment token ID, or empty string if not set.
	 */
	private function get_vault_token_id( int $post_id ): string {
		return sanitize_text_field( (string) get_post_meta( $post_id, DonationMeta::VAULT_PAYMENT_TOKEN_ID, true ) );
	}

	/**
	 * Transition a donation status via the state machine and record history.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id     The donation post ID.
	 * @param string $from_status The current status.
	 * @param string $to_status   The target status.
	 * @param string $source      The action source identifier for history.
	 * @return void
	 */
	private function transition( int $post_id, string $from_status, string $to_status, string $source ): void {
		$repository    = new CptDonationRepository();
		$state_machine = new StateMachine();

		$next_status = $state_machine->transition( $from_status, $to_status );
		if ( $next_status !== $from_status ) {
			$repository->set_status( $post_id, $next_status );
		} else {
			// Force status update -- PayPal action already succeeded.
			$repository->set_status( $post_id, $to_status );
			$next_status = $to_status;
		}
		$repository->append_history(
			$post_id,
			$next_status,
			array(
				'source' => $source,
				'actor'  => (string) wp_get_current_user()->user_email,
			)
		);
	}
}
