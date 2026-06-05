# Plan: improve benchmark scoring for all-agent-error suites

## Failure

Many suite/model runs failed every question with provider-level HTTP 402 errors, but their summaries showed:

```text
Assertions    : 0/0 passed
```

with no score line. This is technically true because no assertions ran, but it is not useful for model ranking.

## Evidence

Run root:

```text
wp-content/uploads/sd-ai-agent/benchmark-runs/20260605T171006Z-synthetic-all/
```

Examples:

```text
syn:large:vision / content-v1: 7 questions, 7 agent errors, 0/0 assertions
syn:small:text / functional-v1: 8 questions, 8 agent errors, 0/0 assertions
syn:small:vision / abilities-utility-v1: 13 questions, 13 agent errors, 0/0 assertions
```

All failed with:

```text
Client error (402): Request was rejected due to client-side issue - Insufficient quota and token balance
```

## Impact

- Customer-facing model recommendations cannot distinguish:
  - a model that failed all tasks,
  - a provider that was unavailable,
  - a suite that had no assertions,
  - a run that was blocked before any assertions executed.
- Automated scoreboards may sort `0/0` rows incorrectly or omit them.
- Follow-up work is harder because failure cause is repeated in per-question text but not summarized structurally.

## Remediation plan

1. **Add question-level statuses.**
   - `passed`
   - `assertion_failed`
   - `agent_error`
   - `provider_error`
   - `skipped`
   - `blocked`

2. **Add provider-error parsing.**
   - Classify common transport errors from `AgentLoop` / provider exceptions:
     - HTTP 402: `provider_quota_exhausted`
     - HTTP 429: `provider_rate_limited`
     - HTTP 5xx: `provider_unavailable`
     - timeout: `provider_timeout`

3. **Change suite summary output.**
   - Include:
     - `Questions run`
     - `Questions passed`
     - `Assertion failures`
     - `Agent errors`
     - `Provider errors`
     - `Assertions passed/total`
     - `Model score`
     - `Run status`
   - Suggested run statuses:
     - `complete`
     - `partial`
     - `blocked_provider`
     - `inconclusive`

4. **Persist machine-readable suite summaries.**
   - Alongside per-question JSON logs, write a `suite-summary.json` with normalized statuses.
   - This lets ranking tools ignore inconclusive provider failures.

5. **Define ranking eligibility.**
   - A model is eligible for customer recommendation only if:
     - at least N questions completed without provider errors,
     - at least one content or functional suite completed,
     - provider errors are below a configured threshold.

## Verification

Run with an intentionally invalid/quota-exhausted provider credential and confirm summary says `blocked_provider`, not only `0/0 assertions`.

Then run a known-good model and confirm normal scores remain unchanged.

```bash
wp sd-ai-agent benchmark run --suite=content-v1 --provider=ai-provider-for-any-openai-compatible --model=syn:large:text
```

## Success criteria

- All-provider-error runs are visibly marked `blocked_provider` / `inconclusive`.
- Ranking reports exclude blocked provider runs from model quality comparisons.
- The text output and JSON logs both preserve the exact provider error message for diagnostics.
