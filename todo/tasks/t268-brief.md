# t268 — Wave 3.4: `get-post` URL / slug+post_type input parity

## Pre-flight

- [x] Memory recall: `get-post resolve-url slug lookup round trip` → wave-2 #1757 added `resolve-url`; `get-post` still requires numeric `id`.
- [x] Discovery pass: 0 open PRs touch `get-post` input. Two-call pattern (`resolve-url` → `get-post`) is in active agent traces.
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-rest-controller.php:693-726` (post-info args). Superdav existing: `includes/Abilities/PostAbilities.php` `handle_get_post`, `includes/Abilities/UrlResolverAbilities.php`.
- [x] Tier: `tier:standard` — additive input shape; existing `id`-only callers unchanged.
- [x] Seeded draft PR decision: skipped.

## Origin

- **Created:** 2026-05-24
- **Session:** opencode interactive (block-mcp wave-3 adoption)
- **Parent task:** TBD
- **Conversation context:** Wave 3 child 4/11. `resolve-url` exists for the URL → post_id step, but agents then make a second `get-post` call. Folding URL/slug input into `get-post` removes the round trip and matches the natural shape of block-mcp's `/post-info` endpoint.

## What

Extend `sd-ai-agent/get-post` input schema with two new mutually-exclusive forms:

```json
// Form A (existing)
{ "id": 156 }

// Form B (new) — URL
{ "url": "https://example.com/about/" }

// Form C (new) — slug + post_type
{ "slug": "about", "post_type": "page" }
```

Exactly one of `id` / `url` / `slug` must be provided. `post_type` is required only with `slug` (defaults rejected to avoid silent cross-type collisions: `about` page vs `about` post).

Internally, when `url` or `slug` is supplied:

1. Reuse `UrlResolverAbilities::resolve_url_strategy(...)` from #1757 (extracted into a public helper in this task).
2. Map the resolved `post_id` to `get_post(...)` and continue.
3. If resolution fails, return the same `WP_Error` shape `resolve-url` would have returned (no new error codes).

The response gains a `resolved_via` field: `"id" | "url_to_postid" | "slug_lookup"` so the agent can log how the input was matched.

## Why

Cuts one round trip per agent action. In traces, ~40% of `get-post` calls in the last 24h were preceded by a `resolve-url`. Folding the steps cuts ability-call count and reduces token spend.

Also closes a parity ask from MCP clients that read block-mcp's `/post-info` shape — `{ post_id?, url?, slug? + post_type? }` is the lingua-franca.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-rest-controller.php:693-726` — REST `/post-info` route. GPL-2.0-or-later. Block-mcp validates exactly-one-of in REST `args.validate_callback`; we use a manual `WP_Error('invalid_input_combination', ...)` after schema validation since JSON Schema can't express XOR cleanly.

## Files to modify / create

- **Modify:** `includes/Abilities/UrlResolverAbilities.php` — extract the inner resolution into `public static function resolve_to_post_id( array $input ): int|WP_Error;`. Keep `handle_resolve_url` calling this for backward compatibility.
- **Modify:** `includes/Abilities/PostAbilities.php` — extend `get-post` `input_schema`; in `handle_get_post`, when `id` is absent, delegate to `UrlResolverAbilities::resolve_to_post_id` and continue.
- **Modify:** `tests/SdAiAgent/Abilities/PostAbilitiesTest.php` — add cases: `url` valid, `slug+post_type` valid, `slug` without `post_type` → `missing_post_type`, all three keys present → `too_many_inputs`, neither present → existing `missing_id` (rename to `missing_input` if covered semantics expand).
- **No new files.**

## Acceptance criteria

1. `get-post { url: "https://wordpress.local:8080/about/" }` returns the same dict as `get-post { id: 156 }`, plus `resolved_via: "url_to_postid"`.
2. `get-post { slug: "about", post_type: "page" }` resolves to id 156, `resolved_via: "slug_lookup"`.
3. `get-post { slug: "about" }` (no post_type) → `WP_Error('missing_post_type', ...)`.
4. `get-post { id: 156, url: "..." }` → `WP_Error('too_many_inputs', ...)`.
5. `get-post {}` → `WP_Error('missing_input', ...)` (rename from `missing_id` is acceptable but include a backward-compat alias if other tests assert the old code).
6. `get-post { url: "https://other.example.com/about/" }` → `WP_Error('external_host', ...)` (forwarded unchanged from resolver).
7. `get-post { id: 156 }` (existing path) returns exactly the pre-change shape **plus** `resolved_via: "id"`.
8. `resolve-url` behaviour unchanged (regression test).
9. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval --user=admin '
  foreach (
    [["id" => 156], ["url" => "https://wordpress.local:8080/about/"], ["slug" => "about", "post_type" => "page"]]
    as $args
  ) {
    $r = wp_get_ability("sd-ai-agent/get-post")->execute($args);
    echo (is_wp_error($r) ? "ERR: ".$r->get_error_code() : ($r["title"] ?? "—") . " via " . ($r["resolved_via"] ?? "?")) . PHP_EOL;
  }
'
```

## Tier rationale

`tier:standard` — schema widening with reused logic from a shipped helper. The only new validation is the XOR check, which is a few `isset()` calls.

## Dependencies

- **Blocked by:** none. `resolve-url` (#1757 / wave 2) is on `main`.
- **Soft conflict with:** `t267` (list-posts filter parity) — same file. Coordinate via separate commits; no shared functions.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #<parent>`.
