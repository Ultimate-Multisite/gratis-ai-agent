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
	 * Empty means normal authenticated behaviour. Non-empty means anonymous
	 * public chat mode is active and knowledge searches must stay inside these
	 * server-configured documentation collections.
	 *
	 * @var array<string, true>
	 */
	private static array $public_collection_allowlist = [];

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
		self::$public_collection_allowlist = [];
		self::$public_default_query        = trim( $default_query );
		foreach ( $collections as $collection ) {
			$collection = sanitize_key( $collection );
			if ( '' !== $collection ) {
				self::$public_collection_allowlist[ $collection ] = true;
			}
		}
	}

	/** Clear request-scoped public collection gating. */
	public static function clear_public_collection_allowlist(): void {
		self::$public_collection_allowlist = [];
		self::$public_default_query        = '';
	}

	/** Whether public collection gating is active for this request. */
	public static function is_public_collection_mode(): bool {
		return ! empty( self::$public_collection_allowlist );
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

		if ( empty( $args['collection'] ) ) {
			$collection = array_key_first( self::$public_collection_allowlist );
			if ( is_string( $collection ) ) {
				$args['collection'] = $collection;
			}
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

			$options['collection'] = '' !== $requested ? $requested : (string) array_key_first( self::$public_collection_allowlist );
		} elseif ( ! empty( $input['collection'] ) ) {
			$options['collection'] = $input['collection'];
		}

		// @phpstan-ignore-next-line
		$results = Knowledge::search( $query, $options );

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
