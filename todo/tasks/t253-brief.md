# t253 — Tier 3.10: Site-wide block / pattern usage analytics

## Pre-flight

- [x] Memory recall: `site usage analytics block pattern count cache` → 0 hits
- [x] Discovery pass: 0 open PRs touch block discovery abilities
- [x] File refs verified: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-inventory.php` (772 LOC); Superdav `includes/Abilities/BlockAbilities.php:1225`
- [x] Tier: `tier:standard` — site-walk with caching; clear surface
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-22
- **Session:** opencode interactive (block-mcp adoption review)
- **Parent task:** none (sibling group t244–t254)
- **Conversation context:** Tier 3 item 2 — give the agent site-aware planning data.

## What

Add ability `sd-ai-agent/get-site-block-usage` that returns:

- `block_counts` — assoc array `block_name => int` (count across all published posts/pages).
- `pattern_counts` — assoc array `pattern_name => int` (synced + registered references).
- `top_namespaces` — sorted list of namespace usage (e.g. `core` → 4823, `kadence` → 217).
- `last_scanned` — ISO 8601 timestamp.

Cached in a site option; refreshed via an admin button **or** on a daily cron tick; cap walking at the most recent 1000 published posts to bound runtime.

## Why

When the agent plans a page ("add a CTA"), knowing the site uses `kadence/advancedbutton` 200× and `core/buttons` 5× lets it match the site's voice. Today the agent guesses.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-inventory.php` (772 LOC). Adopt the inventory-scan strategy (post query → `parse_blocks` per post → recursive walker tallies block names) and the cached-option storage. Skip the dual-storage scanning bits — those go in t250.

## Files to modify / create

- **New:** `includes/Core/BlockInventory.php` — scan, tally, cache.
- **Modify:** `includes/Abilities/BlockAbilities.php` — register `sd-ai-agent/get-site-block-usage`.
- **Modify:** `includes/Admin/UnifiedAdminMenu.php` (or sub-page) — "Refresh block usage" button (with rate-limit + confirmation).
- **New cron:** daily refresh hook `sd_ai_agent_refresh_block_usage` (configurable, off by default — opt-in via setting).
- **New:** `tests/SdAiAgent/Core/BlockInventoryTest.php` — tally correctness, cache freshness, post-count cap respect.

## Acceptance criteria

1. Fresh scan returns the expected counts for a fixture site with three known blocks.
2. Cached result returned without re-scanning within the cache window (default 24h, filterable via `sd_ai_agent_block_usage_ttl`).
3. Scan caps at 1000 most-recent published posts; further posts ignored (with `truncated: true` flag in response).
4. Scan rate-limited per-IP on the admin button (no thrash).
5. Cron hook scheduled only when the opt-in setting is on; cleared on plugin deactivate.
6. Full suite + lint + phpstan clean.

## Verification

`npm run verify`. Plus on the dev install:

```bash
wp eval 'print_r( wp_get_ability("sd-ai-agent/get-site-block-usage")->execute([]) );'
```

## Tier rationale

`tier:standard`. Scan + tally + cache + cron is standard plumbing; no judgment-heavy correctness traps.

## Dependencies

- **Blocked by:** none.
- **Standalone-shippable.**

## PR conventions

Leaf — `Resolves #<this-issue>`.
