---
description: Triage incoming feedback reports from the sd-ai-feedback plugin (r020 routine)
agent: Build+
mode: subagent
tools:
  read: false
  write: false
  edit: false
  bash: true
  glob: false
  grep: false
  webfetch: false
  task: false
---

<!-- SPDX-License-Identifier: MIT -->
<!-- SPDX-FileCopyrightText: 2025-2026 Dave Stone -->

# Feedback Triage — r020 Routine SOP

Triage new feedback reports submitted via the sd-ai-agent feedback system. Fetch pending
reports, judge each one, and either create a GitHub issue or dismiss with a reason.

**Invocation**: Automated daily at 09:00 by the systemd timer
`sh.aidevops.routine-feedback-triage-daily.timer` (aidevops routine slot `r020`;
`repeat:persistent` so the pulse-wrapper does not also dispatch it). Can also be
triggered manually with `/feedback-triage`.

Slot history: previously labelled `r010` until 2026-05-18; renamed to `r020` to free
`r010` for the framework GH Failure Miner reservation.

**Required env vars** (sourced from `~/.config/aidevops/credentials.sh` or gopass):
- `FEEDBACK_ENDPOINT` — Base URL of the sd-ai-feedback WordPress site
- `FEEDBACK_API_KEY` — Raw `user:application_password` string (the helper base64-encodes it for HTTP Basic auth)
- `FEEDBACK_REPO` — Target GitHub repo (default: `Ultimate-Multisite/sd-ai-agent`)

## Workflow

### Step 1: Credentials

The helper script auto-sources `~/.config/aidevops/credentials.sh` when env
vars are missing, so headless dispatchers (systemd timer + `opencode run`,
which start with an empty env) work out of the box. You only need to
explicitly source credentials in an interactive shell if you want to
override the defaults.

If the auto-source still leaves `FEEDBACK_ENDPOINT` or `FEEDBACK_API_KEY`
empty, the script exits with `ERROR: FEEDBACK_ENDPOINT not set ...`. In
that case emit:

```
BLOCKED: FEEDBACK_ENDPOINT and FEEDBACK_API_KEY not configured.
Set them in ~/.config/aidevops/credentials.sh and retry.
```

Then stop. Do not proceed without credentials.

### Step 2: Fetch new reports

```bash
~/.aidevops/agents/custom/scripts/feedback-triage.sh fetch
```

Output is a JSON array of report objects. Each object has at minimum:
- `id` — report ID
- `report_type` — `user_reported`, `self_reported`, `exit_reason`, `thumbs_down`
- `model_id` / `provider_id` — top-level convenience copies (also nested in `session_data`)
- `site_url` — site that submitted the report (may be empty for legacy/test reports)
- `created_at` — submission timestamp
- `status` — should be `new`

Note: `plugin_version` lives inside the full payload as
`environment.plugin_version` and is fetched in step 4a, not at the list level.

The helper retries once automatically if the first response body is exactly
`[]`, because the underlying endpoint occasionally serves a transient empty
result on cold cache. If the second response is still `[]`, output
`r020: No new reports to triage.` and stop (success).

### Step 3: Check latest plugin version

```bash
gh release list -R Ultimate-Multisite/sd-ai-agent --limit 1 --json tagName --jq '.[0].tagName'
```

Store as `LATEST_VERSION`. Used to detect reports from outdated installs.

### Step 4: For each report, triage independently

For each report in the fetched array:

#### 4a: Fetch full payload

Use the `transcript` subcommand for triage. It renders a compact, jq-free
view that is safe for the systemd log file (text snippets are truncated to
200 chars per part) and surfaces tool-call errors inline:

```bash
~/.aidevops/agents/custom/scripts/feedback-triage.sh transcript <report_id>
```

Use `get <report_id>` instead when you need the raw JSON payload (rare —
mostly when crafting an issue body that needs verbatim quotes). The raw
payload may contain user data; never echo it into chat or commit it.

Full payload schema (top-level keys):

- `id`, `created_at`, `reviewed_at`, `status`, `report_type`, `api_key_id`
- `site_url`, `model_id`, `provider_id`, `user_description`
- `github_issue_url`, `triage_summary`
- `environment` — object with `plugin_version`, `wp_version`, `php_version`,
  `theme`, `site_locale`, `is_multisite`, `active_plugins[]`. Legacy / test
  reports may serialize this as an empty array (`[]`) instead of an object.
- `session_data` — object with:
  - `id`, `title`, `model_id`, `provider_id`
  - `message_count`, `tool_call_count`
  - `prompt_tokens`, `completion_tokens`
  - `messages[]` — each message is `{role, parts: [...]}` where each part
    is `{channel, type, text | functionCall | functionResponse}`. Older
    reports use the simpler `{role, content: "..."}` shape.
  - `tool_calls[]` — each entry is `{type: "call"|"response", id, name,
    args | response}`. Errors surface as `response.response.error` /
    `response.response.code` (e.g. `skill_disabled`, `skill_not_found`).
  - `exit_reason` — `spin` | `timeout` | `max_iterations` (only present for
    automated `self_reported` / `exit_reason` reports; absent for
    `thumbs_down` and `user_reported`).

#### 4b: Version check — is this already fixed?

Compare `environment.plugin_version` to `LATEST_VERSION`. If the report is from a version
more than one patch behind and the issue is plausibly already fixed (no matching open issue),
dismiss with reason:

