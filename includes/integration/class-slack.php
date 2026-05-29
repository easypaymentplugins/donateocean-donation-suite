<?php
/**
 * Slack integration.
 *
 * Sends formatted donation notifications to a configured Slack Incoming
 * Webhook URL whenever key donation events occur.
 *
 * @package    Donation_Suite
 * @subpackage Integration
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Integration;

use DonationSuite\Core\ConfigService;
use DonationSuite\Donation\DonationMeta;
use DonationSuite\Logging\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Slack
 *
 * Hooks into donation lifecycle events and sends rich Slack messages
 * via an Incoming Webhook URL configured in plugin settings.
 *
 * @since 1.0.0
 */
class Slack {

	/**
	 * Plugin configuration service.
	 *
	 * @since 1.0.0
	 * @var ConfigService
	 */
	private ConfigService $config;

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param ConfigService $config Plugin configuration service.
	 * @param Logger        $logger Logger instance.
	 */
	public function __construct( ConfigService $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * Register WordPress hooks for the Slack integration.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'donadosu_donation_completed', array( $this, 'handle_donation_completed' ), 50, 2 );
		add_action( 'donadosu_donation_disputed', array( $this, 'handle_donation_disputed' ), 50, 2 );
		add_action( 'transition_post_status', array( $this, 'handle_status_transition' ), 10, 3 );
	}

	/**
	 * Handle the donation_completed event.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id The donation post ID.
	 * @param array $payload The webhook payload from PayPal (if any).
	 * @return void
	 */
	public function handle_donation_completed( int $post_id, $payload = array() ): void {
		$this->send_notification( 'donation_completed', $post_id );
	}

	/**
	 * Handle the donation_disputed event.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id The donation post ID.
	 * @param array $payload The webhook payload from PayPal (if any).
	 * @return void
	 */
	public function handle_donation_disputed( int $post_id, $payload = array() ): void {
		$this->send_notification( 'donation_disputed', $post_id );
	}

	/**
	 * Handle post status transitions to catch refunds.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $new_status The new post status.
	 * @param string   $old_status The old post status.
	 * @param \WP_Post $post       The post object.
	 * @return void
	 */
	public function handle_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'donadosu_donation' !== $post->post_type || $new_status === $old_status ) {
			return;
		}

		if ( 'donadosu_refunded' === $new_status ) {
			$this->send_notification( 'donation_refunded', $post->ID );
		}
	}

	/**
	 * Send a formatted Slack message for a donation event.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type The event type identifier.
	 * @param int    $post_id    The donation post ID.
	 * @return bool True on success, false on failure or if integration is disabled.
	 */
	private function send_notification( string $event_type, int $post_id ): bool {
		$settings    = $this->config->get_all();
		$webhook_url = (string) ( $settings['slack_webhook_url'] ?? '' );
		$enabled     = ! empty( $settings['slack_enabled'] );

		if ( ! $enabled || '' === $webhook_url ) {
			return false;
		}

		// Check per-event toggles.
		$event_toggles = array(
			'donation_completed' => 'slack_on_completed',
			'donation_refunded'  => 'slack_on_refunded',
			'donation_disputed'  => 'slack_on_disputed',
		);

		$toggle_key = $event_toggles[ $event_type ] ?? '';
		if ( '' !== $toggle_key && empty( $settings[ $toggle_key ] ) ) {
			return false;
		}

		$message = $this->build_slack_message( $event_type, $post_id, $settings );

		$this->logger->info(
			sprintf( 'Slack: sending %s notification for donation #%d', $event_type, $post_id )
		);

		$response = wp_remote_post(
			$webhook_url,
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => (string) wp_json_encode( $message ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				sprintf(
					'Slack: failed to send %s notification for donation #%d — %s',
					$event_type,
					$post_id,
					$response->get_error_message()
				)
			);
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->logger->error(
				sprintf( 'Slack: unexpected HTTP %d for %s on donation #%d', $code, $event_type, $post_id )
			);
			return false;
		}

		$this->logger->info(
			sprintf( 'Slack: %s notification sent successfully for donation #%d', $event_type, $post_id )
		);

		return true;
	}

	/**
	 * Build a Slack Block Kit message payload for a donation event.
	 *
	 * @since 1.0.0
	 *
	 * @param string              $event_type The event type identifier.
	 * @param int                 $post_id    The donation post ID.
	 * @param array<string,mixed> $settings   Plugin settings.
	 * @return array<string,mixed> The Slack message payload.
	 */
	private function build_slack_message( string $event_type, int $post_id, array $settings ): array {
		$meta = static function ( string $key ) use ( $post_id ) {
			return (string) get_post_meta( $post_id, $key, true );
		};

		$amount     = $meta( DonationMeta::AMOUNT );
		$currency   = $meta( DonationMeta::CURRENCY ) ?: 'USD';
		$donor_name = $meta( DonationMeta::DONOR_NAME ) ?: 'Anonymous';
		$frequency  = $meta( DonationMeta::DONATION_FREQUENCY ) ?: 'one_time';
		$campaign   = $meta( DonationMeta::CAMPAIGN );
		$is_anon    = '1' === $meta( DonationMeta::IS_ANONYMOUS );

		$display_name = $is_anon ? 'Anonymous' : self::escape_mrkdwn( $donor_name );
		$amount       = self::escape_mrkdwn( $amount );
		$currency     = self::escape_mrkdwn( $currency );
		$campaign     = self::escape_mrkdwn( $campaign );

		$event_config = array(
			'donation_completed' => array(
				'emoji' => ':white_check_mark:',
				'title' => __( 'New Donation Received', 'donateocean-donation-suite' ),
				'color' => '#22c55e',
			),
			'donation_refunded'  => array(
				'emoji' => ':rotating_light:',
				'title' => __( 'Donation Refunded', 'donateocean-donation-suite' ),
				'color' => '#ef4444',
			),
			'donation_disputed'  => array(
				'emoji' => ':warning:',
				'title' => __( 'Donation Disputed', 'donateocean-donation-suite' ),
				'color' => '#f59e0b',
			),
		);

		$cfg   = $event_config[ $event_type ] ?? $event_config['donation_completed'];
		$emoji = $cfg['emoji'];
		$title = $cfg['title'];
		$color = $cfg['color'];

		$frequency_label = 'one_time' === $frequency ? __( 'One-time', 'donateocean-donation-suite' ) : ucfirst( $frequency );

		$fields = array(
			array(
				'type' => 'mrkdwn',
				'text' => "*Amount:*\n{$currency} {$amount}",
			),
			array(
				'type' => 'mrkdwn',
				'text' => "*Donor:*\n{$display_name}",
			),
			array(
				'type' => 'mrkdwn',
				'text' => "*Frequency:*\n{$frequency_label}",
			),
		);

		if ( '' !== $campaign ) {
			$fields[] = array(
				'type' => 'mrkdwn',
				'text' => "*Campaign:*\n{$campaign}",
			);
		}

		$admin_url = admin_url( 'admin.php?page=donadosu-detail&id=' . $post_id );

		$channel = (string) ( $settings['slack_channel'] ?? '' );

		$payload = array(
			'attachments' => array(
				array(
					'color'  => $color,
					'blocks' => array(
						array(
							'type' => 'section',
							'text' => array(
								'type' => 'mrkdwn',
								'text' => "{$emoji} *{$title}*",
							),
						),
						array(
							'type'   => 'section',
							'fields' => $fields,
						),
						array(
							'type'     => 'actions',
							'elements' => array(
								array(
									'type' => 'button',
									'text' => array(
										'type' => 'plain_text',
										'text' => 'View Donation',
									),
									'url'  => $admin_url,
								),
							),
						),
					),
				),
			),
		);

		if ( '' !== $channel ) {
			$payload['channel'] = $channel;
		}

		return $payload;
	}

	/**
	 * Escape special Slack mrkdwn characters in user-supplied data.
	 *
	 * Prevents donor names, campaign values, or amounts containing
	 * characters like *, _, ~, > from breaking the message layout.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text The text to escape.
	 * @return string The escaped text.
	 */
	private static function escape_mrkdwn( string $text ): string {
		// Slack mrkdwn formatting characters that could alter rendering.
		return str_replace(
			array( '&', '<', '>', '*', '_', '~', '`' ),
			array( '&amp;', '&lt;', '&gt;', '\*', '\_', '\~', '\`' ),
			$text
		);
	}

	/**
	 * Send a test notification to verify the Slack webhook.
	 *
	 * @since 1.0.0
	 *
	 * @param string $webhook_url The Slack webhook URL to test.
	 * @param string $channel     Optional channel override.
	 * @return array{success: bool, message: string} Result of the test.
	 */
	public static function send_test( string $webhook_url, string $channel = '' ): array {
		$payload = array(
			'attachments' => array(
				array(
					'color'  => '#6366f1',
					'blocks' => array(
						array(
							'type' => 'section',
							'text' => array(
								'type' => 'mrkdwn',
								'text' => ":test_tube: *Donation Suite — Test Notification*\nYour Slack integration is working correctly.",
							),
						),
						array(
							'type'   => 'section',
							'fields' => array(
								array(
									'type' => 'mrkdwn',
									'text' => "*Amount:*\nUSD 25.00",
								),
								array(
									'type' => 'mrkdwn',
									'text' => "*Donor:*\nTest Donor",
								),
							),
						),
					),
				),
			),
		);

		if ( '' !== $channel ) {
			$payload['channel'] = $channel;
		}

		$response = wp_remote_post(
			$webhook_url,
			array(
				'timeout'     => 15,
				'httpversion' => '1.1',
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => (string) wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array(
				'success' => true,
				'message' => __( 'Test notification sent successfully. Check your Slack channel.', 'donateocean-donation-suite' ),
			);
		}

		return array(
			'success' => false,
			/* translators: %d: HTTP status code returned by Slack. */
			'message' => sprintf( __( 'Slack returned HTTP %d. Check your webhook URL.', 'donateocean-donation-suite' ), $code ),
		);
	}
}
