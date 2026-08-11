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
		CEA_Form_Action_Registry::register_defaults();
		CEA_Forms::register_hooks();
		CEA_SMTP_Mailer::register_hooks();

		if ( is_admin() ) {
			CEA_Forms_Admin::register_hooks();
			CEA_SMTP_Settings_Page::register_hooks();
			CEA_Mailchimp_Settings_Page::register_hooks();
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
		CEA_Forms::register_post_type();
	}
}
