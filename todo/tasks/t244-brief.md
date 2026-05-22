# t244 — Tier 1.1: Stable block refs (`sd_ref`)

## Pre-flight

- [x] Memory recall: `superdav block abilities gutenberg refs revisions optimistic concurrency` → 0 hits — no prior lessons; fresh territory
- [x] Discovery pass: 0 open PRs touching `includes/Abilities/BlockAbilities.php`, `includes/Core/BlockValidator.php`, or new `includes/Core/BlockReferences.php` (verified `git log --since=2d` and `gh pr list --state open`)
- [x] File refs verified: source files present in clone `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-reader.php` (846 LOC); target anchors `includes/Abilities/BlockAbilities.php:1225`, `includes/Core/BlockContentPolicy.php:149`
- [x] Tier: `tier:thinking` — adapting upstream pattern across an existing namespace; naming + DB-write strategy needs judgment
- [x] Seeded draft PR decision: skipped — single-PR scope; open PR once foundation class is built

## Origin

- **Created:** 2026-05-22
- **Session:** opencode interactive (block-mcp adoption review)
- **Created by:** ai-interactive
- **Parent task:** none (sibling group: t244–t254, this is the foundation)
- **Conversation context:** Review of GravityKit/block-mcp identified 11 borrowable patterns. This is Tier 1 item 1 — the prerequisite for all surgical-edit work.

## What

Add per-block stable UUID references (`sd_ref`) to every block in any post Superdav reads, so multi-turn agent edits address the **same** block reliably across sibling shifts, inserts, and deletes — without re-reading the page between mutations.

Concrete deliverables:

1. New class `includes/Core/BlockReferences.php` (namespace `SdAiAgent\Core`) with:
   - `assign_refs( array $blocks ): array` — walks `parse_blocks()` output, ensures every block (and inner block, recursively) has `attrs.metadata.sd_ref` set to a `blk_XXXXXXXX` UUID (8-char URL-safe slug, e.g. `blk_a3f2c1q9`). Skips blocks that already carry one.
   - `find_by_ref( array $blocks, string $ref ): array|null` — returns `['path' => [int…], 'flat_index' => int, 'block' => array]` or `null`.
   - `persist_refs_for_post( int $post_id ): bool` — re-serialises `post_content` and writes to DB **without** creating a revision (refs are editor-only metadata, not user content). Uses `$wpdb->update()` directly + `clean_post_cache()`.
2. New ability `sd-ai-agent/get-page-blocks` (in `includes/Abilities/BlockAbilities.php`) that returns the block tree with `flat_index`, `path`, `ref`, `name`, `attributes`, and `text_preview` per block. Accepts `persist_refs: false` to read without the side-effect.
3. Unit tests in `tests/SdAiAgent/Core/BlockReferencesTest.php` and ability tests in `tests/SdAiAgent/Abilities/BlockAbilitiesTest.php`.

## Why

The biggest qualitative ROI in the block-mcp comparison. Without stable refs, every "delete the third paragraph, then update the heading after it" workflow forces the agent to re-read the page after each step (path indices shift). With refs, one read covers a multi-step edit chain — fewer tool calls, lower cost, better correctness on cheap models.

This is the foundation for t245 (mutator), t246 (atomic batch), t247 (optimistic concurrency), t250 (dual-storage), and t251 (depth caps).

## Source pattern

Adapt from `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-reader.php` (846 LOC, GPL-2.0-or-later — compatible). Specifically:

- `Block_Reader::ensure_refs()` / ref-assignment helpers — UUID generation + recursive walk.
- The no-revision direct DB write pattern (`$wpdb->update( $wpdb->posts, ['post_content' => …], ['ID' => …] );` + `clean_post_cache()`).
- The `attrs.metadata.gk_ref` metadata slot — we use `sd_ref` per `AGENTS.md` canonical naming rules.

**Naming discipline (canonical):** `gk_ref` → `sd_ref`; `gk_block_api_*` options → `sd_ai_agent_block_*`; PHP namespace `GravityKit\BlockAPI\` → `SdAiAgent\Core\`. Ability ID is `sd-ai-agent/get-page-blocks` (per `AGENTS.md` canonical: REST namespace stays `sd-ai-agent/v1`, never `superdav-ai-agent/`).

## Files to modify / create

- **New:** `includes/Core/BlockReferences.php`
- **New:** `tests/SdAiAgent/Core/BlockReferencesTest.php`
- **Modify:** `includes/Abilities/BlockAbilities.php` — register `sd-ai-agent/get-page-blocks` ability
- **Modify:** `tests/SdAiAgent/Abilities/BlockAbilitiesTest.php` — add coverage for new ability
- **Modify:** `includes/Plugin.php` if DI wiring needed (see `docs/x-wp-di.md` — `compile_class` required for hyphenated IDs)
- **Modify:** `composer.json` only if a new PSR-4 mapping is required (unlikely; `SdAiAgent\\Core\\` already mapped)

## Acceptance criteria

1. Calling `sd-ai-agent/get-page-blocks` on a post with no refs assigns refs and persists them via a direct DB write that does **not** create a revision (verified by `wp_count_posts( 'revision' )` before/after).
2. UUID format: `blk_` + 8 URL-safe base64url chars; collision-checked within the document.
3. Existing refs are preserved across subsequent reads.
4. `persist_refs: false` returns refs in the response but does not write to DB.
5. `find_by_ref` correctly resolves to nested blocks at depth ≥ 5.
6. Tree walks respect a hard depth cap of 32 (raises `block_depth_exceeded` `WP_Error`) — see t251 for the shared constant.
7. PHPUnit: full suite passes (`npm run test:php`); new tests cover happy-path, missing-ref, nested-block, depth-cap, and `persist_refs: false`.
8. Lint clean: `npm run lint:php`, `composer phpstan` zero errors.
9. No revisions created on first read of a post (verified in tests).

## Verification

Run `npm run verify` (= `lint` → `phpstan` → `test:php` → `build`). Paste summary into PR body under `## Worker self-verification` per `AGENTS.md`.

Manual smoke (optional, against `../wordpress` dev install):

```bash
wp eval 'var_dump( wp_get_ability( "sd-ai-agent/get-page-blocks" )->execute( ["post_id" => 1] ) );'
wp post revisions list 1   # should NOT show a new revision from the read above
```

## Tier rationale

`tier:thinking`. New core class, new ability, DB-write side-effect that must skip revisions, integration with WP block parser and existing `BlockAbilities`. Single-file edits are insufficient — requires judgment on UUID format, collision handling, and the side-effect semantics.

## Dependencies

- **Blocks:** t245, t246, t247, t250, t251 (they all assume `sd_ref` exists).
- **Blocked by:** none.

## PR conventions

Leaf task — PR body uses `Resolves #<this-issue>`.
