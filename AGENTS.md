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
- Close with the resubmission status and any specific reviewer action requested.

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
- If provider discovery appears stale, fix the credential-loading path or remove
  redundant caching; do not whitelist individual option keys as a cache
  invalidation strategy.

## Local Development Environment

The shared WordPress dev install for testing this plugin is at `../wordpress` (relative to this repo root).

- **URL**: http://wordpress.local:8080
- **Admin**: http://wordpress.local:8080/wp-admin — `admin` / `admin`
- **WordPress version**: 7.0-RC2
- **This plugin**: symlinked into `../wordpress/wp-content/plugins/$(basename $PWD)`
- **Reset to clean state**: `cd ../wordpress && ./reset.sh`

WP-CLI is configured via `wp-cli.yml` in this repo root — run `wp` commands directly from here without specifying `--path`.

```bash
wp plugin activate $(basename $PWD)   # activate this plugin
wp plugin deactivate $(basename $PWD) # deactivate
wp db reset --yes && cd ../wordpress && ./reset.sh  # full reset
```

<!-- BEGIN BEADS INTEGRATION v:1 profile:minimal hash:ca08a54f -->
## Beads Issue Tracker

This project uses **bd (beads)** for issue tracking. Run `bd prime` to see full workflow context and commands.

### Quick Reference

```bash
bd ready              # Find available work
bd show <id>          # View issue details
bd update <id> --claim  # Claim work
bd close <id>         # Complete work
```

### Rules

- Use `bd` for ALL task tracking — do NOT use TodoWrite, TaskCreate, or markdown TODO lists
- Run `bd prime` for detailed command reference and session close protocol
- Use `bd remember` for persistent knowledge — do NOT use MEMORY.md files

## Session Completion

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until `git push` succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   bd dolt push
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Clean up** - Clear stashes, prune remote branches
6. **Verify** - All changes committed AND pushed
7. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds
<!-- END BEADS INTEGRATION -->
