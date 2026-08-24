<?php

declare(strict_types=1);
/**
 * REST API controller for automations, event-automations, automation-logs,
 * event-triggers, and test-notification.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use SdAiAgent\Automations\AutomationLogs;
use SdAiAgent\Automations\AutomationRunner;
use SdAiAgent\Automations\Automations;
use SdAiAgent\Automations\CalendarReminderRecords;
use SdAiAgent\Automations\EventAutomations;
use SdAiAgent\Automations\EventTriggerHandler;
use SdAiAgent\Automations\EventTriggerRegistry;
use SdAiAgent\Automations\HumanApprovalGate;
use SdAiAgent\Automations\MonitorWakeQueue;
use SdAiAgent\Automations\NotificationDispatcher;
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
 * Manages automations, event-automations, logs, and triggers via REST.
 *
 * Uses #[Handler] + #[Action] because this controller serves multiple
 * basenames (/automations, /event-automations, /automation-logs, etc.).
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class AutomationController {

	use PermissionTrait;

	/**
	 * Register REST routes.
	 */
	#[Action( tag: 'rest_api_init', priority: 10 )]
	public function register_routes(): void {

		// Automations endpoints.
		register_rest_route(
			RestController::NAMESPACE,
			'/automations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_list_automations' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_create_automation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'name'                        => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'prompt'                      => array(
							'required' => true,
							'type'     => 'string',
						),
						'schedule'                    => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'daily',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'mode'                        => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'task',
							'sanitize_callback' => 'sanitize_key',
						),
						'monitor_scratch'             => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'monitor_event_wakes_enabled' => array(
							'required' => false,
							'type'     => 'boolean',
						),
						'monitor_event_sources'       => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
						),
						'enabled'                     => array(
							'required' => false,
							'type'     => 'boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/automations/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'handle_update_automation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'                          => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'mode'                        => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'monitor_scratch'             => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'monitor_event_wakes_enabled' => array(
							'required' => false,
							'type'     => 'boolean',
						),
						'monitor_event_sources'       => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
						),
						'enabled'                     => array(
							'required' => false,
							'type'     => 'boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete_automation' ),
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

		register_rest_route(
			RestController::NAMESPACE,
			'/automations/(?P<id>\d+)/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_run_automation' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'                   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'manual_monitor_draft' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/automations/(?P<id>\d+)/logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_automation_logs' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/automation-templates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_automation_templates' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/monitor-wake-sources',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_monitor_wake_sources' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Event Automations endpoints.
		register_rest_route(
			RestController::NAMESPACE,
			'/event-automations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_list_event_automations' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_create_event_automation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'name'            => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'hook_name'       => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'prompt_template' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/event-automations/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'handle_update_event_automation' ),
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
					'callback'            => array( $this, 'handle_delete_event_automation' ),
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

		register_rest_route(
			RestController::NAMESPACE,
			'/event-triggers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_event_triggers' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/automation-logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_all_logs' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/calendar-reminder-records',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_calendar_reminder_records' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'limit' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/automation-approvals',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_approval_requests' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'status' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'limit'  => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/automation-approvals/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_approval_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/automation-approvals/(?P<id>\d+)/approve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_approve_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'      => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'execute' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => true,
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/automation-approvals/(?P<id>\d+)/reject',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_reject_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/automations/test-notification',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_test_notification' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'type'        => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'webhook_url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * List scheduled automations.
	 */
	public function handle_list_automations(): WP_REST_Response {
		return new WP_REST_Response(
			array_map( array( $this, 'with_monitor_wake_status' ), Automations::list() ),
			200
		);
	}

	/**
	 * Create a scheduled automation.
	 */
	public function handle_create_automation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data       = $request->get_json_params();
		$data       = is_array( $data ) ? $data : array();
		$validation = Automations::validate_definition( $data );
		if ( is_wp_error( $validation ) ) {
			$validation->add_data( array( 'status' => 400 ) );
			return $validation;
		}
		$data['owner_user_id'] = get_current_user_id();
		$id                    = Automations::create( $data );

		if ( false === $id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create automation.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}
		EventTriggerHandler::attach_monitor_wake_hooks();

		return new WP_REST_Response( $this->with_monitor_wake_status( Automations::get( $id ) ?? array() ), 201 );
	}

	/**
	 * Update a scheduled automation.
	 */
	public function handle_update_automation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id       = self::get_int_param( $request, 'id' );
		$data     = $request->get_json_params();
		$data     = is_array( $data ) ? $data : array();
		$existing = Automations::get( $id );
		if ( null === $existing ) {
			return new WP_Error( 'not_found', __( 'Automation not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}
		$validation = Automations::validate_definition( $data, $existing );
		if ( is_wp_error( $validation ) ) {
			$validation->add_data( array( 'status' => 400 ) );
			return $validation;
		}
		$data['owner_user_id'] = get_current_user_id();

		if ( ! Automations::update( $id, $data ) ) {
			return new WP_Error( 'update_failed', __( 'Failed to update automation.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}
		EventTriggerHandler::attach_monitor_wake_hooks();

		return new WP_REST_Response( $this->with_monitor_wake_status( Automations::get( $id ) ?? array() ), 200 );
	}

	/**
	 * Delete a scheduled automation.
	 */
	public function handle_delete_automation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = self::get_int_param( $request, 'id' );

		if ( ! Automations::delete( $id ) ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete automation.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Manually run a scheduled automation.
	 */
	public function handle_run_automation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id         = self::get_int_param( $request, 'id' );
		$automation = Automations::get( $id );
		if ( null === $automation ) {
			return new WP_Error( 'not_found', __( 'Automation not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		$manual_monitor_draft = (bool) $request->get_param( 'manual_monitor_draft' );
		if ( $manual_monitor_draft && ( ! Automations::is_monitor( $automation ) || ! empty( $automation['enabled'] ) ) ) {
			return new WP_Error(
				'sd_ai_agent_automation_manual_draft_requires_disabled_monitor',
				__( 'Check now is available only for a disabled Monitor/Pulse draft.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		$result = $manual_monitor_draft
			? AutomationRunner::run_manual_monitor_draft( $id )
			: AutomationRunner::run( $id );

		if ( null === $result ) {
			return new WP_Error( 'not_found', __( 'Automation not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get logs for a specific automation.
	 */
	public function handle_automation_logs( WP_REST_Request $request ): WP_REST_Response {
		$id   = self::get_int_param( $request, 'id' );
		$logs = AutomationLogs::list_for_automation( $id );

		return new WP_REST_Response( $logs, 200 );
	}

	/**
	 * Get automation templates.
	 */
	public function handle_automation_templates(): WP_REST_Response {
		return new WP_REST_Response( Automations::get_templates(), 200 );
	}

	/** List the fixed, privacy-safe sources available for explicit Monitor wakes. */
	public function handle_list_monitor_wake_sources(): WP_REST_Response {
		return new WP_REST_Response( EventTriggerRegistry::get_monitor_wake_sources(), 200 );
	}

	/**
	 * Add compact, non-sensitive queue status to a Monitor response.
	 *
	 * @param array<string, mixed> $automation Automation response data.
	 * @return array<string, mixed> Automation response data with Monitor queue status.
	 */
	private function with_monitor_wake_status( array $automation ): array {
		if ( ! Automations::is_monitor( $automation ) ) {
			return $automation;
		}

		$automation['monitor_wake_status'] = MonitorWakeQueue::get_status_for_monitor( (int) ( $automation['id'] ?? 0 ) );

		return $automation;
	}

	/**
	 * List event automations.
	 */
	public function handle_list_event_automations(): WP_REST_Response {
		return new WP_REST_Response( EventAutomations::list(), 200 );
	}

	/**
	 * Create an event automation.
	 */
	public function handle_create_event_automation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = $request->get_json_params();
		$id   = EventAutomations::create( $data );

		if ( false === $id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create event automation.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( EventAutomations::get( $id ), 201 );
	}

	/**
	 * Update an event automation.
	 */
	public function handle_update_event_automation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = self::get_int_param( $request, 'id' );
		$data = $request->get_json_params();

		if ( ! EventAutomations::update( $id, $data ) ) {
			return new WP_Error( 'update_failed', __( 'Failed to update event automation.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( EventAutomations::get( $id ), 200 );
	}

	/**
	 * Delete an event automation.
	 */
	public function handle_delete_event_automation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = self::get_int_param( $request, 'id' );

		if ( ! EventAutomations::delete( $id ) ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete event automation.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * List available event triggers from the registry.
	 */
	public function handle_list_event_triggers(): WP_REST_Response {
		return new WP_REST_Response( EventTriggerRegistry::get_all(), 200 );
	}

	/**
	 * List recent automation logs across all automations.
	 */
	public function handle_list_all_logs(): WP_REST_Response {
		return new WP_REST_Response( AutomationLogs::list_recent(), 200 );
	}

	/**
	 * List recent calendar SMS reminder records.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_list_calendar_reminder_records( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( CalendarReminderRecords::list_recent( absint( $request->get_param( 'limit' ) ?: 25 ) ), 200 );
	}

	/**
	 * List approval requests.
	 */
	public function handle_list_approval_requests( WP_REST_Request $request ): WP_REST_Response {
		$status = (string) $request->get_param( 'status' );
		$limit  = absint( $request->get_param( 'limit' ) ?: 50 );

		return new WP_REST_Response( HumanApprovalGate::list( $status, $limit ), 200 );
	}

	/**
	 * Get one approval request.
	 */
	public function handle_get_approval_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$approval = HumanApprovalGate::get( self::get_int_param( $request, 'id' ) );

		if ( null === $approval ) {
			return new WP_Error( 'not_found', __( 'Approval request not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $approval, 200 );
	}

	/**
	 * Approve and optionally execute a request.
	 */
	public function handle_approve_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$execute_param = $request->get_param( 'execute' );
		$execute       = is_bool( $execute_param ) || is_int( $execute_param ) || is_string( $execute_param ) ? rest_sanitize_boolean( $execute_param ) : true;
		$result        = HumanApprovalGate::approve( self::get_int_param( $request, 'id' ), get_current_user_id(), $execute );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Reject a request.
	 */
	public function handle_reject_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = HumanApprovalGate::reject( self::get_int_param( $request, 'id' ), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle POST /automations/test-notification.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_test_notification( WP_REST_Request $request ): WP_REST_Response {
		$type        = $request->get_param( 'type' );
		$webhook_url = $request->get_param( 'webhook_url' );

		// @phpstan-ignore-next-line
		$result = NotificationDispatcher::test( $type, $webhook_url );

		return new WP_REST_Response( $result, $result['success'] ? 200 : 422 );
	}
}
