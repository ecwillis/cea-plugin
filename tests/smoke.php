<?php
/**
 * Non-mutating WordPress CLI smoke tests for CEA Plugin.
 *
 * Run with:
 * wp eval-file wp-content/plugins/cea-plugin/tests/smoke.php --path=/path/to/wordpress
 *
 * @package CEA_Plugin
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run this file with wp eval-file.' );
}

/**
 * Fails the smoke test when a condition is false.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 * @return void
 */
function cea_smoke_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

cea_smoke_assert( defined( 'CEA_PLUGIN_VERSION' ), 'Plugin bootstrap did not load.' );
cea_smoke_assert( post_type_exists( CEA_Forms::POST_TYPE ), 'Form post type is not registered.' );

$actions = CEA_Form_Action_Registry::get_all();
cea_smoke_assert( isset( $actions['email'], $actions['webhook'] ), 'Built-in actions are not registered.' );

$fields = CEA_Form_Schema::sanitize_fields(
	array(
		array(
			'label'    => 'Email',
			'type'     => 'email',
			'required' => 1,
		),
		array(
			'label'   => 'Topic',
			'type'    => 'select',
			'choices' => "general|General\nsupport|Support",
		),
	)
);

cea_smoke_assert( 2 === count( $fields ), 'Field schema normalization failed.' );
cea_smoke_assert( 2 === count( $fields[1]['choices'] ), 'Choice normalization failed.' );
cea_smoke_assert( $fields[0]['key'] !== $fields[1]['key'], 'Stable field keys are not unique.' );

$invalid = CEA_Form_Submission_Handler::validate_fields(
	$fields,
	array(
		$fields[0]['key'] => 'not-an-email',
		$fields[1]['key'] => 'unexpected',
	)
);
cea_smoke_assert( 2 === count( $invalid['errors'] ), 'Invalid field values were not rejected.' );

$valid = CEA_Form_Submission_Handler::validate_fields(
	$fields,
	array(
		$fields[0]['key'] => 'person@example.com',
		$fields[1]['key'] => 'support',
		'unexpected'      => 'discarded',
	)
);
cea_smoke_assert( empty( $valid['errors'] ), 'Valid field values were rejected.' );
cea_smoke_assert( ! isset( $valid['values']['unexpected'] ), 'Unexpected fields were not discarded.' );

$template = CEA_Form_Action_Dispatcher::replace_tokens(
	'{{form.title}}: {{field.' . $fields[0]['key'] . '}}',
	array(
		'form_id'      => 10,
		'form_title'   => 'Contact',
		'submitted_at' => '2026-08-10 12:00:00',
		'fields'       => array(
			$fields[0]['key'] => array(
				'label' => 'Email',
				'type'  => 'email',
				'value' => 'person@example.com',
			),
		),
	)
);
cea_smoke_assert( 'Contact: person@example.com' === $template, 'Template token replacement failed.' );

cea_smoke_assert(
	'' !== CEA_Form_Schema::normalize_same_site_url( home_url( '/thank-you/' ) ),
	'Same-site redirects were rejected.'
);
cea_smoke_assert(
	'' === CEA_Form_Schema::normalize_same_site_url( 'https://example.net/thank-you/' ),
	'External redirects were accepted.'
);
cea_smoke_assert(
	true === CEA_Form_Email_Action::validate_settings( CEA_Form_Email_Action::get_defaults() ),
	'Default email settings are invalid.'
);

$hostile_fields = CEA_Form_Schema::sanitize_fields(
	array(
		array(
			'label' => array( 'invalid' ),
			'type'  => array( 'invalid' ),
		),
	)
);
cea_smoke_assert( empty( $hostile_fields ), 'Hostile field configuration was not rejected.' );

$hostile_values = CEA_Form_Submission_Handler::validate_fields(
	array( $fields[0] ),
	array( $fields[0]['key'] => array( 'invalid' ) )
);
cea_smoke_assert( isset( $hostile_values['errors'][ $fields[0]['key'] ] ), 'Array field input was not rejected.' );

$hostile_webhook = CEA_Form_Webhook_Action::sanitize_settings( array( 'url' => array( 'invalid' ) ) );
cea_smoke_assert( '' === $hostile_webhook['url'], 'Array webhook URL was not rejected.' );

CEA_Form_Action_Registry::register(
	'smoke_failure',
	array(
		'label'             => 'Smoke failure',
		'sanitize_callback' => static function ( $settings ) {
			return is_array( $settings ) ? $settings : array();
		},
		'validate_callback' => static function () {
			return true;
		},
		'render_callback'   => static function () {
			return;
		},
		'execute_callback'  => static function () {
			return false;
		},
	)
);
$contract_errors = CEA_Form_Action_Dispatcher::dispatch(
	array(
		array(
			'id'       => 'smoke_failure',
			'type'     => 'smoke_failure',
			'enabled'  => true,
			'settings' => array(),
		),
	),
	array(
		'form_id' => 0,
		'fields'  => array(),
	)
);
cea_smoke_assert( isset( $contract_errors['smoke_failure'] ), 'Invalid action return values were not normalized to errors.' );

WP_CLI::success( 'CEA Plugin smoke tests passed.' );
