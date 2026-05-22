# t248 — Tier 2.5: Auto-transforms (attribute ↔ innerHTML sync)

## Pre-flight

- [x] Memory recall: `wordpress block innerhtml attribute sync transform` → 0 hits
- [x] Discovery pass: 0 open PRs touch block validator/policy paths
- [x] File refs verified: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-html-transformer.php` (475 LOC); Superdav `includes/Core/BlockValidator.php:534`, `includes/Core/BlockContentPolicy.php:149`
- [x] Tier: `tier:thinking` — pattern-match across many block types; correctness matters
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-22
- **Session:** opencode interactive (block-mcp adoption review)
- **Parent task:** none (sibling group t244–t254)
- **Conversation context:** Tier 2 item 1 — keep declared attributes and rendered HTML in sync server-side so agents don't have to send both.

## What

Add a server-side transformer that, when a write changes specific attributes on a static block, updates the block's `innerHTML` to stay consistent — preventing the "comment marker says `level: 2` but inner tag is `<h3>`" desync that breaks the block editor on reopen.

Covers at minimum:

- `core/heading` — `level` ↔ `<h{1..6}>` tag
- `core/list` — `ordered: bool` ↔ `<ul>` / `<ol>` (and item nesting preserved)
- `core/group` — `tagName` ↔ wrapper tag (`div` | `section` | `header` | `footer` | `main` | `aside` | `nav`)
- `core/button` — `url` ↔ `href` on inner `<a>`; `text` ↔ link text
- `core/image` — `url`/`alt`/`id` ↔ `<img src>` / `alt` / `class="wp-image-{id}"`
- `core/spacer` — `height` / `width` ↔ inline style
- `core/details` — `showContent: bool` ↔ `<details open>`
- `core/quote` — `citation` ↔ `<cite>` element
- `core/audio` / `core/video` — `loop`/`autoplay`/`controls` ↔ attributes
- `core/code`, `core/preformatted`, `core/paragraph` — `content` ↔ inner text

## Why

The block-mcp bench showed AI Engine Pro's `wp_alter_post` failing on Haiku precisely because the agent updated attrs but not innerHTML (or vice versa). Server-side transforms remove the entire failure mode from the agent's plate.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-html-transformer.php` (475 LOC). The class is one public dispatch (`apply( array $block ): array`) that branches on `blockName` to a per-block private method. Each method uses DOMDocument or simple regex on `innerHTML`. Drop-in adaptable.

## Files to modify / create

- **New:** `includes/Core/HtmlTransformer.php`
- **Modify:** `includes/Core/BlockMutator.php` (from t245) — call `HtmlTransformer::apply()` after every `update-attrs` op on a known static block.
- **New:** `tests/SdAiAgent/Core/HtmlTransformerTest.php` — one test per supported block + one "unknown block, no-op" guard.

## Acceptance criteria

1. After `update-attrs` with `{ level: 3 }` on a `core/heading` with `<h2>...</h2>` innerHTML, the resulting `innerHTML` is `<h3>...</h3>` and the attribute is `3`.
2. After `update-attrs` with `{ ordered: true }` on a `core/list` with `<ul>` items, the resulting innerHTML uses `<ol>` (item children preserved).
3. Group `tagName: 'section'` rewrites the wrapper tag in innerHTML.
4. Button `url` change rewrites `<a href>` while preserving the `class`, `target`, and `rel` attributes.
5. Image `id` change rewrites the `wp-image-{id}` class.
6. Unknown block (no transformer) returns the block unchanged.
7. Static block guard: when attrs change on a block **not** in the transformer table, the mutator emits a warning `static_block_attrs_changed` in the response so the agent knows it may need to send `innerHTML` too.
8. All transformers use `wp_kses_post` on final output.
9. Full PHPUnit + lint + phpstan clean.

## Verification

`npm run verify`. Test fixtures use the same block-comment markup that `parse_blocks()` produces.

## Tier rationale

`tier:thinking`. Per-block-type correctness; regex/DOMDocument choices matter; edge cases (nested lists, multi-class images) are real.

## Dependencies

- **Blocked by:** t245 (the mutator is the call site for transforms).
- **Pairs with:** t250 (dual-storage check runs alongside transforms).

## PR conventions

Leaf — `Resolves #<this-issue>`.
