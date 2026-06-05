<?php
/**
 * Donation detail page for the Donation Suite admin.
 *
 * Renders a read-only detail view for a single donation, accessible at
 * admin.php?page=donadosu-detail&id=POST_ID. Linked from the
 * "View Details" row action in the donations list.
 *
 * @package    Donation_Suite
 * @subpackage Admin
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Admin;

use DonationSuite\Core\Capabilities;
use DonationSuite\Donation\DonationMeta;
use DonationSuite\Email\ReceiptEmailService;
use DonationSuite\PayPal\PayPalClient;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DonationDetailPage
 *
 * Displays a comprehensive read-only view of a single donation record,
 * including all meta fields, status history, and action buttons.
 *
 * @since 1.0.0
 */
class DonationDetailPage {

	/**
	 * PayPal client instance.
	 *
	 * @since 1.0.0
	 * @var PayPalClient
	 */
	private PayPalClient $paypal;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param PayPalClient $paypal PayPal client instance.
	 * @return void
	 */
	public function __construct( PayPalClient $paypal ) {
		$this->paypal = $paypal;
	}

	/**
	 * Register the hidden admin submenu page.
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
					__( 'Donation Details', 'donateocean-donation-suite' ),
					__( 'Donation Details', 'donateocean-donation-suite' ),
					Capabilities::VIEW_DONATIONS,
					'donadosu-detail',
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
	}

	/**
	 * Runs on the load-{page} hook, before any output is sent.
	 *
	 * Sets the page title (fixes strip_tags null deprecation for hidden
	 * submenu pages) and handles the resend-receipt redirect so that
	 * wp_safe_redirect() can send headers successfully.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_load(): void {
		global $title;
		$title = __( 'Donation Details', 'donateocean-donation-suite' );

		// Handle resend receipt action before output starts.
		if ( isset( $_POST['donadosu_resend_receipt'] ) ) {
			if ( ! Capabilities::can_manage() ) {
				wp_die( esc_html__( 'You do not have permission to resend receipts.', 'donateocean-donation-suite' ) );
			}
			$resend_post_id = absint( $_POST['donadosu_post_id'] ?? 0 );
			$nonce_ok       = isset( $_POST['_wpnonce'] ) && wp_verify_nonce(
				sanitize_text_field( (string) wp_unslash( $_POST['_wpnonce'] ) ),
				'donadosu_resend_receipt_' . $resend_post_id
			);
			if ( $nonce_ok && $resend_post_id > 0 ) {
				ReceiptEmailService::resend_receipt( $resend_post_id );
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'      => 'donadosu-detail',
							'id'        => $resend_post_id,
							'donadosu_msg' => 'receipt_resent',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}
	}

	/**
	 * Render the donation detail page.
	 *
	 * Loads all meta fields for the donation, builds status labels,
	 * handles the resend receipt POST action, and displays notices.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Capabilities::can_view() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'donateocean-donation-suite' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page display.
		$post_id = absint( $_GET['id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || 'donadosu_donation' !== $post->post_type ) {
			wp_die( esc_html__( 'Donation not found.', 'donateocean-donation-suite' ) );
		}

		// Convenience meta loader.
		$meta = static function ( string $key ) use ( $post_id ): string {
			return (string) get_post_meta( $post_id, $key, true );
		};

		$receipt_no     = $meta( DonationMeta::RECEIPT_NO );
		$amount         = $meta( DonationMeta::AMOUNT );
		$currency       = $meta( DonationMeta::CURRENCY );
		$order_id       = $meta( DonationMeta::ORDER_ID );
		$capture_id     = $meta( DonationMeta::CAPTURE_ID );
		$env            = $meta( DonationMeta::ENV );
		$donor_name     = $meta( DonationMeta::DONOR_NAME );
		$donor_email    = $meta( DonationMeta::DONOR_EMAIL );
		$donor_phone    = $meta( DonationMeta::DONOR_PHONE );
		$donor_company  = $meta( DonationMeta::DONOR_COMPANY );
		$donor_address  = $meta( DonationMeta::DONOR_ADDRESS );
		$donor_city     = $meta( DonationMeta::DONOR_CITY );
		$donor_postal   = $meta( DonationMeta::DONOR_POSTAL );
		$donor_message  = $meta( DonationMeta::DONOR_MESSAGE );
		$campaign       = $meta( DonationMeta::CAMPAIGN );
		$purpose        = $meta( DonationMeta::PURPOSE );
		$frequency      = $meta( DonationMeta::DONATION_FREQUENCY );
		$receipt_status = $meta( DonationMeta::RECEIPT_EMAIL_STATUS );
		$receipt_sent_at = $meta( DonationMeta::RECEIPT_SENT_AT );
		$last_event_id  = $meta( DonationMeta::LAST_WEBHOOK_EVENT_ID );
		$last_event_at  = $meta( DonationMeta::LAST_WEBHOOK_EVENT_AT );
		$status         = (string) get_post_status( $post_id );
		$history        = get_post_meta( $post_id, DonationMeta::STATUS_HISTORY, true );
		$history        = is_array( $history ) ? $history : array();

		// New feature meta.
		$is_anonymous       = (bool) get_post_meta( $post_id, DonationMeta::IS_ANONYMOUS, true );
		$is_tribute         = (bool) get_post_meta( $post_id, DonationMeta::IS_TRIBUTE, true );
		$tribute_type       = $meta( DonationMeta::TRIBUTE_TYPE );
		$tribute_name       = $meta( DonationMeta::TRIBUTE_NAME );
		$tribute_notify     = $meta( DonationMeta::TRIBUTE_NOTIFY_EMAIL );
		$fee_covered        = (bool) get_post_meta( $post_id, DonationMeta::FEE_COVERED, true );
		$fee_amount         = $meta( DonationMeta::FEE_AMOUNT );
		$gross_amount       = $meta( DonationMeta::GROSS_AMOUNT );
		$giving_level       = $meta( DonationMeta::GIVING_LEVEL );
		$sub_id             = $meta( DonationMeta::SUBSCRIPTION_ID );
		$sub_cycle          = $meta( DonationMeta::SUBSCRIPTION_CYCLE );
		$sub_status         = $meta( DonationMeta::SUBSCRIPTION_STATUS );
		$sub_next_billing   = $meta( DonationMeta::SUBSCRIPTION_NEXT_BILLING );
		$vault_id           = $meta( DonationMeta::VAULT_PAYMENT_TOKEN_ID );
		$dispute_id         = $meta( DonationMeta::DISPUTE_ID );
		$dispute_status     = $meta( DonationMeta::DISPUTE_STATUS );
		$dispute_reason     = $meta( DonationMeta::DISPUTE_REASON );
		$fraud_flag         = (bool) get_post_meta( $post_id, DonationMeta::FRAUD_FLAG, true );

		$status_labels = array(
			'donadosu_created'       => array(
				'label' => __( 'Created', 'donateocean-donation-suite' ),
				'class' => 'donadosu-badge--neutral',
			),
			'donadosu_approved'      => array(
				'label' => __( 'Approved', 'donateocean-donation-suite' ),
				'class' => 'donadosu-badge--info',
			),
			'donadosu_captured'      => array(
				'label' => __( 'Captured', 'donateocean-donation-suite' ),
				'class' => 'donadosu-badge--info',
			),
			'donadosu_pending'       => array(
				'label' => __( 'Pending', 'donateocean-donation-suite' ),
				'class' => 'donadosu-badge--warning',
			),
			'donadosu_completed'     => array(
				'label' => __( 'Completed', 'donateocean-donation-suite' ),
				'class' => 'donadosu-badge--success',
			),
			'donadosu_refunded'      => array(
				'label' => __( 'Refunded', 'donateocean-donation-suite' ),
				'class' => 'donadosu-badge--warning',
			),
			'donadosu_failed'        => array(
				'label' => __( 'Failed', 'donateocean-donation-suite' ),
				'class' => 'donadosu-badge--error',
			),
			'donadosu_disputed'      => array(
				'label' => __( 'Disputed', 'donateocean-donation-suite' ),
				'class' => 'donadosu-badge--error',
			),
			'donadosu_sub_active'    => array(
				'label' => __( 'Subscription Active', 'donateocean-donation-suite' ),
				'class' => 'donadosu-badge--success',
			),
			'donadosu_sub_paused'    => array(
				'label' => __( 'Subscription Paused', 'donateocean-donation-suite' ),
				'class' => 'donadosu-badge--warning',
			),
			'donadosu_sub_cancelled' => array(
				'label' => __( 'Subscription Cancelled', 'donateocean-donation-suite' ),
				'class' => 'donadosu-badge--neutral',
			),
			'donadosu_sub_failed'    => array(
				'label' => __( 'Subscription Failed', 'donateocean-donation-suite' ),
				'class' => 'donadosu-badge--error',
			),
		);

		$status_label = $status_labels[ $status ]['label'] ?? $status;
		$status_class = $status_labels[ $status ]['class'] ?? 'donadosu-badge--neutral';

		$back_url    = admin_url( 'edit.php?post_type=donadosu_donation' );
		$pdf_url     = add_query_arg(
			array(
				'page' => 'donadosu-pdf',
				'id'   => $post_id,
			),
			admin_url( 'admin.php' )
		);
		$donor_url   = $donor_email
			? add_query_arg(
				array(
					'page'  => 'donadosu-donor',
					'email' => rawurlencode( $donor_email ),
				),
				admin_url( 'admin.php' )
			)
			: '';

		// Success / error notice from refund, cancel, or resend redirect.
		$notice = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading redirect message keys for display only; no data is mutated.
		if ( isset( $_GET['donadosu_msg'] ) ) {
			$success_messages = array(
				'refunded'               => __( 'Refund processed successfully.', 'donateocean-donation-suite' ),
				'partially_refunded'     => __( 'Partial refund processed successfully. The donation remains completed for the unrefunded balance.', 'donateocean-donation-suite' ),
				'receipt_resent'         => __( 'Receipt email resent successfully.', 'donateocean-donation-suite' ),
				'subscription_cancelled' => __( 'Subscription cancelled successfully.', 'donateocean-donation-suite' ),
				'subscription_paused'    => __( 'Subscription paused successfully.', 'donateocean-donation-suite' ),
				'subscription_resumed'   => __( 'Subscription reactivated successfully.', 'donateocean-donation-suite' ),
				'manual_added'           => __( 'Manual donation recorded successfully.', 'donateocean-donation-suite' ),
			);
			$msg_key = sanitize_key( (string) ( $_GET['donadosu_msg'] ?? '' ) );
			$msg     = $success_messages[ $msg_key ] ?? '';
			if ( $msg ) {
				$notice = '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
			}
		}
		if ( ! $notice && isset( $_GET['donadosu_error'] ) ) {
			$error_messages = array(
				'not_completed'            => __( 'Refund failed: only completed or disputed donations can be refunded.', 'donateocean-donation-suite' ),
				'no_capture_id'            => __( 'Refund failed: no PayPal capture ID found for this donation.', 'donateocean-donation-suite' ),
				'invalid_refund_amount'    => __( 'Refund failed: refund amount must be a valid positive number.', 'donateocean-donation-suite' ),
				'refund_exceeds_captured'  => __( 'Refund failed: refund amount exceeds the captured amount.', 'donateocean-donation-suite' ),
				'refund_failed'            => __( 'Refund failed: PayPal declined the refund request. Check PayPal dashboard.', 'donateocean-donation-suite' ),
				'not_cancellable'       => __( 'Subscription cannot be cancelled: it is not in an active or paused state.', 'donateocean-donation-suite' ),
				'no_subscription_id'    => __( 'No PayPal subscription ID found for this donation.', 'donateocean-donation-suite' ),
				'cancel_failed'         => __( 'Subscription cancel failed: PayPal rejected the request. Check PayPal dashboard.', 'donateocean-donation-suite' ),
				'not_pausable'          => __( 'Subscription cannot be paused: it is not currently active.', 'donateocean-donation-suite' ),
				'pause_failed'          => __( 'Subscription pause failed: PayPal rejected the request. Check PayPal dashboard.', 'donateocean-donation-suite' ),
				'not_resumable'         => __( 'Subscription cannot be resumed: it is not currently paused.', 'donateocean-donation-suite' ),
				'resume_failed'         => __( 'Subscription resume failed: PayPal rejected the request. Check PayPal dashboard.', 'donateocean-donation-suite' ),
			);
			$error_key = sanitize_key( (string) ( $_GET['donadosu_error'] ?? '' ) );
			$msg       = $error_messages[ $error_key ] ?? __( 'An unknown error occurred.', 'donateocean-donation-suite' );
			$notice    = '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$resend_receipt_nonce = wp_create_nonce( 'donadosu_resend_receipt_' . $post_id );

		// Meta for offline/manual donations.
		$payment_source = $meta( DonationMeta::PAYMENT_SOURCE );
		$offline_ref    = $meta( DonationMeta::OFFLINE_REFERENCE );

		// Template expects camelCase variables.
		$postId         = $post_id;
		$receiptNo      = $receipt_no;
		$orderId        = $order_id;
		$captureId      = $capture_id;
		$donorName      = $donor_name;
		$donorEmail     = $donor_email;
		$donorPhone     = $donor_phone;
		$donorCompany   = $donor_company;
		$donorAddress   = $donor_address;
		$donorCity      = $donor_city;
		$donorPostal    = $donor_postal;
		$donorMessage   = $donor_message;
		$receiptStatus  = $receipt_status;
		$receiptSentAt  = $receipt_sent_at;
		$lastEventId    = $last_event_id;
		$lastEventAt    = $last_event_at;
		$statusLabel    = $status_label;
		$statusClass    = $status_class;
		$backUrl        = $back_url;
		$pdfUrl         = $pdf_url;
		$donorUrl       = $donor_url;
		$isAnonymous    = $is_anonymous;
		$isTribute      = $is_tribute;
		$tributeType    = $tribute_type;
		$tributeName    = $tribute_name;
		$tributeNotify  = $tribute_notify;
		$feeCovered     = $fee_covered;
		$feeAmount      = $fee_amount;
		$grossAmount    = $gross_amount;
		$givingLevel    = $giving_level;
		$subId          = $sub_id;
		$subCycle       = $sub_cycle;
		$subStatus      = $sub_status;
		$subNextBilling = $sub_next_billing;
		$vaultId        = $vault_id;
		$disputeId      = $dispute_id;
		$disputeStatus  = $dispute_status;
		$disputeReason  = $dispute_reason;
		$fraudFlag      = $fraud_flag;
		$paymentSource  = $payment_source;
		$offlineRef     = $offline_ref;

		include DONADOSU_PATH . 'templates/admin-donation-detail.php';
	}
}
