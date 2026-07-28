=== SD AI Agent ===
Contributors: superdav42
Tags: ai, chatbot, assistant, automation, connector
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.20.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your AI teammate for WordPress. Fix, publish, optimise, and run your existing site from one assistant — using the AI provider you choose.

== Description ==

SD AI Agent is an AI teammate for the WordPress site you already run. Ask it to improve a page, draft content, review SEO opportunities, prepare media, answer questions with site context, or run routine admin work from inside WordPress using natural language.

You stay in control: choose the included SD AI managed service or your own compatible WordPress AI provider, decide which tools the agent can use, and require confirmation before consequential actions run.

= What it helps you do =

* **Improve content faster** — review pages and posts, find SEO opportunities, draft updates, organise categories and tags, and prepare changes for your approval.
* **Use your site context** — save preferences and site facts as memory, then search posts, pages, documents, and other indexed knowledge during a conversation.
* **Publish with fewer handoffs** — work with copy, media, products, comments, settings, and supported plugin tools without jumping between admin screens.
* **Automate routine work** — schedule prompts or respond to WordPress and WooCommerce events for reports, moderation, reminders, follow-up, and maintenance checks.
* **Review work before it changes your site** — use read-only or scoped tool profiles, disable tools you do not want available, and approve sensitive actions before they run.
* **Understand usage** — review conversation history, tool activity, exports, token usage, and estimated provider costs.

= Work where you already work =

Open the full workspace at **Tools > SD AI Agent** for longer jobs with folders, search, history, and exports. Use the compact admin widget when you want quick help while editing another WordPress screen.

= Bring context to every conversation =

SD AI Agent can remember site preferences, reuse team instructions, and search a local knowledge base built from your WordPress content and uploaded documents. That context helps the assistant produce answers and drafts that fit your site instead of starting from a blank prompt each time.

= Automate without giving up control =

Create reusable skills, custom HTTP tools, WordPress action tools, scheduled tasks, and event-driven workflows. Start with focused jobs such as a daily site-health report, a weekly content review, a new-post summary, a WooCommerce order follow-up, or a moderation check. Logs and confirmations keep the work reviewable.

= Before you start =

You need WordPress 7.0 or later and PHP 8.2 or later. Use the included SD AI managed service, or install a compatible WordPress AI provider connector. Third-party providers may charge for model usage; review their pricing and privacy terms before sending content or attachments.

== Installation ==

1. Install and activate SD AI Agent from the WordPress Plugins screen.
2. Go to **Settings > AI Credentials**. Use the included SD AI managed service, or configure a connector for your preferred AI provider with its required credentials.
3. Visit **Tools > SD AI Agent Settings** to choose your provider and model.
4. Review the available abilities and choose a tool profile that fits the access you want to grant.
5. Open **Tools > SD AI Agent** and start with a focused task, such as improving a draft or finding information from your site.

= Requirements =

* WordPress 7.0 or higher
* PHP 8.2 or higher
* The included SD AI managed service, or an AI provider connector plugin registered through the WordPress Connectors API
* Provider credentials when required by your selected service

== External Services ==

This plugin contacts outside services only when they are needed for a feature you use, except that the included SD AI managed service registers the site installation during activation. Other services are contacted when you configure the relevant key, URL, or feature; ask the agent to run an action that uses that service; or enable scheduled maintenance such as skill-manifest updates.

= SD AI managed service =

The included SD AI provider can register the site and provide AI responses when selected. Activation registration sends a durable installation ID, site URL, plugin version, and WordPress version. AI requests send the conversation messages, system prompt, attached files if any, and tool definitions needed to generate the reply.

= AI providers (chat completions) =

These are contacted only when configured in **Settings > AI Credentials** and selected for a response. Requests send the conversation messages, system prompt, attached files if any, and tool definitions to the chosen provider.

* **OpenAI** (api.openai.com) — Provides AI chat completions for OpenAI models. Terms: https://openai.com/policies/terms-of-use/ Privacy: https://openai.com/policies/privacy-policy/

* **Anthropic** (api.anthropic.com) — Provides AI chat completions for Claude models. Terms: https://www.anthropic.com/legal/consumer-terms Privacy: https://www.anthropic.com/legal/privacy

* **Google AI / Gemini** (generativelanguage.googleapis.com) — Provides AI chat completions for Gemini models. Terms: https://policies.google.com/terms Privacy: https://policies.google.com/privacy

**Other compatible providers.** If you install a separate connector, SD AI Agent uses that connector's configured endpoint and data handling. Some connectors may route requests through regional or cloud services such as Google Vertex AI (`*.aiplatform.googleapis.com`). Review the connector's own terms and privacy policy.

= Internet search providers =

