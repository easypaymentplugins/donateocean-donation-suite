<?php
/**
 * Constant Contact OAuth2 handler.
 *
 * Constant Contact's v3 API authenticates exclusively with OAuth2 access
 * tokens (it does not accept a static API key). This class implements the
 * Authorization Code flow: it builds the authorize URL, handles the redirect
 * callback, exchanges the code for access + refresh tokens, persists them, and
 * transparently refreshes the access token (which expires every 24 hours)
 * before it is used by the ConstantContact integration.
 *
 * Tokens are stored in a dedicated option (separate from the settings form)
 * so writing them never triggers the settings sanitize callback.
 *
 * @package    Donation_Suite
 * @subpackage Integration
 * @since      1.0.5
 * @version    1.0.5
 */

namespace DonationSuite\Integration;

use DonationSuite\Core\ConfigService;
use DonationSuite\Logging\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConstantContactOAuth
 *
 * Manages the Constant Contact OAuth2 connection lifecycle.
 *
 * @since 1.0.5
 */
class ConstantContactOAuth {

	/**
	 * Constant Contact OAuth2 authorization endpoint.
	 *
	 * @since 1.0.5
	 * @var string
	 */
	private const AUTHORIZE_URL = 'https://authz.constantcontact.com/oauth2/default/v1/authorize';

	/**
	 * Constant Contact OAuth2 token endpoint.
	 *
	 * @since 1.0.5
	 * @var string
	 */
	private const TOKEN_URL = 'https://authz.constantcontact.com/oauth2/default/v1/token';

	/**
	 * Constant Contact v3 API base URL.
	 *
	 * @since 1.0.5
	 * @var string
	 */
	private const API_BASE = 'https://api.cc.email/v3';

	/**
	 * OAuth2 scopes requested. offline_access yields a refresh token.
	 *
	 * @since 1.0.5
	 * @var string
	 */
	private const SCOPES = 'contact_data offline_access';

	/**
	 * Dedicated option storing the OAuth token set.
	 *
	 * @since 1.0.5
	 * @var string
	 */
	public const TOKENS_OPTION = 'donadosu_cc_tokens';

	/**
	 * Plugin configuration service.
	 *
	 * @since 1.0.5
	 * @var ConfigService
	 */
	private ConfigService $config;

	/**
	 * Logger instance.
	 *
	 * @since 1.0.5
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.5
	 *
	 * @param ConfigService $config Plugin configuration service.
	 * @param Logger        $logger Logger instance.
	 */
	public function __construct( ConfigService $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * Register the connect / disconnect / callback admin-post handlers.
	 *
	 * @since 1.0.5
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_donadosu_cc_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_donadosu_cc_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_donadosu_cc_callback', array( $this, 'handle_callback' ) );
	}

	/**
	 * The OAuth2 redirect URI. Must be registered verbatim in the Constant
	 * Contact application settings (Redirect URIs).
	 *
	 * @since 1.0.5
	 *
	 * @return string The redirect URI.
	 */
	public function get_redirect_uri(): string {
		return admin_url( 'admin-post.php?action=donadosu_cc_callback' );
	}

	/**
	 * The stored OAuth token set.
	 *
	 * @since 1.0.5
	 *
	 * @return array<string,mixed> The token set (access_token, refresh_token, expires, account).
	 */
	private function tokens(): array {
		return (array) get_option( self::TOKENS_OPTION, array() );
	}

	/**
	 * Whether a Constant Contact account is currently connected.
	 *
	 * @since 1.0.5
	 *
	 * @return bool True when a refresh token is stored.
	 */
	public function is_connected(): bool {
		return '' !== (string) ( $this->tokens()['refresh_token'] ?? '' );
	}

	/**
	 * Get the display name of the connected Constant Contact account.
	 *
	 * @since 1.0.5
	 *
	 * @return string The account/organization name, or empty string.
	 */
	public function connected_account(): string {
		return (string) ( $this->tokens()['account'] ?? '' );
	}

