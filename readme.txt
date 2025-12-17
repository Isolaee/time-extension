=== Time Extension ===
Contributors: Eero Isola
Tags: expiration, cron, email, admin, custom table
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Time Extension allows you to configure a custom database table to monitor for expiring items and send notification emails before expiration. Useful for custom post types, expiring content, or any table with an expiration date column.

**Features:**
- Select which database table to monitor
- Set the column for expiration (auto-detects common names)
- Configure how many days before expiration to send notifications
- Customizable email template
- Daily WP-Cron scheduling at a configurable time
- Admin UI for settings and test emails

== Installation ==
1. Upload the `time-extension` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to the 'Time Extension' menu in the WordPress admin to configure settings.

== Usage ==
* Set the table name you want to monitor (e.g., `wp_posts` or a custom table).
* The plugin will look for columns named `expiration`, `expiry_date`, or `expires_at`.
* Set how many days before expiration to send the email.
* Customize the email template using `{ID}` and `{EXPIRY_DATE}` placeholders.
* Use the 'Show Top 5 Rows' button to preview data from the selected table.
* Use the 'Test' tab to send a test email.

== Upgrade Notice ==
= 1.0 =
Initial release.
