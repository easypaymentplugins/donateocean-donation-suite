<?php
/**
 * Admin donation list customisation.
 *
 * Adds custom columns, filters, and bulk actions to the donadosu_donation
 * post type list table in the WordPress admin.
 *
 * @package    Donation_Suite
 * @subpackage Reporting
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Reporting;

use DonationSuite\Core\Capabilities;
use DonationSuite\Donation\DonationMeta;
use DonationSuite\Donation\DonationRepositoryInterface;
use DonationSuite\Email\ReceiptEmailService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminList
 *
 * Customises the WordPress admin list table for the donadosu_donation post type
 * with additional columns, date/campaign filters, CSV export link, and bulk
 * receipt resend functionality.
 *
 * @since 1.0.0
 */
class AdminList {

	/**
	 * Donation repository instance.
	 *
	 * @since 1.0.0
	 * @var DonationRepositoryInterface
	 */
	private DonationRepositoryInterface $repository;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param DonationRepositoryInterface $repository Donation repository.
	 */
	public function __construct( DonationRepositoryInterface $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register all WordPress hooks for the admin list table.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'manage_donadosu_donation_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_donadosu_donation_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'filters' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_filters' ) );

		// Bulk actions — donation managers can resend receipts in bulk.
		add_filter( 'bulk_actions-edit-donadosu_donation', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-donadosu_donation', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_action_notice' ) );
	}

	/**
	 * Add custom columns to the donations list table.
	 *
	 * @since 1.0.0
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns array.
	 */
	public function columns( array $columns ): array {
		$columns['receipt']     = __( 'Receipt #', 'donateocean-donation-suite' );
		$columns['amount']      = __( 'Amount', 'donateocean-donation-suite' );
		$columns['currency']    = __( 'Currency', 'donateocean-donation-suite' );
		$columns['frequency']   = __( 'Type', 'donateocean-donation-suite' );
		$columns['donor_email'] = __( 'Donor', 'donateocean-donation-suite' );
		return $columns;
	}

	/**
	 * Render content for custom columns in the donations list table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column  Column identifier.
	 * @param int    $post_id Donation post ID.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( 'receipt' === $column ) {
			echo esc_html( (string) get_post_meta( $post_id, DonationMeta::RECEIPT_NO, true ) );
			return;
		}

		if ( 'amount' === $column ) {
			$amount     = (float) get_post_meta( $post_id, DonationMeta::AMOUNT, true );
			$fraud_flag = (bool) get_post_meta( $post_id, DonationMeta::FRAUD_FLAG, true );

			if ( $fraud_flag ) {
				echo '<span style="color:#b91c1c;font-weight:700;" title="' . esc_attr__( 'High-value donation — flagged for review', 'donateocean-donation-suite' ) . '">&#x2691; ' . esc_html( number_format( $amount, 2 ) ) . '</span>';
			} else {
				echo esc_html( number_format( $amount, 2 ) );
			}
			return;
		}

		if ( 'currency' === $column ) {
			echo esc_html( (string) get_post_meta( $post_id, DonationMeta::CURRENCY, true ) );
			return;
		}

		if ( 'frequency' === $column ) {
			$freq   = (string) get_post_meta( $post_id, DonationMeta::DONATION_FREQUENCY, true );
			$labels = array(
				'one_time' => __( 'One-time', 'donateocean-donation-suite' ),
				'monthly'  => __( 'Monthly', 'donateocean-donation-suite' ),
				'annual'   => __( 'Annual', 'donateocean-donation-suite' ),
			);
			echo esc_html( $labels[ $freq ] ?? ( $freq ? $freq : __( 'One-time', 'donateocean-donation-suite' ) ) );
			return;
		}

		if ( 'donor_email' === $column ) {
			$is_anonymous = (bool) get_post_meta( $post_id, DonationMeta::IS_ANONYMOUS, true );

			if ( $is_anonymous ) {
				echo '<em style="color:#6b7280;">' . esc_html__( 'Anonymous', 'donateocean-donation-suite' ) . '</em>';
			} else {
				$donor_name  = sanitize_text_field( (string) get_post_meta( $post_id, DonationMeta::DONOR_NAME, true ) );
				$donor_email = sanitize_email( (string) get_post_meta( $post_id, DonationMeta::DONOR_EMAIL, true ) );

				if ( $donor_name ) {
					echo esc_html( $donor_name );
					if ( $donor_email ) {
						echo '<br><small>' . esc_html( $donor_email ) . '</small>';
					}
				} else {
					echo esc_html( $donor_email ? $donor_email : '—' );
				}
			}
			return;
		}
	}

	/**
	 * Render date range, campaign filter inputs, and CSV export link.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function filters(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'donadosu_donation' !== $screen->post_type ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Admin list table filter values for display.
		$from     = sanitize_text_field( (string) wp_unslash( $_GET['donadosu_from']    ?? '' ) );
		$to       = sanitize_text_field( (string) wp_unslash( $_GET['donadosu_to']      ?? '' ) );
		$campaign = sanitize_text_field( (string) wp_unslash( $_GET['donadosu_campaign'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<input type="date" name="donadosu_from" value="' . esc_attr( $from ) . '" title="' . esc_attr__( 'From date', 'donateocean-donation-suite' ) . '" /> ';
		echo '<input type="date" name="donadosu_to" value="' . esc_attr( $to ) . '" title="' . esc_attr__( 'To date', 'donateocean-donation-suite' ) . '" /> ';
		echo '<input type="text" name="donadosu_campaign" value="' . esc_attr( $campaign ) . '" placeholder="' . esc_attr__( 'Campaign…', 'donateocean-donation-suite' ) . '" style="height:30px;line-height:28px;" title="' . esc_attr__( 'Filter by campaign name', 'donateocean-donation-suite' ) . '" /> ';

		if ( Capabilities::can_export() ) {
			$export_nonce = wp_create_nonce( 'donadosu_export_csv' );
			echo '<a href="' . esc_url(
				add_query_arg(
					array(
						'action'          => 'donadosu_export_csv',
						'donadosu_from'     => $from,
						'donadosu_to'       => $to,
						'donadosu_campaign' => $campaign,
						'_wpnonce'        => $export_nonce,
					),
					admin_url( 'admin-post.php' )
				)
			) . '" class="button button-secondary" style="height:30px;line-height:28px;">' . esc_html__( 'Export CSV', 'donateocean-donation-suite' ) . '</a>';
		}
	}

	/**
	 * Add the resend receipts option to the bulk-actions dropdown.
	 *
	 * @since 1.0.0
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array Modified bulk actions array.
	 */
	public function register_bulk_actions( array $actions ): array {
		if ( Capabilities::can_manage() ) {
			$actions['donadosu_resend_receipts'] = __( 'Resend receipts', 'donateocean-donation-suite' );
		}
		return $actions;
	}

	/**
	 * Process the bulk resend-receipts action.
	 *
	 * WordPress passes the redirect URL through this filter; we append a
	 * count so the notice callback can display the result.
	 *
	 * @since 1.0.0
	 *
	 * @param string $redirect_url URL to redirect to after bulk action.
	 * @param string $action       Bulk action being performed.
	 * @param array  $post_ids     Array of selected post IDs.
	 * @return string Modified redirect URL with result counts.
	 */
	public function handle_bulk_actions( string $redirect_url, string $action, array $post_ids ): string {
		if ( 'donadosu_resend_receipts' !== $action ) {
			return $redirect_url;
		}

		if ( ! Capabilities::can_manage() ) {
			return $redirect_url;
		}

		$sent   = 0;
		$failed = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );

			if ( ! $post || 'donadosu_donation' !== $post->post_type ) {
				continue;
			}

			if ( ReceiptEmailService::resend_receipt( $post_id ) ) {
				$sent++;
			} else {
				$failed++;
			}
		}

		return add_query_arg(
			array(
				'donadosu_bulk_sent'   => $sent,
				'donadosu_bulk_failed' => $failed,
			),
			$redirect_url
		);
	}

	/**
	 * Show a WordPress admin notice after a bulk resend-receipts operation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function bulk_action_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-donadosu_donation' !== $screen->id ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading bulk action result counts for notice display.
		if ( isset( $_GET['donadosu_bulk_sent'] ) ) {
			$sent   = absint( $_GET['donadosu_bulk_sent'] );
			$failed = absint( $_GET['donadosu_bulk_failed'] ?? 0 );
			$msg    = sprintf(
				/* translators: %d: number of receipts resent */
				_n(
					'%d receipt resent successfully.',
					'%d receipts resent successfully.',
					$sent,
					'donateocean-donation-suite'
				),
				$sent
			);

			if ( $failed > 0 ) {
				$msg .= ' ' . sprintf(
					/* translators: %d: number of failed receipts */
					_n(
						'%d failed (no email address on record).',
						'%d failed (no email address on record).',
						$failed,
						'donateocean-donation-suite'
					),
					$failed
				);
			}

			$type = $failed > 0 && 0 === $sent ? 'error' : 'success';
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( $msg )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Apply date range and campaign filters to the main donations query.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Query $query WordPress query object.
	 * @return void
	 */
	public function apply_filters( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'donadosu_donation' !== $query->get( 'post_type' ) ) {
			return;
		}

		// WordPress "All" view only queries standard statuses (publish, draft, etc.)
		// by default. Since donations use custom statuses, we must explicitly include
		// them so the "All" tab shows every donation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WP_Query filter on admin list.
		if ( empty( $_GET['post_status'] ) ) {
			$query->set(
				'post_status',
				array(
					'donadosu_created',
					'donadosu_approved',
					'donadosu_captured',
					'donadosu_pending',
					'donadosu_completed',
					'donadosu_refunded',
					'donadosu_failed',
					'donadosu_disputed',
					'donadosu_sub_active',
					'donadosu_sub_paused',
					'donadosu_sub_cancelled',
					'donadosu_sub_failed',
				)
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Admin list table filter values.
		$from     = sanitize_text_field( (string) wp_unslash( $_GET['donadosu_from']    ?? '' ) );
		$to       = sanitize_text_field( (string) wp_unslash( $_GET['donadosu_to']      ?? '' ) );
		$campaign = sanitize_text_field( (string) wp_unslash( $_GET['donadosu_campaign'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $from || '' !== $to ) {
			// Filter on post_date_gmt so this list matches the Reports page and
			// the CSV export — WP_Query's default of post_date (local time)
			// would put boundary donations in a different bucket on non-UTC sites.
			$date_query = array(
				'inclusive' => true,
				'column'    => 'post_date_gmt',
			);
			if ( '' !== $from ) {
				$date_query['after'] = $from . ' 00:00:00';
			}
			if ( '' !== $to ) {
				$date_query['before'] = $to . ' 23:59:59';
			}
			$query->set( 'date_query', array( $date_query ) );
		}

		if ( '' !== $campaign ) {
			// WP_Meta_Query auto-wraps LIKE values with % and calls esc_like(),
			// so pass the raw campaign value — double-wrapping produced
			// '%\%campaign\%%' and matched nothing.
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => DonationMeta::CAMPAIGN,
						'value'   => $campaign,
						'compare' => 'LIKE',
					),
				)
			);
		}
	}
}
