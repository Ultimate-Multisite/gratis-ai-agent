# t261 — Wave 2.7: `replace_block_range` ability — atomic N-for-M swap

## Pre-flight

- [x] Memory recall: `replace block range swap section atomic` → 0 hits
- [x] Discovery pass: 0 open PRs. `edit-block-tree` + `update-blocks` cover the underlying mechanics; this is a dedicated tool surface.
- [x] File refs verified — `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-mutator.php` (968 LOC, includes range ops); Superdav: `includes/Core/BlockMutator.php` (1382 LOC)
- [x] Tier: `tier:thinking` — atomic N→M swap with refs preserved on survivors, revision integrity, refs assigned to new blocks
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-23
- **Session:** opencode interactive (block-mcp wave-2 adoption)
- **Parent task:** #1739
- **Conversation context:** Wave 2 child 7/10. Common UX: "rewrite this section". Combinable today from `update-blocks` (remove N, then insert M after the previous sibling) but two-tool sequencing breaks the atomic-revision guarantee. A dedicated tool is shorter and safer.

## What

Add a `sd-ai-agent/replace-block-range` ability:

```json
{
  "post_id": 123,
  "start_ref": "sd_ref:aaa",
  "end_ref": "sd_ref:zzz",        // inclusive; must be sibling of start_ref at the same depth
  "new_blocks": [ { "name": "core/heading", "attrs": {...}, "innerBlocks": [] }, ... ],
  "expected_revision_id": 999
}
```

Behaviour:

1. Resolve `start_ref` and `end_ref` to (path, parent_path, position) tuples. Reject with `not_siblings` if their parent paths differ.
2. Reject if `end_ref` comes before `start_ref` in document order (`bad_range`).
3. Reject if the range exceeds 200 blocks (`range_too_large`) — guard against accidental whole-post wipes.
4. Validate `new_blocks` (≤200, depth ≤32, tier policy, no bound-attribute writes from t259) before any write.
5. In one revision: remove `[start..end]` inclusive, insert `new_blocks` at the start position.
6. Refs preserved on survivors outside the range; new blocks get fresh `sd_ref` values.
7. Same atomic semantics as `update-blocks`: all-or-nothing inside one revision.

Returns standard `{ post_id, revision_id, refs_added, refs_removed, refs_preserved, block_count }`.

## Why

A common authoring step is "rewrite this section" — e.g. replace the 5 blocks between two headings with 8 new blocks. Composing this from `update-blocks` requires:

1. Snapshot current blocks (read).
2. Compute removals + insertion-after (write).

…and racing edits between the two calls break the assumption. Single tool, single revision = no race.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-mutator.php` (range-ops section, around L600-800). GPL-2.0-or-later.

## Files to modify / create

- **Modify:** `includes/Core/BlockMutator.php` — add `replace_range( $post_id, $start_ref, $end_ref, $new_blocks, $expected_revision, $allow_bound_writes ): array|WP_Error`. Reuse the existing path-resolution + serialise + revision helpers.
- **Modify:** `includes/Abilities/BlockAbilities.php` — register `sd-ai-agent/replace-block-range` ability.
- **New:** `tests/SdAiAgent/Core/BlockMutator/ReplaceRangeTest.php` — happy path 3→5 swap, single-block range (start == end), refs preserved on survivors, not-siblings rejection, bad-range rejection, oversized range rejection, depth-cap violation in new_blocks (must reject pre-write), bound-attribute violation in new_blocks, atomic semantics on validation failure.

## Acceptance criteria

1. Replace 3 sibling paragraphs with 1 heading + 4 paragraphs → revision created, `refs_added: 5`, `refs_removed: 3`, blocks outside the range keep their refs.
2. `end_ref` not a sibling of `start_ref` → `WP_Error('not_siblings', ...)`.
3. `end_ref` document-order before `start_ref` → `WP_Error('bad_range', ...)`.
4. Range > 200 blocks → `WP_Error('range_too_large', ...)`.
5. `new_blocks` violates depth cap → `WP_Error('depth_exceeded', ...)`, NO write occurs (validation pre-write).
6. `new_blocks` writes to a bound attribute (no override) → `WP_Error('bound_attribute', ...)`, NO write occurs.
7. Stale `expected_revision_id` → `WP_Error('revision_stale', ...)`.
8. Same-ref `start_ref == end_ref` → succeeds (range of 1).
9. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval '
  $r = wp_get_ability("sd-ai-agent/replace-block-range")->execute([
    "post_id"    => 156,
    "start_ref"  => "sd_ref:<para-1>",
    "end_ref"    => "sd_ref:<para-3>",
    "new_blocks" => [
      [ "name" => "core/heading", "attrs" => [ "level" => 2, "content" => "Replaced" ], "innerBlocks" => [] ],
    ],
  ]);
  echo wp_json_encode($r) . PHP_EOL;
'
```

## Tier rationale

`tier:thinking` — range semantics, atomic-revision discipline, ref preservation contract, depth/tier/bindings validation pre-write, and rate-limit integration with t264.

## Dependencies

- **Blocked by:** none — independent of t259 (bindings) but interacts; if t259 lands first, `new_blocks` validation must include the bound-attribute check.
- **Related:** t259, t264 (rate-limit bucket).

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #1739`.
