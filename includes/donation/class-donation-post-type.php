<?php
/**
 * Donation custom post type registration.
 *
 * Registers the donadosu_donation CPT, custom post statuses, row actions,
 * and admin-screen lockdown so donations are always viewed through the
 * plugin's own detail page.
 *
 * @package    Donation_Suite
 * @subpackage Donation
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Donation;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DonationPostType
 *
 * Manages the donadosu_donation custom post type and its statuses.
 *
 * @since 1.0.0
 */
class DonationPostType {

	/**
	 * Register the custom post type, custom statuses, and admin hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register(): void {
		// Map every standard WP CPT capability to the plugin's custom caps so
		// that the donadosu_donation_viewer and donadosu_donation_manager roles can
		// access the list page. Using capability_type='post' would require
		// edit_posts (admin-only).
		register_post_type(
			'donadosu_donation',
			array(
				'labels'             => array(
					'name'          => __( 'Donations', 'donateocean-donation-suite' ),
					'singular_name' => __( 'Donation', 'donateocean-donation-suite' ),
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=donadosu_donation',
				'publicly_queryable' => false,
				'supports'           => array( 'title' ),
				'map_meta_cap'       => false,
				'capabilities'       => array(
					// List-page and read-only access.
					'edit_post'              => \DonationSuite\Core\Capabilities::VIEW_DONATIONS,
					'read_post'              => \DonationSuite\Core\Capabilities::VIEW_DONATIONS,
					'edit_posts'             => \DonationSuite\Core\Capabilities::VIEW_DONATIONS,
					'read_private_posts'     => \DonationSuite\Core\Capabilities::VIEW_DONATIONS,
					// All write/delete operations stay admin-only.
					'delete_post'            => 'manage_options',
					'delete_posts'           => 'manage_options',
					'delete_private_posts'   => 'manage_options',
					'delete_published_posts' => 'manage_options',
					'delete_others_posts'    => 'manage_options',
					'edit_others_posts'      => 'manage_options',
					'edit_private_posts'     => 'manage_options',
					'edit_published_posts'   => 'manage_options',
					'publish_posts'          => 'manage_options',
					'create_posts'           => 'manage_options',
				),
			)
		);

		$statuses = array(
			'donadosu_created'       => array(
				'label'       => _x( 'Created', 'post status', 'donateocean-donation-suite' ),
				/* translators: %s: Number of donations with this status. */
				'label_count' => _n_noop(
					'Created <span class="count">(%s)</span>',
					'Created <span class="count">(%s)</span>',
					'donateocean-donation-suite'
				),
			),
			'donadosu_approved'      => array(
				'label'       => _x( 'Approved', 'post status', 'donateocean-donation-suite' ),
				/* translators: %s: Number of donations with this status. */
				'label_count' => _n_noop(
					'Approved <span class="count">(%s)</span>',
					'Approved <span class="count">(%s)</span>',
					'donateocean-donation-suite'
				),
			),
			'donadosu_captured'      => array(
				'label'       => _x( 'Captured', 'post status', 'donateocean-donation-suite' ),
				/* translators: %s: Number of donations with this status. */
				'label_count' => _n_noop(
					'Captured <span class="count">(%s)</span>',
					'Captured <span class="count">(%s)</span>',
					'donateocean-donation-suite'
				),
			),
			'donadosu_pending'       => array(
				'label'       => _x( 'Pending', 'post status', 'donateocean-donation-suite' ),
				/* translators: %s: Number of donations with this status. */
				'label_count' => _n_noop(
					'Pending <span class="count">(%s)</span>',
					'Pending <span class="count">(%s)</span>',
					'donateocean-donation-suite'
				),
			),
			'donadosu_completed'     => array(
				'label'       => _x( 'Completed', 'post status', 'donateocean-donation-suite' ),
				/* translators: %s: Number of donations with this status. */
				'label_count' => _n_noop(
					'Completed <span class="count">(%s)</span>',
					'Completed <span class="count">(%s)</span>',
					'donateocean-donation-suite'
				),
			),
			'donadosu_refunded'      => array(
				'label'       => _x( 'Refunded', 'post status', 'donateocean-donation-suite' ),
				/* translators: %s: Number of donations with this status. */
				'label_count' => _n_noop(
					'Refunded <span class="count">(%s)</span>',
					'Refunded <span class="count">(%s)</span>',
					'donateocean-donation-suite'
				),
			),
			'donadosu_failed'        => array(
				'label'       => _x( 'Failed', 'post status', 'donateocean-donation-suite' ),
				/* translators: %s: Number of donations with this status. */
				'label_count' => _n_noop(
					'Failed <span class="count">(%s)</span>',
					'Failed <span class="count">(%s)</span>',
					'donateocean-donation-suite'
				),
			),
			'donadosu_disputed'      => array(
				'label'       => _x( 'Disputed', 'post status', 'donateocean-donation-suite' ),
				/* translators: %s: Number of donations with this status. */
				'label_count' => _n_noop(
					'Disputed <span class="count">(%s)</span>',
					'Disputed <span class="count">(%s)</span>',
					'donateocean-donation-suite'
				),
			),
			'donadosu_sub_active'    => array(
				'label'       => _x( 'Subscription Active', 'post status', 'donateocean-donation-suite' ),
				/* translators: %s: Number of donations with this status. */
				'label_count' => _n_noop(
					'Subscription Active <span class="count">(%s)</span>',
					'Subscription Active <span class="count">(%s)</span>',
					'donateocean-donation-suite'
				),
			),
			'donadosu_sub_paused'    => array(
				'label'       => _x( 'Subscription Paused', 'post status', 'donateocean-donation-suite' ),
				/* translators: %s: Number of donations with this status. */
				'label_count' => _n_noop(
					'Subscription Paused <span class="count">(%s)</span>',
					'Subscription Paused <span class="count">(%s)</span>',
					'donateocean-donation-suite'
				),
			),
			'donadosu_sub_cancelled' => array(
				'label'       => _x( 'Subscription Cancelled', 'post status', 'donateocean-donation-suite' ),
				/* translators: %s: Number of donations with this status. */
				'label_count' => _n_noop(
					'Subscription Cancelled <span class="count">(%s)</span>',
					'Subscription Cancelled <span class="count">(%s)</span>',
					'donateocean-donation-suite'
				),
			),
			'donadosu_sub_failed'    => array(
				'label'       => _x( 'Subscription Failed', 'post status', 'donateocean-donation-suite' ),
				/* translators: %s: Number of donations with this status. */
				'label_count' => _n_noop(
					'Subscription Failed <span class="count">(%s)</span>',
					'Subscription Failed <span class="count">(%s)</span>',
					'donateocean-donation-suite'
				),
			),
		);

		foreach ( $statuses as $status => $config ) {
			register_post_status(
				$status,
				array(
					'label'                     => $config['label'],
					'public'                    => false,
					'internal'                  => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => $config['label_count'],
				)
			);
		}

		if ( ! has_filter( 'post_row_actions', array( self::class, 'row_actions' ) ) ) {
			add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
			add_action( 'admin_menu', array( self::class, 'remove_add_new_submenu' ), 99 );
			add_action( 'admin_head', array( self::class, 'lock_edit_screen' ) );
		}
	}

	/**
	 * Replace the default row actions with useful read-only actions.
	 *
	 * Provides "View Details" (dedicated detail page), "Donor" (donor profile
	 * when an email is present), and "Print receipt" (for completed donations
	 * with a capture ID).
	 *
	 * @since 1.0.0
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_Post $post    The current post object.
	 * @return array Modified row actions.
	 */
	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( 'donadosu_donation' !== $post->post_type ) {
			return $actions;
		}

		// Remove default WordPress actions that make no sense for read-only records.
		unset( $actions['inline hide-if-no-js'], $actions['edit'], $actions['trash'], $actions['view'] );

		$detail_url        = add_query_arg(
			array(
				'page' => 'donadosu-detail',
				'id'   => $post->ID,
			),
			admin_url( 'admin.php' )
		);
		$actions['detail'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $detail_url ),
			esc_html__( 'View details', 'donateocean-donation-suite' )
		);

		$donor_email = sanitize_email( (string) get_post_meta( $post->ID, DonationMeta::DONOR_EMAIL, true ) );
		if ( $donor_email ) {
			$donor_url        = add_query_arg(
				array(
					'page'  => 'donadosu-donor',
					'email' => rawurlencode( $donor_email ),
				),
				admin_url( 'admin.php' )
			);
			$actions['donor'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $donor_url ),
				esc_html__( 'Donor history', 'donateocean-donation-suite' )
			);
		}

		$status = (string) get_post_status( $post->ID );
		if ( 'donadosu_completed' === $status ) {
			$capture_id = (string) get_post_meta( $post->ID, DonationMeta::CAPTURE_ID, true );
			if ( $capture_id ) {
				$pdf_url            = add_query_arg(
					array(
						'page' => 'donadosu-pdf',
						'id'   => $post->ID,
					),
					admin_url( 'admin.php' )
				);
				$actions['receipt'] = sprintf(
					'<a href="%s" target="_blank">%s</a>',
					esc_url( $pdf_url ),
					esc_html__( 'Download PDF', 'donateocean-donation-suite' )
				);
			}
		}

		return $actions;
	}

	/**
	 * Remove the "Add New" submenu page for donations.
	 *
	 * Donations are created via REST, never through the WP edit screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function remove_add_new_submenu(): void {
		remove_submenu_page( 'edit.php?post_type=donadosu_donation', 'post-new.php?post_type=donadosu_donation' );
	}

	/**
	 * Lock the native edit screen for donations.
	 *
	 * Redirects users from the WordPress post editor to the plugin's
	 * dedicated detail page via a fast JavaScript redirect.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function lock_edit_screen(): void {
		$screen = get_current_screen();
		if ( $screen && 'donadosu_donation' === $screen->post_type && 'post' === $screen->base ) {
			// Redirect from the native edit screen to our detail page.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading standard WP post ID for redirect.
			$post_id = absint( $_GET['post'] ?? 0 );
			if ( $post_id ) {
				$detail_url = add_query_arg(
					array(
						'page' => 'donadosu-detail',
						'id'   => $post_id,
					),
					admin_url( 'admin.php' )
				);
				wp_safe_redirect( $detail_url );
				exit;
			}
		}
	}
}
