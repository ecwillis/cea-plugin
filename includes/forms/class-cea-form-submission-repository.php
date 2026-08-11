<?php
/**
 * Persistent form submission storage.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Installs and accesses the form submissions table.
 */
final class CEA_Form_Submission_Repository {

	/** Database schema version. */
	const DB_VERSION = '1';

	/** Database schema version option. */
	const DB_VERSION_OPTION = 'cea_form_submissions_db_version';

	/** Maximum rows returned by one query. */
	const MAX_PER_PAGE = 100;

	/**
	 * Returns the prefixed submissions table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'cea_form_submissions';
	}

	/**
	 * Creates or upgrades the submissions table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			form_title varchar(255) NOT NULL DEFAULT '',
			token_hash char(64) NOT NULL,
			submitted_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			fields longtext NOT NULL,
			email_hashes text NOT NULL,
			delivery_status varchar(20) NOT NULL DEFAULT 'processing',
			action_results longtext NOT NULL,
			reviewed_at_gmt datetime DEFAULT NULL,
			reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY form_submitted (form_id, submitted_at_gmt),
			KEY status_submitted (delivery_status, submitted_at_gmt),
			KEY reviewed_submitted (reviewed_at_gmt, submitted_at_gmt)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( self::table_exists() ) {
			if ( false === get_option( self::DB_VERSION_OPTION, false ) ) {
				add_option( self::DB_VERSION_OPTION, self::DB_VERSION, '', false );
			} else {
				update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
			}
		}
	}

	/**
	 * Upgrades the table after an already-active plugin is updated.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			self::install();
		}
	}

	/**
	 * Returns whether the submissions table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table_name = self::get_table_name();
		$found      = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table_name )
			)
		);

		return $table_name === $found;
	}

	/**
	 * Inserts a normalized submission or returns its existing idempotent row.
	 *
	 * @param array<string, mixed> $submission Normalized submission.
	 * @param string               $token      Browser-generated submission token.
	 * @return array{id: int, created: bool, status: string}|WP_Error
	 */
	public static function create( $submission, $token ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return new WP_Error( 'cea_form_submission_table_missing', __( 'Form submission storage is unavailable.', 'cea-plugin' ) );
		}

		if ( ! self::is_valid_token( $token ) ) {
			$token = wp_generate_uuid4() . '-' . wp_generate_password( 24, false, false );
		}

		$token_hash = hash( 'sha256', $token );
		$existing   = self::get_by_token_hash( $token_hash );

		if ( null !== $existing ) {
			return array(
				'id'      => absint( $existing['id'] ),
				'created' => false,
				'status'  => $existing['delivery_status'],
			);
		}

		$fields         = self::normalize_fields( isset( $submission['fields'] ) ? $submission['fields'] : array() );
		$fields_json    = wp_json_encode( $fields );
		$email_hashes   = wp_json_encode( self::get_email_hashes( $fields ) );
		$action_results = wp_json_encode( array() );

		if ( ! is_string( $fields_json ) || ! is_string( $email_hashes ) || ! is_string( $action_results ) ) {
			return new WP_Error( 'cea_form_submission_encode', __( 'The form submission could not be prepared for storage.', 'cea-plugin' ) );
		}

