<?php
/**
 * CSV export controller.
 *
 * Handles exporting donation data as a CSV file from the WordPress admin.
 * Supports date range and campaign filters with a 10,000 row hard cap.
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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ExportController
 *
 * Streams a CSV file of donation records to the browser. Primes the meta
 * cache for all queried posts to avoid N+1 database queries.
 *
 * @since 1.0.0
 */
class ExportController {

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
	 * Register the admin_post action for CSV export.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_donadosu_export_csv', array( $this, 'export' ) );
	}

	/**
	 * Generate and stream a CSV export of donation records.
	 *
	 * Checks capabilities, verifies the nonce, applies date/campaign filters,
	 * primes the meta cache, and streams the CSV output.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function export(): void {
		if ( ! Capabilities::can_export() ) {
			wp_die( esc_html__( 'Forbidden', 'donateocean-donation-suite' ) );
		}

		check_admin_referer( 'donadosu_export_csv' );

		$from     = sanitize_text_field( (string) wp_unslash( $_GET['donadosu_from']    ?? '' ) );
		$to       = sanitize_text_field( (string) wp_unslash( $_GET['donadosu_to']      ?? '' ) );
		$campaign = sanitize_text_field( (string) wp_unslash( $_GET['donadosu_campaign'] ?? '' ) );

		// Hard cap: export at most 10,000 rows per request to avoid OOM on large sites.
		// Include subscription-active/paused posts so the CSV matches the
		// totals on the Reports page (which treats the initial subscription
		// charge as completed revenue, same as get_campaign_total() and
		// CampaignStatsService::rebuild_stats()).
		$args = array(
			'post_type'     => 'donadosu_donation',
			'post_status'   => array( 'donadosu_completed', 'donadosu_sub_active', 'donadosu_sub_paused' ),
			'numberposts'   => 10000,
			'orderby'       => 'date',
			'order'         => 'DESC',
			'no_found_rows' => true,
		);

		if ( '' !== $from || '' !== $to ) {
			// Match the Reports page, which queries post_date_gmt. WP_Query's
			// date_query defaults to post_date (local time), which would put
			// donations near the day boundary into a different bucket on
			// non-UTC sites and make the CSV disagree with the on-screen totals.
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
			$args['date_query'] = array( $date_query );
		}

		if ( '' !== $campaign ) {
			// WP_Meta_Query auto-wraps LIKE values with % and calls esc_like(),
			// so pass the raw campaign value — double-wrapping produced
			// '%\%campaign\%%' and matched nothing.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for campaign-filtered export.
			$args['meta_query'] = array(
				array(
					'key'     => DonationMeta::CAMPAIGN,
					'value'   => $campaign,
					'compare' => 'LIKE',
				),
			);
		}

		$posts = get_posts( $args );

		// Prime the meta cache for all posts in one query so the per-row
		// get_post_meta() calls below hit the cache instead of the database.
		if ( $posts ) {
			update_meta_cache( 'post', array_column( $posts, 'ID' ) );
		}

		// Send headers before any output to prevent "headers already sent" errors.
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="donadosu-' . gmdate( 'Y-m-d' ) . '.csv"' );
			header( 'Cache-Control: no-store, no-cache' );
			header( 'Pragma: no-cache' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Could not open output stream for CSV export.', 'donateocean-donation-suite' ) );
		}

		// Write UTF-8 BOM so Excel recognises the encoding.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to php://output stream, not filesystem.
		fwrite( $out, "\xEF\xBB\xBF" );

		$this->fputcsv_safe(
			$out,
			array(
				'receipt_no',
				'date',
				'amount',
				'currency',
				'frequency',
				'fee_covered',
				'fee_amount',
				'gross_amount',
				'giving_level',
				'order_id',
				'capture_id',
				'donor_email',
				'donor_name',
				'donor_phone',
				'donor_company',
				'campaign',
				'purpose',
				'donor_message',
				'is_anonymous',
				'is_tribute',
				'tribute_type',
				'tribute_name',
				'payment_source',
				'offline_reference',
			)
		);

		foreach ( $posts as $post ) {
			$is_anon = (bool) get_post_meta( $post->ID, DonationMeta::IS_ANONYMOUS, true );

			$frequency_val    = (string) get_post_meta( $post->ID, DonationMeta::DONATION_FREQUENCY, true );
			$fee_amount_val   = (string) get_post_meta( $post->ID, DonationMeta::FEE_AMOUNT, true );
			$gross_amount_val = (string) get_post_meta( $post->ID, DonationMeta::GROSS_AMOUNT, true );
			$amount_val       = (string) get_post_meta( $post->ID, DonationMeta::AMOUNT, true );

			$this->fputcsv_safe(
				$out,
				array(
					(string) get_post_meta( $post->ID, DonationMeta::RECEIPT_NO, true ),
					$post->post_date_gmt,
					$amount_val,
					(string) get_post_meta( $post->ID, DonationMeta::CURRENCY, true ),
					$frequency_val ? $frequency_val : 'one_time',
					get_post_meta( $post->ID, DonationMeta::FEE_COVERED, true ) ? 'yes' : 'no',
					$fee_amount_val ? $fee_amount_val : '0',
					$gross_amount_val ? $gross_amount_val : $amount_val,
					(string) get_post_meta( $post->ID, DonationMeta::GIVING_LEVEL, true ),
					(string) get_post_meta( $post->ID, DonationMeta::ORDER_ID, true ),
					(string) get_post_meta( $post->ID, DonationMeta::CAPTURE_ID, true ),
					$is_anon ? '[anonymous]' : (string) get_post_meta( $post->ID, DonationMeta::DONOR_EMAIL, true ),
					$is_anon ? '[anonymous]' : (string) get_post_meta( $post->ID, DonationMeta::DONOR_NAME, true ),
					$is_anon ? '' : (string) get_post_meta( $post->ID, DonationMeta::DONOR_PHONE, true ),
					$is_anon ? '' : (string) get_post_meta( $post->ID, DonationMeta::DONOR_COMPANY, true ),
					(string) get_post_meta( $post->ID, DonationMeta::CAMPAIGN, true ),
					(string) get_post_meta( $post->ID, DonationMeta::PURPOSE, true ),
					$is_anon ? '' : (string) get_post_meta( $post->ID, DonationMeta::DONOR_MESSAGE, true ),
					$is_anon ? 'yes' : 'no',
					get_post_meta( $post->ID, DonationMeta::IS_TRIBUTE, true ) ? 'yes' : 'no',
					(string) get_post_meta( $post->ID, DonationMeta::TRIBUTE_TYPE, true ),
					(string) get_post_meta( $post->ID, DonationMeta::TRIBUTE_NAME, true ),
					(string) get_post_meta( $post->ID, DonationMeta::PAYMENT_SOURCE, true ),
					(string) get_post_meta( $post->ID, DonationMeta::OFFLINE_REFERENCE, true ),
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream, not filesystem.
		fclose( $out );
		exit;
	}

	/**
	 * Write a CSV row with each cell defused against spreadsheet formula injection.
	 *
	 * Donor-supplied fields (name, message, company, etc.) flow straight into
	 * the export. A donor who submits `=cmd|'/c calc'!A1` or `@SUM(...)` as
	 * their name would otherwise have that formula executed when an admin
	 * opens the CSV in Excel / LibreOffice / Google Sheets (CWE-1236).
	 *
	 * @since 1.0.0
	 *
	 * @param resource     $handle Open file pointer for php://output.
	 * @param array<mixed> $row    Row cells.
	 * @return void
	 */
	private function fputcsv_safe( $handle, array $row ): void {
		fputcsv(
			$handle,
			array_map( array( $this, 'sanitize_csv_cell' ), $row )
		);
	}

	/**
	 * Prefix any cell whose first character is interpretable as a formula
	 * trigger with a single quote. Excel and LibreOffice both treat
	 * `= + - @ \t \r` at the start of a cell as the start of a formula.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw cell value.
	 * @return string Defused cell value.
	 */
	private function sanitize_csv_cell( $value ): string {
		$str = (string) $value;
		if ( '' === $str ) {
			return $str;
		}
		$first = $str[0];
		if ( '=' === $first || '+' === $first || '-' === $first || '@' === $first || "\t" === $first || "\r" === $first ) {
			return "'" . $str;
		}
		return $str;
	}
}
