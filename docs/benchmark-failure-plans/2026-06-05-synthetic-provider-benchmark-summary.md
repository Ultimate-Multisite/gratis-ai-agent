# Synthetic provider benchmark, scoring, and customer ranking — 2026-06-05

## Scope

Ran every registered benchmark suite against every configured Synthetic model exposed by the compatible-endpoints provider.

Provider ID: `ai-provider-for-any-openai-compatible`

Models:

- `syn:large:text`
- `syn:large:vision`
- `syn:small:text`
- `syn:small:vision`

Suites:

- `content-v1`
- `functional-v1`
- `abilities-content-v1`
- `abilities-media-v1`
- `abilities-structure-v1`
- `abilities-design-v1`
- `abilities-developer-v1`
- `abilities-plugin-mgmt-v1`
- `abilities-utility-v1`
- `abilities-credentialed-v1`

Run artifacts are in the local WordPress uploads directory:

```text
wp-content/uploads/sd-ai-agent/benchmark-runs/20260605T171006Z-synthetic-all/
```

## Result matrix

| Model | Suite | Questions | Agent errors | Assertions | Score |
|---|---:|---:|---:|---:|---:|
| `syn:large:text` | `content-v1` | 7 | 0 | 16/16 | 100% |
| `syn:large:text` | `functional-v1` | 8 | 0 | 32/33 | 97% |
| `syn:large:text` | `abilities-content-v1` | 12 | 10 | 4/4 | 100% |
| `syn:large:text` | `abilities-media-v1` | 7 | 7 | 0/0 | — |
| `syn:large:text` | `abilities-structure-v1` | 10 | 10 | 0/0 | — |
| `syn:large:text` | `abilities-design-v1` | 13 | 13 | 0/0 | — |
| `syn:large:text` | `abilities-developer-v1` | 9 | 9 | 0/0 | — |
| `syn:large:text` | `abilities-plugin-mgmt-v1` | 13 | 13 | 0/0 | — |
| `syn:large:text` | `abilities-utility-v1` | 13 | 13 | 0/0 | — |
| `syn:large:text` | `abilities-credentialed-v1` | 7 | 7 | 0/0 | — |
| `syn:large:vision` | all suites | 98 total | 98 | 0/0 | — |
| `syn:small:text` | all suites | 98 total | 98 | 0/0 | — |
| `syn:small:vision` | all suites | 98 total | 98 | 0/0 | — |

The Synthetic account ran out of quota during the `syn:large:text` / `abilities-content-v1` suite. All later questions for all models failed before model reasoning could be evaluated.

## Unique failures identified

1. **Provider quota exhaustion aborts model evaluation.**
   - Error: `Client error (402): Request was rejected due to client-side issue - Insufficient quota and token balance`.
   - Affected: all suites after the first two full `syn:large:text` suites, plus the final 10 questions of `abilities-content-v1`.
   - Planning document: `2026-06-05-synthetic-provider-quota-exhaustion.md`.

2. **Functional benchmark `fn-004` missed the WP-CLI dry-run assertion.**
   - Affected model/suite: `syn:large:text` / `functional-v1`.
   - Assertions: 32/33 suite-wide, 3/4 for `fn-004`.
   - Planning document: `2026-06-05-functional-fn-004-meta-migrator-wp-cli.md`.

3. **Benchmark reporting can show `0/0 assertions` for all-agent-error suites.**
   - Affected: quota-failed suites.
   - Implemented: benchmark suite summaries now classify question status, provider errors, run status, and ranking eligibility in `suite-summary.json`.

## Implemented benchmark reporting changes

The benchmark runner now writes a machine-readable `suite-summary.json` alongside per-question logs with:

- provider ID and model ID;
- suite and run ID;
- question count and questions passed;
- agent errors and provider errors;
- assertion pass/fail totals;
- normalized run status;
- ranking eligibility;
- compact per-question status summaries.

Question-level statuses include:

- `passed`
- `assertion_failed`
- `agent_error`
- `provider_error`

Provider errors are classified from common transport failures:

- HTTP 402 or quota text: `provider_quota_exhausted`
- HTTP 429 or rate-limit text: `provider_rate_limited`
- HTTP 5xx or unavailable text: `provider_unavailable`
- timeout text: `provider_timeout`

Run statuses:

- `complete` — no assertion failures or agent errors;
- `partial` — assertion failures or non-provider agent errors;
- `blocked_provider` — every question failed with provider errors;
- `inconclusive` — some provider errors occurred, so model quality cannot be cleanly ranked.

## Customer-facing model ranking policy

A customer-facing recommendation should combine:

1. **Task success** — assertion pass rate and question pass rate.
2. **Reliability** — agent errors, provider errors, retries, timeouts.
3. **Efficiency** — average turns, runtime, token usage, and cost if available.
4. **Coverage** — number and diversity of suites completed.
5. **Safety/compliance** — no secret leakage, no unsafe tool calls, no private REST dispatcher misuse.
6. **Fit by task class** — content generation, plugin generation, media/design, developer operations, credentialed integrations.

A model should not appear as `recommended` unless it has:

- completed `content-v1` and `functional-v1` without provider-level blocking;
- completed at least one abilities suite;
- no critical safety failures;
- a minimum completed-question count, e.g. 25 questions;
- provider error rate below 5% for the evaluated run.

Proposed customer score:

```text
customer_score =
  0.45 * assertion_pass_rate
+ 0.20 * question_pass_rate
+ 0.15 * suite_coverage_rate
+ 0.10 * reliability_score
+ 0.10 * efficiency_score
```

Provider-level blocked questions should be excluded from model capability scoring but included in provider reliability notes.

## Current customer-facing model recommendation

Only `syn:large:text` produced meaningful benchmark evidence in this run.

Recommendation status:

- **Best available evidence:** `syn:large:text`.
- **Evidence:** 100% on `content-v1`, 97% on `functional-v1`, first 2/12 `abilities-content-v1` questions passed before quota exhaustion.
- **Caveat:** The run is not sufficient to certify `syn:large:text` across all ability suites because quota exhaustion prevented the rest of the matrix from executing.
- **Do not rank yet:** `syn:large:vision`, `syn:small:text`, and `syn:small:vision`; every question for these models failed with provider quota errors before any capability signal was collected.

Suggested recommendation text:

> Based on the latest completed evidence, `syn:large:text` is the only Synthetic model with enough successful benchmark signal to recommend provisionally. It passed content generation at 100% and functional plugin/content tasks at 97%. The other Synthetic models were not evaluated because provider quota was exhausted before they could run; they should be treated as inconclusive until the matrix is rerun with sufficient quota.

## Required rerun after quota fix

Run each model/suite combination in an order that avoids starving later models:

1. Run one smoke question per model to verify provider availability.
2. Run one suite per model in round-robin order.
3. Stop immediately on a provider-level 402/429/5xx threshold and mark the run `inconclusive`, not model-failed.
4. Persist per-model cost, wall time, agent turns, agent errors, and assertion pass rate.

Suggested command pattern:

```bash
wp sd-ai-agent benchmark run \
  --suite=<suite> \
  --provider=ai-provider-for-any-openai-compatible \
  --model=<model> \
  --log-dir=<absolute-run-log-dir>
```
