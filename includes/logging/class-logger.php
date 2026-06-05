<?php
/**
 * Plugin logger.
 *
 * Provides levelled logging to a file and error_log with correlation IDs.
 * Log files follow the WooCommerce convention: daily rotation with a
 * security hash in the filename to prevent direct URL guessing.
 *
 * File pattern: donadosu-{Y-m-d}-{hash}.log
 * Directory:    wp-content/uploads/donadosu-logs/
 *
 * @package    Donation_Suite
 * @subpackage Logging
 * @since      1.0.0
 * @version    1.1.0
 */

namespace DonationSuite\Logging;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger
 *
 * A simple levelled logger that writes to both the WordPress error log and
 * a dedicated log file. Fires a `donadosu_log` action for extensibility.
 *
 * @since 1.0.0
 */
class Logger {

	/**
	 * Log file handle/source name used in the filename.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const LOG_HANDLE = 'donateocean-donation-suite';

	/**
	 * Directory name inside wp-content/uploads/.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const LOG_DIR_NAME = 'donadosu-logs';

	/**
	 * Minimum log level required for messages to be recorded.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $minimum_level;

	/**
	 * Whether logging is enabled.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $minimum_level Minimum log level (debug, info, warn, error).
	 * @param bool   $enabled       Whether logging is enabled.
	 */
	public function __construct( string $minimum_level = 'error', bool $enabled = false ) {
		$this->minimum_level = strtolower( $minimum_level );
		$this->enabled       = $enabled;
	}

	/**
	 * Log a debug-level message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->write( 'debug', $message, $context );
	}

	/**
	 * Log an info-level message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->write( 'info', $message, $context );
	}

	/**
	 * Log a warn-level message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	public function warn( string $message, array $context = array() ): void {
		$this->write( 'warn', $message, $context );
	}

	/**
	 * Log an error-level message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	/**
	 * Redact sensitive personal data from log context.
	 *
	 * Replaces sensitive values (emails, names, phone numbers, API keys, etc.)
	 * with redacted placeholders to prevent PII leakage in log files.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context The logging context array.
	 * @return array The sanitized context with sensitive data redacted.
	 */
	private function redact_sensitive_data( array $context ): array {
		// List of keys that should be redacted.
		$sensitive_keys = array(
			// Donor information.
			'donor_name'     => '***NAME***',
			'donor_email'    => '***EMAIL***',
			'donor_phone'    => '***PHONE***',
			'email'          => '***EMAIL***',
			'email_address'  => '***EMAIL***',
			'phone'          => '***PHONE***',
			'name'           => '***NAME***',
			'first_name'     => '***FNAME***',
			'last_name'      => '***LNAME***',
			// Payment/API credentials.
			'api_key'        => '***KEY***',
			'secret'         => '***SECRET***',
			'access_token'   => '***TOKEN***',
			'token'          => '***TOKEN***',
			'client_secret'  => '***SECRET***',
			'password'       => '***PASSWORD***',
			// PayPal specifics.
			'sandbox_secret' => '***SECRET***',
			'live_secret'    => '***SECRET***',
			// Personal details.
			'address'        => '***ADDRESS***',
			'postal'         => '***POSTAL***',
			'city'           => '***CITY***',
			'company'        => '***COMPANY***',
			'message'        => '***MESSAGE***',
			// Payment information.
			'card_number'    => '***CARD***',
			'cvv'            => '***CVV***',
			'card'           => '***CARD***',
		);

		foreach ( $context as $key => &$value ) {
			$key_lower = strtolower( $key );

			// Check if this key should be redacted.
			if ( isset( $sensitive_keys[ $key_lower ] ) ) {
				$value = $sensitive_keys[ $key_lower ];
			} elseif ( is_array( $value ) ) {
				// Recursively redact nested arrays.
				$value = $this->redact_sensitive_data( $value );
			} elseif ( is_string( $value ) ) {
				// Value-level scrub: catches PII carried under keys that are not
				// in the allowlist (e.g. nested PayPal payloads using
				// payer.email_address, account_id, full_name).
				$value = self::mask_pii( $value );
			}
		}
		unset( $value );

		return $context;
	}

