<?php
/**
 * "Leave a review" admin notice.
 *
 * Shows a dismissible success notice on the Donation Suite settings screen
 * once the plugin has been active for at least one hour. Supports a 7-day
 * "remind me later" snooze and permanent dismissal.
 *
 * @package    Donation_Suite
 * @subpackage Admin
 * @since      1.0.6
 * @version    1.0.0
 */

namespace DonationSuite\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReviewNotice
 *
 * Renders and handles the "leave a review" admin notice.
 *
 * @since 1.0.6
 */
class ReviewNotice {

	/**
	 * Option storing the dismissal state ('', 'later', or 'never').
	 *
	 * @since 1.0.6
	 * @var string
	 */
	const HIDE_OPTION = 'donadosu_review_notice_hide';

	/**
	 * Option storing the earliest timestamp the notice may show again.
	 *
	 * @since 1.0.6
	 * @var string
	 */
	const NEXT_OPTION = 'donadosu_review_next_show';

	/**
	 * Nonce action protecting the AJAX dismissal.
	 *
	 * @since 1.0.6
	 * @var string
	 */
	const NONCE = 'donadosu_review_nonce';

	/**
	 * AJAX action name.
	 *
	 * @since 1.0.6
	 * @var string
	 */
	const ACTION = 'donadosu_handle_review_action';

	/**
	 * WordPress.org review URL.
	 *
	 * @since 1.0.6
	 * @var string
	 */
	const REVIEW_URL = 'https://wordpress.org/support/plugin/donateocean-donation-suite/reviews/#new-post';

	/**
	 * Settings page slug the notice appears on.
	 *
	 * @since 1.0.6
	 * @var string
	 */
	const PAGE_SLUG = 'donadosu-settings';

	/**
	 * Delay, in milliseconds, before the notice fades into view on the
	 * settings screen. The notice renders hidden and is revealed by
	 * assets/js/review-ajax.js once this delay elapses.
	 *
	 * @since 1.0.6
	 * @var int
	 */
	const REVEAL_DELAY_MS = 1;

	/**
	 * Guard so the notice renders at most once per request.
	 *
	 * @since 1.0.6
	 * @var bool
	 */
	private static $rendered = false;

