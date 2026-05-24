# t265 — Wave 3.1: `sd-ai-agent/scan-storage-modes` ability — pre-flight dual-storage cataloguer

## Pre-flight

- [x] Memory recall: `dual_storage block storage modes scan inventory` → wave-2 #1763 added `DualStorageRegistry` but no whole-site scanner exists.
- [x] Discovery pass: 0 open PRs touch site-wide block enumeration. `sd-ai-agent/get-site-block-usage` returns per-block-name totals only; it does not classify storage mode.
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-rest-controller.php:232-242` (route), `class-block-inventory.php` (scanner). Superdav existing: `includes/Core/DualStorageRegistry.php`, `includes/Abilities/BlockAbilities.php` (get-site-block-usage handler).
- [x] Tier: `tier:standard` — read-only scan with capped post window + capability gate; no mutations.
- [x] Seeded draft PR decision: skipped.

## Origin

- **Created:** 2026-05-24
- **Session:** opencode interactive (block-mcp wave-3 adoption)
- **Parent task:** TBD (filed alongside this PR)
- **Conversation context:** Wave 3 child 1/11. Wave 2 #1763 added `DualStorageRegistry::is_dual_storage()` so `update-html` rejects writes that would silently corrupt blocks that store the same datum in both `attrs` and `innerHTML`. The registry covers known core/third-party blocks, but on real sites with custom blocks an agent has no way to discover which block names are dual-storage **before** attempting a bulk mutation. This ability returns that catalogue.

## What

Add `sd-ai-agent/scan-storage-modes`:

```json
{
  "post_status": ["publish", "draft"],   // optional, default ["publish"]
  "post_types":  ["post", "page"],        // optional, default all public types
  "limit":       500,                     // optional, default 200, max 1000
  "include_registry_known": false         // optional — if true, also includes blocks already in DualStorageRegistry
}
```

Returns:

```json
{
  "posts_scanned": 187,
  "unique_blocks":  42,
  "items": [
    {
      "block_name":         "acme/hero",
      "storage_mode":       "dual",            // "attrs_only" | "inner_html_only" | "dual" | "unknown"
      "in_registry":        false,
      "occurrences":        14,
      "first_post_id":      341,
      "evidence": {
        "attr_keys":        ["title", "subtitle"],
        "inner_html_chars": 312
      }
    }
  ],
  "truncated": false
}
```

Classification rule per block instance:
- `attrs_only` — `attrs` non-empty AND `innerHTML` empty/whitespace.
- `inner_html_only` — `attrs` empty AND `innerHTML` non-empty.
- `dual` — both populated; flagged for `DualStorageRegistry` review.
- `unknown` — both empty (rare; usually placeholder blocks).

Aggregation: take the **modal** classification per block_name across all occurrences. Tie-break favours `dual` (more conservative).

## Why

`update-blocks` and `update-html` already reject `dual_storage_requires_both` for blocks in the registry. Without a scan ability, an agent operating on a third-party theme/plugin's custom blocks discovers the constraint **per-call** (one rejection per mutation attempt). This ability lets the agent enumerate the safe-vs-dangerous block surface up front, decide whether to:

1. Skip those blocks entirely.
2. Include `attributes` alongside `innerHTML` on every write to those blocks.
3. Surface a warning to the human user.

It also closes a parity gap with block-mcp's `/storage-modes/scan` REST route, which third-party MCP clients call out of habit.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-inventory.php` (full file, ~250 LOC) and `class-rest-controller.php:232-242` (route + permission). GPL-2.0-or-later.

The block-mcp version uses `WP_Query` with `posts_per_page` capped, walks `parse_blocks` on each, recurses `innerBlocks`. Our implementation should reuse the existing recursion helper in `BlockAbilities::get_site_block_usage_handler` so we don't duplicate the tree-walker.

## Files to modify / create

- **New:** `includes/Core/BlockStorageScanner.php` — pure helper: `scan( array $args ): array` returning the items array. No WP-API side effects; pure data.
- **Modify:** `includes/Abilities/BlockAbilities.php` — register `sd-ai-agent/scan-storage-modes` and add a `handle_scan_storage_modes` callback that wraps `BlockStorageScanner::scan`. Schema, capability (`edit_posts`), `meta.mcp.public = true`, `annotations.readonly = true, idempotent = true`.
- **New:** `tests/SdAiAgent/Core/BlockStorageScannerTest.php` — fixtures: empty post (zero items), post with one `attrs_only` block, post with one `dual` block, post mixing all four modes, truncation at limit, `include_registry_known` toggling.
- **New:** `tests/SdAiAgent/Abilities/ScanStorageModesAbilityTest.php` — capability rejection, schema validation, end-to-end against a 5-post fixture.

## Acceptance criteria

1. `scan-storage-modes {}` on a fresh install returns `items` empty or only core blocks, `posts_scanned > 0`, `truncated: false`.
2. After seeding a post with `<!-- wp:acme/dual --><div>x</div><!-- /wp:acme/dual -->` (innerHTML) **and** `attrs.title = "y"`, the item for `acme/dual` reports `storage_mode: "dual"` and `evidence.attr_keys` includes `"title"`.
3. `include_registry_known: false` (default) excludes blocks already in `DualStorageRegistry::all_known()`.
4. `limit: 1000` accepted; `limit: 5000` → `WP_Error('limit_too_large', ...)`.
5. `limit` reached → `truncated: true`, `posts_scanned` equals the limit.
6. Non-`edit_posts` user → `WP_Error('insufficient_capability', ...)`.
7. Memoised tree-walk: scanning the same post tree twice within one request reuses the parsed block array (no double `parse_blocks`).
8. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval --user=admin '
  $r = wp_get_ability("sd-ai-agent/scan-storage-modes")->execute(["limit" => 50]);
  echo "posts_scanned: " . $r["posts_scanned"] . PHP_EOL;
  echo "unique_blocks: " . $r["unique_blocks"] . PHP_EOL;
  foreach (array_slice($r["items"], 0, 5) as $item) {
    echo "  " . $item["block_name"] . " — " . $item["storage_mode"] . " (x" . $item["occurrences"] . ")" . PHP_EOL;
  }
'
```

## Tier rationale

`tier:standard` — read-only enumeration with a bounded scan window. No write paths, no schema changes to existing data. The recursion helper is already battle-tested by `get-site-block-usage`.

## Dependencies

- **Blocked by:** none. `DualStorageRegistry` is already on `main` (#1763).
- **Soft dependency:** results become more actionable once t274 (innerBlocks ref surfacing) lands, but the scan itself doesn't need refs.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #<parent>`.
