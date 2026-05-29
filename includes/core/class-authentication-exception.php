<?php
/**
 * Authentication Exception
 *
 * Thrown when authentication/authorization fails.
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
 * Class AuthenticationException
 *
 * Thrown when authentication/authorization fails.
 *
 * @since 1.0.0
 */
class AuthenticationException extends DonationException {

	/**
	 * Constructor.
	 *
	 * @param string $message Exception message.
	 * @param array  $context Context data.
	 */
	public function __construct( $message = 'Authentication failed', $context = array() ) {
		parent::__construct( $message, 401, 'Authentication required', $context );
	}
}
