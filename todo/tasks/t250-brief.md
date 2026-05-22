# t250 — Tier 2.7: Dual-storage block detection + enforcement

## Pre-flight

- [x] Memory recall: `yoast faq dual storage block innerhtml` → 0 hits
- [x] Discovery pass: 0 open PRs touch BlockAbilities or new BlockMutator paths
- [x] File refs verified: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-inventory.php:108-170` (`is_block_dual_storage`), `class-block-crud.php:1045-1080` (`dual_storage_error`)
- [x] Tier: `tier:standard` — small surface, well-defined list, clear error envelope
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-22
- **Session:** opencode interactive (block-mcp adoption review)
- **Parent task:** none (sibling group t244–t254)
- **Conversation context:** Tier 2 item 3 — prevent silent corruption of blocks that duplicate state across `attributes` and `innerHTML`.

## What

Maintain a list of block names that store the same data in **both** `attributes` **and** `innerHTML` (notably `yoast/faq-block`, `yoast/how-to-block`). Force any `update-attrs` or `update-html` op on such a block to supply **both** sides; reject one-sided updates with `dual_storage_requires_both`.

Includes:

1. A starter list (hard-coded + filterable): `yoast/faq-block`, `yoast/how-to-block` plus any others discoverable.
2. A `filter`-based extension hook: `sd_ai_agent_block_dual_storage_blocks` (array of block names).
3. (Stretch) A scan helper that walks published posts, parses each block, and detects blocks where attribute string values also appear in `innerHTML` — caches result in an option for the settings UI.

## Why

These blocks corrupt silently when half-updated: the editor reads `attributes.questions[0].answer` first, but the rendered preview uses the innerHTML copy. Updating one without the other leaves a permanently inconsistent block until manually fixed in the editor.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-inventory.php:108-170`:

```php
public function is_block_dual_storage( $block_name ) { … }
```

And `class-block-crud.php:1045-1080` for the error envelope:

```
{ code: 'dual_storage_requires_both', message: …, data: { block_name, status: 400 } }
```

## Files to modify / create

- **New:** `includes/Core/DualStorageRegistry.php` — owns the list + filter + (stretch) scan.
- **Modify:** `includes/Core/BlockMutator.php` (t245) — invoke check inside `update-attrs` and `update-html` before applying.
- **New:** `tests/SdAiAgent/Core/DualStorageRegistryTest.php` — list contents, filter override, scan helper.
- **Modify:** existing mutator tests — add dual-storage rejection path.

## Acceptance criteria

1. `update-attrs` on `yoast/faq-block` without `innerHTML` returns HTTP 400 `dual_storage_requires_both` with `data.block_name`.
2. `update-html` on `yoast/faq-block` without `attributes` returns the same error.
3. Combined `{ attributes, innerHTML }` update succeeds.
4. `update-attrs` on a non-dual-storage block (e.g. `core/heading`) is unaffected.
5. Filter `sd_ai_agent_block_dual_storage_blocks` lets a sibling plugin add a block name and triggers the same enforcement.
6. (Stretch) Scan helper, when run, populates a cached option of detected dual-storage blocks distinct from the hard-coded list.
7. Tests cover all paths; full suite green.

## Verification

`npm run verify`. Plus manual confirmation on a site with Yoast SEO active:

```bash
wp eval '
  $r = wp_get_ability( "sd-ai-agent/edit-block-tree" )->execute([
    "post_id" => 1, "op" => "update-attrs",
    "ref" => "blk_yoastfaq", "attributes" => ["questions" => […]]
  ]);
  var_dump( $r );  // expect WP_Error code dual_storage_requires_both
'
```

## Tier rationale

`tier:standard`. Small, focused class; clear list; one new error code; integration is a single call inside the mutator.

## Dependencies

- **Blocked by:** t245 (mutator is the enforcement point).

## PR conventions

Leaf — `Resolves #<this-issue>`.
