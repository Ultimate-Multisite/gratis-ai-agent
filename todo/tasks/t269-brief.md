# t269 — Wave 3.5: HTTP `If-Match` / `ETag` optimistic concurrency on block-write REST routes

## Pre-flight

- [x] Memory recall: `If-Match ETag optimistic concurrency revision` → wave-2 ships body-field `expected_revision_id` / `expected_revision` on every write ability; no HTTP-header form.
- [x] Discovery pass: 0 open PRs touch REST headers. block-mcp has a dedicated `tests/REST/IfMatchTest.php` proving the contract.
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-rest-controller.php:389-560` (write handlers) and `tests/REST/IfMatchTest.php`. Superdav existing: `includes/REST/RestController.php`, `includes/Core/RevisionGuard.php`.
- [x] Tier: `tier:standard` — adds a header pair to existing REST endpoints; ability-layer concurrency unchanged.
- [x] Seeded draft PR decision: skipped.

## Origin

- **Created:** 2026-05-24
- **Session:** opencode interactive (block-mcp wave-3 adoption)
- **Parent task:** TBD
- **Conversation context:** Wave 3 child 5/11. Generic MCP clients and REST consumers expect the standard HTTP optimistic-concurrency idiom (`ETag` on the read, `If-Match` on the write, `412 Precondition Failed` on conflict). Superdav's wave-1 work uses body-field `expected_revision` — correct semantically, but inaccessible to clients that expect HTTP headers.

## What

### Read path (response)

On every REST GET that returns block-tree content (`get-page-blocks`, `get-block` if/when added, `get-post`), emit:

```
ETag: W/"rev-{revision_id}"
```

Weak validator (`W/`) because we don't byte-compare. `revision_id` from the existing `RevisionGuard::current_revision_id(...)`. Format is opaque to clients but documented as the latest post revision ID.

### Write path (request)

On every REST POST/PATCH/PUT that mutates a post (`update-blocks` batch, `edit-block-tree`, `replace-block-range`, `rewrite-post-blocks`, `revert-to-revision`, `insert-pattern`, plus future `update-post`), honour an incoming `If-Match` header:

1. If the header is present, parse the format `W/"rev-N"` (case-insensitive `W/`, accept also strong `"rev-N"`). If unparseable → `412 Precondition Failed` with body `{ "code": "invalid_if_match", ... }`.
2. Compare `N` against `RevisionGuard::current_revision_id($post_id)`.
3. If mismatch → return `412 Precondition Failed` with the existing `revision_stale` shape (same code as the body-field path).
4. If match (or header absent), continue.

Body-field `expected_revision` remains supported. If both are present and they agree, proceed. If both are present and they disagree, return `412` with `code: "conflicting_revision_preconditions"`.

### Write path (response)

After a successful write, set the response `ETag` to the **new** revision ID so chained writes don't have to re-GET.

## Why

Three reasons:

1. **Standards alignment** — every HTTP/REST tutorial and most MCP clients treat `If-Match` as the conditional-write idiom. Body-field preconditions are bespoke.
2. **No two-call overhead** — caching proxies and Service Workers can use `ETag` to short-circuit unchanged GETs, important for the block-editor live preview.
3. **Cross-client interoperability** — block-mcp's MCP clients already send `If-Match`; without support we silently overwrite their conflict detection.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-rest-controller.php:389-560` (parsed-header handling). `tests/REST/IfMatchTest.php` (full contract). GPL-2.0-or-later.

Block-mcp also strips the `W/` prefix and quotes during parse; ours should too. RFC 7232 says weak and strong tags compare equal under `If-Match` only if both are strong, but practical implementations are lenient. Follow the lenient path and document.

## Files to modify / create

- **New:** `includes/REST/IfMatchHeader.php` — `parse(string $header): int|WP_Error` (returns the rev id or an error). Idempotent helper, no WP-API side effects.
- **Modify:** `includes/REST/RestController.php` (or wherever the write controllers live) — pre-handler middleware: parse `If-Match`, run the precondition check, short-circuit with 412 on failure. Post-handler middleware: emit `ETag` on the response.
- **Modify:** `includes/REST/McpController.php` (if it routes block writes) — same middleware pair.
- **No changes** to the ability layer — abilities continue to honour body-field `expected_revision`. The REST layer just **adds** an alternative source for the value.
- **New:** `tests/SdAiAgent/REST/IfMatchTest.php` — covers: ETag present on GET, If-Match match → 200, mismatch → 412, missing header → 200 (no-op precondition), unparseable header → 412 `invalid_if_match`, both body+header agreeing → 200, both disagreeing → 412 `conflicting_revision_preconditions`, response ETag advances after write.

## Acceptance criteria

1. `GET /wp-json/sd-ai-agent/v1/posts/156/blocks` response includes header `ETag: W/"rev-218"`.
2. `POST /wp-json/sd-ai-agent/v1/posts/156/blocks/batch-update` with `If-Match: W/"rev-218"` succeeds when current revision is 218.
3. Same call with `If-Match: W/"rev-1"` → HTTP 412, body `{ code: "revision_stale", ... }`.
4. `If-Match: garbage` → HTTP 412, body `{ code: "invalid_if_match", ... }`.
5. Successful write response includes the new `ETag` (e.g. `W/"rev-220"`).
6. Body-field `expected_revision: 218` with NO `If-Match` header → unchanged behaviour from main.
7. Body-field `expected_revision: 218` with `If-Match: W/"rev-217"` → HTTP 412 `conflicting_revision_preconditions`.
8. Strong tag form `If-Match: "rev-218"` accepted (lenient compare).
9. `*` wildcard (`If-Match: *`) accepted as "any existing revision" — short-circuits the check (RFC 7232 §3.1).
10. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
# Read returns ETag
curl -i -u admin:admin "http://wordpress.local:8080/wp-json/sd-ai-agent/v1/posts/156/blocks" | grep -i etag

# Write with stale If-Match returns 412
curl -i -u admin:admin -X POST -H 'If-Match: W/"rev-1"' \
  -H 'Content-Type: application/json' \
  -d '{"updates":[{"op":"update-html","ref":"blk_x","innerHTML":"<p>x</p>"}]}' \
  "http://wordpress.local:8080/wp-json/sd-ai-agent/v1/posts/156/blocks/batch-update"
```

## Tier rationale

`tier:standard` — REST middleware addition with a tightly-scoped header parser. The trickiest part is the `*` wildcard and the conflicting-preconditions matrix; both are in the test list.

## Dependencies

- **Blocked by:** none. `RevisionGuard` already exists from wave 1.
- **Soft dependency:** future `update-post` REST route should adopt the same middleware (out of scope here; document in the file).

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #<parent>`.
