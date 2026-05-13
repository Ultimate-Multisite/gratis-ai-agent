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
use SdAiAgent\Core\BudgetManager;
use SdAiAgent\Core\ChangeLogger;
use SdAiAgent\Repositories\SkillUsageRepository;
use SdAiAgent\Tools\ModelHealthTracker;
use SdAiAgent\Tools\ToolDiscovery;
use SdAiAgent\Core\RolePermissions;
use WP_AI_Client_Ability_Function_Resolver;
use WP_Error;
use SdAiAgent\Core\CredentialResolver;
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
	private $tool_call_log = array();

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

	/** @var list<string> Per-agent Tier 1 tool override (empty = use global default). */
	private array $agent_tier_1_tools = array();

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
	 * Signature: function( list<array<string, mixed>> $tool_call_log ): void
	 * Used by the job system to write live progress to the transient so the
	 * polling frontend can show tool activity before the loop completes.
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

		// @phpstan-ignore-next-line
		$this->provider_id = $options['provider_id'] ?? ( $settings['default_provider'] ?: '' );
		// @phpstan-ignore-next-line
		$this->model_id = $options['model_id'] ?? ( $settings['default_model'] ?: '' );
		// @phpstan-ignore-next-line
		$this->max_iterations = $options['max_iterations'] ?? ( $settings['max_iterations'] ?: 25 );

		// Cap iterations harder for known-weak models — they burn through
		// rounds on dead-end paths, so failing fast surfaces a model
		// limitation to the user instead of timing out at 2 minutes.
		if ( ModelHealthTracker::is_weak( (string) ( $options['model_id'] ?? ( $settings['default_model'] ?? '' ) ) ) ) {
			$this->max_iterations = min( (int) $this->max_iterations, 10 );
		}
		// @phpstan-ignore-next-line
		$this->temperature = $options['temperature'] ?? ( $settings['temperature'] ?? 0.7 );
		// @phpstan-ignore-next-line
		$this->max_output_tokens = $options['max_output_tokens'] ?? ( $settings['max_output_tokens'] ?? 4096 );

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
		$this->yolo_mode = (bool) ( $options['yolo_mode'] ?? ( $settings['yolo_mode'] ?? false ) );
		// @phpstan-ignore-next-line
		$this->tool_call_log = $options['tool_call_log'] ?? array();
		// @phpstan-ignore-next-line
		$this->session_id = (int) ( $options['session_id'] ?? 0 );
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

		// Append the new user message to history.
		$this->history[] = new UserMessage( array( new MessagePart( $this->user_message ) ) );

		try {
			$result = $this->run_loop( $this->max_iterations );

			// Apply Phase-1 outcome heuristic to skill usage rows for this session.
			$this->evaluate_skill_outcomes( $result );

			return $result;
		} finally {
			AgentEventLog::clear_session();
		}
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
				$assistant_message = end( $this->history );
				ChangeLogger::begin( $this->session_id, 'confirmed-tool' );
				try {
					$response_message = $this->get_ability_resolver()->execute_abilities( $assistant_message );
					/** @var \WordPress\AiClient\Messages\DTO\Message $response_message */
				} finally {
					ChangeLogger::end();
				}
				// Truncate then split for OpenAI-compatible providers.
				$truncated_message = self::truncate_tool_results( $response_message );
				$this->append_tool_response_to_history( $truncated_message );
				$this->log_tool_responses( $response_message );
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
				$this->tool_call_log[] = array(
					'type'     => 'response',
					'id'       => (string) ( $result['id'] ?? '' ),
					'name'     => (string) ( $result['name'] ?? '' ),
					'response' => $result['result'] ?? $result['error'] ?? null,
					'source'   => 'client',
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

				return array(
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

			$result = $this->send_prompt();

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
			$this->history[]   = $assistant_message;

			// Accumulate token usage if available.
			$this->accumulate_tokens( $result );

			// Check if the model wants to call tools.
			if ( ! $this->get_ability_resolver()->has_ability_calls( $assistant_message ) ) {
				// No tool calls — we're done.
				$last_was_tool_call = false;
				$reply              = '';

				try {
					$reply = $result->toText();
				} catch ( \RuntimeException $e ) {
					$reply = '';
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
					$followup_result = $this->send_prompt();

					if ( ! is_wp_error( $followup_result ) ) {
						$followup_message = $followup_result->toMessage();
						$this->history[]  = $followup_message;
						$this->accumulate_tokens( $followup_result );

						try {
							$reply = $followup_result->toText();
						} catch ( \RuntimeException $e ) {
							$reply = '';
						}
					}
				}

				return $this->inject_inability_data(
					array(
						'reply'           => $reply,
						'history'         => $this->serialize_history(),
						'tool_calls'      => $this->tool_call_log,
						'token_usage'     => $this->token_usage,
						'iterations_used' => $this->iterations_used,
						'model_id'        => $this->model_id,
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
						$php_message = ClientAbilityRouter::build_message_from_parts( $assistant_message, $partition['php'] );
						ChangeLogger::begin( $this->session_id );
						try {
							$php_response = $this->get_ability_resolver()->execute_abilities( $php_message );
							/** @var \WordPress\AiClient\Messages\DTO\Message $php_response */
						} finally {
							ChangeLogger::end();
						}
						$truncated_php = self::truncate_tool_results( $php_response );
						$this->append_tool_response_to_history( $truncated_php );
						$this->log_tool_responses( $php_response );
					}

					// Persist loop state so the resume endpoint can reconstruct it.
					if ( $this->session_id > 0 ) {
						$paused_state = array(
							'history'              => $this->serialize_history(),
							'tool_call_log'        => $this->tool_call_log,
							'token_usage'          => $this->token_usage,
							'iterations_remaining' => $iterations,
							'model_id'             => $this->model_id,
							'provider_id'          => $this->provider_id,
							'client_abilities'     => $this->client_abilities,
						);
						Database::save_paused_state( $this->session_id, $paused_state );
					}

					// Return pending client tool calls to the browser.
					return array(
						'pending_client_tool_calls' => $partition['client'],
						'history'                   => $this->serialize_history(),
						'tool_call_log'             => $this->tool_call_log,
						'token_usage'               => $this->token_usage,
						'iterations_remaining'      => $iterations,
						'iterations_used'           => $this->iterations_used,
						'model_id'                  => $this->model_id,
					);
				}
			}
			// ── End client-side routing ───────────────────────────────────

			$confirm_needed = $this->permission_resolver->get_tools_needing_confirmation( $assistant_message );

			if ( ! empty( $confirm_needed ) ) {
				return array(
					'awaiting_confirmation' => true,
					'pending_tools'         => $confirm_needed,
					'history'               => $this->serialize_history(),
					'tool_call_log'         => $this->tool_call_log,
					'token_usage'           => $this->token_usage,
					'iterations_remaining'  => $iterations,
					'iterations_used'       => $this->iterations_used,
					'model_id'              => $this->model_id,
				);
			}

			// Execute the ability calls and get the function response message.
			ChangeLogger::begin( $this->session_id );
			try {
				$response_message = $this->get_ability_resolver()->execute_abilities( $assistant_message );
				/** @var \WordPress\AiClient\Messages\DTO\Message $response_message */
			} finally {
				ChangeLogger::end();
			}
			// Truncate large tool results before adding to history, then
			// append (splitting multi-part responses for OpenAI-compatible
			// providers that only accept one tool result per message).
			$truncated_message = self::truncate_tool_results( $response_message );
			$this->append_tool_response_to_history( $truncated_message );
			$this->log_tool_responses( $response_message );

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

				return array(
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
			$fallback_result = $this->send_prompt();

			if ( ! is_wp_error( $fallback_result ) ) {
				$fallback_message = $fallback_result->toMessage();
				$this->history[]  = $fallback_message;
				$this->accumulate_tokens( $fallback_result );

				$reply = '';
				try {
					$reply = $fallback_result->toText();
				} catch ( \RuntimeException $e ) {
					$reply = '';
				}

				return $this->inject_inability_data(
					[
						'reply'           => $reply,
						'history'         => $this->serialize_history(),
						'tool_calls'      => $this->tool_call_log,
						'token_usage'     => $this->token_usage,
						'iterations_used' => $this->iterations_used,
						'model_id'        => $this->model_id,
					]
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
			array(
				'tool_calls'      => $this->tool_call_log,
				'token_usage'     => $this->token_usage,
				'iterations_used' => $this->iterations_used,
				'model_id'        => $this->model_id,
				'history'         => $this->serialize_history(),
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

		// Rebuild the system instruction unless the caller pinned a static
		// override. This lets the manifest's "recently fetched ability
		// schemas" block reach the model on subsequent turns.
		if ( ! $this->system_instruction_locked ) {
			$this->system_instruction = $this->instruction_builder->build( $this->settings_for_prompt );
		}
		$builder->using_system_instruction( $this->system_instruction );
		$this->configure_model( $builder );

		// For known-weak models, force temperature 0 (less hallucination
		// of arg shapes) and disable parallel tool calls (single-track
		// models lose track of which result corresponds to which call).
		$is_weak     = ModelHealthTracker::is_weak( $this->model_id );
		$temperature = $is_weak ? 0.0 : (float) $this->temperature;

		if ( method_exists( $builder, 'using_temperature' ) ) {
			$builder->using_temperature( $temperature );
		}

		if ( $is_weak && method_exists( $builder, 'using_custom_options' ) ) {
			// @phpstan-ignore-next-line — using_custom_options() exists at runtime in WP 7.0.
			$builder->using_custom_options( array( 'parallel_tool_calls' => false ) );
		}

		if ( method_exists( $builder, 'using_max_tokens' ) ) {
			$builder->using_max_tokens( (int) $this->max_output_tokens );
		}

		$abilities = $this->resolve_abilities();
		if ( ! empty( $abilities ) ) {
			$builder->using_abilities( ...$abilities );
		}

		if ( ! empty( $this->history ) ) {
			$builder->with_history( ...$this->history );
		}

		return $builder->generate_text_result();
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

		// Resolve model — fall back to the connector's configured default.
		if ( empty( $model_id ) && function_exists( 'OpenAiCompatibleConnector\\get_default_model' ) ) {
			$model_id = \OpenAiCompatibleConnector\get_default_model();
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
	 * Truncate large tool results in a response message.
	 *
	 * @param Message $message The tool response message.
	 * @return Message A new message with truncated results.
	 */
	private static function truncate_tool_results( Message $message ): Message {
		return ConversationSerializer::truncate_tool_results( $message );
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
			$meta = $ability->get_meta();
			if ( ! empty( $meta['ai_hidden'] ) ) {
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

	/**
	 * Log tool calls from an assistant message for transparency.
	 */
	private function log_tool_calls( Message $message ): void {
		foreach ( $message->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( $call ) {
				$this->tool_call_log[] = array(
					'type' => 'call',
					'id'   => $call->getId(),
					'name' => $call->getName(),
					'args' => $call->getArgs(),
				);
			}
		}

		$this->fire_progress();
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
				$this->tool_call_log[] = array(
					'type'     => 'response',
					'id'       => $response->getId(),
					'name'     => $response->getName(),
					'response' => $response->getResponse(),
				);
			}
		}

		$this->fire_progress();
	}

	/**
	 * Fire the progress callback with the current tool call log.
	 *
	 * Progress reporting is best-effort: if the callback throws, the exception
	 * is swallowed so a broken progress handler cannot abort the agent loop.
	 */
	private function fire_progress(): void {
		if ( null === $this->progress_callback ) {
			return;
		}

		try {
			call_user_func( $this->progress_callback, $this->tool_call_log );
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
			$this->tool_call_log[] = array(
				'type'    => 'interrupt',
				'message' => $message_text,
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
