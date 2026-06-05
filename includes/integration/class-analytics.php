<?php
/**
 * Google Analytics 4 / Google Tag Manager integration.
 *
 * Renders the GA4 (gtag.js) and/or Google Tag Manager container snippets on
 * the site frontend when configured, and exposes the donation-event push flag
 * to the donation form script (see assets/js/donate.js).
 *
 * @package    Donation_Suite
 * @subpackage Integration
 * @since      1.0.5
 * @version    1.0.5
 */

namespace DonationSuite\Integration;

use DonationSuite\Core\ConfigService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Analytics
 *
 * Outputs Google Analytics 4 and Google Tag Manager tags on the frontend so
 * site owners can measure donation conversions. Disabled by default and only
 * active once an administrator enables tracking and supplies an ID.
 *
 * @since 1.0.5
 */
class Analytics {

	/**
	 * Plugin configuration service.
	 *
	 * @since 1.0.5
	 * @var ConfigService
	 */
	private ConfigService $config;

	/**
	 * Guard so the head snippet is only emitted once per request.
	 *
	 * @since 1.0.5
	 * @var bool
	 */
	private bool $head_printed = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.5
	 *
	 * @param ConfigService $config Plugin configuration service.
	 */
	public function __construct( ConfigService $config ) {
		$this->config = $config;
	}

	/**
	 * Register WordPress hooks for the analytics integration.
	 *
	 * @since 1.0.5
	 *
	 * @return void
	 */
	public function register(): void {
		// Priority 1 so the GTM/GA4 libraries load as early as possible in <head>.
		add_action( 'wp_head', array( $this, 'render_head' ), 1 );
		// GTM requires a <noscript> fallback immediately after the opening body tag.
		add_action( 'wp_body_open', array( $this, 'render_body_open' ) );
	}

	/**
	 * Sanitise and validate a GA4 Measurement ID (e.g. G-XXXXXXXXXX).
	 *
	 * @since 1.0.5
	 *
	 * @return string The validated GA4 ID, or empty string if invalid/disabled.
	 */
	private function ga4_id(): string {
		$settings = $this->config->get_all();
		if ( empty( $settings['ga_enable_tracking'] ) ) {
			return '';
		}
		$id = trim( (string) ( $settings['ga_measurement_id'] ?? '' ) );
		return preg_match( '/^G-[A-Z0-9]+$/i', $id ) ? $id : '';
	}

	/**
	 * Sanitise and validate a GTM Container ID (e.g. GTM-XXXXXXX).
	 *
	 * @since 1.0.5
	 *
	 * @return string The validated GTM ID, or empty string if invalid/disabled.
	 */
	private function gtm_id(): string {
		$settings = $this->config->get_all();
		if ( empty( $settings['ga_enable_tracking'] ) ) {
			return '';
		}
		$id = trim( (string) ( $settings['gtm_container_id'] ?? '' ) );
		return preg_match( '/^GTM-[A-Z0-9]+$/i', $id ) ? $id : '';
	}

	/**
	 * Whether the visitor has consented to analytics/statistics tracking.
	 *
	 * Integrates with the WordPress Consent API when a consent-management
	 * plugin is active (requiring the `statistics` category), and always
	 * passes through the `donadosu_analytics_has_consent` filter so site
	 * owners can wire any consent mechanism. When no consent framework is
	 * present the result defaults to the `ga_require_consent` setting: if
	 * that setting is on, tags are withheld until consent is signalled.
	 *
	 * @since 1.0.5
	 *
	 * @return bool True if analytics tags may be rendered.
	 */
	private function has_analytics_consent(): bool {
		$settings = $this->config->get_all();

		if ( function_exists( 'wp_has_consent' ) ) {
			$granted = (bool) wp_has_consent( 'statistics' );
		} else {
			// No Consent API plugin: respect the admin's explicit choice. When
			// "require consent" is enabled but no framework can signal it, hold
			// the tags back rather than tracking without a lawful basis.
			$granted = empty( $settings['ga_require_consent'] );
		}

		/**
		 * Filters whether analytics tags may be rendered for this request.
		 *
		 * @since 1.0.5
		 *
		 * @param bool $granted Whether consent is considered granted.
		 */
		return (bool) apply_filters( 'donadosu_analytics_has_consent', $granted );
	}

	/**
	 * Render the GA4 and/or GTM library snippets inside <head>.
	 *
	 * @since 1.0.5
	 *
	 * @return void
	 */
	public function render_head(): void {
		if ( is_admin() || $this->head_printed || ! $this->has_analytics_consent() ) {
			return;
		}

		$gtm_id = $this->gtm_id();
		$ga4_id = $this->ga4_id();

		if ( '' === $gtm_id && '' === $ga4_id ) {
			return;
		}

		$this->head_printed = true;

		// Google Tag Manager — the ID is validated against /^GTM-[A-Z0-9]+$/i
		// above, so it contains only characters safe to embed in JS and URLs.
		if ( '' !== $gtm_id ) {
			echo "\n<!-- Google Tag Manager (Donation Suite) -->\n";
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $gtm_id is validated to [A-Z0-9-] only.
			echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . esc_js( $gtm_id ) . "');</script>\n";
			echo "<!-- End Google Tag Manager -->\n";
		}

		// Google Analytics 4 (gtag.js). The ID is validated to /^G-[A-Z0-9]+$/i.
		if ( '' !== $ga4_id ) {
			printf(
				"\n<!-- Google Analytics 4 (Donation Suite) -->\n<script async src=\"%s\"></script>\n", // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- gtag.js loader is injected inline in <head> with async; the ID is validated to [A-Z0-9-].
				esc_url( 'https://www.googletagmanager.com/gtag/js?id=' . $ga4_id )
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $ga4_id is validated to [A-Z0-9-] only.
			echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . esc_js( $ga4_id ) . "');</script>\n<!-- End Google Analytics 4 -->\n";
		}
	}

	/**
	 * Render the GTM <noscript> fallback immediately after <body>.
	 *
	 * @since 1.0.5
	 *
	 * @return void
	 */
	public function render_body_open(): void {
		if ( is_admin() || ! $this->has_analytics_consent() ) {
			return;
		}

		$gtm_id = $this->gtm_id();
		if ( '' === $gtm_id ) {
			return;
		}

		printf(
			'<noscript><iframe src="%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n",
			esc_url( 'https://www.googletagmanager.com/ns.html?id=' . $gtm_id )
		);
	}
}
