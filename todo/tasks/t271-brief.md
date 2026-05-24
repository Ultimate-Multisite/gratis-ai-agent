# t271 — Wave 3.7: Port missing block-mcp security test suites

## Pre-flight

- [x] Memory recall: `IDOR xss injection block comment uploads resource exhaustion` → existing `SsrfGuardTest`, `SafeHttpClientTest`, `UploadMediaAbilityTest` cover SSRF + upload SSRF only.
- [x] Discovery pass: 0 PRs touch security tests. Gap: IDOR, XSS bypass via attribute, block-comment injection, resource exhaustion, uploads-disabled bypass.
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/tests/Security/` (6 files; 5 are missing or partial on our side).
- [x] Tier: `tier:standard` — security regression tests, established threat models.
- [x] Seeded draft PR decision: skipped.

## Origin

- **Created:** 2026-05-24
- **Session:** opencode interactive (block-mcp wave-3 adoption)
- **Parent task:** TBD
- **Conversation context:** Wave 3 child 7/11. We have 30+ abilities — most touching post content or user data — and only one security test class (the SSRF guard). block-mcp ships a security suite covering the threats that historically bit them; porting the suite raises our baseline cheaply.

## What

Create `tests/SdAiAgent/Security/` with 5 PHPUnit test classes, ported from block-mcp:

1. **`IdorTest.php` (NEW)** — Insecure Direct Object Reference checks across every read+write ability that accepts a `post_id` / `attachment_id` / `term_id` / `revision_id`. For each, instantiate two users (admin, contributor) and assert: contributor can READ their own post but NOT read another user's draft via `get-post`, NOT read its blocks via `get-page-blocks`, NOT write to it via `update-blocks`, NOT delete it via `delete-post`, NOT revert it via `revert-to-revision`. Capability check must be **per resource**, not just "edit_posts" globally.

2. **`XssBypassTest.php` (NEW)** — For every ability that accepts user-supplied HTML (`update-blocks { op: update-html }`, `update-blocks { op: replace-block }`, `insert-pattern`, `rewrite-post-blocks`), feed payloads from the OWASP XSS filter evasion cheat sheet (script tags, event handlers, javascript:, data: URIs, SVG onload, MathML, malformed tags). Assert: `wp_kses_post` strips the dangerous parts, the surviving HTML doesn't execute, and no payload survives a round-trip parse → serialise → re-parse.

3. **`BlockCommentInjectionTest.php` (NEW)** — Feed innerHTML containing `<!-- wp:malicious /-->` and `<!-- /wp:paragraph -->` (closing tag that doesn't match the surrounding block) into every write op. Assert: the parser is not tricked into producing a malicious block (e.g. `core/html` with attacker-controlled attrs), or the write is rejected with `block_comment_injection_detected`. This is the corollary of dual-storage safety: comments inside innerHTML can re-shape the block tree if unescaped.

4. **`ResourceExhaustionTest.php` (NEW)** — Send: 200-deep nested block tree, 10MB base64 in `upload-media`, `update-blocks` batch with 51 items (over the cap), `replace-block-range` with 1000 new blocks (over MAX_RANGE_SIZE), `list-posts` with `per_page: 100000`, `get-page-blocks` against a 5MB post_content. Assert: each is rejected with a precise `*_too_large` / `*_exceeded` code BEFORE allocation occurs (e.g. payload size checked before `parse_blocks`).

5. **`UploadsDisabledTest.php` (PORT)** — When `WP_DISABLE_UPLOADS` or `multisite_can_upload()` returns false, every upload path (`upload-media` with each of the 3 source modes, `import-base64-image`, `import-stock-image`) returns `WP_Error('uploads_disabled', ...)`. Also: even when uploads are enabled, a contributor without `upload_files` capability is rejected.

## Why

Two of the five threats above have CVE-level history in the WordPress plugin ecosystem (IDOR + XSS bypass via attribute injection). The other three are operational invariants we already assert in production code but don't have regression coverage for. A merged PR that accidentally weakens any of them would slip through current CI; this suite catches it.

Concretely:
- **IDOR**: with abilities accepting `post_id` directly, a missing per-resource cap check is a one-line bug → user-data leak. Our wave-2 abilities all use `current_user_can( 'edit_posts' )`; this test asserts the stricter per-resource check actually fires.
- **XSS bypass**: `wp_kses_post` is generally strict, but new block ops (rewrite-post-blocks, replace-block-range) need explicit verification that they call it.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/tests/Security/` — all 5 source files. GPL-2.0-or-later.

Per-file notes:

- `UploadsDisabledTest`: block-mcp port lines up almost 1:1; only change the ability namespace.
- `IdorTest`: block-mcp tests REST routes; we test abilities. Replace `wp_remote_request` calls with direct `wp_get_ability(...)->execute(...)` invocations under the right user context (`wp_set_current_user`).
- `XssBypassTest`: payload list is the value; the harness is straightforward.
- `BlockCommentInjectionTest` and `ResourceExhaustionTest` need light rewriting since our op set differs from block-mcp's (we have `replace-block-range`, they don't; they have `mutate`, we have `edit-block-tree`). Cover the equivalents.

## Files to modify / create

- **New:** `tests/SdAiAgent/Security/IdorTest.php`
- **New:** `tests/SdAiAgent/Security/XssBypassTest.php`
- **New:** `tests/SdAiAgent/Security/BlockCommentInjectionTest.php`
- **New:** `tests/SdAiAgent/Security/ResourceExhaustionTest.php`
- **New:** `tests/SdAiAgent/Security/UploadsDisabledTest.php`
- **Modify:** `phpunit.xml.dist` — `<testsuite name="security">` if separation is desired. Default suite should include security so PR CI catches them.

## Acceptance criteria

1. All 5 test classes exist under `tests/SdAiAgent/Security/`.
2. `npm run test:php -- --testsuite=security` runs only these.
3. IDOR: contributor invoking `get-post { id: <admin_draft_id> }` → `WP_Error('insufficient_capability', ...)`, NOT a post dict.
4. XSS: at least 30 OWASP payloads (block-mcp's full list) covered; each round-trip produces sanitised, non-executing HTML.
5. Block-comment injection: payload `"<p>safe</p><!-- wp:html --><script>alert(1)</script><!-- /wp:html -->"` does NOT produce a `core/html` block after parse → write → re-parse; either the comments are escaped or the write is rejected.
6. Resource exhaustion: each of the 6 limits trips a `*_too_large` / `*_exceeded` code with HTTP status 413 / 400 as appropriate.
7. UploadsDisabled: all 5 upload paths rejected when `WP_DISABLE_UPLOADS` is defined.
8. Per-resource cap (the IDOR test) covers at least 8 abilities: `get-post`, `get-page-blocks`, `update-blocks`, `delete-post`, `revert-to-revision`, `upload-media`, `delete-media`, `update-post`.
9. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
npm run test:php -- --testsuite=security
```

## Tier rationale

`tier:standard` — port + adapt of known tests. Risk surface is the IDOR test fixture (creating two users, switching context) which is well-trodden in the WP test harness. No production code changes expected (failures should be reported back as security bugs, not patched in this PR).

## Dependencies

- **Blocked by:** none.
- **Soft dependency:** if `IdorTest` exposes real per-resource cap holes in shipped abilities, each becomes its own follow-up bug PR (similar to wave-2 #1769). Do NOT fix them in this PR — keep the test PR mechanical.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #<parent>`.
