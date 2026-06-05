<?php
/**
 * Settings page for Donation Suite admin.
 *
 * Registers the top-level admin menu, settings fields, and AJAX handlers
 * for testing PayPal credentials and email delivery.
 *
 * @package    Donation_Suite
 * @subpackage Admin
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Admin;

use DonationSuite\Core\Capabilities;
use DonationSuite\Core\ConfigService;
use DonationSuite\PayPal\OAuthTokenCache;
use DonationSuite\PayPal\WebhookRegistrar;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsPage
 *
 * Manages the main Donation Suite settings page in the WordPress admin.
 *
 * @since 1.0.0
 */
class SettingsPage {

	/**
	 * Environment tab slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const TAB_ENVIRONMENT = 'environment';

	/**
	 * Experience tab slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const TAB_EXPERIENCE = 'experience';

	/**
	 * Compliance tab slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const TAB_COMPLIANCE = 'compliance';

	/**
	 * Advanced tab slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const TAB_ADVANCED = 'advanced';

	/**
	 * Shortcode tab slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const TAB_SHORTCODE = 'shortcode';

	/**
	 * Integrations tab slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const TAB_INTEGRATIONS = 'integrations';

	/**
	 * Option storing the keys of inline state banners the admin has dismissed.
	 *
	 * Holds an array of connection-state keys (e.g. 'disconnected',
	 * 'sandbox_active'); a key in the array means that banner stays hidden.
	 *
	 * @since 1.0.7
	 * @var string
	 */
	private const DISMISSED_NOTICES_OPTION = 'donadosu_dismissed_notices';

	/**
	 * Inline state banners that may be dismissed and persisted.
	 *
	 * @since 1.0.7
	 * @var string[]
	 */
	private const DISMISSIBLE_NOTICES = array( 'disconnected', 'sandbox_active' );

	/**
	 * Webhook registrar instance.
	 *
	 * @since 1.0.0
	 * @var WebhookRegistrar
	 */
	private WebhookRegistrar $webhook_registrar;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param WebhookRegistrar|null $webhook_registrar Optional webhook registrar instance.
	 * @return void
	 */
	public function __construct( ?WebhookRegistrar $webhook_registrar = null ) {
		$this->webhook_registrar = $webhook_registrar ?? new WebhookRegistrar();
	}

