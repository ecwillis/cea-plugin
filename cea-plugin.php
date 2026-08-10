<?php
/**
 * Plugin Name: CEA Plugin
 * Description: A multipurpose WordPress plugin with configurable SMTP email delivery and a simple form builder.
 * Version: 0.3.2
 * Requires at least: 5.7
 * Requires PHP: 7.4
 * Text Domain: cea-plugin
 */

defined( 'ABSPATH' ) || exit;

define( 'CEA_PLUGIN_VERSION', '0.3.2' );
define( 'CEA_PLUGIN_FILE', __FILE__ );
define( 'CEA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CEA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CEA_PLUGIN_DIR . 'includes/class-cea-smtp-settings.php';
require_once CEA_PLUGIN_DIR . 'includes/class-cea-smtp-mailer.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-action-registry.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-action-dispatcher.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/actions/class-cea-form-email-action.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/actions/class-cea-form-webhook-action.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-schema.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-renderer.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-submission-handler.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-forms.php';
require_once CEA_PLUGIN_DIR . 'admin/class-cea-smtp-settings-page.php';
require_once CEA_PLUGIN_DIR . 'admin/class-cea-forms-admin.php';
require_once CEA_PLUGIN_DIR . 'includes/class-cea-plugin.php';

register_activation_hook( CEA_PLUGIN_FILE, array( 'CEA_Plugin', 'activate' ) );

CEA_Plugin::init();