		$submitted_at_gmt = isset( $submission['submitted_at_gmt'] ) && self::is_mysql_datetime( $submission['submitted_at_gmt'] )
			? $submission['submitted_at_gmt']
			: current_time( 'mysql', true );
		$inserted         = $wpdb->insert(
			self::get_table_name(),
			array(
				'form_id'          => isset( $submission['form_id'] ) ? absint( $submission['form_id'] ) : 0,
				'form_title'       => isset( $submission['form_title'] ) && is_scalar( $submission['form_title'] )
					? wp_html_excerpt( sanitize_text_field( (string) $submission['form_title'] ), 255, '' )
					: '',
				'token_hash'       => $token_hash,
				'submitted_at_gmt' => $submitted_at_gmt,
				'updated_at_gmt'   => $submitted_at_gmt,
				'fields'           => $fields_json,
				'email_hashes'     => $email_hashes,
				'delivery_status'  => 'processing',
				'action_results'   => $action_results,
				'reviewed_by'      => 0,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			$existing = self::get_by_token_hash( $token_hash );

			if ( null !== $existing ) {
				return array(
					'id'      => absint( $existing['id'] ),
					'created' => false,
					'status'  => $existing['delivery_status'],
				);
			}

			return new WP_Error( 'cea_form_submission_insert', __( 'The form submission could not be saved.', 'cea-plugin' ) );
		}

		$submission_id = absint( $wpdb->insert_id );

		/**
		 * Fires after a validated form submission is stored.
		 *
		 * Submitted values are intentionally omitted.
		 *
		 * @param int $submission_id Submission ID.
		 * @param int $form_id       Form ID.
		 */
		do_action(
			'cea_form_submission_stored',
			$submission_id,
			isset( $submission['form_id'] ) ? absint( $submission['form_id'] ) : 0
		);

		return array(
			'id'      => $submission_id,
			'created' => true,
			'status'  => 'processing',
		);
	}

	/**
	 * Updates action outcomes and returns the aggregate delivery status.
	 *
	 * @param int                               $submission_id Submission ID.
	 * @param array<int, array<string, mixed>>  $actions       Configured actions.
	 * @param array<string, WP_Error>            $errors        Action errors keyed by action ID.
	 * @return string|WP_Error
	 */
	public static function update_action_results( $submission_id, $actions, $errors ) {
		global $wpdb;

		$results = array();
		$failed  = 0;

		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || empty( $action['enabled'] ) || empty( $action['type'] ) ) {
				continue;
			}

			$type       = sanitize_key( $action['type'] );
			$action_id  = ! empty( $action['id'] ) ? sanitize_key( $action['id'] ) : $type;
			$definition = CEA_Form_Action_Registry::get( $type );
			$result     = array(
				'id'      => $action_id,
				'type'    => $type,
				'label'   => null !== $definition ? sanitize_text_field( $definition['label'] ) : $type,
				'status'  => 'completed',
				'code'    => '',
				'message' => '',
			);

			if ( isset( $errors[ $action_id ] ) && is_wp_error( $errors[ $action_id ] ) ) {
				++$failed;
				$code              = $errors[ $action_id ]->get_error_code();
				$result['status']  = 'failed';
				$result['code']    = is_scalar( $code ) ? sanitize_key( (string) $code ) : '';
				$result['message'] = __( 'The action reported a delivery failure.', 'cea-plugin' );
			}