	/**
	 * Mask email addresses embedded in a free-form string.
	 *
	 * Redaction by key name does not catch PII interpolated directly into a
	 * log message or carried under an unexpected key, so mask any email
	 * pattern in the raw text. Email matching is high-precision (low risk of
	 * clobbering useful values such as order IDs or amounts).
	 *
	 * @since 1.0.0
	 *
	 * @param string $text The text to scrub.
	 * @return string The text with email addresses masked.
	 */
	public static function mask_pii( string $text ): string {
		if ( '' === $text || false === strpos( $text, '@' ) ) {
			return $text;
		}

		return (string) preg_replace(
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
			'***EMAIL***',
			$text
		);
	}

	/**
	 * Best-effort scrub of a literal string (e.g. a donor email) from existing
	 * log files, used to honour erasure requests for data written before the
	 * at-write masking, and for any value that is not an email pattern.
	 *
	 * @since 1.0.0
	 *
	 * @param string $needle The literal value to remove from log files.
	 * @return int Number of log files modified.
	 */
	public static function scrub_from_logs( string $needle ): int {
		$needle = trim( $needle );
		if ( '' === $needle ) {
			return 0;
		}

		$files = glob( self::get_log_directory() . '/' . self::LOG_HANDLE . '-*.log' );
		if ( ! is_array( $files ) ) {
			return 0;
		}

		$modified = 0;
		foreach ( $files as $file ) {
			if ( ! is_file( $file ) || ! is_readable( $file ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			$contents = (string) file_get_contents( $file );
			if ( '' === $contents || false === stripos( $contents, $needle ) ) {
				continue;
			}
			$scrubbed = str_ireplace( $needle, '***ERASED***', $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false !== file_put_contents( $file, $scrubbed, LOCK_EX ) ) {
				++$modified;
			}
		}

		return $modified;
	}

	/**
	 * Write a log record if logging is enabled and the level meets the threshold.
	 *
	 * Fires the `donadosu_log` action, writes to file, and writes to error_log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	private function write( string $level, string $message, array $context ): void {
		if ( ! $this->enabled || ! $this->should_log( $level ) ) {
			return;
		}

		// Redact sensitive data from context before logging.
		$context = $this->redact_sensitive_data( $context );

		$record = array(
			'level'          => $level,
			'message'        => self::mask_pii( str_replace( "\0", '', $message ) ),
			'correlation_id' => $this->get_correlation_id( $context ),
			'context'        => $context,
			'time'           => gmdate( 'c' ),
		);

		/**
		 * Fires when a log record is written.
		 *
		 * @since 1.0.0
		 *
		 * @param array $record Log record containing level, message, correlation_id, context, and time.
		 */
		do_action( 'donadosu_log', $record );

		$this->write_to_file( $record );

		if ( function_exists( 'error_log' ) ) {
			// Mirror only level, message, and correlation ID to the shared
			// server error_log — never the full context, which can contain
			// donor PII and is far less access-controlled than the
			// .htaccess-protected plugin log directory.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'DonationSuite [%s] %s (cid:%s)',
					$record['level'],
					$record['message'],
					$record['correlation_id']
				)
			);
		}
	}

	/**
	 * Get the log directory path.
	 *
	 * Uses the WordPress uploads directory as the base, similar to how
	 * WooCommerce stores logs in wp-content/uploads/wc-logs/.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path to the log directory (no trailing slash).
	 */
	public static function get_log_directory(): string {
		$upload_dir = wp_upload_dir();

		return $upload_dir['basedir'] . '/' . self::LOG_DIR_NAME;
	}

	/**
	 * Get a display-safe relative log directory.
	 *
	 * Used in the admin UI so the full server path is not exposed.
	 *
	 * @since 1.0.0
	 *
	 * @return string Relative path beneath the WordPress content directory.
	 */
	public static function get_log_directory_relative(): string {
		$abs    = self::get_log_directory();
		$prefix = defined( 'WP_CONTENT_DIR' ) ? rtrim( WP_CONTENT_DIR, '/' ) : '';
		if ( '' !== $prefix && str_starts_with( $abs, $prefix ) ) {
			return 'wp-content' . substr( $abs, strlen( $prefix ) );
		}
		return 'wp-content/uploads/' . self::LOG_DIR_NAME;
	}

	/**
	 * Generate a security hash for the log filename.
	 *
	 * Follows the WooCommerce pattern of hashing the handle with a salt
	 * so that log file URLs cannot be guessed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $handle The log handle name.
	 * @return string The hash suffix for the filename.
	 */
	private static function get_file_hash( string $handle ): string {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'donadosu-default-salt';

		return wp_hash( $handle . $salt );
	}

	/**
	 * Get the dynamic log file path for a given date.
	 *
	 * Builds a WC-style filename: {handle}-{Y-m-d}-{hash}.log
	 * If no date is provided the current UTC date is used.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date Optional date in Y-m-d format. Defaults to today (UTC).
	 * @return string Absolute path to the log file.
	 */
	public static function get_log_file_path( string $date = '' ): string {
		if ( '' === $date ) {
			$date = gmdate( 'Y-m-d' );
		}

		$hash = self::get_file_hash( self::LOG_HANDLE );

		return self::get_log_directory() . '/' . self::LOG_HANDLE . '-' . $date . '-' . $hash . '.log';
	}

	/**
	 * Get the public URL for a log file (for admin display / download).
	 *
	 * @since 1.0.0
	 *
	 * @param string $date Optional date in Y-m-d format. Defaults to today (UTC).
	 * @return string URL to the log file.
	 */
	public static function get_log_file_url( string $date = '' ): string {
		if ( '' === $date ) {
			$date = gmdate( 'Y-m-d' );
		}

		$upload_dir = wp_upload_dir();
		$hash       = self::get_file_hash( self::LOG_HANDLE );

		return $upload_dir['baseurl'] . '/' . self::LOG_DIR_NAME . '/' . self::LOG_HANDLE . '-' . $date . '-' . $hash . '.log';
	}

	/**
	 * List available log files in the log directory.
	 *
	 * Returns an array of associative arrays sorted newest-first, each
	 * containing 'date', 'path', 'url', and 'size' keys.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{date: string, path: string, url: string, size: int}> Log file details.
	 */
	public static function get_log_files(): array {
		$dir = self::get_log_directory();
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$pattern = $dir . '/' . self::LOG_HANDLE . '-*.log';
		$files   = glob( $pattern );
		if ( ! is_array( $files ) || empty( $files ) ) {
			return array();
		}

		$hash   = self::get_file_hash( self::LOG_HANDLE );
		$result = array();

		foreach ( $files as $file ) {
			$basename = basename( $file, '.log' );
			// Expected: donadosu-{Y-m-d}-{hash}
			$suffix = str_replace( self::LOG_HANDLE . '-', '', $basename );
			// Remove the hash part to extract the date.
			$date = str_replace( '-' . $hash, '', $suffix );

			// Validate date format.
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				continue;
			}

			$result[] = array(
				'date' => $date,
				'path' => $file,
				'url'  => self::get_log_file_url( $date ),
				'size' => (int) filesize( $file ),
			);
		}

		// Sort newest first.
		usort(
			$result,
			static function ( array $a, array $b ): int {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		return $result;
	}

	/**
	 * Ensure the log directory exists with protection files.
	 *
	 * Creates the directory via wp_mkdir_p() and adds .htaccess and index.php
	 * files to prevent direct HTTP access. Safe to call multiple times.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the directory exists or was created successfully.
	 */
	public function ensure_log_directory(): bool {
		$dir = self::get_log_directory();

		if ( is_dir( $dir ) ) {
			return true;
		}

		$created = wp_mkdir_p( $dir );
		if ( ! $created ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'DonationSuite: Failed to create log directory: ' . $dir );
			}
			return false;
		}

		// Protect the log directory from direct HTTP access.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$wp_filesystem->put_contents( $htaccess, "Deny from all\n", FS_CHMOD_FILE );
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			$wp_filesystem->put_contents( $index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}

		return true;
	}

	/**
	 * Write a log record to the log file on disk.
	 *
	 * Creates the log directory with .htaccess and index.php protection files
	 * if it does not already exist. Uses a daily-rotated filename with a
	 * security hash, following the WooCommerce logging convention.
	 *
	 * @since 1.0.0
	 *
	 * @param array $record Log record array.
	 * @return void
	 */
	private function write_to_file( array $record ): void {
		if ( ! $this->ensure_log_directory() ) {
			return;
		}

		$path = self::get_log_file_path();
		$line = sprintf(
			"[%s] %s: %s | %s\n",
			$record['time'],
			strtoupper( $record['level'] ),
			$record['message'],
			wp_json_encode( $record['context'] )
		);

		// Append the single line instead of read-modify-writing the whole file
		// (which was O(n^2) and a memory risk on busy days). WP_Filesystem has
		// no append primitive, so use a native locked append to the plugin's
		// own protected log directory.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
		if ( false === $written ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'DonationSuite: Failed to write to log file: ' . $path );
			}
			return;
		}

		// Opportunistically prune log files older than the retention window,
		// at most once per day (cheap guard via a short-lived option).
		$this->maybe_cleanup_old_logs();
	}

	/**
	 * Delete log files older than the retention window, at most once per day.
	 *
	 * Daily-rotated log files were never removed, so PII-bearing logs
	 * accumulated indefinitely. This prunes files whose modification time is
	 * older than the retention window (default 30 days, filterable).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function maybe_cleanup_old_logs(): void {
		$today      = gmdate( 'Y-m-d' );
		$last_clean = (string) get_option( 'donadosu_logs_last_cleanup', '' );
		if ( $last_clean === $today ) {
			return;
		}
		update_option( 'donadosu_logs_last_cleanup', $today, false );

		/**
		 * Filters how many days of plugin log files to retain.
		 *
		 * @since 1.0.0
		 *
		 * @param int $days Retention window in days.
		 */
		$days   = (int) apply_filters( 'donadosu_log_retention_days', 30 );
		$cutoff = time() - ( max( 1, $days ) * DAY_IN_SECONDS );
		$dir    = self::get_log_directory();

		$files = glob( $dir . '/' . self::LOG_HANDLE . '-*.log' );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( is_file( $file ) && (int) filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Determine whether the given level meets the configured minimum threshold.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level Log level to check.
	 * @return bool True if the level should be logged.
	 */
	private function should_log( string $level ): bool {
		$weights = array(
			'debug' => 10,
			'info'  => 20,
			'warn'  => 30,
			'error' => 40,
		);

		$configured = $weights[ $this->minimum_level ] ?? $weights['error'];
		$current    = $weights[ strtolower( $level ) ] ?? $weights['error'];

		return $current >= $configured;
	}

	/**
	 * Get or generate a correlation ID for the log context.
	 *
	 * If a correlation_id already exists in the context array it is returned
	 * as-is. Otherwise a new UUID is generated and injected into the context.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Context array, passed by reference so the ID can be injected.
	 * @return string Correlation ID string.
	 */
	private function get_correlation_id( array &$context ): string {
		$correlation_id = (string) ( $context['correlation_id'] ?? '' );
		if ( '' === $correlation_id ) {
			$correlation_id            = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'donadosu_', true );
			$context['correlation_id'] = $correlation_id;
		}

		return $correlation_id;
	}
}
