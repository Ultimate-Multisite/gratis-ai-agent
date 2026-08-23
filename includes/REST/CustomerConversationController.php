<?php

declare(strict_types=1);
/**
 * Admin-only REST API controller for privacy-safe customer conversation reviews.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use SdAiAgent\Models\CustomerConversationReviewRepository;
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
 * Provides read-only inspection and deletion of sanitized customer reviews.
 *
 * Customer-facing execution data, tokens, and source identifiers never cross
 * this controller boundary. The only mutation routes tombstone retained review
 * projections; they never resume or execute a customer conversation.
 */
#[REST_Handler(
	namespace: RestController::NAMESPACE,
	basename: 'customer-conversations',
	container: 'sd-ai-agent',
)]
final class CustomerConversationController extends XWP_REST_Controller {

	use PermissionTrait;

	/** Handle GET /customer-conversations. */
	#[REST_Route(
		route: '',
		methods: WP_REST_Server::READABLE,
		vars: 'get_list_args',
		guard: 'check_permission',
	)]
	public function handle_list_customer_conversations( WP_REST_Request $request ): WP_REST_Response {
		$filters = array();
		foreach ( array( 'source', 'status', 'agent', 'date_from', 'date_to', 'search', 'limit', 'offset' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$filters[ $key ] = $request->get_param( $key );
			}
		}

		$limit             = min( 50, max( 1, (int) ( $filters['limit'] ?? 20 ) ) );
		$offset            = min( 10000, max( 0, (int) ( $filters['offset'] ?? 0 ) ) );
		$filters['limit']  = $limit;
		$filters['offset'] = $offset;

		return new WP_REST_Response(
			array(
				'conversations' => CustomerConversationReviewRepository::list_reviews( $filters ),
				'total'         => CustomerConversationReviewRepository::count_reviews( $filters ),
				'limit'         => $limit,
				'offset'        => $offset,
			),
			200
		);
	}

	/**
	 * Handle GET /customer-conversations/{opaque-review-id}.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	#[REST_Route(
		route: '(?P<id>[a-f0-9-]+)',
		methods: WP_REST_Server::READABLE,
		vars: 'get_detail_args',
		guard: 'check_permission',
	)]
	public function handle_get_customer_conversation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$review_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$review    = CustomerConversationReviewRepository::get_review(
			$review_id,
			(int) $request->get_param( 'turn_limit' ),
			(int) $request->get_param( 'turn_offset' )
		);
		if ( null === $review ) {
			return $this->not_found_error();
		}

		return new WP_REST_Response( $review, 200 );
	}

	/**
	 * Handle DELETE /customer-conversations/{opaque-review-id}.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	#[REST_Route(
		route: '(?P<id>[a-f0-9-]+)',
		methods: WP_REST_Server::DELETABLE,
		vars: 'get_identifier_args',
		guard: 'check_permission',
	)]
	public function handle_delete_customer_conversation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$review_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		if ( ! CustomerConversationReviewRepository::delete_review( $review_id ) ) {
			return $this->not_found_error();
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $review_id,
			),
			200
			);
	}

	/**
	 * Handle POST /customer-conversations/purge after explicit admin confirmation.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	#[REST_Route(
		route: 'purge',
		methods: WP_REST_Server::CREATABLE,
		vars: 'get_purge_args',
		guard: 'check_permission',
	)]
	public function handle_purge_customer_conversations( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( true !== $request->get_param( 'confirm' ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_conversation_purge_confirmation_required',
				__( 'Confirm deletion of retained customer conversations.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		$limit = min( 1000, max( 1, (int) $request->get_param( 'limit' ) ) );

		return new WP_REST_Response(
			array( 'purged' => CustomerConversationReviewRepository::purge_reviews( $limit ) ),
			200
		);
	}

	/**
	 * REST schema for list filters and bounded offset pagination.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_list_args(): array {
		return array(
			'source'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'status'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'agent'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_from' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_to'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'limit'     => array(
				'type'              => 'integer',
				'default'           => 20,
				'sanitize_callback' => 'absint',
			),
			'offset'    => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * REST schema for opaque review IDs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_identifier_args(): array {
		return array(
			'id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * REST schema for one opaque review ID and a bounded transcript page.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_detail_args(): array {
		$args                = $this->get_identifier_args();
		$args['turn_limit']  = array(
			'type'              => 'integer',
			'default'           => 100,
			'sanitize_callback' => 'absint',
		);
		$args['turn_offset'] = array(
			'type'              => 'integer',
			'default'           => 0,
			'sanitize_callback' => 'absint',
		);

		return $args;
	}

	/**
	 * REST schema for explicit retained-review purge.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_purge_args(): array {
		return array(
			'confirm' => array(
				'required' => true,
				'type'     => 'boolean',
			),
			'limit'   => array(
				'type'              => 'integer',
				'default'           => 500,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/** Return a deliberately generic error for inaccessible review IDs. */
	private function not_found_error(): WP_Error {
		return new WP_Error(
			'sd_ai_agent_customer_conversation_not_found',
			__( 'Customer conversation not found.', 'superdav-ai-agent' ),
			array( 'status' => 404 )
		);
	}
}
