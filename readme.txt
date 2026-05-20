=== Superdav AI Agent ===
Contributors: superdav42
Tags: ai, chatbot, assistant, automation, tools
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.16.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI assistant in your dashboard. Chat with it, teach it about your site, and let it manage tasks — using your own API key.

== Description ==

Superdav AI Agent adds a powerful AI assistant directly inside your WordPress admin. Ask it questions, give it tasks, and it will use your site's tools to get the job done — creating posts, managing users, checking site health, calling external APIs, and more.

**You bring your own API key.** Superdav AI Agent connects directly to your chosen AI provider (OpenAI, Anthropic, or any OpenAI-compatible service). There is no middleman, no markup on API costs, and no data routed through third-party servers. You pay only what your provider charges, and you can see every cent in their dashboard.

= Built on WordPress Core =

Superdav AI Agent is built on official WordPress APIs available in supported WordPress versions:

* **AI Client SDK** — One interface for all AI providers. Install a connector plugin for OpenAI, Anthropic, Ollama, or any compatible service and Superdav AI Agent works immediately.
* **Abilities API** — The WordPress-native tool registry. Every tool registered by any plugin on your site is automatically available to the agent. As your site grows, so does the agent's capabilities.

This means no fragile custom API wrappers, no vendor lock-in, and automatic improvements as WordPress core evolves.

= Two Ways to Chat =

* **Full-page chat** at Tools > Superdav AI Agent — A complete workspace with session history, folder organization, search, and export.
* **Floating widget** — A small button on every admin page that expands into a chat panel. Always available, never in the way.

= It Remembers =

The agent has persistent memory across sessions. It learns your preferences, site details, and workflows over time. You can also teach it manually or let it save knowledge automatically.

= It Knows Your Content =

Index your posts, pages, and uploaded documents into a searchable knowledge base. The agent searches this knowledge automatically when it needs context to answer your questions.

= Custom Tools Without Code =

Create tools the agent can use — no plugin development needed:

* **HTTP tools** — Connect to any external API (weather services, Zapier, Slack, CRMs)
* **WordPress action tools** — Trigger any WordPress hook
* **WP-CLI tools** — Run command-line operations

Five example tools are included to get you started.

= Scheduled Automations =

Set up AI tasks that run automatically on a schedule:

* Daily site health reports
* Weekly plugin update checks
* Content moderation
* Broken link scanning
* Database cleanup

Pick a schedule, write a prompt, and the agent handles the rest. View logs for every run.

= Event-Driven Automations =

The agent can react to things happening on your site in real time:

* A new post is published — auto-generate tags and a social media summary
* A user registers — send a personalized welcome sequence
* A WooCommerce order is placed — check inventory and notify your team
* A plugin is activated — run a compatibility check

20+ WordPress and WooCommerce triggers are included, with placeholder templates for dynamic data.

= Tool Profiles =

Control what the agent can access. Six built-in profiles (Read Only, Full Management, Content, Users, Maintenance, Developer) let you quickly scope permissions. Create your own custom profiles for specific use cases.

= Smart and Efficient =

* **Tool discovery** — On sites with many tools, the agent discovers what it needs instead of loading everything upfront. Saves tokens and money.
* **Conversation trimming** — Long conversations are automatically trimmed at safe boundaries to prevent context overflow.
* **Suggestion chips** — Clickable follow-up suggestions after each response keep the conversation flowing.
* **Usage tracking** — See exactly how many tokens each session uses and what it costs.

= Skills =

Create reusable instruction guides for the agent. Write a "content publishing checklist" or "image optimization workflow" once, and the agent follows it whenever the task comes up.

= Export and Import =

Export any conversation to JSON (for backup and reimport) or Markdown (for sharing and documentation). Import conversations from JSON backups.

= Extending with Ability Plugins =

The Superdav AI Agent discovers abilities at runtime from any plugin that registers them via `wp_register_ability()`. The more abilities installed, the more capable the agent.

**Recommended ability plugins:**

* **WP-CLI Abilities Bridge** — Exposes all WP-CLI commands as abilities. Gives the agent 500+ tools covering posts, users, plugins, themes, WooCommerce, and more.
* **Ultimate Multisite Core** — Registers abilities for multisite management: customers, memberships, sites, payments, products, domains, and email accounts.
* **Any plugin using the Abilities API** — Tools registered by any plugin are automatically available. No configuration needed.

== Installation ==

1. Upload the `sd-ai-agent` folder to `/wp-content/plugins/` or install through the WordPress plugin screen.
2. Activate the plugin.
3. Go to **Settings > AI Credentials** and configure a connector for your AI provider (OpenAI, Anthropic, etc.). You will need an API key from your provider.
4. Visit **Tools > Superdav AI Agent Settings** to choose your default provider and model.
5. Open **Tools > Superdav AI Agent** and start chatting.

= Requirements =

* WordPress 6.9 or higher
* PHP 8.2 or higher
* An AI provider connector plugin registered through the WordPress Connectors API
* An AI key from your chosen AI provider (OpenAI, Anthropic, etc.)

== External Services ==

