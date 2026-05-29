<?php
/**
 * Admin donation reports template.
 *
 * Variables set by ReportsPage::render():
 *   $range         (array)  - preset, from_local, to_local, from_gmt, to_gmt, label, days
 *   $summary       (array)  - count, amount, avg_amount, unique_donors, new_donors, refund_count, refund_amount
 *   $summary_prev  (array)  - same shape, for the previous equal-length window
 *   $daily         (array)  - list of {date, count, amount} (repository raw rows; chart uses its own fill)
 *   $frequency     (array)  - list of {value, count, amount}
 *   $gift_size     (array)  - list of {bucket, count, amount}
 *   $purpose       (array)  - list of {value, count, amount}
 *   $giving_levels (array)  - list of {value, count, amount}
 *   $top_campaigns (array)  - list of {campaign, count, amount, donor_count}
 *   $chart_svg     (string) - prebuilt SVG trend chart
 *   $currency      (string)
 *   $list_url      (string) - donations list URL
 *   $export_url    (string) - CSV export URL scoped to the current range
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables set by including controller.

$base_url = admin_url( 'edit.php?post_type=donadosu_donation&page=donadosu-reports' );

/**
 * Helper: compute a percent delta between current and previous values.
 *
 * @param float $current  Current value.
 * @param float $previous Previous value.
 * @return array{dir:string,label:string}
 */
$delta = static function ( float $current, float $previous ): array {
	if ( $previous <= 0 ) {
		if ( $current > 0 ) {
			return array( 'dir' => 'up', 'label' => '+' );
		}
		return array( 'dir' => 'flat', 'label' => '—' );
	}
	$pct = ( ( $current - $previous ) / $previous ) * 100;
	// Use the same rounding the label uses (1 decimal) so a "+0.1%" label
	// never gets paired with a "flat" indicator.
	$rounded = round( $pct, 1 );
	$dir     = 0.0 === $rounded ? 'flat' : ( $rounded > 0 ? 'up' : 'down' );
	$sign    = $rounded > 0 ? '+' : '';
	return array( 'dir' => $dir, 'label' => $sign . number_format( $rounded, 1 ) . '%' );
};

$d_count    = $delta( (float) $summary['count'], (float) $summary_prev['count'] );
$d_amount   = $delta( (float) $summary['amount'], (float) $summary_prev['amount'] );
$d_avg      = $delta( (float) $summary['avg_amount'], (float) $summary_prev['avg_amount'] );
$d_donors   = $delta( (float) $summary['unique_donors'], (float) $summary_prev['unique_donors'] );

/**
 * Helper: render a breakdown table body.
 *
 * @param array  $rows     List of {value, count, amount}.
 * @param string $currency Currency code.
 * @param array  $labels   Optional value => localized label map.
 * @return string HTML.
 */
$breakdown_rows = static function ( array $rows, string $currency, array $labels = array() ): string {
	if ( empty( $rows ) ) {
		return '<tr><td colspan="3" class="donadosu-rp-empty-row">' . esc_html__( 'No data in this period.', 'donateocean-donation-suite' ) . '</td></tr>';
	}

	$total = 0.0;
	foreach ( $rows as $row ) {
		$total += (float) $row['amount'];
	}
	$total = max( $total, 0.01 );

	$html = '';
	foreach ( $rows as $row ) {
		$raw_value = (string) $row['value'];
		$label     = $labels[ $raw_value ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $raw_value ) );
		$amount    = (float) $row['amount'];
		$count     = (int) $row['count'];
		$pct       = ( $amount / $total ) * 100;

		$html .= '<tr>';
		$html .= '<td><span class="donadosu-rp-breakdown-label">' . esc_html( $label ) . '</span></td>';
		$html .= '<td class="donadosu-rp-num">' . esc_html( number_format( $count ) ) . '</td>';
		$html .= '<td class="donadosu-rp-num">'
			. '<div class="donadosu-rp-bar"><span class="donadosu-rp-bar__fill" style="width:' . esc_attr( number_format( $pct, 1 ) ) . '%"></span></div>'
			. '<span class="donadosu-rp-bar__amount">' . esc_html( $currency . ' ' . number_format( $amount, 2 ) ) . '</span>'
			. ' <span class="donadosu-rp-bar__pct">' . esc_html( number_format( $pct, 1 ) . '%' ) . '</span>'
			. '</td>';
		$html .= '</tr>';
	}
	return $html;
};

$frequency_labels = array(
	'one_time' => __( 'One-time', 'donateocean-donation-suite' ),
	'monthly'  => __( 'Monthly', 'donateocean-donation-suite' ),
	'annual'   => __( 'Annual', 'donateocean-donation-suite' ),
	'unknown'  => __( 'Unspecified', 'donateocean-donation-suite' ),
);

