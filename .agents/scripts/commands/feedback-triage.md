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

When a report is a contributor-insight shorthand rather than a direct bug,
translate each note into committed source surfaces before filing or updating an
issue. Examples:

- Block-theme or Full Site Editing excerpts headed `Block Themes`, `theme.json`,
  templates, parts, patterns, or style variations map to
  `includes/Models/skills/wp-block-themes.md` plus the root `AGENTS.md` summary.
- REST security shorthand mentioning `sd-ai-agent/v1`, secret scrubbing, current
  users, file uploads, or WordPress.org readiness maps to root `AGENTS.md`,
  `includes/REST/`, and the `wp-rest/execute` ability path.
- Service-usage questions such as "where do we use Google Search Console API?"
  map to `includes/Abilities/GscAbilities.php`, `includes/Core/Settings.php`,
  `includes/REST/SettingsController.php`, and
  `includes/Abilities/ToolCapabilities.php`.
- WordPress.org review-response notes map to the root `AGENTS.md`
  "WordPress.org Review Responses" policy. The issue body should require a
  concise issue-by-issue response, concrete fix/rationale references, verification
  evidence, and no broad "false positive" claims without finding-specific proof.
- `read:file_not_found` reports whose summarized command is `read vendor` and
  maintainer recovery mentions a fatal error map to headless file-read recovery
  plus local WordPress activation triage: root `AGENTS.md`, `.agents/AGENTS.md`,
  and `includes/Models/skills/site-troubleshooting.md`. The Worker Guidance must
  tell implementers to make `../wordpress/wp-content/debug.log` the next evidence
  source, then verify every involved plugin resolves through its canonical plugin
  directory or symlink before diagnosing Composer dependencies.

The resulting issue body must name the target files, expected behaviour, and at
least one verification command; do not leave private local paths or shorthand as
the only implementation guidance.

For mixed contributor-insight issues, the body must also include a concise
source map so the worker can start with implementation instead of rediscovering
the lesson. For the recurring mixed-report pattern seen in issues #2034, #2050,
#2060, and #2066, map each note independently. For #2034-style reports, map the four
notes as:
`Block Themes` excerpts → `includes/Models/skills/wp-block-themes.md`; REST
security shorthand → root `AGENTS.md`, `includes/REST/`, and
`includes/Abilities/WpRestAbilities.php`; Google Search Console questions →
`includes/Abilities/GscAbilities.php`, `includes/Core/Settings.php`,
`includes/REST/SettingsController.php`, and
`includes/Abilities/ToolCapabilities.php`; WordPress.org review-response prompts
→ root `AGENTS.md` "WordPress.org Review Responses". Require the PR body to cite
the inspected guard or policy when no code change is needed.
For #2050/#2060/#2066-style reports that include only block-theme excerpts, Google
Search Console usage questions, and WordPress.org review-response prompts,
update the block-theme skill, the GSC usage map, and the review-response policy
only; do not add unrelated REST hardening work unless the report actually
includes REST shorthand. When filing the issue, include Worker Guidance that maps
the exact three candidates to `includes/Models/skills/wp-block-themes.md`,
`AGENTS.md` Google Search Console API Usage Mapping, and `AGENTS.md`
WordPress.org Review Responses.

For #2082-style `read:file_not_found` reports with `read vendor` plus fatal-error
recovery, include Worker Guidance that maps the issue to root `AGENTS.md`
"Headless File Read Recovery", `.agents/AGENTS.md` "Headless Worker Tool
Guardrails", and `includes/Models/skills/site-troubleshooting.md` "Plugin
Activation Fatal Error". Require verification with
`rg -n "read:file_not_found|missing-file reads|read vendor|debug.log|canonical plugin|wp plugin path" AGENTS.md .agents/AGENTS.md .agents/scripts/commands/feedback-triage.md includes/Models/skills/site-troubleshooting.md`.

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
| `contributor_insight` | Maintainer feedback says automation needs better instructions, scripts, or durable context | Create a contributor-insight issue with worker guidance that names the durable target and verification |

