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
cea_smoke_assert( isset( $actions['email'], $actions['webhook'], $actions['mailchimp'] ), 'Built-in actions are not registered.' );

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

cea_smoke_assert(
	'us21' === CEA_Mailchimp_Settings::derive_server_prefix( 'example-key-us21' ),
	'Mailchimp server-prefix derivation failed.'
);
cea_smoke_assert(
	md5( 'person@example.com' ) === CEA_Mailchimp_Client::get_subscriber_hash( ' Person@Example.com ' ),
	'Mailchimp subscriber hashing failed.'
);

$mailchimp_settings = CEA_Form_Mailchimp_Action::sanitize_settings(
	array(
		'audience_id'          => 'abc123',
		'email_field'          => $fields[0]['key'],
		'consent_field'        => 'consent_field',
		'status_if_new'        => 'invalid',
		'first_name_merge_tag' => 'fname',
		'tags'                 => "Newsletter\nNewsletter\nCustomers",
	)
);
cea_smoke_assert( 'pending' === $mailchimp_settings['status_if_new'], 'Invalid Mailchimp opt-in mode was not normalized.' );
cea_smoke_assert( 'FNAME' === $mailchimp_settings['first_name_merge_tag'], 'Mailchimp merge tag was not normalized.' );
cea_smoke_assert( array( 'Newsletter', 'Customers' ) === $mailchimp_settings['tags'], 'Mailchimp tags were not normalized.' );

if ( ! CEA_Mailchimp_Settings::has_external_api_key() && ! CEA_Mailchimp_Settings::has_external_server_prefix() ) {
	$mailchimp_fields = CEA_Form_Schema::sanitize_fields(
		array(
			array(
				'label' => 'Email',
				'type'  => 'email',
			),
			array(
				'label' => 'Mailchimp consent',
				'type'  => 'checkbox',
			),
			array(
				'label' => 'First name',
				'type'  => 'text',
			),
		)
	);
	$mailchimp_action_settings = CEA_Form_Mailchimp_Action::sanitize_settings(
		array(
			'audience_id'      => 'abc123',
			'email_field'      => $mailchimp_fields[0]['key'],
			'consent_field'    => $mailchimp_fields[1]['key'],
			'first_name_field' => $mailchimp_fields[2]['key'],
			'status_if_new'    => 'pending',
			'tags'             => "Newsletter\nCustomer",
		)
	);
	$mailchimp_submission      = array(
		'form_id' => 0,
		'fields'  => array(
			$mailchimp_fields[0]['key'] => array(
				'type'  => 'email',
				'value' => 'Person@Example.com',
			),
			$mailchimp_fields[1]['key'] => array(
				'type'  => 'checkbox',
				'value' => '',
			),
			$mailchimp_fields[2]['key'] => array(
				'type'  => 'text',
				'value' => 'Ada',
			),
		),
	);
	$mailchimp_requests        = array();
	$mailchimp_api_key_filter  = static function () {
		return 'smoke-api-key-us1';
	};
	$mailchimp_settings_filter = static function () {
		return array( 'server_prefix' => 'us1' );
	};
	$mailchimp_http_filter     = static function ( $preempt, $args, $url ) use ( &$mailchimp_requests ) {
		unset( $preempt );
		$mailchimp_requests[] = array(
			'args' => $args,
			'url'  => $url,
		);

		return array(
			'headers'  => array(),
			'body'     => false !== strpos( $url, '/tags' ) ? '' : '{"id":"member"}',
			'response' => array(
				'code'    => false !== strpos( $url, '/tags' ) ? 204 : 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};

	add_filter( 'pre_option_' . CEA_Mailchimp_Settings::API_KEY_OPTION_NAME, $mailchimp_api_key_filter );
	add_filter( 'pre_option_' . CEA_Mailchimp_Settings::OPTION_NAME, $mailchimp_settings_filter );
	add_filter( 'pre_http_request', $mailchimp_http_filter, 10, 3 );

	cea_smoke_assert(
		true === CEA_Form_Mailchimp_Action::execute( $mailchimp_submission, $mailchimp_action_settings ),
		'An unchecked Mailchimp consent field should skip successfully.'
	);
	cea_smoke_assert( empty( $mailchimp_requests ), 'Mailchimp was called without consent.' );

	$mailchimp_submission['fields'][ $mailchimp_fields[1]['key'] ]['value'] = '1';
	cea_smoke_assert(
		true === CEA_Form_Mailchimp_Action::execute( $mailchimp_submission, $mailchimp_action_settings ),
		'Mailchimp action execution failed.'
	);
	cea_smoke_assert( 2 === count( $mailchimp_requests ), 'Mailchimp member and tag requests were not both sent.' );
	cea_smoke_assert( 'PUT' === $mailchimp_requests[0]['args']['method'], 'Mailchimp member request did not use PUT.' );
	cea_smoke_assert(
		false !== strpos( $mailchimp_requests[0]['url'], md5( 'person@example.com' ) ),
		'Mailchimp member request did not use the normalized subscriber hash.'
	);

	$mailchimp_member_payload = json_decode( $mailchimp_requests[0]['args']['body'], true );
	cea_smoke_assert( 'pending' === $mailchimp_member_payload['status_if_new'], 'Mailchimp opt-in mode was not sent.' );
	cea_smoke_assert( ! isset( $mailchimp_member_payload['status'] ), 'Mailchimp action attempted to change an existing contact status.' );
	cea_smoke_assert( 'Ada' === $mailchimp_member_payload['merge_fields']['FNAME'], 'Mailchimp merge field was not mapped.' );

	$mailchimp_tag_payload = json_decode( $mailchimp_requests[1]['args']['body'], true );
	cea_smoke_assert( 'POST' === $mailchimp_requests[1]['args']['method'], 'Mailchimp tag request did not use POST.' );
	cea_smoke_assert( 'Newsletter' === $mailchimp_tag_payload['tags'][0]['name'], 'Mailchimp tags were not sent.' );
	cea_smoke_assert( 'active' === $mailchimp_tag_payload['tags'][0]['status'], 'Mailchimp tag was not activated.' );

	remove_filter( 'pre_option_' . CEA_Mailchimp_Settings::API_KEY_OPTION_NAME, $mailchimp_api_key_filter );
	remove_filter( 'pre_option_' . CEA_Mailchimp_Settings::OPTION_NAME, $mailchimp_settings_filter );
	remove_filter( 'pre_http_request', $mailchimp_http_filter, 10 );
}

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
