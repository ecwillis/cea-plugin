<?php
/**
 * Plugin Name: CEA Plugin
 * Description: A WordPress form builder with SMTP delivery, Mailchimp integration, and private response storage.
 * Version: 0.5.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Text Domain: cea-plugin
 */

defined( 'ABSPATH' ) || exit;

define( 'CEA_PLUGIN_VERSION', '0.5.0' );
define( 'CEA_PLUGIN_FILE', __FILE__ );
define( 'CEA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CEA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CEA_PLUGIN_DIR . 'includes/class-cea-smtp-settings.php';
require_once CEA_PLUGIN_DIR . 'includes/class-cea-smtp-mailer.php';
require_once CEA_PLUGIN_DIR . 'includes/integrations/class-cea-mailchimp-settings.php';
require_once CEA_PLUGIN_DIR . 'includes/integrations/class-cea-mailchimp-client.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-action-registry.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-action-dispatcher.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-submission-repository.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-submission-settings.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-submission-privacy.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/actions/class-cea-form-email-action.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/actions/class-cea-form-webhook-action.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/actions/class-cea-form-mailchimp-action.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-schema.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-renderer.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-form-submission-handler.php';
require_once CEA_PLUGIN_DIR . 'includes/forms/class-cea-forms.php';
require_once CEA_PLUGIN_DIR . 'includes/blocks/class-cea-form-picker.php';
require_once CEA_PLUGIN_DIR . 'includes/blocks/class-cea-rest-form-picker-controller.php';
require_once CEA_PLUGIN_DIR . 'includes/blocks/class-cea-block-base.php';
require_once CEA_PLUGIN_DIR . 'includes/blocks/class-cea-block-registry.php';
require_once CEA_PLUGIN_DIR . 'includes/blocks/form/class-cea-form-block.php';
require_once CEA_PLUGIN_DIR . 'includes/integrations/class-cea-elementor-integration.php';
require_once CEA_PLUGIN_DIR . 'includes/blocks/class-cea-blocks.php';
require_once CEA_PLUGIN_DIR . 'admin/class-cea-smtp-settings-page.php';
require_once CEA_PLUGIN_DIR . 'admin/class-cea-mailchimp-settings-page.php';
require_once CEA_PLUGIN_DIR . 'admin/class-cea-forms-admin.php';
require_once CEA_PLUGIN_DIR . 'admin/class-cea-form-submissions-admin.php';
require_once CEA_PLUGIN_DIR . 'includes/class-cea-plugin.php';

register_activation_hook( CEA_PLUGIN_FILE, array( 'CEA_Plugin', 'activate' ) );
register_deactivation_hook( CEA_PLUGIN_FILE, array( 'CEA_Plugin', 'deactivate' ) );

CEA_Plugin::init();
