<?php
/**
 * Marketing consent helper.
 *
 * Central gate that determines whether a donor may be subscribed to a
 * third-party marketing/CRM service. Under GDPR (Art. 6/7) making a donation
 * is not, by itself, consent to marketing, so subscription must be gated on
 * an explicit, recorded opt-in.
 *
 * @package    Donation_Suite
 * @subpackage Core
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Core;

use DonationSuite\Donation\DonationMeta;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Consent
 *
 * @since 1.0.0
 */
class Consent {

	/**
	 * Whether the donor for a given donation has granted marketing consent.
	 *
	 * Returns true only when an explicit opt-in was recorded at donation time.
	 * Site owners who collect consent through another channel (or who have a
	 * different lawful basis) can override the result via the
	 * `donadosu_donor_marketing_consent` filter.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Donation post ID.
	 * @return bool True if the donor may be subscribed to marketing services.
	 */
	public static function has_marketing_consent( int $post_id ): bool {
		$granted = '1' === (string) get_post_meta( $post_id, DonationMeta::MARKETING_CONSENT, true );

		/**
		 * Filters whether a donor is considered to have granted marketing consent.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $granted Whether explicit consent was recorded.
		 * @param int  $post_id Donation post ID.
		 */
		return (bool) apply_filters( 'donadosu_donor_marketing_consent', $granted, $post_id );
	}
}
