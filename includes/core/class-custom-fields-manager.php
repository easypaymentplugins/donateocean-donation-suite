<?php
/**
 * Custom fields manager.
 *
 * Allows developers to register and manage custom fields for the
 * donation form. Provides hooks for registering fields, rendering them
 * in templates, and handling validation and storage.
 *
 * @package    Donation_Suite
 * @subpackage Core
 * @since      1.0.0
 * @version    1.0.0
 */

namespace DonationSuite\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomFieldsManager
 *
 * Manages custom fields registration, validation, and storage for donations.
 *
 * Custom fields allow developers to extend the donation form with additional
 * fields beyond the built-in donor details. Fields can be text inputs, selects,
 * textareas, checkboxes, and more.
 *
 * @since 1.0.0
 */
class CustomFieldsManager {

	/**
	 * Allowed field types.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	public const ALLOWED_TYPES = array(
		'text',
		'email',
		'tel',
		'number',
		'url',
		'textarea',
		'select',
		'radio',
		'checkbox',
		'hidden',
	);

	/**
	 * Registered custom fields.
	 *
	 * Array of custom field definitions indexed by field ID.
	 *
	 * @since 1.0.0
	 * @var array<string, array<string, mixed>>
	 */
	private static array $fields = array();

	/**
	 * Whether the registration action has already been fired in this request.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Initialize the custom fields manager.
	 *
	 * Fires the 'donadosu_register_custom_fields' hook to allow third-party
	 * code and plugins to register custom fields. Safe to call multiple
	 * times — the registration action fires only once per request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		/**
		 * Fires when custom fields can be registered.
		 *
		 * Third-party plugins and code should use this hook to register
		 * custom fields via CustomFieldsManager::register_field().
		 *
		 * @since 1.0.0
		 */
		do_action( 'donadosu_register_custom_fields' );
	}

	/**
	 * Reset the manager state.
	 *
	 * Primarily intended for unit tests. Clears registered fields and
	 * resets the initialized flag so the hook can fire again.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$fields      = array();
		self::$initialized = false;
	}

	/**
	 * Register a custom field.
	 *
	 * Registers a field that will be displayed in the donation form and
	 * stored with the donation.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $field_config Field configuration array.
	 *
	 * @throws \InvalidArgumentException If required field parameters are missing or invalid.
	 *
	 * @return void
	 */
	public static function register_field( array $field_config ): void {
		if ( empty( $field_config['id'] ) || ! is_string( $field_config['id'] ) ) {
			throw new \InvalidArgumentException( 'Custom field requires a non-empty string "id" parameter.' );
		}

		if ( empty( $field_config['label'] ) || ! is_string( $field_config['label'] ) ) {
			throw new \InvalidArgumentException( 'Custom field requires a non-empty string "label" parameter.' );
		}

		if ( empty( $field_config['type'] ) || ! is_string( $field_config['type'] ) ) {
			throw new \InvalidArgumentException( 'Custom field requires a non-empty string "type" parameter.' );
		}

		$field_id   = sanitize_key( (string) $field_config['id'] );
		$field_type = sanitize_key( (string) $field_config['type'] );

		if ( '' === $field_id ) {
			throw new \InvalidArgumentException( 'Custom field "id" is empty after sanitization.' );
		}

		// Block collisions with reserved/built-in parameter names to avoid
		// accidentally overwriting core donation fields.
		$reserved = array(
			'amount',
			'currency',
			'campaign',
			'purpose',
			'idempotency_key',
			'donation_frequency',
			'fee_covered',
			'locale',
			'_confirm_email',
			'giving_level',
			'donor_name',
			'donor_email',
			'donor_phone',
			'donor_company',
			'donor_address',
			'donor_city',
			'donor_postal',
			'donor_message',
			'is_anonymous',
			'is_tribute',
			'tribute_type',
			'tribute_name',
			'tribute_notify_email',
			'return_page',
		);

		if ( in_array( $field_id, $reserved, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Custom field id "%s" collides with a reserved built-in parameter.', esc_html( $field_id ) )
			);
		}

		if ( ! in_array( $field_type, self::ALLOWED_TYPES, true ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Custom field type "%s" is not supported. Allowed types: %s',
					esc_html( $field_type ),
					esc_html( implode( ', ', self::ALLOWED_TYPES ) )
				)
			);
		}

		if ( in_array( $field_type, array( 'select', 'radio' ), true ) && empty( $field_config['options'] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Custom field of type "%s" requires a non-empty "options" array.', esc_html( $field_type ) )
			);
		}

		$normalized = array(
			'id'          => $field_id,
			'label'       => sanitize_text_field( (string) $field_config['label'] ),
			'type'        => $field_type,
			'description' => isset( $field_config['description'] ) ? sanitize_text_field( (string) $field_config['description'] ) : '',
			'required'    => ! empty( $field_config['required'] ),
			'placeholder' => isset( $field_config['placeholder'] ) ? sanitize_text_field( (string) $field_config['placeholder'] ) : '',
			'default'     => isset( $field_config['default'] ) ? sanitize_text_field( (string) $field_config['default'] ) : '',
		);

		if ( in_array( $field_type, array( 'select', 'radio' ), true ) ) {
			$options = array();
			foreach ( (array) $field_config['options'] as $key => $label ) {
				$options[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $label );
			}
			$normalized['options'] = $options;
		}

		/**
		 * Filters the normalized custom field configuration.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $normalized   The normalized field configuration.
		 * @param array<string, mixed> $field_config The original field configuration.
		 */
		$normalized = (array) apply_filters( 'donadosu_custom_field_config', $normalized, $field_config );

		self::$fields[ $field_id ] = $normalized;
	}

	/**
	 * Get all registered custom fields.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> Array of all registered fields.
	 */
	public static function get_fields(): array {
		return self::$fields;
	}

	/**
	 * Get a single custom field by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $field_id The field ID.
	 *
	 * @return array<string, mixed>|null The field configuration or null if not found.
	 */
	public static function get_field( string $field_id ): ?array {
		$field_id = sanitize_key( $field_id );

		return self::$fields[ $field_id ] ?? null;
	}

	/**
	 * Check if any custom fields are registered.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if at least one field is registered.
	 */
	public static function has_fields(): bool {
		return array() !== self::$fields;
	}

	/**
	 * Sanitize a raw value according to the field type.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $field The field configuration.
	 * @param mixed                $value The raw submitted value.
	 *
	 * @return string The sanitized value (always cast to string for meta storage).
	 */
	private static function sanitize_value( array $field, $value ): string {
		$type = (string) ( $field['type'] ?? 'text' );

		switch ( $type ) {
			case 'email':
				return sanitize_email( (string) $value );

			case 'url':
				return esc_url_raw( (string) $value );

			case 'number':
				if ( '' === $value || null === $value ) {
					return '';
				}
				return is_numeric( $value ) ? (string) ( 0 + $value ) : '';

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'checkbox':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? '1' : '0';

			case 'select':
			case 'radio':
				$allowed = array_keys( (array) ( $field['options'] ?? array() ) );
				$scalar  = is_scalar( $value ) ? (string) $value : '';
				return in_array( $scalar, $allowed, true ) ? sanitize_text_field( $scalar ) : '';

			case 'tel':
			case 'text':
			case 'hidden':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Validate required custom fields in a request.
	 *
	 * Returns an array of field IDs whose required constraint is unmet.
	 * An empty array means the request is valid.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 *
	 * @return string[] Array of missing required field IDs.
	 */
	public static function validate_required( \WP_REST_Request $request ): array {
		$missing = array();

		foreach ( self::$fields as $field_id => $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}

			$raw       = $request->get_param( $field_id );
			$sanitized = self::sanitize_value( $field, $raw );

			// For checkboxes, "required" means it must be checked.
			if ( 'checkbox' === $field['type'] ) {
				if ( '1' !== $sanitized ) {
					$missing[] = $field_id;
				}
				continue;
			}

			if ( '' === $sanitized ) {
				$missing[] = $field_id;
			}
		}

		return $missing;
	}

	/**
	 * Extract sanitized custom field values from a request.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 *
	 * @return array<string, string> Sanitized custom field values keyed by field ID.
	 */
	public static function get_custom_field_values( \WP_REST_Request $request ): array {
		$values = array();

		foreach ( self::$fields as $field_id => $field ) {
			$raw       = $request->get_param( $field_id );
			$sanitized = self::sanitize_value( $field, $raw );

			// Always include checkbox values (0/1) so the stored state is explicit;
			// skip other empty values to keep meta tidy.
			if ( 'checkbox' === $field['type'] || '' !== $sanitized ) {
				$values[ $field_id ] = $sanitized;
			}
		}

		/**
		 * Filters the extracted custom field values.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $values  The extracted field values.
		 * @param \WP_REST_Request      $request The REST request object.
		 */
		return (array) apply_filters( 'donadosu_custom_field_values', $values, $request );
	}

	/**
	 * Get public-facing schema of registered fields for frontend consumption.
	 *
	 * Used by wp_localize_script to pass field metadata to the JavaScript
	 * donation form so it can collect and submit custom field values.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>> Numerically indexed schema array.
	 */
	public static function get_frontend_schema(): array {
		$schema = array();

		foreach ( self::$fields as $field ) {
			$schema[] = array(
				'id'       => $field['id'],
				'type'     => $field['type'],
				'required' => (bool) $field['required'],
			);
		}

		return $schema;
	}
}
