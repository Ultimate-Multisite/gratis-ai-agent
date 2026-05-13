# Superdav AI Agent - Plans & Strategy

## Positioning

**"The AI that doesn't just build your site -- it runs it."**

Every competitor generates a site and then you're on your own. We build AND manage. The agent
lives inside WordPress with access to the database, hooks, cron, plugins, and business context
that no external AI tool can match.

### What we have that nobody else does

- **True agentic architecture** -- autonomous multi-step tool calling via WordPress Abilities API
- **Provider independence (BYOK)** -- users bring their own API key, pay direct, no markup
- **Extensible via Abilities API** -- any plugin can register abilities the agent discovers automatically
- **Event-driven + scheduled automations** -- 20+ WordPress/WooCommerce triggers, cron-based tasks
- **Knowledge base / RAG** -- document indexing, PDF upload, auto-index on publish
- **Persistent memory** -- cross-session memory with categories, auto-memory mode
- **Admin context awareness** -- agent knows which page you're on and adapts
- **No vendor lock-in** -- standard WordPress plugin, works on any host, any theme, any builder

### Where competitors beat us (gaps to close)

| Gap | Who does it | Priority | TODO refs |
|-----|-------------|----------|-----------|
| AI site generation from prompt | 10Web, GoDaddy, ZipWP, Divi | P0 | t060-t062 |
| Streaming responses | Everyone | P0 | t054 |
| Smart onboarding for existing sites | 10Web (site scan) | P0 | t063-t065 |
| Frontend widget for logged-in admins | N/A (our own gap) | P0 | t066 |
| AI image generation | 10Web, Divi (unlimited) | P1 | t068-t069 |
| WooCommerce deep integration | 10Web, GoDaddy | P1 | t070-t071 |
| Rich artifacts in chat | Claude.ai, OpenWebUI | P1 | t072-t074 |
| White-label / resale API | 10Web, ZipWP | P2 | t075-t076 |
| Multi-user collaboration | 10Web (agency workspaces) | P2 | t077-t079 |

## Current Focus

P0 tasks: onboarding flows (t060-t065), frontend widget (t066), streaming (t054). These are the
features that determine whether a user stays past the first 5 minutes.

## Competitive Landscape (March 2026)

### AI Site Generation Platforms (hosted)

**10Web** -- Hosted WordPress + AI builder. Full site from 1 prompt in <60s. Elementor-based
editor. $10-23/mo with hosting. 2M+ sites generated. 4.5/5 Trustpilot (2,300 reviews). Strengths:
speed, support, all-in-one. Weaknesses: AI struggles with complex prompts, billing complaints,
medium lock-in. White-label API for B2B.

**GoDaddy Airo** -- Proprietary builder (NOT WordPress). Site in <30s. $11-24/mo. 20M+ customers,
$4.6B revenue. Strengths: fastest setup, cheapest, marketing suite (email, social, SEO). Weaknesses:
generic designs, no plugins/apps, full vendor lock-in, no export. Not a direct competitor since
it's not WordPress, but sets user expectations for AI site building speed.

**ZipWP** -- WordPress-native AI builder by Brainstorm Force (Astra). Full site from prompt in
<60s. $0-33/mo. Real WordPress install, fully portable. Strengths: no lock-in, agency features,
Chrome extension for profile-to-site. Weaknesses: Astra/Spectra only, free plan sites expire in
24h. Closest competitor to our approach.

### AI-Enhanced Page Builders (plugins)

**Divi AI** -- AI built into Divi visual builder. Unlimited text/image/code generation at flat
$16-23/mo. Full site generation via Quick Sites. Fine-tuned on Divi codebase. Strengths: unlimited
usage, deep builder integration. Weaknesses: Divi-only, proprietary shortcode format.

**Elementor AI + Angie** -- AI in Elementor editor (credit-based) plus Angie (free agentic plugin).
Angie creates widgets, CPTs, admin snippets -- closest to our agentic approach. 21M+ sites.
Strengths: massive market share, Angie is truly agentic. Weaknesses: credit-based pricing, complex
tiers, Angie is new.

### AI Code Assistants

**CodeWP/Telex** -- AI code generation for WordPress, acquired by Automattic. In transition/rebrand.
WordPress-specific training data. Wildcard -- Automattic has deep WP knowledge and resources.

## Model Strategy

Default: GPT-4.1-nano ($0.10/1M input). Premium: GPT-4.1-mini ($0.40/1M input). Both native
OpenAI format, 1M context. Session cost ~$0.007 on nano. See ROADMAP.md for full analysis.

## Success Metrics

| Metric | 3-Month | 6-Month | 12-Month |
|--------|---------|---------|----------|
| WordPress.org active installs | 500 | 2,000 | 10,000 |
| Sites generated via AI builder | 200 | 2,000 | 15,000 |
| Daily active users | 100 | 500 | 3,000 |
| Avg sessions/user/day | 2 | 3 | 5 |
| Time to first value (install to useful output) | < 5 min | < 3 min | < 2 min |
| AI site generation success rate | > 75% | > 85% | > 90% |
| User retention (30-day) | 35% | 50% | 65% |
| NPS score | 30 | 45 | 60 |
| White-label partners | 0 | 3 | 10 |

## Key Workflows We Must Support

Ranked by user frequency and competitive necessity:

1. **"Build me a website for X"** -- full site generation from prompt (P0, t060-t062)
2. **"Write content about X"** -- blog post / page content generation (have ContentAbilities, need orchestration)
3. **Chat with streaming responses** -- real-time token streaming (P0, t054)
4. **"What's wrong with my site?"** -- site health analysis + auto-fix (P2, t080-t081)
5. **"Create a product for X"** -- WooCommerce product creation (P1, t070)
6. **"Fix my SEO"** -- SEO audit + auto-fix (have SeoAbilities)
7. **"Update all my plugins"** -- already works via WordPressAbilities
8. **"Show me my stats"** -- rich charts/dashboards (P1, t072-t073)
9. **"Set up email marketing"** -- integration hub (P3, t085-t088)
10. **"Answer customer questions"** -- frontend chatbot with RAG (P2, needs frontend widget t066 first)

---

### [2026-04-09] Complete Site Building Abilities

**Status:** Planning
**Estimate:** ~40h (ai:30h test:8h read:2h)
**Benchmark target:** ac-016 (Restaurant website) as first end-to-end validation

#### Purpose

The agent can create pages and content but cannot build a complete website autonomously.
It lacks abilities for custom post types, taxonomies, menus, global styles, and form
handling. The benchmark suite (agent-capabilities-v1, ac-016 through ac-024) defines
what "complete site building" means -- the agent should be able to execute every step
those questions describe.

The hybrid approach: build only what doesn't exist elsewhere, leverage the WordPress
plugin ecosystem for everything else, and make the agent smart enough to discover and
install the plugins it needs.

#### Architecture: Smart Plugin Discovery

**Core principle:** The agent should never re-implement what a plugin already does well.
Instead of building a form plugin, a menu manager, or an SEO tool, the agent should:

1. Detect what capabilities are missing for the current task
2. Search for plugins that provide those capabilities (preferring plugins with
   Abilities API support, then block-based plugins, then popular plugins)
3. Recommend or auto-install the best option (with user confirmation)
4. Discover and use the newly available abilities/blocks

This requires a new **Ability Discovery & Plugin Recommendation** system:

```
User: "Add a contact form to the homepage"
Agent: [checks registered abilities -- no form ability found]
Agent: [checks installed plugins -- no form plugin found]
Agent: [searches WordPress.org + known ability-providing plugins]
Agent: "I can install WPForms Lite (has blocks) or Formidable Forms
        (has Abilities API support). Which do you prefer?"
User: "WPForms"
Agent: [installs + activates WPForms via existing install_plugin ability]
Agent: [discovers new blocks: wp:wpforms/form-selector]
Agent: [creates contact page with form block]
```

**Known plugins that register abilities (preferred):**
- `mcp-expose-abilities` (bjornfix) -- 66 core abilities: menus, widgets, options, plugins, content, users, media, comments, taxonomy
- `mcp-abilities-elementor` -- 40 Elementor abilities
- `mcp-abilities-rankmath` -- 23 SEO abilities
- `mcp-abilities-toolset` -- 38 CPT/field/taxonomy/relationship abilities
- `filter-abilities` -- taxonomy CRUD, Gravity Forms, Yoast SEO, ACF, media, redirects
- `designsetgo` -- block insertion, configuration, batch updates, CSS
- ACF Pro -- `register-custom-post-type`, `register-custom-taxonomy`

