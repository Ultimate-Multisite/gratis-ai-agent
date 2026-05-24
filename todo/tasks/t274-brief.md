# t274 — Wave 3.10: Surface `innerBlocks` refs in `get-page-blocks` flat array

## Pre-flight

- [x] Memory recall: `get-page-blocks flat blocks innerBlocks ref nested` → noted during wave-2 t259/t261 verification; nested blocks have refs internally but `get-page-blocks` only returns refs for top-level blocks in the flat array.
- [x] Discovery pass: 0 PRs touch this code path. The flat array currently includes `path` and `flat_index` for top-level blocks; descendants are buried under `innerBlocks` of the parent.
- [x] File refs verified — Superdav existing: `includes/Abilities/BlockAbilities.php` (get-page-blocks serialiser), `includes/Core/BlockReferences.php`.
- [x] Tier: `tier:standard` — flatten the serialised output without changing the underlying ref model.
- [x] Seeded draft PR decision: skipped.

## Origin

- **Created:** 2026-05-24
- **Session:** opencode interactive (block-mcp wave-3 adoption)
- **Parent task:** TBD
- **Conversation context:** Wave 3 child 10/11. During wave-2 t261 verification (testing depth-mismatch rejection on `replace-block-range`), I could not address inner blocks because `get-page-blocks` only includes refs for top-level blocks in the flat `blocks` array. Workers either have to recurse `innerBlocks` themselves or call a separate endpoint. Block-mcp surfaces every ref in one flat list via the optional `flat` mode; we should too.

## What

Extend `sd-ai-agent/get-page-blocks` with a new optional input field:

```json
{ "post_id": 156, "include_inner_blocks": true }
```

Default `false` (no behaviour change). When `true`, the response's `blocks` array becomes a depth-first flattened list including every nested block, each with:

```json
{
  "flat_index":   3,           // depth-first ordinal
  "path":         [0, 1, 0],   // index path from root
  "depth":        2,           // 0 for top-level, increments per innerBlocks dive
  "parent_ref":   "blk_root",  // null for top-level
  "name":         "core/paragraph",
  "ref":          "blk_xyz",
  "text_preview": "first para inside group",
  "attributes":   { /* ... */ }
}
```

`innerBlocks` is omitted from the flat output (because each child appears as its own entry). When `include_inner_blocks: false` (default), the existing nested shape is preserved exactly.

Edge cases:
- `path` and `flat_index` are still emitted for top-level blocks under the legacy mode.
- `depth` is new even in legacy mode (always `0`); harmless to existing clients.
- `parent_ref` is new even in legacy mode (always `null` for top-level); harmless.

## Why

Wave-2 verification proved this: I couldn't write a `replace-block-range` test that targets refs of different depths without manually constructing the input post and recursing the tree in my test harness. Workers ported from block-mcp's stress / security suite (t270, t271) will hit the same wall.

For agents: many block-tree tasks — "find every paragraph nested inside a group", "convert all inner H3s to H2s", "remove every inner block matching X" — are awkward without the flat list. Today they require client-side recursion of the response.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-reader.php` — block-mcp returns a flat list by default and offers a tree mode. We're doing the inverse (tree by default, flat opt-in) to preserve backward compatibility. GPL-2.0-or-later.

## Files to modify / create

- **Modify:** `includes/Abilities/BlockAbilities.php`:
  - Extend `get-page-blocks` `input_schema` with `include_inner_blocks: { type: boolean, default: false }`.
  - In `handle_get_page_blocks`, after parsing, branch: legacy path (existing recursive serialiser) vs flat path (new recursive flattener).
  - Add a private static helper `flatten_block_tree( array $tree, int $start_depth = 0, ?string $parent_ref = null ): array` that walks DFS and emits the flat entries.
  - Always emit `depth` and `parent_ref` in both modes (set to `0` / `null` for top-level when nested mode is in use).
- **Modify:** `tests/SdAiAgent/Abilities/BlockAbilitiesTest.php` (or the dedicated `GetPageBlocksTest` file):
  - Default mode unchanged (regression test).
  - `include_inner_blocks: true` on a 3-level tree returns DFS-ordered flat list.
  - Each flat entry has correct `depth`, `path`, `parent_ref`, `flat_index`.
  - The first entry has `depth: 0`, `parent_ref: null`.
- **Document:** mention the new field in the ability `description` (the long-form one, shown to agents at tool discovery).

## Acceptance criteria

1. `get-page-blocks { post_id: P }` (no new flag) returns the existing nested shape exactly (regression).
2. `get-page-blocks { post_id: P, include_inner_blocks: true }` on a post with `<!-- wp:group --><!-- wp:paragraph --><!-- /wp:paragraph --><!-- /wp:group -->` returns 2 entries (group then paragraph), in that order, with the paragraph carrying `depth: 1` and `parent_ref` equal to the group's ref.
3. `flat_index` is sequential DFS order; matches the existing top-level numbering when no nesting is present.
4. Every flat entry has a non-null `ref` (i.e. `assign_refs` runs on the entire tree, not just top-level).
5. `path` matches the index path needed to reach the block under `mutate { op: …, path: [...] }`.
6. Memory/perf: scanning a post with 200 blocks completes within 100 ms on the dev workstation.
7. `update-blocks { ref: <a-nested-ref> }` succeeds, proving the surfaced refs round-trip into the write path.
8. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval --user=admin '
  $id = wp_insert_post([
    "post_title"   => "nested",
    "post_content" => "<!-- wp:group --><div class=\"wp-block-group\"><!-- wp:paragraph --><p>inside</p><!-- /wp:paragraph --></div><!-- /wp:group -->\n\n<!-- wp:paragraph --><p>outside</p><!-- /wp:paragraph -->",
    "post_type"    => "page",
    "post_status"  => "publish",
  ]);

  $r = wp_get_ability("sd-ai-agent/get-page-blocks")->execute([
    "post_id" => $id, "include_inner_blocks" => true,
  ]);
  foreach ($r["blocks"] as $b) {
    echo str_repeat("  ", $b["depth"]) . "[$b[flat_index]] $b[name] ref=$b[ref] parent=" . ($b["parent_ref"] ?? "null") . PHP_EOL;
  }
  wp_delete_post($id, true);
'
```

Expected output:

```
[0] core/group ref=blk_X parent=null
  [1] core/paragraph ref=blk_Y parent=blk_X
[2] core/paragraph ref=blk_Z parent=null
```

## Tier rationale

`tier:standard` — the flattener is a textbook recursion; the only nuance is preserving backward compatibility for the existing nested response.

## Dependencies

- **Blocked by:** none.
- **Soft dependency:** unlocks more rigorous stress (t270) and security (t271) test coverage.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #<parent>`.
