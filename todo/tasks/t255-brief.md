# t255 — Wave 2.1: `resolve_url` ability — URL/slug → `{post_id, post_type, edit_link, status}`

## Pre-flight

- [x] Memory recall: `resolve url url_to_postid post lookup wp` → 0 hits in session memory
- [x] Discovery pass: 0 open PRs touch URL resolution; nearest neighbour is `WordPressAbilities.php` (list-posts) and `BlockAbilities.php` (get-page-blocks)
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-rest-controller.php:640` (route), `:1358` (callback `resolve_url`)
- [x] Tier: `tier:standard` — single-purpose lookup ability, no new schema, well-trodden WP API (`url_to_postid`)
- [x] Seeded draft PR decision: skipped — standalone leaf ability

## Origin

- **Created:** 2026-05-23
- **Session:** opencode interactive (block-mcp wave-2 adoption)
- **Parent task:** #1739 (Wave 2 — remaining block-mcp tool adoption)
- **Conversation context:** Wave 2 child 1/10. README's first example ("Update a heading by URL") leads with this; without `resolve_url`, agents must scan `list-posts` and guess the target.

## What

Add a `sd-ai-agent/resolve-url` ability that accepts a URL **or** post slug and returns:

```json
{
  "post_id": 123,
  "post_type": "page",
  "post_status": "publish",
  "edit_link": "https://example.com/wp-admin/post.php?post=123&action=edit",
  "permalink": "https://example.com/about/",
  "title": "About",
  "matched_via": "url_to_postid"  // or "slug_lookup", "guid_lookup"
}
```

Resolution order:

1. `url_to_postid()` for full URLs (handles permalinks, query-strings, custom-post-type rewrites).
2. If that fails and input looks like a slug, `get_page_by_path($slug, OBJECT, $post_types)` across public post types.
3. If still no match and input is a numeric `?p=N` or `?page_id=N`, parse directly.
4. Return `WP_Error('not_found', ...)` with the attempted strategies in `data.attempts`.

Resolves draft posts and private posts when current user can `edit_post`.

## Why

Today agents call `list-posts` then pattern-match titles to guess IDs — slow, error-prone, breaks on duplicates. `resolve_url` is one round-trip and authoritative. Mirrors the block-mcp README pattern.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-rest-controller.php:1358` (callback) — 30-line implementation, GPL-2.0-or-later, safe to adapt.

## Files to modify / create

- **New:** `includes/Abilities/UrlResolverAbilities.php` — `register_resolve_url()`, `handle_resolve_url()`.
- **Modify:** `includes/Plugin.php` — register the new handler with `#[Handler]` attribute and `CTX_ABILITIES` context.
- **New:** `tests/SdAiAgent/Abilities/UrlResolverAbilitiesTest.php` — covers full-URL, slug-only, `?p=`, draft (with cap check), unknown URL, and external-host URL (must reject).
- **Modify:** `docs/abilities-reference.md` (if it exists) — add entry; otherwise skip.

## Acceptance criteria

1. `resolve-url { "url": "https://site.test/about/" }` → `post_id` populated, `matched_via: "url_to_postid"`.
2. `resolve-url { "url": "about" }` (slug) → resolves via `get_page_by_path`, `matched_via: "slug_lookup"`.
3. `resolve-url { "url": "https://site.test/?p=123" }` → resolves, `matched_via: "url_to_postid"`.
4. `resolve-url { "url": "https://other-site.example/foo" }` → `WP_Error('external_host', ...)` — never resolves cross-host.
5. `resolve-url { "url": "https://site.test/missing/" }` → `WP_Error('not_found', ...)` with `data.attempts` listing each strategy tried.
6. Draft post: user with `edit_post` cap → resolved; user without → `not_found`.
7. Full PHPUnit + phpstan + lint clean.

## Verification

`npm run verify`. Plus a smoke check against the local dev install:

```bash
wp eval '
  $r = wp_get_ability("sd-ai-agent/resolve-url")->execute(["url" => "http://wordpress.local:8080/?page_id=2"]);
  echo wp_json_encode($r) . PHP_EOL;
'
```

## Tier rationale

`tier:standard` — small focused ability, single WP API call path, clear acceptance criteria.

## Dependencies

- **Blocked by:** none.
- **Standalone-shippable.** Independent of every other wave-2 child.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #1739`.
