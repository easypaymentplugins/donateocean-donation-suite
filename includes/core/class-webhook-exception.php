<?php
/**
 * Webhook Exception
 *
 * Thrown when webhook processing fails.
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
 * Class WebhookException
 *
 * Thrown when webhook processing fails.
 *
 * @since 1.0.0
 */
class WebhookException extends DonationException {

	/**
	 * Webhook event ID.
	 *
	 * @var string
	 */
	protected $event_id;

	/**
	 * Constructor.
	 *
	 * @param string $message  Exception message.
	 * @param string $event_id Webhook event ID.
	 * @param int    $code     Exception code.
	 * @param array  $context  Context data.
	 */
	public function __construct( $message = 'Webhook processing failed', $event_id = '', $code = 400, $context = array() ) {
		parent::__construct( $message, $code, 'Webhook could not be processed', $context );
		$this->event_id = $event_id;
	}

	/**
	 * Get event ID.
	 *
	 * @return string
	 */
	public function get_event_id(): string {
		return $this->event_id;
	}
}
