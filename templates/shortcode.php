<?php
/**
 * Frontend donation form template.
 *
 * Available variables (set by Shortcode::render()):
 *   $atts   – sanitised shortcode attributes
 *   $this   – Shortcode instance (gives access to $this->config)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables set by including controller.

$_settings   = $this->config->get_all();
$_privacyUrl = esc_url((string) ($_settings['privacy_url'] ?? ''));
$_refundUrl  = esc_url((string) ($_settings['refund_url']  ?? ''));
$_cfg        = array(
	'apiBase'  => esc_url_raw( rest_url( 'donadosu/v1' ) ),
	'nonce'    => wp_create_nonce( 'wp_rest' ),
	'defaults' => $this->config->get_frontend_config(),
	'atts'     => $atts,
);

// Resolve the form's currency once so the goal panel, preset buttons, and
// amount label all show the same symbol. Mirrors the JS map in assets/js/donate.js.
$_currency_code       = strtoupper( (string) ( '' !== (string) $atts['currency'] ? $atts['currency'] : ( $_cfg['defaults']['currency'] ?? 'USD' ) ) );
$_currency_symbol_map = array(
	'USD' => '$',  'EUR' => '€',  'GBP' => '£',  'JPY' => '¥',  'CNY' => '¥',
	'CAD' => 'CA$','AUD' => 'A$', 'CHF' => 'CHF','INR' => '₹',  'KRW' => '₩',
	'BRL' => 'R$', 'MXN' => 'MX$','SGD' => 'S$', 'HKD' => 'HK$','NZD' => 'NZ$',
	'SEK' => 'kr', 'NOK' => 'kr', 'DKK' => 'kr', 'ZAR' => 'R',  'PLN' => 'zł',
	'THB' => '฿',  'IDR' => 'Rp', 'MYR' => 'RM', 'PHP' => '₱',  'CZK' => 'Kč',
	'ILS' => '₪',  'TWD' => 'NT$','TRY' => '₺',  'RUB' => '₽',  'HUF' => 'Ft',
);
$_currency_symbol = $_currency_symbol_map[ $_currency_code ] ?? $_currency_code;
?>
<?php
$_display_classes = 'donadosu-display donadosu-display--' . $atts['display_mode'];
if ( '' !== trim( (string) $atts['css_class'] ) ) {
  $_display_classes .= ' ' . trim( (string) $atts['css_class'] );
}
?>
<div class="<?php echo esc_attr( $_display_classes ); ?>" data-donadosu-display="<?php echo esc_attr($atts['display_mode']); ?>"<?php if ( '' !== $atts['button_color'] ) : ?> style="--donadosu-accent: <?php echo esc_attr( $atts['button_color'] ); ?>;"<?php endif; ?>>

  <?php if ($atts['display_mode'] === 'modal' && '' !== trim($atts['button_text'])) : ?>
  <button type="button" id="donadosu-open-modal" class="donadosu-open-modal" aria-haspopup="dialog">
    <?php echo esc_html($atts['button_text']); ?>
  </button>
  <?php endif; ?>

  <?php if (! empty($atts['campaign_closed'])) : ?>
  <!-- Feature 8: Campaign is closed / not yet open -->
  <div class="donadosu-wrap donadosu-wrap--closed">
    <div class="donadosu-campaign-closed">
      <p><?php echo esc_html($atts['campaign_message']); ?></p>
    </div>
  </div>
  <?php else : ?>

  <div
    class="donadosu-wrap"
    data-donadosu
    role="<?php echo esc_attr( $atts['display_mode'] === 'modal' ? 'dialog' : 'region' ); ?>"
    aria-label="<?php esc_attr_e('Donation form', 'donateocean-donation-suite'); ?>"
    <?php if ($atts['display_mode'] === 'modal') : ?>aria-modal="true"<?php endif; ?>
    data-donor-fields-enabled="<?php echo esc_attr($atts['donor_fields']); ?>"
    data-thank-you-url="<?php echo esc_url($atts['thank_you_url']); ?>"
    data-redirect-on-success="<?php echo esc_attr($atts['redirect_on_success']); ?>"
    data-donation-mode="<?php echo esc_attr($atts['donation_mode']); ?>"
    data-button-color="<?php echo esc_attr($atts['button_color']); ?>"
    data-donadosu-config="<?php echo esc_attr( wp_json_encode( $_cfg ) ); ?>"
  >

    <?php if ($atts['display_mode'] === 'modal') : ?>
    <button type="button" id="donadosu-close-modal" class="donadosu-modal-close" aria-label="<?php esc_attr_e('Close donation form', 'donateocean-donation-suite'); ?>">&#x2715;</button>
    <?php endif; ?>

    <div class="donadosu-form-header">
      <?php if ('' !== trim($atts['title'])) : ?><h3><?php echo esc_html($atts['title']); ?></h3><?php endif; ?>
      <?php if ('' !== trim($atts['description'])) : ?><p><?php echo esc_html($atts['description']); ?></p><?php endif; ?>
    </div>

    <?php if ($atts['goal_amount'] > 0) : ?>
      <?php
      $goalProgressRaw = $atts['goal_amount'] > 0 ? ($atts['goal_current'] / $atts['goal_amount']) * 100 : 0;
      $goalProgress    = max(0, min(100, round($goalProgressRaw, 1)));
      ?>
      <section class="donadosu-panel donadosu-goal" aria-label="<?php esc_attr_e('Donation goal', 'donateocean-donation-suite'); ?>">
        <div class="donadosu-goal__header">
          <?php if ('' !== trim($atts['goal_label'])) : ?>
          <h4 class="donadosu-panel__title"><?php echo esc_html($atts['goal_label']); ?></h4>
          <?php endif; ?>
          <strong><?php echo esc_html(number_format($goalProgress, 1)); ?>%</strong>
        </div>
        <div class="donadosu-goal__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $goalProgress); ?>">
          <span class="donadosu-goal__value" style="width: <?php echo esc_attr((string) $goalProgress); ?>%;"></span>
        </div>
        <p class="donadosu-panel__help">
          <?php
          printf(
            /* translators: 1: amount raised (with currency symbol), 2: goal amount (with currency symbol) */
            esc_html__('%1$s raised of %2$s.', 'donateocean-donation-suite'),
            esc_html( $_currency_symbol . number_format($atts['goal_current'], 2) ),
            esc_html( $_currency_symbol . number_format($atts['goal_amount'], 2) )
          );
          ?>
        </p>
      </section>
    <?php endif; ?>

    <!-- Step 1: Amount & frequency -->
    <section class="donadosu-panel" aria-labelledby="donadosu-step-1-title">
      <h4 id="donadosu-step-1-title" class="donadosu-panel__title"><?php esc_html_e('Choose amount', 'donateocean-donation-suite'); ?></h4>

      <?php if (in_array($atts['donation_mode'], ['both', 'monthly', 'annual'], true) && ! empty($_cfg['defaults']['recurringEnabled'])) : ?>
      <!-- Feature 1: Frequency toggle -->
      <div id="donadosu-frequency-group" class="donadosu-frequency-group" role="group" aria-label="<?php esc_attr_e('Donation frequency', 'donateocean-donation-suite'); ?>">
        <?php if (in_array($atts['donation_mode'], ['both', 'one_time'], true)) : ?>
        <button type="button" class="donadosu-freq-btn donadosu-freq-btn--active" data-frequency="one_time" aria-pressed="true"><?php esc_html_e('One-time', 'donateocean-donation-suite'); ?></button>
        <?php endif; ?>
        <?php if (in_array($atts['donation_mode'], ['both', 'monthly'], true)) : ?>
        <button type="button" class="donadosu-freq-btn<?php echo esc_attr( $atts['donation_mode'] === 'monthly' ? ' donadosu-freq-btn--active' : '' ); ?>" data-frequency="monthly" aria-pressed="<?php echo esc_attr( $atts['donation_mode'] === 'monthly' ? 'true' : 'false' ); ?>"><?php esc_html_e('Monthly', 'donateocean-donation-suite'); ?></button>
        <?php endif; ?>
        <?php if (in_array($atts['donation_mode'], ['both', 'annual'], true)) : ?>
        <button type="button" class="donadosu-freq-btn<?php echo esc_attr( $atts['donation_mode'] === 'annual' ? ' donadosu-freq-btn--active' : '' ); ?>" data-frequency="annual" aria-pressed="<?php echo esc_attr( $atts['donation_mode'] === 'annual' ? 'true' : 'false' ); ?>"><?php esc_html_e('Annual', 'donateocean-donation-suite'); ?></button>
        <?php endif; ?>
      </div>
      <input type="hidden" id="donadosu-donation-frequency" value="<?php echo esc_attr($atts['donation_mode'] === 'monthly' ? 'monthly' : ($atts['donation_mode'] === 'annual' ? 'annual' : 'one_time')); ?>" />
      <?php else : ?>
      <input type="hidden" id="donadosu-donation-frequency" value="one_time" />
      <?php endif; ?>

      <?php
      // Resolve preset amounts: per-shortcode/block "amounts" override the global preset list.
      $_atts_amounts_raw = trim( (string) ( $atts['amounts'] ?? '' ) );
      if ( '' !== $_atts_amounts_raw ) {
        $_preset_amounts = array_values( array_filter(
          array_map( 'floatval', array_map( 'trim', explode( ',', $_atts_amounts_raw ) ) ),
          static function ( $v ) { return $v > 0; }
        ) );
      } else {
        $_preset_amounts = is_array( $_cfg['defaults']['presetAmounts'] ?? null ) ? $_cfg['defaults']['presetAmounts'] : array();
      }
      $_giving_levels         = is_array( $_cfg['defaults']['givingLevels'] ?? null ) ? $_cfg['defaults']['givingLevels'] : array();
      $_custom_amount_enabled = ! empty( $_cfg['defaults']['customAmountEnabled'] );
      $_format_amount         = static function ( $v ) {
        $v = (float) $v;
        return ( fmod( $v, 1.0 ) === 0.0 ) ? (string) (int) $v : number_format( $v, 2, '.', '' );
      };
      ?>
      <div id="donadosu-preset-buttons" class="donadosu-preset-buttons" role="group" aria-label="<?php esc_attr_e('Suggested donation amounts', 'donateocean-donation-suite'); ?>">
        <?php if ( ! empty( $_giving_levels ) ) : ?>
          <?php foreach ( $_giving_levels as $_level ) :
            $_amt = (float) ( $_level['amount'] ?? 0 );
            if ( $_amt <= 0 ) { continue; }
            $_label   = (string) ( $_level['label'] ?? '' );
            $_display = $_currency_symbol . $_format_amount( $_amt );
          ?>
          <button type="button" class="donadosu-preset-btn donadosu-preset-btn--level" data-amount="<?php echo esc_attr( (string) $_amt ); ?>" data-level="<?php echo esc_attr( $_label ); ?>"<?php if ( ! empty( $_level['description'] ) ) : ?> title="<?php echo esc_attr( (string) $_level['description'] ); ?>"<?php endif; ?>>
            <strong><?php echo esc_html( '' !== $_label ? $_label : $_display ); ?></strong>
            <span class="donadosu-preset-btn__amount"><?php echo esc_html( $_display ); ?></span>
          </button>
          <?php endforeach; ?>
        <?php else : ?>
          <?php foreach ( $_preset_amounts as $_amt ) :
            $_amt = (float) $_amt;
            if ( $_amt <= 0 ) { continue; }
            $_display = $_currency_symbol . $_format_amount( $_amt );
          ?>
          <button type="button" class="donadosu-preset-btn" data-amount="<?php echo esc_attr( (string) $_amt ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: donation amount */ __( 'Donate %s', 'donateocean-donation-suite' ), $_display ) ); ?>"><?php echo esc_html( $_display ); ?></button>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php if ( $_custom_amount_enabled ) : ?>
          <button type="button" class="donadosu-preset-btn donadosu-preset-btn--custom" aria-label="<?php esc_attr_e( 'Enter your own donation amount', 'donateocean-donation-suite' ); ?>"><?php esc_html_e( 'Other amount', 'donateocean-donation-suite' ); ?></button>
        <?php endif; ?>
      </div>

      <?php
      $_allowed_currencies = ( ! empty( $_cfg['defaults']['allowedCurrencies'] ) && is_array( $_cfg['defaults']['allowedCurrencies'] ) )
        ? $_cfg['defaults']['allowedCurrencies']
        : array( (string) ( $_cfg['defaults']['currency'] ?? 'USD' ) );
      $_allowed_currencies   = array_values( array_filter( array_map( 'strval', $_allowed_currencies ), static function ( $c ) { return '' !== $c; } ) );
      $_selected_currency    = (string) ( '' !== (string) $atts['currency'] ? $atts['currency'] : ( $_cfg['defaults']['currency'] ?? ( $_allowed_currencies[0] ?? 'USD' ) ) );
      $_show_currency_select = count( $_allowed_currencies ) > 1;
      ?>
      <?php if ( $_show_currency_select ) : ?>
      <div class="donadosu-inline-grid">
        <div class="donadosu-field donadosu-field--amount">
          <label for="donadosu-amount"><?php esc_html_e('Amount', 'donateocean-donation-suite'); ?></label>
          <input id="donadosu-amount" type="number" min="1" step="0.01" aria-label="<?php esc_attr_e('Donation amount', 'donateocean-donation-suite'); ?>" placeholder="50.00" />
        </div>
        <div class="donadosu-field donadosu-field--currency">
          <label for="donadosu-currency"><?php esc_html_e('Currency', 'donateocean-donation-suite'); ?></label>
          <select id="donadosu-currency" aria-label="<?php esc_attr_e('Currency', 'donateocean-donation-suite'); ?>">
            <?php foreach ( $_allowed_currencies as $_code ) : ?>
              <option value="<?php echo esc_attr( $_code ); ?>" <?php selected( $_code, $_selected_currency ); ?>><?php echo esc_html( $_code ); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php else : ?>
      <div class="donadosu-field donadosu-field--amount">
        <label for="donadosu-amount">
          <?php
          /* translators: %s: ISO currency code, e.g. USD */
          printf( esc_html__('Amount (%s)', 'donateocean-donation-suite'), esc_html( $_currency_code ) );
          ?>
        </label>
        <input id="donadosu-amount" type="number" min="1" step="0.01" aria-label="<?php esc_attr_e('Donation amount', 'donateocean-donation-suite'); ?>" placeholder="50.00" />
      </div>
      <input type="hidden" id="donadosu-currency" value="<?php echo esc_attr( $_selected_currency ); ?>" />
      <?php endif; ?>
      <?php if ('' !== trim((string) $atts['campaign']) || '' !== trim((string) $atts['purpose'])) : ?>
      <div class="donadosu-inline-grid">
        <?php if ('' !== trim((string) $atts['campaign'])) : ?>
        <div class="donadosu-field">
          <label for="donadosu-campaign"><?php esc_html_e('Campaign / Fund', 'donateocean-donation-suite'); ?></label>
          <input id="donadosu-campaign" type="text" value="<?php echo esc_attr($atts['campaign']); ?>" readonly />
        </div>
        <?php endif; ?>
        <?php if ('' !== trim((string) $atts['purpose'])) : ?>
        <div class="donadosu-field">
          <label for="donadosu-purpose"><?php esc_html_e('Purpose', 'donateocean-donation-suite'); ?></label>
          <input id="donadosu-purpose" type="text" value="<?php echo esc_attr($atts['purpose']); ?>" readonly />
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- Step 2: Donor details -->
    <section id="donadosu-donor-panel" class="donadosu-panel" aria-labelledby="donadosu-step-2-title" <?php if ($atts['donor_fields'] !== '1') : ?>hidden<?php endif; ?>>
      <h4 id="donadosu-step-2-title" class="donadosu-panel__title"><?php esc_html_e('Donor details', 'donateocean-donation-suite'); ?></h4>
      <p class="donadosu-panel__help"><?php esc_html_e('Share your information for a personalised receipt and follow-up.', 'donateocean-donation-suite'); ?></p>
      <div class="donadosu-checkbox-row">
        <input type="checkbox" id="donadosu-donor-fields" <?php checked($atts['donor_fields'], '1'); ?> />
        <label for="donadosu-donor-fields"><?php esc_html_e('Add donor details', 'donateocean-donation-suite'); ?></label>
      </div>

      <div id="donadosu-donor-section" class="donadosu-donor-section">
        <div class="donadosu-inline-grid donadosu-inline-grid--donor">
          <div class="donadosu-field donadosu-field--name">
            <label for="donadosu-name"><?php esc_html_e('Full name', 'donateocean-donation-suite'); ?></label>
            <input id="donadosu-name" type="text" autocomplete="name" />
          </div>
          <div class="donadosu-field donadosu-field--email">
            <label for="donadosu-email"><?php esc_html_e('Email', 'donateocean-donation-suite'); ?></label>
            <input id="donadosu-email" type="email" autocomplete="email" />
          </div>
        </div>
        <div class="donadosu-inline-grid donadosu-inline-grid--donor">
          <div class="donadosu-field donadosu-field--phone">
            <label for="donadosu-phone"><?php esc_html_e('Phone', 'donateocean-donation-suite'); ?></label>
            <input id="donadosu-phone" type="tel" autocomplete="tel" />
          </div>
          <div class="donadosu-field donadosu-field--company">
            <label for="donadosu-company"><?php esc_html_e('Company / Organization', 'donateocean-donation-suite'); ?></label>
            <input id="donadosu-company" type="text" autocomplete="organization" />
          </div>
        </div>
        <div class="donadosu-field donadosu-field--address">
          <label for="donadosu-address"><?php esc_html_e('Street address', 'donateocean-donation-suite'); ?></label>
          <input id="donadosu-address" type="text" autocomplete="street-address" />
        </div>
        <div class="donadosu-inline-grid donadosu-inline-grid--donor">
          <div class="donadosu-field donadosu-field--city">
            <label for="donadosu-city"><?php esc_html_e('City', 'donateocean-donation-suite'); ?></label>
            <input id="donadosu-city" type="text" autocomplete="address-level2" />
          </div>
          <div class="donadosu-field donadosu-field--postal">
            <label for="donadosu-postal"><?php esc_html_e('Postal code', 'donateocean-donation-suite'); ?></label>
            <input id="donadosu-postal" type="text" autocomplete="postal-code" />
          </div>
        </div>
        <div class="donadosu-field donadosu-field--message">
          <label for="donadosu-message"><?php esc_html_e('Message (optional)', 'donateocean-donation-suite'); ?></label>
          <textarea id="donadosu-message"></textarea>
        </div>

        <!-- Feature 4: Anonymous donation -->
        <div class="donadosu-checkbox-row donadosu-checkbox-row--anonymous">
          <input type="checkbox" id="donadosu-anonymous" />
          <label for="donadosu-anonymous"><?php esc_html_e('Donate anonymously', 'donateocean-donation-suite'); ?></label>
        </div>

        <!-- Remember donor details for returning donors -->
        <div class="donadosu-checkbox-row donadosu-checkbox-row--remember">
          <input type="checkbox" id="donadosu-remember-donor" checked />
          <label for="donadosu-remember-donor"><?php esc_html_e('Save my details for faster checkout next time', 'donateocean-donation-suite'); ?></label>
        </div>

        <?php if ( ! empty( $atts['marketing_consent_show'] ) ) : ?>
        <!-- Marketing consent opt-in (GDPR): unchecked by default -->
        <div class="donadosu-checkbox-row donadosu-checkbox-row--marketing-consent">
          <input type="checkbox" id="donadosu-marketing-consent" />
          <label for="donadosu-marketing-consent"><?php echo esc_html( (string) $atts['marketing_consent_label'] ); ?></label>
        </div>
        <?php endif; ?>

        <!-- Feature 3: Tribute donation -->
        <div class="donadosu-checkbox-row">
          <input type="checkbox" id="donadosu-tribute-toggle" />
          <label for="donadosu-tribute-toggle"><?php esc_html_e('Make this a tribute donation', 'donateocean-donation-suite'); ?></label>
        </div>
        <div id="donadosu-tribute-section" class="donadosu-tribute-section" hidden>
          <div class="donadosu-inline-grid donadosu-inline-grid--donor">
            <div class="donadosu-field">
              <label for="donadosu-tribute-type"><?php esc_html_e('Tribute type', 'donateocean-donation-suite'); ?></label>
              <select id="donadosu-tribute-type">
                <option value="in_honor"><?php esc_html_e('In honor of', 'donateocean-donation-suite'); ?></option>
                <option value="in_memory"><?php esc_html_e('In memory of', 'donateocean-donation-suite'); ?></option>
              </select>
            </div>
            <div class="donadosu-field">
              <label for="donadosu-tribute-name"><?php esc_html_e('Honoree name', 'donateocean-donation-suite'); ?></label>
              <input id="donadosu-tribute-name" type="text" placeholder="<?php esc_attr_e('Full name', 'donateocean-donation-suite'); ?>" />
            </div>
          </div>
          <div class="donadosu-field">
            <label for="donadosu-tribute-notify"><?php esc_html_e('Notify family / friend (email, optional)', 'donateocean-donation-suite'); ?></label>
            <input id="donadosu-tribute-notify" type="email" placeholder="<?php esc_attr_e('family@example.com', 'donateocean-donation-suite'); ?>" />
          </div>
        </div>
      </div>
    </section>

    <!-- Step 2.5: Custom fields registered via donadosu_register_custom_fields hook -->
    <?php
    \DonationSuite\Core\CustomFieldsManager::init();
    $_custom_fields = \DonationSuite\Core\CustomFieldsManager::get_fields();
    ?>
    <?php if ( ! empty( $_custom_fields ) ) : ?>
    <section class="donadosu-panel" aria-labelledby="donadosu-step-custom-title">
      <h4 id="donadosu-step-custom-title" class="donadosu-panel__title"><?php esc_html_e('Additional information', 'donateocean-donation-suite'); ?></h4>
      <div id="donadosu-custom-fields" class="donadosu-custom-fields">
        <?php foreach ( $_custom_fields as $_field ) :
          $_fid          = (string) $_field['id'];
          $_ftype        = (string) $_field['type'];
          $_flabel       = (string) $_field['label'];
          $_fdesc        = (string) ( $_field['description'] ?? '' );
          $_frequired    = ! empty( $_field['required'] );
          $_fplaceholder = (string) ( $_field['placeholder'] ?? '' );
          $_fdefault     = (string) ( $_field['default'] ?? '' );
          $_foptions     = (array) ( $_field['options'] ?? array() );
          $_fdom_id      = 'donadosu-cf-' . $_fid;
        ?>

          <?php if ( 'hidden' === $_ftype ) : ?>
            <input type="hidden" id="<?php echo esc_attr( $_fdom_id ); ?>" name="<?php echo esc_attr( $_fid ); ?>" value="<?php echo esc_attr( $_fdefault ); ?>" />

          <?php elseif ( 'checkbox' === $_ftype ) : ?>
            <div class="donadosu-field donadosu-field--checkbox donadosu-field--custom">
              <div class="donadosu-checkbox-row">
                <input type="checkbox" id="<?php echo esc_attr( $_fdom_id ); ?>" name="<?php echo esc_attr( $_fid ); ?>" value="1" <?php checked( '1', $_fdefault ); ?> <?php if ( $_frequired ) : ?>required aria-required="true"<?php endif; ?> />
                <label for="<?php echo esc_attr( $_fdom_id ); ?>">
                  <?php echo esc_html( $_flabel ); ?><?php if ( $_frequired ) : ?> <span class="donadosu-required" aria-hidden="true">*</span><?php endif; ?>
                </label>
              </div>
              <?php if ( '' !== $_fdesc ) : ?><p class="donadosu-field__help"><?php echo esc_html( $_fdesc ); ?></p><?php endif; ?>
            </div>

          <?php elseif ( 'radio' === $_ftype ) : ?>
            <div class="donadosu-field donadosu-field--radio donadosu-field--custom" role="group" aria-labelledby="<?php echo esc_attr( $_fdom_id ); ?>-label">
              <span id="<?php echo esc_attr( $_fdom_id ); ?>-label" class="donadosu-field__label">
                <?php echo esc_html( $_flabel ); ?><?php if ( $_frequired ) : ?> <span class="donadosu-required" aria-hidden="true">*</span><?php endif; ?>
              </span>
              <div class="donadosu-radio-group">
                <?php foreach ( $_foptions as $_opt_key => $_opt_label ) :
                  $_opt_id = $_fdom_id . '-' . $_opt_key;
                ?>
                <div class="donadosu-radio-item">
                  <input type="radio" id="<?php echo esc_attr( $_opt_id ); ?>" name="<?php echo esc_attr( $_fid ); ?>" value="<?php echo esc_attr( $_opt_key ); ?>" <?php checked( (string) $_opt_key, $_fdefault ); ?> <?php if ( $_frequired ) : ?>required aria-required="true"<?php endif; ?> />
                  <label for="<?php echo esc_attr( $_opt_id ); ?>"><?php echo esc_html( $_opt_label ); ?></label>
                </div>
                <?php endforeach; ?>
              </div>
              <?php if ( '' !== $_fdesc ) : ?><p class="donadosu-field__help"><?php echo esc_html( $_fdesc ); ?></p><?php endif; ?>
            </div>

          <?php elseif ( 'select' === $_ftype ) : ?>
            <div class="donadosu-field donadosu-field--select donadosu-field--custom">
              <label for="<?php echo esc_attr( $_fdom_id ); ?>">
                <?php echo esc_html( $_flabel ); ?><?php if ( $_frequired ) : ?> <span class="donadosu-required" aria-hidden="true">*</span><?php endif; ?>
              </label>
              <select id="<?php echo esc_attr( $_fdom_id ); ?>" name="<?php echo esc_attr( $_fid ); ?>" <?php if ( $_frequired ) : ?>required aria-required="true"<?php endif; ?>>
                <option value=""><?php esc_html_e( 'Choose…', 'donateocean-donation-suite' ); ?></option>
                <?php foreach ( $_foptions as $_opt_key => $_opt_label ) : ?>
                <option value="<?php echo esc_attr( $_opt_key ); ?>" <?php selected( (string) $_opt_key, $_fdefault ); ?>><?php echo esc_html( $_opt_label ); ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ( '' !== $_fdesc ) : ?><p class="donadosu-field__help"><?php echo esc_html( $_fdesc ); ?></p><?php endif; ?>
            </div>

          <?php elseif ( 'textarea' === $_ftype ) : ?>
            <div class="donadosu-field donadosu-field--textarea donadosu-field--custom">
              <label for="<?php echo esc_attr( $_fdom_id ); ?>">
                <?php echo esc_html( $_flabel ); ?><?php if ( $_frequired ) : ?> <span class="donadosu-required" aria-hidden="true">*</span><?php endif; ?>
              </label>
              <textarea id="<?php echo esc_attr( $_fdom_id ); ?>" name="<?php echo esc_attr( $_fid ); ?>" placeholder="<?php echo esc_attr( $_fplaceholder ); ?>" <?php if ( $_frequired ) : ?>required aria-required="true"<?php endif; ?>><?php echo esc_textarea( $_fdefault ); ?></textarea>
              <?php if ( '' !== $_fdesc ) : ?><p class="donadosu-field__help"><?php echo esc_html( $_fdesc ); ?></p><?php endif; ?>
            </div>

          <?php else : // text, email, tel, number, url ?>
            <div class="donadosu-field donadosu-field--<?php echo esc_attr( $_ftype ); ?> donadosu-field--custom">
              <label for="<?php echo esc_attr( $_fdom_id ); ?>">
                <?php echo esc_html( $_flabel ); ?><?php if ( $_frequired ) : ?> <span class="donadosu-required" aria-hidden="true">*</span><?php endif; ?>
              </label>
              <input id="<?php echo esc_attr( $_fdom_id ); ?>" type="<?php echo esc_attr( $_ftype ); ?>" name="<?php echo esc_attr( $_fid ); ?>" value="<?php echo esc_attr( $_fdefault ); ?>" placeholder="<?php echo esc_attr( $_fplaceholder ); ?>" <?php if ( $_frequired ) : ?>required aria-required="true"<?php endif; ?> />
              <?php if ( '' !== $_fdesc ) : ?><p class="donadosu-field__help"><?php echo esc_html( $_fdesc ); ?></p><?php endif; ?>
            </div>
          <?php endif; ?>

        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <!-- Step 3: Confirm and pay -->
    <section class="donadosu-panel" aria-labelledby="donadosu-step-3-title">
      <h4 id="donadosu-step-3-title" class="donadosu-panel__title"><?php esc_html_e('Confirm and pay', 'donateocean-donation-suite'); ?></h4>
      <p class="donadosu-panel__help"><?php esc_html_e('Review your details and complete your donation.', 'donateocean-donation-suite'); ?></p>

      <div class="donadosu-summary" aria-live="polite">
        <div class="donadosu-summary__row">
          <span><?php esc_html_e('Amount', 'donateocean-donation-suite'); ?></span>
          <strong id="donadosu-summary-amount">—</strong>
        </div>
        <?php if (! empty($_cfg['defaults']['feeCoverageEnabled']) || $atts['fee_coverage'] === '1') : ?>
        <div class="donadosu-summary__row" id="donadosu-summary-fee-row" hidden>
          <span><?php esc_html_e('Transaction fee (covered)', 'donateocean-donation-suite'); ?></span>
          <strong id="donadosu-summary-fee">—</strong>
        </div>
        <div class="donadosu-summary__row" id="donadosu-summary-total-row" hidden>
          <span><?php esc_html_e('Total charged', 'donateocean-donation-suite'); ?></span>
          <strong id="donadosu-summary-total">—</strong>
        </div>
        <?php endif; ?>
        <div class="donadosu-summary__row">
          <span><?php esc_html_e('Donation type', 'donateocean-donation-suite'); ?></span>
          <strong id="donadosu-summary-frequency"><?php esc_html_e('One-time donation', 'donateocean-donation-suite'); ?></strong>
        </div>
        <?php if ($atts['donor_fields'] === '1') : ?>
        <div class="donadosu-summary__row" id="donadosu-summary-donor-row">
          <span><?php esc_html_e('Donor details', 'donateocean-donation-suite'); ?></span>
          <strong id="donadosu-summary-donor"><?php esc_html_e('Not included', 'donateocean-donation-suite'); ?></strong>
        </div>
        <?php endif; ?>
      </div>

      <?php if (! empty($_cfg['defaults']['feeCoverageEnabled']) || $atts['fee_coverage'] === '1') : ?>
      <!-- Feature 2: Fee coverage -->
      <div class="donadosu-checkbox-row donadosu-checkbox-row--fee">
        <input type="checkbox" id="donadosu-fee-coverage" />
        <label for="donadosu-fee-coverage" id="donadosu-fee-coverage-label">
          <?php esc_html_e('Cover the transaction fee', 'donateocean-donation-suite'); ?> — <span id="donadosu-fee-amount-label"></span>
        </label>
      </div>
      <?php endif; ?>

      <!-- Feature 10: Honeypot — hidden from real users, visible to bots -->
      <div style="position:absolute;left:-9999px;top:-9999px;visibility:hidden;" aria-hidden="true">
        <input type="text" id="donadosu-confirm-email" name="_confirm_email" tabindex="-1" autocomplete="off" value="" />
      </div>
    </section>

    <p id="donadosu-result" aria-live="assertive" aria-atomic="true"></p>

    <?php if ( ! empty( $_cfg['defaults']['cardFieldsEnabled'] ) ) : ?>
    <!-- Payment method selector: PayPal vs Card -->
    <div id="donadosu-payment-method" class="donadosu-payment-method" hidden>
      <div class="donadosu-payment-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Payment method', 'donateocean-donation-suite' ); ?>">
        <button type="button" class="donadosu-payment-tab donadosu-payment-tab--active" role="tab" aria-selected="true" aria-controls="donadosu-tab-paypal" id="donadosu-tab-btn-paypal" data-method="paypal">
          <svg class="donadosu-payment-tab__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 11l5-7"/><path d="M3 21h4l1.7-9.4A2 2 0 0 1 10.7 10H19a2 2 0 0 1 2 2.2L19.7 20a2 2 0 0 1-2 1.8H3"/><path d="M12 4h5a2 2 0 0 1 2 2.2L17.7 14"/></svg>
          <?php esc_html_e( 'PayPal', 'donateocean-donation-suite' ); ?>
        </button>
        <button type="button" class="donadosu-payment-tab" role="tab" aria-selected="false" aria-controls="donadosu-tab-card" id="donadosu-tab-btn-card" data-method="card">
          <svg class="donadosu-payment-tab__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          <?php esc_html_e( 'Credit / Debit Card', 'donateocean-donation-suite' ); ?>
        </button>
      </div>

      <!-- PayPal tab panel -->
      <div id="donadosu-tab-paypal" class="donadosu-payment-panel" role="tabpanel" aria-labelledby="donadosu-tab-btn-paypal">
        <div id="donadosu-paypal-button-container" class="donadosu-paypal-button-container"></div>
      </div>

      <!-- Card tab panel -->
      <div id="donadosu-tab-card" class="donadosu-payment-panel" role="tabpanel" aria-labelledby="donadosu-tab-btn-card" hidden>
        <div class="donadosu-card-fields-form">
          <div class="donadosu-card-field-wrapper">
            <label><?php esc_html_e( 'Card number', 'donateocean-donation-suite' ); ?></label>
            <div id="donadosu-card-number" class="donadosu-card-field"></div>
          </div>
          <div class="donadosu-card-fields-row">
            <div class="donadosu-card-field-wrapper donadosu-card-field-wrapper--half">
              <label><?php esc_html_e( 'Expiry', 'donateocean-donation-suite' ); ?></label>
              <div id="donadosu-card-expiry" class="donadosu-card-field"></div>
            </div>
            <div class="donadosu-card-field-wrapper donadosu-card-field-wrapper--half">
              <label><?php esc_html_e( 'CVV', 'donateocean-donation-suite' ); ?></label>
              <div id="donadosu-card-cvv" class="donadosu-card-field"></div>
            </div>
          </div>
          <div class="donadosu-card-field-wrapper">
            <label><?php esc_html_e( 'Name on card', 'donateocean-donation-suite' ); ?></label>
            <div id="donadosu-card-name" class="donadosu-card-field"></div>
          </div>
          <button type="button" id="donadosu-card-submit" class="donadosu-card-submit" disabled>
            <?php esc_html_e( 'Donate with Card', 'donateocean-donation-suite' ); ?>
          </button>
        </div>
      </div>
    </div>
    <?php else : ?>
    <!-- PayPal only (card fields not enabled) -->
    <div id="donadosu-paypal-button-container" class="donadosu-paypal-button-container"></div>
    <?php endif; ?>

    <?php if ($_privacyUrl || $_refundUrl) : ?>
    <p class="donadosu-footer-links">
      <?php if ($_privacyUrl) : ?>
        <a href="<?php echo esc_url( $_privacyUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Privacy policy', 'donateocean-donation-suite'); ?></a>
      <?php endif; ?>
      <?php if ($_privacyUrl && $_refundUrl) : ?>
        <span aria-hidden="true">&middot;</span>
      <?php endif; ?>
      <?php if ($_refundUrl) : ?>
        <a href="<?php echo esc_url( $_refundUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Refund policy', 'donateocean-donation-suite'); ?></a>
      <?php endif; ?>
    </p>
    <?php endif; ?>

    <div id="donadosu-thankyou" class="donadosu-thankyou" hidden aria-live="polite">
      <h3><?php esc_html_e('Thank you for your donation!', 'donateocean-donation-suite'); ?></h3>
      <p><?php esc_html_e('A receipt has been emailed to you. We appreciate your generous support.', 'donateocean-donation-suite'); ?></p>
      <?php if ($_privacyUrl || $_refundUrl) : ?>
      <p class="donadosu-footer-links">
        <?php if ($_privacyUrl) : ?>
          <a href="<?php echo esc_url( $_privacyUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Privacy policy', 'donateocean-donation-suite'); ?></a>
        <?php endif; ?>
        <?php if ($_privacyUrl && $_refundUrl) : ?>
          <span aria-hidden="true">&middot;</span>
        <?php endif; ?>
        <?php if ($_refundUrl) : ?>
          <a href="<?php echo esc_url( $_refundUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Refund policy', 'donateocean-donation-suite'); ?></a>
        <?php endif; ?>
      </p>
      <?php endif; ?>
    </div>

  </div><!-- .donadosu-wrap -->

  <?php endif; // campaign not closed ?>

</div><!-- .donadosu-display -->
