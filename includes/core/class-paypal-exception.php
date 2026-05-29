<?php
/**
 * PayPal Exception
 *
 * Thrown when PayPal API operations fail.
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
 * Class PayPalException
 *
 * Thrown when PayPal API operations fail.
 *
 * @since 1.0.0
 */
class PayPalException extends DonationException {

	/**
	 * Whether the error is retryable.
	 *
	 * @var bool
	 */
	protected $retryable = false;

	/**
	 * Constructor.
	 *
	 * @param string $message   Exception message.
	 * @param int    $code      Exception code.
	 * @param bool   $retryable Whether error is retryable.
	 * @param array  $context   Context data.
	 */
	public function __construct( $message = 'PayPal API error', $code = 0, $retryable = false, $context = array() ) {
		parent::__construct( $message, $code, 'Payment processing failed. Please try again.', $context );
		$this->retryable = $retryable;
	}

	/**
	 * Check if error is retryable.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}
