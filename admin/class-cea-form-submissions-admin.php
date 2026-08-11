<?php
/**
 * Stored form submissions administrator screens.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provides private list, detail, review, deletion, and retention controls.
 */
final class CEA_Form_Submissions_Admin {

	/** Administrator page slug. */
	const PAGE_SLUG = 'cea-form-submissions';

	/** User-specific notice transient prefix. */
	const NOTICE_TRANSIENT_PREFIX = 'cea_form_submissions_notice_';

	/** Rows shown on each administrator page. */
	const PER_PAGE = 25;

	/**
	 * Registers administrator hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 15 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_cea_form_submission_review', array( __CLASS__, 'handle_review' ) );
		add_action( 'admin_post_cea_form_submission_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_cea_form_submission_bulk', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_cea_form_submission_purge', array( __CLASS__, 'handle_purge' ) );
		add_filter( 'option_page_capability_' . CEA_Form_Submission_Settings::GROUP, array( __CLASS__, 'filter_settings_capability' ) );
	}

	/**
	 * Keeps Settings API authorization aligned with the submissions screen.
	 *
	 * @return string
	 */
	public static function filter_settings_capability() {
		return self::get_capability();
	}

	/**
	 * Registers the submissions page beneath CEA.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_submenu_page(
			'cea-plugin',
			__( 'Form Submissions', 'cea-plugin' ),
			__( 'Submissions', 'cea-plugin' ),
			self::get_capability(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueues submission administration styles.
	 *
	 * @param string $hook_suffix Current administrator hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		wp_enqueue_style( 'cea-form-submissions-admin', CEA_PLUGIN_URL . 'assets/admin/submissions.css', array(), CEA_PLUGIN_VERSION );
		unset( $hook_suffix );
	}

	/**
	 * Renders the list or detail screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		self::require_capability();

		$submission_id = isset( $_GET['submission'] ) ? absint( wp_unslash( $_GET['submission'] ) ) : 0;
		?>
		<div class="wrap cea-submissions-admin">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Form Submissions', 'cea-plugin' ); ?></h1>
			<hr class="wp-header-end">
			<?php self::render_notice(); ?>
			<?php settings_errors(); ?>

			<?php if ( 0 < $submission_id ) : ?>
				<?php self::render_detail( $submission_id ); ?>
			<?php else : ?>
				<?php self::render_list(); ?>
				<?php self::render_storage_settings(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the submissions list and filters.
	 *
	 * @return void
	 */
	private static function render_list() {
		$filters = self::get_filters();
		$query   = CEA_Form_Submission_Repository::query(
			array_merge(
				$filters,
				array(
					'page'     => isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1,
					'per_page' => self::PER_PAGE,
				)
			)
		);
		?>
		<form method="get" class="cea-submissions-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<label>
				<span class="screen-reader-text"><?php echo esc_html__( 'Filter by form', 'cea-plugin' ); ?></span>
				<select name="form_id">
					<option value="0"><?php echo esc_html__( 'All forms', 'cea-plugin' ); ?></option>
					<?php foreach ( CEA_Form_Submission_Repository::get_form_options() as $form ) : ?>
						<option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( $filters['form_id'], $form['id'] ); ?>>
							<?php echo esc_html( '' !== $form['title'] ? $form['title'] : sprintf( __( 'Form #%d', 'cea-plugin' ), $form['id'] ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span class="screen-reader-text"><?php echo esc_html__( 'Filter by delivery status', 'cea-plugin' ); ?></span>
				<select name="delivery_status">
					<option value=""><?php echo esc_html__( 'All delivery statuses', 'cea-plugin' ); ?></option>
					<?php foreach ( self::get_status_labels() as $status => $label ) : ?>
						<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span class="screen-reader-text"><?php echo esc_html__( 'Filter by review status', 'cea-plugin' ); ?></span>
				<select name="reviewed">
					<option value=""><?php echo esc_html__( 'All review statuses', 'cea-plugin' ); ?></option>
					<option value="unreviewed" <?php selected( $filters['reviewed'], 'unreviewed' ); ?>><?php echo esc_html__( 'Unreviewed', 'cea-plugin' ); ?></option>
					<option value="reviewed" <?php selected( $filters['reviewed'], 'reviewed' ); ?>><?php echo esc_html__( 'Reviewed', 'cea-plugin' ); ?></option>
				</select>
			</label>
			<label>
				<span><?php echo esc_html__( 'From', 'cea-plugin' ); ?></span>
				<input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
			</label>
			<label>
				<span><?php echo esc_html__( 'To', 'cea-plugin' ); ?></span>
				<input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
			</label>
			<?php submit_button( __( 'Filter', 'cea-plugin' ), 'secondary', 'filter_action', false ); ?>
			<a class="button" href="<?php echo esc_url( self::get_list_url() ); ?>"><?php echo esc_html__( 'Reset', 'cea-plugin' ); ?></a>
		</form>

		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: Number of stored responses. */
					_n( '%s stored response.', '%s stored responses.', $query['total'], 'cea-plugin' ),
					number_format_i18n( $query['total'] )
				)
			);
			?>
		</p>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="cea_form_submission_bulk">
			<?php wp_nonce_field( 'cea_form_submission_bulk' ); ?>
			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<label class="screen-reader-text" for="cea-submission-bulk-action"><?php echo esc_html__( 'Select bulk action', 'cea-plugin' ); ?></label>
					<select id="cea-submission-bulk-action" name="bulk_action">
						<option value=""><?php echo esc_html__( 'Bulk actions', 'cea-plugin' ); ?></option>
						<option value="review"><?php echo esc_html__( 'Mark reviewed', 'cea-plugin' ); ?></option>
						<option value="unreview"><?php echo esc_html__( 'Mark unreviewed', 'cea-plugin' ); ?></option>
						<option value="delete"><?php echo esc_html__( 'Delete permanently', 'cea-plugin' ); ?></option>
					</select>
					<?php submit_button( __( 'Apply', 'cea-plugin' ), 'secondary', 'submit', false ); ?>
				</div>
				<div class="tablenav-pages"><?php self::render_pagination( $query['pages'] ); ?></div>
			</div>

			<table class="wp-list-table widefat fixed striped table-view-list">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column"><input type="checkbox" aria-label="<?php echo esc_attr__( 'Select all submissions', 'cea-plugin' ); ?>"></td>
						<th scope="col"><?php echo esc_html__( 'Submitted', 'cea-plugin' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Form', 'cea-plugin' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Delivery', 'cea-plugin' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Action failures', 'cea-plugin' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Review', 'cea-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $query['items'] ) ) : ?>
						<tr><td colspan="6"><?php echo esc_html__( 'No stored submissions match these filters.', 'cea-plugin' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $query['items'] as $submission ) : ?>
							<?php
							$detail_url = add_query_arg( 'submission', absint( $submission['id'] ), self::get_list_url() );
							$failures   = count(
								array_filter(
									$submission['action_results'],
									static function ( $result ) {
										return is_array( $result ) && isset( $result['status'] ) && 'failed' === $result['status'];
									}
								)
							);
							?>
							<tr>
								<th scope="row" class="check-column"><input type="checkbox" name="submission_ids[]" value="<?php echo esc_attr( $submission['id'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select submission %d', 'cea-plugin' ), $submission['id'] ) ); ?>"></th>
								<td>
									<strong><a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( self::format_datetime( $submission['submitted_at_gmt'] ) ); ?></a></strong>
									<div class="row-actions"><span class="view"><a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html__( 'View', 'cea-plugin' ); ?></a></span></div>
								</td>
								<td><?php echo esc_html( $submission['form_title'] ); ?></td>
								<td><?php self::render_status( $submission['delivery_status'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $failures ) ); ?></td>
								<td>
									<?php if ( null === $submission['reviewed_at_gmt'] ) : ?>
										<?php echo esc_html__( 'Unreviewed', 'cea-plugin' ); ?>
									<?php else : ?>
										<?php $reviewer = 0 < $submission['reviewed_by'] ? get_userdata( $submission['reviewed_by'] ) : false; ?>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: Reviewing administrator display name. */
												__( 'Reviewed by %s', 'cea-plugin' ),
												$reviewer instanceof WP_User ? $reviewer->display_name : __( 'an administrator', 'cea-plugin' )
											)
										);
										?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="tablenav bottom">
				<div class="alignleft actions cea-submissions-delete-confirmation">
					<label>
						<input type="checkbox" name="confirm_delete" value="1">
						<?php echo esc_html__( 'Confirm permanent deletion when using the delete bulk action', 'cea-plugin' ); ?>
					</label>
				</div>
				<div class="tablenav-pages"><?php self::render_pagination( $query['pages'] ); ?></div>
			</div>
		</form>
		<?php
	}

	/**
	 * Renders one stored submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return void
	 */
	private static function render_detail( $submission_id ) {
		$submission = CEA_Form_Submission_Repository::get( $submission_id );

		if ( null === $submission ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The requested submission could not be found.', 'cea-plugin' ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( self::get_list_url() ) . '">' . esc_html__( 'Back to submissions', 'cea-plugin' ) . '</a></p>';
			return;
		}

		$form      = get_post( $submission['form_id'] );
		$reviewer  = 0 < $submission['reviewed_by'] ? get_userdata( $submission['reviewed_by'] ) : false;
		$reviewed  = null !== $submission['reviewed_at_gmt'];
		$list_url  = add_query_arg( 'form_id', absint( $submission['form_id'] ), self::get_list_url() );
		?>
		<p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php echo esc_html__( 'Back to submissions', 'cea-plugin' ); ?></a></p>
		<div class="cea-submission-summary">
			<div>
				<h2><?php echo esc_html( $submission['form_title'] ); ?></h2>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: Submission ID, 2: Submission time. */
							__( 'Submission #%1$d received %2$s', 'cea-plugin' ),
							$submission['id'],
							self::format_datetime( $submission['submitted_at_gmt'] )
						)
					);
					?>
				</p>
				<?php if ( $form instanceof WP_Post && CEA_Forms::POST_TYPE === $form->post_type && current_user_can( 'edit_post', $form->ID ) ) : ?>
					<p><a href="<?php echo esc_url( get_edit_post_link( $form->ID ) ); ?>"><?php echo esc_html__( 'Edit the original form', 'cea-plugin' ); ?></a></p>
				<?php else : ?>
					<p class="description"><?php echo esc_html__( 'The original form is no longer available.', 'cea-plugin' ); ?></p>
				<?php endif; ?>
			</div>
			<div>
				<?php self::render_status( $submission['delivery_status'] ); ?>
				<p>
					<?php
					if ( $reviewed ) {
						echo esc_html(
							sprintf(
								/* translators: 1: Reviewer name, 2: Review time. */
								__( 'Reviewed by %1$s on %2$s', 'cea-plugin' ),
								$reviewer instanceof WP_User ? $reviewer->display_name : __( 'an administrator', 'cea-plugin' ),
								self::format_datetime( $submission['reviewed_at_gmt'] )
							)
						);
					} else {
						echo esc_html__( 'This submission has not been reviewed.', 'cea-plugin' );
					}
					?>
				</p>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="cea_form_submission_review">
					<input type="hidden" name="submission_id" value="<?php echo esc_attr( $submission['id'] ); ?>">
					<input type="hidden" name="reviewed" value="<?php echo $reviewed ? '0' : '1'; ?>">
					<?php wp_nonce_field( 'cea_form_submission_review_' . $submission['id'] ); ?>
					<?php submit_button( $reviewed ? __( 'Mark unreviewed', 'cea-plugin' ) : __( 'Mark reviewed', 'cea-plugin' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>

		<h2><?php echo esc_html__( 'Submitted values', 'cea-plugin' ); ?></h2>
		<table class="widefat striped cea-submission-values">
			<thead><tr><th><?php echo esc_html__( 'Field', 'cea-plugin' ); ?></th><th><?php echo esc_html__( 'Value', 'cea-plugin' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $submission['fields'] as $field ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( isset( $field['label'] ) ? $field['label'] : __( 'Field', 'cea-plugin' ) ); ?></th>
						<td><?php echo nl2br( esc_html( self::format_field_value( $field ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php echo esc_html__( 'Delivery actions', 'cea-plugin' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php echo esc_html__( 'Action', 'cea-plugin' ); ?></th><th><?php echo esc_html__( 'Result', 'cea-plugin' ); ?></th><th><?php echo esc_html__( 'Diagnostic code', 'cea-plugin' ); ?></th><th><?php echo esc_html__( 'Details', 'cea-plugin' ); ?></th></tr></thead>
			<tbody>
				<?php if ( empty( $submission['action_results'] ) ) : ?>
					<tr><td colspan="4"><?php echo esc_html__( 'Action results have not been recorded yet.', 'cea-plugin' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $submission['action_results'] as $result ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( isset( $result['label'] ) ? $result['label'] : __( 'Action', 'cea-plugin' ) ); ?></th>
							<td><?php echo esc_html( isset( $result['status'] ) && 'failed' === $result['status'] ? __( 'Failed', 'cea-plugin' ) : __( 'Completed', 'cea-plugin' ) ); ?></td>
							<td><code><?php echo esc_html( isset( $result['code'] ) && '' !== $result['code'] ? $result['code'] : '—' ); ?></code></td>
							<td><?php echo esc_html( isset( $result['message'] ) && '' !== $result['message'] ? $result['message'] : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<div class="cea-submission-danger-zone">
			<h2><?php echo esc_html__( 'Delete submission', 'cea-plugin' ); ?></h2>
			<p><?php echo esc_html__( 'Deletion is permanent and removes all stored field values for this response.', 'cea-plugin' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="cea_form_submission_delete">
				<input type="hidden" name="submission_id" value="<?php echo esc_attr( $submission['id'] ); ?>">
				<?php wp_nonce_field( 'cea_form_submission_delete_' . $submission['id'] ); ?>
				<label><input type="checkbox" name="confirm_delete" value="1" required> <?php echo esc_html__( 'I understand this response cannot be recovered.', 'cea-plugin' ); ?></label>
				<?php submit_button( __( 'Delete permanently', 'cea-plugin' ), 'delete', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders retention settings and manual purge controls.
	 *
	 * @return void
	 */
	private static function render_storage_settings() {
		$settings = CEA_Form_Submission_Settings::get_settings();
		?>
		<hr>
		<h2><?php echo esc_html__( 'Submission retention', 'cea-plugin' ); ?></h2>
		<p><?php echo esc_html__( 'Expired responses are removed daily in bounded batches. Changing this setting does not immediately delete data.', 'cea-plugin' ); ?></p>
		<form action="options.php" method="post">
			<?php settings_fields( CEA_Form_Submission_Settings::GROUP ); ?>
			<label for="cea-form-submission-retention"><strong><?php echo esc_html__( 'Retain responses for', 'cea-plugin' ); ?></strong></label>
			<select id="cea-form-submission-retention" name="<?php echo esc_attr( CEA_Form_Submission_Settings::OPTION_NAME ); ?>[retention_days]">
				<option value="30" <?php selected( $settings['retention_days'], 30 ); ?>><?php echo esc_html__( '30 days', 'cea-plugin' ); ?></option>
				<option value="90" <?php selected( $settings['retention_days'], 90 ); ?>><?php echo esc_html__( '90 days (recommended)', 'cea-plugin' ); ?></option>
				<option value="180" <?php selected( $settings['retention_days'], 180 ); ?>><?php echo esc_html__( '180 days', 'cea-plugin' ); ?></option>
				<option value="365" <?php selected( $settings['retention_days'], 365 ); ?>><?php echo esc_html__( 'One year', 'cea-plugin' ); ?></option>
				<option value="0" <?php selected( $settings['retention_days'], 0 ); ?>><?php echo esc_html__( 'Until manually deleted', 'cea-plugin' ); ?></option>
			</select>
			<?php submit_button( __( 'Save retention setting', 'cea-plugin' ), 'secondary' ); ?>
		</form>

		<h3><?php echo esc_html__( 'Manual purge', 'cea-plugin' ); ?></h3>
		<p><?php echo esc_html__( 'Each purge removes at most 500 responses. Run it again if additional matching responses remain.', 'cea-plugin' ); ?></p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="cea_form_submission_purge">
			<?php wp_nonce_field( 'cea_form_submission_purge' ); ?>
			<select name="purge_scope">
				<option value="expired"><?php echo esc_html__( 'Responses outside the retention period', 'cea-plugin' ); ?></option>
				<option value="all"><?php echo esc_html__( 'All stored responses', 'cea-plugin' ); ?></option>
			</select>
			<label><input type="checkbox" name="confirm_delete" value="1" required> <?php echo esc_html__( 'I understand this permanently deletes stored responses.', 'cea-plugin' ); ?></label>
			<?php submit_button( __( 'Purge responses', 'cea-plugin' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Handles an individual review-state change.
	 *
	 * @return void
	 */
	public static function handle_review() {
		self::require_capability();

		$submission_id = isset( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		$reviewed      = ! empty( $_POST['reviewed'] );

		check_admin_referer( 'cea_form_submission_review_' . $submission_id );
		CEA_Form_Submission_Repository::set_reviewed( array( $submission_id ), $reviewed, get_current_user_id() );
		self::set_notice( 'success', $reviewed ? __( 'The submission was marked reviewed.', 'cea-plugin' ) : __( 'The submission was marked unreviewed.', 'cea-plugin' ) );
		self::redirect( array( 'submission' => $submission_id ) );
	}

	/**
	 * Handles permanent deletion of one submission.
	 *
	 * @return void
	 */
	public static function handle_delete() {
		self::require_capability();

		$submission_id = isset( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		check_admin_referer( 'cea_form_submission_delete_' . $submission_id );

		if ( empty( $_POST['confirm_delete'] ) ) {
			self::set_notice( 'error', __( 'Confirm permanent deletion before deleting the submission.', 'cea-plugin' ) );
			self::redirect( array( 'submission' => $submission_id ) );
		}

		$deleted = CEA_Form_Submission_Repository::delete( array( $submission_id ) );
		self::set_notice( 0 < $deleted ? 'success' : 'error', 0 < $deleted ? __( 'The submission was permanently deleted.', 'cea-plugin' ) : __( 'The submission could not be deleted.', 'cea-plugin' ) );
		self::redirect();
	}

	/**
	 * Handles bulk review and delete operations.
	 *
	 * @return void
	 */
	public static function handle_bulk() {
		self::require_capability();
		check_admin_referer( 'cea_form_submission_bulk' );

		$ids    = isset( $_POST['submission_ids'] ) && is_array( $_POST['submission_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['submission_ids'] ) ) : array();
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';

		if ( empty( $ids ) || ! in_array( $action, array( 'review', 'unreview', 'delete' ), true ) ) {
			self::set_notice( 'error', __( 'Select one or more submissions and a valid bulk action.', 'cea-plugin' ) );
			self::redirect();
		}

		if ( 'delete' === $action ) {
			if ( empty( $_POST['confirm_delete'] ) ) {
				self::set_notice( 'error', __( 'Confirm permanent deletion before applying the delete action.', 'cea-plugin' ) );
				self::redirect();
			}

			$count = CEA_Form_Submission_Repository::delete( $ids );
			$message = sprintf(
				/* translators: %d: Number of deleted submissions. */
				_n( '%d submission was permanently deleted.', '%d submissions were permanently deleted.', $count, 'cea-plugin' ),
				$count
			);
		} else {
			$count = CEA_Form_Submission_Repository::set_reviewed( $ids, 'review' === $action, get_current_user_id() );
			$message = sprintf(
				/* translators: %d: Number of updated submissions. */
				_n( '%d submission was updated.', '%d submissions were updated.', $count, 'cea-plugin' ),
				$count
			);
		}

		self::set_notice( 'success', $message );
		self::redirect();
	}

	/**
	 * Handles a bounded manual purge.
	 *
	 * @return void
	 */
	public static function handle_purge() {
		self::require_capability();
		check_admin_referer( 'cea_form_submission_purge' );

		if ( empty( $_POST['confirm_delete'] ) ) {
			self::set_notice( 'error', __( 'Confirm permanent deletion before purging submissions.', 'cea-plugin' ) );
			self::redirect();
		}

		$scope = isset( $_POST['purge_scope'] ) ? sanitize_key( wp_unslash( $_POST['purge_scope'] ) ) : '';

		if ( 'all' === $scope ) {
			$count = CEA_Form_Submission_Repository::delete_oldest( 500 );
		} else {
			$settings = CEA_Form_Submission_Settings::get_settings();
			$days     = absint( $settings['retention_days'] );
			$count    = 0 === $days
				? 0
				: CEA_Form_Submission_Repository::delete_older_than( gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ), 500 );
		}

		$message = sprintf(
			/* translators: %d: Number of purged submissions. */
			_n( '%d submission was purged.', '%d submissions were purged.', $count, 'cea-plugin' ),
			$count
		);
		self::set_notice( 'success', $message );
		self::redirect();
	}

	/**
	 * Renders a short-lived operation notice.
	 *
	 * @return void
	 */
	private static function render_notice() {
		$key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );
		$type = isset( $notice['type'] ) && 'success' === $notice['type'] ? 'success' : 'error';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}

	/**
	 * Stores a short-lived operation notice.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 * @return void
	 */
	private static function set_notice( $type, $message ) {
		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'type'    => 'success' === $type ? 'success' : 'error',
				'message' => sanitize_text_field( $message ),
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Renders list pagination while preserving current filters.
	 *
	 * @param int $total_pages Total pages.
	 * @return void
	 */
	private static function render_pagination( $total_pages ) {
		if ( 1 >= $total_pages ) {
			return;
		}

		$current = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$filters = self::get_filters();
		$base    = add_query_arg(
			array(
				'page'            => self::PAGE_SLUG,
				'form_id'         => $filters['form_id'],
				'delivery_status' => $filters['status'],
				'reviewed'        => $filters['reviewed'],
				'date_from'       => $filters['date_from'],
				'date_to'         => $filters['date_to'],
				'paged'           => 999999999,
			),
			admin_url( 'admin.php' )
		);
		$base    = str_replace( '999999999', '%#%', $base );
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => $base,
					'format'    => '',
					'current'   => $current,
					'total'     => $total_pages,
					'prev_text' => __( '&laquo;', 'cea-plugin' ),
					'next_text' => __( '&raquo;', 'cea-plugin' ),
				)
			)
		);
	}

	/**
	 * Returns sanitized list filters from the current request.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_filters() {
		return array(
			'form_id'   => isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0,
			'status'    => isset( $_GET['delivery_status'] ) ? sanitize_key( wp_unslash( $_GET['delivery_status'] ) ) : '',
			'reviewed'  => isset( $_GET['reviewed'] ) ? sanitize_key( wp_unslash( $_GET['reviewed'] ) ) : '',
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
		);
	}

	/**
	 * Returns aggregate status labels.
	 *
	 * @return array<string, string>
	 */
	private static function get_status_labels() {
		return array(
			'processing'      => __( 'Processing', 'cea-plugin' ),
			'completed'       => __( 'Completed', 'cea-plugin' ),
			'partial_failure' => __( 'Partial failure', 'cea-plugin' ),
			'failed'          => __( 'Failed', 'cea-plugin' ),
		);
	}

	/**
	 * Renders a status badge.
	 *
	 * @param string $status Delivery status.
	 * @return void
	 */
	private static function render_status( $status ) {
		$labels = self::get_status_labels();
		$status = isset( $labels[ $status ] ) ? $status : 'processing';

		echo '<span class="cea-submission-status cea-submission-status--' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span>';
	}

	/**
	 * Formats one GMT database timestamp in the site timezone.
	 *
	 * @param string $datetime_gmt GMT datetime.
	 * @return string
	 */
	private static function format_datetime( $datetime_gmt ) {
		if ( ! is_string( $datetime_gmt ) || '' === $datetime_gmt ) {
			return '';
		}

		return get_date_from_gmt( $datetime_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * Formats one field value for the private detail screen.
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

	/**
	 * Returns the submissions list URL.
	 *
	 * @return string
	 */
	private static function get_list_url() {
		return add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * Redirects to the private submissions screen.
	 *
	 * @param array<string, mixed> $args Additional query arguments.
	 * @return void
	 */
	private static function redirect( $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, self::get_list_url() ) );
		exit;
	}

	/**
	 * Returns the capability required to review stored personal data.
	 *
	 * @return string
	 */
	private static function get_capability() {
		/**
		 * Filters the capability required to manage stored form submissions.
		 *
		 * @param string $capability Required capability.
		 */
		$capability = apply_filters( 'cea_form_submission_capability', 'manage_options' );
		$capability = is_string( $capability ) ? sanitize_key( $capability ) : '';

		return '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Stops unauthorized response access.
	 *
	 * @return void
	 */
	private static function require_capability() {
		if ( ! current_user_can( self::get_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage form submissions.', 'cea-plugin' ) );
		}
	}
}
