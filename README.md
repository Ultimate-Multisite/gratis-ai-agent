# SD AI Agent for WordPress

[![Download Plugin Now](https://img.shields.io/github/v/release/Ultimate-Multisite/superdav-ai-agent?style=for-the-badge&label=Download+Plugin+Now&color=0073aa)](https://github.com/Ultimate-Multisite/superdav-ai-agent/releases/latest/download/superdav-ai-agent.zip) &nbsp; Upload the zip to WordPress like any other plugin

[![Tests](https://github.com/Ultimate-Multisite/sd-ai-agent/actions/workflows/tests.yml/badge.svg)](https://github.com/Ultimate-Multisite/sd-ai-agent/actions/workflows/tests.yml)
[![Code Quality](https://github.com/Ultimate-Multisite/sd-ai-agent/actions/workflows/code-quality.yml/badge.svg)](https://github.com/Ultimate-Multisite/sd-ai-agent/actions/workflows/code-quality.yml)
[![PHP 8.2+](https://img.shields.io/badge/php-%3E%3D%208.2-blue.svg)](https://www.php.net/)
[![WordPress 7.0+](https://img.shields.io/badge/WordPress-%3E%3D%207.0-blue.svg)](https://wordpress.org/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Try in Playground](https://img.shields.io/badge/Try%20in-WordPress%20Playground-3858e9?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/Ultimate-Multisite/sd-ai-agent/refs/heads/main/.wordpress-org/blueprints/blueprint.json)

[Documentation](https://github.com/Ultimate-Multisite/sd-ai-agent/wiki)

A universal AI agent for WordPress. It connects to every plugin on your site through WordPress's Abilities API, giving a single AI assistant the power to manage content, products, users, SEO, analytics, media, and more — across any plugin that registers abilities. Bring your own API key, choose any AI provider, and pay only what your provider charges.

## How it works

WordPress 7.0 introduced two core APIs that make this possible:

- **AI Client SDK** — A unified interface for AI providers. Any connector plugin (OpenAI, Anthropic, Google, Ollama, etc.) registered through the Connectors API works automatically. Switch providers or models at any time.
- **Abilities API** — A standard way for plugins to register actions an AI can take. AI Agent discovers every registered ability on your site and makes it available to the AI. Install a WooCommerce extension that registers abilities and the agent can manage orders without any extra configuration.

This means the agent gets more capable as your site grows. Every plugin that registers abilities expands what the AI can do — no glue code, no per-plugin setup.

## What the agent can do out of the box

AI Agent ships with 28 built-in ability classes covering:

| Domain | Examples |
|--------|----------|
| **Content** | Create, edit, and manage posts, pages, and custom post types. Manage categories and tags. |
| **Editorial** | Review content, check readability, manage editorial workflows |
| **WooCommerce** | Manage products, orders, customers, and view sales analytics |
| **Media** | Upload, search, and manage images and files in the media library |
| **SEO** | Analyze pages, check meta tags, review structured data, find issues |
| **Site Health** | Run diagnostics, check plugin status, review security and performance |
| **Users** | List, create, and manage user accounts and roles |
| **Blocks** | Browse block types, patterns, and templates |
| **Navigation** | Manage WordPress navigation menus |
| **Site Builder** | Build and modify page layouts |
| **Analytics** | Query Google Analytics data and Google Search Console metrics |
| **Knowledge** | Search indexed content, PDFs, and external URLs (RAG) |
| **AI Images** | Generate images with AI and insert them into content |
| **Stock Images** | Search and download stock photography |
| **Database** | Run read queries against the WordPress database |
| **Files** | Read and search files on the server |
| **Marketing** | Review CDN, security headers, and performance settings |
| **Memory & Skills** | Save and recall facts across sessions; load reusable instruction guides |

Any plugin can register additional abilities through the WordPress Abilities API — the agent discovers and uses them automatically.

## Bring Your Own Key

Most WordPress AI plugins route calls through a proprietary proxy that marks up API costs. AI Agent talks directly to your provider:

- **Direct API calls** — Your site connects straight to OpenAI, Anthropic, Google, or any OpenAI-compatible endpoint. No middleman.
- **Full model choice** — GPT-4o, Claude Opus 4, Gemini, a local Ollama instance, or anything the WordPress AI Client connector supports. Switch models per session.
- **Transparent pricing** — You see exactly what you spend in your provider's dashboard. The plugin tracks token counts and estimated costs locally.
- **Privacy** — Conversations go directly to your chosen provider. Nothing routes through a third-party relay.

## AI Provider Connectors

AI Agent works with any connector plugin registered through the WordPress Connectors API. Install the ones that match your setup:

### Official WP.org connectors

| Connector | Models | Auth |
|-----------|--------|------|
| [AI Provider for OpenAI](https://wordpress.org/plugins/ai-provider-for-openai/) | GPT-4o, GPT-4.1, o3, o4-mini, and more | API key |
| [AI Provider for Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic/) | Claude Opus 4, Sonnet 4, Haiku | API key |
| [AI Provider for Google](https://wordpress.org/plugins/ai-provider-for-google/) | Gemini 2.5 Flash, Gemini 2.5 Pro | API key |

### Community connectors

| Connector | What it does |
|-----------|-------------|
| [AI Provider for Anthropic Max](https://github.com/Ultimate-Multisite/ai-provider-for-anthropic-max) | Use your Claude Max subscription instead of per-token API billing. Authenticates via OAuth tokens with automatic account pool rotation — ideal for teams or heavy usage where a flat-rate Max plan is cheaper than API credits. |
| [Compatible Endpoints](https://github.com/Ultimate-Multisite/ultimate-ai-connector-compatible-endpoints) | Connect to anything that speaks the OpenAI API format: Ollama (local models, zero cost), Azure OpenAI, Groq, Together AI, OpenRouter, and more. |
| [WebLLM](https://github.com/Ultimate-Multisite/ultimate-ai-connector-webllm) | Run models entirely in the browser using WebGPU. No API key, no server, no data leaves the machine. Good for demos and privacy-sensitive environments. |

All connectors are included in the [WordPress Playground demo](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/Ultimate-Multisite/sd-ai-agent/refs/heads/main/.wordpress-org/blueprints/blueprint.json).

## Features

### Agentic chat
- Autonomous tool-calling loop — the AI plans, executes tools, reads results, and iterates until the task is done
- Configurable max iterations (1-50) to control how many tool calls per request
- Tool confirmation — approve or reject destructive actions before they run, with "always allow" memory
- Full markdown rendering with syntax-highlighted code blocks
- Message actions: regenerate, edit and resend, delete

### Three chat interfaces
- **Full-page admin panel** (Tools > AI Agent) — two-column layout with session sidebar, folder organization, search, and filtering
- **Floating widget** — draggable button on every admin page, expandable chat panel with multi-tab sessions
- **Screen meta panel** — collapsible panel in the WordPress screen meta area (alongside Screen Options and Help)

### Custom agents
Build specialized agents with the Agent Builder:
- Custom system prompts, greeting messages, and avatar icons
- Per-agent provider and model overrides
- Per-agent temperature and max iteration settings
- Enable or disable agents; users can switch between them in chat

### Session management
- Persistent conversation history in a dedicated database table
- Organize sessions into folders, pin favorites, archive old conversations
- Search across all sessions by title or content
- Export to JSON (reimportable) or Markdown (human-readable)
- Per-session token usage tracking with cost estimates

### Memory
- Persistent knowledge base the AI retains across sessions
- Categories: site info, user preferences, technical notes, workflows, general
- Auto-memory mode — the AI proactively saves important facts it learns
- Full CRUD through the settings UI and through chat

### Skills
- Reusable instruction guides the AI can load on demand
- Define complex workflows once ("how to optimize images", "content publishing checklist") and the agent follows them when relevant
- Enable or disable per skill

### Knowledge base (RAG)
- Index WordPress posts, pages, and custom post types into searchable collections
- Upload PDF documents and external URLs
- Automatic chunking with configurable size and overlap
- Full-text search across indexed content
- Auto-index on post publish/update (optional)
- The AI searches knowledge automatically when relevant context is needed

### Custom tools
Create tools the AI can use without writing plugin code:

- **HTTP tools** — Call any external API (weather, Zapier, Slack, etc.) with `{{placeholder}}` substitution in URLs, headers, and body
- **ACTION tools** — Fire any WordPress `do_action()` hook with arguments
- **CLI tools** — Run WP-CLI commands with argument schemas

5 example tools are seeded on first activation (Weather API, Zapier Webhook, Clear Object Cache, Maintenance Mode, Site Health Check).

### Scheduled automations
Cron-based AI tasks that run unattended:

- Define a prompt, pick a schedule (hourly / twice daily / daily / weekly), and let the agent work
- 5 quick-start templates: Daily Site Health Report, Weekly Plugin Update Check, Content Moderation, Broken Link Check, Database Optimization
- Per-automation max iteration limits
- Run any automation manually with "Run Now"
- Execution logs with duration, token usage, status, and full response

### Event-driven automations
React to WordPress hooks in real time:

- 20+ pre-registered triggers across WordPress core, WooCommerce, and form plugins
- **WordPress**: post status changed, user registered, login, comment posted, plugin activated, theme switched, media uploaded, and more
- **WooCommerce**: new order, order status changed, low stock, payment complete, refund created
- Prompt templates with `{{placeholder}}` substitution from hook arguments (e.g., `{{post.title}}`, `{{user.email}}`, `{{order.total}}`)
- Conditional execution — only fire when conditions match (post type, status, role, etc.)
- Shared logging infrastructure with scheduled automations

### Tool discovery
When your site has many registered abilities (20+), the agent uses meta-tools to discover what's available instead of loading every tool definition into the system prompt:

- `list-tools` — Browse tools by category with pagination
- `execute-tool` — Run a discovered tool by name
- Configurable threshold and mode (auto / always / never)
- Reduces token usage on sites with dozens of tools

### Smart conversation trimming
Prevents context window overflow in long agentic loops:

- Counts tool-call/response cycles and trims at safe boundaries
- Never cuts mid-tool-cycle or drops the first turn
- Configurable max history turns (default: 20)
- Transparent trimming marker so the AI knows context was compressed

### Suggestion chips
After each AI response, clickable follow-up suggestions appear:

- AI generates 2-4 contextual suggestions
- One click sends the suggestion as your next message
- Configurable count (0-5)

### Access and branding
- Role-based permissions — control which WordPress roles can access the agent
- Custom branding — set a custom name, logo, and colors for the chat interface

### Usage dashboard
- Per-session and aggregate token counts (prompt + completion)
- Cost estimates based on current model pricing
- Breakdown by model and time period

### Provider trace
Debug mode that captures the raw HTTP traffic between WordPress and your AI provider. Useful for troubleshooting connection issues and inspecting the exact prompts and responses.

### Context providers
The AI automatically receives relevant context about the current page:

- Current admin page URL and title
- Site information (name, URL, WordPress version)
- Extensible — plugins can register additional context providers

## Requirements

- WordPress 7.0+
- PHP 8.2+
- An AI provider connector plugin (see [AI Provider Connectors](#ai-provider-connectors) above)
- An API key from your chosen provider (or a Claude Max subscription, or a local Ollama instance)

## Installation

1. Upload the `sd-ai-agent` folder to `/wp-content/plugins/`
2. Activate through the Plugins screen
3. Configure an AI provider in **Settings > AI Credentials** (WordPress core Connectors API)
4. Go to **Tools > AI Agent Settings** to select your default provider and model
5. Open **Tools > AI Agent** to start chatting

## Configuration

All settings live under **Tools > AI Agent Settings** with these tabs:

| Tab | What it controls |
|-----|-----------------|
| General | Default provider, model, max iterations, system prompt, greeting message |
| Memory & Knowledge | Auto-memory toggle, memory CRUD, RAG collections and sources |
| Skills | Create and manage instruction guides |
| Tools | Custom HTTP/ACTION/CLI tools, per-ability permission controls (auto / confirm / disabled) |
| Automations | Scheduled cron-based AI tasks and event-driven hook automations |
| Agents | Create and manage custom agents with their own prompts, models, and settings |
| Access & Branding | Role permissions, custom name, logo, and colors |
| Usage | Token counts and cost tracking |
| Provider Trace | Debug HTTP traffic between WordPress and AI providers |
| Advanced | Temperature, max tokens, context window, tool discovery settings |

## Architecture

```
sd-ai-agent/
├── sd-ai-agent.php             # Bootstrap, requires, hooks
├── includes/
│   ├── Abilities/                   # 28 ability classes (tools the AI can call)
│   ├── Admin/                       # Admin pages, floating widget, screen-meta panel
│   ├── Automations/                 # Scheduled + event-driven automation system
│   ├── Benchmark/                   # Model benchmark runner and suite
│   ├── CLI/                         # WP-CLI command
│   ├── Core/                        # AgentLoop, Database, Settings, CostCalculator, etc.
│   ├── Enums/                       # PHP 8.1 enums (ToolType, Schedule, etc.)
│   ├── Knowledge/                   # RAG pipeline (collections, sources, chunks)
│   ├── Models/                      # Data models (Memory, Skill, Agent, Chunker, etc.)
│   ├── REST/                        # REST API, SSE streamer, MCP server, webhooks
│   └── Tools/                       # Custom tools, tool discovery
├── src/
│   ├── admin-page/                  # Full-page chat React app
│   ├── floating-widget/             # Draggable widget React app
│   ├── screen-meta/                 # Screen meta panel React app
│   ├── benchmark-page/              # Model benchmark React app
│   ├── unified-admin/               # Unified admin menu entry point
│   ├── settings-page/               # Settings React app
│   ├── abilities-explorer/          # Abilities browser
│   ├── changes-page/                # Changelog viewer
│   ├── components/                  # Shared React components
│   ├── store/                       # Redux store (actions, selectors)
│   └── utils/                       # Keyboard shortcuts, helpers
└── build/                           # Compiled assets
```

### REST API pattern

The agent uses an **async job + polling** pattern to handle long-running inference:

1. `POST /sd-ai-agent/v1/run` — Starts a background job, returns `job_id`
2. `GET /sd-ai-agent/v1/job/{id}` — Poll until `status: completed` (or `awaiting_confirmation` for tool approval)
3. `POST /sd-ai-agent/v1/job/{id}/confirm` or `/reject` — Handle tool confirmations

This avoids HTTP timeout issues with multi-step agentic loops that can take 30+ seconds.

### Extending

**Register custom abilities** that the agent can use:

```php
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability( 'my_custom_tool', [
        'label'       => 'My Custom Tool',
        'description' => 'Does something useful',
        'category'    => 'my-plugin',
        'callback'    => 'my_tool_callback',
        'schema'      => [
            'type'       => 'object',
            'properties' => [
                'param' => [ 'type' => 'string', 'description' => 'A parameter' ],
            ],
        ],
    ] );
} );
```

The agent discovers and uses any registered ability automatically.

**Add context providers:**

```php
add_filter( 'ai_agent_context_providers', function( $providers ) {
    $providers[] = [
        'label'    => 'My Plugin Context',
        'priority' => 10,
        'callback' => function() {
            return "Current inventory count: " . get_inventory_count();
        },
    ];
    return $providers;
} );
```

## Development

```bash
# Install dependencies
npm install
composer install

# Development build with watch
npm start

# Production build
npm run build

# Run linters
composer phpcs      # PHP CodeSniffer
composer phpcbf     # Auto-fix PHPCS issues
composer phpstan    # Static analysis
composer test       # PHPUnit tests
```

### WordPress Playground testing

Test the plugin instantly in your browser without any local setup:

- **Latest release**: [Open in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/Ultimate-Multisite/sd-ai-agent/refs/heads/main/.wordpress-org/blueprints/blueprint.json) (login: `admin` / `password`)

The canonical blueprint lives at `.wordpress-org/blueprints/blueprint.json` — this is the path WordPress.org uses when displaying the plugin in the plugin directory.

## License

GPL-2.0-or-later