Internet search runs only when you or the agent explicitly request it. The first configured provider is used. Only the search query is sent.

* **Tavily Search API** (api.tavily.com) — Web search results when a Tavily key is configured. Terms: https://tavily.com/terms Privacy: https://tavily.com/privacy

* **Brave Search API** (api.search.brave.com) — Web search results when a Brave key is configured. Terms: https://brave.com/terms-of-use/ Privacy: https://brave.com/privacy/browser/

* **DuckDuckGo Instant Answer API** (api.duckduckgo.com) — Free fallback search when no Tavily or Brave key is configured. Terms: https://duckduckgo.com/terms Privacy: https://duckduckgo.com/privacy

= Plugin and skill maintenance =

* **GitHub** (github.com, api.github.com, raw.githubusercontent.com) — Provides optional public plugin ZIPs, public skill files, and WP-CLI download instructions. Contacted only when an administrator runs the skills sync command or installs a GitHub-hosted recommendation. Sends the requested public path plus normal HTTP metadata, not site content, conversation history, API keys, or settings. Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

* **WordPress.org Plugin Directory** (api.wordpress.org and downloads.wordpress.org) — Provides plugin search, metadata, and ZIP downloads when you request a WordPress.org plugin. Sends the search keyword or slug, result count, and normal HTTP metadata. Terms / directory guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/ Privacy: https://wordpress.org/about/privacy/

* **Admin-supplied skill manifest URLs** — If you configure `skill_manifest_url`, the plugin can fetch that HTTPS JSON manifest for manual checks, lookups, or enabled auto-updates. The request sends the manifest path, JSON/cache headers, and normal HTTP metadata. Terms and privacy depend on the host you configure.

= Stock-image services =

Stock-image services run only when requested. They receive the search keyword and requested image dimensions, not site content or conversation history.

* **Openverse** (api.openverse.org) — Openly licensed image search hosted by the WordPress Foundation. No API key required. Terms: https://docs.openverse.org/terms_of_service.html Privacy: https://wordpress.org/about/privacy/

* **Pixabay** (pixabay.com) — Stock-image search when a Pixabay API key is configured. Sends the keyword, dimensions, and API key. Terms: https://pixabay.com/service/terms/ Privacy: https://pixabay.com/service/privacy/

= Analytics =

* **Google Analytics Data API** (analyticsdata.googleapis.com and oauth2.googleapis.com) — Reads your GA4 traffic, top-page, and realtime statistics when you upload a service-account key and request a report. Sends the service-account token request plus the property ID, date range, and metrics requested. Google APIs Terms: https://developers.google.com/terms Privacy: https://policies.google.com/privacy Google Analytics terms: https://marketingplatform.google.com/about/analytics/terms/us/

* **Google Search Console API** (searchconsole.googleapis.com and oauth2.googleapis.com) — Reads your Search Console query and page performance when credentials are configured and you request a report. Sends the token request or stored OAuth token plus the property URL, date range, dimensions, filters, and row limit. Google APIs Terms: https://developers.google.com/terms Privacy: https://policies.google.com/privacy

= User-configured and user-requested external URLs =

* **Custom HTTP tools and webhooks** — Administrators can create tools that call configured external URLs, including disabled examples for weather-style APIs and Zapier (`hooks.zapier.com`). Nothing is contacted unless the tool is enabled and run. Requests send the configured URL, method, headers, substituted inputs, and body. Terms and privacy depend on the configured service. Zapier Terms: https://zapier.com/tos Zapier Privacy: https://zapier.com/privacy

* **User-requested URL fetch, site scrape, and media import URLs** — URL metadata, SEO audit, scrape, upload-from-URL, and generated-image import tools contact only the URL you provide or approve. The target may receive the requested path, query string, normal HTTP metadata, and a WordPress user-agent; media import tools download the remote file into WordPress. Review the target site's terms and privacy policy before running the tool.

= Notifications (optional) =

* **Discord** (discord.com) — Optional automation-result webhooks. Sends the configured automation summary to the webhook URL only after you add one. Terms: https://discord.com/terms Privacy: https://discord.com/privacy

* **Slack** (slack.com / hooks.slack.com) — Optional automation-result webhooks. Sends the configured automation summary to the webhook URL only after you add one. Terms: https://slack.com/terms-of-service Privacy: https://slack.com/privacy-policy

= Feedback and issue reporting (optional, opt-in) =

* **SD AI Agent feedback service** (ultimateagentwp.ai) — Optional user-submitted feedback and issue reports. Nothing is sent automatically; reports are sent only after you use thumbs-down or `/report-issue` and accept the consent preview. The payload is the sanitized conversation excerpt and metadata shown in the modal. Terms: https://ultimateagentwp.ai/terms/ Privacy: https://ultimateagentwp.ai/privacy/

