<?php
/**
 * Manual donation page for the Donation Suite admin.
 *
 * Lets admins record cash, cheque, or bank-transfer donations directly in
 * WordPress without going through PayPal. The resulting record uses the same
 * donadosu_donation post type and donadosu_completed status as PayPal donations,
 * so it appears seamlessly in reports, exports, and the donor profile.
 *
 * Accessible at: admin.php?page=donadosu-manual
 * Linked from the donations list via a top-of-page button.
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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ManualDonationPage
 *
 * Provides the admin interface for recording offline and manual donations.
 *
 * @since 1.0.0
 */
class ManualDonationPage {

	/**
	 * Register the hidden admin submenu page and list button hook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'admin_menu',
			function () {
				$hook = add_submenu_page(
					'',
					__( 'Add Manual Donation', 'donateocean-donation-suite' ),
					__( 'Add Donation', 'donateocean-donation-suite' ),
					'manage_options',
					'donadosu-manual',
					array( $this, 'render' )
				);

				if ( $hook ) {
					add_action(
						"load-{$hook}",
						array( $this, 'handle_load' )
					);
				}
			}
		);

		// Add "Add Manual Donation" button above the donations list.
		add_action( 'donadosu_donation_list_top', array( $this, 'render_list_button' ) );
	}

	/**
	 * Runs on the load-{page} hook, before any output is sent.
	 *
	 * Sets the page title to fix the strip_tags() null deprecation
	 * for hidden submenu pages in WordPress admin-header.php.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_load(): void {
		global $title;
		$title = __( 'Add Manual Donation', 'donateocean-donation-suite' );
	}

	/**
	 * Render the "Add Manual Donation" button on the donations list page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_list_button(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<a href="%s" class="page-title-action">%s</a>',
			esc_url( admin_url( 'admin.php?page=donadosu-manual' ) ),
			esc_html__( '+ Add Manual Donation', 'donateocean-donation-suite' )
		);
	}

	/**
	 * Render the manual donation form page.
	 *
	 * Handles the POST submission and redirects on success, or displays
	 * an error notice on failure.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to add donations.', 'donateocean-donation-suite' ) );
		}

		$config   = new ConfigService();
		$settings = $config->get_all();
		$currency = strtoupper( sanitize_text_field( (string) ( $settings['currency'] ?? 'USD' ) ) );
		$notice   = '';

		if (
			isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD']
			&& isset( $_POST['donadosu_manual_submit'] )
			&& check_admin_referer( 'donadosu_manual_donation' )
		) {
			$result = $this->process( wp_unslash( $_POST ), $currency );
			if ( isset( $result['post_id'] ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'      => 'donadosu-detail',
							'id'        => $result['post_id'],
							'donadosu_msg' => 'manual_added',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
			$notice = '<div class="notice notice-error inline"><p>' . esc_html( $result['error'] ?? __( 'An unknown error occurred.', 'donateocean-donation-suite' ) ) . '</p></div>';
		}

		include DONADOSU_PATH . 'templates/manual-donation-form.php';
	}

	/**
	 * Create a completed donation record from the submitted form data.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $post     The $_POST data.
	 * @param string              $currency Default currency from settings.
	 * @return array{post_id:int}|array{error:string} Result with post ID or error message.
	 */
	private function process( array $post, string $currency ): array {
		$amount = (float) ( $post['amount'] ?? 0 );
		if ( $amount <= 0 ) {
			return array( 'error' => __( 'A valid donation amount greater than zero is required.', 'donateocean-donation-suite' ) );
		}

		$donor_email = sanitize_email( (string) ( $post['donor_email'] ?? '' ) );
		if ( ! $donor_email || ! is_email( $donor_email ) ) {
			return array( 'error' => __( 'A valid donor email address is required.', 'donateocean-donation-suite' ) );
		}

		$currency          = strtoupper( sanitize_text_field( (string) ( $post['currency'] ?? $currency ) ) );
		$donor_name        = sanitize_text_field( (string) ( $post['donor_name'] ?? '' ) );
		$donor_phone       = sanitize_text_field( (string) ( $post['donor_phone'] ?? '' ) );
		$donor_company     = sanitize_text_field( (string) ( $post['donor_company'] ?? '' ) );
		$donor_address     = sanitize_text_field( (string) ( $post['donor_address'] ?? '' ) );
		$donor_city        = sanitize_text_field( (string) ( $post['donor_city'] ?? '' ) );
		$donor_postal      = sanitize_text_field( (string) ( $post['donor_postal'] ?? '' ) );
		$donor_message     = sanitize_textarea_field( (string) ( $post['donor_message'] ?? '' ) );
		$campaign          = sanitize_text_field( (string) ( $post['campaign'] ?? '' ) );
		$purpose           = sanitize_text_field( (string) ( $post['purpose'] ?? '' ) );
		$payment_source    = sanitize_key( (string) ( $post['payment_source'] ?? 'cash' ) );
		$offline_reference = sanitize_text_field( (string) ( $post['offline_reference'] ?? '' ) );
		$is_anonymous      = ! empty( $post['is_anonymous'] ) ? 1 : 0;
		$send_receipt      = ! empty( $post['send_receipt'] );

		$donation_date = sanitize_text_field( (string) ( $post['donation_date'] ?? gmdate( 'Y-m-d' ) ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $donation_date ) ) {
			$donation_date = gmdate( 'Y-m-d' );
		}
		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $donation_date ) );
		if ( ! checkdate( $month, $day, $year ) ) {
			$donation_date = gmdate( 'Y-m-d' );
		}
		$post_date = $donation_date . ' 00:00:00';

		// Receipt number prefixed with M- to distinguish offline donations.
		$receipt_no = 'M-' . strtoupper( wp_generate_password( 8, false ) );

		$post_id = wp_insert_post(
			array(
				'post_type'     => 'donadosu_donation',
				'post_status'   => 'donadosu_completed',
				'post_title'    => sprintf(
					/* translators: 1: currency code, 2: formatted amount, 3: donor email */
					__( 'Manual – %1$s %2$s – %3$s', 'donateocean-donation-suite' ),
					$currency,
					number_format( $amount, 2 ),
					$donor_email
				),
				'post_date'     => $post_date,
				'post_date_gmt' => get_gmt_from_date( $post_date ),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => $post_id->get_error_message() );
		}

		$post_id = (int) $post_id;

		$metas = array(
			DonationMeta::AMOUNT             => $amount,
			DonationMeta::GROSS_AMOUNT       => $amount,
			DonationMeta::CURRENCY           => $currency,
			DonationMeta::DONOR_EMAIL        => $donor_email,
			DonationMeta::DONOR_NAME         => $donor_name,
			DonationMeta::DONOR_PHONE        => $donor_phone,
			DonationMeta::DONOR_COMPANY      => $donor_company,
			DonationMeta::DONOR_ADDRESS      => $donor_address,
			DonationMeta::DONOR_CITY         => $donor_city,
			DonationMeta::DONOR_POSTAL       => $donor_postal,
			DonationMeta::DONOR_MESSAGE      => $donor_message,
			DonationMeta::CAMPAIGN           => $campaign,
			DonationMeta::PURPOSE            => $purpose,
			DonationMeta::DONATION_FREQUENCY => 'one_time',
			DonationMeta::IS_ANONYMOUS       => $is_anonymous,
			DonationMeta::RECEIPT_NO         => $receipt_no,
			DonationMeta::ENV                => 'manual',
			DonationMeta::PAYMENT_SOURCE     => $payment_source,
			DonationMeta::OFFLINE_REFERENCE  => $offline_reference,
		);

		foreach ( $metas as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		// Initial history entry.
		$history   = array();
		$history[] = array(
			'status'  => 'donadosu_completed',
			'time'    => gmdate( 'c' ),
			'context' => array(
				'source' => 'manual_entry',
				'actor'  => (string) wp_get_current_user()->user_email,
				'method' => $payment_source,
			),
		);
		update_post_meta( $post_id, DonationMeta::STATUS_HISTORY, $history );

		// Always fire the completion hook so integrations (Zapier, Slack, etc.)
		// are notified. The receipt email handler checks its own conditions.
		do_action( 'donadosu_donation_completed', $post_id, array( 'source' => 'manual_entry', 'send_receipt' => $send_receipt ) );

		// Bust analytics cache so the new donation shows immediately.
		\DonationSuite\Reporting\AnalyticsWidget::bust_cache();

		return array( 'post_id' => $post_id );
	}
}
