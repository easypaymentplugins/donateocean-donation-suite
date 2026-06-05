<?php
/**
 * Currency utility.
 *
 * Maps ISO-4217 currency codes to their decimal exponent (minor-unit
 * count) so the plugin can format amounts correctly for PayPal and
 * display. PayPal rejects 2-decimal JPY and truncates 3-decimal KWD,
 * so hardcoding `number_format($amount, 2)` everywhere is wrong.
 *
 * @package    Donation_Suite
 * @subpackage Core
 * @since      1.0.5
 */

namespace DonationSuite\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Currency {

	private const EXPONENTS = array(
		// Zero-decimal currencies.
		'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0,
		'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
		'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0,
		'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
		'HUF' => 0, 'TWD' => 0,
		// Three-decimal currencies.
		'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3,
		'LYD' => 3, 'OMR' => 3, 'TND' => 3,
	);

	public static function exponent( string $currency ): int {
		return self::EXPONENTS[ strtoupper( $currency ) ] ?? 2;
	}

	/**
	 * Format a numeric amount for the PayPal API / display.
	 *
	 * Returns a string with the correct number of decimal places for the
	 * given currency: "1025" for JPY, "49.990" for KWD, "50.00" for USD.
	 */
	public static function format_amount( float $amount, string $currency ): string {
		$exp = self::exponent( $currency );
		return number_format( $amount, $exp, '.', '' );
	}

	/**
	 * Compare two monetary amounts for equality using the currency's
	 * native precision instead of a hardcoded float epsilon.
	 */
	public static function amounts_equal( float $a, float $b, string $currency ): bool {
		$exp = self::exponent( $currency );
		$threshold = 0.5 / pow( 10, $exp );
		return abs( $a - $b ) < $threshold;
	}

	/**
	 * SQL-safe CAST expression for aggregating monetary meta values.
	 */
	public static function sql_cast( string $expression ): string {
		return "CAST({$expression} AS DECIMAL(20,6))";
	}
}
