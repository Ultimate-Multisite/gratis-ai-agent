<?php

declare(strict_types=1);
/**
 * REST controller for the block-content validator (GH#1584 Phase 1).
 *
 * Three endpoints:
 *
 *   POST /sd-ai-agent/v1/blocks/validate
 *       Run the server-side {@see \SdAiAgent\Core\BlockValidator} against
 *       the supplied content and return a Studio-shaped report. When the
 *       browser has previously primed the cache for the same content (via
 *       /validate-cache below), the cached JS-validator report is returned
 *       instead.
 *
 *   POST /sd-ai-agent/v1/blocks/validate-cache
 *       Accept a Studio-shaped report from the browser-side validator
 *       (`src/block-validator/index.js`) and store it via
 *       {@see \SdAiAgent\Core\BlockValidatorBridge::store()} so subsequent
 *       PHP calls pick it up. This is the bridge that gives PHP access to
 *       real `wp.blocks.validateBlock()` results without spawning a headless
 *       browser.
 *
 *   GET  /sd-ai-agent/v1/blocks/validate-page
 *       Returns the URL of the hidden admin page that hosts the JS validator
 *       so admin React clients can iframe-load it.
 *
 * @package SdAiAgent\REST
 * @license GPL-2.0-or-later
 * @since   1.11.0
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1584
 */

namespace SdAiAgent\REST;

use SdAiAgent\Core\BlockValidator;
use SdAiAgent\Core\BlockValidatorBridge;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;
use XWP_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block validator REST endpoints.
 *
 * @since 1.11.0
 */
#[REST_Handler(
	namespace: RestController::NAMESPACE,
	basename: 'blocks',
	container: 'sd-ai-agent',
)]
final class BlockValidatorController extends XWP_REST_Controller {

	use PermissionTrait;

	/**
	 * POST /blocks/validate — run the validator and return the report.
	 *
	 * @since 1.11.0
	 *
	 * @param WP_REST_Request $request Incoming request with `content`.
	 * @return WP_REST_Response|WP_Error Studio-shaped report or error.
	 */
	#[REST_Route(
		route: 'validate',
		methods: WP_REST_Server::CREATABLE,
		vars: 'get_validate_args',
		guard: 'check_validator_permission',
	)]
	public function handle_validate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$content = (string) $request->get_param( 'content' );

		if ( '' === trim( $content ) ) {
			return new WP_Error(
				'sd_ai_agent_validate_missing_content',
				__( 'Content is required for validation.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		return new WP_REST_Response( $report, 200 );
	}

	/**
	 * POST /blocks/validate-cache — store browser-validated report in the bridge.
	 *
	 * @since 1.11.0
	 *
	 * @param WP_REST_Request $request Incoming request with `content` and `report`.
	 * @return WP_REST_Response|WP_Error Confirmation or error.
	 */
	#[REST_Route(
		route: 'validate-cache',
		methods: WP_REST_Server::CREATABLE,
		vars: 'get_cache_args',
		guard: 'check_validator_permission',
	)]
	public function handle_cache_put( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$content = (string) $request->get_param( 'content' );
		$report  = $request->get_param( 'report' );

		if ( '' === trim( $content ) ) {
			return new WP_Error(
				'sd_ai_agent_validate_missing_content',
				__( 'Content is required.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! is_array( $report ) || ! isset( $report['results'] ) || ! is_array( $report['results'] ) ) {
			return new WP_Error(
				'sd_ai_agent_validate_bad_report',
				__( 'Report payload is invalid — expected an array with a results[] field.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		/** @var array<string, mixed> $report */
		BlockValidatorBridge::store( $content, $report );

		return new WP_REST_Response(
			[
				'stored'      => true,
				'totalBlocks' => isset( $report['totalBlocks'] ) ? (int) $report['totalBlocks'] : count( $report['results'] ),
			],
			200
		);
	}

	/**
	 * GET /blocks/validate-page — return the hidden validator page URL.
	 *
	 * @since 1.11.0
	 *
	 * @param WP_REST_Request $request Incoming request (no params).
	 * @return WP_REST_Response Page URL payload.
	 */
	#[REST_Route(
		route: 'validate-page',
		methods: WP_REST_Server::READABLE,
		vars: 'get_no_args',
		guard: 'check_validator_permission',
	)]
	public function handle_get_page( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$url = admin_url( 'admin.php?page=sd-ai-agent-block-validator' );

		return new WP_REST_Response(
			[ 'url' => $url ],
			200
		);
	}

	/**
	 * Permission gate — validator endpoints require edit_posts capability so
	 * any logged-in editor or above can use them. The validator itself is a
	 * pure pre-flight check with no side effects, but content can be PII so
	 * we restrict to authors+.
	 *
	 * @since 1.11.0
	 *
	 * @return bool True when the current user may use the validator.
	 */
	public function check_validator_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Schema for POST /blocks/validate.
	 *
	 * @since 1.11.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_validate_args(): array {
		return [
			'content' => [
				'required' => true,
				'type'     => 'string',
			],
		];
	}

	/**
	 * Schema for POST /blocks/validate-cache.
	 *
	 * @since 1.11.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_cache_args(): array {
		return [
			'content' => [
				'required' => true,
				'type'     => 'string',
			],
			'report'  => [
				'required' => true,
				'type'     => 'object',
			],
		];
	}

	/**
	 * Schema for GET /blocks/validate-page.
	 *
	 * @since 1.11.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_no_args(): array {
		return [];
	}
}
