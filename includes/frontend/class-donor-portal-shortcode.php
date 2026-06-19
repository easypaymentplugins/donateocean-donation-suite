<?php
/**
 * Donor self-service portal shortcode.
 *
 * Provides the [donadosu_donor_portal] shortcode that allows donors to
 * view and cancel their active recurring subscriptions without needing
 * a WordPress account, using a token-based magic link flow.
 *
 * @package    Donation_Suite
 * @subpackage Frontend
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Frontend;

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
 * Class DonorPortalShortcode
 *
 * Implements the donor self-service portal where donors can manage
 * their recurring subscriptions via a magic link email flow.
 *
 * @since 1.0.0
 */
class DonorPortalShortcode {

	/**
	 * Transient prefix for portal access tokens.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const TOKEN_PREFIX = 'donadosu_portal_';

	/**
	 * Token lifetime in seconds (30 minutes).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const TOKEN_LIFETIME = 1800;

	/**
	 * Register the shortcode and admin-post action handlers.
	 *
	 * Registers handlers for both authenticated and unauthenticated
	 * users to request magic links and cancel subscriptions.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'donadosu_donor_portal', array( $this, 'render' ) );

		// Both authenticated and unauthenticated users must be able to use these.
		add_action( 'admin_post_nopriv_donadosu_portal_request', array( $this, 'handle_request' ) );
		add_action( 'admin_post_donadosu_portal_request', array( $this, 'handle_request' ) );
		add_action( 'admin_post_nopriv_donadosu_portal_cancel', array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_donadosu_portal_cancel', array( $this, 'handle_cancel' ) );
	}

	/**
	 * Render the donor portal shortcode.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string Rendered shortcode HTML.
	 */
	public function render( $atts = array() ): string {
		wp_enqueue_style( 'donadosu-portal' );
		ob_start();
		$this->render_portal();
		return (string) ob_get_clean();
	}

