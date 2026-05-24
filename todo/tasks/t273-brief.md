# t273 — Wave 3.9: `get-page-blocks` `revision_id` never zero for revisionless posts

## Pre-flight

- [x] Memory recall: `revision_id 0 wp_get_post_revisions empty` → noted during wave-2 t257 verification; freshly-created posts with no prior edits return `revision_id: 0` from `get-page-blocks`.
- [x] Discovery pass: 0 PRs touch this code path. `RevisionGuard::current_revision_id` returns 0 when `wp_get_post_revisions($post_id)` is empty.
- [x] File refs verified — Superdav existing: `includes/Core/RevisionGuard.php`, `includes/Abilities/BlockAbilities.php` (get-page-blocks handler).
- [x] Tier: `tier:standard` — small behavioural fix with one shape choice (null vs post_id-as-pseudo-rev).
- [x] Seeded draft PR decision: skipped.

## Origin

- **Created:** 2026-05-24
- **Session:** opencode interactive (block-mcp wave-3 adoption)
- **Parent task:** TBD
- **Conversation context:** Wave 3 child 9/11. During wave-2 t257 (revert) verification, a freshly-`wp_insert_post`'d post returned `revision_id: 0` from `get-page-blocks`. Passing 0 back into `revert-to-revision { expected_current_revision_id: 0 }` then triggers `revision_stale` (zero is "wildly stale"). The result is that the natural read → write loop fails on revisionless posts. Two clean fixes possible; pick one.

## What

Two acceptable shapes; pick one and apply consistently across **every** ability that emits a `revision_id` field:

### Option A — null

`revision_id: null` when no revisions exist. Downstream `expected_revision_id` / `expected_current_revision_id` accepts `null` as "no precondition" (already the behaviour when the field is omitted).

Pros: explicit "no revision exists yet" signal; easy for clients to switch on.
Cons: requires schema update to `revision_id: { type: [ 'integer', 'null' ] }`; clients with strict typing need updates.

### Option B — post_id-as-pseudo-rev

`revision_id: $post_id` (the post itself is the only revision). Downstream check: if `$revision_id === $post_id`, compare against the post itself.

Pros: integer-valued; existing strict-int clients unchanged.
Cons: ambiguous semantically (a post and a revision are not the same thing); risks subtle bugs when revision_id is treated as "the prior version".

**Recommendation:** Option A. The semantic clarity outweighs the schema bump.

Implementation:

1. Change `RevisionGuard::current_revision_id( int $post_id ): ?int` — return `null` (not `0`) when `wp_get_post_revisions` is empty.
2. Update every caller that propagates the value into a response.
3. Update every caller that *consumes* the value as a precondition (`expected_revision_id` / `expected_current_revision_id`) to accept `null` as "no precondition".
4. Update output schemas to widen the `revision_id` type to `[ 'integer', 'null' ]`.

## Why

The current 0 value is a footgun: it's a valid integer that looks like a real revision ID to downstream code, and the natural `get-page-blocks → update-blocks` chain fails on freshly-created posts. Multiple wave-2 test sequences hit this; agent traces will hit it too.

## Source pattern

No upstream — internal fix. WordPress core itself returns `false` from `wp_get_post_revisions(...)[0]` when no revisions exist; the PHP convention there is "absence == null/false", not "absence == 0".

## Files to modify / create

- **Modify:** `includes/Core/RevisionGuard.php` — change return type and behaviour.
- **Modify:** every ability handler that returns `revision_id` in its response — confirmed list (audit with `rg "'revision_id' =>"`):
  - `BlockAbilities.php` (get-page-blocks, update-blocks, replace-block-range, rewrite-post-blocks, edit-block-tree, insert-pattern, revert-to-revision)
  - any others surfaced by the grep
- **Modify:** every ability handler that *reads* `expected_revision_id` / `expected_current_revision_id` — same audit. Accept `null` (no precondition).
- **Modify:** output_schema entries → `'revision_id' => [ 'type' => [ 'integer', 'null' ] ]`.
- **Modify:** `tests/SdAiAgent/Core/RevisionGuardTest.php` — assert `null` on revisionless post.
- **Modify:** affected ability tests — add a "freshly-created post" case for each.

## Acceptance criteria

1. `get-page-blocks` on a freshly-inserted post (zero `wp_insert_post` calls, no `wp_update_post`) returns `revision_id: null`.
2. `update-blocks { expected_revision: null }` against that post succeeds (no precondition triggered).
3. After one mutation, `get-page-blocks` returns `revision_id: <integer>` again.
4. `revert-to-revision { post_id, revision_id: 218, expected_current_revision_id: null }` succeeds (no precondition).
5. `revert-to-revision { post_id, revision_id: 218, expected_current_revision_id: 0 }` → `revision_stale` (existing behaviour for a real "stale" ID).
6. Every ability output schema that includes `revision_id` allows both `integer` and `null`.
7. PHPStan passes with the widened type.
8. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval --user=admin '
  $pid = wp_insert_post([
    "post_title"   => "freshly created",
    "post_content" => "<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->",
    "post_type"    => "page",
    "post_status"  => "publish",
  ]);
  $r = wp_get_ability("sd-ai-agent/get-page-blocks")->execute(["post_id" => $pid]);
  echo "revision_id: " . var_export($r["revision_id"], true) . " (expect null)" . PHP_EOL;
  wp_delete_post($pid, true);
'
```

## Tier rationale

`tier:standard` — small behavioural change with a tight blast radius and a clear semantic. The audit (callers of `current_revision_id`) is grep-able.

## Dependencies

- **Blocked by:** none.
- **Soft conflict with:** t269 (ETag) emits `W/"rev-N"`. After this lands, `null` revision should emit no `ETag` header (or `ETag: W/"rev-none"` — decide in the t269 PR if both land together).

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #<parent>`.
