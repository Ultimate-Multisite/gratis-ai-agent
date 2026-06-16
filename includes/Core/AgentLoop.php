<?php

declare(strict_types=1);
/**
 * Core agentic loop orchestration.
 *
 * Sends a prompt, checks for tool calls, executes them,
 * feeds results back, and repeats until the model is done.
 *
 * Sub-responsibilities are delegated to focused service classes:
 *
 * - {@see SystemInstructionBuilder}   — build_system_instruction()
 * - {@see ProviderCredentialLoader}   — ensure_provider_credentials_static()
 * - {@see ToolPermissionResolver}     — get_tools_needing_confirmation(), classify_ability()
 * - {@see SpinDetector}               — spin detection & build_tool_signature()
 * - {@see ClientAbilityRouter}        — partition_tool_calls(), client ability stubs
 * - {@see ConversationSerializer}     — serialize/deserialize history
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Admin\UnifiedAdminMenu;
use SdAiAgent\Abilities\FeedbackAbilities;
use SdAiAgent\Core\AbilityVisibility;
use SdAiAgent\Core\BudgetManager;
use SdAiAgent\Core\ChangeLogger;
use SdAiAgent\Models\ActiveJobRepository;
use SdAiAgent\Repositories\SkillUsageRepository;
use SdAiAgent\Tools\ModelHealthTracker;
use SdAiAgent\Tools\ToolDiscovery;
use SdAiAgent\Core\RolePermissions;
use WP_AI_Client_Ability_Function_Resolver;
use WP_Error;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

class AgentLoop {

	// ── Production Hardening Constants ────────────────────────────────────

	/**
	 * Wall-clock timeout in seconds. Prevents runaway loops from burning
	 * tokens indefinitely when round/token limits are not hit.
	 * Reset after each successful tool call so productive long tasks are
	 * not killed — only truly stalled loops hit this limit.
	 */
	const LOOP_TIMEOUT_SECONDS = 300;

	/**
	 * Consecutive no-progress rounds before forced exit.
	 * If the model calls the exact same tools with the same args N times
	 * in a row, it's spinning and we bail out.
	 */
	const MAX_IDLE_ROUNDS = 3;

	/**
	 * Maximum token-estimated size (in characters) for a single tool result
	 * fed back into the loop. Results exceeding this are truncated.
	 * ~40K chars ≈ 10K tokens — generous but bounded.
	 */
	const MAX_TOOL_RESULT_CHARS = 40000;

	/** Maximum provider-call attempts for retryable transient failures. */
	private const PROVIDER_RETRY_MAX_ATTEMPTS = 10;

	/** Retryable upstream/network statuses. */
	private const PROVIDER_RETRYABLE_STATUS_CODES = array( 408, 429, 500, 502, 503, 504 );

	/** Default exponential backoff schedule in seconds, capped at 60 seconds. */
	private const PROVIDER_RETRY_DELAYS = array( 1, 2, 4, 8, 16, 32, 60, 60, 60, 60 );

	/**
	 * Maximum consecutive preamble-only truncations before we abort the loop.
	 *
	 * A preamble-only truncation happens when the model emits text up to its
	 * output cap without ever opening a tool call. We inject guidance and
	 * retry once; if it happens again immediately the model is stuck (model
	 * cap is genuinely too small, or the task is too large) and burning more
	 * iterations is wasteful. Abort with a structured WP_Error so the
	 * session ends cleanly and the UI can surface a real failure to the
	 * user instead of an idle session with a half-sentence reply.
	 */
	private const PREAMBLE_TRUNCATION_MAX_RETRIES = 2;

	/** @var string */
	private $user_message;

	/** @var string[] Ability names to enable. */
	private $abilities;

	/** @var Message[] Conversation history. */
	private $history;

	/** @var string */
	private $system_instruction;

	/** @var array<string,mixed> Cached settings for per-turn system-prompt rebuilds. */
	private array $settings_for_prompt = array();

	/** @var bool When true the constructor was given an explicit system_instruction override and we should NOT rebuild it per turn. */
	private bool $system_instruction_locked = false;

	/** @var int */
	private $max_iterations;

	/** @var string AI provider ID. */
	private $provider_id;

	/** @var string AI model ID. */
	private $model_id;

	/** @var list<array<string, mixed>> Logged tool call activity. */
	private array $tool_call_log = array();

	/** @var list<array<string, mixed>> Assistant channel messages separate from real tool calls. */
	private array $message_log = array();

	/** @var int Monotonic sequence for merging tool calls and channel messages in UI order. */
	private int $activity_sequence = 0;

	/** @var array<int, array<string, mixed>> Posts that still require block-validation repair. */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Project property naming guidance requires camelCase.
	private array $pendingBlockValidationRepairs = array();

	/** @var float */
	private $temperature;

	/** @var int */
	private $max_output_tokens;

	/** @var int Number of loop iterations used. */
	private $iterations_used = 0;

	/** @var array<string, int> Token usage accumulator. */
	private $token_usage = array(
		'prompt'     => 0,
		'completion' => 0,
	);

	/** @var array<int|string, mixed> Tool permission levels from settings. */
	private $tool_permissions = array();

	/** @var bool When true, skip all tool confirmations (YOLO mode). */
	private $yolo_mode = false;

	/** @var array<int|string, mixed> Page context from the widget. */
	private $page_context = array();

	/** @var WP_AI_Client_Ability_Function_Resolver|null */
	private $ability_resolver = null;

	/** @var Settings Injected settings dependency. */
	private $settings_service;

	/** @var int Session ID for change attribution (0 = no session). */
	private int $session_id = 0;

	/**
	 * Active job UUID for heartbeat and shutdown-handler updates.
	 *
	 * Empty string when the loop is not running under a background job
	 * (e.g. automations, CLI, or direct invocations). When set, each
	 * loop iteration calls ActiveJobRepository::heartbeat() so the
	 * hourly stale-job reaper can distinguish an actively-running job
	 * from a zombie, and register_shutdown_function() marks the row as
	 * 'interrupted' if the PHP process terminates before the loop completes.
	 *
	 * @var string
	 */
	private string $active_job_id = '';

	/** @var float Unix timestamp when this active-job run started. */
	private float $active_job_started_at = 0.0;

	/** @var string Last coarse loop phase for shutdown diagnostics. */
	private string $last_loop_phase = 'initializing';

	/** @var int Maximum attempts for retryable provider failures. */
	private int $provider_retry_max_attempts = self::PROVIDER_RETRY_MAX_ATTEMPTS;

	/** @var list<int> Retry delay schedule in seconds. */
	private array $provider_retry_delays = self::PROVIDER_RETRY_DELAYS;

	/** @var list<string> Per-agent Tier 1 tool override (empty = use global default). */
	private array $agent_tier_1_tools = array();

	/** @var int Consecutive preamble-only truncations observed this run. */
	private int $preamble_truncation_retries = 0;

	/**
	 * Client-side ability descriptors validated against JsAbilityCatalog.
	 * These are abilities the browser can execute; the loop pauses and returns
	 * them as pending_client_tool_calls when the model invokes one.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $client_abilities = array();

	/**
	 * Optional callback invoked after each tool call/response pair.
	 *
	 * Signature: function( list<array<string, mixed>> $tool_call_log, list<array<string, mixed>> $message_log ): void
	 * Used by the job system to write live progress to the transient so the
	 * polling frontend can show tool activity and channel messages before the loop completes.
	 *
	 * @var callable|null
	 */
	private $progress_callback = null;

	/**
	 * Optional callback that checks for interrupt messages from the user.
	 *
	 * Signature: function(): ?array{ message: string, timestamp: int }
	 * Returns the next unprocessed interrupt, or null if none pending.
	 * Used by the job system to read interrupts from the job transient
	 * so the agent loop can incorporate new user context mid-execution.
	 *
	 * @var callable|null
	 */
	private $interrupt_checker = null;

	// ── Focused service objects ───────────────────────────────────────────

	/** @var SystemInstructionBuilder Builds the per-turn system instruction. */
	private SystemInstructionBuilder $instruction_builder;

	/** @var ToolPermissionResolver Checks tool confirmation requirements. */
	private ToolPermissionResolver $permission_resolver;

	/** @var SpinDetector Tracks consecutive identical tool-call rounds. */
	private SpinDetector $spin_detector;

	/** @var ClientAbilityRouter Partitions tool calls to PHP or JS handlers. */
	private ClientAbilityRouter $client_router;

	/**
	 * @param string               $user_message     The user's prompt.
	 * @param string[]             $abilities         Ability names to enable (empty = all).
	 * @param Message[]            $history           Prior messages for multi-turn.
	 * @param array<string, mixed> $options           Optional overrides: system_instruction, max_iterations, provider_id, model_id, temperature, max_output_tokens, page_context.
	 * @param Settings|null        $settings_service  Injected Settings service (uses Settings::instance() when null).
	 */
	public function __construct( string $user_message, array $abilities = array(), array $history = array(), array $options = array(), ?Settings $settings_service = null ) {
		$this->user_message     = $user_message;
		$this->abilities        = $abilities;
		$this->history          = $history;
		$raw_page_ctx           = $options['page_context'] ?? null;
		$this->page_context     = is_array( $raw_page_ctx ) ? $raw_page_ctx : array();
		$this->settings_service = $settings_service ?? new Settings();

		// Merge explicit options with saved settings as fallbacks.
		$raw_settings = $this->settings_service->get();
		$settings     = is_array( $raw_settings ) ? $raw_settings : array();

		// Default provider/model resolution flows through the Settings
		// service so an out-of-date saved value (e.g. a model whose provider
		// has been uninstalled) is validated against the live WP AI Client
		// SDK registry and substituted before it reaches the SDK. Without
		// this, every new chat against a broken default hits a top-level
		// "model not available" error banner — see GH#1494 (the production
		// `gemma4:e4b` demo regression). Explicit per-call overrides via
		// $options bypass validation so callers can still pin arbitrary
		// values (benchmarks, scheduled jobs, etc.).
		// @phpstan-ignore-next-line
		$this->provider_id = $options['provider_id'] ?? $this->settings_service->get_default_provider();
		// @phpstan-ignore-next-line
		$this->model_id = $options['model_id'] ?? $this->settings_service->get_default_model();
		// @phpstan-ignore-next-line
		$this->max_iterations = $options['max_iterations'] ?? ( $settings['max_iterations'] ?: 25 );

		// NOTE: The weak-model iteration cap is currently DISABLED.
		//
		// The previous implementation hard-capped max_iterations at 10 when
		// ModelHealthTracker::is_weak() returned true. That tracker uses a
		// telemetry score (success / (success + validation_error + 5*nudge))
		// with a 0.7 threshold, which turned out to be unreliable in
		// practice:
		//
		// 1. Framework bugs (empty-parts crashes, JS-tool-cycle stripping)
		// counted as validation_errors and nudges, dragging legitimate
		// models (Opus 4.7, Sonnet 4.6) below 0.7 — score 0.59-0.68 —
		// even though the model itself was healthy.
		// 2. Once a model dropped below 0.7 it got capped at 10
		// iterations, which made user-visible task failures more
		// likely, which fed more nudges into the telemetry, which
		// kept the model flagged — a self-reinforcing trap.
		// 3. The hard 10 silently overrode the user's max_iterations
		// setting (e.g. 100 for landing-page builds), making the
		// setting feel broken with no surfaced explanation.
		//
		// Until ModelHealthTracker telemetry can distinguish "model burned
		// rounds on dead ends" from "framework bug crashed the loop", and
		// until weak-cap behaviour is surfaced to the user, the cap is
		// disabled in favour of the user's configured budget. The original
		// code is preserved here in comments so it can be restored once
		// the telemetry pipeline is reliable:
		//
		// if ( ModelHealthTracker::is_weak( $model_id ) ) {
		// $this->max_iterations = min( (int) $this->max_iterations, 10 );
		// }
		// @phpstan-ignore-next-line
		$this->temperature = $options['temperature'] ?? ( $settings['temperature'] ?? 0.7 );
		// max_output_tokens semantics:
		// - 0 (Settings::MAX_OUTPUT_TOKENS_AUTO) means "resolve per model at
		// request time" — see send_prompt(). This is the default for new
		// installs; existing installs may have a saved 4096 from the
		// pre-7rl default, which we honour as an explicit override.
		// - a positive value is treated as an explicit user override and
		// passed to the provider (clamped to MAX_OUTPUT_TOKENS_CEILING).
		// @phpstan-ignore-next-line
		$this->max_output_tokens = (int) ( $options['max_output_tokens'] ?? ( $settings['max_output_tokens'] ?? Settings::MAX_OUTPUT_TOKENS_AUTO ) );

		// If an agent_system_prompt is provided, inject it into settings so
		// build_system_instruction() uses it as the base instead of the global prompt.
		if ( ! empty( $options['agent_system_prompt'] ) ) {
			// @phpstan-ignore-next-line
			$settings['system_prompt'] = $options['agent_system_prompt'];
		}

		// Store settings so send_prompt() can rebuild the system instruction
		// before each model call — this lets the recently_fetched_section
		// (and any other dynamic blocks) reach the model on subsequent turns.
		// @phpstan-ignore-next-line
		$this->settings_for_prompt = $settings;

		// Tool permissions, YOLO mode, and resumable state.
		// Options override settings for tool_permissions and yolo_mode so
		// callers (e.g. CLI, automations) can inject per-run overrides.
		$raw_perms              = $options['tool_permissions'] ?? ( $settings['tool_permissions'] ?? null );
		$this->tool_permissions = is_array( $raw_perms ) ? $raw_perms : array();
		// @phpstan-ignore-next-line
		$this->yolo_mode         = (bool) ( $options['yolo_mode'] ?? ( $settings['yolo_mode'] ?? false ) );
		$this->tool_call_log     = self::normalize_activity_log( $options['tool_call_log'] ?? array() );
		$this->message_log       = self::normalize_activity_log( $options['message_log'] ?? array() );
		$this->activity_sequence = $this->get_max_activity_sequence( $this->tool_call_log, $this->message_log );
		// @phpstan-ignore-next-line
		$this->session_id = (int) ( $options['session_id'] ?? 0 );
		// Active job UUID for heartbeat and shutdown-handler updates.
		// Empty string when the loop is not running under a background job.
		// @phpstan-ignore-next-line
		$this->active_job_id = (string) ( $options['active_job_id'] ?? '' );
		// @phpstan-ignore-next-line -- Test/job callers may lower attempts or delays; production defaults remain 10 attempts.
		$this->provider_retry_max_attempts = max( 1, (int) ( $options['provider_retry_max_attempts'] ?? self::PROVIDER_RETRY_MAX_ATTEMPTS ) );
		// @phpstan-ignore-next-line -- Values are normalised below to non-negative integer seconds.
		$retry_delays = $options['provider_retry_delays'] ?? self::PROVIDER_RETRY_DELAYS;
		if ( is_array( $retry_delays ) ) {
			$this->provider_retry_delays = array_map(
				static fn( $delay ): int => max( 0, min( 60, (int) $delay ) ),
				array_values( $retry_delays )
			);
		}
		// @phpstan-ignore-next-line
		$this->token_usage = $options['token_usage'] ?? array(
			'prompt'     => 0,
			'completion' => 0,
		);

		// Per-agent Tier 1 tool override (passed from Agent::get_loop_options).
		$raw_tier_1_tools = $options['tier_1_tools'] ?? array();
		// @phpstan-ignore-next-line -- Options bag contains mixed values; runtime array_values is safe.
		$this->agent_tier_1_tools = is_array( $raw_tier_1_tools ) ? array_values( $raw_tier_1_tools ) : array();

		// Progress callback for live tool-call reporting (used by job system).
		if ( isset( $options['progress_callback'] ) && is_callable( $options['progress_callback'] ) ) {
			$this->progress_callback = $options['progress_callback'];
		}

		// Interrupt checker for mid-loop user message injection (used by job system).
		if ( isset( $options['interrupt_checker'] ) && is_callable( $options['interrupt_checker'] ) ) {
			$this->interrupt_checker = $options['interrupt_checker'];
		}

		// ── Initialise focused service objects ───────────────────────────

		// SystemInstructionBuilder needs the model_id for weak-model nudges
		// and user_message for knowledge RAG, both resolved above.
		// session_id is passed so skill injection events are recorded to the
		// skill_usage telemetry table (Phase 1 / t215).
		$this->instruction_builder = new SystemInstructionBuilder(
			(string) $this->model_id,
			$this->user_message,
			$this->page_context,
			$this->session_id
		);

		// ToolPermissionResolver encapsulates yolo_mode and tool_permissions.
		$this->permission_resolver = new ToolPermissionResolver(
			$this->yolo_mode,
			$this->tool_permissions
		);

		// SpinDetector tracks consecutive identical tool-call rounds.
		$this->spin_detector = new SpinDetector();

		// ClientAbilityRouter validates and routes client-side ability calls.
		// @phpstan-ignore-next-line
		$raw_client_abilities = $options['client_abilities'] ?? array();
		if ( is_array( $raw_client_abilities ) ) {
			$this->client_router    = ClientAbilityRouter::from_raw( $raw_client_abilities );
			$this->client_abilities = $this->client_router->get_descriptors();
		} else {
			$this->client_router    = new ClientAbilityRouter();
			$this->client_abilities = array();
		}

		// Build or lock the initial system instruction.
		if ( isset( $options['system_instruction'] ) ) {
			// @phpstan-ignore-next-line
			$this->system_instruction        = $options['system_instruction'];
			$this->system_instruction_locked = true;
		} else {
			$this->system_instruction = $this->instruction_builder->build( $settings );
		}
	}

	/**
	 * Run the agentic loop.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function run() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'sd_ai_agent_missing_client',
				__( 'The AI Client SDK is not available. WordPress 7.0+ is required.', 'superdav-ai-agent' )
			);
		}

		// Check spending budget before making any API call.
		$budget_check = BudgetManager::check_budget();
		if ( is_wp_error( $budget_check ) ) {
			return $budget_check;
		}

		// Clear per-call failure history so spin detection is per-run, and
		// attribute subsequent telemetry to the configured model.
		IdenticalFailureTracker::reset();
		ModelHealthTracker::set_current_model( $this->model_id );

		// Make session_id available to event-log emitters in sub-layers
		// (AbilityFunctionResolver, ProviderTraceLogger) that don't carry
		// a session reference through their call chain. Always cleared on exit
		// via try/finally so a thrown exception cannot leak attribution into
		// a subsequent unrelated run.
		AgentEventLog::set_session( $this->session_id );

		// Ensure provider auth is available (critical for loopback requests).
		ProviderCredentialLoader::load();

		// Register a shutdown handler to mark the active-jobs row as
		// 'interrupted' if the PHP request terminates before the loop
		// completes (PHP fatal, FastCGI/nginx timeout, SIGKILL, OOM).
		// Only registered when an active_job_id is set (background jobs).
		// The handler is a no-op when the row is no longer 'processing'
		// (i.e. the loop finished normally and updated the status first).
		if ( '' !== $this->active_job_id ) {
			$this->active_job_started_at = microtime( true );
			register_shutdown_function( array( $this, 'handle_active_job_shutdown' ) );
		}

		// Append the new user message to history.
		$this->history[] = new UserMessage( array( new MessagePart( $this->user_message ) ) );

		try {
			$result = $this->run_loop( $this->max_iterations );

			// Apply Phase-1 outcome heuristic to skill usage rows for this session.
			$this->evaluate_skill_outcomes( $result );

			return $result;
		} finally {
			$this->last_loop_phase = 'agent_loop_exiting';
			AgentEventLog::clear_session();
		}
	}

	/**
	 * Mark a background job interrupted and include actionable shutdown context.
	 *
	 * The database update is guarded by status='processing', so this method is a
	 * no-op after normal completion/error persistence. When PHP terminates during
	 * a provider call or ability execution, the row now records the last loop
	 * phase and any fatal shutdown error instead of a generic interruption note.
	 *
	 * @return void
	 */
	public function handle_active_job_shutdown(): void {
		if ( '' === $this->active_job_id ) {
			return;
		}

		$connection_status = connection_status();
		if ( CONNECTION_ABORTED === $connection_status ) {
			$reason = 'shutdown handler — client disconnected before loop completion';
		} elseif ( CONNECTION_TIMEOUT === $connection_status ) {
			$reason = 'shutdown handler — PHP request timed out before loop completion';
		} else {
			$reason = 'shutdown handler — request terminated without loop completion';
		}

		$elapsed               = $this->active_job_started_at > 0
			? max( 0, (int) round( microtime( true ) - $this->active_job_started_at ) )
			: 0;
		$interrupted_phase     = $this->last_loop_phase;
		$this->last_loop_phase = 'shutdown';

		$details = array(
			'phase=' . $interrupted_phase,
			'iteration=' . $this->iterations_used . '/' . (int) $this->max_iterations,
			'elapsed_s=' . $elapsed,
			'connection_status=' . $this->format_connection_status( $connection_status ),
			'memory_peak=' . (int) memory_get_peak_usage( true ),
		);

		$last_error = error_get_last();
		if ( is_array( $last_error ) && $this->is_fatal_shutdown_error( $last_error ) ) {
			$message   = (string) $last_error['message'];
			$file      = (string) $last_error['file'];
			$line      = (int) $last_error['line'];
			$type      = (int) $last_error['type'];
			$details[] = 'fatal_type=' . $type;
			$details[] = 'fatal=redacted';
			$details[] = 'fatal_code=php_shutdown_' . $type;

			AgentEventLog::log(
				'active_job_shutdown_fatal',
				AgentEventLog::SEVERITY_ERROR,
				array(
					'session_id' => $this->session_id,
					'code'       => 'php_shutdown_' . $type,
					'reason'     => 'fatal_shutdown',
					'message'    => $this->shorten_shutdown_detail( $message . ( '' !== $file ? ' in ' . $file . ':' . $line : '' ) ),
				)
			);
		} else {
			$details[] = 'fatal=none';
		}

		ActiveJobRepository::mark_interrupted( $this->active_job_id, $reason . '; ' . implode( '; ', $details ) );
	}

	/**
	 * Format a PHP connection status bitmask for shutdown diagnostics.
	 *
	 * @param int $status PHP connection_status() bitmask.
	 * @return string Human-readable status label.
	 */
	private function format_connection_status( int $status ): string {
		if ( CONNECTION_NORMAL === $status ) {
			return 'normal';
		}

		$labels = array();
		if ( ( $status & CONNECTION_ABORTED ) === CONNECTION_ABORTED ) {
			$labels[] = 'aborted';
		}
		if ( ( $status & CONNECTION_TIMEOUT ) === CONNECTION_TIMEOUT ) {
			$labels[] = 'timeout';
		}

		return empty( $labels ) ? (string) $status : implode( '+', $labels );
	}

	/**
	 * Whether error_get_last() represents a fatal shutdown condition.
	 *
	 * @param array<string, mixed> $error Last PHP error array.
	 * @return bool True when the error type terminates execution.
	 */
	private function is_fatal_shutdown_error( array $error ): bool {
		$type = (int) ( $error['type'] ?? 0 );

		return in_array(
			$type,
			array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ),
			true
		);
	}

	/**
	 * Keep shutdown details compact enough for active_jobs.error.
	 *
	 * @param string $detail Raw shutdown detail.
	 * @return string Trimmed detail.
	 */
	private function shorten_shutdown_detail( string $detail ): string {
		$detail = trim( preg_replace( '/\s+/', ' ', $detail ) ?? $detail );

		if ( strlen( $detail ) <= 220 ) {
			return $detail;
		}

		return substr( $detail, 0, 217 ) . '...';
	}

	/**
	 * Resume after a tool confirmation or rejection.
	 *
	 * @param bool $confirmed Whether the user approved the tool call.
	 * @param int  $remaining_iterations Remaining loop iterations.
	 * @return array<string, mixed>|WP_Error
	 */
	public function resume_after_confirmation( bool $confirmed, int $remaining_iterations ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'sd_ai_agent_missing_client',
				__( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' )
			);
		}

		ProviderCredentialLoader::load();

		AgentEventLog::set_session( $this->session_id );

		try {
			if ( $confirmed ) {
				// The last message in history is the model's tool call message.
				$assistant_message     = end( $this->history );
				$this->last_loop_phase = 'executing_confirmed_abilities';
				ChangeLogger::begin( $this->session_id, 'confirmed-tool' );
				try {
					$response_message = $this->get_ability_resolver()->execute_abilities( $assistant_message );
					/** @var \WordPress\AiClient\Messages\DTO\Message $response_message */
				} finally {
					ChangeLogger::end();
				}
				$this->last_loop_phase = 'confirmed_ability_response_received';
				// Truncate then split for OpenAI-compatible providers.
				$truncated_message = self::truncate_tool_results( $response_message );
				$this->append_tool_response_to_history( $truncated_message );
				$this->log_tool_responses( $truncated_message );
			} else {
				// Remove the model's tool call message and tell the model the call was rejected.
				array_pop( $this->history );
				$this->history[] = new UserMessage(
					array(
						new MessagePart(
							'The user declined the requested tool calls. Please respond directly without using those tools.'
						),
					)
				);
			}

			return $this->run_loop( $remaining_iterations );
		} finally {
			AgentEventLog::clear_session();
		}
	}

	/**
	 * Resume the agent loop after the browser has executed client-side tool calls.
	 *
	 * Called by the /chat/tool-result REST endpoint. Reconstructs a tool-response
	 * Message from the client results, appends it to history, and continues the loop.
	 * Mirrors resume_after_confirmation() in shape.
	 *
	 * @param list<array{id: string, name: string, result?: mixed, error?: string}> $results Client tool results.
	 * @param int                                                                   $remaining_iterations Remaining loop iterations from the paused state.
	 * @return array<string, mixed>|WP_Error
	 */
	public function resume_after_client_tools( array $results, int $remaining_iterations ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'sd_ai_agent_missing_client',
				__( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' )
			);
		}

		ProviderCredentialLoader::load();

		AgentEventLog::set_session( $this->session_id );

		// Build a tool-response message from the client results.
		$parts = array();
		foreach ( $results as $result ) {
			$id   = (string) ( $result['id'] ?? '' );
			$name = (string) ( $result['name'] ?? '' );

			if ( '' === $id || '' === $name ) {
				continue;
			}

			// Encode the result payload as a JSON string for the response.
			$response_payload = isset( $result['error'] )
				? wp_json_encode( array( 'error' => $result['error'] ) )
				: wp_json_encode( $result['result'] ?? array() );

			$parts[] = new MessagePart(
				new FunctionResponse(
					$id,
					$name,
					(string) $response_payload
				)
			);
		}

		if ( ! empty( $parts ) ) {
			$response_message = new UserMessage( $parts );
			$this->append_tool_response_to_history( $response_message );

			// Log the client tool responses for transparency.
			foreach ( $results as $result ) {
				$id   = (string) ( $result['id'] ?? '' );
				$name = (string) ( $result['name'] ?? '' );
				if ( '' === $id || '' === $name ) {
					continue;
				}

				$this->tool_call_log[] = array(
					'type'     => 'response',
					'id'       => $id,
					'name'     => $name,
					'response' => $result['result'] ?? $result['error'] ?? null,
					'source'   => 'client',
					'sequence' => $this->next_activity_sequence(),
				);
			}

			// Fire progress so the UI reflects the client tool responses
			// immediately, matching the behaviour of server-side tool calls.
			$this->fire_progress();
		}

		try {
			return $this->run_loop( $remaining_iterations );
		} finally {
			AgentEventLog::clear_session();
		}
	}

	/**
	 * Attach channel/message logs to a terminal loop payload.
	 *
	 * @param array<string, mixed> $payload Base result payload.
	 * @return array<string, mixed>
	 */
	private function with_result_logs( array $payload ): array {
		$payload['messages'] = $this->message_log;
		return $payload;
	}

	/**
	 * Attach resumable channel/message logs to a paused loop payload.
	 *
	 * @param array<string, mixed> $payload Base pause payload.
	 * @return array<string, mixed>
	 */
	private function with_paused_logs( array $payload ): array {
		$payload['message_log'] = $this->message_log;
		$payload['messages']    = $this->message_log;
		return $payload;
	}

	/**
	 * Inner loop: send prompts, handle tool calls, repeat.
	 *
	 * @param int $iterations Max iterations remaining.
	 * @return array<string, mixed>|WP_Error
	 */
	private function run_loop( int $iterations ) {
		$last_was_tool_call = false;

		// Wall-clock deadline prevents runaway loops even when round count
		// and token budget are within limits (e.g. cheap read-only tool
		// calls in a spin cycle).
		$deadline = microtime( true ) + self::LOOP_TIMEOUT_SECONDS;

		while ( $iterations > 0 ) {
			--$iterations;
			++$this->iterations_used;
			$this->last_loop_phase = 'iteration_started';

			// Heartbeat: advance updated_at so the hourly stale-job reaper
			// can distinguish an actively-running loop from a zombie row.
			// Skipped when not running under a background job (active_job_id
			// empty) to avoid unnecessary DB writes from automations/CLI.
			if ( '' !== $this->active_job_id ) {
				ActiveJobRepository::heartbeat( $this->active_job_id );
			}

			// Wall-clock timeout check.
			if ( microtime( true ) >= $deadline ) {
				AgentEventLog::log(
					'agent_loop_aborted',
					AgentEventLog::SEVERITY_WARNING,
					array(
						'session_id'      => $this->session_id,
						'reason'          => 'timeout',
						'iterations_used' => $this->iterations_used,
						'iterations_max'  => (int) $this->max_iterations,
						'model_id'        => (string) $this->model_id,
						'provider_id'     => (string) $this->provider_id,
					)
				);

				return $this->with_result_logs(
					array(
						'reply'           => __(
							'This request took longer than expected and was stopped to protect your usage budget. You can continue the conversation to pick up where it left off.',
							'superdav-ai-agent'
						),
						'history'         => $this->serialize_history(),
						'tool_calls'      => $this->tool_call_log,
						'token_usage'     => $this->token_usage,
						'iterations_used' => $this->iterations_used,
						'model_id'        => $this->model_id,
						'exit_reason'     => 'timeout',
					)
				);
			}

			// Check for user interrupts — messages sent while the loop runs.
			// Inject them into the conversation history so the model is
			// aware of the new context on this iteration.
			$this->check_and_inject_interrupts();

			// Smart conversation trimming before each LLM call.
			// @phpstan-ignore-next-line
			$max_turns = (int) $this->settings_service->get( 'max_history_turns' );
			if ( $max_turns > 0 ) {
				$this->history = ConversationTrimmer::trim( $this->history, $max_turns );
			}

			// Safety net: validate tool_use/tool_result pairing even when
			// trimming is disabled. Deserialization round-trips or history
			// corruption from session storage could leave orphaned tool
			// calls that cause API 400 errors.
			$this->history = ConversationTrimmer::validate_tool_pairs( $this->history );

			$this->last_loop_phase = 'provider_call';
			$result                = $this->send_prompt();
			$this->last_loop_phase = 'provider_response_received';

			if ( is_wp_error( $result ) ) {
				/** @var WP_Error $result */
				AgentEventLog::log(
					'agent_loop_aborted',
					AgentEventLog::SEVERITY_ERROR,
					array(
						'session_id'      => $this->session_id,
						'reason'          => (string) $result->get_error_code(),
						'iterations_used' => $this->iterations_used,
						'iterations_max'  => (int) $this->max_iterations,
						'model_id'        => (string) $this->model_id,
						'provider_id'     => (string) $this->provider_id,
						'message'         => (string) $result->get_error_message(),
					)
				);
				return $result;
			}

			/** @var \WordPress\AiClient\Results\DTO\GenerativeAiResult $result */
			$assistant_message = $result->toMessage();

			// Accumulate token usage if available.
			$this->accumulate_tokens( $result );

			if ( $this->is_truncated_tool_call_result( $result, $assistant_message ) ) {
				// Partial tool call cut at the cap — discard the unsafe call,
				// inject guidance, retry. This is the long-standing branch.
				$this->preamble_truncation_retries = 0;
				$this->inject_truncated_tool_call_guidance( $assistant_message );
				continue;
			}

			if ( $this->is_truncated_before_tool_call_result( $result, $assistant_message ) ) {
				// Model wrote a preamble, then hit the cap before opening any
				// tool call. Without intervention the loop would treat the
				// preamble as a final answer and silently exit, leaving the
				// session idle. Inject distinct guidance and retry — but cap
				// the retries so a genuinely undersized model doesn't burn
				// every iteration on the same stall (see
				// PREAMBLE_TRUNCATION_MAX_RETRIES).
				++$this->preamble_truncation_retries;
				if ( $this->preamble_truncation_retries > self::PREAMBLE_TRUNCATION_MAX_RETRIES ) {
					return $this->abort_on_repeated_preamble_truncation();
				}
				$this->inject_pre_tool_call_truncation_guidance();
				continue;
			}

			// Normal (non-truncated) turn — reset the preamble retry counter
			// so an unrelated later truncation doesn't inherit prior state.
			$this->preamble_truncation_retries = 0;

			// Some providers/models emit the same function call more than once in
			// a single assistant response when they are hedging. Unless there is an
			// explicit parallel identifier, execute only the first identical
			// name+arguments pair so metrics and provider time reflect real work.
			$assistant_message = $this->deduplicate_tool_calls( $assistant_message );

			// Split multi-part assistant messages so each function_call lives
			// in its own ModelMessage. The OpenAI Responses API rejects
			// "function_call + other part" shapes (see
			// ConversationSerializer::append_assistant_message for the full
			// rationale and provider-side validator reference).
			$this->append_assistant_message_to_history( $assistant_message );

			// Check if the model wants to call tools.
			if ( ! $this->message_has_function_calls( $assistant_message ) ) {
				// No tool calls — we're done.
				$last_was_tool_call    = false;
				$reply                 = '';
				$this->last_loop_phase = 'final_response_received';

				try {
					$reply = $result->toText();
				} catch ( \RuntimeException $e ) {
					$reply = '';
				}

				// If a prior create/update saved invalid block markup, do not let the
				// model report success until it has attempted the documented
				// update-post self-repair loop. The system prompt already instructs
				// this behaviour; this guard catches models that ignore that instruction
				// after seeing a block_validation.invalidBlocks response.
				if ( ! empty( $this->pendingBlockValidationRepairs ) ) {
					if ( $iterations > 0 ) {
						$this->inject_block_validation_repair_guidance();
						continue;
					}

					$reply = $this->append_unresolved_block_validation_warning( $reply );
				}

				// If the response is empty or whitespace-only after tool results,
				// inject a follow-up user message asking the AI to summarize.
				// This handles models that silently return an empty text turn
				// after processing tool results instead of providing a summary.
				// Guard: only attempt if we have at least one iteration remaining
				// to avoid consuming the last slot and returning empty anyway.
				if ( '' === trim( $reply ) && $iterations > 0 ) {
					$this->history[] = new UserMessage(
						[
							new MessagePart(
								__(
									'Please summarize the tool results for the user and provide your final response.',
									'superdav-ai-agent'
								)
							),
						]
					);

					++$this->iterations_used;
					$this->last_loop_phase = 'provider_followup_call';
					$followup_result       = $this->send_prompt();
					$this->last_loop_phase = 'provider_followup_response_received';

					if ( ! is_wp_error( $followup_result ) ) {
						$followup_message = $followup_result->toMessage();
						$this->append_assistant_message_to_history( $followup_message );
						$this->accumulate_tokens( $followup_result );

						try {
							$reply = $followup_result->toText();
						} catch ( \RuntimeException $e ) {
							$reply = '';
						}
					}
				}

				// Post-process the reply to inject real permalinks from create-post responses.
				$reply = $this->inject_real_permalinks( $reply );

				return $this->inject_inability_data(
					$this->with_result_logs(
						array(
							'reply'           => $reply,
							'history'         => $this->serialize_history(),
							'tool_calls'      => $this->tool_call_log,
							'token_usage'     => $this->token_usage,
							'iterations_used' => $this->iterations_used,
							'model_id'        => $this->model_id,
						)
					)
				);
			}

			$last_was_tool_call = true;

			// Log tool calls and check for confirmation requirement.
			$this->log_tool_calls( $assistant_message );

			// ── Client-side ability routing ───────────────────────────────
			// Partition tool calls into PHP-executable and JS-pending sets.
			// PHP calls execute inline; JS calls are returned as pending so
			// the browser can dispatch them and POST results back.
			$client_names = $this->client_router->get_names();
			if ( ! empty( $client_names ) ) {
				$partition = $this->partition_tool_calls( $assistant_message, $client_names );

				if ( ! empty( $partition['client'] ) ) {
					// Execute any PHP-side calls inline first.
					if ( ! empty( $partition['php'] ) ) {
						$php_message           = ClientAbilityRouter::build_message_from_parts( $assistant_message, $partition['php'] );
						$this->last_loop_phase = 'executing_client_partition_abilities';
						ChangeLogger::begin( $this->session_id );
						try {
							$php_response = $this->get_ability_resolver()->execute_abilities( $php_message );
							/** @var \WordPress\AiClient\Messages\DTO\Message $php_response */
						} finally {
							ChangeLogger::end();
						}
						$this->last_loop_phase = 'client_partition_ability_response_received';
						$truncated_php         = self::truncate_tool_results( $php_response );
						$this->append_tool_response_to_history( $truncated_php );
						$this->log_tool_responses( $truncated_php );
					}

					// Persist loop state so the resume endpoint can reconstruct it.
					if ( $this->session_id > 0 ) {
						$paused_state = array(
							'history'              => $this->serialize_history(),
							'tool_call_log'        => $this->tool_call_log,
							'message_log'          => $this->message_log,
							'token_usage'          => $this->token_usage,
							'iterations_remaining' => $iterations,
							'model_id'             => $this->model_id,
							'provider_id'          => $this->provider_id,
							'client_abilities'     => $this->client_abilities,
						);
						Database::save_paused_state( $this->session_id, $paused_state );
					}

					// Return pending client tool calls to the browser.
					return $this->with_paused_logs(
						array(
							'pending_client_tool_calls' => $partition['client'],
							'history'                   => $this->serialize_history(),
							'tool_call_log'             => $this->tool_call_log,
							'token_usage'               => $this->token_usage,
							'iterations_remaining'      => $iterations,
							'iterations_used'           => $this->iterations_used,
							'model_id'                  => $this->model_id,
						)
					);
				}
			}
			// ── End client-side routing ───────────────────────────────────

			$confirm_needed = $this->permission_resolver->get_tools_needing_confirmation( $assistant_message );

			if ( ! empty( $confirm_needed ) ) {
				return $this->with_paused_logs(
					array(
						'awaiting_confirmation' => true,
						'pending_tools'         => $confirm_needed,
						'history'               => $this->serialize_history(),
						'tool_call_log'         => $this->tool_call_log,
						'token_usage'           => $this->token_usage,
						'iterations_remaining'  => $iterations,
						'iterations_used'       => $this->iterations_used,
						'model_id'              => $this->model_id,
					)
				);
			}

			// Execute the ability calls and get the function response message.
			$this->last_loop_phase = 'executing_abilities';
			ChangeLogger::begin( $this->session_id );
			try {
				$response_message = $this->get_ability_resolver()->execute_abilities( $assistant_message );
				/** @var \WordPress\AiClient\Messages\DTO\Message $response_message */
			} finally {
				ChangeLogger::end();
			}
			$this->last_loop_phase = 'ability_response_received';

			// Check if any tool result is a proposal_pending status.
			// If so, return it to the client for user approval.
			$pending_proposal = $this->extract_pending_proposal( $response_message );
			if ( null !== $pending_proposal ) {
				// Persist loop state so the resume endpoint can reconstruct it.
				if ( $this->session_id > 0 ) {
					$paused_state = array(
						'history'              => $this->serialize_history(),
						'tool_call_log'        => $this->tool_call_log,
						'message_log'          => $this->message_log,
						'token_usage'          => $this->token_usage,
						'iterations_remaining' => $iterations,
						'model_id'             => $this->model_id,
						'provider_id'          => $this->provider_id,
						'client_abilities'     => $this->client_abilities,
					);
					Database::save_paused_state( $this->session_id, $paused_state );
				}

				return $this->with_paused_logs(
					array(
						'pending_proposal'     => $pending_proposal,
						'history'              => $this->serialize_history(),
						'tool_call_log'        => $this->tool_call_log,
						'token_usage'          => $this->token_usage,
						'iterations_remaining' => $iterations,
						'iterations_used'      => $this->iterations_used,
						'model_id'             => $this->model_id,
					)
				);
			}

			// Truncate large tool results before adding to history, then
			// append (splitting multi-part responses for OpenAI-compatible
			// providers that only accept one tool result per message).
			$truncated_message = self::truncate_tool_results( $response_message );
			$this->append_tool_response_to_history( $truncated_message );
			$this->log_tool_responses( $truncated_message );
			$this->last_loop_phase = 'tool_response_recorded';

			// Reset the wall-clock deadline after each productive tool call.
			// This allows genuinely long tasks (many sequential tool calls) to
			// complete while still killing truly stalled loops that make no
			// forward progress within a single LOOP_TIMEOUT_SECONDS window.
			$deadline = microtime( true ) + self::LOOP_TIMEOUT_SECONDS;

			// Spin detection: delegate to SpinDetector which encapsulates
			// the idle-round state (last_tool_signature + idle_rounds counter).
			if ( $this->spin_detector->record( $assistant_message, self::MAX_IDLE_ROUNDS ) ) {
				AgentEventLog::log(
					'agent_loop_aborted',
					AgentEventLog::SEVERITY_WARNING,
					array(
						'session_id'      => $this->session_id,
						'reason'          => 'spin_detected',
						'iterations_used' => $this->iterations_used,
						'iterations_max'  => (int) $this->max_iterations,
						'model_id'        => (string) $this->model_id,
						'provider_id'     => (string) $this->provider_id,
					)
				);

				return $this->with_result_logs(
					array(
						'reply'           => __(
							'I\'ve been repeating the same operations without making progress. Here\'s what I found so far. Try rephrasing your request or providing more specifics.',
							'superdav-ai-agent'
						),
						'history'         => $this->serialize_history(),
						'tool_calls'      => $this->tool_call_log,
						'token_usage'     => $this->token_usage,
						'iterations_used' => $this->iterations_used,
						'model_id'        => $this->model_id,
						'exit_reason'     => 'spin_detected',
					)
				);
			}
		}

		// Exhausted iterations. If the last AI turn was a tool call (not text),
		// the user would see an empty response. Inject one final summarization
		// prompt so the AI can explain what it accomplished and what failed.
		if ( $last_was_tool_call ) {
			$this->history[] = new UserMessage(
				[
					new MessagePart(
						__(
							'You have reached the maximum number of tool calls. Please summarize what you accomplished and what failed, and provide your final response to the user.',
							'superdav-ai-agent'
						)
					),
				]
			);

			++$this->iterations_used;
			$this->last_loop_phase = 'provider_fallback_call';
			$fallback_result       = $this->send_prompt();
			$this->last_loop_phase = 'provider_fallback_response_received';

			if ( ! is_wp_error( $fallback_result ) ) {
				$fallback_message = $fallback_result->toMessage();
				$this->append_assistant_message_to_history( $fallback_message );
				$this->accumulate_tokens( $fallback_result );

				$reply = '';
				try {
					$reply = $fallback_result->toText();
				} catch ( \RuntimeException $e ) {
					$reply = '';
				}

				// Post-process the reply to inject real permalinks from create-post responses.
				$reply = $this->inject_real_permalinks( $reply );

				return $this->inject_inability_data(
					$this->with_result_logs(
						array(
							'reply'           => $reply,
							'history'         => $this->serialize_history(),
							'tool_calls'      => $this->tool_call_log,
							'token_usage'     => $this->token_usage,
							'iterations_used' => $this->iterations_used,
							'model_id'        => $this->model_id,
						)
					)
				);
			}
		}

		// Exhausted iterations — return what we have so callers can inspect the log.
		AgentEventLog::log(
			'tool_limit_reached',
			AgentEventLog::SEVERITY_WARNING,
			array(
				'session_id'      => $this->session_id,
				'iterations_used' => $this->iterations_used,
				'iterations_max'  => (int) $this->max_iterations,
				'model_id'        => (string) $this->model_id,
				'provider_id'     => (string) $this->provider_id,
			)
		);

		return new WP_Error(
			'sd_ai_agent_max_iterations',
			sprintf(
				/* translators: %d: max iterations */
				__( 'Agent reached the maximum of %d iterations without completing.', 'superdav-ai-agent' ),
				$this->max_iterations
			),
			$this->with_result_logs(
				array(
					'tool_calls'      => $this->tool_call_log,
					'token_usage'     => $this->token_usage,
					'iterations_used' => $this->iterations_used,
					'model_id'        => $this->model_id,
					'history'         => $this->serialize_history(),
				)
			)
		);
	}

	/**
	 * Build and send a single prompt with the current history.
	 *
	 * Always routes through the WordPress AI Client SDK. Per-vendor direct
	 * paths and the OpenAI-compatible HTTP fallback have been removed —
	 * provider auth, model resolution, and request transport are entirely
	 * the SDK's responsibility now.
	 *
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult|WP_Error
	 */
	private function send_prompt() {
		$provider_id = $this->resolve_provider_id();

		if ( '' === $provider_id ) {
			return new WP_Error(
				'sd_ai_agent_no_provider',
				sprintf(
					/* translators: %s: URL to the Connectors admin page */
					__( 'No AI provider is configured. Please add an API key on the <a href="%s">Connectors</a> settings page to get started.', 'superdav-ai-agent' ),
					esc_url( UnifiedAdminMenu::getConnectorsUrl() )
				)
			);
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( ! $registry->hasProvider( $provider_id ) ) {
				return new WP_Error(
					'sd_ai_agent_provider_unavailable',
					sprintf(
						/* translators: 1: provider ID, 2: URL to the Connectors admin page */
						__( 'Provider "%1$s" is no longer available. Please configure a provider on the <a href="%2$s">Connectors</a> settings page.', 'superdav-ai-agent' ),
						$provider_id,
						esc_url( UnifiedAdminMenu::getConnectorsUrl() )
					)
				);
			}
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'sd_ai_agent_registry_unavailable',
				__( 'AI Client SDK registry is not available.', 'superdav-ai-agent' )
			);
		}

		$builder = wp_ai_client_prompt();
		/** @var \WP_AI_Client_Prompt_Builder $builder */

		// Resolve abilities once here so they are available for both the
		// system-instruction rebuild (cadence injection) and the prompt
		// builder's using_abilities() call below.
		$abilities = $this->resolve_abilities();

		// Rebuild the system instruction unless the caller pinned a static
		// override. This lets the manifest's "recently fetched ability
		// schemas" block reach the model on subsequent turns.
		// Pass active ability names so the cadence section is injected
		// when content-generation or theme-modification tools are in scope.
		if ( ! $this->system_instruction_locked ) {
			$ability_names            = array_map(
				static fn( \WP_Ability $a ): string => $a->get_name(),
				$abilities
			);
			$this->system_instruction = $this->instruction_builder->build( $this->settings_for_prompt, $ability_names );
		}
		$builder->using_system_instruction( $this->system_instruction );
		$this->configure_model( $builder );

		// NOTE: weak-model temperature/parallel-tool overrides are disabled
		// alongside the weak-model iteration cap (see constructor comment).
		// The same telemetry-reliability concerns apply: flagging Opus 4.7
		// or Sonnet 4.6 as weak because of past framework bugs would force
		// temperature=0 and disable parallel tool calls, making real model
		// usage noticeably slower and lower-quality without surfacing why
		// to the user. Restore once telemetry is reliable.
		//
		// The builder is `WP_AI_Client_Prompt_Builder`, a thin wrapper that
		// forwards snake_case calls (e.g. `using_max_tokens`) to the SDK's
		// camelCase `usingMaxTokens` via PHP's `__call`. An earlier version
		// of this block guarded both setters with `method_exists()`, which
		// does NOT detect `__call`-routed methods and silently skipped them
		// — leaving `max_tokens` unset (so the anthropic-max connector fell
		// back to its hard-coded 4096 default) and `temperature` absent
		// from the outgoing request body. The guards are removed because
		// the wrapper's `__call` is guaranteed to exist and the `@method`
		// declarations on the wrapper enumerate the supported API.
		//
		// Model-specific exception: some model families reject or deprecate
		// `temperature` outright with HTTP 400 even though standard models still
		// accept it. OpenAI reasoning families (gpt-5*, o1*, o3*, o4*) run at the
		// model's implicit sampling setting. Anthropic Max Claude Opus 4.7 also
		// rejects the OpenAI-compatible `temperature` field as deprecated.
		// Skip the setter for those model IDs so the request body omits the field
		// entirely. `max_tokens` is still safe to send.
		//
		// Resolve the effective model ID (including connector defaults)
		// before checking if it rejects temperature, since configure_model()
		// may have resolved a default model from the connector.
		$resolved_model_id = $this->model_id;
		if ( empty( $resolved_model_id ) && function_exists( 'OpenAiCompatibleConnector\\get_default_model' ) ) {
			$resolved_model_id = (string) \OpenAiCompatibleConnector\get_default_model();
		}
		if ( ! self::model_omits_temperature( $resolved_model_id ) ) {
			$builder->using_temperature( (float) $this->temperature );
		}
		$builder->using_max_tokens( $this->get_effective_max_output_tokens() );

		// $abilities was already resolved before the system-instruction rebuild
		// above; reuse it here instead of calling resolve_abilities() twice.
		if ( ! empty( $abilities ) ) {
			$builder->using_abilities( ...$abilities );
		}

		if ( ! empty( $this->history ) ) {
			$builder->with_history( ...$this->history );
		}

		$started_at = microtime( true );
		$last_error = null;

		for ( $attempt = 1; $attempt <= $this->provider_retry_max_attempts; ++$attempt ) {
			try {
				$result = $builder->generate_text_result();
				if ( is_wp_error( $result ) ) {
					/** @var WP_Error $result */
					$last_error = $result;
				} else {
					return $result;
				}
			} catch ( \Throwable $e ) {
				$last_error = $e;
			}

			$status_code = $this->extract_provider_error_status( $last_error );
			if ( ! $this->is_retryable_provider_error( $last_error, $status_code ) ) {
				return $this->provider_error_to_wp_error( $last_error, $status_code );
			}

			if ( $attempt >= $this->provider_retry_max_attempts ) {
				break;
			}

			$delay = $this->get_provider_retry_delay( $attempt, $last_error );
			$this->log_provider_retry_progress( $status_code, $attempt + 1, $delay );

			if ( $delay > 0 ) {
				sleep( $delay );
			}
		}

		$elapsed_seconds = max( 0, (int) round( microtime( true ) - $started_at ) );
		return $this->build_provider_retry_failed_error( $last_error, $elapsed_seconds );
	}

	/**
	 * Determine whether a provider failure is retryable.
	 *
	 * @param WP_Error|\Throwable|null $error       Last provider error.
	 * @param int                      $status_code HTTP status code, or 0 when unknown.
	 */
	private function is_retryable_provider_error( $error, int $status_code ): bool {
		if ( in_array( $status_code, self::PROVIDER_RETRYABLE_STATUS_CODES, true ) ) {
			return true;
		}

		if ( $status_code >= 400 ) {
			return false;
		}

		$message = $this->get_provider_error_message( $error );
		if ( '' === $message ) {
			return false;
		}

		return (bool) preg_match( '/\b(timeout|timed out|connection reset|connection refused|network|cURL error|internal server error|bad gateway|service unavailable|gateway timeout|too many requests|rate limit)\b/i', $message );
	}

	/**
	 * Extract an HTTP status code from provider errors produced by SDK layers.
	 *
	 * @param WP_Error|\Throwable|null $error Last provider error.
	 */
	private function extract_provider_error_status( $error ): int {
		if ( $error instanceof WP_Error ) {
			$code = $error->get_error_code();
			if ( is_numeric( $code ) ) {
				return (int) $code;
			}

			$data = $error->get_error_data();
			if ( is_array( $data ) ) {
				foreach ( [ 'status', 'status_code', 'code' ] as $key ) {
					if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
						return (int) $data[ $key ];
					}
				}
			}
		}

		if ( $error instanceof \Throwable ) {
			$code = $error->getCode();
			if ( $code >= 400 && $code <= 599 ) {
				return (int) $code;
			}
		}

		$message = $this->get_provider_error_message( $error );
		if ( preg_match( '/\((\d{3})\)|\bHTTP\s+(\d{3})\b|\bstatus\s*(?:code)?\s*[:=]?\s*(\d{3})\b/i', $message, $matches ) ) {
			foreach ( array_slice( $matches, 1 ) as $match ) {
				if ( '' !== $match ) {
					return (int) $match;
				}
			}
		}

		return 0;
	}

	/**
	 * Build a user-facing message for a provider error.
	 *
	 * @param WP_Error|\Throwable|null $error Last provider error.
	 */
	private function get_provider_error_message( $error ): string {
		if ( $error instanceof WP_Error ) {
			return $error->get_error_message();
		}

		if ( $error instanceof \Throwable ) {
			return $error->getMessage();
		}

		return '';
	}

	/**
	 * Return retry delay in seconds, honouring Retry-After metadata when present.
	 *
	 * @param int                      $attempt Current one-based attempt number.
	 * @param WP_Error|\Throwable|null $error   Last provider error.
	 */
	private function get_provider_retry_delay( int $attempt, $error ): int {
		$retry_after = $this->extract_retry_after_seconds( $error );
		if ( null !== $retry_after ) {
			return min( 60, max( 0, $retry_after ) );
		}

		$index = max( 0, $attempt - 1 );
		return (int) ( $this->provider_retry_delays[ $index ] ?? 60 );
	}

	/**
	 * Extract Retry-After from WP_Error data when the SDK preserves headers.
	 *
	 * @param WP_Error|\Throwable|null $error Last provider error.
	 */
	private function extract_retry_after_seconds( $error ): ?int {
		if ( ! $error instanceof WP_Error ) {
			return null;
		}

		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			return null;
		}

		$headers = $data['headers'] ?? $data['response_headers'] ?? null;
		if ( ! is_array( $headers ) ) {
			return null;
		}

		foreach ( $headers as $name => $value ) {
			if ( 'retry-after' !== strtolower( (string) $name ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}
			if ( is_numeric( $value ) ) {
				return (int) $value;
			}
			$timestamp = strtotime( (string) $value );
			if ( false !== $timestamp ) {
				return max( 0, $timestamp - time() );
			}
		}

		return null;
	}

	/**
	 * Extract a pending proposal from tool results.
	 *
	 * Checks if any tool result has status 'proposal_pending' and returns it.
	 * Returns null if no proposal is pending.
	 *
	 * @param Message $response_message The response message from ability execution.
	 * @return array<string, mixed>|null The pending proposal data, or null.
	 */
	private function extract_pending_proposal( Message $response_message ): ?array {
		foreach ( $response_message->getParts() as $part ) {
			$function_response = method_exists( $part, 'getFunctionResponse' ) ? $part->getFunctionResponse() : null;
			if ( ! $function_response ) {
				continue;
			}

			$result = $function_response->getResponse();
			if ( ! is_array( $result ) ) {
				continue;
			}

			$proposal = array();
			foreach ( $result as $key => $value ) {
				if ( ! is_string( $key ) ) {
					continue 2;
				}

				$proposal[ $key ] = $value;
			}

			if ( 'proposal_pending' === ( $proposal['status'] ?? null ) ) {
				return $proposal;
			}
		}

		return null;
	}

	/**
	 * Log provider retry progress so jobs and chat streams can render it.
	 */
	private function log_provider_retry_progress( int $status_code, int $next_attempt, int $delay ): void {
		$status_label = $status_code > 0 ? (string) $status_code : __( 'a transient network error', 'superdav-ai-agent' );
		$message      = sprintf(
			/* translators: 1: status/error label, 2: delay seconds, 3: next attempt number, 4: maximum attempt number */
			__( 'Provider returned %1$s. Retrying in %2$ds (attempt %3$d/%4$d)…', 'superdav-ai-agent' ),
			$status_label,
			$delay,
			$next_attempt,
			$this->provider_retry_max_attempts
		);

		$this->message_log[] = [
			'type'         => 'provider_retry',
			'message'      => $message,
			'status_code'  => $status_code,
			'attempt'      => $next_attempt,
			'max_attempts' => $this->provider_retry_max_attempts,
			'delay'        => $delay,
			'sequence'     => $this->next_activity_sequence(),
		];
		$this->fire_progress();
	}

	/**
	 * Convert a non-retryable provider failure into a WP_Error without retry noise.
	 *
	 * @param WP_Error|\Throwable|null $error       Last provider error.
	 * @param int                      $status_code HTTP status code, or 0 when unknown.
	 */
	private function provider_error_to_wp_error( $error, int $status_code ): WP_Error {
		if ( $error instanceof WP_Error ) {
			return $error;
		}

		$message = $this->get_provider_error_message( $error );
		if ( '' === $message ) {
			$message = __( 'AI provider request failed.', 'superdav-ai-agent' );
		}

		return new WP_Error(
			'sd_ai_agent_provider_error',
			$message,
			[
				'status_code' => $status_code,
			]
		);
	}

	/**
	 * Build the exhausted-retry WP_Error and persist resumable state if possible.
	 *
	 * @param WP_Error|\Throwable|null $error           Last provider error.
	 * @param int                      $elapsed_seconds Total elapsed seconds.
	 */
	private function build_provider_retry_failed_error( $error, int $elapsed_seconds ): WP_Error {
		$message = sprintf(
			/* translators: 1: attempts, 2: elapsed seconds, 3: provider error message */
			__( 'Provider retry failed after %1$d attempts over %2$ds — try resending your last message. Last error: %3$s', 'superdav-ai-agent' ),
			$this->provider_retry_max_attempts,
			$elapsed_seconds,
			$this->get_provider_error_message( $error )
		);

		if ( $this->session_id > 0 ) {
			Database::save_paused_state(
				$this->session_id,
				[
					'history'          => $this->serialize_history(),
					'tool_call_log'    => $this->tool_call_log,
					'message_log'      => $this->message_log,
					'token_usage'      => $this->token_usage,
					'model_id'         => $this->model_id,
					'provider_id'      => $this->provider_id,
					'client_abilities' => $this->client_abilities,
					'exit_reason'      => 'provider_retry_failed',
				]
			);
		}

		return new WP_Error(
			'sd_ai_agent_provider_retry_failed',
			$message,
			$this->with_result_logs(
				[
					'tool_calls'      => $this->tool_call_log,
					'token_usage'     => $this->token_usage,
					'iterations_used' => $this->iterations_used,
					'model_id'        => $this->model_id,
					'history'         => $this->serialize_history(),
					'elapsed_seconds' => $elapsed_seconds,
				]
			)
		);
	}

	/**
	 * Configure the PromptBuilder with the correct provider and model.
	 *
	 * Uses the builder's own provider/preference API so that the SDK
	 * handles model creation and dependency injection (auth, transporter)
	 * through ProviderRegistry::getProviderModel(). This avoids creating
	 * model instances outside the registry which can miss auth binding.
	 *
	 * The provider ID is resolved by {@see resolve_provider_id()} before
	 * this method is called — send_prompt() validates that a provider
	 * exists and returns a WP_Error early if none is configured.
	 *
	 * @param \WP_AI_Client_Prompt_Builder $builder The prompt builder.
	 */
	private function configure_model( $builder ): void {
		$provider_id = $this->resolve_provider_id();
		$model_id    = $this->model_id;

		if ( empty( $provider_id ) ) {
			// No provider available — send_prompt() will have already
			// returned a WP_Error, so this is a defensive no-op.
			return;
		}

		// Resolve model — fall back to the connector's configured default
		// when the agent loop was started without one. The connector default
		// (`ultimate_ai_connector_default_model`) is what produced the
		// production `gemma4:e4b` regression (GH#1494): it stored a model
		// the endpoint did not actually serve and the SDK rejected every
		// chat with a top-level error banner. Validate before adopting.
		if ( empty( $model_id ) && function_exists( 'OpenAiCompatibleConnector\\get_default_model' ) ) {
			$candidate = (string) \OpenAiCompatibleConnector\get_default_model();
			if ( '' !== $candidate && Settings::is_model_advertised( $provider_id, $candidate ) ) {
				$model_id = $candidate;
			}
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();

			if ( ! $registry->hasProvider( $provider_id ) ) {
				return;
			}

			if ( ! empty( $model_id ) ) {
				// Directly create the model instance via the registry.
				// This bypasses the SDK's model-listing HTTP call which
				// can fail for OpenAI-compatible endpoints.
				$model = $registry->getProviderModel( $provider_id, $model_id );
				$builder->using_model( $model );
			} else {
				$builder->using_provider( $provider_id );
			}
		} catch ( \Throwable $e ) {
			// Last resort: just set the provider and hope for the best.
			try {
				$builder->using_provider( $provider_id );
			} catch ( \Throwable $e2 ) {
				// Both approaches failed — builder will use default.
			}
		}
	}

	/**
	 * Resolve the provider ID to use for this request.
	 *
	 * Returns, in order of priority:
	 *  1. The explicitly configured provider ID (from options or settings).
	 *  2. The first authenticated provider found in the SDK registry.
	 *  3. An empty string when no provider is available at all.
	 *
	 * @return string Provider ID, or '' if none is configured.
	 */
	private function resolve_provider_id(): string {
		// Explicitly configured — use as-is.
		if ( ! empty( $this->provider_id ) ) {
			return $this->provider_id;
		}

		// Fall back to the first authenticated provider in the registry.
		if ( ! class_exists( '\\WordPress\\AiClient\\AiClient' ) ) {
			return '';
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			ProviderCredentialLoader::load();

			foreach ( $registry->getRegisteredProviderIds() as $id ) {
				$auth = $registry->getProviderRequestAuthentication( $id );
				if ( null !== $auth ) {
					return $id;
				}
			}
		} catch ( \Throwable $e ) {
			// Registry unavailable.
		}

		return '';
	}

	// ── Private delegation helpers ────────────────────────────────────────

	/**
	 * Serialize conversation history to transportable arrays.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function serialize_history(): array {
		return ConversationSerializer::serialize( $this->history );
	}

	/**
	 * Append a tool-response message to history.
	 *
	 * @param Message $message Tool-response message returned by the resolver.
	 */
	private function append_tool_response_to_history( Message $message ): void {
		ConversationSerializer::append_tool_response( $this->history, $message );
	}

	/**
	 * Append an assistant message to history using provider-aware preservation.
	 *
	 * DeepSeek thinking-mode chat completions require the assistant turn that
	 * opened tool calls to round-trip with its original thought channel attached
	 * as `reasoning_content` on that same wire message. The generic serializer
	 * splits mixed thought+function_call messages so the OpenAI Responses API can
	 * replay function calls as separate top-level input items, but that split
	 * severs DeepSeek's reasoning/tool-call pairing. Keep DeepSeek tool-call
	 * assistant messages intact so the DeepSeek provider can serialize them as a
	 * single assistant entry with both `tool_calls` and `reasoning_content`.
	 *
	 * @param Message $message Assistant message returned by the model.
	 */
	private function append_assistant_message_to_history( Message $message ): void {
		if ( $this->should_preserve_deepseek_tool_call_message( $message ) ) {
			$this->history[] = $message;
			return;
		}

		ConversationSerializer::append_assistant_message( $this->history, $message );
	}

	/**
	 * Whether a model-role tool-call message should remain unsplit for DeepSeek.
	 *
	 * @param Message $message Assistant message returned by the model.
	 */
	private function should_preserve_deepseek_tool_call_message( Message $message ): bool {
		if ( ! $this->is_deepseek_context() ) {
			return false;
		}

		return $this->message_has_function_calls( $message );
	}

	/**
	 * Whether a message contains any function-call part, even if not executable.
	 *
	 * Resolver::has_ability_calls() intentionally returns false for malformed or
	 * non-ability function names. The loop still must treat those as tool calls so
	 * it can return matching error tool results; otherwise the next DeepSeek/OpenAI
	 * request violates provider tool_call/tool_result pairing requirements.
	 *
	 * @param Message $message Assistant message returned by the model.
	 * @return bool True when the message contains at least one function call part.
	 */
	private function message_has_function_calls( Message $message ): bool {
		foreach ( $message->getParts() as $part ) {
			$is_function_call = false;
			if ( method_exists( $part, 'getType' ) ) {
				$type = $part->getType();
				if ( is_object( $type ) && is_callable( array( $type, 'isFunctionCall' ) ) ) {
					$is_function_call = (bool) $type->isFunctionCall();
				}
			}

			if ( ! $is_function_call && method_exists( $part, 'getFunctionCall' ) ) {
				$is_function_call = null !== $part->getFunctionCall();
			}

			if ( $is_function_call ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the active provider/model is DeepSeek or an OpenAI-compatible DeepSeek model.
	 */
	private function is_deepseek_context(): bool {
		$provider_id = strtolower( trim( (string) $this->provider_id ) );
		$model_id    = strtolower( trim( (string) $this->model_id ) );

		return 'deepseek' === $provider_id || str_starts_with( $model_id, 'deepseek-' );
	}

	/**
	 * Truncate large tool results in a response message.
	 *
	 * @param Message $message The tool response message.
	 * @return Message A new message with truncated results.
	 */
	private static function truncate_tool_results( Message $message ): Message {
		return ConversationSerializer::truncate_tool_results( $message );
	}

	/**
	 * Detect models that reject or deprecate the `temperature` parameter.
	 *
	 * Reasoning models from OpenAI (GPT-5 family + o-series) only run at the
	 * model's implicit sampling setting and respond with HTTP 400 if
	 * `temperature` is present in the request body:
	 *
	 *     Unsupported parameter: 'temperature' is not supported with this model.
	 *
	 * Anthropic Max Claude Opus 4.7 and 4.8 similarly reject `temperature` as a
	 * deprecated field in OpenAI-compatible routing, while older Claude models
	 * used by existing installs still accept it.
	 *
	 * The OpenAI check is prefix-based and intentionally broad — it covers current
	 * variants (gpt-5, gpt-5.4, gpt-5.4-mini, gpt-5.5, gpt-5.5-pro,
	 * gpt-5-codex, gpt-5-pro, o1, o1-mini, o3, o3-mini, o4, o4-mini) and any
	 * future dated/sized snapshots from the same families that follow the
	 * same naming convention (e.g. `gpt-5.5-2026-04-23`).
	 *
	 * Non-reasoning OpenAI models (gpt-4*, gpt-3.5*, gpt-4o, gpt-4.1) accept
	 * `temperature` normally and are NOT matched here.
	 *
	 * @param string $model_id The provider-scoped model identifier.
	 * @return bool True if the model rejects the `temperature` parameter.
	 */
	private static function model_omits_temperature( string $model_id ): bool {
		$normalised = strtolower( trim( $model_id ) );

		if ( '' === $normalised ) {
			return false;
		}

		// GPT-5 family — all variants are reasoning models.
		if ( str_starts_with( $normalised, 'gpt-5' ) ) {
			return true;
		}

		// o-series reasoning models (o1, o3, o4 and their *-mini/*-pro/*-preview/dated snapshots).
		// Match the exact prefix followed by `-` or end-of-string so we don't
		// accidentally match a hypothetical non-reasoning model whose ID
		// starts with `o1`/`o3`/`o4` (e.g. `o1magic`).
		foreach ( array( 'o1', 'o3', 'o4' ) as $family ) {
			if ( $normalised === $family || str_starts_with( $normalised, $family . '-' ) ) {
				return true;
			}
		}

		// Claude Opus 4.7 and 4.8 (Anthropic Max) reject `temperature` with HTTP
		// 400: "`temperature` is deprecated for this model." Match the dateless
		// IDs plus any dated/provider-specific snapshot suffixes.
		foreach ( array( 'claude-opus-4-7', 'claude-opus-4-8' ) as $opus_prefix ) {
			if ( $normalised === $opus_prefix || str_starts_with( $normalised, $opus_prefix . '-' ) ) {
				return true;
			}
		}

		return false;
	}

	// ── Resolve abilities ─────────────────────────────────────────────────

	/**
	 * Resolve which abilities should be loaded as direct (Tier-1) tools for
	 * this run. Returns the WP_Ability objects matching {@see ToolDiscovery::tier_1_for_run()}
	 * (curated cold-start list ∪ top-N most-used ∪ meta-tools), filtered
	 * through tool_permissions, the `ai_hidden` meta flag and any role-based
	 * restrictions.
	 *
	 * When client_abilities are present, synthetic WP_Ability stubs for the
	 * validated JS descriptors are appended so the model sees them in its
	 * tool list. The loop intercepts calls to these names and returns them
	 * as pending_client_tool_calls instead of executing them server-side.
	 *
	 * Tier-2 abilities are NOT returned here — the model sees them as a
	 * name-only manifest in the system prompt and reaches them via
	 * sd-ai-agent/ability-search + ability-call.
	 *
	 * @return \WP_Ability[]
	 */
	private function resolve_abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		// Explicit per-instance override (e.g. from tests or CLI --abilities).
		// When set, bypass the auto-discovery layer and return exactly what was asked for.
		if ( ! empty( $this->abilities ) ) {
			$resolved = array();
			foreach ( $this->abilities as $name ) {
				// @phpstan-ignore-next-line
				$ability = wp_get_ability( $name );
				if ( $ability instanceof \WP_Ability ) {
					$resolved[] = $ability;
				}
			}
			// Append client ability stubs even in explicit-abilities mode.
			return $this->deduplicate_by_function_name(
				array_merge( $resolved, $this->client_router->build_stubs() )
			);
		}

		$tier_1 = ToolDiscovery::tier_1_for_run( $this->agent_tier_1_tools );

		$role_allowed = RolePermissions::get_allowed_abilities_for_current_user();
		$perms        = $this->tool_permissions;

		$resolved = array();
		foreach ( $tier_1 as $name ) {
			if ( null !== $role_allowed && ! in_array( $name, $role_allowed, true ) ) {
				continue;
			}
			if ( 'disabled' === ( $perms[ $name ] ?? 'auto' ) ) {
				continue;
			}
			// @phpstan-ignore-next-line
			$ability = wp_get_ability( $name );
			if ( ! $ability instanceof \WP_Ability ) {
				continue;
			}
			if ( ! AbilityVisibility::for_ai_chat( $ability ) ) {
				continue;
			}
			$resolved[] = $ability;
		}

		// Append synthetic stubs for validated client-side abilities.
		return $this->deduplicate_by_function_name(
			array_merge( $resolved, $this->client_router->build_stubs() )
		);
	}

	/**
	 * Remove abilities whose API function name collides with an earlier entry.
	 *
	 * AI providers (e.g. Anthropic, OpenAI) reject requests with duplicate tool
	 * names (HTTP 400 "tools: Tool names must be unique"). Collisions occur when:
	 *
	 *   • Two abilities are registered under different namespace prefixes but the
	 *     same base name (e.g. "sd-ai-agent/create-block-content" and
	 *     "sd-ai-agent/create-block-content"). WP 7.0-RC2's native
	 *     ability_name_to_function_name() may normalise these to the same string,
	 *     whereas the compat polyfill preserves the full prefixed form.
	 *
	 *   • A third-party plugin registers an ability whose name, after the
	 *     namespace prefix is stripped, matches one this plugin has already
	 *     registered (e.g. "some-plugin/create-block-content").
	 *
	 * The first occurrence in the list wins; later duplicates are silently
	 * dropped. Tier-1 curated abilities appear first so they take priority over
	 * usage-tracked or third-party entries.
	 *
	 * @param \WP_Ability[] $abilities Resolved ability list, possibly containing duplicates.
	 * @return \WP_Ability[] De-duplicated list safe to pass to using_abilities().
	 */
	private function deduplicate_by_function_name( array $abilities ): array {
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			return $abilities;
		}

		$seen   = array();
		$unique = array();

		foreach ( $abilities as $ability ) {
			$fn_name = \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name(
				$ability->get_name()
			);

			// Normalise to the lowest-common-denominator form so that providers
			// which treat hyphens and underscores as equivalent (or which strip the
			// namespace prefix) do not see duplicates. Lowercase for safety.
			$key = strtolower( str_replace( '-', '_', $fn_name ) );

			if ( isset( $seen[ $key ] ) ) {
				// Log the collision so it's visible in debug logs without throwing.
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: 1: duplicate ability name, 2: earlier ability name, 3: resolved function name */
							__( 'Ability "%1$s" produces the same API tool name as "%2$s" (%3$s) and will be skipped to prevent a duplicate-tool-name API error. Register abilities under unique base names.', 'superdav-ai-agent' ),
							$ability->get_name(),
							$seen[ $key ],
							$fn_name
						)
					),
					'1.8.3'
				);
				continue;
			}

			$seen[ $key ] = $ability->get_name();
			$unique[]     = $ability;
		}

		return $unique;
	}

	/**
	 * Get or create the ability function resolver instance.
	 *
	 * @return WP_AI_Client_Ability_Function_Resolver
	 */
	private function get_ability_resolver(): WP_AI_Client_Ability_Function_Resolver {
		if ( null === $this->ability_resolver ) {
			$abilities              = $this->resolve_abilities();
			$this->ability_resolver = new AbilityFunctionResolver( ...$abilities );
		}
		return $this->ability_resolver;
	}

	// ── Tool call logging ─────────────────────────────────────────────────

	// Tool-call entries and assistant channel messages share a monotonic
	// sequence so the live UI can merge them without polluting tool_calls.
	/**
	 * Return the next chronological activity sequence number.
	 *
	 * @return int Next sequence number.
	 */
	private function next_activity_sequence(): int {
		++$this->activity_sequence;
		return $this->activity_sequence;
	}

	/**
	 * Normalise a persisted activity log into string-keyed entries.
	 *
	 * @param mixed $raw Raw option/state value.
	 * @return list<array<string, mixed>> Normalised activity entries.
	 */
	private static function normalize_activity_log( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$normalized_entry = array();
			foreach ( $entry as $key => $value ) {
				if ( is_string( $key ) ) {
					$normalized_entry[ $key ] = $value;
				}
			}

			if ( ! empty( $normalized_entry ) ) {
				$normalized[] = $normalized_entry;
			}
		}

		return $normalized;
	}

	/**
	 * Find the highest existing activity sequence across resumable logs.
	 *
	 * @param array<int, array<string, mixed>> ...$logs Existing activity logs.
	 * @return int Highest sequence found, or 0.
	 */
	private function get_max_activity_sequence( array ...$logs ): int {
		$max = 0;
		foreach ( $logs as $log ) {
			foreach ( $log as $entry ) {
				$sequence = $entry['sequence'] ?? null;
				if ( is_numeric( $sequence ) ) {
					$max = max( $max, (int) $sequence );
				}
			}
		}

		return $max;
	}

	/**
	 * Remove identical tool calls from one assistant turn before dispatch.
	 *
	 * @param Message $message Assistant message returned by the provider.
	 * @return Message Message with duplicate function calls removed.
	 */
	private function deduplicate_tool_calls( Message $message ): Message {
		$parts       = $message->getParts();
		$seen        = array();
		$deduped     = array();
		$removed     = 0;
		$has_changes = false;

		foreach ( $parts as $part ) {
			$call = $part->getFunctionCall();
			if ( ! $call ) {
				$deduped[] = $part;
				continue;
			}

			$key = $this->build_tool_call_dedupe_key( $call );
			if ( '' !== $key && ! $this->tool_call_has_parallel_intent( $call ) ) {
				if ( isset( $seen[ $key ] ) ) {
					++$removed;
					$has_changes = true;
					continue;
				}
				$seen[ $key ] = true;
			}

			$deduped[] = $part;
		}

		if ( ! $has_changes ) {
			return $message;
		}

		$this->message_log[] = array(
			'type'     => 'event',
			'reason'   => 'tool_call_deduplicated',
			'count'    => $removed,
			'sequence' => $this->next_activity_sequence(),
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Required operational observability line for duplicate tool-call suppression.
		error_log( '[Superdav AI Agent] event=tool_call_deduplicated count=' . $removed );

		AgentEventLog::log(
			'tool_call_deduplicated',
			AgentEventLog::SEVERITY_INFO,
			array(
				'session_id'  => $this->session_id,
				'model_id'    => (string) $this->model_id,
				'provider_id' => (string) $this->provider_id,
			)
		);

		return new ModelMessage( $deduped );
	}

	/**
	 * Build the per-iteration duplicate key for a function call.
	 *
	 * @param FunctionCall $call Function call DTO.
	 * @return string Stable duplicate key, or empty string when not comparable.
	 */
	private function build_tool_call_dedupe_key( FunctionCall $call ): string {
		$name = (string) $call->getName();
		if ( '' === $name ) {
			return '';
		}

		$args_json = wp_json_encode( self::canonicalize_tool_call_args( $call->getArgs() ) );
		if ( ! is_string( $args_json ) ) {
			$args_json = '';
		}

		return $name . "\n" . $args_json;
	}

	/**
	 * Canonicalise arrays so semantically identical argument objects hash equally.
	 *
	 * @param mixed $value Raw function-call arguments.
	 * @return mixed Canonicalised value.
	 */
	private static function canonicalize_tool_call_args( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize_tool_call_args( $item );
		}

		return $value;
	}

	/**
	 * Whether a call carries an explicit provider/model signal to keep duplicates parallel.
	 *
	 * @param FunctionCall $call Function call DTO.
	 * @return bool True when the call should not be deduped.
	 */
	private function tool_call_has_parallel_intent( FunctionCall $call ): bool {
		$args = $call->getArgs();
		if ( ! is_array( $args ) ) {
			return false;
		}

		foreach ( array( 'parallel_id', 'parallelId', 'parallel_group', 'parallelGroup' ) as $key ) {
			if ( ! array_key_exists( $key, $args ) ) {
				continue;
			}

			$value = $args[ $key ];
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				return true;
			}
			if ( null !== $value && ! is_scalar( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Log real tool calls and separate any assistant preamble text.
	 *
	 * Some models emit short narration in the same assistant message as a tool
	 * call. The narration belongs in the message log, not `tool_calls`, so
	 * usage dashboards and run summaries can count only real tool activity while
	 * the live UI can still merge both streams by sequence.
	 *
	 * @param Message $message Assistant message to inspect.
	 */
	private function log_tool_calls( Message $message ): void {
		foreach ( $message->getParts() as $part ) {
			$text = $part->getText();
			if ( is_string( $text ) && '' !== trim( $text ) ) {
				$this->message_log[] = array(
					'type'     => 'preamble',
					'text'     => $text,
					'sequence' => $this->next_activity_sequence(),
				);
			}

			$call = $part->getFunctionCall();
			if ( $call ) {
				$name = (string) $call->getName();
				if ( '' === $name ) {
					continue;
				}

				$this->tool_call_log[] = array(
					'type'     => 'call',
					'id'       => $call->getId(),
					'name'     => $name,
					'args'     => $call->getArgs(),
					'sequence' => $this->next_activity_sequence(),
				);
			}
		}

		$this->fire_progress();
	}

	/**
	 * Detect provider length-cap finishes that include tool calls.
	 *
	 * @param mixed   $result  Provider result object.
	 * @param Message $message Assistant message converted from the result.
	 */
	private function is_truncated_tool_call_result( $result, Message $message ): bool {
		if ( ! $this->message_has_function_calls( $message ) ) {
			return false;
		}

		return $this->finish_reason_is_length_cap( $result );
	}

	/**
	 * Detect provider length-cap finishes that never opened a tool call.
	 *
	 * This is the "preamble-only truncation" case: the model emitted assistant
	 * text (typically a one-line lead-in like "Now I'll create the full landing
	 * page...") and then hit its output cap before producing the JSON for a
	 * tool call. Without intervention the agent loop would treat the partial
	 * text as a final answer and silently exit the loop, leaving the session
	 * idle with a half-sentence reply.
	 *
	 * We only flag this when the assistant *did* emit non-empty text — an
	 * empty response that happens to report finish=length is almost always a
	 * provider bug and should not enter the guidance-retry path.
	 *
	 * @param mixed   $result  Provider result object.
	 * @param Message $message Assistant message converted from the result.
	 */
	private function is_truncated_before_tool_call_result( $result, Message $message ): bool {
		if ( $this->message_has_function_calls( $message ) ) {
			return false;
		}

		if ( ! $this->message_has_assistant_text( $message ) ) {
			return false;
		}

		return $this->finish_reason_is_length_cap( $result );
	}

	/**
	 * Whether the provider's normalized finish reason indicates an output-cap hit.
	 *
	 * @param mixed $result Provider result object.
	 */
	private function finish_reason_is_length_cap( $result ): bool {
		$reason = $this->get_result_finish_reason( $result );
		if ( '' === $reason ) {
			return false;
		}

		$normalized = strtolower( str_replace( [ '-', ' ' ], '_', $reason ) );
		return in_array( $normalized, [ 'max_tokens', 'length', 'max_output_tokens' ], true );
	}

	/**
	 * Whether a Message contains any non-empty assistant text part.
	 *
	 * @param Message $message Assistant message converted from the result.
	 */
	private function message_has_assistant_text( Message $message ): bool {
		foreach ( $message->getParts() as $part ) {
			$text = $part->getText();
			if ( is_string( $text ) && '' !== trim( $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract a provider-agnostic finish reason from SDK and direct-call results.
	 *
	 * @param mixed $result Provider result object.
	 * @return string Finish reason or empty string when unavailable.
	 */
	private function get_result_finish_reason( $result ): string {
		if ( is_object( $result ) && method_exists( $result, 'getFinishReason' ) ) {
			$reason = $result->getFinishReason();
			return is_string( $reason ) ? $reason : '';
		}

		if ( is_object( $result ) && method_exists( $result, 'getCandidates' ) ) {
			$candidates = $result->getCandidates();
			$candidate  = is_array( $candidates ) ? ( $candidates[0] ?? null ) : null;
			if ( is_object( $candidate ) && method_exists( $candidate, 'getFinishReason' ) ) {
				$reason = $candidate->getFinishReason();
				if ( is_object( $reason ) && method_exists( $reason, '__toString' ) ) {
					return (string) $reason;
				}
				if ( is_string( $reason ) ) {
					return $reason;
				}
			}
		}

		return '';
	}

	/**
	 * Replace an unsafe truncated tool-use turn with guidance for the next model turn.
	 */
	private function inject_truncated_tool_call_guidance( Message $message ): void {
		$tool_name = $this->get_first_tool_call_name( $message );
		$cap       = $this->get_effective_max_output_tokens();
		$guidance  = sprintf(
			/* translators: 1: max token cap, 2: tool/ability name. */
			__( 'Your previous response was truncated because it hit the max_tokens cap (current cap: %1$d). The tool call you started (%2$s) was discarded because its input JSON was incomplete. Either split this work into smaller tool calls, reduce the payload size, or use a tool that accepts file/post references instead of inline content.', 'superdav-ai-agent' ),
			$cap,
			$tool_name
		);

		$this->message_log[] = array(
			'type'       => 'event',
			'reason'     => 'truncated_tool_call',
			'name'       => $tool_name,
			'max_tokens' => $cap,
			'message'    => $guidance,
			'sequence'   => $this->next_activity_sequence(),
		);

		AgentEventLog::log(
			'truncated_tool_call',
			AgentEventLog::SEVERITY_WARNING,
			array(
				'session_id'  => $this->session_id,
				'ability'     => $tool_name,
				'max_tokens'  => $cap,
				'model_id'    => (string) $this->model_id,
				'provider_id' => (string) $this->provider_id,
			)
		);

		$this->history[] = new UserMessage( [ new MessagePart( $guidance ) ] );
		$this->fire_progress();
	}

	/**
	 * Inject guidance after a preamble-only truncation and retry the turn.
	 *
	 * Distinct from {@see inject_truncated_tool_call_guidance()} because no
	 * tool call was actually started — the previous wording ("the tool call
	 * you started (X) was discarded") would be a lie. This message tells the
	 * model exactly what happened (it wrote a lead-in and ran out of room)
	 * and points it at the concrete decomposition path that
	 * `sd-ai-agent/create-post` + `sd-ai-agent/append-post-content` enables
	 * for long content on small-cap models.
	 */
	private function inject_pre_tool_call_truncation_guidance(): void {
		$cap      = $this->get_effective_max_output_tokens();
		$guidance = sprintf(
			/* translators: %d: max token cap. */
			__( 'Your previous response hit the max_tokens cap (current cap: %d) before you opened a tool call. The text-only reply has been discarded. Skip any lead-in narration and call a tool immediately. For long content like landing pages, articles, or multi-section copy: call sd-ai-agent/create-post with only the hero and intro, then call sd-ai-agent/append-post-content once per remaining section. Never attempt to emit a complete long page in a single sd-ai-agent/create-post call when your output budget is this small.', 'superdav-ai-agent' ),
			$cap
		);

		$this->message_log[] = array(
			'type'       => 'event',
			'reason'     => 'truncated_before_tool_call',
			'max_tokens' => $cap,
			'message'    => $guidance,
			'sequence'   => $this->next_activity_sequence(),
		);

		AgentEventLog::log(
			'truncated_before_tool_call',
			AgentEventLog::SEVERITY_WARNING,
			array(
				'session_id'  => $this->session_id,
				'max_tokens'  => $cap,
				'model_id'    => (string) $this->model_id,
				'provider_id' => (string) $this->provider_id,
				'retry'       => $this->preamble_truncation_retries,
			)
		);

		$this->history[] = new UserMessage( [ new MessagePart( $guidance ) ] );
		$this->fire_progress();
	}

	/**
	 * Abort the loop after repeated preamble-only truncations.
	 *
	 * Returns a structured WP_Error so the calling code in {@see run()} can
	 * end the session cleanly and surface a real failure to the UI instead
	 * of leaving the session idle with a half-sentence assistant reply.
	 */
	private function abort_on_repeated_preamble_truncation(): WP_Error {
		$cap = $this->get_effective_max_output_tokens();

		AgentEventLog::log(
			'agent_loop_aborted',
			AgentEventLog::SEVERITY_ERROR,
			array(
				'session_id'      => $this->session_id,
				'reason'          => 'preamble_truncation_loop',
				'iterations_used' => $this->iterations_used,
				'iterations_max'  => (int) $this->max_iterations,
				'model_id'        => (string) $this->model_id,
				'provider_id'     => (string) $this->provider_id,
				'max_tokens'      => $cap,
				'retries'         => $this->preamble_truncation_retries,
			)
		);

		return new WP_Error(
			'preamble_truncation_loop',
			sprintf(
				/* translators: %d: max token cap. */
				__( 'The model repeatedly hit its output cap (%d tokens) before opening a tool call. The current model or output cap is too small for this task. Try a model with a larger output budget, or break the request into smaller steps.', 'superdav-ai-agent' ),
				$cap
			),
			array(
				'cap'        => $cap,
				'retries'    => $this->preamble_truncation_retries,
				'tool_calls' => $this->tool_call_log,
				'messages'   => $this->message_log,
			)
		);
	}

	/**
	 * Get the first tool call name from a message.
	 */
	private function get_first_tool_call_name( Message $message ): string {
		foreach ( $message->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( $call ) {
				return $call->getName();
			}
		}

		return __( 'unknown tool', 'superdav-ai-agent' );
	}

	/**
	 * Resolve the effective max output token cap used for provider requests.
	 *
	 * Legacy 4096 handling: the pre-7rl plugin shipped with a default of 4096
	 * which is too low for modern Claude/GPT models to complete a single
	 * page-building tool call. Existing installs carry that saved value as an
	 * "explicit" override even though the user never chose it. We treat the
	 * exact legacy default as AUTO so existing installs get the per-model
	 * catalog value transparently — without forcing a settings migration.
	 *
	 * Users who genuinely want a 4096 cap (rare) can set 4097 or any other
	 * value via the Settings UI; the legacy-default trigger is exact match
	 * only.
	 */
	private function get_effective_max_output_tokens(): int {
		// AUTO (0): consult the per-model catalog so each provider/model gets a
		// sensible value. EXPLICIT (>0): honour the saved override but clamp at
		// MAX_OUTPUT_TOKENS_CEILING to defend against runaway generations.
		$max_tokens = $this->max_output_tokens;
		if (
			$max_tokens <= Settings::MAX_OUTPUT_TOKENS_AUTO
			|| Settings::MAX_OUTPUT_TOKENS_LEGACY_DEFAULT === $max_tokens
		) {
			return Settings::get_max_output_tokens_for_model( $this->model_id );
		}

		if ( $max_tokens > Settings::MAX_OUTPUT_TOKENS_CEILING ) {
			return Settings::MAX_OUTPUT_TOKENS_CEILING;
		}

		return $max_tokens;
	}

	/**
	 * Log tool responses for transparency.
	 *
	 * After logging, fires the progress callback (if set) so the job system
	 * can write the updated tool_call_log to the transient in real time.
	 */
	private function log_tool_responses( Message $message ): void {
		foreach ( $message->getParts() as $part ) {
			$response = $part->getFunctionResponse();
			if ( $response ) {
				$name = (string) $response->getName();
				if ( '' === $name ) {
					continue;
				}

				$this->track_block_validation_response( $name, $response->getResponse() );

				$this->tool_call_log[] = array(
					'type'     => 'response',
					'id'       => $response->getId(),
					'name'     => $name,
					'response' => $response->getResponse(),
					'sequence' => $this->next_activity_sequence(),
				);
			}
		}

		$this->fire_progress();
	}

	/**
	 * Track create/update responses that require block-validation self-repair.
	 *
	 * @param string $tool_name Ability/tool name as returned by the SDK.
	 * @param mixed  $response  Tool response payload.
	 */
	private function track_block_validation_response( string $tool_name, $response ): void {
		if ( ! is_array( $response ) ) {
			return;
		}

		$normalized_name = self::normalize_logged_tool_name( $tool_name );
		if ( ! in_array( $normalized_name, array( 'sd-ai-agent/create-post', 'sd-ai-agent/update-post' ), true ) ) {
			return;
		}

		$post_id = (int) ( $response['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return;
		}

		$validation = $response['block_validation'] ?? null;
		if ( ! is_array( $validation ) ) {
			return;
		}

		$invalid_blocks = (int) ( $validation['invalidBlocks'] ?? 0 );
		if ( $invalid_blocks <= 0 ) {
			unset( $this->pendingBlockValidationRepairs[ $post_id ] );
			return;
		}

		$this->pendingBlockValidationRepairs[ $post_id ] = array(
			'post_id'        => $post_id,
			'tool_name'      => $normalized_name,
			'invalidBlocks'  => $invalid_blocks,
			'firstInvalid'   => $validation['firstInvalid'] ?? null,
			'recommendation' => (string) ( $validation['recommendation'] ?? '' ),
		);
	}

	/**
	 * Inject a hard self-repair instruction after invalid block validation.
	 */
	private function inject_block_validation_repair_guidance(): void {
		$pending = array_values( $this->pendingBlockValidationRepairs );
		$lines   = array(
			'Block validation self-repair is required before you report success.',
			'One or more create-post/update-post responses returned block_validation.invalidBlocks > 0.',
			'Your next action must be sd-ai-agent/update-post for each listed post_id, '
				. 'with content rebuilt by replacing each invalid block originalContent '
				. 'with expectedContent from block_validation.results[].',
			'Do not provide a final success response until a follow-up update-post '
				. 'response returns block_validation.invalidBlocks = 0. If repair is '
				. 'impossible, explicitly report the unresolved post_id and invalid block count.',
			'',
			'Pending repairs:',
		);

		foreach ( $pending as $repair ) {
			$lines[] = sprintf(
				'- post_id %1$d from %2$s: invalidBlocks=%3$d',
				(int) ( $repair['post_id'] ?? 0 ),
				(string) ( $repair['tool_name'] ?? '' ),
				(int) ( $repair['invalidBlocks'] ?? 0 )
			);
		}

		$this->history[] = new UserMessage( array( new MessagePart( implode( "\n", $lines ) ) ) );

		$this->message_log[] = array(
			'type'     => 'guardrail',
			'reason'   => 'block_validation_repair_required',
			'pending'  => $pending,
			'sequence' => $this->next_activity_sequence(),
		);

		$this->fire_progress();
	}

	/**
	 * Append an explicit warning when the loop cannot spend another repair turn.
	 *
	 * @param string $reply Current assistant reply.
	 * @return string Reply with unresolved-validation disclosure.
	 */
	private function append_unresolved_block_validation_warning( string $reply ): string {
		$lines = array( '', __( 'Unresolved block validation:', 'superdav-ai-agent' ) );

		foreach ( $this->pendingBlockValidationRepairs as $repair ) {
			$lines[] = sprintf(
				/* translators: 1: post ID, 2: invalid block count. */
				__( '- Post ID %1$d still has %2$d invalid block(s).', 'superdav-ai-agent' ),
				(int) ( $repair['post_id'] ?? 0 ),
				(int) ( $repair['invalidBlocks'] ?? 0 )
			);
		}

		return rtrim( $reply ) . "\n\n" . implode( "\n", $lines );
	}

	/**
	 * Normalize SDK function names back to canonical ability names when possible.
	 *
	 * @param string $tool_name Name from a FunctionCall/FunctionResponse.
	 * @return string Canonical-ish ability name.
	 */
	private static function normalize_logged_tool_name( string $tool_name ): string {
		if ( str_starts_with( $tool_name, 'wpab__sd-ai-agent__' ) ) {
			return 'sd-ai-agent/' . substr( $tool_name, strlen( 'wpab__sd-ai-agent__' ) );
		}

		return $tool_name;
	}

	/**
	 * Fire the progress callback with the current tool-call and message logs.
	 *
	 * Progress reporting is best-effort: if the callback throws, the exception
	 * is swallowed so a broken progress handler cannot abort the agent loop.
	 */
	private function fire_progress(): void {
		if ( null === $this->progress_callback ) {
			return;
		}

		try {
			call_user_func( $this->progress_callback, $this->tool_call_log, $this->message_log );
		} catch ( \Throwable $e ) {
			// Progress reporting is best-effort and must not interrupt the agent loop.
		}
	}

	// ── Interrupt handling ────────────────────────────────────────────────

	/**
	 * Check for user interrupt messages and inject them into the conversation.
	 *
	 * Called at the start of each loop iteration. If the interrupt_checker
	 * callback returns an interrupt, it's appended to the history as a
	 * UserMessage prefixed with "[User interrupt]" so the model knows
	 * the user has provided new context mid-execution.
	 */
	private function check_and_inject_interrupts(): void {
		if ( null === $this->interrupt_checker ) {
			return;
		}

		try {
			$interrupt = call_user_func( $this->interrupt_checker );
			if ( null === $interrupt || ! is_array( $interrupt ) ) {
				return;
			}

			$message_text = (string) ( $interrupt['message'] ?? '' );
			if ( '' === $message_text ) {
				return;
			}

			// Inject the interrupt as a user message so the model sees it.
			$this->history[] = new UserMessage(
				array(
					new MessagePart(
						'[User interrupt — the user has sent a new message while you were working. '
						. 'Read it carefully. If it changes the task, adapt accordingly. '
						. "If it's additional context, incorporate it and continue.]\n\n"
						. $message_text
					),
				)
			);

			// Log the interrupt for transparency.
			$this->message_log[] = array(
				'type'     => 'interrupt',
				'message'  => $message_text,
				'sequence' => $this->next_activity_sequence(),
			);

			$this->fire_progress();
		} catch ( \Throwable $e ) {
			// Interrupt checking is best-effort and must not crash the loop.
		}
	}

	// ── Token accounting ──────────────────────────────────────────────────

	/**
	 * Accumulate token usage from an AI result.
	 *
	 * @param mixed $result The AI result object.
	 */
	private function accumulate_tokens( $result ): void {
		try {
			// @phpstan-ignore-next-line
			if ( method_exists( $result, 'getUsage' ) ) {
				/** @phpstan-ignore-next-line */
				$usage = $result->getUsage();
				if ( $usage ) {
					if ( method_exists( $usage, 'getPromptTokens' ) ) {
						/** @phpstan-ignore-next-line */
						$this->token_usage['prompt'] += (int) $usage->getPromptTokens();
					}
					if ( method_exists( $usage, 'getCompletionTokens' ) ) {
						/** @phpstan-ignore-next-line */
						$this->token_usage['completion'] += (int) $usage->getCompletionTokens();
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Token tracking is best-effort.
		}
	}

	// ── Reply post-processing ────────────────────────────────────────────

	/**
	 * Post-process the final reply to inject real permalinks from create-post responses.
	 *
	 * When the agent calls sd-ai-agent/create-post, it may hallucinate the URL in its
	 * prose reply (wrong date, wrong slug) even though the tool response contains the
	 * correct permalink. This method finds successful create-post responses in the
	 * tool_call_log and appends a verified line with the real permalink to the reply.
	 *
	 * @param string $reply The assistant's final reply text.
	 * @return string The reply, potentially with real permalinks appended.
	 */
	private function inject_real_permalinks( string $reply ): string {
		// Find all successful create-post responses in the tool_call_log.
		$create_post_responses = array();
		foreach ( $this->tool_call_log as $entry ) {
			if ( 'response' !== ( $entry['type'] ?? '' ) ) {
				continue;
			}
			if ( 'sd-ai-agent/create-post' !== ( $entry['name'] ?? '' ) ) {
				continue;
			}

			$response = $entry['response'] ?? null;
			if ( ! is_array( $response ) ) {
				continue;
			}

			// Extract the permalink from the response.
			$permalink = (string) ( $response['permalink'] ?? '' );
			if ( '' === $permalink ) {
				continue;
			}

			$create_post_responses[] = array(
				'post_id'   => (int) ( $response['post_id'] ?? 0 ),
				'permalink' => $permalink,
				'status'    => (string) ( $response['status'] ?? '' ),
				'post_type' => (string) ( $response['post_type'] ?? '' ),
			);
		}

		// If we found create-post responses, append a verified line with the real permalink.
		if ( ! empty( $create_post_responses ) ) {
			$reply .= "\n\n---\n\n";
			$reply .= __( 'Verified post details:', 'superdav-ai-agent' ) . "\n";
			foreach ( $create_post_responses as $post_data ) {
				$post_type_label = 'page' === $post_data['post_type'] ? __( 'Page', 'superdav-ai-agent' ) : __( 'Post', 'superdav-ai-agent' );
				$status_label    = 'publish' === $post_data['status'] ? __( 'Published', 'superdav-ai-agent' ) : ucfirst( $post_data['status'] );
				$reply          .= sprintf(
					/* translators: 1: post type label, 2: status label, 3: post ID, 4: permalink */
					__( '- %1$s %2$s (ID: %3$d): %4$s', 'superdav-ai-agent' ),
					$post_type_label,
					$status_label,
					$post_data['post_id'],
					$post_data['permalink']
				) . "\n";
			}
		}

		return $reply;
	}

	// ── Inability data injection ──────────────────────────────────────────

	/**
	 * Inject inability_reported data into a loop result array if the
	 * FeedbackAbilities::report-inability ability was called this request.
	 *
	 * @param array<string,mixed> $result The loop result to augment.
	 * @return array<string,mixed> The result, potentially with inability_reported added.
	 */
	private function inject_inability_data( array $result ): array {
		$inability = FeedbackAbilities::get_inability_data();
		if ( null !== $inability ) {
			$result['inability_reported'] = $inability;
		}
		return $result;
	}

	// ── Skill usage outcome heuristic ─────────────────────────────────────

	/**
	 * Apply the outcome heuristic to skill usage rows for the current session.
	 *
	 * Called after run_loop() completes. If the loop exited cleanly (no
	 * exit_reason in the result), injected skills are marked 'helpful'. All
	 * other exits (timeout, spin, WP_Error) are marked 'neutral' — we cannot
	 * infer benefit when the agent did not reach a conclusive answer.
	 *
	 * This is a Phase-1 heuristic. Future phases will refine based on
	 * model-reported inability (t186), thumbs-down feedback, and follow-up
	 * message correlation.
	 *
	 * @param array<string,mixed>|WP_Error $result The loop result.
	 */
	private function evaluate_skill_outcomes( $result ): void {
		if ( $this->session_id <= 0 ) {
			return;
		}

		if ( is_wp_error( $result ) ) {
			SkillUsageRepository::update_session_outcomes( $this->session_id, 'neutral' );
			return;
		}

		// @phpstan-ignore-next-line
		$exit_reason = $result['exit_reason'] ?? '';

		$outcome = ( '' === $exit_reason ) ? 'helpful' : 'neutral';

		SkillUsageRepository::update_session_outcomes( $this->session_id, $outcome );
	}

	// ── Client ability partitioning ───────────────────────────────────────

	/**
	 * Partition an assistant message's tool calls into PHP-executable and
	 * client-side (JS) sets.
	 *
	 * Delegates to {@see ClientAbilityRouter::partition()} and exists as a
	 * named method so tests can exercise the partitioning logic in isolation
	 * via reflection without needing a full loop run.
	 *
	 * @param Message  $message      The assistant message containing tool calls.
	 * @param string[] $client_names Names of client-side abilities.
	 * @return array{php: list<MessagePart>, client: list<array<string, mixed>>}
	 */
	private function partition_tool_calls( Message $message, array $client_names ): array {
		return $this->client_router->partition( $message, $client_names );
	}
}
