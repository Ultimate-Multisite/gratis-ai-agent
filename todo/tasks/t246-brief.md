# t246 — Tier 1.3: Atomic batch block updates with pre-flight validation

## Pre-flight

- [x] Memory recall: `atomic batch revision wordpress posts` → 0 hits — fresh territory
- [x] Discovery pass: 0 open PRs touch batch-write paths in `includes/Abilities/`
- [x] File refs verified: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-writer.php:897-1060` (batch path), `class-block-crud.php:49` (MAX_BATCH_SIZE = 50)
- [x] Tier: `tier:thinking` — all-or-nothing semantics + WP revision plumbing
- [x] Seeded draft PR decision: skipped — open after batch validator and rollback test pass

## Origin

- **Created:** 2026-05-22
- **Session:** opencode interactive (block-mcp adoption review)
- **Parent task:** none (sibling group t244–t254)
- **Conversation context:** Tier 1 item 3 — revision-history hygiene for multi-edit operations.

## What

Add ability `sd-ai-agent/update-blocks` that applies up to **50** independent block updates atomically inside **one** WordPress revision, with all-or-nothing pre-flight validation.

Behaviour:

1. Caller passes `updates: [ { ref|path|flat_index, op, … }, … ]` (max 50).
2. Validator runs every update against an in-memory tree copy.
3. If **any** update fails validation (stale ref, out-of-range index, dual-storage violation, depth-cap breach, duplicate target), the whole call rejects with HTTP 400 `batch_validation_failed` and per-item errors in `data.errors[]`. **Nothing hits disk.**
4. If all pass, serialise once, write once, one revision.
5. Counts as **one** write against any rate-limit bucket.

## Why

Today, applying N edits creates N revisions and N round-trips. Sites with even moderate revision retention get noisy quickly. More importantly, a partial-success scenario (3 of 5 edits applied, then a stale ref aborts) leaves the post in an indeterminate state that the agent can't reason about. Atomic batches make multi-edit flows transactional.

## Source pattern

Adapt from `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-writer.php` lines ~897–1060 (`update_blocks` method) and the validator loop in `class-block-crud.php`. The pattern is: clone tree → apply each op to clone, collect errors → if any errors, return aggregate WP_Error → else, single `wp_update_post()`.

## Files to modify / create

- **Modify:** `includes/Core/BlockMutator.php` (from t245) — add `apply_batch( array $blocks, array $updates ): array|WP_Error`.
- **Modify:** `includes/Abilities/BlockAbilities.php` — register `sd-ai-agent/update-blocks`.
- **Modify:** `includes/REST/RestController.php` — route or controller method.
- **New:** `tests/SdAiAgent/Core/BlockMutatorBatchTest.php` — atomicity, partial-failure rollback, size cap, empty batch.

## Acceptance criteria

1. Successful 5-update batch produces exactly **1** WordPress revision (asserted via `wp_get_post_revisions`).
2. A batch where item 3 of 5 has a stale ref returns HTTP 400 `batch_validation_failed` with **all** errors itemised; **zero** revisions and zero changes to `post_content`.
3. `updates: []` returns HTTP 400 `empty_batch`.
4. `updates` length > `MAX_BATCH_SIZE` (50) returns HTTP 400 `batch_too_large` with `data.max_batch_size`.
5. Duplicate targets within a single batch (two ops on the same ref) return `batch_validation_failed` with `duplicate_target` per item — last-write-wins is rejected explicitly.
6. PHPUnit covers all five paths above; full suite passes.
7. PHPStan + lint clean.

## Verification

`npm run verify`. Plus a manual atomicity check:

```bash
wp eval '
$before = count( wp_get_post_revisions( 1 ) );
wp_get_ability("sd-ai-agent/update-blocks")->execute(["post_id"=>1, "updates"=>[ /* 5 valid ops */ ]]);
$after = count( wp_get_post_revisions( 1 ) );
var_dump( $after - $before );  // expect 1
'
```

## Tier rationale

`tier:thinking`. Atomicity + WordPress revision semantics + error aggregation. Not a single-file mechanical change.

## Dependencies

- **Blocked by:** t244 (refs), t245 (mutator — batch is N×single-op).
- **Pairs with:** t247 (If-Match revision lock — batch consumes one revision).

## PR conventions

Leaf — `Resolves #<this-issue>`.
