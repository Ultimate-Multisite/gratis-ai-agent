# t249 — Tier 2.6: Tier-based block preference policy (preferred / acceptable / avoid / legacy)

## Pre-flight

- [x] Memory recall: `block preference policy legacy replacement` → 0 hits
- [x] Discovery pass: 0 open PRs touch `BlockContentPolicy.php` or admin pages
- [x] File refs verified: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-preferences.php` (347 LOC), `class-settings-page.php`; Superdav `includes/Core/BlockContentPolicy.php:149`, `includes/Admin/UnifiedAdminMenu.php`
- [x] Tier: `tier:thinking` — productising existing policy class; admin UI design judgment
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-22
- **Session:** opencode interactive (block-mcp adoption review)
- **Parent task:** none (sibling group t244–t254)
- **Conversation context:** Tier 2 item 2 — refactor `BlockContentPolicy` into a scored tier system with site-editable replacement map.

## What

Replace the binary allow/deny in `includes/Core/BlockContentPolicy.php` (149 LOC) with a 0–100 score per **block namespace** that maps to four tiers:

| Tier | Score | Policy on insert | Policy on update |
|---|---|---|---|
| `preferred` | ≥ 80 | allow | allow |
| `acceptable` | 50–79 | allow | allow |
| `avoid` | 10–49 | allow + warning + `suggested_replacement` | allow |
| `legacy` | < 10 | **reject** with `legacy_block` + `suggested_replacement` | allow (existing pages aren't bricked) |

Plus an admin-editable **replacement map** (`legacy_block_name` → `modern_block_name`).

Defaults: `core/*` = 90 preferred; a small starter set of known-deprecated blocks (e.g. `core/freeform`, `core/legacy-widget` if present) at < 10.

## Why

A productised, site-tunable preference layer lets each install decide what the agent can ship to disk. Today's policy is binary and code-coded. Tier scoring lets ops mark "yes but avoid" vs "no, use this instead" with clear semantics the agent can act on.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-preferences.php` (347 LOC). The class is a thin wrapper around two options: `gk_block_api_preferences` (namespace → score) and `gk_block_api_replacements` (legacy → modern). Both editable via filters and the settings page.

## Files to modify / create

- **Refactor:** `includes/Core/BlockContentPolicy.php` (149 LOC, current binary allow-list) — add score lookup, tier mapping, replacement map.
- **New:** `includes/Admin/BlockPreferencesPage.php` — sub-page under existing `UnifiedAdminMenu`.
- **Modify:** `includes/Core/BlockMutator.php` (t245) — call `BlockContentPolicy::check_insert()` before any insert/replace/wrap op.
- **New options:** `sd_ai_agent_block_preferences` (assoc array namespace → int), `sd_ai_agent_block_replacements` (assoc legacy → modern). Naming per `AGENTS.md` canonical: `sd_ai_agent_*` prefix.
- **Modify:** `tests/SdAiAgent/Core/BlockContentPolicyTest.php` — score/tier/replacement coverage.
- **New:** `tests/SdAiAgent/Admin/BlockPreferencesPageTest.php` — option round-trip.

## Acceptance criteria

1. Insert of a namespace scored < 10 returns HTTP 400 `legacy_block` with `data.suggested_replacement` populated when mapped (null otherwise).
2. Insert of a namespace scored 10–49 succeeds but the response includes `warnings: [ { code: "avoid_block", suggested_replacement: … } ]`.
3. Insert of a namespace ≥ 50 succeeds silently.
4. `update-attrs` on an existing legacy block succeeds (insert-only enforcement).
5. Admin page lists every registered namespace with its current score; supports typing a new namespace and assigning a score; persists to option.
6. Replacement map persists separately; both columns are searchable dropdowns of currently-registered blocks (plus free-text fallback).
7. Defaults ship sensibly: `core/*` = 90; at least 3 known-deprecated entries at < 10.
8. Filter hooks: `sd_ai_agent_block_preferences`, `sd_ai_agent_block_replacements` (allow programmatic override).
9. Lint/phpstan/tests clean; full suite passes.

## Verification

`npm run verify`. Admin smoke: open `Settings → Block MCP → Preferences`, change a score, save, confirm option in `wp_options`.

## Tier rationale

`tier:thinking`. Schema migration of an existing class, new admin UI, two new options, integration with mutator. Multi-file + judgment.

## Dependencies

- **Blocked by:** none structurally. Useful **after** t245 lands (mutator is the enforcement point) but can ship before — the policy class can be ready and called once t245 wires it.
- **Standalone-shippable** with a simple `apply_filters` hook in existing write paths.

## PR conventions

Leaf — `Resolves #<this-issue>`.