			$results[] = $result;
		}

		$total  = count( $results );
		$status = 0 === $failed ? 'completed' : ( $failed >= $total ? 'failed' : 'partial_failure' );
		$json   = wp_json_encode( $results );

		if ( ! is_string( $json ) ) {
			return new WP_Error( 'cea_form_submission_results_encode', __( 'The form action results could not be prepared for storage.', 'cea-plugin' ) );
		}

		$updated = $wpdb->update(
			self::get_table_name(),
			array(
				'delivery_status' => $status,
				'action_results'  => $json,
				'updated_at_gmt'  => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $submission_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'cea_form_submission_update', __( 'The form delivery status could not be saved.', 'cea-plugin' ) );
		}

		/**
		 * Fires after a stored submission's delivery status is updated.
		 *
		 * @param int    $submission_id Submission ID.
		 * @param string $status        Aggregate delivery status.
		 */
		do_action( 'cea_form_submission_updated', absint( $submission_id ), $status );

		return $status;
	}

	/**
	 * Returns one stored submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( $submission_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_table_name() . ' WHERE id = %d',
				absint( $submission_id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::normalize_row( $row ) : null;
	}

	/**
	 * Queries stored submissions for the administrator list.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'form_id'  => 0,
				'status'   => '',
				'reviewed' => '',
				'date_from' => '',
				'date_to'   => '',
				'page'       => 1,
				'per_page'   => 25,
			)
		);
		$where = self::build_where( $args );
		$count = 'SELECT COUNT(*) FROM ' . self::get_table_name() . $where['sql'];
		$total = empty( $where['values'] )
			? absint( $wpdb->get_var( $count ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name and clauses are internally generated.
			: absint( $wpdb->get_var( $wpdb->prepare( $count, $where['values'] ) ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $args['per_page'] ) ) );
		$page     = max( 1, absint( $args['page'] ) );
		$offset   = ( $page - 1 ) * $per_page;
		$sql      = 'SELECT * FROM ' . self::get_table_name() . $where['sql'] . ' ORDER BY submitted_at_gmt DESC, id DESC LIMIT %d OFFSET %d';
		$values   = array_merge( $where['values'], array( $per_page, $offset ) );
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		$items    = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items[] = self::normalize_row( $row );
		}

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	/**
	 * Returns forms represented in stored submissions.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	public static function get_form_options() {
		global $wpdb;

		$rows    = $wpdb->get_results( 'SELECT form_id, form_title FROM ' . self::get_table_name() . ' ORDER BY submitted_at_gmt DESC LIMIT 2000', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static query against a trusted table name.
		$options = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$form_id = isset( $row['form_id'] ) ? absint( $row['form_id'] ) : 0;

			if ( ! isset( $options[ $form_id ] ) ) {
				$options[ $form_id ] = array(
					'id'    => $form_id,
					'title' => isset( $row['form_title'] ) ? sanitize_text_field( $row['form_title'] ) : '',
				);
			}
		}

		return array_values( $options );
	}

	/**
	 * Returns the count of unreviewed submissions for a form or all forms.
	 *
	 * @param int $form_id Optional form ID.
	 * @return int
	 */
	public static function count_unreviewed( $form_id = 0 ) {
		global $wpdb;

		if ( 0 < $form_id ) {
			return absint(
				$wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE reviewed_at_gmt IS NULL AND form_id = %d',
						absint( $form_id )
					)
				)
			);
		}

		return absint( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE reviewed_at_gmt IS NULL' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static query against a trusted table name.
	}

	/**
	 * Marks submissions reviewed or unreviewed.
	 *
	 * @param array<int, int> $submission_ids Submission IDs.
	 * @param bool            $reviewed       Review state.
	 * @param int             $user_id        Reviewing user ID.
	 * @return int
	 */
	public static function set_reviewed( $submission_ids, $reviewed, $user_id ) {
		global $wpdb;

		$count = 0;

		foreach ( self::normalize_ids( $submission_ids ) as $submission_id ) {
			$updated = $wpdb->update(
				self::get_table_name(),
				array(
					'reviewed_at_gmt' => $reviewed ? current_time( 'mysql', true ) : null,
					'reviewed_by'     => $reviewed ? absint( $user_id ) : 0,
					'updated_at_gmt'  => current_time( 'mysql', true ),
				),
				array( 'id' => $submission_id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);

			if ( false !== $updated ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Permanently deletes submissions.
	 *
	 * @param array<int, int> $submission_ids Submission IDs.
	 * @return int
	 */
	public static function delete( $submission_ids ) {
		global $wpdb;

		$count = 0;

		foreach ( self::normalize_ids( $submission_ids ) as $submission_id ) {
			$deleted = $wpdb->delete( self::get_table_name(), array( 'id' => $submission_id ), array( '%d' ) );

			if ( false !== $deleted && 0 < $deleted ) {
				++$count;

				/**
				 * Fires after a stored form submission is permanently deleted.
				 *
				 * @param int $submission_id Submission ID.
				 */
				do_action( 'cea_form_submission_deleted', $submission_id );
			}
		}

		return $count;
	}

	/**
	 * Deletes a bounded batch older than a GMT cutoff.
	 *
	 * @param string $cutoff_gmt GMT MySQL datetime.
	 * @param int    $limit      Maximum rows to delete.
	 * @return int
	 */
	public static function delete_older_than( $cutoff_gmt, $limit = 500 ) {
		global $wpdb;

		if ( ! self::is_mysql_datetime( $cutoff_gmt ) ) {
			return 0;
		}

		$limit = min( 500, max( 1, absint( $limit ) ) );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . self::get_table_name() . ' WHERE submitted_at_gmt < %s ORDER BY submitted_at_gmt ASC LIMIT %d',
				$cutoff_gmt,
				$limit
			)
		);

		return self::delete( array_map( 'absint', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * Deletes a bounded batch of the oldest stored submissions.
	 *
	 * @param int $limit Maximum rows to delete.
	 * @return int
	 */
	public static function delete_oldest( $limit = 500 ) {
		global $wpdb;

		$limit = min( 500, max( 1, absint( $limit ) ) );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . self::get_table_name() . ' ORDER BY submitted_at_gmt ASC LIMIT %d',
				$limit
			)
		);

		return self::delete( array_map( 'absint', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * Finds submissions associated with an exact normalized email address.
	 *
	 * @param string $email    Email address.
	 * @param int    $page     One-based page.
	 * @param int    $per_page Rows per page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, done: bool}
	 */
	public static function find_by_email( $email, $page = 1, $per_page = 50 ) {
		global $wpdb;

		$email = strtolower( trim( sanitize_email( $email ) ) );

		if ( ! is_email( $email ) ) {
			return array( 'items' => array(), 'total' => 0, 'done' => true );
		}

		$hash     = self::get_email_hash( $email );
		$like     = '%' . $wpdb->esc_like( '"' . $hash . '"' ) . '%';
		$page     = max( 1, absint( $page ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $per_page ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$total    = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE email_hashes LIKE %s',
					$like
				)
			)
		);
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_table_name() . ' WHERE email_hashes LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d',
				$like,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$items    = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$item = self::normalize_row( $row );

			if ( self::row_contains_email( $item, $email ) ) {
				$items[] = $item;
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
			'done'  => $offset + $per_page >= $total,
		);
	}

	/**
	 * Returns whether a browser submission token is valid.
	 *
	 * @param mixed $token Submission token.
	 * @return bool
	 */
	public static function is_valid_token( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-zA-Z0-9-]{16,80}$/', $token );
	}

	/**
	 * Returns a stored row by its token hash.
	 *
	 * @param string $token_hash Token hash.
	 * @return array<string, mixed>|null
	 */
	private static function get_by_token_hash( $token_hash ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_table_name() . ' WHERE token_hash = %s',
				$token_hash
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::normalize_row( $row ) : null;
	}

	/**
	 * Builds query conditions from allowlisted arguments.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{sql: string, values: array<int, mixed>}
	 */
	private static function build_where( $args ) {
		$conditions = array();
		$values     = array();
		$form_id    = isset( $args['form_id'] ) ? absint( $args['form_id'] ) : 0;
		$status     = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
		$reviewed   = isset( $args['reviewed'] ) ? sanitize_key( $args['reviewed'] ) : '';

		if ( 0 < $form_id ) {
			$conditions[] = 'form_id = %d';
			$values[]     = $form_id;
		}

		if ( in_array( $status, array( 'processing', 'completed', 'partial_failure', 'failed' ), true ) ) {
			$conditions[] = 'delivery_status = %s';
			$values[]     = $status;
		}

		if ( 'reviewed' === $reviewed ) {
			$conditions[] = 'reviewed_at_gmt IS NOT NULL';
		} elseif ( 'unreviewed' === $reviewed ) {
			$conditions[] = 'reviewed_at_gmt IS NULL';
		}

		if ( ! empty( $args['date_from'] ) && self::is_date( $args['date_from'] ) ) {
			$conditions[] = 'submitted_at_gmt >= %s';
			$values[]     = get_gmt_from_date( $args['date_from'] . ' 00:00:00' );
		}

		if ( ! empty( $args['date_to'] ) && self::is_date( $args['date_to'] ) ) {
			$conditions[] = 'submitted_at_gmt <= %s';
			$values[]     = get_gmt_from_date( $args['date_to'] . ' 23:59:59' );
		}

		return array(
			'sql'    => empty( $conditions ) ? '' : ' WHERE ' . implode( ' AND ', $conditions ),
			'values' => $values,
		);
	}

	/**
	 * Normalizes fields before storage.
	 *
	 * @param mixed $fields Submission fields.
	 * @return array<string, array<string, string>>
	 */
	private static function normalize_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$normalized = array();

		foreach ( array_slice( $fields, 0, CEA_Form_Schema::MAX_FIELDS, true ) as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$key = sanitize_key( $key );

			if ( '' === $key ) {
				continue;
			}

			$value = isset( $field['value'] ) && is_scalar( $field['value'] ) ? (string) $field['value'] : '';
			$value = substr( $value, 0, 10000 );

			$normalized[ $key ] = array(
				'label' => isset( $field['label'] ) && is_scalar( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : $key,
				'type'  => isset( $field['type'] ) && is_scalar( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text',
				'value' => $value,
			);
		}

		return $normalized;
	}

	/**
	 * Returns privacy lookup hashes for stored email fields.
	 *
	 * @param array<string, array<string, string>> $fields Normalized fields.
	 * @return array<int, string>
	 */
	private static function get_email_hashes( $fields ) {
		$hashes = array();

		foreach ( $fields as $field ) {
			if ( 'email' === $field['type'] && is_email( $field['value'] ) ) {
				$hashes[] = self::get_email_hash( $field['value'] );
			}
		}

		return array_values( array_unique( $hashes ) );
	}

	/**
	 * Returns a keyed email lookup hash.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private static function get_email_hash( $email ) {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
	}

	/**
	 * Verifies an exact email match after a hash lookup.
	 *
	 * @param array<string, mixed> $row   Normalized stored row.
	 * @param string               $email Normalized email.
	 * @return bool
	 */
	private static function row_contains_email( $row, $email ) {
		foreach ( isset( $row['fields'] ) && is_array( $row['fields'] ) ? $row['fields'] : array() as $field ) {
			if (
				is_array( $field )
				&& isset( $field['type'], $field['value'] )
				&& 'email' === $field['type']
				&& strtolower( trim( $field['value'] ) ) === $email
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes a database row and decodes JSON columns.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function normalize_row( $row ) {
		$fields         = isset( $row['fields'] ) ? json_decode( $row['fields'], true ) : array();
		$email_hashes   = isset( $row['email_hashes'] ) ? json_decode( $row['email_hashes'], true ) : array();
		$action_results = isset( $row['action_results'] ) ? json_decode( $row['action_results'], true ) : array();

		$row['id']             = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
		$row['form_id']        = isset( $row['form_id'] ) ? absint( $row['form_id'] ) : 0;
		$row['reviewed_by']    = isset( $row['reviewed_by'] ) ? absint( $row['reviewed_by'] ) : 0;
		$row['fields']         = is_array( $fields ) ? $fields : array();
		$row['email_hashes']   = is_array( $email_hashes ) ? $email_hashes : array();
		$row['action_results'] = is_array( $action_results ) ? $action_results : array();

		return $row;
	}

	/**
	 * Normalizes a bounded set of submission IDs.
	 *
	 * @param mixed $ids Submission IDs.
	 * @return array<int, int>
	 */
	private static function normalize_ids( $ids ) {
		if ( ! is_array( $ids ) ) {
			$ids = array( $ids );
		}

		$ids = array_map( 'absint', array_slice( $ids, 0, self::MAX_PER_PAGE ) );
		$ids = array_filter( array_unique( $ids ) );

		return array_values( $ids );
	}

	/**
	 * Validates a YYYY-MM-DD date.
	 *
	 * @param mixed $date Date value.
	 * @return bool
	 */
	private static function is_date( $date ) {
		if ( ! is_string( $date ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) ) {
			return false;
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/**
	 * Validates a MySQL datetime string.
	 *
	 * @param mixed $datetime Datetime value.
	 * @return bool
	 */
	private static function is_mysql_datetime( $datetime ) {
		return is_string( $datetime ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $datetime );
	}
}
