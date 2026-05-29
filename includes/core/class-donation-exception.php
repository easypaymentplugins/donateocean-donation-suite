<?php
/**
 * Donation Suite Base Exception
 *
 * Custom base exception for better error handling and debugging.
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
 * Class DonationException
 *
 * Base exception for all Donation Suite errors.
 *
 * @since 1.0.0
 */
class DonationException extends \Exception {

	/**
	 * User-friendly error message.
	 *
	 * @var string
	 */
	protected $user_message;

	/**
	 * Error context data for logging.
	 *
	 * @var array
	 */
	protected $context = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message       Exception message.
	 * @param int    $code          Exception code.
	 * @param string $user_message  User-friendly message.
	 * @param array  $context       Context data for logging.
	 */
	public function __construct( $message = '', $code = 0, $user_message = '', $context = array() ) {
		parent::__construct( $message, $code );
		$this->user_message = $user_message ?: $message;
		$this->context      = $context;
	}

	/**
	 * Get user-friendly error message.
	 *
	 * @since 1.0.0
	 *
	 * @return string User-friendly error message.
	 */
	public function get_user_message(): string {
		return $this->user_message;
	}

	/**
	 * Get error context data.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Context data for logging.
	 */
	public function get_context(): array {
		return $this->context;
	}
}
