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
use SdAiAgent\Core\ActiveJobFailureDiagnostic;
use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\ConversationSerializer;
use SdAiAgent\Core\ConversationTrimmer;
use SdAiAgent\Core\CustomerAgentPromptComposer;
use SdAiAgent\Core\JobErrorSanitizer;
use SdAiAgent\Knowledge\KnowledgeDatabase;
use SdAiAgent\Models\ActiveJobRepository;
use SdAiAgent\Models\Agent;
use SdAiAgent\Models\CustomerConversationReviewRepository;
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
 *     profile_key: string,
 *     profile_version: string,
 *     support_instructions: string,
 *     abilities: list<string>,
 *     collections: list<string>,
 *     provider_id: string,
 *     model_id: string,
 *     max_message_length: int,
 *     max_history_turns: int,
 *     max_iterations: int,
 *     max_runtime_seconds: int
 * }
 * @phpstan-type ManagedProfileSpec array{
 *     profile_version: string,
 *     support_instructions: string,
 *     collections: list<string>,
 *     name: string,
 *     description: string,
 *     provider_id: string,
 *     model_id: string,
 *     temperature: float|null,
 *     max_iterations: int|null,
 *     greeting: string,
 *     avatar_icon: string,
 *     max_message_length: int,
 *     max_history_turns: int,
 *     max_runtime_seconds: int,
 *     reset_operator_fields: bool
 * }
 */
class CustomerAgentRuntimeService implements CustomerAgentRuntimeInterface {

	/** Semantic version consumers use for fail-closed compatibility checks. */
	public const CONTRACT_VERSION = '1.1.0';

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
	private const SAFE_ABILITIES                   = array( 'sd-ai-agent/knowledge-search' );
	private const REMOVED_PROFILE_OPTION_PREFIX    = 'sd_ai_agent_customer_agent_removed_profile_';
	private const INTEGRATION_LOCK_PREFIX          = 'sd_ai_agent_customer_agent_';
	private const INTEGRATION_LOCK_TIMEOUT_SECONDS = 10;

	private static ?self $instance = null;

	/** @var array<string,\mysqli> Connections that own request-scoped advisory locks. */
	private array $integration_lock_connections = array();

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

	/** {@inheritDoc} */
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
				'managed_profiles'         => true,
				'profile_health'           => true,
				'structured_handoff'       => true,
				'trusted_request_context'  => true,
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
	public function ensure_profile( string $integration_key, array $spec ): array|WP_Error {
		$integration_key = $this->normalize_integration_key( $integration_key );
		if ( is_wp_error( $integration_key ) ) {
			return $integration_key;
		}

		$spec = $this->normalize_managed_profile_spec( $spec );
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}
		$lock_name = $this->acquire_integration_lock( $integration_key );
		if ( is_wp_error( $lock_name ) ) {
			return $lock_name;
		}

