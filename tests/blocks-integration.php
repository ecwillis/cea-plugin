<?php
/**
 * Mutating WordPress CLI integration tests for theme blocks.
 *
 * Every fixture (forms and test users) is permanently removed in the
 * cleanup block.
 *
 * Run with:
 * wp eval-file wp-content/plugins/cea-plugin/tests/blocks-integration.php --path=/path/to/wordpress
 *
 * @package CEA_Plugin
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run this file with wp eval-file.' );
}

if ( ! function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}

/**
 * Fails the integration test when a condition is false.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 * @return void
 */
function cea_blocks_integration_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$post_fixture_ids = array();
$user_fixture_ids = array();
$previous_user    = get_current_user_id();
$suffix           = strtolower( wp_generate_password( 8, false, false ) );

try {
	$published_id = wp_insert_post(
		array(
			'post_type'   => CEA_Forms::POST_TYPE,
			'post_title'  => 'CEA Blocks Fixture Published ' . $suffix,
			'post_status' => 'publish',
		),
		true
	);
	cea_blocks_integration_assert( ! is_wp_error( $published_id ), 'Published fixture form could not be created.' );
	$post_fixture_ids[] = absint( $published_id );

	$draft_id = wp_insert_post(
		array(
			'post_type'   => CEA_Forms::POST_TYPE,
			'post_title'  => 'CEA Blocks Fixture Draft ' . $suffix,
			'post_status' => 'draft',
		),
		true
	);
	cea_blocks_integration_assert( ! is_wp_error( $draft_id ), 'Draft fixture form could not be created.' );
	$post_fixture_ids[] = absint( $draft_id );

	// A form that's actually displayable: published, with a labeled field
	// and a valid enabled action, matching what CEA_Form_Schema::validate_configuration()
	// (invoked inside CEA_Form_Renderer::render_shortcode()) requires.
	$renderable_fields  = CEA_Form_Schema::sanitize_fields(
		array( array( 'label' => 'Email', 'type' => 'email', 'required' => 1 ) )
	);
	$renderable_actions = CEA_Form_Schema::sanitize_actions(
		array(
			array(
				'id'       => 'email_1',
				'type'     => 'email',
				'enabled'  => true,
				'settings' => CEA_Form_Email_Action::get_defaults(),
			),
		),
		array()
	);
	$renderable_id       = wp_insert_post(
		array(
			'post_type'   => CEA_Forms::POST_TYPE,
			'post_title'  => 'CEA Blocks Fixture Renderable ' . $suffix,
			'post_status' => 'publish',
		),
		true
	);
	cea_blocks_integration_assert( ! is_wp_error( $renderable_id ), 'Renderable fixture form could not be created.' );
	$post_fixture_ids[] = absint( $renderable_id );
	update_post_meta( $renderable_id, CEA_Forms::META_FIELDS, $renderable_fields );
	update_post_meta( $renderable_id, CEA_Forms::META_ACTIONS, $renderable_actions );

	// CEA_Form_Picker is the shared data source behind both the REST route
	// (Gutenberg) and, later, the Elementor widget's control options — see
	// docs/BLOCKS-PLAN.md, section 3.
	$choices    = CEA_Form_Picker::get_choices();
	$choice_ids = wp_list_pluck( $choices, 'id' );
	cea_blocks_integration_assert( in_array( absint( $published_id ), $choice_ids, true ), 'Published form is missing from the picker.' );
	cea_blocks_integration_assert( ! in_array( absint( $draft_id ), $choice_ids, true ), 'Draft form leaked into the picker.' );

	$editor_id = wp_insert_user(
		array(
			'user_login' => 'cea-blocks-editor-' . $suffix,
			'user_pass'  => wp_generate_password(),
			'user_email' => 'cea-blocks-editor-' . $suffix . '@example.test',
			'role'       => 'editor',
		)
	);
	cea_blocks_integration_assert( ! is_wp_error( $editor_id ), 'Editor fixture user could not be created.' );
	$user_fixture_ids[] = absint( $editor_id );

	$subscriber_id = wp_insert_user(
		array(
			'user_login' => 'cea-blocks-subscriber-' . $suffix,
			'user_pass'  => wp_generate_password(),
			'user_email' => 'cea-blocks-subscriber-' . $suffix . '@example.test',
			'role'       => 'subscriber',
		)
	);
	cea_blocks_integration_assert( ! is_wp_error( $subscriber_id ), 'Subscriber fixture user could not be created.' );
	$user_fixture_ids[] = absint( $subscriber_id );

	// rest_get_server() fires rest_api_init the first time it's called,
	// which is what actually registers our route.
	rest_get_server();

	// An editor can edit posts/pages but cannot manage_options — this is
	// the capability boundary decided in docs/BLOCKS-PLAN.md ("who can
	// place blocks"), deliberately looser than the cea_form CPT's own
	// manage_options-gated capabilities.
	wp_set_current_user( absint( $editor_id ) );
	$editor_response = rest_do_request( new WP_REST_Request( 'GET', '/cea/v1/forms' ) );
	cea_blocks_integration_assert( 200 === $editor_response->get_status(), 'An editor-role user (edit_posts, not manage_options) was denied the form picker.' );
	$editor_ids = wp_list_pluck( $editor_response->get_data(), 'id' );
	cea_blocks_integration_assert( in_array( absint( $published_id ), $editor_ids, true ), 'Editor-role picker response is missing the published form.' );
	cea_blocks_integration_assert( ! in_array( absint( $draft_id ), $editor_ids, true ), 'Editor-role picker response leaked the draft form.' );

	wp_set_current_user( absint( $subscriber_id ) );
	$subscriber_response = rest_do_request( new WP_REST_Request( 'GET', '/cea/v1/forms' ) );
	cea_blocks_integration_assert( in_array( $subscriber_response->get_status(), array( 401, 403 ), true ), 'A subscriber without edit_posts was not denied the form picker.' );

	wp_set_current_user( 0 );
	$anonymous_response = rest_do_request( new WP_REST_Request( 'GET', '/cea/v1/forms' ) );
	cea_blocks_integration_assert( in_array( $anonymous_response->get_status(), array( 401, 403 ), true ), 'A logged-out request was not denied the form picker.' );

	// --- CEA_Form_Block::render() across every state ----------------------
	//
	// render_shortcode() already owns "is this form displayable" (missing
	// ID, unpublished, empty fields); the block only adds an editor-only
	// diagnostic on top — see includes/blocks/form/class-cea-form-block.php.
	$block = new CEA_Form_Block();

	wp_set_current_user( absint( $editor_id ) );
	cea_blocks_integration_assert(
		false !== strpos( $block->render( array( 'formId' => 0 ) ), 'Select a form' ),
		'An editor did not see a "select a form" placeholder for formId 0.'
	);
	cea_blocks_integration_assert(
		false !== strpos( $block->render( array( 'formId' => $draft_id ) ), 'not published' ),
		'An editor did not see a "not published" placeholder for a draft form.'
	);
	cea_blocks_integration_assert(
		false !== strpos( $block->render( array( 'formId' => $published_id ) ), 'no fields' ),
		'An editor did not see a "no fields" placeholder for a published-but-empty form.'
	);
	$editor_markup = $block->render( array( 'formId' => $renderable_id ) );
	cea_blocks_integration_assert( false !== strpos( $editor_markup, 'cea-form' ), 'A renderable form did not render for an editor.' );

	// A second render_shortcode() call for the same form on one page
	// intentionally gets a new "cea-form-{id}-{instance}" DOM id (see
	// CEA_Form_Renderer's static instance counter), so this checks the
	// hidden cea_form_id field resolves correctly rather than expecting
	// byte-identical markup between an int and a string formId.
	$string_id_markup = $block->render( array( 'formId' => '' . $renderable_id ) );
	cea_blocks_integration_assert(
		false !== strpos( $string_id_markup, 'name="cea_form_id" value="' . $renderable_id . '"' ),
		'A string formId attribute (e.g. from a non-Gutenberg caller) was not normalized to the same form as an int.'
	);

	wp_set_current_user( 0 );
	cea_blocks_integration_assert( '' === $block->render( array( 'formId' => 0 ) ), 'An anonymous visitor saw placeholder text for formId 0.' );
	cea_blocks_integration_assert( '' === $block->render( array( 'formId' => $draft_id ) ), 'An anonymous visitor saw placeholder text for a draft form.' );
	cea_blocks_integration_assert( '' === $block->render( array( 'formId' => $published_id ) ), 'An anonymous visitor saw placeholder text for an empty published form.' );
	cea_blocks_integration_assert(
		false !== strpos( $block->render( array( 'formId' => $renderable_id ) ), 'cea-form' ),
		'A renderable form did not render for an anonymous visitor.'
	);

	// The block-renderer REST route is what the editor's ServerSideRender
	// component actually calls, so this is the same code path Gutenberg
	// itself exercises for the live preview.
	wp_set_current_user( absint( $editor_id ) );
	$ssr_request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/cea/form' );
	$ssr_request->set_param( 'attributes', array( 'formId' => $renderable_id ) );
	$ssr_request->set_param( 'context', 'edit' );
	$ssr_response = rest_do_request( $ssr_request );
	cea_blocks_integration_assert( 200 === $ssr_response->get_status(), 'The block-renderer REST route did not render cea/form.' );
	cea_blocks_integration_assert(
		false !== strpos( $ssr_response->get_data()['rendered'], 'cea-form' ),
		'The block-renderer REST route did not return real form markup.'
	);

	// The shared "CEA Plugin" block category exists, so this block (and any
	// future one) groups together in the inserter — see class-cea-blocks.php.
	$categories     = apply_filters( 'block_categories_all', array(), null );
	$category_slugs = wp_list_pluck( $categories, 'slug' );
	cea_blocks_integration_assert( in_array( 'cea', $category_slugs, true ), 'The "cea" block category is not registered.' );

	WP_CLI::success( 'CEA theme blocks integration tests passed.' );
} finally {
	wp_set_current_user( $previous_user );

	foreach ( $post_fixture_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	foreach ( $user_fixture_ids as $user_id ) {
		wp_delete_user( $user_id );
	}
}
