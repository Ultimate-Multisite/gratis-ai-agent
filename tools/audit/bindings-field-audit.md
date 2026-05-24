# t275 Audit: Bindings field shape across read abilities

## Audit date

2026-05-24

## Pre-fix matrix

| Read path | Ability | Returns block dict? | `bindings` field | `bound_attributes` field | Shape notes |
|---|---|---|---|---|---|
| `get-page-blocks` (nested) | `sd-ai-agent/get-page-blocks` | Yes | Conditional (only when non-empty) | Conditional (only when non-empty) | `flatten_blocks_for_response()` lines 2409-2413 |
| `get-page-blocks` (flat DFS) | `sd-ai-agent/get-page-blocks` | Yes | Conditional (only when non-empty) | Conditional (only when non-empty) | `flatten_block_tree()` lines 2579-2583 |
| `parse-block-content` | `sd-ai-agent/parse-block-content` | Yes | MISSING | MISSING | `clean_parsed_blocks()` omitted bindings |
| `edit-block-tree` (dry-run) | `sd-ai-agent/edit-block-tree` | Yes (raw `block_tree`) | MISSING | MISSING | Raw `BlockMutator::apply()` output |
| `update-blocks` (dry-run) | `sd-ai-agent/update-blocks` | Yes (raw `block_tree`) | MISSING | MISSING | Raw `BlockMutator::apply_batch()` output |
| `insert-pattern` | `sd-ai-agent/insert-pattern` | Yes (raw `block_tree`) | MISSING | MISSING | Raw block tree after ref assignment |
| `validate-block-content` | `sd-ai-agent/validate-block-content` | No (validation results) | N/A | N/A | Returns `results[]` with `blockName`, `isValid`, etc. |
| `review-block` | `sd-ai-agent/review-block` | No (AI suggestions) | N/A | N/A | Returns `suggestions[]` — not a block dict emitter |
| `get-block-type` | `sd-ai-agent/get-block-type` | No (type definition) | N/A | N/A | Returns block type metadata, not parsed block instances |
| `rewrite-post-blocks` | `sd-ai-agent/rewrite-post-blocks` | No (summary stats) | N/A | N/A | Returns `block_count`, `refs_count` — no `block_tree` |
| `replace-block-range` | `sd-ai-agent/replace-block-range` | No (summary stats) | N/A | N/A | Returns `refs_added/removed/preserved` — no `block_tree` |
| `revert-to-revision` | `sd-ai-agent/revert-to-revision` | No (summary stats) | N/A | N/A | Returns `refs_reseeded`, `block_count` — no `block_tree` |

## Canonical shape (post-fix)

```json
{
  "ref": "blk_xyz",
  "name": "core/paragraph",
  "attributes": { "..." : "..." },
  "bindings": {
    "content": { "source": "core/post-meta", "args": { "key": "subtitle" } }
  },
  "bound_attributes": ["content"]
}
```

When a block has **no bindings**:

```json
{
  "ref": "blk_abc",
  "name": "core/paragraph",
  "attributes": { "..." : "..." },
  "bindings": null,
  "bound_attributes": []
}
```

### Rules

- `bindings` is always present: `object` (the `attrs.metadata.bindings` map) when bound, `null` when unbound.
- `bound_attributes` is always present: `string[]` (the attribute keys listed in `bindings`) when bound, `[]` when unbound.
- Neither field is ever absent from a block dict.

## Changes made

### `BlockAbilities.php`

1. **`flatten_blocks_for_response()`** (get-page-blocks nested mode): Changed from conditional `if (!empty($bindings))` to always-emit with `null`/`[]` fallback.

2. **`flatten_block_tree()`** (get-page-blocks flat DFS mode): Same conditional-to-always change.

3. **`clean_parsed_blocks()`** (parse-block-content): Added bindings extraction from `attrs.metadata.bindings` with canonical `null`/`[]` shape.

4. **`annotate_bindings_tree()`** (new helper): Recursively walks a raw block tree and adds `bindings`/`bound_attributes` to every named block. Applied to:
   - `handle_edit_block_tree()` — `block_tree` return value
   - `handle_update_blocks()` — `block_tree` return value
   - `handle_insert_pattern()` — `block_tree` return value

5. **Ability descriptions**: Updated `get-page-blocks`, `parse-block-content`, `edit-block-tree`, and `update-blocks` descriptions to document the fields and reference `allow_bound_writes`.

### `BindingsReadSurfaceTest.php`

- Updated `test_unbound_block_has_no_bindings_in_response` → `test_unbound_block_has_null_bindings_in_response` to assert `null`/`[]` instead of absent keys.

### `BindingsFieldConsistencyTest.php` (new)

- Regression guard covering 5 read paths: get-page-blocks (nested), get-page-blocks (flat DFS), parse-block-content, edit-block-tree dry-run, update-blocks dry-run.
- Each path tested with both bound and unbound blocks.
- `annotate_bindings_tree()` unit test.

## Verification

```bash
npm run verify   # lint -> phpstan -> test:php -> build
```
