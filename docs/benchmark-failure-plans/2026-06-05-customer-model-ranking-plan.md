# Plan: customer-facing model ranking from benchmark evidence

## Goal

Track which configured model is best for Superdav AI Agent customers to use, without confusing provider outages or quota exhaustion with model capability.

## Current evidence from 2026-06-05 run

Only `syn:large:text` completed meaningful work before the Synthetic provider quota was exhausted.

| Model | Completed meaningful suites | Best evidence | Current recommendation |
|---|---:|---|---|
| `syn:large:text` | 2 full suites + 2 ability questions | `content-v1` 100%, `functional-v1` 97% | Provisional best |
| `syn:large:vision` | 0 | All questions blocked by HTTP 402 quota | Inconclusive |
| `syn:small:text` | 0 | All questions blocked by HTTP 402 quota | Inconclusive |
| `syn:small:vision` | 0 | All questions blocked by HTTP 402 quota | Inconclusive |

## Ranking dimensions

A customer-facing recommendation should combine:

1. **Task success** — assertion pass rate and question pass rate.
2. **Reliability** — agent errors, provider errors, retries, timeouts.
3. **Efficiency** — average turns, runtime, token usage, and cost if available.
4. **Coverage** — number and diversity of suites completed.
5. **Safety/compliance** — no secret leakage, no unsafe tool calls, no private REST dispatcher misuse.
6. **Fit by task class** — content generation, plugin generation, media/design, developer operations, credentialed integrations.

## Eligibility rules

A model should not appear as `recommended` unless it has:

- completed `content-v1` and `functional-v1` without provider-level blocking;
- completed at least one abilities suite;
- no critical safety failures;
- a minimum completed-question count, e.g. 25 questions;
- provider error rate below 5% for the evaluated run.

Models below that threshold should be labelled `inconclusive`, `blocked`, or `experimental`.

## Proposed score formula

```text
customer_score =
  0.45 * assertion_pass_rate
+ 0.20 * question_pass_rate
+ 0.15 * suite_coverage_rate
+ 0.10 * reliability_score
+ 0.10 * efficiency_score
```

Provider-level blocked questions should be excluded from model capability scoring but included in provider reliability notes.

## Data to persist per run

For each model/suite/question:

- provider ID and model ID;
- suite and question ID;
- result status;
- assertion count and pass count;
- agent errors and provider errors;
- wall time;
- turns used;
- token usage;
- generated plugin/content artifact references;
- exact provider error class/message when blocked.

## Next run design

After Synthetic quota is replenished:

1. Run one short preflight question for every model.
2. Run `content-v1` for every model.
3. Run `functional-v1` for every model.
4. Run ability suites in round-robin order.
5. Stop only the affected model on repeated provider errors unless every model sees the same provider error.

## Current recommendation text

> Based on the latest completed evidence, `syn:large:text` is the only Synthetic model with enough successful benchmark signal to recommend provisionally. It passed content generation at 100% and functional plugin/content tasks at 97%. The other Synthetic models were not evaluated because provider quota was exhausted before they could run; they should be treated as inconclusive until the matrix is rerun with sufficient quota.
