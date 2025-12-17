<?php
/*
Plugin Name: WP SQL Workloads
Description: Allows configuration of the database table to follow.
Version: 1.0
Author: Eero Isola
*/

// Add settings menu
add_action('admin_menu', 'WP_SQL_workloads_add_admin_menu');
add_action('admin_init', 'WP_SQL_workloads_settings_init');

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

// WP-Cron logic
register_activation_hook(__FILE__, 'WP_SQL_workloads_activate');
register_deactivation_hook(__FILE__, 'WP_SQL_workloads_deactivate');

function WP_SQL_workloads_activate() {
	WP_SQL_workloads_schedule_event();
}
// Schedule the event at the selected time every day
function WP_SQL_workloads_schedule_event() {
	$clock_time = get_option('WP_SQL_workloads_clock_time', '00:00');
	if (preg_match('/^(\d{2}):(\d{2})$/', $clock_time, $matches)) {
		$hour = (int)$matches[1];
		$minute = (int)$matches[2];
		$now = current_time('timestamp');
		$scheduled = mktime($hour, $minute, 0, date('n', $now), date('j', $now), date('Y', $now));
		if ($scheduled <= $now) {
			$scheduled = strtotime('+1 day', $scheduled);
		}
		// Remove any existing event
		$timestamp = wp_next_scheduled('WP_SQL_workloads_cron_event');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'WP_SQL_workloads_cron_event');
		}
		wp_schedule_event($scheduled, 'daily', 'WP_SQL_workloads_cron_event');
	}
}

// Reschedule if the time changes
add_action('update_option_WP_SQL_workloads_clock_time', 'WP_SQL_workloads_schedule_event', 10, 0);

function WP_SQL_workloads_deactivate() {
	$timestamp = wp_next_scheduled('WP_SQL_workloads_cron_event');
	if ($timestamp) {
		wp_unschedule_event($timestamp, 'WP_SQL_workloads_cron_event');
	}
}

add_action('WP_SQL_workloads_cron_event', 'WP_SQL_workloads_cron_callback');

function WP_SQL_workloads_cron_callback() {
	global $wpdb;
	$table = esc_sql(get_option('WP_SQL_workloads_table_name', 'wp_posts'));
	$email_template = get_option('WP_SQL_workloads_email_template', 'Your item with ID {ID} will expire on {EXPIRY_DATE}.');
	$admin_email = get_option('admin_email');

	// Try to find a column named 'expiration' or 'expiry_date' (date or datetime)
	$columns = $wpdb->get_results("SHOW COLUMNS FROM `$table`");
	$expiry_col = null;
	foreach ($columns as $col) {
		if (in_array(strtolower($col->Field), ['expiration', 'expiry_date', 'expires_at'])) {
			$expiry_col = $col->Field;
			break;
		}
	}
	if (!$expiry_col) {
		error_log('WP_SQL_workloads: No expiration column found in table ' . $table);
		return;
	}

	// Find rows expiring in exactly X days (trigger condition)
	$trigger_days = intval(get_option('WP_SQL_workloads_trigger_days', 7));
	$target_date = date('Y-m-d', strtotime('+' . $trigger_days . ' days'));
	$results = $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM `$table` WHERE DATE($expiry_col) = %s",
		$target_date
	));

	foreach ($results as $row) {
		$id = isset($row->ID) ? $row->ID : (isset($row->id) ? $row->id : '');
		$expiry = isset($row->$expiry_col) ? $row->$expiry_col : '';
		$message = str_replace(['{ID}', '{EXPIRY_DATE}'], [$id, $expiry], $email_template);
		// You may want to use a different recipient field here
		WP_SQL_workloads_queue_email($admin_email, 'Expiration Notice', $message);
	}
}

