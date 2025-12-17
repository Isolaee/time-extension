<?php
// Handle form submission FIRST, before any output
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
	// Helper: get next timestamp for given HH:MM (24h)
	if (!function_exists('WP_SQL_workloads_next_scheduled_time')) {
		function WP_SQL_workloads_next_scheduled_time($hhmm) {
			$now = current_time('timestamp');
			list($h, $m) = explode(':', $hhmm);
			$next = mktime((int)$h, (int)$m, 0, date('n', $now), date('j', $now), date('Y', $now));
			if ($next <= $now) {
				$next = strtotime('+1 day', $next);
			}
			return $next;
		}
	}
	$name = sanitize_text_field($_POST['workload_name']);
	$time = sanitize_text_field($_POST['workload_time']);
	$sql_query = trim(wp_unslash($_POST['workload_sql_query']));
	$action = sanitize_text_field($_POST['workload_action']);
	$message = isset($_POST['workload_message']) ? wp_unslash($_POST['workload_message']) : '';
	$cf7_form_id = sanitize_text_field($_POST['workload_cf7_form_id']);
	$edit_id_post = isset($_POST['edit_id']) ? sanitize_text_field($_POST['edit_id']) : false;

	// Only allow SELECT queries
	if (stripos($sql_query, 'select') !== 0) {
		// We'll show the error after output starts, so just set a flag
		$_GET['form_error'] = 'Only SELECT queries are allowed in SQL Query.';
	} else {
		$workloads = get_option('WP_SQL_workloads_workloads', []);
		if (!is_array($workloads)) $workloads = [];
		if ($edit_id_post && isset($workloads[$edit_id_post])) {
			// Edit existing workload
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
			// Add new workload
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
			// Schedule cron event (daily at specified time)
			$timestamp = WP_SQL_workloads_next_scheduled_time($time);
			if (!wp_next_scheduled('WP_SQL_workloads_run_workload', [$id])) {
				wp_schedule_event($timestamp, 'daily', 'WP_SQL_workloads_run_workload', [$id]);
			}
			wp_redirect(add_query_arg(['page'=>'WP_SQL_workloads_add_workload','success'=>'added'], menu_page_url('WP_SQL_workloads_add_workload', false)));
			exit;
		}
	}
}

// ...existing code for rendering tabs, form, etc. (no changes below this line except for error display)...

