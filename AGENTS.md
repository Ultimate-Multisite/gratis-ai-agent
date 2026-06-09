# Superdav AI Agent - WordPress Plugin Development Guide

**Requires:** WordPress 7.0+, PHP 8.2+

## CANONICAL NAMING - DO NOT CHANGE

This plugin has ONE canonical set of identifiers. They are deliberately different
between the user-facing plugin slug and the code-level prefixes/namespaces.
**Do NOT "normalise" them. Do NOT rename. Do NOT migrate legacy names.**

| Purpose | Canonical Value | Notes |
| --- | --- | --- |
| Display name | `Superdav AI Agent` | Human-readable |
| WordPress.org plugin slug / text domain | `superdav-ai-agent` | Used ONLY for: `Text Domain:` header, `__( '...', 'superdav-ai-agent' )`, plugin folder name, `superdav-ai-agent.php` main file |
| Plugin DI container ID | `sd-ai-agent` | `xwp_load_app(['id' => 'sd-ai-agent', ...])` — DO NOT change to `superdav-ai-agent` |
| DI compile class | `CompiledContainerSdAiAgent` | Pairs with the `sd-ai-agent` ID |
| PHP namespace root | `SdAiAgent\` | e.g. `SdAiAgent\Core\AgentLoop` |
| PHP constant prefix | `SD_AI_AGENT_` | e.g. `SD_AI_AGENT_DIR`, `SD_AI_AGENT_VERSION` |
| Database table prefix | `{$wpdb->prefix}sd_ai_agent_` | All 23 tables |
| REST API namespace | `sd-ai-agent/v1` | Routes: `/wp-json/sd-ai-agent/v1/...` |
| Ability namespace | `sd-ai-agent/` | e.g. `sd-ai-agent/memory-save`, `sd-ai-agent/skill-load` |
| CSS class prefix | `sd-ai-agent-` | e.g. `sd-ai-agent-chat-panel` |
| JS option / handle prefix | `sd-ai-agent-` / `sd_ai_agent_` | Match WP convention per context |

### Rules for Agents

1. **Never** rename `sd-ai-agent` (in any form) to `superdav-ai-agent` in code,
   ability names, REST namespaces, CSS classes, DB tables, constants, namespaces,
   container IDs, or compile class names. The `superdav-ai-agent` form is **only**
   the WP.org plugin slug + i18n text domain.
2. **Never** rename `superdav-ai-agent` (the text domain) to `sd-ai-agent` either.
   Both are correct in their own contexts.
3. **No legacy-name migrations.** Do not write code to rewrite, canonicalise,
   or migrate `ai-agent/`, `gratis-ai-agent`, or any other historical prefix.
   Old names are not supported and we are not maintaining backward compatibility
   for them.
4. **No "WordPress.org compatibility" refactors** that rename `sd-ai-agent` →
   `superdav-ai-agent` in code. The WP.org plugin-check tool only inspects the
   text domain and slug; the internal `'id'` and namespaces are private.
5. If a PR proposes any of the above renames, it should be **closed and reverted**.
   Examples of past mistakes (do NOT repeat):
   - PR #1289 (changed `'id' => 'sd-ai-agent'` to `'superdav-ai-agent'`) — reverted
   - PR #1290 (renamed `gratis-ai-agent` → `superdav-ai-agent` in `AbstractAbility`) — closed unmerged
   - PR #1291 (changed docblock examples `sd-ai-agent/` → `superdav-ai-agent/`) — reverted
   - PR #1283 (auto-migration of legacy `ai-agent/` keys) — reverted
6. Headless agents that propose these renames are operating outside scope. File
   an issue describing the rogue behaviour rather than merging the PR.
7. **Do not canonicalise the deliberate dispatcher namespaces** `wp-rest/*` and
   `wp-cli/*` to `sd-ai-agent/*`. These are the only ability namespaces
   intentionally outside `sd-ai-agent/`: they mirror the underlying WordPress
   REST API and `wp` CLI systems, act as generic dispatchers rather than
   plugin-specific features, and would be both breaking and misleading if
   renamed. PRs #1922 and #1923 hardened these paths with dual gates without
   canonicalising their ability IDs; future "rename-to-canonical" PRs for
   `wp-rest/` or `wp-cli/` should be closed and reverted.

### WordPress.org Review Responses

When drafting replies to WordPress.org plugin review emails, use a concise,
professional, issue-by-issue format:

- Acknowledge the review and state that each reported item was investigated.
- For every reviewer finding, cite the exact fix or rationale, including file or
  pattern references when available.
- Include verification evidence such as `wp plugin check`, PHPCS, PHPUnit, build,
  or manual review commands that were run.
- Avoid defensive language, marketing copy, emojis, and broad claims that are not
  backed by a concrete change or test.
- Do not say remaining reviewer findings are "false positives" unless a specific
  finding was reproduced, traced to a scanner limitation, and documented with the
  exact file/pattern evidence that proves the submitted code is safe.
- Close with the resubmission status and any specific reviewer action requested.

### Contributor Insight Triage

When recurring maintainer feedback suggests that automation is missing context,
turn it into durable, worker-ready guidance instead of relying on chat history:

- Update this `AGENTS.md` when the lesson is repo-wide and should affect every
  future agent session.
- Update the narrowest relevant script, workflow, or docs page when the lesson is
  procedural or command-specific.
- When the prompt asks whether instructions or automation should change, treat it
  as a request to improve the durable guidance or the deterministic script path;
  do not leave the learning only as a chat reply.
- Convert shorthand maintainer notes into explicit worker actions: name the files
  or route families to inspect, the policy to enforce, and the command or manual
  check that proves the guidance was followed.
- If the fix is not obvious during the current session, open a GitHub issue brief
  that names the target files or patterns to inspect, the expected behaviour, and
  the verification command that should prove the change.
- Keep instruction changes concrete and actionable; avoid copying vague feedback
  without translating it into a rule, reference pattern, or testable outcome.
- When a contributor insight contains a pasted local-path skill or prompt excerpt,
  identify the committed source file it came from before editing. For block-theme
  or Full Site Editing excerpts, use `includes/Models/skills/wp-block-themes.md`
  as the durable target; do not paste private local paths or duplicate the whole
  skill into GitHub comments.

#### Contributor Insight Completion Checklist

For auto-filed contributor-insight issues, treat the quoted maintainer note as a
seed, not the finished instruction. A PR for this class of issue is complete only
when it includes all of the following:

- A durable instruction or deterministic script change, not just a comment on the
  issue. Choose the narrowest durable target: root `AGENTS.md` for repo-wide
  rules, `.agents/AGENTS.md` for worker/runtime guardrails, or a command/helper
  doc when the lesson belongs to one workflow.
- A translation from shorthand into worker actions. For example, "block
  `sd-ai-agent/v1`, do secret scrubbing, run as real current user, hide file
  uploads" means inspect `includes/REST/` controllers plus the
  `wp-rest/execute` ability path, verify namespace blocking, capability checks,
  `get_current_user_id()` context, redacted responses/logs, and upload route
  restrictions.
- Worker-ready follow-up issue briefs when implementation hardening is broader
  than the instruction change. Each brief must name the controller or route
  family, the missing guard or scrubber, expected safe behaviour, and one
  verification command or REST request.
- Evidence in the PR body showing the instruction is now loaded by future
  workers, such as `rg -n "Contributor Insight|sd-ai-agent/v1|secret|current user|file upload" AGENTS.md .agents/AGENTS.md`
  plus any workflow-specific doc check.
- If the repository already contains guidance for a reported candidate, tighten
  the loaded source instead of closing the issue as "already documented". Add the
  missing worker action, source map, or verification command that would have made
  the original shorthand unambiguous to a fresh headless worker.

#### Contributor Insight Source Mapping

When a contributor-insight issue combines multiple shorthand notes, resolve each
note to a committed, future-loaded source before editing:

- Treat local-path snippets as evidence only. Identify the matching committed
  file by title, heading, or distinctive phrases, then edit that file instead of
  reproducing the private path or pasted excerpt.
- For block-theme snippets headed `Block Themes`, `Full Site Editing`,
  `theme.json`, templates, parts, patterns, or style variations, update
  `includes/Models/skills/wp-block-themes.md` and keep the root summary here in
  sync. The skill itself should explain that pasted local-path excerpts are only
  clues; future workers must edit the committed skill file and validate generated
  block content before saving templates, parts, or patterns.
- For REST security shorthand involving `sd-ai-agent/v1`, secret scrubbing,
  current-user execution, or file uploads, update this root guidance and inspect
  the concrete controller or ability family before deciding whether code or a
  follow-up issue brief is required.
- For automation/process shorthand such as "do we need instructions or script",
  update `.agents/AGENTS.md`, `.agents/scripts/commands/feedback-triage.md`, or
  the relevant helper so future triage issues include worker-ready files,
  expectations, and verification commands.
- For service-usage questions such as "where do we use Google Search Console
  API?", map the question to the concrete integration files before answering or
  filing follow-up work. For GSC, inspect `includes/Abilities/GscAbilities.php`
  for API calls and ability IDs, `includes/Core/Settings.php` for credential
  storage, `includes/REST/SettingsController.php` for credential REST routes,
  and `includes/Abilities/ToolCapabilities.php` for capability gating.
- For WordPress.org review-response prompts, use the review response rules above:
  summarize each reviewer finding, cite the merged fix or evidence-backed
  rationale, include verification commands, and avoid blanket "all fixed" or
  "false positive" claims that are not tied to a specific finding.
- For mixed reports like issues #2034, #2050, #2060, #2066, #2074, and #2081,
  make the mapping
  explicit in the PR body: block-theme excerpts map to
  `includes/Models/skills/wp-block-themes.md`; REST hardening shorthand maps to
  this root REST policy plus `includes/REST/` and
  `includes/Abilities/WpRestAbilities.php`; Google Search Console questions map
  to the GSC surfaces listed below; WordPress.org reply prompts map to the
  review-response policy above. If a mixed report omits one of those categories,
  do not invent work for it; state the inspected candidate list and the durable
  source chosen for each note. State when the implementation is a guidance
  hardening only because the inspected code already has the required guard.
- For #2060/#2066/#2074/#2081-style reports that include the three candidates `Block Themes`,
  "where do we use Google Search Console API?", and a WordPress.org
  review-response prompt, update or verify only those three durable sources:
  `includes/Models/skills/wp-block-themes.md`, the Google Search Console usage
  map below, and the WordPress.org review-response policy above. Do not add REST
  hardening work unless the issue body also includes REST shorthand.
- Verification for this class of guidance-only fix should include both:
  `rg -n "Contributor Insight|source mapping|local-path|sd-ai-agent/v1|file upload|WordPress.org Review|false positive" AGENTS.md .agents/AGENTS.md .agents/scripts/commands/feedback-triage.md`
  and `rg -n "wp-block-themes|Full Site Editing|theme.json|validate-block-content" AGENTS.md includes/Models/skills/wp-block-themes.md`.

### Google Search Console API Usage Mapping

The Google Search Console integration lives in these committed surfaces:

- `includes/Abilities/GscAbilities.php` registers `sd-ai-agent/gsc-*` abilities
  and calls the Search Analytics API endpoint
  `searchconsole.googleapis.com/webmasters/v3/sites/{siteUrl}/searchAnalytics/query`.
- `includes/Core/Settings.php` stores credentials in
  `sd_ai_agent_gsc_credentials`, separate from general settings so the main
  settings response does not leak secrets.
- `includes/REST/SettingsController.php` exposes admin-only create/delete routes
  for `/settings/gsc-credentials` and reports only configured/type/default-site
  metadata in settings responses.
- `includes/Abilities/ToolCapabilities.php` gates all `sd-ai-agent/gsc-*`
  abilities behind `manage_options`.

When changing or auditing this integration, verify both credential secrecy and
capability gating with `npm run test:php -- --filter=GscAbilitiesTest` plus the
relevant REST/settings test when a route or settings response changes.

### Block Theme Automation Guidance

When work touches WordPress block themes, Full Site Editing, `theme.json`,
`templates/*.html`, `parts/*.html`, `patterns/*.php`, or style variations:

- Treat `includes/Models/skills/wp-block-themes.md` as the canonical in-plugin
  guidance for generated block-theme work. Update that skill when maintainers
  report recurring block-theme automation failures.
- Keep block-theme guidance agent-safe and actionable: require core block markup,
  avoid `core/html` escape hatches, avoid decorative non-block comments, avoid
  unprovided stock image URLs, and validate generated block content before saving.
- For active-site diagnosis, confirm whether the theme is a block theme with
  `sd-ai-agent/site-info`, `wp option get template` / `stylesheet`, or
  `wp_is_block_theme()` before applying Full Site Editing assumptions.
- Verification for guidance-only block-theme fixes should include
  `rg -n "wp-block-themes|Full Site Editing|theme.json|validate-block-content" AGENTS.md includes/Models/skills/wp-block-themes.md`.

#### Headless GitHub Write Guardrails

Recurring contributor-insight reports show OpenCode headless workers losing time
to blocked GitHub writes before implementation begins. Treat these as hard rules
whenever creating issues, PR comments, or other GitHub write bodies:

- Do not use raw `gh issue create` for tracking issues in this repo. Use the
  project helper `.agents/scripts/issue-sync-helper.sh create-signed-issue` or a
  framework helper that appends the required aidevops signature footer.
- Keep every GitHub write body as a real file that already exists before the
  `gh` write command runs. Do not create the body file later in the same shell
  command that invokes `gh`; the signature gate may check the `--body-file` path
  before the shell has produced it.
- Do not use shell heredocs, process substitution, command substitution, or
  inline generated markdown for a `gh` write body or signature. Create the
  markdown with the runtime file-writing tool, read it back if editing, then pass
  the stable path via `--body-file`.
- If a write is blocked by the signature gate, change the write path to the
  signed-file helper pattern above. Do not retry the same unsigned or inline
  command form.
- If the blocked output includes `gh-signature-helper.sh invocation failed` or
  `bash:file_not_found`, treat it as a write-path problem, not a missing repo
  dependency. Recreate the body as a stable file, use the signed issue/comment
  helper, and continue; do not spend the run diagnosing the deployed framework
  helper before the implementation work is complete.
- Verification for guidance-only fixes: run `rg -n "signature gate|body-file|heredoc|process substitution|gh issue create" AGENTS.md .agents/AGENTS.md .agents/scripts/commands/feedback-triage.md` and ensure the policy is present in the root `AGENTS.md` loaded by future workers.

#### Headless File Read Recovery

Recurring contributor-insight reports also show workers stopping after a single
`read:file_not_found` result when the path was merely misspelled or produced by a
tool with a nearby filename. Treat missing-file reads as a recovery prompt, not a
completion state:

- Re-check the exact path and basename against the previous tool output before
  assuming the artifact is unavailable; common failures include `reply3` vs
  `r3`, omitted extensions, and stale `/tmp/opencode/` filenames.
- When the read tool reports a nearby-path hint such as "you mean one of
  these?", treat that hint as the next recovery target. For example, if reading
  `phpcs.xml.dist` fails but the tool suggests `bootstrap.php`, verify and read
  the suggested path before concluding the file is unavailable.
- For tracked repository files, verify the path with `git ls-files '<pattern>'`
  before retrying `Read`; for untracked/runtime artifacts, inspect the known
  parent directory or rerun the command that generated the artifact.
- For local WordPress fatal-error reports, do not keep retrying unrelated project
  paths such as `vendor/` after a missing-file read. Make the runtime evidence
  artifact `../wordpress/wp-content/debug.log` the next read target, or enable
  `WP_DEBUG_LOG` and reproduce once if the log does not exist.
- If the first failed read was `vendor/`, treat it as a symptom of looking in
  the wrong layer until runtime evidence says otherwise: read the WordPress
  debug log before checking Composer install state or adding dependency fixes.
- When the maintainer recovery mentions a fatal error after `read vendor`, map
  the report to local WordPress activation triage, not dependency discovery:
  read `../wordpress/wp-content/debug.log`, then verify every related plugin is
  symlinked under its canonical plugin slug before changing Composer files.
- Before blaming plugin code for local activation fatals, verify the shared
  WordPress install has symlinks for every checked-out plugin worktree under each
  plugin's canonical directory name, not only the active worktree basename. Use
  `wp plugin path <plugin-slug>` or inspect `../wordpress/wp-content/plugins/`
  so the failing plugin resolves to the intended checkout. A missing, stale, or
  basename-only symlink can make WordPress load the wrong path and hide the real
  debug.log failure.
- When several local plugins or worktrees are involved, enumerate each expected
  canonical plugin slug and confirm all of them resolve to their current checkout
  before retrying activation; a single correct symlink does not prove the shared
  install is safe.
- If the corrected path is found, retry `Read` with that path and continue the
  task. If no plausible path exists after bounded recovery, record what was
  checked and continue with the next safe implementation step rather than ending
  the session solely because one optional artifact is missing.
- Verification for guidance-only fixes: run `rg -n "read:file_not_found|missing-file reads|git ls-files|debug.log|canonical plugin" AGENTS.md .agents/AGENTS.md .agents/scripts/commands/feedback-triage.md includes/Models/skills/site-troubleshooting.md` and ensure file-read recovery, debug-log triage, and canonical plugin-symlink policies are present in files loaded by future workers.

#### Headless Tool-Abort Recovery

When a headless run reports `bash:other` / `Tool execution aborted`, do not treat
the abort text itself as the task outcome:

- Inspect the immediately preceding command and expected artifact. If it was a
  diagnostic command that only printed context, replace it with the next safe
  deterministic action instead of replaying ad-hoc shell output.
- If the aborted command was required, retry once with a narrower, non-inline
  command and a clear `description`; avoid heredocs, command substitution, and
  long compound commands that make hook failures hard to attribute.
- If the retry also aborts, preserve any implementation diff with a commit or
  patch, record the exact command and blocker, and continue with independent
  verification steps before considering a BLOCKED outcome.
- Verification for guidance-only fixes: run `rg -n "Tool execution aborted|bash:other|signature gate|read:file_not_found" AGENTS.md .agents/AGENTS.md .agents/scripts/commands/feedback-triage.md` and ensure the recovery policy is loaded by future workers.

## Build Commands
- **Build**: `npm run build` or `npx wp-scripts build` (production)
- **Dev**: `npm start` or `npx wp-scripts start` (watch mode)
- **Install**: `npm install && composer install`
- **Autoload**: `composer dump-autoload` (after adding/moving PHP classes)
- **Lint JS**: `npm run lint:js` (ESLint with `@wordpress/eslint-plugin`)
- **Lint CSS**: `npm run lint:css` (Stylelint)
- **Lint PHP**: `npm run lint:php` or `composer phpcs` (WordPress Coding Standards via PHPCS)
- **Fix lint**: `npm run lint:js:fix`, `npm run lint:css:fix`, `npm run lint:php:fix`
- **Static analysis**: `composer phpstan` (PHPStan with WordPress extensions)
- **Test JS**: `npm run test:js` (Jest via `@wordpress/scripts`)
- **Test PHP**: `npm run test:php` (PHPUnit via shared WordPress tests lib; run `npm run test:php:setup` once)
- **Test E2E**: `npm run test:e2e:playwright` (Playwright)
- **Pre-commit**: Husky + lint-staged runs lint fixes on staged files
- **Dev environment**: `npx wp-env start` (WordPress 7.0 via `.wp-env.json`) — dev site at http://localhost:8890, test site at http://localhost:8893

## Worker Self-Verification Protocol

**Applies to every headless dispatched worker** (auto-dispatch, pulse, manual `headless-runtime-helper.sh run`). Interactive maintainer sessions must also satisfy steps 1–4 before pushing.

**Hard gate — do NOT open a PR until ALL of the following succeed locally on your worktree branch:**

1. **Full PHPUnit suite passes**

   ```bash
   npm run test:php
   ```

   The final summary line MUST read `Tests: <N>, Assertions: <M>, ... Errors: 0, Failures: 0` (Skipped > 0 is acceptable). A filtered run (`--filter ClassName`) is NOT sufficient — your change may break a test in a different file. Run the full suite.

2. **Lint passes**

   ```bash
   npm run lint:php
   npm run lint:js
   npm run lint:css
   ```

3. **Static analysis passes**

   ```bash
   composer phpstan
   ```

4. **Build succeeds**

   ```bash
   npm run build
   ```

   Or run all four steps in sequence with the single alias:

   ```bash
   npm run verify
   ```

   `npm run verify` = `lint` → `phpstan` → `test:php` → `build`. If any step fails, the rest are skipped — fix the failure and rerun.

5. **Paste the test summary in the PR body** under a `## Worker self-verification` heading, in this exact format:

   ```text
   ## Worker self-verification
   - PHPUnit: Tests: 2281, Assertions: 6157, Errors: 0, Failures: 0, Skipped: 104
   - Lint:    PHP/JS/CSS all clean
   - PHPStan: 0 errors
   - Build:   succeeded
   ```

   Reviewers and auto-merge gates read this block to verify you ran the suite. Missing or stale summary = PR is treated as unverified and will be rejected by auto-merge.

**Failure modes that have shipped to PRs and been reverted (do NOT repeat):**

- Adding new tests, running ONLY those tests (or only the filtered class), and opening the PR while the full suite has errors — see PR #1537, PR #1538 (both closed). The worker added tests, the tests failed against the worker's own implementation, and CI caught it. Fix the implementation; do not paper over by deleting/skipping the test.
- Treating PR CI as the verification step. CI is the **second** gate. The worker self-verification block above is the **first** gate. Workers that rely on CI burn API budget, block other workers, and waste reviewer time.
- Reporting "tests pass" without the actual summary line. The summary is the evidence; "tests pass" is intent.

**If verification fails:**

- Fix the implementation until step 1 is genuinely clean.
- If a pre-existing failure is unrelated to your change, file a separate issue, link it in the PR body, and proceed only if the failure was already failing on `main` (provide proof — `git stash && npm run test:php` on `main`).
- Never `--filter` past failures, never delete failing tests, never mark them skipped without a linked tracking issue and explicit user approval.

## Code Style & Architecture

### PHP (PSR-4 + PHP 8.2+)
- **Namespace**: PSR-4 namespaces under `SdAiAgent\` (e.g., `namespace SdAiAgent\Core;`)
- **Class names**: PascalCase (e.g., `AgentLoop`, `RestController`)
- **File naming**: `{ClassName}.php` matching the class name exactly
- **Directory structure**:
  - `includes/Core/` - Core classes (Database, Settings, AgentLoop, BudgetManager)
  - `includes/Models/` - Data models (Memory, Skill, Agent, ConversationTemplate, Chunker)
  - `includes/Abilities/` - WordPress Abilities API implementations (30+ ability classes)
  - `includes/Knowledge/` - Knowledge base system (collections, sources, chunks, RAG search)
  - `includes/Tools/` - Custom tools, tool profiles, and tool discovery
  - `includes/Automations/` - Scheduled and event-driven automations
  - `includes/Benchmark/` - Model benchmarking (runner, suite, scoring)
  - `includes/REST/` - REST API controllers (RestController, WebhookController, McpController, ResaleApiController, BenchmarkController)
  - `includes/Admin/` - Admin pages (UnifiedAdminMenu, ModelBenchmarkPage)
  - `includes/CLI/` - WP-CLI commands
  - `includes/Enums/` - PHP 8.1+ enums
- **Constants**: SCREAMING_SNAKE_CASE (e.g., `DB_VERSION`, `PAGE_SLUG`)
- **Methods**: snake_case (e.g., `get_session()`, `create_session()`, `list_sessions()`)
- **Properties**: camelCase with typed declarations
- **Hooks**: Use `add_action()`, `add_filter()` with priority 10 by default
- **Autoloading**: Composer PSR-4 from `includes/` directory
- **Type declarations**: Required for all parameters and return types
- **Strict types**: All files must declare `declare(strict_types=1);`
- **Error handling**: Return `WP_Error` objects; never throw exceptions in hooks

### JavaScript (React + WordPress Components)
- **Framework**: React 18 with `@wordpress/element` and `@wordpress/components`
- **State**: Redux via `@wordpress/data` store (see `src/store/index.js`)
- **Imports**: WordPress packages first, then internal dependencies
- **File structure**: React components in `src/components/`, entry points in `src/`
- **Styling**: CSS files in same directory as component (`style.css`), prefix all classes with `sd-ai-agent-`
- **i18n**: Always use \`__( 'text', 'superdav-ai-agent' )\` for translatable strings
- **Hooks**: Use WordPress data hooks (`useSelect`, `useDispatch`) consistently
- **Build**: Webpack via `@wordpress/scripts` with entry points defined in `webpack.config.js`

### Naming Conventions
- **Variables**: camelCase in both JS and PHP
- **Functions/Methods**: snake_case in PHP, camelCase in JS
- **Classes**: PascalCase (e.g., `AgentLoop`, `MemoryAbilities`)
- **Components**: PascalCase (e.g., `ChatPanel`, `MessageList`)
- **Enums**: PascalCase with PascalCase cases (e.g., `MemoryCategory::SiteInfo`)
- **Database tables**: Prefixed with `{$wpdb->prefix}sd_ai_agent_` (23 tables across 4 schema files)
- **REST routes**: `/sd-ai-agent/v1/{endpoint}` namespace
- **CSS classes**: Prefixed with `sd-ai-agent-` (e.g., `sd-ai-agent-chat-panel`)

## Dependency Injection (x-wp/di)

All hook wiring flows through an `x-wp/di` container. `sd-ai-agent.php` is ~70 lines — just constants, autoloader, and `xwp_load_app()`. The 24 `#[Handler]` classes in `Plugin.php` manage everything.

**Read [`docs/x-wp-di.md`](docs/x-wp-di.md)** before:
- Adding new handlers or REST controllers
- Converting legacy `add_action()` calls to DI
- Debugging handler loading, context, or route registration issues
- Working with the DI cache (`build/di-cache/`)

Key gotchas: `compile_class` required for hyphenated IDs, `REST_Handler` supports only one basename, `CTX_REST` doesn't load in PHPUnit (see doc for workaround).

## WordPress SDK Integration
- Use `wp_ai_client_prompt()` for AI calls (WordPress 7.0+ AI Client SDK)
- Register abilities via `wp_register_ability()` (Abilities API)
- All tool schemas follow OpenAI function-calling JSON schema format
- Provider/model selection via WordPress Connectors API (Settings > Connectors)
- Abilities extend `AbstractAbility` which extends core `WP_Ability`

### Provider Credentials and Model Discovery
- Do **not** add a plugin-level cache around provider/model discovery. The WP AI
  Client SDK already caches model metadata, and an extra cache requires brittle
  invalidation rules for unknown third-party provider option names.
- When provider availability must reflect newly saved keys (for example,
  `ai-provider-for-anthropic-max` or other connector plugins), reload credentials
  from the registry/options at request time via `ProviderCredentialLoader::load()`
  and let `/providers` build its response fresh.
- Treat third-party provider option names as open-ended. Do not depend on a fixed
  list of option keys to invalidate provider state; prefer the connector registry,
  provider credential loader, and request-time option reads.
- If provider discovery appears stale, fix the credential-loading path or remove
  redundant caching; do not whitelist individual option keys as a cache
  invalidation strategy.

### Secret-Option Read Blocklist (single source of truth)

Authentication keys and salts (`auth_key`, `secure_auth_key`, `logged_in_key`,
`nonce_key`, plus the four matching `*_salt` names) are written to `wp_options`
by WordPress when `wp-config.php` does not define them. Any code path that
exposes those values to the AI agent enables session forgery and admin
impersonation.

The single source of truth for the read blocklist lives in
`includes/Abilities/OptionsAbilities.php`:

- `OptionsAbilities::SECRET_READ_BLOCKLIST` — shipped names.
- `OptionsAbilities::get_secret_read_blocklist()` — runtime list (filterable
  via `sd_ai_agent_options_read_blocklist`).
- `OptionsAbilities::is_secret_option_name( string $name ): bool` — predicate
  every read surface must call.
- `OptionsAbilities::SECRET_REDACTED_PLACEHOLDER` — the opaque token any
  surface that must keep the row visible writes in place of the value.
- `OptionsAbilities::secret_read_error( string $name ): WP_Error` — uniform
  `sd_ai_agent_option_secret_redacted` error code (HTTP 403).

For option writes, the same file is the single source of truth for the
default-deny write policy:

- `OptionsAbilities::get_write_blocklist()` — protected names that always win.
- `OptionsAbilities::get_write_allowlist()` — exact names site code explicitly
  opts into AI write/delete access.
- `OptionsAbilities::get_write_allowlist_prefixes()` — allowed prefixes; by
  default only this plugin's `sd_ai_agent_` option namespace.
- `OptionsAbilities::is_write_allowed_option( string $name ): bool` — predicate
  every option write/delete surface must call after empty-name validation.

**Rules for any new ability, REST controller, CLI command, or SQL helper that
reads option data:**

1. Call `OptionsAbilities::is_secret_option_name( $name )` before returning
   any value sourced from `wp_options`, `wp_sitemeta`, `wp_*_meta`, transients,
   or a WP-CLI subprocess.
2. For row-shaped responses, omit the row OR redact `option_value` /
   `meta_value` to `SECRET_REDACTED_PLACEHOLDER`. Never include the row with
   the real value.
3. Reject SQL queries whose literal arguments include a secret option name
   (`DatabaseQueryAbility::find_secret_option_literal()` is the reference
   implementation).
4. For low-level function callers like `RunPhpAbility`, gate
   `get_option`/`get_site_option`/`get_network_option`/`get_transient`/
   `get_site_transient` on the read predicate and gate the matching write
   functions on `OptionsAbilities::is_write_allowed_option()`; the blocklist
   still takes precedence and should keep the protected error path distinct.

**Verification (must run for any PR that touches option reads or writes):**

```bash
rg -n "is_secret_option_name|is_write_allowed_option|get_write_allowlist_prefixes|SECRET_REDACTED_PLACEHOLDER|sd_ai_agent_option_secret_redacted|sd_ai_agent_options_write_allowlist|sd_ai_agent_options_write_allowlist_prefixes|sd_ai_agent_options_read_blocklist" includes/ tests/
npm run test:php -- --filter='OptionsAbilitiesTest|WordPressAbilitiesTest|DatabaseAbilitiesTest|WpCliAbilitiesTest'
```

The first command must show coverage in every read/write surface (get-option,
list-options, update-option, delete-option, db-query, run-php, wp-cli). The
second must report zero failures for the four ability suites.

### REST API Security and Operational Guidelines
- **Secret Scrubbing**: All REST endpoints must scrub sensitive data (API keys, tokens,
  credentials) from responses and logs. Never expose provider credentials, user secrets,
  or internal configuration in REST output. For option-shaped data, route through the
  Secret-Option Read Blocklist above — do not invent a parallel scrubber.
- **Internal namespace block**: Keep `sd-ai-agent/v1` unavailable to the agent-facing
  `wp-rest/execute` ability. This plugin must not call its own private REST controllers
  through the internal dispatcher; use direct service/controller calls instead.
- **File Upload Restrictions**: Hide or restrict file upload endpoints from public access.
  File upload functionality should be gated behind capability checks and only exposed
  to authenticated users with explicit permissions.
- **User Context**: Always execute REST operations as the real current user (via
  `get_current_user_id()` and capability checks). Never bypass user context or
  execute operations as a privileged system user without explicit authorization.
- **Endpoint Visibility**: The `sd-ai-agent/v1` namespace is private to the plugin.
  Do not expose internal implementation details, debug endpoints, or experimental
  features in the public REST API. Use capability checks to gate all endpoints.
- **WordPress.org readiness**: Private REST routes may ship in the WordPress.org
  plugin when they are capability-gated, secret-scrubbed, and documented with
  worker-ready GitHub issue briefs for any remaining exposure or hardening work.
- **Review checklist**: When adding or hardening routes under `sd-ai-agent/v1`,
  verify the controller uses the real current user context, has a capability
  gate, avoids public file-upload exposure, and returns scrubbed responses before
  calling the route WordPress.org-ready.
- **Evidence checklist for contributor-insight REST hardening**: cite the exact
  inspected guard before deciding guidance-only is enough. Reference
  `includes/Abilities/WpRestAbilities.php` for the `sd-ai-agent/v1` execute
  block, file-upload hiding, current-user dispatcher behaviour, and audit-log
  secret scrubbing; reference `includes/REST/PermissionTrait.php` or the concrete
  controller for the route capability check; reference the upload controller
  method (for example `includes/REST/KnowledgeController.php`) when the note says
  to hide or restrict uploads.
- **Follow-up issue briefs**: If a REST hardening pass finds remaining exposure,
  file worker-ready GitHub issue briefs that name the route/controller, the missing
  guard or scrubber, the expected safe behaviour, and the exact verification command
  (for example PHPCS/PHPUnit plus a REST request proving unauthorized users are
  blocked and secrets are redacted).

## Local Development Environment

The shared WordPress dev install for testing this plugin is at `../wordpress` (relative to this repo root).

- **URL**: http://wordpress.local:8080
- **Admin**: http://wordpress.local:8080/wp-admin — `admin` / `admin`
- **WordPress version**: 7.0-RC2
- **This plugin**: symlinked into `../wordpress/wp-content/plugins/$(basename $PWD)` for the active worktree, and into the canonical WordPress plugin directory when testing slug-specific activation/loading behaviour.
- **Reset to clean state**: `cd ../wordpress && ./reset.sh`

WP-CLI is configured via `wp-cli.yml` in this repo root — run `wp` commands directly from here without specifying `--path`.

```bash
wp plugin activate $(basename $PWD)   # activate this plugin
wp plugin deactivate $(basename $PWD) # deactivate
wp db reset --yes && cd ../wordpress && ./reset.sh  # full reset
```

## Session Completion

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until `git push` succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work** - Create GitHub issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   git push
   git status  # MUST show "up to date with origin"
   ```
4. **Clean up** - Clear stashes, prune remote branches
5. **Verify** - All changes committed AND pushed
6. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds
- Do NOT use `bd`, `beads`, or any local issue tracker — use GitHub issues directly
