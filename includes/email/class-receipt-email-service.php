<?php
/**
 * Receipt email service.
 *
 * Handles sending donation receipt emails to donors, tribute notifications
 * to honoree families, dispute alerts, and admin notifications.
 *
 * @package    Donation_Suite
 * @subpackage Email
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Email;

use DonationSuite\Core\AddressFormatter;
use DonationSuite\Core\ConfigService;
use DonationSuite\Donation\DonationMeta;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReceiptEmailService
 *
 * Sends transactional emails related to donation lifecycle events including
 * donor receipts, admin notifications, tribute notifications, and dispute alerts.
 *
 * @since 1.0.0
 */
class ReceiptEmailService {

	/**
	 * Manually resend the receipt for a specific donation.
	 *
	 * Bypasses the duplicate-send guard so it can be called by admins.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Donation post ID.
	 * @return bool True if the receipt was sent successfully.
	 */
	public static function resend_receipt( int $post_id ): bool {
		delete_post_meta( $post_id, DonationMeta::RECEIPT_SENT_AT );
		self::handle_completion( $post_id, array() );
		return 'sent' === get_post_meta( $post_id, DonationMeta::RECEIPT_EMAIL_STATUS, true );
	}

	/**
	 * Send a dispute alert email to the charity admin.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id    Donation post ID.
	 * @param string $dispute_id PayPal dispute identifier.
	 * @param string $reason     Human-readable dispute reason.
	 * @return void
	 */
	public static function handle_dispute_alert( int $post_id, string $dispute_id, string $reason ): void {
		$config   = new ConfigService();
		$settings = $config->get_all();

		$admin_email = sanitize_email( (string) ( $settings['contact_email'] ?? '' ) );
		if ( ! $admin_email ) {
			$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		if ( ! $admin_email ) {
			return;
		}

		$org_name   = trim( (string) ( $settings['charity_name'] ?? '' ) ) ?: (string) get_bloginfo( 'name' );
		$receipt_no = (string) get_post_meta( $post_id, DonationMeta::RECEIPT_NO, true );
		$amount     = (string) get_post_meta( $post_id, DonationMeta::AMOUNT, true );
		$currency   = (string) get_post_meta( $post_id, DonationMeta::CURRENCY, true );
		$admin_url  = esc_url(
			add_query_arg(
				array(
					'page' => 'donadosu-detail',
					'id'   => $post_id,
				),
				admin_url( 'admin.php' )
			)
		);

		/* translators: %d: numeric donation post ID */
		$donation_label = $receipt_no ? $receipt_no : sprintf( __( 'Donation #%d', 'donateocean-donation-suite' ), $post_id );

		$subject = sprintf(
			/* translators: 1: currency code, 2: donation amount, 3: receipt number or donation ID */
			__( '[ACTION REQUIRED] PayPal dispute opened – %1$s %2$s (%3$s)', 'donateocean-donation-suite' ),
			$currency,
			$amount,
			$donation_label
		);

		$body = sprintf(
			'<!DOCTYPE html><html><body style="margin:0;padding:24px;font-family:Inter,-apple-system,sans-serif;background:#f4f4f5;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;border:1px solid #e4e4e7;">
  <h2 style="margin:0 0 4px;color:#dc2626;">%s</h2>
  <p style="margin:0 0 24px;color:#525252;">%s</p>
  <table style="width:100%%;font-size:14px;border-collapse:collapse;">
    <tr><td style="padding:8px 0;color:#525252;width:45%%;">Receipt #</td><td style="padding:8px 0;font-weight:600;">%s</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Amount</td><td style="padding:8px 0;font-weight:600;">%s %s</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Dispute ID</td><td style="padding:8px 0;font-family:monospace;font-size:12px;">%s</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Reason</td><td style="padding:8px 0;">%s</td></tr>
  </table>
  <div style="margin:24px 0 0;text-align:center;">
    <a href="%s" style="display:inline-block;padding:10px 20px;background:#dc2626;color:#fff;border-radius:8px;text-decoration:none;font-size:14px;font-weight:600;">View Donation</a>
  </div>
  <p style="margin:16px 0 0;font-size:12px;color:#71717a;text-align:center;">Log in to <a href="https://www.paypal.com/resolutioncenter">%s</a> to respond.</p>
</div></body></html>',
			esc_html__( 'PayPal Dispute Opened', 'donateocean-donation-suite' ),
			esc_html__( 'A donor has opened a dispute. Action may be required.', 'donateocean-donation-suite' ),
			esc_html( $receipt_no ) ? esc_html( $receipt_no ) : '—',
			esc_html( $currency ),
			esc_html( $amount ),
			esc_html( $dispute_id ),
			esc_html( $reason ),
			esc_attr( $admin_url ),
			esc_html__( 'PayPal Resolution Center', 'donateocean-donation-suite' )
		);

		wp_mail( $admin_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Send a tribute notification email to the honoree's family.
	 *
	 * Called after a tribute donation completes.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id Donation post ID.
	 * @param array $webhook Webhook payload (unused, kept for hook signature).
	 * @return void
	 */
	public static function handle_tribute_notification( int $post_id, array $webhook = array() ): void {
		$notify_email = sanitize_email( (string) get_post_meta( $post_id, DonationMeta::TRIBUTE_NOTIFY_EMAIL, true ) );
		if ( ! $notify_email ) {
			return;
		}

		$config       = new ConfigService();
		$settings     = $config->get_all();
		$org_name     = trim( (string) ( $settings['charity_name'] ?? '' ) ) ?: (string) get_bloginfo( 'name' );
		$tribute_type = (string) get_post_meta( $post_id, DonationMeta::TRIBUTE_TYPE, true );
		$tribute_name = (string) get_post_meta( $post_id, DonationMeta::TRIBUTE_NAME, true );
		$donor_name   = (string) get_post_meta( $post_id, DonationMeta::DONOR_NAME, true );
		$amount       = (string) get_post_meta( $post_id, DonationMeta::AMOUNT, true );
		$currency     = (string) get_post_meta( $post_id, DonationMeta::CURRENCY, true );

		$type_label = 'in_memory' === $tribute_type ? 'in memory of' : 'in honor of';
		$subject    = sprintf( 'A donation has been made %s %s', $type_label, $tribute_name );

		$body = sprintf(
			'<!DOCTYPE html><html><body style="margin:0;padding:24px;font-family:Inter,-apple-system,sans-serif;background:#f4f4f5;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;border:1px solid #e4e4e7;">
  <h2 style="margin:0 0 4px;">A Tribute Donation</h2>
  <p style="margin:0 0 24px;color:#525252;">A donation has been made to %s %s %s.</p>
  <table style="width:100%%;font-size:14px;border-collapse:collapse;">
    <tr><td style="padding:8px 0;color:#525252;width:45%%;">Amount</td><td style="padding:8px 0;font-weight:600;">%s %s</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Made by</td><td style="padding:8px 0;">%s</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Organization</td><td style="padding:8px 0;">%s</td></tr>
  </table>
</div></body></html>',
			esc_html( $org_name ),
			esc_html( $type_label ),
			esc_html( $tribute_name ),
			esc_html( $currency ),
			esc_html( number_format( (float) $amount, 2 ) ),
			esc_html( $donor_name ? $donor_name : 'A generous donor' ),
			esc_html( $org_name )
		);

		wp_mail( $notify_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Send a receipt email to the donor after a completed donation.
	 *
	 * Hooked to `donadosu_donation_completed` at priority 10. Guards against
	 * duplicate sends via the RECEIPT_SENT_AT meta key and generates a receipt
	 * number in RCPT-{YYYY}-{000000} format if one does not already exist.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id Donation post ID.
	 * @param array $webhook Webhook payload (unused for email, kept for hook signature).
	 * @return void
	 */
	public static function handle_completion( int $post_id, array $webhook = array() ): void {
		$email = sanitize_email( (string) get_post_meta( $post_id, DonationMeta::DONOR_EMAIL, true ) );
		if ( ! $email ) {
			update_post_meta( $post_id, DonationMeta::RECEIPT_EMAIL_STATUS, 'skipped_no_email' );
			return;
		}

		// Guard: do not send a second receipt for the same donation.
		// Use wp_cache_add for an atomic claim under concurrent webhook deliveries.
		$lock_key = 'donadosu_receipt_lock_' . $post_id;
		if ( ! wp_cache_add( $lock_key, 1, 'donateocean-donation-suite', 60 ) ) {
			return;
		}
		if ( get_post_meta( $post_id, DonationMeta::RECEIPT_SENT_AT, true ) ) {
			wp_cache_delete( $lock_key, 'donateocean-donation-suite' );
			return;
		}

		$receipt_no = (string) get_post_meta( $post_id, DonationMeta::RECEIPT_NO, true );
		if ( ! $receipt_no ) {
			$receipt_no = sprintf( 'RCPT-%s-%06d', gmdate( 'Y' ), $post_id );
			update_post_meta( $post_id, DonationMeta::RECEIPT_NO, $receipt_no );
		}

		$config   = new ConfigService();
		$settings = $config->get_all();

		$amount        = (string) get_post_meta( $post_id, DonationMeta::AMOUNT, true );
		$currency      = (string) get_post_meta( $post_id, DonationMeta::CURRENCY, true );
		$capture_id    = (string) get_post_meta( $post_id, DonationMeta::CAPTURE_ID, true );
		$order_id      = (string) get_post_meta( $post_id, DonationMeta::ORDER_ID, true );
		$donation_date = (string) get_post_time( 'Y-m-d H:i:s', true, $post_id );

		// Fee coverage.
		$fee_covered  = (bool) get_post_meta( $post_id, DonationMeta::FEE_COVERED, true );
		$fee_amount   = (float) get_post_meta( $post_id, DonationMeta::FEE_AMOUNT, true );
		$gross_amount = (float) get_post_meta( $post_id, DonationMeta::GROSS_AMOUNT, true );

		// Tribute.
		$is_tribute   = (bool) get_post_meta( $post_id, DonationMeta::IS_TRIBUTE, true );
		$tribute_type = (string) get_post_meta( $post_id, DonationMeta::TRIBUTE_TYPE, true );
		$tribute_name = (string) get_post_meta( $post_id, DonationMeta::TRIBUTE_NAME, true );

		// Giving level.
		$giving_level = (string) get_post_meta( $post_id, DonationMeta::GIVING_LEVEL, true );

		// Frequency.
		$frequency = (string) get_post_meta( $post_id, DonationMeta::DONATION_FREQUENCY, true );

		// Donor message.
		$donor_message = (string) get_post_meta( $post_id, DonationMeta::DONOR_MESSAGE, true );

		$org_name      = trim( (string) ( $settings['charity_name'] ?? '' ) );
		$org_address   = trim( (string) ( $settings['charity_address'] ?? '' ) );
		$reg_id        = trim( (string) ( $settings['reg_id'] ?? '' ) );
		$contact_email = trim( (string) ( $settings['contact_email'] ?? '' ) );
		$privacy_url   = esc_url( (string) ( $settings['privacy_url'] ?? '' ) );
		$refund_url    = esc_url( (string) ( $settings['refund_url']  ?? '' ) );

		/* translators: %s: receipt number */
		$subject = sprintf( __( 'Donation Receipt %s', 'donateocean-donation-suite' ), $receipt_no );
		$body    = self::build_receipt_html(
			$org_name,
			$receipt_no,
			$donation_date,
			$currency,
			$amount,
			$order_id,
			$capture_id,
			$reg_id,
			$org_address,
			$contact_email,
			$config->get_receipt_statement(),
			$privacy_url,
			$refund_url,
			$fee_covered,
			$fee_amount,
			$gross_amount,
			$is_tribute,
			$tribute_type,
			$tribute_name,
			$giving_level,
			$frequency,
			$donor_message
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent    = wp_mail( $email, $subject, $body, $headers );

		update_post_meta( $post_id, DonationMeta::RECEIPT_EMAIL_STATUS, $sent ? 'sent' : 'failed' );
		if ( $sent ) {
			update_post_meta( $post_id, DonationMeta::RECEIPT_SENT_AT, gmdate( 'c' ) );
		}
	}

	/**
	 * Notify the site admin / charity contact when a donation completes.
	 *
	 * Hooked to `donadosu_donation_completed` at priority 20 (after donor receipt).
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id Donation post ID.
	 * @param array $webhook Webhook payload.
	 * @return void
	 */
	public static function handle_admin_notification( int $post_id, array $webhook = array() ): void {
		$config   = new ConfigService();
		$settings = $config->get_all();

		$admin_email = sanitize_email( (string) ( $settings['contact_email'] ?? '' ) );
		if ( ! $admin_email ) {
			$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		if ( ! $admin_email ) {
			return;
		}

		$receipt_no    = (string) get_post_meta( $post_id, DonationMeta::RECEIPT_NO, true );
		$amount        = (string) get_post_meta( $post_id, DonationMeta::AMOUNT, true );
		$currency      = (string) get_post_meta( $post_id, DonationMeta::CURRENCY, true );
		$donor_email   = sanitize_email( (string) get_post_meta( $post_id, DonationMeta::DONOR_EMAIL, true ) );
		$donor_name    = sanitize_text_field( (string) get_post_meta( $post_id, DonationMeta::DONOR_NAME, true ) );
		$order_id      = (string) get_post_meta( $post_id, DonationMeta::ORDER_ID, true );
		$capture_id    = (string) get_post_meta( $post_id, DonationMeta::CAPTURE_ID, true );
		$campaign      = sanitize_text_field( (string) get_post_meta( $post_id, DonationMeta::CAMPAIGN, true ) );
		$purpose       = sanitize_text_field( (string) get_post_meta( $post_id, DonationMeta::PURPOSE, true ) );
		$donation_date = (string) get_post_time( 'Y-m-d H:i:s', true, $post_id );
		$org_name      = trim( (string) ( $settings['charity_name'] ?? '' ) ) ?: (string) get_bloginfo( 'name' );
		$admin_url     = esc_url(
			add_query_arg(
				array(
					'page' => 'donadosu-detail',
					'id'   => $post_id,
				),
				admin_url( 'admin.php' )
			)
		);

		/* translators: %d: numeric donation post ID */
		$admin_donation_label = $receipt_no ? $receipt_no : sprintf( __( 'Donation #%d', 'donateocean-donation-suite' ), $post_id );

		$subject = sprintf(
			/* translators: 1: organization name, 2: currency code, 3: donation amount, 4: receipt number or donation ID */
			__( '[%1$s] New donation received – %2$s %3$s (%4$s)', 'donateocean-donation-suite' ),
			$org_name,
			$currency,
			$amount,
			$admin_donation_label
		);

		$body = self::build_admin_notification_html(
			$org_name,
			$receipt_no,
			$donation_date,
			$currency,
			$amount,
			$donor_name,
			$donor_email,
			$order_id,
			$capture_id,
			$campaign,
			$purpose,
			$admin_url
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $admin_email, $subject, $body, $headers );
	}

	/**
	 * Build the HTML body for the donor receipt email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $org_name      Organisation name.
	 * @param string $receipt_no    Receipt number.
	 * @param string $donation_date Donation date string.
	 * @param string $currency      Currency code.
	 * @param string $amount        Donation amount.
	 * @param string $order_id      PayPal order ID.
	 * @param string $capture_id    PayPal capture ID.
	 * @param string $reg_id        Organisation registration / tax ID.
	 * @param string $org_address   Organisation address.
	 * @param string $contact_email Organisation contact email.
	 * @param string $tax_statement Tax disclaimer statement.
	 * @param string $privacy_url   Privacy policy URL.
	 * @param string $refund_url    Refund policy URL.
	 * @param bool   $fee_covered   Whether the donor covered the transaction fee.
	 * @param float  $fee_amount    Transaction fee amount.
	 * @param float  $gross_amount  Total gross amount charged.
	 * @param bool   $is_tribute    Whether this is a tribute donation.
	 * @param string $tribute_type  Tribute type (in_honor or in_memory).
	 * @param string $tribute_name  Name of the tribute honoree.
	 * @param string $giving_level  Giving level label.
	 * @param string $frequency     Donation frequency (one_time, monthly, annual).
	 * @param string $donor_message Optional message from the donor.
	 * @return string Complete HTML email body.
	 */
	private static function build_receipt_html(
		string $org_name,
		string $receipt_no,
		string $donation_date,
		string $currency,
		string $amount,
		string $order_id,
		string $capture_id,
		string $reg_id,
		string $org_address,
		string $contact_email,
		string $tax_statement,
		string $privacy_url,
		string $refund_url,
		bool $fee_covered = false,
		float $fee_amount = 0.0,
		float $gross_amount = 0.0,
		bool $is_tribute = false,
		string $tribute_type = '',
		string $tribute_name = '',
		string $giving_level = '',
		string $frequency = 'one_time',
		string $donor_message = ''
	): string {
		$policy_links = '';
		if ( $privacy_url || $refund_url ) {
			$links = array();
			if ( $privacy_url ) {
				$links[] = sprintf( '<a href="%s" style="color:#71717a;">Privacy policy</a>', esc_attr( $privacy_url ) );
			}
			if ( $refund_url ) {
				$links[] = sprintf( '<a href="%s" style="color:#71717a;">Refund policy</a>', esc_attr( $refund_url ) );
			}
			$policy_links = sprintf(
				'<p style="margin:16px 0 0;font-size:12px;color:#71717a;text-align:center;">%s</p>',
				implode( ' &middot; ', $links )
			);
		}

		// Fee row.
		$fee_row = '';
		if ( $fee_covered && $fee_amount > 0 ) {
			$total_charged = $gross_amount > 0 ? $gross_amount : ( (float) $amount + $fee_amount );
			$fee_row       = sprintf(
				'<tr><td style="padding:8px 0;color:#525252;">Transaction fee covered</td><td style="padding:8px 0;">%s %s</td></tr>
                 <tr><td style="padding:8px 0;color:#525252;">Total charged</td><td style="padding:8px 0;font-weight:600;">%s %s</td></tr>',
				esc_html( $currency ),
				esc_html( number_format( $fee_amount, 2 ) ),
				esc_html( $currency ),
				esc_html( number_format( $total_charged, 2 ) )
			);
		}

		// Frequency row.
		$frequency_labels = array(
			'monthly' => __( 'Monthly recurring', 'donateocean-donation-suite' ),
			'annual'  => __( 'Annual recurring', 'donateocean-donation-suite' ),
		);
		$frequency_label  = $frequency_labels[ $frequency ] ?? __( 'One-time donation', 'donateocean-donation-suite' );
		$frequency_row    = sprintf(
			'<tr><td style="padding:8px 0;color:#525252;">Donation type</td><td style="padding:8px 0;">%s</td></tr>',
			esc_html( $frequency_label )
		);

		// Giving level row.
		$level_row = '';
		if ( '' !== $giving_level ) {
			$level_row = sprintf(
				'<tr><td style="padding:8px 0;color:#525252;">Giving level</td><td style="padding:8px 0;">%s</td></tr>',
				esc_html( $giving_level )
			);
		}

		// Tribute row.
		$tribute_row = '';
		if ( $is_tribute && '' !== $tribute_name ) {
			$type_label  = 'in_memory' === $tribute_type ? __( 'In memory of', 'donateocean-donation-suite' ) : __( 'In honor of', 'donateocean-donation-suite' );
			$tribute_row = sprintf(
				'<tr><td style="padding:8px 0;color:#525252;">Tribute</td><td style="padding:8px 0;">%s %s</td></tr>',
				esc_html( $type_label ),
				esc_html( $tribute_name )
			);
		}

		// Donor message row.
		$message_row = '';
		if ( '' !== $donor_message ) {
			$message_row = sprintf(
				'<tr><td style="padding:8px 0;color:#525252;vertical-align:top;">Your message</td><td style="padding:8px 0;">%s</td></tr>',
				nl2br( esc_html( $donor_message ) )
			);
		}

		// Build organization details section only when at least one field is configured.
		$has_org_details = '' !== $org_name || '' !== $reg_id || '' !== $org_address || '' !== $contact_email;
		$org_section     = '';

		if ( $has_org_details ) {
			$org_rows = '';
			if ( '' !== $org_name ) {
				$org_rows .= sprintf(
					'<tr><td style="padding:6px 0;color:#525252;width:45%%;">Name</td><td style="padding:6px 0;">%s</td></tr>',
					esc_html( $org_name )
				);
			}
			if ( '' !== $reg_id ) {
				$org_rows .= sprintf(
					'<tr><td style="padding:6px 0;color:#525252;">Registration / Tax ID</td><td style="padding:6px 0;">%s</td></tr>',
					esc_html( $reg_id )
				);
			}
			if ( '' !== $org_address ) {
				$org_rows .= sprintf(
					'<tr><td style="padding:6px 0;color:#525252;vertical-align:top;">Address</td><td style="padding:6px 0;">%s</td></tr>',
					AddressFormatter::to_html( AddressFormatter::format_org( $org_address ) )
				);
			}
			if ( '' !== $contact_email ) {
				$org_rows .= sprintf(
					'<tr><td style="padding:6px 0;color:#525252;">Contact</td><td style="padding:6px 0;"><a href="mailto:%s" style="color:#111;">%s</a></td></tr>',
					esc_attr( $contact_email ),
					esc_html( $contact_email )
				);
			}

			$org_section = sprintf(
				'<hr style="border:none;border-top:1px solid #e4e4e7;margin:24px 0;">
  <h3 style="margin:0 0 12px;font-size:15px;color:#111;">%s</h3>
  <table style="width:100%%;border-collapse:collapse;font-size:14px;">%s</table>',
				esc_html__( 'Organization details', 'donateocean-donation-suite' ),
				$org_rows
			);
		}

		// Tax statement section — only shown when non-empty.
		$tax_section = '' !== $tax_statement
			? sprintf( '<div style="margin:24px 0 0;padding:12px 0;font-size:13px;color:#3f3f46;">%s</div>', esc_html( $tax_statement ) )
			: '';

		// Header line — use org name if available, otherwise generic heading.
		$header_line = '' !== $org_name ? esc_html( $org_name ) : esc_html__( 'Donation Receipt', 'donateocean-donation-suite' );

		return sprintf(
			'<!DOCTYPE html><html><body style="margin:0;padding:24px;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:#f4f4f5;color:#111;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;border:1px solid #e4e4e7;">
  <h2 style="margin:0 0 2px;font-size:22px;color:#111;">%s</h2>
  <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#111;">%s</p>
  <p style="margin:0 0 24px;font-size:13px;color:#525252;">%s</p>

  <h3 style="margin:0 0 12px;font-size:15px;color:#111;">%s</h3>
  <table style="width:100%%;border-collapse:collapse;font-size:14px;">
    <tr><td style="padding:8px 0;color:#525252;width:45%%;">Receipt #</td><td style="padding:8px 0;font-weight:600;">%s</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Donation date</td><td style="padding:8px 0;">%s UTC</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Amount</td><td style="padding:8px 0;font-weight:600;">%s %s</td></tr>
    %s%s%s%s%s
    <tr><td style="padding:8px 0;color:#525252;">Payment method</td><td style="padding:8px 0;">PayPal</td></tr>
  </table>

  %s
  %s
  %s
</div>
</body></html>',
			$header_line,
			esc_html__( 'Donation Receipt', 'donateocean-donation-suite' ),
			esc_html__( 'Thank you for your generous donation.', 'donateocean-donation-suite' ),
			esc_html__( 'Donation details', 'donateocean-donation-suite' ),
			esc_html( $receipt_no ),
			esc_html( $donation_date ),
			esc_html( $currency ),
			esc_html( $amount ),
			$frequency_row,
			$level_row,
			$tribute_row,
			$fee_row,
			$message_row,
			$org_section,
			$tax_section,
			$policy_links
		);
	}

	/**
	 * Build the HTML body for the admin/charity notification email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $org_name      Organisation name.
	 * @param string $receipt_no    Receipt number.
	 * @param string $donation_date Donation date string.
	 * @param string $currency      Currency code.
	 * @param string $amount        Donation amount.
	 * @param string $donor_name    Donor display name.
	 * @param string $donor_email   Donor email address.
	 * @param string $order_id      PayPal order ID.
	 * @param string $capture_id    PayPal capture ID.
	 * @param string $campaign      Campaign name.
	 * @param string $purpose       Donation purpose.
	 * @param string $admin_url     URL to the donation detail in WP admin.
	 * @return string Complete HTML email body.
	 */
	private static function build_admin_notification_html(
		string $org_name,
		string $receipt_no,
		string $donation_date,
		string $currency,
		string $amount,
		string $donor_name,
		string $donor_email,
		string $order_id,
		string $capture_id,
		string $campaign,
		string $purpose,
		string $admin_url
	): string {
		$donor_display = $donor_name ? $donor_name : ( $donor_email ? $donor_email : __( 'Anonymous', 'donateocean-donation-suite' ) );

		return sprintf(
			'<!DOCTYPE html><html><body style="margin:0;padding:24px;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:#f4f4f5;color:#111;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;border:1px solid #e4e4e7;">
  <h2 style="margin:0 0 4px;font-size:22px;color:#111;">%s</h2>
  <p style="margin:0 0 24px;font-size:13px;color:#525252;">%s</p>

  <table style="width:100%%;border-collapse:collapse;font-size:14px;">
    <tr><td style="padding:8px 0;color:#525252;width:45%%;">Receipt #</td><td style="padding:8px 0;font-weight:600;">%s</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Date</td><td style="padding:8px 0;">%s UTC</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Amount</td><td style="padding:8px 0;font-weight:600;font-size:16px;">%s %s</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Donor</td><td style="padding:8px 0;">%s</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Donor email</td><td style="padding:8px 0;">%s</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Campaign</td><td style="padding:8px 0;">%s</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Purpose</td><td style="padding:8px 0;">%s</td></tr>
    <tr><td style="padding:8px 0;color:#525252;">Payment method</td><td style="padding:8px 0;">PayPal</td></tr>
  </table>

  <div style="margin:24px 0 0;text-align:center;">
    <a href="%s" style="display:inline-block;padding:10px 20px;background:#111;color:#fff;border-radius:8px;text-decoration:none;font-size:14px;font-weight:600;">View in Dashboard</a>
  </div>
</div>
</body></html>',
			esc_html__( 'New Donation Received', 'donateocean-donation-suite' ),
			/* translators: %s: organization name */
			sprintf( esc_html__( '%s has received a new donation.', 'donateocean-donation-suite' ), esc_html( $org_name ) ),
			esc_html( $receipt_no ) ? esc_html( $receipt_no ) : '—',
			esc_html( $donation_date ),
			esc_html( $currency ),
			esc_html( $amount ),
			esc_html( $donor_display ),
			$donor_email ? sprintf( '<a href="mailto:%s" style="color:#111;">%s</a>', esc_attr( $donor_email ), esc_html( $donor_email ) ) : '—',
			esc_html( $campaign ) ? esc_html( $campaign ) : '—',
			esc_html( $purpose ) ? esc_html( $purpose ) : '—',
			esc_attr( $admin_url )
		);
	}
}
