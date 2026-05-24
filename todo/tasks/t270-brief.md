# t270 — Wave 3.6: Port block-mcp stress test suites

## Pre-flight

- [x] Memory recall: `mutation chaos ref collision unicode depth stress test` → 0 hits.
- [x] Discovery pass: 0 stress tests in `tests/SdAiAgent/`. Existing tests are unit + integration only.
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/tests/Stress/` (5 files: `MutationChaosTest.php`, `AutoTransformCombinatoricsTest.php`, `MaxBlockDepthTest.php`, `RefCollisionStressTest.php`, `UnicodePathologiesTest.php`).
- [x] Tier: `tier:standard` — port of established tests; the logic is well-defined.
- [x] Seeded draft PR decision: skipped.

## Origin

- **Created:** 2026-05-24
- **Session:** opencode interactive (block-mcp wave-3 adoption)
- **Parent task:** TBD
- **Conversation context:** Wave 3 child 6/11. block-mcp's stress suite caught real bugs upstream (ref-collision under concurrent writes; unicode in slugs and attributes confusing the parser; depth-cap edge cases). We have **zero** stress tests despite a far larger surface (30+ abilities, multi-mutation atomic batches). Porting them upgrades our regression net cheaply.

## What

Create `tests/SdAiAgent/Stress/` with 5 PHPUnit test classes, ported one-for-one from block-mcp with the namespace/class-name changes needed for our codebase:

1. **`MutationChaosTest.php`** — Randomised sequence of 1000 mutation ops (insert, update, replace, remove, wrap, unwrap, move, duplicate) against a seeded tree. After each op, re-parse and assert tree validity (no orphaned refs, no depth-cap violation, no dual-storage corruption). Uses a deterministic seed env var `SD_AI_AGENT_CHAOS_SEED` so failures are reproducible.

2. **`AutoTransformCombinatoricsTest.php`** — Iterate every (attribute_name × value × block_name) combination registered in `HtmlTransformer` (existing in `includes/Core/HtmlTransformer.php`). For each, run `update-blocks { op: update-attrs }` and assert the innerHTML is regenerated correctly. Catches off-by-one in the transformer's attribute → DOM-attribute map.

3. **`MaxBlockDepthTest.php`** — Construct trees at exactly `MAX_BLOCK_DEPTH`, one above, one below. Assert: at-limit succeeds, over-limit rejects with `depth_cap_exceeded`, under-limit succeeds. Also tests the recursive-validation path on `wrap-in-group` (one wrap on an at-limit tree → over-limit reject).

4. **`RefCollisionStressTest.php`** — Generate 100k `sd_ref` UUIDs via `BlockReferences::generate_ref()` and assert zero collisions. Then simulate two concurrent writes against the same post by manually re-using a ref and assert the second write detects the collision.

5. **`UnicodePathologiesTest.php`** — Run mutations with attribute values containing: ZWJ (`U+200D`), BiDi controls (`U+202E`), homoglyph slugs (Cyrillic 'а' vs Latin 'a'), CRLF in innerHTML, NULs, 4-byte emoji clusters, combining marks. Assert: parser doesn't crash, refs survive serialise→parse round-trip, sanitiser strips dangerous characters but preserves benign ones.

## Why

A stress suite is the difference between catching a regression in PR CI and shipping it to users. block-mcp learned this the hard way (their MutationChaosTest exists because of a real corruption bug). Our mutation surface is larger than theirs after wave 2 (atomic batches of up to 50 ops; replace-block-range with N-for-M swap), so the value transfers and amplifies.

The suite is also a stable fuzzer: if a future refactor breaks something subtle (e.g. `BlockMutator::normalize_block` losing a key), the chaos test surfaces it in O(seconds), not in a user bug report.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/tests/Stress/` — all 5 files. GPL-2.0-or-later.

Per-file port notes:

- Replace `\GK_BlockApi\` namespace → `\SdAiAgent\`.
- Replace `gk_ref` → `sd_ref` (the attribute key) where it appears in fixtures.
- Replace REST namespace `gk-block-api/v1` → `sd-ai-agent/v1`.
- Keep the seed envvar name family but prefix `SD_AI_AGENT_` (e.g. `SD_AI_AGENT_CHAOS_SEED`).
- Class headers use our standard SPDX line; tests inherit our existing `tests/bootstrap.php` base class.

## Files to modify / create

- **New:** `tests/SdAiAgent/Stress/MutationChaosTest.php`
- **New:** `tests/SdAiAgent/Stress/AutoTransformCombinatoricsTest.php`
- **New:** `tests/SdAiAgent/Stress/MaxBlockDepthTest.php`
- **New:** `tests/SdAiAgent/Stress/RefCollisionStressTest.php`
- **New:** `tests/SdAiAgent/Stress/UnicodePathologiesTest.php`
- **Modify:** `phpunit.xml.dist` — add `<testsuite name="stress">` so CI can run stress separately (and skip it on fast PR feedback if needed).
- **Modify:** `composer.json` scripts — `"test:php:stress": "phpunit --testsuite=stress"`.
- **Modify:** `.github/workflows/ci.yml` (or equivalent) — add a `stress` job that runs `npm run test:php:stress` on a nightly cadence, NOT on every PR (to keep PR feedback fast).

## Acceptance criteria

1. All 5 test classes exist under `tests/SdAiAgent/Stress/`.
2. `phpunit --testsuite=stress` runs all 5 with a single command.
3. Default seed produces a deterministic pass; `SD_AI_AGENT_CHAOS_SEED=12345` re-runs the same sequence.
4. `MutationChaosTest` runs 1000 ops within 30 s on a dev workstation (relax in CI if needed; record budget in test docblock).
5. `MaxBlockDepthTest` proves at-limit, over-limit, under-limit, and one-wrap-puts-you-over.
6. `RefCollisionStressTest` 100k generation produces zero collisions (deterministic given UUIDv4 entropy; this is more of a smoke check on the generator).
7. `UnicodePathologiesTest` covers each of the 7 pathology classes listed above with at least one round-trip assertion.
8. Nightly CI workflow exists; PR CI does NOT block on stress.
9. Full PHPUnit (regular suite) + phpstan + lint clean.

## Verification

```bash
npm run test:php:stress
echo "Default seed run: $?"

SD_AI_AGENT_CHAOS_SEED=42 npm run test:php:stress
echo "Seeded run: $?"
```

## Tier rationale

`tier:standard` — straightforward port of well-defined tests. Risk surface is the namespace/identifier remap and the CI cadence change. No production code touched.

## Dependencies

- **Blocked by:** none.
- **Soft dependency:** ports are easier after t274 (innerBlocks ref surfacing) but the stress tests can work directly with the tree array regardless.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #<parent>`.
