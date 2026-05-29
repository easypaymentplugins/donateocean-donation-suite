<?php
/**
 * Admin single-donation detail view template.
 *
 * Variables set by DonationDetailPage::render():
 *   $postId, $post, $status, $statusLabel, $statusClass
 *   $receiptNo, $amount, $currency, $orderId, $captureId, $env
 *   $donorName, $donorEmail, $donorPhone, $donorCompany
 *   $donorAddress, $donorCity, $donorPostal, $donorMessage
 *   $campaign, $purpose, $frequency
 *   $receiptStatus, $receiptSentAt
 *   $lastEventId, $lastEventAt
 *   $history (array)
 *   $backUrl, $receiptUrl, $donorUrl
 *   $notice (HTML string)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables set by including controller.

// Ensure all expected variables have defaults to avoid PHP 8.x undefined variable warnings.
$postId         = $postId ?? 0;
$post           = $post ?? null;
$status         = $status ?? '';
$statusLabel    = $statusLabel ?? '';
$statusClass    = $statusClass ?? 'donadosu-badge--neutral';
$receiptNo      = $receiptNo ?? '';
$amount         = $amount ?? '0';
$currency       = $currency ?? '';
$orderId        = $orderId ?? '';
$captureId      = $captureId ?? '';
$env            = $env ?? '';
$donorName      = $donorName ?? '';
$donorEmail     = $donorEmail ?? '';
$donorPhone     = $donorPhone ?? '';
$donorCompany   = $donorCompany ?? '';
$donorAddress   = $donorAddress ?? '';
$donorCity      = $donorCity ?? '';
$donorPostal    = $donorPostal ?? '';
$donorMessage   = $donorMessage ?? '';
$campaign       = $campaign ?? '';
$purpose        = $purpose ?? '';
$frequency      = $frequency ?? '';
$receiptStatus  = $receiptStatus ?? '';
$receiptSentAt  = $receiptSentAt ?? '';
$lastEventId    = $lastEventId ?? '';
$lastEventAt    = $lastEventAt ?? '';
$history        = $history ?? array();
$backUrl        = $backUrl ?? '';
$receiptUrl     = $receiptUrl ?? '';
$pdfUrl         = $pdfUrl ?? '';
$donorUrl       = $donorUrl ?? '';
$notice         = $notice ?? '';
$isAnonymous    = $isAnonymous ?? false;
$isTribute      = $isTribute ?? false;
$tributeType    = $tributeType ?? '';
$tributeName    = $tributeName ?? '';
$tributeNotify  = $tributeNotify ?? '';
$feeCovered     = $feeCovered ?? false;
$feeAmount      = $feeAmount ?? '0';
$grossAmount    = $grossAmount ?? '0';
$givingLevel    = $givingLevel ?? '';
$subId          = $subId ?? '';
$subCycle       = $subCycle ?? '';
$subStatus      = $subStatus ?? '';
$subNextBilling = $subNextBilling ?? '';
$vaultId        = $vaultId ?? '';
// A donation counts as a "manageable subscription" if it has either a
// PayPal subscription ID (wallet flow) or a vaulted payment token
// (advanced-card flow driven by the renewal cron).
$hasSubscription = ( '' !== $subId ) || ( '' !== $vaultId );
$disputeId      = $disputeId ?? '';
$disputeStatus  = $disputeStatus ?? '';
$disputeReason  = $disputeReason ?? '';
$fraudFlag      = $fraudFlag ?? false;
$paymentSource  = $paymentSource ?? '';
$offlineRef     = $offlineRef ?? '';
?>
<div class="wrap donadosu-detail-wrap">
    <h1>
        <?php echo esc_html__('Donation', 'donateocean-donation-suite'); ?>
        <?php echo esc_html($receiptNo ?: '#' . $postId); ?>
        <span class="donadosu-badge <?php echo esc_attr($statusClass); ?>"><?php echo esc_html($statusLabel); ?></span>
    </h1>

    <?php echo wp_kses_post( $notice ); ?>

    <div class="donadosu-actions">
        <a href="<?php echo esc_url($backUrl); ?>" class="button"><?php esc_html_e('← Back to donations', 'donateocean-donation-suite'); ?></a>
        <a href="<?php echo esc_url($pdfUrl); ?>" class="button" target="_blank"><?php esc_html_e('Download PDF', 'donateocean-donation-suite'); ?></a>
        <?php if ($donorUrl) : ?>
            <a href="<?php echo esc_url($donorUrl); ?>" class="button"><?php esc_html_e('View donor history', 'donateocean-donation-suite'); ?></a>
        <?php endif; ?>
        <?php if (\DonationSuite\Core\Capabilities::can_manage()) : ?>
        <!-- Feature 6: Resend receipt -->
        <form method="post" style="display:inline;">
            <input type="hidden" name="donadosu_post_id" value="<?php echo esc_attr((string) $postId); ?>" />
            <?php wp_nonce_field('donadosu_resend_receipt_' . $postId); ?>
            <button type="submit" name="donadosu_resend_receipt" value="1" class="button button-secondary"><?php esc_html_e('Resend Receipt', 'donateocean-donation-suite'); ?></button>
        </form>
        <?php endif; ?>
        <?php if ($status === 'donadosu_completed' && $captureId && current_user_can('manage_options')) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-flex;align-items:center;gap:6px;" onsubmit="return confirm('<?php echo esc_js(__('Issue a PayPal refund for this donation?', 'donateocean-donation-suite')); ?>');">
                <input type="hidden" name="action" value="donadosu_refund" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $postId); ?>" />
                <?php wp_nonce_field('donadosu_refund_' . $postId); ?>
                <input
                    type="number"
                    name="refund_amount"
                    min="0.01"
                    max="<?php echo esc_attr($feeCovered ? $grossAmount : $amount); ?>"
                    step="0.01"
                    placeholder="<?php esc_attr_e('Full refund', 'donateocean-donation-suite'); ?>"
                    style="width:110px;height:30px;padding:0 6px;border:1px solid #fca5a5;border-radius:4px;"
                    aria-label="<?php esc_attr_e('Refund amount (leave blank for full refund)', 'donateocean-donation-suite'); ?>"
                />
                <button type="submit" class="button button-secondary" style="color:#b91c1c;border-color:#fca5a5;"><?php esc_html_e('Issue Refund', 'donateocean-donation-suite'); ?></button>
            </form>
        <?php endif; ?>
        <?php if ($status === 'donadosu_sub_active' && $hasSubscription && \DonationSuite\Core\Capabilities::can_manage()) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <input type="hidden" name="action"  value="donadosu_pause_subscription" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $postId); ?>" />
                <?php wp_nonce_field('donadosu_pause_subscription_' . $postId); ?>
                <button type="submit" class="button button-secondary"><?php echo '⏸ ' . esc_html__('Pause Subscription', 'donateocean-donation-suite'); ?></button>
            </form>
        <?php endif; ?>
        <?php if ($status === 'donadosu_sub_paused' && $hasSubscription && \DonationSuite\Core\Capabilities::can_manage()) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <input type="hidden" name="action"  value="donadosu_resume_subscription" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $postId); ?>" />
                <?php wp_nonce_field('donadosu_resume_subscription_' . $postId); ?>
                <button type="submit" class="button button-secondary" style="color:#15803d;border-color:#bbf7d0;"><?php echo '▶ ' . esc_html__('Resume Subscription', 'donateocean-donation-suite'); ?></button>
            </form>
        <?php endif; ?>
        <?php if (in_array($status, ['donadosu_sub_active', 'donadosu_sub_paused'], true) && $hasSubscription && \DonationSuite\Core\Capabilities::can_manage()) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Cancel this PayPal subscription? This cannot be undone.', 'donateocean-donation-suite')); ?>');">
                <input type="hidden" name="action" value="donadosu_cancel_subscription" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $postId); ?>" />
                <?php wp_nonce_field('donadosu_cancel_subscription_' . $postId); ?>
                <button type="submit" class="button button-secondary" style="color:#b91c1c;border-color:#fca5a5;"><?php esc_html_e('Cancel Subscription', 'donateocean-donation-suite'); ?></button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Payment summary -->
    <div class="donadosu-card">
        <h2><?php esc_html_e('Payment', 'donateocean-donation-suite'); ?></h2>
        <?php if ($fraudFlag) : ?>
        <p style="color:#b91c1c;font-weight:600;"><?php echo '⚑ ' . esc_html__('High-value donation — flagged for review', 'donateocean-donation-suite'); ?></p>
        <?php endif; ?>
        <div class="donadosu-grid-2">
            <div class="donadosu-field">
                <label><?php esc_html_e('Amount (donation)', 'donateocean-donation-suite'); ?></label>
                <p><?php echo esc_html($currency . ' ' . number_format((float) $amount, 2)); ?></p>
            </div>
            <?php if ($feeCovered) : ?>
            <div class="donadosu-field">
                <label><?php esc_html_e('Fee covered by donor', 'donateocean-donation-suite'); ?></label>
                <p><?php echo esc_html($currency . ' ' . number_format((float) $feeAmount, 2)); ?></p>
            </div>
            <div class="donadosu-field">
                <label><?php esc_html_e('Total charged', 'donateocean-donation-suite'); ?></label>
                <p><?php echo esc_html($currency . ' ' . number_format((float) $grossAmount, 2)); ?></p>
            </div>
            <?php endif; ?>
            <?php if ($givingLevel) : ?>
            <div class="donadosu-field">
                <label><?php esc_html_e('Giving level', 'donateocean-donation-suite'); ?></label>
                <p><?php echo esc_html($givingLevel); ?></p>
            </div>
            <?php endif; ?>
            <?php if (! empty($paymentSource) && $paymentSource !== 'paypal') :
                $sourceLabels = ['cash'=>__('Cash', 'donateocean-donation-suite'),'cheque'=>__('Cheque', 'donateocean-donation-suite'),'bank_transfer'=>__('Bank Transfer', 'donateocean-donation-suite'),'other'=>__('Other', 'donateocean-donation-suite')];
            ?>
            <div class="donadosu-field">
                <label><?php esc_html_e('Payment Method', 'donateocean-donation-suite'); ?></label>
                <p><?php echo esc_html($sourceLabels[$paymentSource] ?? ucfirst($paymentSource)); ?> <span class="donadosu-badge donadosu-badge--neutral" style="font-size:10px;"><?php esc_html_e('Offline', 'donateocean-donation-suite'); ?></span></p>
            </div>
            <?php if ($offlineRef) : ?>
            <div class="donadosu-field">
                <label><?php esc_html_e('Reference / Cheque #', 'donateocean-donation-suite'); ?></label>
                <p><?php echo esc_html($offlineRef); ?></p>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <div class="donadosu-field">
                <label><?php esc_html_e('Environment', 'donateocean-donation-suite'); ?></label>
                <p><?php echo esc_html(ucfirst($env ?: 'unknown')); ?></p>
            </div>
            <div class="donadosu-field">
                <label><?php esc_html_e('PayPal Order ID', 'donateocean-donation-suite'); ?></label>
                <p><?php echo $orderId ? esc_html($orderId) : '<em>—</em>'; ?></p>
            </div>
            <div class="donadosu-field">
                <label><?php esc_html_e('PayPal Capture ID', 'donateocean-donation-suite'); ?></label>
                <p><?php echo $captureId ? esc_html($captureId) : '<em>—</em>'; ?></p>
            </div>
            <div class="donadosu-field">
                <label><?php esc_html_e('Campaign / Fund', 'donateocean-donation-suite'); ?></label>
                <p><?php echo $campaign ? esc_html($campaign) : '<em>—</em>'; ?></p>
            </div>
            <div class="donadosu-field">
                <label><?php esc_html_e('Purpose', 'donateocean-donation-suite'); ?></label>
                <p><?php echo $purpose ? esc_html($purpose) : '<em>—</em>'; ?></p>
            </div>
            <div class="donadosu-field">
                <label><?php esc_html_e('Frequency', 'donateocean-donation-suite'); ?></label>
                <p><?php echo esc_html(str_replace('_', ' ', (string) ($frequency ?: 'one_time'))); ?></p>
            </div>
            <div class="donadosu-field">
                <label><?php esc_html_e('Donation date', 'donateocean-donation-suite'); ?></label>
                <p><?php echo esc_html(( $post ? $post->post_date_gmt : '' ) . ' UTC'); ?></p>
            </div>
        </div>
    </div>

    <!-- Feature 1: Subscription details -->
    <?php if ($hasSubscription) : ?>
    <div class="donadosu-card">
        <h2><?php esc_html_e('Subscription', 'donateocean-donation-suite'); ?></h2>
        <div class="donadosu-grid-2">
            <?php if ($subId) : ?>
            <div class="donadosu-field"><label><?php esc_html_e('Subscription ID', 'donateocean-donation-suite'); ?></label><p><?php echo esc_html($subId); ?></p></div>
            <?php else : ?>
            <div class="donadosu-field"><label><?php esc_html_e('Billing method', 'donateocean-donation-suite'); ?></label><p><?php esc_html_e('Vaulted card (merchant-initiated renewals)', 'donateocean-donation-suite'); ?></p></div>
            <div class="donadosu-field"><label><?php esc_html_e('Vault token ID', 'donateocean-donation-suite'); ?></label><p><?php echo esc_html($vaultId); ?></p></div>
            <?php endif; ?>
            <div class="donadosu-field"><label><?php esc_html_e('Cycle', 'donateocean-donation-suite'); ?></label><p><?php echo esc_html(ucfirst($subCycle ?: '—')); ?></p></div>
            <div class="donadosu-field"><label><?php esc_html_e('Status', 'donateocean-donation-suite'); ?></label><p><?php echo esc_html(ucfirst($subStatus ?: '—')); ?></p></div>
            <?php if ($subNextBilling) : ?>
            <div class="donadosu-field"><label><?php esc_html_e('Next billing', 'donateocean-donation-suite'); ?></label><p><?php echo esc_html($subNextBilling); ?></p></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Feature 5: Dispute details -->
    <?php if ($disputeId || $disputeStatus) : ?>
    <div class="donadosu-card" style="border-color:#fca5a5;">
        <h2 style="color:#b91c1c;"><?php esc_html_e('Dispute', 'donateocean-donation-suite'); ?></h2>
        <div class="donadosu-grid-2">
            <div class="donadosu-field"><label><?php esc_html_e('Dispute ID', 'donateocean-donation-suite'); ?></label><p><?php echo esc_html($disputeId ?: '—'); ?></p></div>
            <div class="donadosu-field"><label><?php esc_html_e('Status', 'donateocean-donation-suite'); ?></label><p><?php echo esc_html($disputeStatus ?: '—'); ?></p></div>
            <div class="donadosu-field"><label><?php esc_html_e('Reason', 'donateocean-donation-suite'); ?></label><p><?php echo esc_html($disputeReason ?: '—'); ?></p></div>
            <div class="donadosu-field"><label><?php esc_html_e('PayPal Resolution Center', 'donateocean-donation-suite'); ?></label><p><a href="https://www.paypal.com/resolutioncenter" target="_blank" rel="noopener"><?php esc_html_e('Open PayPal', 'donateocean-donation-suite'); ?></a></p></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Receipt -->
    <div class="donadosu-card">
        <h2><?php esc_html_e('Receipt', 'donateocean-donation-suite'); ?></h2>
        <div class="donadosu-grid-2">
            <div class="donadosu-field">
                <label><?php esc_html_e('Receipt #', 'donateocean-donation-suite'); ?></label>
                <p><?php echo $receiptNo ? esc_html($receiptNo) : '<em>' . esc_html__('Not yet assigned', 'donateocean-donation-suite') . '</em>'; ?></p>
            </div>
            <div class="donadosu-field">
                <label><?php esc_html_e('Email status', 'donateocean-donation-suite'); ?></label>
                <p><?php echo esc_html($receiptStatus ?: __('not sent', 'donateocean-donation-suite')); ?></p>
            </div>
            <?php if ($receiptSentAt) : ?>
            <div class="donadosu-field">
                <label><?php esc_html_e('Sent at', 'donateocean-donation-suite'); ?></label>
                <p><?php echo esc_html($receiptSentAt); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Donor details -->
    <div class="donadosu-card">
        <h2><?php esc_html_e('Donor details', 'donateocean-donation-suite'); ?></h2>
        <?php if ($isAnonymous) : ?>
        <p><span class="donadosu-badge donadosu-badge--neutral"><?php esc_html_e('Anonymous donation', 'donateocean-donation-suite'); ?></span> — <?php esc_html_e('donor requested anonymity. Details visible to admins only.', 'donateocean-donation-suite'); ?></p>
        <?php endif; ?>
        <div class="donadosu-grid-2">
            <div class="donadosu-field">
                <label><?php esc_html_e('Full name', 'donateocean-donation-suite'); ?></label>
                <p><?php echo $donorName ? esc_html($donorName) : '<em>' . esc_html__('Not provided', 'donateocean-donation-suite') . '</em>'; ?></p>
            </div>
            <div class="donadosu-field">
                <label><?php esc_html_e('Email', 'donateocean-donation-suite'); ?></label>
                <p>
                    <?php if ($donorEmail) : ?>
                        <a href="<?php echo esc_url($donorUrl); ?>"><?php echo esc_html($donorEmail); ?></a>
                    <?php else : ?>
                        <em>—</em>
                    <?php endif; ?>
                </p>
            </div>
            <div class="donadosu-field">
                <label><?php esc_html_e('Phone', 'donateocean-donation-suite'); ?></label>
                <p><?php echo $donorPhone ? esc_html($donorPhone) : '<em>—</em>'; ?></p>
            </div>
            <div class="donadosu-field">
                <label><?php esc_html_e('Company / Organization', 'donateocean-donation-suite'); ?></label>
                <p><?php echo $donorCompany ? esc_html($donorCompany) : '<em>—</em>'; ?></p>
            </div>
            <div class="donadosu-field" style="grid-column:1/-1;">
                <label><?php esc_html_e('Address', 'donateocean-donation-suite'); ?></label>
                <?php
                $formattedDonorAddr = \DonationSuite\Core\AddressFormatter::format_donor($donorAddress, $donorCity, $donorPostal);
                ?>
                <p><?php echo $formattedDonorAddr ? nl2br(esc_html($formattedDonorAddr)) : '<em>—</em>'; ?></p>
            </div>
            <?php if ($donorMessage) : ?>
            <div class="donadosu-field" style="grid-column:1/-1;">
                <label><?php esc_html_e('Message', 'donateocean-donation-suite'); ?></label>
                <p><?php echo esc_html($donorMessage); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Custom fields registered via donadosu_register_custom_fields hook -->
    <?php
    $customFields = get_post_meta( $postId, '_donadosu_custom_fields', true );
    if ( is_array( $customFields ) && ! empty( $customFields ) ) :
        // Load registered field metadata so we can show labels (and map
        // select/radio keys back to human-readable option labels) when
        // the registering plugin is still active.
        \DonationSuite\Core\CustomFieldsManager::init();
        $registeredCustomFields = \DonationSuite\Core\CustomFieldsManager::get_fields();
    ?>
    <div class="donadosu-card">
        <h2><?php esc_html_e('Additional information', 'donateocean-donation-suite'); ?></h2>
        <div class="donadosu-grid-2">
            <?php foreach ( $customFields as $cfId => $cfValue ) :
                $cfMeta    = $registeredCustomFields[ $cfId ] ?? null;
                $cfLabel   = $cfMeta['label'] ?? $cfId;
                $cfType    = $cfMeta['type'] ?? 'text';
                $cfOptions = (array) ( $cfMeta['options'] ?? array() );
                $cfDisplay = (string) $cfValue;

                if ( in_array( $cfType, array( 'select', 'radio' ), true ) && isset( $cfOptions[ $cfDisplay ] ) ) {
                    $cfDisplay = $cfOptions[ $cfDisplay ];
                } elseif ( 'checkbox' === $cfType ) {
                    $cfDisplay = '1' === (string) $cfValue ? __( 'Yes', 'donateocean-donation-suite' ) : __( 'No', 'donateocean-donation-suite' );
                }
            ?>
            <div class="donadosu-field"<?php echo 'textarea' === $cfType ? ' style="grid-column:1/-1;"' : ''; ?>>
                <label><?php echo esc_html( $cfLabel ); ?></label>
                <p><?php echo '' !== $cfDisplay ? esc_html( $cfDisplay ) : '<em>—</em>'; ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Feature 3: Tribute details -->
    <?php if ($isTribute) : ?>
    <div class="donadosu-card">
        <h2><?php esc_html_e('Tribute Donation', 'donateocean-donation-suite'); ?></h2>
        <div class="donadosu-grid-2">
            <div class="donadosu-field"><label><?php esc_html_e('Type', 'donateocean-donation-suite'); ?></label><p><?php echo esc_html($tributeType === 'in_memory' ? __('In Memory Of', 'donateocean-donation-suite') : __('In Honor Of', 'donateocean-donation-suite')); ?></p></div>
            <div class="donadosu-field"><label><?php esc_html_e('Honoree Name', 'donateocean-donation-suite'); ?></label><p><?php echo $tributeName ? esc_html($tributeName) : '<em>—</em>'; ?></p></div>
            <?php if ($tributeNotify) : ?>
            <div class="donadosu-field"><label><?php esc_html_e('Notification Sent To', 'donateocean-donation-suite'); ?></label><p><?php echo esc_html($tributeNotify); ?></p></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Webhook -->
    <div class="donadosu-card">
        <h2><?php esc_html_e('Webhook & events', 'donateocean-donation-suite'); ?></h2>
        <div class="donadosu-grid-2">
            <div class="donadosu-field">
                <label><?php esc_html_e('Last webhook event ID', 'donateocean-donation-suite'); ?></label>
                <p><?php echo $lastEventId ? esc_html($lastEventId) : '<em>—</em>'; ?></p>
            </div>
            <div class="donadosu-field">
                <label><?php esc_html_e('Last webhook received', 'donateocean-donation-suite'); ?></label>
                <p><?php echo $lastEventAt ? esc_html($lastEventAt) : '<em>—</em>'; ?></p>
            </div>
        </div>
    </div>

    <!-- Status history -->
    <?php if ($history) : ?>
    <div class="donadosu-card">
        <h2><?php esc_html_e('Status history', 'donateocean-donation-suite'); ?></h2>
        <ul class="donadosu-history">
            <?php foreach (array_reverse($history) as $entry) :
                $entryStatus = (string) ($entry['status'] ?? '');
                $dotClass = '';
                if ($entryStatus === 'donadosu_completed') $dotClass = 'donadosu-history__dot--success';
                elseif ($entryStatus === 'donadosu_failed' || $entryStatus === 'donadosu_refunded') $dotClass = 'donadosu-history__dot--error';
                elseif ($entryStatus === 'donadosu_approved' || $entryStatus === 'donadosu_captured') $dotClass = 'donadosu-history__dot--warn';
                $context = (array) ($entry['context'] ?? []);
                unset($context['from_status']);
            ?>
            <li>
                <span class="donadosu-history__dot <?php echo esc_attr($dotClass); ?>"></span>
                <span class="donadosu-history__time"><?php echo esc_html((string) ($entry['time'] ?? '')); ?></span>
                <span class="donadosu-history__status"><?php echo esc_html($entryStatus); ?></span>
                <?php if ($context) : ?>
                    <span style="color:#9ca3af;font-size:12px;"><?php echo esc_html(implode( ', ', array_map( static function ( $k, $v ) { return $k . '=' . $v; }, array_keys( $context ), array_values( $context ) ) )); ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

</div>
