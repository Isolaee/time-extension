<?php
/**
 * Shared helper functions for WP SQL Workloads plugin.
 * Loaded with require_once from plugin pages to ensure availability
 * both in admin UI and during WP-Cron/background runs.
 */

if (!function_exists('WP_SQL_workloads_next_scheduled_time')) {
    /**
     * Compute next occurrence timestamp for given local HH:MM string.
     * Uses WP timezone when available.
     *
     * @param string $hhmm Time string in 'HH:MM' or 'H:MM' format (24h)
     * @return int UNIX timestamp of next occurrence (today or tomorrow)
     */
    function WP_SQL_workloads_next_scheduled_time($hhmm) {
        try {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());
            $now = new DateTimeImmutable('now', $tz);
            if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
                return $now->getTimestamp();
            }
            $h = (int)$m[1];
            $mm = (int)$m[2];
            $candidate = $now->setTime($h, $mm, 0);
            if ($candidate <= $now) {
                $candidate = $candidate->modify('+1 day');
            }
            return $candidate->getTimestamp();
        } catch (Exception $e) {
            return current_time('timestamp');
        }
    }
}


if (!function_exists('WP_SQL_workloads_queue_email')) {
    /**
     * Queue/send an email. Thin wrapper around `wp_mail()` kept for future
     * customization (e.g., persistent queue implementation).
     *
     * @param string $to
     * @param string $subject
     * @param string $message
     * @param string|array $headers
     * @param array $attachments
     * @return bool True if mail was accepted for delivery.
     */
    function WP_SQL_workloads_queue_email($to, $subject, $message, $headers = '', $attachments = array()) {
        return wp_mail($to, $subject, $message, $headers, $attachments);
    }
}


if (!function_exists('WP_SQL_workloads_run_workload_with_output')) {
    /**
     * Run a saved workload and return debug output.
     * Loads workloads from the option `WP_SQL_workloads_workloads`, validates
     * the SQL (must be a SELECT), executes a safe preview (adds LIMIT if missing),
     * and optionally sends/queues emails per matched row. Results and debug lines
     * are stored into `WP_SQL_workloads_last_run` for UI inspection.
     *
     * @param string|int $workload_id Workload identifier (option array key).
     * @return array ['debug' => array<string>] and optionally 'error'.
     */
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

                        // Replace CF7 tags like [field] or {FIELD} by matching SQL row columns.
                        $body_filled = $cf7_body;
                        $subject_filled = $cf7_subject;
                        $to_filled = $to_address;

                        // Normalize a tag/column name to a simplified form for matching.
                        $normalize = function($s) {
                            $s = (string) $s;
                            $s = trim($s);
                            $s = strtolower($s);
                            // replace non-alphanumeric with underscore
                            $s = preg_replace('/[^a-z0-9]+/', '_', $s);
                            $s = trim($s, '_');
                            return $s;
                        };

                        // Attempt to resolve a CF7/placeholder tag to a value from the SQL row.
                        $find_value_for_tag = function($tag) use ($row_arr, $normalize) {
                            $t = $normalize($tag);
                            // Try direct matches
                            foreach ($row_arr as $col => $val) {
                                if ($normalize($col) === $t) return $val;
                            }
                            // Try stripping common prefixes from tag (e.g., 'your_', 'your')
                            $stripped = preg_replace('/^your_?/', '', $t);
                            if ($stripped !== $t) {
                                foreach ($row_arr as $col => $val) {
                                    if ($normalize($col) === $stripped) return $val;
                                }
                            }
                            // Try matching tag to column suffixes (e.g., 'name' -> 'user_name' or 'user_login')
                            foreach ($row_arr as $col => $val) {
                                $norm_col = $normalize($col);
                                if (str_ends_with($norm_col, '_' . $t) || str_ends_with($norm_col, $t)) return $val;
                            }
                            return null;
                        };

                        // Replace bracket-style tags like [field] using detected row values.
                        if (preg_match_all('/(\[([^\]\s\*]+)\*?\])/', $body_filled, $m, PREG_SET_ORDER)) {
                            foreach ($m as $mt) {
                                $full = $mt[1];
                                $tag = $mt[2];
                                $val = $find_value_for_tag($tag);
                                if (!is_null($val)) {
                                    $body_filled = str_replace($full, $val, $body_filled);
                                }
                            }
                        }
                        if (preg_match_all('/(\[([^\]\s\*]+)\*?\])/', $subject_filled, $m, PREG_SET_ORDER)) {
                            foreach ($m as $mt) {
                                $full = $mt[1];
                                $tag = $mt[2];
                                $val = $find_value_for_tag($tag);
                                if (!is_null($val)) {
                                    $subject_filled = str_replace($full, $val, $subject_filled);
                                }
                            }
                        }
                        if (preg_match_all('/(\[([^\]\s\*]+)\*?\])/', $to_filled, $m, PREG_SET_ORDER)) {
                            foreach ($m as $mt) {
                                $full = $mt[1];
                                $tag = $mt[2];
                                $val = $find_value_for_tag($tag);
                                if (!is_null($val)) {
                                    $to_filled = str_replace($full, $val, $to_filled);
                                }
                            }
                        }

                        // Replace brace-style tags like {FIELD} using detected row values.
                        if (preg_match_all('/\{([^\}]+)\}/', $body_filled, $m, PREG_SET_ORDER)) {
                            foreach ($m as $mt) {
                                $tag = $mt[1];
                                $full = '{' . $tag . '}';
                                $val = $find_value_for_tag($tag);
                                if (!is_null($val)) {
                                    $body_filled = str_replace($full, $val, $body_filled);
                                }
                            }
                        }
                        if (preg_match_all('/\{([^\}]+)\}/', $subject_filled, $m, PREG_SET_ORDER)) {
                            foreach ($m as $mt) {
                                $tag = $mt[1];
                                $full = '{' . $tag . '}';
                                $val = $find_value_for_tag($tag);
                                if (!is_null($val)) {
                                    $subject_filled = str_replace($full, $val, $subject_filled);
                                }
                            }
                        }
                        if (preg_match_all('/\{([^\}]+)\}/', $to_filled, $m, PREG_SET_ORDER)) {
                            foreach ($m as $mt) {
                                $tag = $mt[1];
                                $full = '{' . $tag . '}';
                                $val = $find_value_for_tag($tag);
                                if (!is_null($val)) {
                                    $to_filled = str_replace($full, $val, $to_filled);
                                }
                            }
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

                        // Also replace simple {ID} and other common placeholders.
                        if (isset($row->ID)) {
                            $body_filled = str_replace(['{ID}', '{id}'], $row->ID, $body_filled);
                            $subject_filled = str_replace(['{ID}', '{id}'], $row->ID, $subject_filled);
                        }

                        // Headers: try to use CF7 sender if present (maps to From header).
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

        // Record last run metadata for UI/debugging
        $last_run = array(
            'workload_id' => $workload_id,
            'timestamp' => current_time('timestamp'),
            'sent' => $sent,
            'rows' => is_array($results) ? count($results) : 0,
            'debug' => $output,
        );
        update_option('WP_SQL_workloads_last_run', $last_run);

        return ['debug' => $output];
    }
}
