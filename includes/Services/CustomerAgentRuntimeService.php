<?php

declare(strict_types=1);
/**
 * Versioned PHP runtime for trusted customer-facing integrations.
 *
 * @package SdAiAgent\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Services;

use SdAiAgent\Contracts\CustomerAgentRuntimeInterface;
use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\ConversationSerializer;
use SdAiAgent\Core\ConversationTrimmer;
use SdAiAgent\Core\JobErrorSanitizer;
use SdAiAgent\Models\ActiveJobRepository;
use SdAiAgent\Models\CustomerAgentRuntimeRepository;
use WP_Error;
use WordPress\AiClient\Messages\DTO\Message;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes constrained asynchronous customer turns without exposing REST tokens.
 *
 * @phpstan-type RuntimeProfile array{
 *     profile_id: string,
 *     system_instruction: string,
 *     abilities: list<string>,
 *     collections: list<string>,
 *     provider_id: string,
 *     model_id: string,
 *     max_message_length: int,
 *     max_history_turns: int,
 *     max_iterations: int,
 *     max_runtime_seconds: int
 * }
 */
class CustomerAgentRuntimeService implements CustomerAgentRuntimeInterface {

	/** Semantic version consumers use for fail-closed compatibility checks. */
	public const CONTRACT_VERSION = '1.0.0';

	/** WP-Cron hook used exclusively by the durable runtime queue. */
	public const PROCESS_HOOK = 'sd_ai_agent_customer_agent_process_job';

	/** Runtime data is retained for at least seven days after creation. */
	public const RETENTION_SECONDS = 604800;

	private const DEFAULT_MAX_MESSAGE_LENGTH  = 4000;
	private const MAX_MESSAGE_LENGTH          = 12000;
	private const DEFAULT_MAX_HISTORY_TURNS   = 8;
	private const MAX_HISTORY_TURNS           = 20;
	private const DEFAULT_MAX_ITERATIONS      = 6;
	private const MAX_ITERATIONS              = 12;
	private const DEFAULT_MAX_RUNTIME_SECONDS = 120;
	private const MAX_RUNTIME_SECONDS         = 600;
	private const MAX_REPLY_LENGTH            = 12000;

	/** @var list<string> V1 forbids meta-tools, mutations, browser, and client tools. */
	private const SAFE_ABILITIES = array( 'sd-ai-agent/knowledge-search' );

	private static ?self $instance = null;

	/**
	 * Optional test seam. Production execution always uses AgentLoop directly.
	 *
	 * @var callable|null
	 */
	private $executor;

	/**
	 * @param callable|null $executor Test-only callable accepting job, conversation, and profile arrays.
	 */
	public function __construct( ?callable $executor = null ) {
		$this->executor = $executor;
	}

