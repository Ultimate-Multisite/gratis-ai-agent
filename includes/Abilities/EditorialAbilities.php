<?php

declare(strict_types=1);
/**
 * Editorial AI abilities for the AI agent.
 *
 * Provides AI-powered content generation abilities ported from the
 * WordPress/ai experiments plugin (https://github.com/WordPress/ai):
 *
 *  - sd-ai-agent/generate-title      — Generate title suggestions for a post or content.
 *  - sd-ai-agent/generate-excerpt    — Generate an excerpt for a post or content.
 *  - sd-ai-agent/summarize-content   — Summarize content at short/medium/long length.
 *  - sd-ai-agent/review-block        — Review a Gutenberg block for accessibility,
 *                                   readability, grammar, and SEO issues.
 *
 * @package SdAiAgent
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all editorial AI abilities.
 *
 * @since 1.1.0
 */
class EditorialAbilities {

	/**
	 * Register all editorial abilities.
	 *
	 * @since 1.1.0
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_generate_title();
		self::register_generate_excerpt();
		self::register_summarize_content();
		self::register_review_block();
	}

	// ─── Title Generation ────────────────────────────────────────────────────

	/**
	 * Register the generate-title ability.
	 *
	 * @since 1.1.0
	 */
	private static function register_generate_title(): void {
		wp_register_ability(
			'sd-ai-agent/generate-title',
			[
				'label'               => __( 'Generate Title Suggestions', 'superdav-ai-agent' ),
				'description'         => __( 'Generate SEO-optimised title suggestions for a post or arbitrary content. Accepts a post ID or raw content string.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'content'    => [
							'type'        => 'string',
							'description' => __( 'Content to generate title suggestions for.', 'superdav-ai-agent' ),
						],
						'post_id'    => [
							'type'        => 'integer',
							'description' => __( 'Post ID whose content will be used. Overrides the content parameter when both are provided.', 'superdav-ai-agent' ),
						],
						'candidates' => [
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 10,
							'default'     => 3,
							'description' => __( 'Number of title suggestions to generate (1–10, default 3).', 'superdav-ai-agent' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'titles' => [
							'type'        => 'array',
							'description' => __( 'Generated title suggestions.', 'superdav-ai-agent' ),
							'items'       => [ 'type' => 'string' ],
						],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_generate_title' ],
				'permission_callback' => [ __CLASS__, 'permission_edit_posts' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Execute the generate-title ability.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array{titles: list<string>}|\WP_Error
	 */
	public static function handle_generate_title( array $input ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'ai_client_unavailable', __( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' ) );
		}

		// @phpstan-ignore-next-line
		$candidates = max( 1, min( 10, (int) ( $input['candidates'] ?? 3 ) ) );

		// Build context string from post or raw content.
		$context = self::build_text_context( $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( empty( $context ) ) {
			return new WP_Error( 'content_not_provided', __( 'Content is required to generate title suggestions.', 'superdav-ai-agent' ) );
		}

		$system_instruction = <<<'INSTRUCTION'
You are an editorial assistant that generates title suggestions for online articles and pages.

Goal: You will be provided with some context and you should then generate a concise, engaging, and accurate title that reflects that context. This title should be optimised for clarity, engagement, and SEO — while maintaining an appropriate tone for the author's intent and audience.

The title suggestion should follow these requirements:

- Be no more than 80 characters
- Should not contain any markdown, bullets, numbering, or formatting — plain text only
- Should be distinct in tone and focus
- Must reflect the actual content and context, not generic clickbait

The context you will be provided is delimited by triple quotes.
INSTRUCTION;

		$builder = wp_ai_client_prompt( '"""' . $context . '"""' )
			->using_system_instruction( $system_instruction )
			->using_temperature( 0.7 )
			->using_candidate_count( $candidates );

		$model = self::get_configured_model();
		if ( ! empty( $model ) ) {
			$builder = $builder->using_model_preference( $model );
		}

		$result = $builder->generate_texts();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error( 'no_results', __( 'No title suggestions were generated.', 'superdav-ai-agent' ) );
		}

		return [
			'titles' => array_values(
				array_map(
					static fn( string $t ) => sanitize_text_field( trim( $t, ' "\'' ) ),
					$result
				)
			),
		];
	}

	// ─── Excerpt Generation ──────────────────────────────────────────────────

	/**
	 * Register the generate-excerpt ability.
	 *
	 * @since 1.1.0
	 */
	private static function register_generate_excerpt(): void {
		wp_register_ability(
			'sd-ai-agent/generate-excerpt',
			[
				'label'               => __( 'Generate Excerpt', 'superdav-ai-agent' ),
				'description'         => __( 'Generate a concise, SEO-friendly excerpt (~55 words) for a post or arbitrary content.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'content' => [
							'type'        => 'string',
							'description' => __( 'Content to generate an excerpt for.', 'superdav-ai-agent' ),
						],
						'context' => [
							'type'        => 'string',
							'description' => __( 'Additional context or a post ID to enrich the excerpt generation.', 'superdav-ai-agent' ),
						],
					],
				],
				'output_schema'       => [
					'type'        => 'string',
					'description' => __( 'Generated excerpt (plain text, ~55 words).', 'superdav-ai-agent' ),
				],
				'execute_callback'    => [ __CLASS__, 'handle_generate_excerpt' ],
				'permission_callback' => [ __CLASS__, 'permission_edit_posts' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Execute the generate-excerpt ability.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return string|\WP_Error
	 */
	public static function handle_generate_excerpt( array $input ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'ai_client_unavailable', __( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' ) );
		}

		// @phpstan-ignore-next-line
		$content = self::resolve_content( $input['content'] ?? '', $input['context'] ?? '' );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		if ( empty( $content['text'] ) ) {
			return new WP_Error( 'content_not_provided', __( 'Content is required to generate an excerpt.', 'superdav-ai-agent' ) );
		}

		$system_instruction = <<<'INSTRUCTION'
You are an editorial assistant that generates excerpts for online articles and pages.

An excerpt is a brief summary or preview of the full content, typically displayed in archive pages, RSS feeds, search results, and social media previews. It gives readers a quick overview of what the article covers without requiring them to read the full post.

Goal: You will be provided with content and optionally some additional context and you should then generate a concise, engaging, and accurate excerpt that reflects that content and keeps in mind the context. This excerpt should be optimised for clarity, engagement, and SEO — suitable for archive views, RSS feeds, and search results — while maintaining an appropriate tone for the author's intent and audience.

The excerpt suggestion should follow these requirements:

- Be approximately 55 words. If the content is shorter, adjust accordingly while maintaining completeness.
- Should not contain any markdown, bullets, numbering, or formatting — plain text only
- Should be a complete, coherent summary that captures the main points and key information from the content
- Must reflect the actual content and context accurately, not generic summaries or clickbait
- Should be self-contained and readable on its own, providing enough context for readers to understand the topic without reading the full article
INSTRUCTION;

		$prompt = '<content>' . $content['text'] . '</content>';
		if ( ! empty( $content['context'] ) ) {
			$prompt .= "\n\n<additional-context>" . $content['context'] . '</additional-context>';
		}

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system_instruction )
			->using_temperature( 0.7 );

		$model = self::get_configured_model();
		if ( ! empty( $model ) ) {
			$builder = $builder->using_model_preference( $model );
		}

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error( 'no_results', __( 'No excerpt was generated.', 'superdav-ai-agent' ) );
		}

		return sanitize_textarea_field( trim( $result, ' "\'' ) );
	}

	// ─── Summarization ───────────────────────────────────────────────────────

	/**
	 * Register the summarize-content ability.
	 *
	 * @since 1.1.0
	 */
	private static function register_summarize_content(): void {
		wp_register_ability(
			'sd-ai-agent/summarize-content',
			[
				'label'               => __( 'Summarize Content', 'superdav-ai-agent' ),
				'description'         => __( 'Generate a factual, neutral summary of a post or content at short (1 sentence), medium (2–3 sentences), or long (4–6 sentences) length.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'content' => [
							'type'        => 'string',
							'description' => __( 'Content to summarize.', 'superdav-ai-agent' ),
						],
						'context' => [
							'type'        => 'string',
							'description' => __( 'Additional context or a post ID to enrich the summary.', 'superdav-ai-agent' ),
						],
						'length'  => [
							'type'        => 'string',
							'enum'        => [ 'short', 'medium', 'long' ],
							'default'     => 'medium',
							'description' => __( 'Desired summary length: short (1 sentence), medium (2–3 sentences), long (4–6 sentences).', 'superdav-ai-agent' ),
						],
					],
				],
				'output_schema'       => [
					'type'        => 'string',
					'description' => __( 'Generated summary (plain text).', 'superdav-ai-agent' ),
				],
				'execute_callback'    => [ __CLASS__, 'handle_summarize_content' ],
				'permission_callback' => [ __CLASS__, 'permission_edit_posts' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Execute the summarize-content ability.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return string|\WP_Error
	 */
	public static function handle_summarize_content( array $input ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'ai_client_unavailable', __( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' ) );
		}

		$length = in_array( $input['length'] ?? 'medium', [ 'short', 'medium', 'long' ], true )
			? $input['length']
			: 'medium';

		// @phpstan-ignore-next-line
		$content = self::resolve_content( $input['content'] ?? '', $input['context'] ?? '' );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		if ( empty( $content['text'] ) ) {
			return new WP_Error( 'content_not_provided', __( 'Content is required to generate a summary.', 'superdav-ai-agent' ) );
		}

		$length_desc = '2–3 sentences; 25–80 words';
		if ( 'short' === $length ) {
			$length_desc = '1 sentence; ≤ 25 words';
		} elseif ( 'long' === $length ) {
			$length_desc = '4–6 sentences; 80–160 words';
		}

		$system_instruction = "You are an editorial assistant that generates concise, factual, and neutral summaries of long-form content. Your summaries support both inline readability (e.g., top-of-post overview) and structured metadata use cases (search previews, featured cards, accessibility tools).\n\nGoal: You will be provided with content and optionally some additional context. You will then generate a concise, factual, and neutral summary of that content that also keeps in mind the context. Write in complete sentences, avoid persuasive or stylistic language, do not use humour or exaggeration, and do not introduce information not present in the source.\n\nThe summary should follow these requirements:\n\n- Target {$length_desc}\n- Should not contain any markdown, bullets, numbering, or formatting — plain text only\n- Provide a high-level overview, not a list of details\n- Do not start with \"This article is about...\" or \"This post explains...\" or \"This content describes...\" or any other generic introduction\n- Must reflect the actual content, not generic filler text";

		$prompt = '<content>' . $content['text'] . '</content>';
		if ( ! empty( $content['context'] ) ) {
			$prompt .= "\n\n<additional-context>" . $content['context'] . '</additional-context>';
		}

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system_instruction )
			->using_temperature( 0.9 );

		$model = self::get_configured_model();
		if ( ! empty( $model ) ) {
			$builder = $builder->using_model_preference( $model );
		}

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error( 'no_results', __( 'No summary was generated.', 'superdav-ai-agent' ) );
		}

		return sanitize_textarea_field( trim( $result ) );
	}

	// ─── Block Review ────────────────────────────────────────────────────────

	/**
	 * Register the review-block ability.
	 *
	 * @since 1.1.0
	 */
	private static function register_review_block(): void {
		wp_register_ability(
			'sd-ai-agent/review-block',
			[
				'label'               => __( 'Review Block Content', 'superdav-ai-agent' ),
				'description'         => __( 'Review a Gutenberg block\'s content for accessibility, readability, grammar, and SEO issues. Returns actionable suggestions.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'block_type'     => [
							'type'        => 'string',
							'description' => __( 'The block type, e.g. core/paragraph, core/heading.', 'superdav-ai-agent' ),
						],
						'block_content'  => [
							'type'        => 'string',
							'description' => __( 'The plain-text content of the block to review.', 'superdav-ai-agent' ),
						],
						'context'        => [
							'type'        => 'string',
							'description' => __( 'Surrounding content to improve review relevance.', 'superdav-ai-agent' ),
						],
						'post_id'        => [
							'type'        => 'integer',
							'description' => __( 'ID of the post being reviewed.', 'superdav-ai-agent' ),
						],
						'existing_notes' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'Existing note texts for this block from prior review runs, used to avoid repeating suggestions.', 'superdav-ai-agent' ),
						],
						'review_types'   => [
							'type'        => 'array',
							'items'       => [
								'type' => 'string',
								'enum' => [ 'accessibility', 'readability', 'grammar', 'seo' ],
							],
							'description' => __( 'Review types to perform (accessibility, readability, grammar, seo). Defaults to all four.', 'superdav-ai-agent' ),
						],
					],
					'required'   => [ 'block_type', 'block_content' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'suggestions' => [
							'type'        => 'array',
							'description' => __( 'Review suggestions for the block.', 'superdav-ai-agent' ),
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'review_type' => [ 'type' => 'string' ],
									'text'        => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_review_block' ],
				'permission_callback' => [ __CLASS__, 'permission_edit_posts' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Execute the review-block ability.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array{suggestions: list<array{review_type: string, text: string}>}|\WP_Error
	 */
	public static function handle_review_block( array $input ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'ai_client_unavailable', __( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' ) );
		}

		// @phpstan-ignore-next-line
		$block_type = sanitize_text_field( $input['block_type'] ?? '' );
		// @phpstan-ignore-next-line
		$block_content = sanitize_text_field( $input['block_content'] ?? '' );

		if ( empty( $block_content ) ) {
			return new WP_Error( 'block_content_required', __( 'Block content is required to perform a review.', 'superdav-ai-agent' ) );
		}

		$supported_types = [ 'accessibility', 'readability', 'grammar', 'seo' ];
		$review_types    = array_values(
			array_filter(
				is_array( $input['review_types'] ?? null ) ? $input['review_types'] : $supported_types,
				static fn( $t ) => is_string( $t ) && in_array( $t, $supported_types, true )
			)
		);

		$existing_notes = array_values(
			array_filter(
				is_array( $input['existing_notes'] ?? null ) ? $input['existing_notes'] : [],
				'is_string'
			)
		);

		$system_instruction = <<<'INSTRUCTION'
You are an editorial review assistant for WordPress block content. You are reviewing a single block only. The type of block is provided in <block-type> tags. Your goal is to identify material, objective issues in the block content (denoted by <block-content> tags) and return concise, actionable suggestions. If additional context is provided (denoted by <additional-context> tags), use it to generate a more relevant review.

Attach a priority score to each suggestion between 1 and 5, where 1 is the highest priority and 5 is the lowest priority. If there are no substantial issues, return an empty suggestions array.

## High Bar for Suggestions

Only return a suggestion if the issue:
- Materially affects clarity, correctness, accessibility, structure, or usability
- Is objectively identifiable (not stylistic preference)
- Is specific to the actual content provided
- Would meaningfully improve the block if fixed

Do not generate suggestions for minor wording preferences, tone adjustments, engagement improvements, vague clarity issues, general improvement advice, hypothetical SEO optimisations, or subjective style choices.

## Specificity Requirement

Every suggestion must reference a concrete issue, clearly state what is wrong, be directly fixable, and avoid vague language like "Consider improving..." or "This could be clearer".

## Output Format

Return a JSON object with a "suggestions" array. Each suggestion has: review_type (string), text (string), priority (integer 1–5). Only include suggestions with priority ≤ 2.

## Category guidance by block type

The review types to perform are provided in <review-types> tags.

**core/image**: accessibility — check alt text is present and descriptive. Skip readability, grammar, seo.
**core/heading**: seo — flag vague headings. Skip readability and grammar.
**core/paragraph**: readability — flag passive voice or complex vocabulary; grammar — flag errors; seo — flag buried keywords.
**core/list, core/list-item**: readability — flag inconsistent style; grammar — flag errors.
**core/table**: accessibility — flag missing header cells or caption.
**core/quote, core/pullquote, core/verse, core/preformatted**: readability — flag excessively long quotes; grammar — flag errors.
For all other block types, apply readability and grammar checks if text content is present.
INSTRUCTION;

		// Build prompt.
		$prompt_parts   = [];
		$prompt_parts[] = '<block-type>' . $block_type . '</block-type>';
		$prompt_parts[] = '<block-content>' . wp_strip_all_tags( $block_content ) . '</block-content>';

		if ( ! empty( $input['context'] ) ) {
			// @phpstan-ignore-next-line
			$prompt_parts[] = '<additional-context>' . wp_strip_all_tags( sanitize_text_field( $input['context'] ) ) . '</additional-context>';
		}

		$prompt_parts[] = '<review-types>' . implode( ', ', $review_types ) . '</review-types>';

		if ( ! empty( $existing_notes ) ) {
			$prompt_parts[] = '<existing-notes>' . implode( "\n\n", array_map( 'sanitize_text_field', $existing_notes ) ) . '</existing-notes>';
		}

		$suggestions_schema = [
			'type'                 => 'object',
			'properties'           => [
				'suggestions' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => [
							'review_type' => [ 'type' => 'string' ],
							'text'        => [ 'type' => 'string' ],
							'priority'    => [ 'type' => 'integer' ],
						],
						'required'             => [ 'review_type', 'text', 'priority' ],
						'additionalProperties' => false,
					],
				],
			],
			'required'             => [ 'suggestions' ],
			'additionalProperties' => false,
		];

		$builder = wp_ai_client_prompt( implode( "\n", $prompt_parts ) )
			->using_system_instruction( $system_instruction )
			->as_json_response( $suggestions_schema );

		$model = self::get_configured_model();
		if ( ! empty( $model ) ) {
			$builder = $builder->using_model_preference( $model );
		}

		$raw = $builder->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		if ( empty( $raw ) ) {
			return [ 'suggestions' => [] ];
		}

		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['suggestions'] ) || ! is_array( $decoded['suggestions'] ) ) {
			return [ 'suggestions' => [] ];
		}

		// Extract existing review types from prior notes to avoid repeating them.
		$existing_types = [];
		foreach ( $existing_notes as $note ) {
			if ( preg_match_all( '/\[([^\]]+)\]/', (string) $note, $matches ) ) {
				foreach ( $matches[1] as $type ) {
					$existing_types[ strtolower( trim( $type ) ) ] = true;
				}
			}
		}

		$suggestions = [];
		foreach ( $decoded['suggestions'] as $item ) {
			if (
				! is_array( $item ) ||
				empty( $item['review_type'] ) ||
				empty( $item['text'] ) ||
				! is_string( $item['review_type'] ) ||
				! is_string( $item['text'] )
			) {
				continue;
			}

			$review_type = sanitize_text_field( $item['review_type'] );
			$text        = sanitize_text_field( $item['text'] );
			$priority    = absint( $item['priority'] ?? 5 );

			// Skip if already covered by existing notes.
			if ( isset( $existing_types[ strtolower( $review_type ) ] ) ) {
				continue;
			}

			// Only surface high-priority suggestions (1–2).
			if ( $priority > 2 ) {
				continue;
			}

			$suggestions[] = [
				'review_type' => $review_type,
				'text'        => $text,
			];
		}

		return [ 'suggestions' => $suggestions ];
	}

	// ─── Shared helpers ──────────────────────────────────────────────────────

	/**
	 * Default permission callback: requires edit_posts capability.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $input Input args (unused).
	 * @return bool|\WP_Error
	 */
	public static function permission_edit_posts( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				__( 'You do not have permission to use AI editorial abilities.', 'superdav-ai-agent' )
			);
		}
		return true;
	}

	/**
	 * Resolve content and context from input.
	 *
	 * When $context_or_post_id is numeric, treats it as a post ID and loads
	 * the post content and metadata as context. Otherwise uses it as a plain
	 * context string.
	 *
	 * @since 1.1.0
	 *
	 * @param string $raw_content       Raw content string from input.
	 * @param string $context_or_post_id Context string or numeric post ID.
	 * @return array{text: string, context: string}|\WP_Error
	 */
	private static function resolve_content( string $raw_content, string $context_or_post_id ) {
		if ( is_numeric( $context_or_post_id ) && (int) $context_or_post_id > 0 ) {
			$post = get_post( (int) $context_or_post_id );

			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( __( 'Post with ID %d not found.', 'superdav-ai-agent' ), absint( $context_or_post_id ) )
				);
			}

			$text    = ! empty( $raw_content ) ? wp_strip_all_tags( $raw_content ) : wp_strip_all_tags( $post->post_content );
			$context = implode(
				"\n",
				array_filter(
					[
						$post->post_title ? 'Title: ' . $post->post_title : '',
						$post->post_excerpt ? 'Excerpt: ' . $post->post_excerpt : '',
					]
				)
			);

			return [
				'text'    => $text,
				'context' => $context,
			];
		}

		return [
			'text'    => wp_strip_all_tags( $raw_content ),
			'context' => $context_or_post_id,
		];
	}

	/**
	 * Build a context string for title generation from post_id or content.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return string|\WP_Error
	 */
	private static function build_text_context( array $input ) {
		if ( ! empty( $input['post_id'] ) ) {
			// @phpstan-ignore-next-line
			$post = get_post( (int) $input['post_id'] );

			if ( ! $post ) {
				$post_id_int = is_numeric( $input['post_id'] ) ? (int) $input['post_id'] : 0;
				// translators: %d: Post ID.
				$error_message = sprintf( __( 'Post with ID %d not found.', 'superdav-ai-agent' ), absint( $post_id_int ) );
				return new WP_Error( 'post_not_found', $error_message );
			}

			$parts = array_filter(
				[
					$post->post_title ? 'Title: ' . $post->post_title : '',
					wp_strip_all_tags( $post->post_content ),
					$post->post_excerpt ? 'Excerpt: ' . $post->post_excerpt : '',
				]
			);

			return implode( "\n", $parts );
		}

		// @phpstan-ignore-next-line
		return wp_strip_all_tags( $input['content'] ?? '' );
	}

	/**
	 * Get the configured model ID from plugin settings.
	 *
	 * @since 1.1.0
	 *
	 * @return string Model ID or empty string.
	 */
	private static function get_configured_model(): string {
		if ( class_exists( \SdAiAgent\Core\Settings::class ) ) {
			$model = \SdAiAgent\Core\Settings::instance()->get( 'default_model' );
			return is_string( $model ) ? $model : '';
		}
		return '';
	}
}
