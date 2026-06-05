<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables set by including controller. ?>
<div class="wrap donadosu-admin-wrap">
    <header class="donadosu-page-header">
        <span class="donadosu-eyebrow">Donation Suite</span>
        <h1><?php esc_html_e('Settings', 'donateocean-donation-suite'); ?></h1>
    </header>
    <?php // Marks where WordPress should drop admin notices, so the review notice lands below the header instead of inside the flex header. ?>
    <hr class="wp-header-end">

    <?php
    // Single source of truth: $connectionState comes from ConfigService and is
    // identical on every tab. Banner above is rendered once, not per-tab.
    $noticeSettingsUrl = esc_url(admin_url('admin.php?page=donadosu-settings&tab=environment'));

    // Inline state banners are dismissible; a dismissed key (persisted by the
    // settings controller) keeps that banner hidden on subsequent visits.
    $dismissedNotices = isset($dismissedNotices) && is_array($dismissedNotices) ? $dismissedNotices : array();

    if ('disconnected' === $connectionState && ! in_array('disconnected', $dismissedNotices, true)) : ?>
    <div class="donadosu-inline-notice donadosu-inline-notice--info" data-donadosu-dismissible-notice="disconnected"><p><strong><?php esc_html_e('Donation Suite — Setup required.', 'donateocean-donation-suite'); ?></strong> <?php esc_html_e('Connect your PayPal account to start accepting donations.', 'donateocean-donation-suite'); ?> <a href="<?php echo esc_url( $noticeSettingsUrl ); ?>"><?php esc_html_e('Connect PayPal', 'donateocean-donation-suite'); ?></a></p><button type="button" class="donadosu-notice-dismiss" aria-label="<?php esc_attr_e('Dismiss this notice.', 'donateocean-donation-suite'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></div>
    <?php elseif ('sandbox_active' === $connectionState && ! in_array('sandbox_active', $dismissedNotices, true)) : ?>
    <div class="donadosu-inline-notice donadosu-inline-notice--warning" data-donadosu-dismissible-notice="sandbox_active"><p><strong><?php esc_html_e('Donation Suite — Sandbox (Test Mode) active.', 'donateocean-donation-suite'); ?></strong> <?php esc_html_e('The donation form is using PayPal sandbox credentials. No real payments will be processed.', 'donateocean-donation-suite'); ?> <a href="<?php echo esc_url( $noticeSettingsUrl ); ?>"><?php esc_html_e('Switch to Production', 'donateocean-donation-suite'); ?></a></p><button type="button" class="donadosu-notice-dismiss" aria-label="<?php esc_attr_e('Dismiss this notice.', 'donateocean-donation-suite'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></div>
    <?php endif; ?>

    <div class="donadosu-settings-toolbar">
        <a class="donadosu-support-link" href="<?php echo esc_url('https://wordpress.org/support/plugin/donateocean-donation-suite/'); ?>" target="_blank" rel="noopener noreferrer">
            <span class="dashicons dashicons-sos" aria-hidden="true"></span>
            <?php esc_html_e('Support', 'donateocean-donation-suite'); ?>
        </a>
    </div>

    <form method="post" action="options.php" class="donadosu-settings-form">
        <?php settings_fields(\DonationSuite\Core\ConfigService::OPTION_KEY); ?>
        <input type="hidden" name="donadosu_settings[_active_tab]" value="<?php echo esc_attr($activeTab); ?>" />

        <?php
        $settingsTabKeys = array_keys($settingsTabs);
        $isToolTab       = array_key_exists($activeTab, $toolTabs);
        $currentStep     = array_search($activeTab, $settingsTabKeys, true);
        $currentStep     = ($currentStep === false || $isToolTab) ? -1 : (int) $currentStep;
        $totalSteps      = count($settingsTabKeys);
        $nextTab     = $settingsTabKeys[$currentStep + 1] ?? null;
        $previousTab = ($currentStep > 0) ? ($settingsTabKeys[$currentStep - 1] ?? null) : null;
        ?>

        <div class="donadosu-settings-layout">
            <aside class="donadosu-setup-wizard" aria-label="Setup progress">
                <div class="donadosu-setup-wizard__header">
                    <div>
                        <p class="donadosu-setup-wizard__eyebrow"><?php esc_html_e('Setup wizard', 'donateocean-donation-suite'); ?></p>
                        <?php if ($isToolTab) : ?>
                            <h2><?php esc_html_e('Tools', 'donateocean-donation-suite'); ?></h2>
                        <?php else : ?>
                            <h2><?php
                            /* translators: 1: current step number, 2: total number of steps */
                            printf(esc_html__('Step %1$s of %2$s', 'donateocean-donation-suite'), esc_html((string) ($currentStep + 1)), esc_html((string) $totalSteps));
                            ?></h2>
                        <?php endif; ?>
                    </div>
                </div>

                <ol class="donadosu-setup-wizard__steps">
                    <?php foreach ($settingsTabs as $tabKey => $tabLabel) : ?>
                        <?php
                        $tabIndex      = array_search($tabKey, $settingsTabKeys, true);
                        $tabIndex      = $tabIndex === false ? 0 : (int) $tabIndex;
                        $isCurrentStep = $activeTab === $tabKey;
                        ?>
                        <li class="donadosu-step-item <?php echo esc_attr( $isCurrentStep ? 'is-active' : '' ); ?>">
                            <a
                                class="donadosu-step-item__link"
                                href="<?php echo esc_url(add_query_arg(['page' => 'donadosu-settings', 'tab' => $tabKey], admin_url('admin.php'))); ?>"
                                <?php if ($isCurrentStep) : ?>aria-current="step"<?php endif; ?>
                            >
                                <span class="donadosu-step-item__index"><?php echo esc_html((string) ($tabIndex + 1)); ?></span>
                                <span class="donadosu-step-item__label"><?php echo esc_html($tabLabel); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <?php if (! empty($toolTabs)) : ?>
                <hr class="donadosu-setup-wizard__divider">
                <div class="donadosu-setup-wizard__tools">
                    <p class="donadosu-setup-wizard__tools-label"><?php esc_html_e('Tools', 'donateocean-donation-suite'); ?></p>
                    <ul class="donadosu-setup-wizard__tool-list">
                        <?php foreach ($toolTabs as $tabKey => $tabLabel) : ?>
                            <li class="donadosu-tool-item <?php echo esc_attr( $activeTab === $tabKey ? 'is-active' : '' ); ?>">
                                <a
                                    class="donadosu-tool-item__link"
                                    href="<?php echo esc_url(add_query_arg(['page' => 'donadosu-settings', 'tab' => $tabKey], admin_url('admin.php'))); ?>"
                                    <?php if ($activeTab === $tabKey) : ?>aria-current="page"<?php endif; ?>
                                >
                                    <span class="donadosu-tool-item__icon">
                                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M16 4.2v1.5h2.5v12.5H16v1.5h4V4.2h-4zM4.2 19.8h4v-1.5H5.8V5.8h2.5V4.2h-4l-.1 15.6zm5.1-3.1l1.4.6 4-10-1.4-.6-4 10z"></path></svg>
                                    </span>
                                    <span class="donadosu-tool-item__label"><?php echo esc_html($tabLabel); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </aside>

            <div class="donadosu-settings-grid">
            <?php if ($activeTab === 'environment') : ?>
            <?php
            $envSandboxConnected = '' !== (string) ($settings['sandbox_client_id'] ?? '')
                && '' !== (string) ($settings['sandbox_secret'] ?? '');
            $envLiveConnected = '' !== (string) ($settings['live_client_id'] ?? '')
                && '' !== (string) ($settings['live_secret'] ?? '');
            $showCredentialsGuide = ! $envSandboxConnected && ! $envLiveConnected;
            ?>
            <section class="donadosu-settings-card">
                <h2><?php esc_html_e('PayPal Connection', 'donateocean-donation-suite'); ?></h2>
                <p class="description"><?php esc_html_e('Use sandbox for testing, then switch to live for real donations.', 'donateocean-donation-suite'); ?></p>

                <?php if ($showCredentialsGuide) : ?>
                <details class="donadosu-credentials-guide">
                    <summary class="donadosu-credentials-guide__summary">
                        <span class="donadosu-credentials-guide__title"><?php esc_html_e('How to get your PayPal API credentials', 'donateocean-donation-suite'); ?></span>
                    </summary>
                    <div class="donadosu-credentials-guide__body">
                        <p><?php esc_html_e('Follow these steps to generate the Client ID and Secret needed below:', 'donateocean-donation-suite'); ?></p>
                        <ol class="donadosu-credentials-guide__steps">
                            <li><?php
                            printf(
                                wp_kses(
                                    /* translators: 1: opening anchor tag link to PayPal Developer Dashboard, 2: closing anchor tag */
                                    __('Sign in to the %1$sPayPal Developer Dashboard%2$s with your PayPal business account.', 'donateocean-donation-suite'),
                                    array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))
                                ),
                                '<a href="https://developer.paypal.com/dashboard/applications/" target="_blank" rel="noopener">',
                                '</a>'
                            );
                            ?></li>
                            <li><?php esc_html_e('At the top of the dashboard, toggle between Sandbox and Live to match the environment you are configuring.', 'donateocean-donation-suite'); ?></li>
                            <li><?php esc_html_e('Open "Apps & Credentials" and click "Create App". Give it a name (for example, "Donation Suite") and choose "Merchant" as the app type.', 'donateocean-donation-suite'); ?></li>
                            <li><?php esc_html_e('On the app details screen, copy the Client ID and Secret.', 'donateocean-donation-suite'); ?></li>
                            <li><?php esc_html_e('Paste them into the Client ID and Secret fields below, then click Save Settings.', 'donateocean-donation-suite'); ?></li>
                        </ol>
                        <p class="donadosu-credentials-guide__note"><?php esc_html_e('Note: Sandbox credentials are only valid in test mode. Repeat the steps under the Live tab to obtain production credentials.', 'donateocean-donation-suite'); ?></p>
                    </div>
                </details>
                <?php endif; ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="donadosu-environment"><?php esc_html_e('Environment', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Choose Sandbox to test with PayPal test credentials. Switch to Production when you are ready to accept real donations.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span>
                        </th>
                        <td>
                            <select id="donadosu-environment" name="donadosu_settings[sandbox]">
                                <option value="1" <?php selected(!empty($settings['sandbox'])); ?>><?php esc_html_e('Sandbox (Test Mode)', 'donateocean-donation-suite'); ?></option>
                                <option value="0" <?php selected(empty($settings['sandbox'])); ?>><?php esc_html_e('Production (Live)', 'donateocean-donation-suite'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <?php
                    // Per-environment connection state.
                    $sandboxClientId    = (string) ($settings['sandbox_client_id'] ?? '');
                    $sandboxSecret      = (string) ($settings['sandbox_secret'] ?? '');
                    $sandboxEmail       = (string) ($settings['sandbox_connected_email'] ?? '');
                    $sandboxConnected   = '' !== $sandboxClientId && '' !== $sandboxSecret;

                    $liveClientId       = (string) ($settings['live_client_id'] ?? '');
                    $liveSecret         = (string) ($settings['live_secret'] ?? '');
                    $liveEmail          = (string) ($settings['live_connected_email'] ?? '');
                    $liveConnected      = '' !== $liveClientId && '' !== $liveSecret;
                    ?>

                    <!-- ── Sandbox: Connection Status (only when connected) ── -->
                    <?php if ($sandboxConnected) : ?>
                    <tr data-donadosu-env-row="sandbox">
                        <th scope="row"><?php esc_html_e('Connection Status', 'donateocean-donation-suite'); ?></th>
                        <td>
                            <div class="donadosu-connection-box donadosu-connection-box--connected">
                                <div class="donadosu-connection-box__header">
                                    <span class="donadosu-connection-box__icon donadosu-connection-box__icon--success">&#10003;</span>
                                    <strong class="donadosu-connection-box__title"><?php esc_html_e('PayPal Account Connected', 'donateocean-donation-suite'); ?></strong>
                                </div>
                                <?php if ('' !== $sandboxEmail) : ?>
                                <p class="donadosu-connection-box__detail"><?php
                                /* translators: %s: connected PayPal account email address */
                                echo esc_html(sprintf(__('Connected Account: %s', 'donateocean-donation-suite'), $sandboxEmail));
                                ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="donadosu-connection-box__actions">
                                <button type="button" class="button button-secondary donadosu-disconnect-btn donadosu-disconnect-paypal" data-donadosu-env="sandbox"><?php esc_html_e('Disconnect PayPal Account', 'donateocean-donation-suite'); ?></button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($sandboxConnected) : ?>
                    <input type="hidden" name="donadosu_settings[sandbox_client_id]" value="<?php echo esc_attr($sandboxClientId); ?>" />
                    <input type="hidden" name="donadosu_settings[sandbox_secret]" value="<?php echo esc_attr($sandboxSecret); ?>" />
                    <?php else : ?>
                    <tr data-donadosu-env-row="sandbox">
                        <th scope="row"><label for="donadosu-sandbox-client-id"><?php esc_html_e('Sandbox Client ID', 'donateocean-donation-suite'); ?></label></th>
                        <td>
                            <div class="donadosu-password-field">
                                <input id="donadosu-sandbox-client-id" type="password" class="regular-text code" name="donadosu_settings[sandbox_client_id]" value="<?php echo esc_attr($sandboxClientId); ?>" autocomplete="off" readonly onfocus="this.removeAttribute('readonly');" />
                                <button type="button" class="button donadosu-toggle-visibility" data-donadosu-toggle="donadosu-sandbox-client-id" aria-label="<?php esc_attr_e('Show or hide value', 'donateocean-donation-suite'); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr data-donadosu-env-row="sandbox">
                        <th scope="row"><label for="donadosu-sandbox-secret"><?php esc_html_e('Sandbox Secret', 'donateocean-donation-suite'); ?></label></th>
                        <td>
                            <div class="donadosu-password-field">
                                <input id="donadosu-sandbox-secret" class="regular-text code" type="password" name="donadosu_settings[sandbox_secret]" value="<?php echo '' !== $sandboxSecret ? '••••••••' : ''; ?>" autocomplete="off" readonly onfocus="this.removeAttribute('readonly');" data-donadosu-masked="1" />
                                <button type="button" class="button donadosu-toggle-visibility" data-donadosu-toggle="donadosu-sandbox-secret" aria-label="<?php esc_attr_e('Show or hide value', 'donateocean-donation-suite'); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <input type="hidden" name="donadosu_settings[sandbox_webhook_id]" value="<?php echo esc_attr((string) ($settings['sandbox_webhook_id'] ?? '')); ?>" />
                    <input type="hidden" name="donadosu_settings[sandbox_connected_email]" value="<?php echo esc_attr($sandboxEmail); ?>" />

                    <!-- ── Live: Connection Status (only when connected) ── -->
                    <?php if ($liveConnected) : ?>
                    <tr data-donadosu-env-row="live">
                        <th scope="row"><?php esc_html_e('Connection Status', 'donateocean-donation-suite'); ?></th>
                        <td>
                            <div class="donadosu-connection-box donadosu-connection-box--connected">
                                <div class="donadosu-connection-box__header">
                                    <span class="donadosu-connection-box__icon donadosu-connection-box__icon--success">&#10003;</span>
                                    <strong class="donadosu-connection-box__title"><?php esc_html_e('PayPal Account Connected', 'donateocean-donation-suite'); ?></strong>
                                </div>
                                <?php if ('' !== $liveEmail) : ?>
                                <p class="donadosu-connection-box__detail"><?php
                                /* translators: %s: connected PayPal account email address */
                                echo esc_html(sprintf(__('Connected Account: %s', 'donateocean-donation-suite'), $liveEmail));
                                ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="donadosu-connection-box__actions">
                                <button type="button" class="button button-secondary donadosu-disconnect-btn donadosu-disconnect-paypal" data-donadosu-env="live"><?php esc_html_e('Disconnect PayPal Account', 'donateocean-donation-suite'); ?></button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($liveConnected) : ?>
                    <input type="hidden" name="donadosu_settings[live_client_id]" value="<?php echo esc_attr($liveClientId); ?>" />
                    <input type="hidden" name="donadosu_settings[live_secret]" value="<?php echo esc_attr($liveSecret); ?>" />
                    <?php else : ?>
                    <tr data-donadosu-env-row="live">
                        <th scope="row"><label for="donadosu-live-client-id"><?php esc_html_e('Live Client ID', 'donateocean-donation-suite'); ?></label></th>
                        <td>
                            <div class="donadosu-password-field">
                                <input id="donadosu-live-client-id" type="password" class="regular-text code" name="donadosu_settings[live_client_id]" value="<?php echo esc_attr($liveClientId); ?>" autocomplete="off" readonly onfocus="this.removeAttribute('readonly');" />
                                <button type="button" class="button donadosu-toggle-visibility" data-donadosu-toggle="donadosu-live-client-id" aria-label="<?php esc_attr_e('Show or hide value', 'donateocean-donation-suite'); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr data-donadosu-env-row="live">
                        <th scope="row"><label for="donadosu-live-secret"><?php esc_html_e('Live Secret', 'donateocean-donation-suite'); ?></label></th>
                        <td>
                            <div class="donadosu-password-field">
                                <input id="donadosu-live-secret" class="regular-text code" type="password" name="donadosu_settings[live_secret]" value="<?php echo '' !== $liveSecret ? '••••••••' : ''; ?>" autocomplete="off" readonly onfocus="this.removeAttribute('readonly');" data-donadosu-masked="1" />
                                <button type="button" class="button donadosu-toggle-visibility" data-donadosu-toggle="donadosu-live-secret" aria-label="<?php esc_attr_e('Show or hide value', 'donateocean-donation-suite'); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <input type="hidden" name="donadosu_settings[live_webhook_id]" value="<?php echo esc_attr((string) ($settings['live_webhook_id'] ?? '')); ?>" />
                    <input type="hidden" name="donadosu_settings[live_connected_email]" value="<?php echo esc_attr($liveEmail); ?>" />
                </table>
            </section>
            <?php endif; ?>

            <?php if ($activeTab === 'experience') : ?>
            <section class="donadosu-settings-card">
                <h2><?php esc_html_e('Donation Experience', 'donateocean-donation-suite'); ?></h2>
                <p class="description"><?php esc_html_e('Set the default donor currency and optional quick-pick amounts.', 'donateocean-donation-suite'); ?></p>

                <table class="form-table" role="presentation">
                    <?php
                    $selectedCurrency = strtoupper((string) ($settings['currency'] ?? 'USD'));
                    $currencyOptions = [
                        'AUD' => 'Australian Dollar (AUD)',
                        'BRL' => 'Brazilian Real (BRL)',
                        'CAD' => 'Canadian Dollar (CAD)',
                        'CHF' => 'Swiss Franc (CHF)',
                        'CZK' => 'Czech Koruna (CZK)',
                        'DKK' => 'Danish Krone (DKK)',
                        'EUR' => 'Euro (EUR)',
                        'GBP' => 'British Pound (GBP)',
                        'HKD' => 'Hong Kong Dollar (HKD)',
                        'HUF' => 'Hungarian Forint (HUF)',
                        'ILS' => 'Israeli New Shekel (ILS)',
                        'JPY' => 'Japanese Yen (JPY)',
                        'MXN' => 'Mexican Peso (MXN)',
                        'MYR' => 'Malaysian Ringgit (MYR)',
                        'NOK' => 'Norwegian Krone (NOK)',
                        'NZD' => 'New Zealand Dollar (NZD)',
                        'PHP' => 'Philippine Peso (PHP)',
                        'PLN' => 'Polish Złoty (PLN)',
                        'SEK' => 'Swedish Krona (SEK)',
                        'SGD' => 'Singapore Dollar (SGD)',
                        'THB' => 'Thai Baht (THB)',
                        'TWD' => 'Taiwan Dollar (TWD)',
                        'USD' => 'US Dollar (USD)',
                    ];
                    if (! array_key_exists($selectedCurrency, $currencyOptions)) {
                        $currencyOptions[$selectedCurrency] = sprintf('%s (%s)', $selectedCurrency, $selectedCurrency);
                    }
                    ?>
                    <tr><th scope="row"><label for="donadosu-currency"><?php esc_html_e('Default donation currency', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Shown by default on the donation form.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th><td><select id="donadosu-currency" name="donadosu_settings[currency]">
                                <?php foreach ($currencyOptions as $currencyCode => $currencyLabel) : ?>
                                    <option value="<?php echo esc_attr($currencyCode); ?>" <?php selected($selectedCurrency, $currencyCode); ?>><?php echo esc_html($currencyLabel); ?></option>
                                <?php endforeach; ?>
                            </select></td></tr>
                    <tr><th scope="row"><label for="donadosu-preset-amounts"><?php esc_html_e('Suggested donation amounts', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Comma-separated values, for example: 10,25,50,100.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th><td><input id="donadosu-preset-amounts" type="text" class="regular-text" name="donadosu_settings[preset_amounts]" value="<?php echo esc_attr($settings['preset_amounts'] ?? '10,25,50,100'); ?>" /></td></tr>
                    <tr><th scope="row"><label for="donadosu-allowed-currencies"><?php esc_html_e('Allowed currencies', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Comma-separated ISO currency codes, e.g. USD,EUR,GBP.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th><td><input id="donadosu-allowed-currencies" type="text" class="regular-text" name="donadosu_settings[allowed_currencies_csv]" value="<?php echo esc_attr(implode(',', array_map('strval', (array) ($settings['allowed_currencies'] ?? [])))); ?>" /></td></tr>
                    <tr><th scope="row"><label for="donadosu-custom-amount"><?php esc_html_e('Allow custom amount', 'donateocean-donation-suite'); ?></label></th><td><input type="hidden" name="donadosu_settings[custom_amount]" value="0" /><label><input id="donadosu-custom-amount" type="checkbox" name="donadosu_settings[custom_amount]" value="1" <?php checked(!empty($settings['custom_amount'])); ?> /> <?php esc_html_e('Donors can type their own amount', 'donateocean-donation-suite'); ?></label></td></tr>
                    <tr><th scope="row"><label for="donadosu-min-amount"><?php esc_html_e('Minimum amount', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Must be lower than the maximum amount below.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th><td><input id="donadosu-min-amount" type="number" min="0.5" step="0.01" class="small-text" name="donadosu_settings[min_amount]" value="<?php echo esc_attr((string) ($settings['min_amount'] ?? 1)); ?>" /><p class="description donadosu-min-max-hint" role="alert" hidden></p></td></tr>
                    <tr><th scope="row"><label for="donadosu-max-amount"><?php esc_html_e('Maximum amount', 'donateocean-donation-suite'); ?></label></th><td><input id="donadosu-max-amount" type="number" min="1" step="0.01" class="small-text" name="donadosu_settings[max_amount]" value="<?php echo esc_attr((string) ($settings['max_amount'] ?? 100000)); ?>" /></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Recurring donations', 'donateocean-donation-suite'); ?></th><td><label><input type="checkbox" name="donadosu_settings[enable_recurring]" value="1" <?php checked(!empty($settings['enable_recurring'])); ?> /> <?php esc_html_e('Enable monthly and annual recurring donations (PayPal Subscriptions API)', 'donateocean-donation-suite'); ?></label></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Advanced Credit & Debit Card', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Requires Advanced Credit and Debit Card Payments to be enabled in your PayPal account. Donors can enter card details directly without leaving your site.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th><td><label><input type="checkbox" name="donadosu_settings[enable_paypal_card_fields]" value="1" <?php checked(!empty($settings['enable_paypal_card_fields'])); ?> /> <?php esc_html_e('Show credit/debit card fields directly on the donation form', 'donateocean-donation-suite'); ?></label></td></tr>
                    <?php $feeCoverageEnabled = ! empty($settings['enable_fee_coverage']); ?>
                    <tr><th scope="row"><?php esc_html_e('Fee coverage', 'donateocean-donation-suite'); ?></th><td><label><input id="donadosu-enable-fee-coverage" type="checkbox" name="donadosu_settings[enable_fee_coverage]" value="1" <?php checked($feeCoverageEnabled); ?> /> <?php esc_html_e('Allow donors to cover the transaction fee', 'donateocean-donation-suite'); ?></label></td></tr>
                    <tr class="donadosu-fee-coverage-dependent"<?php if (! $feeCoverageEnabled) : ?> hidden<?php endif; ?>><th scope="row"><label for="donadosu-fee-percentage"><?php esc_html_e('Transaction fee %', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Default: 2.9. Used to calculate the donor fee-coverage amount.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th><td><input id="donadosu-fee-percentage" type="number" min="0" max="10" step="0.01" class="small-text" name="donadosu_settings[fee_percentage]" value="<?php echo esc_attr((string) ($settings['fee_percentage'] ?? 2.9)); ?>" /></td></tr>
                    <tr class="donadosu-fee-coverage-dependent"<?php if (! $feeCoverageEnabled) : ?> hidden<?php endif; ?>><th scope="row"><?php esc_html_e('Fee coverage pre-checked', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Returning donors who previously unchecked it will see it unchecked (their preference is remembered).', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th><td><label><input type="hidden" name="donadosu_settings[fee_coverage_default_checked]" value="0" /><input type="checkbox" name="donadosu_settings[fee_coverage_default_checked]" value="1" <?php checked(!empty($settings['fee_coverage_default_checked'])); ?> /> <?php esc_html_e('Check the "Cover the transaction fee" checkbox by default', 'donateocean-donation-suite'); ?></label></td></tr>
                    <tr>
                        <th scope="row"><label for="donadosu-giving-levels"><?php esc_html_e('Giving levels with descriptions', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php echo esc_attr(wp_strip_all_tags(__('JSON array of giving levels. Example: <code>[{"amount":25,"label":"Supporter","description":"Feeds a family for one week"},{"amount":50,"label":"Champion"}]</code>. Leave empty to use plain preset amounts.', 'donateocean-donation-suite'))); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                        <td>
                            <textarea id="donadosu-giving-levels" name="donadosu_settings[giving_levels_json]" rows="6" class="large-text code" placeholder='[{"amount":25,"label":"Supporter","description":"Feeds a family for one week"},{"amount":50,"label":"Champion"}]'><?php echo esc_textarea($settings['giving_levels_json'] ?? ''); ?></textarea>
                        </td>
                    </tr>
                </table>
            </section>
            <?php endif; ?>

            <?php if ($activeTab === 'advanced') : ?>
            <section class="donadosu-settings-card">
                <h2><?php esc_html_e('Advanced & Security', 'donateocean-donation-suite'); ?></h2>
                <p class="description"><?php esc_html_e('Fraud protection thresholds. Higher values allow more flexibility; lower values add stricter checks.', 'donateocean-donation-suite'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="donadosu-fraud-threshold"><?php esc_html_e('High-value flag threshold', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Donations at or above this amount are flagged for review in the admin list. Default: 5000. Set to 0 to disable.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                        <td>
                            <input id="donadosu-fraud-threshold" type="number" min="0" step="1" class="small-text" name="donadosu_settings[fraud_flag_threshold]" value="<?php echo esc_attr((string) ($settings['fraud_flag_threshold'] ?? 5000)); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="donadosu-max-per-email"><?php esc_html_e('Max donations per email / 24 h', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Block a donor email address after this many donations in 24 hours. Default: 5.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                        <td>
                            <input id="donadosu-max-per-email" type="number" min="1" step="1" class="small-text" name="donadosu_settings[fraud_max_per_email]" value="<?php echo esc_attr((string) ($settings['fraud_max_per_email'] ?? 5)); ?>" />
                        </td>
                    </tr>
                </table>

                <h2 class="donadosu-settings-card__section-h2"><?php esc_html_e('Data', 'donateocean-donation-suite'); ?></h2>
                <p class="description"><?php esc_html_e('Controls what happens to plugin data when the plugin is deleted.', 'donateocean-donation-suite'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="donadosu-cleanup"><?php esc_html_e('Cleanup data on uninstall', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('When unchecked, data is preserved so it is available again on reinstall. Deactivating the plugin never deletes data.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                        <td>
                            <label><input id="donadosu-cleanup" type="checkbox" name="donadosu_settings[cleanup_on_uninstall]" value="1" <?php checked(!empty($settings['cleanup_on_uninstall'])); ?> /> <?php esc_html_e('Delete all donation data, settings, tables, and logs on uninstall', 'donateocean-donation-suite'); ?></label>
                        </td>
                    </tr>
                </table>

                <h2 class="donadosu-settings-card__section-h2"><?php esc_html_e('Logging', 'donateocean-donation-suite'); ?></h2>
                <p class="description"><?php esc_html_e('Control diagnostic logging for troubleshooting.', 'donateocean-donation-suite'); ?></p>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><?php esc_html_e('Enable logging', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php
/* translators: %s: relative path to the log directory under wp-content. */
echo esc_attr(sprintf(__('Logs are written to %s with daily rotation.', 'donateocean-donation-suite'), \DonationSuite\Logging\Logger::get_log_directory_relative()));
?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th><td><label><input type="checkbox" name="donadosu_settings[enable_logging]" value="1" <?php checked(!empty($settings['enable_logging'])); ?> /> <?php esc_html_e('Enable', 'donateocean-donation-suite'); ?></label></td></tr>
                    <tr><th scope="row"><label for="donadosu-logging-level"><?php esc_html_e('Logging level', 'donateocean-donation-suite'); ?></label></th><td><select id="donadosu-logging-level" name="donadosu_settings[logging_level]"><option value="error" <?php selected(($settings['logging_level'] ?? 'error'), 'error'); ?>><?php esc_html_e('Error', 'donateocean-donation-suite'); ?></option><option value="warn" <?php selected(($settings['logging_level'] ?? 'error'), 'warn'); ?>><?php esc_html_e('Warn', 'donateocean-donation-suite'); ?></option><option value="info" <?php selected(($settings['logging_level'] ?? 'error'), 'info'); ?>><?php esc_html_e('Info', 'donateocean-donation-suite'); ?></option><option value="debug" <?php selected(($settings['logging_level'] ?? 'error'), 'debug'); ?>><?php esc_html_e('Debug', 'donateocean-donation-suite'); ?></option></select></td></tr>
                </table>

                <h2 class="donadosu-settings-card__section-h2"><?php esc_html_e('Email', 'donateocean-donation-suite'); ?></h2>
                <p class="description"><?php esc_html_e('Verify that your WordPress site can send emails and that receipt formatting looks correct.', 'donateocean-donation-suite'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Test receipt email', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Sends a sample receipt to the admin email address to verify delivery and formatting.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                        <td>
                            <button type="button" id="donadosu-send-test-email" class="button button-secondary"><?php esc_html_e('Send test email', 'donateocean-donation-suite'); ?></button>
                            <span id="donadosu-test-email-result" class="donadosu-inline-result" aria-live="polite"></span>
                        </td>
                    </tr>
                </table>

                <h2 class="donadosu-settings-card__section-h2"><?php esc_html_e('Year-End Summaries', 'donateocean-donation-suite'); ?></h2>
                <p class="description"><?php esc_html_e('Send a consolidated giving summary to every donor who donated in a calendar year.', 'donateocean-donation-suite'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <?php
                        $yearEndNonce = wp_create_nonce('donadosu_year_end_summary');
                        $prevYear     = (int) gmdate('Y') - 1;
                        ?>
                        <th scope="row"><?php esc_html_e('Send year-end summaries', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php
                        /* translators: %s: four-digit year for which summaries will be sent, e.g. "2024" */
                        echo esc_attr(sprintf(__('Sends one consolidated email per donor for %s. Already-sent summaries are skipped automatically.', 'donateocean-donation-suite'), (string) $prevYear));
                        ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                        <td>
                            <a
                                href="<?php echo esc_url(admin_url(sprintf('admin-post.php?action=donadosu_year_end_summary&year=%d&_wpnonce=%s', $prevYear, $yearEndNonce))); ?>"
                                class="button button-secondary donadosu-year-end-trigger"
                                data-donadosu-confirm="<?php echo esc_attr(sprintf(
                                    /* translators: %d: four-digit year for which summaries will be sent. */
                                    __('This will email every donor who gave in %d. Continue?', 'donateocean-donation-suite'),
                                    $prevYear
                                )); ?>"
                            >
                                <?php
                                /* translators: %s: four-digit year for which summaries will be sent, e.g. "2024" */
                                printf(esc_html__('Send %s summaries now', 'donateocean-donation-suite'), esc_html((string) $prevYear));
                                ?>
                            </a>
                        </td>
                    </tr>
                </table>
            </section>
            <?php endif; ?>

            <?php if ($activeTab === 'compliance') : ?>
            <section class="donadosu-settings-card">
                <h2><?php esc_html_e('Organization & Compliance', 'donateocean-donation-suite'); ?></h2>
                <p class="description"><?php esc_html_e('Details shown to donors and used in receipts and policy links.', 'donateocean-donation-suite'); ?></p>

                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="donadosu-charity-name"><?php esc_html_e('Charity name', 'donateocean-donation-suite'); ?></label></th><td><input id="donadosu-charity-name" type="text" class="regular-text" name="donadosu_settings[charity_name]" value="<?php echo esc_attr($settings['charity_name'] ?? ''); ?>" /></td></tr>
                    <tr><th scope="row"><label for="donadosu-reg-id"><?php esc_html_e('Registration / Tax ID', 'donateocean-donation-suite'); ?></label></th><td><input id="donadosu-reg-id" type="text" class="regular-text" name="donadosu_settings[reg_id]" value="<?php echo esc_attr($settings['reg_id'] ?? ''); ?>" /></td></tr>
                    <tr><th scope="row"><label for="donadosu-charity-address"><?php esc_html_e('Organization address', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Enter each line separately (e.g. street, city, postal code, country).', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th><td><textarea id="donadosu-charity-address" name="donadosu_settings[charity_address]" rows="3" class="regular-text" style="min-height:80px;"><?php echo esc_textarea($settings['charity_address'] ?? ''); ?></textarea></td></tr>
                    <tr><th scope="row"><label for="donadosu-contact-email"><?php esc_html_e('Contact email', 'donateocean-donation-suite'); ?></label></th><td><input id="donadosu-contact-email" type="email" class="regular-text" name="donadosu_settings[contact_email]" value="<?php echo esc_attr($settings['contact_email'] ?? ''); ?>" /></td></tr>
                    <tr><th scope="row"><label for="donadosu-tax-disclaimer"><?php esc_html_e('Tax disclaimer', 'donateocean-donation-suite'); ?></label></th><td><textarea id="donadosu-tax-disclaimer" name="donadosu_settings[tax_disclaimer]" rows="4" class="large-text"><?php echo esc_textarea($settings['tax_disclaimer'] ?? 'No goods or services were provided in exchange for this donation.'); ?></textarea></td></tr>
                    <tr><th scope="row"><label for="donadosu-privacy-url"><?php esc_html_e('Privacy URL', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Link to your privacy policy. Displayed in the donation form footer and receipt emails.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th><td><input id="donadosu-privacy-url" type="url" class="regular-text code" name="donadosu_settings[privacy_url]" value="<?php echo esc_attr($settings['privacy_url'] ?? ''); ?>" /></td></tr>
                    <tr><th scope="row"><label for="donadosu-refund-url"><?php esc_html_e('Refund URL', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Link to your refund policy. Displayed in the donation form footer and receipt emails.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th><td><input id="donadosu-refund-url" type="url" class="regular-text code" name="donadosu_settings[refund_url]" value="<?php echo esc_attr($settings['refund_url'] ?? ''); ?>" /></td></tr>
                    <tr><th scope="row"><label for="donadosu-retention"><?php esc_html_e('Retention months', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('How long donor personal information is kept after their last donation. After this period donor records are anonymised, but donation totals are preserved. Default: 24.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th><td><input id="donadosu-retention" type="number" min="1" class="small-text" name="donadosu_settings[retention_months]" value="<?php echo esc_attr((string) ($settings['retention_months'] ?? 24)); ?>" /></td></tr>
                    <tr><th scope="row"><label for="donadosu-store-raw"><?php esc_html_e('Store raw webhook payload', 'donateocean-donation-suite'); ?></label></th><td><label><input id="donadosu-store-raw" type="checkbox" name="donadosu_settings[store_raw_payload]" value="1" <?php checked(!empty($settings['store_raw_payload'])); ?> /> <?php esc_html_e('Keep raw payload for diagnostics and audit.', 'donateocean-donation-suite'); ?></label></td></tr>
                </table>
            </section>
            <?php endif; ?>

            <?php if ($activeTab === 'integrations') : ?>
            <?php
            $gaEnabled     = ! empty($settings['ga_enable_tracking']);
            $mcEnabled     = ! empty($settings['mailchimp_auto_subscribe']);
            $ccEnabled     = ! empty($settings['cc_auto_subscribe']);
            $zapierEnabled = ! empty($settings['zapier_enabled']);
            $slackEnabled  = ! empty($settings['slack_enabled']);
            $twilioEnabled = ! empty($settings['twilio_enabled']);
            $acEnabled      = ! empty($settings['ac_auto_subscribe']);
            $brevoEnabled   = ! empty($settings['brevo_auto_subscribe']);
            $gsheetsEnabled = ! empty($settings['gsheets_enabled']);
            ?>
            <section class="donadosu-settings-card">
                <h2><?php esc_html_e('Integrations', 'donateocean-donation-suite'); ?></h2>
                <p class="description"><?php esc_html_e('Connect analytics and email marketing services. Enable each integration to reveal its settings.', 'donateocean-donation-suite'); ?></p>

                <!-- Status-dot legend -->
                <p class="donadosu-integration-legend" aria-hidden="true">
                    <span class="donadosu-integration-legend__item"><span class="donadosu-integration-tab-btn__dot donadosu-integration-legend__dot is-enabled"></span><?php esc_html_e('Connected', 'donateocean-donation-suite'); ?></span>
                    <span class="donadosu-integration-legend__item"><span class="donadosu-integration-tab-btn__dot donadosu-integration-legend__dot"></span><?php esc_html_e('Not connected', 'donateocean-donation-suite'); ?></span>
                </p>

                <!-- Sub-tab buttons -->
                <div class="donadosu-integration-tabs" role="tablist" aria-label="Integration services">
                    <button type="button"
                        class="donadosu-integration-tab-btn is-active <?php echo esc_attr( $gaEnabled ? 'is-enabled' : '' ); ?>"
                        role="tab"
                        aria-selected="true"
                        aria-controls="donadosu-integration-panel-ga"
                        data-donadosu-integration-tab="ga">
                        <span class="donadosu-integration-tab-btn__dot"></span>
                        <?php esc_html_e('Google / GTM', 'donateocean-donation-suite'); ?>
                    </button>
                    <button type="button"
                        class="donadosu-integration-tab-btn <?php echo esc_attr( $mcEnabled ? 'is-enabled' : '' ); ?>"
                        role="tab"
                        aria-selected="false"
                        aria-controls="donadosu-integration-panel-mailchimp"
                        data-donadosu-integration-tab="mailchimp">
                        <span class="donadosu-integration-tab-btn__dot"></span>
                        <?php esc_html_e('Mailchimp', 'donateocean-donation-suite'); ?>
                    </button>
                    <button type="button"
                        class="donadosu-integration-tab-btn <?php echo esc_attr( $ccEnabled ? 'is-enabled' : '' ); ?>"
                        role="tab"
                        aria-selected="false"
                        aria-controls="donadosu-integration-panel-cc"
                        data-donadosu-integration-tab="cc">
                        <span class="donadosu-integration-tab-btn__dot"></span>
                        <?php esc_html_e('Constant Contact', 'donateocean-donation-suite'); ?>
                    </button>
                    <button type="button"
                        class="donadosu-integration-tab-btn <?php echo esc_attr( $zapierEnabled ? 'is-enabled' : '' ); ?>"
                        role="tab"
                        aria-selected="false"
                        aria-controls="donadosu-integration-panel-zapier"
                        data-donadosu-integration-tab="zapier">
                        <span class="donadosu-integration-tab-btn__dot"></span>
                        <?php esc_html_e('Zapier', 'donateocean-donation-suite'); ?>
                    </button>
                    <button type="button"
                        class="donadosu-integration-tab-btn <?php echo esc_attr( $slackEnabled ? 'is-enabled' : '' ); ?>"
                        role="tab"
                        aria-selected="false"
                        aria-controls="donadosu-integration-panel-slack"
                        data-donadosu-integration-tab="slack">
                        <span class="donadosu-integration-tab-btn__dot"></span>
                        <?php esc_html_e('Slack', 'donateocean-donation-suite'); ?>
                    </button>
                    <button type="button"
                        class="donadosu-integration-tab-btn <?php echo esc_attr( $twilioEnabled ? 'is-enabled' : '' ); ?>"
                        role="tab"
                        aria-selected="false"
                        aria-controls="donadosu-integration-panel-twilio"
                        data-donadosu-integration-tab="twilio">
                        <span class="donadosu-integration-tab-btn__dot"></span>
                        <?php esc_html_e('Twilio SMS', 'donateocean-donation-suite'); ?>
                    </button>
                    <button type="button"
                        class="donadosu-integration-tab-btn <?php echo esc_attr( $acEnabled ? 'is-enabled' : '' ); ?>"
                        role="tab"
                        aria-selected="false"
                        aria-controls="donadosu-integration-panel-ac"
                        data-donadosu-integration-tab="ac">
                        <span class="donadosu-integration-tab-btn__dot"></span>
                        <?php esc_html_e('ActiveCampaign', 'donateocean-donation-suite'); ?>
                    </button>
                    <button type="button"
                        class="donadosu-integration-tab-btn <?php echo esc_attr( $brevoEnabled ? 'is-enabled' : '' ); ?>"
                        role="tab"
                        aria-selected="false"
                        aria-controls="donadosu-integration-panel-brevo"
                        data-donadosu-integration-tab="brevo">
                        <span class="donadosu-integration-tab-btn__dot"></span>
                        <?php esc_html_e('Brevo', 'donateocean-donation-suite'); ?>
                    </button>
                    <button type="button"
                        class="donadosu-integration-tab-btn <?php echo esc_attr( $gsheetsEnabled ? 'is-enabled' : '' ); ?>"
                        role="tab"
                        aria-selected="false"
                        aria-controls="donadosu-integration-panel-gsheets"
                        data-donadosu-integration-tab="gsheets">
                        <span class="donadosu-integration-tab-btn__dot"></span>
                        <?php esc_html_e('Google Sheets', 'donateocean-donation-suite'); ?>
                    </button>
                </div>

                <!-- Panel: Google Analytics / Tag Manager -->
                <div id="donadosu-integration-panel-ga" class="donadosu-integration-panel is-active" role="tabpanel">
                    <div class="donadosu-integration-enable-row">
                        <label>
                            <input id="donadosu-ga-enable" type="checkbox" name="donadosu_settings[ga_enable_tracking]" value="1" <?php checked($gaEnabled); ?> />
                            <?php esc_html_e('Enable Google Analytics & Tag Manager integration', 'donateocean-donation-suite'); ?>
                        </label>
                    </div>
                    <div class="donadosu-integration-fields" id="donadosu-ga-fields" <?php if ( ! $gaEnabled ) : ?>hidden<?php endif; ?>>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="donadosu-ga-measurement-id"><?php esc_html_e('GA4 Measurement ID', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Your Google Analytics 4 Measurement ID. Leave blank to disable GA4 tracking.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-ga-measurement-id" type="text" class="regular-text code" name="donadosu_settings[ga_measurement_id]" value="<?php echo esc_attr($settings['ga_measurement_id'] ?? ''); ?>" placeholder="G-XXXXXXXXXX" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-gtm-container-id"><?php esc_html_e('GTM Container ID', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Your Google Tag Manager Container ID. Leave blank to disable GTM.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-gtm-container-id" type="text" class="regular-text code" name="donadosu_settings[gtm_container_id]" value="<?php echo esc_attr($settings['gtm_container_id'] ?? ''); ?>" placeholder="GTM-XXXXXXX" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Donation event tracking', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Works with both GA4 and GTM. Passes amount, currency, and campaign as parameters.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <label><input type="checkbox" name="donadosu_settings[ga_push_events]" value="1" <?php checked(!empty($settings['ga_push_events'])); ?> /> <?php echo wp_kses(__('Push <code>donation_complete</code> events to the data layer', 'donateocean-donation-suite'), ['code' => []]); ?></label>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Panel: Mailchimp -->
                <div id="donadosu-integration-panel-mailchimp" class="donadosu-integration-panel" role="tabpanel" hidden>
                    <div class="donadosu-integration-enable-row">
                        <label>
                            <input id="donadosu-mailchimp-enable" type="checkbox" name="donadosu_settings[mailchimp_auto_subscribe]" value="1" <?php checked($mcEnabled); ?> />
                            <?php esc_html_e('Enable Mailchimp integration', 'donateocean-donation-suite'); ?>
                        </label>
                    </div>
                    <div class="donadosu-integration-fields" id="donadosu-mailchimp-fields" <?php if ( ! $mcEnabled ) : ?>hidden<?php endif; ?>>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="donadosu-mailchimp-api-key"><?php esc_html_e('API Key', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php echo esc_attr(wp_strip_all_tags(__('Found under <strong>Profile → Extras → API Keys</strong> in Mailchimp.', 'donateocean-donation-suite'))); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-mailchimp-api-key" type="password" class="regular-text code" name="donadosu_settings[mailchimp_api_key]" value="<?php echo esc_attr($settings['mailchimp_api_key'] ?? ''); ?>" autocomplete="new-password" placeholder="xxxxxxxxxxxx-us1" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-mailchimp-list-id"><?php esc_html_e('Audience (List) ID', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php echo esc_attr(wp_strip_all_tags(__('Found in <strong>Audience → Settings → Audience name and defaults</strong>.', 'donateocean-donation-suite'))); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-mailchimp-list-id" type="text" class="regular-text code" name="donadosu_settings[mailchimp_list_id]" value="<?php echo esc_attr($settings['mailchimp_list_id'] ?? ''); ?>" placeholder="a1b2c3d4e5" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Double opt-in', 'donateocean-donation-suite'); ?></th>
                                <td>
                                    <label><input type="checkbox" name="donadosu_settings[mailchimp_double_optin]" value="1" <?php checked(!empty($settings['mailchimp_double_optin'])); ?> /> <?php esc_html_e('Send a confirmation email before subscribing (recommended)', 'donateocean-donation-suite'); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Test connection', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Verifies the API key and audience ID against Mailchimp.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <button type="button" class="button" id="donadosu-mailchimp-test"><?php esc_html_e('Test connection', 'donateocean-donation-suite'); ?></button>
                                    <span id="donadosu-mailchimp-test-result" style="margin-left:10px;"></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Panel: Constant Contact -->
                <?php
                $cc_oauth     = new \DonationSuite\Integration\ConstantContactOAuth( new \DonationSuite\Core\ConfigService(), new \DonationSuite\Logging\Logger( 'error', false ) );
                $cc_connected = $cc_oauth->is_connected();
                $cc_account   = $cc_oauth->connected_account();
                $cc_has_creds = '' !== (string) ( $settings['cc_client_id'] ?? '' ) && '' !== (string) ( $settings['cc_client_secret'] ?? '' );
                $cc_notice    = get_transient( 'donadosu_cc_notice_' . get_current_user_id() );
                if ( $cc_notice ) {
                    delete_transient( 'donadosu_cc_notice_' . get_current_user_id() );
                }
                ?>
                <div id="donadosu-integration-panel-cc" class="donadosu-integration-panel" role="tabpanel" hidden>
                    <div class="donadosu-integration-enable-row">
                        <label>
                            <input id="donadosu-cc-enable" type="checkbox" name="donadosu_settings[cc_auto_subscribe]" value="1" <?php checked($ccEnabled); ?> />
                            <?php esc_html_e('Enable Constant Contact integration', 'donateocean-donation-suite'); ?>
                        </label>
                    </div>
                    <div class="donadosu-integration-fields" id="donadosu-cc-fields" <?php if ( ! $ccEnabled ) : ?>hidden<?php endif; ?>>
                        <?php if ( is_array( $cc_notice ) && ! empty( $cc_notice['message'] ) ) : ?>
                            <div class="notice notice-<?php echo esc_attr( 'connected' === ( $cc_notice['status'] ?? '' ) ? 'success' : ( 'error' === ( $cc_notice['status'] ?? '' ) ? 'error' : 'info' ) ); ?> inline"><p><?php echo esc_html( (string) $cc_notice['message'] ); ?></p></div>
                        <?php endif; ?>
                        <p class="description"><?php echo wp_kses(__('Constant Contact v3 uses OAuth2. Create an app under <strong>My Applications</strong> in the Constant Contact developer portal, add the Redirect URI below, then enter your Client ID and Secret, save, and connect.', 'donateocean-donation-suite'), ['strong' => []]); ?></p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="donadosu-cc-client-id"><?php esc_html_e('Client ID (API Key)', 'donateocean-donation-suite'); ?></label></th>
                                <td>
                                    <input id="donadosu-cc-client-id" type="text" class="regular-text code" name="donadosu_settings[cc_client_id]" value="<?php echo esc_attr($settings['cc_client_id'] ?? ''); ?>" autocomplete="off" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-cc-client-secret"><?php esc_html_e('Client Secret', 'donateocean-donation-suite'); ?></label></th>
                                <td>
                                    <input id="donadosu-cc-client-secret" type="password" class="regular-text code" name="donadosu_settings[cc_client_secret]" value="<?php echo esc_attr($settings['cc_client_secret'] ?? ''); ?>" autocomplete="new-password" readonly onfocus="this.removeAttribute('readonly');" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Redirect URI', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Add this exact URL to your Constant Contact app\'s Redirect URIs.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <code><?php echo esc_html( $cc_oauth->get_redirect_uri() ); ?></code>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Connection', 'donateocean-donation-suite'); ?></th>
                                <td>
                                    <?php if ( $cc_connected ) : ?>
                                        <p>
                                            <span class="donadosu-inline-result donadosu-inline-result--success" style="margin:0;">
                                                <?php
                                                if ( '' !== $cc_account ) {
                                                    /* translators: %s: Constant Contact account/organization name. */
                                                    echo esc_html( sprintf( __( 'Connected: %s', 'donateocean-donation-suite' ), $cc_account ) );
                                                } else {
                                                    esc_html_e( 'Connected.', 'donateocean-donation-suite' );
                                                }
                                                ?>
                                            </span>
                                        </p>
                                        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=donadosu_cc_disconnect' ), 'donadosu_cc_disconnect' ) ); ?>"><?php esc_html_e('Disconnect', 'donateocean-donation-suite'); ?></a>
                                    <?php elseif ( $cc_has_creds ) : ?>
                                        <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=donadosu_cc_connect' ), 'donadosu_cc_connect' ) ); ?>"><?php esc_html_e('Connect to Constant Contact', 'donateocean-donation-suite'); ?></a>
                                        <p class="description"><?php esc_html_e('You will be redirected to Constant Contact to authorize access.', 'donateocean-donation-suite'); ?></p>
                                    <?php else : ?>
                                        <p class="description"><?php esc_html_e('Enter and save your Client ID and Client Secret above, then return here to connect.', 'donateocean-donation-suite'); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-cc-list-id"><?php esc_html_e('Contact List ID', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('The UUID of the contact list where donors will be added.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-cc-list-id" type="text" class="regular-text code" name="donadosu_settings[cc_list_id]" value="<?php echo esc_attr($settings['cc_list_id'] ?? ''); ?>" />
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

<!-- Panel: Zapier -->
                <div id="donadosu-integration-panel-zapier" class="donadosu-integration-panel" role="tabpanel" hidden>
                    <div class="donadosu-integration-enable-row">
                        <label>
                            <input id="donadosu-zapier-enable" type="checkbox" name="donadosu_settings[zapier_enabled]" value="1" <?php checked($zapierEnabled); ?> />
                            <?php esc_html_e('Enable Zapier integration', 'donateocean-donation-suite'); ?>
                        </label>
                    </div>
                    <div class="donadosu-integration-fields" id="donadosu-zapier-fields" <?php if ( ! $zapierEnabled ) : ?>hidden<?php endif; ?>>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="donadosu-zapier-webhook-url"><?php esc_html_e('Webhook URL', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php echo esc_attr(wp_strip_all_tags(__('The <strong>Catch Hook</strong> URL from your Zapier Zap trigger. Found in the Zap editor when using "Webhooks by Zapier" as the trigger.', 'donateocean-donation-suite'))); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-zapier-webhook-url" type="url" class="regular-text code" name="donadosu_settings[zapier_webhook_url]" value="<?php echo esc_attr($settings['zapier_webhook_url'] ?? ''); ?>" placeholder="https://hooks.zapier.com/hooks/catch/..." />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-zapier-secret-key"><?php esc_html_e('Secret Key', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Used to authenticate the sample data endpoint. Auto-generated on first use.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <?php
                                    $zapier_secret = $settings['zapier_secret_key'] ?? '';
                                    if ( '' === $zapier_secret ) {
                                        $zapier_secret = wp_generate_password( 32, false );
                                    }
                                    ?>
                                    <input id="donadosu-zapier-secret-key" type="text" class="regular-text code" name="donadosu_settings[zapier_secret_key]" value="<?php echo esc_attr($zapier_secret); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Sample Data URL', 'donateocean-donation-suite'); ?></th>
                                <td>
                                    <?php if ( '' !== ($settings['zapier_secret_key'] ?? '') ) : ?>
                                        <code><?php echo esc_html( rest_url( 'donadosu/v1/zapier/sample' ) ); ?></code>
                                        <p class="description"><?php esc_html_e('Send the secret key via an X-Donadosu-Secret HTTP header (recommended). The URL query parameter ?secret=… is deprecated but still accepted.', 'donateocean-donation-suite'); ?></p>
                                        <p class="description"><strong><?php esc_html_e('Outbound events are signed:', 'donateocean-donation-suite'); ?></strong> <?php esc_html_e('Each webhook payload includes an X-Donadosu-Signature header (HMAC-SHA256 of the JSON body using this secret key).', 'donateocean-donation-suite'); ?></p>
                                    <?php else : ?>
                                        <p class="description"><?php esc_html_e('Save settings to generate the sample data URL.', 'donateocean-donation-suite'); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Events to send', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Choose which donation events trigger a Zapier webhook.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <fieldset>
                                        <input type="hidden" name="donadosu_settings[zapier_on_completed]" value="0" />
                                        <label><input type="checkbox" name="donadosu_settings[zapier_on_completed]" value="1" <?php checked(!empty($settings['zapier_on_completed'])); ?> /> <?php esc_html_e('Donation completed', 'donateocean-donation-suite'); ?></label><br />
                                        <input type="hidden" name="donadosu_settings[zapier_on_refunded]" value="0" />
                                        <label><input type="checkbox" name="donadosu_settings[zapier_on_refunded]" value="1" <?php checked(!empty($settings['zapier_on_refunded'])); ?> /> <?php esc_html_e('Donation refunded', 'donateocean-donation-suite'); ?></label><br />
                                        <input type="hidden" name="donadosu_settings[zapier_on_disputed]" value="0" />
                                        <label><input type="checkbox" name="donadosu_settings[zapier_on_disputed]" value="1" <?php checked(!empty($settings['zapier_on_disputed'])); ?> /> <?php esc_html_e('Donation disputed', 'donateocean-donation-suite'); ?></label>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Test connection', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Sends a sample payload to verify your Zapier webhook is receiving data.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <button type="button" class="button" id="donadosu-zapier-test"><?php esc_html_e('Send test event', 'donateocean-donation-suite'); ?></button>
                                    <span id="donadosu-zapier-test-result" style="margin-left:10px;"></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Panel: Slack -->
                <div id="donadosu-integration-panel-slack" class="donadosu-integration-panel" role="tabpanel" hidden>
                    <div class="donadosu-integration-enable-row">
                        <label>
                            <input id="donadosu-slack-enable" type="checkbox" name="donadosu_settings[slack_enabled]" value="1" <?php checked($slackEnabled); ?> />
                            <?php esc_html_e('Enable Slack notifications', 'donateocean-donation-suite'); ?>
                        </label>
                    </div>
                    <div class="donadosu-integration-fields" id="donadosu-slack-fields" <?php if ( ! $slackEnabled ) : ?>hidden<?php endif; ?>>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="donadosu-slack-webhook-url"><?php esc_html_e('Webhook URL', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php echo esc_attr(wp_strip_all_tags(__('Create an <strong>Incoming Webhook</strong> in your Slack workspace settings and paste the URL here.', 'donateocean-donation-suite'))); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-slack-webhook-url" type="url" class="regular-text code" name="donadosu_settings[slack_webhook_url]" value="<?php echo esc_attr($settings['slack_webhook_url'] ?? ''); ?>" placeholder="https://hooks.slack.com/services/T.../B.../..." />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-slack-channel"><?php esc_html_e('Channel override', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Optional. Override the default channel set in your webhook. Leave blank to use the webhook default.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-slack-channel" type="text" class="regular-text code" name="donadosu_settings[slack_channel]" value="<?php echo esc_attr($settings['slack_channel'] ?? ''); ?>" placeholder="#donations" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Events to notify', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Choose which donation events send a Slack notification.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <fieldset>
                                        <input type="hidden" name="donadosu_settings[slack_on_completed]" value="0" />
                                        <label><input type="checkbox" name="donadosu_settings[slack_on_completed]" value="1" <?php checked(!empty($settings['slack_on_completed'])); ?> /> <?php esc_html_e('Donation completed', 'donateocean-donation-suite'); ?></label><br />
                                        <input type="hidden" name="donadosu_settings[slack_on_refunded]" value="0" />
                                        <label><input type="checkbox" name="donadosu_settings[slack_on_refunded]" value="1" <?php checked(!empty($settings['slack_on_refunded'])); ?> /> <?php esc_html_e('Donation refunded', 'donateocean-donation-suite'); ?></label><br />
                                        <input type="hidden" name="donadosu_settings[slack_on_disputed]" value="0" />
                                        <label><input type="checkbox" name="donadosu_settings[slack_on_disputed]" value="1" <?php checked(!empty($settings['slack_on_disputed'])); ?> /> <?php esc_html_e('Donation disputed', 'donateocean-donation-suite'); ?></label>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Test connection', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Sends a test message to verify your Slack webhook is working.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <button type="button" class="button" id="donadosu-slack-test"><?php esc_html_e('Send test notification', 'donateocean-donation-suite'); ?></button>
                                    <span id="donadosu-slack-test-result" style="margin-left:10px;"></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Panel: Twilio SMS -->
                <div id="donadosu-integration-panel-twilio" class="donadosu-integration-panel" role="tabpanel" hidden>
                    <div class="donadosu-integration-enable-row">
                        <label>
                            <input id="donadosu-twilio-enable" type="checkbox" name="donadosu_settings[twilio_enabled]" value="1" <?php checked($twilioEnabled); ?> />
                            <?php esc_html_e('Enable Twilio SMS notifications', 'donateocean-donation-suite'); ?>
                        </label>
                    </div>
                    <div class="donadosu-integration-fields" id="donadosu-twilio-fields" <?php if ( ! $twilioEnabled ) : ?>hidden<?php endif; ?>>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="donadosu-twilio-account-sid"><?php esc_html_e('Account SID', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php echo esc_attr(wp_strip_all_tags(__('Found on your <strong>Twilio Console</strong> dashboard.', 'donateocean-donation-suite'))); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-twilio-account-sid" type="text" class="regular-text code" name="donadosu_settings[twilio_account_sid]" value="<?php echo esc_attr($settings['twilio_account_sid'] ?? ''); ?>" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-twilio-auth-token"><?php esc_html_e('Auth Token', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Found next to Account SID on your Twilio Console.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-twilio-auth-token" type="password" class="regular-text code" name="donadosu_settings[twilio_auth_token]" value="<?php echo esc_attr($settings['twilio_auth_token'] ?? ''); ?>" autocomplete="new-password" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-twilio-from-number"><?php esc_html_e('From number', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Your Twilio phone number in E.164 format (e.g. +15551234567).', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-twilio-from-number" type="tel" class="regular-text code" name="donadosu_settings[twilio_from_number]" value="<?php echo esc_attr($settings['twilio_from_number'] ?? ''); ?>" placeholder="+15551234567" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-twilio-to-number"><?php esc_html_e('Notify number', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('The phone number that receives donation SMS alerts in E.164 format.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-twilio-to-number" type="tel" class="regular-text code" name="donadosu_settings[twilio_to_number]" value="<?php echo esc_attr($settings['twilio_to_number'] ?? ''); ?>" placeholder="+15559876543" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Events to notify', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Choose which donation events trigger an SMS notification.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <fieldset>
                                        <input type="hidden" name="donadosu_settings[twilio_on_completed]" value="0" />
                                        <label><input type="checkbox" name="donadosu_settings[twilio_on_completed]" value="1" <?php checked(!empty($settings['twilio_on_completed'])); ?> /> <?php esc_html_e('Donation completed', 'donateocean-donation-suite'); ?></label><br />
                                        <input type="hidden" name="donadosu_settings[twilio_on_refunded]" value="0" />
                                        <label><input type="checkbox" name="donadosu_settings[twilio_on_refunded]" value="1" <?php checked(!empty($settings['twilio_on_refunded'])); ?> /> <?php esc_html_e('Donation refunded', 'donateocean-donation-suite'); ?></label><br />
                                        <input type="hidden" name="donadosu_settings[twilio_on_disputed]" value="0" />
                                        <label><input type="checkbox" name="donadosu_settings[twilio_on_disputed]" value="1" <?php checked(!empty($settings['twilio_on_disputed'])); ?> /> <?php esc_html_e('Donation disputed', 'donateocean-donation-suite'); ?></label>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Test connection', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Sends a test SMS to verify your Twilio credentials and phone numbers.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <button type="button" class="button" id="donadosu-twilio-test"><?php esc_html_e('Send test SMS', 'donateocean-donation-suite'); ?></button>
                                    <span id="donadosu-twilio-test-result" style="margin-left:10px;"></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Panel: ActiveCampaign -->
                <div id="donadosu-integration-panel-ac" class="donadosu-integration-panel" role="tabpanel" hidden>
                    <div class="donadosu-integration-enable-row">
                        <label>
                            <input id="donadosu-ac-enable" type="checkbox" name="donadosu_settings[ac_auto_subscribe]" value="1" <?php checked($acEnabled); ?> />
                            <?php esc_html_e('Enable ActiveCampaign integration', 'donateocean-donation-suite'); ?>
                        </label>
                    </div>
                    <div class="donadosu-integration-fields" id="donadosu-ac-fields" <?php if ( ! $acEnabled ) : ?>hidden<?php endif; ?>>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="donadosu-ac-api-url"><?php esc_html_e('API URL', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php echo esc_attr(wp_strip_all_tags(__('Found in <strong>Settings → Developer</strong> in your ActiveCampaign account.', 'donateocean-donation-suite'))); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-ac-api-url" type="url" class="regular-text code" name="donadosu_settings[ac_api_url]" value="<?php echo esc_attr($settings['ac_api_url'] ?? ''); ?>" placeholder="https://youraccountname.api-us1.com" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-ac-api-key"><?php esc_html_e('API Key', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php echo esc_attr(wp_strip_all_tags(__('Found alongside the API URL in <strong>Settings → Developer</strong>.', 'donateocean-donation-suite'))); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-ac-api-key" type="password" class="regular-text code" name="donadosu_settings[ac_api_key]" value="<?php echo esc_attr($settings['ac_api_key'] ?? ''); ?>" autocomplete="new-password" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-ac-list-id"><?php esc_html_e('List ID', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php echo esc_attr(wp_strip_all_tags(__('The numeric list ID. Found in <strong>Lists → Edit → List ID</strong> in the URL.', 'donateocean-donation-suite'))); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-ac-list-id" type="text" class="regular-text code" name="donadosu_settings[ac_list_id]" value="<?php echo esc_attr($settings['ac_list_id'] ?? ''); ?>" placeholder="1" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Test connection', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Verifies your API URL and key are valid.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <button type="button" class="button" id="donadosu-ac-test"><?php esc_html_e('Test connection', 'donateocean-donation-suite'); ?></button>
                                    <span id="donadosu-ac-test-result" style="margin-left:10px;"></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Panel: Brevo (Sendinblue) -->
                <div id="donadosu-integration-panel-brevo" class="donadosu-integration-panel" role="tabpanel" hidden>
                    <div class="donadosu-integration-enable-row">
                        <label>
                            <input id="donadosu-brevo-enable" type="checkbox" name="donadosu_settings[brevo_auto_subscribe]" value="1" <?php checked($brevoEnabled); ?> />
                            <?php esc_html_e('Enable Brevo integration', 'donateocean-donation-suite'); ?>
                        </label>
                    </div>
                    <div class="donadosu-integration-fields" id="donadosu-brevo-fields" <?php if ( ! $brevoEnabled ) : ?>hidden<?php endif; ?>>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="donadosu-brevo-api-key"><?php esc_html_e('API Key', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php echo esc_attr(wp_strip_all_tags(__('Found in <strong>Settings → SMTP & API → API Keys</strong> in your Brevo dashboard.', 'donateocean-donation-suite'))); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-brevo-api-key" type="password" class="regular-text code" name="donadosu_settings[brevo_api_key]" value="<?php echo esc_attr($settings['brevo_api_key'] ?? ''); ?>" autocomplete="new-password" placeholder="xkeysib-..." />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-brevo-list-id"><?php esc_html_e('List ID', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php echo esc_attr(wp_strip_all_tags(__('The numeric list ID. Found in <strong>Contacts → Lists</strong> — click the list and check the URL.', 'donateocean-donation-suite'))); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-brevo-list-id" type="text" class="regular-text code" name="donadosu_settings[brevo_list_id]" value="<?php echo esc_attr($settings['brevo_list_id'] ?? ''); ?>" placeholder="2" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Double opt-in', 'donateocean-donation-suite'); ?></th>
                                <td>
                                    <label><input type="checkbox" name="donadosu_settings[brevo_double_optin]" value="1" <?php checked(!empty($settings['brevo_double_optin'])); ?> /> <?php esc_html_e('Send a confirmation email before subscribing (recommended for GDPR compliance)', 'donateocean-donation-suite'); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Test connection', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Verifies your Brevo API key is valid.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <button type="button" class="button" id="donadosu-brevo-test"><?php esc_html_e('Test connection', 'donateocean-donation-suite'); ?></button>
                                    <span id="donadosu-brevo-test-result" style="margin-left:10px;"></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Panel: Google Sheets -->
                <div id="donadosu-integration-panel-gsheets" class="donadosu-integration-panel" role="tabpanel" hidden>
                    <div class="donadosu-integration-enable-row">
                        <label>
                            <input id="donadosu-gsheets-enable" type="checkbox" name="donadosu_settings[gsheets_enabled]" value="1" <?php checked($gsheetsEnabled); ?> />
                            <?php esc_html_e('Enable Google Sheets integration', 'donateocean-donation-suite'); ?>
                        </label>
                    </div>
                    <div class="donadosu-integration-fields" id="donadosu-gsheets-fields" <?php if ( ! $gsheetsEnabled ) : ?>hidden<?php endif; ?>>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="donadosu-gsheets-spreadsheet-id"><?php esc_html_e('Spreadsheet ID', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('The long ID from your Google Sheet URL: docs.google.com/spreadsheets/d/{THIS_PART}/edit', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-gsheets-spreadsheet-id" type="text" class="regular-text code" name="donadosu_settings[gsheets_spreadsheet_id]" value="<?php echo esc_attr($settings['gsheets_spreadsheet_id'] ?? ''); ?>" placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-gsheets-sheet-name"><?php esc_html_e('Sheet name', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('The tab name in your spreadsheet. Defaults to "Sheet1".', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <input id="donadosu-gsheets-sheet-name" type="text" class="regular-text code" name="donadosu_settings[gsheets_sheet_name]" value="<?php echo esc_attr($settings['gsheets_sheet_name'] ?? 'Sheet1'); ?>" placeholder="Sheet1" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="donadosu-gsheets-credentials"><?php esc_html_e('Service account JSON', 'donateocean-donation-suite'); ?></label><span class="donadosu-tooltip" tabindex="0" aria-label="<?php echo esc_attr(wp_strip_all_tags(__('Paste the full JSON key file contents from your Google Cloud service account. The service account email must be shared as an <strong>Editor</strong> on the spreadsheet.', 'donateocean-donation-suite'))); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <textarea id="donadosu-gsheets-credentials" class="large-text code" name="donadosu_settings[gsheets_credentials_json]" rows="6" placeholder='{"type":"service_account","project_id":"...","private_key":"...","client_email":"..."}'><?php echo esc_textarea($settings['gsheets_credentials_json'] ?? ''); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Column order', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Add these as headers in row 1 of your sheet. Each donation appends a new row.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <code>Date | Donation ID | Event | Amount | Currency | Donor Name | Donor Email | Frequency | Campaign | Purpose | Payment Source | Receipt # | Anonymous | Tribute</code>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Test connection', 'donateocean-donation-suite'); ?><span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Verifies the service account can access your spreadsheet.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></th>
                                <td>
                                    <button type="button" class="button" id="donadosu-gsheets-test"><?php esc_html_e('Test connection', 'donateocean-donation-suite'); ?></button>
                                    <span id="donadosu-gsheets-test-result" style="margin-left:10px;"></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

            </section>
            <?php endif; ?>

            <?php if ($activeTab === 'shortcode') : ?>
            <section class="donadosu-settings-card donadosu-shortcode-helper">
                <h2><?php esc_html_e('Shortcode Builder', 'donateocean-donation-suite'); ?></h2>
                <p class="description"><?php esc_html_e('Fill in the options below to build your donation shortcode — no code needed. Copy it and paste it into any page or post.', 'donateocean-donation-suite'); ?></p>

                <div class="donadosu-shortcode-builder">

                    <!-- Group: Presentation -->
                    <div class="donadosu-sc-group">
                        <h3 class="donadosu-sc-group__title"><?php esc_html_e('Presentation', 'donateocean-donation-suite'); ?></h3>
                        <div class="donadosu-sc-fields">
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-mode"><?php esc_html_e('Display mode', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('How the donation form is displayed.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <select id="donadosu-sc-mode">
                                    <option value="inline"><?php esc_html_e('Inline (default)', 'donateocean-donation-suite'); ?></option>
                                    <option value="modal"><?php esc_html_e('Modal / popup', 'donateocean-donation-suite'); ?></option>
                                    <option value="widget"><?php esc_html_e('Widget', 'donateocean-donation-suite'); ?></option>
                                    <option value="page"><?php esc_html_e('Full page', 'donateocean-donation-suite'); ?></option>
                                </select>
                            </div>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-donation-mode"><?php esc_html_e('Donation type', 'donateocean-donation-suite'); ?></label>
                                <select id="donadosu-sc-donation-mode">
                                    <option value="both"><?php esc_html_e('One-time & recurring (default)', 'donateocean-donation-suite'); ?></option>
                                    <option value="one_time"><?php esc_html_e('One-time only', 'donateocean-donation-suite'); ?></option>
                                    <option value="monthly"><?php esc_html_e('Monthly recurring only', 'donateocean-donation-suite'); ?></option>
                                    <option value="annual"><?php esc_html_e('Annual recurring only', 'donateocean-donation-suite'); ?></option>
                                </select>
                                <?php if ( empty( $settings['enable_recurring'] ) ) : ?>
                                <div id="donadosu-sc-recurring-warning" class="donadosu-inline-notice donadosu-inline-notice--error">
                                    <p>
                                        <?php
                                        printf(
                                            wp_kses(
                                                /* translators: %s: link to the Donation Experience tab */
                                                __( 'Recurring donations are off. Turn them on in the %s for this option to work.', 'donateocean-donation-suite' ),
                                                array( 'a' => array( 'href' => array() ) )
                                            ),
                                            '<a href="' . esc_url( admin_url( 'admin.php?page=donadosu-settings&tab=experience' ) ) . '">' . esc_html__( 'Donation Experience tab', 'donateocean-donation-suite' ) . '</a>'
                                        );
                                        ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php
                            $sc_default_title       = __('Make a Donation', 'donateocean-donation-suite');
                            $sc_default_description = __('Fast and secure checkout.', 'donateocean-donation-suite');
                            $sc_default_button_text = __('Donate now', 'donateocean-donation-suite');
                            ?>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-title"><?php esc_html_e('Form title', 'donateocean-donation-suite'); ?> <span class="donadosu-optional">(<?php esc_html_e('optional', 'donateocean-donation-suite'); ?>)</span></label>
                                <input id="donadosu-sc-title" type="text" value="<?php echo esc_attr($sc_default_title); ?>" data-donadosu-sc-default="<?php echo esc_attr($sc_default_title); ?>" />
                            </div>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-description"><?php esc_html_e('Form description', 'donateocean-donation-suite'); ?> <span class="donadosu-optional">(<?php esc_html_e('optional', 'donateocean-donation-suite'); ?>)</span></label>
                                <input id="donadosu-sc-description" type="text" value="<?php echo esc_attr($sc_default_description); ?>" data-donadosu-sc-default="<?php echo esc_attr($sc_default_description); ?>" />
                            </div>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-button-text"><?php esc_html_e('Button text', 'donateocean-donation-suite'); ?> <span class="donadosu-optional">(<?php esc_html_e('optional', 'donateocean-donation-suite'); ?>)</span></label>
                                <input id="donadosu-sc-button-text" type="text" value="<?php echo esc_attr($sc_default_button_text); ?>" data-donadosu-sc-default="<?php echo esc_attr($sc_default_button_text); ?>" />
                            </div>
                            <div class="donadosu-sc-field donadosu-sc-field--accent">
                                <label for="donadosu-sc-button-color-text"><?php esc_html_e('Form accent color', 'donateocean-donation-suite'); ?> <span class="donadosu-optional">(<?php esc_html_e('optional', 'donateocean-donation-suite'); ?>)</span> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Color used on the donation type tabs and amount buttons.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <div class="donadosu-sc-color-row">
                                    <input id="donadosu-sc-button-color-picker" type="color" value="#0070ba" title="<?php esc_attr_e('Pick a color', 'donateocean-donation-suite'); ?>" />
                                    <input id="donadosu-sc-button-color-text" type="text" placeholder="e.g. #0070ba" maxlength="7" class="donadosu-sc-color-text" />
                                    <button type="button" id="donadosu-sc-button-color-clear" class="button donadosu-sc-clear-btn"><?php esc_html_e('Clear', 'donateocean-donation-suite'); ?></button>
                                </div>
                            </div>
                            <div class="donadosu-sc-field donadosu-sc-field--checkbox donadosu-sc-field--full">
                                <label><input id="donadosu-sc-donor-fields" type="checkbox" checked /> <?php esc_html_e('Show donor details form', 'donateocean-donation-suite'); ?></label>
                                <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Uncheck to hide name, contact, address, message, and tribute fields.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span>
                            </div>
                        </div>
                    </div>

                    <!-- Group: Campaign & Purpose -->
                    <div class="donadosu-sc-group">
                        <h3 class="donadosu-sc-group__title"><?php esc_html_e('Campaign & Purpose', 'donateocean-donation-suite'); ?></h3>
                        <div class="donadosu-sc-field donadosu-sc-field--checkbox donadosu-sc-group__toggle">
                            <label><input id="donadosu-sc-enable-campaign" type="checkbox" /> <?php esc_html_e('Set a fundraising campaign', 'donateocean-donation-suite'); ?></label>
                            <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Show campaign name and purpose on the form. Uncheck to hide all campaign fields.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span>
                        </div>
                        <div class="donadosu-sc-fields" id="donadosu-sc-campaign-fields" hidden>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-campaign"><?php esc_html_e('Campaign name', 'donateocean-donation-suite'); ?></label>
                                <input id="donadosu-sc-campaign" type="text" placeholder="e.g. Emergency Relief" />
                            </div>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-purpose"><?php esc_html_e('Purpose', 'donateocean-donation-suite'); ?></label>
                                <input id="donadosu-sc-purpose" type="text" placeholder="e.g. Building fund" />
                            </div>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-campaign-start"><?php esc_html_e('Campaign start date', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Donations are blocked before this date.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <input id="donadosu-sc-campaign-start" type="date" />
                            </div>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-campaign-end"><?php esc_html_e('Campaign end date', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Donations are blocked after this date.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <input id="donadosu-sc-campaign-end" type="date" />
                            </div>
                        </div>
                    </div>

                    <!-- Group: Fundraising Goal -->
                    <div class="donadosu-sc-group">
                        <h3 class="donadosu-sc-group__title"><?php esc_html_e('Fundraising Goal', 'donateocean-donation-suite'); ?></h3>
                        <div class="donadosu-sc-field donadosu-sc-field--checkbox donadosu-sc-group__toggle">
                            <label><input id="donadosu-sc-enable-goal" type="checkbox" /> <?php esc_html_e('Set a fundraising goal', 'donateocean-donation-suite'); ?></label>
                            <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Show a progress bar on the form. Uncheck to hide all goal fields.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span>
                        </div>
                        <div class="donadosu-sc-fields" id="donadosu-sc-goal-fields" hidden>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-goal"><?php esc_html_e('Goal amount', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Target fundraising amount. Leave blank to hide the progress bar.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <input id="donadosu-sc-goal" type="number" min="0" step="1" placeholder="e.g. 50000" />
                            </div>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-goal-current-mode"><?php esc_html_e('Current progress', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('"Auto" uses the running total of donations for this campaign.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <select id="donadosu-sc-goal-current-mode">
                                    <option value=""><?php esc_html_e('None / start at zero', 'donateocean-donation-suite'); ?></option>
                                    <option value="auto"><?php esc_html_e('Auto — pull from database', 'donateocean-donation-suite'); ?></option>
                                    <option value="custom"><?php esc_html_e('Custom amount', 'donateocean-donation-suite'); ?></option>
                                </select>
                            </div>
                            <div class="donadosu-sc-field" id="donadosu-sc-goal-current-custom-wrap" hidden>
                                <label for="donadosu-sc-goal-current-custom"><?php esc_html_e('Custom current amount', 'donateocean-donation-suite'); ?></label>
                                <input id="donadosu-sc-goal-current-custom" type="number" min="0" step="1" placeholder="e.g. 12500" />
                            </div>
                            <?php $sc_default_goal_label = __('Campaign progress', 'donateocean-donation-suite'); ?>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-goal-label"><?php esc_html_e('Progress bar label', 'donateocean-donation-suite'); ?> <span class="donadosu-optional">(<?php esc_html_e('optional', 'donateocean-donation-suite'); ?>)</span></label>
                                <input id="donadosu-sc-goal-label" type="text" value="<?php echo esc_attr($sc_default_goal_label); ?>" data-donadosu-sc-default="<?php echo esc_attr($sc_default_goal_label); ?>" />
                            </div>
                            <div class="donadosu-sc-field donadosu-sc-field--checkbox">
                                <label><input id="donadosu-sc-goal-close" type="checkbox" /> <?php esc_html_e('Auto-close when goal is reached', 'donateocean-donation-suite'); ?></label>
                                <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Stops accepting donations once the goal amount is met.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span>
                            </div>
                        </div>
                    </div>

                    <!-- Group: After Donation (single field — title omitted) -->
                    <div class="donadosu-sc-group">
                        <div class="donadosu-sc-fields">
                            <div class="donadosu-sc-field donadosu-sc-field--full">
                                <label for="donadosu-sc-thankyou-url"><?php esc_html_e('Thank-you page URL', 'donateocean-donation-suite'); ?> <span class="donadosu-optional">(<?php esc_html_e('optional', 'donateocean-donation-suite'); ?>)</span> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Donors are redirected here after a successful payment. Leave blank to stay on the same page.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <input id="donadosu-sc-thankyou-url" type="url" placeholder="https://example.com/thank-you" />
                            </div>
                        </div>
                    </div>

                    <!-- Group: Advanced -->
                    <div class="donadosu-sc-group">
                        <h3 class="donadosu-sc-group__title"><?php esc_html_e('Advanced Overrides', 'donateocean-donation-suite'); ?></h3>
                        <div class="donadosu-sc-field donadosu-sc-field--checkbox donadosu-sc-group__toggle">
                            <label><input id="donadosu-sc-enable-advanced" type="checkbox" /> <?php esc_html_e('Set advanced overrides', 'donateocean-donation-suite'); ?></label>
                            <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Set currency, amounts, and fee coverage for this form only. Uncheck to use the site-wide defaults.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span>
                        </div>
                        <div id="donadosu-sc-advanced-fields" hidden>
                        <p class="description"><?php esc_html_e('These override the global Donation Experience defaults for this specific form only. Leave blank to use site-wide settings.', 'donateocean-donation-suite'); ?></p>
                        <div class="donadosu-sc-fields">
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-currency"><?php esc_html_e('Currency override', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('3-letter ISO code. Leave blank to use the site default.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <input id="donadosu-sc-currency" type="text" maxlength="3" placeholder="e.g. EUR" class="donadosu-sc-uppercase" />
                            </div>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-locale"><?php esc_html_e('Locale override', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Sets the language PayPal shows to donors on this form. Use a code like en, en_US, or fr_FR. Invalid codes are ignored.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <input id="donadosu-sc-locale" type="text" placeholder="e.g. fr_FR" pattern="[a-z]{2,3}(_[A-Z]{2})?" />
                            </div>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-amounts"><?php esc_html_e('Preset amounts', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Comma-separated quick-pick amounts. Replaces the site default for this form.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <input id="donadosu-sc-amounts" type="text" placeholder="e.g. 10,25,50,100" />
                            </div>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-min-amount"><?php esc_html_e('Minimum amount', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Leave blank to use the global setting.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <input id="donadosu-sc-min-amount" type="number" min="0.5" step="0.01" placeholder="e.g. 5" />
                            </div>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-max-amount"><?php esc_html_e('Maximum amount', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Leave blank to use the global setting.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <input id="donadosu-sc-max-amount" type="number" min="1" step="0.01" placeholder="e.g. 10000" />
                            </div>
                            <div class="donadosu-sc-field donadosu-sc-field--checkbox">
                                <label><input id="donadosu-sc-fee-coverage" type="checkbox" /> <?php esc_html_e('Show fee coverage option', 'donateocean-donation-suite'); ?></label>
                                <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Let donors opt-in to cover the transaction fee on this form.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span>
                            </div>
                            <div class="donadosu-sc-field">
                                <label for="donadosu-sc-css-class"><?php esc_html_e('Custom CSS class', 'donateocean-donation-suite'); ?> <span class="donadosu-tooltip" tabindex="0" aria-label="<?php esc_attr_e('Extra CSS class added to the form for your own styling.', 'donateocean-donation-suite'); ?>"><span class="donadosu-tooltip__icon" aria-hidden="true">?</span></span></label>
                                <input id="donadosu-sc-css-class" type="text" placeholder="e.g. my-campaign-form" />
                            </div>
                        </div>
                        </div><!-- /#donadosu-sc-advanced-fields -->
                    </div>

                </div>
            </section>
            <?php endif; ?>

            <div class="donadosu-actions-bar">
                <?php if (! $isToolTab) : ?>
                    <?php if ($previousTab !== null) : ?>
                        <a
                            class="button donadosu-button-back"
                            href="<?php echo esc_url(add_query_arg(['page' => 'donadosu-settings', 'tab' => $previousTab], admin_url('admin.php'))); ?>"
                            aria-label="Go to previous setup step"
                        >
                            <span class="donadosu-button-back__icon" aria-hidden="true">←</span>
                            <span class="donadosu-button-back__label"><?php esc_html_e('Previous step', 'donateocean-donation-suite'); ?></span>
                        </a>
                    <?php endif; ?>

                    <?php submit_button(__('Save Settings', 'donateocean-donation-suite'), 'secondary', 'submit', false); ?>

                    <?php if ($nextTab !== null) : ?>
                        <button type="submit" id="donadosu-next-step" class="button button-primary" data-next-tab="<?php echo esc_attr($nextTab); ?>"><?php esc_html_e('Save & Continue →', 'donateocean-donation-suite'); ?></button>
                    <?php else : ?>
                        <button type="submit" id="donadosu-finish-setup" class="button button-primary" data-finish-target="<?php echo esc_attr(admin_url('edit.php?post_type=donadosu_donation')); ?>"><?php esc_html_e('Finish setup ✓', 'donateocean-donation-suite'); ?></button>
                    <?php endif; ?>
                <?php elseif ($activeTab === 'shortcode') : ?>
                    <div class="donadosu-sc-output">
                        <label class="donadosu-sc-output__label" for="donadosu-sc-output"><?php esc_html_e('Generated shortcode', 'donateocean-donation-suite'); ?></label>
                        <div class="donadosu-copy-field">
                            <input id="donadosu-sc-output" type="text" class="regular-text code" readonly aria-label="<?php esc_attr_e('Generated shortcode', 'donateocean-donation-suite'); ?>" value="[donadosu_donation]" />
                            <button type="button" class="button donadosu-copy-btn" data-donadosu-copy="donadosu-sc-output"><?php esc_html_e('Copy', 'donateocean-donation-suite'); ?></button>
                        </div>
                        <p class="description"><?php esc_html_e('No save needed — copy this shortcode and paste it into any page or post.', 'donateocean-donation-suite'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            </div>
        </div>
    </form>
</div>
