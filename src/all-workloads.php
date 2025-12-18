<?php

// Load shared helpers (defines runner, scheduler helper, queue_email, etc.)
require_once dirname(__FILE__) . '/helpers.php';
WP_SQL_workloads_render_tabs('all_workloads');

// Handle actions: delete, pause, resume, testnow
if (isset($_GET['workload_action'], $_GET['workload_id'])) {
    $workloads = get_option('WP_SQL_workloads_workloads', []);
    $id = sanitize_text_field($_GET['workload_id']);
    $action = sanitize_text_field($_GET['workload_action']);
    if (isset($workloads[$id])) {
        if ($action === 'delete') {
            wp_clear_scheduled_hook('WP_SQL_workloads_run_workload', [$id]);
            unset($workloads[$id]);
            update_option('WP_SQL_workloads_workloads', $workloads);
            echo '<div class="notice notice-success"><p>Workload deleted.</p></div>';
        } elseif ($action === 'pause') {
            $workloads[$id]['paused'] = true;
            wp_clear_scheduled_hook('WP_SQL_workloads_run_workload', [$id]);
            update_option('WP_SQL_workloads_workloads', $workloads);
            echo '<div class="notice notice-success"><p>Workload paused.</p></div>';
        } elseif ($action === 'resume') {
            if (!empty($workloads[$id]['paused'])) {
                unset($workloads[$id]['paused']);
                if (!wp_next_scheduled('WP_SQL_workloads_run_workload', [$id])) {
                    $timestamp = function_exists('WP_SQL_workloads_next_scheduled_time') ? WP_SQL_workloads_next_scheduled_time($workloads[$id]['time']) : time();
                    wp_schedule_event($timestamp, 'daily', 'WP_SQL_workloads_run_workload', [$id]);
                }
                update_option('WP_SQL_workloads_workloads', $workloads);
                echo '<div class="notice notice-success"><p>Workload resumed.</p></div>';
            }
        } elseif ($action === 'testnow') {
               if (function_exists('WP_SQL_workloads_run_workload_with_output')) {
                   $output = WP_SQL_workloads_run_workload_with_output($id);
                   $hasError = isset($output['error']);
                   $debug = isset($output['debug']) ? $output['debug'] : (is_array($output) ? $output : []);
                   if ($hasError) {
                       echo '<div class="notice notice-error"><p>' . esc_html($output['error']) . '</p>';
                       if (!empty($debug)) {
                           echo '<details style="margin-top:1em;"><summary>Debug Output</summary><ul style="margin-left:2em;">';
                           foreach ($debug as $line) {
                               echo '<li>' . $line . '</li>';
                           }
                           echo '</ul></details>';
                       }
                       echo '</div>';
                   } else {
                       echo '<div class="notice notice-success"><p>Workload executed immediately. Output:</p>';
                       if (!empty($debug)) {
                           echo '<ul style="margin-left:2em;">';
                           foreach ($debug as $line) {
                               echo '<li>' . $line . '</li>';
                           }
                           echo '</ul>';
                       }
                       echo '</div>';
                   }

                   // Additionally: show a preview of up to 3 rows returned by the workload SQL
                   global $wpdb;
                   $sql_query = isset($workloads[$id]['sql_query']) ? $workloads[$id]['sql_query'] : (isset($workloads[$id]['criteria']) ? $workloads[$id]['criteria'] : '');
                   $sql_query = trim((string) $sql_query);
                   if ($sql_query && preg_match('/^\s*SELECT/i', $sql_query)) {
                       // Prepare a preview query: remove trailing semicolons and add LIMIT 3 if not present
                       $preview_query = preg_replace('/;\s*$/', '', $sql_query);
                       if (!preg_match('/\blimit\b\s+\d+/i', $preview_query)) {
                           $preview_query .= ' LIMIT 3';
                       }
                       // Show the actual query being executed for debugging
                       echo '<div style="margin-top:0.5em;font-size:90%;color:#333;">';
                       echo '<strong>Executing preview SQL:</strong> <code>' . esc_html($preview_query) . '</code>';
                       echo '</div>';

                       $results = $wpdb->get_results($preview_query, ARRAY_A);
                       // Show any SQL error
                       if (!empty($wpdb->last_error)) {
                           echo '<div class="notice notice-error"><p><strong>SQL Error:</strong> ' . esc_html($wpdb->last_error) . '</p></div>';
                       }

                       if ($results && is_array($results) && count($results) > 0) {
                           echo '<div class="notice notice-info"><p><strong>SQL Preview (up to 3 rows):</strong></p>';
                           echo '<table class="widefat fixed striped"><thead><tr>';
                           $first = $results[0];
                           foreach (array_keys($first) as $col) {
                               echo '<th>' . esc_html($col) . '</th>';
                           }
                           echo '</tr></thead><tbody>';
                           foreach ($results as $row) {
                               echo '<tr>';
                               foreach ($row as $val) {
                                   echo '<td>' . esc_html(is_null($val) ? 'NULL' : (string)$val) . '</td>';
                               }
                               echo '</tr>';
                           }
                           echo '</tbody></table></div>';
                       } else {
                           echo '<div class="notice notice-warning"><p>No rows returned by the SQL query.</p></div>';
                       }
                   } else {
                       echo '<div class="notice notice-error"><p>SQL query missing or not a SELECT query. Preview unavailable.</p></div>';
                   }
               } else {
                   do_action('WP_SQL_workloads_run_workload', $id);
                   echo '<div class="notice notice-success"><p>Workload executed immediately.</p></div>';
               }
        }
    }
}

