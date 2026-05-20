<?php

declare(strict_types=1);
/**
 * List Interview Uploads ability — surfaces photos the user provided
 * during the Theme Builder interview.
 *
 * The user uploads photos through the {@see \SdAiAgent\Core\OnboardingManager::rest_interview_upload()}
 * endpoint, which tags each attachment with three meta keys:
 *
 *   _sd_ai_agent_interview_upload          (1)
 *   _sd_ai_agent_interview_upload_category (space|product|team|event|other)
 *   _sd_ai_agent_interview_upload_session  (int session id, optional)
 *
 * This ability lets the Theme Builder agent read those photos back when
 * planning sections (hero, gallery, about, menu, etc.). Photos remain in
 * the media library after the interview ends, so the agent can recall them
 * across sessions for the same site.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\InterviewUploadStore;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability: sd-ai-agent/list-interview-uploads
 *
 * @since 1.16.0
 */
class ListInterviewUploadsAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'List Interview Uploads', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __(
			'List photos the user uploaded during the Theme Builder interview (space, product, team, event). Returns attachment IDs, URLs, thumbnails, filenames, and the heuristic category guess for each upload so the agent can plan section imagery (hero, gallery, about, menu) without calling stock or generated-image abilities. Filter by category and/or session ID. Returns an empty list (not an error) when no photos have been uploaded yet — fall back to sd-ai-agent/stock-image or sd-ai-agent/generate-image in that case.',
			'superdav-ai-agent'
		);
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'category'   => [
					'type'        => 'string',
					'enum'        => [ 'space', 'product', 'team', 'event', 'other' ],
					'description' => 'Optional category filter. When omitted, all categories are returned.',
				],
				'session_id' => [
					'type'        => 'integer',
					'description' => 'Optional session ID. When provided, only uploads tagged with this session are returned.',
				],
				'limit'      => [
					'type'        => 'integer',
					'description' => 'Maximum number of items to return (default: 50, max: 200).',
				],
			],
			'required'   => [],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'items'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'attachment_id' => [ 'type' => 'integer' ],
							'url'           => [ 'type' => 'string' ],
							'thumbnail'     => [ 'type' => 'string' ],
							'title'         => [ 'type' => 'string' ],
							'filename'      => [ 'type' => 'string' ],
							'category'      => [ 'type' => 'string' ],
							'mime_type'     => [ 'type' => 'string' ],
							'width'         => [ 'type' => 'integer' ],
							'height'        => [ 'type' => 'integer' ],
						],
					],
				],
				'total'       => [ 'type' => 'integer' ],
				'by_category' => [
					'type'                 => 'object',
					'additionalProperties' => [ 'type' => 'integer' ],
				],
			],
		];
	}

	protected function execute_callback( $input ): array|WP_Error {
		/** @var array<string,mixed> $input */
		$args = [];

		if ( isset( $input['category'] ) && is_string( $input['category'] ) && '' !== $input['category'] ) {
			$args['category'] = $input['category'];
		}
		if ( isset( $input['session_id'] ) ) {
			$args['session_id'] = (int) $input['session_id'];
		}
		if ( isset( $input['limit'] ) ) {
			$args['limit'] = (int) $input['limit'];
		}

		return InterviewUploadStore::list_uploads( $args );
	}

	protected function permission_callback( $input ): bool {
		return current_user_can( 'upload_files' );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}
