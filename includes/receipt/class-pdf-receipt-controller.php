<?php
/**
 * PDF receipt download controller.
 *
 * Registers a hidden admin page that streams a generated PDF receipt to the
 * browser as a file download.
 *
 * Accessible at: admin.php?page=donadosu-pdf&id=POST_ID
 *
 * @package    Donation_Suite
 * @subpackage Receipt
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Receipt;

use DonationSuite\Core\Capabilities;
use DonationSuite\Core\ConfigService;
use DonationSuite\Donation\DonationMeta;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PdfReceiptController
 *
 * Handles the generation and streaming of PDF donation receipts from
 * the WordPress admin interface.
 *
 * @since 1.0.0
 */
class PdfReceiptController {

	/**
	 * Register the hidden admin submenu page for PDF receipt downloads.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'admin_menu',
			function () {
				add_submenu_page(
					'',
					'Download Receipt PDF',
					'Download Receipt PDF',
					Capabilities::VIEW_DONATIONS,
					'donadosu-pdf',
					'__return_null'
				);
			}
		);

		// Stream the PDF before WordPress sends any admin page output.
		add_action(
			'admin_init',
			function () {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( isset( $_GET['page'] ) && 'donadosu-pdf' === $_GET['page'] ) {
					$this->output();
				}
			}
		);
	}

	/**
	 * Generate and stream the PDF receipt as a browser download.
	 *
	 * Checks user permissions, loads donation meta, generates the PDF,
	 * and streams the binary output directly to the browser.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function output(): void {
		if ( ! Capabilities::can_view() ) {
			wp_die( esc_html__( 'You do not have permission to download receipts.', 'donateocean-donation-suite' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page, permission checked above.
		$post_id = absint( $_GET['id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || 'donadosu_donation' !== $post->post_type ) {
			wp_die( esc_html__( 'Donation not found.', 'donateocean-donation-suite' ) );
		}

		$receipt_statuses = array( 'donadosu_captured', 'donadosu_completed', 'donadosu_refunded', 'donadosu_sub_active', 'donadosu_sub_paused', 'donadosu_sub_cancelled' );
		if ( ! in_array( (string) get_post_status( $post_id ), $receipt_statuses, true ) ) {
			wp_die( esc_html__( 'Receipts are only available for completed donations.', 'donateocean-donation-suite' ) );
		}

		$meta = static function ( string $key ) use ( $post_id ): string {
			return (string) get_post_meta( $post_id, $key, true );
		};

		$config   = new ConfigService();
		$settings = $config->get_all();

		$data = array(
			'org_name'          => trim( (string) ( $settings['charity_name'] ?? '' ) ),
			'org_address'       => trim( (string) ( $settings['charity_address'] ?? '' ) ),
			'reg_id'            => trim( (string) ( $settings['reg_id'] ?? '' ) ),
			'contact_email'     => trim( (string) ( $settings['contact_email'] ?? '' ) ),
			'tax_statement'     => $config->get_receipt_statement(),
			'receipt_no'        => $meta( DonationMeta::RECEIPT_NO ),
			'donation_date'     => (string) get_post_time( 'Y-m-d H:i:s', true, $post_id ),
			'amount'            => $meta( DonationMeta::AMOUNT ),
			'currency'          => $meta( DonationMeta::CURRENCY ),
			'order_id'          => $meta( DonationMeta::ORDER_ID ),
			'capture_id'        => $meta( DonationMeta::CAPTURE_ID ),
			'campaign'          => $meta( DonationMeta::CAMPAIGN ),
			'purpose'           => $meta( DonationMeta::PURPOSE ),
			'frequency'         => $meta( DonationMeta::DONATION_FREQUENCY ),
			'payment_source'    => $meta( DonationMeta::PAYMENT_SOURCE ),
			'offline_reference' => $meta( DonationMeta::OFFLINE_REFERENCE ),
			'donor_name'        => $meta( DonationMeta::DONOR_NAME ),
			'donor_email'       => $meta( DonationMeta::DONOR_EMAIL ),
			'donor_company'     => $meta( DonationMeta::DONOR_COMPANY ),
			'donor_address'     => $meta( DonationMeta::DONOR_ADDRESS ),
			'donor_city'        => $meta( DonationMeta::DONOR_CITY ),
			'donor_postal'      => $meta( DonationMeta::DONOR_POSTAL ),
		);

		$receipt_no = $data['receipt_no'] ? $data['receipt_no'] : sprintf( 'donation-%d', $post_id );
		$filename   = sanitize_file_name( 'receipt-' . $receipt_no . '.pdf' );

		$generator = new PdfReceiptGenerator();
		$pdf_bytes = $generator->generate( $data );

		// Discard any output buffers so we can stream the binary cleanly.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf_bytes ) );
		header( 'Cache-Control: private, no-store' );
		header( 'Pragma: no-cache' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $pdf_bytes;
		exit;
	}
}
