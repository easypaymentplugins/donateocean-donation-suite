<?php
/**
 * PSR-4 autoloader with WordPress-style file naming.
 *
 * Maps the DonationSuite namespace to the includes/ directory using
 * WordPress file naming conventions (class-*.php, interface-*.php).
 *
 * @package    Donation_Suite
 * @subpackage Includes
 * @since      1.0.0
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Donadosu_Donation_Suite_Autoloader
 *
 * Registers an SPL autoloader that converts DonationSuite namespace
 * references to WordPress-style file paths under the includes/ directory.
 *
 * @since 1.0.0
 */
class Donadosu_Donation_Suite_Autoloader {

	/**
	 * Manual class-to-file overrides for names whose CamelCase-to-kebab
	 * conversion produces an undesirable result (acronyms, proper nouns).
	 *
	 * Keys are the short class name (without namespace and without
	 * the Interface suffix for interfaces). Values are the file name
	 * portion between the prefix (class- / interface-) and .php.
	 *
	 * @since 1.0.0
	 * @var array<string,string>
	 */
	private static $overrides = array(
		'OAuthTokenCache'      => 'oauth-token-cache',
		'PayPalClient'         => 'paypal-client',
		'PayPalException'      => 'paypal-exception',
		'ConstantContactOAuth' => 'constant-contact-oauth',
	);

	/**
	 * Register the autoloader with the SPL autoload stack.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Autoload callback.
	 *
	 * Converts a fully-qualified class name like
	 * DonationSuite\Core\Bootstrap to includes/core/class-bootstrap.php.
	 *
	 * @since 1.0.0
	 *
	 * @param string $class Fully-qualified class name.
	 * @return void
	 */
	public static function autoload( $class ) {
		$prefix = 'DonationSuite\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );

		// Determine the file prefix and strip type suffix from the name.
		if ( substr( $name, -9 ) === 'Interface' ) {
			$file_prefix = 'interface-';
			$name        = substr( $name, 0, -9 );
		} else {
			$file_prefix = 'class-';
		}

		// Check for a manual override before falling back to auto-conversion.
		if ( isset( self::$overrides[ $name ] ) ) {
			$kebab = self::$overrides[ $name ];
		} else {
			$kebab = self::camel_to_kebab( $name );
		}

		// Convert namespace parts to lowercase directory names.
		$directory = implode( DIRECTORY_SEPARATOR, array_map( 'strtolower', $parts ) );
		if ( '' !== $directory ) {
			$directory .= DIRECTORY_SEPARATOR;
		}

		$file = DONADOSU_PATH . 'includes/' . $directory . $file_prefix . $kebab . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Convert a CamelCase string to kebab-case.
	 *
	 * @since 1.0.0
	 *
	 * @param string $string CamelCase string.
	 * @return string Kebab-case string.
	 */
	private static function camel_to_kebab( $string ) {
		$result = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $string );
		$result = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1-$2', $result );

		return strtolower( $result );
	}
}
