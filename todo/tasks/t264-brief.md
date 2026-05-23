# t264 — Wave 2.10: Per-post rate limiting (write 10/min, rewrite 2/min)

## Pre-flight

- [x] Memory recall: `rate limit transient per-post 429 retry-after` → 0 hits
- [x] Discovery pass: 0 open PRs touch rate limiting. Underlying transient API and `wp_safe_remote_*` rate-limit patterns well established in WP.
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-writer.php:111` (`check_rate_limit`), `:178` (`record_rate_limit`), L766/857/913/1135/1156/1242/1309/1386/1420/1537 (per-op call sites)
- [x] Tier: `tier:thinking` — touches every write path; misuse can lock out legitimate work; must coordinate with atomic-batch semantics (one batch = one bucket tick, not N)
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-23
- **Session:** opencode interactive (block-mcp wave-2 adoption)
- **Parent task:** #1739
- **Conversation context:** Wave 2 child 10/10. Multi-agent or shared-tenant scenarios can blow up a post by hammering writes. Bucket per *post* (not per *user*) keeps protection effective even when one user runs multiple agents.

## What

Add a per-post, per-bucket rate limiter:

- **Bucket `write`:** 10 ops/60s/post. Applies to `edit-block-tree`, `update-blocks` (one bucket-tick per batch, not per op), `update-post`, `create-post` (per *target* post for updates; per *user* for create), `insert-pattern`, `revert-to-revision`, `replace-block-range`.
- **Bucket `rewrite`:** 2 ops/60s/post. Applies to `rewrite-post-blocks`. Independent of `write` bucket (a rewrite does not consume a write tick).

Storage: WP transient `sd_ai_agent_rl_{bucket}_{post_id}` containing a JSON array of unix timestamps for ops within the last 60s. Old entries trimmed on each check.

Response on limit exceeded:

```php
new WP_Error(
  'rate_limit_exceeded',
  __( 'Rate limit exceeded for this post.', 'superdav-ai-agent' ),
  [
    'status'              => 429,
    'bucket'              => 'write',     // or 'rewrite'
    'limit'               => 10,
    'window_seconds'      => 60,
    'retry_after_seconds' => 12,            // until oldest tick falls off
    'post_id'             => 123,
  ]
);
```

REST controller maps the `status: 429` into the HTTP response header `Retry-After: 12`.

## Why

- Multi-agent: parallel workers writing the same post saturate revisions and confuse optimistic concurrency.
- Stuck-loop guard: an agent in a fix/retry loop can otherwise burn 1000 revisions/hour.
- Bucket per *post* (not per *user*): a single super-admin running 3 agents against post 156 is still capped at 10/min on that post.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-writer.php:111-180` (check + record), and the 9 call sites listed in pre-flight. GPL-2.0-or-later. Adapt the transient-key naming to our `sd_ai_agent_` prefix.

## Files to modify / create

- **New:** `includes/Core/RateLimiter.php` — `check( $bucket, $post_id ): true|WP_Error`, `record( $bucket, $post_id ): void`. Filterable limits via `sd_ai_agent_rate_limits` filter (returns `[ 'write' => 10, 'rewrite' => 2 ]`).
- **Modify:** `includes/Core/BlockMutator.php` — wire `check`/`record` into every write op. Atomic batch ticks once per batch.
- **Modify:** `includes/Abilities/BlockAbilities.php` — surface the 429 + `retry_after_seconds` from WP_Error into the ability response.
- **Modify:** `includes/REST/RestController.php` — when an ability returns `rate_limit_exceeded`, set HTTP 429 + `Retry-After` header.
- **Modify:** every write-ability call site (edit-block-tree, update-blocks, update-post, create-post, insert-pattern from t256, revert-to-revision from t257, replace-block-range from t261, rewrite-post-blocks from t262, upload-media from t263 — write bucket).
- **New:** `tests/SdAiAgent/Core/RateLimiterTest.php` — bucket isolation (write vs rewrite independent), per-post isolation (post A doesn't consume post B's bucket), transient pruning (entries > 60s old discarded), HTTP 429 status + Retry-After header, atomic-batch single tick, custom-limit filter.

## Acceptance criteria

1. 10 successive `edit-block-tree` calls on post 156 within 60s → 11th call returns `rate_limit_exceeded` with `retry_after_seconds` populated.
2. Same post 156, a `rewrite-post-blocks` call concurrently → succeeds (separate bucket).
3. Atomic batch of 30 ops on post 156 → counts as 1 tick (not 30).
4. After waiting `retry_after_seconds`, the next call succeeds.
5. Post 200 unaffected by post 156's bucket.
6. HTTP response carries `Retry-After: N` header when 429.
7. Filter `sd_ai_agent_rate_limits` returning `[ 'write' => 100 ]` raises the cap.
8. Transient cleanup: an old transient with entries outside the window is pruned on next check, not stale.
9. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
# Spam edit-block-tree to trip the limit.
wp eval '
  for ($i = 0; $i < 12; $i++) {
    $r = wp_get_ability("sd-ai-agent/edit-block-tree")->execute([
      "post_id" => 156, "ref" => "sd_ref:<some-ref>", "op" => "update-attrs",
      "attrs" => [ "className" => "rl-probe-" . $i ],
    ]);
    if ( is_wp_error( $r ) ) {
      echo $i . " -> " . $r->get_error_code() . " retry=" . $r->get_error_data()["retry_after_seconds"] . PHP_EOL;
    } else {
      echo $i . " -> ok" . PHP_EOL;
    }
  }
'
```

## Tier rationale

`tier:thinking` — touches every write path, has interactions with atomic-batch (one tick per batch), interacts with REST status-code mapping, filterable limits, and risk of locking out legitimate writers if the contract is misimplemented.

## Dependencies

- **Blocked by:** none.
- **Soft coordination:** t262 (rewrite-post-blocks) needs the `rewrite` bucket — both ship guarded if order varies.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #1739`.
