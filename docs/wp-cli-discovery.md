# WP-CLI Binary Discovery

The plugin's `wp-cli/execute` ability needs to locate a working WP-CLI binary
before it can run a command. On a developer machine that's trivial — `wp` is
on `$PATH` and everything Just Works™. On shared hosting it's a different
story: the PHP-FPM/Apache user often has no `wp` on `$PATH`, `shell_exec()`
is in `disable_functions`, and `/usr/local/bin/wp` doesn't exist.

This document describes how the plugin finds WP-CLI, how to override the
default discovery, and the canonical "shared hosting" workaround.

## Resolution order

The first match wins:

1. **`sd_ai_agent_wp_cli_binary` filter** — runtime override, useful for
   MU-plugins. Empty string is ignored.
2. **`SD_AI_AGENT_WP_CLI_PATH` constant** — pinned in `wp-config.php`.
3. **Per-request cache** — once a path resolves successfully, subsequent
   calls in the same request reuse it.
4. **System binaries** — `/usr/local/bin/wp`, `/usr/bin/wp`.
5. **WordPress install candidates**:
   - `ABSPATH . 'wp-cli.phar'` (the recommended shared-hosting target)
   - `ABSPATH . 'wp'`
   - `dirname( ABSPATH ) . '/wp-cli.phar'` (when WP is in a subdir)
   - `WP_CONTENT_DIR . '/mu-plugins/wp-cli.phar'`
   - `WP_CONTENT_DIR . '/wp-cli.phar'`
6. **User-home candidates** — `$HOME/.local/bin/wp`, `$HOME/bin/wp`,
   `$HOME/wp-cli.phar`.
7. **Filterable extra candidates** via `sd_ai_agent_wp_cli_candidates`.
8. **`$PATH` scan** — pure-PHP walk of `getenv('PATH')`, no `shell_exec`.
9. **`shell_exec('command -v wp')`** — last resort, only used when
   `shell_exec` is not in `disable_functions`.

`.phar` files are accepted even without the executable bit because the
plugin invokes them via `PHP_BINARY` (the same interpreter that runs
WordPress), which sidesteps both the missing-`php`-on-`$PATH` problem and
the can't-exec-other-UID problem common on shared hosts.

## Shared-hosting setup (recommended)

If your hosting provider does not install WP-CLI system-wide:

1. Download the official `wp-cli.phar`:

   ```
   https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
   ```

2. Upload it via SFTP/cPanel File Manager to your WordPress root — the same
   directory that contains `wp-config.php` and `wp-load.php`. For most
   cPanel hosts this is `~/public_html/wp-cli.phar`.

3. That's it. The plugin will find it on the next request. No `chmod +x`,
   no `php` on `$PATH`, no MU-plugin wrappers required.

If you cannot write to the WordPress root, the plugin also looks in
`wp-content/mu-plugins/wp-cli.phar` and `wp-content/wp-cli.phar`.

## Pinning an exact path

To skip auto-discovery entirely (e.g. you've installed WP-CLI somewhere
non-standard), add this to `wp-config.php` **before** the
`/* That's all, stop editing! */` line:

```php
define( 'SD_AI_AGENT_WP_CLI_PATH', '/home/user/bin/wp-cli.phar' );
```

The constant takes effect immediately on the next request.

## Runtime override (for MU-plugins)

For dynamic resolution (e.g. one path on staging, another on production)
use the `sd_ai_agent_wp_cli_binary` filter:

```php
add_filter(
    'sd_ai_agent_wp_cli_binary',
    static function ( string $path ): string {
        return WP_DEBUG
            ? '/home/dev/bin/wp'
            : '/home/prod/wp-cli.phar';
    }
);
```

The filter takes precedence over `SD_AI_AGENT_WP_CLI_PATH`.

## Extending the auto-discovery list

To add hosting-specific paths without committing to a single value, use
`sd_ai_agent_wp_cli_candidates`:

```php
add_filter(
    'sd_ai_agent_wp_cli_candidates',
    static function ( array $candidates ): array {
        array_unshift( $candidates, '/opt/cpanel/wp-cli.phar' );
        return $candidates;
    }
);
```

The plugin will check the new path before its built-in candidates.

## Disabling the `$PATH` scan

Locked-down hosts may prefer to fail fast (with the actionable "not found"
error) rather than risk finding an unsupported `wp` somewhere on `$PATH`.
Disable the post-candidate-list fallback with:

```php
add_filter( 'sd_ai_agent_wp_cli_scan_path', '__return_false' );
```

This is also how the plugin's own PHPUnit tests get deterministic outcomes
regardless of the CI runner's environment.

## Verifying discovery

Run any read-only WP-CLI command from the agent — e.g. ask the agent to
run `option get blogname`. On success you'll get the blog name back. On
failure the `WP_Error` contains a fully-actionable message:

- The exact download URL.
- The expected target path (`ABSPATH . 'wp-cli.phar'`, resolved to the
  real absolute path so users can paste it into their SFTP client).
- The override constant and filter names.
- The list of paths that were searched.

The error data array also exposes these as machine-readable keys
(`download_url`, `abspath`, `override_constant`, `override_filter`,
`searched_paths`) for tools that present a structured remediation UI.

## Why not bundle `wp-cli.phar`?

The official phar is ~7 MB, which would dominate the plugin distribution
size and create a dependency on keeping the bundled version current.
Users who already have WP-CLI system-wide pay that cost for nothing, and
WordPress.org plugin review prohibits bundled binaries of this size and
scope. The "upload one file" workflow above is the recommended path.

## Related

- Issue: `GH-1335 — A.I Issue Summary WP-CLI` (the customer report that
  prompted the discovery rewrite).
- Source: `includes/Abilities/WpCliAbilities.php`
- Tests: `tests/SdAiAgent/Abilities/WpCliAbilitiesTest.php`
