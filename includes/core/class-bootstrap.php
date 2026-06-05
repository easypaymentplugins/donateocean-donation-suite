<?php
/**
 * Plugin bootstrap.
 *
 * Wires every hook, filter, and service that powers Donation Suite.
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

use DonationSuite\Admin\DeactivationFeedback;
use DonationSuite\Admin\DonationDetailPage;
use DonationSuite\Admin\DonorProfilePage;
use DonationSuite\Admin\RefundController;
use DonationSuite\Admin\ManualDonationPage;
use DonationSuite\Admin\ReviewNotice;
use DonationSuite\Admin\SettingsPage;
use DonationSuite\Admin\CampaignTrackingPage;
use DonationSuite\Admin\SubscriptionController;
use DonationSuite\Api\RestController;
use DonationSuite\Block\DonationBlock;
use DonationSuite\Campaign\CampaignStatsService;
use DonationSuite\Donation\DonationPostType;
use DonationSuite\Donation\CptDonationRepository;
use DonationSuite\Email\ReceiptEmailService;
use DonationSuite\Email\YearEndSummaryService;
use DonationSuite\Frontend\DonorPortalShortcode;
use DonationSuite\Frontend\Shortcode;
use DonationSuite\PayPal\OAuthTokenCache;
use DonationSuite\PayPal\PayPalClient;
use DonationSuite\Privacy\PrivacyTools;
use DonationSuite\Receipt\PdfReceiptController;
use DonationSuite\Reporting\AdminList;
use DonationSuite\Reporting\AnalyticsWidget;
use DonationSuite\Reporting\ExportController;
use DonationSuite\Reporting\ReportsPage;
use DonationSuite\Integration\ActiveCampaign;
use DonationSuite\Integration\Analytics;
use DonationSuite\Integration\Brevo;
use DonationSuite\Integration\ConstantContact;
use DonationSuite\Integration\ConstantContactOAuth;
use DonationSuite\Integration\GoogleSheets;
use DonationSuite\Integration\Mailchimp;
use DonationSuite\Integration\Slack;
use DonationSuite\Integration\Twilio;
use DonationSuite\Integration\Zapier;
use DonationSuite\Reporting\WebhookHealthWidget;

/**
 * Class Bootstrap
 *
 * Central entry point that registers every WordPress hook the plugin needs
 * and instantiates all service objects on `plugins_loaded`.
 *
 * @since 1.0.0
 */
class Bootstrap {

