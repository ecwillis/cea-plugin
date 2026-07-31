<?php
/**
 * Plugin Name: CEA Plugin
 * Description: A multipurpose WordPress plugin with configurable SMTP email delivery.
 * Version: 0.2.0
 * Requires at least: 5.7
 * Requires PHP: 7.4
 * Text Domain: cea-plugin
 */

defined( 'ABSPATH' ) || exit;

define( 'CEA_PLUGIN_VERSION', '0.2.0' );
define( 'CEA_PLUGIN_FILE', __FILE__ );
define( 'CEA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once CEA_PLUGIN_DIR . 'includes/class-cea-smtp-settings.php';
require_once CEA_PLUGIN_DIR . 'includes/class-cea-smtp-mailer.php';
require_once CEA_PLUGIN_DIR . 'admin/class-cea-smtp-settings-page.php';
require_once CEA_PLUGIN_DIR . 'includes/class-cea-plugin.php';

register_activation_hook( CEA_PLUGIN_FILE, array( 'CEA_SMTP_Settings', 'activate' ) );

CEA_Plugin::init();
