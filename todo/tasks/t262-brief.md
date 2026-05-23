# t262 — Wave 2.8: `rewrite_post_blocks` ability — full-page replace

## Pre-flight

- [x] Memory recall: `rewrite_post_blocks full page replace bucket 2/min` → 0 hits
- [x] Discovery pass: `update-post` already accepts a `content` field which effectively does this; the new tool is **rate-limited differently** and explicit about intent
- [x] File refs verified — block-mcp source: writer's bulk-replace path (~`class-block-writer.php` L1300-1500); Superdav: existing `update-post` in `WordPressAbilities.php` / `BlockAbilities.php` content-write handlers
- [x] Tier: `tier:thinking` — full-content replacement, separate rate-limit bucket, must coordinate with t264
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-23
- **Session:** opencode interactive (block-mcp wave-2 adoption)
- **Parent task:** #1739
- **Conversation context:** Wave 2 child 8/10. `update-post` with full content works today but agents shouldn't lean on it casually — full rewrites are expensive (every ref reseeds, every revision balloons), and a separate rate-limit bucket (2/min vs the standard 10/min) signals "use sparingly".

## What

Add a `sd-ai-agent/rewrite-post-blocks` ability:

```json
{
  "post_id": 123,
  "blocks": [ { "name": "core/heading", "attrs": {...}, "innerBlocks": [] }, ... ],
  "expected_revision_id": 999
}
```

Behaviour:

1. Validate `blocks` (≤200 top-level, depth ≤32, tier policy, no bound-attribute violations).
2. Optimistic concurrency on `expected_revision_id`.
3. Serialise via `serialize_blocks` and `wp_update_post`.
4. Reseed all refs (refs are content-derived; every block in the new payload gets a fresh `sd_ref`).
5. Rate limit: counts against the **`rewrite` bucket** (2/min/post — see t264), separate from the `write` bucket (10/min/post).

Returns the standard write response (`revision_id`, refs counts, block_count).

## Why

Two reasons for a dedicated tool over the existing `update-post`-with-content path:

1. **Intent clarity**: when an agent calls `rewrite-post-blocks`, the schema explicitly says "I am replacing the entire body". Reviewers and audit logs can flag full rewrites distinctly from targeted edits.
2. **Separate rate-limit bucket**: full rewrites are 5× the cost of a targeted op. A 2/min bucket prevents runaway agent loops from churning a post into oblivion. The standard 10/min `write` bucket isn't tight enough.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-writer.php` L1300-1500 region (full-content replace path with the `rewrite` rate bucket). GPL-2.0-or-later.

## Files to modify / create

- **Modify:** `includes/Core/BlockMutator.php` — add `rewrite_post_blocks( $post_id, array $blocks, $expected_revision, $allow_bound_writes ): array|WP_Error`. Will share validation helpers with `update-blocks` atomic batch.
- **Modify:** `includes/Abilities/BlockAbilities.php` — register `sd-ai-agent/rewrite-post-blocks` ability.
- **Coordinate with t264** — invoke the new `RateLimiter` with bucket `rewrite`. Until t264 lands, gate the bucket-check behind `if ( class_exists( RateLimiter::class ) )` so the ability ships safely before rate limiting does.
- **New:** `tests/SdAiAgent/Core/BlockMutator/RewritePostBlocksTest.php` — happy path (200 blocks), depth violation, tier violation, bound-attribute violation, ref reseeding count, oversized payload (>200 top-level → reject), empty `blocks` array (rejects with `empty_payload`), optimistic-concurrency mismatch.

## Acceptance criteria

1. Rewrite a post with 50 blocks → revision created, all 50 blocks have fresh `sd_ref`, no leftover refs.
2. Empty `blocks: []` → `WP_Error('empty_payload', ...)` — a deliberate zero-content post should use `update-post { content: "" }` instead.
3. `blocks` count > 200 top-level → `WP_Error('payload_too_large', ...)`.
4. Depth > 32 → `WP_Error('depth_exceeded', ...)`.
5. Tier policy violation → `WP_Error('tier_disallowed', ...)`.
6. Bound attribute write without override → `WP_Error('bound_attribute', ...)`.
7. Once t264 lands: 3rd call within 60s for the same post → HTTP 429 `rate_limit_exceeded` with `data.bucket: "rewrite"` and `data.retry_after_seconds`.
8. Stale `expected_revision_id` → `WP_Error('revision_stale', ...)`.
9. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval '
  $r = wp_get_ability("sd-ai-agent/rewrite-post-blocks")->execute([
    "post_id" => 156,
    "blocks"  => [
      [ "name" => "core/heading", "attrs" => [ "level" => 1, "content" => "Fresh start" ], "innerBlocks" => [] ],
      [ "name" => "core/paragraph", "attrs" => [ "content" => "New content." ], "innerBlocks" => [] ],
    ],
  ]);
  echo wp_json_encode($r) . PHP_EOL;
'
```

## Tier rationale

`tier:thinking` — full-content replacement, requires sound interaction with the bindings lock + tier policy + depth cap + rate limit, ref-reseeding correctness.

## Dependencies

- **Blocked by:** none. **Soft dependency** on t264 for the dedicated `rewrite` rate bucket — ship guarded if t264 hasn't landed.
- **Related:** t259, t261, t264.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #1739`.
