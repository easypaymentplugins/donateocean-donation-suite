<?php
/**
 * Donor profile page for the Donation Suite admin.
 *
 * Shows all donations from a single email address with aggregate stats.
 * Accessible at admin.php?page=donadosu-donor&email=EMAIL. Linked
 * from the "View donor history" button on the donation detail view and
 * from the donor email column on the donations list.
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
 * Class DonorProfilePage
 *
 * Renders a donor profile with donation history and aggregate statistics.
 *
 * @since 1.0.0
 */
class DonorProfilePage {

	/**
	 * Register the hidden admin submenu page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'admin_menu',
			function () {
				$hook = add_submenu_page(
					'',
					__( 'Donor Profile', 'donateocean-donation-suite' ),
					__( 'Donor Profile', 'donateocean-donation-suite' ),
					Capabilities::VIEW_DONATIONS,
					'donadosu-donor',
					array( $this, 'render' )
				);

				if ( $hook ) {
					add_action(
						"load-{$hook}",
						array( $this, 'handle_load' )
					);
				}
			}
		);
	}

	/**
	 * Runs on the load-{page} hook, before any output is sent.
	 *
	 * Sets the page title to fix the strip_tags() null deprecation
	 * for hidden submenu pages in WordPress admin-header.php.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_load(): void {
		global $title;
		$title = __( 'Donor Profile', 'donateocean-donation-suite' );
	}

	/**
	 * Render the donor profile page.
	 *
	 * Retrieves the donor email from the query string, queries aggregate
	 * stats via the donation repository, and includes the template.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Capabilities::can_view() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'donateocean-donation-suite' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only display page; email is sanitized via sanitize_email().
		$email = sanitize_email( urldecode( (string) wp_unslash( $_GET['email'] ?? '' ) ) );
		if ( ! $email ) {
			wp_die( esc_html__( 'A donor email address is required.', 'donateocean-donation-suite' ) );
		}

		$repository = new CptDonationRepository();
		$post_ids   = $repository->find_by_donor_email( $email );

		// Aggregate stats via a single SQL query — no N+1 on large donors.
		$stats               = $repository->get_donor_stats( $email );
		$total_completed     = $stats['total_completed'];
		$total_amount        = $stats['total_amount'];
		$currency            = $stats['currency'];
		$donor_name          = $stats['donor_name'];
		$first_donation_date = $stats['first_date'];
		$last_donation_date  = $stats['last_date'];

		$back_url = admin_url( 'edit.php?post_type=donadosu_donation' );

		// Template expects camelCase variables.
		$postIds           = $post_ids;
		$totalCompleted    = $total_completed;
		$totalAmount       = $total_amount;
		$donorName         = $donor_name;
		$firstDonationDate = $first_donation_date;
		$lastDonationDate  = $last_donation_date;
		$backUrl           = $back_url;

		include DONADOSU_PATH . 'templates/admin-donor-profile.php';
	}
}