		try {
			return $this->ensure_profile_locked( $integration_key, $spec );
		} finally {
			$this->release_integration_lock( $lock_name );
		}
	}

	/**
	 * @param string $integration_key Stable integration profile key.
	 * @param array  $spec            Normalized managed profile specification.
	 * @phpstan-param ManagedProfileSpec $spec
	 * @return array<string,mixed>|WP_Error
	 */
	private function ensure_profile_locked( string $integration_key, array $spec ): array|WP_Error {

		$existing = Agent::get_by_managed_profile_key( $integration_key );
		$created  = false;
		$actions  = array();

		if ( null === $existing ) {
			$slug      = $this->managed_profile_slug( $integration_key );
			$collision = Agent::get_by_slug( $slug );
			if ( null !== $collision ) {
				return new WP_Error(
					'sd_ai_agent_customer_agent_profile_conflict',
					__( 'A non-managed agent already uses this customer profile slug.', 'superdav-ai-agent' ),
					array( 'status' => 409 )
				);
			}

			$metadata = $this->build_managed_profile_metadata( $integration_key, $spec, $spec['collections'] );
			$id       = Agent::create(
				array(
					'slug'                     => $slug,
					'name'                     => $spec['name'],
					'description'              => $spec['description'],
					'system_prompt'            => $spec['support_instructions'],
					'provider_id'              => $spec['provider_id'],
					'model_id'                 => $spec['model_id'],
					'temperature'              => $spec['temperature'],
					'max_iterations'           => $spec['max_iterations'],
					'greeting'                 => $spec['greeting'],
					'avatar_icon'              => $spec['avatar_icon'],
					'tier_1_tools'             => self::SAFE_ABILITIES,
					'managed_profile_key'      => $integration_key,
					'managed_profile_version'  => $spec['profile_version'],
					'managed_profile_metadata' => $metadata,
					'enabled'                  => true,
				)
			);
			if ( false === $id ) {
				// The stable slug is also the concurrency gate. If a concurrent
				// provisioner won the insert, recover only its explicit metadata.
				$existing = Agent::get_by_managed_profile_key( $integration_key );
				if ( null === $existing ) {
					return new WP_Error(
						'sd_ai_agent_customer_agent_profile_storage_failed',
						__( 'The managed customer profile could not be saved.', 'superdav-ai-agent' ),
						array( 'status' => 500 )
					);
				}
			} else {
				$existing = Agent::get( $id );
				$created  = true;
				$actions  = array( 'created', 'set_customer_safety_envelope', 'set_knowledge_only_policy' );
			}
		}

		if ( null === $existing || ! Agent::is_managed_customer_profile( $existing ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_profile_storage_failed',
				__( 'The managed customer profile could not be loaded.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		if ( ! $created ) {
			$current_metadata = $existing->managed_profile_metadata;
			$reset_operator   = $spec['reset_operator_fields'];
			$approved         = $reset_operator
				? $spec['collections']
				: (
					array_key_exists( 'approved_collections', $current_metadata )
						? $this->sanitize_string_list( $current_metadata['approved_collections'], true )
						: $spec['collections']
				);

			$metadata        = $this->build_managed_profile_metadata( $integration_key, $spec, $approved );
			$managed_updated = Agent::update_managed_customer_profile(
				$existing->id,
				array(
					'system_prompt'            => $spec['support_instructions'],
					'tier_1_tools'             => self::SAFE_ABILITIES,
					'managed_profile_version'  => $spec['profile_version'],
					'managed_profile_metadata' => $metadata,
				)
			);
			if ( ! $managed_updated ) {
				return new WP_Error(
					'sd_ai_agent_customer_agent_profile_storage_failed',
					__( 'The managed customer profile could not be reconciled.', 'superdav-ai-agent' ),
					array( 'status' => 500 )
				);
			}
			$actions = array( 'reconciled_managed_fields', 'preserved_operator_fields' );

			if ( $reset_operator ) {
				Agent::update(
					$existing->id,
					array(
						'name'           => $spec['name'],
						'description'    => $spec['description'],
						'provider_id'    => $spec['provider_id'],
						'model_id'       => $spec['model_id'],
						'temperature'    => $spec['temperature'],
						'max_iterations' => $spec['max_iterations'],
						'greeting'       => $spec['greeting'],
						'avatar_icon'    => $spec['avatar_icon'],
					)
				);
				$actions[] = 'reset_operator_fields';
			}
		}

		if ( ! $this->clear_removed_profile( $integration_key ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_profile_storage_failed',
				__( 'The managed customer profile could not be activated.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$status = $this->profile_status( $integration_key );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$status['created'] = $created;
		$status['actions'] = $actions;
		return $status;
	}

	/**
	 * {@inheritDoc}
	 */
	public function profile_status( string $integration_key ): array|WP_Error {
		$integration_key = $this->normalize_integration_key( $integration_key );
		if ( is_wp_error( $integration_key ) ) {
			return $integration_key;
		}
		if ( $this->is_removed_profile( $integration_key ) ) {
			return $this->removed_profile_error();
		}

		$agent = Agent::get_by_managed_profile_key( $integration_key );
		if ( null === $agent ) {
			return $this->legacy_profile_status( $integration_key );
		}
		if ( ! Agent::is_managed_customer_profile( $agent ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_invalid_profile',
				__( 'Customer-agent profile is not safely configured.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		$metadata             = $agent->managed_profile_metadata;
		$managed_collections  = $this->sanitize_string_list( $metadata['managed_collections'] ?? array(), true );
		$approved_collections = $this->sanitize_string_list( $metadata['approved_collections'] ?? array(), true );
		$collections          = array_values( array_intersect( $managed_collections, $approved_collections ) );
		$reasons              = array();
		$missing_collections  = array();

		if ( ! $agent->enabled ) {
			$reasons[] = 'profile_disabled';
		}
		if ( '' === trim( $agent->provider_id ) ) {
			$reasons[] = 'provider_missing';
		}
		if ( '' === trim( $agent->model_id ) ) {
			$reasons[] = 'model_missing';
		}
		if ( empty( $collections ) ) {
			$reasons[] = 'customer_collections_not_approved';
		}
		foreach ( $collections as $collection ) {
			$row = KnowledgeDatabase::get_collection_by_slug( $collection );
			if ( ! is_object( $row ) || 'active' !== (string) ( $row->status ?? '' ) ) {
				$missing_collections[] = $collection;
			}
		}
		if ( ! empty( $missing_collections ) ) {
			$reasons[] = 'collections_unavailable';
		}

		return array(
			'profile_key'         => $integration_key,
			'profile_version'     => $agent->managed_profile_version,
			'enabled'             => $agent->enabled,
			'ready'               => empty( $reasons ),
			'reasons'             => array_values( array_unique( $reasons ) ),
			'missing_collections' => $missing_collections,
			'capabilities'        => array(
				'abilities'     => self::SAFE_ABILITIES,
				'collections'   => $collections,
				'customer_mode' => true,
			),
			'drift'               => array(
				'safety_envelope_version'    => (string) ( $metadata['safety_envelope_version'] ?? '' ),
				'operator_resources_missing' => ! empty( $missing_collections ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function disable_profile( string $integration_key ): array|WP_Error {
		$integration_key = $this->normalize_integration_key( $integration_key );
		if ( is_wp_error( $integration_key ) ) {
			return $integration_key;
		}
		$lock_name = $this->acquire_integration_lock( $integration_key );
		if ( is_wp_error( $lock_name ) ) {
			return $lock_name;
		}

		try {
			return $this->disable_profile_locked( $integration_key );
		} finally {
			$this->release_integration_lock( $lock_name );
		}
	}

	/** @return array<string,mixed>|WP_Error */
	private function disable_profile_locked( string $integration_key ): array|WP_Error {

		$agent = Agent::get_by_managed_profile_key( $integration_key );
		if ( null === $agent || ! Agent::is_managed_customer_profile( $agent ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_unknown_integration',
				__( 'Customer-agent integration is not registered.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		if ( $agent->enabled && ! Agent::update( $agent->id, array( 'enabled' => false ) ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_profile_storage_failed',
				__( 'The managed customer profile could not be disabled.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$status = $this->profile_status( $integration_key );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		$status['status'] = 'disabled';
		return $status;
	}

	/**
	 * {@inheritDoc}
	 */
	public function remove_profile( string $integration_key ): array|WP_Error {
		$integration_key = $this->normalize_integration_key( $integration_key );
		if ( is_wp_error( $integration_key ) ) {
			return $integration_key;
		}
		$lock_name = $this->acquire_integration_lock( $integration_key );
		if ( is_wp_error( $lock_name ) ) {
			return $lock_name;
		}

		try {
			return $this->remove_profile_locked( $integration_key );
		} finally {
			$this->release_integration_lock( $lock_name );
		}
	}

	/** @return array<string,mixed>|WP_Error */
	private function remove_profile_locked( string $integration_key ): array|WP_Error {
		$agent = Agent::get_by_managed_profile_key( $integration_key );
		if ( null === $agent || ! Agent::is_managed_customer_profile( $agent ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_unknown_integration',
				__( 'Customer-agent integration is not registered.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->mark_profile_removed( $integration_key ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_profile_storage_failed',
				__( 'The managed customer profile could not be removed.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$purged = CustomerAgentRuntimeRepository::purge_integration( $this->hash_identifier( 'integration', $integration_key ) );
		if ( ! $purged['purged'] ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_profile_storage_failed',
				__( 'The managed customer profile could not be removed.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}
		foreach ( $purged['job_ids'] as $job_id ) {
			ActiveJobRepository::delete( $job_id );
		}
		if ( ! Agent::delete_managed_customer_profile( $integration_key ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_profile_storage_failed',
				__( 'The managed customer profile could not be removed.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'profile_key'     => $integration_key,
			'status'          => 'removed',
			'purged_jobs'     => count( $purged['job_ids'] ),
			'purged_sessions' => $purged['conversations'],
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_or_recover_conversation( string $integration_key, string $external_session_id ): array|WP_Error {
		$integration_key = $this->normalize_integration_key( $integration_key );
		if ( is_wp_error( $integration_key ) ) {
			return $integration_key;
		}
		$lock_name = $this->acquire_integration_lock( $integration_key );
		if ( is_wp_error( $lock_name ) ) {
			return $lock_name;
		}

		try {
			return $this->create_or_recover_conversation_locked( $integration_key, $external_session_id );
		} finally {
			$this->release_integration_lock( $lock_name );
		}
	}

	/** @return array{conversation_id:string,status:string,recovered:bool,expires_at:string}|WP_Error */
	private function create_or_recover_conversation_locked( string $integration_key, string $external_session_id ): array|WP_Error {
		$profile = $this->resolve_integration( $integration_key, true );
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
	 * Queue one idempotent customer turn.
	 *
	 * @param string              $integration_key     Stable integration-owned profile key.
	 * @param string              $external_session_id Consumer-owned opaque session identifier.
	 * @param string              $external_message_id Consumer-owned idempotency identifier.
	 * @param string              $message             Customer message content.
	 * @param array<string,mixed> $request_context     Trusted integration context used only to narrow profile limits.
	 * @return array{conversation_id:string,job_id:string,status:string,created:bool,expires_at:string}|WP_Error
	 */
	public function enqueue_turn( string $integration_key, string $external_session_id, string $external_message_id, string $message, array $request_context = array() ): array|WP_Error {
		$integration_key = $this->normalize_integration_key( $integration_key );
		if ( is_wp_error( $integration_key ) ) {
			return $integration_key;
		}
		$lock_name = $this->acquire_integration_lock( $integration_key );
		if ( is_wp_error( $lock_name ) ) {
			return $lock_name;
		}

		try {
			return $this->enqueue_turn_locked( $integration_key, $external_session_id, $external_message_id, $message, $request_context );
		} finally {
			$this->release_integration_lock( $lock_name );
		}
	}

	/**
	 * @param string              $integration_key     Stable integration-owned profile key.
	 * @param string              $external_session_id Consumer-owned opaque session identifier.
	 * @param string              $external_message_id Consumer-owned idempotency identifier.
	 * @param string              $message             Customer message content.
	 * @param array<string,mixed> $request_context     Trusted integration context used only to narrow profile limits.
	 * @return array{conversation_id:string,job_id:string,status:string,created:bool,expires_at:string}|WP_Error
	 */
	private function enqueue_turn_locked( string $integration_key, string $external_session_id, string $external_message_id, string $message, array $request_context ): array|WP_Error {
		$profile = $this->resolve_integration( $integration_key, true );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$profile = $this->apply_consumer_policy_narrowing( $profile, $request_context );
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
		$request_payload             = wp_json_encode(
			array(
				'message' => $message,
				'context' => CustomerAgentPromptComposer::sanitize_request_context( $request_context ),
			)
		);
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

		// Review recording is a separate, fail-open display projection. Its write
		// must never prevent a constrained customer request from being delivered.
		$this->record_runtime_review_on_enqueue( $conversation['row'], $profile, $job_id, $message, $expires_at );

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
			$this->record_runtime_review_status( $owned_job, 'cancelled' );
			ActiveJobRepository::record_failure(
				$job_id,
				'error',
				ActiveJobFailureDiagnostic::REASON_UNKNOWN,
				array( 'last_safe_phase' => 'customer_agent_cancellation' )
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
		$integration_key = $this->normalize_integration_key( $integration_key );
		if ( is_wp_error( $integration_key ) ) {
			return $integration_key;
		}
		$lock_name = $this->acquire_integration_lock( $integration_key );
		if ( is_wp_error( $lock_name ) ) {
			return $lock_name;
		}

		try {
			return $this->close_conversation_locked( $integration_key, $external_session_id );
		} finally {
			$this->release_integration_lock( $lock_name );
		}
	}

	/** @return array{conversation_id:string,status:string}|WP_Error */
	private function close_conversation_locked( string $integration_key, string $external_session_id ): array|WP_Error {
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
		// Claiming the queued row is the concurrency guard for normal execution.
		// Lifecycle mutations retain their separate integration lock.
		$this->process_job_locked( $job_id );
	}

	/** Run one customer job while its integration lifecycle lock is held. */
	private function process_job_locked( string $job_id ): void {
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
		$profile = $this->restrict_profile_to_snapshot( $profile, $profile_snapshot );

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
			$error_data         = $result->get_error_data();
			$diagnostic_context = is_array( $error_data )
				? ActiveJobFailureDiagnostic::context_from_error_data( $error_data )
				: array();
			$this->fail_job(
				$job,
				'sd_ai_agent_customer_agent_execution_failed',
				$result->get_error_message(),
				ActiveJobFailureDiagnostic::reason_from_error( $result ),
				$diagnostic_context
			);
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

		$history      = $this->bounded_serialized_history( $result['history'] ?? array(), $profile['max_history_turns'] );
		$response     = $this->normalise_customer_response( $result );
		$reply        = $response['reply'];
		$tokens       = isset( $result['token_usage'] ) && is_array( $result['token_usage'] ) ? $result['token_usage'] : array();
		$payload_data = array(
			'reply'           => $reply,
			'provider_id'     => $profile['provider_id'],
			'model_id'        => (string) ( $result['model_id'] ?? $profile['model_id'] ),
			'iterations_used' => (int) ( $result['iterations_used'] ?? 0 ),
			'token_usage'     => array(
				'prompt'     => (int) ( $tokens['prompt'] ?? 0 ),
				'completion' => (int) ( $tokens['completion'] ?? 0 ),
			),
		);
		if ( null !== $response['handoff'] ) {
			$payload_data['handoff'] = $response['handoff'];
		}
		$payload = wp_json_encode( $payload_data );
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

		$review_expires_at = $this->future_mysql_time( self::RETENTION_SECONDS );
		$history_json      = wp_json_encode( $history );
		if ( is_string( $history_json ) ) {
			CustomerAgentRuntimeRepository::update_runtime_history(
				(string) $job['conversation_id'],
				$history_json,
				$review_expires_at
			);
		}
		$this->record_runtime_review_completion(
			$job,
			$response,
			$profile['provider_id'],
			(string) ( $result['model_id'] ?? $profile['model_id'] ),
			(int) ( $result['iterations_used'] ?? 0 ),
			(int) ( $tokens['prompt'] ?? 0 ),
			(int) ( $tokens['completion'] ?? 0 ),
			$review_expires_at
		);
		ActiveJobRepository::update_status( $job_id, 'complete' );
		$completed_job = CustomerAgentRuntimeRepository::get_job( $job_id );
		if ( null !== $completed_job ) {
			$this->emit_lifecycle_event( 'complete', $completed_job );
		}
	}

	/**
	 * Resolve a managed profile first, preserving the V1 filter registration path
	 * for existing public integrations until they explicitly provision a profile.
	 *
	 * @return RuntimeProfile|WP_Error
	 */
	private function resolve_integration( string $integration_key, bool $require_ready = false ): array|WP_Error {
		$integration_key = $this->normalize_integration_key( $integration_key );
		if ( is_wp_error( $integration_key ) ) {
			return $integration_key;
		}
		if ( $this->is_removed_profile( $integration_key ) ) {
			return $this->removed_profile_error();
		}

		$agent = Agent::get_by_managed_profile_key( $integration_key );
		if ( null !== $agent ) {
			if ( ! Agent::is_managed_customer_profile( $agent ) ) {
				return new WP_Error(
					'sd_ai_agent_customer_agent_invalid_profile',
					__( 'Customer-agent profile is not safely configured.', 'superdav-ai-agent' ),
					array( 'status' => 403 )
				);
			}

			$metadata    = $agent->managed_profile_metadata;
			$collections = array_values(
				array_intersect(
					$this->sanitize_string_list( $metadata['managed_collections'] ?? array(), true ),
					$this->sanitize_string_list( $metadata['approved_collections'] ?? array(), true )
				)
			);
			if ( $require_ready ) {
				$status = $this->profile_status( $integration_key );
				if ( is_wp_error( $status ) ) {
					return $status;
				}
				if ( empty( $status['ready'] ) ) {
					return $this->profile_readiness_error( $status );
				}
			}

			return array(
				'profile_id'           => 'managed:' . $integration_key,
				'profile_key'          => $integration_key,
				'profile_version'      => $agent->managed_profile_version,
				'support_instructions' => $agent->system_prompt,
				'abilities'            => self::SAFE_ABILITIES,
				'collections'          => $collections,
				'provider_id'          => $agent->provider_id,
				'model_id'             => $agent->model_id,
				'max_message_length'   => $this->bounded_setting( $metadata['max_message_length'] ?? self::DEFAULT_MAX_MESSAGE_LENGTH, 256, self::MAX_MESSAGE_LENGTH ),
				'max_history_turns'    => $this->bounded_setting( $metadata['max_history_turns'] ?? self::DEFAULT_MAX_HISTORY_TURNS, 1, self::MAX_HISTORY_TURNS ),
				'max_iterations'       => $this->bounded_setting( $agent->max_iterations ?? ( $metadata['max_iterations'] ?? self::DEFAULT_MAX_ITERATIONS ), 1, self::MAX_ITERATIONS ),
				'max_runtime_seconds'  => $this->bounded_setting( $metadata['max_runtime_seconds'] ?? self::DEFAULT_MAX_RUNTIME_SECONDS, 30, self::MAX_RUNTIME_SECONDS ),
			);
		}

		return $this->resolve_legacy_integration( $integration_key );
	}

	/**
	 * Resolve the version-1 filter configuration kept for public-chat compatibility.
	 *
	 * @return RuntimeProfile|WP_Error
	 */
	private function resolve_legacy_integration( string $integration_key ): array|WP_Error {
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

		$profile_id           = sanitize_key( (string) ( $config['profile'] ?? '' ) );
		$support_instructions = trim( (string) ( $config['system_instruction'] ?? '' ) );
		$abilities            = $this->sanitize_string_list( $config['abilities'] ?? array(), false );
		$collections          = $this->sanitize_string_list( $config['collections'] ?? array(), true );
		if ( '' === $profile_id || '' === $support_instructions || empty( $abilities ) || empty( $collections ) || ! empty( array_diff( $abilities, self::SAFE_ABILITIES ) ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_invalid_profile',
				__( 'Customer-agent profile is not safely configured.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		return array(
			'profile_id'           => $profile_id,
			'profile_key'          => $integration_key,
			'profile_version'      => 'legacy',
			'support_instructions' => $support_instructions,
			'abilities'            => $abilities,
			'collections'          => $collections,
			'provider_id'          => sanitize_text_field( (string) ( $config['provider_id'] ?? '' ) ),
			'model_id'             => sanitize_text_field( (string) ( $config['model_id'] ?? '' ) ),
			'max_message_length'   => $this->bounded_setting( $config['max_message_length'] ?? self::DEFAULT_MAX_MESSAGE_LENGTH, 256, self::MAX_MESSAGE_LENGTH ),
			'max_history_turns'    => $this->bounded_setting( $config['max_history_turns'] ?? self::DEFAULT_MAX_HISTORY_TURNS, 1, self::MAX_HISTORY_TURNS ),
			'max_iterations'       => $this->bounded_setting( $config['max_iterations'] ?? self::DEFAULT_MAX_ITERATIONS, 1, self::MAX_ITERATIONS ),
			'max_runtime_seconds'  => $this->bounded_setting( $config['max_runtime_seconds'] ?? self::DEFAULT_MAX_RUNTIME_SECONDS, 30, self::MAX_RUNTIME_SECONDS ),
		);
	}

	/**
	 * Normalize an integration key before it is used as a profile identifier.
	 *
	 * @return string|WP_Error
	 */
	private function normalize_integration_key( string $integration_key ): string|WP_Error {
		$integration_key = sanitize_key( $integration_key );
		if ( '' === $integration_key || strlen( $integration_key ) > 100 ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_unknown_integration',
				__( 'Customer-agent integration is not registered.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		return $integration_key;
	}

	/**
	 * Acquire a cross-request mutex for every lifecycle mutation of one integration.
	 *
	 * @return string|WP_Error Opaque advisory-lock name or a fail-closed error.
	 */
	private function acquire_integration_lock( string $integration_key ): string|WP_Error {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$lock_name = self::INTEGRATION_LOCK_PREFIX . substr( $this->hash_identifier( 'integration-lock', $integration_key ), 0, 36 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A database advisory lock serializes lifecycle operations across PHP requests and web heads.
		$acquired = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s, %d)',
				$lock_name,
				self::INTEGRATION_LOCK_TIMEOUT_SECONDS
			)
		);
		if ( 1 !== (int) $acquired ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_lifecycle_busy',
				__( 'The customer-agent profile is busy. Please retry shortly.', 'superdav-ai-agent' ),
				array( 'status' => 503 )
			);
		}
		$connection = $this->database_connection_handle( $wpdb );
		if ( null !== $connection ) {
			$this->integration_lock_connections[ $lock_name ] = $connection;
		}

		return $lock_name;
	}

	/** Release a lock acquired by acquire_integration_lock(). */
	private function release_integration_lock( string $lock_name ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$query      = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name );
		$connection = $this->integration_lock_connections[ $lock_name ] ?? null;
		unset( $this->integration_lock_connections[ $lock_name ] );
		if ( ! is_string( $query ) ) {
			return;
		}

		if ( $connection instanceof \mysqli ) {
			try {
				// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Advisory locks are connection-scoped, so release must use the exact connection that acquired the lock instead of a router-selected wpdb connection.
				$result = mysqli_query( $connection, $query );
				if ( $result instanceof \mysqli_result ) {
					$result->free();
				}
			} catch ( \Throwable $exception ) {
				// The database releases request-owned advisory locks when the connection closes.
			}
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The query was prepared above; fallback supports wpdb drivers that do not expose their connection handle.
		$wpdb->get_var( $query );
	}

	/** Return the current driver connection without assuming wpdb property visibility. */
	private function database_connection_handle( \wpdb $database ): ?\mysqli {
		try {
			$property   = new \ReflectionProperty( $database, 'dbh' );
			$connection = $property->getValue( $database );
		} catch ( \ReflectionException $exception ) {
			return null;
		}

		return $connection instanceof \mysqli ? $connection : null;
	}

	/** Return the independent opaque tombstone option name for one integration key. */
	private function removed_profile_option_name( string $integration_key ): string {
		return self::REMOVED_PROFILE_OPTION_PREFIX . $this->hash_identifier( 'integration', $integration_key );
	}

	/** Persist a fail-closed tombstone before purging and deleting a managed profile. */
	private function mark_profile_removed( string $integration_key ): bool {
		$option_name = $this->removed_profile_option_name( $integration_key );
		if ( false !== get_option( $option_name, false ) ) {
			return true;
		}

		return update_option( $option_name, '1', false );
	}

	/** Clear a profile tombstone only after successful explicit provisioning. */
	private function clear_removed_profile( string $integration_key ): bool {
		$option_name = $this->removed_profile_option_name( $integration_key );
		if ( false === get_option( $option_name, false ) ) {
			return true;
		}

		return delete_option( $option_name );
	}

	/** Whether a removed managed profile must not fall back to a legacy registration. */
	private function is_removed_profile( string $integration_key ): bool {
		return false !== get_option( $this->removed_profile_option_name( $integration_key ), false );
	}

	/** Return the generic fail-closed response for an explicitly removed profile. */
	private function removed_profile_error(): WP_Error {
		return new WP_Error(
			'sd_ai_agent_customer_agent_profile_removed',
			__( 'The customer-agent profile has been removed.', 'superdav-ai-agent' ),
			array( 'status' => 410 )
		);
	}

	/**
	 * @param array<string,mixed> $spec Raw trusted-integration provisioning spec.
	 * @return ManagedProfileSpec|WP_Error
	 */
	private function normalize_managed_profile_spec( array $spec ): array|WP_Error {
		$profile_version = sanitize_text_field( (string) ( $spec['profile_version'] ?? '' ) );
		$collections     = $this->sanitize_string_list( $spec['collections'] ?? array(), true );
		if ( '' === $profile_version || strlen( $profile_version ) > 100 || empty( $collections ) ) {
			return new WP_Error(
				'sd_ai_agent_customer_agent_invalid_profile_spec',
				__( 'A managed customer profile requires a version and at least one approved collection.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		if ( array_key_exists( 'abilities', $spec ) ) {
			if ( ! is_array( $spec['abilities'] ) ) {
				return new WP_Error(
					'sd_ai_agent_customer_agent_forbidden_ability',
					__( 'Customer-agent profiles may use knowledge-search only.', 'superdav-ai-agent' ),
					array( 'status' => 403 )
				);
			}
			$requested_abilities = $this->sanitize_string_list( $spec['abilities'], false );
			if ( ! empty( array_diff( $requested_abilities, self::SAFE_ABILITIES ) ) ) {
				return new WP_Error(
					'sd_ai_agent_customer_agent_forbidden_ability',
					__( 'Customer-agent profiles may use knowledge-search only.', 'superdav-ai-agent' ),
					array( 'status' => 403 )
				);
			}
		}

		$support_instructions = trim( wp_strip_all_tags( wp_check_invalid_utf8( (string) ( $spec['support_instructions'] ?? '' ) ) ) );
		if ( '' === $support_instructions ) {
			$support_instructions = CustomerAgentPromptComposer::default_support_instructions();
		}

		$temperature = null;
		if ( array_key_exists( 'temperature', $spec ) && null !== $spec['temperature'] ) {
			$temperature = max( 0.0, min( 2.0, (float) $spec['temperature'] ) );
		}

		return array(
			'profile_version'       => $profile_version,
			'support_instructions'  => $support_instructions,
			'collections'           => $collections,
			'name'                  => sanitize_text_field( (string) ( $spec['name'] ?? __( 'Customer Support', 'superdav-ai-agent' ) ) ),
			'description'           => sanitize_textarea_field( (string) ( $spec['description'] ?? __( 'Managed customer-support agent.', 'superdav-ai-agent' ) ) ),
			'provider_id'           => sanitize_text_field( (string) ( $spec['provider_id'] ?? '' ) ),
			'model_id'              => sanitize_text_field( (string) ( $spec['model_id'] ?? '' ) ),
			'temperature'           => $temperature,
			'max_iterations'        => array_key_exists( 'max_iterations', $spec ) && null !== $spec['max_iterations']
				? $this->bounded_setting( $spec['max_iterations'], 1, self::MAX_ITERATIONS )
				: null,
			'greeting'              => sanitize_textarea_field( (string) ( $spec['greeting'] ?? '' ) ),
			'avatar_icon'           => sanitize_text_field( (string) ( $spec['avatar_icon'] ?? '' ) ),
			'max_message_length'    => $this->bounded_setting( $spec['max_message_length'] ?? self::DEFAULT_MAX_MESSAGE_LENGTH, 256, self::MAX_MESSAGE_LENGTH ),
			'max_history_turns'     => $this->bounded_setting( $spec['max_history_turns'] ?? self::DEFAULT_MAX_HISTORY_TURNS, 1, self::MAX_HISTORY_TURNS ),
			'max_runtime_seconds'   => $this->bounded_setting( $spec['max_runtime_seconds'] ?? self::DEFAULT_MAX_RUNTIME_SECONDS, 30, self::MAX_RUNTIME_SECONDS ),
			'reset_operator_fields' => ! empty( $spec['reset_operator_fields'] ),
		);
	}

	/**
	 * @param string $integration_key      Stable integration profile key.
	 * @param array  $spec                 Managed profile specification.
	 * @param array  $approved_collections Current operator-approved collection slugs.
	 * @phpstan-param ManagedProfileSpec $spec
	 * @phpstan-param list<string> $approved_collections
	 * @return array<string,mixed>
	 */
	private function build_managed_profile_metadata( string $integration_key, array $spec, array $approved_collections ): array {
		return array(
			'customer_mode'           => true,
			'profile_key'             => $integration_key,
			'safety_envelope_version' => CustomerAgentPromptComposer::SAFETY_ENVELOPE_VERSION,
			'allowed_abilities'       => self::SAFE_ABILITIES,
			'managed_collections'     => $spec['collections'],
			'approved_collections'    => $approved_collections,
			'max_message_length'      => $spec['max_message_length'],
			'max_history_turns'       => $spec['max_history_turns'],
			'max_iterations'          => $spec['max_iterations'] ?? self::DEFAULT_MAX_ITERATIONS,
			'max_runtime_seconds'     => $spec['max_runtime_seconds'],
		);
	}

	/** Create a stable, collision-resistant slug without assuming ownership of existing rows. */
	private function managed_profile_slug( string $integration_key ): string {
		$hash   = substr( hash( 'sha256', $integration_key ), 0, 16 );
		$prefix = substr( sanitize_title( $integration_key ), 0, 66 );

		return 'managed-customer-' . $prefix . '-' . $hash;
	}

	/**
	 * Return a safe health view for the legacy filter registration path.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function legacy_profile_status( string $integration_key ): array|WP_Error {
		$profile = $this->resolve_legacy_integration( $integration_key );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$reasons = array();
		if ( '' === $profile['provider_id'] ) {
			$reasons[] = 'provider_missing';
		}
		if ( '' === $profile['model_id'] ) {
			$reasons[] = 'model_missing';
		}

		return array(
			'profile_key'         => $integration_key,
			'profile_version'     => 'legacy',
			'enabled'             => true,
			'ready'               => empty( $reasons ),
			'reasons'             => $reasons,
			'missing_collections' => array(),
			'capabilities'        => array(
				'abilities'     => $profile['abilities'],
				'collections'   => $profile['collections'],
				'customer_mode' => true,
			),
			'drift'               => array( 'legacy_registration' => true ),
		);
	}

	/**
	 * @param array<string,mixed> $status Customer-safe profile status.
	 */
	private function profile_readiness_error( array $status ): WP_Error {
		$reasons = isset( $status['reasons'] ) && is_array( $status['reasons'] ) ? $status['reasons'] : array();
		if ( in_array( 'profile_disabled', $reasons, true ) ) {
			return new WP_Error( 'sd_ai_agent_customer_agent_profile_disabled', __( 'Customer-agent profile is disabled.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}
		if ( in_array( 'provider_missing', $reasons, true ) ) {
			return new WP_Error( 'sd_ai_agent_customer_agent_provider_missing', __( 'Customer-agent profile needs an operator-selected provider.', 'superdav-ai-agent' ), array( 'status' => 409 ) );
		}
		if ( in_array( 'model_missing', $reasons, true ) ) {
			return new WP_Error( 'sd_ai_agent_customer_agent_model_missing', __( 'Customer-agent profile needs an operator-selected model.', 'superdav-ai-agent' ), array( 'status' => 409 ) );
		}
		if ( in_array( 'collections_unavailable', $reasons, true ) ) {
			return new WP_Error( 'sd_ai_agent_customer_agent_collections_unavailable', __( 'One or more approved customer knowledge collections are unavailable.', 'superdav-ai-agent' ), array( 'status' => 409 ) );
		}

		return new WP_Error( 'sd_ai_agent_customer_agent_profile_not_ready', __( 'Customer-agent profile is not ready for customer requests.', 'superdav-ai-agent' ), array( 'status' => 409 ) );
	}

	/**
	 * Let a trusted consumer narrow policy for one turn, never widen it.
	 *
	 * @param array $profile         Runtime profile resolved from server-owned state.
	 * @param array $request_context Consumer input.
	 * @return array
	 * @phpstan-param RuntimeProfile $profile
	 * @phpstan-param array<string,mixed> $request_context
	 * @phpstan-return RuntimeProfile|WP_Error
	 */
	private function apply_consumer_policy_narrowing( array $profile, array $request_context ): array|WP_Error {
		foreach ( array( 'system_instruction', 'system_prompt', 'client_abilities', 'attachments', 'tool_calls' ) as $forbidden_key ) {
			if ( array_key_exists( $forbidden_key, $request_context ) ) {
				return new WP_Error(
					'sd_ai_agent_customer_agent_forbidden_request_input',
					__( 'Customer-agent request input cannot configure prompts, client tools, attachments, or tool calls.', 'superdav-ai-agent' ),
					array( 'status' => 403 )
				);
			}
		}

		if ( array_key_exists( 'abilities', $request_context ) ) {
			if ( ! is_array( $request_context['abilities'] ) ) {
				return new WP_Error( 'sd_ai_agent_customer_agent_forbidden_ability', __( 'Customer-agent abilities may only be narrowed to approved capabilities.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
			}
			$requested_abilities = $this->sanitize_string_list( $request_context['abilities'], false );
			if ( ! empty( array_diff( $requested_abilities, $profile['abilities'] ) ) ) {
				return new WP_Error( 'sd_ai_agent_customer_agent_forbidden_ability', __( 'Customer-agent abilities may only be narrowed to approved capabilities.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
			}
			$profile['abilities'] = $requested_abilities;
		}

		$collection_key = array_key_exists( 'collection_slugs', $request_context ) ? 'collection_slugs' : 'collections';
		if ( array_key_exists( $collection_key, $request_context ) ) {
			if ( ! is_array( $request_context[ $collection_key ] ) ) {
				return new WP_Error( 'sd_ai_agent_customer_agent_forbidden_collection', __( 'Customer-agent collections may only be narrowed to approved collections.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
			}
			$requested_collections = $this->sanitize_string_list( $request_context[ $collection_key ], true );
			if ( ! empty( array_diff( $requested_collections, $profile['collections'] ) ) ) {
				return new WP_Error( 'sd_ai_agent_customer_agent_forbidden_collection', __( 'Customer-agent collections may only be narrowed to approved collections.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
			}
			$profile['collections'] = $requested_collections;
		}

		return $profile;
	}

	/**
	 * Keep a durable job at least as constrained as the policy it was queued with.
	 *
	 * @param array $profile  Current profile state.
	 * @param array $snapshot Queued profile snapshot.
	 * @return array|WP_Error
	 * @phpstan-param RuntimeProfile $profile
	 * @phpstan-param array<string,mixed> $snapshot
	 * @phpstan-return RuntimeProfile
	 */
	private function restrict_profile_to_snapshot( array $profile, array $snapshot ): array {
		if ( array_key_exists( 'abilities', $snapshot ) ) {
			$snapshot_abilities   = $this->sanitize_string_list( $snapshot['abilities'], false );
			$profile['abilities'] = array_values( array_intersect( $profile['abilities'], $snapshot_abilities ) );
		}
		if ( array_key_exists( 'collections', $snapshot ) ) {
			$snapshot_collections   = $this->sanitize_string_list( $snapshot['collections'], true );
			$profile['collections'] = array_values( array_intersect( $profile['collections'], $snapshot_collections ) );
		}
		foreach ( array( 'max_message_length', 'max_history_turns', 'max_iterations', 'max_runtime_seconds' ) as $key ) {
			if ( isset( $snapshot[ $key ] ) ) {
				$profile[ $key ] = min( (int) $profile[ $key ], max( 1, (int) $snapshot[ $key ] ) );
			}
		}

		return $profile;
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

		$request_context = isset( $request['context'] ) && is_array( $request['context'] )
			? $this->normalise_associative_array( $request['context'] ) ?? array()
			: array();
		$history         = $this->deserialize_runtime_history( (string) $conversation['runtime_history'], $profile['max_history_turns'] );
		$options         = array(
			// This explicitly locked instruction is composed only from the
			// customer-safe path; SystemInstructionBuilder is never invoked.
			'system_instruction'            => CustomerAgentPromptComposer::compose( $profile['support_instructions'], $profile['collections'], $request_context ),
			'max_iterations'                => $profile['max_iterations'],
			'provider_id'                   => $profile['provider_id'],
			'model_id'                      => $profile['model_id'],
			'page_context'                  => array(
				'customer_agent_runtime' => true,
				'profile_id'             => $profile['profile_id'],
			),
			'anonymous_allowed_abilities'   => $profile['abilities'],
			'anonymous_allowed_collections' => $profile['collections'],
			'customer_agent_mode'           => true,
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
			$handoff      = $this->normalise_handoff( $payload['handoff'] ?? null );
			if ( null !== $handoff ) {
				$dto['handoff'] = $handoff;
			}
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
	 * Separate a model's structured display response from its handoff decision.
	 *
	 * JSON decoding is intentionally structural: this never guesses intent by
	 * phrase matching generated prose. Non-JSON model replies remain compatible
	 * with existing consumers and simply have no handoff signal.
	 *
	 * @param array<string,mixed> $result AgentLoop or test-executor result.
	 * @return array{reply:string,handoff:array{intent:string,reason:string}|null}
	 */
	private function normalise_customer_response( array $result ): array {
		$reply   = (string) ( $result['reply'] ?? '' );
		$handoff = $this->normalise_handoff( $result['handoff'] ?? null );
		$decoded = json_decode( trim( $reply ), true );

		if ( is_array( $decoded ) && isset( $decoded['display_text'] ) && is_string( $decoded['display_text'] ) ) {
			$reply = $decoded['display_text'];
			if ( null === $handoff ) {
				$handoff = $this->normalise_handoff( $decoded['handoff'] ?? null );
			}
		} elseif ( is_array( $decoded ) ) {
			$handoff = $handoff ?? $this->normalise_handoff( $decoded['handoff'] ?? null );
			$reply   = __( 'Sorry, that response could not be prepared. Please try again or ask for human support.', 'superdav-ai-agent' );
		}

		return array(
			'reply'   => JobErrorSanitizer::sanitize( $reply, self::MAX_REPLY_LENGTH ),
			'handoff' => $handoff,
		);
	}

	/**
	 * Validate a model-provided handoff object without inspecting display prose.
	 *
	 * @param mixed $candidate Structured model output only.
	 * @return array{intent:string,reason:string}|null
	 */
	private function normalise_handoff( mixed $candidate ): ?array {
		if ( ! is_array( $candidate ) || ! isset( $candidate['intent'] ) || ! is_string( $candidate['intent'] ) ) {
			return null;
		}

		$intent = sanitize_key( $candidate['intent'] );
		if ( ! in_array( $intent, array( 'human_support', 'private_data_required', 'insufficient_evidence', 'unsafe_request' ), true ) ) {
			return null;
		}

		$reason = isset( $candidate['reason'] ) && is_scalar( $candidate['reason'] )
			? JobErrorSanitizer::sanitize( (string) $candidate['reason'], 500 )
			: '';
		if ( '' === $reason ) {
			$reason = __( 'A human support specialist is needed to continue safely.', 'superdav-ai-agent' );
		}

		return array(
			'intent' => $intent,
			'reason' => $reason,
		);
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
			$this->record_runtime_review_status( $job, 'failed', 'sd_ai_agent_customer_agent_timeout' );
			ActiveJobRepository::record_failure(
				$job_id,
				'error',
				ActiveJobFailureDiagnostic::REASON_PROVIDER_TIMEOUT,
				array( 'last_safe_phase' => 'customer_agent_runtime' )
			);
			$timed_out = CustomerAgentRuntimeRepository::get_job( $job_id );
			if ( null !== $timed_out ) {
				$this->emit_lifecycle_event( 'failed', $timed_out );
			}
		}
	}

	/**
	 * Persist a sanitized failure only while cancellation has not already won.
	 *
	 * @param array<string,mixed> $job                Durable job row.
	 * @param string              $error_code         Customer-runtime error code.
	 * @param string              $detail             Potentially unsafe failure detail.
	 * @param string              $reason             Normalized terminal reason.
	 * @param array<string,mixed> $diagnostic_context Allowlisted diagnostic metadata.
	 */
	private function fail_job( array $job, string $error_code, string $detail, string $reason = ActiveJobFailureDiagnostic::REASON_UNKNOWN, array $diagnostic_context = array() ): void {
		$job_id                                = (string) $job['job_id'];
		$message                               = $this->customer_safe_error_message( $detail );
		$diagnostic_context['last_safe_phase'] = 'customer_agent_runtime';
		if ( CustomerAgentRuntimeRepository::mark_failed( $job_id, current_time( 'mysql', true ), $error_code, $message ) ) {
			$this->record_runtime_review_status( $job, 'failed', $error_code );
			ActiveJobRepository::record_failure(
				$job_id,
				'error',
				$reason,
				$diagnostic_context
			);
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
	 * Create the isolated review projection for a newly queued customer turn.
	 *
	 * @param array<string,mixed> $conversation Durable runtime conversation row.
	 * @param array<string,mixed> $profile      Resolved constrained profile.
	 */
	private function record_runtime_review_on_enqueue( array $conversation, array $profile, string $job_id, string $message, string $expires_at ): void {
		try {
			$profile_key     = (string) ( $profile['profile_key'] ?? '' );
			$agent           = '' !== $profile_key ? Agent::get_by_managed_profile_key( $profile_key ) : null;
			$agent_id        = null !== $agent ? (int) $agent->id : 0;
			$conversation_id = (string) ( $conversation['conversation_id'] ?? '' );
			CustomerConversationReviewRepository::create_runtime_review(
				wp_generate_uuid4(),
				$conversation_id,
				(string) ( $profile['provider_id'] ?? '' ),
				(string) ( $profile['model_id'] ?? '' ),
				$expires_at,
				$agent_id
			);
			CustomerConversationReviewRepository::append_runtime_turn(
				$conversation_id,
				$job_id,
				'user',
				$message,
				'queued',
				array(
					'provider_id' => (string) ( $profile['provider_id'] ?? '' ),
					'model_id'    => (string) ( $profile['model_id'] ?? '' ),
					'expires_at'  => $expires_at,
				)
			);
		} catch ( \Throwable $exception ) {
			// Review persistence is intentionally non-blocking for customer traffic.
		}
	}

	/**
	 * Write the completed display projection after the runtime conditional update wins.
	 *
	 * @param array<string,mixed>                                                 $job      Durable runtime job row.
	 * @param array{reply:string,handoff:array{intent:string,reason:string}|null} $response Safe response data.
	 */
	private function record_runtime_review_completion( array $job, array $response, string $provider_id, string $model_id, int $iterations_used, int $prompt_tokens, int $completion_tokens, string $expires_at ): void {
		$handoff_intent = is_array( $response['handoff'] ?? null ) && is_string( $response['handoff']['intent'] ?? null )
			? $response['handoff']['intent']
			: '';

		try {
			CustomerConversationReviewRepository::append_runtime_turn(
				(string) ( $job['conversation_id'] ?? '' ),
				(string) ( $job['job_id'] ?? '' ),
				'assistant',
				(string) ( $response['reply'] ?? '' ),
				'complete',
				array(
					'provider_id'       => $provider_id,
					'model_id'          => $model_id,
					'iterations_used'   => $iterations_used,
					'prompt_tokens'     => $prompt_tokens,
					'completion_tokens' => $completion_tokens,
					'handoff_intent'    => $handoff_intent,
					'expires_at'        => $expires_at,
				)
			);
		} catch ( \Throwable $exception ) {
			// Review persistence is intentionally non-blocking for customer traffic.
		}
	}

	/**
	 * Persist a safe terminal review status without retaining exception detail.
	 *
	 * @param array<string,mixed> $job Durable runtime job row.
	 */
	private function record_runtime_review_status( array $job, string $status, string $error_code = '' ): void {
		try {
			CustomerConversationReviewRepository::update_runtime_review_status(
				(string) ( $job['conversation_id'] ?? '' ),
				(string) ( $job['job_id'] ?? '' ),
				$status,
				array(
					'provider_id' => (string) ( $job['provider_id'] ?? '' ),
					'model_id'    => (string) ( $job['model_id'] ?? '' ),
					'error_code'  => $error_code,
					'expires_at'  => (string) ( $job['expires_at'] ?? '' ),
				)
			);
		} catch ( \Throwable $exception ) {
			// Review persistence is intentionally non-blocking for customer traffic.
		}
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
