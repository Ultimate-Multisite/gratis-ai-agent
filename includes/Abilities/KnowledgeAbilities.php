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
					return current_user_can( 'manage_options' ); },
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

		if ( ! empty( $input['collection'] ) ) {
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
