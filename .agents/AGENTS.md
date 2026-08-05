# Agent Instructions

This directory contains project-specific agent context. The [aidevops](https://aidevops.sh)
framework is loaded separately via the global config (`~/.aidevops/agents/`).

## Purpose

Files in `.agents/` provide project-specific instructions that AI assistants
read when working in this repository. Use this for:

- Domain-specific conventions not covered by the framework
- Project architecture decisions and patterns
- API design rules, data models, naming conventions
- Integration details (third-party services, deployment targets)

## Adding Agents

Create `.md` files in this directory for domain-specific context:

```text
.agents/
  AGENTS.md              # This file - overview and index
  api-patterns.md        # API design conventions
  deployment.md          # Deployment procedures
  data-model.md          # Database schema and relationships
```

Each file is read on demand by AI assistants when relevant to the task.

## Task Completion Rules

**Never mark a task complete before its PR is merged.**

Workers must call `task-complete-helper.sh` only after the PR is in `MERGED`
state. The project-level wrapper at `.agents/scripts/task-complete-helper.sh`
enforces this via `gh pr view --json state,mergedAt` and will abort if the PR
is not yet merged.

The `todo-integrity` CI check (`.github/workflows/todo-integrity.yml`) also
validates this on every push to `TODO.md` — any completed task referencing an
unmerged PR will fail the check.

Root cause of issue #466: a worker called `task-complete-helper.sh t085 --pr 465`
immediately after opening PR #465, before it was merged. The framework fix
(aidevops PR #5066) adds the merge check to the framework script. The CI check
and project-level wrapper here provide a project-level safety net.

## Headless Worker Tool Guardrails

Recurring contributor-insight reports for this repository show workers losing
time to schema and GitHub signature failures before they reach plugin code. When
working here in OpenCode headless mode:

- Every `bash` tool call must include the `description` argument. Do not emit a
  bare command object, even for quick environment setup or WP-CLI checks.
- Do not create tracking issues with raw `gh issue create`. Use the project or
  framework sync helpers that add the required aidevops signature footer, such as
  `.agents/scripts/issue-sync-helper.sh`, or write the body to a temporary file
  and pass it with `--body-file` after appending the signed footer.
- Keep the signed body as a real file that already exists before the `gh` write
  command starts. Do not create the `--body-file` later in the same shell command
  that invokes `gh`, because the signature gate may inspect the path before the
  shell has produced it.
- Do not use shell heredocs, process substitution, or command substitution to
  generate the body inline for a `gh` write; the framework signature gate blocks
  those forms. Create or update the markdown with the runtime file-writing tool,
  then pass that stable file path to `gh --body-file` in a later command.
- If the signature helper is unavailable, use the helper fallback path rather
  than retrying the same raw `gh` command; repeated unsigned writes are blocked
  by the framework guard.
- If a GitHub write fails with `gh-signature-helper.sh invocation failed` or a
  `bash:file_not_found` tool error, treat it as a signature-gate write-path
  failure. Recreate the body as an existing stable file, rerun through the signed
  helper path, and continue implementation before diagnosing framework helper
  installation details.
- Treat `read:file_not_found` as a recoverable path mismatch unless bounded
  evidence proves the artifact is gone. Compare the requested basename with the
  prior tool output, check for nearby variants such as `reply3` vs `r3`, verify
  tracked paths with `git ls-files '<pattern>'`, and retry `Read` with the
  corrected path before continuing. If the tool error includes a "you mean one
  of these?" suggestion, verify and read the suggested path first (for example,
  a failed `phpcs.xml.dist` read that suggests `bootstrap.php`). Do not stop a
  headless run solely because one optional screenshot, prompt, or generated
  artifact path was stale.
- When a missing-file read happens during WordPress fatal-error triage, switch to
  the runtime evidence before more repo-path probing: make
  `../wordpress/wp-content/debug.log` the next read target (enable `WP_DEBUG_LOG`
  and reproduce once if absent), then verify the shared install's plugin symlinks
  point at every checked-out plugin worktree's canonical plugin directory name,
  not just the current basename. If the failed read was `vendor/`, do this before
  assuming a Composer dependency problem. If maintainer recovery says a fatal
  appeared after `read vendor`, treat that as a direct instruction to read
  debug.log and check canonical plugin symlinks before touching dependencies. Use
  `wp plugin path <plugin-slug>` or inspect `../wordpress/wp-content/plugins/`
  before retrying unrelated repository paths; enumerate all involved plugin slugs
  when multiple local plugins or worktrees participate in the failing activation
  path.
- Treat `bash:other` / `Tool execution aborted` as a recoverable command-shape or
  hook failure first. Inspect the preceding command, retry once with a narrower
  non-inline command and a clear `description`, avoid heredocs/process or command
  substitution, and preserve any implementation diff before reporting BLOCKED.

## Contributor Insight Follow-through

When an auto-filed contributor insight asks whether instructions or scripts need
to change, do not answer only in the issue thread. Make the durable repo change:

- Put repo-wide guidance in the root `AGENTS.md` so future workers load it before
  editing plugin code.
- For REST API hardening notes, inspect controllers under `includes/REST/` and
  routes in the private `sd-ai-agent/v1` namespace. Convert shorthand feedback
  into explicit checks for capability gates, real current-user context, secret
  scrubbing, and hidden or restricted file-upload endpoints.
- For pasted local-path skill excerpts, first map the excerpt to the committed
  in-plugin source. Block theme or Full Site Editing excerpts belong in
  `includes/Models/skills/wp-block-themes.md`; update that skill or the root
  `AGENTS.md` summary instead of copying private local paths into GitHub.
- For mixed shorthand reports, map each note before editing: automation/process
  feedback belongs in `.agents/AGENTS.md`, `.agents/scripts/commands/feedback-triage.md`,
  or the relevant helper; REST security feedback belongs in root `AGENTS.md`
  plus the concrete controller or ability family; block-theme excerpts belong in
  `includes/Models/skills/wp-block-themes.md`.
- For service-usage questions, map the named service to the committed integration
  surfaces before answering. For Google Search Console, inspect
  `includes/Abilities/GscAbilities.php`, `includes/Core/Settings.php`,
  `includes/REST/SettingsController.php`, and
  `includes/Abilities/ToolCapabilities.php` so the response names API calls,
  credential storage, REST routes, and capability gates.
- For WordPress.org review-response prompts, require an issue-by-issue reply
  that cites the merged fix or evidence-backed rationale for each reviewer
  finding, includes verification commands, and avoids broad "everything is fixed"
  or "remaining issues are false positives" claims unless each claim is tied to
  exact file or pattern evidence.
- For mixed reports like issues #2034, #2050, #2060, #2066, #2074, and #2081,
  include the source map
  in the PR body: block-theme excerpts →
  `includes/Models/skills/wp-block-themes.md`; REST
  hardening → root `AGENTS.md`, `includes/REST/`, and
  `includes/Abilities/WpRestAbilities.php`; Google Search Console →
  `includes/Abilities/GscAbilities.php`, `includes/Core/Settings.php`,
  `includes/REST/SettingsController.php`, and
  `includes/Abilities/ToolCapabilities.php`; WordPress.org review responses →
  root `AGENTS.md` review-response policy. For #2060/#2066/#2074/#2081-style
  reports with only block-theme, Google Search Console, and WordPress.org review-response notes,
  do not invent REST work; cite the three inspected durable sources instead. If
  code already has the requested guard, cite the exact inspected guard instead of
  claiming "already documented".
- Do not mark contributor-insight issues complete after documentation reading
  alone. A valid fix changes a durable instruction, workflow doc, or helper, then
  verifies the changed guidance with an exact `rg -n` check that future workers
  will load it.
- If the requested hardening is broader than the current task, file worker-ready
  GitHub issue briefs that name the route/controller family, expected security
  behaviour, and verification command or manual review to run.

## Security

### Prompt Injection Defense

Any feature that processes untrusted content (tool outputs, user input, webhook
payloads) and passes it to an LLM must defend against prompt injection. This is
a JS/TS project — use `@stackone/defender` to sanitize untrusted tool results
before they enter the LLM context:

```bash
pnpm add @stackone/defender
```

```typescript
import { createPromptDefense } from '@stackone/defender';

const defense = createPromptDefense({ enableTier2: true, blockHighRisk: true });

// Sanitize untrusted tool output before adding to LLM context
const result = await defense.defendToolResult(toolResponse, 'tool_name');
if (!result.allowed) {
  throw new Error('Blocked untrusted content');
}
const safeContent = result.sanitized;
```

For features that don't use LLMs but process untrusted text (webhooks, form
submissions, API endpoints), validate and sanitize inputs at the boundary.

### General Security Rules

- Never log or expose API keys, tokens, or credentials in output
- Store secrets via `aidevops secret set <NAME>` (gopass-encrypted) or
  environment variables — never hardcode them in source
- Use `<PLACEHOLDER>` values in code examples; note the secure storage location
- Validate all external input (user input, webhook payloads, API responses)
- Pin third-party GitHub Actions to SHA hashes, not branch tags
- Run `aidevops security audit` periodically to check security posture
- See `~/.aidevops/agents/tools/security/prompt-injection-defender.md` for
  the framework's prompt injection defense patterns
