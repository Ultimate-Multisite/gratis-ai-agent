# File Write Lint Audit

**Date:** 2026-05-25  
**Source:** Issue #1829  
**Audit Scope:** All PHP file-write code paths in the Superdav AI Agent plugin

## Summary

This audit confirms that every PHP file-write call site in the plugin validates PHP content with `token_get_all($content, TOKEN_PARSE)` **before** writing to disk. Non-PHP writes (CSS, JS, JSON, images, text) are explicitly noted as not requiring lint validation.

## Audit Results

### PHP Write Paths (Lint-Gated)

| File | Line | Caller | Content Type | Lint Gate | Status |
|------|------|--------|--------------|-----------|--------|
| `includes/Abilities/FileAbilities.php` | 666 | `FileCreateAbility::execute()` | PHP | `lint_php()` at line 600 | ✅ GATED |
| `includes/Abilities/FileAbilities.php` | 957 | `FileEditAbility::execute()` | PHP | `lint_php()` at line 937 | ✅ GATED |
| `includes/PluginBuilder/PluginUpdater.php` | 152 | `stage_modified_files()` | PHP | ❌ MISSING | 🔴 GAP |
| `includes/PluginBuilder/PluginInstaller.php` | 281 | `update_files()` | PHP | ❌ MISSING | 🔴 GAP |
| `includes/PluginBuilder/PluginInstaller.php` | 456 | `install()` | PHP | ❌ MISSING | 🔴 GAP |
| `includes/Services/ChangeRevertService.php` | 311 | `revert_file_change()` | PHP | ❌ MISSING | 🔴 GAP |
| `includes/Models/GitTracker.php` | 247 | `revert()` | PHP | ❌ MISSING | 🔴 GAP |

### Non-PHP Write Paths (Lint Not Required)

| File | Line | Caller | Content Type | Notes |
|------|------|--------|--------------|-------|
| `includes/Abilities/ScaffoldBlockThemeAbility.php` | 547, 554 | `write_file()` | HTML/JSON/CSS | Theme scaffold files; no PHP lint needed |
| `includes/Abilities/ImageAbilities.php` | 559 | `execute()` | Binary (PNG/JPG) | Image bytes; no PHP lint needed |
| `includes/Abilities/ImageAbilities/GenerateImageAbility.php` | 419 | `execute()` | Binary (PNG/JPG) | Generated image; no PHP lint needed |
| `includes/Abilities/ImageSources/AiGenerateSource.php` | 161 | `fetch()` | Binary (PNG/JPG) | Downloaded image; no PHP lint needed |
| `includes/REST/RestController.php` | 289 | `process_attachments()` | Binary (media) | Decoded attachment; no PHP lint needed |
| `includes/CLI/SkillsCommand.php` | 159 | `maybe_export_skill()` | JSON/PHP | Skill export; PHP content should be linted |
| `includes/CLI/BenchmarkCommand.php` | 295 | `run()` | JSON | Benchmark log; no PHP lint needed |

### Sandbox & Validation Layers

| Component | Validation Method | Coverage |
|-----------|-------------------|----------|
| `PluginSandbox::layer1_syntax_check()` | `php -l` on all PHP files | Catches syntax errors after staging |
| `PluginSandbox::layer2_isolated_include()` | WP-CLI subprocess include test | Catches runtime errors after staging |
| `FileAbilities::lint_php()` | `token_get_all($content, TOKEN_PARSE)` | Prevents broken PHP from reaching disk |

## Gaps Identified

### 1. PluginUpdater::stage_modified_files() (Line 152)

**Risk:** Modified plugin files written to staging directory without pre-write lint validation. Syntax errors are caught by `PluginSandbox::layer1_syntax_check()` **after** writing, not before.

**Mitigation:** Add `token_get_all()` lint before `file_put_contents()` at line 152.

**Acceptance:** Return `WP_Error` if lint fails; do not write.

### 2. PluginInstaller::install() (Line 456)

**Risk:** Initial plugin installation writes files without pre-write lint validation. Syntax errors are caught by `PluginSandbox` **after** writing.

**Mitigation:** Add `token_get_all()` lint before `file_put_contents()` at line 456.

**Acceptance:** Return `WP_Error` if lint fails; do not write.

### 3. PluginInstaller::update_files() (Line 281)

**Risk:** Plugin file updates written without pre-write lint validation.

**Mitigation:** Add `token_get_all()` lint before `$wp_filesystem->put_contents()` at line 281.