	/**
	 * Register WordPress hooks. Called from Bootstrap::services().
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Whether the current request is the Donation Suite settings screen.
	 *
	 * @since 1.0.6
	 *
	 * @return bool
	 */
	private function is_settings_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
		return isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Render the review notice when all conditions are met.
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public function render(): void {
		if ( self::$rendered || ! $this->is_settings_screen() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::$rendered = true;

		// Reuse the activation timestamp the Installer already stores.
		$activation_time = (int) get_option( 'donadosu_activated_at' );
		if ( empty( $activation_time ) ) {
			$activation_time = time();
		}

		$hide_state   = get_option( self::HIDE_OPTION, '' );
		$next_show    = (int) get_option( self::NEXT_OPTION, time() );
		$since_active = time() - $activation_time;

		if ( 'never' === $hide_state || $since_active < HOUR_IN_SECONDS || time() < $next_show ) {
			return;
		}

		$plugin_name = 'DonateOcean – Donations via PayPal';
		$review_url  = self::REVIEW_URL;

		// The `--delayed` modifier keeps the notice hidden until review-ajax.js
		// fades it in after self::REVEAL_DELAY_MS.
		$html  = '<div class="notice donadosu-review-notice donadosu-review-notice--delayed">';

		// Leading heart badge — mirrors the plugin's dashicons-heart menu icon.
		$html .= '<span class="donadosu-review-notice__badge" aria-hidden="true"><span class="dashicons dashicons-heart"></span></span>';

		$html .= '<div class="donadosu-review-notice__body">';
		$html .= '<span class="donadosu-review-notice__eyebrow">' .
			esc_html__( 'Donation Suite', 'donateocean-donation-suite' ) . '</span>';
		$html .= '<h2 class="donadosu-review-notice__title">' .
			sprintf(
				/* translators: %s: plugin name. */
				esc_html__( 'Thank you for using %s 💕', 'donateocean-donation-suite' ),
				'<b>' . esc_html( $plugin_name ) . '</b>'
			) . '</h2>';

		$html .= '<p class="donadosu-review-notice__text">' .
			sprintf(
				wp_kses(
					/* translators: %1$s: URL to the plugin review page. */
					__( 'If you have a moment, we’d love it if you could leave us a <b><a target="_blank" href="%1$s">quick review</a>.</b> It motivates us and helps us keep improving. 💫 <br>Have feature ideas? Include them in your review — your feedback shapes our roadmap, and we love turning your ideas into reality.', 'donateocean-donation-suite' ),
					array(
						'b'  => array(),
						'a'  => array(
							'href'   => array(),
							'target' => array(),
						),
						'br' => array(),
					)
				),
				esc_url( $review_url )
			) . '</p>';

		$html .= '<div class="donadosu-review-notice__actions">';
		$html .= '<a target="_blank" class="donadosu-action-button donadosu-action-button--primary" data-action="reviewed" href="' . esc_url( $review_url ) . '">' .
			esc_html__( 'Write Review', 'donateocean-donation-suite' ) . '</a>';
		$html .= '<button type="button" class="donadosu-action-button" data-action="never">' .
			esc_html__( 'Done!', 'donateocean-donation-suite' ) . '</button>';
		$html .= '<a href="#" class="donadosu-action-button donadosu-action-button--link" data-action="never">' .
			esc_html__( 'Hide', 'donateocean-donation-suite' ) . '</a>';
		$html .= '<button type="button" class="donadosu-action-button" data-action="later">' .
			esc_html__( 'Remind me later', 'donateocean-donation-suite' ) . '</button>';
		$html .= '</div>';
		$html .= '</div>';

		$html .= '</div>';

		echo wp_kses(
			$html,
			array(
				'div'    => array( 'class' => array() ),
				'p'      => array( 'class' => array() ),
				'h2'     => array( 'class' => array() ),
				'span'   => array(
					'class'       => array(),
					'aria-hidden' => array(),
				),
				'b'      => array(),
				'br'     => array(),
				'a'      => array(
					'href'        => array(),
					'target'      => array(),
					'class'       => array(),
					'data-action' => array(),
				),
				'button' => array(
					'type'        => array(),
					'class'       => array(),
					'data-action' => array(),
				),
			)
		);
	}

	/**
	 * AJAX handler for the notice action buttons.
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public function handle_action(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$action = isset( $_POST['review_action'] ) ? sanitize_text_field( wp_unslash( $_POST['review_action'] ) ) : '';

		if ( 'later' === $action ) {
			update_option( self::NEXT_OPTION, time() + ( 7 * DAY_IN_SECONDS ) );
			update_option( self::HIDE_OPTION, 'later' );
		} elseif ( 'never' === $action || 'reviewed' === $action ) {
			update_option( self::HIDE_OPTION, 'never' );
		} else {
			wp_send_json_error( 'Invalid action' );
		}

		wp_send_json_success();
	}

	/**
	 * Enqueue the notice script on the settings screen and pass it the nonce.
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->is_settings_screen() ) {
			return;
		}
		wp_enqueue_style(
			'donadosu-review-notice',
			DONADOSU_URL . 'assets/css/review-notice.css',
			array(),
			DONADOSU_VERSION
		);
		wp_enqueue_script(
			'donadosu-review',
			DONADOSU_URL . 'assets/js/review-ajax.js',
			array( 'jquery' ),
			DONADOSU_VERSION,
			true
		);
		wp_localize_script(
			'donadosu-review',
			'donadosuReview',
			array(
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'review_url'   => self::REVIEW_URL,
				'reveal_delay' => self::REVEAL_DELAY_MS,
			)
		);
	}
}
