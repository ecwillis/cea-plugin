<?php
/**
 * Form submission privacy integration.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers WordPress personal-data tools for stored form submissions.
 */
final class CEA_Form_Submission_Privacy {

	/** Privacy operation page size. */
	const PAGE_SIZE = 50;

	/**
	 * Registers privacy hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
	}

	/**
	 * Registers the personal-data exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( $exporters ) {
		$exporters['cea-form-submissions'] = array(
			'exporter_friendly_name' => __( 'CEA form submissions', 'cea-plugin' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Registers the personal-data eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( $erasers ) {
		$erasers['cea-form-submissions'] = array(
			'eraser_friendly_name' => __( 'CEA form submissions', 'cea-plugin' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Exports stored submissions matching an email field.
	 *
	 * @param string $email_address Requested email address.
	 * @param int    $page          Export page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_personal_data( $email_address, $page = 1 ) {
		$matches = CEA_Form_Submission_Repository::find_by_email( $email_address, $page, self::PAGE_SIZE );
		$data    = array();

		foreach ( $matches['items'] as $submission ) {
			$item_data   = array();
			$item_data[] = array(
				'name'  => __( 'Form', 'cea-plugin' ),
				'value' => $submission['form_title'],
			);
			$item_data[] = array(
				'name'  => __( 'Submitted at', 'cea-plugin' ),
				'value' => get_date_from_gmt( $submission['submitted_at_gmt'], 'Y-m-d H:i:s' ),
			);

			foreach ( $submission['fields'] as $field ) {
				$item_data[] = array(
					'name'  => isset( $field['label'] ) ? $field['label'] : __( 'Field', 'cea-plugin' ),
					'value' => self::format_field_value( $field ),
				);
			}

			$data[] = array(
				'group_id'    => 'cea-form-submissions',
				'group_label' => __( 'CEA form submissions', 'cea-plugin' ),
				'item_id'     => 'cea-form-submission-' . absint( $submission['id'] ),
				'data'        => $item_data,
			);
		}

		return array(
			'data' => $data,
			'done' => ! empty( $matches['done'] ),
		);
	}

	/**
	 * Permanently erases submissions matching an email field.
	 *
	 * Always reads the first page because each processed row is deleted.
	 *
	 * @param string $email_address Requested email address.
	 * @param int    $page          Erasure page, unused because rows shift after deletion.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase_personal_data( $email_address, $page = 1 ) {
		unset( $page );

		$matches = CEA_Form_Submission_Repository::find_by_email( $email_address, 1, self::PAGE_SIZE );
		$ids     = wp_list_pluck( $matches['items'], 'id' );
		$deleted = CEA_Form_Submission_Repository::delete( array_map( 'absint', $ids ) );

		return array(
			'items_removed'  => 0 < $deleted,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => self::PAGE_SIZE > count( $matches['items'] ),
		);
	}

	/**
	 * Adds suggested privacy-policy disclosure text.
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content() {
		$content = '<p>' . esc_html__( 'When visitors submit a CEA form, the validated field values are stored in the WordPress database before configured delivery actions run. The site does not store the form nonce, honeypot value, IP address, referrer, or browser user agent with the response.', 'cea-plugin' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Stored responses are available only to authorized administrators and are retained according to the CEA submission-retention setting. Form responses associated with an email field are included in WordPress personal-data export and erasure requests.', 'cea-plugin' ) . '</p>';

		wp_add_privacy_policy_content( __( 'CEA form submissions', 'cea-plugin' ), wp_kses_post( $content ) );
	}

	/**
	 * Formats one stored field for a privacy export.
	 *
	 * @param array<string, mixed> $field Stored field.
	 * @return string
	 */
	private static function format_field_value( $field ) {
		$value = isset( $field['value'] ) && is_scalar( $field['value'] ) ? (string) $field['value'] : '';

		if ( isset( $field['type'] ) && 'checkbox' === $field['type'] ) {
			return '1' === $value ? __( 'Yes', 'cea-plugin' ) : __( 'No', 'cea-plugin' );
		}

		return $value;
	}
}
