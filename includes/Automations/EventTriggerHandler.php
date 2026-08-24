<?php

declare(strict_types=1);
/**
 * Event Trigger Handler — hooks into WordPress, resolves placeholders, fires Agent_Loop.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Automations;

use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\PlaceholderResolver;
use SdAiAgent\Core\ProviderCredentialLoader;
use SdAiAgent\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventTriggerHandler {

	/**
	 * Track which hooks we've registered to avoid duplicates.
	 *
	 * @var array<string, bool>
	 */
	private static $registered_hooks = [];

	/**
	 * Track strict Monitor-wake hook registrations separately from legacy event
	 * automations, whose arbitrary configured hooks must remain unchanged.
	 *
	 * @var array<string, bool>
	 */
	private static array $registered_monitor_wake_hooks = [];

	/**
	 * Guard against re-entrant trigger execution.
	 *
	 * @var bool
	 */
	private static $executing = false;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		// Register all hooks for enabled event automations after init.
		add_action( 'init', [ __CLASS__, 'attach_hooks' ], 99 );

		// Register the cron action that executes an event automation run.
		add_action( 'sd_ai_agent_run_event_automation', [ __CLASS__, 'execute_event_run' ] );
	}

	/**
	 * Attach WordPress hooks for all enabled event automations.
	 */
	public static function attach_hooks(): void {
		$events = EventAutomations::list( true );

		foreach ( $events as $event ) {
			$hook_name = (string) ( $event['hook_name'] ?? '' );

			if ( empty( $hook_name ) ) {
				continue;
			}

			// Only register each hook once (multiple events on the same hook
			// are handled in the dispatch method).
			if ( isset( self::$registered_hooks[ $hook_name ] ) ) {
				continue;
			}

			self::$registered_hooks[ $hook_name ] = true;

			// Determine accepted arg count from trigger registry.
			$trigger_def = EventTriggerRegistry::get( $hook_name );
			$arg_count   = $trigger_def ? count( $trigger_def['args'] ?? [] ) : 5;

			add_action(
				$hook_name,
				function () use ( $hook_name ) {
					$args = func_get_args();
					self::dispatch( $hook_name, $args );
				},
				99,
				$arg_count
			);
		}
	}

	/**
	 * Attach only the fixed, explicitly subscribed Monitor wake sources.
	 *
	 * This deliberately does not share the broader legacy event-automation
	 * registration path: a Monitor never accepts an arbitrary stored hook name
	 * or passes raw callback arguments into model context.
	 */
	public static function attach_monitor_wake_hooks(): void {
		foreach ( MonitorWakeQueue::get_enabled_source_hooks() as $hook_name => $arg_count ) {
			if ( isset( self::$registered_monitor_wake_hooks[ $hook_name ] ) ) {
				continue;
			}

			self::$registered_monitor_wake_hooks[ $hook_name ] = true;
			add_action(
				$hook_name,
				function () use ( $hook_name ): void {
					MonitorWakeQueue::capture( $hook_name, func_get_args() );
				},
				99,
				max( 1, $arg_count )
			);
		}
	}

	/**
	 * Dispatch the AI for all event automations matching a hook.
	 *
	 * @param string $hook_name The WordPress hook that fired.
	 * @param array  $hook_args The arguments passed to the hook.
	 * @phpstan-param list<mixed> $hook_args
	 */
	private static function dispatch( string $hook_name, array $hook_args ): void {
		// Prevent re-entrant execution (avoid infinite loops).
		if ( self::$executing ) {
			return;
		}

		$events = EventAutomations::list( true );

		foreach ( $events as $event ) {
			if ( (string) ( $event['hook_name'] ?? '' ) !== $hook_name ) {
				continue;
			}

			// Check conditions.
			if ( ! self::check_conditions( $event, $hook_name, $hook_args ) ) {
				continue;
			}

			// Resolve placeholders in the prompt template.
			$prompt = PlaceholderResolver::resolve(
				(string) ( $event['prompt_template'] ?? '' ),
				$hook_name,
				$hook_args
			);

			// Fire the agent loop asynchronously via wp_schedule_single_event
			// to avoid blocking the main request.
			$run_data = [
				'event_id'  => (int) ( $event['id'] ?? 0 ),
				'prompt'    => $prompt,
				'hook_name' => $hook_name,
			];

			// Use a transient to pass data to the cron callback.
			$run_key = 'sd_ai_agent_event_run_' . wp_generate_uuid4();
			set_transient( $run_key, $run_data, HOUR_IN_SECONDS );
			wp_schedule_single_event( time(), 'sd_ai_agent_run_event_automation', [ $run_key ] );

			// Spawn the cron immediately.
			spawn_cron();
		}
	}

	/**
	 * Execute an event-triggered automation (called via cron).
	 *
	 * @param string $run_key Transient key containing run data.
	 */
	public static function execute_event_run( string $run_key ): void {
		$run_data = get_transient( $run_key );
		delete_transient( $run_key );

		if ( ! is_array( $run_data ) ) {
			return;
		}

		self::$executing = true;

		$event_id  = (int) ( $run_data['event_id'] ?? 0 );
		$prompt    = (string) ( $run_data['prompt'] ?? '' );
		$hook_name = (string) ( $run_data['hook_name'] ?? '' );
		$start     = microtime( true );

		$event = EventAutomations::get( $event_id );
		if ( ! $event ) {
			self::$executing = false;
			return;
		}

		// Ensure credentials are available.
		ProviderCredentialLoader::load();

		$settings = Settings::instance()->get();
		$options  = [
			'max_iterations' => (int) ( $event['max_iterations'] ?? 5 ) ?: 5,
			'provider_id'    => (string) ( $settings['default_provider'] ?? '' ),
			'model_id'       => (string) ( $settings['default_model'] ?? '' ),
		];

		$loop   = new AgentLoop( $prompt, [], [], $options );
		$result = $loop->run();

		$duration = round( ( microtime( true ) - $start ) * 1000 );
		$is_error = is_wp_error( $result );

		// Log the execution.
		AutomationLogs::create(
			[
				'automation_id'     => $event_id,
				'trigger_type'      => 'event',
				'trigger_name'      => $hook_name,
				'status'            => $is_error ? 'error' : 'success',
				'reply'             => $is_error ? $result->get_error_message() : ( $result['reply'] ?? '' ),
				'tool_calls'        => $is_error ? [] : ( $result['tool_calls'] ?? [] ),
				'prompt_tokens'     => $is_error ? 0 : ( $result['token_usage']['prompt'] ?? 0 ),
				'completion_tokens' => $is_error ? 0 : ( $result['token_usage']['completion'] ?? 0 ),
				'duration_ms'       => $duration,
				'error_message'     => $is_error ? $result->get_error_message() : '',
			]
		);

		EventAutomations::record_run( $event_id );

		/**
		 * Fires after an event-driven automation completes.
		 *
		 * @param int    $event_id  The event automation ID.
		 * @param string $hook_name The hook that triggered it.
		 * @param bool   $is_error  Whether the run failed.
		 */
		do_action( 'sd_ai_agent_event_automation_complete', $event_id, $hook_name, $is_error );

		self::$executing = false;
	}

	/**
	 * Check if event conditions are met.
	 *
	 * @param array<string, mixed> $event     The event automation definition.
	 * @param string               $hook_name The hook that fired.
	 * @param array                $hook_args The hook arguments.
	 * @phpstan-param list<mixed> $hook_args
	 * @return bool
	 */
	private static function check_conditions( array $event, string $hook_name, array $hook_args ): bool {
		$conditions = $event['conditions'] ?? [];

		if ( empty( $conditions ) ) {
			return true;
		}

		// Build context for condition evaluation.
		$context     = [];
		$trigger_def = EventTriggerRegistry::get( $hook_name );
		if ( $trigger_def && ! empty( $trigger_def['args'] ) && is_array( $trigger_def['args'] ) ) {
			foreach ( $trigger_def['args'] as $i => $arg_name ) {
				if ( isset( $hook_args[ $i ] ) ) {
					$context[ (string) $arg_name ] = $hook_args[ $i ];
				}
			}
		}

		// Check post_type condition.
		if ( ! empty( $conditions['post_type'] ) ) {
			$post = null;
			if ( isset( $context['post'] ) && $context['post'] instanceof \WP_Post ) {
				$post = $context['post'];
			} elseif ( isset( $hook_args[2] ) && $hook_args[2] instanceof \WP_Post ) {
				$post = $hook_args[2];
			}

			if ( $post && $post->post_type !== $conditions['post_type'] ) {
				return false;
			}
		}

		// Check new_status condition.
		if ( ! empty( $conditions['new_status'] ) && isset( $context['new_status'] ) ) {
			if ( $context['new_status'] !== $conditions['new_status'] ) {
				return false;
			}
		}

		// Check old_status condition.
		if ( ! empty( $conditions['old_status'] ) && isset( $context['old_status'] ) ) {
			if ( $context['old_status'] !== $conditions['old_status'] ) {
				return false;
			}
		}

		// Check role condition.
		if ( ! empty( $conditions['role'] ) ) {
			$user = null;
			if ( isset( $context['user_id'] ) ) {
				$user = get_userdata( (int) $context['user_id'] );
			} elseif ( isset( $hook_args[0] ) && is_numeric( $hook_args[0] ) ) {
				$user = get_userdata( (int) $hook_args[0] );
			}

			if ( $user && ! in_array( $conditions['role'], $user->roles, true ) ) {
				return false;
			}
		}

		// Check approved condition.
		if ( isset( $conditions['approved'] ) && isset( $context['comment_approved'] ) ) {
			if ( (string) $context['comment_approved'] !== (string) $conditions['approved'] ) {
				return false;
			}
		}

		// Check option_name condition.
		if ( ! empty( $conditions['option_name'] ) && isset( $context['option_name'] ) ) {
			if ( $context['option_name'] !== $conditions['option_name'] ) {
				return false;
			}
		}

		return true;
	}
}
