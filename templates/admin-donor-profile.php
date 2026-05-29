<?php
/**
 * Admin donor profile template.
 *
 * Variables set by DonorProfilePage::render():
 *   $email, $donorName, $postIds (int[])
 *   $totalCompleted, $totalAmount, $currency
 *   $firstDonationDate, $lastDonationDate
 *   $backUrl
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables set by including controller.

$statusLabels = [
    'donadosu_created'   => __('Created', 'donateocean-donation-suite'),
    'donadosu_approved'  => __('Approved', 'donateocean-donation-suite'),
    'donadosu_captured'  => __('Captured', 'donateocean-donation-suite'),
    'donadosu_completed' => __('Completed', 'donateocean-donation-suite'),
    'donadosu_refunded'  => __('Refunded', 'donateocean-donation-suite'),
    'donadosu_failed'    => __('Failed', 'donateocean-donation-suite'),
];
$statusColors = [
    'donadosu_created'   => '#6b7280',
    'donadosu_approved'  => '#2563eb',
    'donadosu_captured'  => '#2563eb',
    'donadosu_completed' => '#15803d',
    'donadosu_refunded'  => '#b45309',
    'donadosu_failed'    => '#b91c1c',
];
?>
<div class="wrap donadosu-donor-wrap">
    <h1>
        <?php
        /* translators: %s: donor full name or email address */
        printf(esc_html__('Donor: %s', 'donateocean-donation-suite'), esc_html($donorName ?: $email));
        ?>
    </h1>
    <p style="color:#6b7280;">
        <?php echo esc_html($email); ?>
        &nbsp;&middot;&nbsp;
        <?php
        /* translators: %d: total number of donations made by this donor */
        printf(esc_html(_n('%d donation total', '%d donations total', count($postIds), 'donateocean-donation-suite')), count($postIds));
        ?>
    </p>

    <a href="<?php echo esc_url($backUrl); ?>" class="button"><?php esc_html_e('← Back to donations', 'donateocean-donation-suite'); ?></a>

    <!-- Aggregate stats -->
    <div class="donadosu-stat-grid">
        <div class="donadosu-stat">
            <div class="donadosu-stat__label"><?php esc_html_e('Completed donations', 'donateocean-donation-suite'); ?></div>
            <div class="donadosu-stat__value"><?php echo esc_html((string) $totalCompleted); ?></div>
        </div>
        <div class="donadosu-stat">
            <div class="donadosu-stat__label"><?php esc_html_e('Total given', 'donateocean-donation-suite'); ?></div>
            <div class="donadosu-stat__value"><?php echo esc_html($totalCompleted > 0 ? $currency . ' ' . number_format($totalAmount, 2) : '—'); ?></div>
        </div>
        <div class="donadosu-stat">
            <div class="donadosu-stat__label"><?php esc_html_e('First donation', 'donateocean-donation-suite'); ?></div>
            <div class="donadosu-stat__value" style="font-size:14px;"><?php echo $firstDonationDate !== '' ? esc_html(substr((string) $firstDonationDate, 0, 10)) : '—'; ?></div>
        </div>
        <div class="donadosu-stat">
            <div class="donadosu-stat__label"><?php esc_html_e('Latest donation', 'donateocean-donation-suite'); ?></div>
            <div class="donadosu-stat__value" style="font-size:14px;"><?php echo $lastDonationDate !== '' ? esc_html(substr((string) $lastDonationDate, 0, 10)) : '—'; ?></div>
        </div>
    </div>

    <!-- Donation history table -->
    <?php if ($postIds) : ?>
    <table class="donadosu-donor-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Receipt #', 'donateocean-donation-suite'); ?></th>
                <th><?php esc_html_e('Date', 'donateocean-donation-suite'); ?></th>
                <th><?php esc_html_e('Amount', 'donateocean-donation-suite'); ?></th>
                <th><?php esc_html_e('Campaign', 'donateocean-donation-suite'); ?></th>
                <th><?php esc_html_e('Status', 'donateocean-donation-suite'); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($postIds as $postId) :
                $receiptNo = (string) get_post_meta($postId, \DonationSuite\Donation\DonationMeta::RECEIPT_NO, true);
                $amount    = (string) get_post_meta($postId, \DonationSuite\Donation\DonationMeta::AMOUNT, true);
                $currency_ = (string) get_post_meta($postId, \DonationSuite\Donation\DonationMeta::CURRENCY, true);
                $campaign_ = (string) get_post_meta($postId, \DonationSuite\Donation\DonationMeta::CAMPAIGN, true);
                $pStatus   = (string) get_post_status($postId);
                $statusLabel_ = $statusLabels[$pStatus] ?? $pStatus;
                $statusColor_ = $statusColors[$pStatus] ?? '#6b7280';
                $postDate_    = (string) get_post_field('post_date_gmt', $postId);
                $detailUrl_   = add_query_arg(['page' => 'donadosu-detail', 'id' => $postId], admin_url('admin.php'));
            ?>
            <tr>
                <td><?php echo esc_html($receiptNo ?: '—'); ?></td>
                <td><?php echo esc_html(substr((string) $postDate_, 0, 10)); ?></td>
                <td><?php echo esc_html($currency_ . ' ' . number_format((float) $amount, 2)); ?></td>
                <td><?php echo $campaign_ ? esc_html($campaign_) : '<em style="color:#9ca3af;">—</em>'; ?></td>
                <td>
                    <span class="donadosu-status" style="color:<?php echo esc_attr($statusColor_); ?>">
                        <?php echo esc_html($statusLabel_); ?>
                    </span>
                </td>
                <td><a href="<?php echo esc_url($detailUrl_); ?>"><?php esc_html_e('View', 'donateocean-donation-suite'); ?></a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
    <p><?php esc_html_e('No donations found for this donor.', 'donateocean-donation-suite'); ?></p>
    <?php endif; ?>
</div>
