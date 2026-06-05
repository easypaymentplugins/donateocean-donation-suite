<?php
/**
 * Milestone 2: Manual / offline donation entry form template.
 *
 * Variables set by ManualDonationPage::render():
 *   $currency  string  Default currency from settings.
 *   $notice    string  Pre-escaped HTML notice (error).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables set by including controller.
?>
<?php
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in ManualDonationPage::render() before this template is included.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- All values are sanitized inline with sanitize_text_field/sanitize_email/esc_textarea.
?>
<div class="wrap donadosu-manual-wrap">
    <h1><?php esc_html_e('Add Manual Donation', 'donateocean-donation-suite'); ?></h1>
    <p style="color:#6b7280;margin-top:4px;"><?php esc_html_e('Record a cash, cheque, bank transfer, or other offline donation.', 'donateocean-donation-suite'); ?></p>

    <?php echo wp_kses_post( $notice ); ?>

    <form method="post" autocomplete="off">
        <?php wp_nonce_field('donadosu_manual_donation'); ?>

        <!-- Payment details -->
        <div class="donadosu-manual-card">
            <h2><?php esc_html_e('Payment Details', 'donateocean-donation-suite'); ?></h2>

            <div class="donadosu-form-row thirds">
                <div class="donadosu-field-group">
                    <label for="donadosu-amount">
                        <?php esc_html_e('Amount', 'donateocean-donation-suite'); ?>
                        <span class="donadosu-required">*</span>
                    </label>
                    <input
                        type="number"
                        id="donadosu-amount"
                        name="amount"
                        min="0.01"
                        step="0.01"
                        placeholder="0.00"
                        required
                        value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( (string) ( $_POST['amount'] ?? '' ) ) ) ); ?>"
                    />
                </div>
                <div class="donadosu-field-group">
                    <label for="donadosu-currency"><?php esc_html_e('Currency', 'donateocean-donation-suite'); ?></label>
                    <input
                        type="text"
                        id="donadosu-currency"
                        name="currency"
                        maxlength="3"
                        placeholder="USD"
                        value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( (string) ( $_POST['currency'] ?? $currency ) ) ) ); ?>"
                    />
                </div>
                <div class="donadosu-field-group">
                    <label for="donadosu-date"><?php esc_html_e('Donation Date', 'donateocean-donation-suite'); ?></label>
                    <input
                        type="date"
                        id="donadosu-date"
                        name="donation_date"
                        value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( (string) ( $_POST['donation_date'] ?? gmdate( 'Y-m-d' ) ) ) ) ); ?>"
                    />
                </div>
            </div>

            <div class="donadosu-form-row">
                <div class="donadosu-field-group">
                    <label for="donadosu-payment-source"><?php esc_html_e('Payment Method', 'donateocean-donation-suite'); ?></label>
                    <select id="donadosu-payment-source" name="payment_source">
                        <?php
                        $sources = [
                            'cash'          => __('Cash', 'donateocean-donation-suite'),
                            'cheque'        => __('Cheque', 'donateocean-donation-suite'),
                            'bank_transfer' => __('Bank Transfer', 'donateocean-donation-suite'),
                            'other'         => __('Other', 'donateocean-donation-suite'),
                        ];
                        $selected = sanitize_key((string) wp_unslash((string) ($_POST['payment_source'] ?? 'cash')));
                        foreach ($sources as $val => $label) :
                        ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($selected, $val); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="donadosu-field-group">
                    <label for="donadosu-reference"><?php esc_html_e('Reference / Cheque Number', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Optional. Appears on the receipt.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                    <input
                        type="text"
                        id="donadosu-reference"
                        name="offline_reference"
                        placeholder="e.g. CHQ-001234"
                        value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( (string) ( $_POST['offline_reference'] ?? '' ) ) ) ); ?>"
                    />
                </div>
            </div>

            <div class="donadosu-form-row">
                <div class="donadosu-field-group">
                    <label for="donadosu-campaign"><?php esc_html_e('Campaign / Fund', 'donateocean-donation-suite'); ?></label>
                    <input
                        type="text"
                        id="donadosu-campaign"
                        name="campaign"
                        placeholder="e.g. Summer Appeal"
                        value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( (string) ( $_POST['campaign'] ?? '' ) ) ) ); ?>"
                    />
                </div>
                <div class="donadosu-field-group">
                    <label for="donadosu-purpose"><?php esc_html_e('Purpose', 'donateocean-donation-suite'); ?></label>
                    <input
                        type="text"
                        id="donadosu-purpose"
                        name="purpose"
                        placeholder="e.g. General fund"
                        value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( (string) ( $_POST['purpose'] ?? '' ) ) ) ); ?>"
                    />
                </div>
            </div>
        </div>

        <!-- Donor details -->
        <div class="donadosu-manual-card">
            <h2><?php esc_html_e('Donor Details', 'donateocean-donation-suite'); ?></h2>

            <div class="donadosu-form-row">
                <div class="donadosu-field-group">
                    <label for="donadosu-donor-name"><?php esc_html_e('Full Name', 'donateocean-donation-suite'); ?></label>
                    <input
                        type="text"
                        id="donadosu-donor-name"
                        name="donor_name"
                        value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( (string) ( $_POST['donor_name'] ?? '' ) ) ) ); ?>"
                    />
                </div>
                <div class="donadosu-field-group">
                    <label for="donadosu-donor-email">
                        <?php esc_html_e('Email Address', 'donateocean-donation-suite'); ?>
                        <span class="donadosu-required">*</span>
                    </label>
                    <input
                        type="email"
                        id="donadosu-donor-email"
                        name="donor_email"
                        required
                        value="<?php echo esc_attr(sanitize_email((string) wp_unslash((string) ($_POST['donor_email'] ?? '')))); ?>"
                    />
                </div>
            </div>

            <div class="donadosu-form-row">
                <div class="donadosu-field-group">
                    <label for="donadosu-donor-phone"><?php esc_html_e('Phone', 'donateocean-donation-suite'); ?></label>
                    <input
                        type="text"
                        id="donadosu-donor-phone"
                        name="donor_phone"
                        value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( (string) ( $_POST['donor_phone'] ?? '' ) ) ) ); ?>"
                    />
                </div>
                <div class="donadosu-field-group">
                    <label for="donadosu-donor-company"><?php esc_html_e('Company / Organization', 'donateocean-donation-suite'); ?></label>
                    <input
                        type="text"
                        id="donadosu-donor-company"
                        name="donor_company"
                        value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( (string) ( $_POST['donor_company'] ?? '' ) ) ) ); ?>"
                    />
                </div>
            </div>

            <div class="donadosu-form-row thirds">
                <div class="donadosu-field-group" style="grid-column:1/3;">
                    <label for="donadosu-donor-address"><?php esc_html_e('Street Address', 'donateocean-donation-suite'); ?></label>
                    <input
                        type="text"
                        id="donadosu-donor-address"
                        name="donor_address"
                        value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( (string) ( $_POST['donor_address'] ?? '' ) ) ) ); ?>"
                    />
                </div>
                <div class="donadosu-field-group">
                    <label for="donadosu-donor-city"><?php esc_html_e('City', 'donateocean-donation-suite'); ?></label>
                    <input
                        type="text"
                        id="donadosu-donor-city"
                        name="donor_city"
                        value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( (string) ( $_POST['donor_city'] ?? '' ) ) ) ); ?>"
                    />
                </div>
                <div class="donadosu-field-group">
                    <label for="donadosu-donor-postal"><?php esc_html_e('Postal Code', 'donateocean-donation-suite'); ?></label>
                    <input
                        type="text"
                        id="donadosu-donor-postal"
                        name="donor_postal"
                        value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( (string) ( $_POST['donor_postal'] ?? '' ) ) ) ); ?>"
                    />
                </div>
            </div>

            <div class="donadosu-form-row full">
                <div class="donadosu-field-group">
                    <label for="donadosu-donor-message"><?php esc_html_e('Donor Message', 'donateocean-donation-suite'); ?></label>
                    <textarea id="donadosu-donor-message" name="donor_message"><?php echo esc_textarea((string) wp_unslash((string) ($_POST['donor_message'] ?? ''))); ?></textarea>
                </div>
            </div>

            <div class="donadosu-checkbox-row">
                <input
                    type="checkbox"
                    id="donadosu-anonymous"
                    name="is_anonymous"
                    value="1"
                    <?php checked(! empty($_POST['is_anonymous'])); ?>
                />
                <label for="donadosu-anonymous"><?php esc_html_e('Anonymous donation — hide donor name in public views', 'donateocean-donation-suite'); ?></label>
            </div>
        </div>

        <!-- Options -->
        <div class="donadosu-manual-card">
            <h2><?php esc_html_e('Options', 'donateocean-donation-suite'); ?></h2>
            <div class="donadosu-checkbox-row">
                <input
                    type="checkbox"
                    id="donadosu-send-receipt"
                    name="send_receipt"
                    value="1"
                    <?php checked(! isset($_POST['donadosu_manual_submit']) || ! empty($_POST['send_receipt'])); ?>
                />
                <label for="donadosu-send-receipt"><?php esc_html_e('Send receipt email to donor', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Uses the same HTML receipt template as PayPal donations. Leave unticked if the donor should not receive an automated email.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
            </div>
        </div>

        <div style="margin-top:20px;display:flex;gap:10px;align-items:center;">
            <button type="submit" name="donadosu_manual_submit" value="1" class="button button-primary button-large">
                <?php esc_html_e('Save Donation', 'donateocean-donation-suite'); ?>
            </button>
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=donadosu_donation')); ?>" class="button button-large">
                <?php esc_html_e('Cancel', 'donateocean-donation-suite'); ?>
            </a>
        </div>

    </form>
</div>