**Curated plugin recommendations by need (maintained in code):**
- Forms: WPForms Lite, Formidable Forms, Gravity Forms, Contact Form 7
- SEO: Yoast SEO, Rank Math (has abilities add-on)
- E-commerce: WooCommerce (has abilities)
- Page builders: Elementor (has abilities add-on), GeneratePress (has abilities add-on)
- Custom fields: ACF (has abilities), Meta Box
- Backup: UpdraftPlus
- Security: Wordfence (has abilities add-on)

#### Progress

- [ ] Phase 1: Smart Plugin Discovery & Recommendation ~8h
- [ ] Phase 2: Core Site Building Abilities (what no plugin provides) ~10h
- [ ] Phase 3: Site Builder Orchestration (system prompt + multi-step) ~10h
- [ ] Phase 4: Design System & Styling Abilities ~6h
- [ ] Phase 5: Benchmark Validation (ac-016 through ac-024) ~6h

#### Phase 1: Smart Plugin Discovery & Recommendation

**Goal:** The agent can find, recommend, and install plugins to gain capabilities
it doesn't have, preferring plugins that register abilities or provide blocks.

Tasks:
- [ ] `search-plugin-directory` ability -- search WordPress.org by keyword, return
  results with active installs, rating, block support, last updated. We already have
  `install_plugin` (WordPressAbilities) so this completes the discover-then-install flow.
- [ ] `list-installable-abilities` ability -- maintain a curated registry of plugins
  known to register abilities (slug, ability count, categories). When the agent needs
  a capability it doesn't have, it can check this registry first. Registry stored as
  a PHP array constant, updated with plugin releases.
- [ ] `recommend-plugin` ability -- given a need category (forms, seo, ecommerce,
  page-builder, custom-fields, security, backup), return ranked recommendations from
  the curated list with reasoning. Factors: has abilities > has blocks > popular.
- [ ] `list-available-blocks` ability -- list all registered Gutenberg block types
  on the current site, so the agent knows what blocks it can use after installing a
  plugin. We have `list-block-types` in BlockAbilities already -- verify it returns
  enough detail (supports, attributes, category).
- [ ] Agent loop system prompt update -- teach the agent the discovery workflow:
  "If you need a capability you don't have, search for a plugin that provides it.
  Prefer plugins with Abilities API support. Ask the user before installing."

#### Phase 2: Core Site Building Abilities

**Goal:** Build abilities that no existing plugin provides well, or that are too
fundamental to depend on a third-party plugin for.

Tasks:
- [ ] `register-custom-post-type` ability -- register a CPT with labels, supports,
  menu icon, REST API visibility, and rewrite rules. Persist registration across
  page loads via an option (so CPTs survive without our plugin being the registrar
  on every request -- store in a `sd_ai_agent_custom_post_types` option that
  fires on `init`).
- [ ] `register-custom-taxonomy` ability -- register a taxonomy, associate with
  post types, set hierarchical flag, labels. Same persistence pattern as CPTs.
- [ ] `manage-nav-menu` ability -- create menus, add/update/remove items, assign
  to theme locations. This is the biggest gap in the ecosystem (only bjornfix has
  it). We build our own because menus are too fundamental to site building to
  depend on a third-party plugin.
- [ ] `manage-options` ability -- get/set WordPress options (site title, tagline,
  permalink structure, timezone, date format, etc.) with a blocklist for dangerous
  options (siteurl, home, active_plugins, etc.).
- [ ] `manage-global-styles` ability -- read and update theme.json global styles
  (colors, typography, spacing, layout). Uses the `wp_global_styles` CPT that
  WordPress uses internally for user-customized styles.

#### Phase 3: Site Builder Orchestration

**Goal:** The agent can execute a multi-step site build from a single prompt,
using all available abilities (built-in + discovered from plugins).

**Status (2026-05-13):** Obsolete. Site Builder mode was removed in beads
`sd-ai-dh0`. Onboarding now consolidates into the Setup Assistant agent
(`Models/Agent::ONBOARDING_AGENT_SLUG`); its stored system prompt drives the
discovery flow. Multi-step orchestration is now an Agent-driven concern, not
a dedicated builder mode. If a future task revives orchestrated builds, it
should extend the Setup Assistant prompt and abilities rather than reintroduce
a parallel `site_builder_mode` setting.

#### Phase 4: Design System & Styling Abilities

**Goal:** The agent can apply cohesive visual design, not just create content.

Tasks:
- [ ] `inject-custom-css` ability -- add custom CSS to the site via the Customizer
  additional CSS or a custom stylesheet. Scoped to avoid conflicts.
- [ ] Block pattern library -- curate a set of block patterns for common site
  sections (hero, features grid, pricing table, testimonials, CTA, footer).
  The agent selects and customizes patterns rather than building blocks from scratch.
- [ ] `set-site-logo` ability -- upload and set the site logo via `custom_logo`
  theme mod.
- [ ] Theme.json presets -- curated design presets for common site types (restaurant,
  portfolio, law firm, SaaS, nonprofit). Each preset defines colors, typography,
  spacing. The agent selects a preset based on the site type, then customizes.

#### Phase 5: Benchmark Validation

**Goal:** Validate that the agent can complete ac-016 (restaurant website) end-to-end,
then create follow-up tasks for ac-017 through ac-024.

Tasks:
- [ ] Manual test: ac-016 restaurant website -- run the full prompt through the agent
  on a fresh WordPress install. Document what works, what fails, what's missing.
- [ ] Create follow-up issues for each remaining benchmark question (ac-017 through
  ac-024) based on gaps found during ac-016 testing.
- [ ] Update benchmark scoring criteria if needed -- the keyword-based scoring may
  need adjustment after seeing real agent responses vs the benchmark prompts.

#### Context from Discussion

**Research findings (April 2026):**
- WordPress Abilities API ecosystem has 285+ abilities across community plugins
- bjornfix/mcp-expose-abilities is the most complete: 66 core + 12 add-ons
- Biggest ecosystem gaps: form creation, CPT registration (without ACF), theme.json
- Our agent already has: PostAbilities, BlockAbilities, ContentAbilities, MediaAbilities,
  StockImageAbilities, WordPressAbilities (install_plugin),
  NavigationAbilities, SeoAbilities, WooCommerceAbilities
  (SiteBuilderAbilities was removed 2026-05-13 alongside Site Builder mode.)
- Community plugins that register abilities: mcp-expose-abilities, ACF Pro, WooCommerce,
  Yoast SEO, designsetgo, filter-abilities, elementor-mcp, wp-agentic-admin
- Agent capabilities benchmark (ac-016 through ac-024) defines the target: restaurant
  site, portfolio, nonprofit events, Shopify migration, law firm design, blog readability,
  SaaS landing page, learning platform, healthcare accessibility

**Key design decisions:**
- Hybrid approach: build core abilities + smart plugin discovery, don't duplicate
  what plugins do well
- Agent should prefer plugins with Abilities API support > blocks > popularity
- Never build a form plugin -- install one and use its blocks/abilities
- Menus are too fundamental to outsource -- build our own ability
- CPT/taxonomy registration must persist without our plugin being the sole registrar
- Global styles manipulation uses the wp_global_styles CPT (WordPress internal)

#### Decision Log

(To be populated during implementation)

#### Surprises & Discoveries

(To be populated during implementation)

---

### [2026-04-14] Customer Feedback & Issue Reporting System

