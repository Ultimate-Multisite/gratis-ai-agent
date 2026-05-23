# t260 — Wave 2.6: `render: true` mode on get-page-blocks

## Pre-flight

- [x] Memory recall: `render_block do_blocks do_shortcode synced expand` → 0 hits
- [x] Discovery pass: #1745 (just merged) added outline/summary/search/block_name/render/fields params to get-page-blocks **schema**, but `render` was a placeholder pending this dedicated task — needs the actual rendering path
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-reader.php:285-294` (post-context setup), `:494` (`render_block()` call), `:508` (error handling)
- [x] Tier: `tier:thinking` — render uses global post context, runs filters, may have side-effects; output-buffer management + error containment matter
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-23
- **Session:** opencode interactive (block-mcp wave-2 adoption)
- **Parent task:** #1739
- **Conversation context:** Wave 2 child 6/10. PR #1745 added the `render` param to the schema; this task implements the actual rendering. Without rendering, dynamic blocks (`core/latest-posts`, `core/query`, shortcodes, synced patterns) show only their static placeholder. Agents auditing "what will users see?" need the rendered output.

## What

Implement the `render` query param on `sd-ai-agent/get-page-blocks`:

- `render: false` (default) — current behaviour, returns raw block tree.
- `render: true` — for each returned block, populate a new `rendered_html` field with the result of `render_block( $block )`, executed under the post's global context so shortcodes/`do_blocks` work.

Synced patterns (`core/block { ref: N }`) — when `render: true` and the block is `core/block`, recursively resolve the referenced `wp_block` post and render *its* blocks too; set `rendered_synced_pattern_id: N` on the parent for traceability.

Render runs are wrapped in:

1. `setup_postdata( $post )` + restore in `finally`.
2. Try/catch around `render_block` — exceptions become `rendered_html: ""` + `render_error: "<class>: <message>"` on that block, never propagate.
3. `ob_start` / `ob_get_clean` around each render in case a block leaks output via `echo`.
4. Hard time budget: 5 seconds total render budget per request; exceed → remaining blocks get `render_error: "render_timeout"`.

## Why

- Truthful diff: agents inspecting "did my change affect the page?" need rendered HTML, not static block-comment markup.
- Synced patterns are invisible at the raw-tree level — only the reference shows. Rendering expands them.
- Dynamic blocks (`core/query`, `core/latest-posts`, `core/post-content`) show no real content in raw mode.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-reader.php:285-510` — post-context setup, render loop, error containment. GPL-2.0-or-later.

## Files to modify / create

- **New:** `includes/Core/BlockRenderer.php` — `render_block_tree( int $post_id, array $blocks, int $budget_seconds = 5 ): array` returns the same tree with `rendered_html` (+ optional `render_error`, `rendered_synced_pattern_id`) attached per block.
- **Modify:** `includes/Abilities/BlockAbilities.php` — `handle_get_page_blocks` branches on `render: true` and invokes `BlockRenderer`. Already-merged schema (PR #1745) defines the param.
- **New:** `tests/SdAiAgent/Core/BlockRendererTest.php` — covers static block (paragraph), dynamic block (latest-posts), synced pattern expansion, shortcode resolution, render exception containment, budget exhaustion, post-context restoration after errors.

## Acceptance criteria

1. `render: true` on a post containing a paragraph → `rendered_html: "<p>...</p>"` matches `apply_filters('the_content', ...)`-style output for that block.
2. `render: true` on a post containing `core/latest-posts` → `rendered_html` includes the actual `<ul>` of recent posts.
3. `render: true` on a post containing a synced pattern (`core/block { ref: N }`) → block has `rendered_synced_pattern_id: N` and `rendered_html` reflects the referenced post's content.
4. Shortcode in a `core/shortcode` block resolves: `[gallery]` → actual gallery markup.
5. A throwing render callback (mocked via filter) → the affected block has `render_error` set, response still 200, sibling blocks unaffected.
6. Time budget exhaustion → remaining blocks get `render_error: "render_timeout"`, response still 200.
7. `$GLOBALS['post']` is restored to its pre-call value even on error (assert in test via shutdown function).
8. `render: false` (default) → no `rendered_html` field present, no perf change (within 5%).
9. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval '
  $r = wp_get_ability("sd-ai-agent/get-page-blocks")->execute([
    "post_id" => 156,
    "render"  => true,
  ]);
  foreach ($r["blocks"] as $b) {
    echo $b["name"] . " -> " . substr($b["rendered_html"] ?? "(none)", 0, 60) . PHP_EOL;
  }
'
```

## Tier rationale

`tier:thinking` — global state (`$GLOBALS['post']`), output buffering, exception containment, time budget, recursive synced-pattern resolution, and a perf-regression risk on the default code path.

## Dependencies

- **Blocked by:** none — #1745 already shipped the schema scaffold.
- **Related:** #1745 (schema), #1738 (parent enhancement issue, now closed).

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #1739`. Reference #1745 in the body since it laid the schema.
