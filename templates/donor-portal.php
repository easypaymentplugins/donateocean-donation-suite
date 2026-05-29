<?php
/**
 * Milestone 2: Donor self-service portal template.
 *
 * Variables set by DonorPortalShortcode::render_portal():
 *   $currentUrl    string   URL of the portal page.
 *   $token         string   Raw token from query string (empty if none).
 *   $tokenData     array|null  Validated token data: ['email', 'return_url'] or null.
 *   $sent          bool     True after a magic-link email was dispatched.
 *   $msgKey        string   Success message key from query string.
 *   $errorKey      string   Error key from query string.
 *   $subscriptions array    List of active/paused subscription data arrays.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables set by including controller.

// ── Message and error texts ───────────────────────────────────────────────────
$successMessages = [
    'cancelled' => __('Your subscription has been cancelled successfully.', 'donateocean-donation-suite'),
];
$errorMessages = [
    'token_expired'    => __('Your access link has expired. Please request a new one below.', 'donateocean-donation-suite'),
    'not_cancellable'  => __('That subscription is not in a cancellable state.', 'donateocean-donation-suite'),
    'cancel_failed'    => __('Cancellation failed. Please contact us for assistance.', 'donateocean-donation-suite'),
    'forbidden'        => __('You do not have permission to manage that subscription.', 'donateocean-donation-suite'),
    'invalid'          => __('Invalid request. Please try again.', 'donateocean-donation-suite'),
    'no_sub_id'        => __('Subscription record not found. Please contact us.', 'donateocean-donation-suite'),
];

$successMsg = $msgKey   ? ($successMessages[$msgKey]  ?? '') : '';
$errorMsg   = $errorKey ? ($errorMessages[$errorKey]   ?? __('An error occurred. Please try again.', 'donateocean-donation-suite')) : '';
?>
<div class="donadosu-portal">
<?php if ($successMsg) : ?>
    <div class="donadosu-portal-notice donadosu-portal-notice--success"><?php echo esc_html($successMsg); ?></div>
<?php endif; ?>

<?php if ($errorMsg) : ?>
    <div class="donadosu-portal-notice donadosu-portal-notice--error"><?php echo esc_html($errorMsg); ?></div>
<?php endif; ?>

<?php if ($tokenData) :
    // ── Portal view (authenticated via token) ─────────────────────────────
?>
    <div class="donadosu-portal-card">
        <h2><?php esc_html_e('Your Recurring Donations', 'donateocean-donation-suite'); ?></h2>
        <p class="sub"><?php /* translators: %s: donor email address wrapped in <strong> tag */ printf(wp_kses(__('Signed in as %s', 'donateocean-donation-suite'), array('strong' => array())), '<strong>' . esc_html($tokenData['email']) . '</strong>'); ?></p>

        <?php if (empty($subscriptions)) : ?>
            <p class="donadosu-no-subs">
                <?php esc_html_e('You have no active recurring donations to manage.', 'donateocean-donation-suite'); ?>
            </p>
        <?php else : ?>
            <?php foreach ($subscriptions as $sub) :
                $statusLabel = $sub['status'] === 'donadosu_sub_paused'
                    ? __('Paused', 'donateocean-donation-suite')
                    : __('Active', 'donateocean-donation-suite');
                $badgeClass = $sub['status'] === 'donadosu_sub_paused'
                    ? 'donadosu-sub-badge--paused'
                    : 'donadosu-sub-badge--active';
                $cycleLabelMap = array(
                    'annual'  => __('Annual', 'donateocean-donation-suite'),
                    'monthly' => __('Monthly', 'donateocean-donation-suite'),
                );
                $cycleLabel = isset( $cycleLabelMap[ $sub['cycle'] ] )
                    ? $cycleLabelMap[ $sub['cycle'] ]
                    : ucfirst( $sub['cycle'] ?: __('Recurring', 'donateocean-donation-suite') );
            ?>
            <div class="donadosu-sub-item">
                <div class="donadosu-sub-header">
                    <div>
                        <div class="donadosu-sub-amount">
                            <?php echo esc_html($sub['currency'] . ' ' . number_format((float) $sub['amount'], 2)); ?>
                        </div>
                        <div class="donadosu-sub-cycle"><?php echo esc_html($cycleLabel); ?></div>
                    </div>
                    <span class="donadosu-sub-badge <?php echo esc_attr($badgeClass); ?>">
                        <?php echo esc_html($statusLabel); ?>
                    </span>
                </div>
                <div class="donadosu-sub-meta">
                    <?php if ($sub['campaign']) : ?>
                        <?php esc_html_e('Campaign:', 'donateocean-donation-suite'); ?> <?php echo esc_html($sub['campaign']); ?><br/>
                    <?php endif; ?>
                    <?php if ($sub['next_billing']) : ?>
                        <?php esc_html_e('Next billing:', 'donateocean-donation-suite'); ?> <?php echo esc_html($sub['next_billing']); ?><br/>
                    <?php endif; ?>
                    <?php if ($sub['receipt_no']) : ?>
                        <?php esc_html_e('Receipt #:', 'donateocean-donation-suite'); ?> <?php echo esc_html($sub['receipt_no']); ?>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Cancel this recurring donation? This cannot be undone.', 'donateocean-donation-suite')); ?>');">
                    <input type="hidden" name="action"          value="donadosu_portal_cancel" />
                    <input type="hidden" name="donadosu_portal_token" value="<?php echo esc_attr($token); ?>" />
                    <input type="hidden" name="post_id"         value="<?php echo esc_attr((string) $sub['post_id']); ?>" />
                    <?php wp_nonce_field('donadosu_portal_cancel_' . $sub['post_id']); ?>
                    <button type="submit" class="donadosu-btn-cancel">
                        <?php esc_html_e('Cancel Subscription', 'donateocean-donation-suite'); ?>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <p class="donadosu-portal-footer-note">
        <?php esc_html_e('To change your donation amount or frequency, please contact us directly.', 'donateocean-donation-suite'); ?>
    </p>

