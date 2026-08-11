<?php
/**
 * Form submission retention settings.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages stored-submission retention and scheduled cleanup.
 */
final class CEA_Form_Submission_Settings {

	/** Settings API group. */
	const GROUP = 'cea_form_submissions';

	/** Settings option. */
	const OPTION_NAME = 'cea_form_submission_settings';

	/** Daily retention cleanup hook. */
	const CRON_HOOK = 'cea_form_submission_retention_cleanup';

	/**
	 * Returns default settings.
	 *
	 * @return array<string, int>
	 */
	public static function get_defaults() {
		return array( 'retention_days' => 90 );
	}

	/**
	 * Creates settings and schedules cleanup on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		add_option( self::OPTION_NAME, self::get_defaults(), '', false );
		self::ensure_scheduled();
	}

	/**
	 * Registers settings and cleanup hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cleanup' ) );
	}

	/**
	 * Registers the retention option.
	 *
	 * @return void
	 */
	public static function register() {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::get_defaults(), '', false );
		}

		register_setting(
			self::GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::get_defaults(),
			)
		);
	}

	/**
	 * Returns normalized retention settings.
	 *
	 * @return array<string, int>
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_NAME, array() );
		$saved = is_array( $saved ) ? wp_parse_args( $saved, self::get_defaults() ) : self::get_defaults();
		$days  = isset( $saved['retention_days'] ) ? absint( $saved['retention_days'] ) : 90;

		if ( ! in_array( $days, self::get_allowed_retention_days(), true ) ) {
			$days = 90;
		}

		return array( 'retention_days' => $days );
	}

	/**
	 * Sanitizes submitted retention settings.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array<string, int>
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$days  = isset( $input['retention_days'] ) ? absint( $input['retention_days'] ) : 90;

		if ( ! in_array( $days, self::get_allowed_retention_days(), true ) ) {
			$days = 90;
			add_settings_error(
				self::OPTION_NAME,
				'cea_form_submission_retention_invalid',
				__( 'Select a supported form submission retention period.', 'cea-plugin' ),
				'error'
			);
		}

		return array( 'retention_days' => $days );
	}

	/**
	 * Returns allowed retention values in days. Zero means no automatic deletion.
	 *
	 * @return array<int, int>
	 */
	public static function get_allowed_retention_days() {
		return array( 0, 30, 90, 180, 365 );
	}

	/**
	 * Schedules the daily cleanup event when it is absent.
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Removes the scheduled cleanup event on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Deletes one bounded batch outside the configured retention period.
	 *
	 * @return int
	 */
	public static function run_cleanup() {
		$settings = self::get_settings();
		$days     = absint( $settings['retention_days'] );

		if ( 0 === $days ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return CEA_Form_Submission_Repository::delete_older_than( $cutoff, 500 );
	}
}
