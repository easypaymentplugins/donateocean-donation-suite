<?php
/**
 * Role-Based Access Control.
 *
 * Defines custom capabilities and registers two roles on plugin activation:
 *
 *   donadosu_donation_viewer  - read-only: list, view details, donor profiles, export CSV
 *   donadosu_donation_manager - above + resend receipts, trigger year-end summaries,
 *                      cancel subscriptions
 *
 * Refunds and settings remain gated behind manage_options (admin only).
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

/**
 * Class Capabilities
 *
 * Manages custom capabilities and roles for the Donation Suite plugin.
 *
 * @since 1.0.0
 */
class Capabilities {

	/**
	 * Capability to view the donations list and individual donation detail pages.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const VIEW_DONATIONS = 'donadosu_view_donations';

	/**
	 * Capability to download CSV exports and access donor profiles.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const EXPORT_DONATIONS = 'donadosu_export_donations';

	/**
	 * Capability to resend receipts and trigger year-end summaries.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const MANAGE_DONATIONS = 'donadosu_manage_donations';

	/**
	 * Register roles on plugin activation.
	 *
	 * Safe to call multiple times because add_role is a no-op when the
	 * role already exists.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_roles(): void {
		add_role(
			'donadosu_donation_viewer',
			__( 'Donation Viewer', 'donateocean-donation-suite' ),
			array(
				'read'               => true,
				self::VIEW_DONATIONS => true,
			)
		);

		add_role(
			'donadosu_donation_manager',
			__( 'Donation Manager', 'donateocean-donation-suite' ),
			array(
				'read'                 => true,
				self::VIEW_DONATIONS   => true,
				self::EXPORT_DONATIONS => true,
				self::MANAGE_DONATIONS => true,
			)
		);

		// Grant custom capabilities to the administrator role so admins
		// can access donation list tables, detail pages, and exports.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->add_cap( self::VIEW_DONATIONS );
			$admin_role->add_cap( self::EXPORT_DONATIONS );
			$admin_role->add_cap( self::MANAGE_DONATIONS );
		}
	}

	/**
	 * Remove roles on plugin uninstall.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function remove_roles(): void {
		remove_role( 'donadosu_donation_viewer' );
		remove_role( 'donadosu_donation_manager' );

		// Remove custom capabilities from administrator role.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->remove_cap( self::VIEW_DONATIONS );
			$admin_role->remove_cap( self::EXPORT_DONATIONS );
			$admin_role->remove_cap( self::MANAGE_DONATIONS );
		}
	}

	/**
	 * Check whether the current user can view donation records.
	 *
	 * Admins (manage_options) always have access.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether the current user can view donations.
	 */
	public static function can_view(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( self::VIEW_DONATIONS );
	}

	/**
	 * Check whether the current user can export or view donor profiles.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether the current user can export donations.
	 */
	public static function can_export(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( self::EXPORT_DONATIONS );
	}

	/**
	 * Check whether the current user can manage donations (resend, year-end).
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether the current user can manage donations.
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( self::MANAGE_DONATIONS );
	}
}
