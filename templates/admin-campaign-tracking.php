<?php
/**
 * Admin campaign fund tracking template.
 *
 * Variables set by CampaignTrackingPage::render():
 *   $campaigns (array[]) - Each row: campaign, total_amount, donation_count,
 *                          donor_count. Optional keys: first_donation,
 *                          last_donation (may be absent when stats come from
 *                          the optimized stats table).
 *   $goals     (array)   - Campaign slug => goal amount
 *   $currency  (string)  - Currency code
 *   $can_edit  (bool)    - Whether current user can edit goals
 *   $notice    (string)  - Success/error notice HTML
 *   $list_url  (string)  - URL to the donations list
 *   $export_url (string) - URL to CSV export
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables set by including controller.

// Compute grand totals.
$grand_total_raised  = 0.0;
$grand_total_donors  = 0;
$grand_total_count   = 0;
foreach ( $campaigns as $row ) {
	$grand_total_raised += $row['total_amount'];
	$grand_total_donors += $row['donor_count'];
	$grand_total_count  += $row['donation_count'];
}
?>
<div class="wrap donadosu-ct-wrap">
	<h1><?php esc_html_e( 'Campaign Fund Tracking', 'donateocean-donation-suite' ); ?></h1>
	<p style="color:#6b7280;margin-top:4px;">
		<?php esc_html_e( 'Track fundraising progress for each campaign across all shortcodes and donation forms.', 'donateocean-donation-suite' ); ?>
	</p>

	<?php
	echo wp_kses_post( $notice );
	?>

	<!-- Summary stats -->
	<div class="donadosu-ct-grid">
		<div class="donadosu-ct-stat">
			<div class="donadosu-ct-stat__label"><?php esc_html_e( 'Total campaigns', 'donateocean-donation-suite' ); ?></div>
			<div class="donadosu-ct-stat__value"><?php echo esc_html( (string) count( $campaigns ) ); ?></div>
		</div>
		<div class="donadosu-ct-stat">
			<div class="donadosu-ct-stat__label"><?php esc_html_e( 'Total raised', 'donateocean-donation-suite' ); ?></div>
			<div class="donadosu-ct-stat__value"><?php echo esc_html( $currency . ' ' . number_format( $grand_total_raised, 2 ) ); ?></div>
		</div>
		<div class="donadosu-ct-stat">
			<div class="donadosu-ct-stat__label"><?php esc_html_e( 'Total donations', 'donateocean-donation-suite' ); ?></div>
			<div class="donadosu-ct-stat__value"><?php echo esc_html( number_format( $grand_total_count ) ); ?></div>
		</div>
		<div class="donadosu-ct-stat">
			<div class="donadosu-ct-stat__label"><?php esc_html_e( 'Unique donors', 'donateocean-donation-suite' ); ?></div>
			<div class="donadosu-ct-stat__value"><?php echo esc_html( number_format( $grand_total_donors ) ); ?></div>
		</div>
	</div>

	<?php if ( empty( $campaigns ) ) : ?>
	<div class="donadosu-ct-empty">
		<div class="donadosu-ct-empty__icon">&#x1F4CA;</div>
		<p class="donadosu-ct-empty__text">
			<?php esc_html_e( 'No campaign donations yet. Campaigns are created automatically when you use the campaign attribute in your shortcode:', 'donateocean-donation-suite' ); ?>
		</p>
		<code style="display:inline-block;margin-top:8px;padding:8px 16px;background:#f3f4f6;border-radius:6px;font-size:13px;">[donadosu_donation campaign="your-campaign-name" goal_amount="5000" goal_current="auto"]</code>
	</div>
	<?php else : ?>

	<?php if ( $can_edit ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="donadosu_save_campaign_goals" />
		<?php wp_nonce_field( 'donadosu_save_campaign_goals' ); ?>
	<?php endif; ?>

	<table class="donadosu-ct-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Campaign', 'donateocean-donation-suite' ); ?></th>
				<th><?php esc_html_e( 'Raised', 'donateocean-donation-suite' ); ?></th>
				<th><?php esc_html_e( 'Goal', 'donateocean-donation-suite' ); ?></th>
				<th style="min-width:160px;"><?php esc_html_e( 'Progress', 'donateocean-donation-suite' ); ?></th>
				<th><?php esc_html_e( 'Donations', 'donateocean-donation-suite' ); ?></th>
				<th><?php esc_html_e( 'Donors', 'donateocean-donation-suite' ); ?></th>
				<th><?php esc_html_e( 'Date range', 'donateocean-donation-suite' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $campaigns as $row ) :
				$slug         = $row['campaign'];
				$raised       = $row['total_amount'];
				$goal         = isset( $goals[ $slug ] ) ? (float) $goals[ $slug ] : 0.0;
				$percentage   = $goal > 0 ? min( 100.0, ( $raised / $goal ) * 100 ) : 0.0;
				$first_raw    = (string) ( $row['first_donation'] ?? '' );
				$last_raw     = (string) ( $row['last_donation'] ?? '' );
				$first_date   = '' !== $first_raw ? substr( $first_raw, 0, 10 ) : '';
				$last_date    = '' !== $last_raw ? substr( $last_raw, 0, 10 ) : '';

				// Progress bar colour class.
				if ( $percentage >= 100 ) {
					$bar_class = 'donadosu-ct-progress__bar--complete';
				} elseif ( $percentage >= 60 ) {
					$bar_class = 'donadosu-ct-progress__bar--high';
				} elseif ( $percentage >= 30 ) {
					$bar_class = 'donadosu-ct-progress__bar--mid';
				} else {
					$bar_class = 'donadosu-ct-progress__bar--low';
				}

				$donations_url = add_query_arg(
					array(
						'post_type'        => 'donadosu_donation',
						'donadosu_campaign'  => rawurlencode( $slug ),
					),
					admin_url( 'edit.php' )
				);
			?>
			<tr>
				<td>
					<strong><?php echo esc_html( $slug ); ?></strong>
				</td>
				<td>
					<strong><?php echo esc_html( $currency . ' ' . number_format( $raised, 2 ) ); ?></strong>
				</td>
				<td>
					<?php if ( $can_edit ) : ?>
						<input
							type="number"
							name="donadosu_goals[<?php echo esc_attr( $slug ); ?>]"
							value="<?php echo $goal > 0 ? esc_attr( (string) $goal ) : ''; ?>"
							class="donadosu-ct-goal-input"
							placeholder="<?php esc_attr_e( 'No goal', 'donateocean-donation-suite' ); ?>"
							min="0"
							step="0.01"
						/>
					<?php elseif ( $goal > 0 ) : ?>
						<?php echo esc_html( $currency . ' ' . number_format( $goal, 2 ) ); ?>
					<?php else : ?>
						<span class="donadosu-ct-badge donadosu-ct-badge--none"><?php esc_html_e( 'No goal', 'donateocean-donation-suite' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $goal > 0 ) : ?>
					<div class="donadosu-ct-progress">
						<div class="donadosu-ct-progress__bar <?php echo esc_attr( $bar_class ); ?>" style="width:<?php echo esc_attr( number_format( $percentage, 1 ) ); ?>%"></div>
						<div class="donadosu-ct-progress__label"><?php echo esc_html( number_format( $percentage, 1 ) . '%' ); ?></div>
					</div>
					<?php else : ?>
					<span style="color:#9ca3af;font-size:12px;"><?php esc_html_e( 'Set a goal to track', 'donateocean-donation-suite' ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( number_format( $row['donation_count'] ) ); ?></td>
				<td><?php echo esc_html( number_format( $row['donor_count'] ) ); ?></td>
				<td style="font-size:12px;color:#6b7280;">
					<?php if ( $first_date ) : ?>
						<?php echo esc_html( $first_date ); ?>
						<?php if ( $first_date !== $last_date ) : ?>
							&ndash; <?php echo esc_html( $last_date ); ?>
						<?php endif; ?>
					<?php else : ?>
						&mdash;
					<?php endif; ?>
				</td>
				<td>
					<a href="<?php echo esc_url( $donations_url ); ?>" style="font-size:12px;white-space:nowrap;"><?php esc_html_e( 'View donations', 'donateocean-donation-suite' ); ?></a>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $can_edit ) : ?>
		<p style="margin-top:12px;">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save campaign goals', 'donateocean-donation-suite' ); ?></button>
		</p>
	</form>
	<?php endif; ?>

	<?php endif; ?>

	<div class="donadosu-ct-footer">
		<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View all donations', 'donateocean-donation-suite' ); ?></a>
		<a href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'donateocean-donation-suite' ); ?></a>
	</div>
</div>
