<?php

declare(strict_types=1);
/**
 * Builds the system instruction for the AI agent.
 *
 * Extracted from AgentLoop so the prompt-assembly concern — base prompt,
 * memory/skill injection, context providers, manifest, and nudges —
 * lives in one focused class.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Knowledge\Knowledge;
use SdAiAgent\Models\Memory;
use SdAiAgent\Models\Skill;
use SdAiAgent\Tools\ModelHealthTracker;
use SdAiAgent\Tools\ToolDiscovery;

class SystemInstructionBuilder {

	/**
	 * Ability names that trigger cadence-section injection.
	 *
	 * When any of these are present in the active tool list the "Working
	 * cadence" block is appended to the system prompt so the model writes
	 * one Edit/Write per turn for large files and never overwrites a
	 * freshly scaffolded style.css.
	 *
	 * @since 1.10.0
	 * @var string[]
	 */
	public const CONTENT_GENERATION_ABILITY_NAMES = array(
		'sd-ai-agent/create-post',
		'sd-ai-agent/update-post',
		'sd-ai-agent/append-post-content',
		'sd-ai-agent/batch-create-posts',
		'sd-ai-agent/scaffold-block-theme',
		'sd-ai-agent/file-write',
		'sd-ai-agent/file-edit',
	);

	/**
	 * @param string                   $model_id     Current AI model ID (for weak-model nudges).
	 * @param string                   $user_message User's message (for knowledge context RAG).
	 * @param array<int|string, mixed> $page_context Page context from the widget.
	 * @param int                      $session_id   Session ID for skill usage telemetry (0 if unknown).
	 */
	public function __construct(
		private string $model_id = '',
		private string $user_message = '',
		private array $page_context = array(),
		private int $session_id = 0,
	) {}

	/**
	 * Return the "Working cadence" section string for content-generation turns.
	 *
	 * Injected into the system prompt when any content-generation or
	 * theme-modification ability is active. Keeps turns atomic and prevents
	 * gateway timeouts on long-file writes.
	 *
	 * @since 1.10.0
	 * @return string The cadence rules string.
	 */
	public static function build_working_cadence_section(): string {
		return "## Working cadence\n\n"
			. 'One Write or Edit per turn for content >50 lines. '
			. 'Read-only inspection tools (`get-post`, `list-posts`, `get-block-type`) may be combined within a turn. '
			. "Short prose between tools — no long design-plan essays.\n\n"
			. "**Long files (style.css >200 lines, page content >300 lines): skeleton first, then fill across Edits.**\n\n"
			. '- **style.css:** skeleton = `:root { ... }` custom properties + 6–10 anchor comments '
			. '`/* === <concern> === */` (e.g. `reset`, `typography`, `hero`, `features`, `cta`, `footer`, `responsive`), '
			. '<2KB total. Fill one anchor per Edit (300–2000B each) — `oldString` is the anchor line, '
			. "`newString` is `<anchor>\\n\\n<styles>`.\n"
			. '- **Page content:** create the post empty (`wp_insert_post` with empty content), write block markup '
			. 'to a draft using anchor comments `<!-- section:hero -->`, fill one anchor per Edit, then '
			. "`wp_update_post()` with the assembled content.\n\n"
			. '**Never overwrite a freshly scaffolded `style.css`** — it contains the required theme header. '
			. 'Always Edit to append, never Write to replace.';
	}

	/**
	 * Build the system instruction, incorporating custom prompt and memories.
	 *
	 * @param array<string, mixed> $settings      Plugin settings.
	 * @param string[]             $ability_names Names of active Tier-1 abilities for this turn.
	 *                                             Used to conditionally inject the Working-cadence section.
	 * @return string
	 */
	public function build( array $settings, array $ability_names = array() ): string {
		// Use custom system prompt if set, otherwise the built-in default.
		$custom = $settings['system_prompt'] ?? '';
		$base   = ! empty( $custom ) ? $custom : self::default_system_instruction();

		// Append memory section if memories exist.
		$memory_text = Memory::get_formatted_for_prompt();
		if ( ! empty( $memory_text ) ) {
			// @phpstan-ignore-next-line
			$base .= "\n\n" . $memory_text;
		}

		// Append skill index if skills are available.
		$skill_index = Skill::get_index_for_prompt();
		if ( ! empty( $skill_index ) ) {
			// @phpstan-ignore-next-line
			$base .= "\n\n" . $skill_index;
		}

		// Model-aware tiered skill injection (Phase 2 / t217):
		//
		// Strong models (GPT-4.1, Claude Sonnet/Opus): receive only the lean
		// skill index above (~15 tok/skill) plus a targeted hint pointing at
		// relevant skills. They reliably call skill-load on demand, so injecting
		// 1 500-3 000 tokens of guide content unconditionally wastes context.
		//
		// Weak models (quantized open-weight, small-param models): auto-inject
		// the best matching skill guide (max 1) directly into the prompt. They
		// often fail to voluntarily call skill-load even when the index is
		// present, so front-loading the content is the only reliable path.
		//
		// The model_id also passes through so injections are recorded to the
		// skill_usage table for telemetry (Phase 1 / t215).
		if ( ! empty( $this->user_message ) ) {
			if ( ModelHealthTracker::is_weak( $this->model_id ) ) {
				// Weak model path: inject full skill content (max 1 guide).
				$auto_skill = SkillAutoInjector::inject_for_message( $this->user_message, $this->model_id, $this->session_id );
				if ( ! empty( $auto_skill ) ) {
					// @phpstan-ignore-next-line
					$base .= "\n\n" . $auto_skill;
				}
			} else {
				// Strong model path: add a targeted hint to guide skill-load calls.
				$hint = SkillAutoInjector::get_index_description( $this->user_message );
				if ( ! empty( $hint ) ) {
					// @phpstan-ignore-next-line
					$base .= "\n\n" . $hint;
				}
			}
		}

		// If auto-memory is enabled, tell the agent about memory abilities.
		$auto_memory = $settings['auto_memory'] ?? true;
		if ( $auto_memory ) {
			// @phpstan-ignore-next-line
			$base .= "\n\n## Memory Instructions\n"
				. "You have access to persistent memory tools. Use them proactively:\n"
				. "- Use **sd-ai-agent/memory-save** to remember important information the user tells you (preferences, site details, workflows).\n"
				. "- Use **sd-ai-agent/memory-list** to recall what you've previously stored.\n"
				. "- Use **sd-ai-agent/memory-delete** to remove outdated memories.\n"
				. "- Use **sd-ai-agent/knowledge-search** to search the knowledge base for relevant documents and information.\n"
				. 'Save memories when the user shares reusable facts, preferences, or context that would be valuable in future conversations.';
		}

		// Inject knowledge context if enabled and user message is available.
		$knowledge_enabled = $settings['knowledge_enabled'] ?? true;
		if ( $knowledge_enabled && ! empty( $this->user_message ) ) {
			$context = Knowledge::get_context_for_query( $this->user_message );
			if ( ! empty( $context ) ) {
				// @phpstan-ignore-next-line
				$base .= "\n\n## Relevant Knowledge\n"
					. "The following information was retrieved from the knowledge base and may be relevant:\n\n"
					. $context
					. "\n\nUse this information to provide accurate, contextual responses. "
					. 'Cite the source when using specific facts from the knowledge base.';
			}
		}

		// Inject structured context from providers.
		$context_data = ContextProviders::gather( $this->page_context );
		if ( ! empty( $context_data ) ) {
			$formatted_context = ContextProviders::format_for_prompt( $context_data );
			if ( ! empty( $formatted_context ) ) {
				// @phpstan-ignore-next-line
				$base .= "\n\n" . $formatted_context;
			}
		}

		// Append the Tier-2 ability manifest so the model knows what's
		// reachable via ability-search / ability-call. This is the heart of
		// the auto-discovery layer.
		$manifest = ToolDiscovery::build_manifest_section();
		if ( '' !== $manifest ) {
			// @phpstan-ignore-next-line
			$base .= "\n\n" . $manifest;
		}

		// If the configured model is known to be weak at tool use (either
		// by name heuristic or by accumulated telemetry), append explicit
		// guidance about reading schemas and not retrying with the same
		// arguments. Strong models don't get this — keeps their context lean.
		if ( ModelHealthTracker::is_weak( $this->model_id ) ) {
			// @phpstan-ignore-next-line
			$base .= "\n\n" . ModelHealthTracker::weak_model_prompt_nudge();
		}

		// Inject plugin recommendations when the setting is enabled and at least
		// one recommendation with guidance is registered. Gated so the section is
		// absent from prompts for sessions that never do page-content generation.
		$plugin_recommendations_enabled = (bool) ( $settings['plugin_recommendations_enabled'] ?? true );
		if ( $plugin_recommendations_enabled ) {
			$plugin_rec_section = PluginRecommendations::build_system_prompt_section();
			if ( '' !== $plugin_rec_section ) {
				// @phpstan-ignore-next-line
				$base .= "\n\n" . $plugin_rec_section;
			}
		}

		// Working cadence: inject one-file-per-turn rules when the active
		// tool list includes content-generation or theme-modification abilities.
		// Prevents gateway timeouts on large file writes and keeps the
		// validate → screenshot → fix feedback loop intact.
		if ( self::has_content_generation_ability( $ability_names ) ) {
			// @phpstan-ignore-next-line
			$base .= "\n\n" . self::build_working_cadence_section();
		}

		// Suggestion chips: instruct the AI to append follow-up suggestions.
		// @phpstan-ignore-next-line
		$suggestion_count = (int) ( $settings['suggestion_count'] ?? 3 );
		if ( $suggestion_count > 0 ) {
			// @phpstan-ignore-next-line
			$base .= "\n\n## Follow-up Suggestions\n"
				. sprintf(
					'After each response, include exactly %d brief follow-up suggestions the user might want to ask next. '
					. "Format them on the LAST lines of your response, one per line, each prefixed with `[suggestion]`. Example:\n"
					. "[suggestion] Show me recent posts\n"
					. "[suggestion] Check plugin updates\n"
					. "[suggestion] Optimize the database\n"
					. 'Keep suggestions relevant, actionable, and under 60 characters each. '
					. 'Do NOT include suggestions when you are asking the user a question or waiting for input.',
					$suggestion_count
				);
		}

		// @phpstan-ignore-next-line
		return $base;
	}

	/**
	 * Return true when at least one active ability triggers cadence injection.
	 *
	 * @since 1.10.0
	 *
	 * @param string[] $ability_names Names of active Tier-1 abilities for this turn.
	 * @return bool
	 */
	public static function has_content_generation_ability( array $ability_names ): bool {
		foreach ( $ability_names as $name ) {
			if ( in_array( $name, self::CONTENT_GENERATION_ABILITY_NAMES, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Internal default system instruction builder.
	 *
	 * @return string
	 */
	public static function default_system_instruction(): string {
		$wp_path  = ABSPATH;
		$site_url = get_site_url();

		return "You are a WordPress assistant that ACTS — you execute tasks immediately using your tools.\n\n"
			. "## WordPress Environment\n"
			. "- WordPress path: {$wp_path}\n"
			. "- Site URL: {$site_url}\n\n"
			. "## Core Principles\n"
			. "1. **Act, don't ask.** Execute the task right away. Don't ask \"shall I proceed?\" or request confirmation unless the task is destructive (deleting data, dropping tables).\n"
			. "2. **Generate real content.** When creating pages or posts, write substantial, realistic content (3+ paragraphs). Never use placeholder text like \"Lorem ipsum\" or \"Content goes here\".\n"
			. "3. **Use tools directly.** Call tools immediately — don't describe what you would do.\n"
			. "4. **Call all needed tools in one response.** When a task requires multiple tools (e.g. create a post AND find an image), call them all at once.\n"
			. "5. **After receiving tool results, ALWAYS provide a text response summarizing the results for the user.** Never return an empty response after tool calls.\n"
			. "6. **Only claim completion for work you actually performed.** Do not claim to have set the site title, front page, or created menus unless you have actually called the corresponding tools and received success responses.\n\n"
			. "## Content Creation (IMPORTANT)\n"
			. "To create any page or blog post, use `sd-ai-agent/create-post`.\n"
			. "To update an existing post or page, use `sd-ai-agent/update-post` (pass post_id plus the fields to change).\n"
			. "To list or search posts, use `sd-ai-agent/list-posts` (filter by post_type, status, search term, category, or tag).\n"
			. "- For pages: set `post_type` to `page`.\n"
			. "- For blog posts: set `post_type` to `post`.\n"
			. "- **Blog posts and articles**: write content in markdown (`## headings`, `**bold**`, `- lists`). Markdown is auto-converted to Gutenberg blocks.\n"
			. "- **Pages with visual layouts** (landing pages, about pages, services pages): write content as serialized Gutenberg block markup (`<!-- wp:blockname -->` HTML `<!-- /wp:blockname -->`). Use columns, groups, covers, and buttons for professional layouts. A skill guide with complete block markup examples will be auto-loaded when relevant.\n"
			. "- **NEVER mix markdown with block markup** in the same content — use one or the other.\n"
			. "- Set `status` to `publish` to make it live, or `draft` to save without publishing.\n"
			. "- Include `categories` and `tags` arrays for blog posts.\n"
			. "- Include `excerpt` for SEO meta descriptions.\n"
			. "- To add a featured image: first call `sd-ai-agent/stock-image` or `sd-ai-agent/generate-image`, then pass the returned attachment_id as `featured_image_id` in create-post or update-post.\n"
			. "- For WooCommerce products, use WooCommerce's native `woocommerce/products-create` ability instead.\n\n"
			. "## Site Configuration (IMPORTANT)\n"
			. "When building a website or configuring site settings:\n"
			. "- **To set the site title (name):** Use `sd-ai-agent/update-option` with option_name=\"blogname\" and the desired site name.\n"
			. "- **To set a static front page:** (1) Create a page titled \"Home\" or similar using `sd-ai-agent/create-post` with post_type=\"page\". (2) Get its post ID from the response. (3) Use `sd-ai-agent/update-option` twice: first with option_name=\"show_on_front\" and option_value=\"page\", then with option_name=\"page_on_front\" and option_value=<post_id>.\n"
			. "- **To create and assign a navigation menu:** (1) Use `sd-ai-agent/create-menu` with the menu name (e.g. \"Main Menu\"). (2) Add menu items using `sd-ai-agent/add-menu-item` for each page/link. (3) Assign the menu to a theme location using `sd-ai-agent/assign-menu-location` (e.g. location=\"primary\" or \"header\").\n"
			. "- Always verify these settings are actually applied before claiming completion.\n\n"
			. "## Tips\n"
			. "- Chain operations: create content first, then configure settings.\n"
			. "- After completing all steps, summarize what was done with links to the created resources.\n\n"
			. "## Error Handling\n"
			. "- If a tool call fails, try a different approach or skip it and continue with the next step.\n"
			. "- Never stop after a single error — complete as many steps as possible.\n"
			. "- If you've retried the same tool 2 times with similar args, move on.\n\n"
			. "## Reporting Inability\n"
			. "- If you have genuinely tried and cannot complete the user's request, call `sd-ai-agent/report-inability` with a clear reason and the steps you attempted.\n"
			. "- Use this only as a last resort — after at least 2 different approaches have failed.\n"
			. '- Always provide a helpful text response explaining what you tried before calling the ability.';
	}
}
