<?php
/**
 * Main plugin controller.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's features.
 */
final class CEA_Plugin {

	/**
	 * Registers plugin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( 'CEA_Form_Submission_Repository', 'maybe_upgrade' ), 5 );

		CEA_Form_Action_Registry::register_defaults();
		CEA_Forms::register_hooks();
		CEA_Form_Submission_Settings::register_hooks();
		CEA_Form_Submission_Privacy::register_hooks();
		CEA_SMTP_Mailer::register_hooks();

		if ( is_admin() ) {
			CEA_Forms_Admin::register_hooks();
			CEA_SMTP_Settings_Page::register_hooks();
			CEA_Mailchimp_Settings_Page::register_hooks();
			CEA_Form_Submissions_Admin::register_hooks();
		}
	}

	/**
	 * Creates plugin data and registers features on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		CEA_SMTP_Settings::activate();
		CEA_Mailchimp_Settings::activate();
		CEA_Form_Submission_Repository::install();
		CEA_Form_Submission_Settings::activate();
		CEA_Forms::register_post_type();
	}

	/**
	 * Removes scheduled events while retaining stored plugin data.
	 *
	 * @return void
	 */
	public static function deactivate() {
		CEA_Form_Submission_Settings::deactivate();
	}
}