== Frequently Asked Questions ==

= Which AI providers are supported? =

Any provider that has a connector plugin for the WordPress AI Client SDK. This currently includes OpenAI (GPT-4o, GPT-4.1), Anthropic (Claude Opus 4, Sonnet 4, Haiku 4), and any OpenAI-compatible API (Ollama, Azure OpenAI, Groq, Together AI, etc.).

= How much does it cost to use? =

The plugin itself is free. You pay only for the API usage from your chosen provider at their published rates. There is no markup, subscription, or usage fee from SD AI Agent. The Usage tab in settings tracks your token consumption and estimated costs.

= Is my data sent to a third party? =

Your conversations go directly from your WordPress site to your configured AI provider. Nothing is routed through any intermediary server. The plugin stores conversation history, memories, and knowledge locally in your WordPress database.

= Can I use a local AI model? =

Yes. If you run a local model through Ollama or any OpenAI-compatible server, configure it as a provider through the WordPress Connectors API and SD AI Agent will use it. All inference happens on your hardware with zero API costs.

= What can the agent actually do? =

The agent can use any tool (ability) registered on your WordPress site. Out of the box this includes managing posts, pages, comments, media, site options, and more. With custom tools you can extend it to call external APIs or trigger WordPress hooks. The separate SD AI Agent Advanced companion plugin adds trusted developer tools such as WP-CLI dispatchers, filesystem mutation, raw database diagnostics, and low-level WordPress function calls. Any plugin that registers abilities through the WordPress Abilities API automatically makes those tools available to the agent.

= Is it safe? Will the AI break my site? =

The agent has a built-in confirmation system. Potentially destructive tool calls pause and ask for your approval before executing. You can configure each tool as "auto" (always allow), "confirm" (ask first), or "disabled" in the Abilities tab. Tool profiles let you restrict the agent to read-only access.

= Can I use this on a multisite network? =

Yes, the plugin works on both single-site and multisite WordPress installations. Each site has its own settings, sessions, memories, and automations.

= Why does the plugin require WordPress 7.0? =

SD AI Agent uses the native WordPress AI Client SDK and Abilities API. These APIs are available in WordPress 7.0 and later, so the plugin no longer bundles compatibility shims for older WordPress versions.

== Screenshots ==

1. Full-page workspace for conversations, session history, folders, search, and exports.
2. Tools settings for configuring custom tools and controlling agent access.
3. Provider and safety settings, including confirmation controls for consequential actions.

== Changelog ==

= 1.20.0 - Released on 2026-07-23 =
* Improved chat recovery so interrupted jobs, retries, and timeouts resume more reliably.
* Added clearer SD account and credit status plus expanded model and image options, including GPT-5.6 aliases, SD image edits, and OpenAI Responses tool search.
* Improved theme-building and design workflows with safer patterns, design-token handling, artifact review, style-variation support, and stronger block update safeguards.

= 1.19.0 - Released on 2026-07-09 =
* Added searchable documentation imports so the agent can answer from public docs and knowledge resources.
* Added public and embeddable chat options for customer-facing assistant experiences.
* Added calendar-driven SMS reminder workflows, with fixes for chat recovery, markdown rendering, knowledge counts, provider selection, and tool permissions.

= 1.18.0 - Released on 2026-06-29 =
* Added Google Calendar tools, attendee matching, and TextBee SMS support for reminder automations.
* Added approval gates and reminder records so scheduled messages can pause for review and avoid duplicates.
* Made SD setup easier and improved model selection, request timeouts, onboarding, and retry guidance.

= 1.17.0 - Released on 2026-06-11 =
* Added safer page, post, block, and file editing with previews, Apply/Reject controls, revision recovery, and rollback protection.
* Improved onboarding, Theme Builder, media helpers, stock-image imports, saved pattern insertion, and dashboard access.
* Strengthened privacy and permission protections for settings, users, uploads, WooCommerce data, internal actions, and provider handling.

= 1.16.2 - Released on 2026-05-29 =
* Clarified External Services disclosures for plugin downloads, Search Console, OAuth token exchanges, skill manifests, custom HTTP tools, webhooks, and user-requested URLs.
* Removed stale or misleading example service references so administrators can see which outside services may actually be contacted.

= 1.16.1 - Released on 2026-05-20 =
* Fixed Theme Builder chat scrolling on sites with existing content.
* Simplified Theme Builder uploads by using the normal chat attachment controls for images and documents.

= 1.16.0 - Released on 2026-05-20 =
* Added logo generation, photo-based design context, colour-contrast checks, mobile and desktop previews, and hospitality menu-page support to Theme Builder.
* Improved site prefill and long-running task reliability.

