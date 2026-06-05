<?php
/**
 * Privacy tools.
 *
 * Integrates with the WordPress personal data exporter and eraser system
 * and provides automatic PII retention cleanup.
 *
 * @package    Donation_Suite
 * @subpackage Privacy
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Privacy;

use DonationSuite\Core\ConfigService;
use DonationSuite\Donation\DonationMeta;
use DonationSuite\Donation\DonationRepositoryInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PrivacyTools
 *
 * Registers personal data exporters and erasers for GDPR compliance and
 * provides a retention cleanup method for automatic PII removal.
 *
 * @since 1.0.0
 */
class PrivacyTools {

	/**
	 * Donation repository instance.
	 *
	 * @since 1.0.0
	 * @var DonationRepositoryInterface
	 */
	private DonationRepositoryInterface $repository;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param DonationRepositoryInterface $repository Donation repository.
	 */
	public function __construct( DonationRepositoryInterface $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register WordPress privacy hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Register suggested privacy-policy text describing the donor data the
	 * plugin collects, the processors it may share it with, and retention.
	 *
	 * Surfaces in Settings → Privacy → "Check Privacy Policy" so site owners
	 * can include accurate disclosures.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'This site uses the DonateOcean donation plugin to accept donations. When you make a donation we collect the information you provide on the donation form — which may include your name, email address, phone number, billing and/or shipping address, donation amount, currency, campaign, and any message or tribute details — together with technical data such as your IP address and the time of the donation.', 'donateocean-donation-suite' ) . '</p>';

		$content .= '<p>' . esc_html__( 'Donation records and donor profiles are stored in this site\'s database. Payments are processed by PayPal; the plugin never stores your full card details. PDF receipts are generated on demand and are not retained on the server after delivery.', 'donateocean-donation-suite' ) . '</p>';

		$content .= '<p>' . esc_html__( 'Depending on the features this site has enabled, your donation details may be shared with third-party processors: PayPal (payment processing) and, where configured, email/CRM and notification services (for example Mailchimp, Brevo, ActiveCampaign, Constant Contact, Google Sheets, Slack, Twilio, or Zapier). You are only added to a marketing service when you have given consent on the donation form. See the plugin\'s External Services documentation for details of each service and links to their privacy policies.', 'donateocean-donation-suite' ) . '</p>';

		$content .= '<p>' . esc_html__( 'Donation records may be retained for the period configured by the site administrator (for accounting and tax-reporting purposes) and are anonymised or removed after that period. You can request export or erasure of your personal data using this site\'s privacy tools.', 'donateocean-donation-suite' ) . '</p>';

