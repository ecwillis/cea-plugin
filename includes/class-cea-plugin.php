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
		CEA_SMTP_Mailer::register_hooks();

		if ( is_admin() ) {
			CEA_SMTP_Settings_Page::register_hooks();
		}
	}
}
