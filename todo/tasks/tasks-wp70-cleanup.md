# Tasks: WP 7.0 Cleanup — Fix Settings Tabs, Flatten Hierarchy, Remove Custom Providers

Based on [ai-dev-tasks](https://github.com/snarktank/ai-dev-tasks) task format, with time tracking.

**Task:** t144
**Created:** 2026-04-03
**Status:** In Progress
**Estimate:** ~9h (ai:7h test:1.5h read:30m)

## Relevant Files

- `src/settings-page/style.css` - CSS for settings tabs (overflow bug at line 54)
- `src/settings-page/settings-app.js` - 18-tab TabPanel with scroll wrapper
- `src/settings-page/providers-manager.js` - Custom provider key management UI (to be removed)
- `src/unified-admin/routes/settings.js` - Outer 3-tab TabPanel wrapping SettingsApp (to be flattened)
- `src/components/onboarding-wizard.js` - Provider key entry during onboarding
- `src/floating-widget/site-builder-overlay.js` - Provider key entry in site builder (deleted 2026-05-13 with Site Builder mode removal — beads sd-ai-dh0)
- `src/store/index.js` - fetchProviders action and provider state
- `includes/Core/Settings.php` - DIRECT_PROVIDERS constant, get/set_provider_key methods
- `includes/Core/CredentialResolver.php` - OpenAI compat endpoint/key/timeout options
- `includes/Core/AgentLoop.php` - send_prompt_openai/anthropic/google/direct methods (~700 lines)
- `includes/REST/RestController.php` - provider-key endpoints, handle_providers, title generation
- `.wp-env.json` - Dev environment WP version (6.9 → 7.0)

## Notes

- The compat layer removal (refactor/drop-wp69-compat branch) is already committed
- WP 7.0-RC2 is running in wp-env and the plugin activates successfully
- WP 7.0 Connectors API provides Settings > Connectors for provider API key management
- `wp_ai_client_prompt()` is the WP 7.0 SDK entry point for all AI calls
- The direct HTTP paths exist to work around a plugin-check autoloader conflict — verify if still needed on 7.0

## Instructions

**IMPORTANT:** As you complete each task, check it off by changing `- [ ]` to `- [x]`.

Update after completing each sub-task, not just parent tasks.

## Tasks

- [x] 0.0 Create feature branch ~5m (ai:5m)
  - [x] 0.1 Already on `refactor/drop-wp69-compat` worktree branch

- [ ] 1.0 Fix settings tab bar overflow bug ~15m (ai:15m)
  - [ ] 1.1 Fix CSS selector mismatch in `src/settings-page/style.css`: change `.ai-agent-settings` to `.sd-ai-agent-settings` so `overflow-x: auto` applies to the 18-tab bar
  - [ ] 1.2 Verify the scroll wrapper fade indicators (`.has-scroll-left`/`.has-scroll-right` pseudo-elements) work with the corrected selector
  - [ ] 1.3 Build (`npm run build`) and verify in browser that all 18 tabs are scrollable

- [ ] 2.0 Flatten settings tab hierarchy ~1h (ai:45m test:15m)
  - [ ] 2.1 Remove the outer 3-tab TabPanel (General/Providers/Advanced) from `src/unified-admin/routes/settings.js` — render `<SettingsApp />` directly inside the Card
  - [ ] 2.2 Remove the `ProvidersManager` import and `AdvancedSettings` component from `routes/settings.js` (these are already tabs inside SettingsApp)
  - [ ] 2.3 Remove the `provider-keys` REST fetch from `routes/settings.js` (SettingsApp handles its own data)
  - [ ] 2.4 Verify the `subRoute` prop still works for deep-linking (e.g. `#/settings/advanced`)
  - [ ] 2.5 Build and verify in browser: single tab bar, no duplicate tab names, all tabs accessible

- [ ] 3.0 Remove custom provider management UI ~3h (ai:2.5h test:30m)
  - [ ] 3.1 Audit WP 7.0 Connectors API: verify `_wp_connectors_get_provider_settings()` returns configured providers and `wp_ai_client_prompt()` uses them automatically
  - [ ] 3.2 Remove `src/settings-page/providers-manager.js` (entire file)
  - [ ] 3.3 Remove the "Providers" tab from `settings-app.js` tab list and its `case 'providers':` switch branch
  - [ ] 3.4 Remove provider key entry from `src/components/onboarding-wizard.js` — replace with a notice linking to Settings > Connectors
  - [x] 3.5 Remove provider key entry from `src/floating-widget/site-builder-overlay.js` — N/A: the overlay file and Site Builder mode were removed entirely (beads sd-ai-dh0, 2026-05-13). Fresh installs now use the Setup Assistant agent bootstrap flow, which never asks for provider keys (Settings > Connectors only).
  - [x] 3.6 Remove `get_provider_key()` / `set_provider_key()` / `get_configured_direct_providers()` from `includes/Core/Settings.php` and remove the `PROVIDER_KEYS_OPTION` constant. **Scope deviation:** keep `Settings::DIRECT_PROVIDERS` as a static catalog of provider IDs and model lists — without it, bare-WP installs (no third-party AI provider plugin) cannot enumerate available models. Add `Settings::get_connectors_api_key()` to read the WP 7.0 Connectors API option (`connectors_ai_{provider}_api_key`).
  - [x] 3.7 Remove REST endpoints `POST /settings/provider-key` and `POST /settings/provider-key/test` from `includes/REST/SettingsController.php`. (There was never a `GET /settings/provider-keys` route — the original plan was wrong; presence flags were carried inside `GET /settings`.)
  - [x] 3.8 Remove `_provider_keys` from the `GET /settings` REST response.
  - [x] 3.9 Update `handle_providers()` and `handle_alerts()` to read keys via `Settings::get_connectors_api_key()` instead of `get_provider_key()`. The `DIRECT_PROVIDERS` loop stays as the provider catalog when no third-party AI provider plugin is active.
  - [x] 3.10 Remove or simplify `CredentialResolver` — Claude Max OAuth is now owned by the separate `ai-provider-for-anthropic-max` plugin, so `getClaudeMaxToken()`/`setClaudeMaxToken()`/`hasClaudeMaxToken()` and `CLAUDE_MAX_TOKEN_OPTION` have all been removed along with the `/settings/claude-max-token` REST route, `Settings::get_claude_max_token()`/`set_claude_max_token()`, and `_has_claude_max_token` response field. `AI_EXPERIMENTS_CREDENTIALS_OPTION` and `openai_compat_*` cleanup remain out of scope for this PR.
  - [ ] 3.11 Update `AgentLoop::ensure_provider_credentials_static()` — remove Source 2 (AI Experiments) and Source 3 (OpenAI compat); keep Source 1 (WP 7.0 Connectors API)
  - [ ] 3.12 Remove provider key tests from `tests/SdAiAgent/Core/CredentialResolverTest.php` (openai_compat tests)
  - [x] 3.13 Update `src/store/index.js` — simplify `fetchProviders` to only read from the `/providers` REST endpoint (no more `_provider_keys` state). Confirmed already done in a prior JS cleanup; no `_provider_keys` references remain in `src/`.
  - [ ] 3.14 Build and verify: no provider key UI in settings, onboarding points to Connectors, chat still shows available providers from registry

- [ ] 4.0 Remove direct provider HTTP paths ~3h (ai:2.5h test:30m)
  - [ ] 4.1 Verify `wp_ai_client_prompt()` on WP 7.0-RC2 supports: tool calls (function calling), streaming (SSE), temperature, max_tokens, system instruction
  - [ ] 4.2 Remove `send_prompt_openai()` (~60 lines) from `AgentLoop.php`
  - [ ] 4.3 Remove `send_prompt_anthropic()` (~190 lines) from `AgentLoop.php` — includes Anthropic-specific message format conversion
  - [ ] 4.4 Remove `send_prompt_google()` (~75 lines) from `AgentLoop.php`
  - [ ] 4.5 Remove `send_prompt_direct()` (~80 lines) and `send_prompt_direct_streaming()` (~120 lines) from `AgentLoop.php`
  - [ ] 4.6 Simplify `send_prompt()` to always use `wp_ai_client_prompt()` via the SDK path — remove the provider-specific routing switch
  - [ ] 4.7 Remove `build_openai_messages()` and `build_openai_tools()` helper methods if they are only used by the direct paths (check if SDK path uses them too)
  - [ ] 4.8 Remove `SimpleAiResult` class if no longer needed (the SDK returns `GenerativeAiResult`)
  - [ ] 4.9 Update `configure_model()` to remove OpenAI-compatible connector fallback logic
  - [ ] 4.10 Remove `call_openai_compat_for_title()` from `RestController.php` — use `wp_ai_client_prompt()` for title generation
  - [ ] 4.11 Update `AgentLoopTest.php` — remove `openai_compat_endpoint_url`/`openai_compat_api_key` option setup; update tests to work with SDK path
  - [ ] 4.12 Run full test suite and fix failures

- [ ] 5.0 Update `.wp-env.json` to WP 7.0 ~10m (ai:10m)
  - [ ] 5.1 Change `"core": "WordPress/WordPress#6.9"` to `"core": "WordPress/WordPress#7.0-branch"` (already done in worktree, needs committing)
  - [ ] 5.2 Verify wp-env starts cleanly and plugin activates on WP 7.0-RC2

- [ ] 6.0 Testing and verification ~1h (ai:15m test:45m)
  - [ ] 6.1 Run `npm run build` — verify clean build with no errors
  - [ ] 6.2 Run `npm run lint:js` — fix any new violations
  - [ ] 6.3 Run `composer phpcs` — fix any new violations
  - [ ] 6.4 Run `composer phpstan` — fix any new type errors
  - [ ] 6.5 Run `npm run test:php` — verify PHPUnit tests pass on WP 7.0
  - [ ] 6.6 Browser test: navigate all settings tabs, verify scrolling works
  - [ ] 6.7 Browser test: start a chat session, verify AI responses work through WP SDK
  - [ ] 6.8 Browser test: verify onboarding wizard shows Connectors link instead of inline key entry

- [ ] 7.0 Quality and review ~30m (ai:20m read:10m)
  - [ ] 7.1 Self-review all changed files for dead code, stale comments, unused imports
  - [ ] 7.2 Update AGENTS.md if any architectural changes affect developer guidance
  - [ ] 7.3 Commit with descriptive message
  - [ ] 7.4 Push branch and create PR

<!--TOON:tasks[8]{id,parent,desc,est,est_ai,est_test,status,actual,completed}:
0.0,,Create feature branch,5m,5m,,done,,
1.0,,Fix settings tab bar overflow bug,15m,15m,,pending,,
2.0,,Flatten settings tab hierarchy,1h,45m,15m,pending,,
3.0,,Remove custom provider management UI,3h,2.5h,30m,pending,,
4.0,,Remove direct provider HTTP paths,3h,2.5h,30m,pending,,
5.0,,Update wp-env to WP 7.0,10m,10m,,pending,,
6.0,,Testing and verification,1h,15m,45m,pending,,
7.0,,Quality and review,30m,20m,,pending,,
-->

## Time Tracking

| Task | Estimated | Actual | Variance |
|------|-----------|--------|----------|
| 0.0 Create branch | 5m | 5m | 0 |
| 1.0 Fix tab overflow | 15m | - | - |
| 2.0 Flatten tab hierarchy | 1h | - | - |
| 3.0 Remove provider UI | 3h | - | - |
| 4.0 Remove direct HTTP paths | 3h | - | - |
| 5.0 Update wp-env | 10m | - | - |
| 6.0 Testing | 1h | - | - |
| 7.0 Quality | 30m | - | - |
| **Total** | **~9h** | **-** | **-** |

<!--TOON:time_summary{total_est,total_actual,variance_pct}:
9h,,
-->

## Completion Checklist

Before marking this task list complete:

- [ ] All tasks checked off
- [ ] Tests passing
- [ ] Linters passing
- [ ] Documentation updated
- [ ] CHANGELOG.md updated
- [ ] PR created and ready for review
- [ ] Time actuals recorded
