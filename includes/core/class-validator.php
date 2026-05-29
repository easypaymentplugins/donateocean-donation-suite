<?php
/**
 * Input Validator
 *
 * Provides validation methods for common data types and donation fields.
 *
 * @package    Donation_Suite
 * @subpackage Core
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace DonationSuite\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Validator
 *
 * Validates input data and throws ValidationException on failure.
 *
 * @since 1.0.0
 */
class Validator {

	/**
	 * Validate email address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Email to validate.
	 * @return string Validated and sanitized email.
	 * @throws ValidationException If email is invalid.
	 */
	public static function validate_email( string $email ): string {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			throw new ValidationException(
				'Invalid email address provided',
				[ 'email' ],
				[ 'email' => esc_html( $email ) ]
			);
		}
		return $email;
	}

	/**
	 * Validate donation amount.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $amount Amount to validate.
	 * @param float  $min    Minimum allowed amount.
	 * @param float  $max    Maximum allowed amount.
	 * @return float Validated amount.
	 * @throws ValidationException If amount is invalid.
	 */
	public static function validate_amount( $amount, float $min = 0.01, float $max = 999999.99 ): float {
		$amount = floatval( $amount );

		if ( $amount < $min ) {
			throw new ValidationException(
				'Amount must be at least ' . esc_html( $min ),
				[ 'amount' ],
				[ 'amount' => esc_html( $amount ), 'min' => esc_html( $min ) ]
			);
		}

		if ( $amount > $max ) {
			throw new ValidationException(
				'Amount cannot exceed ' . esc_html( $max ),
				[ 'amount' ],
				[ 'amount' => esc_html( $amount ), 'max' => esc_html( $max ) ]
			);
		}

		return round( $amount, 2 );
	}

	/**
	 * Validate currency code.
	 *
	 * @since 1.0.0
	 *
	 * @param string $currency Currency code to validate.
	 * @param array  $allowed  Allowed currency codes.
	 * @return string Validated currency code.
	 * @throws ValidationException If currency is invalid.
	 */
	public static function validate_currency( string $currency, array $allowed = array( 'USD' ) ): string {
		$currency = strtoupper( sanitize_text_field( $currency ) );

		if ( ! in_array( $currency, $allowed, true ) ) {
			throw new ValidationException(
				sprintf( "Currency '%s' is not supported", esc_html( $currency ) ),
				[ 'currency' ],
				[ 'currency' => esc_html( $currency ), 'allowed' => array_map( 'esc_html', $allowed ) ]
			);
		}

		return $currency;
	}

	/**
	 * Validate URL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL to validate.
	 * @return string Validated URL.
	 * @throws ValidationException If URL is invalid.
	 */
	public static function validate_url( string $url ): string {
		$url = sanitize_url( $url );

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			throw new ValidationException(
				'Invalid URL provided',
				[ 'url' ],
				[ 'url' => esc_html( $url ) ]
			);
		}

		return $url;
	}

	/**
	 * Validate UUID format.
	 *
	 * @since 1.0.0
	 *
	 * @param string $uuid UUID to validate.
	 * @return string Validated UUID.
	 * @throws ValidationException If UUID is invalid.
	 */
	public static function validate_uuid( string $uuid ): string {
		$pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

		if ( ! preg_match( $pattern, $uuid ) ) {
			throw new ValidationException(
				'Invalid UUID format',
				[ 'uuid' ],
				[ 'uuid' => esc_html( $uuid ) ]
			);
		}

		return strtolower( $uuid );
	}

	/**
	 * Validate required field.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $value Value to validate.
	 * @param string $field Field name.
	 * @return mixed Validated value.
	 * @throws ValidationException If value is empty.
	 */
	public static function validate_required( $value, string $field ) {
		if ( empty( $value ) && '0' !== $value ) {
			throw new ValidationException(
				sprintf( "Field '%s' is required", esc_html( $field ) ),
				[ esc_html( $field ) ],
				[ 'field' => esc_html( $field ) ]
			);
		}

		return $value; // @phpstan-ignore-line
	}

	/**
	 * Validate array contains required keys.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data    Array to validate.
	 * @param array $keys    Required keys.
	 * @return array Validated array.
	 * @throws ValidationException If required keys are missing.
	 */
	public static function validate_array_keys( array $data, array $keys ): array {
		$missing = array_diff( $keys, array_keys( $data ) );

		if ( ! empty( $missing ) ) {
			throw new ValidationException(
				'Required fields are missing',
				array_map( 'esc_html', array_values( $missing ) ),
				[ 'missing' => array_map( 'esc_html', $missing ) ]
			);
		}

		return $data;
	}

	/**
	 * Validate string length.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Value to validate.
	 * @param int    $min   Minimum length.
	 * @param int    $max   Maximum length.
	 * @param string $field Field name.
	 * @return string Validated value.
	 * @throws ValidationException If length is invalid.
	 */
	public static function validate_length( string $value, int $min = 1, int $max = 65535, string $field = 'value' ): string {
		$length = strlen( $value );

		if ( $length < $min ) {
			throw new ValidationException(
				sprintf( "'%s' must be at least %d characters", esc_html( $field ), esc_html( $min ) ),
				[ esc_html( $field ) ],
				[ 'field' => esc_html( $field ), 'min' => esc_html( $min ), 'length' => esc_html( $length ) ]
			);
		}

		if ( $length > $max ) {
			throw new ValidationException(
				sprintf( "'%s' cannot exceed %d characters", esc_html( $field ), esc_html( $max ) ),
				[ esc_html( $field ) ],
				[ 'field' => esc_html( $field ), 'max' => esc_html( $max ), 'length' => esc_html( $length ) ]
			);
		}

		return $value;
	}

	/**
	 * Validate integer value.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Value to validate.
	 * @param int   $min   Minimum value.
	 * @param int   $max   Maximum value.
	 * @param string $field Field name.
	 * @return int Validated integer.
	 * @throws ValidationException If value is not a valid integer.
	 */
	public static function validate_integer( $value, int $min = 0, int $max = PHP_INT_MAX, string $field = 'value' ): int {
		if ( ! is_numeric( $value ) || (int) $value != $value ) {
			throw new ValidationException(
				sprintf( "'%s' must be an integer", esc_html( $field ) ),
				[ esc_html( $field ) ],
				[ 'field' => esc_html( $field ), 'value' => esc_html( $value ) ]
			);
		}

		$value = intval( $value );

		if ( $value < $min || $value > $max ) {
			throw new ValidationException(
				sprintf( "'%s' must be between %d and %d", esc_html( $field ), esc_html( $min ), esc_html( $max ) ),
				[ esc_html( $field ) ],
				[ 'field' => esc_html( $field ), 'value' => esc_html( $value ), 'min' => esc_html( $min ), 'max' => esc_html( $max ) ]
			);
		}

		return $value;
	}
}