		wp_add_privacy_policy_content(
			__( 'DonateOcean – Donations via PayPal', 'donateocean-donation-suite' ),
			wp_kses_post( $content )
		);
	}

	/**
	 * Register the Donation Suite personal data exporter.
	 *
	 * @since 1.0.0
	 *
	 * @param array $exporters Existing exporters.
	 * @return array Modified exporters array.
	 */
	public function exporters( array $exporters ): array {
		$exporters['donateocean-donation-suite'] = array(
			'exporter_friendly_name' => __( 'Donation Suite', 'donateocean-donation-suite' ),
			'callback'               => array( $this, 'export_data' ),
		);
		return $exporters;
	}

	/**
	 * Register the Donation Suite personal data eraser.
	 *
	 * @since 1.0.0
	 *
	 * @param array $erasers Existing erasers.
	 * @return array Modified erasers array.
	 */
	public function erasers( array $erasers ): array {
		$erasers['donateocean-donation-suite'] = array(
			'eraser_friendly_name' => __( 'Donation Suite', 'donateocean-donation-suite' ),
			'callback'             => array( $this, 'erase_data' ),
		);
		return $erasers;
	}

	/**
	 * Export personal donation data for a given email address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Email address to export data for.
	 * @param int    $page  Page number for paginated export.
	 * @return array Export result with data and done flag.
	 */
	public function export_data( string $email, int $page ): array {
		$posts = get_posts(
			array(
				'post_type'   => 'donadosu_donation',
				'post_status' => 'any',
				'meta_key'    => DonationMeta::DONOR_EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for privacy data lookup by donor email.
				'meta_value'  => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for privacy data lookup by donor email.
				'numberposts' => 50,
				'offset'      => ( $page - 1 ) * 50,
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$data = array(
				array(
					'name'  => __( 'Receipt', 'donateocean-donation-suite' ),
					'value' => (string) get_post_meta( $post->ID, DonationMeta::RECEIPT_NO, true ),
				),
				array(
					'name'  => __( 'Donor name', 'donateocean-donation-suite' ),
					'value' => (string) get_post_meta( $post->ID, DonationMeta::DONOR_NAME, true ),
				),
				array(
					'name'  => __( 'Donor email', 'donateocean-donation-suite' ),
					'value' => (string) get_post_meta( $post->ID, DonationMeta::DONOR_EMAIL, true ),
				),
				array(
					'name'  => __( 'Donor phone', 'donateocean-donation-suite' ),
					'value' => (string) get_post_meta( $post->ID, DonationMeta::DONOR_PHONE, true ),
				),
				array(
					'name'  => __( 'Donor company', 'donateocean-donation-suite' ),
					'value' => (string) get_post_meta( $post->ID, DonationMeta::DONOR_COMPANY, true ),
				),
				array(
					'name'  => __( 'Donor address', 'donateocean-donation-suite' ),
					'value' => (string) get_post_meta( $post->ID, DonationMeta::DONOR_ADDRESS, true ),
				),
				array(
					'name'  => __( 'Donor city', 'donateocean-donation-suite' ),
					'value' => (string) get_post_meta( $post->ID, DonationMeta::DONOR_CITY, true ),
				),
				array(
					'name'  => __( 'Donor postal code', 'donateocean-donation-suite' ),
					'value' => (string) get_post_meta( $post->ID, DonationMeta::DONOR_POSTAL, true ),
				),
				array(
					'name'  => __( 'Donor message', 'donateocean-donation-suite' ),
					'value' => (string) get_post_meta( $post->ID, DonationMeta::DONOR_MESSAGE, true ),
				),
				array(
					'name'  => __( 'Amount', 'donateocean-donation-suite' ),
					'value' => (string) get_post_meta( $post->ID, DonationMeta::AMOUNT, true ),
				),
				array(
					'name'  => __( 'Currency', 'donateocean-donation-suite' ),
					'value' => (string) get_post_meta( $post->ID, DonationMeta::CURRENCY, true ),
				),
			);

			// Filter out empty values to keep the export clean.
			$data = array_filter(
				$data,
				static function ( array $item ): bool {
					return '' !== $item['value'];
				}
			);

			$items[] = array(
				'group_id'    => 'donateocean-donation-suite',
				'group_label' => __( 'Donation Suite', 'donateocean-donation-suite' ),
				'item_id'     => 'donation-' . $post->ID,
				'data'        => array_values( $data ),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $posts ) < 50,
		);
	}

	/**
	 * Erase personal donation data for a given email address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Email address to erase data for.
	 * @param int    $page  Page number for paginated erasure.
	 * @return array Erasure result with counts and done flag.
	 */
	public function erase_data( string $email, int $page ): array {
		$posts = get_posts(
			array(
				'post_type'   => 'donadosu_donation',
				'post_status' => 'any',
				'meta_key'    => DonationMeta::DONOR_EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for privacy data lookup by donor email.
				'meta_value'  => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for privacy data lookup by donor email.
				'numberposts' => 50,
				'offset'      => ( $page - 1 ) * 50,
			)
		);

		foreach ( $posts as $post ) {
			self::erase_pii( (int) $post->ID, false );
		}

		$messages = array();

		// Best-effort scrub of the email from existing plugin log files, and an
		// honest notice that copies may persist in third-party services the
		// plugin pushed data to, which must be removed in those services.
		if ( '' !== $email ) {
			\DonationSuite\Logging\Logger::scrub_from_logs( $email );

			$processors = self::active_data_processors();
			if ( ! empty( $processors ) ) {
				$messages[] = sprintf(
					/* translators: %s: comma-separated list of third-party service names */
					__( 'This donor\'s name and email may also have been sent to the following third-party services, which are outside this site and must be erased directly with each provider: %s.', 'donateocean-donation-suite' ),
					implode( ', ', $processors )
				);
			}
		}

		return array(
			'items_removed'  => count( $posts ),
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => count( $posts ) < 50,
		);
	}

	/**
	 * Names of third-party services that are enabled and may hold donor data.
	 *
	 * Used to give an honest erasure message: this plugin cannot delete data
	 * already pushed to external processors, so it tells the admin which ones
	 * to clear manually.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> Human-readable service names.
	 */
	private static function active_data_processors(): array {
		$settings = ( new ConfigService() )->get_all();
		$map      = array(
			'mailchimp_auto_subscribe' => 'Mailchimp',
			'brevo_auto_subscribe'     => 'Brevo',
			'ac_auto_subscribe'        => 'ActiveCampaign',
			'cc_auto_subscribe'        => 'Constant Contact',
			'gsheets_enabled'          => 'Google Sheets',
			'zapier_enabled'           => 'Zapier',
		);

		$active = array();
		foreach ( $map as $key => $label ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$active[] = $label;
			}
		}

		return $active;
	}

	/**
	 * Run automatic PII retention cleanup.
	 *
	 * Erases PII from donations older than the configured retention period.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function run_retention(): void {
		$config   = new ConfigService();
		$settings = $config->get_all();
		$months   = $settings['retention_months'] ?? 24;
		$before   = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) $months . ' months' ) );

		$posts = get_posts(
			array(
				'post_type'   => 'donadosu_donation',
				'post_status' => 'any',
				'date_query'  => array(
					array(
						'before' => $before,
					),
				),
				// Only target records that still have PII (non-empty donor email).
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for retention cleanup targeting un-erased records only.
					array(
						'key'     => DonationMeta::DONOR_EMAIL,
						'value'   => '',
						'compare' => '!=',
					),
				),
				'numberposts' => 50,
			)
		);

		foreach ( $posts as $post ) {
			self::erase_pii( (int) $post->ID, ! empty( $settings['store_raw_payload'] ) );
		}
	}

	/**
	 * Erase personally identifiable information from a donation post.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $post_id          Donation post ID.
	 * @param bool $keep_raw_payload Whether to preserve the raw webhook payload.
	 * @return void
	 */
	private static function erase_pii( int $post_id, bool $keep_raw_payload ): void {
		$pii_keys = array(
			DonationMeta::DONOR_EMAIL,
			DonationMeta::DONOR_NAME,
			DonationMeta::DONOR_MESSAGE,
			DonationMeta::DONOR_PHONE,
			DonationMeta::DONOR_COMPANY,
			DonationMeta::DONOR_ADDRESS,
			DonationMeta::DONOR_CITY,
			DonationMeta::DONOR_POSTAL,
			// Tribute honoree data is third-party PII and must be erased.
			DonationMeta::TRIBUTE_NAME,
			DonationMeta::TRIBUTE_NOTIFY_EMAIL,
		);

		foreach ( $pii_keys as $meta_key ) {
			update_post_meta( $post_id, $meta_key, '' );
		}

		if ( ! $keep_raw_payload ) {
			delete_post_meta( $post_id, DonationMeta::META_JSON );
		}
	}
}
