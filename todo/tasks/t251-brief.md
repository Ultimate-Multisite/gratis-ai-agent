# t251 — Tier 2.8: Block depth cap + structural caps

## Pre-flight

- [x] Memory recall: `block depth limit recursion php stack` → 0 hits
- [x] Discovery pass: 0 open PRs touch block traversal paths
- [x] File refs verified: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-crud.php:49 (MAX_BATCH_SIZE=50), :70 (MAX_BLOCK_DEPTH=32), :522-573 (validate_tree_depth)`
- [x] Tier: `tier:simple` — two constants, one validator, integration into existing classes
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-22
- **Session:** opencode interactive (block-mcp adoption review)
- **Parent task:** none (sibling group t244–t254)
- **Conversation context:** Tier 2 item 4 — defensive structural caps that prevent stack overflow and runaway batches.

## What

Add two constants and the validator that enforces them across the new block-mutation surface:

- `MAX_BLOCK_DEPTH = 32` — recursive block nesting cap.
- `MAX_BATCH_SIZE = 50` — `update_blocks` batch limit (already used by t246 — formalise here).

Plus a `validate_tree_depth( array $blocks, int $depth = 0 ): true|WP_Error` helper that returns HTTP 400 `block_depth_exceeded` when violated.

## Why

WordPress core has no built-in depth cap on `post_content` blocks; a malformed (or malicious) write can recursively nest a tree that stack-overflows the read-side walker. 32 is the upstream-proven limit and well above any realistic editor-produced tree.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-crud.php:49,70,522-573`:

```php
const MAX_BATCH_SIZE = 50;
const MAX_BLOCK_DEPTH = 32;

public function validate_tree_depth( array $blocks, int $depth_so_far = 0 ): int|true { … }
```

## Files to modify / create

- **Modify:** `includes/Core/BlockMutator.php` (t245) — add the two constants as `public const`, plus the validator method.
- **Modify:** `includes/Core/BlockReferences.php` (t244) — call `validate_tree_depth` before recursive walks (defensive early-exit; refs walker bails at depth + 1).
- **Modify:** `includes/Abilities/BlockAbilities.php` — `update-blocks` ability schema rejects `updates` arrays > 50 items at ability-validation time.
- **New:** `tests/SdAiAgent/Core/BlockDepthTest.php` — depth-exact, depth+1 rejection, deeply-valid tree passes, batch size cap.

## Acceptance criteria

1. A block tree exactly 32 levels deep is accepted.
2. A tree 33 levels deep returns HTTP 400 `block_depth_exceeded` with `data.max_depth = 32`.
3. `update_blocks` with `updates.length > 50` returns HTTP 400 `batch_too_large` with `data.max_batch_size = 50`.
4. Constants are publicly accessible (`BlockMutator::MAX_BLOCK_DEPTH`) so other classes can reference them rather than literals.
5. Read paths short-circuit at depth + 1 (don't recurse forever even if a malformed tree slips in).
6. Tests cover all four paths; full suite green.

## Verification

`npm run verify`. The deep-tree fixture is a recursive group nested 33 deep.

## Tier rationale

`tier:simple`. Two `const` declarations, one ~20-LOC validator, three call-site additions, two test cases. Mechanical.

## Dependencies

- **Blocked by:** t244 + t245 (both need to call the new validator).
- Can land in the same PR as t245 if convenient.

## PR conventions

Leaf — `Resolves #<this-issue>`.
