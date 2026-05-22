# t252 — Tier 3.9: Server-side instructions addendum (per-site rule string)

## Pre-flight

- [x] Memory recall: `system prompt instructions per-site addendum mcp handshake` → 0 hits
- [x] Discovery pass: 0 open PRs touch `includes/REST/McpController.php` or settings pages
- [x] File refs verified: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-instructions.php` (261 LOC); Superdav `includes/REST/McpController.php`, `includes/Admin/UnifiedAdminMenu.php`
- [x] Tier: `tier:standard` — self-contained class, settings UI, MCP handshake integration
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-22
- **Session:** opencode interactive (block-mcp adoption review)
- **Parent task:** none (sibling group t244–t254)
- **Conversation context:** Tier 3 item 1 — short admin-editable string injected into MCP handshake so agents pick up site-specific style rules without RAG.

## What

Add a 2000-char (UTF-8) admin-editable string surfaced at the MCP handshake (and via a public REST endpoint) so a site can encode conventions like:

- "Callouts use class `is-style-info`."
- "Code blocks use the Dracula syntax theme."
- "Headings start at H2; H1 is the page title only."

The string supplements (does not replace) the existing Skills / Knowledge plane — those handle RAG-style lookup; this is a tiny always-on prefix.

## Why

Two problems Skills don't solve well:

1. Rules so short and universal they don't justify a Skill (e.g. "do not insert `core/freeform`").
2. Rules that need to reach **every** agent session without a tool call (handshake-time).

The instructions addendum is the lightweight always-on lane.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-instructions.php` (261 LOC). Self-contained: get/set/sanitize, length cap, updated_at companion option, rate-limited REST read endpoint. Drop-in adaptable.

Key constants to preserve:

```php
const OPTION_KEY = 'sd_ai_agent_instructions';            // (was gk_block_api_instructions)
const UPDATED_AT_OPTION = 'sd_ai_agent_instructions_updated_at';
const MAX_LENGTH = 2000;                                  // UTF-8 chars via mb_strlen
const RATE_LIMIT_PER_MIN = 30;                            // public REST endpoint
```

## Files to modify / create

- **New:** `includes/Core/InstructionsAddendum.php` — get/set/sanitize, UTF-8 safe truncation via `mb_substr`/`mb_strlen`.
- **Modify:** `includes/REST/McpController.php` — append addendum to the existing `serverInfo.instructions` string at handshake.
- **New:** REST endpoint `/sd-ai-agent/v1/instructions` (GET, public, `max-age=60`, rate-limited per IP).
- **Modify:** `includes/Admin/UnifiedAdminMenu.php` (or sub-page) — add a textarea with character counter and "warning: do not put secrets here" copy.
- **New:** `tests/SdAiAgent/Core/InstructionsAddendumTest.php` — sanitize, length cap (incl. multi-byte), rate-limit, public read.

## Acceptance criteria

1. Saving a value through the admin UI persists to option, updates the timestamp companion.
2. Saving > 2000 characters returns `WP_Error('addendum_too_long')` (counted as UTF-8 chars, not bytes — emoji/CJK split-safe).
3. Saving empty string clears the value but still bumps the timestamp.
4. `GET /sd-ai-agent/v1/instructions` returns `{ addendum, updated_at }` with `Cache-Control: public, max-age=60`.
5. Public endpoint enforces 30 req/min per remote IP (transient-backed); excess returns HTTP 429.
6. Handshake response from `McpController` contains the addendum appended after the baseline instructions.
7. Sanitization on read **and** write (defence-in-depth against options written outside the sanitize callback).
8. Full suite + lint + phpstan clean.

## Verification

`npm run verify`. Plus:

```bash
curl -s http://wordpress.local:8080/wp-json/sd-ai-agent/v1/instructions
wp option get sd_ai_agent_instructions
```

## Tier rationale

`tier:standard`. One new class, one new route, one settings field, MCP integration touchpoint. Multi-file but clear scope.

## Dependencies

- **Blocked by:** none.
- **Standalone-shippable.**

## PR conventions

Leaf — `Resolves #<this-issue>`.