?>
<h2>All Workloads</h2>
<?php
$workloads = get_option('WP_SQL_workloads_workloads', []);
if (empty($workloads)) {
    echo '<p>No workloads found.</p>';
} else {
    echo '<table class="widefat fixed striped"><thead><tr>';
    echo '<th>Name</th><th>Time</th><th>Criteria</th><th>Action</th><th>Message</th><th>Status</th><th>Actions</th>';
    echo '</tr></thead><tbody>';
    foreach ($workloads as $id => $w) {
        $is_paused = !empty($w['paused']);
        echo '<tr>';
        echo '<td>' . esc_html($w['name']) . '</td>';
        echo '<td>' . esc_html($w['time']) . '</td>';
        // Show sql_query if present, else criteria (for backward compatibility)
        $criteria = isset($w['sql_query']) ? $w['sql_query'] : (isset($w['criteria']) ? $w['criteria'] : '');
        echo '<td><code>' . esc_html($criteria) . '</code></td>';
        echo '<td>' . esc_html($w['action']) . '</td>';
        echo '<td>' . esc_html($w['message']) . '</td>';
        echo '<td>' . ($is_paused ? '<span style="color:#aaa">Paused</span>' : 'Active') . '</td>';
        echo '<td>';
        echo '<a href="?page=WP_SQL_workloads_all_workloads&workload_action=delete&workload_id=' . urlencode($id) . '" onclick="return confirm(\'Delete this workload?\')" class="button">Delete</a> ';
                if ($is_paused) {
                    echo '<a href="?page=WP_SQL_workloads_all_workloads&workload_action=resume&workload_id=' . urlencode($id) . '" class="button">Resume</a> ';
                } else {
                    echo '<a href="?page=WP_SQL_workloads_all_workloads&workload_action=pause&workload_id=' . urlencode($id) . '" class="button">Pause</a> ';
                }
                // Edit: link to add_workload with ?edit=ID (edit logic not implemented yet)
                echo '<a href="?page=WP_SQL_workloads_add_workload&edit=' . urlencode($id) . '" class="button">Edit</a> ';
                // Test Now button with confirmation
                echo '<a href="?page=WP_SQL_workloads_all_workloads&workload_action=testnow&workload_id=' . urlencode($id) . '" onclick="return confirm(\'Are you sure you want to run this workload now?\')" class="button button-primary">Test Now</a>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
