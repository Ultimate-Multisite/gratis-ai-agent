# Superdav AI Agent - Task Management

## Ready

- [x] t225 WordPress plugin check readiness (wp.org submission) @superdav42 #enhancement ~30m logged:2026-04-20
  - Ran `wp plugin check` for WordPress.org submission
  - Fixed: removed stray zip from root, added build/* to .distignore
  - .distignore correctly excludes all dev files but scanner scans dev dir anyway
  - Production warnings: upgrade notice length (readme.txt), non-prefixed globals ($_rest_prefix, $_request_uri)

- [x] t138 Add E2E tests for spending limits and budget caps @superdav42 #testing ~2h ref=GH#651 #auto-dispatch pr:#655 completed:2026-03-24
  - Spending limits are a financial safety feature — silent regressions could allow unbounded AI spend
  - Create tests/e2e/spending-limits.spec.js with ≥4 test cases covering settings UI, persistence, enforcement warning, reset

- [x] t139 Add E2E tests for text-to-speech feature @superdav42 #testing ~2h ref=GH#652 #auto-dispatch pr:#654 completed:2026-03-24
  - TTS is a user-facing accessibility feature with no E2E coverage — regressions break audio output silently
  - Create tests/e2e/text-to-speech.spec.js with ≥3 test cases covering toggle, persistence, play button visibility

- [x] t140 Add E2E tests for white-label branding (custom agent name, colors, logo) @superdav42 #testing ~2h ref=GH#653 #auto-dispatch pr:#656 completed:2026-03-24
  - White-label is a resale/agency feature — regressions break customer-facing deployments silently
  - Create tests/e2e/white-label.spec.js with ≥4 test cases covering settings fields, name reflection, color update, reset

- [x] t137 Bump version to 1.3.0 and update CHANGELOG.md @superdav42 #chore ~1h ref=GH#646 #auto-dispatch pr:#647 completed:2026-03-24
  - 53 commits merged since v1.2.0: new models (o3, o4-mini, claude-sonnet-4-6, Gemini 2.5 Flash), PHPStan level 10, E2E coverage, bug fixes
  - Update CHANGELOG.md [Unreleased] → [1.3.0], bump Version in sd-ai-agent.php, update readme.txt Stable tag

- [x] t136 Add JS bundle size budget and performance benchmarks @superdav42 #ci ~2h ref=GH#611 pr:#616 completed:2026-03-19
  - Plugin has grown with Chart.js, CodeMirror 6, highlight.js — no size budget means silent bloat
  - Add size-limit to package.json; budget: main bundle < 400KB gzipped; add CI step to code-quality.yml

- [x] t135 Add E2E tests for Changes page (diff/revert/export) @superdav42 #testing ~2h ref=GH#610 pr:#615 completed:2026-03-19
  - Changes page (diff view, revert, export patch) has zero E2E coverage — destructive feature, high regression risk

- [x] t134 Add E2E tests for automations system (t080/t081) @superdav42 #testing ~3h ref=GH#609 pr:#613 completed:2026-03-24
  - Event-triggered and scheduled automations have zero E2E coverage — silent regression risk for scheduled AI tasks

- [x] t133 Add E2E tests for agent builder UI (t082) @superdav42 #testing ~3h ref=GH#608 pr:#612 completed:2026-03-24
  - Agent builder (create/edit/delete custom agents) shipped in t082 with no E2E coverage

- [x] t132 Add E2E tests for shared conversations feature (t091) @superdav42 #testing ~3h ref=GH#607 pr:#614 completed:2026-03-24
  - Shared conversations (view/contribute permissions, revocation) shipped in t091 with no E2E coverage

- [x] t131 Raise PHPStan level from 9 to 10 (max) and fix new errors @superdav42 #quality ~3h ref=GH#605 pr:#606 completed:2026-03-19
  - Continue quality ladder: t010 (5→6), t110 (6→7), t125 (7→8), t130 (8→9), now 9→10
  - Level 10 is maximum: dead code detection, impossible type checks, strictest mixed narrowing

- [x] t129 Add o3, o4-mini, and claude-sonnet-4-6 to model selector @superdav42 #enhancement ~1h ref=GH#601 pr:#603 completed:2026-03-19
  - o3 and o4-mini already in CostCalculator but missing from Settings; claude-sonnet-4-6/opus-4-6 missing from both
  - Update Settings.php model arrays + CostCalculator.php pricing; update Anthropic default_model to claude-sonnet-4-6

- [x] t130 Raise PHPStan level from 8 to 9 and fix new errors @superdav42 #quality ~3h ref=GH#602 pr:#604 completed:2026-03-19
  - Continue quality ladder: t010 (5→6), t110 (6→7), t125 (7→8), now 8→9
  - Level 9 adds stricter mixed type narrowing, null handling, array shapes

- [x] t127 Submit Superdav AI Agent to WordPress.org plugin directory via SVN @superdav42 #devops ~2h ref=GH#597 pr:#600 completed:2026-03-19
  - SVN repo returns 404 (plugin not yet submitted for review). Documented full submission process in docs/wordpress-org-submission.md and added bin/deploy-wporg.sh. Manual step required: submit ZIP at wordpress.org/plugins/developers/add/

- [x] t128 Add Gemini 2.5 Flash and Gemini 2.5 Flash Lite to model selector @superdav42 #feature ~2h ref=GH#598 pr:#599 completed:2026-03-19
  - Gemini 2.5 Flash ($0.30/1M input), Flash Lite ($0.10/1M). Needs OpenRouter/LiteLLM adapter. Update CostCalculator + Settings UI tier grouping

- [x] t124 Prepare plugin for WordPress.org submission (review checklist) @superdav42 #devops ~4h ref=GH#591 pr:#596 completed:2026-03-19
  - Run wp plugin check, audit sanitization/nonces/capabilities, add GPL headers, create screenshots, submit via SVN

- [x] t125 Raise PHPStan level from 7 to 8 and fix new errors @superdav42 #quality ~3h ref=GH#592 pr:#595 completed:2026-03-19
  - Continue quality ladder: t010 (5→6), t110 (6→7), now 7→8. Level 8 adds stricter generics/template type checks.

- [x] t126 Add Claude 3.5 Haiku and Gemini 2.0 Flash to model selector @superdav42 #feature ~2h ref=GH#593 pr:#594 completed:2026-03-19
  - claude-3-5-haiku-20241022 ($0.80/1M), gemini-2.0-flash ($0.10/1M), gemini-2.0-flash-lite ($0.075/1M)
  - Update CostCalculator pricing and Settings UI tier grouping

- [x] t120 Fix readme.txt PHP requirement: update 7.4 to 8.2 to match plugin header and CI @superdav42 #bug ~0.5h ref=GH#581 pr:#582 completed:2026-03-19
  - readme.txt says "Requires PHP: 7.4" but plugin header says PHP 8.2 and CI runs PHP 8.2
  - t109 was incorrectly linked to PR #560 (image upload) — the actual fix was never applied
  - Fix: update readme.txt "Requires PHP: 7.4" → "Requires PHP: 8.2"

- [x] t121 Bump version to 1.2.0 and update CHANGELOG.md @superdav42 #chore ~2h ref=GH#583 pr:#586 completed:2026-03-19
  - Plugin at v1.1.0 but 100+ PRs merged since — streaming, image upload, spending limits, mobile UX, a11y, etc.
  - Update CHANGELOG.md [Unreleased] → [1.2.0], bump Version in sd-ai-agent.php, update readme.txt Stable tag

- [x] t122 Add E2E tests for image/file upload in chat (t109 feature) @superdav42 #testing ~3h ref=GH#584 pr:#587 completed:2026-03-19
  - PR #560 added upload but no E2E tests — paperclip button, drag-drop, thumbnail preview, remove button

- [x] t123 Fix ShellCheck violations in .agents/scripts/ and bin/ @superdav42 #quality ~1h ref=GH#585 pr:#588 completed:2026-03-19
  - 1 error (SC2148 missing shebang in husky.sh), 6 warnings (SC2015, SC1091, SC2086, SC2046, SC2001)

- [x] t109 Fix readme.txt PHP requirement: update 7.4 to 8.2 to match plugin header and CI @superdav42 #bug ~0.5h ref=GH#550 pr:#560 completed:2026-03-19
  - readme.txt says "Requires PHP: 7.4" but plugin header says PHP 8.2 and CI runs PHP 8.2
  - Codebase uses PHP 8.1 enums (ToolType, MemoryCategory, Schedule, HttpMethod) — 7.4 is impossible
  - Fix: update readme.txt "Requires PHP: 7.4" → "Requires PHP: 8.2"

- [x] t110 Raise PHPStan level from 6 to 7 and fix new errors @superdav42 #quality ~3h ref=GH#6 pr:#559 completed:2026-03-19
  - Current level: 6 (set in t010). Level 7 adds stricter type inference and union type checks.
  - Run `vendor/bin/phpstan analyse --level=7` to see new errors, then fix them
  - Update phpstan.neon level from 6 to 7

- [x] t111 Update GitHub Actions to Node.js 24 compatible versions @superdav42 #ci ~1h ref=GH#24 pr:#558 completed:2026-03-19
  - CI warns: "actions/cache@v4 and actions/checkout@v4 running on Node.js 20 — deprecated"
  - Node.js 24 becomes default on June 2, 2026 (~2.5 months away)
  - Update all workflow files to use latest v4 patch versions that support Node.js 24
  - Affected workflows: tests.yml, e2e.yml, code-quality.yml, release.yml, issue-sync.yml, todo-integrity.yml
  - Also add FORCE_JAVASCRIPT_ACTIONS_TO_NODE24=true env var to opt in early

- [x] t112 Add E2E tests for auto-title sessions and abilities search/filter @superdav42 #testing ~3h ref=GH#551 pr:#553 completed:2026-03-19
  - t099 (auto-title) and t098 (abilities search) shipped without E2E coverage
  - Add to chat-interactions.spec.js: verify session gets auto-titled after first AI response
  - Add to admin-page.spec.js: verify abilities search filters the list, categories collapse/expand
  - These are user-visible features that should be regression-tested

- [x] t106 Update default model to GPT-4.1-nano and add GPT-4.1 model family to selector @superdav42 #feature ~2h ref=GH#539 pr:#542 completed:2026-03-19
  - Update Settings::DIRECT_PROVIDERS openai default_model from gpt-4o to gpt-4.1-nano
  - Add GPT-4.1-nano, GPT-4.1-mini, GPT-4.1 to OpenAI model list in Settings
  - CostCalculator already has pricing — no changes needed there
  - Existing installs with gpt-4o configured must not be affected (setting persists)

- [x] t107 Fix persistent ShellCheck violations in .agents/scripts/ and bin/ @superdav42 #quality ~1h ref=GH#540 pr:#544 completed:2026-03-19

- [x] t108 Add tiered model pricing display to Settings UI @superdav42 #ui ~3h ref=GH#541 pr:#543 completed:2026-03-19
  - Show pricing hints next to each model (e.g. GPT-4.1-nano — $0.10/M input, best value)
  - Group models by provider and tier (Budget / Standard / Premium)
  - Estimated cost per session based on average token usage

- [x] t090 Rename plugin to "Superdav AI Agent" with slug "sd-ai-agent" for WP.org guidelines @superdav42 #chore ~4h logged:2026-03-15 pr:#194 completed:2026-03-15
  - Rename main file, text domain, namespace, constants, REST namespace, DB tables, options, CSS classes, ability names, CLI command, build assets, config files, CI/docs
  - Add activation hook migration for existing installs (detect old option/table names and rename)

- [x] t091 Shared conversations: multiple admins can view/continue same session @superdav42 #feature ~6h logged:2026-03-16 ref=GH#387 pr:#474 completed:2026-03-16

### Browser Review Findings (2026-03-18)

#### Critical Bugs (P0)

- [x] t092 Fix incomplete plugin rename: 34 JS API paths still use old `/ai-agent/v1/` namespace instead of `/sd-ai-agent/v1/` @superdav42 #bug ~2h ref=GH#508 completed:2026-03-19
  - Affected files: usage-dashboard.js, knowledge-manager.js, automations-manager.js, events-manager.js, custom-tools-manager.js, tool-profiles-manager.js, message-input.js (memory slash commands)
  - Also 384 instances of old text domain `'ai-agent'` instead of `'sd-ai-agent'` across 20+ JS files
  - Root cause: t090 rename was incomplete — store/index.js and some settings files were updated but others were missed
  - Impact: Usage tab, Knowledge tab, Automations, Events, Custom Tools, Tool Profiles, and /remember /forget slash commands are ALL broken

- [x] t093 Fix SSE stream endpoint fatal error: non-static method called statically @superdav42 #bug ~1h ref=GH#509 completed:2026-03-19
  - `RestController.php` line 99: `'permission_callback' => [ __CLASS__, 'check_chat_permission' ]`
  - `check_chat_permission()` is a non-static instance method but registered with `__CLASS__` (static context)
  - The `$instance = new self()` is created on line 151, AFTER the `/stream` route registration on line 93
  - Fix: move `$instance` creation before the `/stream` route, use `[ $instance, 'check_chat_permission' ]`
  - Impact: 100% of chat messages fail with fatal PHP error — no AI responses work at all

- [x] t094 Fix Abilities Explorer crash: Badge component not exported from @wordpress/components @superdav42 #bug ~1h ref=GH#510 completed:2026-03-19
  - `abilities-explorer-app.js` imports `Badge` from `@wordpress/components` but Badge is not in the package's public exports (it exists in src/badge/ but is not re-exported from index.ts)
  - Badge is `undefined` at runtime, causing 2,185+ React errors and the error boundary to trigger
  - Fix: replace Badge with a simple styled span/div, or use a custom Badge component
  - Impact: Abilities Explorer page is completely non-functional

#### High Priority Bugs (P1)

- [x] t095 Fix "Build Your Site" overlay blocking other admin pages on fresh install @superdav42 #bug ~2h ref=GH#511 completed:2026-03-19
  - The site builder overlay renders on ALL admin pages when `isFreshInstall` is true, not just the AI Agent page
  - On Changes and Abilities pages, the overlay covers the actual page content
  - Fix: only render the site builder overlay on the main AI Agent page, or check the current admin page before rendering

- [x] t096 Add proper error handling for chat stream failures @superdav42 #bug ~2h pr:#517 completed:2026-03-18
  - When the stream endpoint returns a fatal PHP error (or any non-200 response), the frontend shows "Thinking..." indefinitely
  - No timeout mechanism exists — users wait forever with no feedback
  - Fix: add a configurable timeout (e.g., 120s), detect non-SSE responses, show error messages to the user
  - Also handle EventSource errors and display meaningful error messages

- [x] t097 Fix Knowledge tab "Failed to load collections" error @superdav42 #bug ~1h ref=GH#519 completed:2026-03-19
  - The Knowledge manager uses old API path `/ai-agent/v1/knowledge` (part of t092 namespace issue)
  - Red error banner appears even though the feature is enabled
  - Will be fixed as part of t092 namespace fix

#### UX Improvements (P2)

- [x] t098 Abilities settings tab: add categories/search/collapsible sections for 40+ tools @superdav42 #ui ~3h pr:#530 completed:2026-03-19
  - The Settings > Abilities tab shows a flat list of 40+ tools with dropdowns (Auto/Confirm/Disabled)
  - Very long page, hard to find specific tools
  - Add: grouping by category, search/filter input, collapsible category sections

- [x] t099 Auto-title sessions from first message content @superdav42 #ui ~2h ref=GH#521 completed:2026-03-19
  - All sessions show as "Untitled" — no auto-naming from conversation content
  - After the first AI response, generate a short title from the conversation topic
  - Could use the AI to generate a 3-5 word title, or extract keywords from the first user message

- [x] t100 Consolidate duplicate save buttons on Permissions and Integrations tabs @superdav42 #ui ~1h pr:#527 completed:2026-03-19
  - Permissions tab has both "Save Permissions" and "Save Settings" buttons
  - Integrations tab has both "Save GA Credentials" and "Save Settings" buttons
  - Confusing for users — consolidate into a single save action or clarify the difference

- [x] t101 Fix Integrations tab "Save GA Credentials" button red text styling @superdav42 #ui ~0.5h pr:#529 completed:2026-03-19
  - The button has red text which looks like a destructive/danger action
  - Should use standard primary/secondary button styling

- [x] t102 Settings tab bar overflow handling for narrow viewports @superdav42 #ui ~1h pr:#528 completed:2026-03-19
  - 18 tabs in the settings page — rightmost tabs (Integrations, Advanced) may be cut off at narrower viewports
  - Add horizontal scroll indicators or a responsive tab layout (dropdown on mobile)

- [x] t103 Frontend floating widget not rendering for logged-in admins @superdav42 #bug ~2h pr:#526 completed:2026-03-19
  - Settings > General has "Show Widget on Frontend" toggle (currently off)
  - Even when the setting exists, the widget was not found in the DOM on the frontend
  - Verify the frontend enqueue hook fires correctly and the widget renders when enabled

### CI / WP Trunk Compatibility

- [x] t104 Fix PHPUnit (WP trunk) fatal: WP_AI_Client_HTTP_Client::sendRequestWithOptions() interface incompatibility @superdav42 #bug ~1h ref=GH#535 pr:#538 completed:2026-03-19
  - WP trunk updated ClientWithOptionsInterface to use non-namespaced Psr\Http\Message\RequestInterface
  - Plugin compat layer still uses vendored WordPress\AiClientDependencies\Psr\Http\Message\RequestInterface
  - Fix: align WP_AI_Client_HTTP_Client::sendRequestWithOptions() signature with current WP trunk interface

- [x] t105 Fix Playwright E2E (WP trunk) CI: invalid JSON in wp-env override script @superdav42 #bug ~0.5h ref=GH#536 pr:#537 completed:2026-03-19
  - CI script passes {core:WordPress/WordPress#master} (unquoted keys) to JSON.parse — not valid JSON
  - Fix: quote the key in the inline Node.js script or write JSON directly without JSON.parse

## New Tasks

- [x] t167 Test debt cleanup: JS snapshot + PHPUnit failures carried over from t162 #testing #bug #auto-dispatch ~4h logged:2026-04-08 pr:#828 completed:2026-04-09
  - Baseline on main post-#822: npm run test:js is 214 pass / 31 fail across 5 suites (MessageInput, ChatPanel, OnboardingWizard, ProviderSelector, ContextIndicator). vendor/bin/phpunit is 31 errors / 20 failures, all in AgentLoopTest and CredentialResolverTest. Red CI is masking real regressions — #815 shipped 3 runtime-fatal bugs despite PHPUnit passing because the failing tests hid the assertions that would have caught them.
  - Classify each failure: snapshot-stale (regen with diff review), assertion regression (fix component), or asserting dead behaviour (delete). For PHP, AgentLoopTest fixtures drifted after the WP 7.0 Abilities API shape changed — model fixes on AgentLoopClientToolsTest which is green. CredentialResolverTest failures are pinned to #805's openai_compat cleanup — absorb #805 into this task or skip with markTestSkipped. Brief: todo/tasks/t167-brief.md.

- [x] t168 Playwright E2E spec for client-side abilities (navigate-to + insert-block + snapshotDescriptors round-trip) #testing ~3h For #806 logged:2026-04-08 blocked-by:t167 pr:#826 completed:2026-04-09
  - The entire #806 chain shipped, broke at runtime 3 times, and CI never caught any of it because there's no browser-pipeline spec — PHPUnit synthetically injects client_abilities into AgentLoop options, bypassing the whole registration pipeline. Port /tmp/t165-smoke.mjs (used to diagnose #821 and #822) into tests/e2e/client-abilities.spec.js. 7 test cases: category registered, abilities in getAbilities(), executeAbility navigate-to actually navigates, executeAbility insert-block inserts on editor screen, insert-block no-ops on non-editor, snapshotDescriptors returns expected shape, zero relevant console errors.
  - Blocked by t167 so this new spec doesn't land into a red baseline. Verify by temporarily reverting a t166 fix — spec must go red. Brief: todo/tasks/t168-brief.md.

- [x] t169 Investigate @wordpress/core-abilities not populating wp.data store on WP 7.0-RC2 #investigation #auto-dispatch ~2h For #806 logged:2026-04-08 pr:#827 completed:2026-04-09
  - Browser observation during #822 smoke test: `wp.abilities.getAbilities()` returns 62 abilities (correct), but `wp.data.select('core/abilities').getAbilities()` returns 0. The WP 7.0 dev note documents the @wordpress/data store mirror as the React-integration path; it should be populated automatically by @wordpress/core-abilities on every admin page. t166 workaround reads via wp.abilities directly; this task figures out why the wp.data path is empty.
  - Hypotheses: (1) @wordpress/core-abilities not actually enqueued; (2) REST fetch failing silently; (3) store-identity confusion; (4) RC2 regression; (5) our plugin registering before store ready. Deliverable: root-cause statement with browser-probe evidence, then one of — code fix, upstream bug report, or documented "no action needed" with registry.js comment update. Non-blocking; our feature works via workaround. Brief: todo/tasks/t169-brief.md.

- [x] t162 Fix systemic CI failures: PHPStan, PHPUnit, Playwright E2E #bug #auto-dispatch ~4h ref:GH#803 logged:2026-04-07 pr:#820 pr:#820 completed:2026-04-08
  - PHPStan: ~40 errors from WP 7.0 Abilities API stubs not matching actual API; PHPUnit: 7 errors + 20 failures in AgentLoopTest (WP_Error returned where array expected + WP_Ability category fixture); Playwright: 6 shards failing, likely /stream → /chat rename in #802
  - PHPStan fixed in PR #820 (stubs + code fixes, verified [OK] No errors locally). PHPUnit and Playwright still need fixing.

- [x] t163 Seamless PHP+JS abilities — foundation slice (JsAbilityCatalog, client registry, entry wiring) #feature #interactive ~3h For #806 logged:2026-04-08 pr:#815 completed:2026-04-08
  - Bundled into t164's PR #815 by a parallel pulse worker that implemented #806 whole, ignoring the split t163/t164 briefs committed to main. The split-PR attempt (#816) was closed as a dup with merge conflicts. Foundation files (JsAbilityCatalog.php, src/abilities/{registry,navigation,editor,index}.js, entry-point wiring, script-module enqueue) are all on main.

- [x] t164 Seamless PHP+JS abilities — AgentLoop pause/resume + /chat/tool-result + sessionsSlice round-trip #feature ~5h For #806 blocked-by:t163 logged:2026-04-08 pr:#815 completed:2026-04-08
  - PR 2 of 2 splitting #806. Teaches AgentLoop to pause on sd-ai-agent-js/* tool calls, return pending_client_tool_calls, and resume via a new /chat/tool-result REST route. Adds sessionsSlice round-trip, tool-call-details "Ran in browser" badge, abilities-explorer client badge, and PHPUnit coverage.
  - Brief: todo/tasks/t164-brief.md. Depends on t163 being merged first.

- [x] t141 Add E2E tests for UnifiedAdminMenu (PR #665) @superdav42 #testing #auto-dispatch ~2h ref=GH#680 pr:#683 completed:2026-03-29
  - UnifiedAdminMenu (hash-based React SPA) shipped with no E2E coverage — regressions break all admin navigation
  - Create tests/e2e/unified-admin-menu.spec.js with ≥5 test cases: navigation, routing, active state, access control

- [x] t142 Add E2E tests for model benchmark page (PR #663) @superdav42 #testing #auto-dispatch ~2h ref=GH#681 pr:#682 completed:2026-03-29
  - Benchmark page (React SPA with REST API) shipped with no E2E coverage
  - Create tests/e2e/benchmark-page.spec.js with ≥4 test cases: page render, form inputs, run list

- [x] t143 Fix wp.org plugin review blockers: SSL, uninstall, i18n, permissions #devops #auto-dispatch ~4h ref:GH#724 logged:2026-04-03 pr:#725 completed:2026-04-03
  - Remove sslverify=>false (8 locations), create uninstall.php, add load_plugin_textdomain + wp_set_script_translations
  - Move auth into permission_callback for webhook/resale endpoints, harden CLI exec, wrap error_log in WP_DEBUG
  - Fix blueprint slugs, add MODELS.md to .distignore, update PHPCS min WP version to 6.9

## In Progress

- [x] t144 WP 7.0 cleanup: fix settings tab overflow, flatten tab hierarchy, remove custom provider management @superdav42 #refactor ~9h logged:2026-04-03 started:2026-04-03 ref:GH#727 assignee:superdav42 pr:#738 completed:2026-04-04
  - Settings tab bar has CSS selector mismatch causing 9 of 18 tabs to be hidden off-screen
  - Two-level tab nesting creates duplicate tab names (General, Providers, Advanced at both levels)
  - Custom provider key management duplicates WP 7.0 Connectors API — remove and delegate to core
  - Direct HTTP provider paths (send_prompt_openai/anthropic/google/direct) bypass WP SDK — consolidate to wp_ai_client_prompt()
  - Tasks: [todo/tasks/tasks-wp70-cleanup.md](todo/tasks/tasks-wp70-cleanup.md)

## Ready

### Pre-Release Codebase Cleanup

- [x] t145 Split RestController.php (6,416 lines) into domain controllers #refactor ~8h logged:2026-04-03 ref:GH#729 #auto-dispatch pr:#746 completed:2026-04-04
  - 73 routes and 101 handlers in one file — split into SessionController, SettingsController, MemoryController, AutomationController, KnowledgeController, ToolController, ProviderController, ExportController

- [x] t146 Delete dead code — orphaned admin pages, OpenAIProxy, legacy E2E scripts #refactor ~2h logged:2026-04-03 ref:GH#730 #auto-dispatch pr:#738 completed:2026-04-04
  - AdminPage.php, ChangesAdminPage.php, AbilitiesExplorerAdminPage.php (never registered), OpenAIProxy.php (452 lines, only referenced by own test), 4 legacy E2E scripts (1,840 lines), placeholder dirs, Settings::register() dead method

- [x] t147 Remove redundant webpack entry points (changes-page, settings-page, abilities-explorer standalone) #refactor ~1h logged:2026-04-03 ref:GH#731 #auto-dispatch blocked-by:t146 pr:#738 completed:2026-04-04
  - These produce build artifacts that are never loaded — their PHP registration pages are dead code

- [x] t148 Standardize CSS class prefix to sd-ai-agent- across all components #refactor ~4h logged:2026-04-03 ref:GH#732 #auto-dispatch pr:#739 completed:2026-04-04
  - Three conventions coexist: ai-agent-, sd-ai-agent-, sd-ai- — namespace collision risk

- [x] t149 Split Redux store (3,095 lines) into domain slices #refactor ~4h logged:2026-04-03 ref:GH#733 #auto-dispatch pr:#742 completed:2026-04-04
  - Single store/index.js handles sessions, messages, streaming, settings, memory, skills, agents, templates, TTS, site builder

- [x] t150 Add JS unit tests for critical components (chat-panel, message-input, onboarding-wizard) #testing ~8h logged:2026-04-03 ref:GH#734 #auto-dispatch pr:#743 completed:2026-04-04
  - 29 of 32 components have zero unit tests — cover the 5 most critical

- [x] t151 Remove dead CSS rules and consolidate shared component styles #refactor ~2h logged:2026-04-03 ref:GH#735 #auto-dispatch blocked-by:t148 pr:#765 completed:2026-04-04
  - Dead selectors for old Tools page, shared ChatPanel/MessageList styles duplicated across entry points

- [x] t152 Fix AGENTS.md to document actual conventions and WP 7.0 requirement #docs ~30m logged:2026-04-03 ref:GH#736 #auto-dispatch pr:#738 completed:2026-04-04
  - Says camelCase but codebase is 95% snake_case, references removed compat layer, stale class mapping table

### UI Design Polish — WP Admin Aesthetic (P1)

Goal: clean, minimal design that matches wp-admin conventions. Replace custom dark sidebar nav, reduce visual noise, align spacing/typography with core wp-admin patterns.

- [x] t153 Redesign unified admin nav sidebar to match wp-admin aesthetic #ui ~3h logged:2026-04-03 ref:GH#748 #auto-dispatch pr:#758 completed:2026-04-04
  - Current: custom dark (#1e1e1e) sidebar with blue active state — looks like a separate app, not a WP admin page
  - Replace with wp-admin-style left nav: white/light background, standard WP submenu link styles, no custom dark theme
  - Use `--wp-admin-theme-color` for active state only; remove hardcoded #1e1e1e, #333, #a7aaad
  - Consider replacing custom nav entirely with WP's native submenu structure (registered via add_submenu_page)

- [x] t154 Simplify chat panel header — reduce clutter, align with wp-admin toolbar style #ui ~2h logged:2026-04-03 ref:GH#749 #auto-dispatch pr:#759 completed:2026-04-04
  - Current: provider selector + agent selector + budget badge + TTS button + debug badge + YOLO badge all in one row
  - Move secondary controls (TTS, debug badge) to a settings popover or collapse into a single "options" button
  - Provider/agent selectors: use compact inline selects without labels (labels add height, break the toolbar feel)
  - Header background #f6f7f7 with border-bottom is fine — keep; remove excess padding

- [x] t155 Redesign floating widget panel — lighter titlebar, less visual weight #ui ~2h logged:2026-04-03 ref:GH#750 pr:#757 completed:2026-04-04
  - Current: solid `--wp-admin-theme-color` titlebar (blue) is heavy and inconsistent with wp-admin modals
  - Replace with white/light titlebar, thin border-bottom, title in dark text — matches WP modal style
  - FAB button is fine; keep the circular shape and theme color
  - Session tabs: current pill style is ok but font-size 11px is too small — bump to 12px

- [x] t156 Settings page: reduce tab count, improve section spacing and form layout #ui ~3h logged:2026-04-03 ref:GH#751 #auto-dispatch pr:#760 completed:2026-04-04
  - 18 tabs is too many — group into 5-6 top-level tabs with sub-sections inside each panel
  - Section padding (20px 24px) and gap (16px) are good; keep
  - Form fields: use WP's `<BaseControl>` with proper label/help text instead of raw inputs
  - Skill cards and memory cards: border-radius 4px is fine; consider removing the card border on hover-only items

- [x] t157 Session sidebar: tighten spacing, improve active/hover states #ui ~1h logged:2026-04-03 ref:GH#752 #auto-dispatch pr:#761 completed:2026-04-04
  - Active item uses `border-left: 3px solid` + background — the left border is a good WP pattern, keep it
  - Session item padding (10px 36px 10px 12px) is generous — reduce to 8px 32px 8px 10px
  - Folder tabs (pill shape, #e0e0e0 background) look out of place — replace with plain text tabs matching filter tabs
  - Context menu: already clean; no changes needed

- [x] t158 Message bubbles and input area: align with wp-admin form conventions #ui ~2h logged:2026-04-03 ref:GH#753 #auto-dispatch pr:#762 completed:2026-04-04
  - User bubble (blue, right-aligned) is fine — standard chat convention
  - Assistant bubble (#f0f0f1 background) is fine — keep
  - Input area: background #f6f7f7 with border-top is correct; the textarea border (#8c8f94) is slightly dark — use #c3c4c7 to match WP inputs
  - Send button (circular, absolute positioned) is non-standard for wp-admin — consider a standard rectangular "Send" button or keep circle but ensure it doesn't overlap text
  - Token counter below input: good addition, ensure it uses wp-admin muted text color (#646970)

- [x] t159 Welcome screen / empty state: simplify and reduce visual weight #ui ~1h logged:2026-04-03 ref:GH#754 #auto-dispatch pr:#763 completed:2026-04-04
  - Current: 3-column card grid with border + hover shadow — looks like a marketing page
  - Replace with a simple centered text + 2-3 plain suggestion links (no cards, no borders)
  - Greeting text (18px, font-weight 600) is too large for an admin tool — reduce to 15px, normal weight
  - Hint text (#a7aaad) is too light — use #646970

- [x] t160 Benchmark and changes pages: apply consistent wp-admin card/table layout #ui ~2h logged:2026-04-03 ref:GH#755 #auto-dispatch pr:#764 completed:2026-04-04
  - Benchmark page: use WP's `<Card>` component for run list items instead of custom styling
  - Changes page: ensure it uses standard WP admin table markup (widefat, striped classes) where applicable
  - Both pages: max-width 1200px container is fine; ensure headings use wp-admin h2 style (not custom font-size)

## Backlog

- [ ] t226 Block theme generation in onboarding (Automattic wp-site-creator inspired) #enhancement #parent-task #plan → [todo/PLANS.md#onboarding-theme-builder] ~24h logged:2026-05-13
  - 4 phases: site-spec skill (t226a), block-themes skill expansion (t226b), theme-builder onboarding branch (t226c, 2 new abilities + REST + UI), design-system aesthetics skill (t226d)
  - Brief: todo/tasks/t226-brief.md
  - Source: github.com/Automattic/wordpress-agent-skills/tree/trunk/claude-code/wp-site-creator (architecture only — not the Claude-Code packaging or Studio CLI deps)
  - Closes the PLANS.md "AI site generation from prompt" P0 gap on the visual-container side (t060-t062 cover pages)
  - Renumbered from t170 in PR; t170 was already used (PR #848, completed 2026-04-09)

- [x] t226a Site-specification skill — markdown + memory category #enhancement #auto-dispatch ~3h For #t226 logged:2026-05-13 pr:#1371 completed:2026-05-13
  - NEW: includes/Models/skills/site-specification.md — site-type inference patterns, worked examples, presentation format
  - EDIT: includes/Models/Skill.php — register in BUILTIN_META (enabled by default)
  - EDIT: includes/Enums/MemoryCategory.php — add SiteBrief = 'site_brief'
  - EDIT: includes/Models/Memory.php — add 'site_brief' to CATEGORIES
  - EDIT: tests/SdAiAgent/Models/MemoryTest.php — assert new category accepted
  - EDIT: src/settings-page/memory-manager.js — add Site Brief category option
  - Verify: `composer phpcs && npm run lint:js && npm run test:php` (MemoryTest)

- [ ] t226b Block-themes skill expansion — theme.json presets, animation classes, editor-visibility CSS #enhancement #auto-dispatch ~4h For #t226 logged:2026-05-13
  - EDIT: includes/Models/skills/block-themes.md — expand from 150 → ~400 lines (target 350-450)
  - Add: Absolute Rules (no html-blocks, no decorative comments, no stock images), theme.json color/typography presets, Landing Page Composition, Cover Block Pitfalls, Card layouts, Visual Richness Without Images, Animation & Motion (className pattern, entrance animations, staggered children, hover transitions, ambient motion), Scroll-triggered reveals (IntersectionObserver), prefers-reduced-motion (required), Editor Visibility / .editor-styles-wrapper override (required), functions.php essentials
  - Source: Automattic wordpress-block-theming reference (29KB → ~280 lines added) at github.com/Automattic/wordpress-agent-skills/blob/trunk/claude-code/wp-site-creator/references/wordpress-block-theming.md
  - Verify: `wc -l includes/Models/skills/block-themes.md` is 350-450, and `grep -E "Editor Visibility|editor-styles-wrapper|prefers-reduced-motion" includes/Models/skills/block-themes.md` returns matches

- [ ] t232 Ability discovery investigation: why agent misses registered abilities #investigation #parent #plan → [todo/PLANS.md#ability-discovery-investigation] ~5h logged:2026-04-26
- [x] t234 Audit ability injection pipeline and tool catalog (Phase 1) #investigation #auto-dispatch ~3h For #t232 logged:2026-04-26 pr:#1204 completed:2026-04-27
  - Trace how abilities reach the model: ToolCapabilities.php → AgentLoop.php → wp_ai_client_prompt() injection
  - Check for count limits, filters, or namespace handling that could drop abilities
  - Check site-builder system prompt for tool enumeration gaps
  - Verify: document findings with specific file:line references
- [ ] t235 Fix ability discoverability — descriptions, system prompt, namespace alignment (Phase 2) #enhancement #auto-dispatch ~2h For #t232 blocked-by:t234 logged:2026-04-26
  - Apply fixes identified in t234: improve descriptions, align namespaces (ai-agent/ vs sd-ai-agent/), update system prompt
  - Verify: `composer phpstan && composer phpcs`

- [-] t233 Site builder ability improvements #parent #plan → [todo/PLANS.md#site-builder-ability-improvements] ~9h logged:2026-04-26 obsolete:2026-05-13 (Site Builder mode removed in beads sd-ai-dh0)
- [ ] t236 Stock image fallback chain: retry all free sources on download failure (Phase 1) #bugfix #auto-dispatch ~1.5h For #t233 logged:2026-04-26
  - EDIT: includes/Abilities/ImageSources/ImageSourceFactory.php — in import_image(), on download failure iterate to next free source before AI generate fallback
  - EDIT: includes/Abilities/ImageAbilities/StockImageAbility.php — surface source tried + fallback info in error response
  - Verify: `composer phpstan && composer phpcs`
- [x] t237 Add page_template param to create-post and update-post (Phase 2) #enhancement #auto-dispatch ~1h For #t233 logged:2026-04-26 pr:#1344 completed:2026-05-13
  - EDIT: includes/Abilities/PostAbilities.php — add page_template to input_schema + post_data array in both handle_create_post and handle_update_post
  - Verify: `composer phpstan && composer phpcs`
- [x] t238 Add set-featured-image standalone ability (Phase 3) #enhancement #auto-dispatch ~1h For #t233 logged:2026-04-26 pr:#1231 completed:2026-04-27
  - EDIT: includes/Abilities/PostAbilities.php — register ai-agent/set-featured-image with {post_id, featured_image_id} schema + handle_set_featured_image handler
  - Verify: `composer phpstan && composer phpcs`
- [x] t239 Add batch-create-posts ability (Phase 4) #feature #auto-dispatch ~2.5h For #t233 logged:2026-04-26 pr:#1230 completed:2026-04-27
  - EDIT: includes/Abilities/PostAbilities.php — register ai-agent/batch-create-posts, schema: {posts: [{title, content, post_type, status, featured_image_id, page_template, ...}]}, returns [{post_id, permalink, title, status}]
  - Verify: `composer phpstan && composer phpcs`
- [x] t240 Add create-contact-form ability (Phase 5) #feature #auto-dispatch ~3h For #t233 logged:2026-04-26 pr:#1232 completed:2026-04-27
  - EDIT: includes/Abilities/ContentAbilities.php or NEW: includes/Abilities/FormsAbilities.php
  - Fallback chain: Contact Form 7 (WPCF7_ContactForm::create() + shortcode) → raw HTML block page
  - Verify: `composer phpstan && composer phpcs`

- [x] t226 WP 6.9 compatibility: polyfill WP 7.0 AI APIs + Connectors page #parent #feature #plan → [todo/PLANS.md#wp-69-compat] ~40h logged:2026-04-21 pr:#1133,#1136,#1137 completed:2026-04-21

- [x] t227 Add wordpress/php-ai-client + wordpress/abilities-api Composer packages (Phase 1) #feature ~4h For #t226 logged:2026-04-21 pr:#1137 completed:2026-04-21
  - Add wordpress/php-ai-client and wordpress/abilities-api to composer.json require
  - Jetpack Autoloader resolves version conflicts — core's copy wins on WP 7.0, bundled copy loads on 6.9
  - Test: composer install succeeds, WP_Ability and WordPress\AiClient\AiClient classes are available

- [x] t228 Create WP AI Client bridge polyfill for WP 6.9 (Phase 2) #feature ~8h For #t226 blocked-by:t227 logged:2026-04-21 pr:#1133 completed:2026-04-21
  - NEW: includes/Compat/WP7Polyfill.php — conditional loader (function_exists/class_exists guards)
  - Copy from WP 7.0-RC2: wp_ai_client_prompt(), wp_supports_ai(), WP_AI_Client_Prompt_Builder (472 lines), WP_AI_Client_Ability_Function_Resolver (232 lines), 4 adapter classes (HTTP client, discovery, cache, event dispatcher)
  - EDIT: sd-ai-agent.php — load polyfill before DI container bootstrap
  - All definitions guarded — WP 7.0 core takes precedence when present

- [x] t229 Create Connectors API polyfill for WP 6.9 (Phase 3) #feature ~8h For #t226 blocked-by:t227 logged:2026-04-21 pr:#1137 completed:2026-04-21
  - NEW: includes/Compat/ConnectorsPolyfill.php — conditional definitions
  - Copy from WP 7.0-RC2: WP_Connector_Registry (388 lines), wp_get_connectors(), wp_is_connector_registered(), wp_get_connector(), _wp_connectors_init(), _wp_connectors_register_default_ai_providers(), _wp_connectors_pass_default_keys_to_ai_client(), _wp_register_default_connector_settings(), credential helpers
  - Same option names as WP 7.0 (connectors_ai_anthropic_api_key, etc.) — zero migration on upgrade

- [x] t230 Build Connectors admin page with install/activate/API key UI (Phase 4) #feature ~16h For #t226 blocked-by:t228,t229 logged:2026-04-21 pr:#1136 completed:2026-04-21
  - NEW: src/connectors-page/ — React page using @wordpress/components + @wordpress/data
  - Lists providers (Anthropic, OpenAI, Google) with Install/Activate/Connected status
  - Installs plugins via WP Plugins REST API (POST /wp/v2/plugins {slug, status: active}) — same as WP 7.0
  - API key entry, validation via registry->isProviderConfigured(), masked display
  - Stores keys in connectors_ai_{provider}_api_key options (same as WP 7.0)
  - NEW: includes/Admin/ConnectorsPage.php — admin menu registration, script enqueue
  - On WP 7.0: detect core Connectors page exists, show redirect link instead

- [x] t231 Lower version requirement to 6.9 + add WP 7.0 detection guards + test both versions (Phase 5) #feature ~4h For #t226 blocked-by:t230 logged:2026-04-21 pr:#1137 completed:2026-04-21
  - EDIT: sd-ai-agent.php — Requires at least: 6.9
  - EDIT: readme.txt — Requires at least: 6.9
  - Add version detection: if WP >= 7.0, skip all polyfills, Connectors page shows link to core page
  - Test on WP 6.9: full flow (install provider plugin, enter API key, chat works)
  - Test on WP 7.0-RC2: polyfills skipped, core APIs used, credentials migrate seamlessly

- [x] t221 Onboarding v2: Gate + AI-driven discovery #parent #feature → [todo/PLANS.md#onboarding-v2-gate--ai-driven-discovery] ~8h logged:2026-04-18 pr:#1097 completed:2026-04-19

- [x] t222 Connector gate component + remove wizard (Phase 1) #feature #auto-dispatch ~2h For #t221 logged:2026-04-18 pr:#1103 completed:2026-04-19
  - NEW: src/components/onboarding-gate.js — single-screen "Connect an AI Provider" gate with provider polling (3-5s interval). Auto-transitions to chat when provider appears. No skip, no next, no dots.
  - EDIT: src/admin-page/index.js — replace OnboardingWizard import/usage with OnboardingGate. Remove OnboardingInterview import. On gate clear, create bootstrap session and show chat.
  - DELETE content from: src/components/onboarding-wizard.js (entire multi-step wizard)
  - DELETE content from: src/components/onboarding-interview.js (static form interview)
  - Verify: `npm run lint:js && npm run build`

- [x] t223 Bootstrap system prompt + auto-discovery session (Phase 2) #feature #auto-dispatch ~4h For #t221 blocked-by:t222 logged:2026-04-18 pr:#1098 completed:2026-04-19
  - NEW: includes/Core/BootstrapPrompt.php — generates onboarding system prompt. Instructs AI to explore site with abilities (read posts, pages, plugins, theme, WooCommerce), infer style/tone/audience, queue RAG indexing, store memories, then present findings + starter prompts. Model on includes/Core/SystemInstructionBuilder.php
  - EDIT: includes/Core/AgentLoop.php — accept bootstrap_prompt param when creating first session, prepend to system instructions for that session only
  - EDIT: includes/Core/OnboardingManager.php — simplify to track onboarding_complete. Add REST endpoint to create bootstrap session. Remove interview endpoints.
  - EDIT: src/store/ — add isBootstrapSession flag so UI doesn't show empty-state on first run
  - Verify: `composer phpstan && composer phpcs && npm run lint:js && npm run build`

- [ ] t224 Auto-enable WooCommerce abilities + cleanup dead onboarding code (Phase 3) #enhancement #auto-dispatch ~2h For #t221 blocked-by:t223 logged:2026-04-18
  - EDIT: includes/Core/Settings.php — on first load with provider detected, auto-enable WooCommerce abilities if WooCommerce active
  - DELETE: includes/Core/OnboardingInterview.php — entire class replaced by AI conversation
  - EDIT: includes/Bootstrap/OnboardingHandler.php — remove interview REST route registration
  - DELETE: src/components/__tests__/OnboardingWizard.test.js — replace with onboarding-gate tests
  - DELETE: tests/SdAiAgent/Core/OnboardingInterviewTest.php
  - Verify: `composer phpstan && composer phpcs && npm run lint:js && npm run build`

- [x] t215 Adaptive skill system #parent #feature → [todo/PLANS.md#adaptive-skill-system] ~30h logged:2026-04-18 pr:#1086 completed:2026-04-19

- [ ] t216 Skill usage tracking table + telemetry (Phase 1) #feature #auto-dispatch ~4h For #t215 logged:2026-04-18
  - NEW: includes/Models/DTO/SkillUsageRow.php — readonly DTO: id, skill_id, session_id, trigger_type, injected_tokens, outcome, model_id, created_at. Model on includes/Models/DTO/SkillRow.php
  - NEW: includes/Models/SkillUsageRepository.php — create(), get_by_skill(), get_stats(). Model on includes/Models/Memory.php (static CRUD pattern)
  - EDIT: includes/Core/Database.php — add CREATE TABLE for skill_usage, bump DB_VERSION
  - EDIT: includes/Core/SkillAutoInjector.php — record auto-injection events via SkillUsageRepository::create()
  - EDIT: includes/Abilities/SkillAbilities.php — record manual skill-load calls
  - EDIT: includes/Core/AgentLoop.php — after loop completes, evaluate outcome heuristic (no exit_reason error + skill domain match = helpful)
  - Verify: `composer phpstan && composer phpcs`

- [x] t217 Model-aware tiered skill injection (Phase 2) #enhancement #auto-dispatch ~4h For #t215 blocked-by:t216 logged:2026-04-18 ref=GH#1358 pr:#1099 completed:2026-05-13
  - Implemented: SystemInstructionBuilder now routes strong models to the lean skill index plus targeted skill-load hints and weak models to capped full skill auto-injection.
  - Implemented: SkillAutoInjector caps auto-injection at one skill and exposes get_index_description() for the strong-model hint path.
  - Implemented: SkillAbilities records voluntary skill-load calls through ModelHealthTracker::record_skill_load() so tier decisions can use model skill-loading telemetry.
  - Verify: `composer phpstan && composer phpcs`

- [x] t218 Skill versioning schema + remote update checker (Phase 3a) #feature #auto-dispatch ~4h For #t215 logged:2026-04-18 ref=GH#1359 completed:2026-05-13
  - EDIT: includes/Core/Database.php — ALTER TABLE skills ADD version, content_hash, source_url, user_modified columns, bump DB_VERSION
  - EDIT: includes/Models/Skill.php — set user_modified=1 on update() when skill is_builtin, add check_for_updates() and apply_update() methods
  - EDIT: includes/Models/DTO/SkillRow.php — add version, content_hash, source_url, user_modified properties
  - EDIT: includes/Core/Settings.php — add skill_auto_update and skill_manifest_url settings
  - Verify: `composer phpstan && composer phpcs && composer phpunit -- --filter SkillTest`

- [ ] t219 WP-Cron skill update checker + conditional HTTP (Phase 3b) #feature #auto-dispatch ~4h For #t215 blocked-by:t218 logged:2026-04-18
  - NEW: includes/Core/SkillUpdateChecker.php — WP-Cron callback: fetch manifest JSON from skill_manifest_url, compare content_hash per slug, update is_builtin=1 AND user_modified=0 skills. Use wp_remote_get with If-None-Match/If-Modified-Since headers. Model on Knowledge.php hash-comparison pattern (line 44)
  - EDIT: includes/Models/Skill.php::reset_builtin() — pull from remote manifest URL with fallback to bundled .md file
  - EDIT: sd-ai-agent.php or LifecycleHandler — register wp_schedule_event for daily skill check
  - Verify: `composer phpstan && composer phpcs`

- [x] t220 Skill manager UI: usage stats + update badges + auto-update toggle (Phase 4) #ui #auto-dispatch ~6h For #t215 blocked-by:t216,t219 logged:2026-04-18 pr:#1100 completed:2026-04-19
  - EDIT: src/settings-page/skill-manager.js — add "Usage Stats" column (load count, helpful %, last used), "Update available" badge, "Modified" badge with "Reset to upstream" button, per-skill and global auto-update toggle
  - EDIT: includes/REST/SkillController.php — add GET /skills/usage-stats endpoint returning aggregated data from SkillUsageRepository::get_stats()
  - EDIT: src/store/slices/skillsSlice.js — add fetchSkillUsageStats thunk and state
  - Verify: `npm run lint:js && npm run build`

- [ ] t199 Resumable background jobs & multi-session chat #parent #feature → [todo/PLANS.md#resumable-background-jobs] ~10d ref:GH#1027 logged:2026-04-17

- [x] t200 Active jobs DB table + repository class (Phase 1a) #feature #auto-dispatch ~3h For #t199 ref:GH#1028 logged:2026-04-17 pr:#1106 completed:2026-04-20
  - NEW: includes/Models/DTO/ActiveJobRow.php — readonly DTO: id, session_id, job_id (UUID), user_id, status (processing|awaiting_confirmation|complete|error), pending_tools (JSON), tool_calls (JSON), created_at, updated_at. Model on includes/Models/DTO/SessionRow.php
  - NEW: includes/Models/ActiveJobRepository.php — create(), get_by_job_id(), get_by_session_id(), get_active_for_user(), update_status(), delete(). Model on includes/Models/Memory.php (static CRUD pattern)
  - EDIT: includes/Core/Database.php:255 area — add CREATE TABLE for `{$wpdb->prefix}sd_ai_agent_active_jobs` with indexes on session_id, job_id, user_id+status. Bump DB_VERSION constant.
  - Verify: `composer phpstan && composer phpcs && wp plugin activate sd-ai-agent`

- [ ] t201 Persist job lifecycle to DB alongside transients (Phase 1b) #feature #auto-dispatch ~4h For #t199 blocked-by:t200 ref:GH#1029 logged:2026-04-17
  - EDIT: includes/REST/SessionController.php — `handle_run()` (~line 1175): after `set_transient()`, call `ActiveJobRepository::create()` with job_id, session_id, user_id, status=processing
  - EDIT: includes/REST/SessionController.php — `handle_process()` (~line 1244): on status changes (complete/error/awaiting_confirmation), call `ActiveJobRepository::update_status()` alongside `set_transient()`
  - EDIT: includes/REST/SessionController.php — `handle_job_status()` (~line 922): when `get_transient()` returns false, fall back to `ActiveJobRepository::get_by_job_id()`. On complete, delete DB row after delivering result (same as transient delete on line 986)
  - EDIT: includes/REST/SessionController.php — `resume_job()` (~line 1131): update DB row status to processing on confirm/reject resume
  - Verify: `composer phpstan && composer phpcs`; manually test: send message, verify row appears in `wp_sd_ai_agent_active_jobs`, verify it's cleaned up after poll reads completion

- [ ] t202 Active-job reconnection REST endpoint + frontend integration (Phase 1c) #feature #auto-dispatch ~3h For #t199 blocked-by:t201 ref:GH#1030 logged:2026-04-17
  - EDIT: includes/REST/SessionController.php — register `GET /sessions/(?P<id>\d+)/active-job` route. Handler: call `ActiveJobRepository::get_by_session_id()`, return job_id + status + pending_tools + tool_calls (same shape as `/job/{id}` response). Return 404 if no active job.
  - EDIT: includes/REST/SessionController.php — register `GET /sessions/active-jobs` route. Handler: call `ActiveJobRepository::get_active_for_user(get_current_user_id())`, return array of {session_id, job_id, status}.
  - EDIT: src/store/slices/sessionsSlice.js — in `openSession()` thunk: after loading session messages, call `GET /sessions/{id}/active-job`. If active job exists, dispatch `pollJob(jobId, sessionId)` to resume polling.
  - NEW: `restoreActiveJobs()` thunk in sessionsSlice.js — calls `GET /sessions/active-jobs`, starts `pollJob()` for each. Called from FloatingWidget and AdminPageApp on mount.
  - EDIT: src/floating-widget/index.js — call `restoreActiveJobs()` in mount useEffect alongside `fetchSessions()`
  - EDIT: src/admin-page/index.js — call `restoreActiveJobs()` in mount useEffect alongside `fetchSessions()`
  - Verify: `npm run lint:js && npm run build`; manually test: send message, navigate to different WP admin page, come back, verify "Thinking..." resumes

- [ ] t203 Extract jobSlice from sessionsSlice + remove dead SSE state + normalize session IDs #refactor #auto-dispatch ~4h For #t199 ref:GH#1031 logged:2026-04-17
  - NEW: src/store/slices/jobSlice.js — extract from sessionsSlice.js: state keys (currentJobId, liveToolCalls, pendingConfirmation, pendingActionCard, sessionJobs, sendTimestamp), actions (pollJob, streamMessage, confirmToolCall, rejectToolCall, interruptAgent, setCurrentJobId, setLiveToolCalls, setSessionJob, setPendingConfirmation, setPendingActionCard, setSendTimestamp), selectors, reducer cases. Model on src/store/slices/settingsSlice.js (same export pattern)
  - EDIT: src/store/slices/sessionsSlice.js — remove the extracted state/actions/selectors/reducer cases. Remove dead SSE state: streamingText, isStreaming, streamAbortController, streamError, APPEND_STREAMING_TEXT, SET_STREAMING_TEXT, SET_IS_STREAMING, SET_STREAM_ABORT_CONTROLLER, SET_STREAM_ERROR (~80 lines). Normalize session IDs: in SET_SESSIONS reducer, cast `id` to number; in SET_CURRENT_SESSION, cast sessionId. Remove all downstream `parseInt(session.id, 10)` calls.
  - EDIT: src/store/index.js — import jobSlice, add to combined state/actions/selectors/reducer chain
  - EDIT: src/components/message-list.js:607-627 — extract the IIFE tool-calls resolution into a `useActiveToolCalls(sessionId)` hook (can live in jobSlice or a new src/hooks/use-active-tool-calls.js)
  - Verify: `npm run lint:js && npm run build && npm run test:js`

- [x] t204 Session-scoped polling with exponential backoff + visibility throttling (Phase 2) #feature #auto-dispatch ~4h For #t199 blocked-by:t203 ref:GH#1032 logged:2026-04-17 pr:#1045 completed:2026-04-18
  - EDIT: src/store/slices/jobSlice.js — refactor `pollJob(jobId, sessionId)`: remove check against `currentJobId` so multiple sessions can poll independently. Add exponential backoff: start 1s, after 10 polls → 5s, after 30 polls → 10s cap. Reset to 1s when `tool_calls` array length changes (progress detected). Update `sessionJobs[sessionId]` on every poll regardless of which session is active; only update `liveToolCalls`/`pendingConfirmation` when sessionId matches current.
  - NEW: src/utils/visibility-manager.js — `document.addEventListener('visibilitychange')` singleton. When `document.hidden`: slow all active polls to 15s. When visible: immediately poll once per active session, resume normal intervals. Export `onVisibilityChange(callback)` for notification integration.
  - EDIT: src/store/slices/jobSlice.js — integrate visibility manager: `pollJob` reads visibility state to determine interval.
  - Verify: `npm run lint:js && npm run build`; manually test: start job, switch browser tabs, verify Network tab shows slower polls; switch back, verify immediate poll fires

- [x] t205 Browser notifications for permission prompts (Phase 3) #feature #auto-dispatch ~3h For #t199 blocked-by:t204 ref:GH#1033 logged:2026-04-17 pr:#1050 completed:2026-04-18
  - NEW: src/utils/notification-manager.js — `requestPermission()` (call on first tool confirmation or from settings), `notifyConfirmationNeeded(jobId, toolName)` (fires `new Notification()` with `requireInteraction: true`, `tag: job-confirm-${jobId}`), `clearNotification(jobId)`, title flash manager (toggle document.title with "Approval needed" when `document.hidden`, clear on focus)
  - EDIT: src/store/slices/jobSlice.js — in `pollJob()`, when status === 'awaiting_confirmation' and `document.hidden`: call `notifyConfirmationNeeded()`. When confirmation resolved: call `clearNotification()`.
  - EDIT: src/components/session-sidebar.js — SessionItem: when `sessionJobs[session.id]?.status === 'awaiting_confirmation'`, render a pulsing warning dot badge. Model on existing `hasActiveJob` prop (line 74).
  - EDIT: src/floating-widget/session-tabs.js — same badge on compact tabs
  - Verify: `npm run lint:js && npm run build`; manually test: trigger tool confirmation, switch to another browser tab, verify notification fires and title flashes

- [ ] t206 Cross-page navigation survival via sessionStorage (Phase 4) #feature #auto-dispatch ~2h For #t199 blocked-by:t202,t204 ref:GH#1034 logged:2026-04-17
  - EDIT: src/store/slices/jobSlice.js — in `pollJob()`, on start: write `sessionStorage.setItem('sdAiAgent_activeJobs', JSON.stringify({[sessionId]: {jobId, startedAt}}))`. On job complete/error: remove entry. On poll resume: read and restore.
  - EDIT: src/floating-widget/index.js — in mount useEffect: read `sessionStorage` active jobs, call `pollJob()` for each before `restoreActiveJobs()` fires (fast path — sessionStorage is synchronous, REST is async)
  - EDIT: src/admin-page/index.js — same sessionStorage restore on mount
  - Verify: `npm run lint:js && npm run build`; manually test: send message, navigate to Posts page in wp-admin, floating widget should show "Thinking..." with tool progress for the active session

- [ ] t207 Tabbed multi-session chat UI (Phase 5) #feature #auto-dispatch ~6h For #t199 blocked-by:t204 ref:GH#1035 logged:2026-04-17
  - NEW: src/components/chat-tab-bar.js — tab bar component above ChatPanel. Shows `openTabs` array as clickable tabs with session title (truncated), close button, status indicator (spinner for processing, warning dot for awaiting_confirmation, idle for none). `+` button calls `clearCurrentSession()`. Click tab calls `openSession(id)`. Model on src/floating-widget/session-tabs.js (78 lines) but with close buttons and status indicators.
  - EDIT: src/store/slices/sessionsSlice.js — add `openTabs: []` state, `ADD_OPEN_TAB`, `REMOVE_OPEN_TAB`, `SET_OPEN_TABS` reducers. Persist to `localStorage.getItem('sdAiAgent_openTabs')`. In `openSession()`, auto-add to openTabs if not present.
  - EDIT: src/components/ChatPanel.js — render `<ChatTabBar />` above header when `openTabs.length > 0` (or always, if a session is active)
  - NEW: src/components/chat-tab-bar.css — styles: horizontal scroll, active tab highlight, status dot animations, close button on hover
  - Verify: `npm run lint:js && npm run build`; manually test: open 3 sessions, verify tab bar shows all 3, clicking switches, close button works, status indicators update during active jobs

- [ ] t208 Dynamic context windows from provider API response #enhancement #auto-dispatch ~2h For #t199 ref:GH#1036 logged:2026-04-17
  - EDIT: src/store/index.js — remove hardcoded `MODEL_CONTEXT_WINDOWS` object (lines 107-115). Replace `getContextPercentage()` and `isContextWarning()` selectors to read from `state.providers` model metadata instead.
  - EDIT: src/store/slices/providersSlice.js — when `fetchProviders()` response includes model data, store `context_window` per model. Add `getModelContextWindow(state, modelId)` selector with 128000 fallback.
  - EDIT: includes/REST/SettingsController.php — in the providers/models endpoint response, include `context_window` field per model from a static lookup (move the PHP-side equivalent of the hardcoded map into CostCalculator or Settings, where model metadata already lives)
  - Verify: `npm run lint:js && npm run build && composer phpstan`

- [x] t209 Improve block editor content quality #parent #feature → [todo/PLANS.md#improve-block-editor-content-quality] ~20h logged:2026-04-17 pr:#1047 completed:2026-04-17

- [x] t210 Fix `maybe_convert_markdown()` mixed-content bug (Phase 1) #bug ~2h For #t209 logged:2026-04-17 pr:#1047 completed:2026-04-17

- [x] t211 Auto-inject skills into system prompt based on task intent (Phase 2) #feature ~4h For #t209 logged:2026-04-17 pr:#1047 completed:2026-04-17

- [x] t212 Rewrite gutenberg-blocks skill with comprehensive block markup reference (Phase 3) #enhancement ~6h For #t209 logged:2026-04-17 pr:#1047 completed:2026-04-17

- [x] t213 Unhide block abilities + update system prompt content creation guidance (Phase 4) #feature ~4h For #t209 logged:2026-04-17 pr:#1047 completed:2026-04-17

- [x] t214 Add validate-block-content ability for pre-insertion validation (Phase 4b) #feature ~4h For #t209 logged:2026-04-17 pr:#1047 completed:2026-04-17

- [x] t188 Replace provider dropdown with connectors link when no providers defined #enhancement #auto-dispatch ~1h logged:2026-04-16 pr:#981 completed:2026-04-16

### Post-DI Code Quality & Structure Improvements

Full plan: [todo/PLANS.md#post-di-code-quality](todo/PLANS.md#2026-04-16-post-di-code-quality--structure-improvements)

- [x] t189 Split Database God Class (1,490 lines) into domain repositories #refactor #auto-dispatch ~6h logged:2026-04-16 pr:#1002 completed:2026-04-17
  - Database handles 7 unrelated domains: schema, sessions, usage, generated plugins, modified files, shared sessions, paused state
  - Split into: SchemaManager, SessionRepository, UsageRepository, GeneratedPluginRepository, ModifiedFileRepository, SharedSessionRepository
  - EDIT: includes/Core/Database.php — extract methods into new repository classes
  - NEW: includes/Infrastructure/Database/SchemaManager.php — install(), migrate, table creation
  - NEW: includes/Models/SessionRepository.php — create/get/update/delete/list sessions + paused state
  - NEW: includes/Models/UsageRepository.php — log_usage(), get_usage_summary()
  - NEW: includes/PluginBuilder/GeneratedPluginRepository.php — generated plugin CRUD
  - NEW: includes/Models/ModifiedFileRepository.php — record/get modified files
  - NEW: includes/Models/SharedSessionRepository.php — share/unshare/list shared sessions
  - Update all callers (REST controllers, AgentLoop, Bootstrap handlers)
  - Verify: `composer phpstan && composer phpcs`

- [x] t190 Remove dead register() methods from ~35 ability classes #refactor #auto-dispatch ~1h logged:2026-04-16 pr:#1005 completed:2026-04-17
  - DI AbilitiesHandler calls register_abilities() directly — the register() methods that add_action('wp_abilities_api_init') are dead code
  - Remove register() from: MemoryAbilities, FeedbackAbilities, SkillAbilities, KnowledgeAbilities, and ~30 more
  - Verify: `git grep '::register()' includes/Abilities/` returns zero hits, `composer phpstan`

- [x] t191 Introduce typed DTOs for database rows to eliminate phpstan mixed-type ignores #refactor #auto-dispatch ~4h logged:2026-04-16 blocked-by:t189 pr:#1009 completed:2026-04-17
  - wpdb::get_row() returns stdClass — every caller does $row->field with @phpstan-ignore-next-line
  - NEW: includes/Models/DTO/SessionRow.php — readonly class with from_row(object) factory
  - NEW: includes/Models/DTO/UsageRow.php, MemoryRow.php, AutomationRow.php, etc.
  - Update repository methods to return typed DTOs instead of object|null
  - Remove corresponding phpstan.neon ignoreErrors (target: 30-50% reduction)
  - Verify: `composer phpstan` with reduced ignoreErrors

- [x] t192 Convert Settings to injectable DI service #refactor #auto-dispatch ~3h logged:2026-04-16 pr:#1017 completed:2026-04-17
  - Settings is entirely static — hard to test, hidden global state
  - Add instance methods mirroring static ones, register as singleton in Plugin::configure()
  - Inject into AgentLoop (already accepts ?Settings), REST controllers, handlers
  - Keep static methods as deprecated wrappers during transition
  - Verify: `composer phpstan && composer phpcs && npm run test:php`

- [x] t193 Extract AgentLoop subresponsibilities into focused classes #refactor #auto-dispatch ~8h logged:2026-04-16 blocked-by:t192 pr:#1008 completed:2026-04-17
  - AgentLoop is ~1,500 lines handling 8+ concerns: orchestration, prompts, permissions, spin detection, client abilities, history, tokens, interrupts
  - NEW: includes/Core/SystemInstructionBuilder.php — build_system_instruction() + memory/skill assembly
  - NEW: includes/Core/ProviderCredentialLoader.php — ensure_provider_credentials_static()
  - NEW: includes/Core/ToolPermissionResolver.php — get_tools_needing_confirmation(), classify_ability()
  - NEW: includes/Core/SpinDetector.php — build_tool_signature(), idle round tracking
  - NEW: includes/Core/ClientAbilityRouter.php — partition_tool_calls(), client ability stubs
  - NEW: includes/Core/ConversationSerializer.php — serialize/deserialize history
  - AgentLoop becomes ~400-line orchestrator composing these services
  - Verify: `composer phpstan && npm run test:php` (existing AgentLoop tests must still pass)

- [x] t194 Complete DI migration — convert CoreServicesHandler static calls to real handlers #refactor #auto-dispatch ~4h logged:2026-04-16 pr:#1007 completed:2026-04-17
  - CoreServicesHandler::on_initialize() calls 10 static ::register() methods — thin veneer, not real DI
  - Convert ChangeLogger, ProviderTraceLogger, KnowledgeHooks, ToolDiscovery, CustomToolExecutor, AutomationRunner, EventTriggerHandler, GitTrackerManager, OnboardingManager, FreshInstallDetector into #[Handler] classes with #[Action] decorators
  - Remove CoreServicesHandler once empty
  - Register new handlers in Plugin.php handlers array
  - Verify: `composer phpstan && composer phpcs`

- [x] t195 Clean up phpstan.neon — deduplicate ignores and write WP 7.0 AI Client stubs #quality #auto-dispatch ~3h logged:2026-04-16 blocked-by:t191 pr:#1011 completed:2026-04-17
  - phpstan.neon has 300 lines of ignoreErrors with ~40 duplicates
  - Deduplicate patterns, add count: annotations where supported
  - EDIT: stubs/wordpress-7-runtime.php — add WP_AI_Client_Prompt_Builder class with method stubs
  - Remove ignores made unnecessary by t191 DTOs
  - Target: <100 lines of ignoreErrors
  - Verify: `composer phpstan` passes with reduced ignore list

- [x] t196 Move domain logic out of REST controllers into service classes #refactor #auto-dispatch ~2h logged:2026-04-16 pr:#1010 completed:2026-04-17
  - RestController has upload_attachments_to_media_library() (infrastructure) and generate_session_title() (domain logic)
  - NEW: includes/Infrastructure/WordPress/MediaUploader.php — extracted from RestController
  - NEW: includes/Core/SessionTitleGenerator.php — extracted from RestController
  - Fix stale AdminPage::SLUG reference in ScreenMetaPanel (class was deleted in t146)
  - Verify: `composer phpstan && composer phpcs`

- [x] t197 Add interfaces for key contracts (repositories, settings, budget) #refactor ~4h logged:2026-04-16 blocked-by:t189,t192 pr:#1012 completed:2026-04-17
  - No interfaces exist — direct coupling to concrete classes, hard to mock in tests
  - NEW: includes/Contracts/SessionRepositoryInterface.php — CRUD contract from t189
  - NEW: includes/Contracts/SettingsProviderInterface.php — get/update/defaults from t192
  - NEW: includes/Contracts/BudgetCheckerInterface.php — wraps BudgetManager::check_budget()
  - Update DI container bindings to use interface → implementation mapping
  - Verify: `composer phpstan && composer phpcs`
  - `src/components/provider-selector.js:43-48` renders a disabled `(no providers)` SelectControl when `providers` is empty — useless UX that gives no path forward
  - EDIT: src/components/provider-selector.js — when `providers.length === 0`, return early with a `<p>` or `<div>` containing a link: `<a href="/wp-admin/options-connectors.php">{ __( 'Configure a provider', 'sd-ai-agent' ) }</a>` instead of the two SelectControl dropdowns
  - Update snapshot: `npm run test:js -- --updateSnapshot` (ProviderSelector.test.js snapshot will need regenerating)
  - Verify: render ProviderSelector with empty providers array, assert link renders and points to `options-connectors.php`; render with providers, assert dropdowns render normally

### Customer Feedback & Issue Reporting System (P1)

Full plan: [todo/PLANS.md#customer-feedback-issue-reporting-system](todo/PLANS.md#2026-04-14-customer-feedback--issue-reporting-system)

Phase 1 (receiving plugin) complete — shipped to Ultimate-Multisite/sd-ai-feedback.

- [x] t180 Feedback settings UI: endpoint URL + API key fields in Settings > Advanced #feature #auto-dispatch ~2h logged:2026-04-14 pr:#946 completed:2026-04-15
  - Add "Feedback" section to Settings > Advanced tab with endpoint URL, API key (password field), and enable/disable toggle
  - EDIT: src/components/settings/AdvancedSettings.js — add feedback section below existing fields
  - EDIT: src/store/slices/settingsSlice.js — add feedback_endpoint_url, feedback_api_key, feedback_enabled to settings state
  - EDIT: includes/Core/Settings.php — add feedback keys to defaults and sanitization
  - Verify: settings save/load round-trip in browser, key stored in separate option (not exposed via GET /settings)


- [x] t182 Consent UI component: modal with payload preview + send/dismiss #feature #auto-dispatch ~3h logged:2026-04-14 blocked-by:t181 pr:#952 completed:2026-04-15
  - NEW: src/components/FeedbackConsentModal.js — modal with: (1) summary stats (message count, tool call count, environment keys), (2) collapsible "View full payload" section showing the actual sanitized JSON that will be sent, (3) privacy notice: "No passwords, API keys, or credentials are included. Server paths are anonymized. Review the full payload below.", (4) optional user description textarea, (5) "Strip tool results" checkbox for aggressive privacy, (6) "Send Report" and "Dismiss" buttons
  - Wire to Redux store: dispatches sendFeedbackReport async thunk on confirm
  - EDIT: src/store/slices/sessionsSlice.js — add sendFeedbackReport thunk that calls ReportBuilder via new REST proxy endpoint
  - NEW: REST endpoint `POST /sd-ai-agent/v1/feedback/send` — server-side proxy that builds, sanitizes, and forwards the report (keeps API key server-side)
  - Verify: modal renders, payload preview matches actual data, send succeeds against a mock endpoint, dismiss closes without side effects

- [x] t183 Auto-prompt feedback banner on exit_reason (spin/timeout/max_iterations) #feature #auto-dispatch ~2h logged:2026-04-14 blocked-by:t182 pr:#951 completed:2026-04-15
  - EDIT: src/store/slices/sessionsSlice.js — when chat response includes exit_reason, set feedbackPromptVisible flag
  - EDIT: src/components/MessageList.js or ChatPanel.js — render inline banner below the error message: "The AI had trouble completing your request. Would you like to send a report to help us improve? [Send Report] [Dismiss]"
  - "Send Report" opens FeedbackConsentModal with report_type pre-set to the exit_reason value
  - "Dismiss" hides the banner for this session
  - Also handle WP_Error responses (max_iterations) — these come as error responses, not exit_reason in the JSON
  - Verify: E2E test or manual test — trigger a spin_detected response, verify banner appears, clicking Send opens the consent modal

- [x] t184 /report-issue slash command in chat input #feature #auto-dispatch ~2h logged:2026-04-14 blocked-by:t182 pr:#947 completed:2026-04-15
  - EDIT: src/components/MessageInput.js — add `/report-issue` to the slash command list (pattern: `/report-issue [optional description]`)
  - When invoked: open FeedbackConsentModal with report_type='user_reported', pre-fill user_description from the command argument
  - Model on existing `/remember` and `/forget` slash command handling in the same file
  - Verify: type `/report-issue something broke` in chat input, verify modal opens with description pre-filled

- [x] t185 report-inability ability: agent self-flags when it cannot complete a task #feature #auto-dispatch ~2h logged:2026-04-14 blocked-by:t181 pr:#948 completed:2026-04-15
  - NEW: includes/Abilities/FeedbackAbilities.php — single ability `report-inability` with schema: { reason: string, attempted_steps: string[] }
  - Ability handler: sets a session-level flag (transient or session meta) that the frontend reads on next poll
  - EDIT: sd-ai-agent.php — register the new abilities class
  - EDIT: system prompt (Settings.php build_system_instruction or agent prompt) — add instruction: "If you cannot complete the user's request after trying, call the report-inability ability with a reason."
  - Frontend: when session response includes the inability flag, show the feedback consent banner with report_type='self_reported'
  - Verify: `composer phpcs && composer phpstan`


- [x] t187 AI-assisted triage automation for incoming feedback reports #feature ~6h logged:2026-04-14 blocked-by:t183 pr:#949 completed:2026-04-15
  - Runs as an aidevops routine (r020) using deterministic script + AI agent split (modeled on /log-issue-aidevops pattern)
  - Deterministic script: `~/.aidevops/agents/custom/scripts/feedback-triage.sh` (already created) — `fetch` pulls new reports, `get <id>` pulls full payload, `dedup <keywords>` checks existing GitHub issues, `update <id> <status>` marks reports after triage
  - AI agent receives each report and judges: (1) real bug vs user error vs model limitation vs missing ability vs provider error, (2) checks dedup results — is this already reported?, (3) validates claims per log-issue-aidevops Step 3.6 (verify evidence, check data scale, detect template-driven findings), (4) composes structured GitHub issue body (Description, Expected Behavior, Steps to Reproduce from conversation, Environment, Report ID for backlink)
  - If real + not duplicate: `gh issue create -R Ultimate-Multisite/sd-ai-agent` then `feedback-triage.sh update <id> issue_created <url>`
  - If duplicate: `feedback-triage.sh update <id> dismissed` with link to existing issue
  - If not a bug: `feedback-triage.sh update <id> dismissed` with reason
  - Check plugin_version vs latest: if the report is from an outdated version where the issue is already fixed, dismiss with "fixed in vX.Y.Z"
  - Routine entry: `r020 Triage incoming feedback reports repeat:persistent ~15m agent:Build+` (lifecycle managed by `sh.aidevops.routine-feedback-triage-daily.timer`; r010 reserved for framework GH Failure Miner)

### Complete Site Building Abilities (P0)

- [x] t177 Smart plugin discovery: search-plugin-directory ability #feature #auto-dispatch ~3h logged:2026-04-09 ref:GH#840 pr:#788 completed:2026-04-06
  - Search WordPress.org by keyword, return results with active installs, rating, block support
  - Completes the discover-then-install flow (install_plugin already exists)
  - EDIT: includes/Abilities/WordPressAbilities.php — add search_plugin_directory ability
  - Model on existing install_plugin ability pattern in same file
  - Verify: `composer phpcs -- includes/Abilities/WordPressAbilities.php && composer phpstan`
- [x] t171 Installable abilities registry and recommend-plugin ability #feature #auto-dispatch ~3h logged:2026-04-09 ref:GH#849 pr:#861 completed:2026-04-09
  - Curated registry of plugins known to register abilities (slug, ability count, categories)
  - recommend-plugin ability: given a need category, return ranked recommendations
  - Preference order: has abilities > has blocks > popular
  - EDIT: includes/Abilities/WordPressAbilities.php — add recommend_plugin ability
  - NEW: includes/Core/AbilityPluginRegistry.php — curated registry as PHP array constant
  - Verify: `composer phpcs && composer phpstan`
- [x] t172 Register custom post type ability with persistence #feature #auto-dispatch ~4h logged:2026-04-09 ref:GH#850 pr:#858 completed:2026-04-09
  - register-custom-post-type: labels, supports, menu icon, REST API, rewrite rules
  - Persist via sd_ai_agent_custom_post_types option, register on init
  - NEW: includes/Abilities/CustomPostTypeAbilities.php — model on PostAbilities.php
  - EDIT: sd-ai-agent.php — register the new abilities class
  - Verify: `composer phpcs && composer phpstan && wp plugin activate sd-ai-agent`
- [x] t173 Register custom taxonomy ability with persistence #feature #auto-dispatch ~3h logged:2026-04-09 ref:GH#851 pr:#863 completed:2026-04-10
  - register-custom-taxonomy: associate with post types, hierarchical, labels
  - Same persistence pattern as CPTs
  - EDIT: includes/Abilities/CustomPostTypeAbilities.php — add taxonomy abilities to same file
  - Verify: `composer phpcs && composer phpstan`
- [x] t178 Navigation menu management ability #feature #auto-dispatch ~4h logged:2026-04-09 ref:GH#844 pr:#860 completed:2026-04-10
  - Create menus, add/update/remove items, assign to theme locations
  - Biggest ecosystem gap — only bjornfix/mcp-expose-abilities has this
  - NEW: includes/Abilities/MenuAbilities.php — model on NavigationAbilities.php
  - EDIT: sd-ai-agent.php — register the new abilities class
  - Verify: `composer phpcs && composer phpstan`
- [x] t179 Options management ability with safety blocklist #feature #auto-dispatch ~2h logged:2026-04-09 ref:GH#845 pr:#859 completed:2026-04-10
  - Get/set WordPress options (site title, tagline, permalinks, timezone, date format)
  - Blocklist for dangerous options (siteurl, home, active_plugins, etc.)
  - EDIT: includes/Abilities/WordPressAbilities.php — add get_option/update_option abilities
  - Verify: `composer phpcs && composer phpstan`
- [x] t174 Global styles (theme.json) management ability #feature #auto-dispatch ~4h logged:2026-04-09 ref:GH#852 pr:#856 completed:2026-04-09
  - Read and update theme.json global styles (colors, typography, spacing, layout)
  - Uses wp_global_styles CPT internally
  - NEW: includes/Abilities/GlobalStylesAbilities.php — model on BlockAbilities.php
  - EDIT: sd-ai-agent.php — register the new abilities class
  - Verify: `composer phpcs && composer phpstan`
- [x] t175 Site builder orchestration v2: system prompt + multi-step builds #feature ~8h logged:2026-04-09 ref:GH#854 pr:#862 completed:2026-04-09
  - Rewrite site builder prompt to use new abilities + plugin discovery
  - Plan generation, progress tracking, error recovery
  - Blocked by: t171-t174, t177-t179 (needs all abilities available first)
- [x] t176 Design system: custom CSS injection, block patterns, site logo, theme presets #feature #auto-dispatch ~6h logged:2026-04-09 ref:GH#853 pr:#857 completed:2026-04-10
  - inject-custom-css, curated block patterns, set-site-logo, theme.json presets
  - NEW: includes/Abilities/DesignAbilities.php — model on BlockAbilities.php
  - EDIT: sd-ai-agent.php — register the new abilities class
  - Verify: `composer phpcs && composer phpstan`
- [x] t170 Benchmark validation: ac-016 restaurant website end-to-end test #testing ~3h logged:2026-04-09 ref:GH#839 pr:#848 completed:2026-04-09
  - Run full prompt on fresh WordPress install, document gaps
  - Create follow-up issues for ac-017 through ac-024
  - Blocked by: t168 (needs orchestration working first)

Full plan: [todo/PLANS.md#complete-site-building-abilities](PLANS.md#2026-04-09-complete-site-building-abilities)

### Onboarding & First-Run Experience (P0)

- [x] t060 Detect fresh WordPress install and trigger site builder mode @superdav42 #feature ~3h logged:2026-03-15 pr:#454 completed:2026-03-16
  - Check post count, theme, and whether any real content exists
  - Set `site_builder_mode` flag; floating widget opens automatically in expanded mode
- [x] t061 Site builder conversation flow: interview user then generate full site @superdav42 #feature ~12h logged:2026-03-15 blocked-by:t060 pr:#455 completed:2026-03-16
  - System prompt interviews user: business name, type, goals, pages needed
  - Agent creates pages via BlockAbilities, content via ContentAbilities, images via StockImageAbilities
  - Agent sets up nav menu, site title, tagline, basic SEO
  - Target: 5-page site from prompt in < 3 minutes
- [x] t062 Full-screen site builder widget mode for fresh installs @superdav42 #ui ~6h logged:2026-03-15 blocked-by:t060 pr:#452 completed:2026-03-16
  - Centered full-screen overlay instead of FAB; progress indicators; "Skip" option
- [x] t063 Smart onboarding: agent scans existing site on first activation @superdav42 #feature ~8h logged:2026-03-15 pr:#453 completed:2026-03-16
  - Background wp-cron scan: plugins, theme, post types, post count, categories, WooCommerce status
  - Store results as memories; detect site type; index first 50 posts into knowledge base
- [x] t064 Onboarding interview: ask user about site goals after scan @superdav42 #feature ~4h logged:2026-03-15 blocked-by:t063 pr:#457 completed:2026-03-16
  - Agent asks targeted questions based on scan results
  - Store answers as memories; suggest relevant automations
- [x] t065 Integrate provider setup into onboarding conversation @superdav42 #ui ~4h logged:2026-03-15 pr:#456 completed:2026-03-16
  - Replace 4-step wizard with conversational flow; agent guides provider setup in chat

### Floating Widget & Frontend (P0)

- [x] t066 Show floating widget on frontend for logged-in admins @superdav42 #feature ~3h logged:2026-03-15 pr:#458 completed:2026-03-16
  - Register `wp_enqueue_scripts` hook with capability check
  - Frontend page context: current URL, post ID, template, query vars
  - Setting to enable/disable (default: enabled for admins)
- [x] t067 Improve floating widget UX: resizable panel, keyboard shortcut @superdav42 #ui ~4h logged:2026-03-15 pr:#446 completed:2026-03-16
  - Resize handle, Cmd/Ctrl+Shift+A toggle, localStorage persistence, mobile responsiveness

### Streaming Responses (P0)

- [x] t054 Add streaming response support via SSE @superdav42 #feature ~8h logged:2026-03-15 pr:#268 completed:2026-03-15
  - Custom REST endpoint with `Content-Type: text/event-stream`, output buffer bypass
  - Frontend EventSource API with reconnection; typing indicator; stop button
  - Fall back to current polling pattern if SSE fails

### AI Image Generation & Selection (P1)

- [x] t068 Add AI image generation ability (DALL-E / Stable Diffusion) @superdav42 #feature ~6h logged:2026-03-15 pr:#459 completed:2026-03-16
  - New ability: `generate-image` from text prompt; upload to media library; return attachment ID
- [x] t069 Enhance StockImageAbilities: auto-select images during content creation @superdav42 #feature ~3h logged:2026-03-15 pr:#449 completed:2026-03-16
  - Auto-attach featured image when creating posts/pages; search by style/mood

### WooCommerce Integration (P1)

- [x] t070 WooCommerce abilities: product CRUD, order queries, store stats @superdav42 #feature ~8h logged:2026-03-15 pr:#450 completed:2026-03-16
  - Conditionally register on WooCommerce detection
  - create-product, list-products, get-orders, store-stats, update-product via Woo REST API
- [x] t071 WooCommerce onboarding: detect store and offer AI product creation @superdav42 #feature ~3h logged:2026-03-15 blocked-by:t063,t070 pr:#443 completed:2026-03-16

### Rich Output & Artifacts (P1)

- [x] t072 Render data tables in chat responses (sortable, filterable) @superdav42 #ui ~6h logged:2026-03-15 pr:#439 completed:2026-03-16
- [x] t073 Render charts in chat responses (Chart.js) @superdav42 #ui ~4h logged:2026-03-15 pr:#440 pr:#440 completed:2026-03-16
- [x] t074 Action cards for confirmable operations @superdav42 #ui ~4h logged:2026-03-15 pr:#277 completed:2026-03-16

### Code Quality & Dev Environment (P1)

- [x] t006 Fix npm audit vulnerabilities (21 issues: 7 moderate, 14 high) @superdav42 #security ~2h logged:2026-03-14 pr:#262 completed:2026-03-15
- [x] t007 Add pre-commit hooks via husky + lint-staged for PHP/JS/CSS @superdav42 #quality ~2h logged:2026-03-14 pr:#84 completed:2026-03-15
- [x] t008 Make CI workflows non-continue-on-error @superdav42 #ci ~1h logged:2026-03-14 pr:#83 completed:2026-03-15
- [x] t009 Tighten PHPCS: re-enable EscapeOutput, add nonce verification rules @superdav42 #quality ~4h logged:2026-03-14 pr:#87 completed:2026-03-15
- [x] t010 Raise PHPStan level from 5 to 6, fix new errors @superdav42 #quality ~4h logged:2026-03-14 pr:#266 completed:2026-03-15
- [x] t011 Add PHPDoc blocks to all classes/methods @superdav42 #quality ~8h logged:2026-03-14 pr:#89 completed:2026-03-15

### Testing Infrastructure (P1)

- [x] t012 Set up wp-env based test runner for local and CI @superdav42 #testing ~4h logged:2026-03-14 pr:#88 completed:2026-03-15
- [x] t013 Add integration tests for all Abilities classes @superdav42 #testing ~8h logged:2026-03-14 blocked-by:t012 pr:#276 completed:2026-03-15
- [x] t014 Add integration tests for AgentLoop (mock AI responses) @superdav42 #testing ~6h logged:2026-03-14 blocked-by:t012 pr:#274 completed:2026-03-15
- [x] t015 Add integration tests for RestController endpoints @superdav42 #testing ~4h logged:2026-03-14 blocked-by:t012 pr:#275 completed:2026-03-15
- [x] t016 Add integration tests for Database schema and migrations @superdav42 #testing ~3h logged:2026-03-14 blocked-by:t012 pr:#273 completed:2026-03-15
- [x] t017 Add integration tests for Automations system @superdav42 #testing ~4h logged:2026-03-14 blocked-by:t012 pr:#270 completed:2026-03-15
- [x] t018 Add JS unit tests with @wordpress/scripts test-unit-js @superdav42 #testing ~6h logged:2026-03-14 pr:#272 completed:2026-03-15
- [x] t019 Add code coverage reporting to CI @superdav42 #ci ~2h logged:2026-03-14 blocked-by:t012 pr:#264 completed:2026-03-15
- [x] t020 Add E2E tests with wp-env + Playwright for chat UI @superdav42 #testing ~8h logged:2026-03-14 blocked-by:t012 pr:#445 completed:2026-03-16

### Ability Quality Improvements (P1)

- [x] t029 Standardize error handling: return WP_Error not arrays with 'error' key @superdav42 #refactor ~4h logged:2026-03-14 pr:#269 completed:2026-03-15
- [x] t030 Add output_schema to all abilities @superdav42 #quality ~4h logged:2026-03-14 pr:#402 completed:2026-03-16
- [x] t031 Add meta.annotations (readonly, destructive, idempotent) to all abilities @superdav42 #quality ~3h logged:2026-03-14 pr:#438 completed:2026-03-16
- [x] t032 Add meta.show_in_rest = true where appropriate @superdav42 #quality ~2h logged:2026-03-14 pr:#298 completed:2026-03-15

### Git Change Tracking & Undo (P1)

- [x] t033 Port GitTracker class: store original files as git blobs, track changes @superdav42 #feature ~6h logged:2026-03-14 ref=GH#431 pr:#442 verified:2026-03-16
- [x] t034 Port GitTrackerManager: manage trackers across plugins/themes @superdav42 #feature ~3h logged:2026-03-14 ref=GH#432 pr:#451 verified:2026-03-16
- [x] t035 Add Changes Admin page: view diffs, revert changes, export patches @superdav42 #feature ~8h logged:2026-03-14 blocked-by:t033,t034 ref=GH#433 pr:#447 pr:#447 completed:2026-03-16
- [x] t036 Add plugin download links for AI-modified plugins @superdav42 #feature ~2h logged:2026-03-14 ref=GH#434 pr:#441 pr:#441 completed:2026-03-16

### Tool Permission System (P1)

- [x] t037 Add per-tool WordPress capabilities (sd_ai_agent_tool_{name}) @superdav42 #feature ~3h logged:2026-03-14 ref=GH#435 pr:#448 pr:#448 completed:2026-03-16
- [x] t038 Add YOLO mode toggle (skip all confirmations) @superdav42 #feature ~1h logged:2026-03-14 pr:#444 completed:2026-03-16

### AI SDK Alignment (P1)

- [x] t041 Update compat layer to match WordPress/ai Abstract_Ability pattern @superdav42 #refactor ~4h logged:2026-03-14 pr:#405 completed:2026-03-15
- [x] t042 Port abilities from WordPress/ai experiments plugin @superdav42 #feature ~6h logged:2026-03-14 blocked-by:t041 pr:#472 completed:2026-03-16
- [x] t043 Add Abilities Explorer admin page @superdav42 #feature ~6h logged:2026-03-14 pr:#471 completed:2026-03-16
- [x] t044 Support all three official AI providers @superdav42 #feature ~3h logged:2026-03-14 pr:#271 ref=GH#236 completed:2026-03-15

### White-Label & Resale (P2)

- [x] t075 White-label support: custom branding, colors, greeting, agent name @superdav42 #feature ~6h logged:2026-03-15 pr:#470 completed:2026-03-16
- [x] t076 Resale API: proxy endpoint for managed AI with usage tracking @superdav42 #feature ~8h logged:2026-03-15 pr:#475 completed:2026-03-16

### Architecture & Modernization (P2)

- [x] t046 Extract send_prompt_direct() to dedicated OpenAI proxy class @superdav42 #refactor ~4h logged:2026-03-14 ref=GH#239 pr:#281 pr:#281 completed:2026-03-15
- [x] t047 Extract credential management to CredentialResolver class @superdav42 #refactor ~3h logged:2026-03-14 pr:#280 completed:2026-03-15
- [x] t048 Replace hardcoded model fallback with configurable default @superdav42 #refactor ~1h logged:2026-03-14 pr:#278 completed:2026-03-15
- [x] t049 Add proper dependency injection instead of static method calls @superdav42 #refactor ~4h logged:2026-03-14 pr:#344 ref=GH#320 pr:#344 completed:2026-03-15
- [x] t050 Add event/hook system for ability execution (before/after hooks) @superdav42 #refactor ~3h logged:2026-03-14 pr:#337 completed:2026-03-15

### Frontend/UI Improvements (P2)

- [x] t051 Add screen-meta integration (chat in WP admin Help/Screen Options) @superdav42 #ui ~4h logged:2026-03-14 pr:#473 completed:2026-03-16
- [x] t053 Add CodeMirror integration for code display in chat @superdav42 #ui ~2h logged:2026-03-14 pr:#460 completed:2026-03-16
- [x] t055 Add proper error boundaries in React components @superdav42 #quality ~3h logged:2026-03-14 pr:#279 completed:2026-03-15
- [x] t056 Add TypeScript types or JSDoc to JS codebase @superdav42 #quality ~8h logged:2026-03-14 pr:#341 completed:2026-03-15

### Multi-User & Collaboration (P2)

- [x] t077 Shared conversations: multiple admins can view/continue same session @superdav42 #feature ~6h logged:2026-03-15 ref=GH#387 pr:#474 pr:#474 completed:2026-03-16
- [x] t078 Role-based AI permissions: restrict abilities by WordPress user role @superdav42 #feature ~4h logged:2026-03-15 pr:#462 completed:2026-03-16
- [x] t079 Conversation templates: pre-built prompts for common tasks @superdav42 #feature ~3h logged:2026-03-15 ref=GH#389 pr:#413 pr:#413 completed:2026-03-16

### Proactive Site Intelligence (P2)

- [x] t080 Daily site health automation: check plugins, errors, performance, security @superdav42 #feature ~6h logged:2026-03-15 pr:#411 completed:2026-03-16
- [x] t081 Proactive alerts: surface issues as notification badge on FAB @superdav42 #feature ~4h logged:2026-03-15 pr:#410 completed:2026-03-16

### Custom Agent Builder (P2)

- [x] t082 Agent builder UI: specialized agents with custom prompts, tools, models @superdav42 #feature ~8h logged:2026-03-15 ref=GH#392 pr:#437 pr:#437 completed:2026-03-16

### Voice Interface (P3)

- [x] t083 Push-to-talk: speech-to-text via browser Web Speech API @superdav42 #feature ~4h logged:2026-03-15 pr:#408 completed:2026-03-16
- [x] t084 Text-to-speech for AI responses (optional) @superdav42 #feature ~3h logged:2026-03-15 pr:#469 completed:2026-03-16

### Integration Hub (P3)

- [x] t085 Google Analytics integration: traffic analysis ability @superdav42 #feature ~4h logged:2026-03-15 pr:#465 completed:2026-03-16
- [x] t086 Google Search Console integration: SEO insights ability @superdav42 #feature ~4h logged:2026-03-15 pr:#464 completed:2026-03-16
- [x] t087 Slack/Discord notification forwarding for automation results @superdav42 #feature ~3h logged:2026-03-15 pr:#412 completed:2026-03-16
- [x] t088 Webhook API: trigger AI conversations from external systems @superdav42 #feature ~4h logged:2026-03-15 pr:#409 completed:2026-03-16
- [x] t089 MCP server: expose abilities as MCP tools for external AI clients @superdav42 #feature ~6h logged:2026-03-15 pr:#404 completed:2026-03-15

### Documentation & Packaging (P2)

- [x] t057 Add CONTRIBUTING.md with dev setup, testing, and PR guidelines @superdav42 #docs ~1h logged:2026-03-14 pr:#167 completed:2026-03-15
- [x] t058 Add WordPress Playground blueprint for instant demo @superdav42 #devops ~2h logged:2026-03-14 pr:#403 ref=GH#400
- [x] t059 Update .distignore for clean plugin packaging @superdav42 #devops ~1h logged:2026-03-14 pr:#168 completed:2026-03-15

## Routines

- [x] r020 Triage incoming feedback reports repeat:persistent ~15m agent:Build+ ref:GH#944 # systemd: sh.aidevops.routine-feedback-triage-daily.timer (renamed from r010 — that slot is reserved for the framework GH Failure Miner)

## Done

- [x] t186 Thumbs-down button on assistant messages #feature #auto-dispatch ~2h logged:2026-04-14 blocked-by:t182 pr:#956 completed:2026-04-15
  - EDIT: src/components/MessageList.js — add a subtle thumbs-down icon button on assistant message hover (next to copy button if present)
  - On click: open FeedbackConsentModal with report_type='thumbs_down', anchor to the specific message index
  - Include the specific message + surrounding context (2 messages before/after) in the report payload, not the full conversation — user can opt into full conversation via checkbox in the modal
  - Verify: hover over assistant message, thumbs-down appears, clicking opens consent modal
- [x] t181 Feedback report payload builder + sender-side sanitizer #feature #auto-dispatch ~3h logged:2026-04-14 blocked-by:t180 pr:#953 completed:2026-04-15
  - NEW: includes/Feedback/ReportBuilder.php — collects session messages, tool_calls, token_usage, model_id, provider_id, environment (WP version, PHP version, plugin version, theme, active plugins, locale, multisite). Also include plugin_version vs latest_available for "is this already fixed?" triage.
  - NEW: includes/Feedback/ReportSanitizer.php — port from sd-ai-feedback receiving plugin, runs before transmission
  - NEW: includes/Feedback/ReportSender.php — wp_remote_post() to configured endpoint with X-Feedback-Api-Key header, handles errors gracefully (no user-facing crash on 4xx/5xx)
  - Verify: `composer phpstan && composer phpcs`
- [x] t000 Install dependencies and verify code quality baseline verified:2026-03-14 completed:2026-03-14
- [x] t001 Set up dev environment: composer install, npm ci, wp-env config verified:2026-03-14 completed:2026-03-14
- [x] t002 php-ai-client already at ^1.3 (latest) verified:2026-03-14 completed:2026-03-14
- [x] t003 .wp-env.json created with WP 6.9, PHP 8.2, multisite, debug config verified:2026-03-14 completed:2026-03-14
- [x] t021 FileAbilities: 7 abilities (read/write/edit/delete/list/search-files/search-content) verified:2026-03-14 completed:2026-03-14
- [x] t023 DatabaseAbilities: db_query with SELECT-only guard and {prefix} placeholder verified:2026-03-14 completed:2026-03-14
- [x] t024 WordPressAbilities: get_plugins, get_themes, install_plugin, run_php verified:2026-03-14 completed:2026-03-14
- [x] t026 NavigationAbilities: navigate (URL validation), get_page_html (CSS selector) verified:2026-03-14 completed:2026-03-14
- [x] t028 AbilityDiscoveryAbilities: 3 meta-tools (discovery-list, discovery-get, discovery-execute) verified:2026-03-14 completed:2026-03-14
- [x] t045 SimpleAiResult extracted to own file, PHPDoc reference fixed verified:2026-03-14 completed:2026-03-14

## Declined
