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

		return array(
			'items_removed'  => count( $posts ),
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => count( $posts ) < 50,
		);
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