Apply Step 3.6 validation from `/log-issue-aidevops` before classifying as `real_bug` or
`missing_ability`:
- Verify claims against `session_data.messages` and `session_data.tool_calls`
  (do the tool calls actually match the user's complaint?).
- Assess data scale: was this a realistic workload or an edge case the user forced?
- Check for template-driven reports (multiple reports with identical structure suggest
  a systematic issue — treat as one issue, not N).
- For thumbs_down with empty `session_data.messages` and `tool_calls`, treat
  as a test / setup-verification report and dismiss without filing.

For `contributor_insight` reports, convert shorthand into worker-ready actions
before creating the issue:

- For mixed reports with several candidates, create one issue whose `## Worker
  Guidance` maps each candidate separately. Do not assume a pasted local-path
  excerpt, REST shorthand, service-usage question, and review-response prompt all
  belong in the same file; name the narrowest committed source for each note.
- If the note asks whether instructions or scripts should change, direct the
  worker to change the narrowest durable source instead of replying in comments:
  root `AGENTS.md` for repo-wide plugin rules, `.agents/AGENTS.md` for
  repository worker guardrails, or this SOP/helper docs for feedback-triage
  mechanics.
- If the report includes a pasted local-path skill excerpt, require the worker
  to map it to the committed source first. Block theme / Full Site Editing
  excerpts belong in `includes/Models/skills/wp-block-themes.md`; do not paste
  private local paths or duplicate the whole skill into GitHub.
- If the note says “block `sd-ai-agent/v1`, do secret scrubbing, run as real
  current user, hide file uploads,” translate that into explicit checks for
  `includes/REST/` controllers and the `wp-rest/execute` ability: capability
  gates, `get_current_user_id()` context, redacted responses/logs, blocked
  internal namespace dispatch, and hidden or restricted upload endpoints.
- If recurring tool errors mention `read:file_not_found`, require durable worker
  guidance to treat the missing path as a recoverable mismatch: compare the
  requested basename with the previous tool output, check nearby names such as
  `reply3` vs `r3`, follow any "you mean one of these?" suggested path (for
  example `phpcs.xml.dist` → `bootstrap.php`), use `git ls-files '<pattern>'`
  for tracked files, then retry `Read` before continuing with the next safe
  implementation step.
- If the same note mentions a WordPress fatal error, plugin activation, or
  canonical plugin symlinks, require the worker guidance to inspect
  `../wordpress/wp-content/debug.log` before chasing unrelated missing repo paths,
  and to verify the local WordPress install symlinks every checked-out plugin
  worktree into the expected canonical plugin directory name. Name
  `wp plugin path <plugin-slug>` or `../wordpress/wp-content/plugins/` as the
  concrete check so workers do not only verify the active worktree basename;
  when multiple plugins or worktrees are mentioned, require guidance to enumerate
  each canonical plugin slug and confirm all resolve to the intended checkout. If
  the summarized failed command is `read vendor`, explicitly steer workers away
  from Composer diagnosis until the debug log and canonical symlink checks have
  been completed.
- If recurring tool errors mention `bash:file_not_found`, `gh-signature-helper.sh
  invocation failed`, `signature gate`, or blocked `gh` writes, require durable
  worker guidance to create the issue/PR/comment body as an existing stable file,
  avoid heredocs/process substitution/command substitution/inline markdown, pass
  that file via `--body-file`, and switch to the signed helper path instead of
  retrying the same raw unsigned `gh` command.
- If the note asks for a WordPress.org plugin-review email response, require the
  worker to draft against the root `AGENTS.md` review-response rules: address
  each reviewer finding separately, cite the merged fix or evidence-backed
  rationale, include commands/manual checks run, and avoid "all fixed" or
  "false positive" language unless tied to exact file/pattern evidence.
- If the report matches the #2060 three-candidate pattern (`Block Themes` local
  excerpt, "where do we use Google Search Console API?", and WordPress.org
  review-response prompt), file Worker Guidance that explicitly says the durable
  fix is guidance-only unless inspection finds a missing committed source: update
  `includes/Models/skills/wp-block-themes.md` for the block-theme excerpt, root
  `AGENTS.md` for GSC usage mapping and WordPress.org response policy, and this
  feedback-triage SOP if the issue lacked actionable worker guidance.
- Include verification in the issue body, for example
  `rg -n "Contributor Insight|sd-ai-agent/v1|secret|current user|file upload|WordPress.org Review|false positive" AGENTS.md .agents/AGENTS.md .agents/scripts/commands/feedback-triage.md`
  or `rg -n "read:file_not_found|missing-file reads|git ls-files|debug.log|canonical plugin|signature gate|body-file|gh-signature-helper" AGENTS.md .agents/AGENTS.md .agents/scripts/commands/feedback-triage.md includes/Models/skills/site-troubleshooting.md`
  plus `rg -n "wp-block-themes|Full Site Editing|theme.json|validate-block-content" AGENTS.md includes/Models/skills/wp-block-themes.md`
  when block-theme guidance is involved.