<?php elseif ($sent) :
    // ── Sent confirmation ─────────────────────────────────────────────────
?>
    <div class="donadosu-portal-card" style="text-align:center;">
        <div style="font-size:36px;margin-bottom:12px;">✉️</div>
        <h2><?php esc_html_e('Check your inbox', 'donateocean-donation-suite'); ?></h2>
        <p class="sub" style="margin:0 0 18px;">
            <?php esc_html_e('If we found an account for that email address, we sent you a secure access link. It will expire in 30 minutes.', 'donateocean-donation-suite'); ?>
        </p>
        <a href="<?php echo esc_url($currentUrl); ?>" style="font-size:13px;color:#6366f1;">
            <?php esc_html_e('Try a different email', 'donateocean-donation-suite'); ?>
        </a>
    </div>

<?php else :
    // ── Email entry form ──────────────────────────────────────────────────
?>
    <div class="donadosu-portal-card">
        <h2><?php esc_html_e('Manage Your Donations', 'donateocean-donation-suite'); ?></h2>
        <p class="sub">
            <?php esc_html_e('Enter your email address and we\'ll send you a secure link to manage your recurring donations.', 'donateocean-donation-suite'); ?>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action"     value="donadosu_portal_request" />
            <input type="hidden" name="return_url" value="<?php echo esc_attr($currentUrl); ?>" />
            <?php wp_nonce_field('donadosu_portal_request'); ?>

            <label for="donadosu-portal-email" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">
                <?php esc_html_e('Email address', 'donateocean-donation-suite'); ?>
            </label>
            <input
                type="email"
                id="donadosu-portal-email"
                name="donor_email"
                placeholder="you@example.com"
                required
                autocomplete="email"
            />
            <button type="submit" class="donadosu-btn">
                <?php esc_html_e('Send Access Link', 'donateocean-donation-suite'); ?>
            </button>
        </form>
    </div>

<?php endif; ?>
</div>
