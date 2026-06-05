# Synthetic provider benchmark run summary — 2026-06-05

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
   - This makes scoreboards hard to compare and can hide that no benchmark assertions actually executed.
   - Planning document: `2026-06-05-benchmark-runner-agent-error-scoring.md`.

## Current customer-facing model recommendation

Only `syn:large:text` produced meaningful benchmark evidence in this run.

Recommendation status:

- **Best available evidence:** `syn:large:text`.
- **Evidence:** 100% on `content-v1`, 97% on `functional-v1`, first 2/12 `abilities-content-v1` questions passed before quota exhaustion.
- **Caveat:** The run is not sufficient to certify `syn:large:text` across all ability suites because quota exhaustion prevented the rest of the matrix from executing.
- **Do not rank yet:** `syn:large:vision`, `syn:small:text`, and `syn:small:vision`; every question for these models failed with provider quota errors before any capability signal was collected.

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
