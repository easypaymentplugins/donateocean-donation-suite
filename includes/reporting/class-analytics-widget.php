<?php
/**
 * Dashboard analytics widget.
 *
 * Displays key donation analytics at a glance including all-time totals,
 * this-month totals, an SVG bar chart, and top campaigns. Stats are cached
 * in a transient that refreshes every 15 minutes.
 *
 * @package    Donation_Suite
 * @subpackage Reporting
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Reporting;

use DonationSuite\Donation\CptDonationRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AnalyticsWidget
 *
 * Registers and renders a WordPress dashboard widget showing donation
 * statistics, a 12-month trend chart, and top campaigns.
 *
 * @since 1.0.0
 */
class AnalyticsWidget {

	/**
	 * Transient key for cached statistics.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const CACHE_KEY = 'donadosu_analytics_stats';

	/**
	 * Transient key for cached chart data.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const CACHE_KEY_CHART = 'donadosu_analytics_chart';

	/**
	 * Cache time-to-live in seconds (15 minutes).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Register the dashboard widget hook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * Add the analytics widget to the WordPress dashboard.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_widget(): void {
		if ( ! \DonationSuite\Core\Capabilities::can_view() ) {
			return;
		}

		wp_add_dashboard_widget(
			'donadosu_analytics',
			__( 'Donation Suite Analytics', 'donateocean-donation-suite' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Render the analytics widget content.
	 *
	 * Displays a grid with all-time stats, this-month stats, an SVG bar chart,
	 * top campaigns list, and footer links.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		$repository = new CptDonationRepository();

		$stats = get_transient( self::CACHE_KEY );
		if ( false === $stats ) {
			$stats = $repository->get_stats();
			set_transient( self::CACHE_KEY, $stats, self::CACHE_TTL );
		}

		$monthly = get_transient( self::CACHE_KEY_CHART );
		if ( false === $monthly ) {
			$monthly = $repository->get_monthly_totals( 12 );
			set_transient( self::CACHE_KEY_CHART, $monthly, self::CACHE_TTL );
		}

		$settings = get_option( 'donadosu_settings', array() );
		$currency = ( is_array( $settings ) && isset( $settings['currency'] ) && is_string( $settings['currency'] ) && '' !== $settings['currency'] )
			? $settings['currency']
			: 'USD';

		$total_count  = (int) ( $stats['total_count']  ?? 0 );
		$total_amount = (float) ( $stats['total_amount'] ?? 0.0 );
		$month_count  = (int) ( $stats['month_count']  ?? 0 );
		$month_amount = (float) ( $stats['month_amount'] ?? 0.0 );
		$campaigns    = (array) ( $stats['top_campaigns'] ?? array() );

		$list_url   = admin_url( 'edit.php?post_type=donadosu_donation' );
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=donadosu_export_csv' ), 'donadosu_export_csv' );
		?>
		<div class="donadosu-aw-grid">
			<div class="donadosu-aw-stat">
				<div class="donadosu-aw-stat__label"><?php esc_html_e( 'All-time donations', 'donateocean-donation-suite' ); ?></div>
				<div class="donadosu-aw-stat__value"><?php echo esc_html( number_format( $total_count ) ); ?></div>
				<div class="donadosu-aw-stat__sub"><?php echo esc_html( $currency . ' ' . number_format( $total_amount, 2 ) ); ?></div>
			</div>
			<div class="donadosu-aw-stat">
				<div class="donadosu-aw-stat__label"><?php esc_html_e( 'This month', 'donateocean-donation-suite' ); ?></div>
				<div class="donadosu-aw-stat__value"><?php echo esc_html( number_format( $month_count ) ); ?></div>
				<div class="donadosu-aw-stat__sub"><?php echo esc_html( $currency . ' ' . number_format( $month_amount, 2 ) ); ?></div>
			</div>
		</div>

		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is built with proper escaping in render_chart().
		echo $this->render_chart( $monthly, $currency );
		?>

		<?php if ( $campaigns ) : ?>
		<p style="margin:0 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;font-weight:600;"><?php esc_html_e( 'Top campaigns', 'donateocean-donation-suite' ); ?></p>
		<ul class="donadosu-aw-campaigns">
			<?php foreach ( $campaigns as $row ) : ?>
			<li>
				<span><?php echo esc_html( (string) ( $row['campaign'] ?? '' ) ); ?></span>
				<span>
					<?php echo esc_html( $currency . ' ' . number_format( (float) ( $row['total_amount'] ?? 0 ), 2 ) ); ?>
					<span style="color:#9ca3af;font-weight:400;">(<?php echo esc_html( (string) ( $row['donation_count'] ?? 0 ) ); ?>)</span>
				</span>
			</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>

		<div class="donadosu-aw-footer">
			<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View all donations', 'donateocean-donation-suite' ); ?></a>
			<a href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'donateocean-donation-suite' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Render a pure-SVG bar chart for the last 12 months of donation amounts.
	 *
	 * No external dependencies - works everywhere WordPress admin runs.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $monthly  Monthly donation data.
	 * @param string $currency Currency code for display.
	 * @return string SVG chart HTML.
	 */
	private function render_chart( array $monthly, string $currency ): string {
		if ( empty( $monthly ) ) {
			return '';
		}

		// Build a complete 12-month slot map so months with zero donations
		// still show as empty bars (keeps the x-axis uniform).
		$slots = array();
		for ( $i = 11; $i >= 0; $i-- ) {
			$ts          = strtotime( "-{$i} months", strtotime( gmdate( 'Y-m-01' ) ) );
			$key         = gmdate( 'Y-n', $ts );
			$slots[ $key ] = array(
				'year'   => (int) gmdate( 'Y', $ts ),
				'month'  => (int) gmdate( 'n', $ts ),
				'amount' => 0.0,
				'count'  => 0,
			);
		}

		foreach ( $monthly as $row ) {
			$key = $row['year'] . '-' . $row['month'];
			if ( isset( $slots[ $key ] ) ) {
				$slots[ $key ]['amount'] = $row['amount'];
				$slots[ $key ]['count']  = $row['count'];
			}
		}

		$slots      = array_values( $slots );
		$amounts    = array_column( $slots, 'amount' );
		$max_amount = $amounts ? max( $amounts ) : 0.0;
		$max_amount = max( $max_amount, 0.01 );

		// SVG dimensions.
		$svg_w    = 260;
		$svg_h    = 70;
		$bar_w    = 14;
		$gap      = (int) floor( ( $svg_w - count( $slots ) * $bar_w ) / ( count( $slots ) + 1 ) );
		$max_bar_h = $svg_h - 16;

		$bars       = '';
		$slot_count = count( $slots );

		foreach ( $slots as $i => $slot ) {
			$x     = $gap + $i * ( $bar_w + $gap );
			$bar_h = $slot['amount'] > 0 ? max( 2, (int) round( $slot['amount'] / $max_amount * $max_bar_h ) ) : 0;
			$bar_y = $svg_h - 14 - $bar_h;
			$label = gmdate( 'M', mktime( 0, 0, 0, $slot['month'], 1 ) );
			$tip   = esc_attr( $currency . ' ' . number_format( $slot['amount'], 2 ) . ' (' . $slot['count'] . ')' );

			// Highlight the current month.
			$bar_colour = ( $i === $slot_count - 1 ) ? '#111827' : '#93c5fd';
			$bars      .= sprintf(
				'<rect x="%d" y="%d" width="%d" height="%d" rx="2" fill="%s"><title>%s %s</title></rect>'
				. '<text x="%d" y="%d" font-size="6" fill="#9ca3af" text-anchor="middle">%s</text>',
				$x,
				$bar_y,
				$bar_w,
				$bar_h,
				esc_attr( $bar_colour ),
				esc_attr( $label ),
				$tip,
				$x + (int) floor( $bar_w / 2 ),
				$svg_h - 2,
				esc_html( $label )
			);
		}

		return sprintf(
			'<div class="donadosu-aw-chart"><p class="donadosu-aw-chart__title">%s</p>'
			. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" height="%d" aria-hidden="true">%s</svg></div>',
			esc_html__( '12-month donation trend', 'donateocean-donation-suite' ),
			$svg_w,
			$svg_h,
			$svg_h,
			$bars
		);
	}

	/**
	 * Purge the cached stats after each completed donation.
	 *
	 * Ensures the dashboard widget reflects new totals without waiting
	 * for the TTL to expire.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function bust_cache(): void {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::CACHE_KEY_CHART );
	}
}
