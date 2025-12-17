=== Time Extension ===
Contributors: Eero Isola
Tags: expiration, cron, email, admin, custom table, contact-form-7
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Time Extension allows you to configure a custom database table to monitor for expiring items and send notification emails before expiration. Useful for custom post types, expiring content, or any table with an expiration date column.

The plugin supports both simple scheduled notifications (global table-based) and ad-hoc SQL-driven "workloads" which can map SQL result columns to Contact Form 7 templates for flexible, per-row notifications.

== Features ==
- Select which database table to monitor
- Customizable email template for table-based notifications
- Create multiple SQL-driven "workloads" (stored in options) with:
	- a `SELECT` query (SELECT only enforced)
	- schedule time
	- action (Send an email)
	- optional Contact Form 7 form mapping
- Integrates with Contact Form 7: use a CF7 form as the email template and map SQL columns to CF7 tags (e.g., `[user_email]`, `[user_login]`)
- "Test Now" executes a workload immediately and shows SQL preview, returned rows and debug output
- Preview top rows from the selected table and see DB connection details for troubleshooting
- Uses `wp_mail()` (and WP Mail SMTP compatibility) as fallback if CF7 is not available or configured

== Quick Start ==
1. Upload the `WP-SQL-workloads` folder to `/wp-content/plugins/` and activate the plugin.
2. Go to the *WP SQL Workloads* menu in admin.
3. In **Settings**: set the table name and execution time, and configure the expiration email template.
4. Use **Show Top 5 Rows** to verify the plugin sees your table and rows.
5. Create a **Workload** (Add Workload) to schedule custom SQL-driven notifications. Optionally pick a Contact Form 7 form to use its Mail template.
6. Use **All Workloads** → **Test Now** to run and debug a workload immediately.

== Contact Form 7 integration ==
To use CF7 templates with the plugin, create a CF7 form whose field names match the SQL columns you will return. The plugin maps SQL columns to CF7 tags using these rules (attempted in order):

- Exact column name (case-insensitive)
- Normalized name: lowercased and non-alphanumeric characters replaced with underscores (so `user-email` ↔ `user_email`)
- Stripping common prefixes like `your_` (`[your-name]` can match SQL column `name`)
- Suffix match: a tag `name` may match `user_name` or `user_login` if appropriate


CF7 Mail tab example (exact values):

To: [user_email]
From: Your Site <wordpress@yourdomain.com>
Subject: Info about your post
Message body:

Hello [user_login],

Best regards,
Your Site


== Workloads (step-by-step) ==
1. Go to *WP SQL Workloads → Add Workload*.
2. Fill in:
	 - Name: a friendly label
	 - Time: daily execution time (24h)
	 - SQL Query: a SELECT that returns the columns your CF7 form uses. Example:
		 ```sql
		 SELECT user_login, user_email FROM wp_users WHERE user_email = 'user@example.com';
		 ```
		 If your column names differ from CF7 tags, use `AS` to alias them:
		 ```sql
		 SELECT login AS user_login, email AS user_email FROM my_users WHERE ...;
		 ```
	 - Action: choose "Send an email"
	 - Contact Form 7 Form: select the form to use as template (optional)
	 - Message: fallback message used when CF7 template is not available
3. Save. The workload will be scheduled daily at the specified time (unless paused).
4. Use *All Workloads → Test Now* to run and inspect debug output immediately.

== Test Now & Debugging ==
- "Test Now" runs the workload and shows:
	- The exact SQL executed (preview uses `LIMIT` for safety)
	- Any SQL error from `$wpdb->last_error`
	- Up to 3 returned rows (preview table)
	- Runner debug lines: which placeholders were replaced, whether CF7 template was applied, fallback decisions, and email send status
- If tags are not replaced, check:
	- The preview table shows column names — alias in SQL to match CF7 tags
	- CF7 Mail template uses bracket tags (`[user_email]`) or `{TAG}`; bracket tags are recommended
	- Recipient resolution: the plugin validates resolved addresses; if invalid it falls back to SQL email column or `admin_email`

== Security & Production Notes ==
- Workloads only accept `SELECT` queries. Nevertheless, run the plugin with a DB user that has read-only access where possible.
- For reliable scheduled runs in production, run a system cron to hit `wp-cron.php` rather than relying on visitor-triggered WP-Cron.
- Test thoroughly in a development environment before enabling scheduled notifications.

== Troubleshooting checklist ==
- "No results found": verify `DB_NAME` and `DB_HOST` in the plugin Settings debug box; ensure table name is correct and readable by DB user.
- Placeholders not replaced: check preview column names and alias SQL to match CF7 tags exactly.
- Email not sent: check WP Mail SMTP logs or `wp-content/debug.log`; the plugin logs debug info to the Test Now UI.

== Upgrade Notice ==
= 1.0 =
Initial release.
