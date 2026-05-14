<?php

declare(strict_types=1);
/**
 * REST API controller for agents and conversation templates.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use SdAiAgent\Core\AbilityVisibility;
use SdAiAgent\Models\Agent;
use SdAiAgent\Models\ConversationTemplate;
use SdAiAgent\Models\DTO\AgentRow;
use SdAiAgent\Models\DTO\ConversationTemplateRow;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages agents and conversation templates via REST.
 *
 * Uses #[Handler] + #[Action] because this controller serves multiple
 * basenames (/agents, /conversation-templates).
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class AgentController {

	use PermissionTrait;

	/**
	 * Register REST routes.
	 */
	#[Action( tag: 'rest_api_init', priority: 10 )]
	public function register_routes(): void {

		// Agents endpoints.
		register_rest_route(
			RestController::NAMESPACE,
			'/agents',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_list_agents' ),
					'permission_callback' => array( $this, 'check_chat_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_create_agent' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'slug'           => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_title',
						),
						'name'           => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'description'    => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'system_prompt'  => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'provider_id'    => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'model_id'       => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'tool_profile'   => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'temperature'    => array(
							'required' => false,
							'type'     => array( 'number', 'null' ),
						),
						'max_iterations' => array(
							'required' => false,
							'type'     => array( 'integer', 'null' ),
						),
						'greeting'       => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'avatar_icon'    => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'tier_1_tools'   => array(
							'required' => false,
							'type'     => 'array',
							'default'  => array(),
							'items'    => array( 'type' => 'string' ),
						),
						'suggestions'    => array(
							'required' => false,
							'type'     => 'array',
							'default'  => array(),
						),
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/agents/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_agent' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'handle_update_agent' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'             => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'name'           => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'description'    => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'system_prompt'  => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'provider_id'    => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'model_id'       => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'tool_profile'   => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'temperature'    => array(
							'required' => false,
							'type'     => array( 'number', 'null' ),
						),
						'max_iterations' => array(
							'required' => false,
							'type'     => array( 'integer', 'null' ),
						),
						'greeting'       => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'avatar_icon'    => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'tier_1_tools'   => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
						),
						'suggestions'    => array(
							'required' => false,
							'type'     => 'array',
						),
						'enabled'        => array(
							'required' => false,
							'type'     => 'boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete_agent' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Reset built-in agents to factory defaults.
		register_rest_route(
			RestController::NAMESPACE,
			'/agents/reset-defaults',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_reset_defaults' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// List registered abilities for the Tier 1 tools picker.
		register_rest_route(
			RestController::NAMESPACE,
			'/abilities',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_list_abilities' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'search' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Conversation Templates endpoints.
		register_rest_route(
			RestController::NAMESPACE,
			'/conversation-templates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_list_conversation_templates' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'category' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_create_conversation_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'name'   => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'prompt' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/conversation-templates/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'handle_update_conversation_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete_conversation_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Handle GET /agents — list all agents.
	 *
	 * Returns public-safe fields plus suggestions so the chat UI can render
	 * per-agent suggestion cards. System prompt and provider/model config
	 * are excluded for non-admin chat users.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_list_agents(): WP_REST_Response {
		$agents = Agent::get_all();
		$list   = array_map(
			static function ( AgentRow $agent ): array {
				return array(
					'id'          => $agent->id,
					'slug'        => $agent->slug,
					'name'        => $agent->name,
					'description' => $agent->description,
					'avatar_icon' => $agent->avatar_icon,
					'greeting'    => $agent->greeting,
					'suggestions' => $agent->suggestions,
					'is_builtin'  => $agent->is_builtin,
					'enabled'     => $agent->enabled,
				);
			},
			$agents
		);
		return new WP_REST_Response( $list, 200 );
	}

	/**
	 * Handle GET /agents/{id} — get a single agent.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get_agent( WP_REST_Request $request ) {
		$id    = self::get_int_param( $request, 'id' );
		$agent = Agent::get( $id );

		if ( ! $agent ) {
			return new WP_Error(
				'sd_ai_agent_agent_not_found',
				__( 'Agent not found.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( Agent::to_array( $agent ), 200 );
	}

	/**
	 * Handle POST /agents — create a new agent.
	 *
	 * New agents default to the General agent's tier_1_tools when none are
	 * provided, so they start with a usable tool set.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_create_agent( WP_REST_Request $request ) {
		$slug = $request->get_param( 'slug' );

		// Check for duplicate slug.
		// @phpstan-ignore-next-line
		$existing = Agent::get_by_slug( $slug );
		if ( $existing ) {
			return new WP_Error(
				'sd_ai_agent_agent_slug_exists',
				__( 'An agent with this slug already exists.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}

		// Default tier_1_tools to the general agent's tool set.
		$tier_1_tools = $request->get_param( 'tier_1_tools' );
		if ( empty( $tier_1_tools ) ) {
			$tier_1_tools = Agent::get_general_tier_1_tools();
		}

		$id = Agent::create(
			array(
				'slug'           => $slug,
				'name'           => $request->get_param( 'name' ),
				'description'    => $request->get_param( 'description' ) ?? '',
				'system_prompt'  => $request->get_param( 'system_prompt' ) ?? '',
				'provider_id'    => $request->get_param( 'provider_id' ) ?? '',
				'model_id'       => $request->get_param( 'model_id' ) ?? '',
				'tool_profile'   => $request->get_param( 'tool_profile' ) ?? '',
				'temperature'    => $request->get_param( 'temperature' ),
				'max_iterations' => $request->get_param( 'max_iterations' ),
				'greeting'       => $request->get_param( 'greeting' ) ?? '',
				'avatar_icon'    => $request->get_param( 'avatar_icon' ) ?? '',
				'tier_1_tools'   => $tier_1_tools,
				'suggestions'    => $request->get_param( 'suggestions' ) ?? array(),
				'enabled'        => true,
			)
		);

		if ( false === $id ) {
			return new WP_Error(
				'sd_ai_agent_agent_create_failed',
				__( 'Failed to create agent.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$agent = Agent::get( $id );

		if ( ! $agent ) {
			return new WP_Error( 'sd_ai_agent_agent_not_found', __( 'Agent not found after creation.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( Agent::to_array( $agent ), 201 );
	}

	/**
	 * Handle PATCH /agents/{id} — update an agent.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_update_agent( WP_REST_Request $request ) {
		$id   = self::get_int_param( $request, 'id' );
		$data = array();

		$fields = array(
			'name',
			'description',
			'system_prompt',
			'provider_id',
			'model_id',
			'tool_profile',
			'temperature',
			'max_iterations',
			'greeting',
			'avatar_icon',
			'tier_1_tools',
			'suggestions',
			'enabled',
		);

		foreach ( $fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		$updated = Agent::update( $id, $data );

		if ( ! $updated ) {
			return new WP_Error(
				'sd_ai_agent_agent_update_failed',
				__( 'Failed to update agent.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$agent = Agent::get( $id );

		if ( ! $agent ) {
			return new WP_Error( 'sd_ai_agent_agent_not_found', __( 'Agent not found after update.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( Agent::to_array( $agent ), 200 );
	}

	/**
	 * Handle DELETE /agents/{id} — delete an agent.
	 *
	 * The built-in General agent cannot be deleted.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_delete_agent( WP_REST_Request $request ) {
		$id     = self::get_int_param( $request, 'id' );
		$result = Agent::delete( $id );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( ! $result ) {
			return new WP_Error(
				'sd_ai_agent_agent_delete_failed',
				__( 'Failed to delete agent or agent not found.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Handle POST /agents/reset-defaults — reset built-in agents to factory defaults.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_reset_defaults(): WP_REST_Response {
		Agent::reset_defaults();

		$agents = Agent::get_all();
		$list   = array_map( [ Agent::class, 'to_array' ], $agents );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Built-in agents have been reset to factory defaults.', 'superdav-ai-agent' ),
				'agents'  => $list,
			),
			200
		);
	}

	/**
	 * Handle GET /abilities — list registered abilities for the tier 1 tool picker.
	 *
	 * Returns ability id + label + description + category for the autocomplete
	 * search in the agent builder.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_list_abilities( WP_REST_Request $request ): WP_REST_Response {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return new WP_REST_Response( array(), 200 );
		}

		$search = strtolower( (string) ( $request->get_param( 'search' ) ?? '' ) );
		$result = array();

		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();

			// Skip abilities the admin picker should not show.
			if ( ! AbilityVisibility::for_admin_picker( $ability ) ) {
				continue;
			}

			$label = (string) $ability->get_label();
			$desc  = (string) $ability->get_description();
			$cat   = $ability->get_category() ?: 'uncategorized';

			// Apply search filter if provided.
			if ( '' !== $search ) {
				$haystack = strtolower( $name . ' ' . $label . ' ' . $desc . ' ' . $cat );
				if ( ! str_contains( $haystack, $search ) ) {
					continue;
				}
			}

			$result[] = array(
				'id'          => $name,
				'label'       => $label,
				'description' => mb_strlen( $desc ) > 120 ? mb_substr( $desc, 0, 117 ) . '...' : $desc,
				'category'    => $cat,
			);
		}

		// Sort by category, then id.
		usort(
			$result,
			static function ( array $a, array $b ): int {
				$cat_cmp = strcmp( $a['category'], $b['category'] );
				return 0 !== $cat_cmp ? $cat_cmp : strcmp( $a['id'], $b['id'] );
			}
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * List conversation templates, optionally filtered by category.
	 */
	public function handle_list_conversation_templates( WP_REST_Request $request ): WP_REST_Response {
		$category = $request->get_param( 'category' );
		// @phpstan-ignore-next-line
		$templates = ConversationTemplate::get_all( $category ?: null );

		return new WP_REST_Response( $templates, 200 );
	}

	/**
	 * Create a conversation template.
	 */
	public function handle_create_conversation_template( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = $request->get_json_params();
		$id   = ConversationTemplate::create( $data );

		if ( false === $id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create conversation template.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( ConversationTemplate::get( $id ), 201 );
	}

	/**
	 * Update a conversation template.
	 */
	public function handle_update_conversation_template( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = self::get_int_param( $request, 'id' );
		$data = $request->get_json_params();

		if ( ! ConversationTemplate::update( $id, $data ) ) {
			return new WP_Error( 'update_failed', __( 'Failed to update conversation template.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( ConversationTemplate::get( $id ), 200 );
	}

	/**
	 * Delete a conversation template. Built-in templates cannot be deleted.
	 */
	public function handle_delete_conversation_template( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = self::get_int_param( $request, 'id' );

		if ( ! ConversationTemplate::delete( $id ) ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete conversation template. Built-in templates cannot be deleted.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}
}
