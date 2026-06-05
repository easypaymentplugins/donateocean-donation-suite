<?php
/**
 * Year-end giving summary service.
 *
 * Groups all completed donations in a calendar year by donor email and sends
 * each donor a single consolidated tax-summary email listing every donation.
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
 * Class YearEndSummaryService
 *
 * Sends consolidated year-end donation summaries to donors. Triggered manually
 * via the admin action or automatically by the donadosu_donation_year_end_summary
 * cron job on January 1st.
 *
 * @since 1.0.0
 */
class YearEndSummaryService {

	/**
	 * Send year-end summaries for all donors who donated in the given year.
	 *
	 * Runs in batches to avoid memory exhaustion on high-volume sites. Uses
	 * a transient to avoid re-sending if already dispatched for the year.
	 *
	 * @since 1.0.0
	 *
	 * @param int $year Four-digit year (default: previous calendar year).
	 * @return int Number of summaries sent successfully.
	 */
	public static function send_summaries( int $year = 0 ): int {
		if ( 0 === $year ) {
			$year = (int) gmdate( 'Y' ) - 1;
		}

		global $wpdb;

		$config   = new ConfigService();
		$settings = $config->get_all();

		$org_name      = trim( (string) ( $settings['charity_name'] ?? '' ) );
		$org_address   = trim( (string) ( $settings['charity_address'] ?? '' ) );
		$reg_id        = trim( (string) ( $settings['reg_id'] ?? '' ) );
		$contact_email = trim( (string) ( $settings['contact_email'] ?? '' ) );
		$tax_statement = $config->get_receipt_statement();
		$privacy_url   = esc_url( (string) ( $settings['privacy_url'] ?? '' ) );

		// Fetch all unique donor emails with completed donations in the year.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate year-end query.
		$emails = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				 WHERE p.post_type     = 'donadosu_donation'
				   AND p.post_status   = 'donadosu_completed'
				   AND YEAR(p.post_date_gmt) = %d
				   AND pm.meta_value  != ''",
				DonationMeta::DONOR_EMAIL,
				$year
			)
		);

		if ( ! is_array( $emails ) ) {
			return 0;
		}

		$sent = 0;
		foreach ( $emails as $email ) {
			$email = sanitize_email( (string) $email );
			if ( ! $email ) {
				continue;
			}

			// Avoid re-sending if already dispatched this year.
			$sent_key = 'donadosu_ye_summary_' . $year . '_' . md5( $email );
			if ( get_transient( $sent_key ) ) {
				continue;
			}

			$donations = self::get_year_donations( $email, $year );
			if ( empty( $donations ) ) {
				continue;
			}

			// Group totals per currency so multi-currency donors see an accurate summary.
			$totals_by_currency = array();
			foreach ( $donations as $donation ) {
				$cur_code                        = $donation['currency'] ? $donation['currency'] : 'USD';
				$totals_by_currency[ $cur_code ] = ( $totals_by_currency[ $cur_code ] ?? 0.0 ) + $donation['amount'];
			}

			$body    = self::build_html( $email, $donations, $totals_by_currency, $year, $org_name, $org_address, $reg_id, $contact_email, $tax_statement, $privacy_url );
			/* translators: 1: organization name, 2: four-digit year */
			$subject = sprintf( __( '[%1$s] Your %2$d donation summary', 'donateocean-donation-suite' ), $org_name, $year );
			$headers = \DonationSuite\Email\ReceiptEmailService::build_email_headers();

			if ( wp_mail( $email, $subject, $body, $headers ) ) {
				$sent++;
				// Mark as sent for this year — expires after 13 months.
				set_transient( $sent_key, 1, 13 * MONTH_IN_SECONDS );
			}
		}

		return $sent;
	}

	/**
	 * Return completed donation rows for a donor in a given year.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Donor email address.
	 * @param int    $year  Four-digit calendar year.
	 * @return array<int, array{receipt_no:string, date:string, amount:float, currency:string, campaign:string}>
	 */
	private static function get_year_donations( string $email, int $year ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate year-end query.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s AND pm.meta_value = %s
				 WHERE p.post_type     = 'donadosu_donation'
				   AND p.post_status   = 'donadosu_completed'
				   AND YEAR(p.post_date_gmt) = %d
				 ORDER BY p.post_date_gmt ASC",
				DonationMeta::DONOR_EMAIL,
				$email,
				$year
			)
		);

		if ( ! is_array( $post_ids ) ) {
			return array();
		}

		$rows = array();
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$rows[]  = array(
				'receipt_no' => (string) get_post_meta( $post_id, DonationMeta::RECEIPT_NO, true ),
				'date'       => (string) get_post_time( 'Y-m-d', true, $post_id ),
				'amount'     => (float) get_post_meta( $post_id, DonationMeta::AMOUNT, true ),
				'currency'   => (string) get_post_meta( $post_id, DonationMeta::CURRENCY, true ),
				'campaign'   => (string) get_post_meta( $post_id, DonationMeta::CAMPAIGN, true ),
			);
		}

		return $rows;
	}

	/**
	 * Build the HTML email body for a year-end donation summary.
	 *
	 * @since 1.0.0
	 *
	 * @param string              $email              Donor email address.
	 * @param array               $donations          List of donation rows.
	 * @param array<string,float> $totals_by_currency Totals grouped by currency code.
	 * @param int                 $year               Calendar year.
	 * @param string              $org_name           Organisation name.
	 * @param string              $org_address        Organisation address.
	 * @param string              $reg_id             Registration / Tax ID.
	 * @param string              $contact_email      Organisation contact email.
	 * @param string              $tax_statement      Tax disclaimer.
	 * @param string              $privacy_url        Privacy policy URL.
	 * @return string Complete HTML email body.
	 */
	private static function build_html(
		string $email,
		array $donations,
		array $totals_by_currency,
		int $year,
		string $org_name,
		string $org_address,
		string $reg_id,
		string $contact_email,
		string $tax_statement,
		string $privacy_url
	): string {
		$rows = '';
		foreach ( $donations as $donation ) {
			$rows .= sprintf(
				'<tr>
				  <td style="padding:6px 8px;border-bottom:1px solid #f4f4f5;">%s</td>
				  <td style="padding:6px 8px;border-bottom:1px solid #f4f4f5;">%s</td>
				  <td style="padding:6px 8px;border-bottom:1px solid #f4f4f5;text-align:right;font-weight:600;">%s %s</td>
				  <td style="padding:6px 8px;border-bottom:1px solid #f4f4f5;color:#525252;">%s</td>
				</tr>',
				esc_html( $donation['receipt_no'] ? $donation['receipt_no'] : '—' ),
				esc_html( $donation['date'] ),
				esc_html( $donation['currency'] ),
				esc_html( number_format( $donation['amount'], 2 ) ),
				esc_html( $donation['campaign'] ? $donation['campaign'] : '—' )
			);
		}

		$privacy_link = $privacy_url
			? sprintf( '<p style="font-size:12px;color:#71717a;text-align:center;"><a href="%s" style="color:#71717a;">Privacy policy</a></p>', esc_attr( $privacy_url ) )
			: '';

		// Build one tfoot row per currency so totals are always accurate.
		$tfoot_rows = '';
		foreach ( $totals_by_currency as $currency => $currency_total ) {
			$tfoot_rows .= sprintf(
				'<tr>
				  <td colspan="2" style="padding:10px 8px;font-weight:700;font-size:15px;">Total donated in %d (%s)</td>
				  <td style="padding:10px 8px;font-weight:700;font-size:15px;text-align:right;">%s %s</td>
				  <td></td>
				</tr>',
				$year,
				esc_html( $currency ),
				esc_html( $currency ),
				esc_html( number_format( $currency_total, 2 ) )
			);
		}

		// Build organization details section only when at least one field is configured.
		$has_org_details = '' !== $org_name || '' !== $reg_id || '' !== $org_address || '' !== $contact_email;
		$org_section     = '';

		if ( $has_org_details ) {
			$org_rows_html = '';
			if ( '' !== $org_name ) {
				$org_rows_html .= sprintf(
					'<tr><td style="padding:4px 0;color:#525252;width:45%%;">%s</td><td>%s</td></tr>',
					esc_html__( 'Name', 'donateocean-donation-suite' ),
					esc_html( $org_name )
				);
			}
			if ( '' !== $reg_id ) {
				$org_rows_html .= sprintf(
					'<tr><td style="padding:4px 0;color:#525252;">%s</td><td>%s</td></tr>',
					esc_html__( 'Tax / Registration ID', 'donateocean-donation-suite' ),
					esc_html( $reg_id )
				);
			}
			if ( '' !== $org_address ) {
				$org_rows_html .= sprintf(
					'<tr><td style="padding:4px 0;color:#525252;vertical-align:top;">%s</td><td>%s</td></tr>',
					esc_html__( 'Address', 'donateocean-donation-suite' ),
					AddressFormatter::to_html( AddressFormatter::format_org( $org_address ) )
				);
			}
			if ( '' !== $contact_email ) {
				$org_rows_html .= sprintf(
					'<tr><td style="padding:4px 0;color:#525252;">%s</td><td><a href="mailto:%s">%s</a></td></tr>',
					esc_html__( 'Contact', 'donateocean-donation-suite' ),
					esc_attr( $contact_email ),
					esc_html( $contact_email )
				);
			}

			$org_section = sprintf(
				'<hr style="margin:24px 0;border:0;border-top:1px solid #e4e4e7;" />
  <h3 style="margin:0 0 12px;font-size:15px;">%s</h3>
  <table style="width:100%%;font-size:14px;">%s</table>',
				esc_html__( 'Organization details', 'donateocean-donation-suite' ),
				$org_rows_html
			);
		}

		// Tax statement section — only shown when non-empty.
		$tax_section = '' !== $tax_statement
			? sprintf( '<div style="margin:24px 0 0;padding:12px 0;font-size:13px;color:#3f3f46;">%s</div>', esc_html( $tax_statement ) )
			: '';

		// Summary intro — use org name if available, otherwise generic text.
		$support_text = '' !== $org_name
			/* translators: %s: organization name */
			? sprintf( esc_html__( 'Thank you for your generous support of %s.', 'donateocean-donation-suite' ), esc_html( $org_name ) )
			: esc_html__( 'Thank you for your generous support.', 'donateocean-donation-suite' );

		return sprintf(
			'<!DOCTYPE html><html><body style="margin:0;padding:24px;font-family:Inter,-apple-system,sans-serif;background:#f4f4f5;color:#111;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;border:1px solid #e4e4e7;">
  <h2 style="margin:0 0 4px;font-size:22px;">%s</h2>
  <p style="margin:0 0 24px;font-size:13px;color:#525252;">%s</p>

  <table style="width:100%%;border-collapse:collapse;font-size:14px;">
    <thead>
      <tr style="background:#f9fafb;">
        <th style="padding:8px;text-align:left;font-size:12px;color:#525252;">%s</th>
        <th style="padding:8px;text-align:left;font-size:12px;color:#525252;">%s</th>
        <th style="padding:8px;text-align:right;font-size:12px;color:#525252;">%s</th>
        <th style="padding:8px;text-align:left;font-size:12px;color:#525252;">%s</th>
      </tr>
    </thead>
    <tbody>%s</tbody>
    <tfoot>%s</tfoot>
  </table>

  %s
  %s
  %s
</div>
</body></html>',
			/* translators: %d: four-digit year */
			sprintf( esc_html__( '%d Donation Summary', 'donateocean-donation-suite' ), $year ),
			$support_text,
			esc_html__( 'Receipt #', 'donateocean-donation-suite' ),
			esc_html__( 'Date', 'donateocean-donation-suite' ),
			esc_html__( 'Amount', 'donateocean-donation-suite' ),
			esc_html__( 'Campaign', 'donateocean-donation-suite' ),
			$rows,
			$tfoot_rows,
			$org_section,
			$tax_section,
			$privacy_link
		);
	}
}