This plugin connects to several third-party services to provide AI, search, and stock-image capabilities. Each service below is **optional** and is only contacted when you (a) configure the relevant API key or feature, or (b) ask the agent to perform an action that requires it. No data is sent to any of these services on plugin activation, page load, or in the background.

= AI providers (chat completions) =

These are contacted only when you configure the corresponding connector in **Settings > AI Credentials** and the agent generates a response. Each request sends the conversation messages, system prompt, attached files (if any), and tool definitions to the chosen provider so it can produce a reply.

* **OpenAI** (api.openai.com) — Provides AI chat completions when using OpenAI models. Sends the conversation context and your user queries. Terms: https://openai.com/policies/terms-of-use/ Privacy: https://openai.com/policies/privacy-policy/

* **Anthropic** (api.anthropic.com) — Provides AI chat completions when using Claude models. Sends the conversation context and your user queries. Terms: https://www.anthropic.com/legal/consumer-terms Privacy: https://www.anthropic.com/legal/privacy

* **Google AI / Gemini** (generativelanguage.googleapis.com) — Provides AI chat completions when using Gemini models. Sends the conversation context and your user queries. Terms: https://policies.google.com/terms Privacy: https://policies.google.com/privacy

= Internet search providers =

The internet-search ability is used when you (or the agent on your behalf) explicitly run a web search. The first configured provider wins. Only your search query is sent — no WordPress site data, user data, or conversation history is transmitted.

* **Tavily Search API** (api.tavily.com) — Web search results for the agent. Used only when a Tavily API key is configured in **Settings > Internet Search**. Sends the search query only. Terms: https://tavily.com/terms Privacy: https://tavily.com/privacy

* **Brave Search API** (api.search.brave.com) — Web search results for the agent. Used only when a Brave Search API key is configured in **Settings > Internet Search**. Sends the search query only. Terms: https://brave.com/terms-of-use/ Privacy: https://brave.com/privacy/browser/

* **DuckDuckGo Instant Answer API** (api.duckduckgo.com) — Free fallback web search used when no Tavily or Brave key is configured. Sends the search query only; no API key is required. Terms: https://duckduckgo.com/terms Privacy: https://duckduckgo.com/privacy

= Stock-image services =

These are contacted only when you (or the agent) request a stock image via the image-generation ability. Each request sends image dimensions and the search keyword you supply — no site data, user data, or conversation history is transmitted.

* **Openverse** (api.openverse.org) — CC0 / openly-licensed image search hosted by the WordPress Foundation. Used only when an image-search request is made. Sends the search keyword and requested dimensions. No API key required. Terms: https://docs.openverse.org/terms_of_service.html Privacy: https://wordpress.org/about/privacy/

* **Pixabay** (pixabay.com) — Free stock-image service. Used only when a Pixabay API key is configured and an image-search request is made. Sends the search keyword, requested dimensions, and your API key. Terms: https://pixabay.com/service/terms/ Privacy: https://pixabay.com/service/privacy/

* **Lorem Flickr** (loremflickr.com) — Random Creative Commons placeholder images. Used only when generating placeholder/lorem-ipsum-style images. Sends the requested dimensions and an optional search keyword. No API key required. Service info: https://loremflickr.com/

* **Picsum Photos** (picsum.photos) — Fallback random-image placeholder service. Used only when generating placeholder images. Sends the requested dimensions. No API key required. Service info: https://picsum.photos/

= Analytics =

* **Google Analytics Data API** (analyticsdata.googleapis.com) — Reads traffic, top-page, and realtime statistics from your own Google Analytics 4 property when you ask the agent for analytics insights. Used only when you upload a Google service-account JSON key in **Settings > Analytics** and explicitly request a report. Sends the GA4 property ID and the date range / metrics you requested, authenticated with your service-account credentials. The plugin reads from your own GA property; it does not send any WordPress site data to Google. Terms: https://policies.google.com/terms Privacy: https://policies.google.com/privacy Google Analytics terms: https://marketingplatform.google.com/about/analytics/terms/us/

= Notifications (optional) =

* **Discord** (discord.com) — Optional webhook notifications for automation results. Used only when you configure a Discord webhook URL on a specific automation. Sends the automation summary text you configured to the webhook URL. No data is sent unless you create a webhook entry. Terms: https://discord.com/terms Privacy: https://discord.com/privacy

* **Slack** (slack.com / hooks.slack.com) — Optional webhook notifications for automation results. Used only when you configure a Slack incoming-webhook URL on a specific automation. Sends the automation summary text you configured to the webhook URL. No data is sent unless you create a webhook entry. Terms: https://slack.com/terms-of-service Privacy: https://slack.com/privacy-policy

= Feedback and issue reporting (optional, opt-in) =

* **Superdav AI Agent feedback service** (ultimateagentwp.ai) — Optional service that receives user-submitted feedback and issue reports about the plugin so the maintainers can diagnose problems and improve the product. Nothing is sent automatically. A report is transmitted only when you explicitly click the thumbs-down button on an AI response or run the `/report-issue` command **and** accept the feedback-consent modal that previews the exact payload. Each report contains the sanitized conversation excerpt and metadata you reviewed in the consent modal (with secrets and PII redacted before display). No background telemetry, analytics, or automatic error reporting is ever sent. Terms: https://ultimateagentwp.ai/terms/ Privacy: https://ultimateagentwp.ai/privacy/

