<?php
/**
 * Config Exception
 *
 * Thrown when configuration is invalid.
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
 * Class ConfigException
 *
 * Thrown when configuration is invalid.
 *
 * @since 1.0.0
 */
class ConfigException extends DonationException {

	/**
	 * Missing settings.
	 *
	 * @var array
	 */
	protected $missing_settings = array();

	/**
	 * Constructor.
	 *
	 * @param string $message          Exception message.
	 * @param array  $missing_settings Settings that are missing.
	 * @param array  $context          Context data.
	 */
	public function __construct( $message = 'Configuration error', $missing_settings = array(), $context = array() ) {
		parent::__construct( $message, 500, 'Plugin is not properly configured', $context );
		$this->missing_settings = $missing_settings;
	}

	/**
	 * Get missing settings.
	 *
	 * @return array
	 */
	public function get_missing_settings(): array {
		return $this->missing_settings;
	}
}
