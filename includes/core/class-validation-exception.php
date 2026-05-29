<?php
/**
 * Validation Exception
 *
 * Thrown when validation fails.
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
 * Class ValidationException
 *
 * Thrown when validation fails.
 *
 * @since 1.0.0
 */
class ValidationException extends DonationException {

	/**
	 * Invalid field names.
	 *
	 * @var array
	 */
	protected $invalid_fields = array();

	/**
	 * Constructor.
	 *
	 * @param string $message        Exception message.
	 * @param array  $invalid_fields Fields that failed validation.
	 * @param array  $context        Context data.
	 */
	public function __construct( $message = 'Validation failed', $invalid_fields = array(), $context = array() ) {
		parent::__construct( $message, 422, 'Please check your input and try again', $context );
		$this->invalid_fields = $invalid_fields;
	}

	/**
	 * Get invalid fields.
	 *
	 * @return array
	 */
	public function get_invalid_fields(): array {
		return $this->invalid_fields;
	}
}