= 1.15.0 - Released on 2026-05-19 =
* Added site scraping, stock-image search, image import, generated-image variations, and provenance details for Theme Builder workflows.

= 1.14.0 - Released on 2026-05-19 =
* Added a Restart Setup Assistant option and simplified first-run onboarding.
* Improved Theme Builder safety, theme activation checks, model validation, stuck-job cleanup, and Edit & Resend reliability.

= 1.13.0 - Released on 2026-05-16 =
* Added live progress text during multi-step work and more accurate per-model output limits.
* Improved provider discovery and reliability for reasoning models and compatible provider connectors.

= 1.12.0 - Released on 2026-05-15 =
* Added guided Theme Builder onboarding, block-theme generation, reusable site briefs, and design-system guidance.
* Added ability visibility controls and clearer notices when new third-party tools are available.
* Improved complex multi-tool conversations, provider refresh, connector setup, stock-image retries, cost limits, and sensitive-data handling.

= 1.11.1 - Released on 2026-05-09 =
* Corrected External Services links and disclosures for search, image, analytics, notification, and AI services.

= 1.11.0 - Released on 2026-05-09 =
* Improved chat status messages, network-wide failure review, provider switching, and recovery from stale paused chat states.

= 1.10.0 - Released on 2026-05-05 =
* Added Tavily search, theme-aware skills, and chat-based contact form creation.
* Improved WooCommerce reliability, provider refresh, navigation, post filtering, event automation, and usage statistics.

= 1.9.1 - Released on 2026-04-28 =
* Improved post-update accuracy and added better recovery when a tool-result submission fails.

= 1.9.0 - Released on 2026-04-28 =
* Added contact-form creation, featured-image setting, bulk post creation, page templates, screenshot-based review, and five built-in agents.
* Restored recent sessions automatically, improved the chat widget and model panel, reduced initial bundle size, and refined change/revert handling.

= 1.8.2 - Released on 2026-04-23 =
* Improved connector setup links and restored the admin menu icon.

= 1.8.1 - Released on 2026-04-22 =
* Fixed connector page links for earlier WordPress setups.

= 1.8.0 - Released on 2026-04-22 =
* Added clearer AI connector setup, provider refresh controls, and improved image-tool handling.
* Improved compact chat, feedback submission, and error handling.

= 1.7.0 - Released on 2026-04-20 =
* Added adaptive skills, improved onboarding, skill management, resumable jobs, multi-session chat, rollback, and browser notifications for approval prompts.
* Improved WooCommerce setup, block-editor output quality, job reconnection, and cross-page navigation.

= 1.6.0 - Released on 2026-04-17 =
* Added inline tool details, visible skill activation, always-on message input, queued messages, and agent interrupt support.
* Improved provider/settings loading, friendly boot errors, tool confirmation styling, and chat reliability.

= 1.5.0 - Released on 2026-04-15 =
* Added opt-in customer feedback and issue reporting, agent self-reporting when it cannot complete a task, plugin-building workflows, plugin management tools, live progress, and Brave Search.
* Improved long-running tasks, settings links, floating-widget dialogs, and confirmation prompts.

= 1.4.0 - Released on 2026-04-09 =
* Added site-building tools for custom post types, taxonomies, design settings, global styles, navigation menus, site options, plugin recommendations, and guided site plans.

= 1.3.0 - Released on 2026-04-03 =
* Added a unified admin experience, provider comparison tools, and more model choices.
* Improved security, language support, token accounting, plugin listings, model IDs, and ability filtering.

= 1.2.0 =
* Added OpenAI, Anthropic, and Google Gemini support, chat uploads, spending controls, live token and cost visibility, shared sessions, text-to-speech, white-label branding, Google Search Console, Google Analytics, image generation, agent builder, role-based permissions, WooCommerce tools, notifications, charts, webhooks, and mobile improvements.
* Improved multi-step workflows, stream recovery, sensitive-data redaction, and post-edit permission checks.

= 1.1.0 =
* Added block content creation, stock-image import, SEO audits, content reports, marketing URL checks, content-focused tool profiles, built-in editor skills, and recurring SEO/content report templates.
* Improved agent guidance, tool loading, and usage visibility for longer tasks.

= 1.0.0 =
* Initial stable release with AI chat, site tools, memory, knowledge search, custom tools, scoped tool profiles, scheduled and event-driven automations, usage tracking, suggestion chips, conversation export, and WordPress admin workspaces.

== Upgrade Notice ==

= 1.20.0 =
Improves chat recovery, provider account visibility, generated-site safeguards, model options, and image workflows. Requires WordPress 7.0+ and PHP 8.2+.
