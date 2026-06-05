<?php
/**
 * Deactivation feedback modal.
 *
 * Shows a feedback modal on the Plugins screen when an administrator
 * deactivates the plugin, asking why. The selected reason plus optional
 * free text and non-personal environment information are forwarded to a
 * site-configured remote endpoint. Deactivation is never blocked — even
 * when the remote call fails or no endpoint is configured.
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
 * Class DeactivationFeedback
 *
 * Renders the deactivation feedback modal and handles its AJAX submission.
 *
 * @since 1.0.6
 */
class DeactivationFeedback {

	/**
	 * Nonce action protecting the AJAX submission.
	 *
	 * @since 1.0.6
	 * @var string
	 */
	const NONCE = 'donadosu-deactivation';

	/**
	 * AJAX action name.
	 *
	 * @since 1.0.6
	 * @var string
	 */
	const ACTION = 'donadosu_send_deactivation';

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
		add_action( 'admin_footer', array( $this, 'render_modal' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * Print the modal markup in the footer of the Plugins screen only.
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public function render_modal(): void {
		global $pagenow;
		if ( 'plugins.php' !== $pagenow ) {
			return;
		}
		require_once DONADOSU_PATH . 'includes/admin/views/deactivation-feedback-form.php';
	}

	/**
	 * Enqueue the modal stylesheet and script on the Plugins screen only.
	 *
	 * @since 1.0.6
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'plugins.php' !== $hook ) {
			return;
		}

		// jQuery blockUI ships with WordPress core; used for the loading overlay.
		wp_enqueue_script( 'jquery-blockui' );

		wp_enqueue_style(
			'donadosu-deactivation-feedback',
			DONADOSU_URL . 'assets/css/deactivation-feedback-modal.css',
			array(),
			DONADOSU_VERSION
		);

		wp_enqueue_script(
			'donadosu-deactivation-feedback',
			DONADOSU_URL . 'assets/js/deactivation-feedback-modal.js',
			array( 'jquery' ),
			DONADOSU_VERSION,
			true
		);

		$basename = defined( 'DONADOSU_BASENAME' ) ? DONADOSU_BASENAME : plugin_basename( (string) DONADOSU_FILE );

		wp_localize_script(
			'donadosu-deactivation-feedback',
			'donadosuFeedback',
			array(
				'nonce'      => wp_create_nonce( self::NONCE ),
				'pluginFile' => $basename,
				'slug'       => dirname( $basename ),
			)
		);
	}

	/**
	 * AJAX handler.
	 *
	 * Validates the request, forwards the feedback to the configured endpoint,
	 * and always responds with success so the deactivation can proceed.
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public function handle_request(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( 'POST' !== $method ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request method.', 'donateocean-donation-suite' ) ), 405 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'donateocean-donation-suite' ) ), 403 );
		}

		$reason         = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$reason_details = isset( $_POST['reason_details'] ) ? sanitize_text_field( wp_unslash( $_POST['reason_details'] ) ) : '';

		$this->send_feedback( $reason, $reason_details );

		wp_send_json_success( array( 'message' => __( 'Feedback received.', 'donateocean-donation-suite' ) ) );
	}

	/**
	 * Forward feedback to the site-configured remote endpoint.
	 *
	 * No endpoint or token is shipped with the plugin. Configure them per-site
	 * (for example, in wp-config.php) so no secret lives in the public source:
	 *
	 *     define( 'DONADOSU_FEEDBACK_ENDPOINT', 'https://api.airtable.com/v0/BASE_ID/Sheet1' );
	 *     define( 'DONADOSU_FEEDBACK_TOKEN', 'your-write-only-token' );
	 *
	 * The endpoint and token may also be supplied through the
	 * `donadosu_deactivation_feedback_endpoint` and
	 * `donadosu_deactivation_feedback_token` filters. When neither is
	 * configured the request is skipped silently and deactivation still
	 * proceeds. The default body shape matches Airtable's REST API.
	 *
	 * @since 1.0.6
	 *
	 * @param string $reason         Selected reason label.
	 * @param string $reason_details Optional free-text details.
	 * @return void
	 */
	private function send_feedback( string $reason, string $reason_details ): void {
		$endpoint = defined( 'DONADOSU_FEEDBACK_ENDPOINT' ) ? (string) DONADOSU_FEEDBACK_ENDPOINT : '';
		$token    = defined( 'DONADOSU_FEEDBACK_TOKEN' ) ? (string) DONADOSU_FEEDBACK_TOKEN : '';

		/**
		 * Filter the deactivation-feedback endpoint URL.
		 *
		 * @since 1.0.6
		 *
		 * @param string $endpoint Endpoint URL. Empty disables the request.
		 */
		$endpoint = (string) apply_filters( 'donadosu_deactivation_feedback_endpoint', $endpoint );

		/**
		 * Filter the deactivation-feedback authorization token.
		 *
		 * @since 1.0.6
		 *
		 * @param string $token Bearer token. Empty disables the request.
		 */
		$token = (string) apply_filters( 'donadosu_deactivation_feedback_token', $token );

		// Nothing to send to — skip quietly so deactivation is never blocked.
		if ( '' === $endpoint || '' === $token ) {
			return;
		}

		$theme = wp_get_theme();

		$data = array(
			'reason'         => $reason . ( '' !== $reason_details ? ' : ' . $reason_details : '' ),
			'plugin'         => 'DonateOcean',
			'php_version'    => phpversion(),
			'wp_version'     => get_bloginfo( 'version' ),
			'locale'         => get_locale(),
			'theme'          => $theme->get( 'Name' ),
			'theme_version'  => $theme->get( 'Version' ),
			'multisite'      => is_multisite() ? 'Yes' : 'No',
			'plugin_version' => defined( 'DONADOSU_VERSION' ) ? DONADOSU_VERSION : '',
		);

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'records' => array(
						array(
							'fields' => array(
								'reason' => wp_json_encode( $data ),
								'date'   => current_time( 'mysql' ),
							),
						),
					),
				)
			),
			'method'  => 'POST',
			'timeout' => 10,
		);

		// Fire-and-forget: the result never blocks the deactivation experience.
		wp_remote_post( $endpoint, $args );
	}
}
