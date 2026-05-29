<?php
/**
 * Donation reports admin page.
 *
 * Renders a full analytics & reporting page with a date-range filter,
 * headline KPIs, a trend chart, breakdowns by frequency / payment source /
 * purpose / giving level, and a top-campaigns table. Reuses the existing
 * CSV export controller for downloadable reports.
 *
 * @package    Donation_Suite
 * @subpackage Reporting
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Reporting;

use DonationSuite\Core\Capabilities;
use DonationSuite\Donation\CptDonationRepository;
use DonationSuite\Donation\DonationMeta;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReportsPage
 *
 * Registers and renders the Donations → Reports admin page.
 *
 * @since 1.0.0
 */
class ReportsPage {

	/**
	 * Transient key prefix for cached report payloads. The full transient
	 * key also includes an "epoch" counter so busting the cache is O(1)
	 * regardless of how many date ranges have been cached.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const CACHE_PREFIX = 'donadosu_report_';

	/**
	 * Option that stores the cache epoch counter.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const EPOCH_OPTION = 'donadosu_report_cache_epoch';

	/**
	 * Cache time-to-live in seconds (10 minutes).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const CACHE_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Named preset ranges exposed as quick-filter buttons.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const PRESETS = array( 'today', '7d', '30d', '90d', 'ytd', 'all' );

	/**
	 * Maximum number of days to plot one-point-per-day. Beyond this, the
	 * trend chart aggregates by calendar month to keep the SVG readable.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const DAILY_MAX_DAYS = 120;

	/**
	 * Register admin_menu hook so the Reports submenu gets added.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	/**
	 * Add the Reports submenu under Donations.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=donadosu_donation',
			__( 'Reports', 'donateocean-donation-suite' ),
			__( 'Reports', 'donateocean-donation-suite' ),
			Capabilities::VIEW_DONATIONS,
			'donadosu-reports',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the reports page.
	 *
	 * Resolves the date range from query parameters (with a safe default),
	 * fetches cached aggregates, and includes the template.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Capabilities::can_view() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'donateocean-donation-suite' ) );
		}

		$range    = $this->resolve_range();
		$settings = get_option( 'donadosu_settings', array() );
		$currency = ( is_array( $settings ) && isset( $settings['currency'] ) && is_string( $settings['currency'] ) && '' !== $settings['currency'] )
			? $settings['currency']
			: 'USD';
		$data     = $this->get_cached_report( $range['from_gmt'], $range['to_gmt'], $currency );

		$list_url   = admin_url( 'edit.php?post_type=donadosu_donation' );
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'       => 'donadosu_export_csv',
					'donadosu_from' => substr( $range['from_local'], 0, 10 ),
					'donadosu_to'   => substr( $range['to_local'], 0, 10 ),
				),
				admin_url( 'admin-post.php' )
			),
			'donadosu_export_csv'
		);

		// Expose variables to the template.
		$summary       = $data['summary'];
		$summary_prev  = $data['summary_prev'];
		$daily         = $data['daily'];
		$frequency     = $data['frequency'];
		$gift_size     = $data['gift_size'];
		$purpose       = $data['purpose'];
		$giving_levels = $data['giving_level'];
		$top_campaigns = $data['top_campaigns'];
		$chart_svg     = $this->render_trend_chart( $daily, $range, $currency );

		include DONADOSU_PATH . 'templates/admin-reports.php';
	}

	/**
	 * Gather every aggregate shown on the page, with a transient cache.
	 *
	 * The cache key includes an "epoch" integer that is bumped whenever a
	 * donation completes, so bust_cache() is a single option-update regardless
	 * of how many date ranges have been queried. Stale transients expire
	 * naturally via their TTL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from     GMT 'Y-m-d H:i:s' start (inclusive).
	 * @param string $to       GMT 'Y-m-d H:i:s' end (inclusive).
	 * @param string $currency ISO-4217 currency code to scope aggregates to,
	 *                         so multi-currency sites don't sum unrelated
	 *                         amounts together.
	 * @return array<string,mixed> Assembled report data.
	 */
	private function get_cached_report( string $from, string $to, string $currency ): array {
		$epoch = (int) get_option( self::EPOCH_OPTION, 0 );
		$key   = self::CACHE_PREFIX . md5( $from . '|' . $to . '|' . $currency . '|' . $epoch );

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$repository = new CptDonationRepository();

		// Previous-period window of equal length for comparison.
		$from_ts   = (int) strtotime( $from );
		$to_ts     = (int) strtotime( $to );
		$span_secs = max( 0, $to_ts - $from_ts );
		$prev_to   = gmdate( 'Y-m-d H:i:s', max( 0, $from_ts - 1 ) );
		$prev_from = gmdate( 'Y-m-d H:i:s', max( 0, $from_ts - 1 - $span_secs ) );

		$data = array(
			'summary'       => $repository->get_report_summary( $from, $to, $currency ),
			'summary_prev'  => $repository->get_report_summary( $prev_from, $prev_to, $currency ),
			'daily'         => $repository->get_daily_totals( $from, $to, $currency ),
			'frequency'     => $repository->get_breakdown_by_meta( DonationMeta::DONATION_FREQUENCY, $from, $to, $currency ),
			'gift_size'     => $repository->get_gift_size_breakdown( $from, $to, $currency ),
			'purpose'       => $repository->get_breakdown_by_meta( DonationMeta::PURPOSE, $from, $to, $currency ),
			'giving_level'  => $repository->get_breakdown_by_meta( DonationMeta::GIVING_LEVEL, $from, $to, $currency ),
			'top_campaigns' => $repository->get_top_campaigns( $from, $to, 10, $currency ),
		);

		set_transient( $key, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Resolve the active date range from query params.
	 *
	 * Supports preset ranges via `donadosu_range` (today, 7d, 30d, 90d, ytd,
	 * all) or an explicit custom window via `donadosu_from` + `donadosu_to`.
	 * The custom pair is expressed in the site's local timezone (matches
	 * ExportController) but this method also returns the converted GMT
	 * bounds needed by the repository aggregates.
	 *
	 * @since 1.0.0
	 *
	 * @return array{preset:string,from_local:string,to_local:string,from_gmt:string,to_gmt:string,label:string,days:int} Resolved range.
	 */
	private function resolve_range(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameters.
		$preset = sanitize_key( (string) wp_unslash( $_GET['donadosu_range'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameters.
		$from_raw = sanitize_text_field( (string) wp_unslash( $_GET['donadosu_from'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameters.
		$to_raw = sanitize_text_field( (string) wp_unslash( $_GET['donadosu_to'] ?? '' ) );

		// If an explicit custom window was provided, honour it.
		if ( '' !== $from_raw && '' !== $to_raw && $this->looks_like_date( $from_raw ) && $this->looks_like_date( $to_raw ) ) {
			$from_local = substr( $from_raw, 0, 10 ) . ' 00:00:00';
			$to_local   = substr( $to_raw, 0, 10 ) . ' 23:59:59';
			return $this->build_range( 'custom', $from_local, $to_local, __( 'Custom range', 'donateocean-donation-suite' ) );
		}

		// Fall back to a preset.
		if ( ! in_array( $preset, self::PRESETS, true ) ) {
			$preset = '30d';
		}

		// "Now" in the site's configured timezone so midnight boundaries line
		// up with what the user sees on the calendar.
		$tz  = wp_timezone();
		$now = new \DateTimeImmutable( 'now', $tz );

		switch ( $preset ) {
			case 'today':
				$from_local = $now->format( 'Y-m-d 00:00:00' );
				$to_local   = $now->format( 'Y-m-d 23:59:59' );
				$label      = __( 'Today', 'donateocean-donation-suite' );
				break;
			case '7d':
				$from_local = $now->modify( '-6 days' )->format( 'Y-m-d 00:00:00' );
				$to_local   = $now->format( 'Y-m-d 23:59:59' );
				$label      = __( 'Last 7 days', 'donateocean-donation-suite' );
				break;
			case '90d':
				$from_local = $now->modify( '-89 days' )->format( 'Y-m-d 00:00:00' );
				$to_local   = $now->format( 'Y-m-d 23:59:59' );
				$label      = __( 'Last 90 days', 'donateocean-donation-suite' );
				break;
			case 'ytd':
				$from_local = $now->format( 'Y-01-01 00:00:00' );
				$to_local   = $now->format( 'Y-m-d 23:59:59' );
				$label      = __( 'Year to date', 'donateocean-donation-suite' );
				break;
			case 'all':
				$from_local = '1970-01-01 00:00:00';
				$to_local   = $now->format( 'Y-m-d 23:59:59' );
				$label      = __( 'All time', 'donateocean-donation-suite' );
				break;
			case '30d':
			default:
				$preset     = '30d';
				$from_local = $now->modify( '-29 days' )->format( 'Y-m-d 00:00:00' );
				$to_local   = $now->format( 'Y-m-d 23:59:59' );
				$label      = __( 'Last 30 days', 'donateocean-donation-suite' );
				break;
		}

		return $this->build_range( $preset, $from_local, $to_local, $label );
	}

	/**
	 * Construct the range-descriptor array consumed by render().
	 *
	 * @since 1.0.0
	 *
	 * @param string $preset     The preset identifier (or 'custom').
	 * @param string $from_local Start timestamp in site local time.
	 * @param string $to_local   End timestamp in site local time.
	 * @param string $label      Human-readable label for display.
	 * @return array{preset:string,from_local:string,to_local:string,from_gmt:string,to_gmt:string,label:string,days:int} Resolved range.
	 */
	private function build_range( string $preset, string $from_local, string $to_local, string $label ): array {
		$from_gmt = get_gmt_from_date( $from_local, 'Y-m-d H:i:s' );
		$to_gmt   = get_gmt_from_date( $to_local, 'Y-m-d H:i:s' );
		$days     = max( 1, (int) round( ( strtotime( $to_gmt ) - strtotime( $from_gmt ) ) / DAY_IN_SECONDS ) + 1 );

		return array(
			'preset'     => $preset,
			'from_local' => $from_local,
			'to_local'   => $to_local,
			'from_gmt'   => $from_gmt,
			'to_gmt'     => $to_gmt,
			'label'      => $label,
			'days'       => $days,
		);
	}

	/**
	 * Lightweight YYYY-MM-DD[ HH:MM:SS] validator for the from/to params.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Candidate date string.
	 * @return bool Whether the value parses as a date.
	 */
	private function looks_like_date( string $value ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}(\s\d{2}:\d{2}(:\d{2})?)?$/', $value );
	}

	/**
	 * Render the SVG trend chart (line + filled area).
	 *
	 * Automatically switches from daily to monthly aggregation when the
	 * window exceeds DAILY_MAX_DAYS days so the chart stays readable.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,array{date:string,count:int,amount:float}> $daily    Raw daily totals.
	 * @param array<string,mixed>                                   $range    Resolved range array.
	 * @param string                                                $currency Currency code.
	 * @return string SVG HTML.
	 */
	private function render_trend_chart( array $daily, array $range, string $currency ): string {
		$by_month = $range['days'] > self::DAILY_MAX_DAYS;
		$slots    = $by_month ? $this->build_monthly_slots( $daily, $range ) : $this->build_daily_slots( $daily, $range );

		if ( empty( $slots ) ) {
			return '<div class="donadosu-rp-chart donadosu-rp-chart--empty">'
				. esc_html__( 'No donations in this period.', 'donateocean-donation-suite' )
				. '</div>';
		}

		$amounts    = array_column( $slots, 'amount' );
		$max_amount = max( $amounts );
		$max_amount = max( $max_amount, 0.01 );

		$svg_w   = 900;
		$svg_h   = 220;
		$pad_l   = 50;
		$pad_r   = 16;
		$pad_t   = 16;
		$pad_b   = 28;
		$inner_w = $svg_w - $pad_l - $pad_r;
		$inner_h = $svg_h - $pad_t - $pad_b;
		$count   = count( $slots );
		$step    = $count > 1 ? $inner_w / ( $count - 1 ) : 0;

		// Build polyline + area path.
		$points = array();
		foreach ( $slots as $i => $slot ) {
			$x        = $pad_l + $i * $step;
			$y        = $pad_t + ( $inner_h - ( $slot['amount'] / $max_amount ) * $inner_h );
			$points[] = array( $x, $y, $slot );
		}

		$line_pts = implode( ' ', array_map( static fn( array $p ): string => round( $p[0], 2 ) . ',' . round( $p[1], 2 ), $points ) );

		$last_idx = count( $points ) - 1;
		$area_d   = 'M ' . round( $points[0][0], 2 ) . ',' . round( $pad_t + $inner_h, 2 );
		foreach ( $points as $p ) {
			$area_d .= ' L ' . round( $p[0], 2 ) . ',' . round( $p[1], 2 );
		}
		$area_d .= ' L ' . round( $points[ $last_idx ][0], 2 ) . ',' . round( $pad_t + $inner_h, 2 ) . ' Z';

		// Axis gridlines (4 horizontal ticks).
		$grid      = '';
		$tick_vals = array();
		for ( $i = 0; $i <= 4; $i++ ) {
			$ratio       = $i / 4;
			$y           = $pad_t + $inner_h - $ratio * $inner_h;
			$tick_amount = $max_amount * $ratio;
			$grid       .= sprintf(
				'<line x1="%d" y1="%.2f" x2="%d" y2="%.2f" stroke="#e5e7eb" stroke-width="1" stroke-dasharray="2 3" />',
				$pad_l,
				$y,
				$svg_w - $pad_r,
				$y
			);
			$grid       .= sprintf(
				'<text x="%d" y="%.2f" font-size="10" fill="#9ca3af" text-anchor="end" dominant-baseline="middle">%s</text>',
				$pad_l - 6,
				$y,
				esc_html( $this->format_axis_amount( $tick_amount ) )
			);
			$tick_vals[] = $tick_amount;
		}

		// X-axis labels — show ~8 evenly spaced labels.
		$label_count = min( 8, $count );
		$label_step  = $label_count > 1 ? ( $count - 1 ) / ( $label_count - 1 ) : 0;
		$x_labels    = '';
		for ( $i = 0; $i < $label_count; $i++ ) {
			$idx   = (int) round( $i * $label_step );
			$idx   = min( $idx, $count - 1 );
			$slot  = $slots[ $idx ];
			$x     = $pad_l + $idx * $step;
			$text  = $by_month
				? gmdate( 'M Y', strtotime( $slot['date'] . '-01' ) )
				: gmdate( 'M j', strtotime( $slot['date'] ) );
			$x_labels .= sprintf(
				'<text x="%.2f" y="%d" font-size="10" fill="#9ca3af" text-anchor="middle">%s</text>',
				$x,
				$svg_h - 8,
				esc_html( $text )
			);
		}

		// Data point circles with tooltips.
		$dots = '';
		foreach ( $points as $p ) {
			$slot = $p[2];
			$tip  = $currency . ' ' . number_format( $slot['amount'], 2 )
				. ' (' . $slot['count'] . ') — '
				. ( $by_month ? gmdate( 'M Y', strtotime( $slot['date'] . '-01' ) ) : $slot['date'] );
			$dots .= sprintf(
				'<circle cx="%.2f" cy="%.2f" r="3" fill="#2563eb"><title>%s</title></circle>',
				$p[0],
				$p[1],
				esc_html( $tip )
			);
		}

		return sprintf(
			'<div class="donadosu-rp-chart">'
			. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" height="%d" role="img" aria-label="%s">'
			. '<defs><linearGradient id="donadosu-rp-fill" x1="0" y1="0" x2="0" y2="1">'
			. '<stop offset="0%%" stop-color="#3b82f6" stop-opacity=".28" />'
			. '<stop offset="100%%" stop-color="#3b82f6" stop-opacity="0" />'
			. '</linearGradient></defs>'
			. '%s'
			. '<path d="%s" fill="url(#donadosu-rp-fill)" />'
			. '<polyline points="%s" fill="none" stroke="#2563eb" stroke-width="2" />'
			. '%s'
			. '%s'
			. '</svg>'
			. '</div>',
			$svg_w,
			$svg_h,
			$svg_h,
			esc_attr__( 'Donation trend chart', 'donateocean-donation-suite' ),
			$grid,
			esc_attr( $area_d ),
			esc_attr( $line_pts ),
			$dots,
			$x_labels
		);
	}

	/**
	 * Fill in every day in the window so the chart has uniform spacing.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,array{date:string,count:int,amount:float}> $daily Rows from repository.
	 * @param array<string,mixed>                                   $range Resolved range.
	 * @return list<array{date:string,count:int,amount:float}> Slot list.
	 */
	private function build_daily_slots( array $daily, array $range ): array {
		$indexed = array();
		foreach ( $daily as $row ) {
			$indexed[ $row['date'] ] = $row;
		}

		// Walk the *local* date range the user actually picked. Iterating GMT
		// dates on non-UTC sites would tack on an extra slot whenever the
		// timezone offset pushed the window across a day boundary (e.g.
		// PST "from 03-10 to 03-10" would render two columns).
		$slots = array();
		$from  = strtotime( substr( $range['from_local'], 0, 10 ) );
		$to    = strtotime( substr( $range['to_local'], 0, 10 ) );

		if ( false === $from || false === $to || $from > $to ) {
			return array();
		}

		for ( $ts = $from; $ts <= $to; $ts += DAY_IN_SECONDS ) {
			$key     = gmdate( 'Y-m-d', $ts );
			$slots[] = isset( $indexed[ $key ] )
				? $indexed[ $key ]
				: array(
					'date'   => $key,
					'count'  => 0,
					'amount' => 0.0,
				);
		}

		return $slots;
	}

	/**
	 * Aggregate daily rows into monthly slots for long windows.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,array{date:string,count:int,amount:float}> $daily Rows from repository.
	 * @param array<string,mixed>                                   $range Resolved range.
	 * @return list<array{date:string,count:int,amount:float}> Slot list keyed by YYYY-MM.
	 */
	private function build_monthly_slots( array $daily, array $range ): array {
		$by_month = array();
		foreach ( $daily as $row ) {
			$month_key = substr( $row['date'], 0, 7 );
			if ( ! isset( $by_month[ $month_key ] ) ) {
				$by_month[ $month_key ] = array(
					'date'   => $month_key,
					'count'  => 0,
					'amount' => 0.0,
				);
			}
			$by_month[ $month_key ]['count']  += $row['count'];
			$by_month[ $month_key ]['amount'] += $row['amount'];
		}

		// Fill every month in the window so the axis stays uniform.
		$first = strtotime( substr( $range['from_gmt'], 0, 7 ) . '-01' );
		$last  = strtotime( substr( $range['to_gmt'], 0, 7 ) . '-01' );

		if ( false === $first || false === $last || $first > $last ) {
			return array();
		}

		// For open-ended ranges (e.g. "All time" with from=1970) start
		// plotting from the earliest month that has donation data so the
		// chart isn't padded with years of empty columns.
		if ( ! empty( $daily ) ) {
			$first_data = strtotime( substr( $daily[0]['date'], 0, 7 ) . '-01' );
			if ( false !== $first_data && $first_data > $first ) {
				$first = $first_data;
			}
		}

		$slots  = array();
		$cursor = $first;
		while ( $cursor <= $last ) {
			$key     = gmdate( 'Y-m', $cursor );
			$slots[] = isset( $by_month[ $key ] )
				? $by_month[ $key ]
				: array(
					'date'   => $key,
					'count'  => 0,
					'amount' => 0.0,
				);
			$cursor  = strtotime( '+1 month', $cursor );
			if ( false === $cursor ) {
				break;
			}
		}

		return $slots;
	}

	/**
	 * Format an axis value compactly (1.2K, 3.4M, etc.).
	 *
	 * @since 1.0.0
	 *
	 * @param float $amount Raw amount.
	 * @return string Short display string.
	 */
	private function format_axis_amount( float $amount ): string {
		if ( $amount >= 1_000_000 ) {
			return number_format( $amount / 1_000_000, 1 ) . 'M';
		}
		if ( $amount >= 1_000 ) {
			return number_format( $amount / 1_000, 1 ) . 'K';
		}
		return number_format( $amount, 0 );
	}

	/**
	 * Invalidate every cached report payload in one O(1) option update.
	 *
	 * Called after each completed donation so the Reports page reflects
	 * fresh numbers without waiting for TTL expiry.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function bust_cache(): void {
		$epoch = (int) get_option( self::EPOCH_OPTION, 0 );
		update_option( self::EPOCH_OPTION, $epoch + 1, false );
	}
}
