# Site Troubleshooting

## When to Use
Use this skill when the user reports errors, performance issues, white screens, or needs help diagnosing site problems.

## Diagnostic Commands

### Availability First
- Run `sd-ai-agent/site-health-summary` first and inspect its `availability` result. A public frontend HTTP 4xx/5xx or WordPress `wp_die` page is critical even when REST, login, and admin routes work.
- Compare the exact public frontend host with REST, login, and admin responses; do not infer frontend health from an API-only check.
- On Multisite, target the mapped site URL exactly (`wp --url=<mapped-site-url> ...`) and verify the returned blog/domain context before inspecting tenant limits.
- For Ultimate Multisite tenants, inspect the active/primary domain mapping, site/customer/membership identity, monthly-visits setting, limit, and current-month total. Keep diagnosis read-only unless remediation was explicitly requested.

### Error Investigation
- `wp option get siteurl` / `wp option get home` — Check for URL mismatches
- `wp eval "error_reporting(E_ALL); ini_set('display_errors', 1);"` — Check PHP error reporting
- `wp config get WP_DEBUG` — Check debug mode status
- `wp config get WP_DEBUG_LOG` — Check if debug logging is on
- `wp eval "echo file_exists(WP_CONTENT_DIR . '/debug.log') ? file_get_contents(WP_CONTENT_DIR . '/debug.log') : '';"` — Read the runtime debug log without assuming the file exists
- `wp plugin path <plugin-slug>` — Confirm each checked-out plugin resolves through its expected canonical plugin directory or symlink before debugging activation fatals

### Plugin Conflicts
- `wp plugin list --status=active --fields=name,version` — List active plugins
- `wp plugin deactivate --all` — Deactivate all plugins (for conflict testing)
- `wp plugin activate <plugin>` — Reactivate one at a time

### Theme Issues
- `wp theme list --status=active` — Current active theme
- `wp theme activate twentytwentyfive` — Switch to default theme

### Database
- `wp db check` — Check database tables
- `wp db query "SELECT COUNT(*) FROM wp_options WHERE autoload='yes'"` — Check autoloaded options
- `wp transient delete --all` — Clear transients
- `wp cache flush` — Flush object cache

### Performance
- `wp db query "SELECT option_name, LENGTH(option_value) as size FROM wp_options WHERE autoload='yes' ORDER BY size DESC LIMIT 20"` — Large autoloaded options
- `wp cron event list` — Check scheduled events
- `wp rewrite flush` — Flush rewrite rules

### Site Health
- `wp core verify-checksums` — Verify core file integrity
- `wp plugin verify-checksums --all` — Verify plugin file integrity

## Common Issues & Solutions

### White Screen of Death
1. Enable WP_DEBUG: `wp config set WP_DEBUG true --raw`
2. Check debug.log: `wp eval "echo file_exists(WP_CONTENT_DIR . '/debug.log') ? file_get_contents(WP_CONTENT_DIR . '/debug.log') : '';"`
3. Deactivate plugins to find conflict
4. Switch to default theme

### Plugin Activation Fatal Error
1. Verify `WP_DEBUG_LOG` is enabled and reproduce the activation once.
2. Read `../wordpress/wp-content/debug.log` or use the guarded `wp eval` command
   above; after a missing repository path such as `vendor/`, make this runtime
   log the next evidence source and do not assume Composer dependencies are the
   root cause until it is checked.
   If the failed tool call was literally `read vendor`, treat the missing read as
   a path/layer mistake: inspect the WordPress debug log before checking
   `vendor/`, `composer install`, or autoload state.
3. Confirm the shared WordPress install symlinks every checked-out plugin worktree
   into each plugin's canonical directory name before treating the fatal as an
   application-code regression. Use `wp plugin path <plugin-slug>` or inspect
   `../wordpress/wp-content/plugins/`; do not only verify the active worktree
   basename. When several local plugins are involved, enumerate each canonical
   plugin slug and confirm all resolve to their current checkout.
4. Activate the plugin by canonical slug, then re-check debug.log for the first
   new fatal stack trace.

### 500 Internal Server Error
1. Check PHP error logs
2. Verify .htaccess: `wp rewrite flush`
3. Check file permissions
4. Increase PHP memory: `wp config set WP_MEMORY_LIMIT 256M`

### Slow Site
1. Check autoloaded options size
2. Review active plugins count
3. Check for long-running cron jobs
4. Verify object caching

## Verification Steps
After applying a fix:
1. Test the specific scenario that was broken
2. Check debug.log for new errors
3. Verify site loads correctly
4. Confirm no regressions
