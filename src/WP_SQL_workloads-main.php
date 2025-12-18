<?php
/*
Plugin Name: WP SQL Workloads
Description: Database workload tester and notifier (trimmed: settings removed).
Version: 1.0
Author: Eero Isola
*/

// render tab navigation
if (!function_exists('WP_SQL_workloads_render_tabs')) {
	// In case this file is loaded directly, define the function (should always exist)
	/**
	 * Render the admin page tab navigation.
	 *
	 * @param string $active_tab Currently active tab key ('test', 'add_workload', 'all_workloads').
	 */
	function WP_SQL_workloads_render_tabs($active_tab) {
		echo '<style>
		.timeext-tabs { border-bottom: 1px solid #ddd; margin-bottom: 20px; }
		.timeext-tab { display: inline-block; margin-right: 30px; padding: 8px 0; font-size: 16px; color: #444; text-decoration: none; border-bottom: 2px solid transparent; }
		.timeext-tab.active { color: #d35400; border-bottom: 2px solid #d35400; font-weight: 600; }
		</style>';
		echo '<nav class="timeext-tabs">';
		echo '<a href="?page=WP_SQL_workloads&tab=info" class="timeext-tab' . ($active_tab === 'info' ? ' active' : '') . '">Test</a>';
		echo '<a href="?page=WP_SQL_workloads_add_workload" class="timeext-tab' . ($active_tab === 'add_workload' ? ' active' : '') . '">Add Workload</a>';
		echo '<a href="?page=WP_SQL_workloads_all_workloads" class="timeext-tab' . ($active_tab === 'all_workloads' ? ' active' : '') . '">All Workloads</a>';
		echo '</nav>';
	}
}


// Load shared helpers (next-scheduled helper, runner, queue_email)
require_once dirname(__FILE__) . '/helpers.php';
// Ensure the runner is registered on the WP hook so both cron and admin can invoke it.
add_action('WP_SQL_workloads_run_workload', 'WP_SQL_workloads_run_workload_with_output', 10, 1);

// Ensure per-workload scheduled wp-cron events exist (run occasionally)
    if (!function_exists('WP_SQL_workloads_ensure_schedules')) {
    	/**
    	 * Ensure per-workload wp-cron events exist (idempotent).
    	 * Runs at most once per hour via transient to reduce overhead.
    	 */
    	function WP_SQL_workloads_ensure_schedules() {
		// run at most once per hour to avoid overhead
		if (get_transient('WP_SQL_workloads_schedules_checked')) {
			return;
		}
		set_transient('WP_SQL_workloads_schedules_checked', 1, HOUR_IN_SECONDS);

		$workloads = get_option('WP_SQL_workloads_workloads', []);
		if (!is_array($workloads) || empty($workloads)) return;

		foreach ($workloads as $id => $w) {
			// don't schedule paused workloads
			if (!empty($w['paused'])) {
				// ensure any existing schedule is cleared
				if (wp_next_scheduled('WP_SQL_workloads_run_workload', [$id])) {
					wp_clear_scheduled_hook('WP_SQL_workloads_run_workload', [$id]);
				}
				continue;
			}
			if (!wp_next_scheduled('WP_SQL_workloads_run_workload', [$id])) {
				$time = isset($w['time']) ? $w['time'] : get_option('WP_SQL_workloads_clock_time', '00:00');
				$timestamp = WP_SQL_workloads_next_scheduled_time($time);
				wp_schedule_event($timestamp, 'daily', 'WP_SQL_workloads_run_workload', [$id]);
			}
		}
	}
}

// Dashboard widget: next 3 scheduled workloads and last run
add_action('wp_dashboard_setup', 'WP_SQL_workloads_add_dashboard_widget');
/**
 * Register dashboard widget showing next scheduled workloads and last run.
 */
function WP_SQL_workloads_add_dashboard_widget() {
	if (!current_user_can('manage_options')) return;
	wp_add_dashboard_widget(
		'wp_sql_workloads_widget',
		'WP SQL Workloads',
		'WP_SQL_workloads_dashboard_widget_display'
	);
}

/**
 * Dashboard widget rendering logic. Shows upcoming schedules and most recent run.
 */
function WP_SQL_workloads_dashboard_widget_display() {
	$workloads = get_option('WP_SQL_workloads_workloads', array());
	$upcoming = array();
	if (is_array($workloads)) {
		foreach ($workloads as $id => $w) {
			if (!empty($w['paused'])) continue;
			$next = wp_next_scheduled('WP_SQL_workloads_run_workload', array($id));
			if ($next) {
				$upcoming[] = array(
					'id' => $id,
					'name' => !empty($w['name']) ? $w['name'] : '(unnamed)',
					'next' => $next,
				);
			}
		}
	}
	usort($upcoming, function($a, $b) { return $a['next'] <=> $b['next']; });
	$next_three = array_slice($upcoming, 0, 3);

	echo '<div style="padding:6px 0;">';
	if (!empty($next_three)) {
		echo '<strong>Next 3 scheduled workloads</strong>';
		echo '<table style="width:100%;border-collapse:collapse;margin-top:6px;">';
		echo '<thead><tr><th style="text-align:left;padding:4px">Workload</th><th style="text-align:left;padding:4px">Next Run (local)</th></tr></thead>';
		echo '<tbody>';
		foreach ($next_three as $n) {
			$time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $n['next']);
			$link = admin_url('admin.php?page=WP_SQL_workloads_all_workloads');
			echo '<tr><td style="padding:4px">' . esc_html($n['name']) . ' <small style="color:#666">(#' . esc_html($n['id']) . ')</small></td><td style="padding:4px">' . esc_html($time) . '</td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<div>No scheduled workloads found.</div>';
	}

	// Last run info
	$last = get_option('WP_SQL_workloads_last_run');
	echo '<div style="margin-top:10px;border-top:1px solid #eee;padding-top:8px;">';
	echo '<strong>Most recent run</strong>';
	if ($last && is_array($last)) {
		$ts = isset($last['timestamp']) ? $last['timestamp'] : 0;
		$time = $ts ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts) : 'n/a';
		$wid = isset($last['workload_id']) ? $last['workload_id'] : '(unknown)';
		$wname = '(unknown)';
		$all = get_option('WP_SQL_workloads_workloads', array());
		if (isset($all[$wid]) && !empty($all[$wid]['name'])) $wname = $all[$wid]['name'];
		echo '<div style="margin-top:6px;">Workload: ' . esc_html($wname) . ' <small style="color:#666">(#' . esc_html($wid) . ')</small></div>';
		echo '<div>When: ' . esc_html($time) . '</div>';
		echo '<div>Rows: ' . esc_html(isset($last['rows']) ? $last['rows'] : '0') . ' &nbsp; Sent: ' . esc_html(isset($last['sent']) ? $last['sent'] : '0') . '</div>';
		if (!empty($last['debug']) && is_array($last['debug'])) {
			echo '<details style="margin-top:6px;"><summary style="cursor:pointer">Debug (click to expand)</summary><pre style="white-space:pre-wrap;max-height:200px;overflow:auto;padding:6px;background:#fafafa;border:1px solid #eee;">' . esc_html(implode("\n", $last['debug'])) . '</pre></details>';
		}
	} else {
		echo '<div style="color:#666;margin-top:6px;">No runs recorded yet.</div>';
	}
	echo '</div></div>';
}

// -- Admin menu --
/**
 * Add the top-level admin menu and associated subpages.
 * Requires capability 'manage_options' (admin only by default).
 */
function WP_SQL_workloads_add_admin_menu() {
       add_menu_page(
	       'WP SQL Workloads', // Page title
	       'WP SQL Workloads', // Menu title
	       'manage_options', // Capability
	       'WP_SQL_workloads', // Menu slug
	       'WP_SQL_workloads_info_page', // Function
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

// Register the admin menu so the plugin appears under WordPress admin
add_action('admin_menu', 'WP_SQL_workloads_add_admin_menu');

// -- END --

/**
 * Callback to render the "All Workloads" admin page. Attempts to include
 * `all-workloads.php` from the plugin directory and shows an error notice
 * if the file is missing.
 */
function WP_SQL_workloads_all_workloads_page() {
	$file = dirname(__FILE__) . '/all-workloads.php';
       if (file_exists($file)) {
	       include $file;
       } else {
	       echo '<div class="notice notice-error"><p>All Workloads page not found.</p></div>';
       }
}

/**
 * Callback to render the "Add Workload" admin page. Includes the
 * `create-workload.php` UI file if present.
 */
function WP_SQL_workloads_add_workload_page() {
	$file = dirname(__FILE__) . '/create-workload.php';
       if (file_exists($file)) {
	       include $file;
       } else {
	       echo '<div class="notice notice-error"><p>Workload creation page not found.</p></div>';
       }
}


/**
 * Top-level plugin info.
 *
 * Renders the tab navigation via `WP_SQL_workloads_render_tabs()` and outputs
 * messages/results inline.
 */
function WP_SQL_workloads_info_page() {

    echo '<div class="wrap" style="margin-top:12px;">';
    echo '<h1>WP SQL Workloads — Info</h1>';

    echo '<section style="margin-top:18px;padding:12px;background:#fff;border:1px solid #eee;">';
    echo '<h2 style="margin:0 0 8px 0;">wp-cron</h2>';
	echo '<p style="margin:0;">WP-Cron is pseudo-cron that only runs when site visitors access the site. This means scheduled tasks may not run on time if there is low traffic.</p>';
    echo '<p style="margin:0;">To activate true cron, use outside trigger, like cPanel CronJob (curl -s https://growthrocket.online/wp-cron.php?doing_wp_cron >/dev/null 2>&1)';
    echo '</section>';

    echo '<section style="margin-top:12px;padding:12px;background:#fff;border:1px solid #eee;">';
    echo '<h2 style="margin:0 0 8px 0;">Emails</h2>';
    echo '<p style="margin:0;color:#666;">Emails are using default email settings. aka wp_mail()</p>';
	echo '<p style="margin:0;color:#666;">If you want to use a different email service, consider installing an SMTP plugin or configuring your server accordingly. but it *MUST* replace wp_mail() to work correctly.</p>';
    echo '</section>';

	echo '<section style="margin-top:12px;padding:12px;background:#fff;border:1px solid #eee;">';
    echo '<h2 style="margin:0 0 8px 0;">Emails content</h2>';
    echo '<p style="margin:0;color:#666;">This plugin is designed to use Contact Form 7 (CF7) template emails. Install CF7 plugin to use this feature.</p>';
    echo '</section>';

    echo '</div>';
}