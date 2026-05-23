# t257 — Wave 2.3: `revert_to_revision` ability

## Pre-flight

- [x] Memory recall: `wp_restore_post_revision revert revision rollback` → 0 hits
- [x] Discovery pass: 0 open PRs touch revision handling. Every wave-1 write ability already surfaces `revision_id`.
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-writer.php:1903` (`revert_to_revision`), `class-block-crud.php:391` (wrapper), `class-rest-controller.php:798` (route), `:2393` (callback)
- [x] Tier: `tier:standard` — wraps `wp_restore_post_revision` with cap check + ref reseeding
- [x] Seeded draft PR decision: skipped — single leaf ability

## Origin

- **Created:** 2026-05-23
- **Session:** opencode interactive (block-mcp wave-2 adoption)
- **Parent task:** #1739
- **Conversation context:** Wave 2 child 3/10. Every wave-1 write returns `revision_id`; this is the symmetric undo. Without it, agents have to call `db-query` against `wp_posts` revisions and re-write the full content — destroys the revision chain.

## What

Add a `sd-ai-agent/revert-to-revision` ability:

```json
{
  "post_id": 123,
  "revision_id": 999,
  "expected_current_revision_id": 1005   // optional; if set, must match latest before revert
}
```

Returns:

```json
{
  "post_id": 123,
  "reverted_to_revision_id": 999,
  "new_revision_id": 1010,       // wp_restore_post_revision creates a NEW revision pointing at old content
  "refs_reseeded": 17,           // count of blocks that had sd_ref reassigned
  "block_count": 17
}
```

Behaviour:

1. Cap check: `current_user_can( 'edit_post', $post_id )` AND `current_user_can( 'edit_post', $revision_id )` (revisions are children).
2. `wp_is_post_revision( $revision_id )` must equal `$post_id` (revision belongs to this post).
3. Optimistic concurrency: if `expected_current_revision_id` is provided, the latest revision before restore must match — else `revision_stale` `WP_Error`.
4. Call `wp_restore_post_revision( $revision_id )`.
5. **Reseed `sd_ref` for every block** in the restored content (refs are content-derived; reverting drops to whatever refs were embedded in the old revision, which may collide with current refs in other posts).
6. Return the new revision ID and ref count.

## Why

Pairs with the `revision_id` already surfaced by every wave-1 write response. Without an explicit revert tool, an agent cannot honour a user's "undo that last change" without re-writing content — which loses the original revision chain.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-writer.php:1903` — 60-line implementation. GPL-2.0-or-later.

## Files to modify / create

- **Modify:** `includes/Abilities/BlockAbilities.php` — register `sd-ai-agent/revert-to-revision` handler.
- **Modify:** `includes/Core/BlockMutator.php` — add `revert_to_revision( $post_id, $revision_id, $expected_current )` method; call into `BlockReferences::reseed_for_post( $post_id )`.
- **Modify:** `includes/Core/BlockReferences.php` — add public `reseed_for_post( int $post_id ): int` if not already exposed.
- **New:** `tests/SdAiAgent/Abilities/RevertToRevisionTest.php` — covers happy path, wrong-post revision (404), stale concurrency, missing cap, ref reseeding count.

## Acceptance criteria

1. Revert to a known revision → `wp_restore_post_revision` called, new revision created, content matches the older revision.
2. Refs reseeded: every block in the post has a valid `sd_ref`, and `BlockReferences::lookup` resolves them.
3. `revision_id` belongs to a different post → `WP_Error('revision_post_mismatch', ...)`.
4. `expected_current_revision_id` mismatch → `WP_Error('revision_stale', ...)`.
5. User without `edit_post` cap → `WP_Error('insufficient_capability', ...)`.
6. Rate limit applies (this counts as a write, contributing to the standard 10/min bucket — see t264).
7. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
# Make an edit to create a revision, then revert.
wp eval '
  $before = (int) wp_get_post_revisions(156, ["numberposts" => 1, "fields" => "ids"])[0];
  // ... edit happens via edit-block-tree (separate call) ...
  $r = wp_get_ability("sd-ai-agent/revert-to-revision")->execute([
    "post_id" => 156, "revision_id" => $before
  ]);
  echo wp_json_encode($r) . PHP_EOL;
'
```

## Tier rationale

`tier:standard` — wraps a single well-tested core function, but the ref-reseeding step needs careful attention to avoid leaving stale refs in the post.

## Dependencies

- **Blocked by:** none.
- **Related:** counts against rate-limit bucket from t264 once that lands; until then, no rate limiting on this op (acceptable interim).

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #1739`.
