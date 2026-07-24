<?php

declare(strict_types=1);
/**
 * REST API controller for sessions, messages, folders, sharing, export/import,
 * job-status, process, and tool confirmation.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use SdAiAgent\Abilities\OptionsAbilities;
use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\ConversationDisplaySanitizer;
use SdAiAgent\Core\ConversationSerializer;
use SdAiAgent\Core\ConversationTrimmer;
use SdAiAgent\Core\CostCalculator;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\Export;
use SdAiAgent\Core\Settings;
use SdAiAgent\Core\ToolPermissionResolver;
use SdAiAgent\Models\ActiveJobRepository;
use SdAiAgent\Models\Agent;
use SdAiAgent\Models\DTO\ActiveJobRow;
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
 * Manages sessions, messages, folders, sharing, export/import, job-status,
 * process, and tool confirmation via REST.
 *
 * Uses #[Handler] + #[Action] because this controller serves multiple
 * basenames (/sessions, /run, /process, /job).
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class SessionController {

	use PermissionTrait;

	/** Maximum safe technical-detail length returned by job status polling. */
	private const JOB_ERROR_DETAIL_MAX_LENGTH = 180;

	/** Maximum automatic resume attempts for a crashed background job. */
	private const JOB_AUTO_RESUME_MAX_ATTEMPTS = 2;

	/** Public anonymous chat session TTL in seconds. */
	private const PUBLIC_CHAT_SESSION_TTL = DAY_IN_SECONDS;

	/** Public anonymous chat HMAC purpose string. */
	private const PUBLIC_CHAT_TOKEN_PURPOSE = 'public_chat_session_v1';

	/** @var Database Injected database dependency. */
	private Database $database;

	/** @var Settings Injected settings dependency. */
	private Settings $settings;

	/**
	 * Constructor — receives injected dependencies from the DI container.
	 *
	 * @param Database      $database  Injected Database service.
	 * @param Settings|null $settings Injected Settings service.
	 */
	public function __construct( Database $database, ?Settings $settings = null ) {
		$this->database = $database;
		$this->settings = $settings ?? Settings::instance();
	}

	/**
	 * Register REST routes.
	 */
	#[Action( tag: 'rest_api_init', priority: 10 )]
	public function register_routes(): void {

		// Sessions endpoints.
		register_rest_route(
			RestController::NAMESPACE,
			'/sessions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_list_sessions' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'status' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'active',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'folder' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'search' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'pinned' => array(
							'required' => false,
							'type'     => 'boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_create_session' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'title'       => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'provider_id' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'model_id'    => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'agent_id'    => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/folders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_folders' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_bulk_sessions' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'ids'    => array(
						'required' => true,
						'type'     => 'array',
					),
					'action' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'folder' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/trash',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_empty_trash' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_session' ),
					'permission_callback' => array( $this, 'check_session_permission' ),
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
					'callback'            => array( $this, 'handle_update_session' ),
					'permission_callback' => array( $this, 'check_session_permission' ),
					'args'                => array(
						'id'     => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'title'  => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'pinned' => array(
							'required' => false,
							'type'     => 'boolean',
						),
						'folder' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete_session' ),
					'permission_callback' => array( $this, 'check_session_permission' ),
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

		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/(?P<id>\d+)/compact',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_compact_session' ),
				'permission_callback' => array( $this, 'check_session_permission' ),
				'args'                => array(
					'id'          => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'provider_id' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'model_id'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Export endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/(?P<id>\d+)/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_export_session' ),
				'permission_callback' => array( $this, 'check_session_permission' ),
				'args'                => array(
					'id'     => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'format' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'json',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Import endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_import_session' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Shared sessions list endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/shared',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_shared_sessions' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Share / unshare a session.
		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/(?P<id>\d+)/share',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_share_session' ),
					'permission_callback' => array( $this, 'check_session_owner_permission' ),
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
					'callback'            => array( $this, 'handle_unshare_session' ),
					'permission_callback' => array( $this, 'check_session_owner_permission' ),
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

		// Job status endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/job/(?P<id>[a-f0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_job_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Process endpoint (background worker).
		register_rest_route(
			RestController::NAMESPACE,
			'/process',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_process' ),
				'permission_callback' => array( $this, 'check_process_permission' ),
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'token'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Run endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_run' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'message'            => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'history'            => array(
						'required' => false,
						'type'     => 'array',
						'default'  => array(),
					),
					'abilities'          => array(
						'required' => false,
						'type'     => 'array',
						'default'  => array(),
					),
					'system_instruction' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'max_iterations'     => array(
						'required'          => false,
						'type'              => 'integer',
						// No 'default' here: when the client omits this param,
						// $request->get_param('max_iterations') returns null and
						// handle_run()/handle_process() fall back to the saved
						// Settings value (default 100). A REST default of 10
						// would short-circuit that fallback and cap user-facing
						// tool calls at ~10, surfacing as a spurious
						// "maximum number of tool calls" exit after a handful
						// of tool calls even when Settings is set to 100.
						'sanitize_callback' => 'absint',
					),
					'session_id'         => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'provider_id'        => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'model_id'           => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page_context'       => array(
						'required'          => false,
						'type'              => array( 'object', 'string' ),
						'default'           => array(),
						'sanitize_callback' => array( RestController::class, 'sanitize_page_context' ),
					),
					'agent_id'           => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'attachments'        => array(
						'required' => false,
						'type'     => 'array',
						'default'  => array(),
					),
					'client_abilities'   => array(
						'required' => false,
						'type'     => 'array',
						'default'  => array(),
					),
				),
			)
		);

		// Public anonymous docs/customer chat endpoints. These are disabled by
		// default and use their own token flow instead of WordPress auth cookies.
		register_rest_route(
			RestController::NAMESPACE,
			'/public-chat/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_public_chat_session' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/public-chat/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_public_chat_run' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'token'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/public-chat/job/(?P<id>[a-f0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_public_chat_job_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Tool confirmation endpoints.
		register_rest_route(
			RestController::NAMESPACE,
			'/job/(?P<id>[a-f0-9-]+)/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_confirm_tool' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'           => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'always_allow' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/job/(?P<id>[a-f0-9-]+)/reject',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_reject_tool' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Interrupt endpoint — inject a user message into a running job.
		register_rest_route(
			RestController::NAMESPACE,
			'/job/(?P<id>[a-f0-9-]+)/interrupt',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_interrupt' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Resume a recoverable error from the durable session paused state.
		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/(?P<id>\d+)/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_resume_recoverable_job' ),
				'permission_callback' => array( $this, 'check_session_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Active-job reconnection endpoints (t202).
		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/active-jobs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_active_jobs' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/(?P<id>\d+)/active-job',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_session_active_job' ),
				'permission_callback' => array( $this, 'check_session_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Public customer chat config for static documentation embeds.
		register_rest_route(
			RestController::NAMESPACE,
			'/public-chat/config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_public_chat_config' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return public embed configuration visible to static docs pages.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_public_chat_config( WP_REST_Request $request ): WP_REST_Response {
		$config  = $this->get_public_chat_settings();
		$origin  = $this->get_public_chat_request_origin( $request );
		$enabled = ! empty( $config['enabled'] ) && ! empty( $config['collections'] ) && $this->public_origin_is_allowed( $origin, $config['origins'] );

		return $this->add_public_chat_cors(
			new WP_REST_Response(
				array(
					'enabled'     => $enabled,
					'embed_id'    => sanitize_key( (string) $this->settings->get( 'public_chat_embed_id' ) ),
					'agent_id'    => (int) $config['agent_id'],
					'collections' => $config['collections'],
				),
				200
			),
			$origin,
			$config['origins']
		);
	}

	/**
	 * Handle GET /sessions — list sessions for current user.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_list_sessions( WP_REST_Request $request ): WP_REST_Response {
		$filters = array();

		if ( $request->has_param( 'status' ) ) {
			$filters['status'] = $request->get_param( 'status' );
		}
		if ( $request->has_param( 'folder' ) ) {
			$filters['folder'] = $request->get_param( 'folder' );
		}
		if ( $request->has_param( 'search' ) ) {
			$filters['search'] = $request->get_param( 'search' );
		}
		if ( $request->has_param( 'pinned' ) ) {
			$filters['pinned'] = $request->get_param( 'pinned' );
		}

		$sessions = $this->database->list_sessions( get_current_user_id(), $filters );

		return new WP_REST_Response( $sessions, 200 );
	}

	/**
	 * Handle GET /sessions/folders — list folders for current user.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_list_folders(): WP_REST_Response {
		$folders = $this->database->list_folders( get_current_user_id() );

		return new WP_REST_Response( $folders, 200 );
	}

	/**
	 * Handle POST /sessions/bulk — bulk update sessions.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_bulk_sessions( WP_REST_Request $request ) {
		// @phpstan-ignore-next-line
		$ids    = array_map( 'absint', $request->get_param( 'ids' ) );
		$action = $request->get_param( 'action' );

		$data = array();
		switch ( $action ) {
			case 'archive':
				$data['status'] = 'archived';
				break;
			case 'restore':
				$data['status'] = 'active';
				break;
			case 'trash':
				$data['status'] = 'trash';
				break;
			case 'pin':
				$data['pinned'] = 1;
				break;
			case 'unpin':
				$data['pinned'] = 0;
				break;
			case 'move':
				// @phpstan-ignore-next-line
				$data['folder'] = sanitize_text_field( $request->get_param( 'folder' ) ?? '' );
				break;
			default:
				return new WP_Error( 'sd_ai_agent_invalid_action', __( 'Invalid bulk action.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		$count = $this->database->bulk_update_sessions( $ids, get_current_user_id(), $data );

		return new WP_REST_Response( array( 'updated' => $count ), 200 );
	}

	/**
	 * Handle DELETE /sessions/trash — empty trash for current user.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_empty_trash(): WP_REST_Response {
		$count = $this->database->empty_trash( get_current_user_id() );

		return new WP_REST_Response( array( 'deleted' => $count ), 200 );
	}

	/**
	 * Handle GET /sessions/{id} — get full session with messages.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get_session( WP_REST_Request $request ) {
		$session_id = self::get_int_param( $request, 'id' );
		$session    = $this->database->get_session( $session_id );

		if ( ! $session ) {
			return new WP_Error(
				'sd_ai_agent_session_not_found',
				__( 'Session not found.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		$shared    = Database::get_shared_session( (int) $session->id );
		$is_shared = $shared !== null;

		$messages = json_decode( $session->messages, true ) ?: array();
		if ( ! is_array( $messages ) ) {
			$messages = array();
		}

		return new WP_REST_Response(
			array(
				'id'          => (int) $session->id,
				'title'       => $session->title,
				'provider_id' => $session->provider_id,
				'model_id'    => $session->model_id,
				'messages'    => ConversationDisplaySanitizer::sanitize_messages( $messages ),
				'tool_calls'  => json_decode( $session->tool_calls, true ) ?: array(),
				'token_usage' => array(
					'prompt'     => (int) ( $session->prompt_tokens ?? 0 ),
					'completion' => (int) ( $session->completion_tokens ?? 0 ),
				),
				'is_shared'   => $is_shared,
				'shared_by'   => $is_shared ? (int) $shared->shared_by : null,
				'created_at'  => $session->created_at,
				'updated_at'  => $session->updated_at,
			),
			200
		);
	}

	/**
	 * Handle POST /sessions — create a new session.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_create_session( WP_REST_Request $request ) {
		$provider_id = $request->get_param( 'provider_id' ) ?? '';
		$model_id    = $request->get_param( 'model_id' ) ?? '';

		// If an agent is selected, resolve its provider/model overrides so the
		// session is stored with the agent's effective provider/model rather than
		// the caller's pre-agent selection.
		// @phpstan-ignore-next-line
		$agent_id = (int) ( $request->get_param( 'agent_id' ) ?? 0 );
		if ( $agent_id > 0 ) {
			$agent_options = Agent::get_loop_options( $agent_id );
			if ( ! empty( $agent_options['provider_id'] ) ) {
				$provider_id = $agent_options['provider_id'];
			}
			if ( ! empty( $agent_options['model_id'] ) ) {
				$model_id = $agent_options['model_id'];
			}
		}

		$session_id = $this->database->create_session(
			array(
				'user_id'     => get_current_user_id(),
				'title'       => $request->get_param( 'title' ),
				'provider_id' => $provider_id,
				'model_id'    => $model_id,
			)
		);

		if ( ! $session_id ) {
			return new WP_Error(
				'sd_ai_agent_session_create_failed',
				__( 'Failed to create session.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$session = $this->database->get_session( $session_id );

		if ( ! $session ) {
			return new WP_Error( 'sd_ai_agent_session_not_found', __( 'Session not found after creation.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'id'          => (int) $session->id,
				'title'       => $session->title,
				'provider_id' => $session->provider_id,
				'model_id'    => $session->model_id,
				'messages'    => array(),
				'tool_calls'  => array(),
				'created_at'  => $session->created_at,
				'updated_at'  => $session->updated_at,
			),
			201
		);
	}

	/**
	 * Handle POST /sessions/{id}/compact — create a bounded continuation session.
	 *
	 * The source transcript is read from the server-side session row and reduced to
	 * one deterministic context seed. The browser never submits the full transcript
	 * back to `/run`, which prevents `/compact` itself from hitting provider input
	 * limits or logging raw attachment/tool payloads.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_compact_session( WP_REST_Request $request ) {
		$source_session_id = self::get_int_param( $request, 'id' );
		$source_session    = $this->database->get_session( $source_session_id );

		if ( ! $source_session ) {
			return new WP_Error(
				'sd_ai_agent_session_not_found',
				__( 'Session not found.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		$decoded_messages = json_decode( (string) $source_session->messages, true );
		$source_messages  = is_array( $decoded_messages ) ? array_values( array_filter( $decoded_messages, 'is_array' ) ) : array();

		if ( empty( $source_messages ) ) {
			return new WP_Error(
				'sd_ai_agent_compact_empty_session',
				__( 'This conversation has no saved messages to compact.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		$compacted     = ConversationTrimmer::compact_serialized_history( $source_messages );
		$seed_messages = $compacted['messages'];
		if ( empty( $seed_messages ) ) {
			return new WP_Error(
				'sd_ai_agent_compact_failed',
				__( 'Failed to build compact conversation context.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$provider_id = (string) ( $request->get_param( 'provider_id' ) ?: $source_session->provider_id );
		$model_id    = (string) ( $request->get_param( 'model_id' ) ?: $source_session->model_id );
		$title       = $this->build_compacted_session_title( (string) $source_session->title );

		$new_session_id = $this->database->create_session(
			array(
				'user_id'     => get_current_user_id(),
				'title'       => $title,
				'provider_id' => sanitize_text_field( $provider_id ),
				'model_id'    => sanitize_text_field( $model_id ),
			)
		);

		if ( ! $new_session_id ) {
			return new WP_Error(
				'sd_ai_agent_session_create_failed',
				__( 'Failed to create compacted session.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		if ( ! $this->database->append_to_session( (int) $new_session_id, $seed_messages, array() ) ) {
			$this->database->delete_session( (int) $new_session_id );
			return new WP_Error(
				'sd_ai_agent_compact_failed',
				__( 'Failed to save compact conversation context.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$new_session = $this->database->get_session( (int) $new_session_id );
		if ( ! $new_session ) {
			return new WP_Error(
				'sd_ai_agent_session_not_found',
				__( 'Session not found after compaction.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'id'             => (int) $new_session->id,
				'title'          => $new_session->title,
				'provider_id'    => $new_session->provider_id,
				'model_id'       => $new_session->model_id,
				'messages'       => ConversationDisplaySanitizer::sanitize_messages( $seed_messages ),
				'tool_calls'     => array(),
				'token_usage'    => array(
					'prompt'     => 0,
					'completion' => 0,
				),
				'compacted_from' => $source_session_id,
				'compaction'     => $compacted['meta'],
				'created_at'     => $new_session->created_at,
				'updated_at'     => $new_session->updated_at,
			),
			201
		);
	}

	/** Build a safe title for a server-compacted continuation session. */
	private function build_compacted_session_title( string $source_title ): string {
		$source_title = sanitize_text_field( $source_title );
		if ( '' === $source_title ) {
			$source_title = __( 'conversation', 'superdav-ai-agent' );
		}

		$title = sprintf(
			/* translators: %s: original conversation title. */
			__( 'Compacted: %s', 'superdav-ai-agent' ),
			$source_title
		);

		return strlen( $title ) > 190 ? substr( $title, 0, 189 ) . '…' : $title;
	}

	/**
	 * Handle PATCH /sessions/{id} — update session fields.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_update_session( WP_REST_Request $request ) {
		$session_id = self::get_int_param( $request, 'id' );

		$data = array();
		if ( $request->has_param( 'title' ) ) {
			$data['title'] = $request->get_param( 'title' );
		}
		if ( $request->has_param( 'status' ) ) {
			$status = $request->get_param( 'status' );
			if ( in_array( $status, array( 'active', 'archived', 'trash' ), true ) ) {
				$data['status'] = $status;
			}
		}
		if ( $request->has_param( 'pinned' ) ) {
			$data['pinned'] = $request->get_param( 'pinned' ) ? 1 : 0;
		}
		if ( $request->has_param( 'folder' ) ) {
			$data['folder'] = $request->get_param( 'folder' );
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'sd_ai_agent_no_data', __( 'No fields to update.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		$updated = $this->database->update_session( $session_id, $data );

		if ( ! $updated ) {
			return new WP_Error(
				'sd_ai_agent_session_update_failed',
				__( 'Failed to update session.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$session = $this->database->get_session( $session_id );

		if ( ! $session ) {
			return new WP_Error( 'sd_ai_agent_session_not_found', __( 'Session not found after update.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'id'          => (int) $session->id,
				'title'       => $session->title,
				'provider_id' => $session->provider_id,
				'model_id'    => $session->model_id,
				'status'      => $session->status,
				'pinned'      => (bool) (int) $session->pinned,
				'folder'      => $session->folder,
				'created_at'  => $session->created_at,
				'updated_at'  => $session->updated_at,
			),
			200
		);
	}

	/**
	 * Handle DELETE /sessions/{id} — delete a session.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_delete_session( WP_REST_Request $request ) {
		$session_id = self::get_int_param( $request, 'id' );

		$deleted = $this->database->delete_session( $session_id );

		if ( ! $deleted ) {
			return new WP_Error(
				'sd_ai_agent_session_delete_failed',
				__( 'Failed to delete session.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Handle GET /sessions/shared — list all sessions shared with admins.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_list_shared_sessions(): WP_REST_Response {
		$sessions = Database::list_shared_sessions();

		return new WP_REST_Response( $sessions, 200 );
	}

	/**
	 * Handle POST /sessions/{id}/share — share a session with all admins.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_share_session( WP_REST_Request $request ) {
		$session_id = self::get_int_param( $request, 'id' );
		$success    = Database::share_session( $session_id, get_current_user_id() );

		if ( ! $success ) {
			return new WP_Error(
				'sd_ai_agent_share_failed',
				__( 'Failed to share session.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( array( 'shared' => true ), 200 );
	}

	/**
	 * Handle DELETE /sessions/{id}/share — unshare a session.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_unshare_session( WP_REST_Request $request ) {
		$session_id = self::get_int_param( $request, 'id' );
		$success    = Database::unshare_session( $session_id );

		if ( ! $success ) {
			return new WP_Error(
				'sd_ai_agent_unshare_failed',
				__( 'Failed to unshare session.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( array( 'shared' => false ), 200 );
	}

	/**
	 * Handle GET /sessions/{id}/export — export a session.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_export_session( WP_REST_Request $request ) {
		$session_id = self::get_int_param( $request, 'id' );
		$format     = $request->get_param( 'format' ) ?: 'json';
		$session    = $this->database->get_session( $session_id );

		if ( ! $session ) {
			return new WP_Error( 'sd_ai_agent_session_not_found', __( 'Session not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		// @phpstan-ignore-next-line
		$result = Export::export( $session, $format );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle POST /sessions/import — import a session.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_import_session( WP_REST_Request $request ) {
		$data = $request->get_json_params();

		if ( empty( $data ) ) {
			return new WP_Error( 'sd_ai_agent_import_empty', __( 'No import data provided.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		$session_id = Export::import_json( $data, get_current_user_id() );

		if ( is_wp_error( $session_id ) ) {
			return $session_id;
		}

		$session = $this->database->get_session( $session_id );

		if ( ! $session ) {
			return new WP_Error( 'sd_ai_agent_session_not_found', __( 'Session not found after import.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'id'          => (int) $session->id,
				'title'       => $session->title,
				'provider_id' => $session->provider_id,
				'model_id'    => $session->model_id,
				'created_at'  => $session->created_at,
				'updated_at'  => $session->updated_at,
			),
			201
		);
	}

	/**
	 * Handle the /job/{id} polling endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_job_status( WP_REST_Request $request ) {
		$job_id = self::get_string_param( $request, 'id' );
		$job    = get_transient( RestController::JOB_PREFIX . $job_id );

		if ( false === $job || ! is_array( $job ) ) {
			// Transient expired or was never set — fall back to the DB (source of truth).
			$db_row = ActiveJobRepository::get_by_job_id( $job_id );
			if ( null === $db_row ) {
				return new WP_Error(
					'sd_ai_agent_job_not_found',
					__( 'Job not found or expired.', 'superdav-ai-agent' ),
					array( 'status' => 404 )
				);
			}
			return $this->job_status_from_db_row( $job_id, $db_row );
		}

		/** @var array<string, mixed> $job */
		$db_row = ActiveJobRepository::get_by_job_id( $job_id );
		if (
			null !== $db_row &&
			in_array( $db_row->status, array( 'complete', 'error', 'interrupted', 'abandoned' ), true )
		) {
			delete_transient( RestController::JOB_PREFIX . $job_id );
			return $this->job_status_from_db_row( $job_id, $db_row );
		}

		$response = array( 'status' => $job['status'] );

		// Include live tool call progress for all statuses that have it.
		if ( ! empty( $job['tool_calls'] ) ) {
			$response['tool_calls'] = $job['tool_calls'];
		}
		if ( ! empty( $job['messages'] ) ) {
			$response['messages'] = $job['messages'];
		}

		if ( 'awaiting_confirmation' === $job['status'] && isset( $job['pending_tools'] ) ) {
			$response['pending_tools'] = $job['pending_tools'];
			return new WP_REST_Response( $response, 200 );
		}

		if ( 'awaiting_client_tools' === $job['status'] && isset( $job['pending_client_tool_calls'] ) ) {
			// Surface the client-side pending calls so the browser can execute
			// JS abilities and POST results back via /chat/tool-result.
			$response['pending_client_tool_calls'] = $job['pending_client_tool_calls'];
			return new WP_REST_Response( $response, 200 );
		}

		if ( 'complete' === $job['status'] && isset( $job['result'] ) ) {
			// @phpstan-ignore-next-line
			$response['reply'] = $job['result']['reply'] ?? '';
			// @phpstan-ignore-next-line
			$history             = $job['result']['history'] ?? array();
			$response['history'] = is_array( $history ) ? ConversationDisplaySanitizer::sanitize_messages( $history ) : array();
			// @phpstan-ignore-next-line
			$response['tool_calls'] = $job['result']['tool_calls'] ?? array();
			// @phpstan-ignore-next-line
			$response['messages'] = $job['result']['messages'] ?? array();
			// @phpstan-ignore-next-line
			$response['session_id'] = $job['result']['session_id'] ?? null;
			// @phpstan-ignore-next-line
			$response['token_usage'] = $job['result']['token_usage'] ?? array(
				'prompt'     => 0,
				'completion' => 0,
			);
			// @phpstan-ignore-next-line
			$response['model_id'] = $job['result']['model_id'] ?? ( $job['params']['model_id'] ?? '' );
			// @phpstan-ignore-next-line
			$response['iterations_used'] = $job['result']['iterations_used'] ?? 0;

			// Include generated title if one was produced.
			// @phpstan-ignore-next-line
			if ( isset( $job['result']['generated_title'] ) ) {
				$response['generated_title'] = $job['result']['generated_title'];
			}

			// Compute cost estimate from token usage and model.
			$model                     = $response['model_id'];
			$tokens                    = $response['token_usage'];
			$response['cost_estimate'] = CostCalculator::calculate_cost(
				// @phpstan-ignore-next-line
				$model,
				// @phpstan-ignore-next-line
				(int) ( $tokens['prompt'] ?? 0 ),
				// @phpstan-ignore-next-line
				(int) ( $tokens['completion'] ?? 0 )
			);

			// Clean up — result has been delivered.
			delete_transient( RestController::JOB_PREFIX . $job_id );
			ActiveJobRepository::delete( $job_id );
		}

		if ( 'error' === $job['status'] && isset( $job['error'] ) ) {
			$job_session_id = $this->get_job_session_id( $job );
			$error_context  = array(
				'job_id'        => $job_id,
				'session_id'    => $job_session_id,
				'error_context' => $job['error_context'] ?? null,
			);

			if ( $job_session_id > 0 ) {
				$response['session_id'] = $job_session_id;
			}
			if ( ! empty( $job['recoverable'] ) ) {
				$response['recoverable'] = true;
			}

			/**
			 * Filter the error message returned to the chat client.
			 *
			 * Companion plugins that raise their own AI-related errors
			 * (usage caps, billing, content-policy gates, etc.) can hook
			 * here to rewrite the message into something more actionable
			 * for the user — for example, by appending a Markdown link to
			 * a checkout or settings page. The frontend renders the
			 * returned string through its Markdown pipeline, so producers
			 * may use Markdown syntax including links.
			 *
			 * @since 1.11.0
			 *
			 * @param string               $message       Raw error message from AgentLoop.
			 * @param array<string, mixed> $error_context Context: job_id, session_id, error_context.
			 */
			$response['message'] = (string) apply_filters(
				'sd_ai_agent_chat_error_message',
				(string) $job['error'],
				$error_context
			);

			// Forward backtrace context so the frontend can display
			// actionable debugging details (file, line, abbreviated stack).
			if ( ! empty( $job['error_context'] ) ) {
				$response['error_context'] = $job['error_context'];
			}

			// Clean up.
			delete_transient( RestController::JOB_PREFIX . $job_id );
			ActiveJobRepository::delete( $job_id );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Build a job-status REST response from a DB row (transient-expiry fallback).
	 *
	 * Called when the transient is gone but the DB record still exists.
	 * For 'complete' jobs the full reply/history are NOT stored in the DB
	 * (they are already in the session's messages column) — the frontend
	 * receives status='complete' with from_db=true and should reload the session.
	 *
	 * @param string       $job_id Job UUID.
	 * @param ActiveJobRow $row    DTO returned by ActiveJobRepository::get_by_job_id().
	 * @return WP_REST_Response
	 */
	private function job_status_from_db_row( string $job_id, ActiveJobRow $row ): WP_REST_Response {
		$status   = $row->status;
		$response = [
			'status'     => $status,
			'from_db'    => true,
			'session_id' => $row->session_id,
		];

		if ( in_array( $status, array( 'interrupted', 'abandoned' ), true ) && $this->maybe_dispatch_checkpoint_resume( $job_id, $row ) ) {
			return new WP_REST_Response(
				array(
					'status'          => 'processing',
					'from_db'         => true,
					'auto_resumed'    => true,
					'original_status' => $status,
					'session_id'      => $row->session_id,
				),
				202
			);
		}

		// Include tool-call progress when present.
		$tool_calls = json_decode( $row->tool_calls, true );
		if ( is_array( $tool_calls ) && ! empty( $tool_calls ) ) {
			$response['tool_calls'] = $tool_calls;
		}

		if ( 'awaiting_confirmation' === $status ) {
			$pending = json_decode( $row->pending_tools, true );
			if ( is_array( $pending ) ) {
				$response['pending_tools'] = $pending;
			}
		}

		if ( 'awaiting_client_tools' === $status ) {
			// pending_tools column reused — contains pending_client_tool_calls JSON.
			$pending = json_decode( $row->pending_tools, true );
			if ( is_array( $pending ) ) {
				$response['pending_client_tool_calls'] = $pending;
			}
		}

		if ( 'error' === $status ) {
			$error_detail        = $this->sanitize_job_error_detail( (string) $row->error );
			$response['message'] = '' !== $error_detail
				? $error_detail
				: __( 'The background agent job failed before it could finish. Please retry the request.', 'superdav-ai-agent' );

			if ( $this->session_has_recoverable_paused_state( $row->session_id ) ) {
				$response['recoverable'] = true;
			}
		}

		if ( in_array( $status, array( 'interrupted', 'abandoned' ), true ) ) {
			$error_detail                = $this->sanitize_job_error_detail( (string) $row->error );
			$response['status']          = 'error';
			$response['original_status'] = $status;
			$response['message']         = '' !== $error_detail
				? sprintf(
					/* translators: %s: technical interruption detail. */
					__( 'The background agent job stopped before it could finish. Technical detail: %s', 'superdav-ai-agent' ),
					$error_detail
				)
				: __( 'The background agent job stopped before it could finish. Please retry the request.', 'superdav-ai-agent' );
		}

		// Delete DB row on terminal-state delivery (mirrors the transient cleanup).
		if ( in_array( $status, array( 'complete', 'error', 'interrupted', 'abandoned' ), true ) ) {
			ActiveJobRepository::delete( $job_id );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Dispatch a crashed job from a safe durable checkpoint when retry budget remains.
	 *
	 * @param string       $job_id Job UUID.
	 * @param ActiveJobRow $row    Active-job row.
	 * @return bool True when a resume worker was dispatched.
	 */
	private function maybe_dispatch_checkpoint_resume( string $job_id, ActiveJobRow $row ): bool {
		if ( $row->resume_attempts >= self::JOB_AUTO_RESUME_MAX_ATTEMPTS || ! $this->is_auto_resumable_checkpoint_phase( $row->checkpoint_phase ) ) {
			return false;
		}

		$checkpoint = json_decode( (string) $row->checkpoint, true );
		if ( ! is_array( $checkpoint ) || empty( $checkpoint['history'] ) || ! is_array( $checkpoint['history'] ) ) {
			return false;
		}

		$token = wp_generate_password( 40, false );
		$job   = array(
			'status'            => 'processing',
			'token'             => $token,
			'user_id'           => $row->user_id,
			'tool_calls'        => json_decode( $row->tool_calls, true ) ?: array(),
			'messages'          => $checkpoint['message_log'] ?? array(),
			'checkpoint_resume' => true,
			'checkpoint_state'  => $checkpoint,
			'params'            => array(
				'message'            => '',
				'history'            => array(),
				'abilities'          => array(),
				'system_instruction' => '',
				'bootstrap_prompt'   => '',
				'max_iterations'     => $checkpoint['iterations_remaining'] ?? null,
				'session_id'         => $row->session_id,
				'provider_id'        => $checkpoint['provider_id'] ?? '',
				'model_id'           => $checkpoint['model_id'] ?? '',
				'page_context'       => $checkpoint['page_context'] ?? array(),
				'agent_id'           => 0,
				'attachments'        => array(),
				'client_abilities'   => $checkpoint['client_abilities'] ?? array(),
			),
		);

		if ( ! ActiveJobRepository::claim_resume_attempt( $job_id, self::JOB_AUTO_RESUME_MAX_ATTEMPTS ) ) {
			return false;
		}

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );
		wp_remote_post(
			rest_url( RestController::NAMESPACE . '/process' ),
			array(
				'timeout'  => 0.01,
				'blocking' => false,
				'body'     => (string) wp_json_encode(
					array(
						'job_id' => $job_id,
						'token'  => $token,
					)
				),
				'headers'  => array( 'Content-Type' => 'application/json' ),
			)
		);

		return true;
	}

	/**
	 * Determine whether a checkpoint phase can be resumed without replaying tools.
	 *
	 * @param string $phase Durable checkpoint phase.
	 * @return bool True when automatic resume is safe.
	 */
	private function is_auto_resumable_checkpoint_phase( string $phase ): bool {
		return in_array(
			$phase,
			array(
				AgentLoop::CHECKPOINT_BEFORE_PROVIDER_CALL,
				AgentLoop::CHECKPOINT_TOOL_RESPONSE_RECORDED,
			),
			true
		);
	}

	/**
	 * Scrub active-job technical details before they are returned to REST clients.
	 *
	 * Active-job rows may contain shutdown or provider details written from low-level
	 * code paths. Keep the useful phase/status tokens but strip paths, stack traces,
	 * credential-shaped fragments, and any known secret option names.
	 *
	 * @param string $detail Raw active_jobs.error value.
	 * @return string Bounded, client-safe summary, or empty string when fully redacted.
	 */
	private function sanitize_job_error_detail( string $detail ): string {
		$detail = trim( wp_strip_all_tags( $detail ) );
		if ( '' === $detail ) {
			return '';
		}

		foreach ( OptionsAbilities::get_secret_read_blocklist() as $secret_option ) {
			if ( preg_match( '/\b' . preg_quote( $secret_option, '/' ) . '\b/i', $detail ) ) {
				return '';
			}
		}

		$detail = preg_replace( '/#[0-9]+\s+[^;]+/', '[stack_trace]', $detail ) ?? $detail;
		$detail = preg_replace( '#\b(?:/[^\s;:]+){2,}(?::[0-9]+)?#', '[path]', $detail ) ?? $detail;
		$detail = preg_replace( '#\b[A-Za-z]:\\\\[^\s;]+#', '[path]', $detail ) ?? $detail;
		$detail = preg_replace( '/\b(?:api[_-]?key|token|secret|password|credential|authorization)\s*[:=]\s*[^\s;]+/i', '[redacted_credential]', $detail ) ?? $detail;
		$detail = preg_replace( '/\bsk-[A-Za-z0-9_-]{8,}\b/', '[redacted_token]', $detail ) ?? $detail;
		$detail = preg_replace( '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', '[redacted_email]', $detail ) ?? $detail;
		$detail = sanitize_text_field( $detail );
		$detail = preg_replace( '/\s+/', ' ', $detail ) ?? $detail;
		$detail = trim( $detail );

		if ( '' === $detail || preg_match( '/^\[(?:path|stack_trace|redacted_credential|redacted_token|redacted_email)\]$/', $detail ) ) {
			return '';
		}

		if ( strlen( $detail ) > self::JOB_ERROR_DETAIL_MAX_LENGTH ) {
			$detail = substr( $detail, 0, self::JOB_ERROR_DETAIL_MAX_LENGTH - 3 ) . '...';
		}

		return $detail;
	}

	/**
	 * Get sanitized public chat settings.
	 *
	 * @return array{enabled: bool, origins: list<string>, provider_id: string, model_id: string, agent_id: int, collections: list<string>, abilities: list<string>, iterations: int, message_length: int, rate_limit: int}
	 */
	private function get_public_chat_settings(): array {
		$settings = Settings::instance()->get();
		$allowed  = $settings['public_chat_allowed_abilities'] ?? array( 'sd-ai-agent/knowledge-search' );
		$allowed  = is_array( $allowed ) ? $this->sanitize_public_chat_string_list( $allowed, 'sanitize_text_field' ) : array();
		if ( empty( $allowed ) ) {
			$allowed = array( 'sd-ai-agent/knowledge-search' );
		}

		$collections = $settings['public_chat_collection_ids'] ?? array();
		$collections = is_array( $collections ) ? $this->sanitize_public_chat_string_list( $collections, 'sanitize_key' ) : array();

		$origins = $settings['public_chat_allowed_origins'] ?? array();
		$origins = is_array( $origins ) ? $this->sanitize_public_chat_string_list( $origins, 'sanitize_text_field' ) : array();

		return array(
			'enabled'        => (bool) ( $settings['public_chat_enabled'] ?? false ),
			'origins'        => $origins,
			'provider_id'    => sanitize_text_field( (string) ( $settings['public_chat_provider_id'] ?? '' ) ),
			'model_id'       => sanitize_text_field( (string) ( $settings['public_chat_model_id'] ?? '' ) ),
			'agent_id'       => absint( $settings['public_chat_agent_id'] ?? 0 ),
			'collections'    => $collections,
			'abilities'      => $allowed,
			'iterations'     => max( 1, min( 8, (int) ( $settings['public_chat_max_iterations'] ?? 4 ) ) ),
			'message_length' => max( 1, min( 8000, (int) ( $settings['public_chat_message_max_length'] ?? 2000 ) ) ),
			'rate_limit'     => max( 1, min( 60, (int) ( $settings['public_chat_rate_limit_per_min'] ?? 10 ) ) ),
		);
	}

	/**
	 * Sanitize a mixed public-chat list to non-empty strings.
	 *
	 * @param array<int|string, mixed> $values   Raw values.
	 * @param callable(string):string  $sanitize Sanitizer callback.
	 * @return list<string>
	 */
	private function sanitize_public_chat_string_list( array $values, callable $sanitize ): array {
		$clean = array();
		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$item = $sanitize( (string) $value );
			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}

		return $clean;
	}

	/** Validate public chat is enabled and the request origin is allowed. */
	private function check_public_chat_available( WP_REST_Request $request ): true|WP_Error {
		$config = $this->get_public_chat_settings();
		if ( empty( $config['enabled'] ) ) {
			return new WP_Error( 'sd_ai_agent_public_chat_disabled', __( 'Public chat is not enabled.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		if ( empty( $config['collections'] ) ) {
			return new WP_Error( 'sd_ai_agent_public_chat_unconfigured', __( 'Public chat has no documentation collection configured.', 'superdav-ai-agent' ), array( 'status' => 503 ) );
		}

		$origin = $this->get_public_chat_request_origin( $request );

		if ( ! $this->public_origin_is_allowed( $origin, $config['origins'] ) ) {
			return new WP_Error( 'sd_ai_agent_public_chat_origin_forbidden', __( 'This origin is not allowed to use public chat.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/** Resolve the public-chat request origin or referer. */
	private function get_public_chat_request_origin( WP_REST_Request $request ): string {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin ) {
			$origin = (string) $request->get_header( 'referer' );
		}

		return $origin;
	}

	/**
	 * Add public-chat CORS headers for allowlisted static-site origins.
	 *
	 * @param WP_REST_Response $response        REST response.
	 * @param string           $origin          Request origin/referer.
	 * @param list<string>     $allowed_origins Allowed origins/hosts.
	 */
	// phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<string> is valid PHPStan but not a native PHP type.
	private function add_public_chat_cors( WP_REST_Response $response, string $origin, array $allowed_origins ): WP_REST_Response {
		if ( $this->public_origin_is_allowed( $origin, $allowed_origins ) ) {
			$response->header( 'Access-Control-Allow-Origin', $origin );
			$response->header( 'Access-Control-Allow-Credentials', 'false' );
			$response->header( 'Vary', 'Origin' );
		}

		return $response;
	}

	/**
	 * Whether a request origin matches configured public-chat origins.
	 *
	 * @param string       $origin          Request origin/referer.
	 * @param list<string> $allowed_origins Allowed origins/hosts.
	 */
	// phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<string> is valid PHPStan but not a native PHP type.
	private function public_origin_is_allowed( string $origin, array $allowed_origins ): bool {
		if ( '' === $origin ) {
			return empty( $allowed_origins );
		}

		$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
		$origin_host = is_string( $origin_host ) ? strtolower( $origin_host ) : '';
		if ( '' === $origin_host ) {
			return false;
		}

		if ( empty( $allowed_origins ) ) {
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
			return is_string( $home_host ) && strtolower( $home_host ) === $origin_host;
		}

		foreach ( $allowed_origins as $allowed ) {
			$allowed_host = wp_parse_url( (string) $allowed, PHP_URL_HOST );
			$allowed_host = is_string( $allowed_host ) ? strtolower( $allowed_host ) : strtolower( (string) $allowed );
			if ( $origin_host === $allowed_host ) {
				return true;
			}
		}

		return false;
	}

	/** Create a signed opaque public session token. */
	private function create_public_chat_token( string $session_uuid ): string {
		$payload = wp_json_encode(
			array(
				'sid' => $session_uuid,
				'exp' => time() + self::PUBLIC_CHAT_SESSION_TTL,
			)
		);
		$payload = false === $payload ? '{}' : $payload;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token payload encoding, signed by HMAC below.
		$body = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
		$sig  = hash_hmac( 'sha256', self::PUBLIC_CHAT_TOKEN_PURPOSE . '|' . $body, wp_salt( 'auth' ) );

		return $body . '.' . $sig;
	}

	/** Validate and parse a signed public session token. */
	private function parse_public_chat_token( string $token ): array|WP_Error {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'sd_ai_agent_public_chat_invalid_token', __( 'Invalid public chat token.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}

		[ $body, $sig ] = $parts;
		$expected       = hash_hmac( 'sha256', self::PUBLIC_CHAT_TOKEN_PURPOSE . '|' . $body, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new WP_Error( 'sd_ai_agent_public_chat_invalid_token', __( 'Invalid public chat token.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the signed URL-safe token payload.
		$decoded = base64_decode( strtr( $body, '-_', '+/' ), true );
		$data    = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $data ) || empty( $data['sid'] ) || ! is_string( $data['sid'] ) || empty( $data['exp'] ) || (int) $data['exp'] < time() ) {
			return new WP_Error( 'sd_ai_agent_public_chat_invalid_token', __( 'Invalid public chat token.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}

		return array(
			'sid' => sanitize_key( $data['sid'] ),
			'exp' => (int) $data['exp'],
		);
	}

	/** Public session transient key. */
	private function public_chat_session_key( string $session_uuid ): string {
		return 'sd_ai_agent_public_chat_' . md5( $session_uuid );
	}

	/** Consume a public-chat rate-limit token. */
	private function check_public_chat_rate_limit( string $session_uuid, int $limit ): true|WP_Error {
		$ip  = sanitize_text_field( wp_unslash( (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) ) );
		$key = 'sd_ai_agent_public_chat_rate_' . md5( $session_uuid . '|' . $ip . '|' . gmdate( 'YmdHi' ) );
		$hit = (int) get_transient( $key );
		if ( $hit >= $limit ) {
			return new WP_Error( 'sd_ai_agent_public_chat_rate_limited', __( 'Too many public chat messages. Please wait before trying again.', 'superdav-ai-agent' ), array( 'status' => 429 ) );
		}

		set_transient( $key, $hit + 1, MINUTE_IN_SECONDS + 5 );
		return true;
	}

	/** Create an anonymous public chat token/session. */
	public function handle_public_chat_session( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$available = $this->check_public_chat_available( $request );
		if ( true !== $available ) {
			return $available;
		}
		$config = $this->get_public_chat_settings();
		$origin = $this->get_public_chat_request_origin( $request );

		$session_uuid = wp_generate_uuid4();
		$token        = $this->create_public_chat_token( $session_uuid );
		set_transient(
			$this->public_chat_session_key( $session_uuid ),
			array(
				'history'    => array(),
				'created_at' => time(),
			),
			self::PUBLIC_CHAT_SESSION_TTL
		);

		return $this->add_public_chat_cors(
			new WP_REST_Response(
				array(
					'token'      => $token,
					'expires_in' => self::PUBLIC_CHAT_SESSION_TTL,
				),
				201
			),
			$origin,
			$config['origins']
		);
	}

	/** Start a public anonymous chat job. */
	public function handle_public_chat_run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$available = $this->check_public_chat_available( $request );
		if ( true !== $available ) {
			return $available;
		}

		$config = $this->get_public_chat_settings();
		$origin = $this->get_public_chat_request_origin( $request );
		$token  = self::get_string_param( $request, 'token' );
		$parsed = $this->parse_public_chat_token( $token );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$session_uuid = (string) $parsed['sid'];
		$session      = get_transient( $this->public_chat_session_key( $session_uuid ) );
		if ( ! is_array( $session ) ) {
			return new WP_Error( 'sd_ai_agent_public_chat_session_expired', __( 'Public chat session expired.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}

		$rate = $this->check_public_chat_rate_limit( $session_uuid, (int) $config['rate_limit'] );
		if ( true !== $rate ) {
			return $rate;
		}

		$message = trim( self::get_string_param( $request, 'message' ) );
		if ( '' === $message ) {
			return new WP_Error( 'sd_ai_agent_public_chat_empty_message', __( 'Message is required.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}
		if ( strlen( $message ) > (int) $config['message_length'] ) {
			return new WP_Error( 'sd_ai_agent_public_chat_message_too_long', __( 'Message is too long for public chat.', 'superdav-ai-agent' ), array( 'status' => 413 ) );
		}

		$job_id    = wp_generate_uuid4();
		$job_token = wp_generate_password( 40, false );
		$history   = isset( $session['history'] ) && is_array( $session['history'] ) ? $session['history'] : array();

		$job = array(
			'public_chat'         => true,
			'public_session_uuid' => $session_uuid,
			'public_token_hash'   => hash( 'sha256', $token ),
			'status'              => 'processing',
			'token'               => $job_token,
			'user_id'             => 0,
			'tool_calls'          => array(),
			'messages'            => array(),
			'params'              => array(
				'message'                       => $message,
				'history'                       => $history,
				'abilities'                     => $config['abilities'],
				'system_instruction'            => $this->build_public_chat_system_instruction(),
				'bootstrap_prompt'              => '',
				'max_iterations'                => $config['iterations'],
				'session_id'                    => 0,
				'provider_id'                   => $config['provider_id'],
				'model_id'                      => $config['model_id'],
				'page_context'                  => array( 'public_chat' => true ),
				'agent_id'                      => $config['agent_id'],
				'attachments'                   => array(),
				'client_abilities'              => array(),
				'anonymous_allowed_abilities'   => $config['abilities'],
				'anonymous_allowed_collections' => $config['collections'],
			),
		);

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );
		ActiveJobRepository::create( 0, $job_id, 0 );

		wp_remote_post(
			rest_url( RestController::NAMESPACE . '/process' ),
			array(
				'timeout'  => 0.01,
				'blocking' => false,
				'body'     => (string) wp_json_encode(
					array(
						'job_id' => $job_id,
						'token'  => $job_token,
					)
				),
				'headers'  => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		return $this->add_public_chat_cors(
			new WP_REST_Response(
				array(
					'job_id' => $job_id,
					'status' => 'processing',
				),
				202
			),
			$origin,
			$config['origins']
		);
	}

	/** Public anonymous job polling scoped to the signed session token. */
	public function handle_public_chat_job_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$available = $this->check_public_chat_available( $request );
		if ( true !== $available ) {
			return $available;
		}
		$config = $this->get_public_chat_settings();
		$origin = $this->get_public_chat_request_origin( $request );

		$token  = self::get_string_param( $request, 'token' );
		$parsed = $this->parse_public_chat_token( $token );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$job_id = self::get_string_param( $request, 'id' );
		$job    = get_transient( RestController::JOB_PREFIX . $job_id );
		if ( ! is_array( $job ) || empty( $job['public_chat'] ) || ( $job['public_session_uuid'] ?? '' ) !== $parsed['sid'] || ( $job['public_token_hash'] ?? '' ) !== hash( 'sha256', $token ) ) {
			return new WP_Error( 'sd_ai_agent_public_chat_job_not_found', __( 'Public chat job not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		$response = array( 'status' => $job['status'] ?? 'processing' );
		if ( ! empty( $job['tool_calls'] ) ) {
			$response['tool_calls'] = $job['tool_calls'];
		}
		if ( ! empty( $job['messages'] ) ) {
			$response['messages'] = $job['messages'];
		}

		if ( 'complete' === ( $job['status'] ?? '' ) && isset( $job['result'] ) && is_array( $job['result'] ) ) {
			$response['reply']           = $job['result']['reply'] ?? '';
			$response['history']         = isset( $job['result']['history'] ) && is_array( $job['result']['history'] ) ? ConversationDisplaySanitizer::sanitize_messages( $job['result']['history'] ) : array();
			$response['tool_calls']      = $job['result']['tool_calls'] ?? array();
			$response['iterations_used'] = $job['result']['iterations_used'] ?? 0;
			delete_transient( RestController::JOB_PREFIX . $job_id );
			ActiveJobRepository::delete( $job_id );
		} elseif ( 'error' === ( $job['status'] ?? '' ) ) {
			$response['message'] = $this->sanitize_job_error_detail( (string) ( $job['error'] ?? '' ) );
			if ( '' === $response['message'] ) {
				$response['message'] = __( 'The public chat request failed. Please try again later.', 'superdav-ai-agent' );
			}
			delete_transient( RestController::JOB_PREFIX . $job_id );
			ActiveJobRepository::delete( $job_id );
		}

		return $this->add_public_chat_cors( new WP_REST_Response( $response, 200 ), $origin, $config['origins'] );
	}

	/** Public chat system instruction. */
	private function build_public_chat_system_instruction(): string {
		$config             = $this->get_public_chat_settings();
		$collections        = ! empty( $config['collections'] )
			? implode( ', ', $config['collections'] )
			: 'the configured public documentation collection';
		$example_collection = $config['collections'][0] ?? 'docs';

		return 'You are a public documentation assistant. Answer only from retrieved documentation, code, or documentation-index context whenever possible. '
			. 'Your only public tool is knowledge-search. Before answering any substantive product or documentation question, call knowledge-search at least once. '
			. 'When the customer asks a vague contextual question such as "what is this?" or uses pronouns, rewrite it into a concrete overview/getting-started documentation query instead of searching only the vague words. '
			. 'When you need documentation context, call knowledge-search with a non-empty JSON query argument copied from or summarized from the customer question, '
			. 'and when selecting a collection use only one of these configured collections: ' . $collections . '. '
			. 'Example valid arguments: {"query":"customer docs question","collection":"' . $example_collection . '"}. '
			. 'Never call knowledge-search with empty arguments. Cite source titles and URLs from knowledge-search results when using facts. '
			. 'If the available context is insufficient, say so and suggest contacting support or reading the linked documentation. '
			. 'Do not claim access to admin, logged-in, site-management, filesystem, database, WordPress CLI, uploads, settings, memory, or internal REST tools.';
	}

	/**
	 * Resolve a session ID from a transient-backed job array.
	 *
	 * @param array<string, mixed> $job Job transient payload.
	 * @return int Session ID, or 0 when none is known.
	 */
	private function get_job_session_id( array $job ): int {
		if ( ! empty( $job['session_id'] ) ) {
			return absint( $job['session_id'] );
		}

		$params = $job['params'] ?? array();
		if ( is_array( $params ) && ! empty( $params['session_id'] ) ) {
			return absint( $params['session_id'] );
		}

		return 0;
	}

	/**
	 * Build a best-effort history payload when an exception prevents AgentLoop
	 * from returning structured recovery data.
	 *
	 * @param array $history Existing deserialized session history.
	 * @param array $params  Job params.
	 * @return list<array<string, mixed>> Serialized history.
	 *
	 * @phpstan-param list<\WordPress\AiClient\Messages\DTO\Message> $history
	 * @phpstan-param array<string, mixed> $params
	 */
	private function build_exception_recovery_history( array $history, array $params ): array {
		try {
			$serialized = ConversationSerializer::serialize( $history );
		} catch ( \Throwable $e ) {
			$serialized = array();
		}
		$message = isset( $params['message'] ) ? (string) $params['message'] : '';

		if ( '' === trim( $message ) ) {
			return $serialized;
		}

		try {
			$user_turn = new \WordPress\AiClient\Messages\DTO\UserMessage(
				array(
					new \WordPress\AiClient\Messages\DTO\MessagePart( $message ),
				)
			);

			return array_merge( $serialized, ConversationSerializer::serialize( array( $user_turn ) ) );
		} catch ( \Throwable $e ) {
			return $serialized;
		}
	}

	/**
	 * Persist enough failed-job state for the chat UI to reload and continue.
	 *
	 * Provider and SDK errors often occur after the new user turn has been added
	 * to AgentLoop history but before a final assistant response exists. Append the
	 * history delta to the session and save the latest safe state so a refresh or
	 * retry does not make the user's prompt disappear.
	 *
	 * @param int                  $session_id Session ID.
	 * @param WP_Error             $error      Loop/provider error.
	 * @param array<string, mixed> $error_data Structured recovery data.
	 * @param array<string, mixed> $params     Job params.
	 * @param array<string, mixed> $options    Loop options.
	 * @param array<string, mixed> $job        Job payload, updated by reference.
	 */
	private function persist_error_recovery_to_session(
		int $session_id,
		WP_Error $error,
		array $error_data,
		array $params,
		array $options,
		array &$job
	): void {
		$history = $this->normalize_serialized_rows( $error_data['history'] ?? array() );
		if ( empty( $history ) ) {
			$history = $this->build_exception_recovery_history( array(), $params );
		}

		if ( empty( $history ) ) {
			return;
		}

		$tool_calls = $this->normalize_serialized_rows( $error_data['tool_calls'] ?? array() );
		$appended   = $this->append_history_delta_to_session(
			$session_id,
			$history,
			$tool_calls
		);

		$message_log = $error_data['messages'] ?? array();
		$message_log = is_array( $message_log ) ? $message_log : array();
		$token_usage = $error_data['token_usage'] ?? array();
		$token_usage = is_array( $token_usage ) ? $token_usage : array();

		$client_abilities = $error_data['client_abilities'] ?? ( $params['client_abilities'] ?? array() );
		$client_abilities = is_array( $client_abilities ) ? $client_abilities : array();

		$paused_saved = $this->database->save_paused_state(
			$session_id,
			array(
				'history'          => array_values( $history ),
				'tool_call_log'    => array_values( $tool_calls ),
				'message_log'      => array_values( $message_log ),
				'token_usage'      => $token_usage,
				'model_id'         => (string) ( $error_data['model_id']
					?? $options['model_id']
					?? $params['model_id']
					?? '' ),
				'provider_id'      => (string) ( $error_data['provider_id']
					?? $options['provider_id']
					?? $params['provider_id']
					?? '' ),
				'client_abilities' => $client_abilities,
				'exit_reason'      => (string) $error->get_error_code(),
			)
		);

		if ( ! $appended || ! $paused_saved ) {
			return;
		}

		$job['session_id']  = $session_id;
		$job['recoverable'] = true;
	}

	/**
	 * Normalize a mixed serialized payload into a list of string-keyed rows.
	 *
	 * @param mixed $value Serialized rows candidate.
	 * @return list<array<string, mixed>>
	 */
	private function normalize_serialized_rows( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows = array();
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$row = array();
			foreach ( $item as $key => $entry ) {
				if ( is_string( $key ) ) {
					$row[ $key ] = $entry;
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Append only messages that are newer than the currently persisted session.
	 *
	 * @param int   $session_id   Session ID.
	 * @param array $full_history Full serialized loop history.
	 * @param array $tool_calls   Tool-call log entries.
	 * @return bool Whether persistence succeeded.
	 *
	 * @phpstan-param list<array<string, mixed>> $full_history
	 * @phpstan-param list<array<string, mixed>> $tool_calls
	 */
	private function append_history_delta_to_session(
		int $session_id,
		array $full_history,
		array $tool_calls
	): bool {
		$session = $this->database->get_session( $session_id );
		if ( ! $session ) {
			return false;
		}

		$existing_messages = json_decode( (string) $session->messages, true );
		if ( ! is_array( $existing_messages ) ) {
			$existing_messages = array();
		}

		$append_offset = $this->get_history_append_offset( $full_history, $existing_messages );
		$appended      = array_slice( $full_history, $append_offset );
		if ( empty( $appended ) && empty( $tool_calls ) ) {
			return true;
		}

		return $this->database->append_to_session( $session_id, array_values( $appended ), $tool_calls );
	}

	/**
	 * Find the first recovery-history row not already persisted in the session.
	 *
	 * Recovery payloads can be full histories or best-effort suffixes. Prefer the
	 * longest identity prefix; when the persisted session is not a prefix of the
	 * payload, treat the payload as a suffix so a failed user turn is not dropped.
	 *
	 * @param array $full_history      Serialized recovery history.
	 * @param array $existing_messages Serialized messages already persisted.
	 * @return int Offset in $full_history from which rows should be appended.
	 *
	 * @phpstan-param list<array<string, mixed>> $full_history
	 * @phpstan-param array<mixed> $existing_messages
	 */
	private function get_history_append_offset( array $full_history, array $existing_messages ): int {
		$common_prefix = 0;
		foreach ( $full_history as $index => $row ) {
			if ( ! isset( $existing_messages[ $index ] ) || $existing_messages[ $index ] !== $row ) {
				break;
			}

			++$common_prefix;
		}

		if ( $common_prefix > 0 || empty( $existing_messages ) ) {
			return $common_prefix;
		}

		foreach ( $full_history as $index => $row ) {
			if ( ! in_array( $row, $existing_messages, true ) ) {
				return $index;
			}
		}

		return count( $full_history );
	}

	/**
	 * Determine whether a session has durable paused state for an error job.
	 *
	 * @param int $session_id Session ID.
	 * @return bool Whether recovery state exists.
	 */
	private function session_has_recoverable_paused_state( int $session_id ): bool {
		if ( $session_id <= 0 ) {
			return false;
		}

		$session = $this->database->get_session( $session_id );
		if ( ! $session || empty( $session->paused_state ) ) {
			return false;
		}

		$paused_state = json_decode( (string) $session->paused_state, true );
		return is_array( $paused_state ) && ! empty( $paused_state['history'] );
	}

	/**
	 * Handle POST /job/{id}/confirm — user approves a pending tool call.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_confirm_tool( WP_REST_Request $request ) {
		// @phpstan-ignore-next-line
		$job_id = (string) $request->get_param( 'id' );
		$job    = get_transient( RestController::JOB_PREFIX . $job_id );

		if ( ! is_array( $job ) || 'awaiting_confirmation' !== ( $job['status'] ?? '' ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_job',
				__( 'Job not found or not awaiting confirmation.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		if ( ( $job['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'sd_ai_agent_forbidden', __( 'Not authorized.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}

		// "Always allow" — persist permission so this tool auto-executes in future.
		// For sd-ai-agent/ability-call confirmations, `ability` is the nested
		// target ability whose policy triggered the pause; `name` remains the
		// executable meta-tool function name so the confirmed resume can run.
		if ( $request->get_param( 'always_allow' ) && ! empty( $job['pending_tools'] ) ) {
			// @phpstan-ignore-next-line
			foreach ( $job['pending_tools'] as $tool ) {
				/** @var array<string, mixed> $tool */
				$tool_name = (string) ( $tool['ability'] ?? ( $tool['name'] ?? '' ) );
				// Convert function name (wpab__...) to ability name for storage.
				if ( str_starts_with( $tool_name, 'wpab__' ) && class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
					$tool_name = \WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name( $tool_name );
				}
				if ( '' !== $tool_name ) {
					ToolPermissionResolver::set_always_allow( $tool_name );
				}
			}
		}

		return $this->resume_job( $job_id, $job, 'confirm' );
	}

	/**
	 * Handle POST /job/{id}/reject — user denies a pending tool call.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_reject_tool( WP_REST_Request $request ) {
		// @phpstan-ignore-next-line
		$job_id = (string) $request->get_param( 'id' );
		$job    = get_transient( RestController::JOB_PREFIX . $job_id );

		if ( ! is_array( $job ) || 'awaiting_confirmation' !== ( $job['status'] ?? '' ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_job',
				__( 'Job not found or not awaiting confirmation.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		if ( ( $job['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'sd_ai_agent_forbidden', __( 'Not authorized.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}

		return $this->resume_job( $job_id, $job, 'reject' );
	}

	/**
	 * Handle POST /job/{id}/interrupt — inject a user message into a running job.
	 *
	 * Sets a flag on the job transient that the agent loop's progress callback
	 * will pick up on the next poll cycle. The interrupt message is appended to
	 * the session in the database so it persists. The running agent loop will
	 * see the interrupt on its next iteration and can incorporate the new context.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_interrupt( WP_REST_Request $request ) {
		// @phpstan-ignore-next-line
		$job_id  = (string) $request->get_param( 'id' );
		$message = (string) $request->get_param( 'message' );
		$job     = get_transient( RestController::JOB_PREFIX . $job_id );

		if ( ! is_array( $job ) || 'processing' !== ( $job['status'] ?? '' ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_job',
				__( 'Job not found or not currently processing.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		if ( ( $job['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'sd_ai_agent_forbidden', __( 'Not authorized.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}

		// Append the interrupt message to the job's pending interrupts.
		$current_interrupts = $job['interrupts'] ?? array();
		$interrupts         = is_array( $current_interrupts ) ? $current_interrupts : array();
		$interrupts[]       = array(
			'message'   => $message,
			'timestamp' => time(),
		);
		$job['interrupts']  = $interrupts;

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );

		return new WP_REST_Response(
			array(
				'status'     => 'interrupt_queued',
				'job_id'     => $job_id,
				'interrupts' => count( $interrupts ),
			),
			200
		);
	}

	/**
	 * Start a fresh background job from the paused state of a recoverable error.
	 *
	 * The state is atomically consumed before dispatch, preventing two browser
	 * clicks from replaying the same tool history. The newly created job supplies
	 * the usual polling lifecycle while preserving the original session context.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_resume_recoverable_job( WP_REST_Request $request ) {
		$session_id   = self::get_int_param( $request, 'id' );
		$paused_state = Database::load_and_clear_paused_state( $session_id );

		if ( ! is_array( $paused_state ) || empty( $paused_state['history'] ) || ! is_array( $paused_state['history'] ) ) {
			return new WP_Error(
				'sd_ai_agent_no_recoverable_state',
				__( 'There is no saved state available to resume for this session.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}

		$job_id = wp_generate_uuid4();
		$token  = wp_generate_password( 40, false );
		$job    = array(
			'status'          => 'processing',
			'token'           => $token,
			'user_id'         => get_current_user_id(),
			'tool_calls'      => $paused_state['tool_call_log'] ?? array(),
			'messages'        => $paused_state['message_log'] ?? array(),
			'recovery_resume' => true,
			'recovery_state'  => $paused_state,
			'params'          => array(
				'message'            => '',
				'history'            => array(),
				'abilities'          => array(),
				'system_instruction' => '',
				'bootstrap_prompt'   => '',
				'max_iterations'     => $paused_state['iterations_remaining'] ?? null,
				'session_id'         => $session_id,
				'provider_id'        => $paused_state['provider_id'] ?? '',
				'model_id'           => $paused_state['model_id'] ?? '',
				'page_context'       => $paused_state['page_context'] ?? array(),
				'agent_id'           => 0,
				'attachments'        => array(),
				'client_abilities'   => $paused_state['client_abilities'] ?? array(),
			),
		);

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );
		ActiveJobRepository::create( $session_id, $job_id, get_current_user_id() );

		wp_remote_post(
			rest_url( RestController::NAMESPACE . '/process' ),
			array(
				'timeout'  => 0.01,
				'blocking' => false,
				'body'     => (string) wp_json_encode(
					array(
						'job_id' => $job_id,
						'token'  => $token,
					)
				),
				'headers'  => array( 'Content-Type' => 'application/json' ),
			)
		);

		return new WP_REST_Response(
			array(
				'job_id' => $job_id,
				'status' => 'processing',
			),
			202
		);
	}

	/**
	 * Resume a paused job after confirmation or rejection.
	 *
	 * @param string               $job_id Job identifier.
	 * @param array<string, mixed> $job    Job transient data.
	 * @param string               $action 'confirm' or 'reject'.
	 * @return WP_REST_Response
	 */
	private static function resume_job( string $job_id, array $job, string $action ): WP_REST_Response {
		$token = wp_generate_password( 40, false );

		$job['status'] = 'processing';
		$job['token']  = $token;
		$job['resume'] = $action;

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );

		// Keep DB in sync — job is back to processing after confirmation/rejection.
		ActiveJobRepository::update_status( $job_id, 'processing' );

		// Spawn background worker.
		wp_remote_post(
			rest_url( RestController::NAMESPACE . '/process' ),
			array(
				'timeout'  => 0.01,
				'blocking' => false,
				'body'     => (string) wp_json_encode(
					[
						'job_id' => $job_id,
						'token'  => $token,
					]
				),
				'headers'  => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		return new WP_REST_Response(
			array(
				'status' => 'processing',
				'job_id' => $job_id,
			),
			200
		);
	}

	/**
	 * Handle the /run endpoint.
	 *
	 * Creates a job, spawns a background worker, and returns immediately.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_run( WP_REST_Request $request ): WP_REST_Response {
		$job_id = wp_generate_uuid4();
		$token  = wp_generate_password( 40, false );

		// Upload attachments to the media library NOW (in the browser-facing
		// request that has auth cookies) so the loopback worker doesn't need to.
		$raw_attachments = $request->get_param( 'attachments' ) ?? array();
		/** @var array<int, array{name: string, type: string, data_url: string, is_image: bool}> $raw_attachments_typed */
		$raw_attachments_typed = is_array( $raw_attachments ) ? $raw_attachments : array();
		$attachments           = RestController::upload_attachments_to_media_library( $raw_attachments_typed );

		$job = array(
			'status'     => 'processing',
			'token'      => $token,
			'user_id'    => get_current_user_id(),
			'tool_calls' => array(),
			'messages'   => array(),
			'params'     => array(
				'message'            => $request->get_param( 'message' ),
				'history'            => $request->get_param( 'history' ),
				'abilities'          => $request->get_param( 'abilities' ),
				'system_instruction' => $request->get_param( 'system_instruction' ),
				'bootstrap_prompt'   => $request->get_param( 'bootstrap_prompt' ),
				'max_iterations'     => $request->get_param( 'max_iterations' ),
				'session_id'         => $request->get_param( 'session_id' ),
				'provider_id'        => $request->get_param( 'provider_id' ),
				'model_id'           => $request->get_param( 'model_id' ),
				'page_context'       => $request->get_param( 'page_context' ),
				'agent_id'           => $request->get_param( 'agent_id' ),
				'attachments'        => $attachments,
				'client_abilities'   => $request->get_param( 'client_abilities' ) ?? array(),
			),
		);

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );

		// Persist to DB so the job survives transient expiry (source of truth).
		// @phpstan-ignore-next-line
		$db_session_id = isset( $job['params']['session_id'] ) ? (int) $job['params']['session_id'] : 0;
		ActiveJobRepository::create( $db_session_id, $job_id, (int) ( $job['user_id'] ?? 0 ) );

		// Spawn background worker via non-blocking loopback.
		wp_remote_post(
			rest_url( RestController::NAMESPACE . '/process' ),
			array(
				'timeout'  => 0.01,
				'blocking' => false,
				'body'     => (string) wp_json_encode(
					[
						'job_id' => $job_id,
						'token'  => $token,
					]
				),
				'headers'  => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		return new WP_REST_Response(
			array(
				'job_id' => $job_id,
				'status' => 'processing',
			),
			202
		);
	}

	/**
	 * Handle the internal /process endpoint (background worker).
	 *
	 * Runs the Agent_Loop and stores the result in the job transient.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_process( WP_REST_Request $request ): WP_REST_Response {
		ignore_user_abort( true );
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Agent loops need extended execution time.
		set_time_limit( 600 );

		// Keep response emission under WP_REST_Server. Manually sending headers,
		// echoing JSON, or calling SAPI-specific finish-request functions here
		// races WordPress' REST finalization and can trigger "headers already sent"
		// warnings on hosted FastCGI/LiteSpeed stacks.

		// @phpstan-ignore-next-line
		$job_id = (string) $request->get_param( 'job_id' );
		$job    = get_transient( RestController::JOB_PREFIX . $job_id );

		if ( ! is_array( $job ) || empty( $job['params'] ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}

		/** @var array<string, mixed> $job */

		// Restore the user context — the loopback request has no cookies,
		// but the AI Client needs a user for provider auth binding.
		if ( ! empty( $job['user_id'] ) ) {
			// @phpstan-ignore-next-line
			wp_set_current_user( (int) $job['user_id'] );
		}

		$params = $job['params'];
		/** @var array<string, mixed> $params */
		// @phpstan-ignore-next-line
		$session_id = ! empty( $params['session_id'] ) ? (int) $params['session_id'] : 0;

		// Load history from session if session_id is provided.
		$history = array();
		if ( $session_id ) {
			$session = $this->database->get_session( $session_id );
			if ( $session ) {
				$session_messages = json_decode( $session->messages, true ) ?: array();
				/** @var list<array<string, mixed>> $session_messages */
				$session_messages = array_values( array_filter( (array) $session_messages, 'is_array' ) );
				if ( ! empty( $session_messages ) ) {
					try {
						$history = ConversationSerializer::deserialize( $session_messages );
						// Strip orphaned tool_use blocks (no matching tool_result) at
						// load time. Prevents API 400 errors when a prior job was
						// interrupted between recording a tool_use and its tool_result.
						$history = ConversationTrimmer::validate_tool_pairs( $history );
					} catch ( \Exception $e ) {
						$history = array();
					}
				}
			}
		} elseif ( ! empty( $params['history'] ) && is_array( $params['history'] ) ) {
			try {
				/** @var list<array<string, mixed>> $params_history */
				$params_history = $params['history'];
				$history        = ConversationSerializer::deserialize( array_values( $params_history ) );
				// Same defensive strip for history passed directly in the request body.
				$history = ConversationTrimmer::validate_tool_pairs( $history );
			} catch ( \Exception $e ) {
				$job['status'] = 'error';
				$job['error']  = __( 'Invalid conversation history format.', 'superdav-ai-agent' );
				unset( $job['token'] );
				set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );
				ActiveJobRepository::update_status( $job_id, 'error', [ 'error' => $job['error'] ] );
				return new WP_REST_Response( array( 'ok' => false ), 200 );
			}
		}

		// Only forward max_iterations when the client explicitly supplied one.
		// Passing it unconditionally (with a hardcoded fallback) would override
		// the saved Settings value inside AgentLoop, which already falls back
		// to $settings['max_iterations'] (default 100). The /run endpoint
		// schema deliberately has no REST default for this param so
		// $request->get_param() returns null when omitted — see handle_run().
		$options = array();
		if ( null !== $params['max_iterations'] && '' !== $params['max_iterations'] ) {
			$options['max_iterations'] = (int) $params['max_iterations'];
		}

		if ( ! empty( $params['system_instruction'] ) ) {
			$options['system_instruction'] = $params['system_instruction'];
		}

		// Bootstrap prompt — prepended to the regular system instruction for the
		// onboarding auto-discovery session. Mutually exclusive with system_instruction.
		if ( ! empty( $params['bootstrap_prompt'] ) && empty( $params['system_instruction'] ) ) {
			$options['bootstrap_prompt'] = $params['bootstrap_prompt'];
		}

		if ( ! empty( $params['provider_id'] ) ) {
			$options['provider_id'] = $params['provider_id'];
		}

		if ( ! empty( $params['model_id'] ) ) {
			$options['model_id'] = $params['model_id'];
		}

		if ( ! empty( $params['page_context'] ) ) {
			$options['page_context'] = $params['page_context'];
		}

		// Pass session_id to AgentLoop for change attribution.
		if ( ! empty( $params['session_id'] ) ) {
			// @phpstan-ignore-next-line
			$options['session_id'] = (int) $params['session_id'];
		}

		// Pass client-side abilities through to the loop.
		$raw_client_abilities = $params['client_abilities'] ?? array();
		if ( ! empty( $raw_client_abilities ) && is_array( $raw_client_abilities ) ) {
			$options['client_abilities'] = $raw_client_abilities;
			if ( ! empty( $params['session_id'] ) ) {
				// @phpstan-ignore-next-line
				$options['session_id'] = (int) $params['session_id'];
			}
		}

		if ( ! empty( $job['public_chat'] ) ) {
			$allowed_abilities                        = $params['anonymous_allowed_abilities'] ?? array();
			$allowed_collections                      = $params['anonymous_allowed_collections'] ?? array();
			$options['anonymous_allowed_abilities']   = is_array( $allowed_abilities ) ? array_values( $allowed_abilities ) : array( 'sd-ai-agent/knowledge-search' );
			$options['anonymous_allowed_collections'] = is_array( $allowed_collections ) ? array_values( $allowed_collections ) : array();
			$options['client_abilities']              = array();
			$options['yolo_mode']                     = false;
		}

		// Apply agent overrides (agent_id takes precedence over individual params).
		if ( ! empty( $params['agent_id'] ) ) {
			// @phpstan-ignore-next-line
			$agent_options = Agent::get_loop_options( (int) $params['agent_id'] );
			$options       = array_merge( $options, $agent_options );
		}

		/*
		 * Pass the job UUID to AgentLoop so it can:
		 * (a) issue heartbeats on each iteration, keeping updated_at fresh so
		 *     the hourly stale-job reaper treats this as an active loop; and
		 * (b) register a shutdown handler that marks the row as 'interrupted'
		 *     when the PHP process terminates before loop completion.
		 */
		$options['active_job_id'] = $job_id;

		// Progress callback: write live tool-call activity and channel messages
		// to the job transient so the polling frontend can display them incrementally.
		$progress_job_id              = $job_id;
		$options['progress_callback'] = static function ( array $tool_call_log, array $message_log = array() ) use ( $progress_job_id ) {
			$current = get_transient( RestController::JOB_PREFIX . $progress_job_id );
			if ( is_array( $current ) && 'processing' === ( $current['status'] ?? '' ) ) {
				$current['tool_calls'] = $tool_call_log;
				$current['messages']   = $message_log;
				// Refresh TTL on each update to prevent mid-execution expiration.
				// Adding 60s buffer ensures the transient outlasts the execution
				// limit even when the callback fires near the end of the job.
				set_transient( RestController::JOB_PREFIX . $progress_job_id, $current, RestController::JOB_TTL + 60 );
			} elseif ( false === $current ) {
				// Transient expired mid-execution; re-create a minimal entry so
				// the final job result can still be persisted after completion.
				// Use the same buffered TTL (+60s) as the primary path to
				// prevent the recreated transient from expiring again before
				// the job finishes.
				set_transient(
					RestController::JOB_PREFIX . $progress_job_id,
					array(
						'status'     => 'processing',
						'tool_calls' => $tool_call_log,
						'messages'   => $message_log,
					),
					RestController::JOB_TTL + 60
				);
			}
		};

		// Record start time for webhook duration tracking.
		$start_ms = (int) round( microtime( true ) * 1000 );

		// Check if this is a resume from a tool confirmation/rejection or crash checkpoint.
		$is_resume            = ! empty( $job['resume'] );
		$is_checkpoint_resume = ! empty( $job['checkpoint_resume'] );
		$is_recovery_resume   = ! empty( $job['recovery_resume'] );

		// Wrap the entire loop execution in a try/catch so that uncaught
		// exceptions (e.g. from ability schema validation) are captured
		// and written to the job transient instead of silently killing
		// the background worker.
		try {
			if ( $is_checkpoint_resume || $is_recovery_resume ) {
				$state = $is_recovery_resume
					? ( $job['recovery_state'] ?? array() )
					: ( $job['checkpoint_state'] ?? array() );

				/** @var list<array<string, mixed>> $state_history */
				$state_history  = is_array( $state ) ? ( $state['history'] ?? array() ) : array();
				$resume_history = ConversationSerializer::deserialize( array_values( $state_history ) );

				$resume_options = $options;
				// @phpstan-ignore-next-line
				$resume_options['tool_call_log'] = is_array( $state ) ? ( $state['tool_call_log'] ?? array() ) : array();
				// @phpstan-ignore-next-line
				$resume_options['message_log'] = is_array( $state ) ? ( $state['message_log'] ?? array() ) : array();
				// @phpstan-ignore-next-line
				$resume_options['token_usage'] = is_array( $state ) ? ( $state['token_usage'] ?? array(
					'prompt'     => 0,
					'completion' => 0,
				) ) : array(
					'prompt'     => 0,
					'completion' => 0,
				);

				$loop   = new AgentLoop( '', array(), $resume_history, $resume_options );
				$result = $loop->resume_from_checkpoint( (int) ( is_array( $state ) ? ( $state['iterations_remaining'] ?? 100 ) : 100 ) );
			} elseif ( $is_resume ) {
				$confirmed = 'confirm' === $job['resume'];
				$state     = $job['confirmation_state'] ?? array();

				/** @var list<array<string, mixed>> $state_history */
				$state_history  = $state['history'] ?? array();
				$resume_history = ConversationSerializer::deserialize( array_values( $state_history ) );

				$resume_options = $options;
				// @phpstan-ignore-next-line
				$resume_options['tool_call_log'] = $state['tool_call_log'] ?? array();
				// @phpstan-ignore-next-line
				$resume_options['message_log'] = $state['message_log'] ?? array();
				// @phpstan-ignore-next-line
				$resume_options['token_usage'] = $state['token_usage'] ?? array(
					'prompt'     => 0,
					'completion' => 0,
				);
				// @phpstan-ignore-next-line
				$resume_options['approved_once_abilities'] = $state['approved_once_abilities'] ?? array();

				$loop = new AgentLoop( '', array(), $resume_history, $resume_options );
				// Fallback to 100 matches the rest of the codebase
				// (Settings defaults, run-endpoint fallback, REST
				// tool-result resume). A small default truncates the
				// resumed loop and surfaces a false "max tool calls"
				// error to the user when the key is merely absent.
				$result = $loop->resume_after_confirmation( $confirmed, (int) ( $state['iterations_remaining'] ?? 100 ) );
			} else {
				$abilities = $params['abilities'] ?? array();
				// @phpstan-ignore-next-line
				$loop   = new AgentLoop( (string) $params['message'], is_array( $abilities ) ? $abilities : array(), $history, $options );
				$result = $loop->run();
			}
		} catch ( \Throwable $e ) {
			// Log the full exception so stdClass and similar runtime errors
			// are visible in debug.log instead of silently swallowed.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Superdav AI Agent] AgentLoop error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() );

			$job['status'] = 'error';
			$job['error']  = $e->getMessage();

			// Include backtrace context so the frontend can display
			// actionable debugging info instead of a bare message.
			$trace_frames = array();
			foreach ( array_slice( $e->getTrace(), 0, 10 ) as $frame ) {
				$trace_frames[] = ( $frame['file'] ?? '?' )
					. ':' . ( $frame['line'] ?? '?' )
					. ' ' . ( $frame['class'] ?? '' )
					. ( $frame['type'] ?? '' )
					. ( $frame['function'] ?? '' ) . '()';
			}
			$job['error_context'] = array(
				'file'  => $e->getFile(),
				'line'  => $e->getLine(),
				'trace' => $trace_frames,
			);

			if ( $session_id ) {
				$recovery_error = new WP_Error(
					'sd_ai_agent_loop_exception',
					$e->getMessage(),
					array(
						'history'     => $this->build_exception_recovery_history( array_values( $history ), $params ),
						'tool_calls'  => is_array( $job['tool_calls'] ?? null )
							? $job['tool_calls']
							: array(),
						'messages'    => is_array( $job['messages'] ?? null )
							? $job['messages']
							: array(),
						'token_usage' => array(
							'prompt'     => 0,
							'completion' => 0,
						),
					)
				);
				$this->persist_error_recovery_to_session(
					$session_id,
					$recovery_error,
					(array) $recovery_error->get_error_data(),
					$params,
					$options,
					$job
				);
			}

			unset( $job['token'] );
			set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );

			// Persist exception details to DB so status survives transient expiry.
			ActiveJobRepository::update_status( $job_id, 'error', [ 'error' => $job['error'] ] );

			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}

		if ( is_wp_error( $result ) ) {
			$job['status'] = 'error';
			$job['error']  = $result->get_error_message();
			$error_data    = $result->get_error_data();
			if ( is_array( $error_data ) ) {
				$job['tool_calls'] = $error_data['tool_calls'] ?? ( $job['tool_calls'] ?? array() );
				$job['messages']   = $error_data['messages'] ?? ( $job['messages'] ?? array() );
				if ( $session_id ) {
					$this->persist_error_recovery_to_session(
						$session_id,
						$result,
						$error_data,
						$params,
						$options,
						$job
					);
				}
			}

			// Log webhook execution failure.
			if ( ! empty( $job['webhook_id'] ) ) {
				$duration_ms = $start_ms > 0 ? (int) round( microtime( true ) * 1000 ) - $start_ms : 0;
				WebhookDatabase::log_execution(
					// @phpstan-ignore-next-line
					(int) $job['webhook_id'],
					'error',
					'',
					array(),
					0,
					0,
					$duration_ms,
					$result->get_error_message()
				);
			}
		} elseif ( is_array( $result ) && ! empty( $result['awaiting_confirmation'] ) ) {
			/** @var array<string, mixed> $result */
			$job['status']             = 'awaiting_confirmation';
			$job['pending_tools']      = $result['pending_tools'] ?? array();
			$job['messages']           = $result['message_log'] ?? array();
			$job['confirmation_state'] = array(
				'history'                 => $result['history'] ?? array(),
				'tool_call_log'           => $result['tool_call_log'] ?? array(),
				'message_log'             => $result['message_log'] ?? array(),
				'token_usage'             => $result['token_usage'] ?? array(
					'prompt'     => 0,
					'completion' => 0,
				),
				'approved_once_abilities' => $result['approved_once_abilities'] ?? array(),
				'iterations_remaining'    => $result['iterations_remaining'] ?? 5,
			);
			// Keep token and params for the resume flow.
			unset( $job['token'] );
			set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );

			// Persist awaiting_confirmation to DB so status survives transient expiry.
			/** @var list<array<string, mixed>> $pending_tools_for_db */
			$pending_tools_for_db = (array) $job['pending_tools'];
			/** @var list<array<string, mixed>> $tool_calls_for_db */
			$tool_calls_for_db = (array) $job['tool_calls'];
			ActiveJobRepository::update_status(
				$job_id,
				'awaiting_confirmation',
				[
					'pending_tools' => wp_json_encode( $pending_tools_for_db ),
					'tool_calls'    => wp_json_encode( $tool_calls_for_db ),
				]
			);

			return new WP_REST_Response( array( 'ok' => true ), 200 );
		} elseif ( is_array( $result ) && ! empty( $result['pending_client_tool_calls'] ) ) {
			// Agent loop paused — waiting for the browser to execute client-side
			// (JS) tools and POST results back to /chat/tool-result.
			// The AgentLoop already persisted the paused conversation state via
			// Database::save_paused_state(), so /chat/tool-result can reconstruct
			// the loop. We only need to surface the pending calls to the browser.
			/** @var array<string, mixed> $result */
			$job['status']                    = 'awaiting_client_tools';
			$job['pending_client_tool_calls'] = $result['pending_client_tool_calls'];
			// Preserve live tool-call progress so the UI stays current.
			$job['tool_calls'] = $result['tool_call_log'] ?? array();
			$job['messages']   = $result['message_log'] ?? array();

			unset( $job['token'] );
			set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );

			// Persist to DB so the pending calls survive transient expiry.
			// We reuse the pending_tools column (JSON) since the schema is
			// shared and the data shape is compatible.
			/** @var list<array<string, mixed>> $pending_calls_for_db */
			$pending_calls_for_db = (array) $job['pending_client_tool_calls'];
			/** @var list<array<string, mixed>> $tool_calls_for_db */
			$tool_calls_for_db = (array) $job['tool_calls'];
			ActiveJobRepository::update_status(
				$job_id,
				'awaiting_client_tools',
				[
					'pending_tools' => wp_json_encode( $pending_calls_for_db ),
					'tool_calls'    => wp_json_encode( $tool_calls_for_db ),
				]
			);

			return new WP_REST_Response( array( 'ok' => true ), 200 );
		} else {
			/** @var array<string, mixed> $result */
			$job['status'] = 'complete';
			$job['result'] = $result;

			if ( ! empty( $job['public_chat'] ) && ! empty( $job['public_session_uuid'] ) ) {
				$public_history = isset( $result['history'] ) && is_array( $result['history'] ) ? $result['history'] : array();
				set_transient(
					$this->public_chat_session_key( (string) $job['public_session_uuid'] ),
					array(
						'history'    => $public_history,
						'updated_at' => time(),
					),
					self::PUBLIC_CHAT_SESSION_TTL
				);
			}

			// Persist to session if session_id is provided.
			if ( $session_id ) {
				$job['result']['session_id'] = $session_id;

				// The full history from the loop includes existing + new messages.
				// Slice off only the new ones to append.
				$session        = $this->database->get_session( $session_id );
				$existing_count = 0;
				if ( $session ) {
					$existing_messages = json_decode( $session->messages, true ) ?: array();
					// @phpstan-ignore-next-line
					$existing_count = count( $existing_messages );
				}

				$full_history = $result['history'] ?? array();
				/** @var array<mixed> $full_history */
				$appended = array_slice( $full_history, $existing_count );
				/** @var list<array<string, mixed>> $tool_calls_result */
				$tool_calls_result = $result['tool_calls'] ?? array();
				$this->database->append_to_session( $session_id, array_values( $appended ), $tool_calls_result );

				// Persist token usage.
				$token_usage = $result['token_usage'] ?? array();
				/** @var array<string, mixed> $token_usage */
				if ( ! empty( $token_usage ) ) {
					$this->database->update_session_tokens(
						$session_id,
						// @phpstan-ignore-next-line
						(int) ( $token_usage['prompt'] ?? 0 ),
						// @phpstan-ignore-next-line
						(int) ( $token_usage['completion'] ?? 0 )
					);
				}

				// Log to usage tracking table.
				// Use resolved options (which include agent overrides) rather than raw params.
				// @phpstan-ignore-next-line
				$provider_id = (string) ( $options['provider_id'] ?? $params['provider_id'] ?? '' );
				// @phpstan-ignore-next-line
				$model_id = (string) ( $options['model_id'] ?? $params['model_id'] ?? '' );
				// @phpstan-ignore-next-line
				$prompt_t = (int) ( $token_usage['prompt'] ?? 0 );
				// @phpstan-ignore-next-line
				$completion_t = (int) ( $token_usage['completion'] ?? 0 );

				if ( $prompt_t > 0 || $completion_t > 0 ) {
					$cost = CostCalculator::calculate_cost( $model_id, $prompt_t, $completion_t );
					$this->database->log_usage(
						array(
							'user_id'           => $job['user_id'] ?? 0,
							'session_id'        => $session_id,
							'provider_id'       => $provider_id,
							'model_id'          => $model_id,
							'prompt_tokens'     => $prompt_t,
							'completion_tokens' => $completion_t,
							'cost_usd'          => $cost,
						)
					);
				}

				// Auto-generate title from first user message if empty.
				if ( $session && empty( $session->title ) ) {
					// @phpstan-ignore-next-line
					$reply = (string) ( $result['reply'] ?? '' );
					$title = RestController::generate_session_title(
						// @phpstan-ignore-next-line
						(string) $params['message'],
						$reply,
						// @phpstan-ignore-next-line
						(string) ( $options['provider_id'] ?? $params['provider_id'] ?? '' ),
						// @phpstan-ignore-next-line
						(string) ( $options['model_id'] ?? $params['model_id'] ?? '' )
					);
					$this->database->update_session( $session_id, array( 'title' => $title ) );
					$job['result']['generated_title'] = $title;
				}
			}

			// Log webhook execution success.
			if ( ! empty( $job['webhook_id'] ) ) {
				$token_usage = $result['token_usage'] ?? array(
					'prompt'     => 0,
					'completion' => 0,
				);
				/** @var array<string, mixed> $token_usage */
				$duration_ms = $start_ms > 0 ? (int) round( microtime( true ) * 1000 ) - $start_ms : 0;
				/** @var list<array<string, mixed>> $tool_calls_webhook */
				$tool_calls_webhook = $result['tool_calls'] ?? array();
				WebhookDatabase::log_execution(
					// @phpstan-ignore-next-line
					(int) $job['webhook_id'],
					'success',
					// @phpstan-ignore-next-line
					(string) ( $result['reply'] ?? '' ),
					$tool_calls_webhook,
					// @phpstan-ignore-next-line
					(int) ( $token_usage['prompt'] ?? 0 ),
					// @phpstan-ignore-next-line
					(int) ( $token_usage['completion'] ?? 0 ),
					$duration_ms,
					''
				);
			}
		}

		// Clear the token — no longer needed.
		unset( $job['token'] );
		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );

		// Persist terminal status to DB so result survives transient expiry.
		// The full reply/history are in the session messages column already.
		// A DB-sourced poll response returns status + session_id for the
		// frontend to reload the session when needed.
		// @phpstan-ignore-next-line -- status is set above in all paths (error or complete).
		$db_status = (string) $job['status'];
		if ( 'error' === $db_status ) {
			$error_extra = [ 'error' => (string) ( $job['error'] ?? '' ) ];
			if ( ! empty( $job['tool_calls'] ) && is_array( $job['tool_calls'] ) ) {
				$error_extra['tool_calls'] = wp_json_encode( $job['tool_calls'] );
			}
			ActiveJobRepository::update_status( $job_id, 'error', $error_extra );
		} elseif ( 'complete' === $db_status ) {
			/** @var array<string, mixed> $complete_result */
			$complete_result = $job['result'] ?? array();
			/** @var list<array<string, mixed>> $complete_tool_calls */
			$complete_tool_calls = $complete_result['tool_calls'] ?? array();
			ActiveJobRepository::update_status(
				$job_id,
				'complete',
				[ 'tool_calls' => wp_json_encode( $complete_tool_calls ) ]
			);
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Handle GET /sessions/{id}/active-job — return the active job for a session (t202).
	 *
	 * Returns the same shape as /job/{id} for processing/awaiting_confirmation states.
	 * Returns 404 if the session has no active job.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_session_active_job( WP_REST_Request $request ) {
		$session_id = (int) $request->get_param( 'id' );
		$db_row     = ActiveJobRepository::get_by_session_id( $session_id );

		if ( null === $db_row ) {
			return new WP_Error(
				'sd_ai_agent_no_active_job',
				__( 'No active job for this session.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		$response = array(
			'job_id' => $db_row->job_id,
			'status' => $db_row->status,
		);

		$response['tool_calls'] = json_decode( $db_row->tool_calls, true ) ?: [];

		if ( 'awaiting_confirmation' === $db_row->status ) {
			$response['pending_tools'] = json_decode( $db_row->pending_tools, true ) ?: [];
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Handle GET /sessions/active-jobs — list all active jobs for the current user (t202).
	 *
	 * Returns an array of { session_id, job_id, status }.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_list_active_jobs(): WP_REST_Response {
		$rows = ActiveJobRepository::get_active_for_user( get_current_user_id() );

		$data = array_map(
			static function ( $row ) {
				return array(
					'session_id' => $row->session_id,
					'job_id'     => $row->job_id,
					'status'     => $row->status,
				);
			},
			$rows
		);

		return new WP_REST_Response( array_values( $data ), 200 );
	}
}