== Frequently Asked Questions ==

= Which AI providers are supported? =

Any provider that has a connector plugin for the WordPress AI Client SDK. This currently includes OpenAI (GPT-4o, GPT-4.1), Anthropic (Claude Opus 4, Sonnet 4, Haiku 4), and any OpenAI-compatible API (Ollama, Azure OpenAI, Groq, Together AI, etc.).

= How much does it cost to use? =

The plugin itself is free. You pay only for the API usage from your chosen provider at their published rates. There is no markup, subscription, or usage fee from Superdav AI Agent. The Usage tab in settings tracks your token consumption and estimated costs.

= Is my data sent to a third party? =

Your conversations go directly from your WordPress site to your configured AI provider. Nothing is routed through any intermediary server. The plugin stores conversation history, memories, and knowledge locally in your WordPress database.

= Can I use a local AI model? =

Yes. If you run a local model through Ollama or any OpenAI-compatible server, configure it as a provider through the WordPress Connectors API and Superdav AI Agent will use it. All inference happens on your hardware with zero API costs.

= What can the agent actually do? =

The agent can use any tool (ability) registered on your WordPress site. Out of the box this includes managing posts, pages, users, comments, media, site options, and more. With custom tools you can extend it to call external APIs, trigger WordPress hooks, or run WP-CLI commands. Any plugin that registers abilities through the WordPress Abilities API automatically makes those tools available to the agent.

= Is it safe? Will the AI break my site? =

The agent has a built-in confirmation system. Potentially destructive tool calls pause and ask for your approval before executing. You can configure each tool as "auto" (always allow), "confirm" (ask first), or "disabled" in the Abilities tab. Tool profiles let you restrict the agent to read-only access.

= Can I use this on a multisite network? =

Yes, the plugin works on both single-site and multisite WordPress installations. Each site has its own settings, sessions, memories, and automations.

== Screenshots ==

1. Full-page chat interface with session sidebar and folder organization
2. Floating widget available on every admin page
3. Custom Tools tab — create HTTP, ACTION, and CLI tools without code
4. Tool Profiles — restrict what the agent can access
5. Scheduled Automations with quick-start templates
6. Event-Driven Automations with WordPress and WooCommerce triggers
7. Knowledge Base management for RAG
8. Settings page with 12 configuration tabs

== Changelog ==