	/**
	 * Register hooks for the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_donadosu_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'wp_ajax_donadosu_send_test_email', array( $this, 'handle_test_email' ) );
		add_action( 'wp_ajax_donadosu_disconnect_paypal', array( $this, 'handle_disconnect' ) );
		add_action( 'wp_ajax_donadosu_dismiss_notice', array( $this, 'handle_dismiss_notice' ) );
		add_action( 'wp_ajax_donadosu_test_mailchimp', array( $this, 'handle_test_mailchimp' ) );
		add_action( 'wp_ajax_donadosu_test_zapier', array( $this, 'handle_test_zapier' ) );
		add_action( 'wp_ajax_donadosu_test_slack', array( $this, 'handle_test_slack' ) );
		add_action( 'wp_ajax_donadosu_test_twilio', array( $this, 'handle_test_twilio' ) );
		add_action( 'wp_ajax_donadosu_test_activecampaign', array( $this, 'handle_test_activecampaign' ) );
		add_action( 'wp_ajax_donadosu_test_brevo', array( $this, 'handle_test_brevo' ) );
		add_action( 'wp_ajax_donadosu_test_gsheets', array( $this, 'handle_test_gsheets' ) );
	}

	/**
	 * Enqueue admin styles and scripts on the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking current page for asset enqueue.
		if ( ! isset( $_GET['page'] ) || 'donadosu-settings' !== $_GET['page'] ) {
			return;
		}

		wp_enqueue_style(
			'donadosu-admin-settings',
			DONADOSU_URL . 'assets/css/admin-settings.css',
			array(),
			DONADOSU_VERSION
		);

		wp_enqueue_script(
			'donadosu-admin-settings',
			DONADOSU_URL . 'assets/js/admin-settings.js',
			array(),
			DONADOSU_VERSION,
			true
		);

		wp_localize_script(
			'donadosu-admin-settings',
			'donadosuAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'donadosu_admin_actions' ),
			)
		);
	}

	/**
	 * Register the top-level admin menu page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function menu(): void {
		// Use the donations CPT list as the top-level landing page.
		add_menu_page(
			__( 'Donation Suite', 'donateocean-donation-suite' ),
			__( 'Donation Suite', 'donateocean-donation-suite' ),
			Capabilities::VIEW_DONATIONS,
			'edit.php?post_type=donadosu_donation',
			'',
			'dashicons-heart',
			56
		);

		add_submenu_page(
			'edit.php?post_type=donadosu_donation',
			__( 'Settings', 'donateocean-donation-suite' ),
			__( 'Settings', 'donateocean-donation-suite' ),
			'manage_options',
			'donadosu-settings',
			array( $this, 'render' )
		);
	}

	/**
	 * Register the settings group.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function settings(): void {
		register_setting(
			ConfigService::OPTION_KEY,
			ConfigService::OPTION_KEY,
			array(
				'sanitize_callback' => array( $this, 'sanitize' ),
				// This option holds PayPal and integration secrets; never
				// autoload it into memory on every front-end request.
				// (The 'autoload' arg is honoured on WP 6.6+ and ignored
				// gracefully on older versions; the installer also flips the
				// flag via wp_set_option_autoload() for existing installs.)
				'autoload'          => false,
			)
		);
	}

	/**
	 * Sanitize and validate settings input.
	 *
	 * Handles tab-based field preservation so that saving one tab does not
	 * overwrite values from other tabs. Also validates PayPal credentials and
	 * registers webhooks when the environment tab is saved.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The raw settings input from the form.
	 * @return array Sanitized settings array.
	 */
	public function sanitize( array $input ): array {
		$existing_settings = (array) get_option( ConfigService::OPTION_KEY, array() );

		// When the UI submits the masked placeholder, keep the existing
		// (already-encrypted) value so a save-without-edit doesn't destroy secrets.
		$masked_placeholder = '••••••••';
		foreach ( array( 'sandbox_secret', 'live_secret', 'mailchimp_api_key', 'cc_client_secret', 'twilio_auth_token', 'ac_api_key', 'brevo_api_key', 'zapier_secret_key' ) as $secret_field ) {
			if ( isset( $input[ $secret_field ] ) && $masked_placeholder === $input[ $secret_field ] ) {
				$input[ $secret_field ] = ConfigService::decrypt_secret( (string) ( $existing_settings[ $secret_field ] ?? '' ) );
			}
		}

		$active_tab        = sanitize_text_field( (string) ( $input['_active_tab'] ?? '' ) );

		$environment_keys = array(
			'sandbox',
			'sandbox_client_id',
			'sandbox_secret',
			'sandbox_webhook_id',
			'sandbox_connected_email',
			'live_client_id',
			'live_secret',
			'live_webhook_id',
			'live_connected_email',
		);

		$experience_keys = array(
			'currency',
			'allowed_currencies_csv',
			'custom_amount',
			'min_amount',
			'max_amount',
			'preset_amounts',
			'enable_recurring',
			'enable_paypal_card_fields',
			'enable_fee_coverage',
			'fee_percentage',
			'fee_coverage_default_checked',
			'giving_levels_json',
		);

		$compliance_keys = array(
			'charity_name',
			'charity_address',
			'reg_id',
			'contact_email',
			'tax_disclaimer',
			'privacy_url',
			'refund_url',
			'retention_months',
			'store_raw_payload',
		);

		$advanced_keys = array(
			'fraud_flag_threshold',
			'fraud_max_per_email',
			'enable_logging',
			'logging_level',
			'cleanup_on_uninstall',
		);

		$integration_keys = array(
			'ga_measurement_id',
			'gtm_container_id',
			'ga_enable_tracking',
			'ga_push_events',
			'mailchimp_api_key',
			'mailchimp_list_id',
			'mailchimp_auto_subscribe',
			'mailchimp_double_optin',
			'cc_client_id',
			'cc_client_secret',
			'cc_list_id',
			'cc_auto_subscribe',
			'zapier_enabled',
			'zapier_webhook_url',
			'zapier_secret_key',
			'zapier_on_completed',
			'zapier_on_refunded',
			'zapier_on_disputed',
			'slack_enabled',
			'slack_webhook_url',
			'slack_channel',
			'slack_on_completed',
			'slack_on_refunded',
			'slack_on_disputed',
			'twilio_enabled',
			'twilio_account_sid',
			'twilio_auth_token',
			'twilio_from_number',
			'twilio_to_number',
			'twilio_on_completed',
			'twilio_on_refunded',
			'twilio_on_disputed',
			'ac_api_url',
			'ac_api_key',
			'ac_list_id',
			'ac_auto_subscribe',
			'brevo_api_key',
			'brevo_list_id',
			'brevo_auto_subscribe',
			'brevo_double_optin',
			'gsheets_enabled',
			'gsheets_spreadsheet_id',
			'gsheets_sheet_name',
			'gsheets_credentials_json',
		);

		$get_input_or_stored_value = static function ( string $key, $default = '' ) use ( $input, $existing_settings, $active_tab, $environment_keys, $experience_keys, $compliance_keys, $advanced_keys, $integration_keys ) {
			if ( '' === $active_tab ) {
				return $input[ $key ] ?? $default;
			}

			$tab_key_map = array(
				self::TAB_ENVIRONMENT  => $environment_keys,
				self::TAB_EXPERIENCE   => $experience_keys,
				self::TAB_COMPLIANCE   => $compliance_keys,
				self::TAB_ADVANCED     => $advanced_keys,
				self::TAB_INTEGRATIONS => $integration_keys,
			);

			$active_keys = $tab_key_map[ $active_tab ] ?? array();

			if ( ! in_array( $key, $active_keys, true ) ) {
				return $existing_settings[ $key ] ?? $default;
			}

			return $input[ $key ] ?? $default;
		};

		$default_currency = strtoupper( sanitize_text_field( (string) $get_input_or_stored_value( 'currency', $existing_settings['currency'] ?? 'USD' ) ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $default_currency ) ) {
			$default_currency = 'USD';
		}

		$allowed_currencies_csv = (string) $get_input_or_stored_value( 'allowed_currencies_csv', '' );
		if ( '' === $allowed_currencies_csv && ! empty( $existing_settings['allowed_currencies'] ) && self::TAB_EXPERIENCE !== $active_tab ) {
			$allowed_currencies_csv = implode( ',', array_map( 'strval', (array) $existing_settings['allowed_currencies'] ) );
		}

		$currencies = array_filter( array_map( 'trim', explode( ',', $allowed_currencies_csv ) ) );
		$currencies = array_values( array_filter( $currencies, static fn( $c ) => preg_match( '/^[A-Z]{3}$/', $c ) ) );

		$min_amount_input = trim( (string) $get_input_or_stored_value( 'min_amount', $existing_settings['min_amount'] ?? '' ) );
		$max_amount_input = trim( (string) $get_input_or_stored_value( 'max_amount', $existing_settings['max_amount'] ?? '' ) );

		$sanitized_min = '' === $min_amount_input ? 1.0 : max( 0.5, (float) $min_amount_input );
		$sanitized_max = '' === $max_amount_input ? 100000.0 : min( 999999.0, (float) $max_amount_input );

		// Reject saves where minimum is not strictly below maximum.
		if ( self::TAB_EXPERIENCE === $active_tab && $sanitized_min >= $sanitized_max ) {
			add_settings_error(
				ConfigService::OPTION_KEY,
				'donadosu_invalid_min_max',
				__( 'Minimum amount must be lower than the maximum amount. Values were not saved.', 'donateocean-donation-suite' ),
				'error'
			);
			$sanitized_min = (float) ( $existing_settings['min_amount'] ?? 1 );
			$sanitized_max = (float) ( $existing_settings['max_amount'] ?? 100000 );
		}

		$settings = array(
			'sandbox'                  => empty( $get_input_or_stored_value( 'sandbox', 1 ) ) ? 0 : 1,
			'sandbox_client_id'        => sanitize_text_field( (string) $get_input_or_stored_value( 'sandbox_client_id', '' ) ),
			'sandbox_secret'           => sanitize_text_field( (string) $get_input_or_stored_value( 'sandbox_secret', '' ) ),
			'sandbox_webhook_id'       => sanitize_text_field( (string) $get_input_or_stored_value( 'sandbox_webhook_id', '' ) ),
			'sandbox_connected_email'  => sanitize_email( (string) $get_input_or_stored_value( 'sandbox_connected_email', '' ) ),
			'live_client_id'           => sanitize_text_field( (string) $get_input_or_stored_value( 'live_client_id', '' ) ),
			'live_secret'              => sanitize_text_field( (string) $get_input_or_stored_value( 'live_secret', '' ) ),
			'live_webhook_id'          => sanitize_text_field( (string) $get_input_or_stored_value( 'live_webhook_id', '' ) ),
			'live_connected_email'     => sanitize_email( (string) $get_input_or_stored_value( 'live_connected_email', '' ) ),
			'currency'                 => $default_currency,
			'allowed_currencies'       => $currencies ? $currencies : array( $default_currency ),
			'custom_amount'            => empty( $get_input_or_stored_value( 'custom_amount', 1 ) ) ? 0 : 1,
			'min_amount'               => $sanitized_min,
			'max_amount'               => $sanitized_max,
			'preset_amounts'           => sanitize_text_field( (string) $get_input_or_stored_value( 'preset_amounts', '10,25,50,100' ) ),
			'enable_logging'           => empty( $get_input_or_stored_value( 'enable_logging', 0 ) ) ? 0 : 1,
			'logging_level'            => ( static function ( string $level ): string {
				return in_array( $level, array( 'debug', 'info', 'warn', 'error' ), true ) ? $level : 'error';
			} )( sanitize_text_field( (string) $get_input_or_stored_value( 'logging_level', 'error' ) ) ),
			'charity_name'             => sanitize_text_field( (string) $get_input_or_stored_value( 'charity_name', '' ) ),
			'charity_address'          => sanitize_textarea_field( (string) $get_input_or_stored_value( 'charity_address', '' ) ),
			'reg_id'                   => sanitize_text_field( (string) $get_input_or_stored_value( 'reg_id', '' ) ),
			'contact_email'            => sanitize_email( (string) $get_input_or_stored_value( 'contact_email', '' ) ),
			'tax_disclaimer'           => sanitize_textarea_field( (string) $get_input_or_stored_value( 'tax_disclaimer', 'No goods or services were provided in exchange for this donation.' ) ),
			'privacy_url'              => self::sanitize_url_field(
				(string) $get_input_or_stored_value( 'privacy_url', '' ),
				(string) ( $existing_settings['privacy_url'] ?? '' ),
				__( 'Privacy URL', 'donateocean-donation-suite' ),
				self::TAB_COMPLIANCE === $active_tab
			),
			'refund_url'               => self::sanitize_url_field(
				(string) $get_input_or_stored_value( 'refund_url', '' ),
				(string) ( $existing_settings['refund_url'] ?? '' ),
				__( 'Refund URL', 'donateocean-donation-suite' ),
				self::TAB_COMPLIANCE === $active_tab
			),
			'retention_months'         => max( 1, (int) $get_input_or_stored_value( 'retention_months', 24 ) ),
			'store_raw_payload'        => empty( $get_input_or_stored_value( 'store_raw_payload', 0 ) ) ? 0 : 1,
			'enable_recurring'             => empty( $get_input_or_stored_value( 'enable_recurring', 0 ) ) ? 0 : 1,
			'enable_paypal_card_fields'    => empty( $get_input_or_stored_value( 'enable_paypal_card_fields', 0 ) ) ? 0 : 1,
			'enable_fee_coverage'          => empty( $get_input_or_stored_value( 'enable_fee_coverage', 0 ) ) ? 0 : 1,
			'fee_coverage_default_checked' => empty( $get_input_or_stored_value( 'fee_coverage_default_checked', 0 ) ) ? 0 : 1,
			'fee_percentage'           => max( 0.0, min( 10.0, (float) $get_input_or_stored_value( 'fee_percentage', 2.9 ) ) ),
			'giving_levels_json'       => ( static function ( string $raw ): string {
				if ( '' === $raw ) {
					return '';
				}
				$decoded = json_decode( $raw, true );
				if ( ! is_array( $decoded ) ) {
					add_settings_error(
						ConfigService::OPTION_KEY,
						'donadosu_invalid_giving_levels_json',
						__( 'Giving Levels JSON is not valid JSON. Please fix the format — levels were not saved.', 'donateocean-donation-suite' ),
						'error'
					);
					return '';
				}
				// Auto-wrap a single object into an array so both
				// {"amount":25,"label":"Supporter"} and [{...}] work.
				if ( isset( $decoded['amount'] ) ) {
					$decoded = array( $decoded );
				}
				return (string) wp_json_encode( $decoded );
			} )( sanitize_textarea_field( (string) $get_input_or_stored_value( 'giving_levels_json', '' ) ) ),
			'fraud_flag_threshold'     => max( 0, (float) $get_input_or_stored_value( 'fraud_flag_threshold', 5000 ) ),
			'fraud_max_per_email'      => max( 1, (int) $get_input_or_stored_value( 'fraud_max_per_email', 5 ) ),
			'cleanup_on_uninstall'     => empty( $get_input_or_stored_value( 'cleanup_on_uninstall', 0 ) ) ? 0 : 1,
			'ga_measurement_id'        => sanitize_text_field( (string) $get_input_or_stored_value( 'ga_measurement_id', '' ) ),
			'gtm_container_id'         => sanitize_text_field( (string) $get_input_or_stored_value( 'gtm_container_id', '' ) ),
			'ga_enable_tracking'       => empty( $get_input_or_stored_value( 'ga_enable_tracking', 0 ) ) ? 0 : 1,
			'ga_push_events'           => empty( $get_input_or_stored_value( 'ga_push_events', 0 ) ) ? 0 : 1,
			'mailchimp_api_key'        => sanitize_text_field( (string) $get_input_or_stored_value( 'mailchimp_api_key', '' ) ),
			'mailchimp_list_id'        => sanitize_text_field( (string) $get_input_or_stored_value( 'mailchimp_list_id', '' ) ),
			'mailchimp_auto_subscribe' => empty( $get_input_or_stored_value( 'mailchimp_auto_subscribe', 0 ) ) ? 0 : 1,
			'mailchimp_double_optin'   => empty( $get_input_or_stored_value( 'mailchimp_double_optin', 0 ) ) ? 0 : 1,
			'cc_client_id'             => sanitize_text_field( (string) $get_input_or_stored_value( 'cc_client_id', '' ) ),
			'cc_client_secret'         => sanitize_text_field( (string) $get_input_or_stored_value( 'cc_client_secret', '' ) ),
			'cc_list_id'               => sanitize_text_field( (string) $get_input_or_stored_value( 'cc_list_id', '' ) ),
			'cc_auto_subscribe'        => empty( $get_input_or_stored_value( 'cc_auto_subscribe', 0 ) ) ? 0 : 1,
			'zapier_enabled'           => empty( $get_input_or_stored_value( 'zapier_enabled', 0 ) ) ? 0 : 1,
			'zapier_webhook_url'       => esc_url_raw( (string) $get_input_or_stored_value( 'zapier_webhook_url', '' ) ),
			'zapier_secret_key'        => sanitize_text_field( (string) $get_input_or_stored_value( 'zapier_secret_key', '' ) ),
			'zapier_on_completed'      => empty( $get_input_or_stored_value( 'zapier_on_completed', 1 ) ) ? 0 : 1,
			'zapier_on_refunded'       => empty( $get_input_or_stored_value( 'zapier_on_refunded', 1 ) ) ? 0 : 1,
			'zapier_on_disputed'       => empty( $get_input_or_stored_value( 'zapier_on_disputed', 1 ) ) ? 0 : 1,
			'slack_enabled'            => empty( $get_input_or_stored_value( 'slack_enabled', 0 ) ) ? 0 : 1,
			'slack_webhook_url'        => esc_url_raw( (string) $get_input_or_stored_value( 'slack_webhook_url', '' ) ),
			'slack_channel'            => sanitize_text_field( (string) $get_input_or_stored_value( 'slack_channel', '' ) ),
			'slack_on_completed'       => empty( $get_input_or_stored_value( 'slack_on_completed', 1 ) ) ? 0 : 1,
			'slack_on_refunded'        => empty( $get_input_or_stored_value( 'slack_on_refunded', 1 ) ) ? 0 : 1,
			'slack_on_disputed'        => empty( $get_input_or_stored_value( 'slack_on_disputed', 1 ) ) ? 0 : 1,
			'twilio_enabled'           => empty( $get_input_or_stored_value( 'twilio_enabled', 0 ) ) ? 0 : 1,
			'twilio_account_sid'       => sanitize_text_field( (string) $get_input_or_stored_value( 'twilio_account_sid', '' ) ),
			'twilio_auth_token'        => sanitize_text_field( (string) $get_input_or_stored_value( 'twilio_auth_token', '' ) ),
			'twilio_from_number'       => sanitize_text_field( (string) $get_input_or_stored_value( 'twilio_from_number', '' ) ),
			'twilio_to_number'         => sanitize_text_field( (string) $get_input_or_stored_value( 'twilio_to_number', '' ) ),
			'twilio_on_completed'      => empty( $get_input_or_stored_value( 'twilio_on_completed', 1 ) ) ? 0 : 1,
			'twilio_on_refunded'       => empty( $get_input_or_stored_value( 'twilio_on_refunded', 1 ) ) ? 0 : 1,
			'twilio_on_disputed'       => empty( $get_input_or_stored_value( 'twilio_on_disputed', 1 ) ) ? 0 : 1,
			'ac_api_url'               => esc_url_raw( (string) $get_input_or_stored_value( 'ac_api_url', '' ) ),
			'ac_api_key'               => sanitize_text_field( (string) $get_input_or_stored_value( 'ac_api_key', '' ) ),
			'ac_list_id'               => sanitize_text_field( (string) $get_input_or_stored_value( 'ac_list_id', '' ) ),
			'ac_auto_subscribe'        => empty( $get_input_or_stored_value( 'ac_auto_subscribe', 0 ) ) ? 0 : 1,
			'brevo_api_key'            => sanitize_text_field( (string) $get_input_or_stored_value( 'brevo_api_key', '' ) ),
			'brevo_list_id'            => sanitize_text_field( (string) $get_input_or_stored_value( 'brevo_list_id', '' ) ),
			'brevo_auto_subscribe'     => empty( $get_input_or_stored_value( 'brevo_auto_subscribe', 0 ) ) ? 0 : 1,
			'brevo_double_optin'       => empty( $get_input_or_stored_value( 'brevo_double_optin', 0 ) ) ? 0 : 1,
			'gsheets_enabled'          => empty( $get_input_or_stored_value( 'gsheets_enabled', 0 ) ) ? 0 : 1,
			'gsheets_spreadsheet_id'   => sanitize_text_field( (string) $get_input_or_stored_value( 'gsheets_spreadsheet_id', '' ) ),
			'gsheets_sheet_name'       => sanitize_text_field( (string) $get_input_or_stored_value( 'gsheets_sheet_name', 'Sheet1' ) ),
			'gsheets_credentials_json' => ( static function ( string $raw ): string {
				if ( '' === $raw ) {
					return '';
				}
				$decoded = json_decode( $raw, true );
				if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) ) {
					add_settings_error(
						ConfigService::OPTION_KEY,
						'donadosu_invalid_gsheets_json',
						__( 'Google Sheets credentials JSON is invalid. It must contain client_email and private_key fields.', 'donateocean-donation-suite' ),
						'error'
					);
					return '';
				}
				return (string) wp_json_encode( $decoded );
			} )( (string) $get_input_or_stored_value( 'gsheets_credentials_json', '' ) ),
		);

		// Ensure log directory exists when logging is enabled.
		if ( ! empty( $settings['enable_logging'] ) ) {
			$logger = new \DonationSuite\Logging\Logger(
				(string) ( $settings['logging_level'] ?? 'error' ),
				true
			);
			$logger->ensure_log_directory();
		}

		// Only validate credentials and register webhooks when the Environment tab is
		// being saved — avoids duplicate webhook creation on every unrelated save.
		if ( self::TAB_ENVIRONMENT === $active_tab || '' === $active_tab ) {
			$sandbox           = ! empty( $settings['sandbox'] );
			$credential_prefix = $sandbox ? 'sandbox_' : 'live_';
			$webhook_key       = $sandbox ? 'sandbox_webhook_id' : 'live_webhook_id';

			$client_id = (string) $settings[ $credential_prefix . 'client_id' ];
			$secret    = (string) $settings[ $credential_prefix . 'secret' ];

			if ( '' !== $client_id && '' !== $secret ) {
				$validation = $this->webhook_registrar->validate_credentials( $settings, $sandbox );
				if ( empty( $validation['success'] ) ) {
					add_settings_error(
						ConfigService::OPTION_KEY,
						'donadosu_invalid_paypal_credentials',
						sprintf(
							/* translators: %s: "Sandbox" or "Live" */
							__( '%s API credentials could not be validated. Please check Client ID and Secret.', 'donateocean-donation-suite' ),
							$sandbox ? __( 'Sandbox', 'donateocean-donation-suite' ) : __( 'Live', 'donateocean-donation-suite' )
						),
						'error'
					);

					return $existing_settings;
				}

				// Fetch and store the connected account email.
				$token = (string) ( $validation['token'] ?? '' );
				$email = $this->webhook_registrar->get_account_email( $token, $sandbox );
				$email_key = $sandbox ? 'sandbox_connected_email' : 'live_connected_email';
				$settings[ $email_key ] = $email;

				// Only register a new webhook when the credentials have changed or
				// no webhook ID is stored yet — prevents duplicate webhooks in PayPal.
				$stored_client_id  = (string) ( $existing_settings[ $credential_prefix . 'client_id' ] ?? '' );
				$stored_secret     = (string) ( $existing_settings[ $credential_prefix . 'secret' ] ?? '' );
				$stored_webhook_id = (string) ( $existing_settings[ $webhook_key ] ?? '' );

				$credentials_changed = ( $client_id !== $stored_client_id || $secret !== $stored_secret );

				if ( $credentials_changed || '' === $stored_webhook_id ) {
					$registration = $this->webhook_registrar->register( $settings, $sandbox, rest_url( 'donadosu/v1/webhook' ) );
					if ( ! empty( $registration['success'] ) && ! empty( $registration['webhook_id'] ) ) {
						$settings[ $webhook_key ] = sanitize_text_field( (string) $registration['webhook_id'] );
					} else {
						add_settings_error(
							ConfigService::OPTION_KEY,
							'donadosu_webhook_registration_failed',
							sprintf(
								/* translators: %s: "Sandbox" or "Live" */
								__( '%s webhook registration failed. Please save again after confirming credentials.', 'donateocean-donation-suite' ),
								$sandbox ? __( 'Sandbox', 'donateocean-donation-suite' ) : __( 'Live', 'donateocean-donation-suite' )
							),
							'error'
						);
					}
				}
			}
		}

		// Encrypt secret fields before persisting to the database.
		foreach ( $settings as $key => $value ) {
			if ( ConfigService::is_secret_key( $key ) && '' !== (string) $value ) {
				$settings[ $key ] = ConfigService::encrypt_secret( (string) $value );
			}
		}

		return $settings;
	}

	/**
	 * AJAX handler to test PayPal credentials by obtaining an OAuth token.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_test_connection(): void {
		check_ajax_referer( 'donadosu_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'donateocean-donation-suite' ) ), 403 );
		}

		// Accept credentials from POST so the test works before saving.
		$sandbox   = ! empty( $_POST['sandbox'] ) && '0' !== $_POST['sandbox'];
		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$secret    = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';

		if ( '' === $client_id || '' === $secret ) {
			wp_send_json_error( array( 'message' => __( 'Please provide both Client ID and Secret.', 'donateocean-donation-suite' ) ) );
		}

		$settings = array(
			'sandbox'           => $sandbox ? 1 : 0,
			'sandbox_client_id' => $sandbox ? $client_id : '',
			'sandbox_secret'    => $sandbox ? $secret : '',
			'live_client_id'    => $sandbox ? '' : $client_id,
			'live_secret'       => $sandbox ? '' : $secret,
		);

		$validation = $this->webhook_registrar->validate_credentials( $settings, $sandbox );

		if ( empty( $validation['success'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not retrieve PayPal access token. Check your Client ID and Secret.', 'donateocean-donation-suite' ) ) );
		}

		// Try to fetch the connected account email.
		$token = (string) ( $validation['token'] ?? '' );
		$email = $this->webhook_registrar->get_account_email( $token, $sandbox );

		// Store connected account email in settings.
		$stored_settings = (array) get_option( ConfigService::OPTION_KEY, array() );
		$email_key       = $sandbox ? 'sandbox_connected_email' : 'live_connected_email';
		$stored_settings[ $email_key ] = sanitize_email( $email );
		update_option( ConfigService::OPTION_KEY, $stored_settings, false );

		$env = $sandbox ? __( 'Sandbox', 'donateocean-donation-suite' ) : __( 'Live', 'donateocean-donation-suite' );
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: "Sandbox" or "Live" */
					__( '%s credentials are valid. Connection successful.', 'donateocean-donation-suite' ),
					$env
				),
				'email'   => $email,
			)
		);
	}

	/**
	 * AJAX handler to send a test receipt email to verify delivery.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_test_email(): void {
		check_ajax_referer( 'donadosu_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'donateocean-donation-suite' ) ), 403 );
		}

		$config      = new ConfigService();
		$settings    = $config->get_all();
		$admin_email = sanitize_email( (string) ( $settings['contact_email'] ?? get_option( 'admin_email' ) ) );

		if ( ! $admin_email ) {
			wp_send_json_error( array( 'message' => __( 'No admin email address configured.', 'donateocean-donation-suite' ) ) );
		}

		$org_name = (string) ( $settings['charity_name'] ?? get_bloginfo( 'name' ) );
		/* translators: %s: organization name */
		$subject  = sprintf( __( '[%s] Test donation receipt', 'donateocean-donation-suite' ), $org_name );
		$body     = sprintf(
			'<!DOCTYPE html><html><body style="margin:0;padding:24px;font-family:Inter,-apple-system,sans-serif;background:#f4f4f5;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;border:1px solid #e4e4e7;">
  <h2 style="margin:0 0 4px;font-size:22px;">%s</h2>
  <p style="margin:0 0 24px;color:#525252;">%s</p>
  <table style="width:100%%;border-collapse:collapse;font-size:14px;">
    <tr><td style="padding:8px 0;border-bottom:1px solid #f4f4f5;color:#525252;width:45%%;">%s</td><td style="padding:8px 0;border-bottom:1px solid #f4f4f5;font-weight:600;">TEST-RCPT-001</td></tr>
    <tr><td style="padding:8px 0;border-bottom:1px solid #f4f4f5;color:#525252;">%s</td><td style="padding:8px 0;border-bottom:1px solid #f4f4f5;">%s UTC</td></tr>
    <tr><td style="padding:8px 0;border-bottom:1px solid #f4f4f5;color:#525252;">%s</td><td style="padding:8px 0;border-bottom:1px solid #f4f4f5;font-weight:600;">USD 25.00</td></tr>
    <tr><td style="padding:8px 0;border-bottom:1px solid #f4f4f5;color:#525252;">%s</td><td style="padding:8px 0;border-bottom:1px solid #f4f4f5;">%s</td></tr>
  </table>
  <p style="margin:24px 0 0;font-size:12px;color:#71717a;text-align:center;">%s</p>
</div></body></html>',
			/* translators: %s: organization name */
			esc_html( sprintf( __( '%s Donation Receipt', 'donateocean-donation-suite' ), $org_name ) ),
			esc_html( __( 'This is a test email. Your email delivery is working correctly.', 'donateocean-donation-suite' ) ),
			esc_html( __( 'Receipt #', 'donateocean-donation-suite' ) ),
			esc_html( __( 'Donation date', 'donateocean-donation-suite' ) ),
			esc_html( gmdate( 'Y-m-d H:i:s' ) ),
			esc_html( __( 'Amount', 'donateocean-donation-suite' ) ),
			esc_html( __( 'Donation type', 'donateocean-donation-suite' ) ),
			esc_html( __( 'One-time donation', 'donateocean-donation-suite' ) ),
			esc_html( __( 'This is a test message sent from Donation Suite settings.', 'donateocean-donation-suite' ) )
		);

		$sent = wp_mail( $admin_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		if ( $sent ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %s: admin email address */
						__( 'Test email sent to %s.', 'donateocean-donation-suite' ),
						$admin_email
					),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'wp_mail() returned false. Check your server email configuration.', 'donateocean-donation-suite' ) ) );
		}
	}

	/**
	 * AJAX handler to disconnect the PayPal account.
	 *
	 * Clears the stored credentials, webhook ID, and connected email
	 * for the current environment.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		check_ajax_referer( 'donadosu_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'donateocean-donation-suite' ) ), 403 );
		}

		$sandbox  = ! empty( $_POST['sandbox'] ) && '0' !== $_POST['sandbox'];
		$prefix   = $sandbox ? 'sandbox_' : 'live_';
		$settings = (array) get_option( ConfigService::OPTION_KEY, array() );

		$settings[ $prefix . 'client_id' ]       = '';
		$settings[ $prefix . 'secret' ]           = '';
		$settings[ $prefix . 'webhook_id' ]       = '';
		$settings[ $prefix . 'connected_email' ]  = '';

		update_option( ConfigService::OPTION_KEY, $settings, false );

		// Clear cached OAuth token for this environment.
		delete_transient( 'donadosu_token_' . ( $sandbox ? 'sandbox' : 'live' ) );

		$env = $sandbox ? __( 'Sandbox', 'donateocean-donation-suite' ) : __( 'Live', 'donateocean-donation-suite' );
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: "Sandbox" or "Live" */
					__( '%s PayPal account disconnected.', 'donateocean-donation-suite' ),
					$env
				),
			)
		);
	}

	/**
	 * AJAX handler to persist dismissal of an inline state banner.
	 *
	 * Records the dismissed banner key so it is not rendered again on the
	 * settings screen.
	 *
	 * @since 1.0.7
	 *
	 * @return void
	 */
	public function handle_dismiss_notice(): void {
		check_ajax_referer( 'donadosu_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'donateocean-donation-suite' ) ), 403 );
		}

		$notice = isset( $_POST['notice'] ) ? sanitize_key( wp_unslash( $_POST['notice'] ) ) : '';

		if ( ! in_array( $notice, self::DISMISSIBLE_NOTICES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown notice.', 'donateocean-donation-suite' ) ) );
		}

		$dismissed = self::get_dismissed_notices();
		if ( ! in_array( $notice, $dismissed, true ) ) {
			$dismissed[] = $notice;
			update_option( self::DISMISSED_NOTICES_OPTION, $dismissed, false );
		}

		wp_send_json_success();
	}

	/**
	 * Return the list of inline state banners the admin has dismissed.
	 *
	 * @since 1.0.7
	 *
	 * @return string[] Dismissed banner keys.
	 */
	private static function get_dismissed_notices(): array {
		$dismissed = get_option( self::DISMISSED_NOTICES_OPTION, array() );

		return is_array( $dismissed ) ? array_values( array_intersect( $dismissed, self::DISMISSIBLE_NOTICES ) ) : array();
	}

	/**
	 * AJAX handler to test the Mailchimp API connection.
	 *
	 * @since 1.0.5
	 *
	 * @return void
	 */
	public function handle_test_mailchimp(): void {
		check_ajax_referer( 'donadosu_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'donateocean-donation-suite' ) ), 403 );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$list_id = isset( $_POST['list_id'] ) ? sanitize_text_field( wp_unslash( $_POST['list_id'] ) ) : '';

		if ( '' === $api_key || '' === $list_id ) {
			wp_send_json_error( array( 'message' => __( 'Please provide both the API key and audience ID.', 'donateocean-donation-suite' ) ) );
		}

		$result = \DonationSuite\Integration\Mailchimp::test_connection( $api_key, $list_id );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * AJAX handler to send a test payload to the configured Zapier webhook URL.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_test_zapier(): void {
		check_ajax_referer( 'donadosu_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'donateocean-donation-suite' ) ), 403 );
		}

		$webhook_url = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';

		if ( '' === $webhook_url ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a Zapier webhook URL.', 'donateocean-donation-suite' ) ) );
		}

		$test_payload = array(
			'event'            => 'test',
			'donation_id'      => 0,
			'amount'           => '25.00',
			'currency'         => 'USD',
			'donor_name'       => 'Test Donor',
			'donor_email'      => 'test@example.com',
			'donor_message'    => 'This is a test event from Donation Suite.',
			'donation_date'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'frequency'        => 'one_time',
			'campaign'         => '',
			'purpose'          => '',
			'is_anonymous'     => false,
			'is_tribute'       => false,
			'tribute_type'     => '',
			'tribute_name'     => '',
			'fee_covered'      => false,
			'gross_amount'     => '25.00',
			'payment_source'   => 'paypal',
			'receipt_number'   => 'TEST-RCPT-001',
			'order_id'         => 'TEST-ORDER-001',
			'status'           => 'donadosu_completed',
			'site_name'        => get_bloginfo( 'name' ),
			'site_url'         => home_url(),
		);

		$response = wp_remote_post(
			$webhook_url,
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => (string) wp_json_encode( $test_payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				/* translators: %s: Error message from the connection attempt. */
				array( 'message' => sprintf( __( 'Connection failed: %s', 'donateocean-donation-suite' ), $response->get_error_message() ) )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			wp_send_json_success(
				array( 'message' => __( 'Test event sent successfully. Check your Zapier dashboard.', 'donateocean-donation-suite' ) )
			);
		} else {
			wp_send_json_error(
				/* translators: %d: HTTP status code returned by Zapier. */
				array( 'message' => sprintf( __( 'Zapier returned HTTP %d. Check your webhook URL.', 'donateocean-donation-suite' ), $code ) )
			);
		}
	}

	/**
	 * AJAX handler to send a test notification to the configured Slack webhook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_test_slack(): void {
		check_ajax_referer( 'donadosu_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'donateocean-donation-suite' ) ), 403 );
		}

		$webhook_url = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
		$channel     = isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( $_POST['channel'] ) ) : '';

		if ( '' === $webhook_url ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a Slack webhook URL.', 'donateocean-donation-suite' ) ) );
		}

		$result = \DonationSuite\Integration\Slack::send_test( $webhook_url, $channel );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * AJAX handler to send a test SMS via Twilio.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_test_twilio(): void {
		check_ajax_referer( 'donadosu_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'donateocean-donation-suite' ) ), 403 );
		}

		$account_sid = isset( $_POST['account_sid'] ) ? sanitize_text_field( wp_unslash( $_POST['account_sid'] ) ) : '';
		$auth_token  = isset( $_POST['auth_token'] ) ? sanitize_text_field( wp_unslash( $_POST['auth_token'] ) ) : '';
		$from_number = isset( $_POST['from_number'] ) ? sanitize_text_field( wp_unslash( $_POST['from_number'] ) ) : '';
		$to_number   = isset( $_POST['to_number'] ) ? sanitize_text_field( wp_unslash( $_POST['to_number'] ) ) : '';

		if ( '' === $account_sid || '' === $auth_token || '' === $from_number || '' === $to_number ) {
			wp_send_json_error( array( 'message' => __( 'Please fill in all Twilio fields before testing.', 'donateocean-donation-suite' ) ) );
		}

		$site_name = get_bloginfo( 'name' );
		$body      = sprintf( '[%s] Donation Suite test SMS. Your Twilio integration is working correctly.', $site_name );

		$result = \DonationSuite\Integration\Twilio::send_via_api( $account_sid, $auth_token, $from_number, $to_number, $body );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => __( 'Test SMS sent successfully. Check your phone.', 'donateocean-donation-suite' ) ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * AJAX handler to test ActiveCampaign API connection.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_test_activecampaign(): void {
		check_ajax_referer( 'donadosu_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'donateocean-donation-suite' ) ), 403 );
		}

		$api_url = isset( $_POST['api_url'] ) ? esc_url_raw( wp_unslash( $_POST['api_url'] ) ) : '';
		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( '' === $api_url || '' === $api_key ) {
			wp_send_json_error( array( 'message' => __( 'Please provide both API URL and API Key.', 'donateocean-donation-suite' ) ) );
		}

		$result = \DonationSuite\Integration\ActiveCampaign::test_connection( $api_url, $api_key );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * AJAX handler to test Brevo API connection.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_test_brevo(): void {
		check_ajax_referer( 'donadosu_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'donateocean-donation-suite' ) ), 403 );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a Brevo API key.', 'donateocean-donation-suite' ) ) );
		}

		$result = \DonationSuite\Integration\Brevo::test_connection( $api_key );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * AJAX handler to test Google Sheets connection.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_test_gsheets(): void {
		check_ajax_referer( 'donadosu_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'donateocean-donation-suite' ) ), 403 );
		}

		$credentials_json = isset( $_POST['credentials_json'] ) ? sanitize_textarea_field( wp_unslash( $_POST['credentials_json'] ) ) : '';
		$spreadsheet_id   = isset( $_POST['spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ) ) : '';

		if ( '' === $credentials_json || '' === $spreadsheet_id ) {
			wp_send_json_error( array( 'message' => __( 'Please provide both credentials JSON and Spreadsheet ID.', 'donateocean-donation-suite' ) ) );
		}

		$result = \DonationSuite\Integration\GoogleSheets::test_connection( $credentials_json, $spreadsheet_id );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * Render the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'donateocean-donation-suite' ) );
		}

		$settings         = ( new ConfigService() )->get_all();
		$activeTab        = $this->get_active_tab();
		$tabs             = $this->get_tabs();
		$settingsTabs     = $this->get_settings_tabs();
		$toolTabs         = $this->get_tool_tabs();
		$connectionState  = ( new ConfigService() )->get_connection_state();
		$dismissedNotices = self::get_dismissed_notices();

		include DONADOSU_PATH . 'templates/admin-settings.php';
	}

	/**
	 * Sanitize a URL field and surface an error if it is invalid.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw       Raw input value from the form.
	 * @param string $fallback  Existing stored value to revert to on failure.
	 * @param string $label     Human-readable field name for the error message.
	 * @param bool   $on_tab    Whether the field's tab is the one being saved.
	 * @return string Sanitized URL or fallback when input was unparseable.
	 */
	private static function sanitize_url_field( string $raw, string $fallback, string $label, bool $on_tab ): string {
		$raw       = trim( $raw );
		$sanitized = esc_url_raw( $raw );

		if ( '' !== $raw && '' === $sanitized && $on_tab ) {
			add_settings_error(
				ConfigService::OPTION_KEY,
				'donadosu_invalid_url_' . sanitize_key( $label ),
				sprintf(
					/* translators: %s: field name (e.g. "Privacy URL"). */
					__( '%s is not a valid URL — the previous value was kept.', 'donateocean-donation-suite' ),
					$label
				),
				'error'
			);
			return $fallback;
		}

		return $sanitized;
	}

	/**
	 * Get the currently active tab from the query string.
	 *
	 * @since 1.0.0
	 *
	 * @return string The active tab slug.
	 */
	private function get_active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading tab for display purposes only.
		if ( ! isset( $_GET['tab'] ) ) {
			return self::TAB_ENVIRONMENT;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading tab for display purposes only.
		$tab = sanitize_key( (string) wp_unslash( $_GET['tab'] ) );

		if ( array_key_exists( $tab, $this->get_tabs() ) ) {
			return $tab;
		}

		// Unknown tab — redirect to a clean URL on the default tab so the
		// browser address bar reflects what is being shown.
		if ( ! headers_sent() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=donadosu-settings' ) );
			exit;
		}

		return self::TAB_ENVIRONMENT;
	}

	/**
	 * Get all tabs (settings tabs merged with tool tabs).
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Tab slugs mapped to labels.
	 */
	private function get_tabs(): array {
		return array_merge( $this->get_settings_tabs(), $this->get_tool_tabs() );
	}

	/**
	 * Get tabs that persist settings on save.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Tab slugs mapped to labels.
	 */
	private function get_settings_tabs(): array {
		return array(
			self::TAB_ENVIRONMENT  => __( 'PayPal Connection', 'donateocean-donation-suite' ),
			self::TAB_EXPERIENCE   => __( 'Donation Experience', 'donateocean-donation-suite' ),
			self::TAB_COMPLIANCE   => __( 'Organization & Compliance', 'donateocean-donation-suite' ),
			self::TAB_ADVANCED     => __( 'Advanced & Security', 'donateocean-donation-suite' ),
			self::TAB_INTEGRATIONS => __( 'Integrations', 'donateocean-donation-suite' ),
		);
	}

	/**
	 * Get utility tabs that do not persist any settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Tab slugs mapped to labels.
	 */
	private function get_tool_tabs(): array {
		return array(
			self::TAB_SHORTCODE => __( 'Shortcode Builder', 'donateocean-donation-suite' ),
		);
	}
}