**Status:** In Progress (Phase 1 complete)
**Estimate:** ~25h (ai:20h test:3h read:2h)
**Repo (receiving plugin):** [Ultimate-Multisite/sd-ai-feedback](https://github.com/Ultimate-Multisite/sd-ai-feedback)

#### Purpose

There is no mechanism for customers to report when the AI agent fails, loops, or
produces bad results. The agent already detects spin (`exit_reason: spin_detected`)
and timeout (`exit_reason: timeout`) but nothing acts on these signals. Without
feedback from real-world usage, agent quality improvements are guesswork.

This system has three layers: detection (in-plugin), transport (REST API on a
central WordPress site), and processing (AI-assisted triage to GitHub issues).
Every report requires explicit user consent — no silent telemetry.

#### Architecture

```
Customer Site (sd-ai-agent)         Central Site (sd-ai-feedback)
┌─────────────────────────────┐        ┌──────────────────────────────┐
│ Detection triggers:          │        │ POST /sd-feedback/v1/    │
│  - exit_reason spin/timeout  │──POST──│      reports                 │
│  - agent self-report ability │  +key  │  ↓                           │
│  - /report-issue command     │        │ ReportSanitizer (defense     │
│  - thumbs-down on message    │        │   in depth)                  │
│                              │        │  ↓                           │
│ ReportSanitizer (sender-side)│        │ DB: _feedback_reports        │
│ Consent UI (per-report)      │        │  ↓                           │
└─────────────────────────────┘        │ Triage automation            │
                                        │  → GitHub issue creation     │
                                        └──────────────────────────────┘
```

#### Progress

- [x] (2026-04-14) Phase 1: Receiving plugin — REST endpoint, DB, sanitizer, API key CLI
- [ ] Phase 2: Sender — settings UI, report builder, consent flow, auto-prompt on failures ~8h
- [ ] Phase 3: Sender — /report-issue command, report-inability ability, thumbs-down UI ~6h
- [ ] Phase 4: Triage automation — LLM review, dedup, GitHub issue creation ~6h

#### Task Breakdown

**Phase 1 — Receiving Plugin (COMPLETE)**
Shipped to `Ultimate-Multisite/sd-ai-feedback`:
- Plugin bootstrap with PSR-4 autoloader (`SdAiFeedback\` namespace)
- `_feedback_reports` table + `_feedback_api_keys` table (dbDelta migrations)
- `POST /sd-feedback/v1/reports` — API key auth via `X-Feedback-Api-Key`, rate-limited
- `GET/PATCH /reports` — admin listing and triage status updates
- `ReportSanitizer` — strips API keys, passwords, emails, IPs, server paths, DB creds, auth headers
- `strip_tool_results()` — aggressive mode that removes all tool output
- WP-CLI: `wp sd-feedback api-key generate/list/revoke`
- `uninstall.php` for clean removal

**Phase 2 — Sender: Settings + Consent + Auto-Prompt (t180-t183)**
- t180: Settings UI — feedback endpoint URL + API key fields in Settings > Advanced
- t181: Report payload builder — collects conversation, tool calls, env, sanitizes sender-side
- t182: Consent UI component — reusable modal/banner with preview of what gets sent
- t183: Auto-prompt on exit_reason — frontend reacts to spin_detected/timeout/max_iterations

**Phase 3 — Sender: Manual Triggers (t184-t186)**
- t184: `/report-issue` slash command in chat input
- t185: `report-inability` ability — agent self-flags when it cannot complete a task
- t186: Thumbs-down button on assistant messages

**Phase 4 — Triage Automation (t187)**
- t187: AI-assisted triage — LLM reviews new reports, classifies, deduplicates, creates GitHub issues

#### Context from Discussion

**Key design decisions:**
- Receiving plugin lives on an existing WordPress site (not a standalone service)
- Receiving plugin designed for expansion — `Plugin.php` singleton wires subsystems,
  future services (license validation, usage analytics) register there
- API key model: generated via WP-CLI on the receiving site, configured in the sender
  plugin's settings. SHA-256 hashed in DB. `gaf_` prefix for easy identification.
- Rate limiting: per API key, per hour (default 10, configurable per key)
- Sensitive data stripping runs on BOTH sides: sender strips before transmission,
  receiver strips again as defense-in-depth
- `strip_tool_results` option: user can choose to send conversation flow without any
  tool output (aggressive privacy mode). Keeps tool names/args but redacts responses.
- Consent is per-report, not a blanket opt-in. Auto-detected failures show a banner;
  user must click "Send Report" each time.
- Environment allowlist: only safe keys are transmitted (wp_version, php_version,
  plugin_version, theme, site_locale, is_multisite, active_plugins, etc.)
- Active plugins list strips paths, keeps only folder names (slug-level granularity)
- Site URL stripped to scheme+host only (no path)

**Existing infrastructure leveraged:**
- AgentLoop already returns `exit_reason` for `spin_detected` and `timeout` (lines 426, 609)
- RestController already passes `exit_reason` to the frontend (line 485-486)
- Frontend has slash command infrastructure in message input (`/remember`, `/forget`)
- `IdenticalFailureTracker` already nudges the model on spin — the report triggers after
  the nudge fails and the loop bails

#### Decision Log

- 2026-04-14: Chose WordPress plugin over Cloudflare Worker for receiving endpoint — keeps
  everything in the ecosystem, simpler deployment, can leverage WP-CLI for key management
- 2026-04-14: Chose API key auth over site-level tokens — allows per-site rate limiting and
  revocation without affecting other sites
- 2026-04-14: Chose separate `sd-ai-feedback` repo over adding to `sd-ai-agent` —
  the receiving plugin runs on a different site (the central server), not on customer sites

#### Surprises & Discoveries

(To be populated during implementation)

---

### [2026-04-16] Post-DI Code Quality & Structure Improvements

**Status:** Planning
**Estimate:** ~40h (ai:35h test:3h read:2h)
**Tasks:** t189–t197

#### Purpose

The x-wp/di container migration (PRs 1–6, merged 2026-04-16) moved all hook wiring
into `#[Handler]` classes and eliminated the 400-line bootstrap block from
`sd-ai-agent.php`. But the DI layer is currently a thin veneer over the original
static architecture — handlers call `XxxClass::register()` statics, the `Database`
class is a 1,490-line God class, `AgentLoop` handles 8+ concerns, `Settings` is
entirely static, and `phpstan.neon` has 300 lines of suppressions largely caused by
untyped `stdClass` returns from `wpdb`.

This plan systematically addresses the structural debt exposed by the DI migration,
in dependency order so each improvement compounds the next.

#### Progress

- [ ] (t189) Phase 1: Split Database God Class into domain repositories ~6h
- [ ] (t190) Phase 2: Remove dead `register()` methods from ability classes ~1h
- [ ] (t191) Phase 3: Typed DTOs for database rows ~4h
- [ ] (t192) Phase 4: Convert Settings to injectable DI service ~3h
- [ ] (t193) Phase 5: Extract AgentLoop subresponsibilities ~8h
- [ ] (t194) Phase 6: Complete DI migration — CoreServicesHandler → real handlers ~4h
- [ ] (t195) Phase 7: Clean up phpstan.neon — dedup ignores + WP 7.0 AI Client stubs ~3h
- [ ] (t196) Phase 8: Move domain logic out of REST controllers ~2h
- [ ] (t197) Phase 9: Add interfaces for key contracts ~4h

#### Phase 1: Split Database God Class (t189)

**Goal:** Replace the 1,490-line `Database` class with focused repository classes.

Current `Database` handles 7 unrelated domains:
- Schema install + migration → `Infrastructure/Database/SchemaManager`
- Session CRUD + listing → `Models/SessionRepository`
- Usage logging + summaries → `Models/UsageRepository`
- Generated plugins CRUD → `PluginBuilder/GeneratedPluginRepository`
- Modified files tracking → `Models/ModifiedFileRepository`
- Shared sessions → `Models/SharedSessionRepository`
- Paused state → inline in `SessionRepository`

Each repository owns its table name constant, CRUD methods, and query methods.
`Database` becomes `SchemaManager` — only invoked on install/upgrade.

All existing callers (`RestController`, `SessionController`, `AgentLoop`, etc.)
update their references. PHPStan verifies no stale references remain.

#### Phase 2: Remove Dead register() Methods (t190)

**Goal:** Clean up ~35 ability classes that still have `register()` methods.

With the DI `AbilitiesHandler` calling `register_abilities()` directly on the
`wp_abilities_api_init` hook, the `register()` methods that internally do
`add_action('wp_abilities_api_init', [__CLASS__, 'register_abilities'])` are
dead code — never called by anything.

Remove all `register()` stubs. Verify with `git grep '::register()' includes/Abilities/`.

#### Phase 3: Typed DTOs for Database Rows (t191)

**Goal:** Eliminate 30-50% of phpstan.neon ignores by replacing `stdClass` returns.

Currently `wpdb::get_row()` returns `stdClass|null` and every caller accesses
`$row->field` with `@phpstan-ignore-next-line`. Create typed DTOs:

- `Models/DTO/SessionRow` — id, user_id, title, provider_id, model_id, status, etc.
- `Models/DTO/UsageRow` — id, user_id, session_id, tokens, cost
- `Models/DTO/MemoryRow` — id, category, content, timestamps
- `Models/DTO/AutomationRow` — id, name, schedule, config
- Additional DTOs as needed per repository

Each DTO has a static `from_row(object $row): self` factory. Repository methods
return typed DTOs instead of `object|null`.

Benefits from t189 (repositories own the mapping). Eliminates `Cannot access
property on mixed`, `Cannot cast mixed to int/string`, etc.

#### Phase 4: Injectable Settings Service (t192)

**Goal:** Make `Settings` a DI-injectable service instead of a static utility.

Currently `Settings::get()` is called statically everywhere. `AgentLoop` already
accepts `?Settings $settings_service` in its constructor — but the class has no
useful instance methods.

- Add instance methods: `get()`, `update()`, `get_defaults()`, `get_default_model()`
- Register as a singleton in the DI container via `Plugin::configure()`
- Inject into `AgentLoop`, `RestController`, `SessionController`, etc.
- Keep static methods as deprecated wrappers during transition

#### Phase 5: Extract AgentLoop Subresponsibilities (t193)

**Goal:** Reduce `AgentLoop` from ~1,500 lines to ~400 lines.

Extract into focused classes:
- `Core/SystemInstructionBuilder` — `build_system_instruction()` + memory/skill/context assembly
- `Core/ProviderCredentialLoader` — `ensure_provider_credentials_static()`
- `Core/ToolPermissionResolver` — `get_tools_needing_confirmation()`, `classify_ability()`, `set_always_allow()`
- `Core/SpinDetector` — `build_tool_signature()`, idle round tracking
- `Core/ClientAbilityRouter` — `partition_tool_calls()`, `build_client_ability_stubs()`, `get_client_ability_names()`
- `Core/ConversationSerializer` — `serialize_history()`, `deserialize_history()`

`AgentLoop` becomes a thin orchestrator composing these services.

#### Phase 6: Complete DI Migration (t194)

**Goal:** Convert `CoreServicesHandler` static `::register()` calls into real DI handlers.

Currently `CoreServicesHandler::on_initialize()` calls 10 static methods, each
internally doing `add_action()`. Convert each to a `#[Handler]` class with
`#[Action]` decorators, then remove `CoreServicesHandler`.

#### Phase 7: phpstan.neon Cleanup (t195)

**Goal:** Reduce 300-line ignoreErrors to <100 lines.

1. Deduplicate ~40 duplicate patterns
2. Write WP 7.0 AI Client stubs in `stubs/wordpress-7-runtime.php`
3. Remove ignores fixed by t191 (DTOs)

Benefits from t191 (DTOs) and t193 (AgentLoop typed returns).

#### Phase 8: Move Domain Logic from REST Controllers (t196)

**Goal:** Controllers only validate input → call service → format response.

- `upload_attachments_to_media_library()` → `Infrastructure/WordPress/MediaUploader`
- `generate_session_title()` → `Core/SessionTitleGenerator`
- Fix stale `AdminPage::SLUG` reference in `ScreenMetaPanel`

#### Phase 9: Interfaces for Key Contracts (t197)

**Goal:** Formalize dependency contracts for testing with mocks.

- `SessionRepositoryInterface` — from t189
- `SettingsProviderInterface` — from t192
- `BudgetCheckerInterface` — wraps `BudgetManager`

Blocked by t189 + t192.

#### Context from Discussion

**Analysis session (2026-04-16) findings:**
- DI migration successful: 24 handlers, 73-line bootstrap, Infrastructure layer started
- PHPStan level 10, 127 test files, strict types — good baseline
- Key debt: Database God class (1,490 lines), AgentLoop (1,500+ lines), static Settings,
  300-line phpstan.neon, dead register() stubs on all ability classes
- DI is a thin veneer — handlers call static `::register()` methods instead of using
  real dependency injection

**Dependency order:**
t189/t190 (standalone) → t191 (needs t189) → t192 (standalone) → t193 (needs t192) →
t194 (standalone) → t195 (needs t191) → t196 (standalone) → t197 (needs t189+t192)

#### Decision Log

(To be populated during implementation)

#### Surprises & Discoveries

(To be populated during implementation)

---

### [2026-04-17] Resumable Background Jobs & Multi-Session Chat

**Status:** Decomposed → TODO.md (t200-t208)
**Estimate:** ~10d (ai:8d test:1.5d read:4h)
**Tasks:** t200-t208 in TODO.md (9 auto-dispatch tasks across 5 phases + bundled improvements)

#### Purpose

Users should be able to send a prompt, navigate away (close tab, switch admin pages, open a different chat), and come back to see all progress made while they were gone. Permission prompts that fire while the user is away should persist and notify them via browser notifications. Multiple conversations should be manageable concurrently with tab-based UI.

The server-side architecture already supports this — `fastcgi_finish_request()` decouples processing from the browser, and messages are persisted to the DB on completion. The gaps are all on the frontend reconnection and notification layers.

#### Architecture Analysis (from discussion)

**What already works:**
- Server processes independently of browser (`fastcgi_finish_request`, `ignore_user_abort`)
- Job progress stored in transients (polling via `/job/{id}`)
- Messages persisted to DB on completion (`append_to_session`)
- Tool confirmation pause/resume (`awaiting_confirmation` state)
- Per-session job tracking in Redux (`sessionJobs` map — but in-memory only)
- All three chat surfaces (admin page, floating widget, screen-meta) share the **same** `ChatPanel` component and the **same** `@wordpress/data` Redux store singleton

**Code sharing between surfaces:**
- `src/components/` (7,541 lines) — 100% shared across all surfaces
- `src/store/` (3,403 lines) — singleton, shared across all surfaces on same page
- Unique to admin page: ~600 lines (sidebar, onboarding, shortcuts)
- Unique to floating widget: ~400 lines (tabs, drag/resize, FAB)
- Unique to screen-meta: ~80 lines (context builder)
- Any fix to `ChatPanel`, `message-list`, or the store works everywhere automatically

#### Progress

- [ ] (2026-04-17) Phase 1: DB-backed active job tracking — **foundation** ~2-3d
  - NEW: `_active_jobs` table (session_id, job_id UUID, user_id, status, pending_tools JSON, tool_calls JSON, timestamps)
  - `handle_run()` writes row on job creation
  - `handle_process()` updates row on status changes (alongside transient)
  - `handle_job_status()` falls back to DB when transient is gone
  - NEW: `GET /sessions/{id}/active-job` endpoint for reconnection
  - Make transient the **cache layer**, DB the **source of truth** (fixes lost-result-on-network-blip bug)
  - Frontend: `openSession()` calls active-job endpoint, resumes polling if job exists

- [ ] (2026-04-17) Phase 2: Session-scoped polling & visibility-aware throttling ~2d
  - Refactor `pollJob()` to be session-independent (don't check `currentJobId`)
  - Multiple concurrent poll loops — one per active session job
  - Exponential backoff: 1s → 5s (after 10 polls) → 10s (after 30 polls), reset on progress
  - `document.visibilitychange` listener: hidden → slow to 15-30s, visible → immediate poll + resume normal
  - NEW: `restoreActiveJobs()` thunk — `GET /sessions/active-jobs` on mount, start poll loops

- [ ] (2026-04-17) Phase 3: Browser notifications for permission prompts ~1d
  - Request `Notification.permission` on first tool confirmation (or via settings)
  - When `pollJob()` detects `awaiting_confirmation` and `document.hidden`:
    - Fire `new Notification()` with `requireInteraction: true`
    - Flash document title: "Approval needed — WP Admin"
  - Session sidebar: visual badge on sessions with pending confirmations
  - Clear notification on focus or when confirmation is resolved

- [ ] (2026-04-17) Phase 4: Cross-page navigation survival ~0.5d
  - Persist active job IDs to `sessionStorage` on poll start
  - On `FloatingWidget` mount: read sessionStorage, restore poll loops
  - Combined with Phase 1 DB endpoint gives full resilience:
    - `sessionStorage` → fast reconnect on same-tab wp-admin navigation
    - DB → reconnect after tab close/reopen or browser restart

- [ ] (2026-04-17) Phase 5: Tabbed multi-session chat UI ~2-3d
  - NEW: `ChatTabBar` component — open sessions as tabs above ChatPanel
  - Tabs show status indicators: spinner (processing), warning (needs confirmation), idle
  - Close tab removes from open set (session persists in sidebar)
  - `+` button creates new session tab
  - Store `openTabs: [sessionId, ...]` in Redux + localStorage
  - Works in both admin page and floating widget (already shares ChatPanel)

#### Bundled Code Improvements (on the path of phases 1-5)

These touch the same files and are natural to include:

1. **Split job logic from sessionsSlice** (2,042 lines) — extract `jobSlice.js` owning poll/confirm/reject lifecycle. Needed for Phase 2.
2. **Remove dead SSE state** — `streamingText`, `isStreaming`, `streamAbortController`, `APPEND_STREAMING_TEXT` (~80 lines of unused reducer code). Declutter before adding new state.
3. **Extract `useActiveToolCalls(sessionId)` hook** — replaces IIFE in message-list.js thinking bubble. Needed for Phase 5 multi-tab.
4. **Normalize session IDs on ingest** — cast to number once in `setSessions()`/`setCurrentSession()`, eliminate 8+ scattered `parseInt(session.id, 10)` calls.
5. **Dynamic context windows from provider API** — replace hardcoded `MODEL_CONTEXT_WINDOWS` (7 models) with `context_window` field from `/providers` response.
6. **Adaptive poll interval** — exponential backoff (part of Phase 2) saves server load especially with multiple concurrent session polls.

#### Context from Discussion

**Key decisions:**
- Transient becomes cache layer, DB becomes source of truth (fixes silent result loss on network blip or transient eviction)
- `JOB_TTL = 600` stays as transient cache duration but is no longer the authoritative timeout
- All notification/reconnection logic goes into shared components — automatic 3-surface coverage
- Tab bar UI (Phase 5) preferred over split-pane — simpler, more intuitive
- Dead SSE streaming code to be removed (the production path is exclusively job+poll)

**Open questions:**
- Should we add a WordPress admin notification (wp-admin notice bar) for pending confirmations in addition to browser notifications?
- Should the active-jobs table clean up completed rows on a schedule or keep them for audit?
- Should tab state persist across browser sessions (localStorage) or just within a session (sessionStorage)?

**Implementation order:**
Phase 1 (foundation) → Phase 2 (polling refactor) → Phase 4 (sessionStorage, trivial add-on) → Phase 3 (notifications, independent) → Phase 5 (tab UI, polish layer)

#### Decision Log

(To be populated during implementation)

#### Surprises & Discoveries

(To be populated during implementation)

---

### [2026-04-17] Improve Block Editor Content Quality — Auto-inject Skills + Rich Gutenberg Skill {#improve-block-editor-content-quality}

**Status:** Planning
**Estimate:** ~20h (ai:16h test:3h read:1h)

#### Purpose

Pages created by the AI agent look awful — raw markdown mixed with block markup, no layout sophistication, no columns/groups/covers/buttons. The root cause is threefold: (1) skills are opt-in and the LLM never loads them, (2) the gutenberg-blocks skill is too thin to be useful even if loaded (73 lines, zero block markup examples), and (3) the system prompt actively steers the LLM toward markdown-only output. This plan fixes the skill delivery mechanism, enriches the skill content, and fixes the mixed-content rendering bug.

#### Progress

- [ ] (2026-04-17) Phase 1: Fix `maybe_convert_markdown()` mixed-content bug ~2h
- [ ] (2026-04-17) Phase 2: Auto-inject skills based on task intent ~4h
- [ ] (2026-04-17) Phase 3: Rewrite gutenberg-blocks skill with comprehensive block markup reference ~6h
- [ ] (2026-04-17) Phase 4: Unhide block abilities + update system prompt + add validate-block-content ability ~8h

#### Context from Discussion

**Investigation findings (raising-cows-for-beef page):**
- Page content is a mix of proper `<!-- wp:image -->` blocks and raw markdown (`## headings`, `**bold**`, `- lists`)
- `PostAbilities::maybe_convert_markdown()` short-circuits when it finds `<!-- wp:` anywhere in content — even if 90% of the content is still raw markdown
- The LLM produced hybrid content (image blocks from stock image ability + markdown for text) and the converter never touched the markdown portions

**Skills system architecture gaps identified:**
1. **Skills are passive/opt-in** — `Skill::get_index_for_prompt()` injects a one-line index telling the LLM to "use skill-load tool when a request matches." The LLM ignores this because the system prompt already says "just write markdown."
2. **Block tools are hidden** — `markdown-to-blocks` and `create-block-content` have `'ai_hidden' => true`, so `resolve_abilities()` filters them out. The LLM literally cannot call them.
3. **System prompt contradiction** — Default system instruction (line 173) says "Write content directly using markdown" which conflicts with any skill that says "use block tools for layouts."
4. **Skill content is too thin** — `gutenberg-blocks.md` is 73 lines with zero actual serialized block markup examples. Without examples, even a loaded skill doesn't teach the LLM how to produce correct output.
5. **No skill auto-injection** — Knowledge base has RAG-based auto-injection (`Knowledge::get_context_for_query()`). Skills have nothing equivalent — they're purely manual.

**Key files:**
- `includes/Core/SystemInstructionBuilder.php` — builds system prompt, injects skill index
- `includes/Models/Skill.php` — skill model, `get_index_for_prompt()` produces the passive index
- `includes/Abilities/BlockAbilities.php` — block tools (markdown-to-blocks, create-block-content, etc.)
- `includes/Abilities/PostAbilities.php` — `maybe_convert_markdown()` bug location (line 775-813)
- `includes/Models/MarkdownToBlocks.php` — markdown-to-blocks converter (text-only, no layout blocks)
- `includes/Models/skills/gutenberg-blocks.md` — current skill content (73 lines, too thin)

**Design decisions:**
- Auto-inject skills into system prompt (like knowledge RAG) rather than relying on LLM to call skill-load
- Keyword matching for skill injection is sufficient — no need for semantic search
- Keep `create-block-content` as the primary tool for complex layouts, teach raw block markup for simple content
- `maybe_convert_markdown()` should handle the "freeform blocks between real blocks" case gracefully

#### Decision Log

(To be populated during implementation)

#### Surprises & Discoveries

(To be populated during implementation)

---

### [2026-04-18] Adaptive Skill System — Usage Tracking, Tiered Injection, Remote Updates {#adaptive-skill-system}

**Status:** Planning
**Estimate:** ~30h (ai:24h test:4h read:2h)
**Tasks:** t215-t220 in TODO.md

#### Purpose

The skill system is static and blind. Built-in skills ship as frozen `.md` files,
the auto-injector uses hardcoded regex patterns (`SkillAutoInjector::TRIGGER_MAP`),
there's no telemetry on which skills help vs. waste tokens, no mechanism for skill
updates between plugin releases, and the auto-injection fires identically for
Claude Opus and a quantized 7B model — burning 1500-3000 tokens per turn on strong
models that would have loaded the skill on demand.

This plan introduces three capabilities: (1) usage tracking so the system learns
which skills help, (2) model-aware tiered injection so weak models get auto-injection
while strong models get the lean index-only path, and (3) an upstream update channel
so skill content can improve between plugin releases.

#### Architecture

```
                         System Prompt Assembly
                         (SystemInstructionBuilder)
                                   |
                    +--------------+--------------+
                    |                             |
          ModelHealthTracker                      |
          is_weak(model_id)?                      |
                    |                             |
           +-------+-------+                     |
           | YES           | NO                   |
           v               v                      |
   Auto-inject best     Index only               |
   matching skill       (~15 tok/skill)          |
   (~1500 tok, 1 max)   Model calls skill-load    |
           |            when it decides            |
           v               |                      |
   SkillUsageTracker       |                      |
   records outcome    <----+                      |
   (helpful/neutral)                              |
           |                                      |
           v                                      |
   WP-Cron daily:                                 |
   SkillUpdateChecker ---- Remote manifest --------+
   (hash comparison)        (static JSON CDN)
```

#### Progress

- [ ] (2026-04-18) Phase 1: Skill usage tracking table + telemetry ~4h
- [ ] (2026-04-18) Phase 2: Model-aware tiered injection ~4h
- [ ] (2026-04-18) Phase 3: Skill versioning + remote update channel ~8h
- [ ] (2026-04-18) Phase 4: Settings UI + admin dashboard ~6h
- [ ] (2026-04-18) Phase 5: Skill directory endpoint (server-side) ~8h

#### Phase 1: Skill Usage Tracking (t215)

**Goal:** Track which skills get loaded, how they were triggered, and whether
they helped — so the system can tune trigger patterns and surface quality signals.

Schema: `sd_ai_agent_skill_usage` table:
- `id` bigint PK
- `skill_id` bigint FK to skills table
- `session_id` bigint FK to sessions table
- `trigger_type` enum('auto', 'manual', 'tool_call') — how the skill was loaded
- `injected_tokens` int — estimated token cost of the injection
- `outcome` enum('helpful', 'neutral', 'negative', 'unknown') — heuristic or explicit
- `model_id` varchar(100) — which model received the skill
- `created_at` datetime

Outcome heuristic: if the agent completed the task (no `exit_reason` error) and
the skill's domain matched the task, mark `helpful`. If the user's next message
is unrelated (skill was a false positive match), mark `neutral`. Explicit
feedback from thumbs-down (t186) marks `negative`.

**Files:**
- EDIT: `includes/Core/Database.php` — add CREATE TABLE, bump DB_VERSION
- NEW: `includes/Models/DTO/SkillUsageRow.php` — readonly DTO
- NEW: `includes/Models/SkillUsageRepository.php` — create(), get_by_skill(), get_stats()
- EDIT: `includes/Core/SkillAutoInjector.php` — record auto-injection events
- EDIT: `includes/Abilities/SkillAbilities.php` — record manual skill-load calls
- EDIT: `includes/Core/AgentLoop.php` — after loop completes, evaluate outcome heuristic
- Verify: `composer phpstan && composer phpcs`

#### Phase 2: Model-Aware Tiered Injection (t216)

**Goal:** Strong models get the lean skill index only (~150 tokens). Weak models
get auto-injected skill content. Eliminates 1500-3000 tokens/turn waste on
capable models.

**Changes:**
- EDIT: `includes/Core/SystemInstructionBuilder.php:78-84` — wrap auto-injection
  in `ModelHealthTracker::is_weak($this->model_id)` check
- EDIT: `includes/Core/SkillAutoInjector.php` — reduce `MAX_INJECTED_SKILLS` from 2
  to 1 (weak models can't use two guides effectively)
- EDIT: `includes/Core/SkillAutoInjector.php` — add `get_index_description()` method
  returning a richer one-line description for the index (helps strong models decide
  when to call skill-load)
- Track skill-load tool usage in `ModelHealthTracker` — models that never call
  skill-load despite the index being present generate a signal toward "needs
  auto-injection"
- Verify: `composer phpstan && composer phpcs`

#### Phase 3: Skill Versioning + Remote Update Channel (t217, t218)

**Goal:** Built-in skill content can improve between plugin releases via a remote
manifest check. User customizations are preserved.

**Schema changes to skills table:**
- `version` varchar(20) DEFAULT '1.0.0'
- `content_hash` char(32) DEFAULT '' — md5 of content for change detection
- `source_url` varchar(500) DEFAULT '' — upstream URL for updates
- `user_modified` tinyint(1) DEFAULT 0 — set when admin edits a built-in skill

**Remote manifest** (static JSON, CDN-hosted):
```json
{
  "manifest_version": 1,
  "skills": {
    "wordpress-admin": {"version": "1.1.0", "content_hash": "abc123", "updated_at": "2026-04-18"},
    "woocommerce": {"version": "1.2.0", "content_hash": "def456", "updated_at": "2026-04-15"}
  }
}
```

**WP-Cron job** (daily): fetch manifest, compare hashes, update `is_builtin=1 AND
user_modified=0` skills. Conditional HTTP (ETag/If-Modified-Since) to avoid
unnecessary downloads.

**Files:**
- EDIT: `includes/Core/Database.php` — ALTER TABLE add columns, bump DB_VERSION
- EDIT: `includes/Models/Skill.php` — add `check_for_updates()`, `apply_update()`,
  track `user_modified` on `update()`
- NEW: `includes/Core/SkillUpdateChecker.php` — WP-Cron callback, manifest fetch,
  hash comparison, conditional HTTP
- EDIT: `includes/Models/Skill.php::reset_builtin()` — pull from remote instead of
  bundled .md file (with fallback to bundled if offline)
- NEW: `includes/Core/Settings.php` — add `skill_auto_update` setting (default: on)
- Verify: `composer phpstan && composer phpcs`

#### Phase 4: Settings UI + Admin Dashboard (t219)

**Goal:** Admin can see skill effectiveness data, control auto-update, and monitor
the update channel.

**Changes:**
- EDIT: `src/settings-page/skill-manager.js` — add "Usage Stats" column to skill
  list (load count, helpful %, last used)
- EDIT: `src/settings-page/skill-manager.js` — add "Auto-update" toggle per skill
  and global toggle
- EDIT: `src/settings-page/skill-manager.js` — show "Update available" badge when
  remote version is newer
- EDIT: `src/settings-page/skill-manager.js` — show "Modified" badge on user-edited
  built-in skills with "Reset to upstream" button
- NEW: REST endpoint `GET /sd-ai-agent/v1/skills/usage-stats` — aggregated usage
  data per skill
- Verify: `npm run lint:js && npm run build`

#### Phase 5: Skill Directory Endpoint (t220)

**Goal:** A lightweight server-side endpoint for hosting the skill manifest and
individual skill content. Can start as static files, graduate to a WordPress
custom post type.

**Approach:** Host on the same site as the feedback receiver
(sd-ai-feedback repo). Minimal — a manifest.json + individual skill .md
files served via a REST endpoint.

**Files (in sd-ai-feedback repo):**
- NEW: `includes/Skills/SkillDirectoryController.php` — `GET /skills/manifest`,
  `GET /skills/{slug}`
- NEW: `skills/` directory — hosted .md files for each built-in skill
- Manifest auto-generated from directory contents + file hashes
- Version bumped via commit — hash changes trigger client-side updates

Defer marketplace/community features (ratings, submissions, browsing) to a
future phase. The initial goal is a push channel for built-in skill improvements.

#### Context from Discussion

**Investigation findings (2026-04-18):**

Current skill architecture components:
- `Skill.php` — model with CRUD, seeding from `.md` files, `get_index_for_prompt()`
- `SkillAutoInjector.php` — hardcoded regex `TRIGGER_MAP` (9 patterns), injects up to
  2 full skill guides into every system prompt when patterns match
- `SkillAbilities.php` — `skill-load` and `skill-list` WordPress abilities (tools)
- `SkillService.php` — business logic for REST layer (create, delete, format)
- `SkillController.php` — REST API (CRUD, reset built-in)
- `SystemInstructionBuilder.php` — assembles system prompt, calls both `get_index_for_prompt()`
  AND `inject_for_message()` unconditionally
- `SkillRow.php` — immutable DTO for DB rows
- 11 built-in skills as `.md` files in `includes/Models/skills/`
- DB schema: id, slug, name, description, content, is_builtin, enabled, created_at, updated_at

Claude Code comparison (aidevops framework):
- Uses `SKILL.md` files + MCP tool for on-demand loading — model decides when to load
- `skill-update-helper.sh` tracks upstream via GitHub commit SHA or URL content hash
- Conditional HTTP requests (ETag/If-Modified-Since) avoid re-downloading unchanged content
- `skill-sources.json` stores upstream_url, upstream_commit, upstream_hash, upstream_etag,
  upstream_last_modified, imported_at, last_checked, merge_strategy
- Auto-update creates PRs via worktree workflow with conventional commits and changelogs
- `skills.sh` public registry for community skill discovery and installation

**Token cost analysis:**
- Skill index (`get_index_for_prompt()`): ~15 tokens/skill, ~150 total — cheap, always present
- Auto-injected skill content: ~1000-2000 tokens/skill, up to 2 = 3000 tokens/turn
- Auto-injection fires on broad regex (e.g. any message mentioning "page" triggers gutenberg-blocks)
- Strong models (GPT-4.1, Claude Sonnet/Opus) reliably call `skill-load` from the index alone
- Weak models (Mistral-7B, Phi-3, quantized Llama) ignore the index, need auto-injection
- `ModelHealthTracker` already classifies models as weak/strong via name heuristics + telemetry

**Key design decisions:**
- Auto-injection becomes model-aware: weak models get it, strong models don't
- Cap auto-injection at 1 skill (was 2) — weak models can't use two guides effectively
- Track skill-load tool usage as a signal in ModelHealthTracker
- Remote manifest uses the same hash-comparison pattern as Knowledge system
- User-modified built-in skills protected from auto-updates (`user_modified` flag)
- Server component starts minimal (static JSON manifest) — no dynamic backend initially
- Marketplace/community features deferred until tracking data proves skills matter

#### Decision Log

(To be populated during implementation)

#### Surprises & Discoveries

(To be populated during implementation)

---

### [2026-04-18] Onboarding v2: Gate + AI-Driven Discovery

**Status:** Planning
**Estimate:** ~8h (ai:6h test:1.5h read:0.5h)

#### Purpose

Replace the multi-step onboarding wizard with a two-state flow: a hard connector gate (no AI without a provider) followed by an AI-driven first conversation where the agent explores the site itself before asking any questions. Inspired by OpenClaw's "infrastructure gates are hard, personalisation is conversational" pattern — the agent does the discovery work, infers what it can from existing content, and only asks what it can't figure out.

Current onboarding has 5 wizard steps (Welcome, Provider, Abilities, WooCommerce, Done) plus a separate static interview form. Users can skip past the provider step with no connector configured (broken experience), and the interview asks questions the AI could answer itself by reading the site's content.

#### Progress

- [ ] (2026-04-18) Phase 1: Connector gate component + remove wizard ~2h
- [ ] (2026-04-18) Phase 2: Bootstrap system prompt + auto-discovery session ~4h
- [ ] (2026-04-18) Phase 3: Auto-enable WooCommerce + cleanup dead code ~2h

#### Phase 1: Connector Gate + Remove Wizard

Replace `onboarding-wizard.js` with a single-screen connector gate:
- If no providers configured: show "Connect an AI Provider" with link to Connectors page
- Poll the providers store every 3-5 seconds
- When a provider appears, auto-transition to the chat (State 2) — no user click needed
- No "Skip", no "Next", no progress dots. Just a gate.

Files:
- NEW: `src/components/onboarding-gate.js` — connector-required screen with polling
- EDIT: `src/admin-page/index.js` — replace `OnboardingWizard` import/usage with `OnboardingGate`
- DELETE content from: `src/components/onboarding-wizard.js` (remove multi-step wizard)
- DELETE content from: `src/components/onboarding-interview.js` (remove static interview)

#### Phase 2: Bootstrap System Prompt + Auto-Discovery Session

When onboarding transitions past the gate, create a dedicated first session with a bootstrap system prompt injected. The AI:
1. Uses abilities to read recent posts, pages, menus, site settings, active plugins, theme
2. Analyzes existing content to infer writing style, tone, audience, site purpose
3. Triggers RAG knowledge base indexing of existing content
4. Stores insights as agent memories
5. Presents a brief summary of findings + 3-5 tailored starter prompts
6. If site is empty, asks what kind of site the user is building instead

Files:
- EDIT: `includes/Core/AgentLoop.php` — accept a `bootstrap_prompt` parameter when creating the first session. Prepend to system instructions for that session only.
- EDIT: `includes/Core/OnboardingManager.php` — simplify to track `onboarding_complete` only. Add REST endpoint to create bootstrap session. Remove interview endpoints.
- NEW: `includes/Core/BootstrapPrompt.php` — generates the bootstrap system prompt, incorporating site scan results. Prompt instructs the AI to explore the site with tools before asking questions.
- EDIT: `src/admin-page/index.js` — after gate clears, create session via REST with bootstrap flag, transition to chat.
- EDIT: `src/store/` — add `isBootstrapSession` flag so UI doesn't show empty-state on first run.

Bootstrap system prompt (stored in PHP, injected once):

```text
You are starting your first conversation with a new user who just installed
Superdav AI Agent. Before asking them anything, use your available abilities to
learn about their site:

1. Read the site's recent posts, pages, and menus.
2. Check which plugins are active and what abilities are available.
3. Note the site title, tagline, and any obvious branding.
4. If WooCommerce is active, check products and store status.
5. Look at content volume, categories, tags, and publishing patterns.

From this, determine:
- What kind of site this is (blog, store, portfolio, business, etc.)
- The writing style and tone of existing content
- The likely target audience
- What the site owner probably needs help with

Then present a brief summary of what you found (2-3 sentences) and suggest
3-5 specific starter prompts tailored to this site. Ask what they'd like to
work on.

If the site is empty/new, acknowledge that and ask what kind of site they're
building instead.

Do not ask questions you can answer yourself from the site content. Store any
insights as memories for future sessions.
```

#### Phase 3: Auto-Enable WooCommerce + Cleanup

- EDIT: `includes/Core/Settings.php` — on first load with a provider detected, auto-enable WooCommerce abilities if WooCommerce is active. No user toggle needed.
- DELETE: `includes/Core/OnboardingInterview.php` — entire class (replaced by AI conversation)
- EDIT: `includes/Bootstrap/OnboardingHandler.php` — remove interview REST route registration
- EDIT: `includes/Core/OnboardingManager.php` — remove interview REST handlers
- DELETE: `src/components/__tests__/OnboardingWizard.test.js` — replace with gate tests
- DELETE: `tests/SdAiAgent/Core/OnboardingInterviewTest.php`

#### Context from Discussion

**OpenClaw inspiration:** OpenClaw's onboarding has two distinct phases — a hard infrastructure gate (gateway connection, auth) followed by a conversational AI bootstrapping session where the agent reads BOOTSTRAP.md and walks the user through identity/personality setup conversationally. Key quotes from their BOOTSTRAP.md: "Don't interrogate. Don't be robotic. Just... talk." and their kickoff message auto-sends to start the AI conversation immediately.

**Key design decisions:**
- No multi-step wizard at all — two states only (gate or chat)
- The AI explores the site with tools before asking the user anything
- Content analysis determines style/tone/audience when possible — the AI doesn't ask what it can infer
- WooCommerce auto-detected and auto-enabled silently
- RAG indexing queued during onboarding so knowledge base is populated for future sessions
- Empty sites get a different AI flow (ask about goals) vs content-rich sites (present findings)
- The static interview form is eliminated entirely — the AI conversation replaces it
- Memories are stored through normal abilities, not a separate interview-to-memory pipeline

#### Decision Log

(To be populated during implementation)

#### Surprises & Discoveries

(To be populated during implementation)

---

### [2026-04-21] WP 6.9 Compatibility — Polyfill WP 7.0 AI APIs + Connectors Page {#wp-69-compat}

**Status:** Planning
**Estimate:** ~40h (ai:30h test:8h read:2h)
**Tasks:** t226 (parent), t227-t231 (phases)

#### Purpose

WP 7.0 has been delayed again. The plugin currently hard-requires WP 7.0 for four API layers (php-ai-client SDK, Abilities API, AI Client bridge, Connectors API). We need the plugin to work on WP 6.9 while remaining forward-compatible — when users upgrade to WP 7.0, everything should keep working seamlessly with zero migration.

#### Architecture

**Strategy: conditional polyfill.** Every API layer is wrapped in `function_exists()` / `class_exists()` guards. On WP 6.9, our bundled copies load. On WP 7.0, core's definitions take precedence automatically.

**Four layers to polyfill:**

| Layer | Source | LOC | Approach |
|-------|--------|-----|----------|
| php-ai-client SDK (`WordPress\AiClient\*`) | `wp-includes/php-ai-client/` | ~12,800 | Composer package via Jetpack Autoloader |
| Abilities API (`WP_Ability`, registries, global functions) | `wp-includes/abilities-api/` | ~2,100 | Composer package (same as WooCommerce) |
| WP AI Client bridge (`WP_AI_Client_Prompt_Builder`, resolver, `wp_ai_client_prompt()`) | `wp-includes/ai-client/` | ~1,100 | Copy + conditional guards in `includes/Compat/` |
| Connectors API (`WP_Connector_Registry`, settings, credentials) | `wp-includes/connectors.php` + registry | ~1,800 | Copy + conditional guards + custom admin page |

**Provider plugins (Anthropic, OpenAI, Google) are NOT bundled** — they already target `Requires at least: 6.9` and install as standalone plugins. Our Connectors page provides Install/Activate buttons that call the WP Plugins REST API, identical to WP 7.0's core Connectors page.

#### Progress

- [ ] (2026-04-21) Phase 1: Add Composer packages (php-ai-client + abilities-api) ~4h — t227
- [ ] (2026-04-21) Phase 2: Create WP AI Client bridge polyfill ~8h — t228
- [ ] (2026-04-21) Phase 3: Create Connectors API polyfill ~8h — t229
- [ ] (2026-04-21) Phase 4: Build Connectors admin page (React) ~16h — t230
- [ ] (2026-04-21) Phase 5: Lower version requirement + test both versions ~4h — t231

#### Context from Discussion

**Key research findings:**

1. **WooCommerce precedent:** WooCommerce already vendors `wordpress/abilities-api` via Composer in production. This validates the approach of bundling WP 7.0 packages in plugins for pre-7.0 compatibility.

2. **Provider plugins target 6.9:** Both `ai-provider-for-anthropic` and `ai-provider-for-openai` declare `Requires at least: 6.9`. They register via `AiClient::defaultRegistry()->registerProvider()` — they just need the SDK available, which our Composer bundle provides. No bundling needed.

3. **Jetpack Autoloader handles conflicts:** Already in our `composer.json`. It resolves version conflicts by loading the newest copy — when WP 7.0 core provides classes, Jetpack defers to them. When on 6.9, our vendored copy loads.

4. **Same credential option names:** WP 7.0's Connectors API stores API keys in `connectors_ai_{provider}_api_key` options (auto-generated from provider ID). Our polyfill uses the same naming convention, so credentials entered on 6.9 work on 7.0 with zero migration.

5. **WP 7.0 Connectors page is too tightly coupled to copy:** It uses `@wordpress/boot` + script modules + the new WP routing system (none available on 6.9). We build a simpler React page using `@wordpress/components` + `@wordpress/data` (same tech stack we already use) that calls the same REST endpoints.

6. **Bridge classes are small:** `WP_AI_Client_Prompt_Builder` (472 lines) and `WP_AI_Client_Ability_Function_Resolver` (232 lines) are the only non-packaged WP core classes we need. Plus 4 small adapter classes and 2 global function files. Total ~1,100 LOC to copy.

7. **Existing code needs zero changes:** All 30+ ability classes, AgentLoop, ToolDiscovery, CredentialResolver, ProviderCredentialLoader, REST controllers, and the React chat UI work unchanged. The polyfill sits underneath them.

**Risks:**
- `wordpress/php-ai-client` may not be on Packagist yet — fallback: vendor as local path package or copy under `lib/`
- `WP_AI_Client_Prompt_Builder` constructor takes a registry arg in WP 7.0-RC2 but our stub doesn't — need to match the real signature
- Pin to RC2 SDK version and track upstream for breaking changes before 7.0 final

**Forward-compatibility guarantees:**
- Every polyfill guarded by `function_exists()` / `class_exists()` — WP 7.0 definitions win
- Same option names for credentials — zero migration
- Connectors page detects WP 7.0 and shows link to core page instead
- Jetpack Autoloader handles SDK/Abilities class version resolution

#### Decision Log

- 2026-04-21: Decided NOT to bundle provider plugins — they already target 6.9, install as standalone. Build install/activate UI in our Connectors page instead.
- 2026-04-21: Decided NOT to copy WP 7.0's Connectors SPA — too coupled to @wordpress/boot. Build simpler page with same tech stack we already use.
- 2026-04-21: Decided to use Jetpack Autoloader (already in composer.json) for SDK/Abilities version conflict resolution — proven pattern (WooCommerce uses it).

#### Surprises & Discoveries

- WP 7.0's `WP_AI_Client_Prompt_Builder` constructor takes `(ProviderRegistry $registry, $prompt)` — our stub file had `(string $prompt)`. The polyfill must match the real constructor.
- The AI Experiments plugin (`ai`) also requires WP 7.0 (`WPAI_MIN_WP_VERSION = '7.0'`) — it cannot serve as a polyfill source.
- `_wp_connectors_get_provider_settings()` and `_wp_connectors_get_real_api_key()` are used by our existing `ProviderCredentialLoader` — both need to be in the polyfill.

---

### [2026-04-26] Ability Discovery Investigation — Why Agent Misses Registered Abilities {#ability-discovery-investigation}

**Status:** Planning
**Estimate:** ~5h (ai:4h test:0.5h read:0.5h)
**Tasks:** t232 (parent), t234 (phase 1), t235 (phase 2)

#### Purpose

During a site builder session the agent reported that `update-post`, `manage-global-styles`, and `complete-site-builder` did not exist and used WP-CLI workarounds. All three are fully registered and implemented. Root cause must lie in how abilities are presented to the model — either the tool catalog injection, system prompt framing, ability description quality, or a namespace inconsistency the model cannot resolve.

#### Progress

- [ ] (2026-04-26) Phase 1: Audit ability injection pipeline and tool catalog ~3h — t234
- [ ] (2026-04-26) Phase 2: Fix discoverability — descriptions, system prompt, namespace alignment ~2h — t235

#### Context from Discussion

**Abilities verified present that agent could not find:**
- `ai-agent/update-post` — `PostAbilities.php:171`, full schema with title/content/status/featured_image_id/meta
- `ai-agent/update-global-styles` / `ai-agent/get-global-styles` — `GlobalStylesAbilities.php`
- `sd-ai-agent/complete-site-builder` — `SiteBuilderAbilities.php::CompleteSiteBuilderAbility`

**Investigation axes:**
1. `ToolCapabilities.php` — which abilities get included in the tool call payload? Any filter or count cap?
2. `AgentLoop.php` — how are abilities injected into `wp_ai_client_prompt()`? Injection limit?
3. Site-builder system prompt — does it enumerate available tools or leave discovery to the model?
4. Ability descriptions — are `update-post` / `update-global-styles` descriptions clear enough for a restaurant-site context?
5. Namespace inconsistency — `PostAbilities` uses `ai-agent/` prefix, `SiteBuilderAbilities` uses `sd-ai-agent/`. Does the model treat these as different plugins?

#### Decision Log

(To be populated during implementation)

#### Surprises & Discoveries

(To be populated during implementation)

---

### [2026-04-26] Site Builder Ability Improvements {#site-builder-ability-improvements}

**Status:** Planning
**Estimate:** ~9h (ai:8h test:0.5h read:0.5h)
**Tasks:** t233 (parent), t236–t240 (phases)

#### Purpose

Five genuine capability gaps found in the site builder audit. Most critical is the stock image fallback chain — Openverse/Pixabay download failures return hard errors with no retry. The rest are ergonomic improvements that reduce tool-call count and remove ambiguity for the agent.

#### Progress

- [ ] (2026-04-26) Phase 1: Stock image fallback chain — retry all free sources on download failure ~1.5h — t236
- [ ] (2026-04-26) Phase 2: Add `page_template` param to `create-post` and `update-post` ~1h — t237
- [ ] (2026-04-26) Phase 3: Add `set-featured-image` standalone ability ~1h — t238
- [ ] (2026-04-26) Phase 4: Add `batch-create-posts` ability ~2.5h — t239
- [ ] (2026-04-26) Phase 5: Add `create-contact-form` ability (WP core, no WPForms dependency) ~3h — t240

#### Context from Discussion

**Stock image fallback (t236):** `StockImageAbility` calls `ImageSourceFactory::get_available()`, picks the first free source, and returns a hard error if `download()` fails. The factory handles empty search results (falls back to AI generate) but not download failures. Fix: in `ImageSourceFactory::import_image()` on download failure, iterate to next free source then fall back to `generate`. Files: `ImageAbilities/StockImageAbility.php`, `ImageSources/ImageSourceFactory.php`.

**Page template (t237):** `wp_insert_post()` / `wp_update_post()` accept `page_template` as a post data key (maps to `_wp_page_template` meta). Neither `create-post` nor `update-post` expose it. Add to schema + handler in `PostAbilities.php`. Two lines per handler.

**set-featured-image (t238):** Already works via `update-post` (pass `post_id` + `featured_image_id`). A standalone ability removes ambiguity — agents frequently miss that `update-post` can set only the thumbnail without touching other fields. Add as a new `wp_register_ability` call in `PostAbilities.php`.

**batch-create-posts (t239):** A full site build takes ~7 sequential `create-post` calls. A batch ability accepting an array of post definitions reduces that to 1 call. Schema: `{ posts: [{ title, content, post_type, status, featured_image_id, page_template, ... }] }`. Returns `[{ post_id, permalink, title, status }]`. Add handler in `PostAbilities.php`.

**create-contact-form (t240):** WPForms is too plugin-specific. Better: register `ai-agent/create-contact-form` that inserts a simple HTML contact form as a Gutenberg HTML block (no plugin dependency). If Contact Form 7 is active, use its `WPCF7_ContactForm::create()` API instead and return the shortcode. Fallback chain: CF7 → raw HTML block. New ability in `ContentAbilities.php` or a new `FormsAbilities.php`.

**Three abilities the audit wrongly reported as missing (already implemented — no action needed):**
- `update-post` — `PostAbilities.php:171`
- `manage-global-styles` (`get-global-styles`, `update-global-styles`, `get-theme-json`, `reset-global-styles`) — `GlobalStylesAbilities.php`
- `complete-site-builder` — `SiteBuilderAbilities.php::CompleteSiteBuilderAbility`

#### Decision Log

(To be populated during implementation)

#### Surprises & Discoveries

(To be populated during implementation)
