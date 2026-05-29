<?php
/**
 * Donation form shortcode.
 *
 * Registers the [donadosu_donation] shortcode and handles asset
 * registration, attribute sanitisation, dynamic goal progress,
 * campaign date gating, and template rendering.
 *
 * @package    Donation_Suite
 * @subpackage Frontend
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Frontend;

use DonationSuite\Admin\CampaignTrackingPage;
use DonationSuite\Core\ConfigService;
use DonationSuite\Core\CustomFieldsManager;
use DonationSuite\Donation\CptDonationRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Shortcode
 *
 * Provides the [donadosu_donation] shortcode for embedding donation
 * forms on frontend pages.
 *
 * @since 1.0.0
 */
class Shortcode {

	/**
	 * Plugin configuration service.
	 *
	 * @since 1.0.0
	 * @var ConfigService
	 */
	private ConfigService $config;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param ConfigService $config Plugin configuration service.
	 */
	public function __construct( ConfigService $config ) {
		$this->config = $config;
	}

	/**
	 * Register the shortcode and frontend asset hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'donadosu_donation', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register frontend JavaScript and CSS assets.
	 *
	 * Assets are only enqueued when the shortcode is actually rendered
	 * on the page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_assets(): void {
		wp_register_script( 'donadosu-donate', DONADOSU_URL . 'assets/js/donate.js', array(), DONADOSU_VERSION, true );
		wp_register_style( 'donadosu-style', DONADOSU_URL . 'assets/css/donate.css', array(), DONADOSU_VERSION );
	}

	/**
	 * Render the donation form shortcode.
	 *
	 * Processes shortcode attributes, resolves donation mode against
	 * plugin settings, computes dynamic goal progress when set to 'auto',
	 * applies campaign date gating, enqueues assets, localises the
	 * script, and includes the template.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string Rendered shortcode HTML.
	 */
	public function render( $atts = array() ): string {
		// Initialize custom fields manager to allow registration via hooks.
		CustomFieldsManager::init();

		$atts = shortcode_atts(
			array(
				'title'               => __( 'Make a Donation', 'donateocean-donation-suite' ),
				'description'         => __( 'Fast and secure checkout with PayPal.', 'donateocean-donation-suite' ),
				'currency'            => '',
				'amounts'             => '',
				'campaign'            => '',
				'purpose'             => '',
				'locale'              => '',
				'donor_fields'        => '1',
				'donation_mode'       => 'both',
				'display_mode'        => 'inline',
				'goal_amount'         => '',
				'goal_current'        => '',
				'goal_label'          => __( 'Campaign progress', 'donateocean-donation-suite' ),
				'goal_close'          => '0',
				'campaign_start'      => '',
				'campaign_end'        => '',
				'button_text'         => __( 'Donate with PayPal', 'donateocean-donation-suite' ),
				'button_color'        => '',
				'thank_you_url'       => '',
				'redirect_on_success' => '0',
				'min_amount'          => '',
				'max_amount'          => '',
				'fee_coverage'        => '0',
				'css_class'           => '',
			),
			$atts,
			'donadosu_donation'
		);

		// Sanitise and normalise all attributes.
		$atts['donor_fields']        = false === filter_var( $atts['donor_fields'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ? '0' : '1';
		$atts['redirect_on_success'] = true === filter_var( $atts['redirect_on_success'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ? '1' : '0';
		$atts['display_mode']        = in_array( $atts['display_mode'], array( 'inline', 'modal', 'widget', 'page' ), true ) ? $atts['display_mode'] : 'inline';
		$atts['goal_close']          = true === filter_var( $atts['goal_close'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ? '1' : '0';
		$atts['goal_amount']         = max( 0.0, (float) $atts['goal_amount'] );

		// Resolve donation_mode against what is enabled in settings.
		$recurring_enabled     = ! empty( $this->config->get_all()['enable_recurring'] );
		$requested_mode        = in_array( $atts['donation_mode'], array( 'one_time', 'monthly', 'annual', 'both' ), true ) ? $atts['donation_mode'] : 'one_time';
		$atts['donation_mode'] = ( 'one_time' !== $requested_mode && ! $recurring_enabled ) ? 'one_time' : $requested_mode;
		$atts['goal_label']    = sanitize_text_field( (string) $atts['goal_label'] );
		$atts['button_text']   = sanitize_text_field( (string) $atts['button_text'] );
		$atts['button_color']  = sanitize_hex_color( (string) $atts['button_color'] ) ?: '';
		$atts['thank_you_url'] = esc_url_raw( (string) $atts['thank_you_url'] );
		$atts['min_amount']    = '' !== $atts['min_amount'] ? max( 0.5, (float) $atts['min_amount'] ) : '';
		$atts['max_amount']    = '' !== $atts['max_amount'] ? max( 1.0, (float) $atts['max_amount'] ) : '';
		$atts['fee_coverage']  = true === filter_var( $atts['fee_coverage'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ? '1' : '0';
		$atts['css_class']     = implode( ' ', array_map( 'sanitize_html_class', explode( ' ', (string) $atts['css_class'] ) ) );
		// Accept BCP-47-ish locales (en, en_US, fil_PH, fr_CA, zh_CN). Drop anything else.
		$atts['locale']        = preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/', (string) $atts['locale'] ) ? (string) $atts['locale'] : '';

		// Seed the central campaign goal option so the admin Campaign Fund
		// Tracking page reflects goals declared via the shortcode. Admin edits
		// take precedence: we only seed when no goal is stored for this
		// campaign yet.
		if ( '' !== $atts['campaign'] && $atts['goal_amount'] > 0 ) {
			$this->maybe_seed_campaign_goal( (string) $atts['campaign'], (float) $atts['goal_amount'] );
		}

		// Dynamic goal progress.
		// When goal_current is 'auto' (and a campaign name + goal_amount are
		// set), query the database to sum completed donations for that campaign
		// so the progress bar stays accurate without manual updates.
		$goal_current_raw = trim( (string) $atts['goal_current'] );

		if ( 'auto' === $goal_current_raw ) {
			if ( $atts['goal_amount'] > 0 && '' !== $atts['campaign'] ) {
				$repository           = new CptDonationRepository();
				$atts['goal_current'] = $repository->get_campaign_total( $atts['campaign'] );
			} else {
				$atts['goal_current'] = 0.0;
			}
		} else {
			$atts['goal_current'] = max( 0.0, (float) $goal_current_raw );
		}

		// Campaign date gating.
		$campaign_closed  = false;
		$campaign_message = '';
		$now              = time();

		if ( '' !== $atts['campaign_start'] ) {
			$start_ts = strtotime( $atts['campaign_start'] );
			if ( false !== $start_ts && $now < $start_ts ) {
				$campaign_closed  = true;
				$campaign_message = sprintf(
					/* translators: %s: campaign open date */
					__( 'This campaign opens on %s.', 'donateocean-donation-suite' ),
					esc_html( gmdate( 'F j, Y', $start_ts ) )
				);
			}
		}

		if ( ! $campaign_closed && '' !== $atts['campaign_end'] ) {
			$end_ts = strtotime( $atts['campaign_end'] );
			if ( false !== $end_ts && $now > $end_ts ) {
				$campaign_closed  = true;
				$campaign_message = __( 'This campaign has ended. Thank you for your interest.', 'donateocean-donation-suite' );
			}
		}

		if ( ! $campaign_closed && '1' === $atts['goal_close'] && $atts['goal_amount'] > 0 && $atts['goal_current'] >= $atts['goal_amount'] ) {
			$campaign_closed  = true;
			$campaign_message = __( 'This campaign has reached its goal. Thank you for your incredible support!', 'donateocean-donation-suite' );
		}

		$atts['campaign_closed']  = $campaign_closed;
		$atts['campaign_message'] = $campaign_message;

		wp_enqueue_script( 'donadosu-donate' );
		wp_enqueue_style( 'donadosu-style' );

		wp_localize_script(
			'donadosu-donate',
			'donadosuDonation',
			array(
				'apiBase'  => esc_url_raw( rest_url( 'donadosu/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'defaults' => $this->config->get_frontend_config(),
				'atts'     => $atts,
			)
		);

		ob_start();
		include DONADOSU_PATH . 'templates/shortcode.php';
		return (string) ob_get_clean();
	}

	/**
	 * Seed a campaign goal into the central goals option from the shortcode.
	 *
	 * Writes the shortcode's goal_amount into the donadosu_campaign_goals
	 * option so the admin Campaign Fund Tracking page shows the goal and
	 * progress bar for campaigns declared solely via shortcode. Only seeds
	 * when no goal is stored for the campaign yet, so values explicitly set
	 * or cleared by an admin in the tracking page take precedence.
	 *
	 * @since 1.0.0
	 *
	 * @param string $campaign    Campaign slug.
	 * @param float  $goal_amount Goal amount from the shortcode.
	 * @return void
	 */
	private function maybe_seed_campaign_goal( string $campaign, float $goal_amount ): void {
		$goals = get_option( CampaignTrackingPage::GOALS_OPTION, array() );
		if ( ! is_array( $goals ) ) {
			$goals = array();
		}

		if ( isset( $goals[ $campaign ] ) ) {
			return;
		}

		$goals[ $campaign ] = $goal_amount;
		update_option( CampaignTrackingPage::GOALS_OPTION, $goals );
	}
}
