# Plan: handle Synthetic provider quota exhaustion during benchmark matrices

## Failure

The benchmark matrix hit a provider-level quota failure:

```text
Client error (402): Request was rejected due to client-side issue - Insufficient quota and token balance: consider upgrading your subscription on https://synthetic.new/billing
```

## Evidence

Run root:

```text
wp-content/uploads/sd-ai-agent/benchmark-runs/20260605T171006Z-synthetic-all/
```

Observed sequence:

- `syn:large:text` / `content-v1`: 7 questions, 0 agent errors, 16/16 assertions.
- `syn:large:text` / `functional-v1`: 8 questions, 0 agent errors, 32/33 assertions.
- `syn:large:text` / `abilities-content-v1`: first 2 questions passed, remaining 10 questions failed with HTTP 402.
- Every later suite/model failed every question with the same HTTP 402 before assertions ran.

## Impact

- The matrix cannot rank `syn:large:vision`, `syn:small:text`, or `syn:small:vision`; no model-capability signal was collected for them.
- The current benchmark run can only support a provisional recommendation for `syn:large:text`.
- Provider-level failures consume benchmark time while producing `0/0 assertions` rows that look like empty suites rather than blocked runs.

## Root-cause hypothesis

The Synthetic account/token configured in `Ultimate AI Connector for Compatible Endpoints` had insufficient quota for a full 4-model × 10-suite run. The first two full suites plus two ability questions exhausted the token balance.

## Remediation plan

1. **Add benchmark provider preflight.**
   - Before running a suite, issue a minimal prompt against the target provider/model.
   - Detect provider transport errors, especially HTTP 402, 429, and 5xx.
   - If preflight fails, mark the model/suite as `blocked_provider` and skip the suite.

2. **Add provider-error classification to `BenchmarkCommand`.**
   - Distinguish model/agent failure from provider unavailability.
   - Persist fields per question: `status`, `provider_error_code`, `provider_error_message`, `assertions_ran`.
   - Treat HTTP 402 as `inconclusive_provider_quota`, not model failure.

3. **Add a matrix runner that does round-robin scheduling.**
   - Current sequential model-major order let `syn:large:text` consume all quota before later models were evaluated.
   - Round-robin order gives every model at least one suite/question before quota exhaustion.

4. **Add a stop threshold.**
   - If a model has N consecutive provider-level errors, stop that model.
   - If all models return the same provider-level error, stop the entire matrix.

5. **Record cost/usage if exposed.**
   - Capture token usage from `AgentLoop` and provider response metadata where available.
   - Include cost-per-passed-assertion in customer model recommendations.

## Verification

After quota is replenished or a fresh Synthetic key is configured:

```bash
wp sd-ai-agent models --provider=ai-provider-for-any-openai-compatible --format=json
wp sd-ai-agent benchmark run --question=ac-001 --provider=ai-provider-for-any-openai-compatible --model=syn:large:text
wp sd-ai-agent benchmark run --question=ac-001 --provider=ai-provider-for-any-openai-compatible --model=syn:small:text
```

Then rerun the full matrix with round-robin scheduling and confirm no HTTP 402 appears in the logs.

## Success criteria

- Provider-level quota errors are explicitly reported as blocked/inconclusive.
- A quota failure cannot be mistaken for a model capability failure.
- Matrix summaries include enough information to rank only models with meaningful completed assertions.
