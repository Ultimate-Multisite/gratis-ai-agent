# t245 — Tier 1.2: Path/ref/index-addressable block mutator with 9-op vocabulary

## Pre-flight

- [x] Memory recall: `block mutator edit tree wrap unwrap duplicate move` → 0 hits — no prior lessons in this repo
- [x] Discovery pass: 0 open PRs touch `includes/Abilities/BlockAbilities.php` or any `includes/Core/Block*.php` in last 48h
- [x] File refs verified: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-mutator.php` (968 LOC), `class-block-crud.php` (1130 LOC); Superdav `includes/Abilities/BlockAbilities.php:1225`, `includes/Core/BlockValidator.php:534`
- [x] Tier: `tier:thinking` — large mutation surface (9 ops), JSON-schema design, error envelope design
- [x] Seeded draft PR decision: skipped — open PR once op set is implemented + tests pass

## Origin

- **Created:** 2026-05-22
- **Session:** opencode interactive (block-mcp adoption review)
- **Parent task:** none (sibling group t244–t254)
- **Conversation context:** Tier 1 item 2 — surgical block editing primitives.

## What

Add a new ability `sd-ai-agent/edit-block-tree` that mutates a single post's block tree by **path**, **flat_index**, or **`sd_ref`**, supporting nine operations:

1. `update-attrs` — replace/merge a block's `attributes`.
2. `update-html` — replace a block's `innerHTML` (raw).
3. `replace-block` — swap a block (and its descendants) for a new block definition.
4. `remove-block` — delete a block.
5. `wrap-in-group` — wrap a block (or range) inside a new `core/group`.
6. `unwrap-group` — replace a group with its inner blocks.
7. `insert-child` — append a child into an `innerBlocks` array at position N.
8. `duplicate` — clone a block in place at +1 sibling.
9. `move` — relocate a block to a new path.

Plus a `dry_run: true` option that validates without writing.

## Why

Without these, the agent rewrites the entire `post_content` for every edit (current `sd-ai-agent/create-block-content`), which (a) collapses unrelated blocks back to freeform on round-trip, (b) creates one revision per edit cluttering history, and (c) makes multi-turn editing fragile. The 9-op vocabulary is the minimal-but-complete set proven by the block-mcp project to cover real agent workflows.

## Source pattern

Adapt from `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-mutator.php` (968 LOC, GPL-2.0-or-later). The mutator's structure is a single `apply( $blocks, $op, $args )` dispatch with per-op private methods. Each op validates target presence first, mutates, returns the new tree or `WP_Error`.

Address resolution order: if `ref` provided, use `BlockReferences::find_by_ref()`; else if `path` provided, walk indices; else use `flat_index` (depth-first counter from the upstream reader).

## Files to modify / create

- **New:** `includes/Core/BlockMutator.php` — pure-function tree transforms; no WP DB writes.
- **New:** `includes/Core/BlockTreeAddress.php` — resolves `{ref|path|flat_index}` → `(parent, index)`.
- **Modify:** `includes/Abilities/BlockAbilities.php` — register `sd-ai-agent/edit-block-tree` with full JSON schema in the ability definition.
- **Modify:** `includes/REST/RestController.php` — new route or extend existing for the write surface (uses `wp_kses_post` on `innerHTML`).
- **New:** `tests/SdAiAgent/Core/BlockMutatorTest.php` — one describe-block per op, happy + error paths.
- **New:** `tests/SdAiAgent/Abilities/EditBlockTreeAbilityTest.php` — REST/ability surface coverage.

## Acceptance criteria

1. All 9 ops implemented; each addressable by `ref`, `path`, **or** `flat_index`.
2. `wp_kses_post` runs on any supplied `innerHTML` (defence-in-depth — `<script>` and inline event handlers stripped).
3. `move` rejects cycles (cannot move a block into its own descendant tree) with `invalid_destination`.
4. `unwrap-group` rejects blocks with no `innerBlocks` (`no_inner_blocks`).
5. `wrap-in-group` accepts an optional `attributes` object for the new wrapper.
6. `duplicate` JSON-clones via `wp_json_encode` + `json_decode( …, true )` — fails closed on resources/invalid UTF-8 (`duplicate_failed`).
7. `dry_run: true` returns the would-be tree but does not persist.
8. Error envelope matches WP convention: `{ code, message, data: { status, … } }`.
9. PHPUnit: each op has ≥ 3 tests (happy, missing target, type-mismatch); full suite passes.
10. PHPStan: zero errors at current level.

## Verification

`npm run verify`. Tests live under `tests/SdAiAgent/Core/` and `tests/SdAiAgent/Abilities/`.

Manual:

```bash
wp eval 'print_r( wp_get_ability( "sd-ai-agent/edit-block-tree" )->execute( ["post_id"=>1, "op"=>"update-attrs", "ref"=>"blk_xxxxxxxx", "attributes"=>["level"=>3]] ) );'
```

## Tier rationale

`tier:thinking`. 9 ops × 3 addressing modes = 27 code paths; schema design + cycle detection + transactional semantics are judgment work.

## Dependencies

- **Blocked by:** t244 (refs must exist for ref-addressing).
- **Pairs with:** t248 (auto-transforms run after `update-attrs`).
- **Blocks:** t246 (batch update wraps single-op mutator), t250 (dual-storage check runs inside mutator).

## PR conventions

Leaf — `Resolves #<this-issue>`.
