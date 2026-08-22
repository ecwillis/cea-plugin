<?php
/**
 * Shared "which forms can be picked" data source.
 *
 * Used by both the Gutenberg block (over REST) and, later, the Elementor
 * widget (direct PHP call from wp-admin) so the two editors always see the
 * same list — see docs/BLOCKS-PLAN.md, section 3.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provides the published-forms picker list.
 */
final class CEA_Form_Picker {

	/**
	 * Returns published forms as lightweight { id, title } choices.
	 *
	 * Intentionally excludes fields, actions, and settings: this list is
	 * safe to expose to any user who can edit posts/pages, not just users
	 * who can manage forms.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	public static function get_choices() {
		$forms = get_posts(
			array(
				'post_type'      => CEA_Forms::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$choices = array();

		foreach ( $forms as $form ) {
			$title = trim( $form->post_title );

			$choices[] = array(
				'id'    => $form->ID,
				'title' => '' !== $title ? $title : __( '(no title)', 'cea-plugin' ),
			);
		}

		return $choices;
	}
}
