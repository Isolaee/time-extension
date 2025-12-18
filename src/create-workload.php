<?php
/**
 * Add/Edit Workload admin page.
 *
 * Responsibilities:
 * - Render the Add/Edit Workload form
 * - Validate and persist submitted workload data to the `WP_SQL_workloads_workloads` option
 * - Schedule or reschedule the per-workload daily cron event
 *
 * Notes:
 * - Only SELECT queries are allowed for safety (validated here).
 * - This file expects `helpers.php` to be present (defines `WP_SQL_workloads_next_scheduled_time`).
 */

// Load shared helpers (defines WP_SQL_workloads_next_scheduled_time, runner, queue_email)
require_once dirname(__FILE__) . '/helpers.php';

if (!function_exists('WP_SQL_workloads_add_workload_page')) {
	function WP_SQL_workloads_add_workload_page() {
		/*
		 * Handle form submission FIRST, before any output.
		 * Sanitizes fields, validates SQL (must start with SELECT), and either updates
		 * an existing workload or creates a new one. Redirects on success to avoid POST resubmission.
		 */
		if (
			$_SERVER['REQUEST_METHOD'] === 'POST' &&
			isset(
				$_POST['workload_name'],
				$_POST['workload_time'],
				$_POST['workload_sql_query'],
				$_POST['workload_action'],
				$_POST['workload_cf7_form_id']
			)
		) {
			$name = sanitize_text_field($_POST['workload_name']);
			$time = sanitize_text_field($_POST['workload_time']);
			$sql_query = trim(wp_unslash($_POST['workload_sql_query']));
			$action = sanitize_text_field($_POST['workload_action']);
			$message = isset($_POST['workload_message']) ? wp_unslash($_POST['workload_message']) : '';
			$cf7_form_id = sanitize_text_field($_POST['workload_cf7_form_id']);
			$edit_id_post = isset($_POST['edit_id']) ? sanitize_text_field($_POST['edit_id']) : false;

			// Only allow SELECT queries for safety
			if (stripos($sql_query, 'select') !== 0) {
				$_GET['form_error'] = 'Only SELECT queries are allowed in SQL Query.';
			} else {
				$workloads = get_option('WP_SQL_workloads_workloads', []);
				if (!is_array($workloads)) $workloads = [];
				if ($edit_id_post && isset($workloads[$edit_id_post])) {
					// Edit existing workload: preserve id, update settings, and reschedule if needed
					$id = $edit_id_post;
					$old_time = $workloads[$id]['time'];
					$workloads[$id] = [
						'id' => $id,
						'name' => $name,
						'time' => $time,
						'sql_query' => $sql_query,
						'action' => $action,
						'message' => $message,
						'cf7_form_id' => $cf7_form_id,
					];
					update_option('WP_SQL_workloads_workloads', $workloads);
					// Reschedule cron if time changed
					if ($old_time !== $time) {
						wp_clear_scheduled_hook('WP_SQL_workloads_run_workload', [$id]);
						$timestamp = WP_SQL_workloads_next_scheduled_time($time);
						wp_schedule_event($timestamp, 'daily', 'WP_SQL_workloads_run_workload', [$id]);
					}
					wp_redirect(add_query_arg(['page'=>'WP_SQL_workloads_add_workload','edit'=>$id,'success'=>'updated'], menu_page_url('WP_SQL_workloads_add_workload', false)));
					exit;
				} else {
					// Add new workload: generate unique id, store and schedule
					$id = uniqid('workload_', true);
					$workloads[$id] = [
						'id' => $id,
						'name' => $name,
						'time' => $time,
						'sql_query' => $sql_query,
						'action' => $action,
						'message' => $message,
						'cf7_form_id' => $cf7_form_id,
					];
					update_option('WP_SQL_workloads_workloads', $workloads);
					$timestamp = WP_SQL_workloads_next_scheduled_time($time);
					if (!wp_next_scheduled('WP_SQL_workloads_run_workload', [$id])) {
						wp_schedule_event($timestamp, 'daily', 'WP_SQL_workloads_run_workload', [$id]);
					}
					wp_redirect(add_query_arg(['page'=>'WP_SQL_workloads_add_workload','success'=>'added'], menu_page_url('WP_SQL_workloads_add_workload', false)));
					exit;
				}
			}
		}

		$is_edit = false;
		$edit_id = isset($_GET['edit']) ? sanitize_text_field($_GET['edit']) : false;
		$workloads = get_option('WP_SQL_workloads_workloads', []);
		// Backward compatibility: support both 'criteria' and 'sql_query' keys
		// Add cf7_form_id to edit data
		$edit_data = [
			'name' => '',
			'time' => '',
			'sql_query' => '',
			'action' => 'send_email',
			'message' => '',
			'cf7_form_id' => '',
		];
		if ($edit_id && isset($workloads[$edit_id])) {
			$is_edit = true;
			$edit_data = array_merge($edit_data, $workloads[$edit_id]);
			// If old data uses 'criteria', map it to 'sql_query'
			if (empty($edit_data['sql_query']) && !empty($edit_data['criteria'])) {
				$edit_data['sql_query'] = $edit_data['criteria'];
			}
		}

		// Fetch all Contact Form 7 forms for dropdown (fix: must be before form HTML)
		$cf7_forms = [];
		if (class_exists('WPCF7_ContactForm')) {
			$cf7_forms = WPCF7_ContactForm::find();
		}

		WP_SQL_workloads_render_tabs($is_edit ? 'add_workload' : 'add_workload');

		?>
		<h2><?php echo $is_edit ? 'Edit Workload' : 'Add Workload'; ?></h2>

		<form method="post" action="">
			<?php if ($is_edit) echo '<input type="hidden" name="edit_id" value="' . esc_attr($edit_id) . '">'; ?>
			<table class="form-table">
				<tr>
					<th><label for="workload_name">Name:</label></th>
					<td><input type="text" id="workload_name" name="workload_name" required style="width: 300px;" value="<?php echo esc_attr($edit_data['name']); ?>"></td>
				</tr>
				<tr>
					<th><label for="workload_time">Time (24h):</label></th>
					<td><input type="time" id="workload_time" name="workload_time" required step="60" value="<?php echo esc_attr($edit_data['time']); ?>"></td>
				</tr>
				<tr>
					<th><label for="workload_sql_query">SQL Query:</label></th>
					<td>
						<textarea id="workload_sql_query" name="workload_sql_query" style="width: 400px; height: 100px;" required><?php echo esc_textarea($edit_data['sql_query']); ?></textarea>
					</td>
				</tr>
				<tr>
					<th><label for="workload_action">Action:</label></th>
					<td>
						<select id="workload_action" name="workload_action">
							<option value="send_email" <?php echo ($edit_data['action'] === 'send_email') ? 'selected' : ''; ?>>Send an email</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="workload_cf7_form_id">Contact Form 7 Form:</label></th>
					<td>
						<select id="workload_cf7_form_id" name="workload_cf7_form_id" required>
							<option value="">-- Select a form --</option>
							<?php
							if (!empty($cf7_forms)) {
								foreach ($cf7_forms as $form) {
									$selected = ($edit_data['cf7_form_id'] == $form->id()) ? 'selected' : '';
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr($form->id()),
										$selected,
										esc_html($form->title())
									);
								}
							} else {
								echo '<option value="">No Contact Form 7 forms found</option>';
							}
							?>
						</select>
					</td>
				</tr>

			</table>
			<p><button type="submit" class="button button-primary"><?php echo $is_edit ? 'Update Workload' : 'Add Workload'; ?></button></p>
		</form>

		<?php
		// Show form error if present
		if (isset($_GET['form_error'])) {
			echo '<div class="notice notice-error"><p>' . esc_html($_GET['form_error']) . '</p></div>';
		}
		// Show success message if redirected
		if (isset($_GET['success'])) {
			if ($_GET['success'] === 'added') {
				echo '<div class="notice notice-success"><p>Workload added and scheduled.</p></div>';
			} elseif ($_GET['success'] === 'updated') {
				echo '<div class="notice notice-success"><p>Workload updated.</p></div>';
			}
		}
	}
}