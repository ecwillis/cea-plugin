<?php
/**
 * Mutating WordPress CLI integration tests for stored form submissions.
 *
 * Every fixture is permanently removed in the cleanup block.
 *
 * Run with:
 * wp eval-file wp-content/plugins/cea-plugin/tests/submissions-integration.php --path=/path/to/wordpress
 *
 * @package CEA_Plugin
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run this file with wp eval-file.' );
}

/**
 * Fails the integration test when a condition is false.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 * @return void
 */
function cea_submission_integration_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$fixture_ids    = array();
$previous_user  = get_current_user_id();
$previous_query = $_GET;
$suffix         = strtolower( wp_generate_password( 10, false, false ) );
$email          = 'cea-storage-' . $suffix . '@example.com';
$fields         = array(
	'contact_email' => array(
		'label' => 'Email address',
		'type'  => 'email',
		'value' => $email,
	),
	'message'       => array(
		'label' => 'Message',
		'type'  => 'textarea',
		'value' => "First line\nSecond line",
	),
);
$actions        = array(
	array(
		'id'      => 'email_fixture',
		'type'    => 'email',
		'enabled' => true,
	),
	array(
		'id'      => 'webhook_fixture',
		'type'    => 'webhook',
		'enabled' => true,
	),
);

try {
	CEA_Form_Submission_Repository::install();
	cea_submission_integration_assert( CEA_Form_Submission_Repository::table_exists(), 'Submission table was not installed.' );

	$submission = array(
		'form_id'          => 987654,
		'form_title'       => str_repeat( 'Storage fixture ', 30 ),
		'submitted_at_gmt' => current_time( 'mysql', true ),
		'fields'           => $fields,
	);
	$token      = 'cea-storage-' . wp_generate_uuid4();
	$created    = CEA_Form_Submission_Repository::create( $submission, $token );

	cea_submission_integration_assert( ! is_wp_error( $created ) && ! empty( $created['created'] ), 'Submission insert failed.' );
	$fixture_ids[] = absint( $created['id'] );

	$stored = CEA_Form_Submission_Repository::get( $created['id'] );
	cea_submission_integration_assert( is_array( $stored ), 'Stored submission could not be retrieved.' );
	cea_submission_integration_assert( 255 >= strlen( $stored['form_title'] ), 'Stored form title exceeded its database column.' );
	cea_submission_integration_assert( $email === $stored['fields']['contact_email']['value'], 'Normalized email snapshot was not retained.' );
	cea_submission_integration_assert( 'processing' === $stored['delivery_status'], 'New submission did not begin in processing state.' );
	cea_submission_integration_assert( hash( 'sha256', $token ) === $stored['token_hash'], 'Submission token was not stored as a SHA-256 hash.' );
	cea_submission_integration_assert( $token !== $stored['token_hash'], 'Raw submission token was stored.' );

	$duplicate = CEA_Form_Submission_Repository::create( $submission, $token );
	cea_submission_integration_assert( ! is_wp_error( $duplicate ) && empty( $duplicate['created'] ), 'Duplicate token created another response.' );
	cea_submission_integration_assert( absint( $created['id'] ) === absint( $duplicate['id'] ), 'Duplicate token did not resolve to the original response.' );

	$partial_status = CEA_Form_Submission_Repository::update_action_results(
		$created['id'],
		$actions,
		array( 'webhook_fixture' => new WP_Error( 'webhook_fixture_failure', 'Sensitive failure for ' . $email ) )
	);
	cea_submission_integration_assert( 'partial_failure' === $partial_status, 'Mixed action results did not produce partial_failure.' );

	$stored         = CEA_Form_Submission_Repository::get( $created['id'] );
	$stored_results = wp_json_encode( $stored['action_results'] );
	cea_submission_integration_assert( false === strpos( $stored_results, $email ), 'Action results retained submitted personal data.' );
	cea_submission_integration_assert( false === strpos( $stored_results, 'Sensitive failure' ), 'Action results retained an unsafe error message.' );
	cea_submission_integration_assert( 'webhook_fixture_failure' === $stored['action_results'][1]['code'], 'Safe action error code was not retained.' );

	$completed = CEA_Form_Submission_Repository::create(
		array_merge( $submission, array( 'form_title' => 'Completed storage fixture' ) ),
		'cea-completed-' . wp_generate_uuid4()
	);
	cea_submission_integration_assert( ! is_wp_error( $completed ), 'Completed fixture insert failed.' );
	$fixture_ids[] = absint( $completed['id'] );
	cea_submission_integration_assert(
		'completed' === CEA_Form_Submission_Repository::update_action_results( $completed['id'], array( $actions[0] ), array() ),
		'Successful actions did not produce completed.'
	);

	$failed = CEA_Form_Submission_Repository::create(
		array_merge( $submission, array( 'form_title' => 'Failed storage fixture' ) ),
		'cea-failed-' . wp_generate_uuid4()
	);
	cea_submission_integration_assert( ! is_wp_error( $failed ), 'Failed fixture insert failed.' );
	$fixture_ids[] = absint( $failed['id'] );
	cea_submission_integration_assert(
		'failed' === CEA_Form_Submission_Repository::update_action_results(
			$failed['id'],
			array( $actions[0] ),
			array( 'email_fixture' => new WP_Error( 'email_fixture_failure', 'Delivery failed.' ) )
		),
		'All failed actions did not produce failed.'
	);

	$filtered = CEA_Form_Submission_Repository::query(
		array(
			'form_id'  => 987654,
			'status'   => 'partial_failure',
			'per_page' => 10,
		)
	);
	cea_submission_integration_assert( 1 <= $filtered['total'], 'Form and status filters did not find the fixture.' );

	cea_submission_integration_assert(
		1 === CEA_Form_Submission_Repository::set_reviewed( array( $created['id'] ), true, 1 ),
		'Submission review state was not updated.'
	);
	$reviewed = CEA_Form_Submission_Repository::get( $created['id'] );
	cea_submission_integration_assert( null !== $reviewed['reviewed_at_gmt'], 'Reviewed timestamp was not retained.' );

	$email_matches = CEA_Form_Submission_Repository::find_by_email( strtoupper( $email ), 1, 50 );
	cea_submission_integration_assert( 3 === count( $email_matches['items'] ), 'Exact normalized email lookup did not find every fixture.' );
	$export = CEA_Form_Submission_Privacy::export_personal_data( $email, 1 );
	cea_submission_integration_assert( 3 === count( $export['data'] ), 'Privacy exporter did not return every matching fixture.' );

	$administrators = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	cea_submission_integration_assert( ! empty( $administrators ), 'An administrator is required to test the private submissions screen.' );
	wp_set_current_user( absint( $administrators[0] ) );
	$_GET = array(
		'page'       => CEA_Form_Submissions_Admin::PAGE_SLUG,
		'submission' => absint( $created['id'] ),
	);
	ob_start();
	CEA_Form_Submissions_Admin::render_page();
	$admin_html = ob_get_clean();
	cea_submission_integration_assert( false !== strpos( $admin_html, 'Form Submissions' ), 'Private submissions screen did not render.' );
	cea_submission_integration_assert( false !== strpos( $admin_html, esc_html( $email ) ), 'Private submissions screen did not render the stored response.' );

	cea_submission_integration_assert( 1 === CEA_Form_Submission_Repository::delete( array( $failed['id'] ) ), 'Permanent deletion failed.' );
	$fixture_ids = array_values( array_diff( $fixture_ids, array( absint( $failed['id'] ) ) ) );

	WP_CLI::success( 'CEA form submission storage integration tests passed.' );
} finally {
	$_GET = $previous_query;
	wp_set_current_user( $previous_user );

	if ( ! empty( $fixture_ids ) ) {
		CEA_Form_Submission_Repository::delete( $fixture_ids );
	}
}
