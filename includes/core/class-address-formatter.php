<?php
/**
 * Address formatting utility.
 *
 * Formats organisation and donor addresses into clean, internationally
 * standard multi-line strings suitable for receipts, emails, and PDFs.
 *
 * @package    Donation_Suite
 * @subpackage Core
 * @since      1.0.0
 */

namespace DonationSuite\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AddressFormatter
 *
 * @since 1.0.0
 */
class AddressFormatter {

	/**
	 * Format donor address components into a standard multi-line string.
	 *
	 * Produces the international postal format:
	 *   Street Address
	 *   City, Postal Code
	 *
	 * Empty components are omitted gracefully.
	 *
	 * @param string $street Street address line.
	 * @param string $city   City name.
	 * @param string $postal Postal / ZIP code.
	 * @return string Formatted address with newline separators, or empty string.
	 */
	public static function format_donor( string $street = '', string $city = '', string $postal = '' ): string {
		$street = trim( $street );
		$city   = trim( $city );
		$postal = trim( $postal );

		$lines = array();

		if ( '' !== $street ) {
			$lines[] = $street;
		}

		// Build the city + postal line.
		$locality = '';
		if ( '' !== $city && '' !== $postal ) {
			$locality = $city . ', ' . $postal;
		} elseif ( '' !== $city ) {
			$locality = $city;
		} elseif ( '' !== $postal ) {
			$locality = $postal;
		}

		if ( '' !== $locality ) {
			$lines[] = $locality;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Format an organisation address string.
	 *
	 * Normalises whitespace, preserves intentional line breaks, and trims
	 * each line for clean rendering.
	 *
	 * @param string $address Raw organisation address (may contain newlines).
	 * @return string Cleaned multi-line address, or empty string.
	 */
	public static function format_org( string $address ): string {
		$address = trim( $address );
		if ( '' === $address ) {
			return '';
		}

		$lines = preg_split( '/\r?\n/', $address );
		if ( ! is_array( $lines ) ) {
			return '';
		}
		$lines = array_map( 'trim', $lines );
		$lines = array_filter(
			$lines,
			static function ( string $line ): bool {
				return '' !== $line;
			}
		);

		return implode( "\n", $lines );
	}

	/**
	 * Format an address for HTML output (newlines become <br> tags).
	 *
	 * @param string $formatted A formatted address string (from format_donor or format_org).
	 * @return string HTML-safe address with <br> line breaks.
	 */
	public static function to_html( string $formatted ): string {
		if ( '' === $formatted ) {
			return '';
		}
		return nl2br( esc_html( $formatted ) );
	}
}
