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
use SdAiAgent\Abilities\KnowledgeAbilities;
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
use SdAiAgent\Core\CredentialResolver;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
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
	 * Maximum empty required-input calls before stopping the run.
	 *
	 * A model that changes ability names while sending `{}` can evade the
	 * ordinary same-call spin detector. Three failures leave it enough room to
	 * correct the first mistake without allowing a validation-error storm.
	 */
	const MAX_CONSECUTIVE_EMPTY_TOOL_CALL_FAILURES = 3;

	/**
	 * Maximum token-estimated size (in characters) for a single tool result
	 * fed back into the loop. Results exceeding this are truncated.
	 * ~40K chars ≈ 10K tokens — generous but bounded.
	 */
	const MAX_TOOL_RESULT_CHARS = 40000;

	/** Maximum provider-call attempts for retryable transient failures. */
	private const PROVIDER_RETRY_MAX_ATTEMPTS = 4;

	/** Longer managed-service retry budget for short edge/network outages. */
	private const MANAGED_PROVIDER_RETRY_MAX_ATTEMPTS = 6;

	/** Default exponential backoff schedule in seconds. */
	private const PROVIDER_RETRY_DELAYS = array( 1, 2, 4 );

	/** Managed-service backoff schedule: 31 seconds before the final attempt. */
	private const MANAGED_PROVIDER_RETRY_DELAYS = array( 1, 2, 4, 8, 16 );

	/** Durable checkpoint phase saved before a provider call is attempted. */
	public const CHECKPOINT_BEFORE_PROVIDER_CALL = 'before_provider_call';

	/** Durable checkpoint phase saved after an assistant/tool-call response is recorded. */
	public const CHECKPOINT_PROVIDER_RESPONSE_RECORDED = 'provider_response_recorded';

	/** Non-resumable phase set immediately before PHP abilities execute. */
	public const CHECKPOINT_TOOL_EXECUTION_STARTED = 'tool_execution_started';

	/** Durable checkpoint phase saved after tool responses are appended. */
	public const CHECKPOINT_TOOL_RESPONSE_RECORDED = 'tool_response_recorded';

	/** Maximum serialized history retained in one automatic-resume checkpoint. */
	private const CHECKPOINT_HISTORY_MAX_BYTES = ConversationTrimmer::COMPACT_MAX_BYTES;

	/** Maximum estimated tokens retained in one automatic-resume checkpoint. */
	private const CHECKPOINT_HISTORY_MAX_TOKENS = ConversationTrimmer::COMPACT_MAX_TOKENS;

	/** Maximum serialized page context retained in one automatic-resume checkpoint. */
	private const CHECKPOINT_PAGE_CONTEXT_MAX_BYTES = 8192;

	/** Maximum serialized scalar page-context value retained in one checkpoint. */
	private const CHECKPOINT_PAGE_CONTEXT_VALUE_MAX_BYTES = 2048;

	/** Maximum durable ability names retained for one automatic resume. */
	private const CHECKPOINT_ABILITY_NAME_MAX_COUNT = 32;

	/** Maximum serialized byte length of one durable ability name. */
	private const CHECKPOINT_ABILITY_NAME_MAX_BYTES = 128;

	/** Maximum serialized byte length of a provider or model identifier. */
	private const CHECKPOINT_IDENTIFIER_MAX_BYTES = 191;

	/**
	 * Dedicated one-turn instruction for a durable-plan proposal.
	 *
	 * This mode deliberately has no abilities and returns only a narrow JSON
	 * definition. The server still treats every resulting phase as untrusted
	 * metadata and requires explicit approval before execution.
	 */
	private const DURABLE_PLAN_SYSTEM_INSTRUCTION = <<<'PROMPT'
You prepare a compact durable site-operation plan. Do not call tools, browse, change the site, or claim that work has been performed.

Return exactly one JSON object and nothing else: no Markdown fences, prose, comments, or extra fields.

