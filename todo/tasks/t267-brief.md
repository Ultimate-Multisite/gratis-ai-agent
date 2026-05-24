# t267 — Wave 3.3: `list-posts` filter parity with block-mcp `/find-posts`

## Pre-flight

- [x] Memory recall: `list-posts WP_Query filter taxonomy date range` → 0 hits.
- [x] Discovery pass: existing `sd-ai-agent/list-posts` schema: `post_type, post_status, search, category, tag, per_page, page` (see `includes/Abilities/PostAbilities.php:326+`). block-mcp `/find-posts` (`includes/class-rest-controller.php:653-692`) adds: `post_status[]`, `date_after`, `date_before`, `tax_query[]`, `meta_query[]`, `orderby`, `order`, `author`, `has_password`.
- [x] File refs verified — block-mcp source: `class-rest-controller.php:653-692` (route + args) and `class-post-manager.php` (find_posts handler). Superdav existing: `includes/Abilities/PostAbilities.php` `handle_list_posts`.
- [x] Tier: `tier:standard` — schema extension on an existing read ability; no new tables, no new ops.
- [x] Seeded draft PR decision: skipped.

## Origin

- **Created:** 2026-05-24
- **Session:** opencode interactive (block-mcp wave-3 adoption)
- **Parent task:** TBD
- **Conversation context:** Wave 3 child 3/11. Agents currently fall back to `db-query` when they need date-range or multi-status filters because `list-posts` only takes a single `post_status` and no date filters. block-mcp's `/find-posts` is essentially `WP_Query` exposed safely.

## What

Extend the `sd-ai-agent/list-posts` ability input schema:

| New field | Type | Notes |
|---|---|---|
| `post_status` | string \| string[] | Existing field accepts only a string; widen to array. Default `["publish"]`. Each value validated against registered statuses. |
| `date_after` | string (ISO-8601 date) | Maps to `WP_Query::date_query[0]['after']`. |
| `date_before` | string (ISO-8601 date) | Maps to `date_query[0]['before']`. |
| `inclusive_dates` | boolean | Default `true`. Maps to `date_query[0]['inclusive']`. |
| `author` | int \| int[] | Author user IDs. |
| `tax_query` | object[] | Generic taxonomy filter: `[{ taxonomy: "category", terms: [1,2], operator: "IN" }]`. Operator must be `IN`/`NOT IN`/`AND`. |
| `meta_query` | object[] | `[{ key: "_thumbnail_id", compare: "EXISTS" }]`. Operator allowlist: `=, !=, EXISTS, NOT EXISTS, IN, NOT IN`. **NEVER** allow `LIKE` or raw `value` with `compare: "REGEXP"` from agent input. |
| `orderby` | string | `date \| modified \| title \| ID \| menu_order \| rand`. Default `date`. |
| `order` | string | `ASC \| DESC`. Default `DESC`. |
| `has_password` | boolean | Forward to `WP_Query::has_password`. |

Existing fields (`post_type`, `search`, `category`, `tag`, `per_page`, `page`) remain backward compatible — single-value strings still accepted for `post_status` (auto-wrapped to one-element array).

The response shape gains one field: `query_args` (the WP_Query args that were actually applied, **after sanitisation**) so an agent can self-correct when its filter was over-broad.

## Why

Three current pain points solved:

1. `post_status: ["draft", "pending"]` — currently impossible. Editorial workflows need to enumerate non-published work.
2. `date_after: "2026-01-01"` — currently impossible. "Show me posts I haven't updated this year" is a common ask.
3. `tax_query` for multi-term `OR` / `AND` — currently impossible (`category` and `tag` are single-term `IN`). Multi-taxonomy lookup is the canonical "show me posts tagged X but not Y" use case.

Without these, agents fall back to `db-query`, which exposes raw SQL, bypasses capability checks, and triggers our own "raw SQL is a smell" lint.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-rest-controller.php:653-692` (REST args, including REST `args` validation). The arg map there is a clean reference for which filters to forward to `WP_Query`. GPL-2.0-or-later.

## Files to modify / create

- **Modify:** `includes/Abilities/PostAbilities.php` — extend the `sd-ai-agent/list-posts` `input_schema` and `handle_list_posts` to forward the new args. Centralise sanitisation in a private `sanitize_query_args(array $input): array` helper.
- **Modify:** `tests/SdAiAgent/Abilities/PostAbilitiesTest.php` — add a `list-posts` block: multi-status, date range, author filter, tax_query OR/AND, meta_query EXISTS, orderby title ASC, backward-compat with single-value `post_status`.
- **No new files.**

## Acceptance criteria

1. `list-posts { post_status: ["draft", "pending"] }` returns draft+pending posts (excluded by default).
2. `list-posts { date_after: "2026-01-01" }` excludes 2025 posts.
3. `list-posts { tax_query: [{ taxonomy: "category", terms: [1], operator: "IN" }] }` returns only posts in cat 1.
4. `list-posts { meta_query: [{ key: "_thumbnail_id", compare: "EXISTS" }] }` returns only posts with a featured image.
5. `meta_query [{ compare: "LIKE", value: "%admin%" }]` → `WP_Error('invalid_meta_compare', ...)` (allowlist enforced).
6. `tax_query [{ operator: "REGEXP" }]` → `WP_Error('invalid_tax_operator', ...)`.
7. `orderby: "menu_order", order: "ASC"` honoured.
8. Legacy form `post_status: "draft"` (string) still works (auto-wrapped).
9. Response includes `query_args` mirror.
10. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval --user=admin '
  $r = wp_get_ability("sd-ai-agent/list-posts")->execute([
    "post_status" => ["draft", "publish"],
    "date_after"  => "2026-01-01",
    "orderby"     => "modified",
    "order"       => "DESC",
    "per_page"    => 5,
  ]);
  echo "total: " . $r["total"] . PHP_EOL;
  print_r($r["query_args"]);
'
```

## Tier rationale

`tier:standard` — schema extension over `WP_Query`. The risk surface is the `meta_query` / `tax_query` operator allowlist; everything else is pass-through with sanitisation. Capability gate unchanged (`current_user_can('read')` for public statuses; private statuses already gated by `WP_Query`).

## Dependencies

- **Blocked by:** none.
- **Soft conflict with:** `t268` (get-post URL/slug input) lives in the same file. Coordinate via separate commits; no shared functions.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #<parent>`.
