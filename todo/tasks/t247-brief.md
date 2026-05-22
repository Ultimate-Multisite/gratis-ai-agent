# t247 — Tier 1.4: Optimistic concurrency via `If-Match` revision_id

## Pre-flight

- [x] Memory recall: `if-match etag concurrency wordpress` → 0 hits
- [x] Discovery pass: 0 open PRs touch REST write paths
- [x] File refs verified: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-rest-controller.php:1728, 2463`, `class-block-writer.php:209-260`; Superdav `includes/REST/RestController.php:748`
- [x] Tier: `tier:thinking` — cross-cutting header handling and 412 semantics
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-22
- **Session:** opencode interactive (block-mcp adoption review)
- **Parent task:** none (sibling group t244–t254)
- **Conversation context:** Tier 1 item 4 — defence against silent overwrites in concurrent agent/human edit sessions.

## What

Wire optimistic concurrency control into every Superdav block-write endpoint:

1. **Read responses** include `revision_id` (current `post_modified_gmt` revision or `wp_get_post_revisions()[0]->ID`).
2. **Writes** accept an `If-Match` HTTP header **or** an `expected_revision` body field (whichever is present; If-Match wins).
3. If the supplied revision ID doesn't match the post's current latest revision, return HTTP **412** with code `stale_revision` and a `data.current_revision_id` for the agent to re-fetch against.
4. Header parsing accepts both bare `12345` and weak-etag `W/"12345"` forms.
5. Strict mode is **opt-in per request** — writes without an `If-Match` succeed normally (backward compatible).

## Why

Two agents editing the same post, or an agent racing a human in wp-admin, can silently overwrite each other. Today, last-write-wins. With `If-Match`, the loser gets a 412 and can re-read + replay against fresh state. Costs nothing for callers who don't opt in; protects callers who do.

## Source pattern

Adapt from `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-rest-controller.php` around lines 1728 (header extraction) and 2463 (the helper `parse_if_match()`), and `class-block-writer.php:209-260` (the gate inside the write path). The implementation is small (~80 LOC total) but cross-cuts every write endpoint.

## Files to modify / create

- **New:** `includes/Core/RevisionGuard.php` — `current_revision_id( int $post_id ): int`, `parse_if_match( WP_REST_Request $req ): ?int`, `check( int $post_id, ?int $expected ): true|WP_Error`.
- **Modify:** `includes/REST/RestController.php` — wire the guard into every block-write route registered there.
- **Modify:** Any new block-mutation route registered by t245/t246 — add the `RevisionGuard::check()` call before mutation, attach `revision_id` to the response.
- **Modify:** `includes/Abilities/BlockAbilities.php` — pass-through `expected_revision` to underlying REST when callers use abilities directly.
- **New:** `tests/SdAiAgent/Core/RevisionGuardTest.php` — header parse, mismatch returns 412, missing header passes, weak-etag form.

## Acceptance criteria

1. Read endpoints return `revision_id` in the response payload.
2. A write with stale `If-Match: 123` against a post whose current revision is 456 returns HTTP 412 `stale_revision` with `data.current_revision_id = 456`; no DB write.
3. A write with matching `If-Match` succeeds normally.
4. A write with **no** `If-Match` succeeds normally (back-compat).
5. Weak ETag form `W/"123"` parses correctly.
6. Malformed `If-Match` (e.g. `If-Match: garbage`) returns HTTP 400 `invalid_if_match`.
7. Tests cover all paths; full suite passes.

## Verification

`npm run verify` + manual:

```bash
curl -u 'admin:app-pw' -H 'If-Match: 1' -X POST .../wp-json/sd-ai-agent/v1/edit-block-tree -d '{...}' -i
# expect 412 if revision moved
```

## Tier rationale

`tier:thinking`. Cross-cutting REST concern with header parsing nuance (weak-etag, body fallback, 412 vs 400 disambiguation).

## Dependencies

- **Blocked by:** none structurally, but most useful **after** t245/t246 land (more endpoints to guard).
- **Pairs with:** t246 (one batch = one revision, so If-Match is the natural unit of conflict).

## PR conventions

Leaf — `Resolves #<this-issue>`.
