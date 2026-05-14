<?php

declare(strict_types=1);
/**
 * Register memory-related WordPress abilities (tools) for the AI agent.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Models\Memory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MemoryAbilities {

	/**
	 * Register the three memory abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/memory-save',
			[
				'label'               => __( 'Save Memory', 'superdav-ai-agent' ),
				'description'         => __( 'Save a piece of information to persistent memory. Use this to remember facts, preferences, or context for future conversations.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'category' => [
							'type'        => 'string',
							'description' => 'Memory category: site_info, user_preferences, technical_notes, workflows, or general',
							'enum'        => Memory::CATEGORIES,
						],
						'content'  => [
							'type'        => 'string',
							'description' => 'The information to remember',
						],
					],
					'required'   => [ 'category', 'content' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => [ 'type' => 'boolean' ],
						'id'      => [ 'type' => 'integer' ],
						'message' => [ 'type' => 'string' ],
						'error'   => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_memory_save' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ); },
			]
		);

		wp_register_ability(
			'sd-ai-agent/memory-list',
			[
				'label'               => __( 'List Memories', 'superdav-ai-agent' ),
				'description'         => __( 'List all stored memories, grouped by category.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => (object) [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'memories' => [ 'type' => 'array' ],
						'message'  => [ 'type' => 'string' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_memory_list' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ); },
			]
		);

		wp_register_ability(
			'sd-ai-agent/memory-delete',
			[
				'label'               => __( 'Delete Memory', 'superdav-ai-agent' ),
				'description'         => __( 'Delete a specific memory by its ID.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'id' => [
							'type'        => 'integer',
							'description' => 'The memory ID to delete',
						],
					],
					'required'   => [ 'id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => [ 'type' => 'boolean' ],
						'message' => [ 'type' => 'string' ],
						'error'   => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_memory_delete' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ); },
			]
		);
	}

	/**
	 * Handle the memory-save ability call.
	 *
	 * @param array<string,mixed> $input Input with category and content.
	 * @return array<string,mixed>|\WP_Error Result.
	 */
	public static function handle_memory_save( array $input ) {
		$category = $input['category'] ?? 'general';
		$content  = $input['content'] ?? '';

		if ( empty( $content ) ) {
			return new \WP_Error( 'missing_content', 'Content is required.' );
		}

		// @phpstan-ignore-next-line
		$id = Memory::create( $category, $content );

		if ( false === $id ) {
			return [
				'success' => false,
				'id'      => 0,
				'message' => '',
				'error'   => 'Failed to save memory.',
			];
		}

		return [
			'success' => true,
			'id'      => $id,
			// @phpstan-ignore-next-line
			'message' => "Memory saved (ID: $id, category: $category).",
		];
	}

	/**
	 * Handle the memory-list ability call.
	 *
	 * @return array<string,mixed> Result.
	 */
	public static function handle_memory_list(): array {
		$memories = Memory::get_all();

		if ( empty( $memories ) ) {
			return [
				'memories' => [],
				'message'  => 'No memories stored yet.',
			];
		}

		$list = [];
		foreach ( $memories as $m ) {
			$list[] = [
				// @phpstan-ignore-next-line
				'id'       => (int) $m->id,
				// @phpstan-ignore-next-line
				'category' => $m->category,
				// @phpstan-ignore-next-line
				'content'  => $m->content,
			];
		}

		return [
			'memories' => $list,
			'message'  => '',
		];
	}

	/**
	 * Handle the memory-delete ability call.
	 *
	 * @param array<string,mixed> $input Input with id.
	 * @return array<string,mixed>|\WP_Error Result.
	 */
	public static function handle_memory_delete( array $input ) {
		$id = $input['id'] ?? 0;

		if ( empty( $id ) ) {
			return new \WP_Error( 'missing_id', 'Memory ID is required.' );
		}

		// @phpstan-ignore-next-line
		$deleted = Memory::delete( (int) $id );

		if ( ! $deleted ) {
			return [
				'success' => false,
				'message' => '',
				'error'   => 'Failed to delete memory or memory not found.',
			];
		}

		return [
			'success' => true,
			// @phpstan-ignore-next-line
			'message' => "Memory $id deleted.",
		];
	}
}
