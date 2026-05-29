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

## Contributor Insight Follow-through

When an auto-filed contributor insight asks whether instructions or scripts need
to change, do not answer only in the issue thread. Make the durable repo change:

- Put repo-wide guidance in the root `AGENTS.md` so future workers load it before
  editing plugin code.
- For REST API hardening notes, inspect controllers under `includes/REST/` and
  routes in the private `sd-ai-agent/v1` namespace. Convert shorthand feedback
  into explicit checks for capability gates, real current-user context, secret
  scrubbing, and hidden or restricted file-upload endpoints.
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
npm install @stackone/defender
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
