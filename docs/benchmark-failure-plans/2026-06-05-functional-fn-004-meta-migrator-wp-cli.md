# Plan: fix `functional-v1` / `fn-004` meta-migrator WP-CLI failure

## Failure

`functional-v1` question `fn-004` failed one assertion for `syn:large:text`.

Suite result:

```text
Questions run : 8
Agent errors  : 0
Assertions    : 32/33 passed
Score         : 97%
```

Question result:

```text
[fn-004] Build a WordPress plugin called "meta-migrator" that provides a WP-CLI command "wp meta-migrator run" ...
✓ Plugin PHP files have no syntax errors
✓ Plugin activates without fatal errors
✓ Plugin checks WP_CLI before registering command
Score: 3/4 assertions passed
```

The missing assertion is the benchmark definition's WP-CLI command check:

```php
array(
    'type'                    => 'wp_cli_command',
    'command'                 => 'meta-migrator run --dry-run --url=wp-multisite-waas.test',
    'expected_output_pattern' => 'dry.run|Migration complete|Processed',
    'expected_exit_code'      => 0,
    'description'             => 'WP-CLI command runs successfully in dry-run mode',
),
```

## Evidence

Log:

```text
wp-content/uploads/sd-ai-agent/benchmark-runs/20260605T171006Z-synthetic-all/logs/syn-large-text/functional-v1/2026-06-05_172327_syn:large:text/fn-004.json
```

The generated plugin registered:

```php
if ( defined( 'WP_CLI' ) ) {
    WP_CLI::add_command( 'meta-migrator run', 'Meta_Migrator_CLI_Command' );
}
```

The command handler prints a success message when no posts contain `_legacy_price`:

```php
WP_CLI::success( 'No posts with _legacy_price meta found. Nothing to migrate.' );
```

That message does not match the benchmark's expected pattern:

```text
dry.run|Migration complete|Processed
```

## Likely root cause

The model implemented a reasonable empty-state branch, but the benchmark requires the dry-run command output to contain one of the expected progress/summary phrases even when there is no work to do.

There are two possible fixes:

1. **Agent/model behavior fix:** teach generated WP-CLI migration commands to always emit the requested final summary string, including empty-state dry runs.
2. **Benchmark assertion fix:** decide that an empty-state message like `No posts ... Nothing to migrate` is valid when no fixture data exists, and update the expected pattern accordingly.

The safer product behavior is option 1: commands should produce stable machine-readable/progress output even when no rows match.

## Remediation plan

1. **Improve the plugin-generation guidance for WP-CLI migration tasks.**
   - Add instruction in the relevant skill/prompt path that migration commands must always print the requested final summary exactly.
   - Include dry-run and zero-row examples.

2. **Strengthen benchmark fixture setup or assertion output.**
   - Ensure the assertion creates at least one post with `_legacy_price` before invoking `meta-migrator run --dry-run`, or
   - Broaden the assertion pattern only if zero-row output is intentionally accepted.

3. **Add a regression benchmark/unit check.**
   - Run `fn-004` alone after the guidance change.
   - Inspect the generated plugin output for:
     - `Processed 0/0 posts` or `Processed X/Y posts`
     - `Migration complete: 0 posts updated` or `Migration complete: X posts updated`

4. **Keep generated WP-CLI command registration robust.**
   - Register command only when `WP_CLI` is defined.
   - Register the parent command form that WP-CLI resolves consistently for `wp meta-migrator run`.

## Verification

```bash
wp sd-ai-agent benchmark run \
  --question=fn-004 \
  --provider=ai-provider-for-any-openai-compatible \
  --model=syn:large:text
```

Expected:

```text
Assertions: 4/4 passed
```

## Success criteria

- `fn-004` passes for `syn:large:text`.
- The generated command exits `0` for dry-run with and without matching `_legacy_price` rows.
- The output always includes a benchmark-compatible progress or summary phrase.
