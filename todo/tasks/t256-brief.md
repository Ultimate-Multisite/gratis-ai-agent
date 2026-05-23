# t256 — Wave 2.2: `insert_pattern` ability — registered + synced pattern insertion

## Pre-flight

- [x] Memory recall: `insert pattern wp_block synced registered anchor` → 0 hits
- [x] Discovery pass: 0 open PRs touch pattern insertion. `BlockAbilities.php` insert-child op exists but only for single blocks, not pattern-expansion.
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-pattern-manager.php` (657 LOC)
- [x] Tier: `tier:thinking` — pattern resolution + anchor positioning + synced-vs-registered branching + serialisation must round-trip cleanly
- [x] Seeded draft PR decision: skipped — single PR, single feature

## Origin

- **Created:** 2026-05-23
- **Session:** opencode interactive (block-mcp wave-2 adoption)
- **Parent task:** #1739
- **Conversation context:** Wave 2 child 2/10. README example 2 ("Insert a CTA section from a pattern"). Without this, agents either build the pattern inline (loses sync, defeats reusability) or skip patterns entirely.

## What

Add a `sd-ai-agent/insert-pattern` ability:

```json
{
  "post_id": 123,
  "pattern": "core/quote",          // OR numeric ID for synced wp_block
  "anchor": "after_top_level",      // | "before_top_level" | "after_ref" | "before_ref" | "first_child_of_ref"
  "ref": "sd_ref:abc123",            // required when anchor is *_ref / first_child_of_ref
  "expected_revision_id": 999        // optimistic concurrency, same shape as edit-block-tree
}
```

Branching:

- **Registered pattern** (`core/*`, theme-registered, plugin-registered): expand the pattern's `content` server-side via `WP_Block_Patterns_Registry::get_instance()->get_registered( $slug )`, parse into block trees, **inline** them at the anchor.
- **Synced pattern** (`wp_block` post type, numeric ID or `wp-block:N`): insert a single `core/block { "ref": N }` reference at the anchor. Editor renders it transcluded.

Same revision/refs/optimistic-concurrency response shape as `edit-block-tree`.

## Why

Patterns are how WordPress promotes design-system consistency. Without first-class pattern insertion, agent-authored pages drift from the theme's intended look. Synced patterns also let editors update the design centrally — losing that capability silently is worse than not offering it.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-pattern-manager.php` (657 LOC) — handles both registered-pattern expansion and synced-pattern reference insertion. GPL-2.0-or-later.

## Files to modify / create

- **New:** `includes/Core/PatternInserter.php` — `expand_registered( string $slug ): array|WP_Error` (returns parsed block tree); `make_synced_ref( int $wp_block_id ): array` (returns `core/block` reference array); `validate_pattern_exists( $pattern ): true|WP_Error`.
- **Modify:** `includes/Abilities/BlockAbilities.php` — register `sd-ai-agent/insert-pattern` with the anchor-selection schema shared with edit-block-tree's `insert-child` op.
- **Modify:** `includes/Core/BlockMutator.php` — extend the existing anchor-resolution helpers to accept a multi-block payload (today `insert-child` expects a single block; pattern expansion may yield N).
- **New:** `tests/SdAiAgent/Abilities/InsertPatternTest.php`, `tests/SdAiAgent/Core/PatternInserterTest.php`.

## Acceptance criteria

1. Insert registered pattern `core/quote` after top-level → pattern blocks inline at top level, revision created, refs assigned to each new block.
2. Insert synced pattern by ID → single `core/block {"ref": N}` block inserted, NOT inline-expanded.
3. Synced pattern accepts both `pattern: 42` and `pattern: "wp-block:42"` and `pattern: "synced:42"`; reject other shapes with `bad_pattern_id`.
4. Unknown pattern slug → `WP_Error('pattern_not_found', ...)` with the registry list trimmed to 10 nearest matches in `data.suggestions`.
5. `anchor: "first_child_of_ref"` with a non-container target → `WP_Error('not_a_container', ...)`.
6. `expected_revision_id` mismatch → standard `revision_stale` WP_Error (same as `edit-block-tree`).
7. Refs are assigned to every inserted block, including all descendants from a registered-pattern expansion.
8. Tier policy applies to inlined registered-pattern blocks: tier-rejected block names abort the whole insertion (no partial write).
9. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval '
  $r = wp_get_ability("sd-ai-agent/insert-pattern")->execute([
    "post_id" => 156,
    "pattern" => "core/quote",
    "anchor"  => "after_top_level",
  ]);
  echo wp_json_encode($r, JSON_PRETTY_PRINT) . PHP_EOL;
'
```

Verify the post in the editor: quote pattern appears at the end with proper block structure.

## Tier rationale

`tier:thinking` — pattern resolution has two distinct code paths (inline vs reference), anchor positioning shares logic with edit-block-tree, optimistic-concurrency contract must match, and tier policy + ref assignment must extend to expanded sub-trees.

## Dependencies

- **Blocked by:** none.
- **Related:** uses the same `BlockMutator` insert-child plumbing — no schema change; additive.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #1739`.
