<?php
/**
 * Campaign fund tracking page for the Donation Suite admin.
 *
 * Lists every campaign with its raised amount, donation count, unique donor
 * count, goal amount, percentage toward goal, and date range. Admins can
 * set per-campaign goal amounts that are stored centrally so tracking works
 * even when multiple shortcodes reference the same campaign.
 *
 * @package    Donation_Suite
 * @subpackage Admin
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Admin;

use DonationSuite\Core\Capabilities;
use DonationSuite\Donation\CptDonationRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignTrackingPage
 *
 * Renders an admin page showing fund-tracking stats for every campaign.
 *
 * @since 1.0.0
 */
class CampaignTrackingPage {

	/**
	 * Option key where per-campaign goal amounts are stored.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const GOALS_OPTION = 'donadosu_campaign_goals';

	/**
	 * Transient key for cached campaign stats.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const CACHE_KEY = 'donadosu_campaign_tracking_stats';

	/**
	 * Cache time-to-live in seconds (15 minutes).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Register the admin menu page and the goal-save handler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_donadosu_save_campaign_goals', array( $this, 'handle_save_goals' ) );
	}

	/**
	 * Add the Campaign Tracking submenu page under Donation Suite.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=donadosu_donation',
			__( 'Campaign Tracking', 'donateocean-donation-suite' ),
			__( 'Campaign Tracking', 'donateocean-donation-suite' ),
			Capabilities::VIEW_DONATIONS,
			'donadosu-campaigns',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the campaign tracking page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Capabilities::can_view() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'donateocean-donation-suite' ) );
		}

		$repository = new CptDonationRepository();

		$campaigns = get_transient( self::CACHE_KEY );
		if ( false === $campaigns ) {
			$campaigns = $repository->get_all_campaign_stats();
			set_transient( self::CACHE_KEY, $campaigns, self::CACHE_TTL );
		}

		$goals    = (array) get_option( self::GOALS_OPTION, array() );
		$currency = get_option( 'donadosu_settings', array() )['currency'] ?? 'USD';
		$can_edit = Capabilities::can_manage();

		// Success notice after saving goals.
		$notice = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading redirect message key for display only.
		if ( isset( $_GET['donadosu_msg'] ) && 'goals_saved' === sanitize_key( (string) wp_unslash( $_GET['donadosu_msg'] ) ) ) {
			$notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Campaign goals saved successfully.', 'donateocean-donation-suite' ) . '</p></div>';
		}

		$list_url   = admin_url( 'edit.php?post_type=donadosu_donation' );
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=donadosu_export_csv' ), 'donadosu_export_csv' );

		include DONADOSU_PATH . 'templates/admin-campaign-tracking.php';
	}

	/**
	 * Handle the save campaign goals form submission.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_save_goals(): void {
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage campaign goals.', 'donateocean-donation-suite' ) );
		}

		check_admin_referer( 'donadosu_save_campaign_goals' );

		$raw_goals = isset( $_POST['donadosu_goals'] ) && is_array( $_POST['donadosu_goals'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized individually below.
			? wp_unslash( $_POST['donadosu_goals'] )
			: array();

		$goals = array();
		foreach ( $raw_goals as $campaign => $amount ) {
			$campaign = sanitize_text_field( (string) $campaign );
			$amount   = (float) $amount;
			if ( '' !== $campaign && $amount > 0 ) {
				$goals[ $campaign ] = $amount;
			}
		}

		update_option( self::GOALS_OPTION, $goals );
		delete_transient( self::CACHE_KEY );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'  => 'donadosu_donation',
					'page'       => 'donadosu-campaigns',
					'donadosu_msg' => 'goals_saved',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Purge the cached campaign stats.
	 *
	 * Called when a donation is completed so numbers stay fresh.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function bust_cache(): void {
		delete_transient( self::CACHE_KEY );
	}
}