```bash
~/.aidevops/agents/custom/scripts/feedback-triage.sh update <id> dismissed \
  "Submitted from v<plugin_version>. Latest is <LATEST_VERSION> — issue may be fixed. Please upgrade and retest."
```

Skip further analysis for this report.

#### 4c: Classify the report

Based on `session_data.messages`, `session_data.tool_calls`,
`session_data.exit_reason`, and `user_description`, classify:

| Classification | Criteria | Action |
|----------------|----------|--------|
| `real_bug` | Agent failed due to a reproducible code defect, not user error | Check dedup → create issue or dismiss as duplicate |
| `user_error` | User asked for something outside plugin scope or made a configuration mistake | Dismiss with guidance |
| `model_limitation` | The AI model itself is the limiting factor, not a plugin bug | Dismiss with explanation |
| `missing_ability` | A legitimate WordPress action the plugin should support but doesn't | Evaluate for enhancement issue |
| `provider_error` | The AI provider (OpenAI, Anthropic, etc.) returned an error — not plugin fault | Dismiss with provider note |
| `exit_reason_expected` | `spin`/`timeout`/`max_iterations` on a genuinely complex or unsupported task | Dismiss with explanation |

Apply Step 3.6 validation from `/log-issue-aidevops` before classifying as `real_bug` or
`missing_ability`:
- Verify claims against `session_data.messages` and `session_data.tool_calls`
  (do the tool calls actually match the user's complaint?).
- Assess data scale: was this a realistic workload or an edge case the user forced?
- Check for template-driven reports (multiple reports with identical structure suggest
  a systematic issue — treat as one issue, not N).
- For thumbs_down with empty `session_data.messages` and `tool_calls`, treat
  as a test / setup-verification report and dismiss without filing.

#### 4d: Dedup check (for real_bug and missing_ability)

```bash
~/.aidevops/agents/custom/scripts/feedback-triage.sh dedup "<3-5 keyword summary>"
```

If matching open issues are found, dismiss as duplicate:

```bash
~/.aidevops/agents/custom/scripts/feedback-triage.sh update <id> dismissed \
  "Duplicate of #<number>: <url>"
```

#### 4e: Create GitHub issue (real_bug, not duplicate)

Compose issue body using this template:

```markdown
## Description
{problem summarised from session_data.messages and user_description}

## Expected Behavior
{what the agent should have done}

## Steps to Reproduce
{derived from session_data.messages and session_data.tool_calls — list the
sequence of user prompts and the tool calls/responses that led to failure}

## Environment
- Plugin version: {environment.plugin_version}
- WordPress: {environment.wp_version}
- PHP: {environment.php_version}
- Multisite: {environment.is_multisite}
- Provider: {provider_id} / {model_id}
- Theme: {environment.theme}
- Active plugins: {environment.active_plugins}

## Feedback Report
Report ID: {report_id} (submitted {created_at})
```

Write the body to a temp file (avoids quoting hazards with backticks /
heredocs / unicode dashes) and create the issue. Apply `origin:worker` and
`status:available` alongside `bug` so the issue is traceable as
feedback-triage output and visible to claim routines:

```bash
gh issue create -R Ultimate-Multisite/sd-ai-agent \
  --title "<concise bug title>" \
  --body-file /tmp/opencode/r020-triage/issue-<id>-body.md \
  --label "bug,origin:worker,status:available"
```

Capture the issue URL from output. Then update the report (the helper
routes the third argument to `github_issue_url` for the `issue_created`
status):

```bash
~/.aidevops/agents/custom/scripts/feedback-triage.sh update <id> issue_created <github_url>
```

#### 4f: Create GitHub issue (missing_ability)

Use `enhancement` in place of `bug`; keep the rest of the label set
(`enhancement,origin:worker,status:available`). Title format:
`ability: <action> — <context>`.

#### 4g: Dismiss non-bugs

```bash
~/.aidevops/agents/custom/scripts/feedback-triage.sh update <id> dismissed "<reason>"
```

Reason should be one concise sentence explaining why this is not actionable.
The helper stores it in the report's `triage_summary` field server-side
(visible in the feedback admin UI). Earlier versions of the helper put this
text into `github_issue_url` by mistake; if you see old dismissed reports
with a non-URL string in `github_issue_url`, that is the legacy artefact.

### Step 5: Summary

After processing all reports, output a summary:

```
r020 triage complete: <N> reports processed.
  - Issues created: <N>
  - Dismissed (duplicate): <N>
  - Dismissed (user error): <N>
  - Dismissed (model limitation): <N>
  - Dismissed (outdated version): <N>
  - Dismissed (other): <N>
```

## Error handling

- `feedback-triage.sh fetch` HTTP error → log and stop. Do not attempt partial triage.
- `feedback-triage.sh get <id>` HTTP error → skip report, log the error, continue with next.
- `gh issue create` failure → do NOT update report status. Log and continue.
- Missing credentials → stop immediately (Step 1 guard).

## Privacy

- Do not log raw `session_data.messages` to stdout — they may contain user
  data. Prefer the `transcript` subcommand, which truncates text snippets
  to 200 chars per part. Use `get` only when crafting an issue body that
  needs verbatim quotes, and never paste the raw payload back into chat
  or commit it.
- Do not include credentials in any command output or issue body.
- `environment.active_plugins` list is safe to include in issue bodies
  (plugin names only — no secrets).
