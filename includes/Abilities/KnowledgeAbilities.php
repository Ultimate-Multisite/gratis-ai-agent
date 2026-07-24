<?php

declare(strict_types=1);
/**
 * Register knowledge-related WordPress abilities (tools) for the AI agent.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Knowledge\Knowledge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KnowledgeAbilities {

	/**
	 * Request-scoped public chat collection allowlist.
	 *
	 * The explicit mode flag distinguishes normal authenticated behaviour from
	 * an active policy with no approved collections. While active, knowledge
	 * searches must stay inside these server-configured documentation
	 * collections.
	 *
	 * @var array<string, true>
	 */
	private static array $public_collection_allowlist = [];

	/** @var bool Whether a constrained public/customer collection policy is active. */
	private static bool $public_collection_mode_active = false;

	/**
	 * Request-scoped fallback query for public-chat knowledge calls.
	 *
	 * @var string
	 */
	private static string $public_default_query = '';

	/**
	 * Enable request-scoped public collection gating.
	 *
	 * @param list<string> $collections Collection slugs allowed for this run.
	 */
	// phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<string> is valid PHPStan but not a native PHP type.
	public static function set_public_collection_allowlist( array $collections, string $default_query = '' ): void {
		self::$public_collection_mode_active = true;
		self::$public_collection_allowlist   = [];
		self::$public_default_query          = trim( $default_query );
		foreach ( $collections as $collection ) {
			$collection = sanitize_key( $collection );
			if ( '' !== $collection ) {
				self::$public_collection_allowlist[ $collection ] = true;
			}
		}
	}

	/** Clear request-scoped public collection gating. */
	public static function clear_public_collection_allowlist(): void {
		self::$public_collection_mode_active = false;
		self::$public_collection_allowlist   = [];
		self::$public_default_query          = '';
	}

	/** Whether public collection gating is active for this request. */
	public static function is_public_collection_mode(): bool {
		return self::$public_collection_mode_active;
	}

	/**
	 * Fill missing public-chat knowledge-search args from the current customer turn.
	 *
	 * Some fast models can select the right public tool but emit an empty argument
	 * object. In anonymous public-chat mode this fallback is safe because the tool
	 * is already constrained to the server-selected documentation collection(s).
	 *
	 * @param array<string, mixed> $args Tool-call arguments.
	 * @return array<string, mixed>
	 */
	public static function hydrate_public_search_args( array $args ): array {
		if ( ! self::is_public_collection_mode() ) {
			return $args;
		}

		if ( empty( $args['query'] ) && '' !== self::$public_default_query ) {
			$args['query'] = self::$public_default_query;
		}

		return $args;
	}

	/**
	 * Register the knowledge search ability.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/knowledge-search',
			[
				'label'               => __( 'Search Knowledge Base', 'superdav-ai-agent' ),
				'description'         => __( 'Search the knowledge base for relevant information. Use this to find indexed documents, posts, and uploaded files.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'query'      => [
							'type'        => 'string',
							'description' => 'The search query to find relevant knowledge.',
						],
						'collection' => [
							'type'        => 'string',
							'description' => 'Optional collection slug to search within. Leave empty to search all collections.',
						],
					],
					'required'   => [ 'query' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'results' => [ 'type' => 'array' ],
						'count'   => [ 'type' => 'integer' ],
						'message' => [ 'type' => 'string' ],
						'error'   => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_knowledge_search' ],
				'permission_callback' => function () {
					if ( self::is_public_collection_mode() ) {
						return true;
					}
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/knowledge-search' );
				},
			]
		);
	}

	/**
	 * Handle the knowledge-search ability call.
	 *
	 * @param array<string,mixed> $input Input with query and optional collection.
	 * @return array<string,mixed>|\WP_Error Result.
	 */
	public static function handle_knowledge_search( array $input ) {
		$query = $input['query'] ?? '';

		if ( empty( $query ) ) {
			return new \WP_Error( 'missing_query', 'Search query is required.' );
		}

		$options = [ 'limit' => 8 ];

		if ( self::is_public_collection_mode() ) {
			$requested = ! empty( $input['collection'] ) ? sanitize_key( (string) $input['collection'] ) : '';
			if ( '' !== $requested && ! isset( self::$public_collection_allowlist[ $requested ] ) ) {
				return new \WP_Error( 'sd_ai_agent_public_collection_forbidden', 'This public chat session cannot search that collection.' );
			}

			if ( '' !== $requested ) {
				$options['collection'] = $requested;
			} else {
				$results = self::search_public_collections( (string) $query, $options );
				return self::format_knowledge_search_results( $results );
			}
		} elseif ( ! empty( $input['collection'] ) ) {
			$options['collection'] = $input['collection'];
		}

		// @phpstan-ignore-next-line
		$results = Knowledge::search( $query, $options );

		return self::format_knowledge_search_results( $results );
	}

	/**
	 * Search every allowlisted public collection and merge the highest scoring hits.
	 *
	 * @param string               $query   Search query.
	 * @param array<string, mixed> $options Search options.
	 * @return list<array<string, mixed>>
	 */
	private static function search_public_collections( string $query, array $options ): array {
		$merged = [];
		foreach ( array_keys( self::$public_collection_allowlist ) as $collection ) {
			$options['collection'] = $collection;
			$results               = Knowledge::search( $query, $options );
			foreach ( $results as $result ) {
				$merged[] = $result;
			}
		}

		if ( empty( $merged ) && self::query_needs_overview_fallback( $query ) ) {
			foreach ( array_keys( self::$public_collection_allowlist ) as $collection ) {
				$options['collection'] = $collection;
				$results               = Knowledge::search( 'overview getting started introduction documentation guide', $options );
				foreach ( $results as $result ) {
					$merged[] = $result;
				}
			}
		}

		usort(
			$merged,
			static fn( array $a, array $b ): int => ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 )
		);

		return array_slice( $merged, 0, (int) ( $options['limit'] ?? 8 ) );
	}

	/**
	 * Whether a public query is too contextual to search literally.
	 *
	 * @param string $query Customer query.
	 * @return bool
	 */
	private static function query_needs_overview_fallback( string $query ): bool {
		$normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $query ) ?? $query ) );
		return (bool) preg_match( '/\b(?:what\s+(?:is|are)\s+(?:this|that|it|these|those|thing|things)|tell\s+me\s+about\s+(?:this|that|it)|how\s+does\s+(?:this|that|it)\s+work)\b/', $normalized );
	}

	/**
	 * Format raw knowledge search rows for tool output.
	 *
	 * @param list<array<string, mixed>> $results Raw search results.
	 * @return array<string, mixed>
	 */
	private static function format_knowledge_search_results( array $results ): array {

		if ( empty( $results ) ) {
			return [
				'results' => [],
				'count'   => 0,
				'message' => 'No relevant knowledge found for that query.',
			];
		}

		$formatted = [];
		foreach ( $results as $result ) {
			$entry = [
				'text'       => $result['chunk_text'],
				'source'     => $result['source_title'],
				'collection' => $result['collection_name'],
			];

			if ( ! empty( $result['source_url'] ) ) {
				$entry['url'] = $result['source_url'];
			}

			$formatted[] = $entry;
		}

		return [
			'results' => $formatted,
			'count'   => count( $formatted ),
			'message' => '',
		];
	}
}