// Provide workload runner with debug output so 'Test Now' works from All Workloads page
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
		// Ensure query is safe for preview
		$preview_query = preg_replace('/;\s*$/', '', $sql_query);
		if (!preg_match('/\blimit\b\s+\d+/i', $preview_query)) {
			$preview_query .= ' LIMIT 1000';
		}
		$results = $wpdb->get_results($preview_query);
		$output[] = 'Rows found: ' . (is_array($results) ? count($results) : 0);
		if (!empty($wpdb->last_error)) {
			$output[] = '<b>SQL Error:</b> ' . $wpdb->last_error;
			return ['error' => 'SQL execution error', 'debug' => $output];
		}
		$sent = 0;
		foreach ($results as $i => $row) {
			$row_info = [];
			foreach ($row as $col => $val) {
				$row_info[] = esc_html($col) . '=' . esc_html($val);
			}
			$output[] = 'Row #' . ($i+1) . ': ' . implode(', ', $row_info);

			// For now, support action 'send_email' by using wp_mail wrapper
			if (!empty($workload['action']) && $workload['action'] === 'send_email') {
				$template = !empty($workload['message']) ? $workload['message'] : 'Notification for record {ID}.';
				$row_arr = (array) $row;
				foreach ($row_arr as $col => $val) {
					$template = str_replace('{' . strtoupper($col) . '}', $val, $template);
					$template = str_replace('{' . strtolower($col) . '}', $val, $template);
				}
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
				$subject = !empty($workload['name']) ? $workload['name'] : 'WP SQL Workload Notification';
				// If a Contact Form 7 form is configured, prefer using its mail template
				$cf7_id = !empty($workload['cf7_form_id']) ? $workload['cf7_form_id'] : '';
				$sent_ok = false;
				if ($cf7_id && class_exists('WPCF7_ContactForm')) {
					$form = WPCF7_ContactForm::get_instance($cf7_id);
					if ($form) {
						$mail_props = $form->prop('mail');
						// CF7 'recipient' may contain one or more emails or placeholders like [user_email]
						$cf7_recipient = isset($mail_props['recipient']) ? $mail_props['recipient'] : '';
						$to_address = $cf7_recipient ? $cf7_recipient : $recipient;
						$cf7_subject = isset($mail_props['subject']) ? $mail_props['subject'] : $subject;
						$cf7_body = isset($mail_props['body']) ? $mail_props['body'] : $template;

						// Replace placeholders from SQL row into CF7 template and recipient. Try multiple tag styles.
						$body_filled = $cf7_body;
						$subject_filled = $cf7_subject;
						$to_filled = $to_address;
						// Build search/replace arrays
						$search = [];
						$replace = [];
						foreach ($row_arr as $col => $val) {
							$search[] = '{' . strtoupper($col) . '}'; $replace[] = $val;
							$search[] = '{' . strtolower($col) . '}'; $replace[] = $val;
							$search[] = '[' . $col . ']'; $replace[] = $val;
							$search[] = '[' . $col . '*]'; $replace[] = $val;
							$search[] = '[' . strtolower($col) . ']'; $replace[] = $val;
							$search[] = '[' . strtoupper($col) . ']'; $replace[] = $val;
						}
						// Common placeholders
						if (isset($row_arr['ID'])) { $search[] = '{ID}'; $replace[] = $row_arr['ID']; $search[] = '{id}'; $replace[] = $row_arr['ID']; }

						if (!empty($search)) {
							$body_filled = str_replace($search, $replace, $body_filled);
							$subject_filled = str_replace($search, $replace, $subject_filled);
							$to_filled = str_replace($search, $replace, $to_filled);
						}

						// Validate recipient(s) — allow comma-separated list, pick valid emails
						$valid_recipients = [];
						foreach (preg_split('/[,;\s]+/', $to_filled) as $candidate) {
							$candidate = trim($candidate);
							if (is_email($candidate)) {
								$valid_recipients[] = $candidate;
							}
						}
						if (!empty($valid_recipients)) {
							$to_address = implode(',', $valid_recipients);
						} else {
							// fallback to detected recipient from row (email column) or admin
							if (!empty($recipient) && is_email($recipient)) {
								$to_address = $recipient;
								$output[] = 'CF7 recipient placeholders did not resolve; using recipient column ' . esc_html($recipient);
							} else {
								$to_address = get_option('admin_email');
								$output[] = 'CF7 recipient placeholders did not resolve; falling back to admin_email: ' . esc_html($to_address);
							}
						}

						// Also replace simple {ID} and {EXPIRY_DATE} placeholders
						if (isset($row->ID)) {
							$body_filled = str_replace(['{ID}', '{id}'], $row->ID, $body_filled);
							$subject_filled = str_replace(['{ID}', '{id}'], $row->ID, $subject_filled);
						}

						// Headers: try to use CF7 sender if present
						$headers = [];
						if (!empty($mail_props['sender'])) {
							$headers[] = 'From: ' . $mail_props['sender'];
						}

						// Send using wp_mail with CF7-derived templates
						$sent_ok = wp_mail($to_address, $subject_filled, $body_filled, $headers);
						if ($sent_ok) {
							$output[] = 'Email sent using CF7 template to ' . esc_html($to_address);
							$sent++;
						} else {
							$output[] = 'Failed to send email using CF7 template to ' . esc_html($to_address);
						}
					} else {
						$output[] = 'CF7 form not found for ID: ' . esc_html($cf7_id) . '; falling back to wp_mail.';
					}
				}
				if (!$sent_ok) {
					if (function_exists('WP_SQL_workloads_queue_email')) {
						$sent_ok = WP_SQL_workloads_queue_email($recipient, $subject, $template);
						if ($sent_ok) {
							$output[] = 'Email queued to ' . esc_html($recipient);
							$sent++;
						} else {
							$output[] = 'Failed to queue email to ' . esc_html($recipient);
						}
					} else {
						$sent_ok = wp_mail($recipient, $subject, $template);
						if ($sent_ok) {
							$output[] = 'Email sent (wp_mail) to ' . esc_html($recipient);
							$sent++;
						} else {
							$output[] = 'wp_mail failed for ' . esc_html($recipient);
						}
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

// Ensure scheduled/workload action exists
add_action('WP_SQL_workloads_run_workload', function($workload_id) {
	// Call the runner for scheduled runs (no UI output)
	WP_SQL_workloads_run_workload_with_output($workload_id);
}, 10, 1);

/**
 * Queue an email using wp_mail (uses WP Mail SMTP if configured)
 * @param string $to
 * @param string $subject
 * @param string $message
 * @param string|array $headers
 * @param array $attachments
 * @return bool
 */
function WP_SQL_workloads_queue_email($to, $subject, $message, $headers = '', $attachments = array()) {
	// This will use WP Mail SMTP if it is active and configured
	return wp_mail($to, $subject, $message, $headers, $attachments);
}

function WP_SQL_workloads_add_admin_menu() {
       add_menu_page(
	       'WP SQL Workloads', // Page title
	       'WP SQL Workloads', // Menu title
	       'manage_options', // Capability
	       'WP_SQL_workloads', // Menu slug
	       'WP_SQL_workloads_options_page', // Function
	       'dashicons-clock', // Icon
	       25 // Position
       );

       add_submenu_page(
	       'WP_SQL_workloads', // Parent slug
	       'All Workloads',  // Page title
	       'All Workloads',  // Menu title
	       'manage_options', // Capability
	       'WP_SQL_workloads_all_workloads', // Menu slug
	       'WP_SQL_workloads_all_workloads_page' // Function
       );

       add_submenu_page(
	       'WP_SQL_workloads', // Parent slug
	       'Add Workload',   // Page title
	       'Add Workload',   // Menu title
	       'manage_options', // Capability
	       'WP_SQL_workloads_add_workload', // Menu slug
	       'WP_SQL_workloads_add_workload_page' // Function
       );
}

function WP_SQL_workloads_all_workloads_page() {
       $file = dirname(__FILE__) . '/all-workloads.php';
       if (file_exists($file)) {
	       include $file;
       } else {
	       echo '<div class="notice notice-error"><p>All Workloads page not found.</p></div>';
       }
}

function WP_SQL_workloads_add_workload_page() {
       $file = dirname(__FILE__) . '/create-workload.php';
       if (file_exists($file)) {
	       include $file;
       } else {
	       echo '<div class="notice notice-error"><p>Workload creation page not found.</p></div>';
       }
}

function WP_SQL_workloads_settings_init() {
		add_settings_field(
			'WP_SQL_workloads_trigger_days',
			__('Trigger Condition (days before expiration)', 'WP_SQL_workloads'),
			'WP_SQL_workloads_trigger_days_render',
			'WP_SQL_workloads',
			'WP_SQL_workloads_section'
		);
		register_setting('WP_SQL_workloads', 'WP_SQL_workloads_trigger_days');
	function WP_SQL_workloads_trigger_days_render() {
		$value = get_option('WP_SQL_workloads_trigger_days', '7');
		echo "<input type='number' name='WP_SQL_workloads_trigger_days' value='" . esc_attr($value) . "' min='1' max='365' style='width:60px;' /> ";
		echo '<small>Send email this many days before expiration.</small>';
	}
	register_setting('WP_SQL_workloads', 'WP_SQL_workloads_table_name');
	register_setting('WP_SQL_workloads', 'WP_SQL_workloads_clock_time');

	add_settings_section(
		'WP_SQL_workloads_section',
		__('Configure Table', 'WP_SQL_workloads'),
		null,
		'WP_SQL_workloads'
	);

	add_settings_field(
		'WP_SQL_workloads_table_name',
		__('Table Name', 'WP_SQL_workloads'),
		'WP_SQL_workloads_table_name_render',
		'WP_SQL_workloads',
		'WP_SQL_workloads_section'
	);

	add_settings_field(
		'WP_SQL_workloads_clock_time',
		__('Execution Time (24h)', 'WP_SQL_workloads'),
		'WP_SQL_workloads_clock_time_render',
		'WP_SQL_workloads',
		'WP_SQL_workloads_section'
	);
	add_settings_field(
		'WP_SQL_workloads_email_template',
		__('Expiration Email Template', 'WP_SQL_workloads'),
		'WP_SQL_workloads_email_template_render',
		'WP_SQL_workloads',
		'WP_SQL_workloads_section'
	);
	register_setting('WP_SQL_workloads', 'WP_SQL_workloads_email_template');
	function WP_SQL_workloads_email_template_render() {
		$value = get_option('WP_SQL_workloads_email_template', 'Your item with ID {ID} will expire on {EXPIRY_DATE}.');
		echo "<textarea name='WP_SQL_workloads_email_template' rows='4' cols='50'>" . esc_textarea($value) . "</textarea>";
		echo '<br><small>Use {ID} and {EXPIRY_DATE} as placeholders.</small>';
	}
}

function WP_SQL_workloads_clock_time_render() {
	$value = get_option('WP_SQL_workloads_clock_time', '00:00');
	echo "<input type='time' name='WP_SQL_workloads_clock_time' value='" . esc_attr($value) . "' step='60' />";
}

function WP_SQL_workloads_table_name_render() {
	$value = get_option('WP_SQL_workloads_table_name', 'wp_posts');
	echo "<input type='text' name='WP_SQL_workloads_table_name' value='" . esc_attr($value) . "' />";
}

function WP_SQL_workloads_options_page() {
       global $wpdb;
       // Use posted value if available, otherwise use option
	   if (isset($_POST['WP_SQL_workloads_table_name'])) {
		   $table_name = esc_sql(sanitize_text_field($_POST['WP_SQL_workloads_table_name']));
	   } else {
		   $table_name = esc_sql(get_option('WP_SQL_workloads_table_name', 'wp_posts'));
	   }
       $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
       $show_head = isset($_POST['show_head']);
       $show_test = isset($_POST['show_test']);
       $send_test = isset($_POST['send_test']);
       $test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';

	   $test_result = '';
	   if ($send_test && $test_email) {
		   $sent = WP_SQL_workloads_queue_email($test_email, 'Test Email from WP_SQL_workloads', 'This is a test email from the WP_SQL_workloads plugin.');
		   $test_result = $sent ? '<div style="color:green;">Test email sent to ' . esc_html($test_email) . '.</div>' : '<div style="color:red;">Failed to send test email.</div>';
       }

       // SQL test logic
       $show_sql = isset($_POST['show_sql']);
       $run_sql = isset($_POST['run_sql']);
       $sql_query = isset($_POST['sql_query']) ? trim(stripslashes($_POST['sql_query'])) : '';
       $sql_result = '';
       if ($run_sql && $sql_query) {
	       global $wpdb;
	       // Only allow SELECT queries for safety
	       if (preg_match('/^\s*SELECT/i', $sql_query)) {
		       $results = $wpdb->get_results($sql_query, ARRAY_A);
		       if ($results) {
			       $sql_result .= '</tbody></table>';
		       } else {
			       $sql_result .= '<div style="color:red;">No results found.</div>';
		       }
	       } else {
		       $sql_result .= '<div style="color:red;">Only SELECT queries are allowed for testing.</div>';
	       }
       }

       // Unified tab menu
       WP_SQL_workloads_render_tabs($active_tab);

       if ($active_tab === 'settings') {
	       ?>
	       <form action="options.php" method="post">
		       <h2>WP SQL Workloads Settings</h2>
		       <?php
		       settings_fields('WP_SQL_workloads');
		       do_settings_sections('WP_SQL_workloads');
		       submit_button();
		       ?>
	       </form>
	       <form action="" method="post" style="margin-top:1em;">
		       <button type="submit" name="show_head" class="button button-secondary">Show Top 5 Rows</button>
	       </form>
	       <?php
	       if ($show_head) {
		       // Fetch top 5 rows from the selected table
			       // Debug: show which DB and host are in use
			       echo '<div style="margin:0.5em 0;padding:0.5em;background:#f7f7f7;border:1px solid #eee;">';
			       echo '<strong>DB_NAME:</strong> ' . esc_html(defined('DB_NAME') ? DB_NAME : 'constant DB_NAME not set') . '<br/>';
			       echo '<strong>DB_HOST:</strong> ' . esc_html(defined('DB_HOST') ? DB_HOST : 'constant DB_HOST not set') . '<br/>';
			       // Check table existence
			       $table_check = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
			       if ($table_check) {
				       echo '<strong>Table exists:</strong> Yes (' . esc_html($table_name) . ')<br/>';
			       } else {
				       echo '<strong>Table exists:</strong> No (' . esc_html($table_name) . ')<br/>';
			       }
			       echo '</div>';

			       $results = $wpdb->get_results("SELECT * FROM `" . esc_sql($table_name) . "` LIMIT 5", ARRAY_A);
			       if ($results) {
				       echo '<h3>Top 5 Rows from ' . esc_html($table_name) . '</h3>';
				       echo '<pre style="white-space:pre-wrap;">' . esc_html(print_r($results, true)) . '</pre>';
			       } else {
				       echo '<div style="color:red;">No results found.</div>';
			       }
	       }
       } elseif ($active_tab === 'test') {
	       echo '<h2>Test</h2>';
	       echo $test_result;
	       // Test Email UI
	       ?>
	       <form action="?page=WP_SQL_workloads&tab=test" method="post" style="margin-bottom:1em;">
		       <input type="email" name="test_email" placeholder="Enter email address" required />
		       <button type="submit" name="send_test" class="button button-primary">Send Test Email</button>
	       </form>
	       <?php if ($show_test) { ?>
	       <?php }

	       // Test SQL UI
	       ?>
	       <form action="?page=WP_SQL_workloads&tab=test" method="post" style="margin-top:2em;">
		       <input type="text" name="sql_query" placeholder="Enter SELECT query" style="width:300px;" />
		       <button type="submit" name="run_sql" class="button button-primary">Run SQL</button>
	       </form>
	       <?php if ($show_sql) { ?>
		       <?php echo $sql_result; ?>
	       <?php }
       }
}