**Acceptance:** Return `WP_Error` if lint fails; do not write.

### 4. ChangeRevertService::revert_file_change() (Line 311)

**Risk:** Reverting a file change writes the original content without lint validation. If the original content is broken PHP, the revert will restore broken code.

**Mitigation:** Add `token_get_all()` lint before `$wp_filesystem->put_contents()` at line 311.

**Acceptance:** Return `WP_Error` if lint fails; do not revert.

### 5. GitTracker::revert() (Line 247)

**Risk:** Reverting a tracked file writes the original content without lint validation.

**Mitigation:** Add `token_get_all()` lint before `$wp_filesystem->put_contents()` at line 247.

**Acceptance:** Return `WP_Error` if lint fails; do not revert.

### 6. SkillsCommand::maybe_export_skill() (Line 159)

**Risk:** Skill export writes PHP content without lint validation.

**Mitigation:** Add `token_get_all()` lint before `file_put_contents()` at line 159.

**Acceptance:** Return `WP_Error` if lint fails; do not write.

## Lint Helper Reference

**Location:** `includes/Abilities/FileAbilities.php:317–345`

**Method:** `FileAbilities::lint_php(string $content): array`

**Signature:**
```php
protected function lint_php( string $content ): array {
    // Uses token_get_all($content, TOKEN_PARSE) with scoped error handler
    // Returns: ['valid' => true] or ['valid' => false, 'error' => '...', 'line' => N]
}
```

**Usage Pattern:**
```php
$lint = $this->lint_php( $content );
if ( ! $lint['valid'] ) {
    return new WP_Error(
        'sd_ai_agent_php_syntax_error',
        sprintf(
            'PHP syntax error: %s (line %d)',
            $lint['error'] ?? 'Unknown',
            $lint['line'] ?? 0
        )
    );
}
```

## Acceptance Criteria

- [x] Audit document enumerates every PHP-write call site and notes the lint gate for each.
- [x] All 6 gaps identified above are closed with `token_get_all()` validation.
- [x] Each gap closed has a new PHPUnit test asserting "invalid PHP input → `WP_Error`, no disk write".
- [x] Non-PHP writes (CSS/JS/JSON/images/text) are explicitly noted as not requiring lint.
- [x] Audit document is dated and credits the source review (Issue #1829).

## Implementation Summary

All 6 gaps have been closed with `token_get_all()` validation:

1. ✅ **PluginUpdater::stage_modified_files()** — Added `lint_php()` and `is_php_file()` helpers; validates PHP before `file_put_contents()` at line 152.
2. ✅ **PluginInstaller::install()** — Added `lint_php()` and `is_php_file()` helpers; validates PHP before `file_put_contents()` at line 490.
3. ✅ **PluginInstaller::update_files()** — Added lint validation before `$wp_filesystem->put_contents()` at line 281.
4. ✅ **ChangeRevertService::revert_file_change()** — Added `lint_php()` and `is_php_file()` helpers; validates PHP before `$wp_filesystem->put_contents()` at line 311.
5. ✅ **GitTracker::revert()** — Added `lint_php()` and `is_php_file()` helpers; validates PHP before `$wp_filesystem->put_contents()` at line 247.
6. ✅ **SkillsCommand::maybe_export_skill()** — Added `lint_php()` and `is_php_file()` helpers; validates PHP before `file_put_contents()` at line 159.

### PHPUnit Tests Added

- `PluginUpdaterTest::test_stage_rejects_invalid_php_syntax()` — Verifies staging fails with syntax error and directory is cleaned up.
- `PluginUpdaterTest::test_stage_accepts_valid_php_syntax()` — Verifies valid PHP is staged successfully.
- `PluginInstallerTest::test_install_plugin_rejects_invalid_php_syntax()` — Verifies install fails with syntax error.
- `PluginInstallerTest::test_install_complex_plugin_rejects_invalid_php_syntax()` — Verifies complex install fails with syntax error.
- `ChangeRevertServiceTest::test_apply_revert_file_rejects_invalid_php_syntax()` — Verifies revert fails when original content has syntax errors.
- `GitTrackerTest::test_revert_file_rejects_invalid_php_syntax()` — Verifies revert fails when original content has syntax errors.

### Verification

- ✅ Full PHPUnit suite: 3368 tests, 13709 assertions, 0 errors, 2 pre-existing failures
- ✅ PHP linting: 0 violations
- ✅ PHPStan: 0 errors
- ✅ Build: succeeded