	/**
	 * Build the authorize URL the administrator is redirected to.
	 *
	 * @since 1.0.5
	 *
	 * @param string $state Opaque CSRF state value.
	 * @return string The fully-formed authorize URL, or empty string if creds missing.
	 */
	private function build_authorize_url( string $state ): string {
		$client_id = (string) ( $this->config->get_all()['cc_client_id'] ?? '' );

		if ( '' === $client_id ) {
			return '';
		}

		return add_query_arg(
			array(
				'client_id'     => rawurlencode( $client_id ),
				'redirect_uri'  => rawurlencode( $this->get_redirect_uri() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( self::SCOPES ),
				'state'         => rawurlencode( $state ),
			),
			self::AUTHORIZE_URL
		);
	}

	/**
	 * Handle the "Connect" button: start the OAuth2 flow.
	 *
	 * @since 1.0.5
	 *
	 * @return void
	 */
	public function handle_connect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'donateocean-donation-suite' ) );
		}
		check_admin_referer( 'donadosu_cc_connect' );

		$settings = $this->config->get_all();
		if ( '' === (string) ( $settings['cc_client_id'] ?? '' ) || '' === (string) ( $settings['cc_client_secret'] ?? '' ) ) {
			$this->redirect_to_settings( 'error', __( 'Enter and save your Client ID and Client Secret before connecting.', 'donateocean-donation-suite' ) );
		}

		// CSRF state, stored per-user and consumed on callback.
		$state = wp_generate_password( 32, false );
		set_transient( 'donadosu_cc_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );

		$url = $this->build_authorize_url( $state );
		if ( '' === $url ) {
			$this->redirect_to_settings( 'error', __( 'Could not build the Constant Contact authorization URL.', 'donateocean-donation-suite' ) );
		}

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External OAuth provider, not a local path.
		exit;
	}

	/**
	 * Handle the OAuth2 redirect callback from Constant Contact.
	 *
	 * @since 1.0.5
	 *
	 * @return void
	 */
	public function handle_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'donateocean-donation-suite' ) );
		}

		// Surface provider-side errors (e.g. access_denied).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth2 callback; authenticity is verified via the 'state' parameter with hash_equals() below, not a WP nonce.
		$provider_error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		if ( '' !== $provider_error ) {
			$this->logger->warn( 'Constant Contact OAuth error returned by provider', array( 'error' => $provider_error ) );
			$this->redirect_to_settings( 'error', __( 'Constant Contact authorization was cancelled or denied.', 'donateocean-donation-suite' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth2 callback; authenticity is verified via the 'state' parameter with hash_equals() below, not a WP nonce.
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth2 callback; authenticity is verified via the 'state' parameter with hash_equals() below, not a WP nonce.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		$expected_state = (string) get_transient( 'donadosu_cc_oauth_state_' . get_current_user_id() );
		delete_transient( 'donadosu_cc_oauth_state_' . get_current_user_id() );

		if ( '' === $code || '' === $state || '' === $expected_state || ! hash_equals( $expected_state, $state ) ) {
			$this->logger->warn( 'Constant Contact OAuth callback failed state validation' );
			$this->redirect_to_settings( 'error', __( 'Constant Contact authorization could not be verified. Please try again.', 'donateocean-donation-suite' ) );
		}

		$result = $this->exchange_code( $code );
		if ( ! $result['success'] ) {
			$this->redirect_to_settings( 'error', $result['message'] );
		}

		// Best-effort: fetch the account name for display in settings.
		$account = $this->fetch_account_name();
		if ( '' !== $account ) {
			$this->store( array( 'account' => $account ) );
		}

		$this->logger->info( 'Constant Contact account connected via OAuth2' );
		$this->redirect_to_settings( 'connected', __( 'Constant Contact connected successfully.', 'donateocean-donation-suite' ) );
	}

	/**
	 * Handle the "Disconnect" button: clear stored tokens.
	 *
	 * @since 1.0.5
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'donateocean-donation-suite' ) );
		}
		check_admin_referer( 'donadosu_cc_disconnect' );

		delete_option( self::TOKENS_OPTION );

		$this->logger->info( 'Constant Contact account disconnected' );
		$this->redirect_to_settings( 'disconnected', __( 'Constant Contact disconnected.', 'donateocean-donation-suite' ) );
	}

	/**
	 * Exchange an authorization code for access + refresh tokens.
	 *
	 * @since 1.0.5
	 *
	 * @param string $code The authorization code.
	 * @return array{success: bool, message: string}
	 */
	private function exchange_code( string $code ): array {
		return $this->token_request(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => $this->get_redirect_uri(),
			)
		);
	}

	/**
	 * Return a valid (non-expired) access token, refreshing if necessary.
	 *
	 * @since 1.0.5
	 *
	 * @return string The access token, or empty string when unavailable.
	 */
	public function get_valid_access_token(): string {
		$tokens        = $this->tokens();
		$access_token  = (string) ( $tokens['access_token'] ?? '' );
		$refresh_token = (string) ( $tokens['refresh_token'] ?? '' );
		$expires       = (int) ( $tokens['expires'] ?? 0 );

		if ( '' === $refresh_token ) {
			return '';
		}

		// Reuse the current token while it has more than 60s of life left.
		if ( '' !== $access_token && $expires > ( time() + 60 ) ) {
			return $access_token;
		}

		$result = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			)
		);

		if ( ! $result['success'] ) {
			$this->logger->error( 'Constant Contact: token refresh failed — ' . $result['message'] );
			return '';
		}

		return (string) ( $this->tokens()['access_token'] ?? '' );
	}

	/**
	 * Perform a token endpoint request (code exchange or refresh) and persist
	 * the resulting tokens.
	 *
	 * @since 1.0.5
	 *
	 * @param array<string,string> $body The grant-specific request body.
	 * @return array{success: bool, message: string}
	 */
	private function token_request( array $body ): array {
		$settings      = $this->config->get_all();
		$client_id     = (string) ( $settings['cc_client_id'] ?? '' );
		$client_secret = (string) ( $settings['cc_client_secret'] ?? '' );

		if ( '' === $client_id || '' === $client_secret ) {
			return array(
				'success' => false,
				'message' => __( 'Constant Contact Client ID and Secret are required.', 'donateocean-donation-suite' ),
			);
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth per OAuth2 spec.
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$msg = is_array( $data ) && ! empty( $data['error_description'] )
				? (string) $data['error_description']
				/* translators: %d: HTTP status code returned by Constant Contact. */
				: sprintf( __( 'Constant Contact returned HTTP %d during token exchange.', 'donateocean-donation-suite' ), $code );
			return array(
				'success' => false,
				'message' => $msg,
			);
		}

		$new = array(
			'access_token' => sanitize_text_field( (string) $data['access_token'] ),
			'expires'      => time() + (int) ( $data['expires_in'] ?? 3600 ),
		);
		// A refresh token is only returned when offline_access is granted; keep
		// the existing one when the response omits it (e.g. some refresh calls).
		if ( ! empty( $data['refresh_token'] ) ) {
			$new['refresh_token'] = sanitize_text_field( (string) $data['refresh_token'] );
		}

		$this->store( $new );

		return array(
			'success' => true,
			'message' => '',
		);
	}

	/**
	 * Fetch the connected account's organization name for display.
	 *
	 * @since 1.0.5
	 *
	 * @return string The organization name, or empty string.
	 */
	private function fetch_account_name(): string {
		$token = $this->get_valid_access_token();
		if ( '' === $token ) {
			return '';
		}

		$response = wp_remote_get(
			self::API_BASE . '/account/summary',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return '';
		}

		return sanitize_text_field( (string) ( $data['organization_name'] ?? ( $data['contact_email'] ?? '' ) ) );
	}

	/**
	 * Merge the given keys into the stored token set.
	 *
	 * @since 1.0.5
	 *
	 * @param array<string,mixed> $values Token fields to write.
	 * @return void
	 */
	private function store( array $values ): void {
		$tokens = $this->tokens();
		foreach ( $values as $key => $value ) {
			$tokens[ $key ] = $value;
		}
		update_option( self::TOKENS_OPTION, $tokens, false );
	}

	/**
	 * Redirect back to the Integrations settings tab with a status notice.
	 *
	 * @since 1.0.5
	 *
	 * @param string $status  Status slug (connected|disconnected|error).
	 * @param string $message Human-readable message (stored in a transient).
	 * @return void
	 */
	private function redirect_to_settings( string $status, string $message ): void {
		set_transient( 'donadosu_cc_notice_' . get_current_user_id(), array( 'status' => $status, 'message' => $message ), MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'donadosu-settings',
					'tab'         => 'integrations',
					'donadosu_cc' => $status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
