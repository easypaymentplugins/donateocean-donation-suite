<?php
/**
 * Donation state machine.
 *
 * Enforces valid status transitions for both one-time donations and
 * recurring subscriptions, preventing illegal state changes.
 *
 * @package    Donation_Suite
 * @subpackage Donation
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Donation;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StateMachine
 *
 * Validates and performs donation status transitions.
 *
 * @since 1.0.0
 */
class StateMachine {

	/**
	 * One-time donation status order.
	 *
	 * Higher values represent later stages in the donation lifecycle.
	 * donadosu_pending sits at the same level as donadosu_captured so it
	 * can advance to donadosu_completed.
	 *
	 * @since 1.0.0
	 * @var array<string, int>
	 */
	private const ORDER = array(
		'donadosu_created'   => 1,
		'donadosu_approved'  => 2,
		'donadosu_captured'  => 3,
		'donadosu_pending'   => 3,
		'donadosu_completed' => 4,
		'donadosu_refunded'  => 5,
		'donadosu_failed'    => 99,
		'donadosu_disputed'  => 6,
	);

	/**
	 * Subscription statuses managed separately from one-time orders.
	 *
	 * @since 1.0.0
	 * @var array<string>
	 */
	public const SUBSCRIPTION_STATUSES = array(
		'donadosu_sub_active',
		'donadosu_sub_paused',
		'donadosu_sub_cancelled',
		'donadosu_sub_failed',
	);

	/**
	 * Check whether a transition from one status to another is allowed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from_status The current status.
	 * @param string $to_status   The desired target status.
	 * @return bool Whether the transition is valid.
	 */
	public function can_transition( string $from_status, string $to_status ): bool {
		if ( $from_status === $to_status ) {
			return true;
		}

		// Subscription statuses have simpler rules.
		if ( in_array( $from_status, self::SUBSCRIPTION_STATUSES, true ) || in_array( $to_status, self::SUBSCRIPTION_STATUSES, true ) ) {
			return $this->can_transition_subscription( $from_status, $to_status );
		}

		if ( ! isset( self::ORDER[ $from_status ], self::ORDER[ $to_status ] ) ) {
			return false;
		}

		if ( 'donadosu_failed' === $from_status || 'donadosu_refunded' === $from_status ) {
			return false;
		}

		// Only completed or disputed donations can become refunded.
		if ( 'donadosu_refunded' === $to_status ) {
			return in_array( $from_status, array( 'donadosu_completed', 'donadosu_disputed' ), true );
		}

		// Only completed donations can move to disputed.
		if ( 'donadosu_disputed' === $to_status ) {
			return 'donadosu_completed' === $from_status;
		}

		// Disputed donations can resolve back to completed.
		if ( 'donadosu_disputed' === $from_status && 'donadosu_completed' === $to_status ) {
			return true;
		}

		if ( 'donadosu_failed' === $to_status ) {
			return true;
		}

		return self::ORDER[ $to_status ] > self::ORDER[ $from_status ];
	}

	/**
	 * Attempt to transition from one status to another.
	 *
	 * Returns the new status if the transition is valid, or the original
	 * status if it is not.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from_status The current status.
	 * @param string $to_status   The desired target status.
	 * @return string The resulting status after the transition attempt.
	 */
	public function transition( string $from_status, string $to_status ): string {
		if ( ! $this->can_transition( $from_status, $to_status ) ) {
			return $from_status;
		}

		return $to_status;
	}

	/**
	 * Check whether a subscription status transition is allowed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from_status The current subscription status.
	 * @param string $to_status   The desired target subscription status.
	 * @return bool Whether the transition is valid.
	 */
	private function can_transition_subscription( string $from_status, string $to_status ): bool {
		// Money-terminal statuses a subscription post can also move into when a
		// refund, dispute, or capture failure/denial webhook arrives for its
		// (first) charge. Without these the refund/dispute is recorded in meta
		// but the post stays in an active subscription status and keeps being
		// counted as collected revenue in reports.
		$money_terminal = array( 'donadosu_refunded', 'donadosu_disputed', 'donadosu_failed' );

		$allowed = array(
			// Entry from initial donation creation (subscription approval flow).
			'donadosu_created'       => array( 'donadosu_sub_active', 'donadosu_sub_failed' ),
			'donadosu_sub_active'    => array_merge( array( 'donadosu_sub_paused', 'donadosu_sub_cancelled', 'donadosu_sub_failed' ), $money_terminal ),
			'donadosu_sub_paused'    => array_merge( array( 'donadosu_sub_active', 'donadosu_sub_cancelled' ), $money_terminal ),
			'donadosu_sub_cancelled' => array( 'donadosu_refunded', 'donadosu_disputed' ),
			'donadosu_sub_failed'    => array( 'donadosu_sub_active', 'donadosu_refunded', 'donadosu_disputed' ),
		);

		return in_array( $to_status, $allowed[ $from_status ] ?? array(), true );
	}
}
