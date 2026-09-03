<?php
/**
 * DI handler for automation scheduling and event-trigger hooks.
 *
 * Replaces the `AutomationRunner::register()` and
 * `EventTriggerHandler::register()` calls in CoreServicesHandler, plus the
 * inline `add_action('sd_ai_agent_run_event_automation', ...)` that
 * CoreServicesHandler wired directly.
 *
 * All hook registrations are now declared via `#[Action]` / `#[Filter]`
 * attributes so the DI container owns the lifecycle.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Automations\AutomationRunner;
use SdAiAgent\Automations\EventTriggerHandler;
use SdAiAgent\Automations\MonitorWakeQueue;
use SdAiAgent\Core\BackgroundJobDispatcher;
use SdAiAgent\Core\Database;
use SdAiAgent\REST\RestController;
use SdAiAgent\REST\SessionController;
use WP_REST_Request;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires automation cron jobs and event-driven trigger hooks into WordPress.
 *
 * CTX_GLOBAL is required because:
 * - `cron_schedules` is filtered during any request type.
 * - The automation cron hook fires in CTX_CRON.
 * - `init` (for attaching event hooks) fires in all contexts.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class AutomationsHandler {

	/** Execute an agent job claimed by the out-of-process queue worker. */
	#[Action( tag: BackgroundJobDispatcher::HOOK, priority: 10 )]
	public function process_background_job( int $blog_id, string $job_id ): void {
		$switched = false;
		if ( $blog_id > 0 && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			$job = get_transient( RestController::JOB_PREFIX . $job_id );
			if ( ! is_array( $job ) || empty( $job['token'] ) ) {
				return;
			}

			$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/process' );
			$request->set_body_params(
				array(
					'job_id' => $job_id,
					'token'  => (string) $job['token'],
				)
			);

			( new SessionController( new Database() ) )->handle_process( $request );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Add the custom weekly cron schedule used by automations.
	 *
	 * @param array<string,mixed> $schedules Existing cron schedules.
	 * @return array<string,mixed> Schedules with the weekly interval added.
	 */
	#[Filter( tag: 'cron_schedules', priority: 10 )]
	public function add_cron_schedules( array $schedules ): array {
		return AutomationRunner::add_cron_schedules( $schedules );
	}

	/**
	 * Execute a scheduled automation cron job.
	 *
	 * WordPress passes the `$automation_id` as the cron event argument.
	 *
	 * @param int $automation_id Automation ID to run.
	 */
	#[Action( tag: AutomationRunner::CRON_HOOK, priority: 10 )]
	public function run_automation( int $automation_id ): void {
		AutomationRunner::run( $automation_id );
	}

	/**
	 * Attach WordPress hooks for all enabled event automations.
	 *
	 * Runs at priority 99 on `init` (after all post types, taxonomies, and
	 * custom hooks are registered) so the event trigger registry is complete.
	 */
	#[Action( tag: 'init', priority: 99 )]
	public function attach_event_hooks(): void {
		EventTriggerHandler::attach_hooks();
	}

	/** Attach strict, explicitly opted-in Monitor event wake hooks. */
	#[Action( tag: 'init', priority: 99 )]
	public function attach_monitor_wake_hooks(): void {
		EventTriggerHandler::attach_monitor_wake_hooks();
	}

	/** Process a bounded batch of durable coalesced Monitor event wakes. */
	#[Action( tag: MonitorWakeQueue::CRON_HOOK, priority: 10 )]
	public function process_monitor_wakes(): void {
		MonitorWakeQueue::process_due_wakes();
	}

	/** Retry retained-evidence cleanup after an in-flight event capture. */
	#[Action( tag: MonitorWakeQueue::CLEANUP_CRON_HOOK, priority: 10 )]
	public function clear_monitor_wakes( int $monitor_id ): void {
		MonitorWakeQueue::retry_clear_for_monitor( $monitor_id );
	}

	/**
	 * Execute an event-driven automation run.
	 *
	 * @param string $run_key Unique key identifying the event automation run.
	 */
	#[Action( tag: 'sd_ai_agent_run_event_automation', priority: 10 )]
	public function execute_event_run( string $run_key ): void {
		EventTriggerHandler::execute_event_run( $run_key );
	}
}
