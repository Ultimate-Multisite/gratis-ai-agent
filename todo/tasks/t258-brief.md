# t258 — Wave 2.4: `list_terms` ability — taxonomy term discovery

## Pre-flight

- [x] Memory recall: `list_terms get_terms taxonomy slug count` → 0 hits
- [x] Discovery pass: 0 open PRs touch taxonomy listings. `list-taxonomies` exists today (`CustomTaxonomyAbilities.php`) but does not enumerate terms.
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-term-manager.php` (177 LOC); Superdav existing: `includes/Abilities/CustomTaxonomyAbilities.php`
- [x] Tier: `tier:standard` — thin wrapper around `get_terms` with capability + arg validation
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-23
- **Session:** opencode interactive (block-mcp wave-2 adoption)
- **Parent task:** #1739
- **Conversation context:** Wave 2 child 4/10. Agents currently fall back to `db-query` for term IDs — slow, error-prone, exposes raw SQL. `list_terms` is the polite WP-API surface for the same lookup.

## What

Add a `sd-ai-agent/list-terms` ability:

```json
{
  "taxonomy": "category",         // required
  "search": "news",                // optional, fuzzy
  "hide_empty": false,             // optional, default false
  "per_page": 50,                  // optional, default 50, max 200
  "page": 1,                       // optional, default 1
  "parent": 0,                     // optional — direct children of a parent term ID
  "orderby": "name",               // name|slug|count|term_id (default name)
  "order": "ASC"                   // ASC|DESC
}
```

Returns:

```json
{
  "taxonomy": "category",
  "total": 12,
  "page": 1,
  "per_page": 50,
  "items": [
    { "term_id": 1, "name": "Uncategorized", "slug": "uncategorized", "count": 5, "parent": 0, "description": "" }
  ]
}
```

Capability: `current_user_can( $taxonomy_object->cap->manage_terms )` for non-public taxonomies; public taxonomies are readable.

## Why

`list-taxonomies` returns the schema; agents then need the actual term IDs to assign them to posts. Without `list-terms`, the fallback is `db-query 'SELECT term_id, name FROM wp_terms ...'` — exposes raw SQL, bypasses taxonomy registration, and ignores capability checks.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-term-manager.php` (177 LOC). GPL-2.0-or-later. Note: block-mcp's version uses `get_terms` directly; ours should call `get_terms` with `taxonomy => $tax` and pass through search/per_page/page/parent/orderby.

## Files to modify / create

- **New:** `includes/Abilities/TaxonomyAbilities.php` — house both `sd-ai-agent/list-terms` and any future term-management ops. (Keep `CustomTaxonomyAbilities.php` focused on taxonomy registration; this is term-level CRUD-read.)
- **Modify:** `includes/Plugin.php` — register the new handler with `#[Handler]` attribute.
- **New:** `tests/SdAiAgent/Abilities/TaxonomyAbilitiesTest.php` — covers default category lookup, search, pagination, parent filter, unknown taxonomy (404), private taxonomy without cap (403), `hide_empty`.

## Acceptance criteria

1. `list-terms { "taxonomy": "category" }` returns at minimum `Uncategorized` on a fresh install.
2. `search: "uncat"` narrows the result.
3. `per_page: 200` accepted; `per_page: 500` → `WP_Error('per_page_too_large', ...)` (cap at 200).
4. `parent: 5` returns only direct children of term 5.
5. Unknown taxonomy → `WP_Error('taxonomy_not_found', ...)`.
6. Private taxonomy without `manage_terms` cap → `WP_Error('insufficient_capability', ...)`.
7. `hide_empty: true` excludes 0-count terms.
8. Pagination metadata correct: `total` reflects unpaginated count.
9. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval '
  $r = wp_get_ability("sd-ai-agent/list-terms")->execute(["taxonomy" => "category"]);
  echo wp_json_encode($r, JSON_PRETTY_PRINT) . PHP_EOL;
'
```

## Tier rationale

`tier:standard` — thin wrapper around `get_terms`, well-defined arg schema, single capability gate.

## Dependencies

- **Blocked by:** none.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #1739`.
