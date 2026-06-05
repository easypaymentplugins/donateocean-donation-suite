<?php
/**
 * Gutenberg donation form block.
 *
 * Registers the "Donation Suite Form" block type with server-side
 * rendering so the PHP shortcode logic (including dynamic goal
 * tracking, config localisation, asset enqueueing) runs on every
 * page load.
 *
 * @package    Donation_Suite
 * @subpackage Block
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Block;

use DonationSuite\Core\ConfigService;
use DonationSuite\Frontend\Shortcode;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DonationBlock
 *
 * Wraps the donation form shortcode as a Gutenberg block with
 * server-side rendering.
 *
 * Block metadata: blocks/donation-form/block.json
 * Editor JS:      blocks/donation-form/index.js
 *
 * @since 1.0.0
 */
class DonationBlock {

	/**
	 * Register the block initialisation hook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register the block type with WordPress.
	 *
	 * Uses block.json metadata from the blocks/donation-form directory
	 * and a server-side render callback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			DONADOSU_PATH . 'blocks/donation-form',
			array( 'render_callback' => array( self::class, 'render_callback' ) )
		);
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * Maps camelCase block attributes to snake_case shortcode attributes
	 * and delegates to the existing Shortcode::render() pipeline so that
	 * asset enqueueing, nonce generation, and config localisation all
	 * work identically in both the block editor preview and the frontend.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attributes Block attributes from the editor.
	 * @return string Rendered block HTML.
	 */
	public static function render_callback( array $attributes ): string {
		$config    = new ConfigService();
		$shortcode = new Shortcode( $config );

		// Map camelCase block attributes to snake_case shortcode atts.
		$atts = array(
			'campaign'            => sanitize_text_field( (string) ( $attributes['campaign'] ?? '' ) ),
			'purpose'             => sanitize_text_field( (string) ( $attributes['purpose'] ?? '' ) ),
			'currency'            => sanitize_text_field( (string) ( $attributes['currency'] ?? '' ) ),
			'donor_fields'        => ! empty( $attributes['donorFields'] ) ? '1' : '0',
			'display_mode'        => sanitize_key( (string) ( $attributes['displayMode'] ?? 'inline' ) ),
			'goal_amount'         => (string) max( 0.0, (float) ( $attributes['goalAmount'] ?? 0 ) ),
			'goal_current'        => sanitize_text_field( (string) ( $attributes['goalCurrent'] ?? '' ) ),
			'goal_label'          => sanitize_text_field( (string) ( $attributes['goalLabel'] ?? 'Campaign progress' ) ),
			'goal_close'          => ! empty( $attributes['goalClose'] ) ? '1' : '0',
			'button_text'         => sanitize_text_field( (string) ( $attributes['buttonText'] ?? 'Donate now' ) ),
			'button_color'        => sanitize_hex_color( (string) ( $attributes['buttonColor'] ?? '' ) ) ?: '',
			'thank_you_url'       => esc_url_raw( (string) ( $attributes['thankYouUrl'] ?? '' ) ),
			'redirect_on_success' => ! empty( $attributes['redirectOnSuccess'] ) ? '1' : '0',
			'donation_mode'       => sanitize_key( (string) ( $attributes['donationMode'] ?? 'both' ) ),
			'amounts'             => sanitize_text_field( (string) ( $attributes['amounts'] ?? '' ) ),
			'locale'              => sanitize_text_field( (string) ( $attributes['locale'] ?? '' ) ),
			'campaign_start'      => sanitize_text_field( (string) ( $attributes['campaignStart'] ?? '' ) ),
			'campaign_end'        => sanitize_text_field( (string) ( $attributes['campaignEnd'] ?? '' ) ),
			'min_amount'          => (string) ( $attributes['minAmount'] ?? '' ),
			'max_amount'          => (string) ( $attributes['maxAmount'] ?? '' ),
			'fee_coverage'        => ! empty( $attributes['feeCoverage'] ) ? '1' : '0',
			'css_class'           => implode( ' ', array_map( 'sanitize_html_class', explode( ' ', (string) ( $attributes['cssClass'] ?? '' ) ) ) ),
		);

		return $shortcode->render( $atts );
	}
}
