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

use SdAiAgent\Abilities\Js\JsAbilityCatalog;
use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\AgentEventLog;
use SdAiAgent\Core\ActiveJobFailureDiagnostic;
use SdAiAgent\Core\BackgroundJobDispatcher;
use SdAiAgent\Core\ConversationDisplaySanitizer;
use SdAiAgent\Core\ConversationSerializer;
use SdAiAgent\Core\ConversationTrimmer;
use SdAiAgent\Core\CostCalculator;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\DurablePlanRunner;
use SdAiAgent\Core\Export;
use SdAiAgent\Core\PublicChatSecurity;
use SdAiAgent\Core\Settings;
use SdAiAgent\Core\ToolPermissionResolver;
use SdAiAgent\Models\ActiveJobRepository;
use SdAiAgent\Models\Agent;
use SdAiAgent\Models\CustomerConversationReviewRepository;
use SdAiAgent\Models\DTO\ActiveJobRow;
use SdAiAgent\Models\DurablePlanRepository;
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
 *
 * @phpstan-type SerializedHistory list<array<string, mixed>>
 * @phpstan-type CheckpointRequest array{fingerprint:string,request_bytes:int,request_tokens:int,request_budget_bytes:int,request_budget_tokens:int,size_class:string,phase:string,locally_rejected:bool}
 * @phpstan-type ResumeAttempt array{fingerprint:string,request_bytes:int,request_tokens:int,size_class:string,phase:string}
 * @phpstan-type CheckpointResumeOutcome array{dispatched:bool,terminal:bool,metadata:array{phase:string,reason:string,size_class:string}}
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class SessionController {

	use PermissionTrait;

	/** Maximum automatic resume attempts for a crashed background job. */
	private const JOB_AUTO_RESUME_MAX_ATTEMPTS = 2;

	/**
	 * Per-message transport attribution that does not alter conversation meaning.
	 *
	 * Recovery payloads can be serialized before or after turn attribution is
	 * added. Keep this list deliberately narrow: roles, ordered parts, function
	 * IDs, and their payloads must remain part of message identity.
	 *
	 * @var list<string>
	 */
	private const RECOVERY_TRANSPORT_METADATA = array( 'provider_id', 'model_id' );

	/** @var Database Injected database dependency. */
	private Database $database;

	/** @var Settings Injected settings dependency. */
	private Settings $settings;
	private PublicChatSecurity $public_chat_security;

	/**
	 * Constructor — receives injected dependencies from the DI container.
	 *
	 * @param Database                $database             Injected Database service.
	 * @param Settings|null           $settings             Injected Settings service.
	 * @param PublicChatSecurity|null $public_chat_security Shared public authorization service.
	 */
	public function __construct( Database $database, ?Settings $settings = null, ?PublicChatSecurity $public_chat_security = null ) {
		$this->database             = $database;
		$this->settings             = $settings ?? Settings::instance();
		$this->public_chat_security = $public_chat_security ?? new PublicChatSecurity( $this->settings );
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
					'durable_plan'       => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
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
				'args'                => array(
					'recording_consent' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'embed_id'          => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'locale'            => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
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
					'id' => array(
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

		// Durable phased-operation plans. Lifecycle actions accept only a plan ID
		// and explicit user action; plan creation treats browser metadata as untrusted.
		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/(?P<id>\d+)/plan',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_durable_plan_status' ),
					'permission_callback' => array( $this, 'check_session_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_create_durable_plan' ),
					'permission_callback' => array( $this, 'check_session_owner_permission' ),
					'args'                => array(
						'scope'   => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'summary' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'steps'   => array(
							'required' => true,
							'type'     => 'array',
						),
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/sessions/(?P<id>\d+)/plan/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_durable_plan_status' ),
				'permission_callback' => array( $this, 'check_session_permission' ),
			)
		);

		foreach (
			array(
				'continue' => array(
					'callback' => 'handle_continue_durable_plan',
					'args'     => array(),
				),
				'approve'  => array(
					'callback' => 'handle_approve_durable_plan',
					'args'     => array(
						'approval_request_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				'reject'   => array(
					'callback' => 'handle_reject_durable_plan',
					'args'     => array(
						'approval_request_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				'retry'    => array(
					'callback' => 'handle_retry_durable_plan',
					'args'     => array(),
				),
				'cancel'   => array(
					'callback' => 'handle_cancel_durable_plan',
					'args'     => array(),
				),
				'scope'    => array(
					'callback' => 'handle_durable_plan_scope_change',
					'args'     => array(
						'scope' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			) as $operation => $route
		) {
			register_rest_route(
				RestController::NAMESPACE,
				'/sessions/(?P<id>\d+)/plan/' . $operation,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $route['callback'] ),
					'permission_callback' => array( $this, 'check_session_owner_permission' ),
					'args'                => array_merge(
						array(
							'plan_id' => array(
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
						$route['args']
					),
				)
			);
		}

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
		$config         = $this->get_public_chat_settings();
		$origin         = $this->get_public_chat_request_origin( $request );
		$enabled        = ! empty( $config['enabled'] ) && ! empty( $config['collections'] ) && $this->public_origin_is_allowed( $origin, $config['origins'] );
		$speech_enabled = $enabled && ! empty( $config['speech_enabled'] );
		$this->public_chat_security->record_speech_metric( 'availability', $speech_enabled ? 'available' : 'unavailable', 200, microtime( true ) );

		return $this->add_public_chat_cors(
			new WP_REST_Response(
				array(
					'enabled'     => $enabled,
					'embed_id'    => sanitize_key( (string) $this->settings->get( 'public_chat_embed_id' ) ),
					'agent_id'    => (int) $config['agent_id'],
					'collections' => $config['collections'],
					'recording'   => array(
						'enabled'        => $enabled && ! empty( $config['review_recording_enabled'] ),
						'retention_days' => $enabled && ! empty( $config['review_recording_enabled'] ) ? (int) $config['review_retention_days'] : 0,
						'disclosure'     => $enabled && ! empty( $config['review_recording_enabled'] ) ? (string) $config['review_disclosure'] : '',
					),
					'speech'      => array(
						'enabled'                        => $speech_enabled,
						'upload_mime_type'               => 'audio/wav',
						'capture_mime_types'             => array( 'audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4' ),
						'output_mime_types'              => array( 'audio/mpeg' ),
						'max_audio_bytes'                => $speech_enabled ? (int) $config['speech_max_audio_bytes'] : 0,
						'max_recording_duration_seconds' => $speech_enabled ? (int) $config['speech_max_recording_seconds'] : 0,
						'max_tts_characters'             => $speech_enabled ? (int) $config['speech_max_tts_characters'] : 0,
						'voice_conversation_enabled'     => $speech_enabled && ! empty( $config['speech_voice_mode_enabled'] ),
						'disclosure'                     => $speech_enabled ? (string) $config['speech_disclosure'] : '',
						'labels'                         => array(
							'listen'       => __( 'Use microphone', 'superdav-ai-agent' ),
							'stop'         => __( 'Stop', 'superdav-ai-agent' ),
							'listening'    => __( 'Listening…', 'superdav-ai-agent' ),
							'transcribing' => __( 'Transcribing…', 'superdav-ai-agent' ),
							'thinking'     => __( 'Thinking…', 'superdav-ai-agent' ),
							'speaking'     => __( 'Speaking…', 'superdav-ai-agent' ),
							'read_aloud'   => __( 'Read aloud', 'superdav-ai-agent' ),
							'voice_mode'   => __( 'Voice conversation', 'superdav-ai-agent' ),
							'continue'     => __( 'Allow microphone', 'superdav-ai-agent' ),
							'fallback'     => __( 'Speech is unavailable. You can continue with typed chat.', 'superdav-ai-agent' ),
						),
					),
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
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Repository convention uses camelCase PHP locals.
		$rawIds = $request->get_param( 'ids' );
		$action = $request->get_param( 'action' );

		if ( 'delete' === $action ) {
			$hasInvalidId = ! is_array( $rawIds ) || array_filter(
				$rawIds,
				static fn( $id ): bool => ! ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) || absint( $id ) <= 0
			);
			if ( $hasInvalidId ) {
				return new WP_Error( 'sd_ai_agent_invalid_session_ids', __( 'Session IDs must be positive integers.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
			}

			$ids   = array_map( static fn( mixed $id ): int => absint( $id ), $rawIds );
			$count = $this->database->bulk_delete_trashed_sessions( $ids, get_current_user_id() );

			return new WP_REST_Response( array( 'deleted' => $count ), 200 );
		}

		// @phpstan-ignore-next-line
		$ids = array_map( static fn( mixed $id ): int => absint( $id ), $rawIds );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

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

		$provider_id = sanitize_text_field( (string) ( $request->get_param( 'provider_id' ) ?: $source_session->provider_id ) );
		$model_id    = sanitize_text_field( (string) ( $request->get_param( 'model_id' ) ?: $source_session->model_id ) );

		// Reserve half of the effective envelope for the next user turn, system
		// instruction, tool declarations, attachments, and provider options. The
		// transport preflight remains authoritative immediately before dispatch.
		$request_byte_budget  = ConversationTrimmer::get_request_envelope_byte_budget( $provider_id, $model_id );
		$request_token_budget = ConversationTrimmer::get_request_token_budget( $provider_id, $model_id );
		$compact_byte_budget  = min(
			ConversationTrimmer::COMPACT_MAX_BYTES,
			max( 1024, (int) floor( $request_byte_budget / 2 ) )
		);
		$compact_token_budget = min(
			ConversationTrimmer::COMPACT_MAX_TOKENS,
			max( 256, (int) floor( $request_token_budget / 2 ) )
		);
		$compacted            = ConversationTrimmer::compact_serialized_history(
			$source_messages,
			$compact_byte_budget,
			$compact_token_budget
		);
		$seed_messages        = $compacted['messages'];
		if ( empty( $seed_messages ) ) {
			return new WP_Error(
				'sd_ai_agent_compact_failed',
				__( 'Failed to build compact conversation context.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$title = $this->build_compacted_session_title( (string) $source_session->title );

		$new_session_id = $this->database->create_session(
			array(
				'user_id'     => get_current_user_id(),
				'title'       => $title,
				'provider_id' => $provider_id,
				'model_id'    => $model_id,
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
			if ( $this->discard_expired_paused_job( $db_row ) ) {
				$expired_row = ActiveJobRepository::get_by_job_id( $job_id );
				if ( null !== $expired_row ) {
					return $this->job_status_from_db_row( $job_id, $expired_row );
				}
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
		if ( self::can_current_user_view_durable_job( $db_row ) && is_array( $job['durable_plan']['plan'] ?? null ) ) {
			$response['durable_plan'] = $job['durable_plan']['plan'];
		} elseif ( self::can_current_user_view_durable_job( $db_row ) ) {
			$durable_plan = DurablePlanRunner::public_plan_for_job( $job_id );
			if ( null !== $durable_plan ) {
				$response['durable_plan'] = $durable_plan;
			}
		}

		// Include live tool call progress for all statuses that have it.
		if ( ! empty( $job['tool_calls'] ) ) {
			$response['tool_calls'] = $job['tool_calls'];
		}
		if ( ! empty( $job['messages'] ) ) {
			$response['messages'] = is_array( $job['messages'] )
				? ConversationDisplaySanitizer::sanitize_activity_messages( $job['messages'] )
				: array();
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
			$response['reply'] = ConversationDisplaySanitizer::sanitize_display_text( (string) ( $job['result']['reply'] ?? '' ) );
			// @phpstan-ignore-next-line
			$history             = $job['result']['history'] ?? array();
			$response['history'] = is_array( $history ) ? ConversationDisplaySanitizer::sanitize_messages( $history ) : array();
			// @phpstan-ignore-next-line
			$response['tool_calls'] = $job['result']['tool_calls'] ?? array();
			// @phpstan-ignore-next-line
			$response['messages'] = ConversationDisplaySanitizer::sanitize_activity_messages( (array) ( $job['result']['messages'] ?? array() ) );
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

		if ( 'error' === $job['status'] ) {
			$job_session_id = $this->get_job_session_id( $job );
			unset( $response['tool_calls'], $response['messages'] );

			if ( $job_session_id > 0 ) {
				$response['session_id'] = $job_session_id;
			}
			if ( ! empty( $job['recoverable'] ) ) {
				$response['recoverable'] = true;
			}
			$this->add_transient_failure_response( $job_id, $job, $job_session_id, $response );
			$payload_recovery = $this->normalize_payload_recovery( $job['payload_recovery'] ?? null, $job_session_id );
			if ( null === $payload_recovery ) {
				$payload_recovery = $this->get_payload_recovery_from_paused_state( $job_session_id );
			}
			if ( null !== $payload_recovery ) {
				$response['payload_recovery'] = $payload_recovery;
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
		$original_status = $row->status;
		$status          = 'queued' === $row->status ? 'processing' : $row->status;
		$response        = [
			'status'     => $status,
			'from_db'    => true,
			'session_id' => $row->session_id,
		];
		$durable_plan    = null;

		if ( in_array( $status, array( 'interrupted', 'abandoned' ), true ) ) {
			$durable_plan = DurablePlanRunner::mark_phase_interrupted_by_job( $job_id );
			if ( null === $durable_plan ) {
				$resume_outcome = $this->maybe_dispatch_checkpoint_resume( $job_id, $row );
				if ( $resume_outcome['dispatched'] ) {
					return new WP_REST_Response(
						array(
							'status'            => 'processing',
							'from_db'           => true,
							'auto_resumed'      => true,
							'original_status'   => $status,
							'session_id'        => $row->session_id,
							'checkpoint_resume' => $resume_outcome['metadata'],
						),
						202
					);
				}

				if ( $resume_outcome['terminal'] ) {
					return $this->checkpoint_resume_terminal_response( $job_id, $row, $resume_outcome['metadata'] );
				}
			}
		}

		if ( in_array( $status, array( 'interrupted', 'abandoned' ), true ) ) {
			$refreshed_row = ActiveJobRepository::get_by_job_id( $job_id );
			if ( null !== $refreshed_row ) {
				$row    = $refreshed_row;
				$status = $row->status;
			}
		}

		$durable_plan = $durable_plan ?? DurablePlanRunner::public_plan_for_job( $job_id );
		if ( self::can_current_user_view_durable_job( $row ) && null !== $durable_plan ) {
			$response['durable_plan'] = $durable_plan;
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

		if ( in_array( $status, array( 'error', 'interrupted', 'abandoned' ), true ) ) {
			$diagnostic             = ActiveJobFailureDiagnostic::from_stored( $job_id, $row->error );
			$response['diagnostic'] = ActiveJobFailureDiagnostic::to_rest( $diagnostic );
			$response['message']    = $this->filter_failure_message( $diagnostic, $row->session_id );

			if ( $this->session_has_recoverable_paused_state( $row->session_id ) ) {
				$response['recoverable'] = true;
			}
			$payload_recovery = $this->get_payload_recovery_from_paused_state( $row->session_id );
			if ( null !== $payload_recovery ) {
				$response['payload_recovery'] = $payload_recovery;
			}

			if ( 'error' !== $status ) {
				$response['status']          = 'error';
				$response['original_status'] = $original_status;
			}
		}

		// Delete DB row on terminal-state delivery (mirrors the transient cleanup).
		if ( in_array( $status, array( 'complete', 'error', 'interrupted', 'abandoned' ), true ) ) {
			ActiveJobRepository::delete( $job_id );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Durable plan state is visible only to the active job's owning user.
	 *
	 * Shared session viewers may inspect ordinary conversation activity, but a
	 * persisted plan can contain owner-scoped operation details and approvals.
	 */
	private static function can_current_user_view_durable_job( ?ActiveJobRow $row ): bool {
		return null !== $row && (int) $row->user_id === get_current_user_id();
	}

	/**
	 * Dispatch a crashed job from a safe durable checkpoint when retry budget remains.
	 *
	 * @param string       $job_id Job UUID.
	 * @param ActiveJobRow $row    Active-job row.
	 * @return array{dispatched:bool,terminal:bool,metadata:array{phase:string,reason:string,size_class:string}}
	 */
	private function maybe_dispatch_checkpoint_resume( string $job_id, ActiveJobRow $row ): array {
		$checkpoint = $this->checkpoint_array( json_decode( (string) $row->checkpoint, true ) );
		if ( null === $checkpoint ) {
			// Legacy interrupted rows without a checkpoint retain the existing
			// sanitized interruption response instead of pretending they resumed.
			return $this->checkpoint_resume_outcome( false, false, 'unavailable', array(), $row->checkpoint_phase );
		}

		$history = $this->checkpoint_resume_history( $checkpoint );
		if ( null === $history ) {
			return $this->checkpoint_resume_outcome( false, false, 'unavailable', array(), $row->checkpoint_phase );
		}

		$phase = (string) $row->checkpoint_phase;
		if ( ! $this->is_auto_resumable_checkpoint_phase( $phase ) ) {
			return $this->terminal_checkpoint_resume( $job_id, $row, 'unsafe_phase', array(), $phase );
		}

		if ( $row->resume_attempts >= self::JOB_AUTO_RESUME_MAX_ATTEMPTS ) {
			return $this->terminal_checkpoint_resume( $job_id, $row, 'retry_budget_exhausted', array(), $phase );
		}

		$metadata = AgentLoop::describe_checkpoint_request(
			$history,
			$phase,
			(string) ( $checkpoint['provider_id'] ?? '' ),
			(string) ( $checkpoint['model_id'] ?? '' )
		);

		if ( $this->checkpoint_resume_requires_compaction( $metadata ) ) {
			$compacted = $this->compact_checkpoint_resume_history( $checkpoint, $history, $metadata, $phase );
			if ( null === $compacted ) {
				return $this->terminal_checkpoint_resume( $job_id, $row, 'not_compactable', $metadata, $phase );
			}

			$checkpoint = $compacted['checkpoint'];
			$metadata   = $compacted['metadata'];
		}

		if ( $this->checkpoint_resume_is_unchanged( $metadata, $this->checkpoint_resume_last_attempt( $checkpoint ) ) ) {
			return $this->terminal_checkpoint_resume( $job_id, $row, 'no_progress', $metadata, $phase );
		}

		$checkpoint['checkpoint_resume_metadata'] = $this->checkpoint_resume_metadata( $checkpoint, $metadata );

		$token = wp_generate_password( 40, false );
		$job   = array(
			'status'            => 'processing',
			'token'             => $token,
			'user_id'           => $row->user_id,
			'tool_calls'        => json_decode( $row->tool_calls, true ) ?: array(),
			'messages'          => array(),
			'checkpoint_resume' => true,
			'checkpoint_state'  => $checkpoint,
			'params'            => array(
				'message'                       => '',
				'history'                       => array(),
				'abilities'                     => array(),
				'system_instruction'            => '',
				'bootstrap_prompt'              => '',
				'max_iterations'                => $checkpoint['iterations_remaining'] ?? null,
				'session_id'                    => $row->session_id,
				'provider_id'                   => $checkpoint['provider_id'] ?? '',
				'model_id'                      => $checkpoint['model_id'] ?? '',
				'page_context'                  => $checkpoint['page_context'] ?? array(),
				'agent_id'                      => self::resumed_agent_id( $checkpoint ),
				'attachments'                   => array(),
				'client_abilities'              => $this->checkpoint_client_abilities( $checkpoint ),
				'anonymous_allowed_abilities'   => $checkpoint['anonymous_allowed_abilities'] ?? array(),
				'anonymous_allowed_collections' => $checkpoint['anonymous_allowed_collections'] ?? array(),
				'anonymous_policy_active'       => ! empty( $checkpoint['anonymous_policy_active'] ),
			),
		);

		if ( ! ActiveJobRepository::claim_resume_attempt( $job_id, self::JOB_AUTO_RESUME_MAX_ATTEMPTS, $checkpoint ) ) {
			$current = ActiveJobRepository::get_by_job_id( $job_id );
			if ( null !== $current && 'processing' === $current->status ) {
				return $this->checkpoint_resume_outcome( true, false, 'resumed', $metadata, $phase );
			}

			return $this->terminal_checkpoint_resume( $job_id, $row, 'claim_failed', $metadata, $phase );
		}

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );
		BackgroundJobDispatcher::dispatch( $job_id, $token );

		return $this->checkpoint_resume_outcome( true, false, 'resumed', $metadata, $phase );
	}

	/**
	 * Resolve the original selected agent for checkpoint and failure resumes.
	 *
	 * @param array<string,mixed> $state Persisted resume state.
	 */
	private static function resumed_agent_id( array $state ): int {
		$slug = sanitize_key( (string) ( $state['agent_slug'] ?? '' ) );
		if ( '' === $slug ) {
			return 0;
		}
		$agent = Agent::get_by_slug( $slug );
		return null !== $agent ? $agent->id : 0;
	}

	/**
	 * Normalize one decoded JSON object to a string-keyed checkpoint array.
	 *
	 * @param mixed $candidate Decoded JSON value.
	 * @return array<string, mixed>|null Normalized checkpoint array, if valid.
	 */
	private function checkpoint_array( mixed $candidate ): ?array {
		if ( ! is_array( $candidate ) ) {
			return null;
		}

		$normalized = array();
		foreach ( $candidate as $key => $value ) {
			if ( ! is_string( $key ) ) {
				return null;
			}
			$normalized[ $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * Decode and validate serialized checkpoint history before it can resume.
	 *
	 * @param array<string, mixed> $checkpoint Decoded checkpoint payload.
	 * @return list<array<string, mixed>>|null Validated serialized history, if available.
	 */
	private function checkpoint_resume_history( array $checkpoint ): ?array {
		$raw_history = $checkpoint['history'] ?? null;
		if ( ! is_array( $raw_history ) || empty( $raw_history ) ) {
			return null;
		}

		/** @var list<array<string, mixed>> $history */
		$history = array();
		foreach ( $raw_history as $message ) {
			$serialized_message = $this->checkpoint_array( $message );
			if ( null === $serialized_message ) {
				return null;
			}
			$history[] = $serialized_message;
		}

		try {
			ConversationSerializer::deserialize( $history );
		} catch ( \Throwable $e ) {
			return null;
		}

		return $history;
	}

	/**
	 * @param array<string, mixed> $metadata Candidate request metadata.
	 * @phpstan-param CheckpointRequest $metadata
	 * @return bool True when the request requires compaction.
	 */
	private function checkpoint_resume_requires_compaction( array $metadata ): bool {
		return (int) ( $metadata['request_bytes'] ?? 0 ) > ConversationTrimmer::COMPACT_MAX_BYTES
			|| (int) ( $metadata['request_tokens'] ?? 0 ) > ConversationTrimmer::COMPACT_MAX_TOKENS
			|| ! empty( $metadata['locally_rejected'] );
	}

	/**
	 * Replace an oversized persisted state with a smaller deterministic resume input.
	 *
	 * @param array<string, mixed>       $checkpoint Decoded checkpoint payload.
	 * @param list<array<string, mixed>> $history Validated serialized history.
	 * @param array<string, mixed>       $metadata Candidate request metadata.
	 * @phpstan-param CheckpointRequest $metadata
	 * @return array{checkpoint:array<string,mixed>,metadata:CheckpointRequest}|null
	 */
	private function compact_checkpoint_resume_history( array $checkpoint, array $history, array $metadata, string $phase ): ?array {
		$byte_budget  = (int) ( $metadata['request_budget_bytes'] ?? 0 );
		$token_budget = (int) ( $metadata['request_budget_tokens'] ?? 0 );
		$byte_budget  = $byte_budget > 0 ? max( 1024, min( ConversationTrimmer::COMPACT_MAX_BYTES, $byte_budget ) ) : ConversationTrimmer::COMPACT_MAX_BYTES;
		$token_budget = $token_budget > 0 ? max( 256, min( ConversationTrimmer::COMPACT_MAX_TOKENS, $token_budget ) ) : ConversationTrimmer::COMPACT_MAX_TOKENS;
		$compacted    = ConversationTrimmer::compact_serialized_history( $history, $byte_budget, $token_budget );
		$next         = AgentLoop::describe_checkpoint_request(
			$compacted['messages'],
			$phase,
			(string) ( $checkpoint['provider_id'] ?? '' ),
			(string) ( $checkpoint['model_id'] ?? '' )
		);

		if (
			(int) $next['request_bytes'] >= (int) $metadata['request_bytes']
			|| $this->checkpoint_resume_requires_compaction( $next )
		) {
			return null;
		}

		$checkpoint['history']                    = $compacted['messages'];
		$checkpoint['checkpoint_resume_metadata'] = is_array( $checkpoint['checkpoint_resume_metadata'] ?? null )
			? $checkpoint['checkpoint_resume_metadata']
			: array();
		$checkpoint['checkpoint_resume_metadata']['recovery_transformation'] = 'compact_checkpoint_resume';
		$checkpoint['checkpoint_resume_metadata']['compaction']              = $compacted['meta'];

		return array(
			'checkpoint' => $checkpoint,
			'metadata'   => $next,
		);
	}

	/**
	 * @param array<string, mixed> $checkpoint Decoded checkpoint payload.
	 * @return ResumeAttempt Last resume-attempt metadata.
	 */
	private function checkpoint_resume_last_attempt( array $checkpoint ): array {
		$resume_metadata = $this->checkpoint_array( $checkpoint['checkpoint_resume_metadata'] ?? null );
		$last_attempt    = null === $resume_metadata
			? null
			: $this->checkpoint_array( $resume_metadata['last_attempt'] ?? null );

		return $this->checkpoint_resume_attempt_metadata( $last_attempt ?? array() );
	}

	/**
	 * Rebuild bounded metadata at the dispatcher boundary so legacy checkpoint
	 * rows cannot carry arbitrary metadata forward into a new resume attempt.
	 *
	 * @param array<string, mixed> $checkpoint Decoded checkpoint payload.
	 * @param array<string, mixed> $request_metadata Candidate request metadata.
	 * @phpstan-param CheckpointRequest $request_metadata
	 * @return array<string, mixed>
	 */
	private function checkpoint_resume_metadata( array $checkpoint, array $request_metadata ): array {
		$resume_metadata = array(
			'version'      => AgentLoop::CHECKPOINT_RESUME_METADATA_VERSION,
			'next_request' => $request_metadata,
			'last_attempt' => $this->checkpoint_resume_attempt_metadata( $request_metadata ),
		);
		$existing        = $checkpoint['checkpoint_resume_metadata'] ?? array();
		if ( ! is_array( $existing ) ) {
			return $resume_metadata;
		}

		$transformation = (string) ( $existing['recovery_transformation'] ?? '' );
		if ( in_array( $transformation, array( 'compact_checkpoint_history', 'compact_checkpoint_resume', 'discard_uncompactable_checkpoint_history' ), true ) ) {
			$resume_metadata['recovery_transformation'] = $transformation;
		}

		if ( isset( $existing['compaction'] ) && is_array( $existing['compaction'] ) ) {
			$compaction = array();
			foreach ( array( 'source_message_count', 'retained_excerpt_count', 'boundary_omitted_count', 'estimated_bytes', 'estimated_tokens', 'max_bytes', 'max_tokens' ) as $key ) {
				if ( isset( $existing['compaction'][ $key ] ) && is_numeric( $existing['compaction'][ $key ] ) ) {
					$compaction[ $key ] = max( 0, (int) $existing['compaction'][ $key ] );
				}
			}
			foreach ( array( 'attachments_omitted', 'tool_payloads_omitted' ) as $key ) {
				if ( isset( $existing['compaction'][ $key ] ) ) {
					$compaction[ $key ] = (bool) $existing['compaction'][ $key ];
				}
			}
			if ( ! empty( $compaction ) ) {
				$resume_metadata['compaction'] = $compaction;
			}
		}

		return $resume_metadata;
	}

	/**
	 * Rehydrate only catalog-backed client ability descriptors for a checkpoint.
	 *
	 * @param array<string, mixed> $checkpoint Decoded checkpoint payload.
	 * @return list<array<string, mixed>>
	 */
	private function checkpoint_client_abilities( array $checkpoint ): array {
		$raw_names = $checkpoint['client_ability_names'] ?? $checkpoint['client_abilities'] ?? array();
		if ( ! is_array( $raw_names ) ) {
			return array();
		}

		$catalog     = JsAbilityCatalog::get_descriptors_by_name();
		$descriptors = array();
		foreach ( $raw_names as $raw_name ) {
			$name = is_array( $raw_name ) ? (string) ( $raw_name['name'] ?? '' ) : (string) $raw_name;
			if ( '' !== $name && isset( $catalog[ $name ] ) ) {
				$descriptors[ $name ] = $catalog[ $name ];
			}
		}

		return array_values( $descriptors );
	}

	/**
	 * @param array<string, mixed> $candidate Candidate request metadata.
	 * @param array<string, mixed> $previous Previous attempted metadata.
	 * @phpstan-param CheckpointRequest $candidate
	 * @phpstan-param ResumeAttempt $previous
	 * @return bool True when the candidate is unchanged.
	 */
	private function checkpoint_resume_is_unchanged( array $candidate, array $previous ): bool {
		$fingerprint = (string) ( $candidate['fingerprint'] ?? '' );
		$prior       = (string) ( $previous['fingerprint'] ?? '' );

		return 64 === strlen( $fingerprint )
			&& 64 === strlen( $prior )
			&& hash_equals( $prior, $fingerprint )
			&& (int) ( $previous['request_bytes'] ?? -1 ) === (int) ( $candidate['request_bytes'] ?? -2 )
			&& (int) ( $previous['request_tokens'] ?? -1 ) === (int) ( $candidate['request_tokens'] ?? -2 )
			&& (string) ( $previous['size_class'] ?? '' ) === (string) ( $candidate['size_class'] ?? '' )
			&& (string) ( $previous['phase'] ?? '' ) === (string) ( $candidate['phase'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $metadata Candidate request metadata.
	 * @return ResumeAttempt Sanitized attempt metadata.
	 */
	private function checkpoint_resume_attempt_metadata( array $metadata ): array {
		return array(
			'fingerprint'    => (string) ( $metadata['fingerprint'] ?? '' ),
			'request_bytes'  => (int) ( $metadata['request_bytes'] ?? 0 ),
			'request_tokens' => (int) ( $metadata['request_tokens'] ?? 0 ),
			'size_class'     => (string) ( $metadata['size_class'] ?? 'unknown' ),
			'phase'          => (string) ( $metadata['phase'] ?? '' ),
		);
	}

	/**
	 * @param array<string, mixed> $metadata Candidate request metadata.
	 * @param string               $reason Recovery reason.
	 * @param string               $phase Durable checkpoint phase.
	 * @return array{phase:string,reason:string,size_class:string} Sanitized public response metadata.
	 */
	private function checkpoint_resume_response_metadata( array $metadata, string $reason, string $phase ): array {
		$size_class = (string) ( $metadata['size_class'] ?? 'unknown' );
		if ( ! in_array( $size_class, array( 'small', 'medium', 'large', 'very_large' ), true ) ) {
			$size_class = 'unknown';
		}

		return array(
			'phase'      => sanitize_key( $phase ),
			'reason'     => sanitize_key( $reason ),
			'size_class' => $size_class,
		);
	}

	/**
	 * @param bool                 $dispatched Whether a resume was dispatched.
	 * @param bool                 $terminal Whether the outcome is terminal.
	 * @param string               $reason The recovery reason.
	 * @param array<string, mixed> $metadata Candidate request metadata.
	 * @param string               $phase The durable checkpoint phase.
	 * @return CheckpointResumeOutcome Resume outcome.
	 */
	private function checkpoint_resume_outcome( bool $dispatched, bool $terminal, string $reason, array $metadata, string $phase ): array {
		return array(
			'dispatched' => $dispatched,
			'terminal'   => $terminal,
			'metadata'   => $this->checkpoint_resume_response_metadata( $metadata, $reason, $phase ),
		);
	}

	/**
	 * @param string               $job_id The job UUID.
	 * @param ActiveJobRow         $row The persisted active-job row.
	 * @param string               $reason The terminal recovery reason.
	 * @param array<string, mixed> $metadata Candidate request metadata.
	 * @param string               $phase The durable checkpoint phase.
	 * @return CheckpointResumeOutcome Terminal resume outcome.
	 */
	private function terminal_checkpoint_resume( string $job_id, ActiveJobRow $row, string $reason, array $metadata, string $phase ): array {
		$public_metadata = $this->checkpoint_resume_response_metadata( $metadata, $reason, $phase );

		ActiveJobRepository::record_failure(
			$job_id,
			'error',
			ActiveJobFailureDiagnostic::REASON_RESUME_EXHAUSTED,
			array(
				'last_safe_phase'    => $public_metadata['phase'],
				'resume_count'       => $row->resume_attempts,
				'request_size_class' => $public_metadata['size_class'],
			)
		);
		AgentEventLog::log(
			'checkpoint_resume_stopped',
			AgentEventLog::SEVERITY_WARNING,
			array(
				'session_id'         => $row->session_id,
				'job_id'             => $job_id,
				'phase'              => $public_metadata['phase'],
				'reason'             => $public_metadata['reason'],
				'attempts'           => $row->resume_attempts,
				'request_size_class' => $public_metadata['size_class'],
			)
		);

		return $this->checkpoint_resume_outcome( false, true, $reason, $metadata, $phase );
	}

	/**
	 * @param string                $job_id The job UUID.
	 * @param ActiveJobRow          $row The persisted active-job row.
	 * @param array<string, string> $metadata Sanitized resume metadata.
	 * @phpstan-param array{phase:string,reason:string,size_class:string} $metadata
	 * @return WP_REST_Response Terminal job-status response.
	 */
	private function checkpoint_resume_terminal_response( string $job_id, ActiveJobRow $row, array $metadata ): WP_REST_Response {
		$stored_row = ActiveJobRepository::get_by_job_id( $job_id );
		$diagnostic = null !== $stored_row
			? ActiveJobFailureDiagnostic::from_stored( $job_id, $stored_row->error )
			: ActiveJobFailureDiagnostic::create(
				$job_id,
				ActiveJobFailureDiagnostic::REASON_RESUME_EXHAUSTED,
				array(
					'last_safe_phase'    => $metadata['phase'],
					'resume_count'       => $row->resume_attempts,
					'request_size_class' => $metadata['size_class'],
				)
			);
		ActiveJobRepository::delete( $job_id );

		return new WP_REST_Response(
			array(
				'status'            => 'error',
				'from_db'           => true,
				'session_id'        => $row->session_id,
				'original_status'   => $row->status,
				'message'           => $this->filter_failure_message( $diagnostic, $row->session_id ),
				'diagnostic'        => ActiveJobFailureDiagnostic::to_rest( $diagnostic ),
				'checkpoint_resume' => $metadata,
			),
			200
		);
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
	 * Add a prompt-free diagnostic for a transient-backed failed job.
	 *
	 * @param string               $job_id     Active-job UUID.
	 * @param array<string, mixed> $job        Job transient payload.
	 * @param int                  $session_id Session identifier.
	 * @param array<string, mixed> $response   REST response, updated by reference.
	 * @return void
	 */
	private function add_transient_failure_response( string $job_id, array $job, int $session_id, array &$response ): void {
		$stored_diagnostic = $job['diagnostic'] ?? array();
		$stored_diagnostic = is_array( $stored_diagnostic ) ? $stored_diagnostic : array();
		$diagnostic        = ActiveJobFailureDiagnostic::create(
			$job_id,
			(string) ( $stored_diagnostic['reason'] ?? ActiveJobFailureDiagnostic::REASON_UNKNOWN ),
			$stored_diagnostic
		);

		$response['diagnostic'] = ActiveJobFailureDiagnostic::to_rest( $diagnostic );
		$response['message']    = $this->filter_failure_message( $diagnostic, $session_id );
	}

	/**
	 * Filter a fixed, prompt-free failure message with safe metadata only.
	 *
	 * @param array<string, bool|int|string> $diagnostic Safe diagnostic envelope.
	 * @param int                            $session_id Session identifier.
	 * @return string Customer-facing message.
	 */
	private function filter_failure_message( array $diagnostic, int $session_id ): string {
		/**
		 * Filter the prompt-free failure message returned to the chat client.
		 *
		 * @since 1.11.0
		 *
		 * @param string               $message    Fixed message for the normalized reason.
		 * @param array<string, mixed> $context    Safe diagnostic and session context.
		 */
		$message = apply_filters(
			'sd_ai_agent_chat_error_message',
			ActiveJobFailureDiagnostic::message_for( (string) $diagnostic['reason'] ),
			array(
				'session_id' => $session_id,
				'diagnostic' => ActiveJobFailureDiagnostic::to_rest( $diagnostic ),
			)
		);

		return is_string( $message ) && '' !== $message
			? $message
			: ActiveJobFailureDiagnostic::message_for( (string) $diagnostic['reason'] );
	}

	/**
	 * Persist a prompt-free terminal failure and replace transient error data.
	 *
	 * @param string               $job_id  Active-job UUID.
	 * @param array<string, mixed> $job     Job transient payload, updated by reference.
	 * @param string               $reason  Normalized diagnostic reason.
	 * @param array<string, mixed> $context Allowlisted diagnostic metadata.
	 * @return array<string, bool|int|string> Persisted diagnostic envelope.
	 */
	private function persist_active_job_failure( string $job_id, array &$job, string $reason, array $context = array() ): array {
		$diagnostic        = ActiveJobRepository::record_failure( $job_id, 'error', $reason, $context );
		$job['status']     = 'error';
		$job['error']      = ActiveJobFailureDiagnostic::message_for( (string) $diagnostic['reason'] );
		$job['diagnostic'] = ActiveJobFailureDiagnostic::to_rest( $diagnostic );
		unset( $job['error_context'] );

		return $diagnostic;
	}

	/**
	 * Get sanitized public chat settings.
	 *
	 * @return array{
	 *     enabled:bool,
	 *     origins:list<string>,
	 *     provider_id:string,
	 *     model_id:string,
	 *     agent_id:int,
	 *     embed_id:string,
	 *     collections:list<string>,
	 *     abilities:list<string>,
	 *     iterations:int,
	 *     message_length:int,
	 *     rate_limit:int,
	 *     review_recording_enabled:bool,
	 *     review_retention_days:int,
	 *     review_disclosure:string,
	 *     speech_enabled:bool,
	 *     speech_voice:string,
	 *     speech_max_recording_seconds:int,
	 *     speech_max_audio_bytes:int,
	 *     speech_max_tts_characters:int,
	 *     speech_voice_mode_enabled:bool,
	 *     speech_disclosure:string
	 * }
	 */
	private function get_public_chat_settings(): array {
		return $this->public_chat_security->settings();
	}

	/** Validate public chat is enabled and the request origin is allowed. */
	private function check_public_chat_available( WP_REST_Request $request ): true|WP_Error {
		return $this->public_chat_security->check_available( $request );
	}

	/** Resolve the public-chat request origin or referer. */
	private function get_public_chat_request_origin( WP_REST_Request $request ): string {
		return $this->public_chat_security->request_origin( $request );
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
		return $this->public_chat_security->add_cors( $response, $origin, $allowed_origins );
	}

	/**
	 * Whether a request origin matches configured public-chat origins.
	 *
	 * @param string       $origin          Request origin/referer.
	 * @param list<string> $allowed_origins Allowed origins/hosts.
	 */
	// phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<string> is valid PHPStan but not a native PHP type.
	private function public_origin_is_allowed( string $origin, array $allowed_origins ): bool {
		return $this->public_chat_security->origin_is_allowed( $origin, $allowed_origins );
	}

	/** Create a signed opaque public session token. */
	private function create_public_chat_token( string $session_uuid, string $embed_id, string $origin ): string {
		return $this->public_chat_security->create_token( $session_uuid, $embed_id, $origin );
	}

	/** Public session transient key. */
	private function public_chat_session_key( string $session_uuid ): string {
		return $this->public_chat_security->session_key( $session_uuid );
	}

	/** Consume a public-chat rate-limit token. */
	private function check_public_chat_rate_limit( string $session_uuid, int $limit ): true|WP_Error {
		return $this->public_chat_security->check_rate_limit( $session_uuid, $limit );
	}

	/** Create an anonymous public chat token/session. */
	public function handle_public_chat_session( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$available = $this->check_public_chat_available( $request );
		if ( true !== $available ) {
			return $available;
		}
		$config            = $this->get_public_chat_settings();
		$origin            = $this->get_public_chat_request_origin( $request );
		$consent           = $request->get_param( 'recording_consent' );
		$recording_consent = true === $consent || 1 === $consent || '1' === $consent || 'true' === $consent;
		$embed_id          = sanitize_key( self::get_string_param( $request, 'embed_id' ) );
		$embed_id          = '' !== $embed_id ? $embed_id : (string) $config['embed_id'];
		if ( '' === $embed_id || $embed_id !== $config['embed_id'] ) {
			return new WP_Error( 'sd_ai_agent_public_chat_invalid_embed', __( 'The public chat embed is invalid.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}
		$locale = $this->public_chat_security->normalize_locale( $request->get_param( 'locale' ) );
		if ( $locale instanceof WP_Error ) {
			return $locale;
		}

		$session_uuid = wp_generate_uuid4();
		$token        = $this->create_public_chat_token( $session_uuid, $embed_id, $origin );
		$review_id    = '';
		if ( ! empty( $config['review_recording_enabled'] ) && $recording_consent ) {
			$candidate_review_id = wp_generate_uuid4();
			$review_expires_at   = gmdate( 'Y-m-d H:i:s', time() + ( DAY_IN_SECONDS * (int) $config['review_retention_days'] ) );
			try {
				if ( CustomerConversationReviewRepository::create_public_review(
					$candidate_review_id,
					(int) $config['agent_id'],
					(string) $config['provider_id'],
					(string) $config['model_id'],
					$review_expires_at
				) ) {
					$review_id = $candidate_review_id;
				}
			} catch ( \Throwable $exception ) {
				// Review recording is deliberately fail-open for the public chat flow.
			}
		}
		set_transient(
			$this->public_chat_session_key( $session_uuid ),
			array(
				'history'       => array(),
				'review_id'     => $review_id,
				'embed_id'      => $embed_id,
				'origin_hash'   => $this->public_chat_security->origin_binding( $origin ),
				'speech_locale' => null === $locale ? '' : $locale,
				'created_at'    => time(),
			),
			PublicChatSecurity::SESSION_TTL
		);

		return $this->add_public_chat_cors(
			new WP_REST_Response(
				array(
					'token'      => $token,
					'expires_in' => PublicChatSecurity::SESSION_TTL,
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

		$config     = $this->get_public_chat_settings();
		$origin     = $this->get_public_chat_request_origin( $request );
		$token      = self::get_string_param( $request, 'token' );
		$authorized = $this->public_chat_security->authorize_session( $request, $token );
		if ( $authorized instanceof WP_Error ) {
			return $authorized;
		}
		$session_uuid = $authorized['session_uuid'];
		$session      = $authorized['session'];

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
		$review_id = isset( $session['review_id'] ) && is_string( $session['review_id'] ) ? $session['review_id'] : '';

		$job = array(
			'job_id'              => $job_id,
			'public_chat'         => true,
			'public_session_uuid' => $session_uuid,
			'public_token_hash'   => hash( 'sha256', $token ),
			'public_review_id'    => $review_id,
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
				'anonymous_policy_active'       => true,
			),
		);
		$this->record_public_chat_review_turn(
			$job,
			'user',
			$message,
			'processing',
			array(
				'provider_id' => (string) $config['provider_id'],
				'model_id'    => (string) $config['model_id'],
			)
		);

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );
		ActiveJobRepository::create( 0, $job_id, 0 );

		BackgroundJobDispatcher::dispatch( $job_id, $job_token );

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

		$authorization = trim( (string) $request->get_header( 'authorization' ) );
		$matches       = array();
		$token         = 1 === preg_match( '/^Bearer\s+(\S+)$/i', $authorization, $matches )
			? sanitize_text_field( (string) $matches[1] )
			: '';
		if ( '' === $token ) {
			return new WP_Error( 'sd_ai_agent_public_chat_invalid_token', __( 'Invalid public chat token.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}
		$authorized = $this->public_chat_security->authorize_session( $request, $token );
		if ( $authorized instanceof WP_Error ) {
			return $authorized;
		}

		$job_id = self::get_string_param( $request, 'id' );
		$job    = get_transient( RestController::JOB_PREFIX . $job_id );
		if ( ! is_array( $job ) || empty( $job['public_chat'] ) || ( $job['public_session_uuid'] ?? '' ) !== $authorized['session_uuid'] || ( $job['public_token_hash'] ?? '' ) !== $authorized['token_hash'] ) {
			return new WP_Error( 'sd_ai_agent_public_chat_job_not_found', __( 'Public chat job not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		$response = array( 'status' => $job['status'] ?? 'processing' );
		if ( ! empty( $job['tool_calls'] ) ) {
			$response['tool_calls'] = $job['tool_calls'];
		}
		if ( ! empty( $job['messages'] ) ) {
			$response['messages'] = is_array( $job['messages'] )
				? ConversationDisplaySanitizer::sanitize_activity_messages( $job['messages'] )
				: array();
		}

		if ( 'complete' === ( $job['status'] ?? '' ) && isset( $job['result'] ) && is_array( $job['result'] ) ) {
			$response['reply']           = ConversationDisplaySanitizer::sanitize_display_text( (string) ( $job['result']['reply'] ?? '' ) );
			$response['history']         = isset( $job['result']['history'] ) && is_array( $job['result']['history'] ) ? ConversationDisplaySanitizer::sanitize_messages( $job['result']['history'] ) : array();
			$response['tool_calls']      = $job['result']['tool_calls'] ?? array();
			$response['iterations_used'] = $job['result']['iterations_used'] ?? 0;
			$grant                       = $this->public_chat_security->issue_synthesis_grant( $authorized['session_uuid'], $token, $origin, (string) $response['reply'] );
			if ( null !== $grant ) {
				$response['speech'] = array(
					'synthesis_grant' => $grant['grant'],
					'expires_in'      => $grant['expires_in'],
				);
			}
			delete_transient( RestController::JOB_PREFIX . $job_id );
			ActiveJobRepository::delete( $job_id );
		} elseif ( 'error' === ( $job['status'] ?? '' ) ) {
			unset( $response['tool_calls'], $response['messages'] );
			$this->add_transient_failure_response( $job_id, $job, 0, $response );
			delete_transient( RestController::JOB_PREFIX . $job_id );
			ActiveJobRepository::delete( $job_id );
		}

		return $this->add_public_chat_cors( new WP_REST_Response( $response, 200 ), $origin, $config['origins'] );
	}

	/**
	 * Persist one already-safe public-chat turn without exposing the review ID.
	 *
	 * The job and metadata contain only server-side public-chat state and safe
	 * provider/model/usage fields.
	 *
	 * @param array<string,mixed> $job      Public transient job.
	 * @param string              $role     Safe speaker role.
	 * @param string              $content  Safe turn content.
	 * @param string              $status   Public turn status.
	 * @param array<string,mixed> $metadata Safe provider, model, and usage fields.
	 */
	private function record_public_chat_review_turn( array $job, string $role, string $content, string $status, array $metadata = array() ): void {
		if ( ! $this->public_chat_review_recording_enabled() ) {
			return;
		}

		$review_id = isset( $job['public_review_id'] ) && is_string( $job['public_review_id'] ) ? $job['public_review_id'] : '';
		$job_id    = isset( $job['job_id'] ) && is_string( $job['job_id'] ) ? $job['job_id'] : '';
		if ( '' === $review_id || '' === $job_id ) {
			return;
		}

		try {
			CustomerConversationReviewRepository::append_public_turn( $review_id, $job_id, $role, $content, $status, $metadata );
		} catch ( \Throwable $exception ) {
			// Review persistence remains non-blocking for anonymous customer traffic.
		}
	}

	/**
	 * Persist a scrubbed public-chat terminal state without error detail.
	 *
	 * @param array<string,mixed> $job Public transient job.
	 */
	private function record_public_chat_review_status( array $job, string $status, string $error_code = '' ): void {
		if ( ! $this->public_chat_review_recording_enabled() ) {
			return;
		}

		$review_id = isset( $job['public_review_id'] ) && is_string( $job['public_review_id'] ) ? $job['public_review_id'] : '';
		$job_id    = isset( $job['job_id'] ) && is_string( $job['job_id'] ) ? $job['job_id'] : '';
		if ( '' === $review_id || '' === $job_id ) {
			return;
		}

		try {
			CustomerConversationReviewRepository::update_public_review_status(
				$review_id,
				$job_id,
				$status,
				array( 'error_code' => $error_code )
			);
		} catch ( \Throwable $exception ) {
			// Review persistence remains non-blocking for anonymous customer traffic.
		}
	}

	/** Stop new anonymous review writes as soon as site recording is disabled. */
	private function public_chat_review_recording_enabled(): bool {
		$config = $this->get_public_chat_settings();

		return ! empty( $config['review_recording_enabled'] );
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
				'history'                       => array_values( $history ),
				'tool_call_log'                 => array_values( $tool_calls ),
				'message_log'                   => array_values( $message_log ),
				'token_usage'                   => $token_usage,
				'model_id'                      => (string) ( $error_data['model_id']
					?? $options['model_id']
					?? $params['model_id']
					?? '' ),
				'provider_id'                   => (string) ( $error_data['provider_id']
					?? $options['provider_id']
					?? $params['provider_id']
					?? '' ),
				'client_abilities'              => $client_abilities,
				'mutation_policy_context'       => $error_data['mutation_policy_context'] ?? array(),
				'anonymous_allowed_abilities'   => $options['anonymous_allowed_abilities'] ?? array(),
				'anonymous_allowed_collections' => $options['anonymous_allowed_collections'] ?? array(),
				'anonymous_policy_active'       => ! empty( $options['anonymous_policy_active'] ),
				'exit_reason'                   => (string) $error->get_error_code(),
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
		$existing_tool_calls = json_decode( (string) $session->tool_calls, true );
		if ( ! is_array( $existing_tool_calls ) ) {
			$existing_tool_calls = array();
		}

		$append_offset  = $this->get_history_append_offset( $full_history, $existing_messages );
		$appended       = array_slice( $full_history, $append_offset );
		$new_tool_calls = $this->get_unpersisted_recovery_rows( $tool_calls, $existing_tool_calls );
		if ( empty( $appended ) && empty( $new_tool_calls ) ) {
			return true;
		}

		return $this->database->append_to_session( $session_id, array_values( $appended ), $new_tool_calls );
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
			if ( ! isset( $existing_messages[ $index ] ) || ! is_array( $existing_messages[ $index ] ) || ! $this->recovery_rows_are_semantically_equal( $existing_messages[ $index ], $row ) ) {
				break;
			}

			++$common_prefix;
		}

		if ( $common_prefix > 0 || empty( $existing_messages ) ) {
			return $common_prefix;
		}

		foreach ( $full_history as $index => $row ) {
			if ( ! $this->recovery_row_exists( $row, $existing_messages ) ) {
				return $index;
			}
		}

		return count( $full_history );
	}

	/**
	 * Return recovery rows that have not already been persisted.
	 *
	 * Tool-call entries are a separate append-only log from history. Filtering
	 * against the persisted log makes a replay of the same recovery payload
	 * idempotent without globally deduplicating conversation messages.
	 *
	 * @param list<array<string, mixed>> $recovery_rows Recovery rows to append.
	 * @param array<mixed>               $persisted_rows Existing stored rows.
	 * @return list<array<string, mixed>>
	 */
	private function get_unpersisted_recovery_rows( array $recovery_rows, array $persisted_rows ): array {
		$unpersisted = array();
		foreach ( $recovery_rows as $row ) {
			if ( ! $this->recovery_row_exists( $row, $persisted_rows ) ) {
				$unpersisted[] = $row;
			}
		}

		return $unpersisted;
	}

	/**
	 * Check whether a canonical recovery row exists in a list of persisted rows.
	 *
	 * @param array<string, mixed> $needle Candidate row.
	 * @param array<mixed>         $haystack Persisted rows.
	 * @return bool
	 */
	private function recovery_row_exists( array $needle, array $haystack ): bool {
		foreach ( $haystack as $row ) {
			if ( is_array( $row ) && $this->recovery_rows_are_semantically_equal( $needle, $row ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compare serialized recovery rows by conversation semantics.
	 *
	 * Only provider/model attribution is transport metadata. All other fields,
	 * including role, ordered parts, function-call/result IDs, and payloads,
	 * remain part of the canonical value.
	 *
	 * @param array<string, mixed> $left First serialized row.
	 * @param array<string, mixed> $right Second serialized row.
	 * @return bool
	 */
	private function recovery_rows_are_semantically_equal( array $left, array $right ): bool {
		return $this->canonicalize_recovery_row( $left ) === $this->canonicalize_recovery_row( $right );
	}

	/**
	 * Remove documented transport metadata and normalize associative-key order.
	 *
	 * @param array<string, mixed> $row Serialized message or tool-call row.
	 * @return array<string, mixed>
	 */
	private function canonicalize_recovery_row( array $row ): array {
		foreach ( self::RECOVERY_TRANSPORT_METADATA as $metadata_key ) {
			unset( $row[ $metadata_key ] );
		}

		/** @var array<string, mixed> $canonical */
		$canonical = $this->canonicalize_recovery_value( $row );
		return $canonical;
	}

	/**
	 * Canonicalize array keys without changing ordered conversation lists.
	 *
	 * @param mixed $value Serialized field value.
	 * @return mixed
	 */
	private function canonicalize_recovery_value( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $entry ) {
			$value[ $key ] = $this->canonicalize_recovery_value( $entry );
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		return $value;
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
	 * Validate the compact-continuation metadata exposed to the chat client.
	 *
	 * Error data can originate in provider SDK layers, so reduce it to the two
	 * identifiers the UI needs rather than forwarding arbitrary error fields.
	 *
	 * @param mixed $recovery   Candidate recovery payload.
	 * @param int   $session_id Owning source session ID.
	 * @return array{action:string,source_session_id:int}|null Safe action, or null.
	 */
	private function normalize_payload_recovery( mixed $recovery, int $session_id ): ?array {
		if (
			$session_id <= 0
			|| ! is_array( $recovery )
			|| 'compact_session' !== ( $recovery['action'] ?? '' )
			|| $session_id !== (int) ( $recovery['source_session_id'] ?? 0 )
		) {
			return null;
		}

		return array(
			'action'            => 'compact_session',
			'source_session_id' => $session_id,
		);
	}

	/**
	 * Rebuild safe compaction recovery metadata after a terminal job transient expires.
	 *
	 * @param int $session_id Source session ID.
	 * @return array{action:string,source_session_id:int}|null Safe action, or null.
	 */
	private function get_payload_recovery_from_paused_state( int $session_id ): ?array {
		if ( $session_id <= 0 ) {
			return null;
		}

		$session = $this->database->get_session( $session_id );
		if ( ! $session || empty( $session->paused_state ) ) {
			return null;
		}

		$paused_state = json_decode( (string) $session->paused_state, true );
		if ( ! is_array( $paused_state ) || 'sd_ai_agent_provider_payload_budget_exceeded' !== ( $paused_state['exit_reason'] ?? '' ) ) {
			return null;
		}

		return array(
			'action'            => 'compact_session',
			'source_session_id' => $session_id,
		);
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
	 * Queues a message on the job transient that the agent loop's interrupt
	 * checker will consume on the next iteration. The interrupt message is appended to
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
		$lock    = self::acquire_job_mutation_lock( $job_id, 1 );
		if ( null === $lock ) {
			return new WP_Error(
				'sd_ai_agent_job_busy',
				__( 'The job is processing another update. Please retry shortly.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}

		try {
			$job = get_transient( RestController::JOB_PREFIX . $job_id );

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
		} finally {
			self::release_job_mutation_lock( $lock );
		}
	}

	/** Acquire a cross-request lock before mutating one active job transient. */
	private static function acquire_job_mutation_lock( string $job_id, int $timeout = 0 ): ?string {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$lock_name = 'sdai_job_' . substr( hash( 'sha256', get_current_blog_id() . '|' . $job_id ), 0, 48 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A database advisory lock serializes concurrent job-transient mutations across PHP requests and web heads.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, $timeout ) );

		return 1 === (int) $acquired ? $lock_name : null;
	}

	/** Release an active-job mutation lock. */
	private static function release_job_mutation_lock( string $lock_name ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the request-scoped advisory lock acquired immediately above.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	}

	/**
	 * Build the callback that consumes queued user interrupts.
	 *
	 * @param string $job_id Active job UUID.
	 * @return \Closure(): ?array
	 */
	private static function build_job_interrupt_checker( string $job_id ): \Closure {
		return static function () use ( $job_id ): ?array {
			$transient_key = RestController::JOB_PREFIX . $job_id;
			$lock          = self::acquire_job_mutation_lock( $job_id, 1 );
			if ( null === $lock ) {
				return null;
			}

			try {
				$job = get_transient( $transient_key );

				if ( ! is_array( $job ) || 'processing' !== ( $job['status'] ?? '' ) ) {
					return null;
				}

				$current_interrupts = $job['interrupts'] ?? array();
				$interrupts         = is_array( $current_interrupts ) ? array_values( $current_interrupts ) : array();
				if ( empty( $interrupts ) ) {
					return null;
				}

				$interrupt         = array_shift( $interrupts );
				$job['interrupts'] = $interrupts;
				set_transient( $transient_key, $job, RestController::JOB_TTL + 60 );

				return is_array( $interrupt ) ? $interrupt : null;
			} finally {
				self::release_job_mutation_lock( $lock );
			}
		};
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

		$paused_state = self::compact_oversized_provider_retry_state( $paused_state, $session_id );

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
				'message'                       => '',
				'history'                       => array(),
				'abilities'                     => array(),
				'system_instruction'            => '',
				'bootstrap_prompt'              => '',
				'max_iterations'                => $paused_state['iterations_remaining'] ?? null,
				'session_id'                    => $session_id,
				'provider_id'                   => $paused_state['provider_id'] ?? '',
				'model_id'                      => $paused_state['model_id'] ?? '',
				'page_context'                  => $paused_state['page_context'] ?? array(),
				'agent_id'                      => self::resumed_agent_id( $paused_state ),
				'attachments'                   => array(),
				'client_abilities'              => $paused_state['client_abilities'] ?? array(),
				'anonymous_allowed_abilities'   => $paused_state['anonymous_allowed_abilities'] ?? array(),
				'anonymous_allowed_collections' => $paused_state['anonymous_allowed_collections'] ?? array(),
				'anonymous_policy_active'       => ! empty( $paused_state['anonymous_policy_active'] ),
			),
		);

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );
		ActiveJobRepository::create( $session_id, $job_id, get_current_user_id() );

		BackgroundJobDispatcher::dispatch( $job_id, $token );

		return new WP_REST_Response(
			array(
				'job_id' => $job_id,
				'status' => 'processing',
			),
			202
		);
	}

	/**
	 * Ensure a manual provider retry makes payload progress instead of resending
	 * the same oversized single-turn history that already exhausted all retries.
	 *
	 * @param array<string, mixed> $paused_state Recoverable loop state.
	 * @param int                  $session_id   Owning session identifier.
	 * @return array<string, mixed> State with bounded history when required.
	 */
	private static function compact_oversized_provider_retry_state( array $paused_state, int $session_id ): array {
		$exit_reason = (string) ( $paused_state['exit_reason'] ?? '' );
		if ( ! in_array( $exit_reason, array( 'provider_retry_failed', 'sd_ai_agent_provider_retry_failed' ), true ) ) {
			return $paused_state;
		}

		$history = $paused_state['history'] ?? array();
		if ( ! is_array( $history ) || empty( $history ) ) {
			return $paused_state;
		}

		$encoded_history = wp_json_encode( $history );
		$request_bytes   = is_string( $encoded_history ) ? strlen( $encoded_history ) : 0;
		$request_tokens  = (int) ceil( $request_bytes / 4 );
		$provider_id     = (string) ( $paused_state['provider_id'] ?? '' );
		$model_id        = (string) ( $paused_state['model_id'] ?? '' );
		$max_bytes       = min(
			ConversationTrimmer::COMPACT_MAX_BYTES,
			ConversationTrimmer::get_request_envelope_byte_budget( $provider_id, $model_id )
		);
		$max_tokens      = min(
			ConversationTrimmer::COMPACT_MAX_TOKENS,
			ConversationTrimmer::get_request_token_budget( $provider_id, $model_id )
		);
		if (
			$request_bytes <= $max_bytes
			&& $request_tokens <= $max_tokens
		) {
			return $paused_state;
		}

		$compacted = ConversationTrimmer::compact_serialized_history( array_values( $history ), $max_bytes, $max_tokens );
		if ( empty( $compacted['messages'] ) ) {
			return $paused_state;
		}

		$paused_state['history'] = $compacted['messages'];

		AgentEventLog::log(
			'recoverable_job_history_compacted',
			AgentEventLog::SEVERITY_INFO,
			array(
				'session_id'              => $session_id,
				'phase'                   => 'recoverable_job_resume',
				'provider_id'             => $provider_id,
				'model_id'                => $model_id,
				'history_count'           => count( $history ),
				'request_bytes_estimate'  => $request_bytes,
				'request_tokens_estimate' => $request_tokens,
				'payload_reduced'         => true,
				'recovery_outcome'        => 'compact_provider_retry',
			)
		);

		return $paused_state;
	}

	/**
	 * Resume a paused job after confirmation or rejection.
	 *
	 * @param string               $job_id Job identifier.
	 * @param array<string, mixed> $job    Job transient data.
	 * @param string               $action 'confirm' or 'reject'.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function resume_job( string $job_id, array $job, string $action ): WP_REST_Response|WP_Error {
		$token                 = wp_generate_password( 40, false );
		$params                = is_array( $job['params'] ?? null ) ? $job['params'] : array();
		$phase                 = is_array( $params['durable_plan_phase'] ?? null ) ? $params['durable_plan_phase'] : array();
		$is_durable_plan_phase = '' !== (string) ( $phase['plan_id'] ?? '' )
			&& (int) ( $phase['step_id'] ?? 0 ) > 0;

		if ( $is_durable_plan_phase && ! ActiveJobRepository::requeue_paused_job( $job_id ) ) {
			return new WP_Error(
				'sd_ai_agent_job_not_resumable',
				__( 'This durable plan job is no longer waiting for confirmation.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}

		$job['status'] = 'processing';
		$job['token']  = $token;
		$job['resume'] = $action;

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );

		// Durable jobs are atomically queued above so exactly one loopback worker
		// can claim them. Other jobs retain their established processing transition.
		if ( ! $is_durable_plan_phase ) {
			ActiveJobRepository::update_status( $job_id, 'processing' );
		}

		BackgroundJobDispatcher::dispatch( $job_id, $token );

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
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$durable_plan_requested = true === $request->get_param( 'durable_plan' );
		$session_id             = self::get_int_param( $request, 'session_id' );
		if ( $durable_plan_requested ) {
			$session = $this->database->get_session( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sd_ai_agent_session_not_found', __( 'A durable plan requires an existing session.', 'superdav-ai-agent' ), [ 'status' => 404 ] );
			}
			if ( (int) $session->user_id !== get_current_user_id() ) {
				return new WP_Error( 'sd_ai_agent_plan_forbidden', __( 'Only the session owner can create a durable plan.', 'superdav-ai-agent' ), [ 'status' => 403 ] );
			}
		}

		$job_id = wp_generate_uuid4();
		$token  = wp_generate_password( 40, false );

		// Upload attachments to the media library NOW (in the browser-facing
		// request that has auth cookies) so the loopback worker doesn't need to.
		$raw_attachments = $durable_plan_requested ? array() : ( $request->get_param( 'attachments' ) ?? array() );
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
				'session_id'         => $session_id,
				'provider_id'        => $request->get_param( 'provider_id' ),
				'model_id'           => $request->get_param( 'model_id' ),
				'page_context'       => $request->get_param( 'page_context' ),
				'agent_id'           => $request->get_param( 'agent_id' ),
				'attachments'        => $attachments,
				'client_abilities'   => $request->get_param( 'client_abilities' ) ?? array(),
				'durable_plan'       => $durable_plan_requested,
			),
		);

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );

		// Persist to DB so the job survives transient expiry (source of truth).
		// @phpstan-ignore-next-line
		$db_session_id = $session_id;
		ActiveJobRepository::create(
			$db_session_id,
			$job_id,
			(int) ( $job['user_id'] ?? 0 ),
			$durable_plan_requested ? 'queued' : 'processing'
		);

		BackgroundJobDispatcher::dispatch( $job_id, $token );

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
		$session_id              = ! empty( $params['session_id'] ) ? (int) $params['session_id'] : 0;
		$durable_plan_phase      = $params['durable_plan_phase'] ?? null;
		$is_durable_plan_phase   = is_array( $durable_plan_phase )
			&& '' !== (string) ( $durable_plan_phase['plan_id'] ?? '' )
			&& (int) ( $durable_plan_phase['step_id'] ?? 0 ) > 0;
		$is_durable_plan_request = ! $is_durable_plan_phase && ! empty( $params['durable_plan'] );
		if ( ( $is_durable_plan_phase || $is_durable_plan_request ) && ! ActiveJobRepository::claim_queued_job( $job_id ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}

		// Durable plans deliberately start every provider turn with only the compact
		// active-phase context. They must not grow provider context by reloading the
		// session transcript after every completed phase.
		$history = array();
		if ( $is_durable_plan_phase || $is_durable_plan_request ) {
			$history = array();
		} elseif ( $session_id ) {
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
				$this->persist_active_job_failure(
					$job_id,
					$job,
					ActiveJobFailureDiagnostic::REASON_LOOP_EXCEPTION,
					array(
						'last_safe_phase' => 'history_deserialization',
						'provider_id'     => (string) ( $params['provider_id'] ?? '' ),
						'model_id'        => (string) ( $params['model_id'] ?? '' ),
					)
				);
				unset( $job['token'] );
				set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );
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

		$anonymous_allowed_abilities = $params['anonymous_allowed_abilities'] ?? null;
		if ( is_array( $anonymous_allowed_abilities ) ) {
			$options['anonymous_allowed_abilities'] = array_values( $anonymous_allowed_abilities );
		}
		$anonymous_allowed_collections = $params['anonymous_allowed_collections'] ?? null;
		if ( is_array( $anonymous_allowed_collections ) ) {
			$options['anonymous_allowed_collections'] = array_values( $anonymous_allowed_collections );
		}
		if ( ! empty( $params['anonymous_policy_active'] ) ) {
			$options['anonymous_policy_active'] = true;
			$options['client_abilities']        = array();
			$options['yolo_mode']               = false;
		}

		if ( ! empty( $job['public_chat'] ) ) {
			$allowed_abilities                        = $params['anonymous_allowed_abilities'] ?? array();
			$allowed_collections                      = $params['anonymous_allowed_collections'] ?? array();
			$options['anonymous_allowed_abilities']   = is_array( $allowed_abilities ) ? array_values( $allowed_abilities ) : array( 'sd-ai-agent/knowledge-search' );
			$options['anonymous_allowed_collections'] = is_array( $allowed_collections ) ? array_values( $allowed_collections ) : array();
			$options['anonymous_policy_active']       = true;
			$options['client_abilities']              = array();
			$options['yolo_mode']                     = false;
		}

		// Apply agent overrides (agent_id takes precedence over individual params).
		if ( ! empty( $params['agent_id'] ) ) {
			// @phpstan-ignore-next-line
			$agent_options = Agent::get_loop_options( (int) $params['agent_id'] );
			$options       = array_merge( $options, $agent_options );
		}

		if ( $is_durable_plan_request ) {
			// A planning request is a no-tools, one-turn proposal. Do not allow
			// a selected agent or browser descriptor to restore normal tool access.
			$options['durable_plan_mode'] = true;
			$options['client_abilities']  = array();
			$options['yolo_mode']         = false;
		}

		/*
		 * Pass the job UUID to AgentLoop so it can:
		 * (a) issue heartbeats on each iteration, keeping updated_at fresh so
		 *     the hourly stale-job reaper treats this as an active loop; and
		 * (b) register a shutdown handler that marks the row as 'interrupted'
		 *     when the PHP process terminates before loop completion.
		 */
		$options['active_job_id']     = $job_id;
		$options['interrupt_checker'] = self::build_job_interrupt_checker( $job_id );

		// Progress callback: write live tool-call activity and channel messages
		// to the job transient so the polling frontend can display them incrementally.
		$progress_job_id              = $job_id;
		$options['progress_callback'] = static function ( array $tool_call_log, array $message_log = array() ) use ( $progress_job_id ) {
			$lock = self::acquire_job_mutation_lock( $progress_job_id );
			if ( null === $lock ) {
				return;
			}

			try {
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
			} finally {
				self::release_job_mutation_lock( $lock );
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

				$resume_options['checkpoint_resume_metadata'] = is_array( $state ) && is_array( $state['checkpoint_resume_metadata'] ?? null )
					? $state['checkpoint_resume_metadata']
					: array();
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

				$resume_options['mutation_policy_context'] = is_array( $state ) ? ( $state['mutation_policy_context'] ?? array() ) : array();

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
				// @phpstan-ignore-next-line -- Stored by AgentLoop from the original, unsplit assistant batch.
				$resume_options['confirmation_message'] = $state['confirmation_message'] ?? array();
				// @phpstan-ignore-next-line -- Stored by AgentLoop before it splits parallel tool calls for transport.
				$resume_options['confirmation_history_before'] = $state['confirmation_history_before'] ?? null;
				// @phpstan-ignore-next-line -- Stored by AgentLoop from the originating user turn.
				$resume_options['mutation_policy_context'] = $state['mutation_policy_context'] ?? array();

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
			$diagnostic = $this->persist_active_job_failure(
				$job_id,
				$job,
				ActiveJobFailureDiagnostic::REASON_LOOP_EXCEPTION,
				array(
					'last_safe_phase' => 'process_exception',
					'provider_id'     => (string) ( $options['provider_id'] ?? $params['provider_id'] ?? '' ),
					'model_id'        => (string) ( $options['model_id'] ?? $params['model_id'] ?? '' ),
				)
			);
			$this->record_public_chat_review_status( $job, 'failed', (string) $diagnostic['reason'] );

			if ( $session_id && ! $is_durable_plan_phase ) {
				$recovery_error = new WP_Error(
					'sd_ai_agent_loop_exception',
					ActiveJobFailureDiagnostic::message_for( (string) $diagnostic['reason'] ),
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

			unset( $job['token'], $job['tool_calls'], $job['messages'] );
			$this->finalize_durable_plan_phase( $job );
			set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );

			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}

		if ( $is_durable_plan_request && is_array( $result ) ) {
			$result = $this->attach_durable_plan_to_result( $job, $session_id, $result );
		}

		if ( is_wp_error( $result ) ) {
			$error_data   = $result->get_error_data();
			$error_data   = is_array( $error_data ) ? $error_data : array();
			$failure_data = ActiveJobFailureDiagnostic::context_from_error_data( $error_data );

			$failure_data['last_safe_phase'] = 'agent_loop';
			if ( '' === $failure_data['provider_id'] ) {
				$failure_data['provider_id'] = (string) ( $options['provider_id'] ?? $params['provider_id'] ?? '' );
			}
			if ( '' === $failure_data['model_id'] ) {
				$failure_data['model_id'] = (string) ( $options['model_id'] ?? $params['model_id'] ?? '' );
			}
			$diagnostic = $this->persist_active_job_failure(
				$job_id,
				$job,
				ActiveJobFailureDiagnostic::reason_from_error( $result, $failure_data['provider_id'] ),
				$failure_data
			);
			$this->record_public_chat_review_status( $job, 'failed', (string) $diagnostic['reason'] );
			$job['tool_calls'] = $error_data['tool_calls'] ?? ( $job['tool_calls'] ?? array() );
			$job['messages']   = $error_data['messages'] ?? ( $job['messages'] ?? array() );
			$payload_recovery  = $this->normalize_payload_recovery( $error_data['recovery'] ?? null, $session_id );
			if ( null !== $payload_recovery ) {
				$job['payload_recovery'] = $payload_recovery;
			}
			if ( $session_id && ! $is_durable_plan_phase ) {
				$this->persist_error_recovery_to_session(
					$session_id,
					$result,
					$error_data,
					$params,
					$options,
					$job
				);
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
					ActiveJobFailureDiagnostic::message_for( (string) $diagnostic['reason'] )
				);
			}

			unset( $job['tool_calls'], $job['messages'] );
		} elseif ( is_array( $result ) && ! empty( $result['awaiting_confirmation'] ) ) {
			/** @var array<string, mixed> $result */
			$job['status']             = 'awaiting_confirmation';
			$job['pending_tools']      = $result['pending_tools'] ?? array();
			$job['messages']           = $result['message_log'] ?? array();
			$job['confirmation_state'] = array(
				'history'                     => $result['history'] ?? array(),
				'tool_call_log'               => $result['tool_call_log'] ?? array(),
				'message_log'                 => $result['message_log'] ?? array(),
				'token_usage'                 => $result['token_usage'] ?? array(
					'prompt'     => 0,
					'completion' => 0,
				),
				'approved_once_abilities'     => $result['approved_once_abilities'] ?? array(),
				'confirmation_message'        => $result['confirmation_message'] ?? array(),
				'confirmation_history_before' => $result['confirmation_history_before'] ?? null,
				'mutation_policy_context'     => $result['mutation_policy_context'] ?? array(),
				'iterations_remaining'        => $result['iterations_remaining'] ?? 5,
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
				$public_history               = isset( $result['history'] ) && is_array( $result['history'] ) ? $result['history'] : array();
				$public_session               = get_transient( $this->public_chat_session_key( (string) $job['public_session_uuid'] ) );
				$public_session               = is_array( $public_session ) ? $public_session : array();
				$public_session['history']    = $public_history;
				$public_session['updated_at'] = time();
				set_transient(
					$this->public_chat_session_key( (string) $job['public_session_uuid'] ),
					$public_session,
					PublicChatSecurity::SESSION_TTL
				);
				$token_usage = isset( $result['token_usage'] ) && is_array( $result['token_usage'] ) ? $result['token_usage'] : array();
				$handoff     = isset( $result['handoff'] ) && is_array( $result['handoff'] ) ? $result['handoff'] : array();
				$this->record_public_chat_review_turn(
					$job,
					'assistant',
					(string) ( $result['reply'] ?? '' ),
					'complete',
					array(
						'provider_id'       => (string) ( $options['provider_id'] ?? $params['provider_id'] ?? '' ),
						'model_id'          => (string) ( $options['model_id'] ?? $params['model_id'] ?? '' ),
						'iterations_used'   => (int) ( $result['iterations_used'] ?? 0 ),
						'prompt_tokens'     => (int) ( $token_usage['prompt'] ?? 0 ),
						'completion_tokens' => (int) ( $token_usage['completion'] ?? 0 ),
						'handoff_intent'    => (string) ( $handoff['intent'] ?? '' ),
					)
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
				$appended = ( $is_durable_plan_phase || $is_durable_plan_request ) ? $full_history : array_slice( $full_history, $existing_count );
				// Persist the resolved provider/model with each new display message.
				// Session-level fields only describe the initial selection and cannot
				// accurately label a conversation whose model changes between turns.
				$provider_id = (string) ( $options['provider_id'] ?? $params['provider_id'] ?? '' );
				$model_id    = (string) ( $options['model_id'] ?? $params['model_id'] ?? '' );
				$appended    = self::add_turn_model_metadata( $appended, $provider_id, $model_id );
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

		$this->finalize_durable_plan_phase( $job );

		// Clear the token — no longer needed.
		unset( $job['token'] );
		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );

		// Persist terminal status to DB so result survives transient expiry.
		// The full reply/history are in the session messages column already.
		// A DB-sourced poll response returns status + session_id for the
		// frontend to reload the session when needed.
		// @phpstan-ignore-next-line -- status is set above in all paths (error or complete).
		$db_status = (string) $job['status'];
		if ( 'error' === $db_status && empty( $job['diagnostic'] ) ) {
			$this->persist_active_job_failure(
				$job_id,
				$job,
				ActiveJobFailureDiagnostic::REASON_UNKNOWN,
				array( 'last_safe_phase' => 'agent_loop' )
			);
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
	 * Add immutable model metadata to newly persisted conversation messages.
	 *
	 * Metadata is intentionally stored beside the serialized SDK message rather
	 * than only on the session row: one session may contain exchanges from
	 * multiple providers or models. Existing metadata is never overwritten so
	 * durable-plan history retains its original attribution.
	 *
	 * @param array<mixed> $messages    New serialized conversation messages.
	 * @param string       $provider_id Resolved provider used for this turn.
	 * @param string       $model_id    Resolved model used for this turn.
	 * @return array<mixed> Messages with per-turn model metadata.
	 */
	private static function add_turn_model_metadata( array $messages, string $provider_id, string $model_id ): array {
		foreach ( $messages as $index => $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role = (string) ( $message['role'] ?? '' );
			if ( ! in_array( $role, array( 'user', 'model', 'assistant' ), true ) ) {
				continue;
			}

			if ( '' !== $provider_id && ! isset( $message['provider_id'] ) ) {
				$message['provider_id'] = $provider_id;
			}
			if ( '' !== $model_id && ! isset( $message['model_id'] ) ) {
				$message['model_id'] = $model_id;
			}
			$messages[ $index ] = $message;
		}

		return $messages;
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

		if ( null === $db_row || $this->discard_expired_paused_job( $db_row ) ) {
			return new WP_Error(
				'sd_ai_agent_no_active_job',
				__( 'No active job for this session.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		$response = array(
			'job_id' => $db_row->job_id,
			'status' => 'queued' === $db_row->status ? 'processing' : $db_row->status,
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
					'status'     => 'queued' === $row->status ? 'processing' : $row->status,
				);
			},
			array_filter(
				$rows,
				fn( ActiveJobRow $row ): bool => ! $this->discard_expired_paused_job( $row )
			)
		);

		return new WP_REST_Response( array_values( $data ), 200 );
	}

	/**
	 * Handle GET /sessions/{id}/plan and return only the current user's plan.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_durable_plan_status( WP_REST_Request $request ) {
		$session_id = self::get_int_param( $request, 'id' );
		$plan       = DurablePlanRepository::get_latest_for_session( $session_id, get_current_user_id() );
		if ( null === $plan ) {
			return new WP_Error( 'sd_ai_agent_plan_not_found', __( 'No durable plan was found for this session.', 'superdav-ai-agent' ), [ 'status' => 404 ] );
		}

		return new WP_REST_Response( [ 'plan' => DurablePlanRepository::to_public( $plan ) ], 200 );
	}

	/**
	 * Handle POST /sessions/{id}/plan.
	 *
	 * Only a session owner may create a durable plan. Shared-session viewers must
	 * not be able to create a plan that later runs under the owner-owned session.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_create_durable_plan( WP_REST_Request $request ) {
		$session_id = self::get_int_param( $request, 'id' );
		$session    = $this->database->get_session( $session_id );
		$user_id    = get_current_user_id();
		if ( ! $session ) {
			return new WP_Error( 'sd_ai_agent_session_not_found', __( 'Session not found.', 'superdav-ai-agent' ), [ 'status' => 404 ] );
		}
		if ( (int) $session->user_id !== $user_id ) {
			return new WP_Error( 'sd_ai_agent_plan_forbidden', __( 'Only the session owner can create a durable plan.', 'superdav-ai-agent' ), [ 'status' => 403 ] );
		}

		$plan = DurablePlanRunner::create_from_client(
			$session_id,
			$user_id,
			[
				'scope'   => (string) $request->get_param( 'scope' ),
				'summary' => (string) $request->get_param( 'summary' ),
				'steps'   => $request->get_param( 'steps' ),
			]
		);
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		return new WP_REST_Response( [ 'plan' => DurablePlanRepository::to_public( $plan ) ], 201 );
	}

	/**
	 * Handle POST /sessions/{id}/plan/continue.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_continue_durable_plan( WP_REST_Request $request ) {
		$plan_id = self::get_string_param( $request, 'plan_id' );
		$valid   = $this->validate_durable_plan_request( $plan_id, self::get_int_param( $request, 'id' ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$outcome = DurablePlanRunner::prepare_next( $plan_id, get_current_user_id() );
		return $this->respond_to_durable_plan_outcome( $outcome );
	}

	/**
	 * Handle POST /sessions/{id}/plan/approve.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_approve_durable_plan( WP_REST_Request $request ) {
		$plan_id = self::get_string_param( $request, 'plan_id' );
		$valid   = $this->validate_durable_plan_request( $plan_id, self::get_int_param( $request, 'id' ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$outcome = DurablePlanRunner::approve( $plan_id, self::get_int_param( $request, 'approval_request_id' ), get_current_user_id() );
		return $this->respond_to_durable_plan_outcome( $outcome );
	}

	/**
	 * Handle POST /sessions/{id}/plan/reject.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_reject_durable_plan( WP_REST_Request $request ) {
		$plan_id = self::get_string_param( $request, 'plan_id' );
		$valid   = $this->validate_durable_plan_request( $plan_id, self::get_int_param( $request, 'id' ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$outcome = DurablePlanRunner::reject( $plan_id, self::get_int_param( $request, 'approval_request_id' ), get_current_user_id() );
		return $this->respond_to_durable_plan_outcome( $outcome );
	}

	/**
	 * Handle POST /sessions/{id}/plan/retry.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_retry_durable_plan( WP_REST_Request $request ) {
		$plan_id = self::get_string_param( $request, 'plan_id' );
		$valid   = $this->validate_durable_plan_request( $plan_id, self::get_int_param( $request, 'id' ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$outcome = DurablePlanRunner::retry( $plan_id, get_current_user_id() );
		return $this->respond_to_durable_plan_outcome( $outcome );
	}

	/**
	 * Handle POST /sessions/{id}/plan/cancel.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_cancel_durable_plan( WP_REST_Request $request ) {
		$plan_id = self::get_string_param( $request, 'plan_id' );
		$valid   = $this->validate_durable_plan_request( $plan_id, self::get_int_param( $request, 'id' ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$outcome = DurablePlanRunner::cancel( $plan_id, get_current_user_id() );
		return $this->respond_to_durable_plan_outcome( $outcome );
	}

	/**
	 * Handle POST /sessions/{id}/plan/scope.
	 *
	 * A browser can request a scope change but cannot apply it. The persisted
	 * approval record binds the change to this exact plan and owner.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_durable_plan_scope_change( WP_REST_Request $request ) {
		$plan_id = self::get_string_param( $request, 'plan_id' );
		$valid   = $this->validate_durable_plan_request( $plan_id, self::get_int_param( $request, 'id' ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$outcome = DurablePlanRunner::request_scope_change( $plan_id, get_current_user_id(), self::get_string_param( $request, 'scope' ) );
		return $this->respond_to_durable_plan_outcome( $outcome );
	}

	/**
	 * Check both user ownership and route-session ownership against durable state.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate_durable_plan_request( string $plan_id, int $session_id ) {
		$plan = DurablePlanRepository::get_by_plan_id( $plan_id );
		if ( null === $plan ) {
			return new WP_Error( 'sd_ai_agent_plan_not_found', __( 'Durable plan not found.', 'superdav-ai-agent' ), [ 'status' => 404 ] );
		}
		if ( (int) $plan['session_id'] !== $session_id || (int) $plan['user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'sd_ai_agent_plan_forbidden', __( 'You are not authorized to change this durable plan.', 'superdav-ai-agent' ), [ 'status' => 403 ] );
		}

		return $plan;
	}

	/**
	 * Persist a planning-only model result using the fail-closed client policy.
	 *
	 * The model response is intentionally treated like untrusted browser metadata:
	 * classifications remain descriptive, every phase requires approval, and no
	 * phase can automatically resume after an interruption.
	 *
	 * @param array<string, mixed> $job    Background job state.
	 * @param int                  $session_id Session that owns the request.
	 * @param array<string, mixed> $result Agent-loop result.
	 * @return array<string, mixed>|WP_Error
	 */
	private function attach_durable_plan_to_result( array &$job, int $session_id, array $result ) {
		$definition = $result['durable_plan_definition'] ?? null;
		$user_id    = (int) ( $job['user_id'] ?? 0 );
		$session    = $this->database->get_session( $session_id );
		if ( ! is_array( $definition ) || $session_id <= 0 || $user_id <= 0 || ! $session || (int) $session->user_id !== $user_id || get_current_user_id() !== $user_id ) {
			return new WP_Error( 'sd_ai_agent_plan_forbidden', __( 'The durable plan could not be saved for this session.', 'superdav-ai-agent' ) );
		}

		$plan = DurablePlanRunner::create_from_client( $session_id, $user_id, $definition );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		unset( $result['durable_plan_definition'] );
		$public_plan         = DurablePlanRepository::to_public( $plan );
		$job['durable_plan'] = array(
			'plan_id' => (string) $public_plan['plan_id'],
			'plan'    => $public_plan,
		);

		return $result;
	}

	/**
	 * Queue a bounded provider turn only when the runner has claimed a phase.
	 *
	 * @param array<string, mixed>|WP_Error $outcome Runner result.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond_to_durable_plan_outcome( array|WP_Error $outcome ) {
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}
		if ( 'ready' !== ( $outcome['status'] ?? '' ) ) {
			return new WP_REST_Response( $outcome, 200 );
		}

		$plan    = is_array( $outcome['plan'] ?? null ) ? $outcome['plan'] : [];
		$step    = is_array( $outcome['step'] ?? null ) ? $outcome['step'] : [];
		$context = (string) ( $outcome['provider_context'] ?? '' );
		$queued  = $this->enqueue_durable_plan_phase( $plan, $step, $context );
		if ( is_wp_error( $queued ) ) {
			return $queued;
		}

		return new WP_REST_Response( $queued, 202 );
	}

	/**
	 * Create a plan-phase job without loading the full session transcript.
	 *
	 * @param array<string, mixed> $plan             Browser-safe plan payload.
	 * @param array<string, mixed> $step             Internal claimed phase record.
	 * @param string               $provider_context Bounded active-phase context.
	 * @return array<string, mixed>|WP_Error
	 */
	private function enqueue_durable_plan_phase( array $plan, array $step, string $provider_context ) {
		$plan_id    = (string) ( $plan['plan_id'] ?? '' );
		$session_id = (int) ( $plan['session_id'] ?? 0 );
		$step_id    = (int) ( $step['id'] ?? 0 );
		$session    = $this->database->get_session( $session_id );
		if ( '' === $plan_id || $step_id <= 0 || '' === $provider_context || ! $session ) {
			return new WP_Error( 'sd_ai_agent_plan_enqueue_invalid', __( 'The durable plan phase could not be prepared for execution.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}

		$job_id = wp_generate_uuid4();
		$token  = wp_generate_password( 40, false );
		$job    = array(
			'status'       => 'processing',
			'token'        => $token,
			'user_id'      => get_current_user_id(),
			'tool_calls'   => array(),
			'messages'     => array(),
			'durable_plan' => array(
				'plan_id' => $plan_id,
				'step_id' => $step_id,
				'plan'    => $plan,
			),
			'params'       => array(
				'message'            => $provider_context,
				'history'            => array(),
				'abilities'          => array(),
				'system_instruction' => '',
				'bootstrap_prompt'   => '',
				'max_iterations'     => null,
				'session_id'         => $session_id,
				'provider_id'        => (string) $session->provider_id,
				'model_id'           => (string) $session->model_id,
				'page_context'       => array(),
				'agent_id'           => 0,
				'attachments'        => array(),
				'client_abilities'   => array(),
				'durable_plan_phase' => array(
					'plan_id' => $plan_id,
					'step_id' => $step_id,
				),
			),
		);

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );
		if ( false === ActiveJobRepository::create( $session_id, $job_id, get_current_user_id(), 'queued' ) || ! DurablePlanRunner::assign_job( $plan_id, $step_id, $job_id ) ) {
			delete_transient( RestController::JOB_PREFIX . $job_id );
			ActiveJobRepository::delete( $job_id );
			DurablePlanRunner::fail_phase( $plan_id, $step_id, __( 'The phase job could not be queued.', 'superdav-ai-agent' ) );
			return new WP_Error( 'sd_ai_agent_plan_enqueue_failed', __( 'The durable plan phase could not be queued.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}

		BackgroundJobDispatcher::dispatch( $job_id, $token );

		return array(
			'status' => 'processing',
			'job_id' => $job_id,
			'plan'   => DurablePlanRunner::public_plan( $plan_id ),
		);
	}

	/**
	 * Persist terminal plan-phase state without copying raw history or tool data.
	 *
	 * @param array<string, mixed> $job Background job state.
	 */
	private function finalize_durable_plan_phase( array &$job ): void {
		$params  = is_array( $job['params'] ?? null ) ? $job['params'] : array();
		$phase   = is_array( $params['durable_plan_phase'] ?? null ) ? $params['durable_plan_phase'] : array();
		$plan_id = (string) ( $phase['plan_id'] ?? '' );
		$step_id = (int) ( $phase['step_id'] ?? 0 );
		if ( '' === $plan_id || $step_id <= 0 ) {
			return;
		}

		$plan = null;
		if ( 'complete' === ( $job['status'] ?? '' ) && is_array( $job['result'] ?? null ) ) {
			$plan = DurablePlanRunner::complete_phase( $plan_id, $step_id, $job['result'] );
		} elseif ( 'error' === ( $job['status'] ?? '' ) ) {
			$plan = DurablePlanRunner::fail_phase( $plan_id, $step_id, (string) ( $job['error'] ?? __( 'The plan phase failed.', 'superdav-ai-agent' ) ) );
		}

		if ( null !== $plan ) {
			$job['durable_plan'] = array(
				'plan_id' => $plan_id,
				'step_id' => $step_id,
				'plan'    => $plan,
			);
		}
	}

	/**
	 * Convert a paused job whose transient has expired into a safe terminal failure.
	 *
	 * The active-jobs table deliberately stores summary data for polling
	 * recovery, but does not contain the serialized loop state required to
	 * resume a confirmation or client-tool pause. Returning such a row after its
	 * transient expires makes the UI show an approval it can never submit.
	 *
	 * @param ActiveJobRow $row Persistent active-job row.
	 * @return bool Whether an expired paused row was converted to a failure.
	 */
	private function discard_expired_paused_job( ActiveJobRow $row ): bool {
		if ( ! in_array( $row->status, array( 'awaiting_confirmation', 'awaiting_client_tools' ), true ) ) {
			return false;
		}

		$job = get_transient( RestController::JOB_PREFIX . $row->job_id );
		if ( is_array( $job ) ) {
			return false;
		}

		// Durable phases retain their actionable state in the plan tables, so the
		// expired active-job row can be removed after that state is updated. Normal
		// jobs retain the existing prompt-free diagnostic for recovery guidance.
		if ( null !== DurablePlanRunner::mark_phase_interrupted_by_job( $row->job_id ) ) {
			ActiveJobRepository::delete( $row->job_id );
			return true;
		}

		ActiveJobRepository::record_failure(
			$row->job_id,
			'error',
			ActiveJobFailureDiagnostic::REASON_APPROVAL_EXPIRED,
			array( 'last_safe_phase' => $row->status ),
			array( 'pending_tools' => '[]' )
		);
		return true;
	}
}
