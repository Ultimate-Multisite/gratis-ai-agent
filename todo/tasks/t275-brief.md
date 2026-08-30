# t275 — Wave 3.11: Bindings field shape consistency across read abilities

## Pre-flight

- [x] Memory recall: `bindings has_bindings bound_attributes field shape` → noted during wave-2 t259 verification; `bindings` field shape is set in `get-page-blocks` but consistency across the rest of the read surface (`get-block`, `review-block`, etc.) isn't audited.
- [x] Discovery pass: 0 PRs touch this. Wave-2 #1763 added `bindings` (and `bound_attributes`) to `get-page-blocks` output; other read paths may emit it differently or not at all.
- [x] File refs verified — Superdav existing: `includes/Core/BlockReferences.php`, `includes/Abilities/BlockAbilities.php` (get-page-blocks, review-block, get-block-type), and possibly the `update-blocks` dry-run output.
- [x] Tier: `tier:thinking` — small audit + alignment task, but the canonical shape becomes a public contract.
- [x] Seeded draft PR decision: skipped.

## Origin

- **Created:** 2026-05-24
- **Session:** opencode interactive (block-mcp wave-3 adoption)
- **Parent task:** TBD
- **Conversation context:** Wave 3 child 11/11. Wave-2 verification surfaced that `get-page-blocks` returns a `bindings` field for bound blocks. We need to ensure every other read path that emits block data uses the **same** field name and shape, and that the field is consistently documented in `description` strings (so agents know what to expect).

## What

### Step 1 — audit

Grep every read path that emits a block dict, and produce a matrix:

| Read path | Field name | Field type | Nested under `attributes`? | Documented? |
|---|---|---|---|---|
| `get-page-blocks` | `bindings` | object\|null | no — top-level | partially |
| `get-page-blocks` | `bound_attributes` | string[]\|null | no — top-level | partially |
| `review-block` | ? | ? | ? | ? |
| `get-block-type` | n/a (definition only) | — | — | — |
| `update-blocks` dry-run result | ? | ? | ? | ? |
| `edit-block-tree` dry-run result | ? | ? | ? | ? |
| `parse-block-content` | ? | ? | ? | ? |
| `validate-block-content` | ? | ? | ? | ? |

### Step 2 — canonical shape

Pick one and apply everywhere that emits a parsed block. Recommendation:

```json
{
  "ref":                "blk_xyz",
  "name":               "core/paragraph",
  "attributes":         { ... },
  // Bindings always emitted when the block has them; null OR absent when none.
  "bindings": {
    "content": { "source": "core/post-meta", "args": { "key": "subtitle" } }
  },
  "bound_attributes": ["content"]
}
```

Decision: emit **`null`** (not absent) when the block has no bindings, so clients can switch on a single key. `bound_attributes` is **always** an array (empty when no bindings) to avoid `null`-or-array typing.

### Step 3 — apply

Wherever the matrix shows a divergence, change the emitter to match the canonical shape. Wherever the field is missing, add it (it's cheap to read from `attrs.metadata.bindings`).

### Step 4 — document

Update the ability `description` strings to mention `bindings` and `bound_attributes` and link to the write-side override flag `allow_bound_writes` (from #1763 / wave 2).

### Step 5 — regression guard

Add a test that walks every read ability and asserts: if the response contains a block dict, that dict has both `bindings` (object\|null) and `bound_attributes` (string[]).

## Why

Wave-2 surfaced the cosmetic but real issue that agents can't reliably check "does this block have bindings" because different read paths use different field names / shapes / presence rules. This is a small, mechanical alignment with a long-term payoff: agents learn one shape, third-party MCP clients generate one set of types.

## Source pattern

No upstream — internal consistency task. Block-mcp's shape uses `bound_attributes` and `bindings` similarly; ours should align.

## Files to modify / create

- **Audit output:** bindings-field matrix from Step 1, retained in the implementation PR history for reference.
- **Modify:** any read-path file that diverges from the canonical shape. Likely candidates: `BlockAbilities.php` (review-block, parse-block-content), the dry-run output paths for `update-blocks` / `edit-block-tree`.
- **Modify:** affected ability `description` strings to mention `bindings` + `bound_attributes`.
- **New:** `tests/SdAiAgent/Bootstrap/BindingsFieldConsistencyTest.php` — the regression guard.

## Acceptance criteria

1. Audit document exists in PR and is committed alongside the fixes.
2. Every read path that emits a parsed block dict includes both `bindings` and `bound_attributes` fields.
3. When a block has zero bindings, `bindings: null` and `bound_attributes: []` (NOT absent, NOT missing).
4. When a block has bindings, `bindings: { <attr_key>: { source, args } }` and `bound_attributes: [<attr_keys>]`.
5. Regression test asserts the contract on at least 4 read paths: `get-page-blocks`, `review-block`, `update-blocks` dry-run, `edit-block-tree` dry-run.
6. Ability descriptions mention the fields and link the override flag.
7. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval --user=admin '
  $id = wp_insert_post([
    "post_title"   => "binding shape",
    "post_content" => "<!-- wp:paragraph {\"metadata\":{\"bindings\":{\"content\":{\"source\":\"core/post-meta\",\"args\":{\"key\":\"x\"}}}}} --><p>x</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>unbound</p><!-- /wp:paragraph -->",
    "post_type"    => "page",
    "post_status"  => "publish",
  ]);

  foreach (["get-page-blocks", "review-block"] as $ab) {
    $r = wp_get_ability("sd-ai-agent/$ab")->execute(["post_id" => $id]);
    $first = $r["blocks"][0] ?? null;
    echo "$ab: bindings=" . var_export($first["bindings"] ?? "MISSING", true) . " bound=" . var_export($first["bound_attributes"] ?? "MISSING", true) . PHP_EOL;
  }
  wp_delete_post($id, true);
'
```

## Tier rationale

`tier:thinking` — the shape choice (null-vs-absent, always-array-vs-array-or-null) is a long-term contract. Once we ship it, third-party MCP clients will generate types from the shape and we can't drift.

## Dependencies

- **Blocked by:** none. #1763 (wave 2 t259) is on `main`.
- **Soft conflict with:** t274 (innerBlocks ref surfacing) — both touch the read serialiser. Coordinate via separate commits; no shared functions.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #<parent>`.
