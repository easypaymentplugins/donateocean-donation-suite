<?php
/**
 * Database Exception
 *
 * Thrown when database operations fail.
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
 * Class DatabaseException
 *
 * Thrown when database operations fail.
 *
 * @since 1.0.0
 */
class DatabaseException extends DonationException {

	/**
	 * Constructor.
	 *
	 * @param string $message Exception message.
	 * @param array  $context Context data.
	 */
	public function __construct( $message = 'Database operation failed', $context = array() ) {
		parent::__construct( $message, 500, 'Database error occurred', $context );
	}
}
