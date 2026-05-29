<?php
/**
 * Refund controller for the Donation Suite admin.
 *
 * Handles admin-initiated PayPal refunds. Registered as an admin-post
 * action (donadosu_refund). The refund button is rendered in
 * templates/admin-donation-detail.php.
 *
 * @package    Donation_Suite
 * @subpackage Admin
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Admin;

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
 * Class RefundController
 *
 * Processes full and partial refunds through the PayPal API and
 * transitions the donation status accordingly.
 *
 * @since 1.0.0
 */
class RefundController {

	/**
	 * Register the admin-post action for refunds.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_donadosu_refund', array( $this, 'handle' ) );
	}

	/**
	 * Handle the refund request.
	 *
	 * Validates permissions, nonce, donation status, and capture ID.
	 * Supports partial refunds when a refund amount is provided.
	 * Calls the PayPal refund API and transitions the donation status.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'donateocean-donation-suite' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified immediately below via check_admin_referer().
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_die( esc_html__( 'Invalid donation ID.', 'donateocean-donation-suite' ) );
		}

		// Verify nonce — unique per donation to prevent CSRF.
		// Note: post_id is retrieved first because the nonce action includes it,
		// but post_id is only used as an integer (absint) so this is safe.
		check_admin_referer( 'donadosu_refund_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post || 'donadosu_donation' !== $post->post_type ) {
			wp_die( esc_html__( 'Donation not found.', 'donateocean-donation-suite' ) );
		}

		$detail_url = add_query_arg(
			array(
				'page' => 'donadosu-detail',
				'id'   => $post_id,
			),
			admin_url( 'admin.php' )
		);

		// Must be in a refundable status — completed or disputed (StateMachine allows both).
		$current_status = (string) get_post_status( $post_id );
		if ( ! in_array( $current_status, array( 'donadosu_completed', 'donadosu_disputed' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'donadosu_error', 'not_completed', $detail_url ) );
			exit;
		}

		$capture_id = sanitize_text_field( (string) get_post_meta( $post_id, DonationMeta::CAPTURE_ID, true ) );
		if ( '' === $capture_id ) {
			wp_safe_redirect( add_query_arg( 'donadosu_error', 'no_capture_id', $detail_url ) );
			exit;
		}

		$config      = new ConfigService();
		$settings    = $config->get_all();
		$logger      = new Logger(
			(string) ( $settings['logging_level'] ?? 'error' ),
			! empty( $settings['enable_logging'] )
		);
		$token_cache = new OAuthTokenCache( $config, $logger );
		$paypal      = new PayPalClient( $config, $token_cache, $logger );
		$logger->info( 'Admin refund initiated', array( 'post_id' => $post_id, 'capture_id' => $capture_id ) );

		// Support partial refunds: if refund_amount is provided and valid, pass it;
		// otherwise pass 0 to trigger a full refund via the PayPal API.
		$refund_amount_input = trim( sanitize_text_field( (string) wp_unslash( $_POST['refund_amount'] ?? '' ) ) );
		$refund_amount       = 0.0;
		$donation_currency   = sanitize_text_field( (string) get_post_meta( $post_id, DonationMeta::CURRENCY, true ) );
		$gross_amount        = (float) get_post_meta( $post_id, DonationMeta::GROSS_AMOUNT, true );

		if ( '' !== $refund_amount_input ) {
			if ( ! is_numeric( $refund_amount_input ) || (float) $refund_amount_input <= 0 ) {
				wp_safe_redirect( add_query_arg( 'donadosu_error', 'invalid_refund_amount', $detail_url ) );
				exit;
			}
			$refund_amount = (float) $refund_amount_input;

			// Prevent refunding more than the captured amount.
			if ( $gross_amount > 0 && $refund_amount > $gross_amount ) {
				wp_safe_redirect( add_query_arg( 'donadosu_error', 'refund_exceeds_captured', $detail_url ) );
				exit;
			}
		}

		$result = $paypal->refund_capture( $capture_id, $refund_amount, $donation_currency );

		if ( empty( $result['success'] ) ) {
			$logger->error(
				'Admin refund failed',
				array(
					'post_id'       => $post_id,
					'capture_id'    => $capture_id,
					'paypal_status' => $result['status'] ?? '',
					'paypal_error'  => $result['data'] ?? $result['error'] ?? '',
				)
			);
			wp_safe_redirect( add_query_arg( 'donadosu_error', 'refund_failed', $detail_url ) );
			exit;
		}

		$refund_id = sanitize_text_field( (string) ( $result['data']['id'] ?? '' ) );

		$repository    = new CptDonationRepository();
		$state_machine = new StateMachine();

		$next_status = $state_machine->transition( $current_status, 'donadosu_refunded' );
		if ( $next_status !== $current_status ) {
			$repository->set_status( $post_id, $next_status );
		} else {
			// Force status update -- PayPal refund already succeeded.
			$repository->set_status( $post_id, 'donadosu_refunded' );
			$next_status = 'donadosu_refunded';
		}
		$repository->append_history(
			$post_id,
			$next_status,
			array(
				'source'    => 'admin_refund',
				'refund_id' => $refund_id,
				'actor'     => (string) wp_get_current_user()->user_email,
			)
		);

		$logger->info(
			'Admin refund completed successfully',
			array(
				'post_id'   => $post_id,
				'refund_id' => $refund_id,
				'amount'    => $refund_amount,
				'currency'  => $donation_currency,
			)
		);

		wp_safe_redirect( add_query_arg( 'donadosu_msg', 'refunded', $detail_url ) );
		exit;
	}
}