// Gift-size bands follow standard fundraising cohorts. Threshold amounts are
// rendered with the active reporting currency so the labels don't say "$"
// on a EUR / JPY / GBP site.
$gift_size_labels = array(
	/* translators: %s: currency code, e.g. USD */
	'a_under_25'  => sprintf( __( 'Under %s 25 (grassroots)', 'donateocean-donation-suite' ), $currency ),
	/* translators: %s: currency code */
	'b_25_99'     => sprintf( __( '%s 25 – 99 (small)', 'donateocean-donation-suite' ), $currency ),
	/* translators: %s: currency code */
	'c_100_499'   => sprintf( __( '%s 100 – 499 (mid)', 'donateocean-donation-suite' ), $currency ),
	/* translators: %s: currency code */
	'd_500_999'   => sprintf( __( '%s 500 – 999 (leadership)', 'donateocean-donation-suite' ), $currency ),
	/* translators: %s: currency code */
	'e_1000_plus' => sprintf( __( '%s 1,000+ (major)', 'donateocean-donation-suite' ), $currency ),
);
?>

<div class="wrap donadosu-rp-wrap">
	<h1><?php esc_html_e( 'Donation Reports', 'donateocean-donation-suite' ); ?></h1>
	<p style="color:#6b7280;margin-top:4px;">
		<?php esc_html_e( 'Key donation metrics, trends, and breakdowns over a chosen date range.', 'donateocean-donation-suite' ); ?>
	</p>

	<!-- Range filters -->
	<div class="donadosu-rp-filters">
		<div class="donadosu-rp-presets">
			<?php
			$presets = array(
				'today' => __( 'Today', 'donateocean-donation-suite' ),
				'7d'    => __( '7 days', 'donateocean-donation-suite' ),
				'30d'   => __( '30 days', 'donateocean-donation-suite' ),
				'90d'   => __( '90 days', 'donateocean-donation-suite' ),
				'ytd'   => __( 'YTD', 'donateocean-donation-suite' ),
				'all'   => __( 'All time', 'donateocean-donation-suite' ),
			);
			foreach ( $presets as $key => $label ) :
				$active = ( $range['preset'] === $key ) ? 'is-active' : '';
				$url    = add_query_arg( array( 'donadosu_range' => $key ), $base_url );
			?>
				<a class="<?php echo esc_attr( $active ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</div>
		<form class="donadosu-rp-custom" method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
			<input type="hidden" name="post_type" value="donadosu_donation" />
			<input type="hidden" name="page" value="donadosu-reports" />
			<label for="donadosu-rp-from"><?php esc_html_e( 'From', 'donateocean-donation-suite' ); ?></label>
			<input id="donadosu-rp-from" type="date" name="donadosu_from" value="<?php echo esc_attr( substr( $range['from_local'], 0, 10 ) ); ?>" />
			<label for="donadosu-rp-to"><?php esc_html_e( 'To', 'donateocean-donation-suite' ); ?></label>
			<input id="donadosu-rp-to" type="date" name="donadosu_to" value="<?php echo esc_attr( substr( $range['to_local'], 0, 10 ) ); ?>" />
			<button type="submit"><?php esc_html_e( 'Apply', 'donateocean-donation-suite' ); ?></button>
		</form>
	</div>

	<p class="donadosu-rp-range-meta">
		<?php
		printf(
			/* translators: 1: range label, 2: from date, 3: to date, 4: number of days */
			esc_html__( 'Showing %1$s: %2$s → %3$s (%4$s days)', 'donateocean-donation-suite' ),
			'<strong>' . esc_html( $range['label'] ) . '</strong>',
			esc_html( substr( $range['from_local'], 0, 10 ) ),
			esc_html( substr( $range['to_local'], 0, 10 ) ),
			esc_html( number_format( $range['days'] ) )
		);
		?>
	</p>

	<!-- KPI cards -->
	<div class="donadosu-rp-kpis">
		<div class="donadosu-rp-kpi">
			<div class="donadosu-rp-kpi__label"><?php esc_html_e( 'Total raised', 'donateocean-donation-suite' ); ?></div>
			<div class="donadosu-rp-kpi__value"><?php echo esc_html( $currency . ' ' . number_format( (float) $summary['amount'], 2 ) ); ?></div>
			<div class="donadosu-rp-kpi__sub">
				<span class="donadosu-rp-delta donadosu-rp-delta--<?php echo esc_attr( $d_amount['dir'] ); ?>"><?php echo esc_html( $d_amount['label'] ); ?></span>
				<?php esc_html_e( 'vs. previous period', 'donateocean-donation-suite' ); ?>
			</div>
		</div>
		<div class="donadosu-rp-kpi">
			<div class="donadosu-rp-kpi__label"><?php esc_html_e( 'Donations', 'donateocean-donation-suite' ); ?></div>
			<div class="donadosu-rp-kpi__value"><?php echo esc_html( number_format( (int) $summary['count'] ) ); ?></div>
			<div class="donadosu-rp-kpi__sub">
				<span class="donadosu-rp-delta donadosu-rp-delta--<?php echo esc_attr( $d_count['dir'] ); ?>"><?php echo esc_html( $d_count['label'] ); ?></span>
				<?php esc_html_e( 'vs. previous period', 'donateocean-donation-suite' ); ?>
			</div>
		</div>
		<div class="donadosu-rp-kpi">
			<div class="donadosu-rp-kpi__label"><?php esc_html_e( 'Average donation', 'donateocean-donation-suite' ); ?></div>
			<div class="donadosu-rp-kpi__value"><?php echo esc_html( $currency . ' ' . number_format( (float) $summary['avg_amount'], 2 ) ); ?></div>
			<div class="donadosu-rp-kpi__sub">
				<span class="donadosu-rp-delta donadosu-rp-delta--<?php echo esc_attr( $d_avg['dir'] ); ?>"><?php echo esc_html( $d_avg['label'] ); ?></span>
				<?php esc_html_e( 'vs. previous period', 'donateocean-donation-suite' ); ?>
			</div>
		</div>
		<div class="donadosu-rp-kpi">
			<div class="donadosu-rp-kpi__label"><?php esc_html_e( 'Unique donors', 'donateocean-donation-suite' ); ?></div>
			<div class="donadosu-rp-kpi__value"><?php echo esc_html( number_format( (int) $summary['unique_donors'] ) ); ?></div>
			<div class="donadosu-rp-kpi__sub">
				<span class="donadosu-rp-delta donadosu-rp-delta--<?php echo esc_attr( $d_donors['dir'] ); ?>"><?php echo esc_html( $d_donors['label'] ); ?></span>
				<?php esc_html_e( 'vs. previous period', 'donateocean-donation-suite' ); ?>
			</div>
		</div>
		<div class="donadosu-rp-kpi">
			<div class="donadosu-rp-kpi__label"><?php esc_html_e( 'New donors', 'donateocean-donation-suite' ); ?></div>
			<div class="donadosu-rp-kpi__value"><?php echo esc_html( number_format( (int) $summary['new_donors'] ) ); ?></div>
			<div class="donadosu-rp-kpi__sub">
				<?php esc_html_e( 'First-time givers in period', 'donateocean-donation-suite' ); ?>
			</div>
		</div>
		<div class="donadosu-rp-kpi">
			<div class="donadosu-rp-kpi__label"><?php esc_html_e( 'Refunds', 'donateocean-donation-suite' ); ?></div>
			<div class="donadosu-rp-kpi__value"><?php echo esc_html( number_format( (int) $summary['refund_count'] ) ); ?></div>
			<div class="donadosu-rp-kpi__sub">
				<?php echo esc_html( $currency . ' ' . number_format( (float) $summary['refund_amount'], 2 ) ); ?>
			</div>
		</div>
	</div>

	<!-- Trend chart -->
	<div class="donadosu-rp-panel">
		<h2><?php esc_html_e( 'Revenue trend', 'donateocean-donation-suite' ); ?></h2>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built with proper escaping in ReportsPage::render_trend_chart().
		echo $chart_svg;
		?>
	</div>

	<!-- Breakdowns grid -->
	<div class="donadosu-rp-grid">
		<div class="donadosu-rp-panel">
			<h2><?php esc_html_e( 'By frequency', 'donateocean-donation-suite' ); ?></h2>
			<table class="donadosu-rp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Frequency', 'donateocean-donation-suite' ); ?></th>
						<th class="donadosu-rp-num"><?php esc_html_e( 'Count', 'donateocean-donation-suite' ); ?></th>
						<th class="donadosu-rp-num"><?php esc_html_e( 'Amount', 'donateocean-donation-suite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built with esc_*() in helper closure.
					echo $breakdown_rows( $frequency, $currency, $frequency_labels );
					?>
				</tbody>
			</table>
		</div>

		<div class="donadosu-rp-panel">
			<h2><?php esc_html_e( 'By gift size', 'donateocean-donation-suite' ); ?></h2>
			<table class="donadosu-rp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Band', 'donateocean-donation-suite' ); ?></th>
						<th class="donadosu-rp-num"><?php esc_html_e( 'Count', 'donateocean-donation-suite' ); ?></th>
						<th class="donadosu-rp-num"><?php esc_html_e( 'Amount', 'donateocean-donation-suite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					// Remap {bucket, ...} to the {value, ...} shape the helper expects.
					$gift_size_rows = array_map(
						static fn( array $row ): array => array(
							'value'  => (string) $row['bucket'],
							'count'  => (int) $row['count'],
							'amount' => (float) $row['amount'],
						),
						$gift_size
					);
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built with esc_*() in helper closure.
					echo $breakdown_rows( $gift_size_rows, $currency, $gift_size_labels );
					?>
				</tbody>
			</table>
		</div>

		<div class="donadosu-rp-panel">
			<h2><?php esc_html_e( 'By fund / purpose', 'donateocean-donation-suite' ); ?></h2>
			<table class="donadosu-rp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Purpose', 'donateocean-donation-suite' ); ?></th>
						<th class="donadosu-rp-num"><?php esc_html_e( 'Count', 'donateocean-donation-suite' ); ?></th>
						<th class="donadosu-rp-num"><?php esc_html_e( 'Amount', 'donateocean-donation-suite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built with esc_*() in helper closure.
					echo $breakdown_rows( $purpose, $currency );
					?>
				</tbody>
			</table>
		</div>

		<div class="donadosu-rp-panel">
			<h2><?php esc_html_e( 'By giving level', 'donateocean-donation-suite' ); ?></h2>
			<table class="donadosu-rp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Level', 'donateocean-donation-suite' ); ?></th>
						<th class="donadosu-rp-num"><?php esc_html_e( 'Count', 'donateocean-donation-suite' ); ?></th>
						<th class="donadosu-rp-num"><?php esc_html_e( 'Amount', 'donateocean-donation-suite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built with esc_*() in helper closure.
					echo $breakdown_rows( $giving_levels, $currency );
					?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Top campaigns -->
	<div class="donadosu-rp-panel">
		<h2><?php esc_html_e( 'Top campaigns', 'donateocean-donation-suite' ); ?></h2>
		<table class="donadosu-rp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Campaign', 'donateocean-donation-suite' ); ?></th>
					<th class="donadosu-rp-num"><?php esc_html_e( 'Donations', 'donateocean-donation-suite' ); ?></th>
					<th class="donadosu-rp-num"><?php esc_html_e( 'Donors', 'donateocean-donation-suite' ); ?></th>
					<th class="donadosu-rp-num"><?php esc_html_e( 'Raised', 'donateocean-donation-suite' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $top_campaigns ) ) : ?>
					<tr><td colspan="5" class="donadosu-rp-empty-row"><?php esc_html_e( 'No campaign donations in this period.', 'donateocean-donation-suite' ); ?></td></tr>
				<?php else : foreach ( $top_campaigns as $row ) :
					$slug         = (string) $row['campaign'];
					$donations_url = add_query_arg(
						array(
							'post_type'       => 'donadosu_donation',
							'donadosu_campaign' => rawurlencode( $slug ),
							'donadosu_from'    => substr( $range['from_local'], 0, 10 ),
							'donadosu_to'      => substr( $range['to_local'], 0, 10 ),
						),
						admin_url( 'edit.php' )
					);
				?>
					<tr>
						<td><strong><?php echo esc_html( $slug ); ?></strong></td>
						<td class="donadosu-rp-num"><?php echo esc_html( number_format( (int) $row['count'] ) ); ?></td>
						<td class="donadosu-rp-num"><?php echo esc_html( number_format( (int) $row['donor_count'] ) ); ?></td>
						<td class="donadosu-rp-num"><?php echo esc_html( $currency . ' ' . number_format( (float) $row['amount'], 2 ) ); ?></td>
						<td class="donadosu-rp-num"><a href="<?php echo esc_url( $donations_url ); ?>"><?php esc_html_e( 'View donations', 'donateocean-donation-suite' ); ?></a></td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<p class="donadosu-rp-footnote" style="margin:8px 0 0;color:#9ca3af;font-size:11px;">
			<?php esc_html_e( 'Only donations with a campaign assigned are listed here, so totals may be lower than the headline "Total raised" above.', 'donateocean-donation-suite' ); ?>
		</p>
	</div>

	<div class="donadosu-rp-footer">
		<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View all donations', 'donateocean-donation-suite' ); ?></a>
		<a href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV for this range', 'donateocean-donation-suite' ); ?></a>
	</div>
</div>
