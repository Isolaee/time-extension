# WP SQL Workloads

A WordPress plugin that enables database-driven notification scheduling and automated email delivery based on SQL query results.

## Description

WP SQL Workloads allows you to create scheduled "workloads" that execute custom SQL SELECT queries against your WordPress database and automatically send notification emails for each result row. This is particularly useful for monitoring expiring items, subscription renewals, or any database-driven notification needs.

## Features

- **Scheduled SQL Execution** - Run custom SELECT queries daily at specified times
- **Automated Email Notifications** - Send emails for each row returned by your SQL query
- **Contact Form 7 Integration** - Use CF7 forms as email templates with automatic field mapping
- **Dashboard Widget** - View upcoming scheduled workloads and last run information
- **Test Mode** - Execute workloads immediately with detailed debug output
- **Pause/Resume** - Temporarily disable workloads without deleting them
- **SQL Preview** - Preview query results before scheduling

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- Optional: [Contact Form 7](https://wordpress.org/plugins/contact-form-7/) for email templates

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **WP SQL Workloads** in the admin menu

## Usage

### Creating a Workload

1. Go to **WP SQL Workloads → Add Workload**
2. Enter a descriptive name for your workload
3. Set the daily execution time (24-hour format)
4. Write your SQL SELECT query
5. Optionally select a Contact Form 7 form as your email template
6. Save the workload

### SQL Query Guidelines

- Queries must start with `SELECT`
- Include an email column in your results for recipient detection
- The plugin will automatically detect columns named `email`, `user_email`, `recipient`, etc.
- A safety LIMIT of 1000 rows is automatically applied

**Example Query:**
```sql
SELECT
    u.user_email,
    u.display_name,
    m.meta_value as expiry_date
FROM wp_users u
JOIN wp_usermeta m ON u.ID = m.user_id
WHERE m.meta_key = 'subscription_expiry'
AND m.meta_value <= DATE_ADD(NOW(), INTERVAL 7 DAY)
```

### Contact Form 7 Integration

When using a CF7 form as a template, SQL column values are automatically mapped to CF7 mail tags:

1. **Exact match** - Column name matches tag exactly
2. **Normalized match** - Lowercased with special characters replaced by underscores
3. **Prefix stripping** - `your_` and `your` prefixes are removed for matching
4. **Suffix match** - Tag matches column suffix

Both bracket tags `[field]` and brace tags `{field}` are supported.

### Managing Workloads

From **WP SQL Workloads → All Workloads** you can:

- **View** - See all configured workloads with their status
- **Edit** - Modify workload configuration
- **Pause/Resume** - Temporarily disable or re-enable workloads
- **Test Now** - Execute immediately with full debug output
- **Delete** - Remove a workload permanently

## File Structure

```
time-extension/
├── WP_SQL_workloads.php          # Main plugin file
├── readme.txt                     # WordPress.org readme
├── README.md                      # This file
└── src/
    ├── WP_SQL_workloads-main.php # Core initialization & hooks
    ├── helpers.php                # Shared utilities & execution engine
    ├── all-workloads.php          # Workload management page
    └── create-workload.php        # Add/Edit workload form
```

## Security Considerations

- **SELECT-only queries** - All queries are validated to only allow SELECT statements
- **Capability checks** - Admin pages require `manage_options` capability
- **Input sanitization** - All user inputs are properly sanitized
- **Output escaping** - All output is escaped to prevent XSS

### Recommendations

- Use a read-only database user for enhanced security
- Configure a real system cron for production environments (wp-cron has limitations)
- Test workloads thoroughly in a development environment first

## WP-Cron Limitations

WordPress cron depends on site visits to trigger scheduled tasks. For reliable scheduling:

1. Disable WP-Cron in `wp-config.php`:
   ```php
   define('DISABLE_WP_CRON', true);
   ```

2. Set up a real system cron job:
   ```bash
   */15 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
   ```

## Email Configuration

For reliable email delivery, consider using an SMTP plugin such as:

- [WP Mail SMTP](https://wordpress.org/plugins/wp-mail-smtp/)
- [Post SMTP](https://wordpress.org/plugins/post-smtp-mailer-smtp-email-log/)

## Hooks & Filters

### Actions

- `WP_SQL_workloads_run_workload` - Triggered when a workload executes

## Data Storage

The plugin stores data using WordPress options:

- `WP_SQL_workloads_workloads` - Array of workload configurations
- `WP_SQL_workloads_last_run` - Metadata from the last execution
- `WP_SQL_workloads_clock_time` - Default execution time

## Changelog

### 1.0.0
- Initial release
- SQL workload scheduling
- Contact Form 7 integration
- Dashboard widget
- Pause/Resume functionality

## Author

Eero Isola
