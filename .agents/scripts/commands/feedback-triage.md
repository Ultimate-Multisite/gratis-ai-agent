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
- `FEEDBACK_API_KEY` — Base64-encoded `user:application_password` for the REST API
- `FEEDBACK_REPO` — Target GitHub repo (default: `Ultimate-Multisite/sd-ai-agent`)

## Workflow

### Step 1: Load credentials

```bash
source ~/.config/aidevops/credentials.sh 2>/dev/null || true
export FEEDBACK_ENDPOINT="${FEEDBACK_ENDPOINT:-}"
export FEEDBACK_API_KEY="${FEEDBACK_API_KEY:-}"
```

If `FEEDBACK_ENDPOINT` or `FEEDBACK_API_KEY` is empty, emit:
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
- `report_type` — `user_submitted`, `self_reported`, `exit_reason`, `thumbs_down`
- `plugin_version` — plugin version that submitted the report
- `created_at` — submission timestamp
- `status` — should be `new`

If the array is empty, output: `r020: No new reports to triage.` and stop (success).

### Step 3: Check latest plugin version

```bash
gh release list -R Ultimate-Multisite/sd-ai-agent --limit 1 --json tagName --jq '.[0].tagName'
```

Store as `LATEST_VERSION`. Used to detect reports from outdated installs.

### Step 4: For each report, triage independently

For each report in the fetched array:

#### 4a: Fetch full payload

```bash
~/.aidevops/agents/custom/scripts/feedback-triage.sh get <report_id>
```

Full payload includes:
- `session_messages` — the conversation that triggered the report
- `tool_calls` — abilities invoked
- `token_usage` — tokens consumed
- `model_id`, `provider_id` — AI model used
- `environment` — WP version, PHP version, plugin version, theme, active plugins, locale, multisite
- `user_description` — free-text description submitted by the user (if any)
- `exit_reason` — `spin`, `timeout`, `max_iterations` (for automated reports)

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

Based on `session_messages`, `tool_calls`, `exit_reason`, and `user_description`, classify:

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
- Verify claims against the session messages (do the tool calls match the claim?)
- Assess data scale: was this a realistic workload or an edge case the user forced?
- Check for template-driven reports (multiple reports with identical structure suggest
  a systematic issue — treat as one issue, not N)

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
{problem summarised from session_messages and user_description}

## Expected Behavior
{what the agent should have done}

## Steps to Reproduce
{derived from session_messages — list the sequence of user prompts and tool_calls}

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

Then create the issue:

```bash
gh issue create -R Ultimate-Multisite/sd-ai-agent \
  --title "<concise bug title>" \
  --body "$(cat <<'EOF'
<body>
EOF
)" \
  --label "bug"
```

Capture the issue URL from output. Then update the report:

```bash
~/.aidevops/agents/custom/scripts/feedback-triage.sh update <id> issue_created <github_url>
```

#### 4f: Create GitHub issue (missing_ability)

Use label `enhancement` instead of `bug`. Title format: `ability: <action> — <context>`.

#### 4g: Dismiss non-bugs

```bash
~/.aidevops/agents/custom/scripts/feedback-triage.sh update <id> dismissed "<reason>"
```

Reason should be one concise sentence explaining why this is not actionable.

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

- Do not log raw `session_messages` to stdout — they may contain user data.
- Do not include credentials in any command output or issue body.
- `environment.active_plugins` list is safe to include in issue bodies (plugin names only).
