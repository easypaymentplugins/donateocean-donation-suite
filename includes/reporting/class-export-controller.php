<?php
/**
 * CSV export controller.
 *
 * Handles exporting donation data as a CSV file from the WordPress admin.
 * Supports date range and campaign filters with paged streaming.
 *
 * @package    Donation_Suite
 * @subpackage Reporting
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Reporting;

use DonationSuite\Core\Capabilities;
use DonationSuite\Core\ConfigService;
use DonationSuite\Donation\CptDonationRepository;
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

		$this->stream_csv( $out, $from, $to, $campaign );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream, not filesystem.
		fclose( $out );
		exit;
	}

	/**
	 * Write the full CSV (header row + paged data rows) to an open stream.
	 *
	 * Shared by the interactive browser export and the scheduled email export
	 * so both produce identical output. Streams in paged batches and primes
	 * each page's meta cache to avoid N+1 queries and memory exhaustion on
	 * large sites — there is no row cap.
	 *
	 * @since 1.0.6
	 *
	 * @param resource $out      Open writable stream / file handle.
	 * @param string   $from     Start date 'Y-m-d' (inclusive) or ''.
	 * @param string   $to       End date 'Y-m-d' (inclusive) or ''.
	 * @param string   $campaign Optional campaign filter.
	 * @return int Number of data rows written.
	 */
	private function stream_csv( $out, string $from, string $to, string $campaign ): int {
		$this->fputcsv_safe( $out, self::csv_header() );

		$base_args = $this->build_query_args( $from, $to, $campaign );
		$rows      = 0;
		$page      = 1;

		do {
			$base_args['paged'] = $page;
			$query              = new \WP_Query( $base_args );
			$posts              = $query->posts;

			if ( $posts ) {
				update_meta_cache( 'post', array_column( $posts, 'ID' ) );
			}

			foreach ( $posts as $post ) {
				$this->fputcsv_safe( $out, $this->build_row( $post ) );
				++$rows;
			}

			wp_cache_flush();
			++$page;
		} while ( ! empty( $posts ) );

		return $rows;
	}

	/**
	 * Column headers for the donation CSV export.
	 *
	 * @since 1.0.6
	 *
	 * @return list<string> Header cells.
	 */
	private static function csv_header(): array {
		return array(
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
		);
	}

	/**
	 * Build the WP_Query args for an export query.
	 *
	 * @since 1.0.6
	 *
	 * @param string $from     Start date 'Y-m-d' (inclusive) or ''.
	 * @param string $to       End date 'Y-m-d' (inclusive) or ''.
	 * @param string $campaign Optional campaign filter.
	 * @return array<string, mixed> WP_Query arguments.
	 */
	private function build_query_args( string $from, string $to, string $campaign ): array {
		$base_args = array(
			'post_type'      => 'donadosu_donation',
			'post_status'    => array( 'donadosu_completed', 'donadosu_sub_active', 'donadosu_sub_paused' ),
			'posts_per_page' => 500,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'paged'          => 1,
		);

		if ( '' !== $from || '' !== $to ) {
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
			$base_args['date_query'] = array( $date_query );
		}

		if ( '' !== $campaign ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for campaign-filtered export.
			$base_args['meta_query'] = array(
				array(
					'key'     => DonationMeta::CAMPAIGN,
					'value'   => $campaign,
					'compare' => 'LIKE',
				),
			);
		}

		return $base_args;
	}

	/**
	 * Build a single CSV data row for a donation post.
	 *
	 * The post's meta cache should already be primed by the caller.
	 *
	 * @since 1.0.6
	 *
	 * @param \WP_Post $post Donation post.
	 * @return list<string> Row cells.
	 */
	private function build_row( \WP_Post $post ): array {
		$is_anon = (bool) get_post_meta( $post->ID, DonationMeta::IS_ANONYMOUS, true );

		$frequency_val    = (string) get_post_meta( $post->ID, DonationMeta::DONATION_FREQUENCY, true );
		$fee_amount_val   = (string) get_post_meta( $post->ID, DonationMeta::FEE_AMOUNT, true );
		$gross_amount_val = (string) get_post_meta( $post->ID, DonationMeta::GROSS_AMOUNT, true );
		$amount_val       = (string) get_post_meta( $post->ID, DonationMeta::AMOUNT, true );

		return array(
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
		);
	}

	/**
	 * Cron handler: build a donation CSV for the previous period and email it.
	 *
	 * Scheduled daily but only runs on the configured cadence (weekly → every
	 * Monday for the prior 7 days; monthly → the 1st for the prior calendar
	 * month). The CSV is written to a temporary file and attached to an email
	 * sent to the configured recipient, falling back to the charity contact
	 * and then the site admin. A no-op unless scheduled exports are enabled.
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public static function run_scheduled_export(): void {
		$settings = ( new ConfigService() )->get_all();

		if ( empty( $settings['scheduled_export_enabled'] ) ) {
			return;
		}

		$frequency = in_array( (string) ( $settings['scheduled_export_frequency'] ?? 'monthly' ), array( 'weekly', 'monthly' ), true )
			? (string) $settings['scheduled_export_frequency']
			: 'monthly';

		// Only proceed on the cadence's run day.
		if ( 'weekly' === $frequency && '1' !== gmdate( 'N' ) ) {
			return;
		}
		if ( 'monthly' === $frequency && '01' !== gmdate( 'd' ) ) {
			return;
		}

		// Compute the reporting window (the period that just ended).
		if ( 'weekly' === $frequency ) {
			$from = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
			$to   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		} else {
			$from = gmdate( 'Y-m-d', strtotime( 'first day of last month' ) );
			$to   = gmdate( 'Y-m-d', strtotime( 'last day of last month' ) );
		}

		// Guard against duplicate sends if wp-cron fires more than once a day.
		$window_key = $frequency . ':' . $from . '_' . $to;
		if ( (string) get_option( 'donadosu_last_scheduled_export', '' ) === $window_key ) {
			return;
		}

		$recipient = sanitize_email( (string) ( $settings['scheduled_export_email'] ?? '' ) );
		if ( '' === $recipient ) {
			$recipient = sanitize_email( (string) ( $settings['contact_email'] ?? '' ) );
		}
		if ( '' === $recipient ) {
			$recipient = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		if ( '' === $recipient || ! is_email( $recipient ) ) {
			return;
		}

		$tmp_dir  = get_temp_dir();
		$filename = wp_unique_filename( $tmp_dir, sanitize_file_name( 'donadosu-donations-' . $from . '-to-' . $to . '.csv' ) );
		$path     = trailingslashit( $tmp_dir ) . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Temporary CSV file for an email attachment.
		$out = fopen( $path, 'w' );
		if ( false === $out ) {
			return;
		}

		// Write UTF-8 BOM so Excel recognises the encoding.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to a temporary file.
		fwrite( $out, "\xEF\xBB\xBF" );

		$controller = new self( new CptDonationRepository() );
		$rows       = $controller->stream_csv( $out, $from, $to, '' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temporary file handle.
		fclose( $out );

		$org_name = (string) ( $settings['charity_name'] ?? '' );
		if ( '' === $org_name ) {
			$org_name = (string) get_bloginfo( 'name' );
		}

		$subject = sprintf(
			/* translators: 1: organization name, 2: start date, 3: end date */
			__( '[%1$s] Donation export: %2$s to %3$s', 'donateocean-donation-suite' ),
			$org_name,
			$from,
			$to
		);
		$body = sprintf(
			/* translators: 1: number of donations, 2: start date, 3: end date */
			__( 'Attached is your scheduled donation export containing %1$d donation(s) from %2$s to %3$s.', 'donateocean-donation-suite' ),
			$rows,
			$from,
			$to
		);

		wp_mail( $recipient, $subject, $body, array(), array( $path ) );

		update_option( 'donadosu_last_scheduled_export', $window_key, false );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the temporary attachment after sending.
		@unlink( $path );
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