	/** Return the stable singleton exposed by the plugin-level PHP function. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * {@inheritDoc}
	 */
	public function discover_capabilities(): array {
		return array(
			'contract_version' => self::CONTRACT_VERSION,
			'features'         => array(
				'durable_jobs'             => true,
				'idempotent_enqueue'       => true,
				'non_consuming_polling'    => true,
				'cancellation'             => true,
				'opaque_identifiers'       => true,
				'client_tools_supported'   => false,
				'attachments_supported'    => false,
				'caller_prompts_supported' => false,
			),
			'limits'           => array(
				'max_message_length'  => self::MAX_MESSAGE_LENGTH,
				'max_history_turns'   => self::MAX_HISTORY_TURNS,
				'max_iterations'      => self::MAX_ITERATIONS,
				'max_runtime_seconds' => self::MAX_RUNTIME_SECONDS,
				'retention_seconds'   => self::RETENTION_SECONDS,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_or_recover_conversation( string $integration_key, string $external_session_id ): array|WP_Error {
		$profile = $this->resolve_integration( $integration_key );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$external_session_id = $this->validate_external_identifier( $external_session_id, 'session' );
		if ( is_wp_error( $external_session_id ) ) {
			return $external_session_id;
		}

		$conversation = $this->get_or_create_conversation( $integration_key, $external_session_id, $profile );
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		return array(
			'conversation_id' => (string) $conversation['row']['conversation_id'],
			'status'          => 'open',
			'recovered'       => (bool) $conversation['recovered'],
			'expires_at'      => (string) $conversation['row']['expires_at'],
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function enqueue_turn( string $integration_key, string $external_session_id, string $external_message_id, string $message ): array|WP_Error {
		$profile = $this->resolve_integration( $integration_key );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$external_session_id = $this->validate_external_identifier( $external_session_id, 'session' );
		if ( is_wp_error( $external_session_id ) ) {
			return $external_session_id;
		}
		$external_message_id = $this->validate_external_identifier( $external_message_id, 'message' );
		if ( is_wp_error( $external_message_id ) ) {
			return $external_message_id;
		}

		$message = trim( wp_strip_all_tags( wp_check_invalid_utf8( $message ) ) );
		if ( '' === $message ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_empty_message',
				__( 'A customer message is required.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}
		if ( strlen( $message ) > $profile['max_message_length'] ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_message_too_long',
				__( 'The customer message exceeds this runtime profile limit.', 'superdav-ai-agent' ),
				array( 'status' => 413 )
			);
		}

		$conversation = $this->get_or_create_conversation( $integration_key, $external_session_id, $profile );
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		$integration_hash      = $this->hash_identifier( 'integration', $integration_key );
		$external_session_hash = $this->hash_identifier( 'session', $external_session_id );
		$external_message_hash = $this->hash_identifier( 'message', $external_message_id );
		$existing              = CustomerAgentRuntimeRepository::find_job_by_idempotency(
			$integration_hash,
			$external_session_hash,
			$external_message_hash
		);

		if ( null !== $existing ) {
			return $this->enqueue_response( $existing, false );
		}

		$now                         = current_time( 'mysql', true );
		$expires_at                  = $this->future_mysql_time( self::RETENTION_SECONDS );
		$deadline                    = $this->future_mysql_time( $profile['max_runtime_seconds'] );
		$job_id                      = wp_generate_uuid4();
		$snapshot                    = $profile;
		$snapshot['integration_key'] = sanitize_key( $integration_key );
		$request_payload             = wp_json_encode( array( 'message' => $message ) );
		$profile_snapshot            = wp_json_encode( $snapshot );

		if ( ! is_string( $request_payload ) || ! is_string( $profile_snapshot ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_storage_failed',
				__( 'The customer-agent runtime could not persist the request.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$created = CustomerAgentRuntimeRepository::create_job(
			array(
				'job_id'                => $job_id,
				'conversation_id'       => (string) $conversation['row']['conversation_id'],
				'integration_hash'      => $integration_hash,
				'external_session_hash' => $external_session_hash,
				'external_message_hash' => $external_message_hash,
				'status'                => 'queued',
				'request_payload'       => $request_payload,
				'profile_snapshot'      => $profile_snapshot,
				'result_payload'        => '{}',
				'error_code'            => '',
				'error_message'         => '',
				'provider_id'           => $profile['provider_id'],
				'model_id'              => $profile['model_id'],
				'iterations_used'       => 0,
				'prompt_tokens'         => 0,
				'completion_tokens'     => 0,
				'started_at'            => null,
				'completed_at'          => null,
				'cancelled_at'          => null,
				'deadline_at'           => $deadline,
				'expires_at'            => $expires_at,
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);

		if ( ! $created ) {
			// The unique idempotency index is the concurrency gate. A conflicting
			// insert means another request already owns this external message.
			$existing = CustomerAgentRuntimeRepository::find_job_by_idempotency(
				$integration_hash,
				$external_session_hash,
				$external_message_hash
			);
			if ( null !== $existing ) {
				return $this->enqueue_response( $existing, false );
			}

			return new WP_Error(
				'sd_ai_agent_customer_agent_storage_failed',
				__( 'The customer-agent runtime could not queue the request.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$queued = CustomerAgentRuntimeRepository::get_job( $job_id );
		if ( null === $queued ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_storage_failed',
				__( 'The customer-agent runtime could not load the queued request.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$this->emit_lifecycle_event( 'queued', $queued );
		$scheduled = wp_schedule_single_event( time(), self::PROCESS_HOOK, array( $job_id ), true );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			$this->fail_job(
				$queued,
				'sd_ai_agent_customer_agent_queue_unavailable',
				__( 'The customer-agent runtime could not schedule the request.', 'superdav-ai-agent' )
			);
			return new WP_Error(
				'sd_ai_agent_customer_agent_queue_unavailable',
				__( 'The customer-agent runtime could not schedule the request.', 'superdav-ai-agent' ),
				array( 'status' => 503 )
			);
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return $this->enqueue_response( $queued, true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function inspect_job( string $integration_key, string $external_session_id, string $job_id ): array|WP_Error {
		$owned_job = $this->find_owned_job( $integration_key, $external_session_id, $job_id );
		if ( is_wp_error( $owned_job ) ) {
			return $owned_job;
		}

		if ( $this->deadline_has_passed( $owned_job ) ) {
			$this->mark_job_timed_out( $owned_job );
			$owned_job = CustomerAgentRuntimeRepository::get_job( $job_id ) ?? $owned_job;
		}

		return $this->public_job_dto( $owned_job );
	}

	/**
	 * {@inheritDoc}
	 */
	public function cancel_job( string $integration_key, string $external_session_id, string $job_id ): array|WP_Error {
		$owned_job = $this->find_owned_job( $integration_key, $external_session_id, $job_id );
		if ( is_wp_error( $owned_job ) ) {
			return $owned_job;
		}

		if ( 'cancelled' === $owned_job['status'] ) {
			return $this->public_job_dto( $owned_job );
		}
		if ( ! in_array( $owned_job['status'], array( 'queued', 'processing' ), true ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_job_not_cancellable',
				__( 'The customer-agent job is already terminal.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}

		$now = current_time( 'mysql', true );
		if ( CustomerAgentRuntimeRepository::mark_cancelled( $job_id, $now ) ) {
			ActiveJobRepository::update_status(
				$job_id,
				'error',
				array( 'error' => 'Customer-agent runtime cancelled before result delivery.' )
			);
			$cancelled = CustomerAgentRuntimeRepository::get_job( $job_id );
			if ( null !== $cancelled ) {
				$this->emit_lifecycle_event( 'cancelled', $cancelled );
				return $this->public_job_dto( $cancelled );
			}
		}

		$latest = CustomerAgentRuntimeRepository::get_job( $job_id );
		if ( null !== $latest && 'cancelled' === $latest['status'] ) {
			return $this->public_job_dto( $latest );
		}

		return new WP_Error(
			'sd_ai_agent_customer_agent_job_not_cancellable',
			__( 'The customer-agent job is already terminal.', 'superdav-ai-agent' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function close_conversation( string $integration_key, string $external_session_id ): array|WP_Error {
		$profile = $this->resolve_integration( $integration_key );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$external_session_id = $this->validate_external_identifier( $external_session_id, 'session' );
		if ( is_wp_error( $external_session_id ) ) {
			return $external_session_id;
		}

		$conversation = CustomerAgentRuntimeRepository::find_conversation(
			$this->hash_identifier( 'integration', $integration_key ),
			$this->hash_identifier( 'session', $external_session_id )
		);
		if ( null === $conversation ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_conversation_not_found',
				__( 'Customer-agent conversation not found.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		$purged = CustomerAgentRuntimeRepository::purge_conversation( (string) $conversation['conversation_id'] );
		if ( ! $purged['deleted'] ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_storage_failed',
				__( 'The customer-agent runtime could not close the conversation.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		foreach ( $purged['job_ids'] as $job_id ) {
			ActiveJobRepository::delete( $job_id );
		}
		$this->emit_lifecycle_event(
			'closed',
			array(
				'integration_hash' => (string) $conversation['integration_hash'],
				'conversation_id'  => (string) $conversation['conversation_id'],
				'job_id'           => '',
			)
		);

		return array(
			'conversation_id' => (string) $conversation['conversation_id'],
			'status'          => 'closed',
		);
	}

	/**
	 * Run the queued job. Called only by the DI-wired WP-Cron hook.
	 */
	public function process_job( string $job_id ): void {
		$job = CustomerAgentRuntimeRepository::get_job( $job_id );
		if ( null === $job ) {
			return;
		}
		if ( $this->deadline_has_passed( $job ) ) {
			$this->mark_job_timed_out( $job );
			return;
		}

		$now = current_time( 'mysql', true );
		if ( ! CustomerAgentRuntimeRepository::claim_queued_job( $job_id, $now ) ) {
			return;
		}
		$job = CustomerAgentRuntimeRepository::get_job( $job_id );
		if ( null === $job ) {
			return;
		}

		if ( null === ActiveJobRepository::get_by_job_id( $job_id ) ) {
			if ( false === ActiveJobRepository::create( 0, $job_id, 0 ) ) {
				$this->fail_job(
					$job,
					'sd_ai_agent_customer_agent_storage_failed',
					__( 'The customer-agent runtime could not prepare the request.', 'superdav-ai-agent' )
				);
				return;
			}
		} else {
			ActiveJobRepository::update_status( $job_id, 'processing' );
		}
		$this->emit_lifecycle_event( 'processing', $job );

		$profile_snapshot = $this->decode_object( (string) $job['profile_snapshot'] );
		$integration_key  = isset( $profile_snapshot['integration_key'] ) && is_string( $profile_snapshot['integration_key'] )
			? $profile_snapshot['integration_key']
			: '';
		$profile          = $this->resolve_integration( $integration_key );
		if ( $profile instanceof WP_Error ) {
			$this->fail_job(
				$job,
				'sd_ai_agent_customer_agent_profile_unavailable',
				__( 'The customer-agent profile is no longer available.', 'superdav-ai-agent' )
			);
			return;
		}

		$conversation = CustomerAgentRuntimeRepository::get_conversation( (string) $job['conversation_id'] );
		if ( null === $conversation ) {
			$this->fail_job(
				$job,
				'sd_ai_agent_customer_agent_conversation_not_found',
				__( 'The customer-agent conversation is no longer available.', 'superdav-ai-agent' )
			);
			return;
		}

		$result = $this->execute_turn( $job, $conversation, $profile );
		if ( is_wp_error( $result ) ) {
			$this->fail_job( $job, 'sd_ai_agent_customer_agent_execution_failed', $result->get_error_message() );
			return;
		}
		if ( ! is_array( $result ) || ! empty( $result['awaiting_confirmation'] ) || ! empty( $result['pending_client_tool_calls'] ) ) {
			$this->fail_job(
				$job,
				'sd_ai_agent_customer_agent_unsupported_pause',
				__( 'The customer-agent runtime may not wait for client tools or confirmations.', 'superdav-ai-agent' )
			);
			return;
		}

		if ( $this->deadline_has_passed( $job ) ) {
			$this->mark_job_timed_out( $job );
			return;
		}

		$history = $this->bounded_serialized_history( $result['history'] ?? array(), $profile['max_history_turns'] );
		$reply   = JobErrorSanitizer::sanitize( (string) ( $result['reply'] ?? '' ), self::MAX_REPLY_LENGTH );
		$tokens  = isset( $result['token_usage'] ) && is_array( $result['token_usage'] ) ? $result['token_usage'] : array();
		$payload = wp_json_encode(
			array(
				'reply'           => $reply,
				'provider_id'     => $profile['provider_id'],
				'model_id'        => (string) ( $result['model_id'] ?? $profile['model_id'] ),
				'iterations_used' => (int) ( $result['iterations_used'] ?? 0 ),
				'token_usage'     => array(
					'prompt'     => (int) ( $tokens['prompt'] ?? 0 ),
					'completion' => (int) ( $tokens['completion'] ?? 0 ),
				),
			)
		);
		if ( ! is_string( $payload ) ) {
			$this->fail_job(
				$job,
				'sd_ai_agent_customer_agent_storage_failed',
				__( 'The customer-agent runtime could not persist the result.', 'superdav-ai-agent' )
			);
			return;
		}

		$completed = CustomerAgentRuntimeRepository::mark_complete(
			$job_id,
			current_time( 'mysql', true ),
			$payload,
			$profile['provider_id'],
			(string) ( $result['model_id'] ?? $profile['model_id'] ),
			(int) ( $result['iterations_used'] ?? 0 ),
			(int) ( $tokens['prompt'] ?? 0 ),
			(int) ( $tokens['completion'] ?? 0 )
		);
		if ( ! $completed ) {
			// Cancellation and timeout both win this conditional-write race. A late
			// provider result is deliberately discarded instead of becoming visible.
			return;
		}

		$history_json = wp_json_encode( $history );
		if ( is_string( $history_json ) ) {
			CustomerAgentRuntimeRepository::update_runtime_history(
				(string) $job['conversation_id'],
				$history_json,
				$this->future_mysql_time( self::RETENTION_SECONDS )
			);
		}
		ActiveJobRepository::update_status( $job_id, 'complete' );
		$completed_job = CustomerAgentRuntimeRepository::get_job( $job_id );
		if ( null !== $completed_job ) {
			$this->emit_lifecycle_event( 'complete', $completed_job );
		}
	}

	/**
	 * Resolve a trusted integration profile from server-side registration only.
	 *
	 * @return RuntimeProfile|WP_Error
	 */
	private function resolve_integration( string $integration_key ): array|WP_Error {
		$integration_key = sanitize_key( $integration_key );
		if ( '' === $integration_key ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_unknown_integration',
				__( 'Customer-agent integration is not registered.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		/**
		 * Registers trusted same-install customer-agent profiles.
		 *
		 * @param array<string,array<string,mixed>> $integrations Integration-key map.
		 */
		$integrations = apply_filters( 'sd_ai_agent_customer_agent_integrations', array() );
		$config       = is_array( $integrations ) && isset( $integrations[ $integration_key ] ) && is_array( $integrations[ $integration_key ] )
			? $integrations[ $integration_key ]
			: null;
		if ( ! is_array( $config ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_unknown_integration',
				__( 'Customer-agent integration is not registered.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}
		if ( empty( $config['enabled'] ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_profile_disabled',
				__( 'Customer-agent profile is disabled.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		$profile_id         = sanitize_key( (string) ( $config['profile'] ?? '' ) );
		$system_instruction = trim( (string) ( $config['system_instruction'] ?? '' ) );
		$abilities          = $this->sanitize_string_list( $config['abilities'] ?? array(), false );
		$collections        = $this->sanitize_string_list( $config['collections'] ?? array(), true );
		if ( '' === $profile_id || '' === $system_instruction || empty( $abilities ) || empty( $collections ) || ! empty( array_diff( $abilities, self::SAFE_ABILITIES ) ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_invalid_profile',
				__( 'Customer-agent profile is not safely configured.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		return array(
			'profile_id'          => $profile_id,
			'system_instruction'  => $system_instruction,
			'abilities'           => $abilities,
			'collections'         => $collections,
			'provider_id'         => sanitize_text_field( (string) ( $config['provider_id'] ?? '' ) ),
			'model_id'            => sanitize_text_field( (string) ( $config['model_id'] ?? '' ) ),
			'max_message_length'  => $this->bounded_setting( $config['max_message_length'] ?? self::DEFAULT_MAX_MESSAGE_LENGTH, 256, self::MAX_MESSAGE_LENGTH ),
			'max_history_turns'   => $this->bounded_setting( $config['max_history_turns'] ?? self::DEFAULT_MAX_HISTORY_TURNS, 1, self::MAX_HISTORY_TURNS ),
			'max_iterations'      => $this->bounded_setting( $config['max_iterations'] ?? self::DEFAULT_MAX_ITERATIONS, 1, self::MAX_ITERATIONS ),
			'max_runtime_seconds' => $this->bounded_setting( $config['max_runtime_seconds'] ?? self::DEFAULT_MAX_RUNTIME_SECONDS, 30, self::MAX_RUNTIME_SECONDS ),
		);
	}

	/**
	 * Get or atomically create a runtime conversation for one external session.
	 *
	 * @param string              $integration_key     Trusted same-install integration key.
	 * @param string              $external_session_id Consumer-owned opaque session identifier.
	 * @param array<string,mixed> $profile Safe profile configuration.
	 * @phpstan-param RuntimeProfile $profile
	 * @return array{row:array<string,mixed>,recovered:bool}|WP_Error
	 */
	private function get_or_create_conversation( string $integration_key, string $external_session_id, array $profile ): array|WP_Error {
		$integration_hash      = $this->hash_identifier( 'integration', $integration_key );
		$external_session_hash = $this->hash_identifier( 'session', $external_session_id );
		$expires_at            = $this->future_mysql_time( self::RETENTION_SECONDS );
		$existing              = CustomerAgentRuntimeRepository::find_conversation( $integration_hash, $external_session_hash );
		if ( null !== $existing ) {
			CustomerAgentRuntimeRepository::touch_conversation( (string) $existing['conversation_id'], $profile['profile_id'], $expires_at );
			$existing['profile_id'] = $profile['profile_id'];
			$existing['expires_at'] = $expires_at;
			return array(
				'row'       => $existing,
				'recovered' => true,
			);
		}

		$now             = current_time( 'mysql', true );
		$conversation_id = wp_generate_uuid4();
		$created         = CustomerAgentRuntimeRepository::create_conversation(
			array(
				'conversation_id'       => $conversation_id,
				'integration_hash'      => $integration_hash,
				'external_session_hash' => $external_session_hash,
				'profile_id'            => $profile['profile_id'],
				'runtime_history'       => '[]',
				'expires_at'            => $expires_at,
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);
		if ( ! $created ) {
			$existing = CustomerAgentRuntimeRepository::find_conversation( $integration_hash, $external_session_hash );
			if ( null !== $existing ) {
				return array(
					'row'       => $existing,
					'recovered' => true,
				);
			}

			return new WP_Error(
				'sd_ai_agent_customer_agent_storage_failed',
				__( 'The customer-agent runtime could not create the conversation.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$row = CustomerAgentRuntimeRepository::get_conversation( $conversation_id );
		if ( null === $row ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_storage_failed',
				__( 'The customer-agent runtime could not load the conversation.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$this->emit_lifecycle_event( 'conversation_created', array_merge( $row, array( 'job_id' => '' ) ) );
		return array(
			'row'       => $row,
			'recovered' => false,
		);
	}

	/**
	 * Check that an opaque job belongs to the requesting integration/session.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function find_owned_job( string $integration_key, string $external_session_id, string $job_id ): array|WP_Error {
		$profile = $this->resolve_integration( $integration_key );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$external_session_id = $this->validate_external_identifier( $external_session_id, 'session' );
		if ( is_wp_error( $external_session_id ) ) {
			return $external_session_id;
		}
		$job_id = trim( $job_id );
		if ( '' === $job_id || strlen( $job_id ) > 64 ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_job_not_found',
				__( 'Customer-agent job not found.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		$conversation = CustomerAgentRuntimeRepository::find_conversation(
			$this->hash_identifier( 'integration', $integration_key ),
			$this->hash_identifier( 'session', $external_session_id )
		);
		if ( null === $conversation ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_conversation_not_found',
				__( 'Customer-agent conversation not found.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		$job = CustomerAgentRuntimeRepository::get_job( $job_id );
		if ( null === $job || ! hash_equals( (string) $conversation['conversation_id'], (string) $job['conversation_id'] ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_job_not_found',
				__( 'Customer-agent job not found.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		return $job;
	}

	/**
	 * Run AgentLoop with server-owned profile configuration and no client inputs.
	 *
	 * @param array<string,mixed> $job          Durable job row.
	 * @param array<string,mixed> $conversation Durable conversation row.
	 * @param array<string,mixed> $profile      Current registered profile.
	 * @phpstan-param RuntimeProfile $profile
	 * @return array<string,mixed>|WP_Error
	 */
	private function execute_turn( array $job, array $conversation, array $profile ): array|WP_Error {
		if ( null !== $this->executor ) {
			$result = call_user_func( $this->executor, $job, $conversation, $profile );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
			$result = $this->normalise_associative_array( $result );
			if ( null !== $result ) {
				return $result;
			}

			return new WP_Error(
				'sd_ai_agent_customer_agent_execution_failed',
				__( 'The customer-agent executor returned an invalid result.', 'superdav-ai-agent' )
			);
		}

		$request = $this->decode_object( (string) $job['request_payload'] );
		$message = isset( $request['message'] ) && is_string( $request['message'] ) ? $request['message'] : '';
		if ( '' === $message ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_execution_failed',
				__( 'The customer-agent request is invalid.', 'superdav-ai-agent' )
			);
		}

		$history = $this->deserialize_runtime_history( (string) $conversation['runtime_history'], $profile['max_history_turns'] );
		$options = array(
			'system_instruction'            => $profile['system_instruction'],
			'max_iterations'                => $profile['max_iterations'],
			'provider_id'                   => $profile['provider_id'],
			'model_id'                      => $profile['model_id'],
			'page_context'                  => array(
				'customer_agent_runtime' => true,
				'profile_id'             => $profile['profile_id'],
			),
			'anonymous_allowed_abilities'   => $profile['abilities'],
			'anonymous_allowed_collections' => $profile['collections'],
			'client_abilities'              => array(),
			'yolo_mode'                     => false,
			'active_job_id'                 => (string) $job['job_id'],
		);

		try {
			$loop = new AgentLoop( $message, $profile['abilities'], $history, $options );
			return $loop->run();
		} catch ( \Throwable $exception ) {
			// Do not log raw exception details: this public contract must never
			// retain provider payloads, paths, prompts, or credentials.
			return new WP_Error(
				'sd_ai_agent_customer_agent_execution_failed',
				__( 'The customer-agent runtime could not complete the request.', 'superdav-ai-agent' )
			);
		}
	}

	/**
	 * Build the non-consuming public DTO for an owned runtime job.
	 *
	 * @param array<string,mixed> $job Durable job row.
	 * @return array<string,mixed>
	 */
	private function public_job_dto( array $job ): array {
		$payload = $this->decode_object( (string) $job['result_payload'] );
		$status  = (string) ( $job['status'] ?? 'failed' );
		$dto     = array(
			'job_id'          => (string) $job['job_id'],
			'conversation_id' => (string) $job['conversation_id'],
			'status'          => $status,
			'provider_id'     => (string) ( $job['provider_id'] ?? '' ),
			'model_id'        => (string) ( $job['model_id'] ?? '' ),
			'iterations_used' => (int) ( $job['iterations_used'] ?? 0 ),
			'token_usage'     => array(
				'prompt'     => (int) ( $job['prompt_tokens'] ?? 0 ),
				'completion' => (int) ( $job['completion_tokens'] ?? 0 ),
			),
			'created_at'      => (string) ( $job['created_at'] ?? '' ),
			'updated_at'      => (string) ( $job['updated_at'] ?? '' ),
			'expires_at'      => (string) ( $job['expires_at'] ?? '' ),
		);

		if ( 'complete' === $status ) {
			$dto['reply'] = JobErrorSanitizer::sanitize( (string) ( $payload['reply'] ?? '' ), self::MAX_REPLY_LENGTH );
			if ( isset( $payload['provider_id'] ) && is_string( $payload['provider_id'] ) ) {
				$dto['provider_id'] = $payload['provider_id'];
			}
			if ( isset( $payload['model_id'] ) && is_string( $payload['model_id'] ) ) {
				$dto['model_id'] = $payload['model_id'];
			}
		}

		if ( 'failed' === $status ) {
			$dto['error'] = array(
				'code'    => (string) ( $job['error_code'] ?? 'sd_ai_agent_customer_agent_execution_failed' ),
				'message' => $this->customer_safe_error_message( (string) ( $job['error_message'] ?? '' ) ),
			);
		}

		return $dto;
	}

	/**
	 * Mark one runtime job as timed out and prevent a later result publication.
	 *
	 * @param array<string,mixed> $job Durable job row.
	 */
	private function mark_job_timed_out( array $job ): void {
		$job_id  = (string) $job['job_id'];
		$message = __( 'The customer-agent request timed out before a reply was available.', 'superdav-ai-agent' );
		if ( CustomerAgentRuntimeRepository::mark_timed_out( $job_id, current_time( 'mysql', true ), 'sd_ai_agent_customer_agent_timeout', $message ) ) {
			ActiveJobRepository::update_status( $job_id, 'error', array( 'error' => $message ) );
			$timed_out = CustomerAgentRuntimeRepository::get_job( $job_id );
			if ( null !== $timed_out ) {
				$this->emit_lifecycle_event( 'failed', $timed_out );
			}
		}
	}

	/**
	 * Persist a sanitized failure only while cancellation has not already won.
	 *
	 * @param array<string,mixed> $job Durable job row.
	 */
	private function fail_job( array $job, string $error_code, string $detail ): void {
		$job_id  = (string) $job['job_id'];
		$message = $this->customer_safe_error_message( $detail );
		if ( CustomerAgentRuntimeRepository::mark_failed( $job_id, current_time( 'mysql', true ), $error_code, $message ) ) {
			ActiveJobRepository::update_status( $job_id, 'error', array( 'error' => $message ) );
			$failed = CustomerAgentRuntimeRepository::get_job( $job_id );
			if ( null !== $failed ) {
				$this->emit_lifecycle_event( 'failed', $failed );
			}
		}
	}

	/**
	 * @param array<string,mixed> $job Durable job row.
	 */
	private function deadline_has_passed( array $job ): bool {
		$deadline = strtotime( (string) ( $job['deadline_at'] ?? '' ) . ' UTC' );
		return false !== $deadline && $deadline < time();
	}

	/**
	 * @param array<string,mixed> $job Durable job row.
	 * @return array{conversation_id:string,job_id:string,status:string,created:bool,expires_at:string}
	 */
	private function enqueue_response( array $job, bool $created ): array {
		return array(
			'conversation_id' => (string) $job['conversation_id'],
			'job_id'          => (string) $job['job_id'],
			'status'          => (string) $job['status'],
			'created'         => $created,
			'expires_at'      => (string) $job['expires_at'],
		);
	}

	/**
	 * Deserialize and bound the internal-only runtime history.
	 *
	 * @return list<Message>
	 */
	private function deserialize_runtime_history( string $serialized, int $max_history_turns ): array {
		$messages = $this->decode_message_history( $serialized );

		try {
			$history = ConversationSerializer::deserialize( $messages );
			$history = ConversationTrimmer::trim( $history, $max_history_turns );
			return ConversationTrimmer::validate_tool_pairs( $history );
		} catch ( \Throwable $exception ) {
			return array();
		}
	}

	/**
	 * Convert returned loop history into a bounded durable internal history.
	 *
	 * @param mixed $history Raw AgentLoop history output.
	 * @return list<array<string,mixed>>
	 */
	private function bounded_serialized_history( $history, int $max_history_turns ): array {
		if ( ! is_array( $history ) ) {
			return array();
		}
		$messages = array();
		foreach ( $history as $message ) {
			$message = $this->normalise_associative_array( $message );
			if ( null !== $message ) {
				$messages[] = $message;
			}
		}

		try {
			$objects = ConversationSerializer::deserialize( $messages );
			$objects = ConversationTrimmer::trim( $objects, $max_history_turns );
			return ConversationSerializer::serialize( ConversationTrimmer::validate_tool_pairs( $objects ) );
		} catch ( \Throwable $exception ) {
			return array();
		}
	}

	/**
	 * Decode a persisted JSON object without accepting list-shaped payloads.
	 *
	 * @return array<string,mixed>
	 */
	private function decode_object( string $value ): array {
		$decoded = json_decode( $value, true );
		return $this->normalise_associative_array( $decoded ) ?? array();
	}

	/**
	 * Decode only the serialized message maps accepted by ConversationSerializer.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function decode_message_history( string $serialized ): array {
		$decoded = json_decode( $serialized, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$messages = array();
		foreach ( $decoded as $message ) {
			$message = $this->normalise_associative_array( $message );
			if ( null !== $message ) {
				$messages[] = $message;
			}
		}

		return $messages;
	}

	/**
	 * Convert an untrusted PHP value into an associative map with string keys.
	 *
	 * @param mixed $value Raw callback or decoded JSON value.
	 * @return array<string,mixed>|null
	 */
	private function normalise_associative_array( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$normalised = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) {
				return null;
			}
			$normalised[ $key ] = $item;
		}

		return $normalised;
	}

	/**
	 * @param mixed $value Raw profile setting.
	 */
	private function bounded_setting( $value, int $minimum, int $maximum ): int {
		return max( $minimum, min( $maximum, (int) $value ) );
	}

	/**
	 * @param mixed $value Raw profile list.
	 * @return list<string>
	 */
	private function sanitize_string_list( $value, bool $keys ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$item = $keys ? sanitize_key( $item ) : trim( $item );
			if ( '' !== $item ) {
				$sanitized[ $item ] = true;
			}
		}

		return array_keys( $sanitized );
	}

	/**
	 * @return string|WP_Error
	 */
	private function validate_external_identifier( string $identifier, string $type ): string|WP_Error {
		$identifier = trim( wp_check_invalid_utf8( $identifier ) );
		if ( '' === $identifier || strlen( $identifier ) > 255 ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_invalid_' . $type . '_id',
				__( 'Customer-agent identifier is invalid.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		return $identifier;
	}

	/** Return an opaque stable hash without emitting the original identifier. */
	private function hash_identifier( string $kind, string $identifier ): string {
		return hash( 'sha256', $kind . '|' . $identifier );
	}

	/** Generate a UTC MySQL timestamp offset from the current request. */
	private function future_mysql_time( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', time() + max( 0, $seconds ) );
	}

	/** Return a generic fallback if technical redaction removes all detail. */
	private function customer_safe_error_message( string $detail ): string {
		$sanitized = JobErrorSanitizer::sanitize( $detail );
		return '' !== $sanitized
			? $sanitized
			: __( 'The customer-agent runtime could not complete the request.', 'superdav-ai-agent' );
	}

	/**
	 * Emit opaque, privacy-safe lifecycle metadata only; message bodies are never included.
	 *
	 * @param string              $event Lifecycle event name.
	 * @param array<string,mixed> $job   Runtime row or equivalent lifecycle metadata.
	 */
	private function emit_lifecycle_event( string $event, array $job ): void {
		do_action(
			'sd_ai_agent_customer_agent_runtime_event',
			$event,
			array(
				'integration_hash' => (string) ( $job['integration_hash'] ?? '' ),
				'conversation_id'  => (string) ( $job['conversation_id'] ?? '' ),
				'job_id'           => (string) ( $job['job_id'] ?? '' ),
				'status'           => (string) ( $job['status'] ?? '' ),
			)
		);
	}
}