The root object must contain only "scope", "summary", and "steps". "steps" must be a non-empty array with at most 20 objects. Each step object must contain only "title", "instruction", "classification", "preconditions", "expected_evidence", and "rollback_guidance". Use one of "read", "write", or "destructive" for "classification". Include concise bounded instructions and expected evidence. Do not include credentials, tokens, passwords, authorization headers, raw tool payloads, or conversation history.
PROMPT;

	/** Format version for checkpoint resume metadata. */
	public const CHECKPOINT_RESUME_METADATA_VERSION = 1;

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

	/** @var array<string, mixed> Resume metadata carried forward from a claimed checkpoint. */
	private array $checkpoint_resume_metadata = array();

	/** @var int Full-envelope bytes from an upstream 413 eligible for one reduced retry. */
	private int $provider_retry_baseline_envelope_bytes = 0;

	/** @var string Last coarse loop phase for shutdown diagnostics. */
	private string $last_loop_phase = 'initializing';

	/** @var string Safe provider trace phase for the current request scope. */
	private string $provider_trace_phase = 'initial_provider_call';

	/** @var int Maximum attempts for retryable provider failures. */
	private int $provider_retry_max_attempts = self::PROVIDER_RETRY_MAX_ATTEMPTS;

	/** @var list<int> Retry delay schedule in seconds. */
	private array $provider_retry_delays = self::PROVIDER_RETRY_DELAYS;

	/** @var bool Whether default managed-provider delays receive bounded positive jitter. */
	private bool $provider_retry_jitter = false;

	/** @var list<string> Per-agent Tier 1 tool override (empty = use global default). */
	private array $agent_tier_1_tools = array();

	/** @var string Agent slug used to select the appropriate quality profile. */
	private string $agent_slug = '';

	/** @var list<string> Ability names approved for one resumed confirmation turn. */
	private array $approved_once_abilities = array();

	/** @var array<string, mixed> Serialized assistant message that produced the pending confirmation batch. */
	private array $confirmation_message = array();

	/** @var list<array<string, mixed>>|null Serialized history before the pending confirmation batch. */
	private ?array $confirmation_history_before = null;

	/**
	 * Derived source-turn policy state retained across server-owned resumes.
	 *
	 * @var array{requires_clarification: bool, allows_explicit_draft_proposal: bool}
	 */
	private array $mutation_policy_context = array(
		'requires_clarification'         => true,
		'allows_explicit_draft_proposal' => false,
	);

	/** @var list<string> Anonymous public-chat ability allowlist for this run. */
	private array $anonymous_allowed_abilities = array();

	/** @var list<string> Anonymous public-chat knowledge collection allowlist for this run. */
	private array $anonymous_allowed_collections = array();

	/** @var bool Whether an explicitly constrained customer-agent run is active, even with empty lists. */
	private bool $customer_agent_mode = false;

	/** @var bool Whether this is a no-tools, one-turn durable-plan proposal. */
	private bool $durable_plan_mode = false;

	/** @var bool Whether a request-scoped anonymous tool policy is active, even with an empty list. */
	private bool $anonymous_policy_active = false;

	/** @var int Consecutive preamble-only truncations observed this run. */
	private int $preamble_truncation_retries = 0;

	/** @var int Empty calls rejected for missing required input in this run. */
	private int $consecutive_empty_tool_call_failures = 0;

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

	/** @var RunLocalReadonlyToolCache Reuses safe local readonly calls within this run. */
	private RunLocalReadonlyToolCache $readonly_tool_cache;

	/** @var ClientAbilityRouter Partitions tool calls to PHP or JS handlers. */
	private ClientAbilityRouter $client_router;

	/** @var GeneratedThemeCompletionGate Tracks hard completion evidence for generated block themes. */
	private GeneratedThemeCompletionGate $generated_theme_completion_gate;

	/** @var PageCompletionGate Tracks rendered quality evidence for published page mutations. */
	private PageCompletionGate $page_completion_gate;

	/** @var RenderedOutputEvidenceGate Tracks post-file-mutation browser evidence for rendered claims. */
	private RenderedOutputEvidenceGate $rendered_output_evidence_gate;

	/**
	 * @param string               $user_message     The user's prompt.
	 * @param string[]             $abilities         Ability names to enable (empty = all).
	 * @param Message[]            $history           Prior messages for multi-turn.
	 * @param array<string, mixed> $options           Optional overrides: system_instruction, max_iterations, provider_id, model_id, temperature, max_output_tokens, page_context, durable_plan_mode.
	 * @param Settings|null        $settings_service  Injected Settings service (uses Settings::instance() when null).
	 */
	public function __construct( string $user_message, array $abilities = array(), array $history = array(), array $options = array(), ?Settings $settings_service = null ) {
		$this->user_message      = $user_message;
		$this->abilities         = $abilities;
		$this->history           = $history;
		$this->durable_plan_mode = ! empty( $options['durable_plan_mode'] );
		$raw_page_ctx            = $options['page_context'] ?? null;
		$this->page_context      = is_array( $raw_page_ctx ) ? $raw_page_ctx : array();
		$this->settings_service  = $settings_service ?? new Settings();

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
		$this->yolo_mode = (bool) ( $options['yolo_mode'] ?? ( $settings['yolo_mode'] ?? false ) );
		if ( $this->durable_plan_mode ) {
			$this->max_iterations = 1;
			$this->yolo_mode      = false;
		}
		$this->tool_call_log     = self::normalize_activity_log( $options['tool_call_log'] ?? array() );
		$this->message_log       = self::normalize_activity_log( $options['message_log'] ?? array() );
		$this->activity_sequence = $this->get_max_activity_sequence( $this->tool_call_log, $this->message_log );
		// @phpstan-ignore-next-line -- Resumed confirmation state carries scalar ability names.
		$this->approved_once_abilities = $this->normalize_ability_names( $options['approved_once_abilities'] ?? array() );
		// Preserve the original unsplit assistant batch for a confirmed resume.
		// Conversation history may split parallel tool calls for provider transport.
		$raw_confirmation_message   = $options['confirmation_message'] ?? array();
		$this->confirmation_message = is_array( $raw_confirmation_message )
			? self::string_keyed_array( $raw_confirmation_message )
			: array();

		$raw_confirmation_history_before   = $options['confirmation_history_before'] ?? null;
		$this->confirmation_history_before = self::normalize_serialized_history( $raw_confirmation_history_before );
		$this->mutation_policy_context     = self::resolve_mutation_policy_context(
			$this->user_message,
			$options['mutation_policy_context'] ?? null
		);
		// @phpstan-ignore-next-line -- Public-chat options are scalar string lists.
		$this->anonymous_allowed_abilities = $this->normalize_ability_names( $options['anonymous_allowed_abilities'] ?? array() );
		// @phpstan-ignore-next-line -- Public-chat options are scalar string lists.
		$this->anonymous_allowed_collections = $this->normalize_ability_names( $options['anonymous_allowed_collections'] ?? array() );
		// Customer-agent mode keeps request-scoped gates active even when a
		// trusted consumer narrows a list to zero capabilities/collections.
		// @phpstan-ignore-next-line -- Options bag carries a scalar boolean flag.
		$this->customer_agent_mode = ! empty( $options['customer_agent_mode'] );
		// Public chat uses the same request-scoped tool gates as managed customer
		// agents, but does not otherwise become a managed customer profile.
		// @phpstan-ignore-next-line -- Options bag carries a scalar boolean flag.
		$this->anonymous_policy_active = $this->customer_agent_mode || ! empty( $options['anonymous_policy_active'] );
		// @phpstan-ignore-next-line
		$this->session_id = (int) ( $options['session_id'] ?? 0 );
		// Active job UUID for heartbeat and shutdown-handler updates.
		// Empty string when the loop is not running under a background job.
		// @phpstan-ignore-next-line
		$this->active_job_id = (string) ( $options['active_job_id'] ?? '' );

		$this->checkpoint_resume_metadata = self::checkpoint_resume_metadata_from_candidate( $options['checkpoint_resume_metadata'] ?? array() );
		$is_managed_provider              = SuperdavAiProvider::PROVIDER_ID === (string) $this->provider_id;
		$default_retry_attempts           = $is_managed_provider
			? self::MANAGED_PROVIDER_RETRY_MAX_ATTEMPTS
			: self::PROVIDER_RETRY_MAX_ATTEMPTS;
		$default_retry_delays             = $is_managed_provider
			? self::MANAGED_PROVIDER_RETRY_DELAYS
			: self::PROVIDER_RETRY_DELAYS;
		$has_retry_delay_override         = array_key_exists( 'provider_retry_delays', $options );
		// @phpstan-ignore-next-line -- Test/job callers may lower attempts or delays; managed production calls tolerate short outages by default.
		$this->provider_retry_max_attempts = max( 1, (int) ( $options['provider_retry_max_attempts'] ?? $default_retry_attempts ) );
		// Explicit schedules remain exact unless their caller also opts into jitter.
		$this->provider_retry_jitter = array_key_exists( 'provider_retry_jitter', $options )
			? ! empty( $options['provider_retry_jitter'] )
			: $is_managed_provider && ! $has_retry_delay_override;
		// @phpstan-ignore-next-line -- Values are normalised below to non-negative integer seconds.
		$retry_delays = $options['provider_retry_delays'] ?? $default_retry_delays;
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
		// @phpstan-ignore-next-line -- Agent options carry the stable stored slug.
		$this->agent_slug = sanitize_key( (string) ( $options['agent_slug'] ?? '' ) );

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
			$this->tool_permissions,
			$this->mutation_policy_context['requires_clarification'],
			$this->mutation_policy_context['allows_explicit_draft_proposal']
		);

		// SpinDetector tracks consecutive identical tool-call rounds.
		$this->spin_detector       = new SpinDetector();
		$this->readonly_tool_cache = new RunLocalReadonlyToolCache();

		// ClientAbilityRouter validates and routes client-side ability calls.
		// @phpstan-ignore-next-line
		$raw_client_abilities = ( $this->customer_agent_mode || $this->durable_plan_mode ) ? array() : ( $options['client_abilities'] ?? array() );
		if ( is_array( $raw_client_abilities ) ) {
			$this->client_router    = ClientAbilityRouter::from_raw( $raw_client_abilities );
			$this->client_abilities = $this->client_router->get_descriptors();
		} else {
			$this->client_router    = new ClientAbilityRouter();
			$this->client_abilities = array();
		}

		$this->generated_theme_completion_gate = new GeneratedThemeCompletionGate(
			$this->client_router->get_names()
		);
		$this->generated_theme_completion_gate->replay_tool_call_log( $this->tool_call_log );

		$page_quality_profile = match ( $this->agent_slug ) {
			'onboarding' => PageCompletionGate::PROFILE_SETUP,
			'general'    => PageCompletionGate::PROFILE_INCREMENTAL,
			default      => PageCompletionGate::PROFILE_OFF,
		};
		$this->page_completion_gate = new PageCompletionGate(
			$page_quality_profile,
			$this->client_router->get_names()
		);
		$this->page_completion_gate->replay_tool_call_log( $this->tool_call_log );
		$this->rendered_output_evidence_gate = new RenderedOutputEvidenceGate(
			$this->client_router->get_names()
		);
		$this->rendered_output_evidence_gate->replay_tool_call_log( $this->tool_call_log );

		// Build or lock the initial system instruction.
		if ( $this->durable_plan_mode ) {
			$this->system_instruction        = self::DURABLE_PLAN_SYSTEM_INSTRUCTION;
			$this->system_instruction_locked = true;
		} elseif ( isset( $options['system_instruction'] ) ) {
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
		if ( ! $this->is_ai_client_available() ) {
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

		try {
			$this->apply_anonymous_mode_context();
			$this->apply_page_preview_context();

			// Make session_id available to event-log emitters in sub-layers
			// (AbilityFunctionResolver, ProviderTraceLogger) that don't carry
			// a session reference through their call chain.
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
				register_shutdown_function( array( $this, 'handle_active_job_shutdown' ) );
			}

			// Append the new user message to history.
			$this->history[] = new UserMessage( array( new MessagePart( $this->user_message ) ) );

			$result = $this->run_loop( $this->max_iterations );

			// Apply Phase-1 outcome heuristic to skill usage rows for this session.
			$this->evaluate_skill_outcomes( $result );

			return $result;
		} finally {
			$this->last_loop_phase = 'agent_loop_exiting';
			$this->clear_anonymous_mode_context();
			$this->clear_page_preview_context();
			AgentEventLog::clear_session();
		}
	}

	/** Whether the WordPress AI Client SDK entry point is available. */
	protected function is_ai_client_available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/** Apply request-scoped anonymous public-chat gating to tool helpers. */
	private function apply_anonymous_mode_context(): void {
		if ( ! $this->anonymous_policy_active && empty( $this->anonymous_allowed_abilities ) ) {
			return;
		}

		ToolDiscovery::set_anonymous_allowed_abilities( $this->anonymous_allowed_abilities );
		KnowledgeAbilities::set_public_collection_allowlist( $this->anonymous_allowed_collections, $this->user_message );
	}

	/** Enable autosave-backed page preview routing for this loop request. */
	private function apply_page_preview_context(): void {
		$status = $this->page_completion_gate->get_status();
		PagePreviewWorkspace::activate(
			(string) ( $status['profile'] ?? PageCompletionGate::PROFILE_OFF ),
			$this->session_id,
			$this->active_job_id,
			true === ( $status['client_validator_present'] ?? false )
		);
	}

	/** Clear request-scoped autosave preview routing. */
	private function clear_page_preview_context(): void {
		PagePreviewWorkspace::deactivate();
	}

	/** Clear request-scoped anonymous public-chat gating from tool helpers. */
	private function clear_anonymous_mode_context(): void {
		if ( ! $this->anonymous_policy_active && empty( $this->anonymous_allowed_abilities ) ) {
			return;
		}

		ToolDiscovery::clear_anonymous_allowed_abilities();
		KnowledgeAbilities::clear_public_collection_allowlist();
	}

	/**
	 * Mark a background job interrupted and include actionable shutdown context.
	 *
	 * The database update is guarded by status='processing', so this method is a
	 * no-op after normal completion/error persistence. When PHP terminates during
	 * a provider call or ability execution, the row now records the last loop
	 * phase and a normalized fatal error code instead of a generic interruption
	 * note. Raw fatal messages, paths, and traces are never retained.
	 *
	 * @return void
	 */
	public function handle_active_job_shutdown(): void {
		if ( '' === $this->active_job_id ) {
			return;
		}

		$interrupted_phase     = $this->last_loop_phase;
		$this->last_loop_phase = 'shutdown';
		$context               = array(
			'last_safe_phase' => $interrupted_phase,
			'provider_id'     => (string) $this->provider_id,
			'model_id'        => (string) $this->model_id,
		);

		$last_error = error_get_last();
		if ( is_array( $last_error ) && $this->is_fatal_shutdown_error( $last_error ) ) {
			$type = (int) $last_error['type'];

			AgentEventLog::log(
				'active_job_shutdown_fatal',
				AgentEventLog::SEVERITY_ERROR,
				array(
					'session_id' => $this->session_id,
					'code'       => 'php_shutdown_' . $type,
					'reason'     => 'fatal_shutdown',
					'phase'      => $interrupted_phase,
				)
			);
		}

		ActiveJobRepository::mark_interrupted( $this->active_job_id, '', $context );
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
	 * Resume after a tool confirmation or rejection.
	 *
	 * @param bool $confirmed Whether the user approved the tool call.
	 * @param int  $remaining_iterations Remaining loop iterations.
	 * @return array<string, mixed>|WP_Error
	 */
	public function resume_after_confirmation( bool $confirmed, int $remaining_iterations ) {
		if ( ! $this->is_ai_client_available() ) {
			return new WP_Error(
				'sd_ai_agent_missing_client',
				__( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' )
			);
		}

		try {
			$this->apply_anonymous_mode_context();
			$this->apply_page_preview_context();
			AgentEventLog::set_session( $this->session_id );
			ProviderCredentialLoader::load();

			if ( $confirmed ) {
				// The persisted batch preserves parallel calls that history may split
				// into separate ModelMessages for OpenAI-compatible providers. Fall
				// back to legacy history for confirmation jobs created before this state
				// was added.
				$assistant_message = $this->get_confirmation_message();
				if ( ! $assistant_message instanceof Message ) {
					$assistant_message = end( $this->history );
				}
				if ( ! $assistant_message instanceof Message ) {
					return new WP_Error(
						'sd_ai_agent_invalid_confirmation_state',
						__( 'The pending confirmation state is invalid. Please retry the request.', 'superdav-ai-agent' )
					);
				}
				$client_names = $this->client_router->get_names();

				// A confirmation can cover a mixed PHP/browser response. Execute only
				// the approved PHP calls and return browser calls to the client instead
				// of sending their no-op stubs through the PHP ability resolver.
				if ( ! empty( $client_names ) ) {
					$partition = $this->partition_tool_calls( $assistant_message, $client_names );
					if ( ! empty( $partition['client'] ) ) {
						$partition['client'] = $this->mark_user_confirmed_client_tool_calls( $partition['client'] );
						if ( ! empty( $partition['php'] ) ) {
							$php_message           = ClientAbilityRouter::build_message_from_parts( $assistant_message, $partition['php'] );
							$this->last_loop_phase = 'executing_confirmed_abilities';
							ToolPermissionResolver::set_one_turn_approved_abilities( $this->approved_once_abilities );
							ChangeLogger::begin( $this->session_id, 'confirmed-tool' );
							try {
								$response_message = $this->execute_abilities( $php_message );
								/** @var \WordPress\AiClient\Messages\DTO\Message $response_message */
							} finally {
								ChangeLogger::end();
								ToolPermissionResolver::clear_one_turn_approved_abilities();
							}
							$this->last_loop_phase = 'confirmed_ability_response_received';
							// Truncate then split for OpenAI-compatible providers.
							$truncated_message = self::truncate_tool_results( $response_message );
							$this->append_tool_response_to_history( $truncated_message );
							$this->log_tool_responses( $truncated_message );
						}

						$this->last_loop_phase = 'confirmed_client_tools_pending';
						return $this->pause_for_client_tools( $partition, $remaining_iterations );
					}
				}

				$this->last_loop_phase = 'executing_confirmed_abilities';
				ToolPermissionResolver::set_one_turn_approved_abilities( $this->approved_once_abilities );
				ChangeLogger::begin( $this->session_id, 'confirmed-tool' );
				try {
					$response_message = $this->execute_abilities( $assistant_message );
					/** @var \WordPress\AiClient\Messages\DTO\Message $response_message */
				} finally {
					ChangeLogger::end();
					ToolPermissionResolver::clear_one_turn_approved_abilities();
				}
				$this->last_loop_phase = 'confirmed_ability_response_received';
				// Truncate then split for OpenAI-compatible providers.
				$truncated_message = self::truncate_tool_results( $response_message );
				$this->append_tool_response_to_history( $truncated_message );
				$this->log_tool_responses( $truncated_message );
			} else {
				// Remove the entire model tool-call batch and tell the model the call
				// was rejected. Parallel function calls may have been split into
				// several ModelMessages for provider transport, so a single pop would
				// leave unpaired calls in the resumed history.
				$history_before = $this->get_confirmation_history_before();
				if ( null !== $history_before ) {
					$this->history = $history_before;
				} else {
					// Backward compatibility for jobs created before the snapshot was
					// stored. Those jobs used the previous one-message representation.
					array_pop( $this->history );
				}
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
			$this->clear_anonymous_mode_context();
			$this->clear_page_preview_context();
			AgentEventLog::clear_session();
		}
	}

	/**
	 * Resume directly from a durable safe-boundary checkpoint.
	 *
	 * The checkpoint history already contains the user prompt and any durable
	 * model/tool messages. Unlike run(), this method must not append a new user
	 * message or blindly replay work that may have already executed.
	 *
	 * @param int $remaining_iterations Remaining loop iterations.
	 * @return array<string, mixed>|WP_Error
	 */
	public function resume_from_checkpoint( int $remaining_iterations ) {
		if ( ! $this->is_ai_client_available() ) {
			return new WP_Error(
				'sd_ai_agent_missing_client',
				__( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' )
			);
		}

		$budget_check = BudgetManager::check_budget();
		if ( is_wp_error( $budget_check ) ) {
			return $budget_check;
		}

		IdenticalFailureTracker::reset();
		ModelHealthTracker::set_current_model( $this->model_id );

		try {
			$this->apply_anonymous_mode_context();
			$this->apply_page_preview_context();
			AgentEventLog::set_session( $this->session_id );
			ProviderCredentialLoader::load();

			if ( '' !== $this->active_job_id ) {
				register_shutdown_function( array( $this, 'handle_active_job_shutdown' ) );
			}

			$result = $this->run_loop( max( 1, $remaining_iterations ) );
			$this->evaluate_skill_outcomes( $result );
			return $result;
		} finally {
			$this->last_loop_phase = 'agent_loop_exiting';
			$this->clear_anonymous_mode_context();
			$this->clear_page_preview_context();
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
		if ( ! $this->is_ai_client_available() ) {
			return new WP_Error(
				'sd_ai_agent_missing_client',
				__( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' )
			);
		}

		ProviderCredentialLoader::load();

		AgentEventLog::set_session( $this->session_id );

		// Build a tool-response message from the client results.
		$parts = array();
		foreach ( $results as $result_index => $result ) {
			$id   = (string) ( $result['id'] ?? '' );
			$name = (string) ( $result['name'] ?? '' );

			if ( '' === $id || '' === $name ) {
				continue;
			}

			// Screenshot abilities return data URIs that must be genuine image
			// parts, not huge base64 strings hidden in function-response text. Strip
			// the text copy after attachment so vision models can inspect it and
			// checkpoints/provider envelopes remain bounded.
			$review_parts   = array();
			$result_payload = $result['result'] ?? array();
			if ( self::is_screenshot_tool_name( $name ) && is_array( $result_payload ) ) {
				if ( is_string( $result_payload['image'] ?? null ) && str_starts_with( $result_payload['image'], 'data:image/' ) ) {
					try {
						$mime_type = self::screenshot_data_uri_mime_type( $result_payload['image'] );
						if ( null === $mime_type ) {
							throw new \UnexpectedValueException( 'Screenshot data URI has no supported image MIME type.' );
						}
						$review_parts[] = new MessagePart( new File( $result_payload['image'], $mime_type ) );
						unset( $result_payload['image'] );
						$result_payload['attached_to_model'] = true;
					} catch ( \Throwable $e ) {
						unset( $result_payload['image'] );
						$result_payload['attachment_error'] = $e->getMessage();
					}
				}

				if ( is_array( $result_payload['screenshots'] ?? null ) ) {
					foreach ( $result_payload['screenshots'] as $screenshot_index => $screenshot ) {
						if ( ! is_array( $screenshot ) || ! is_string( $screenshot['image'] ?? null ) || ! str_starts_with( $screenshot['image'], 'data:image/' ) ) {
							continue;
						}
						try {
							$mime_type = self::screenshot_data_uri_mime_type( $screenshot['image'] );
							if ( null === $mime_type ) {
								throw new \UnexpectedValueException( 'Screenshot data URI has no supported image MIME type.' );
							}
							$review_parts[] = new MessagePart( new File( $screenshot['image'], $mime_type ) );
							unset( $result_payload['screenshots'][ $screenshot_index ]['image'] );
							$result_payload['screenshots'][ $screenshot_index ]['attached_to_model'] = true;
						} catch ( \Throwable $e ) {
							unset( $result_payload['screenshots'][ $screenshot_index ]['image'] );
							$result_payload['screenshots'][ $screenshot_index ]['attachment_error'] = $e->getMessage();
						}
					}
				}
				$results[ $result_index ]['result'] = $result_payload;
			}

			// Encode the bounded result payload for the function response.
			$response_payload = isset( $result['error'] )
				? wp_json_encode( array( 'error' => $result['error'] ) )
				: wp_json_encode( $result_payload );

			$parts[] = new MessagePart(
				new FunctionResponse(
					$id,
					$name,
					(string) $response_payload
				)
			);
			array_push( $parts, ...$review_parts );
		}

		if ( ! empty( $parts ) ) {
			$response_message = new UserMessage( $parts );
			$this->append_tool_response_to_history( $response_message );

			AgentEventLog::log(
				'client_tools_result_received',
				AgentEventLog::SEVERITY_INFO,
				$this->build_loop_event_context(
					array(
						'client_tool_count' => count( $results ),
						'reason'            => 'browser_result_posted',
					)
				)
			);

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

				$client_result = $result['result'] ?? array( 'error' => $result['error'] ?? '' );
				$this->generated_theme_completion_gate->record_tool_response( $name, $client_result );
				$this->page_completion_gate->record_tool_response( $name, $client_result );
				$this->rendered_output_evidence_gate->record_tool_response( $name, $client_result );
			}

			// Fire progress so the UI reflects the client tool responses
			// immediately, matching the behaviour of server-side tool calls.
			$this->fire_progress();
		}

		try {
			$this->apply_anonymous_mode_context();
			$this->apply_page_preview_context();
			$this->provider_trace_phase = 'client_tool_resume';
			return $this->run_loop( $remaining_iterations );
		} finally {
			$this->provider_trace_phase = 'initial_provider_call';
			$this->clear_anonymous_mode_context();
			$this->clear_page_preview_context();
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
		$payload['messages']        = $this->message_log;
		$payload['run_diagnostics'] = array_merge(
			$this->readonly_tool_cache->get_diagnostics(),
			array( 'cumulative_prompt_tokens' => (int) ( $this->token_usage['prompt'] ?? 0 ) )
		);
		if ( $this->generated_theme_completion_gate->is_required() ) {
			$payload['generated_theme_completion'] = $this->generated_theme_completion_gate->get_status();
		}
		if ( $this->page_completion_gate->is_required() ) {
			$payload['page_quality_completion'] = $this->page_completion_gate->get_status();
		}
		if ( $this->rendered_output_evidence_gate->get_status()['required'] ) {
			$payload['rendered_output_evidence'] = $this->rendered_output_evidence_gate->get_status();
		}
		return $payload;
	}

	/**
	 * Add common loop state to an operator-facing event context.
	 *
	 * @param array<string, mixed> $context Specific event context.
	 * @return array<string, mixed>
	 */
	private function build_loop_event_context( array $context = array() ): array {
		return array_merge(
			array(
				'session_id'      => $this->session_id,
				'job_id'          => $this->active_job_id,
				'phase'           => $this->last_loop_phase,
				'iterations_used' => $this->iterations_used,
				'iterations_max'  => (int) $this->max_iterations,
				'model_id'        => (string) $this->model_id,
				'provider_id'     => (string) $this->provider_id,
				'tool_call_count' => count( $this->tool_call_log ),
				'history_count'   => count( $this->history ),
			),
			$context
		);
	}

	/**
	 * Attach resumable channel/message logs to a paused loop payload.
	 *
	 * @param array<string, mixed> $payload Base pause payload.
	 * @return array<string, mixed>
	 */
	private function with_paused_logs( array $payload ): array {
		$payload['message_log']     = $this->message_log;
		$payload['messages']        = $this->message_log;
		$payload['run_diagnostics'] = array_merge(
			$this->readonly_tool_cache->get_diagnostics(),
			array( 'cumulative_prompt_tokens' => (int) ( $this->token_usage['prompt'] ?? 0 ) )
		);
		if ( $this->generated_theme_completion_gate->is_required() ) {
			$payload['generated_theme_completion'] = $this->generated_theme_completion_gate->get_status();
		}
		if ( $this->page_completion_gate->is_required() ) {
			$payload['page_quality_completion'] = $this->page_completion_gate->get_status();
		}
		$payload['mutation_policy_context'] = $this->mutation_policy_context;

		if ( $this->rendered_output_evidence_gate->get_status()['required'] ) {
			$payload['rendered_output_evidence'] = $this->rendered_output_evidence_gate->get_status();
		}
		return $payload;
	}

	/**
	 * Resolve source-turn mutation policy state for a fresh or resumed loop.
	 *
	 * A resumed loop has no new user message. Its server-owned context must be
	 * carried forward so a vague request cannot become permissive after a
	 * confirmation, browser-tool, or checkpoint boundary. Missing legacy state
	 * defaults to clarification required rather than bypassing the policy.
	 *
	 * @param string $user_message   New user message, if this is a fresh turn.
	 * @param mixed  $resume_context Persisted source-turn policy candidate.
	 * @return array{requires_clarification: bool, allows_explicit_draft_proposal: bool}
	 */
	private static function resolve_mutation_policy_context( string $user_message, $resume_context ): array {
		if ( '' !== trim( $user_message ) ) {
			return array(
				'requires_clarification'         => SystemInstructionBuilder::requires_clarification_before_mutation( $user_message ),
				'allows_explicit_draft_proposal' => SystemInstructionBuilder::explicitly_requests_draft_proposal( $user_message ),
			);
		}

		if (
			is_array( $resume_context )
			&& isset( $resume_context['requires_clarification'], $resume_context['allows_explicit_draft_proposal'] )
			&& is_bool( $resume_context['requires_clarification'] )
			&& is_bool( $resume_context['allows_explicit_draft_proposal'] )
		) {
			return array(
				'requires_clarification'         => $resume_context['requires_clarification'],
				'allows_explicit_draft_proposal' => $resume_context['allows_explicit_draft_proposal'],
			);
		}

		return array(
			'requires_clarification'         => true,
			'allows_explicit_draft_proposal' => false,
		);
	}

	/**
	 * Restore the original assistant tool-call batch from trusted pause state.
	 *
	 * @return Message|null Original unsplit assistant message, when valid.
	 */
	private function get_confirmation_message(): ?Message {
		if ( empty( $this->confirmation_message ) ) {
			return null;
		}

		try {
			$message = Message::fromArray( $this->confirmation_message );
		} catch ( \Throwable $e ) {
			return null;
		}

		return $this->message_has_function_calls( $message ) ? $message : null;
	}

	/**
	 * Restore the history from immediately before a pending confirmation batch.
	 *
	 * @return list<Message>|null
	 */
	private function get_confirmation_history_before(): ?array {
		if ( null === $this->confirmation_history_before ) {
			return null;
		}

		try {
			return ConversationSerializer::deserialize( $this->confirmation_history_before );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Normalize a serialized history candidate from resumable job state.
	 *
	 * @param mixed $raw Serialized history candidate.
	 * @return list<array<string, mixed>>|null
	 */
	private static function normalize_serialized_history( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$history = array();
		foreach ( $raw as $message ) {
			if ( ! is_array( $message ) ) {
				return null;
			}
			$history[] = self::string_keyed_array( $message );
		}

		return $history;
	}

	/**
	 * Mark browser calls that were included in the approved confirmation batch.
	 *
	 * This server-generated flag lets the browser execute a mutating client
	 * ability only after the authenticated confirmation endpoint accepted the
	 * user's approval. Calls that were merely read-only remain unmarked.
	 *
	 * @param list<array<string, mixed>> $client_calls Pending client calls.
	 * @return list<array<string, mixed>>
	 */
	private function mark_user_confirmed_client_tool_calls( array $client_calls ): array {
		$approved = array_fill_keys( $this->approved_once_abilities, true );
		$marked   = array();

		foreach ( $client_calls as $call ) {
			$name = (string) ( $call['name'] ?? '' );
			if ( '' !== $name && isset( $approved[ $name ] ) ) {
				$call['user_confirmed'] = true;
			}
			$marked[] = $call;
		}

		return $marked;
	}

	/**
	 * Persist and return one browser-tool batch for execution by the client.
	 *
	 * @param array{php: list<MessagePart>, client: list<array<string, mixed>>} $partition            Partitioned tool calls.
	 * @param int                                                               $iterations_remaining Remaining loop iterations.
	 * @return array<string, mixed>
	 */
	private function pause_for_client_tools( array $partition, int $iterations_remaining ): array {
		// Persist loop state so the resume endpoint can reconstruct it.
		if ( $this->session_id > 0 ) {
			$paused_state = array(
				'history'                   => $this->serialize_history(),
				'tool_call_log'             => $this->tool_call_log,
				'message_log'               => $this->message_log,
				'token_usage'               => $this->token_usage,
				'mutation_policy_context'   => $this->mutation_policy_context,
				'iterations_remaining'      => $iterations_remaining,
				'model_id'                  => $this->model_id,
				'provider_id'               => $this->provider_id,
				'client_abilities'          => $this->client_abilities,
				'agent_slug'                => $this->agent_slug,
				'page_context'              => $this->checkpoint_page_context(),
				// Bind the browser's resume payload to this exact paused batch.
				'pending_client_tool_calls' => $partition['client'],
			);
			Database::save_paused_state( $this->session_id, $paused_state );
		}

		AgentEventLog::log(
			'client_tools_pending',
			AgentEventLog::SEVERITY_INFO,
			$this->build_loop_event_context(
				array(
					'client_tool_count'  => count( $partition['client'] ),
					'php_tool_count'     => count( $partition['php'] ),
					'paused_state_saved' => $this->session_id > 0,
					'reason'             => 'browser_tool_required',
				)
			)
		);

		return $this->with_paused_logs(
			array(
				'pending_client_tool_calls' => $partition['client'],
				'history'                   => $this->serialize_history(),
				'tool_call_log'             => $this->tool_call_log,
				'token_usage'               => $this->token_usage,
				'iterations_remaining'      => $iterations_remaining,
				'iterations_used'           => $this->iterations_used,
				'model_id'                  => $this->model_id,
			)
		);
	}

	/**
	 * Persist the current loop state for safe automatic background-job resume.
	 *
	 * @param string $phase                Durable loop phase.
	 * @param int    $iterations_remaining Remaining iterations for a resumed loop.
	 * @return void
	 */
	private function save_active_job_checkpoint( string $phase, int $iterations_remaining ): void {
		if ( '' === $this->active_job_id ) {
			return;
		}

		$history                 = $this->serialize_history();
		$original_history_len    = count( $history );
		$resolved_provider_id    = $this->resolve_provider_id();
		$resolved_model_id       = $this->resolve_effective_model_id( $resolved_provider_id );
		$provider_id             = self::checkpoint_identifier( (string) $resolved_provider_id );
		$model_id                = self::checkpoint_identifier( (string) $resolved_model_id );
		$metadata                = self::describe_checkpoint_request( $history, $phase, $provider_id, $model_id );
		$compaction              = array();
		$recovery_transformation = '';

		// Checkpoints are a recovery boundary, not a second unbounded transcript.
		// A session remains the durable source for the full conversation; this copy
		// only needs to be sufficient for one safe provider continuation.
		if ( $this->checkpoint_requires_compaction( $metadata ) ) {
			$compacted_history = ConversationTrimmer::compact_serialized_history(
				$history,
				self::checkpoint_compaction_byte_budget( (int) $metadata['request_budget_bytes'] ),
				self::checkpoint_compaction_token_budget( (int) $metadata['request_budget_tokens'] )
			);
			$compact_metadata  = self::describe_checkpoint_request(
				$compacted_history['messages'],
				$phase,
				$provider_id,
				$model_id
			);

			if (
				(int) $compact_metadata['request_bytes'] < (int) $metadata['request_bytes']
				&& ! self::checkpoint_request_requires_compaction( $compact_metadata )
			) {
				$history                 = $compacted_history['messages'];
				$metadata                = $compact_metadata;
				$compaction              = $compacted_history['meta'];
				$recovery_transformation = 'compact_checkpoint_history';
			} else {
				// Do not leave an oversized request behind when a deterministic
				// compact representation cannot safely fit. An empty history makes
				// this checkpoint deliberately non-resumable rather than replaying
				// the same rejected request on the next status poll.
				$history                 = array();
				$metadata                = self::describe_checkpoint_request( $history, $phase, $provider_id, $model_id );
				$compaction              = array( 'source_message_count' => $original_history_len );
				$recovery_transformation = 'discard_uncompactable_checkpoint_history';
			}
		}

		$resume_metadata = array(
			'version'      => self::CHECKPOINT_RESUME_METADATA_VERSION,
			'next_request' => $metadata,
		);

		if ( isset( $this->checkpoint_resume_metadata['last_attempt'] ) && is_array( $this->checkpoint_resume_metadata['last_attempt'] ) ) {
			$last_attempt = self::checkpoint_attempt_metadata( $this->checkpoint_resume_metadata['last_attempt'] );
			if ( ! empty( $last_attempt ) ) {
				$resume_metadata['last_attempt'] = $last_attempt;
			}
		}

		if ( '' !== $recovery_transformation ) {
			$resume_metadata['recovery_transformation'] = $recovery_transformation;
			$resume_metadata['compaction']              = self::checkpoint_compaction_metadata( $compaction );
		} elseif ( isset( $this->checkpoint_resume_metadata['recovery_transformation'] ) ) {
			$existing_transformation = self::checkpoint_recovery_transformation( (string) $this->checkpoint_resume_metadata['recovery_transformation'] );
			if ( '' !== $existing_transformation ) {
				$resume_metadata['recovery_transformation'] = $existing_transformation;
			}
			if ( isset( $this->checkpoint_resume_metadata['compaction'] ) && is_array( $this->checkpoint_resume_metadata['compaction'] ) ) {
				$existing_compaction = self::checkpoint_compaction_metadata( $this->checkpoint_resume_metadata['compaction'] );
				if ( ! empty( $existing_compaction ) ) {
					$resume_metadata['compaction'] = $existing_compaction;
				}
			}
		}

		ActiveJobRepository::save_checkpoint(
			$this->active_job_id,
			$phase,
			array(
				'history'                       => $history,
				'checkpoint_resume_metadata'    => $resume_metadata,
				'activity'                      => array(
					'tool_call_count' => count( $this->tool_call_log ),
					'message_count'   => count( $this->message_log ),
				),
				'token_usage'                   => $this->checkpoint_token_usage(),
				'iterations_remaining'          => max( 1, $iterations_remaining ),
				'model_id'                      => $model_id,
				'provider_id'                   => $provider_id,
				'client_ability_names'          => self::checkpoint_ability_names( $this->client_router->get_names() ),
				'agent_slug'                    => $this->agent_slug,
				'page_context'                  => $this->checkpoint_page_context(),
				'anonymous_allowed_abilities'   => self::checkpoint_ability_names( $this->anonymous_allowed_abilities ),
				'anonymous_allowed_collections' => self::checkpoint_ability_names( $this->anonymous_allowed_collections ),
				'anonymous_policy_active'       => $this->anonymous_policy_active,
				'mutation_policy_context'       => $this->mutation_policy_context,
			)
		);
	}

	/**
	 * Describe the next provider request without retaining its source content.
	 *
	 * This public helper is shared with the checkpoint dispatcher so legacy rows
	 * without metadata can be upgraded safely before their first resume claim.
	 *
	 * @param array<int, array<string, mixed>> $serialized_history Serializable provider history.
	 * @param string                           $phase              Durable checkpoint phase.
	 * @param string                           $provider_id        Provider selected for the request.
	 * @param string                           $model_id           Model selected for the request.
	 * @return array{fingerprint:string,request_bytes:int,request_tokens:int,request_budget_bytes:int,request_budget_tokens:int,size_class:string,phase:string,locally_rejected:bool}
	 */
	public static function describe_checkpoint_request( array $serialized_history, string $phase, string $provider_id = '', string $model_id = '' ): array {
		$history = array();
		try {
			$history = ConversationSerializer::deserialize( array_values( $serialized_history ) );
			$history = ConversationTrimmer::validate_tool_pairs( $history );
		} catch ( \Throwable $e ) {
			// The dispatcher will reject an unreadable checkpoint before it can run.
			$history = array();
		}

		$request_bytes  = ! empty( $history )
			? ConversationTrimmer::estimate_total_bytes( $history )
			: strlen( (string) wp_json_encode( $serialized_history ) );
		$request_tokens = ! empty( $history )
			? ConversationTrimmer::estimate_total_tokens( $history )
			: (int) ceil( $request_bytes / 4 );
		// Checkpoint history shares the same full-envelope reserve as transport
		// preflight. The provider body also contains system instructions, ability
		// schemas, page context, model options, and framing that are not present
		// in serialized history.
		$byte_budget  = ConversationTrimmer::get_request_envelope_byte_budget( $provider_id, $model_id );
		$token_budget = ConversationTrimmer::get_request_token_budget( $provider_id, $model_id );
		$fingerprint  = hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'history'     => $serialized_history,
					'provider_id' => $provider_id,
					'model_id'    => $model_id,
				)
			)
		);

		return array(
			'fingerprint'           => $fingerprint,
			'request_bytes'         => max( 0, $request_bytes ),
			'request_tokens'        => max( 0, $request_tokens ),
			'request_budget_bytes'  => max( 0, $byte_budget ),
			'request_budget_tokens' => max( 0, $token_budget ),
			'size_class'            => ProviderTraceLogger::classify_request_size( max( 0, $request_bytes ) ),
			'phase'                 => $phase,
			'locally_rejected'      => ! empty( $history ) && ! ConversationTrimmer::fits_budget( $history, $byte_budget, $token_budget ),
		);
	}

	/**
	 * Whether a checkpoint must use its bounded recovery representation.
	 *
	 * @param array<string, mixed> $metadata Next-request metadata.
	 */
	private function checkpoint_requires_compaction( array $metadata ): bool {
		return self::checkpoint_request_requires_compaction( $metadata );
	}

	/**
	 * @param array<string, mixed> $metadata Next-request metadata.
	 * @return bool True when the checkpoint needs compaction.
	 */
	private static function checkpoint_request_requires_compaction( array $metadata ): bool {
		return (int) ( $metadata['request_bytes'] ?? 0 ) > self::CHECKPOINT_HISTORY_MAX_BYTES
			|| (int) ( $metadata['request_tokens'] ?? 0 ) > self::CHECKPOINT_HISTORY_MAX_TOKENS
			|| ! empty( $metadata['locally_rejected'] );
	}

	/**
	 * Normalize checkpoint metadata from an untyped resume payload.
	 *
	 * @param mixed $candidate Candidate checkpoint metadata.
	 * @return array<string, mixed> String-keyed checkpoint metadata.
	 */
	private static function checkpoint_resume_metadata_from_candidate( mixed $candidate ): array {
		if ( ! is_array( $candidate ) ) {
			return array();
		}

		$metadata = array();
		foreach ( $candidate as $key => $value ) {
			if ( is_string( $key ) ) {
				$metadata[ $key ] = $value;
			}
		}

		return $metadata;
	}

	/** Get a bounded byte budget for deterministic checkpoint compaction. */
	private static function checkpoint_compaction_byte_budget( int $request_budget ): int {
		if ( $request_budget <= 0 ) {
			return self::CHECKPOINT_HISTORY_MAX_BYTES;
		}

		return max( 1024, min( self::CHECKPOINT_HISTORY_MAX_BYTES, $request_budget ) );
	}

	/** Get a bounded token budget for deterministic checkpoint compaction. */
	private static function checkpoint_compaction_token_budget( int $request_budget ): int {
		if ( $request_budget <= 0 ) {
			return self::CHECKPOINT_HISTORY_MAX_TOKENS;
		}

		return max( 256, min( self::CHECKPOINT_HISTORY_MAX_TOKENS, $request_budget ) );
	}

	/** Keep one identifier inside the fixed checkpoint storage budget. */
	private static function checkpoint_identifier( string $value ): string {
		return self::checkpoint_json_fits_budget( $value, self::CHECKPOINT_IDENTIFIER_MAX_BYTES ) ? $value : '';
	}

	/**
	 * Keep only a bounded, canonical list of ability names in checkpoint state.
	 *
	 * @param array<int, mixed> $names Candidate ability names.
	 * @return list<string>
	 */
	private static function checkpoint_ability_names( array $names ): array {
		$bounded = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			$name = trim( $name );
			if ( '' === $name || ! self::checkpoint_json_fits_budget( $name, self::CHECKPOINT_ABILITY_NAME_MAX_BYTES ) ) {
				continue;
			}

			$bounded[ $name ] = true;
			if ( count( $bounded ) >= self::CHECKPOINT_ABILITY_NAME_MAX_COUNT ) {
				break;
			}
		}

		return array_keys( $bounded );
	}

	/** @return array<string, int> */
	private function checkpoint_token_usage(): array {
		$usage = is_array( $this->token_usage ) ? $this->token_usage : array();

		return array(
			'prompt'     => max( 0, (int) ( $usage['prompt'] ?? 0 ) ),
			'completion' => max( 0, (int) ( $usage['completion'] ?? 0 ) ),
		);
	}

	/** @return array<string, mixed> */
	private function checkpoint_page_context(): array {
		$context = array();
		foreach ( array( 'summary', 'url', 'surface', 'is_frontend', 'page_title', 'post_id', 'post_type', 'admin_page', 'screen_id', 'public_chat' ) as $key ) {
			if ( ! array_key_exists( $key, $this->page_context ) || ! is_scalar( $this->page_context[ $key ] ) ) {
				continue;
			}

			self::checkpoint_append_context_value( $context, $key, $this->page_context[ $key ] );
		}

		$live_preview = $this->page_context['live_preview'] ?? null;
		if ( is_array( $live_preview ) ) {
			$preview = array();
			foreach ( array( 'affected_descriptor_supported', 'requires_refresh_when_missing_affected', 'refresh_tool' ) as $key ) {
				if ( isset( $live_preview[ $key ] ) && is_scalar( $live_preview[ $key ] ) ) {
					self::checkpoint_append_context_value( $preview, $key, $live_preview[ $key ] );
				}
			}
			if ( ! empty( $preview ) ) {
				self::checkpoint_append_context_value( $context, 'live_preview', $preview );
			}
		}

		return $context;
	}

	/** @param array<string, mixed> $context */
	private static function checkpoint_append_context_value( array &$context, string $key, mixed $value ): void {
		if ( ! self::checkpoint_json_fits_budget( $value, self::CHECKPOINT_PAGE_CONTEXT_VALUE_MAX_BYTES ) ) {
			return;
		}

		$candidate         = $context;
		$candidate[ $key ] = $value;
		if ( self::checkpoint_json_fits_budget( $candidate, self::CHECKPOINT_PAGE_CONTEXT_MAX_BYTES ) ) {
			$context = $candidate;
		}
	}

	/** @param mixed $value */
	private static function checkpoint_json_fits_budget( $value, int $maximum_bytes ): bool {
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) && strlen( $encoded ) <= $maximum_bytes;
	}

	/**
	 * Keep only the fields needed to compare a prior resume candidate.
	 *
	 * @param array<string, mixed> $metadata Candidate metadata.
	 * @return array<string, int|string>
	 */
	private static function checkpoint_attempt_metadata( array $metadata ): array {
		$fingerprint = (string) ( $metadata['fingerprint'] ?? '' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return array();
		}

		$size_class = (string) ( $metadata['size_class'] ?? '' );
		if ( ! in_array( $size_class, array( 'small', 'medium', 'large', 'very_large' ), true ) ) {
			$size_class = 'unknown';
		}

		return array(
			'fingerprint'    => $fingerprint,
			'request_bytes'  => max( 0, (int) ( $metadata['request_bytes'] ?? 0 ) ),
			'request_tokens' => max( 0, (int) ( $metadata['request_tokens'] ?? 0 ) ),
			'size_class'     => $size_class,
			'phase'          => sanitize_key( (string) ( $metadata['phase'] ?? '' ) ),
		);
	}

	/** @return string Allowed checkpoint transformation name, or empty when unknown. */
	private static function checkpoint_recovery_transformation( string $transformation ): string {
		return in_array(
			$transformation,
			array( 'compact_checkpoint_history', 'compact_checkpoint_resume', 'discard_uncompactable_checkpoint_history' ),
			true
		) ? $transformation : '';
	}

	/**
	 * Keep only numeric/boolean compaction diagnostics in persisted metadata.
	 *
	 * @param array<string, mixed> $metadata Compaction metadata.
	 * @return array<string, int|bool>
	 */
	private static function checkpoint_compaction_metadata( array $metadata ): array {
		$bounded = array();
		foreach ( array( 'source_message_count', 'retained_excerpt_count', 'boundary_omitted_count', 'estimated_bytes', 'estimated_tokens', 'max_bytes', 'max_tokens' ) as $key ) {
			if ( isset( $metadata[ $key ] ) && is_numeric( $metadata[ $key ] ) ) {
				$bounded[ $key ] = max( 0, (int) $metadata[ $key ] );
			}
		}
		foreach ( array( 'attachments_omitted', 'tool_payloads_omitted' ) as $key ) {
			if ( isset( $metadata[ $key ] ) ) {
				$bounded[ $key ] = (bool) $metadata[ $key ];
			}
		}

		return $bounded;
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

			// Preserve the full pre-trim history for recovery payloads. The provider
			// call may need a trimmed prompt, but error recovery must append against
			// the untrimmed session prefix so the failed user turn is not skipped.
			$recovery_history = $this->history;

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

			if ( $this->history_ends_with_model_message() ) {
				$result = $this->with_error_recovery_data(
					new WP_Error(
						'sd_ai_agent_history_needs_user_turn',
						__(
							'The previous agent run stopped after an assistant message. Send a new message to continue from the saved chat history.',
							'superdav-ai-agent'
						)
					),
					$recovery_history
				);

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

			$this->save_active_job_checkpoint( self::CHECKPOINT_BEFORE_PROVIDER_CALL, $iterations + 1 );

			$this->last_loop_phase = 'provider_call';
			$result                = $this->send_prompt_with_payload_recovery();
			$this->last_loop_phase = 'provider_response_received';

			if ( is_wp_error( $result ) ) {
				/** @var WP_Error $result */
				$result = $this->with_error_recovery_data( $result, $recovery_history );
				AgentEventLog::log(
					'agent_loop_aborted',
					AgentEventLog::SEVERITY_ERROR,
					$this->build_loop_event_context(
						array(
							'reason'  => (string) $result->get_error_code(),
							'message' => (string) $result->get_error_message(),
						)
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

			// Durable-plan proposal turns never execute or pause for tools. Keep the
			// raw model response out of conversation history and accept only the
			// narrow parsed definition returned by the planning prompt.
			if ( $this->durable_plan_mode ) {
				if ( $this->message_has_function_calls( $assistant_message ) ) {
					return $this->with_error_recovery_data(
						new WP_Error(
							'sd_ai_agent_durable_plan_tool_call',
							__( 'The planning response attempted to use a tool and was discarded. Please try again.', 'superdav-ai-agent' )
						),
						$recovery_history
					);
				}

				try {
					$reply = $result->toText();
				} catch ( \RuntimeException $e ) {
					$reply = '';
				}

				return $this->complete_durable_plan_response( $reply, $recovery_history );
			}

			// Some providers/models emit the same function call more than once in
			// a single assistant response when they are hedging. Unless there is an
			// explicit parallel identifier, execute only the first identical
			// name+arguments pair so metrics and provider time reflect real work.
			$assistant_message = $this->deduplicate_tool_calls( $assistant_message );

			$intercepted_xml_tool_call = $this->intercept_text_tool_call( $assistant_message );
			if ( $intercepted_xml_tool_call instanceof Message ) {
				$assistant_message = $intercepted_xml_tool_call;
			} elseif ( is_string( $intercepted_xml_tool_call ) ) {
				$this->history[]       = new UserMessage( array( new MessagePart( $intercepted_xml_tool_call ) ) );
				$this->last_loop_phase = 'text_tool_call_corrective_prompt';
				continue;
			}

			$media_budget      = $this->enforce_onboarding_media_budget( $assistant_message );
			$assistant_message = $media_budget['message'];

			$history_message          = $assistant_message;
			$history_before_assistant = $this->history;
			$reuse_plan               = $this->readonly_tool_cache->reuse( $assistant_message );

			// Split multi-part assistant messages so each function_call lives
			// in its own ModelMessage. The OpenAI Responses API rejects
			// "function_call + other part" shapes (see
			// ConversationSerializer::append_assistant_message for the full
			// rationale and provider-side validator reference).
			$this->append_assistant_message_to_history( $history_message );
			$this->save_active_job_checkpoint( self::CHECKPOINT_PROVIDER_RESPONSE_RECORDED, $iterations );

			// Check if the model wants to call tools.
			if ( ! $this->message_has_function_calls( $assistant_message ) ) {
				if ( '' !== $media_budget['guidance'] && $iterations > 0 ) {
					$this->history[]       = new UserMessage( array( new MessagePart( $media_budget['guidance'] ) ) );
					$this->last_loop_phase = 'onboarding_media_budget_guarded';
					continue;
				}

				// No tool calls — we're done.
				$last_was_tool_call    = false;
				$reply                 = '';
				$this->last_loop_phase = 'final_response_received';

				if ( '' !== $media_budget['guidance'] ) {
					$reply = $media_budget['guidance'];
				} else {
					try {
						$reply = $result->toText();
					} catch ( \RuntimeException $e ) {
						$reply = '';
					}
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

				// A generated theme starts a hard completion lifecycle at scaffold
				// time. A model cannot substitute previews, screenshots, or prose
				// for the current activated-site browser report required by the gate.
				if ( $this->generated_theme_completion_gate->requires_repair() && $iterations > 0 ) {
					$this->inject_generated_theme_completion_guidance();
					continue;
				}

				// Existing published-page repairs remain private autosave previews
				// until the exact gate-owned browser report (and Setup visual review)
				// passes. AgentLoop, not the model, commits and dispatches validation.
				if ( $this->page_completion_gate->is_ready_to_publish() && $iterations > 0 ) {
					$published = $this->publish_approved_page_previews();
					if ( ! is_wp_error( $published ) ) {
						return $this->pause_for_page_validation( $iterations );
					}
				}

				if ( $this->page_completion_gate->should_dispatch_validation() && $iterations > 0 ) {
					return $this->pause_for_page_validation( $iterations );
				}

				if ( $this->page_completion_gate->requires_repair() && $iterations > 0 ) {
					$this->inject_page_completion_guidance();
					continue;
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
					$followup_result       = $this->send_prompt_with_payload_recovery();
					$this->last_loop_phase = 'provider_followup_response_received';

					if ( is_wp_error( $followup_result ) ) {
						return $this->with_error_recovery_data( $followup_result );
					}

					$followup_message = $followup_result->toMessage();
					$this->append_assistant_message_to_history( $followup_message );
					$this->accumulate_tokens( $followup_result );

					try {
						$reply = $followup_result->toText();
					} catch ( \RuntimeException $e ) {
						$reply = '';
					}
				}

				// Post-process the reply to inject real permalinks from create-post responses.
				$reply = $this->inject_real_permalinks( $reply );
				$reply = $this->append_generated_theme_completion_notice( $reply );
				$reply = $this->append_page_completion_notice( $reply );
				$reply = $this->append_rendered_output_evidence_notice( $reply );

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
			$this->log_tool_calls( $history_message );
			if ( null !== $reuse_plan['reused'] ) {
				$this->append_tool_response_to_history( $reuse_plan['reused'] );
				$this->log_tool_responses( $reuse_plan['reused'] );
				$this->message_log[] = array(
					'type'     => 'event',
					'reason'   => 'repeated_readonly_tool_calls_reused',
					'count'    => $reuse_plan['count'],
					'sequence' => $this->next_activity_sequence(),
				);
				$this->history[]     = new UserMessage(
					array( new MessagePart( __( 'Do not repeat unchanged local file inspections. Synthesize the results already in this conversation or name the specific missing information needed to continue.', 'superdav-ai-agent' ) ) )
				);
			}

			$assistant_message = $reuse_plan['execute'];

			$empty_global_styles_guard = $this->build_empty_global_styles_guard_response( $assistant_message );
			if ( null !== $empty_global_styles_guard ) {
				$this->append_tool_response_to_history( $empty_global_styles_guard );
				$this->log_tool_responses( $empty_global_styles_guard );
				$this->inject_empty_global_styles_update_guidance();
				$this->last_loop_phase = 'empty_global_styles_update_guarded';
				continue;
			}

			if ( ! $this->message_has_function_calls( $assistant_message ) ) {
				$this->last_loop_phase = 'reused_readonly_tool_results';
				continue;
			}

			$confirm_needed = $this->permission_resolver->get_tools_needing_confirmation( $assistant_message );

			if ( ! empty( $confirm_needed ) ) {
				$this->approved_once_abilities = $this->extract_pending_ability_names( $confirm_needed );

				return $this->with_paused_logs(
					array(
						'awaiting_confirmation'       => true,
						'pending_tools'               => $confirm_needed,
						'approved_once_abilities'     => $this->approved_once_abilities,
						'confirmation_message'        => $assistant_message->toArray(),
						'confirmation_history_before' => ConversationSerializer::serialize( $history_before_assistant ),
						'history'                     => $this->serialize_history(),
						'tool_call_log'               => $this->tool_call_log,
						'token_usage'                 => $this->token_usage,
						'iterations_remaining'        => $iterations,
						'iterations_used'             => $this->iterations_used,
						'model_id'                    => $this->model_id,
					)
				);
			}

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
							$php_response = $this->execute_abilities( $php_message );
							/** @var \WordPress\AiClient\Messages\DTO\Message $php_response */
						} finally {
							ChangeLogger::end();
						}
						$this->last_loop_phase = 'client_partition_ability_response_received';
						$truncated_php         = self::truncate_tool_results( $php_response );
						$this->append_tool_response_to_history( $truncated_php );
						$this->log_tool_responses( $truncated_php );
					}

					$this->last_loop_phase = 'client_tools_pending';
					return $this->pause_for_client_tools( $partition, $iterations );
				}
			}
			// ── End client-side routing ───────────────────────────────────

			// Execute the ability calls and get the function response message.
			$this->save_active_job_checkpoint( self::CHECKPOINT_TOOL_EXECUTION_STARTED, $iterations );
			$this->last_loop_phase = 'executing_abilities';
			ChangeLogger::begin( $this->session_id );
			try {
				$response_message = $this->execute_abilities( $assistant_message );
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
						'history'                 => $this->serialize_history(),
						'tool_call_log'           => $this->tool_call_log,
						'message_log'             => $this->message_log,
						'token_usage'             => $this->token_usage,
						'iterations_remaining'    => $iterations,
						'model_id'                => $this->model_id,
						'provider_id'             => $this->provider_id,
						'client_abilities'        => $this->client_abilities,
						'agent_slug'              => $this->agent_slug,
						'page_context'            => $this->checkpoint_page_context(),
						'mutation_policy_context' => $this->mutation_policy_context,
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
			$this->readonly_tool_cache->record( $history_message, $truncated_message );
			if ( '' !== $media_budget['guidance'] ) {
				$this->history[] = new UserMessage( array( new MessagePart( $media_budget['guidance'] ) ) );
			}
			$this->last_loop_phase = 'tool_response_recorded';
			$this->save_active_job_checkpoint( self::CHECKPOINT_TOOL_RESPONSE_RECORDED, $iterations );

			if ( $this->record_empty_required_input_failures( $assistant_message, $truncated_message ) ) {
				return $this->abort_on_empty_tool_call_storm();
			}

			$scaffold_permission_denial = $this->extract_scaffold_block_theme_permission_denial( $truncated_message );
			if ( null !== $scaffold_permission_denial ) {
				$this->last_loop_phase = 'scaffold_block_theme_permission_denied';

				return $this->with_result_logs(
					array(
						'reply'           => $scaffold_permission_denial,
						'history'         => $this->serialize_history(),
						'tool_calls'      => $this->tool_call_log,
						'token_usage'     => $this->token_usage,
						'iterations_used' => $this->iterations_used,
						'model_id'        => $this->model_id,
						'exit_reason'     => 'scaffold_block_theme_permission_denied',
					)
				);
			}

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
			$fallback_result       = $this->send_prompt_with_payload_recovery();
			$this->last_loop_phase = 'provider_fallback_response_received';

			if ( is_wp_error( $fallback_result ) ) {
				return $this->with_error_recovery_data( $fallback_result );
			}

			$fallback_message = $fallback_result->toMessage();
			$this->accumulate_tokens( $fallback_result );

			$reply = '';
			try {
				$reply = $fallback_result->toText();
			} catch ( \RuntimeException $e ) {
				$reply = '';
			}

			// The summarization request is outside the executable tool budget. Some
			// providers still return another function call because tools remain in
			// the conversation history. Never persist that call as if it were going
			// to run, and never complete the job with an empty customer response.
			if ( $this->message_has_function_calls( $fallback_message ) || '' === trim( $reply ) ) {
				$reply            = __( 'I reached the tool-call limit before I could finish. Some requested work may be incomplete, so please review the current site before retrying.', 'superdav-ai-agent' );
				$fallback_message = new ModelMessage( array( new MessagePart( $reply ) ) );
			}

			$this->append_assistant_message_to_history( $fallback_message );

			// Post-process the reply to inject real permalinks from create-post responses.
			$reply = $this->inject_real_permalinks( $reply );
			$reply = $this->append_generated_theme_completion_notice( $reply );
			$reply = $this->append_page_completion_notice( $reply );
			$reply = $this->append_rendered_output_evidence_notice( $reply );

			return $this->inject_inability_data(
				$this->with_result_logs(
					array(
						'reply'           => $reply,
						'history'         => $this->serialize_history(),
						'tool_calls'      => $this->tool_call_log,
						'token_usage'     => $this->token_usage,
						'iterations_used' => $this->iterations_used,
						'model_id'        => $this->model_id,
						'exit_reason'     => 'max_iterations',
					)
				)
			);
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

		$this->discard_unpublished_page_previews();

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
	 * Convert a one-turn planning response into a safe persisted-plan handoff.
	 *
	 * The raw provider text is intentionally not appended to history. Only a
	 * generic confirmation is retained alongside the validated compact shape.
	 *
	 * @param string                     $reply            Raw planning-only provider text.
	 * @param array<int|string, Message> $recovery_history Safe pre-provider history.
	 * @return array<string, mixed>|WP_Error
	 */
	private function complete_durable_plan_response( string $reply, array $recovery_history ) {
		$definition = DurablePlanDefinitionParser::parse( $reply );
		if ( is_wp_error( $definition ) ) {
			$this->last_loop_phase = 'durable_plan_response_invalid';
			return $this->with_error_recovery_data( $definition, $recovery_history );
		}

		$safe_reply = __( 'I prepared a durable plan. Review each phase before continuing.', 'superdav-ai-agent' );

		$this->history[]       = new ModelMessage( array( new MessagePart( $safe_reply ) ) );
		$this->last_loop_phase = 'durable_plan_response_validated';

		return $this->with_result_logs(
			array(
				'reply'                   => $safe_reply,
				'history'                 => $this->serialize_history(),
				'tool_calls'              => $this->tool_call_log,
				'token_usage'             => $this->token_usage,
				'iterations_used'         => $this->iterations_used,
				'model_id'                => $this->model_id,
				'durable_plan_definition' => $definition,
			)
		);
	}

	/**
	 * Apply local input budgets and make at most one reduced 413 fallback.
	 *
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult|WP_Error
	 */
	private function send_prompt_with_payload_recovery(): GenerativeAiResult|WP_Error {
		$provider_id  = $this->resolve_provider_id();
		$model_id     = $this->resolve_effective_model_id( $provider_id );
		$byte_budget  = ConversationTrimmer::get_request_envelope_byte_budget( $provider_id, $model_id );
		$token_budget = ConversationTrimmer::get_request_token_budget( $provider_id, $model_id );

		$this->history = ConversationTrimmer::trim_to_budget( $this->history, $byte_budget, $token_budget );
		$before_bytes  = ConversationTrimmer::estimate_total_bytes( $this->history );
		$before_tokens = ConversationTrimmer::estimate_total_tokens( $this->history );

		if ( ! ConversationTrimmer::fits_budget( $this->history, $byte_budget, $token_budget ) ) {
			$recovery_outcome = $this->session_id > 0
				? 'compact_session_available'
				: 'compact_session_unavailable';
			ProviderTraceLogger::record_payload_limit(
				$provider_id,
				$model_id,
				413,
				$before_bytes,
				$before_tokens,
				$byte_budget,
				true,
				false,
				true,
				$recovery_outcome
			);

			$error = new WP_Error(
				'sd_ai_agent_provider_payload_budget_exceeded',
				__( 'This request is too large to send safely. Compact the conversation or shorten the latest message and remove large attachments before retrying.', 'superdav-ai-agent' ),
				array(
					'status_code'             => 413,
					'provider_id'             => $provider_id,
					'model_id'                => $model_id,
					'request_bytes_estimate'  => $before_bytes,
					'request_tokens_estimate' => $before_tokens,
					'request_budget_bytes'    => $byte_budget,
					'local_rejection'         => true,
					'fallback_attempted'      => false,
					'recovery_outcome'        => $recovery_outcome,
				)
			);

			return $this->with_payload_recovery_metadata( $error, $before_bytes, $before_tokens, false, false );
		}

		$result = $this->send_prompt( $provider_id, $model_id );
		if ( ! is_wp_error( $result ) || ! $this->is_payload_limit_error( $result ) ) {
			return $result;
		}

		if ( $this->is_local_payload_limit_error( $result ) ) {
			return $this->with_payload_recovery_metadata( $result, $before_bytes, $before_tokens, false, false );
		}
		$source_data              = $result->get_error_data();
		$complete_envelope_bytes  = is_array( $source_data ) && 'complete_envelope' === ( $source_data['request_size_source'] ?? '' )
			? max( 0, (int) ( $source_data['request_bytes'] ?? 0 ) )
			: 0;
		$complete_envelope_tokens = is_array( $source_data ) && 'complete_envelope' === ( $source_data['request_size_source'] ?? '' )
			? max( 0, (int) ( $source_data['request_tokens_estimate'] ?? 0 ) )
			: 0;
		ProviderTraceLogger::record_payload_limit(
			$provider_id,
			$model_id,
			413,
			$complete_envelope_bytes > 0 ? $complete_envelope_bytes : $before_bytes,
			$complete_envelope_tokens > 0 ? $complete_envelope_tokens : $before_tokens,
			$byte_budget,
			false,
			false,
			$complete_envelope_bytes <= 0,
			'reduced_retry_attempted'
		);

		// Only a measured, complete request envelope can prove that the one
		// reduced retry is materially smaller. Do not retry 413s from SDK layers
		// that did not reach the HTTP preflight hook.
		if ( $complete_envelope_bytes <= 0 ) {
			return $this->with_payload_recovery_metadata( $result, $before_bytes, $before_tokens, false, false );
		}

		$target_bytes  = max( 1, (int) floor( $before_bytes * 0.6 ) );
		$target_tokens = max( 1, (int) floor( $before_tokens * 0.6 ) );
		$reduced       = ConversationTrimmer::trim_to_budget( $this->history, $target_bytes, $target_tokens );
		$after_bytes   = ConversationTrimmer::estimate_total_bytes( $reduced );
		$after_tokens  = ConversationTrimmer::estimate_total_tokens( $reduced );

		if ( $after_bytes >= $before_bytes ) {
			return $this->with_payload_recovery_metadata( $result, $before_bytes, $before_tokens, false, false );
		}

		$this->history       = $reduced;
		$this->message_log[] = array(
			'type'               => 'provider_payload_recovery',
			'message'            => __( 'The provider rejected the request size. Retrying once with reduced conversation history.', 'superdav-ai-agent' ),
			'status_code'        => 413,
			'request_size_class' => ProviderTraceLogger::classify_request_size( $before_bytes ),
			'fallback_attempted' => true,
			'payload_reduced'    => true,
			'sequence'           => $this->next_activity_sequence(),
		);
		$this->fire_progress();

		$this->provider_retry_baseline_envelope_bytes = $complete_envelope_bytes;

		try {
			$retry_result = $this->send_prompt( $provider_id, $model_id );
		} finally {
			$this->provider_retry_baseline_envelope_bytes = 0;
		}
		if ( is_wp_error( $retry_result ) ) {
			if ( $this->is_payload_limit_error( $retry_result ) && ! $this->is_local_payload_limit_error( $retry_result ) ) {
				$retry_error_data      = $retry_result->get_error_data();
				$retry_envelope_bytes  = is_array( $retry_error_data ) && 'complete_envelope' === ( $retry_error_data['request_size_source'] ?? '' )
					? max( 0, (int) ( $retry_error_data['request_bytes'] ?? 0 ) )
					: 0;
				$retry_envelope_tokens = is_array( $retry_error_data ) && 'complete_envelope' === ( $retry_error_data['request_size_source'] ?? '' )
					? max( 0, (int) ( $retry_error_data['request_tokens_estimate'] ?? 0 ) )
					: 0;
				ProviderTraceLogger::record_payload_limit(
					$provider_id,
					$model_id,
					413,
					$retry_envelope_bytes > 0 ? $retry_envelope_bytes : $after_bytes,
					$retry_envelope_tokens > 0 ? $retry_envelope_tokens : $after_tokens,
					$byte_budget,
					false,
					true,
					$retry_envelope_bytes <= 0,
					'reduced_retry_exhausted'
				);
			}

			return $this->with_payload_recovery_metadata( $retry_result, $after_bytes, $after_tokens, true, true );
		}

		return $retry_result;
	}

	/** Whether an error represents a local or upstream HTTP 413. */
	private function is_payload_limit_error( WP_Error $error ): bool {
		if ( in_array( $error->get_error_code(), array( 'sd_ai_agent_provider_payload_too_large', 'sd_ai_agent_provider_payload_budget_exceeded' ), true ) ) {
			return true;
		}

		$data = $error->get_error_data();
		return is_array( $data ) && 413 === (int) ( $data['status_code'] ?? 0 );
	}

	/** Whether a payload-limit error was produced by the local request guard. */
	private function is_local_payload_limit_error( WP_Error $error ): bool {
		if ( 'sd_ai_agent_provider_payload_budget_exceeded' === $error->get_error_code() ) {
			return true;
		}

		$data = $error->get_error_data();
		return is_array( $data ) && ! empty( $data['local_rejection'] );
	}

	/**
	 * Attach safe fallback diagnostics without replacing recoverable history data.
	 *
	 * @param WP_Error $error             Payload error.
	 * @param int      $request_bytes     Estimated history bytes sent most recently.
	 * @param int      $request_tokens    Estimated history tokens sent most recently.
	 * @param bool     $reduced           Whether the outgoing history was reduced.
	 * @param bool     $fallback_attempted Whether a reduced fallback was attempted.
	 */
	private function with_payload_recovery_metadata( WP_Error $error, int $request_bytes, int $request_tokens, bool $reduced, bool $fallback_attempted ): WP_Error {
		$error_data                      = $error->get_error_data();
		$data                            = is_array( $error_data ) ? $error_data : array();
		$data['request_bytes_estimate']  = $request_bytes;
		$data['request_tokens_estimate'] = $request_tokens;
		$data['fallback_attempted']      = $fallback_attempted;
		$data['payload_reduced']         = $reduced;
		if ( $this->is_local_payload_limit_error( $error ) && $this->session_id > 0 ) {
			$data['recovery'] = array(
				'action'            => 'compact_session',
				'source_session_id' => $this->session_id,
			);
		}
		$error->add_data( $data );

		return $error;
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
	protected function send_prompt( string $provider_id, string $model_id ): GenerativeAiResult|WP_Error {
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

		// Resolve abilities once here so they are available for both the prompt
		// builder's using_abilities() call and the system-instruction rebuild.
		// When native Responses tool search is active this may include the full
		// visible catalog, while the system prompt still uses the compact Tier-1
		// names so it does not re-inline every searchable tool in prose.
		$abilities = $this->resolve_abilities();

		// Rebuild the system instruction unless the caller pinned a static
		// override. This lets the manifest's "recently fetched ability
		// schemas" block reach the model on subsequent turns.
		// Pass active ability names so the cadence section is injected
		// when content-generation or theme-modification tools are in scope.
		if ( ! $this->system_instruction_locked ) {
			$ability_names            = $this->system_prompt_ability_names( $abilities );
			$this->system_instruction = $this->instruction_builder->build( $this->settings_for_prompt, $ability_names );
		}
		$builder->using_system_instruction( $this->system_instruction );
		$this->configure_model( $builder, $provider_id, $model_id );

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
		// Use the same validated effective model for configuration, request
		// budgets, temperature handling, and safe trace attribution.
		if ( ! self::model_omits_temperature( $model_id ) ) {
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

		$started_at               = microtime( true );
		$last_error               = null;
		$last_retry_after_seconds = null;
		$backoff_delays           = array();
		$request_envelope         = array();
		$status_code              = 0;

		for ( $attempt = 1; $attempt <= $this->provider_retry_max_attempts; ++$attempt ) {
			$request_envelope = array();
			ProviderTraceLogger::set_runtime_context(
				$provider_id,
				$model_id,
				$this->session_id,
				$this->provider_retry_baseline_envelope_bytes
			);
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
			} finally {
				$request_envelope = ProviderTraceLogger::get_runtime_envelope_metrics();
				ProviderTraceLogger::clear_runtime_context();
			}

			if ( $last_error instanceof WP_Error && ! empty( $request_envelope ) ) {
				$last_error = $this->with_request_envelope_metadata( $last_error, $request_envelope );
			}

			$status_code = $this->extract_provider_error_status( $last_error );
			if ( ! $this->is_retryable_provider_error( $last_error, $status_code ) ) {
				return $this->provider_error_to_wp_error( $last_error, $status_code, $provider_id );
			}

			if ( $attempt >= $this->provider_retry_max_attempts ) {
				break;
			}

			$delay                    = $this->get_provider_retry_delay( $attempt, $last_error );
			$last_retry_after_seconds = $this->extract_retry_after_seconds( $last_error );
			$backoff_delays[]         = $delay;
			$this->log_provider_retry_progress( $status_code, $attempt + 1, $delay );
			$this->wait_for_provider_retry( $delay );
		}

		$elapsed_seconds = max( 0, (int) round( microtime( true ) - $started_at ) );
		ProviderTraceLogger::record_retry_exhausted_failure(
			$provider_id,
			$model_id,
			$last_error,
			$status_code,
			$this->provider_retry_max_attempts,
			(int) round( ( microtime( true ) - $started_at ) * 1000 ),
			$last_retry_after_seconds,
			$backoff_delays,
			$this->get_provider_trace_phase(),
			$this->session_id,
			$this->active_job_id,
			$request_envelope
		);
		return $this->build_provider_retry_failed_error( $last_error, $elapsed_seconds );
	}

	/** Return the bounded provider invocation phase stored in terminal traces. */
	private function get_provider_trace_phase(): string {
		if ( 'client_tool_resume' === $this->provider_trace_phase ) {
			return 'client_tool_resume';
		}

		return 'provider_followup_call' === $this->last_loop_phase
			? 'provider_followup_call'
			: 'initial_provider_call';
	}

	/**
	 * Copy full-envelope transport measurements onto a provider error.
	 *
	 * @param WP_Error                  $error   Provider error receiving the scalar measurements.
	 * @param array<string, int|string> $metrics Prompt-free scalar measurements.
	 */
	private function with_request_envelope_metadata( WP_Error $error, array $metrics ): WP_Error {
		$error_data = $error->get_error_data();
		$data       = is_array( $error_data ) ? $error_data : array();

		$safe_keys = array(
			'request_bytes',
			'request_tokens_estimate',
			'request_provider_limit_bytes',
			'request_budget_bytes',
			'request_safety_margin_bytes',
			'request_size_class',
			'request_size_source',
		);
		foreach ( $safe_keys as $key ) {
			if ( isset( $metrics[ $key ] ) && is_scalar( $metrics[ $key ] ) ) {
				$data[ $key ] = $metrics[ $key ];
			}
		}

		$error->add_data( $data );

		return $error;
	}

	/**
	 * Determine whether a provider failure is retryable.
	 *
	 * @param WP_Error|\Throwable|null $error       Last provider error.
	 * @param int                      $status_code HTTP status code, or 0 when unknown.
	 */
	private function is_retryable_provider_error( $error, int $status_code ): bool {
		return ProviderErrorClassifier::is_retryable( $error, $status_code );
	}

	/**
	 * Extract an HTTP status code from provider errors produced by SDK layers.
	 *
	 * @param WP_Error|\Throwable|null $error Last provider error.
	 */
	private function extract_provider_error_status( $error ): int {
		return ProviderErrorClassifier::extract_status_code( $error );
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
		$delay = (int) ( $this->provider_retry_delays[ $index ] ?? 60 );
		if ( ! $this->provider_retry_jitter || $delay <= 0 || $delay >= 60 ) {
			return $delay;
		}

		// Spread managed retries by up to 25% without retrying before the base
		// delay or exceeding the existing 60-second per-delay ceiling. Integer
		// scheduling leaves the first two short delays exact and jitters later
		// attempts where synchronized retries would have the greatest impact.
		$jitter_max = (int) floor( $delay / 4 );
		return min( 60, $delay + ( $jitter_max > 0 ? wp_rand( 0, $jitter_max ) : 0 ) );
	}

	/**
	 * Wait between transient provider attempts while keeping the active job alive.
	 *
	 * The managed-provider retry window can now include a 16-second delay. A
	 * one-second heartbeat preserves the post-client-result job/checkpoint if a
	 * stale-job monitor runs while that accepted continuation is backing off.
	 *
	 * @param int $delay Delay in seconds, already bounded by retry configuration.
	 */
	private function wait_for_provider_retry( int $delay ): void {
		for ( $remaining = max( 0, $delay ); $remaining > 0; --$remaining ) {
			if ( '' !== $this->active_job_id ) {
				ActiveJobRepository::heartbeat( $this->active_job_id );
			}
			sleep( 1 );
		}
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
		if ( '' !== $this->active_job_id ) {
			ActiveJobRepository::heartbeat( $this->active_job_id );
		}
		$this->fire_progress();
	}

	/**
	 * Convert a non-retryable provider failure into a WP_Error without retry noise.
	 *
	 * @param WP_Error|\Throwable|null $error       Last provider error.
	 * @param int                      $status_code HTTP status code, or 0 when unknown.
	 * @param string                   $provider_id Runtime-selected provider ID.
	 */
	private function provider_error_to_wp_error( $error, int $status_code, string $provider_id = '' ): WP_Error {
		if ( 413 === $status_code ) {
			$data = array( 'status_code' => $status_code );
			if ( '' !== $provider_id ) {
				$data['provider_id'] = $provider_id;
			}
			$is_local = false;
			if ( $error instanceof WP_Error ) {
				$source_data = $error->get_error_data();
				$is_local    = 'sd_ai_agent_provider_payload_budget_exceeded' === $error->get_error_code()
					|| ( is_array( $source_data ) && ! empty( $source_data['local_rejection'] ) );

				if ( is_array( $source_data ) ) {
					$safe_keys = array(
						'provider_id',
						'model_id',
						'request_bytes',
						'request_tokens_estimate',
						'request_provider_limit_bytes',
						'request_budget_bytes',
						'request_safety_margin_bytes',
						'request_size_class',
						'request_size_source',
						'local_rejection',
						'recovery_outcome',
					);
					foreach ( $safe_keys as $safe_key ) {
						if ( isset( $source_data[ $safe_key ] ) && is_scalar( $source_data[ $safe_key ] ) ) {
							$data[ $safe_key ] = $source_data[ $safe_key ];
						}
					}
				}
			}

			return new WP_Error(
				$is_local ? 'sd_ai_agent_provider_payload_budget_exceeded' : 'sd_ai_agent_provider_payload_too_large',
				$is_local
					? __( 'This request is too large to send safely. Compact the conversation or shorten the latest message and remove large attachments before retrying.', 'superdav-ai-agent' )
					: __( 'The selected AI provider or intermediary rejected this request because it exceeds its payload limit. Start a new chat and send a smaller request. If you attached files, remove or reduce them before retrying.', 'superdav-ai-agent' ),
				$data
			);
		}

		if ( $error instanceof WP_Error ) {
			// SDK layers sometimes expose the status only in their exception
			// message. Preserve the already-classified scalar so downstream safe
			// diagnostics can distinguish managed account actions (such as 402)
			// without retaining that message.
			$error_data = $error->get_error_data();
			$error_data = is_array( $error_data ) ? $error_data : array();
			if ( $status_code > 0 && empty( $error_data['status_code'] ) ) {
				$error_data['status_code'] = $status_code;
			}
			if ( '' !== $provider_id && empty( $error_data['provider_id'] ) ) {
				$error_data['provider_id'] = $provider_id;
			}
			$error->add_data( $error_data );

			return $error;
		}

		$message = $this->get_provider_error_message( $error );
		if ( '' === $message ) {
			$message = __( 'AI provider request failed.', 'superdav-ai-agent' );
		}

		$error_data = array( 'status_code' => $status_code );
		if ( '' !== $provider_id ) {
			$error_data['provider_id'] = $provider_id;
		}

		return new WP_Error(
			'sd_ai_agent_provider_error',
			$message,
			$error_data
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
			/* translators: 1: attempts, 2: elapsed seconds */
			__( 'The AI service is temporarily unavailable after %1$d attempts over %2$ds. Please try again shortly.', 'superdav-ai-agent' ),
			$this->provider_retry_max_attempts,
			$elapsed_seconds
		);

		$hint = $this->get_provider_retry_failure_hint();
		if ( '' !== $hint ) {
			$message .= ' ' . $hint;
		}

		if ( $this->session_id > 0 ) {
			$paused_state = array(
				'history'                 => $this->serialize_history(),
				'tool_call_log'           => $this->tool_call_log,
				'message_log'             => $this->message_log,
				'token_usage'             => $this->token_usage,
				'model_id'                => $this->model_id,
				'provider_id'             => $this->provider_id,
				'client_abilities'        => $this->client_abilities,
				'agent_slug'              => $this->agent_slug,
				'page_context'            => $this->checkpoint_page_context(),
				'iterations_remaining'    => max( 1, (int) $this->max_iterations - $this->iterations_used ),
				'exit_reason'             => 'provider_retry_failed',
				'mutation_policy_context' => $this->mutation_policy_context,
			);

			Database::save_paused_state(
				$this->session_id,
				$paused_state
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
	 * Return provider-specific retry exhaustion guidance.
	 */
	private function get_provider_retry_failure_hint(): string {
		if ( SuperdavAiProvider::PROVIDER_ID !== $this->resolve_provider_id() ) {
			return '';
		}

		return __( 'The managed Superdav service could not be reached from this site. Check outbound HTTPS/network access from the host, try again shortly, or switch provider/model if it continues.', 'superdav-ai-agent' );
	}

	/**
	 * Configure the PromptBuilder with the correct provider and model.
	 *
	 * Uses the builder's own provider/preference API so that the SDK
	 * handles model creation and dependency injection (auth, transporter)
	 * through ProviderRegistry::getProviderModel(). This avoids creating
	 * model instances outside the registry which can miss auth binding.
	 *
	 * The provider and effective model are resolved once by
	 * {@see send_prompt_with_payload_recovery()} so model-specific budgets and
	 * trace attribution cannot diverge from the model configured on the builder.
	 *
	 * @param \WP_AI_Client_Prompt_Builder $builder     The prompt builder.
	 * @param string                       $provider_id Runtime-selected provider ID.
	 * @param string                       $model_id    Validated effective model ID.
	 */
	private function configure_model( $builder, string $provider_id, string $model_id ): void {
		if ( empty( $provider_id ) ) {
			// No provider available — send_prompt() will have already
			// returned a WP_Error, so this is a defensive no-op.
			return;
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
	 * Resolve one validated effective model ID for a provider invocation.
	 *
	 * The connector default is accepted only when it is currently advertised,
	 * matching the existing stale-default protection in model configuration.
	 *
	 * @param string $provider_id Runtime-selected provider ID.
	 */
	private function resolve_effective_model_id( string $provider_id ): string {
		$model_id = trim( (string) $this->model_id );
		if ( '' !== $model_id ) {
			return $model_id;
		}

		if ( ! function_exists( 'OpenAiCompatibleConnector\\get_default_model' ) ) {
			return '';
		}

		$candidate = trim( (string) \OpenAiCompatibleConnector\get_default_model() );
		if ( '' === $candidate || ! Settings::is_model_advertised( $provider_id, $candidate ) ) {
			return '';
		}

		return $candidate;
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
	 * Whether the prompt history currently ends with an assistant/model turn.
	 *
	 * Provider SDKs require a resumed request to continue from a user/tool result
	 * boundary. If a crashed checkpoint or stale paused state ends with a plain
	 * model response, sending it again produces provider validation errors such as
	 * "The last message must be from a user role, not from model". Surface a
	 * recoverable local error instead so the browser can keep the saved history and
	 * the user can send a fresh continuation message.
	 */
	private function history_ends_with_model_message(): bool {
		$last = end( $this->history );
		reset( $this->history );

		if ( ! $last instanceof Message ) {
			return false;
		}

		return in_array( $this->message_role_string( $last ), array( 'model', 'assistant' ), true );
	}

	/**
	 * Return a stable role string for SDK message objects.
	 *
	 * @param Message $message Message to inspect.
	 */
	private function message_role_string( Message $message ): string {
		try {
			$data = $message->toArray();
			$role = $data['role'] ?? '';

			if ( is_scalar( $role ) ) {
				return (string) $role;
			}

			if ( $role instanceof \BackedEnum ) {
				return (string) $role->value;
			}

			if ( is_object( $role ) && method_exists( $role, 'getValue' ) ) {
				return (string) $role->getValue();
			}

			return '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Attach resumable conversation context to a loop error.
	 *
	 * Job/session persistence layers use this data to keep the user's failed turn
	 * and the latest safe history instead of dropping the prompt when the provider
	 * or SDK rejects a request.
	 *
	 * @param WP_Error $error           Error returned from a provider/loop boundary.
	 * @param array    $recovery_history Optional pre-trim history to serialize for recovery.
	 *
	 * @phpstan-param array<int|string, Message>|null $recovery_history
	 */
	private function with_error_recovery_data( WP_Error $error, ?array $recovery_history = null ): WP_Error {
		$error_data = $error->get_error_data();
		$data       = is_array( $error_data ) ? $error_data : array();
		$history    = $recovery_history ?? $this->history;

		$defaults = array(
			'tool_calls'              => $this->tool_call_log,
			'token_usage'             => $this->token_usage,
			'iterations_used'         => $this->iterations_used,
			'model_id'                => $this->model_id,
			'provider_id'             => $this->provider_id,
			'history'                 => ConversationSerializer::serialize( $history ),
			'client_abilities'        => $this->client_abilities,
			'recoverable'             => true,
			'mutation_policy_context' => $this->mutation_policy_context,
		);

		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$data[ $key ] = $value;
			}
		}

		$data = $this->with_result_logs( $data );
		$error->add_data( $data );

		return $error;
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
	 * Convert raw XML-ish tool-call text into an executable ability-call turn.
	 *
	 * Some OpenAI-compatible models trained on XML tool-use traces emit strings
	 * such as `<tool_call>wpab__sd-ai-agent__get-themes</tool_call>` in assistant
	 * content instead of using the provider's structured tool-call channel. Catch
	 * that before the text can become the user-visible final reply.
	 *
	 * @param Message $message Assistant message returned by the model.
	 * @return Message|string|null Synthetic tool-call message, corrective prompt, or null.
	 */
	private function intercept_text_tool_call( Message $message ): Message|string|null {
		if ( $this->message_has_function_calls( $message ) ) {
			return null;
		}

		$text = $this->message_text( $message );
		if ( '' === trim( $text ) ) {
			return null;
		}

		$raw_tool_name = $this->extract_text_tool_call_name( $text );
		if ( null === $raw_tool_name ) {
			return null;
		}

		$ability_name = $this->resolve_text_tool_call_ability_name( $raw_tool_name );
		if ( null === $ability_name ) {
			return sprintf(
				/* translators: %s: invalid tool name emitted as text. */
				__( 'Tool "%s" is not invokable as assistant text. Do not write XML-like tool calls in the reply. Use `sd-ai-agent/ability-search` to find a valid ability, then call `sd-ai-agent/ability-call` with the ability name and arguments.', 'superdav-ai-agent' ),
				$raw_tool_name
			);
		}

		return new ModelMessage(
			array(
				new MessagePart(
					new FunctionCall(
						'text_tool_call_' . substr( md5( $raw_tool_name ), 0, 12 ),
						'wpab__sd-ai-agent__ability-call',
						array(
							'ability'   => $ability_name,
							'arguments' => array(),
						)
					)
				),
			)
		);
	}

	/**
	 * Concatenate text parts from an assistant message.
	 *
	 * @param Message $message Assistant message returned by the model.
	 * @return string Text content.
	 */
	private function message_text( Message $message ): string {
		$text = '';

		foreach ( $message->getParts() as $part ) {
			$part_text = $this->visible_content_text( $part );
			if ( '' !== $part_text ) {
				$text .= $part_text . "\n";
			}
		}

		return trim( $text );
	}

	/**
	 * Return user-visible content-channel text for a message part.
	 *
	 * Thought-channel text is preserved in history for provider round-trips, but
	 * it must never be treated as assistant preamble, XML tool-call text, or final
	 * display text.
	 *
	 * @param MessagePart $part Message part to inspect.
	 */
	private function visible_content_text( MessagePart $part ): string {
		$text = $part->getText();
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return '';
		}

		$channel = method_exists( $part, 'getChannel' ) ? $part->getChannel() : null;
		if ( is_object( $channel ) && is_callable( array( $channel, 'isContent' ) ) ) {
			return (bool) $channel->isContent() ? $text : '';
		}

		if ( is_object( $channel ) && isset( $channel->value ) ) {
			return 'content' === strtolower( (string) $channel->value ) ? $text : '';
		}

		if ( is_string( $channel ) ) {
			return ( '' === $channel || 'content' === strtolower( $channel ) ) ? $text : '';
		}

		return $text;
	}

	/**
	 * Extract the first XML-ish tool-call name from assistant text.
	 *
	 * @param string $text Assistant text.
	 * @return string|null Raw tool/function name.
	 */
	private function extract_text_tool_call_name( string $text ): ?string {
		$patterns = array(
			'/<tool_call>\s*([^\s<>]+)\s*<\/tool_call>/i',
			'/<tool>\s*([^\s<>]+)\s*<\/tool>/i',
			'/<function_call\s+name=["\']([^"\'<>]+)["\']\s*\/?\s*>/i',
		);

		foreach ( $patterns as $pattern ) {
			$matches = array();
			if ( preg_match( $pattern, $text, $matches ) ) {
				return trim( (string) $matches[1] );
			}
		}

		return null;
	}

	/**
	 * Resolve a raw function/tool name from text into a registered ability name.
	 *
	 * @param string $raw_tool_name Raw name from assistant text.
	 * @return string|null Registered ability name, or null when unknown.
	 */
	private function resolve_text_tool_call_ability_name( string $raw_tool_name ): ?string {
		$ability_name = $raw_tool_name;

		if ( str_starts_with( $ability_name, 'wpab__' ) && class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$ability_name = \WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name( $ability_name );
		} elseif ( str_starts_with( $ability_name, 'wpab__sd-ai-agent__' ) ) {
			$ability_name = 'sd-ai-agent/' . substr( $ability_name, strlen( 'wpab__sd-ai-agent__' ) );
		}

		if ( ! str_contains( $ability_name, '/' ) ) {
			return null;
		}

		return AbilityRegistry::get( $ability_name ) instanceof \WP_Ability ? $ability_name : null;
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
	 * Normalise a mixed list of ability names to unique non-empty strings.
	 *
	 * @param mixed $raw Raw ability-name list.
	 * @return list<string> Unique ability names.
	 */
	private function normalize_ability_names( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$names = array();
		foreach ( $raw as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$name = trim( $name );
			if ( '' !== $name ) {
				$names[ $name ] = true;
			}
		}

		return array_keys( $names );
	}

	/**
	 * Extract one-turn approved ability names from pending confirmation tools.
	 *
	 * @param list<array<string, mixed>> $pending_tools Pending confirmation tool data.
	 * @return list<string> Ability names approved if the user confirms this pause.
	 */
	private function extract_pending_ability_names( array $pending_tools ): array {
		$names = array();

		foreach ( $pending_tools as $tool ) {
			$ability_name = '';
			if ( isset( $tool['ability'] ) && is_string( $tool['ability'] ) ) {
				$ability_name = $tool['ability'];
			} elseif ( isset( $tool['name'] ) && is_string( $tool['name'] ) ) {
				$ability_name = $tool['name'];
				if ( str_starts_with( $ability_name, 'wpab__' ) && class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
					$ability_name = \WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name( $ability_name );
				}
			}

			$ability_name = trim( $ability_name );
			if ( '' !== $ability_name ) {
				$names[ $ability_name ] = true;
			}
		}

		return array_keys( $names );
	}

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
		if ( $this->durable_plan_mode ) {
			return array();
		}

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		// Explicit per-instance override (e.g. from tests or CLI --abilities).
		// When set, bypass the auto-discovery layer and return exactly what was asked for.
		if ( ! empty( $this->abilities ) ) {
			$resolved = array();
			foreach ( array_merge( $this->abilities, $this->approved_once_abilities ) as $name ) {
				if ( ! ToolDiscovery::anonymous_mode_allows( $name ) ) {
					continue;
				}
				$ability = AbilityRegistry::get( $name );
				if ( $ability instanceof \WP_Ability ) {
					$resolved[] = $ability;
				}
			}
			// Append client ability stubs even in explicit-abilities mode.
			return $this->deduplicate_by_function_name(
				array_merge( $resolved, $this->client_router->build_stubs() )
			);
		}

		if ( $this->should_use_native_tool_search() ) {
			return $this->deduplicate_by_function_name(
				array_merge(
					ToolDiscovery::visible_ai_chat_abilities(),
					$this->client_router->build_stubs()
				)
			);
		}

		$tier_1 = ToolDiscovery::tier_1_for_run( $this->agent_tier_1_tools );

		$role_allowed = ToolDiscovery::is_anonymous_ability_mode() ? null : RolePermissions::get_allowed_abilities_for_current_user();
		$perms        = $this->tool_permissions;

		$resolved = array();
		foreach ( array_merge( $tier_1, $this->approved_once_abilities ) as $name ) {
			if ( ! ToolDiscovery::anonymous_mode_allows( $name ) ) {
				continue;
			}
			if ( null !== $role_allowed && ! in_array( $name, $role_allowed, true ) ) {
				continue;
			}
			if ( 'disabled' === ( $perms[ $name ] ?? 'auto' ) ) {
				continue;
			}
			$ability = AbilityRegistry::get( $name );
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
	 * Return compact ability names for the system prompt.
	 *
	 * Native OpenAI tool search receives the full visible catalog as deferred
	 * function tools. Keeping the prose prompt on Tier 1 preserves the existing
	 * ability-search guidance and avoids paying for a duplicate direct-tool list.
	 *
	 * @param \WP_Ability[] $resolved_abilities Abilities passed to the provider.
	 * @return list<string>
	 */
	private function system_prompt_ability_names( array $resolved_abilities ): array {
		if ( ! $this->should_use_native_tool_search() ) {
			return array_values(
				array_map(
					static fn( \WP_Ability $a ): string => $a->get_name(),
					$resolved_abilities
				)
			);
		}

		$names = array_merge( ToolDiscovery::tier_1_for_run( $this->agent_tier_1_tools ), $this->approved_once_abilities );
		return array_values(
			array_unique(
				array_filter(
					$names,
					static fn( string $name ): bool => '' !== $name
				)
			)
		);
	}

	/** Whether the active Superdav provider/model should use Responses tool search. */
	private function should_use_native_tool_search(): bool {
		$model_id = (string) $this->model_id;
		if ( '' === $model_id && function_exists( 'OpenAiCompatibleConnector\\get_default_model' ) ) {
			$model_id = (string) \OpenAiCompatibleConnector\get_default_model();
		}

		return SuperdavAiProvider::responses_tool_search_enabled( $this->resolve_provider_id(), $model_id );
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

	/**
	 * Execute server-side abilities for one model tool-call message.
	 *
	 * Kept behind a protected seam so deterministic loop tests can exercise
	 * mixed PHP/client confirmation without a live provider or SDK resolver.
	 *
	 * @param Message $message Model tool-call message.
	 * @return Message Tool-response message.
	 */
	protected function execute_abilities( Message $message ): Message {
		return $this->get_ability_resolver()->execute_abilities( $message );
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
	 * Enforce the Setup Assistant's per-run media acquisition budget.
	 *
	 * Prompt instructions remain useful guidance, but providers can emit several
	 * parallel image calls in one turn or retry after a failed generation. Count
	 * admitted calls in durable history so resumed browser-tool loops preserve the
	 * same budget, and remove excess calls before they can execute.
	 *
	 * @param Message $message Assistant message returned by the provider.
	 * @return array{message:Message,guidance:string,removed:array<string,int>}
	 */
	private function enforce_onboarding_media_budget( Message $message ): array {
		$unchanged = array(
			'message'  => $message,
			'guidance' => '',
			'removed'  => array(),
		);

		if ( 'onboarding' !== $this->agent_slug ) {
			return $unchanged;
		}

		$limits = array(
			'sd-ai-agent/stock-image'    => 2,
			'sd-ai-agent/generate-image' => 1,
		);
		$used   = array_fill_keys( array_keys( $limits ), 0 );

		foreach ( $this->history as $history_message ) {
			foreach ( $history_message->getParts() as $part ) {
				$call = $part->getFunctionCall();
				if ( ! $call ) {
					continue;
				}

				$ability_name = self::media_ability_name_for_call( $call );
				if ( null !== $ability_name ) {
					++$used[ $ability_name ];
				}
			}
		}

		$kept    = array();
		$removed = array_fill_keys( array_keys( $limits ), 0 );
		foreach ( $message->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( ! $call ) {
				$kept[] = $part;
				continue;
			}

			$ability_name = self::media_ability_name_for_call( $call );
			if ( null === $ability_name ) {
				$kept[] = $part;
				continue;
			}

			if ( $used[ $ability_name ] >= $limits[ $ability_name ] ) {
				++$removed[ $ability_name ];
				continue;
			}

			++$used[ $ability_name ];
			$kept[] = $part;
		}

		$removed = array_filter( $removed );
		if ( empty( $removed ) ) {
			return $unchanged;
		}
		if ( empty( $kept ) ) {
			$kept[] = new MessagePart( __( 'Media acquisition call blocked by the Setup Assistant budget.', 'superdav-ai-agent' ) );
		}

		$removed_count       = array_sum( $removed );
		$this->message_log[] = array(
			'type'     => 'guardrail',
			'reason'   => 'onboarding_media_budget_guarded',
			'count'    => $removed_count,
			'sequence' => $this->next_activity_sequence(),
		);
		AgentEventLog::log(
			'onboarding_media_budget_guarded',
			AgentEventLog::SEVERITY_WARNING,
			array(
				'session_id'       => $this->session_id,
				'stock_removed'    => $removed['sd-ai-agent/stock-image'] ?? 0,
				'generate_removed' => $removed['sd-ai-agent/generate-image'] ?? 0,
				'model_id'         => (string) $this->model_id,
				'provider_id'      => (string) $this->provider_id,
			)
		);

		$exhausted_abilities = array();
		$available_abilities = array();
		foreach ( $limits as $ability_name => $limit ) {
			if ( $used[ $ability_name ] >= $limit ) {
				$exhausted_abilities[] = sprintf(
					/* translators: 1: media ability name, 2: total permitted calls. */
					__( '%1$s (%2$d calls total)', 'superdav-ai-agent' ),
					str_replace( 'sd-ai-agent/', '', $ability_name ),
					$limit
				);
			} else {
				$available_abilities[] = sprintf(
					/* translators: 1: media ability name, 2: number of calls remaining. */
					__( '%1$s (%2$d calls remaining)', 'superdav-ai-agent' ),
					str_replace( 'sd-ai-agent/', '', $ability_name ),
					$limit - $used[ $ability_name ]
				);
			}
		}

		$guidance             = sprintf(
			/* translators: %s: comma-separated list of exhausted media abilities. */
			__( 'The Setup Assistant media budget is exhausted for: %s.', 'superdav-ai-agent' ),
			implode( ', ', $exhausted_abilities )
		);
		if ( ! empty( $available_abilities ) ) {
			$guidance .= ' ' . sprintf(
				/* translators: %s: comma-separated list of remaining media abilities. */
				__( 'You may still use: %s. Do not use URL-fetch or upload fallbacks.', 'superdav-ai-agent' ),
				implode( ', ', $available_abilities )
			);
		} else {
			$guidance .= ' ' . __( 'Continue without more image sourcing and do not use URL-fetch or upload fallbacks. If required primary media is unavailable, report that blocker and do not publish a weak text-only homepage.', 'superdav-ai-agent' );
		}

		return array(
			'message'  => new ModelMessage( $kept ),
			'guidance' => $guidance,
			'removed'  => $removed,
		);
	}

	/** Return the bounded media ability represented by a direct or dispatcher call. */
	private static function media_ability_name_for_call( FunctionCall $call ): ?string {
		$tool_name = self::normalize_logged_tool_name( (string) $call->getName() );
		if ( in_array( $tool_name, array( 'sd-ai-agent/stock-image', 'sd-ai-agent/generate-image' ), true ) ) {
			return $tool_name;
		}

		if ( 'sd-ai-agent/ability-call' !== $tool_name ) {
			return null;
		}

		$args         = self::normalize_function_call_args( $call->getArgs() );
		$ability_name = (string) ( $args['ability'] ?? '' );
		return in_array( $ability_name, array( 'sd-ai-agent/stock-image', 'sd-ai-agent/generate-image' ), true )
			? $ability_name
			: null;
	}

	/**
	 * Build a synthetic tool response for empty update-global-styles calls.
	 *
	 * The global-styles ability cannot infer a design direction from
	 * `styles: []` / `settings: []`. Returning a guard response here prevents
	 * dispatching a known-empty mutation and gives the model a concrete recovery
	 * instruction instead of letting it repeat the same validation failure.
	 *
	 * @param Message $message Assistant message to inspect.
	 * @return Message|null Synthetic response when every tool call was guarded; null otherwise.
	 */
	private function build_empty_global_styles_guard_response( Message $message ): ?Message {
		$parts             = array();
		$function_calls    = 0;
		$guarded_responses = 0;

		foreach ( $message->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( ! $call ) {
				continue;
			}

			++$function_calls;

			$name = (string) $call->getName();
			if ( 'sd-ai-agent/update-global-styles' !== self::normalize_logged_tool_name( $name ) ) {
				continue;
			}

			$args = self::normalize_function_call_args( $call->getArgs() );
			if ( ! self::is_empty_global_styles_update_args( $args ) ) {
				continue;
			}

			++$guarded_responses;
			$payload = array(
				'success'           => false,
				'code'              => 'sd_ai_agent_empty_global_styles_update_guarded',
				'error'             => __( 'Empty global styles updates are not dispatched. Provide a non-empty theme.json styles or settings partial.', 'superdav-ai-agent' ),
				'example_arguments' => array(
					'styles' => array(
						'color'      => array(
							'background' => '<background hex or preset var>',
							'text'       => '<text hex or preset var>',
						),
						'typography' => array(
							'fontFamily' => '<system or bundled font stack>',
							'lineHeight' => '<line height>',
						),
						'elements'   => array(
							'button' => array(
								'color' => array(
									'background' => '<button background>',
									'text'       => '<button text>',
								),
							),
						),
					),
				),
				'nudge'             => 'Do not retry update-global-styles with empty or unchanged arguments. Build a concrete design partial from the chosen palette/typography, call get-theme-json first if needed, or skip the style update and report the blocker to the user.',
			);

			$encoded_payload = wp_json_encode( $payload );
			$parts[]         = new MessagePart(
				new FunctionResponse(
					(string) $call->getId(),
					$name,
					is_string( $encoded_payload ) ? $encoded_payload : '{}'
				)
			);
		}

		if ( 0 === $function_calls || $function_calls !== $guarded_responses ) {
			return null;
		}

		$this->message_log[] = array(
			'type'     => 'guardrail',
			'reason'   => 'empty_global_styles_update_guarded',
			'count'    => $guarded_responses,
			'sequence' => $this->next_activity_sequence(),
		);

		return new UserMessage( $parts );
	}

	/**
	 * Normalize function-call args to an array.
	 *
	 * @param mixed $args Raw function-call arguments.
	 * @return array<string,mixed>
	 */
	private static function normalize_function_call_args( $args ): array {
		if ( is_string( $args ) && '' !== $args ) {
			$decoded = json_decode( $args, true );
			return is_array( $decoded ) ? self::string_keyed_array( $decoded ) : array();
		}

		if ( is_array( $args ) ) {
			return self::string_keyed_array( $args );
		}

		return array();
	}

	/**
	 * Keep only string-keyed values from a decoded JSON object.
	 *
	 * @param array<mixed> $value Decoded function-call args.
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}

		return $result;
	}

	/**
	 * Whether update-global-styles received no meaningful style/settings data.
	 *
	 * @param array<string,mixed> $args Normalized function-call args.
	 */
	private static function is_empty_global_styles_update_args( array $args ): bool {
		$styles   = $args['styles'] ?? array();
		$settings = $args['settings'] ?? array();

		return empty( $styles ) && empty( $settings );
	}

	/**
	 * Inject recovery guidance after the empty global-styles guard fires.
	 */
	private function inject_empty_global_styles_update_guidance(): void {
		$this->history[] = new UserMessage(
			array(
				new MessagePart(
					__(
						'The previous update-global-styles call was blocked because both styles and settings were empty. Do not retry that call unchanged. Build a concrete non-empty theme.json styles partial from the selected design direction, call get-theme-json if you need existing presets, or skip the style update and tell the user exactly what blocked it before giving a final response.',
						'superdav-ai-agent'
					)
				),
			)
		);

		$this->fire_progress();
	}

	/**
	 * Detect a denied scaffold-block-theme response and build a terminal reply.
	 *
	 * Block-theme scaffolding is the prerequisite for later theme-writing steps.
	 * If permission is denied (including stale client-side permission state), the
	 * safe recovery is to stop the dependent chain and ask for a fresh grant
	 * instead of letting the model continue into invalid global-style writes.
	 *
	 * @param Message $message Tool response message to inspect.
	 * @return string|null Terminal recovery reply when scaffold permission was denied.
	 */
	private function extract_scaffold_block_theme_permission_denial( Message $message ): ?string {
		foreach ( $message->getParts() as $part ) {
			$response = $part->getFunctionResponse();
			if ( ! $response ) {
				continue;
			}

			$name = self::normalize_logged_tool_name( (string) $response->getName() );
			if ( 'sd-ai-agent/scaffold-block-theme' !== $name ) {
				continue;
			}

			$response_text = self::stringify_tool_response_for_guard( $response->getResponse() );
			if ( ! self::is_permission_denied_tool_response( $response_text ) ) {
				continue;
			}

			$this->message_log[] = array(
				'type'     => 'guardrail',
				'reason'   => 'scaffold_block_theme_permission_denied',
				'sequence' => $this->next_activity_sequence(),
			);

			$this->fire_progress();

			return __(
				'I could not scaffold the block theme because the scaffold-block-theme permission was denied or stale. I stopped the dependent theme-writing steps so I do not make invalid style or template changes. Please re-grant permission for sd-ai-agent/scaffold-block-theme and then retry the scaffold step.',
				'superdav-ai-agent'
			);
		}

		return null;
	}

	/**
	 * Track consecutive empty calls that were rejected for missing required input.
	 *
	 * Models sometimes rotate through unrelated abilities with empty argument
	 * objects. That bypasses the ordinary spin detector, which intentionally
	 * compares call names as well as arguments. Count only calls that were empty
	 * and whose matching response confirms a missing required property, then
	 * reset on any productive or different failure response.
	 *
	 * @param Message $call_message     Assistant message containing function calls.
	 * @param Message $response_message Matching function-response message.
	 * @return bool True when the terminal threshold has been reached.
	 */
	private function record_empty_required_input_failures( Message $call_message, Message $response_message ): bool {
		$empty_call_ids = array();
		foreach ( $call_message->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( ! $call || ! empty( self::normalize_function_call_args( $call->getArgs() ) ) ) {
				continue;
			}

			$call_id = (string) $call->getId();
			if ( '' !== $call_id ) {
				$empty_call_ids[ $call_id ] = true;
			}
		}

		if ( empty( $empty_call_ids ) ) {
			$this->consecutive_empty_tool_call_failures = 0;
			return false;
		}

		$empty_validation_failures = 0;
		$response_count            = 0;
		foreach ( $response_message->getParts() as $part ) {
			$response = $part->getFunctionResponse();
			if ( ! $response ) {
				continue;
			}

			++$response_count;
			$response_id = (string) $response->getId();
			if (
				isset( $empty_call_ids[ $response_id ] )
				&& self::is_empty_required_input_response( $response->getResponse() )
			) {
				++$empty_validation_failures;
			}
		}

		if ( 0 === $empty_validation_failures || $empty_validation_failures !== $response_count ) {
			$this->consecutive_empty_tool_call_failures = 0;
			return false;
		}

		$this->consecutive_empty_tool_call_failures += $empty_validation_failures;
		return $this->consecutive_empty_tool_call_failures >= self::MAX_CONSECUTIVE_EMPTY_TOOL_CALL_FAILURES;
	}

	/**
	 * Whether a tool response represents an empty required-input validation error.
	 *
	 * @param mixed $response Tool response payload.
	 * @return bool True for an ability_invalid_input response with missing fields.
	 */
	private static function is_empty_required_input_response( $response ): bool {
		if ( is_string( $response ) ) {
			$decoded  = json_decode( $response, true );
			$response = is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $response )
			&& 'ability_invalid_input' === ( $response['code'] ?? '' )
			&& ! empty( $response['missing_required_fields'] );
	}

	/**
	 * End a run that is cycling through empty required-input calls.
	 *
	 * @return array<string, mixed> Terminal result payload for the chat client.
	 */
	private function abort_on_empty_tool_call_storm(): array {
		$this->last_loop_phase = 'empty_tool_call_storm_aborted';
		$this->message_log[]   = array(
			'type'     => 'guardrail',
			'reason'   => 'empty_tool_call_storm',
			'count'    => $this->consecutive_empty_tool_call_failures,
			'sequence' => $this->next_activity_sequence(),
		);

		AgentEventLog::log(
			'agent_loop_aborted',
			AgentEventLog::SEVERITY_WARNING,
			array(
				'session_id'      => $this->session_id,
				'reason'          => 'empty_tool_call_storm',
				'failures'        => $this->consecutive_empty_tool_call_failures,
				'iterations_used' => $this->iterations_used,
				'iterations_max'  => (int) $this->max_iterations,
				'model_id'        => (string) $this->model_id,
				'provider_id'     => (string) $this->provider_id,
			)
		);

		return $this->with_result_logs(
			array(
				'reply'           => __( 'I stopped because repeated tool calls were missing required information, so I could not safely complete the requested change. Please provide the missing target or details and try again.', 'superdav-ai-agent' ),
				'history'         => $this->serialize_history(),
				'tool_calls'      => $this->tool_call_log,
				'token_usage'     => $this->token_usage,
				'iterations_used' => $this->iterations_used,
				'model_id'        => $this->model_id,
				'exit_reason'     => 'empty_tool_call_storm',
			)
		);
	}

	/**
	 * Convert a tool response payload into text for guard matching.
	 *
	 * @param mixed $response Tool response payload.
	 */
	private static function stringify_tool_response_for_guard( $response ): string {
		if ( is_string( $response ) ) {
			return $response;
		}

		$encoded = wp_json_encode( $response );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Whether a tool response represents a permission denial.
	 */
	private static function is_permission_denied_tool_response( string $response_text ): bool {
		$normalized = strtolower( $response_text );

		return str_contains( $normalized, 'does not have necessary permission' )
			|| str_contains( $normalized, 'permission denied' )
			|| str_contains( $normalized, 'not allowed' );
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
			$text = $this->visible_content_text( $part );
			if ( '' !== $text ) {
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

				$normalized_args = self::normalize_function_call_args( $call->getArgs() );
				$this->generated_theme_completion_gate->record_tool_call( $name, $normalized_args );
				$this->page_completion_gate->record_tool_call( $name, $normalized_args );
				$this->rendered_output_evidence_gate->record_tool_call( $name, $normalized_args );
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
			if ( '' !== $this->visible_content_text( $part ) ) {
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
				$name = $call->getName();
				if ( is_string( $name ) && '' !== $name ) {
					return $name;
				}
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
				$this->generated_theme_completion_gate->record_tool_response( $name, $response->getResponse() );
				$this->page_completion_gate->record_tool_response( $name, $response->getResponse() );
				$this->rendered_output_evidence_gate->record_tool_response( $name, $response->getResponse() );

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
	 * Inject the exact missing generated-theme evidence as a repair turn.
	 */
	private function inject_generated_theme_completion_guidance(): void {
		$guidance = $this->generated_theme_completion_gate->get_repair_guidance();
		if ( '' === $guidance ) {
			return;
		}

		$this->history[]     = new UserMessage( array( new MessagePart( $guidance ) ) );
		$this->message_log[] = array(
			'type'       => 'guardrail',
			'reason'     => 'generated_theme_completion_required',
			'completion' => $this->generated_theme_completion_gate->get_status(),
			'sequence'   => $this->next_activity_sequence(),
		);

		$this->last_loop_phase = 'generated_theme_completion_repair_required';
		$this->fire_progress();
	}

	/**
	 * Prevent a terminal reply from representing incomplete theme evidence as success.
	 *
	 * @param string $reply Current assistant reply.
	 */
	private function append_generated_theme_completion_notice( string $reply ): string {
		$notice = $this->generated_theme_completion_gate->get_terminal_notice();
		if ( '' === $notice ) {
			return $reply;
		}

		return $notice;
	}

	/**
	 * Pause for the exact server-owned page-quality browser call.
	 *
	 * The model never supplies preview paths, mutation tokens, or viewports.
	 * Keeping the synthetic function call in history preserves the ordinary
	 * client-tool result pairing and resume validation.
	 *
	 * @return array<string,mixed>
	 */
	private function pause_for_page_validation( int $iterations ): array {
		$args    = $this->page_completion_gate->get_expected_report_inputs();
		$call_id = 'page_quality_' . str_replace( '-', '', wp_generate_uuid4() );
		$message = new ModelMessage(
			array(
				new MessagePart(
					new FunctionCall(
						$call_id,
						'wpab__sd-ai-agent-js__validate-page-quality',
						$args
					)
				),
			)
		);
		$pending = array(
			array(
				'id'          => $call_id,
				'name'        => PageCompletionGate::CLIENT_ABILITY,
				'args'        => $args,
				'annotations' => array( 'readonly' => true ),
			),
		);

		$this->append_assistant_message_to_history( $message );
		$this->log_tool_calls( $message );
		if ( $this->session_id > 0 ) {
			Database::save_paused_state(
				$this->session_id,
				array(
					'history'                   => $this->serialize_history(),
					'tool_call_log'             => $this->tool_call_log,
					'message_log'               => $this->message_log,
					'token_usage'               => $this->token_usage,
					'mutation_policy_context'   => $this->mutation_policy_context,
					'iterations_remaining'      => $iterations,
					'model_id'                  => $this->model_id,
					'provider_id'               => $this->provider_id,
					'client_abilities'          => $this->client_abilities,
					'agent_slug'                => $this->agent_slug,
					'page_context'              => $this->checkpoint_page_context(),
					'pending_client_tool_calls' => $pending,
				)
			);
		}

		$this->last_loop_phase = 'page_quality_client_validation_pending';
		AgentEventLog::log(
			'client_tools_pending',
			AgentEventLog::SEVERITY_INFO,
			$this->build_loop_event_context(
				array(
					'client_tool_count'  => 1,
					'php_tool_count'     => 0,
					'paused_state_saved' => $this->session_id > 0,
					'reason'             => 'server_directed_page_quality',
				)
			)
		);

		return $this->with_paused_logs(
			array(
				'pending_client_tool_calls' => $pending,
				'history'                   => $this->serialize_history(),
				'tool_call_log'             => $this->tool_call_log,
				'token_usage'               => $this->token_usage,
				'iterations_remaining'      => $iterations,
				'iterations_used'           => $this->iterations_used,
				'model_id'                  => $this->model_id,
			)
		);
	}

	/** Publish every exact preview accepted by the current completion gate. */
	private function publish_approved_page_previews(): true|WP_Error {
		$targets = $this->page_completion_gate->get_preview_targets();
		if ( empty( $targets ) ) {
			return true;
		}

		foreach ( $targets as $target ) {
			$preflight = PagePreviewWorkspace::preflight_commit( $target );
			if ( is_wp_error( $preflight ) ) {
				$this->page_completion_gate->record_publish_failure( $preflight->get_error_message() );
				return $preflight;
			}
		}

		$published     = array();
		$publish_error = null;
		ChangeLogger::begin( $this->session_id, 'sd-ai-agent/publish-page-preview' );
		try {
			foreach ( $targets as $target ) {
				$result = PagePreviewWorkspace::commit( $target );
				if ( is_wp_error( $result ) ) {
					$publish_error = $result;
					break;
				}
				$published[] = $result;
			}
		} finally {
			ChangeLogger::end();
		}

		if ( ! empty( $published ) ) {
			$this->page_completion_gate->record_published_previews( $published );
		}
		if ( $publish_error instanceof WP_Error ) {
			$this->page_completion_gate->record_publish_failure( $publish_error->get_error_message() );
			return $publish_error;
		}
		$publish_call_id       = 'publish_page_preview_' . str_replace( '-', '', wp_generate_uuid4() );
		$this->tool_call_log[] = array(
			'type'     => 'call',
			'id'       => $publish_call_id,
			'name'     => 'sd-ai-agent/publish-page-preview',
			'args'     => array( 'post_ids' => array_values( array_map( static fn( array $target ): int => (int) $target['post_id'], $targets ) ) ),
			'source'   => 'server',
			'sequence' => $this->next_activity_sequence(),
		);
		$this->tool_call_log[] = array(
			'type'     => 'response',
			'id'       => $publish_call_id,
			'name'     => 'sd-ai-agent/publish-page-preview',
			'response' => array(
				'success'   => true,
				'published' => $published,
			),
			'source'   => 'server',
			'sequence' => $this->next_activity_sequence(),
		);
		$this->message_log[]   = array(
			'type'       => 'event',
			'reason'     => 'approved_page_preview_published',
			'post_ids'   => array_values( array_map( static fn( array $result ): int => (int) $result['post_id'], $published ) ),
			'completion' => $this->page_completion_gate->get_status(),
			'sequence'   => $this->next_activity_sequence(),
		);
		$this->last_loop_phase = 'approved_page_preview_published';
		$this->fire_progress();
		return true;
	}

	/** Inject the exact missing rendered-page evidence as a repair turn. */
	private function inject_page_completion_guidance(): void {
		$guidance = $this->page_completion_gate->get_repair_guidance();
		if ( '' === $guidance ) {
			return;
		}

		$this->history[]     = new UserMessage( array( new MessagePart( $guidance ) ) );
		$this->message_log[] = array(
			'type'       => 'guardrail',
			'reason'     => 'page_quality_completion_required',
			'completion' => $this->page_completion_gate->get_status(),
			'sequence'   => $this->next_activity_sequence(),
		);

		$this->last_loop_phase = 'page_quality_completion_repair_required';
		$this->fire_progress();
	}

	/** Prevent a terminal reply from representing incomplete page QA as success. */
	private function append_page_completion_notice( string $reply ): string {
		$notice = $this->page_completion_gate->get_terminal_notice();
		if ( '' !== $notice ) {
			$this->discard_unpublished_page_previews();
		}
		return '' === $notice ? $reply : $notice;
	}

	/** Prevent unsupported rendered-success claims after a successful file mutation. */
	private function append_rendered_output_evidence_notice( string $reply ): string {
		if ( ! $this->rendered_output_evidence_gate->blocks_rendered_claim( $reply ) ) {
			return $reply;
		}

		return $this->rendered_output_evidence_gate->get_terminal_notice();
	}

	/** Discard only gate-owned autosaves; the published parents are untouched. */
	private function discard_unpublished_page_previews(): void {
		$targets = $this->page_completion_gate->get_preview_targets();
		if ( empty( $targets ) ) {
			return;
		}
		foreach ( $targets as $target ) {
			PagePreviewWorkspace::discard(
				(int) ( $target['post_id'] ?? 0 ),
				(string) ( $target['workspace_id'] ?? '' )
			);
		}
		$this->page_completion_gate->record_previews_discarded();
	}

	/** Extract a safe image MIME type from a base64 screenshot data URI. */
	private static function screenshot_data_uri_mime_type( string $data_uri ): ?string {
		if ( 1 !== preg_match( '/^data:(image\/[a-z0-9.+-]+)(?:;[^,]*)?;base64,/i', $data_uri, $matches ) ) {
			return null;
		}

		return strtolower( (string) $matches[1] );
	}

	/** Return whether an SDK function name produces visual screenshot evidence. */
	private static function is_screenshot_tool_name( string $tool_name ): bool {
		return in_array(
			$tool_name,
			array(
				PageCompletionGate::CLIENT_ABILITY,
				'sd-ai-agent-js/capture-screenshot',
				'sd-ai-agent-js/screenshot-url',
				'wpab__sd-ai-agent-js__validate-page-quality',
				'wpab__sd-ai-agent-js__capture-screenshot',
				'wpab__sd-ai-agent-js__screenshot-url',
			),
			true
		);
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
