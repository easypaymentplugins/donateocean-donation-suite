<?php
/**
 * Configuration service.
 *
 * Provides a centralised interface for reading plugin settings stored in
 * wp_options. All PayPal credentials, feature flags, and frontend
 * configuration values are accessed through this class.
 *
 * @package    Donation_Suite
 * @subpackage Core
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConfigService
 *
 * Reads and normalises Donation Suite settings from the database.
 *
 * @since 1.0.0
 */
class ConfigService {

	/**
	 * The wp_options key under which all plugin settings are stored.
	 *
	 * Uses a vendor-qualified prefix ("donadosu_") to guarantee
	 * uniqueness in the shared wp_options namespace and prevent collisions
	 * with other plugins.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const OPTION_KEY = 'donadosu_settings';

	/**
	 * Retrieve all plugin settings merged with sensible defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Associative array of settings.
	 */
	public function get_all(): array {
		$defaults = array(
			'sandbox'              => 1,
			'sandbox_client_id'    => '',
			'sandbox_secret'       => '',
			'sandbox_webhook_id'       => '',
			'sandbox_connected_email'  => '',
			'live_client_id'           => '',
			'live_secret'              => '',
			'live_webhook_id'          => '',
			'live_connected_email'     => '',
			'currency'             => 'USD',
			'allowed_currencies'   => array( 'USD' ),
			'custom_amount'        => 1,
			'min_amount'           => 1,
			'max_amount'           => 100000,
			'preset_amounts'       => '10,25,50,100',
			'enable_logging'       => 0,
			'logging_level'        => 'error',
			'retention_months'     => 24,
			'store_raw_payload'    => 0,
			'cleanup_on_uninstall' => 0,
			'charity_name'         => '',
			'charity_address'      => '',
			'reg_id'               => '',
			'contact_email'        => '',
			'tax_disclaimer'       => 'No goods or services were provided in exchange for this donation.',
			'privacy_url'          => '',
			'refund_url'           => '',
			// Feature 1: Recurring donations.
			'enable_recurring'     => 1,
			// Feature 2: Fee coverage.
			'enable_fee_coverage'           => 0,
			'fee_percentage'                => 2.9,
			'fee_coverage_default_checked'  => 0,
			// PayPal Advanced Credit and Debit Card Payments.
			'enable_paypal_card_fields' => 0,
			// Feature 7: Giving levels.
			'giving_levels_json'   => '',
			// Feature 10: Fraud detection.
			'fraud_flag_threshold' => 5000,
			'fraud_max_per_email'  => 5,
			// Integrations: Google Analytics / Tag Manager.
			'ga_measurement_id'    => '',
			'gtm_container_id'     => '',
			'ga_enable_tracking'   => 0,
			'ga_push_events'       => 0,
			// Integrations: Mailchimp.
			'mailchimp_api_key'        => '',
			'mailchimp_list_id'        => '',
			'mailchimp_auto_subscribe' => 0,
			'mailchimp_double_optin'   => 0,
			// Integrations: Constant Contact.
			'cc_api_key'        => '',
			'cc_list_id'        => '',
			'cc_auto_subscribe' => 0,
			// Integrations: Zapier.
			'zapier_enabled'       => 0,
			'zapier_webhook_url'   => '',
			'zapier_secret_key'    => '',
			'zapier_on_completed'  => 1,
			'zapier_on_refunded'   => 1,
			'zapier_on_disputed'   => 1,
			// Integrations: Slack.
			'slack_enabled'        => 0,
			'slack_webhook_url'    => '',
			'slack_channel'        => '',
			'slack_on_completed'   => 1,
			'slack_on_refunded'    => 1,
			'slack_on_disputed'    => 1,
			// Integrations: Twilio SMS.
			'twilio_enabled'       => 0,
			'twilio_account_sid'   => '',
			'twilio_auth_token'    => '',
			'twilio_from_number'   => '',
			'twilio_to_number'     => '',
			'twilio_on_completed'  => 1,
			'twilio_on_refunded'   => 1,
			'twilio_on_disputed'   => 1,
			// Integrations: ActiveCampaign.
			'ac_api_url'           => '',
			'ac_api_key'           => '',
			'ac_list_id'           => '',
			'ac_auto_subscribe'    => 0,
			// Integrations: Brevo (Sendinblue).
			'brevo_api_key'        => '',
			'brevo_list_id'        => '',
			'brevo_auto_subscribe' => 0,
			'brevo_double_optin'   => 0,
			// Integrations: Google Sheets.
			'gsheets_enabled'          => 0,
			'gsheets_spreadsheet_id'   => '',
			'gsheets_sheet_name'       => 'Sheet1',
			'gsheets_credentials_json' => '',
		);

		return wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), $defaults );
	}

	/**
	 * Check whether the plugin is currently running in sandbox mode.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if sandbox mode is active.
	 */
	public function is_sandbox(): bool {
		return ! empty( $this->get_all()['sandbox'] );
	}

	/**
	 * Get the active webhook ID for the current environment.
	 *
	 * @since 1.0.0
	 *
	 * @return string The PayPal webhook ID.
	 */
	public function get_webhook_id(): string {
		$settings = $this->get_all();
		return (string) ( $this->is_sandbox()
			? ( $settings['sandbox_webhook_id'] ?? '' )
			: ( $settings['live_webhook_id'] ?? '' )
		);
	}

	/**
	 * Get the active client ID for the current environment.
	 *
	 * @since 1.0.0
	 *
	 * @return string The PayPal client ID.
	 */
	public function get_client_id(): string {
		$settings = $this->get_all();
		return (string) ( $this->is_sandbox()
			? ( $settings['sandbox_client_id'] ?? '' )
			: ( $settings['live_client_id'] ?? '' )
		);
	}

	/**
	 * Get the active client secret for the current environment.
	 *
	 * @since 1.0.0
	 *
	 * @return string The PayPal client secret.
	 */
	public function get_secret(): string {
		$settings = $this->get_all();
		return (string) ( $this->is_sandbox()
			? ( $settings['sandbox_secret'] ?? '' )
			: ( $settings['live_secret'] ?? '' )
		);
	}

	/**
	 * Get the PayPal API base URL for the current environment.
	 *
	 * @since 1.0.0
	 *
	 * @return string The PayPal API base URL.
	 */
	public function get_base_url(): string {
		return $this->is_sandbox()
			? 'https://api-m.sandbox.paypal.com'
			: 'https://api-m.paypal.com';
	}

	/**
	 * Build the configuration array passed to the frontend JavaScript.
	 *
	 * Includes client ID, currency, preset amounts, feature flags, and
	 * parsed giving levels.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Frontend configuration values.
	 */
	public function get_frontend_config(): array {
		$settings = $this->get_all();

		// Feature 7: Parse giving levels JSON into an array of {amount, label, description}.
		$giving_levels = array();
		$levels_json   = trim( (string) ( $settings['giving_levels_json'] ?? '' ) );
		if ( '' !== $levels_json ) {
			$decoded = json_decode( $levels_json, true );
			if ( is_array( $decoded ) ) {
				// Normalise: single object wraps in array so JS always gets [].
				if ( isset( $decoded['amount'] ) ) {
					$decoded = array( $decoded );
				}
				$giving_levels = array_values( $decoded );
			}
		}

		return array(
			'clientId'            => $this->get_client_id(),
			'currency'            => sanitize_text_field( (string) ( $settings['currency'] ?? 'USD' ) ),
			'allowedCurrencies'   => array_values( array_filter( (array) $settings['allowed_currencies'] ) ),
			'customAmountEnabled' => ! empty( $settings['custom_amount'] ),
			'minAmount'           => (float) $settings['min_amount'],
			'maxAmount'           => (float) $settings['max_amount'],
			'presetAmounts'       => array_values( array_map(
				'floatval',
				array_filter(
					array_map( 'trim', explode( ',', (string) $settings['preset_amounts'] ) ),
					static fn( $v ) => is_numeric( $v ) && (float) $v > 0
				)
			) ),
			'locale'              => determine_locale(),
			// Feature 1: Recurring donations.
			'recurringEnabled'    => ! empty( $settings['enable_recurring'] ),
			// Feature 2: Fee coverage.
			'feeCoverageEnabled'        => ! empty( $settings['enable_fee_coverage'] ),
			'feePercentage'             => (float) ( $settings['fee_percentage'] ?? 2.9 ),
			'feeCoverageDefaultChecked' => ! empty( $settings['fee_coverage_default_checked'] ),
			// PayPal Advanced Credit and Debit Card Payments.
			'cardFieldsEnabled'   => ! empty( $settings['enable_paypal_card_fields'] ),
			// Feature 7: Giving levels.
			'givingLevels'        => $giving_levels,
		);
	}

	/**
	 * Check whether logging is enabled for a given severity level.
	 *
	 * Compares the requested level against the configured minimum logging
	 * level. Returns true when the requested level has equal or greater weight.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level The severity level to check (debug, info, warn, error).
	 * @return bool Whether logging is enabled for the given level.
	 */
	public function logging_enabled_for( string $level ): bool {
		$levels           = array(
			'debug' => 10,
			'info'  => 20,
			'warn'  => 30,
			'error' => 40,
		);
		$configured       = strtolower( (string) ( $this->get_all()['logging_level'] ?? 'error' ) );
		$configured_weight = $levels[ $configured ] ?? $levels['error'];
		$current_weight    = $levels[ strtolower( $level ) ] ?? $levels['error'];

		return $current_weight >= $configured_weight;
	}

	/**
	 * Get the tax receipt statement / disclaimer text.
	 *
	 * @since 1.0.0
	 *
	 * @return string The receipt statement text.
	 */
	public function get_receipt_statement(): string {
		$settings = $this->get_all();
		return (string) ( $settings['tax_disclaimer'] ?? 'No goods or services were provided in exchange for this donation.' );
	}
}
