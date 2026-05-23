# t259 — Wave 2.5: Block Bindings API write-lock + read surfacing

## Pre-flight

- [x] Memory recall: `block bindings metadata bound_attribute wp 6.5` → 0 hits
- [x] Discovery pass: 0 open PRs touch bindings. Wave-1 wrote refs to `attrs.metadata.gk_ref` (now `sd_ref`); bindings live at `attrs.metadata.bindings` — same parent key.
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-writer.php:816-840` (write-lock), `class-block-reader.php:403-410` (read surface)
- [x] Tier: `tier:thinking` — touches every write path; must NOT corrupt the `metadata` key that also stores `sd_ref`
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-23
- **Session:** opencode interactive (block-mcp wave-2 adoption)
- **Parent task:** #1739
- **Conversation context:** Wave 2 child 5/10. WP 6.5+ Block Bindings makes attributes dynamic (sourced from meta, options, custom callbacks). Writing the bound attribute directly is a silent data-loss bug — the editor will re-derive the value on next render and the agent's write disappears.

## What

**Read side** (`get-page-blocks`): for every block whose `attrs.metadata.bindings` is non-empty, include in the response:

```json
{
  "ref": "sd_ref:abc",
  "name": "core/paragraph",
  "bindings": { "content": { "source": "core/post-meta", "args": { "key": "subtitle" } } },
  "bound_attributes": ["content"]
}
```

**Write side** (every mutating ability — `edit-block-tree`, `update-blocks` atomic batch, `update-post`, `replace_block_range`, `rewrite_post_blocks`, future ops): when an update's `attrs` would change a key listed in the target block's `attrs.metadata.bindings`, **reject** with:

```php
new WP_Error( 'bound_attribute', __( 'Attribute is bound and cannot be written directly.', 'superdav-ai-agent' ), [
  'block_ref'        => $ref,
  'bound_attributes' => [ 'content' ],
] );
```

…UNLESS the request includes `"allow_bound_writes": true` as an explicit override (advanced agents, full responsibility).

The `metadata` key itself is special: writing `attrs.metadata` is allowed (it carries our `sd_ref` + the bindings registration). The write-lock is only on keys that are *listed inside* `metadata.bindings`.

## Why

Without the lock, the next time the editor renders the block it re-runs the binding source and silently overwrites the agent's write. Worse, the agent thinks the write succeeded (no error returned). This is the kind of silent failure that destroys trust in the tool surface.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-writer.php:816-840` — write-side lock. `class-block-reader.php:403-410` — read-side surface. GPL-2.0-or-later.

## Files to modify / create

- **Modify:** `includes/Core/BlockMutator.php` — add `assert_no_bound_attribute_writes( array $block, array $new_attrs, bool $allow_bound_writes ): true|WP_Error` and call it from every per-op handler (update-attrs, replace-block, update-html when the resulting parse changes bound keys, etc.). Wire `$allow_bound_writes` through the ability signatures.
- **Modify:** `includes/Core/BlockReferences.php` (or wherever the read serialiser lives) — when emitting a block dict, populate `bindings` and `bound_attributes` from `attrs.metadata.bindings`.
- **Modify:** `includes/Abilities/BlockAbilities.php` — extend the JSON schema for every write op with optional `allow_bound_writes: { type: boolean, default: false }`.
- **New:** `tests/SdAiAgent/Core/BlockMutator/BindingsWriteLockTest.php` — covers default-locked write, `allow_bound_writes: true` override, metadata-key-write allowed, atomic-batch partial bound rejection, replace-block re-parse detection.
- **New:** `tests/SdAiAgent/Core/BlockReferences/BindingsReadSurfaceTest.php`.

## Acceptance criteria

1. Block has `bindings: { content: { source: "core/post-meta", args: { key: "subtitle" } } }`. `edit-block-tree` op `update-attrs { content: "new" }` → `WP_Error('bound_attribute')` with `data.bound_attributes: ["content"]`.
2. Same op with `allow_bound_writes: true` → succeeds.
3. `update-attrs { className: "x" }` (non-bound key) → succeeds regardless.
4. `update-attrs { metadata: { bindings: {...}, sd_ref: "..." } }` → succeeds (writing metadata itself is allowed; bindings array is just data here).
5. `replace-block` whose new block lacks the binding declaration but writes to the same key → allowed (replacement removed the binding).
6. Atomic batch with one bound-attribute violation → entire batch rejects with per-item errors (no partial write).
7. `get-page-blocks` response includes `bindings` and `bound_attributes` arrays on bound blocks; absent on unbound.
8. WP < 6.5: feature degrades to read-side surfacing only (write-lock is a no-op since core doesn't enforce bindings); tests guarded by `version_compare( get_bloginfo('version'), '6.5', '>=' )`.
9. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
# Manually attach a binding to a block, then attempt a write.
wp eval '
  // Set up: post 156, paragraph block with content bound to post meta "subtitle".
  $r = wp_get_ability("sd-ai-agent/edit-block-tree")->execute([
    "post_id" => 156,
    "ref"     => "sd_ref:<the-bound-paragraph>",
    "op"      => "update-attrs",
    "attrs"   => [ "content" => "agent overwrite" ],
  ]);
  echo wp_json_encode($r) . PHP_EOL;   // expect bound_attribute error
'
```

## Tier rationale

`tier:thinking` — touches every write path, must not corrupt the existing `metadata.sd_ref` ref system, has interactions with atomic-batch all-or-nothing semantics, requires both read-side and write-side coverage, and WP-version-gated behaviour.

## Dependencies

- **Blocked by:** none.
- **Coordinates with:** wave-1 ref system (refs and bindings share the `metadata` key — must not collide).

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #1739`. Label `security` (silent-overwrite class).