	/**
	 * Render the portal content.
	 *
	 * Validates the portal token, retrieves donor subscriptions,
	 * and includes the portal template.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_portal(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Token-based authentication, not nonce-based.
		$token       = sanitize_text_field( (string) wp_unslash( (string) ( $_GET['donadosu_portal_token'] ?? '' ) ) );
		$msg_key     = sanitize_key( (string) wp_unslash( (string) ( $_GET['donadosu_portal_msg'] ?? '' ) ) );
		$error_key   = sanitize_key( (string) wp_unslash( (string) ( $_GET['donadosu_portal_error'] ?? '' ) ) );
		$sent        = ! empty( $_GET['donadosu_portal_sent'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$current_url = esc_url( (string) ( get_permalink() ?: home_url( '/' ) ) );

		$token_data = $token ? $this->validate_token( $token ) : null;

		$subscriptions = array();
		if ( $token_data ) {
			$subscriptions = $this->get_donor_subscriptions( $token_data['email'] );
		}

		// Template expects camelCase variables.
		$tokenData  = $token_data;
		$currentUrl = $current_url;
		$msgKey     = $msg_key;
		$errorKey   = $error_key;

		include DONADOSU_PATH . 'templates/donor-portal.php';
	}

	/**
	 * Handle the magic-link request form submission.
	 *
	 * Sends a one-time token email to the donor. Always shows a
	 * "sent" confirmation regardless of whether the email was found,
	 * to prevent email enumeration.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_request(): void {
		$email      = sanitize_email( (string) wp_unslash( (string) ( $_POST['donor_email'] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_local_url() which uses esc_url_raw().
		$return_url = $this->sanitize_local_url( (string) wp_unslash( (string) ( $_POST['return_url'] ?? '' ) ) );
		$nonce      = sanitize_text_field( (string) wp_unslash( (string) ( $_POST['_wpnonce'] ?? '' ) ) );

		if ( ! wp_verify_nonce( $nonce, 'donadosu_portal_request' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'donateocean-donation-suite' ), '', array( 'response' => 403 ) );
		}

		// Rate-limit magic link requests to prevent email spam abuse.
		// Max 3 requests per IP per 5 minutes, max 2 per email per 5 minutes.
		$ip_hash       = md5( sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ) );
		$ip_rate_key   = 'donadosu_portal_ip_' . $ip_hash;
		$ip_hits       = (int) get_transient( $ip_rate_key );
		if ( $ip_hits >= 3 ) {
			wp_safe_redirect( add_query_arg( 'donadosu_portal_sent', '1', $return_url ) );
			exit;
		}
		set_transient( $ip_rate_key, $ip_hits + 1, 5 * MINUTE_IN_SECONDS );

		// Always show "sent" confirmation even if email is not found — prevents
		// email enumeration.
		if ( ! $email || ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'donadosu_portal_sent', '1', $return_url ) );
			exit;
		}

		$email_rate_key = 'donadosu_portal_email_' . md5( $email );
		$email_hits     = (int) get_transient( $email_rate_key );
		if ( $email_hits >= 2 ) {
			wp_safe_redirect( add_query_arg( 'donadosu_portal_sent', '1', $return_url ) );
			exit;
		}
		set_transient( $email_rate_key, $email_hits + 1, 5 * MINUTE_IN_SECONDS );

		// Only send the link if the email has at least one donation record.
		$repository = new CptDonationRepository();
		$donations  = $repository->find_by_donor_email( $email, 1 );

		if ( $donations ) {
			$token = wp_generate_password( 40, false );
			set_transient(
				self::TOKEN_PREFIX . $token,
				array(
					'email'      => $email,
					'return_url' => $return_url,
				),
				self::TOKEN_LIFETIME
			);
			$this->send_magic_link( $email, $token, $return_url );
		}

		wp_safe_redirect( add_query_arg( 'donadosu_portal_sent', '1', $return_url ) );
		exit;
	}

	/**
	 * Handle the subscription cancel form in the portal.
	 *
	 * Validates the portal token, verifies ownership, cancels the
	 * subscription via PayPal, and updates the donation status.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_cancel(): void {
		$token   = sanitize_text_field( (string) wp_unslash( (string) ( $_POST['donadosu_portal_token'] ?? '' ) ) );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$nonce   = sanitize_text_field( (string) wp_unslash( (string) ( $_POST['_wpnonce'] ?? '' ) ) );

		if ( ! wp_verify_nonce( $nonce, 'donadosu_portal_cancel_' . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'donateocean-donation-suite' ), '', array( 'response' => 403 ) );
		}

		$token_data = $token ? $this->validate_token( $token ) : null;
		$return_url = is_array( $token_data ) ? ( $token_data['return_url'] ?? home_url( '/' ) ) : home_url( '/' );
		$portal_url = add_query_arg( 'donadosu_portal_token', rawurlencode( $token ), $return_url );

		if ( ! $token_data ) {
			wp_safe_redirect( add_query_arg( 'donadosu_portal_error', 'token_expired', $return_url ) );
			exit;
		}

		if ( ! $post_id ) {
			wp_safe_redirect( add_query_arg( 'donadosu_portal_error', 'invalid', $portal_url ) );
			exit;
		}

		$post = get_post( $post_id );

		if ( ! $post || 'donadosu_donation' !== $post->post_type ) {
			wp_safe_redirect( add_query_arg( 'donadosu_portal_error', 'invalid', $portal_url ) );
			exit;
		}

		// Verify the subscription belongs to this donor.
		$donor_email = sanitize_email( (string) get_post_meta( $post_id, DonationMeta::DONOR_EMAIL, true ) );

		if ( strtolower( $donor_email ) !== strtolower( $token_data['email'] ) ) {
			wp_safe_redirect( add_query_arg( 'donadosu_portal_error', 'forbidden', $portal_url ) );
			exit;
		}

		$current_status = (string) get_post_status( $post_id );

		if ( ! in_array( $current_status, array( 'donadosu_sub_active', 'donadosu_sub_paused' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'donadosu_portal_error', 'not_cancellable', $portal_url ) );
			exit;
		}

		$subscription_id = sanitize_text_field( (string) get_post_meta( $post_id, DonationMeta::SUBSCRIPTION_ID, true ) );
		$vault_id        = sanitize_text_field( (string) get_post_meta( $post_id, DonationMeta::VAULT_PAYMENT_TOKEN_ID, true ) );

		if ( '' === $subscription_id && '' === $vault_id ) {
			wp_safe_redirect( add_query_arg( 'donadosu_portal_error', 'no_sub_id', $portal_url ) );
			exit;
		}

		$config   = new ConfigService();
		$settings = $config->get_all();
		$logger   = new Logger(
			(string) ( $settings['logging_level'] ?? 'error' ),
			! empty( $settings['enable_logging'] )
		);
		$paypal   = new PayPalClient( $config, new OAuthTokenCache( $config, $logger ), $logger );

		// PayPal wallet subscription → cancel the v1 Subscription. Vault-card
		// subscription → delete the stored payment token so the renewal cron
		// can no longer charge it.
		if ( '' !== $subscription_id ) {
			$result = $paypal->cancel_subscription( $subscription_id, 'Cancelled by donor via self-service portal' );
		} else {
			$result = $paypal->delete_payment_token( $vault_id );
		}

		if ( empty( $result['success'] ) ) {
			wp_safe_redirect( add_query_arg( 'donadosu_portal_error', 'cancel_failed', $portal_url ) );
			exit;
		}

		// Stop the renewal cron from re-queuing this subscription.
		delete_post_meta( $post_id, DonationMeta::SUBSCRIPTION_NEXT_BILLING );
		delete_post_meta( $post_id, DonationMeta::NEXT_BILLING_TIME );

		$repository    = new CptDonationRepository();
		$state_machine = new StateMachine();

		$next_status = $state_machine->transition( $current_status, 'donadosu_sub_cancelled' );

		if ( $next_status !== $current_status ) {
			$repository->set_status( $post_id, $next_status );
			$repository->append_history(
				$post_id,
				$next_status,
				array(
					'source' => 'donor_portal',
					'actor'  => $donor_email,
				)
			);
		}

		update_post_meta( $post_id, DonationMeta::SUBSCRIPTION_STATUS, 'cancelled' );

		wp_safe_redirect( add_query_arg( 'donadosu_portal_msg', 'cancelled', $portal_url ) );
		exit;
	}

	/**
	 * Validate a portal token and return its data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token Portal access token.
	 * @return array{email: string, return_url: string}|null Token data or null if invalid/expired.
	 */
	public function validate_token( string $token ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9]+$/', $token ) ) {
			return null;
		}

		$data = get_transient( self::TOKEN_PREFIX . $token );

		if ( ! is_array( $data ) || empty( $data['email'] ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Return active and paused subscriptions for a donor email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Donor email address.
	 * @return list<array<string, mixed>> Array of subscription data.
	 */
	public function get_donor_subscriptions( string $email ): array {
		$post_ids      = ( new CptDonationRepository() )->find_by_donor_email( $email );

		if ( $post_ids ) {
			update_meta_cache( 'post', $post_ids );
		}

		$subscriptions = array();

		foreach ( $post_ids as $id ) {
			$status = (string) get_post_status( $id );

			if ( ! in_array( $status, array( 'donadosu_sub_active', 'donadosu_sub_paused' ), true ) ) {
				continue;
			}

			$subscription_id = (string) get_post_meta( $id, DonationMeta::SUBSCRIPTION_ID, true );

			$subscriptions[] = array(
				'post_id'           => $id,
				'status'            => $status,
				'amount'            => (string) get_post_meta( $id, DonationMeta::AMOUNT, true ),
				'currency'          => (string) get_post_meta( $id, DonationMeta::CURRENCY, true ),
				'cycle'             => (string) get_post_meta( $id, DonationMeta::SUBSCRIPTION_CYCLE, true ),
				'campaign'          => (string) get_post_meta( $id, DonationMeta::CAMPAIGN, true ),
				'next_billing'      => (string) get_post_meta( $id, DonationMeta::SUBSCRIPTION_NEXT_BILLING, true ),
				'receipt_no'        => (string) get_post_meta( $id, DonationMeta::RECEIPT_NO, true ),
				// PayPal-managed subscriptions (those with a PayPal subscription
				// ID) can have their funding source updated by the donor in their
				// own PayPal account; card-recurring donations cannot.
				'is_paypal_managed' => '' !== $subscription_id,
				'manage_url'        => '' !== $subscription_id
					/**
					 * Filters the URL a donor is sent to in order to update the
					 * payment method for a PayPal-managed subscription. Defaults
					 * to the donor's PayPal Automatic Payments page.
					 *
					 * @since 1.0.6
					 *
					 * @param string $url             Manage/update payment URL.
					 * @param int    $post_id         Donation post ID.
					 * @param string $subscription_id PayPal subscription ID.
					 */
					? (string) apply_filters(
						'donadosu_subscription_manage_url',
						'https://www.paypal.com/myaccount/autopay/',
						$id,
						$subscription_id
					)
					: '',
			);
		}

		return $subscriptions;
	}

	/**
	 * Ensure a return URL stays on the same site.
	 *
	 * Prevents open-redirect abuse by rejecting URLs that point
	 * off-site. Returns home_url('/') if the URL is empty, relative,
	 * or points to a different host.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL to sanitise.
	 * @return string Sanitised local URL.
	 */
	public function sanitize_local_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );

		if ( '' === $url ) {
			return home_url( '/' );
		}

		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = (string) wp_parse_url( $url, PHP_URL_HOST );

		// Reject if host is missing (relative path encoded oddly) or differs from home.
		if ( '' === $url_host || strtolower( $url_host ) !== strtolower( $home_host ) ) {
			return home_url( '/' );
		}

		return $url;
	}

	/**
	 * Send the magic link email to the donor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email      Donor email address.
	 * @param string $token      Portal access token.
	 * @param string $return_url URL to embed in the magic link.
	 * @return void
	 */
	public function send_magic_link( string $email, string $token, string $return_url ): void {
		$config   = new ConfigService();
		$settings = $config->get_all();
		$org_name = (string) ( $settings['charity_name'] ?? get_bloginfo( 'name' ) );

		$link    = add_query_arg( 'donadosu_portal_token', rawurlencode( $token ), $return_url );
		$subject = sprintf(
			/* translators: %s: organisation name */
			__( 'Access your donation portal — %s', 'donateocean-donation-suite' ),
			$org_name
		);

		$body = sprintf(
			'<!DOCTYPE html><html><body style="margin:0;padding:24px;font-family:Inter,-apple-system,sans-serif;background:#f4f4f5;">
<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;border:1px solid #e4e4e7;">
  <h2 style="margin:0 0 8px;font-size:20px;color:#111;">%s</h2>
  <p style="margin:0 0 24px;color:#525252;font-size:14px;">%s</p>
  <div style="text-align:center;margin:0 0 24px;">
    <a href="%s" style="display:inline-block;padding:12px 28px;background:#111;color:#fff;border-radius:8px;text-decoration:none;font-size:14px;font-weight:600;">%s</a>
  </div>
  <p style="margin:0;font-size:12px;color:#71717a;text-align:center;">%s</p>
</div></body></html>',
			esc_html( $org_name ),
			esc_html__( 'Click the button below to access your donation portal and manage your recurring donations. This link expires in 30 minutes.', 'donateocean-donation-suite' ),
			esc_url( $link ),
			esc_html__( 'Access My Donation Portal', 'donateocean-donation-suite' ),
			esc_html__( 'If you did not request this link, you can safely ignore this email.', 'donateocean-donation-suite' )
		);

		wp_mail( $email, $subject, $body, \DonationSuite\Email\ReceiptEmailService::build_email_headers() );
	}
}