- Add a `## Worker Guidance` section to the created GitHub issue. It must name
  each durable target file to inspect or edit, translate shorthand notes into
  explicit worker actions, and include the exact `rg -n` verification commands.
  Do not file a contributor-insight issue that only quotes the maintainer note;
  the issue body must be actionable without private paths, chat history, or
  additional context. When a candidate is a usage-mapping question rather than a
  code defect, require the worker to document the committed integration surfaces
  and related secrecy/capability checks instead of answering only in a comment.
- If implementation hardening is broader than the instruction change, include a
  `## Follow-up issue briefs` section with one worker-ready brief per route,
  controller, helper, or skill family. Each brief names the missing guard or
  guidance, expected safe behaviour, and one concrete verification command or
  REST request.

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
unicode dashes). In headless runs, create the file with the runtime's
file-writing tool or an existing generated file before invoking `gh`; do not
create the `--body-file` later in the same shell command that runs the GitHub
write. The signature gate may inspect the path before the shell has produced it.
Do not use shell heredocs, process substitution, or command substitution to
inline the body or signature. Those forms are rejected by the aidevops GitHub
signature gate. Create the GitHub issue through the repo helper so the required
aidevops signature footer is appended automatically and the fallback signature is
used if the framework signature helper is unavailable. Apply `origin:worker` and
`status:available` alongside `bug` so the issue is traceable as feedback-triage
output and visible to claim routines:

```bash
.agents/scripts/issue-sync-helper.sh --repo Ultimate-Multisite/superdav-ai-agent create-signed-issue \
  --title "<concise bug title>" \
  --body-file /tmp/opencode/r020-triage/issue-<id>-body.md \
  --label "bug,origin:worker,status:available"
```

If any referenced screenshot, prompt, or generated artifact returns
`read:file_not_found`, do a bounded recovery pass before filing or abandoning the
triage issue: compare the requested basename with the tool output that introduced
it, follow any "you mean one of these?" nearby-path hint from the tool error,
verify tracked repository paths with `git ls-files '<pattern>'`, inspect the
known parent directory for nearby runtime-artifact names, and retry `Read` with
the corrected path. For WordPress fatal-error reports, make the known runtime
artifact `../wordpress/wp-content/debug.log` the next read target and include a
canonical plugin-symlink check in the worker guidance. If the artifact still
cannot be found, include the attempted paths in the issue body and continue with
the available session evidence instead of treating the missing read as the whole
outcome.

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

#### 4f.1: Create GitHub issue (contributor_insight)

Use `contributor-insight` in place of `bug`; keep the rest of the label set
(`contributor-insight,origin:worker,status:available`). Title format:
`Contributor insight: <instruction/script gap>`.

The issue body must include a `## Worker Guidance` section with:

- **Durable target:** exact file(s) to edit, such as `AGENTS.md`,
  `.agents/AGENTS.md`, `.agents/scripts/commands/feedback-triage.md`, or
  `includes/Models/skills/wp-block-themes.md`.
- **Translation:** the maintainer shorthand rewritten as concrete worker checks
  or implementation steps.
- **Follow-up briefs:** when the insight identifies broader hardening work,
  instruct the worker to file signed GitHub issues naming the route/controller,
  missing guard or scrubber, expected safe behaviour, and verification command.
- **Verification:** exact `rg -n`, PHPCS/PHPUnit, or REST request evidence that
  proves future workers will load the new guidance and that any changed code is
  covered.

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
- `create-signed-issue` failure → do NOT update report status. Log and continue.
- `gh-signature-helper.sh invocation failed` or `bash:file_not_found` while
  creating a GitHub body/write → rebuild the issue body as a stable file, rerun
  the `.agents/scripts/issue-sync-helper.sh create-signed-issue` path, and do
  not retry raw `gh issue create` or inline body generation.
- `Tool execution aborted` / `bash:other` while gathering diagnostic context →
  retry once with a narrower non-inline command. If it still aborts, log the
  attempted command, continue triage with the available transcript evidence, and
  do not let one optional diagnostic abort the whole routine.
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
