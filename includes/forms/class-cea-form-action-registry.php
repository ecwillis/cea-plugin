<?php
/**
 * Form action registry.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and resolves form action types.
 */
final class CEA_Form_Action_Registry {

	/**
	 * Registered action definitions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $definitions = array();

	/**
	 * Whether default actions have been registered.
	 *
	 * @var bool
	 */
	private static $defaults_registered = false;

	/**
	 * Registers built-in action types.
	 *
	 * @return void
	 */
	public static function register_defaults() {
		if ( self::$defaults_registered ) {
			return;
		}

		self::$defaults_registered = true;
		self::register( 'email', CEA_Form_Email_Action::get_definition() );
		self::register( 'webhook', CEA_Form_Webhook_Action::get_definition() );
		self::register( 'mailchimp', CEA_Form_Mailchimp_Action::get_definition() );

		/**
		 * Fires after built-in form actions are registered.
		 *
		 * @param string $registry_class Registry class name.
		 */
		do_action( 'cea_form_register_actions', __CLASS__ );
	}

	/**
	 * Registers an action definition.
	 *
	 * @param string               $type       Action type.
	 * @param array<string, mixed> $definition Action definition.
	 * @return bool
	 */
	public static function register( $type, $definition ) {
		$type = sanitize_key( $type );

		if (
			'' === $type
			|| ! is_array( $definition )
			|| empty( $definition['label'] )
			|| empty( $definition['sanitize_callback'] )
			|| empty( $definition['validate_callback'] )
			|| empty( $definition['render_callback'] )
			|| empty( $definition['execute_callback'] )
		) {
			return false;
		}

		self::$definitions[ $type ] = $definition;

		return true;
	}

	/**
	 * Returns all registered action definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all() {
		/**
		 * Filters the available form action definitions.
		 *
		 * @param array<string, array<string, mixed>> $definitions Action definitions.
		 */
		$definitions = apply_filters( 'cea_form_action_types', self::$definitions );

		return is_array( $definitions ) ? $definitions : self::$definitions;
	}

	/**
	 * Returns one action definition.
	 *
	 * @param string $type Action type.
	 * @return array<string, mixed>|null
	 */
	public static function get( $type ) {
		$definitions = self::get_all();
		$type        = sanitize_key( $type );

		return isset( $definitions[ $type ] ) && is_array( $definitions[ $type ] ) ? $definitions[ $type ] : null;
	}

	/**
	 * Sanitizes action settings.
	 *
	 * @param string               $type     Action type.
	 * @param mixed                $settings Submitted settings.
	 * @param array<string, mixed> $existing Existing settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( $type, $settings, $existing = array() ) {
		$definition = self::get( $type );

		if ( null === $definition || ! is_callable( $definition['sanitize_callback'] ) ) {
			return array();
		}

		$result = call_user_func(
			$definition['sanitize_callback'],
			is_array( $settings ) ? $settings : array(),
			$existing
		);

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Validates action settings.
	 *
	 * @param string               $type     Action type.
	 * @param array<string, mixed> $settings Action settings.
	 * @param array<string, mixed> $context  Optional validation context.
	 * @return true|WP_Error
	 */
	public static function validate_settings( $type, $settings, $context = array() ) {
		$definition = self::get( $type );

		if ( null === $definition || ! is_callable( $definition['validate_callback'] ) ) {
			return new WP_Error( 'cea_form_unknown_action', __( 'The selected form action is unavailable.', 'cea-plugin' ) );
		}

		$result = call_user_func( $definition['validate_callback'], $settings, $context );

		return true === $result || $result instanceof WP_Error
			? $result
			: new WP_Error( 'cea_form_invalid_action', __( 'The form action configuration is invalid.', 'cea-plugin' ) );
	}

	/**
	 * Renders action settings.
	 *
	 * @param string               $type     Action type.
	 * @param string               $name     Base input name.
	 * @param array<string, mixed> $settings Action settings.
	 * @param array<string, mixed> $context  Optional rendering context.
	 * @return void
	 */
	public static function render_settings( $type, $name, $settings, $context = array() ) {
		$definition = self::get( $type );

		if ( null !== $definition && is_callable( $definition['render_callback'] ) ) {
			call_user_func( $definition['render_callback'], $name, $settings, $context );
		}
	}
}