if (!function_exists('WP_SQL_workloads_render_tabs')) {
	// In case this file is loaded directly, define the function (should always exist)
	function WP_SQL_workloads_render_tabs($active_tab) {
		echo '<style>
		.timeext-tabs { border-bottom: 1px solid #ddd; margin-bottom: 20px; }
		.timeext-tab { display: inline-block; margin-right: 30px; padding: 8px 0; font-size: 16px; color: #444; text-decoration: none; border-bottom: 2px solid transparent; }
		.timeext-tab.active { color: #d35400; border-bottom: 2px solid #d35400; font-weight: 600; }
		</style>';
		echo '<nav class="timeext-tabs">';
		echo '<a href="?page=WP_SQL_workloads&tab=settings" class="timeext-tab' . ($active_tab === 'settings' ? ' active' : '') . '">Settings</a>';
		echo '<a href="?page=WP_SQL_workloads&tab=test" class="timeext-tab' . ($active_tab === 'test' ? ' active' : '') . '">Test</a>';
		echo '<a href="?page=WP_SQL_workloads_add_workload" class="timeext-tab' . ($active_tab === 'add_workload' ? ' active' : '') . '">Add Workload</a>';
		echo '<a href="?page=WP_SQL_workloads_all_workloads" class="timeext-tab' . ($active_tab === 'all_workloads' ? ' active' : '') . '">All Workloads</a>';
		echo '</nav>';
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
// Helper: get next timestamp for given HH:MM (24h)
if (!function_exists('WP_SQL_workloads_next_scheduled_time')) {
function WP_SQL_workloads_next_scheduled_time($hhmm) {
    $now = current_time('timestamp');
    list($h, $m) = explode(':', $hhmm);
    $next = mktime((int)$h, (int)$m, 0, date('n', $now), date('j', $now), date('Y', $now));
    if ($next <= $now) {
        $next = strtotime('+1 day', $next);
    }
    return $next;
}
}

// Register cron event handler

if (!function_exists('WP_SQL_workloads_run_workload_with_output')) {
function WP_SQL_workloads_run_workload_with_output($workload_id) {
	$output = [];
	$output[] = '<b>Stage 1: Fetch workloads</b>';
	$workloads = get_option('WP_SQL_workloads_workloads', []);
	$output[] = 'All workloads: ' . esc_html(json_encode(array_keys($workloads)));
	if (!isset($workloads[$workload_id])) {
		$output[] = 'Workload not found: ' . esc_html($workload_id);
		return ['error' => 'Workload not found.', 'debug' => $output];
	}
	$workload = $workloads[$workload_id];
	$output[] = '<b>Stage 2: Workload details</b>';
	foreach ($workload as $k => $v) {
		$output[] = esc_html($k) . ': ' . esc_html($v);
	}
	// Backward compatibility: support both 'criteria' and 'sql_query' keys
	$sql_query = isset($workload['sql_query']) ? $workload['sql_query'] : (isset($workload['criteria']) ? $workload['criteria'] : '');
	$sql_query = trim($sql_query);
	$output[] = '<b>Stage 3: Validate SQL Query</b>';
	$output[] = 'SQL Query: ' . esc_html($sql_query);
	if (stripos($sql_query, 'select') !== 0) {
		$output[] = 'SQL Query is not a SELECT query.';
		return ['error' => 'Only SELECT queries are allowed.', 'debug' => $output];
	}
	global $wpdb;
	$output[] = '<b>Stage 4: Execute query</b>';
	$results = $wpdb->get_results($sql_query);
	$output[] = 'Rows found: ' . count($results);
	$sent = 0;
	foreach ($results as $i => $row) {
		$row_info = [];
		foreach ($row as $col => $val) {
			$row_info[] = esc_html($col) . '=' . esc_html($val);
		}
		$output[] = 'Row #' . ($i+1) . ': ' . implode(', ', $row_info);

		// For now, support action 'send_email' by using wp_mail wrapper
		if (!empty($workload['action']) && $workload['action'] === 'send_email') {
			// Build message from workload message template or default
			$template = !empty($workload['message']) ? $workload['message'] : 'Notification for record {ID}.';
			$row_arr = (array) $row;
			// Replace placeholders like {ID} or {column_name}
			foreach ($row_arr as $col => $val) {
				$template = str_replace('{' . strtoupper($col) . '}', $val, $template);
				$template = str_replace('{' . strtolower($col) . '}', $val, $template);
			}

			// Determine recipient: prefer common email columns
			$recipient = '';
			$email_keys = ['user_email', 'email', 'email_address', 'contact_email', 'to'];
			foreach ($email_keys as $k) {
				if (isset($row_arr[$k]) && !empty($row_arr[$k])) {
					$recipient = $row_arr[$k];
					break;
				}
			}
			if (empty($recipient)) {
				$recipient = get_option('admin_email');
				$output[] = 'No recipient column found; falling back to admin_email: ' . esc_html($recipient);
			}

			// Subject: use workload name if available
			$subject = !empty($workload['name']) ? $workload['name'] : 'WP SQL Workload Notification';

			if (function_exists('WP_SQL_workloads_queue_email')) {
				$sent_ok = WP_SQL_workloads_queue_email($recipient, $subject, $template);
				if ($sent_ok) {
					$output[] = 'Email queued to ' . esc_html($recipient);
					$sent++;
				} else {
					$output[] = 'Failed to queue email to ' . esc_html($recipient);
				}
			} else {
				// Fallback to wp_mail directly
				$sent_ok = wp_mail($recipient, $subject, $template);
				if ($sent_ok) {
					$output[] = 'Email sent (wp_mail) to ' . esc_html($recipient);
					$sent++;
				} else {
					$output[] = 'wp_mail failed for ' . esc_html($recipient);
				}
			}
		} else {
			$output[] = 'Skipped row #' . ($i+1) . ' (unsupported action)';
		}
	}
	$output[] = '<b>Stage 6: Summary</b>';
	$output[] = 'Total emails sent: ' . $sent;
	return ['debug' => $output];
}
}

// Keep the original cron event for scheduled runs
add_action('WP_SQL_workloads_run_workload', function($workload_id) {
	WP_SQL_workloads_run_workload_with_output($workload_id);
}, 10, 1);