	/**
	 * Register all WordPress hooks, actions, and filters for the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( DonationPostType::class, 'register' ) );
		add_action( 'plugins_loaded', array( self::class, 'load_textdomain' ), 1 );
		add_action( 'plugins_loaded', array( self::class, 'services' ) );

		// Register and conditionally enqueue admin/frontend stylesheets so
		// templates never emit inline <style> blocks.
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_frontend_assets' ) );

		// Register custom cron schedules.
		add_filter( 'cron_schedules', array( self::class, 'register_cron_schedules' ) );

		// Donation lifecycle hooks.
		// Priority 10: send receipt to donor.
		add_action( 'donadosu_donation_completed', array( ReceiptEmailService::class, 'handle_completion' ), 10, 2 );
		// Priority 15: tribute notification.
		add_action( 'donadosu_donation_completed', array( ReceiptEmailService::class, 'handle_tribute_notification' ), 15, 2 );
		// Priority 20: notify admin / charity contact.
		add_action( 'donadosu_donation_completed', array( ReceiptEmailService::class, 'handle_admin_notification' ), 20, 2 );
		// Priority 30: bust the analytics stats cache so the dashboard widget
		// reflects the new donation immediately.
		add_action( 'donadosu_donation_completed', array( AnalyticsWidget::class, 'bust_cache' ), 30 );
		// Priority 31: bust campaign tracking cache.
		add_action( 'donadosu_donation_completed', array( CampaignTrackingPage::class, 'bust_cache' ), 31 );
		// Priority 32: bust reports page cache.
		add_action( 'donadosu_donation_completed', array( ReportsPage::class, 'bust_cache' ), 32 );

		// Scheduled tasks.
		add_action( 'donadosu_donation_retention', array( PrivacyTools::class, 'run_retention' ) );
		add_action( 'donadosu_donation_retention', array( \DonationSuite\PayPal\WebhookHandler::class, 'prune_processed_events' ) );
		add_action( 'donadosu_donation_reconcile', array( RestController::class, 'run_reconcile' ) );
		// Year-end summary — fires on Jan 1 each year.
		add_action( 'donadosu_donation_year_end_summary', array( self::class, 'run_year_end_summary' ) );
		// Webhook retry queue — fires every 5 minutes.
		add_action( 'donadosu_webhook_retry_cron', array( self::class, 'process_webhook_retry_queue' ) );
		// Card-recurring renewals — fires daily to charge due vaulted cards.
		add_action( 'donadosu_renewal_charges', array( RestController::class, 'run_renewal_charges' ) );

		// Manual year-end summary trigger from admin.
		add_action( 'admin_post_donadosu_year_end_summary', array( self::class, 'handle_year_end_summary_request' ) );

		// Sandbox mode banner — rendered inline on the settings page only.
		// See templates/admin-settings.php for the inline notice.
	}

	/**
	 * Load the plugin text domain for translations.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Required for custom language directory support.
		load_plugin_textdomain(
			'donateocean-donation-suite',
			false,
			dirname( plugin_basename( (string) DONADOSU_FILE ) ) . '/languages'
		);
	}

	/**
	 * Instantiate and register all plugin service objects.
	 *
	 * Creates shared dependencies (repository, config, logger, PayPal client)
	 * and passes them into the individual service constructors. Called on
	 * `plugins_loaded` so that all dependencies are available.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function services(): void {
		// Run schema migrations on upgrade (no-op when version matches).
		Installer::install_schema();

		$repository  = new CptDonationRepository();
		$config      = new ConfigService();
		$settings    = $config->get_all();
		$logger      = new \DonationSuite\Logging\Logger(
			(string) ( $settings['logging_level'] ?? 'error' ),
			! empty( $settings['enable_logging'] )
		);
		$token_cache = new OAuthTokenCache( $config, $logger );
		$paypal      = new PayPalClient( $config, $token_cache, $logger );

		// Admin pages & settings.
		( new SettingsPage() )->register();
		( new DonationDetailPage( $paypal ) )->register();
		( new DonorProfilePage() )->register();
		( new RefundController() )->register();
		( new SubscriptionController() )->register();
		( new PdfReceiptController() )->register();
		( new ManualDonationPage() )->register();
		( new CampaignTrackingPage() )->register();

		// Deactivation feedback modal & "leave a review" notice.
		( new DeactivationFeedback() )->register();
		( new ReviewNotice() )->register();

		// REST API.
		( new RestController( $repository, $config, $paypal, null, $logger ) )->register();

		// Frontend.
		( new Shortcode( $config ) )->register();
		( new DonorPortalShortcode() )->register();
		( new DonationBlock() )->register();

		// Reporting & dashboard widgets.
		( new AdminList( $repository ) )->register();
		( new ExportController( $repository ) )->register();
		( new AnalyticsWidget() )->register();
		( new ReportsPage() )->register();
		( new WebhookHealthWidget() )->register();

		// Privacy & compliance.
		( new PrivacyTools( $repository ) )->register();

		// Campaign stats (optimized queries).
		( new CampaignStatsService( $logger ) )->register();

		// Integrations.
		( new Analytics( $config ) )->register();
		( new Zapier( $config, $logger ) )->register();
		( new Slack( $config, $logger ) )->register();
		( new Twilio( $config, $logger ) )->register();
		( new Mailchimp( $config, $logger ) )->register();
		( new ConstantContact( $config, $logger ) )->register();
		( new ConstantContactOAuth( $config, $logger ) )->register();
		( new ActiveCampaign( $config, $logger ) )->register();
		( new Brevo( $config, $logger ) )->register();
		( new GoogleSheets( $config, $logger ) )->register();
	}

	/**
	 * Register the donor portal frontend stylesheet.
	 *
	 * Registered on every frontend request but only enqueued by the
	 * donor portal shortcode when actually rendered.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_frontend_assets(): void {
		wp_register_style(
			'donadosu-portal',
			DONADOSU_URL . 'assets/css/donor-portal.css',
			array(),
			DONADOSU_VERSION
		);
	}

	/**
	 * Register and enqueue admin stylesheets used by Donation Suite admin pages.
	 *
	 * Stylesheet is registered on every admin request but only enqueued on the
	 * specific Donation Suite screens and the dashboard (for the analytics
	 * widget) so it never loads on unrelated screens.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_admin_assets( string $hook ): void {
		wp_register_style(
			'donadosu-admin',
			DONADOSU_URL . 'assets/css/admin.css',
			array(),
			DONADOSU_VERSION
		);

		// Enqueue on the WordPress dashboard so the analytics widget renders correctly.
		if ( 'index.php' === $hook ) {
			wp_enqueue_style( 'donadosu-admin' );
			return;
		}

		// Enqueue on any Donation Suite admin page (settings already adds
		// its own stylesheet but having both is harmless and idempotent).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading current page slug for asset loading.
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';

		$donadosu_pages = array(
			'donadosu-detail',
			'donadosu-donor',
			'donadosu-manual',
			'donadosu-campaigns',
			'donadosu-reports',
		);

		if ( in_array( $page, $donadosu_pages, true ) ) {
			wp_enqueue_style( 'donadosu-admin' );
		}
	}

	/**
	 * Register the 'donadosu_yearly' cron interval WordPress does not include by default.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Existing cron schedules.
	 * @return array<string, array{interval:int, display:string}> Modified cron schedules.
	 */
	public static function register_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['donadosu_yearly'] ) ) {
			$schedules['donadosu_yearly'] = array(
				'interval' => YEAR_IN_SECONDS,
				'display'  => __( 'Once a year', 'donateocean-donation-suite' ),
			);
		}
		if ( ! isset( $schedules['donadosu_webhook_retry'] ) ) {
			$schedules['donadosu_webhook_retry'] = array(
				'interval' => 300, // Every 5 minutes.
				'display'  => __( 'Every 5 minutes (webhook retry)', 'donateocean-donation-suite' ),
			);
		}
		return $schedules;
	}

	/**
	 * Cron-triggered year-end summary handler.
	 *
	 * Fires via wp-cron on January 1 each year and sends summaries
	 * for the previous calendar year.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function run_year_end_summary(): void {
		$year = (int) gmdate( 'Y' ) - 1;
		YearEndSummaryService::send_summaries( $year );
	}

	/**
	 * Admin-post handler for the manual "Send now" year-end summary button.
	 *
	 * Accessible at admin-post.php?action=donadosu_year_end_summary. Verifies
	 * capabilities and nonce before sending summaries, then redirects back
	 * to the settings page with a count of sent emails.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function handle_year_end_summary_request(): void {
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'donateocean-donation-suite' ) );
		}

		check_admin_referer( 'donadosu_year_end_summary' );

		$year = absint( wp_unslash( $_GET['year'] ?? (int) gmdate( 'Y' ) - 1 ) );
		if ( $year < 2000 || $year > (int) gmdate( 'Y' ) ) {
			$year = (int) gmdate( 'Y' ) - 1;
		}
		$sent = YearEndSummaryService::send_summaries( $year );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'donadosu-settings',
					'tab'            => 'advanced',
					'donadosu_ye_sent' => $sent,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Process the webhook retry queue.
	 *
	 * Called every 5 minutes by a cron job. Attempts to reprocess any
	 * queued webhooks whose donation posts may have been created since
	 * the webhook first arrived.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function process_webhook_retry_queue(): void {
		$queued = get_option( 'donadosu_webhook_retry_queue', array() );
		if ( ! is_array( $queued ) || empty( $queued ) ) {
			return;
		}

		$config        = new ConfigService();
		$settings      = $config->get_all();
		$logger        = new \DonationSuite\Logging\Logger(
			(string) ( $settings['logging_level'] ?? 'error' ),
			! empty( $settings['enable_logging'] )
		);
		$repository    = new CptDonationRepository();
		$state_machine = new \DonationSuite\Donation\StateMachine();
		$token_cache   = new OAuthTokenCache( $config, $logger );
		$paypal        = new PayPalClient( $config, $token_cache, $logger );

		$handler = new \DonationSuite\PayPal\WebhookHandler(
			$repository,
			$config,
			$paypal,
			$state_machine,
			$logger
		);

		$updated_queue = array();
		$max_retries    = 3;

		foreach ( $queued as $event_id => $webhook ) {
			$retry_count = (int) ( $webhook['retry_count'] ?? 0 );

			// Only retry up to 3 times.
			if ( $retry_count >= $max_retries ) {
				$logger->warn(
					'Webhook retry abandoned after max retries',
					array(
						'event_id'   => $event_id,
						'retry_count' => $retry_count,
					)
				);
				continue;
			}

			// Attempt to reprocess the webhook.
			$result = $handler->handle( $webhook['raw_body'], $webhook['headers'] );

			if ( 200 === $result['status'] ) {
				// Fully processed (or deduplicated): remove from retry queue.
				$logger->info(
					'Webhook retried successfully',
					array(
						'event_id'    => $event_id,
						'retry_count' => $retry_count + 1,
					)
				);
				continue;
			}

			// 202 means the donation post still does not exist yet, and any
			// other non-2xx status is a transient failure. In both cases keep
			// the event queued with an incremented retry count so it is tried
			// again, rather than being silently dropped. (handle() may have
			// re-queued the entry itself, but this cron overwrites the option
			// at the end of the loop, so the cron must own retention here.)
			$webhook['retry_count'] = $retry_count + 1;
			$updated_queue[ $event_id ] = $webhook;

			$logger->warn(
				'Webhook retry failed, will retry again',
				array(
					'event_id'    => $event_id,
					'retry_count' => $retry_count + 1,
				)
			);
		}

		if ( empty( $updated_queue ) ) {
			delete_option( 'donadosu_webhook_retry_queue' );
		} else {
			update_option( 'donadosu_webhook_retry_queue', $updated_queue, false );
		}
	}
}