= 1.16.1 - Released on 2026-05-20 =
* Fix: Theme Builder onboarding chat is scrollable again on sites with existing content. The wrapper that mounts ChatRedesign now participates in the WordPress admin flex chain, so the message list scrolls internally instead of pushing the chat off the viewport (#1578, reverts #1577)
* Change: Removed the dedicated Theme Builder photo-upload tile, the `/sd-ai-agent/v1/onboarding/interview-uploads` REST endpoint, and the `sd-ai-agent/list-interview-uploads` ability. The chat input area already supports drag-and-drop, paste, and the paperclip button for the same image types (plus PDFs, CSV, and TXT), so the Theme Builder agent now asks the user to attach photos directly in the conversation instead of through a separate panel

= 1.16.0 - Released on 2026-05-20 =
* New: Theme Builder can generate and embed a logo SVG with namespace-safe sanitisation (generate-logo-svg ability)
* New: Theme Builder discovery interview now includes a photo upload step for richer design context
* New: validate-palette-contrast ability checks colour pairs for WCAG compliance before applying to a theme
* New: Theme Builder generates structured hospitality menu pages for food and beverage sites
* New: Desktop and mobile preview rendering during Theme Builder design-direction selection
* New: Create Menu ability supports a distinct navigation_label separate from the page title
* Improved: sd-ai-agent/site-scrape is now a Tier 1 tool, making it available to Theme Builder pre-fill by default
* Fix: AI Client cache is now backed by transients for cross-request persistence, preventing data loss on long-running agent tasks
* Fix: Plugin row action links corrected and renamed for clarity

= 1.15.0 - Released on 2026-05-19 =
* New: sd-ai-agent/site-scrape ability scrapes a live URL and returns structured content for Theme Builder pre-fill
* New: Theme Builder stock-image search and import — agents can search for and import stock images directly from within the Theme Builder flow
* New: generate-image ability adds Theme Builder-specific options, image variation generation, and provenance metadata for generated assets

= 1.14.0 - Released on 2026-05-19 =
* New: Settings → Advanced panel now includes a "Restart Setup Assistant" button so users can re-run onboarding at any time
* Improved: Onboarding v2 cleanup — wizard removed, new installs route directly to the Setup Assistant agent
* Fix: Theme Builder now always generates theme.json with schema version 3; server-side guardrail coerces any version < 3 supplied by the model to 3
* Fix: Activate Theme ability validates WordPress and PHP version requirements before switching, preventing incompatible theme activation
* Fix: Zombie active-job rows are cleared when an AgentLoop request terminates abnormally, unblocking subsequent agent runs
* Fix: Default model validated against registered providers on save, preventing a fatal during model selection after a provider is removed
* Fix: Edit & Resend always dispatches on Send click; second-click-ignored regression is resolved
* Fix: HTTP trace cache round-trip preserves tool_use.input as a JSON object (no double-encoding)
* Fix: Feedback-triage routine (r020) SOP and API schema aligned; systemd launcher wired
* Fix: Reasoning-model capability check uses resolved model ID, fixing false negatives for aliased models
* Fix: Trace LIFO stack maintained correctly across concurrent requests

= 1.13.0 - Released on 2026-05-16 =
* New: Per-model output token limits are now read directly from the provider's /models endpoint, keeping limits accurate without manual updates
* New: Provider-supplied usage instructions (meta.ai.usage_instructions) are wired into Tier-1 and Tier-2 model context for more guideline-compliant responses
* New: Model preamble text streams live alongside tool calls so you see real-time feedback as the agent works through multi-step tasks
* New: Structured trace channel uses WordPress AI Client SDK before/after generate events for reliable, SDK-aligned LLM request tracing
* Improved: Provider list is now single-sourced from the WP AI Client SDK registry, removing a redundant internal list
* Fix: Trace logger correctly reflects the SDK DTO API, resolving broken trace output
* Fix: Agent loop now correctly detects preamble-only truncations; synthetic model capabilities prevent false truncation detection
* Fix: Tracing now captures all active LLM providers, not just a hardcoded subset
* Fix: WP_Ability polyfill now loads after WordPress core bootstrap, fixing activation errors on some environments
* Fix: WP_Ability class declaration is guarded to prevent fatal errors when the class is already defined
* Fix: Temperature parameter is no longer sent to OpenAI reasoning models (o-series), preventing API errors on those endpoints
* Fix: .eslintignore is no longer included in the WordPress.org plugin zip

= 1.12.0 - Released on 2026-05-15 =
* New: Scaffold and activate block themes directly from the chat interface — agents can generate a complete block theme and switch it live
* New: Theme Builder onboarding flow — a guided setup wizard builds a custom block theme through the Setup Assistant agent
* New: Site Specification skill — captures site goals, audience, and brand brief in a structured site_brief memory category for consistent agent context across sessions
* New: Design System Aesthetics skill — guides agents through typography, colour, spacing, and motion token decisions for a cohesive visual identity
* New: Google Gemini prompt caching via the cachedContents API for explicit cache lifecycle management; prompt-cache telemetry extended to Azure OpenAI, OpenRouter, and Vertex Anthropic
* New: Ability visibility controls — each ability now carries a visibility level governing which surfaces (REST, MCP, WooCommerce) expose it, with a partner allow-list for fine-grained access
* New: Admin notice alerts administrators when unrecognised third-party abilities are installed so they can review and classify them
* Improved: Third-party ability mode now defaults to auto, enabling native WP Abilities API integration on WordPress 7.0+ without manual configuration
* Improved: Site Builder mode removed — first-run onboarding is consolidated into the Setup Assistant agent for a simpler experience
* Improved: /providers endpoint always returns a fresh list — no more stale results when new provider plugins are activated or deactivated
* Improved: Per-model output-token limits are now aligned with provider documentation for accurate cost and budget management
* Improved: WooCommerce ability auto-enable is now aligned with the native Abilities API, reducing custom compatibility code
* Fix: Agent loop unblocked for complex multi-tool conversations — four loop-termination bugs fixed alongside per-model max_tokens and stacked-context corrections
* Fix: max_tokens and temperature values now reach the AI provider correctly instead of being silently dropped
* Fix: max_iterations from the plugin Settings page is now respected; the REST API no longer overrides it with a default of 10
* Fix: WP-CLI `wp sd-ai-agent prompt "text"` command now works correctly
* Fix: OpenAI-compatible connector now receives provider_id when listing models, fixing discovery for custom-endpoint providers
* Fix: Transient provider failures are retried automatically; truncated tool calls are handled gracefully
* Fix: create-post ability now returns real permalinks from the WordPress response in the agent reply
* Fix: Admin asset URLs resolve correctly when the plugin directory is a symlink
* Fix: Client ability callbacks are registered locally even on sites where the native WP 7.0 Abilities API is absent
* Fix: Empty JSON-Schema objects are preserved through the prompt-cache decoder instead of being stripped
* Fix: cache_creation_tokens columns added to the provider_trace table via dbDelta migration
* Fix: Permission callbacks tightened on block-discovery and client-stub abilities (security hardening)
* Fix: Feedback session credentials are scrubbed before storage
* Fix: Stock image downloads now retry across all configured free image sources
* Fix: GeminiCacheManager uses exact prefix matching instead of a character mask, preventing unintended cache key collisions
* Fix: Connectors admin page restored on WordPress 6.9 + Gutenberg installations
* Fix: Plugin paths now use WP_PLUGIN_DIR and uploads basedir per WordPress.org guidelines
* Fix: Provider list updates immediately when an API key is saved for the first time
* Docs: Disclose the optional, opt-in feedback / issue-reporting service hosted at ultimateagentwp.ai in the External Services section (what is sent, when, and links to Terms and Privacy)
* Docs: Replace the outdated Openverse Terms link (openverse.org/terms now redirects with a 4xx) with the current docs.openverse.org/terms_of_service.html URL
* Cleanup: Remove `api.example.com` placeholder example text from a php-ai-client SDK docblock and the Custom Tools "URL" input placeholder so static reviewers no longer flag a non-existent external service
* CLI: `wp sd-ai-agent benchmark run` now writes log files to `{uploads}/sd-ai-agent/benchmark-logs/` by default instead of inside the plugin folder, so logs survive plugin upgrades; `--log-dir=<abs-path>` still overrides
* Deps: Update bundled libraries to current stable releases — automattic/jetpack-autoloader 5.0.16 → 5.0.17, psr/simple-cache 2.0.0 → 3.0.0, smalot/pdfparser 2.12.4 → 2.12.5, x-wp/di pulled to current fork HEAD (REST-context plain-permalink fix + rest() request-handling refinement, on top of upstream v1.10.0)

= 1.11.1 - Released on 2026-05-09 =
* Docs: Fix invalid Google AI privacy URL in readme (trailing-slash 404) and remove non-existent Lorem Flickr terms link
* Docs: Disclose all third-party / external services in the External Services section per WordPress.org plugin directory guidelines — Google Analytics, Tavily, Brave Search, DuckDuckGo, Openverse, Pixabay, and Slack now documented alongside the existing OpenAI, Anthropic, Google AI, Lorem Flickr, Picsum Photos, and Discord entries

= 1.11.0 - Released on 2026-05-09 =
* New: Generic chat-banner and chat-error-message extension points for third-party UI integrations
* New: AgentEventLog for network-wide review of agent failures
* Fix: Break /chat/tool-result 409 loop on WP_Error and stale paused_state
* Fix: Resolve AI provider dynamically instead of using cached references
* Fix: Correct stale CSS prefix in disabled-card E2E assertions
* Docs: Add canonical naming rules to prevent rogue renames

= 1.10.0 - Released on 2026-05-05 =
* New: Tavily internet search — add Tavily as a search provider for richer internet search results alongside Brave Search
* New: Theme-aware built-in skills — Block Themes, Classic Themes, Kadence Blocks, and Kadence Theme skill guides now ship with the plugin
* New: Site builder contact form ability — add a contact form to any page directly from the chat interface
* Improved: WooCommerce integration refactored to use native WooCommerce APIs for better reliability and compatibility
* Improved: Provider list refreshes automatically when any plugin is activated or deactivated
* Fix: navigate-to ability caused an infinite reload loop on some admin pages
* Fix: list-posts now correctly resolves category and tag names to slugs
* Fix: WP-CLI commands (wp sd-ai-agent) were missing namespace aliases after a previous refactor
* Fix: Event automation gracefully handles sites where automation tables are not yet created
* Fix: memory-save ability now uses the correct namespace prefix in the system instruction builder
* Fix: Scalar tool results are now correctly wrapped before being returned to the AI
* Fix: Usage statistics now correctly handle the legacy ability key format on upgrade from older versions

= 1.9.1 - Released on 2026-04-28 =
* Fix: update-post now includes post_type in its schema and response, preventing agents from calling create-post when the intent is to update an existing post
* Fix: Retry client-side tool result submission on transient POST failures; show a recovery card with Retry/Cancel options if all attempts fail
* Fix: Stop 409 polling loop — job transient now updated after client tool results are processed, preventing browsers from re-executing tools and re-posting results

= 1.9.0 - Released on 2026-04-28 =
* New: Add create-contact-form ability
* New: Add set-featured-image ability
* New: Add batch-create-posts ability
* New: Add page_template parameter to create-post and update-post abilities
* New: Add client-side screenshot abilities for visual page review
* New: Five built-in agents with per-agent tools, prompts, and suggestions
* New: Feature flags for access control and branding settings
* New: Restore last session on chat load and widget open
* New: Add plugin action links on plugins.php admin page
* Improved: Retry all free image sources on download failure before AI fallback
* Improved: Always show model info panel
* Improved: Stop auto-scroll when user reads; show scroll-to-bottom button
* Improved: Agent picker with icons and form-table layout
* Improved: Lazy-load JS chunks — cut initial bundle sizes 75-90%
* Improved: Redesign chat widget with unified AI icon
* Improved: Linkify URLs in system and error message bubbles
* Fix: Fix ability discoverability — descriptions, system prompt, and namespace alignment
* Fix: Make providers cache site-wide via version counter
* Fix: Resolve ability_invalid_output across 12 ability handlers
* Fix: Wire up pending_client_tool_calls pipeline end-to-end
* Fix: Exclude non-revertable changes from history drawer; fix View full history link
* Fix: Five bugs in the changes/revert system and wire into unified admin
* Fix: Show snackbar toast after Save Settings click
* Fix: Add Delete Permanently option to Trash context menu
* Fix: Edit & resend enters only the clicked message's edit mode
* Fix: Adapt chat layout height to plugin-injected content above page

= 1.8.2 - Released on 2026-04-23 =
* Fix: Replace polyfill connectors page with official URL and one-click Gutenberg install
* Fix: Add menu icon back
* Fix: Use correct URL

= 1.8.1 - Released on 2026-04-22 =
* Fix: Connectors page showing WP 7.0 redirect on WP 6.9
* Fix: Connectors page links for WP 6.9 compat

= 1.8.0 - Released on 2026-04-22 =
* New: Split image abilities — extract GenerateImageAbility, rename Stock/Unified abilities
* New: WP 6.9 compatibility — bundle php-ai-client SDK, Connectors polyfill
* New: Provider refresh on tab visibility change and manual refresh button
* New: Connectors admin page with install/activate/API key UI
* Improved: Bootstrap-start idempotency — persist session ID, dual-store completion
* Improved: Provider-selector refresh button — always show icon, fix accessibility
* Improved: Ability-call error handling — return WP_Error for malformed arguments
* Fix: Load provider credentials in BenchmarkRunner before AI calls
* Fix: Hide ChatTabBar in compact mode to remove duplicate tabs
* Fix: Allow manual feedback form submissions regardless of setting

= 1.7.0 - Released on 2026-04-20 =
* New: Adaptive skill system — usage tracking, model-aware injection, and remote skill registry
* New: Onboarding v2 — connector gate and AI-driven discovery session
* New: Skill manager UI — usage stats, update badges, and auto-update toggle
* New: Skill versioning with remote update checker
* New: Active jobs database table for resumable background jobs
* New: Multi-session chat UI with tabbed interface
* New: Session-scoped polling with exponential backoff and visibility throttling
* New: Cross-page navigation survival via sessionStorage
* New: In-session rollback bar for conversation undo
* New: Browser notifications for permission prompts
* New: Dynamic context windows from provider API response headers
* New: Auto-enable WooCommerce abilities on activation
* New: Bootstrap system prompt with auto-discovery session context
* Improved: Skill usage outcome heuristics and update methods
* Improved: Block editor content quality handling
* Improved: Active job reconnection via REST endpoint
* Improved: Active job lifecycle persisted to database alongside transients
* Fix: Orphaned tool_use blocks stripped to prevent Anthropic 400 errors
* Fix: REQUEST_URI normalization for plain-permalink REST detection
* Fix: Various database repository return type consistency fixes

= 1.6.0 - Released on 2026-04-17 =
* New: Tool call details and skill activations displayed inline in chat messages
* New: Always-on message input with message queue and agent interrupt support
* Improved: Complete dependency injection migration — all wiring through x-wp/di container
* Improved: Database god class split into focused domain repositories
* Improved: AgentLoop refactored into focused, testable subclasses
* Improved: Typed DTOs for database rows eliminate mixed-type casting
* Improved: REST controller domain logic extracted into service classes
* Improved: Interfaces added for key contracts (repositories, settings, budget)
* Improved: Settings converted to injectable DI service
* Improved: Plugin Builder abilities extracted into individual PSR-4 files
* Fix: Ability error messages now preserve exception file, line, and trace details
* Fix: Infinite API error loop replaced with friendly boot error and nonce refresh
* Fix: stdClass handling in ability arguments and JSON schema empty-object serialization
* Fix: Admin notice shown instead of fatal error when vendor directory is missing
* Fix: Concurrent REST fetches for providers, settings, and abilities deduplicated
* Fix: Tool confirmation dialog styles missing in screen-meta view

= 1.5.0 - Released on 2026-04-15 =
* New: Customer feedback & issue reporting system — thumbs-down button, feedback consent UI, auto-prompt banner on conversation end, /report-issue command, and AI-assisted triage
* New: Report-inability ability — agent self-flags when it cannot complete a task
* New: Plugin Builder & Sandbox System — AI-powered plugin generation with safe activation, structured codegen, and sandboxed live updates
* New: Plugin installer with path validation, multi-file install, update, and delete by slug
* New: HookScanner for automated plugin and theme hook analysis
* New: 7 plugin management abilities and ecosystem registry
* New: Async job architecture with live tool progress tracking
* New: Internet search ability via Brave Search API
* Improved: GitHub Actions upgraded from Node.js 20 to Node.js 24
* Improved: AI Client SDK timeout raised to 120s for agentic workloads
* Improved: HookScanner skips vendor and node_modules directories
* Fix: E2E test selector mismatches for settings tabs
* Fix: Vendor autoloader manifests regenerated without dev dependencies
* Fix: Dialog styles included in floating-widget bundle
* Fix: Brave Search API URL now a clickable link in settings
* Fix: Transient TTL refresh prevents mid-execution expiry
* Fix: wp-env port conflict resolved (ports 8890/8893)
* Fix: Tool confirmation dialog portal fixes for compact and floating modes
* Fix: HookScanner empty-slug guard and slug sanitization
* Fix: Missing AgentLoop import in feedback handler

= 1.4.0 - Released on 2026-04-09 =
* New: Agent Capabilities v1 benchmark suite for complex model evaluation
* New: WP-CLI benchmark command for running benchmarks from the command line
* New: Custom post type abilities — register, list, and delete custom post types with persistence
* New: Custom taxonomy abilities — register, list, and delete custom taxonomies with persistence
* New: Design system abilities — inject custom CSS, curated block patterns, set site logo, theme.json presets
* New: Global styles abilities for theme.json management
* New: Navigation menu management ability
* New: Options management ability with safety blocklist
* New: Installable abilities registry and recommend-plugin ability
* New: Site builder orchestration v2 — plan generation, plugin discovery, progress tracking, error recovery
* New: Restaurant website benchmark question and E2E tests
* New: AI provider connector plugins added to WordPress Playground blueprints
* Improved: README updated with AI provider connector documentation
* Fix: 25 PHPUnit test failures on main resolved
* Fix: GitHub releases URL format in blueprint.json
* Fix: Task ID renumbering to avoid collisions with old IDs

= 1.3.0 - Released on 2026-04-03 =
* New: Unified admin menu — consolidates 4 separate admin_menu hooks into a single React SPA with hash-based routing
* New: Model benchmark admin page with REST controller and benchmark engine for comparing AI provider performance
* New: Gemini 2.5 Flash and Gemini 2.5 Flash Lite models added to the model selector
* New: o3, o4-mini, claude-sonnet-4-6, and claude-opus-4-6 models added to the model selector
* New: Claude 3.5 Haiku and Gemini 2.0 Flash models added to the model selector
* New: JS bundle size budget enforced in CI to prevent regressions
* New: PHPStan raised to level 10 (maximum) with all new errors resolved
* New: Unit tests for 50+ classes across Core, REST, Abilities, Models, Admin, Knowledge, and Automations
* New: Playwright E2E tests for shared conversations, agent builder, automations, Changes page, UnifiedAdminMenu, and benchmark page
* Enhancement: WP.org SVN submission guide and deploy script
* Enhancement: GPL-2.0-or-later license headers added to all PHP files
* Enhancement: Security hardening, i18n compliance, and code safety improvements
* Fix: tokens_used_this_month clamped to non-negative before database insert
* Fix: get_option() result guarded before array offset access in SiteScanner
* Fix: get-plugins truncator field mismatch (status/slug to active/file)
* Fix: google/ OpenRouter prefix removed from Google direct provider model IDs
* Fix: update-post benchmark schema aligned with implementation
* Fix: Collapsed sections now force-open when filtering activates in abilities manager
* Fix: package_type schema/runtime inconsistency in GitAbilities
* Fix: ShellCheck violations resolved in tests/ and .husky/
* Fix: 96 failing Playwright E2E tests after UnifiedAdminMenu merge

= 1.2.0 =
* New: Support all three official AI providers — OpenAI, Anthropic, and Google Gemini
* New: Image and file upload support in chat messages
* New: Spending limits and budget caps to control AI costs
* New: Live streaming token counter and cost display during responses
* New: Tiered model pricing display in the Settings model selector
* New: GPT-4.1 family models (GPT-4.1, GPT-4.1-mini, GPT-4.1-nano); GPT-4.1-nano is now the default OpenAI model
* New: Graceful fallback when tool calls exhaust max iterations
* New: Suggestion cards on chat empty state
* New: Search, category grouping, and collapsible sections in the Abilities settings tab
* New: Auto-title sessions from the first message using AI
* New: Shared conversations — multiple admins can view and continue the same session
* New: Text-to-speech for AI responses (optional)
* New: White-label branding support — custom agent name, colors, and logo
* New: Google Search Console SEO insights abilities
* New: Google Analytics 4 traffic analysis ability
* New: Resale API proxy endpoint with usage tracking
* New: YOLO mode toggle to skip all confirmations
* New: Abilities Explorer admin page
* New: AI image generation ability (DALL-E 3)
* New: Agent builder UI — create specialized agents with custom prompts, tools, and models
* New: Role-based AI permissions — restrict abilities by WordPress user role
* New: Floating widget shown on frontend for logged-in admins
* New: Resizable floating widget panel with keyboard shortcut
* New: WooCommerce abilities — product CRUD, order queries, and store stats
* New: Slack and Discord notification forwarding for automation results
* New: Sortable, filterable DataTable rendering for tabular chat responses
* New: Chart.js chart rendering in chat responses
* New: Per-tool WordPress capabilities (sd_ai_agent_tool_{name})
* New: Site builder conversation flow — interview user then generate a full site
* New: Site builder mode triggered on fresh WordPress installs
* New: Changes Admin page — view diffs, revert changes, and export patches
* New: CodeMirror 6 syntax highlighting in chat code blocks
* New: Webhook API — trigger AI conversations from external systems
* New: Push-to-talk speech-to-text via browser Web Speech API
* New: MCP server — expose abilities as MCP tools for external AI clients
* New: Mobile slide-out drawer replacing stacked sidebar layout
* New: Visible scroll affordance for settings tabs on touch devices
* Enhancement: PHPStan level raised from 6 to 7
* Enhancement: Improved multi-step agentic workflows
* Enhancement: Configurable default model (replaces hardcoded fallback)
* Enhancement: Secrets and PII redacted in change log before/after values
* Enhancement: Stream error handling, timeout, and retry
* Enhancement: Object-level permission check in post editing
* Fix: Double /wp-admin/ URL in screen meta context
* Fix: Discovery mode confusion in ToolDiscovery
* Fix: Site builder overlay restricted to main AI Agent page
* Fix: Non-static permission callback on /stream route
* Fix: WP_Error return from get_the_terms() in WooCommerceAbilities
* Fix: ShellCheck violations across all shell scripts
* Fix: npm audit vulnerabilities (26 issues: 12 moderate, 14 high)

= 1.1.0 =
* New: Gutenberg block content generation — markdown-to-blocks converter, block discovery, and structured block creation
* New: 7 block abilities — markdown-to-blocks, list-block-types, get-block-type, list-block-patterns, list-block-templates, create-block-content, parse-block-content
* New: Stock image import ability for keyword-based image imports into the media library
* New: SEO abilities — URL auditing and content SEO analysis
* New: Content analysis and performance reporting abilities
* New: Marketing abilities — URL fetching and HTTP header analysis
* New: WP-CLI command (`wp sd-ai-agent`) for running the agent from the command line
* New: Block editor context provider — reports block theme status, registered blocks, and pattern counts
* New: Content Creator tool profile — scoped set of block, media, and post management tools
* New: Gutenberg Blocks built-in skill (enabled by default)
* New: Full Site Editing built-in skill (opt-in)
* New: Weekly SEO Health Report and Monthly Content Performance Report automation templates
* Enhancement: Improved agent system prompt — action-oriented with common workflow guidance
* Enhancement: Priority tool loading — key WP-CLI tools (post/create, site/create, media/import, etc.) load directly without discovery
* Enhancement: Streamlined tool discovery prompt — less verbose, more actionable
* Enhancement: Max iterations default increased from 10 to 25
* Enhancement: Agent returns tool call log and token usage when max iterations is reached
* Enhancement: Empty input schema properties serialize as JSON objects instead of arrays for OpenAI compatibility

= 1.0.0 =
* Initial stable release
* Agentic chat with autonomous tool-calling loop
* Full-page admin panel and floating widget chat interfaces
* Session management with folders, search, export/import
* Persistent memory across sessions with auto-memory mode
* Skills system for reusable instruction guides
* Knowledge Base (RAG) with collections, document upload, and full-text search
* Custom Tools — create HTTP, ACTION, and CLI tools without code
* Tool Profiles — 6 built-in profiles to scope AI access
* Scheduled Automations with cron integration and execution logging
* Event-Driven Automations with 20+ WordPress and WooCommerce triggers
* Tool Discovery meta-tools for sites with many abilities
* Smart Conversation Trimming to prevent context overflow
* Suggestion Chips for contextual follow-ups
* Usage Dashboard with token counts and cost estimates
* Context Providers for page-aware AI responses
* WordPress 7.0 AI Client SDK integration (native core API)

== Upgrade Notice ==

= 1.12.0 =
Documentation and dependency-maintenance release addressing WordPress.org review feedback. Discloses the opt-in feedback service, fixes the Openverse Terms link, removes placeholder URLs, moves the WP-CLI benchmark log directory out of the plugin folder, and updates bundled libraries.

= 1.11.1 =
Documentation-only update: corrects two broken links in the External Services section and adds disclosures for all remaining third-party services (search providers, stock-image services, analytics, notifications) in line with WordPress.org plugin directory guidelines. No code or database changes.

= 1.6.0 =
Architecture release: dependency injection via x-wp/di, domain repositories, typed DTOs, service extraction, and new interfaces. Adds inline tool call details in chat, always-on message input with queue, and bug fixes.

= 1.5.0 =
Major feature release: adds customer feedback & issue reporting system (thumbs-down, consent UI, AI triage), Plugin Builder with sandbox activation, async job architecture, internet search ability, and 7 plugin management abilities. Database will upgrade automatically.

= 1.4.0 =
Major feature release: adds 8 new ability classes (custom post types, taxonomies, design system, global styles, navigation menus, options management, plugin recommendations), site builder orchestration v2, and Agent Capabilities benchmark suite. Database will upgrade automatically.

= 1.3.0 =
Quality and model update: PHPStan level 10, new models (o3, o4-mini, Claude Sonnet 4, Gemini 2.5 Flash), security hardening, and bug fixes. Database will upgrade automatically.

= 1.2.0 =
Major feature release: adds Google/Anthropic provider support, image uploads, spending limits, white-label branding, agent builder, role-based permissions, WooCommerce abilities, MCP server, and many more features. Database will upgrade automatically.

= 1.1.0 =
Adds Gutenberg block content generation, SEO/content/marketing abilities, WP-CLI command, and improved agent behavior. Database will upgrade automatically.

= 1.0.0 =
Initial release. Requires WordPress 7.0+ and an AI provider connector plugin.
