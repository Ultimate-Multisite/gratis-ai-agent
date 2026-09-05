<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiTextToSpeechConversionModel;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiTranscriptionClient;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared authorization and abuse controls for anonymous public chat traffic.
 */
final class PublicChatSecurity {

	public const SESSION_TTL = DAY_IN_SECONDS;

	private const TOKEN_PURPOSE                   = 'public_chat_session_v2';
	private const SYNTHESIS_GRANT_PURPOSE         = 'public_chat_synthesis_v1';
	private const SYNTHESIS_GRANT_TTL             = 2 * MINUTE_IN_SECONDS;
	private const SPEECH_RATE_LIMIT_PER_MINUTE    = 4;
	private const SPEECH_SITE_CONCURRENCY         = 3;
	private const SPEECH_SESSION_SECONDS_BUDGET   = 300;
	private const SPEECH_SITE_SECONDS_BUDGET      = 36000;
	private const SPEECH_SESSION_CHARACTER_BUDGET = 10000;
	private const SPEECH_SITE_CHARACTER_BUDGET    = 500000;

	private Settings $settings;
	private SpeechLocaleResolver $locale_resolver;

	public function __construct( ?Settings $settings = null, ?SpeechLocaleResolver $locale_resolver = null ) {
		$this->settings        = $settings ?? Settings::instance();
		$this->locale_resolver = $locale_resolver ?? new SpeechLocaleResolver();
	}

	/**
	 * Return the complete server-side public-chat policy with bounded speech settings.
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
	public function settings(): array {
		$settings = $this->settings->get();
		$allowed  = $settings['public_chat_allowed_abilities'] ?? array( 'sd-ai-agent/knowledge-search' );
		$allowed  = is_array( $allowed ) ? $this->sanitize_string_list( $allowed, 'sanitize_text_field' ) : array();

		$collections = $settings['public_chat_collection_ids'] ?? array();
		$collections = is_array( $collections ) ? $this->sanitize_string_list( $collections, 'sanitize_key' ) : array();

		$origins = $settings['public_chat_allowed_origins'] ?? array();
		$origins = is_array( $origins ) ? $this->sanitize_string_list( $origins, 'sanitize_text_field' ) : array();

		$review_retention_days = max( 1, min( 90, (int) ( $settings['public_chat_review_retention_days'] ?? 7 ) ) );
		$review_disclosure     = sanitize_textarea_field( (string) ( $settings['public_chat_review_disclosure'] ?? '' ) );
		if ( '' === $review_disclosure ) {
			$review_disclosure = sprintf(
				/* translators: %d: maximum number of days an opted-in anonymous chat is retained. */
				__( 'This conversation may be recorded for quality review and retained for up to %d days.', 'superdav-ai-agent' ),
				$review_retention_days
			);
		}

		$speech_disclosure = sanitize_textarea_field( (string) ( $settings['public_chat_speech_disclosure'] ?? '' ) );
		if ( '' === $speech_disclosure ) {
			$speech_disclosure = __( 'Microphone audio is sent to this site’s managed AI service for transcription and is not retained as audio.', 'superdav-ai-agent' );
		}

		$voice = sanitize_key( (string) ( $settings['public_chat_speech_voice'] ?? 'auto' ) );
		if ( 'auto' !== $voice && ! in_array( $voice, SuperdavAiTextToSpeechConversionModel::SUPPORTED_VOICES, true ) ) {
			$voice = 'auto';
		}

		$max_recording_seconds = max(
			1,
			min(
				60,
				SuperdavAiTranscriptionClient::MAX_DURATION_SECONDS,
				(int) ( $settings['public_chat_speech_max_recording_seconds'] ?? 30 )
			)
		);
		$max_tts_characters    = max(
			1,
			min(
				1000,
				(int) ( $settings['public_chat_speech_max_tts_characters'] ?? 1000 )
			)
		);

		return array(
			'enabled'                      => (bool) ( $settings['public_chat_enabled'] ?? false ),
			'origins'                      => $origins,
			'provider_id'                  => sanitize_text_field( (string) ( $settings['public_chat_provider_id'] ?? '' ) ),
			'model_id'                     => sanitize_text_field( (string) ( $settings['public_chat_model_id'] ?? '' ) ),
			'agent_id'                     => absint( $settings['public_chat_agent_id'] ?? 0 ),
			'embed_id'                     => sanitize_key( (string) ( $settings['public_chat_embed_id'] ?? 'docs' ) ),
			'collections'                  => $collections,
			'abilities'                    => $allowed,
			'iterations'                   => max( 1, min( 8, (int) ( $settings['public_chat_max_iterations'] ?? 4 ) ) ),
			'message_length'               => max( 1, min( 8000, (int) ( $settings['public_chat_message_max_length'] ?? 2000 ) ) ),
			'rate_limit'                   => max( 1, min( 60, (int) ( $settings['public_chat_rate_limit_per_min'] ?? 10 ) ) ),
			'review_recording_enabled'     => (bool) ( $settings['public_chat_review_recording_enabled'] ?? false ),
			'review_retention_days'        => $review_retention_days,
			'review_disclosure'            => $review_disclosure,
			'speech_enabled'               => (bool) ( $settings['public_chat_speech_enabled'] ?? false ),
			'speech_voice'                 => $voice,
			'speech_max_recording_seconds' => $max_recording_seconds,
			'speech_max_audio_bytes'       => min( SuperdavAiTranscriptionClient::MAX_AUDIO_BYTES, 44 + ( $max_recording_seconds * 32000 ) ),
			'speech_max_tts_characters'    => $max_tts_characters,
			'speech_voice_mode_enabled'    => (bool) ( $settings['public_chat_speech_voice_mode_enabled'] ?? false ),
			'speech_disclosure'            => $speech_disclosure,
		);
	}

	/** Validate public chat, optional speech, and the request origin. */
	public function check_available( WP_REST_Request $request, bool $require_speech = false ): true|WP_Error {
		$config = $this->settings();
		if ( empty( $config['enabled'] ) ) {
			return new WP_Error( 'sd_ai_agent_public_chat_disabled', __( 'Public chat is not enabled.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}
		if ( empty( $config['collections'] ) ) {
			return new WP_Error( 'sd_ai_agent_public_chat_unconfigured', __( 'Public chat has no documentation collection configured.', 'superdav-ai-agent' ), array( 'status' => 503 ) );
		}
		$availability = SpeechAvailability::for_conditions(
			Features::is_enabled( Features::SPEECH ),
			true,
			true,
			true,
			! empty( $config['speech_enabled'] )
		);
		if ( $require_speech && ! $availability->is_available() ) {
			return new WP_Error( 'sd_ai_agent_public_speech_disabled', __( 'Public speech is not enabled.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		$origin = $this->request_origin( $request );
		if ( ! $this->origin_is_allowed( $origin, $config['origins'] ) ) {
			return new WP_Error( 'sd_ai_agent_public_chat_origin_forbidden', __( 'This origin is not allowed to use public chat.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/** Resolve the public-chat request origin or referer. */
	public function request_origin( WP_REST_Request $request ): string {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin ) {
			$origin = (string) $request->get_header( 'referer' );
		}

		return $origin;
	}

	/** Return an opaque stable binding for one normalized origin. */
	public function origin_binding( string $origin ): string {
		return $this->origin_hash( $origin );
	}

	/**
	 * Add public CORS headers only for an allowlisted request origin.
	 *
	 * @param WP_REST_Response $response        REST response.
	 * @param string           $origin          Request origin.
	 * @param list<string>     $allowed_origins Configured origin allowlist.
	 */
	// phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<string> is a PHPStan type.
	public function add_cors( WP_REST_Response $response, string $origin, array $allowed_origins ): WP_REST_Response {
		if ( $this->origin_is_allowed( $origin, $allowed_origins ) ) {
			$response->header( 'Access-Control-Allow-Origin', $origin );
			$response->header( 'Access-Control-Allow-Credentials', 'false' );
			$response->header( 'Vary', 'Origin' );
		}

		return $response;
	}

	/**
	 * Check a request origin against the configured public allowlist.
	 *
	 * @param string       $origin          Request origin.
	 * @param list<string> $allowed_origins Configured origin allowlist.
	 */
	// phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<string> is a PHPStan type.
	public function origin_is_allowed( string $origin, array $allowed_origins ): bool {
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

	/** Create a signed token bound to the configured embed and request origin. */
	public function create_token( string $session_uuid, string $embed_id, string $origin ): string {
		$payload = wp_json_encode(
			array(
				'sid' => $session_uuid,
				'eid' => $embed_id,
				'org' => $this->origin_hash( $origin ),
				'exp' => time() + self::SESSION_TTL,
			)
		);
		$payload = false === $payload ? '{}' : $payload;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe HMAC token encoding.
		$body = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
		$sig  = hash_hmac( 'sha256', self::TOKEN_PURPOSE . '|' . $body, wp_salt( 'auth' ) );

		return $body . '.' . $sig;
	}

	/** @return array{sid:string,eid:string,org:string,exp:int}|WP_Error */
	public function parse_token( string $token ): array|WP_Error {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return $this->invalid_token_error();
		}

		[ $body, $sig ] = $parts;
		$expected       = hash_hmac( 'sha256', self::TOKEN_PURPOSE . '|' . $body, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return $this->invalid_token_error();
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a verified URL-safe token payload.
		$decoded = base64_decode( strtr( $body, '-_', '+/' ), true );
		$data    = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $data ) || ! is_string( $data['sid'] ?? null ) || ! is_string( $data['eid'] ?? null )
			|| ! is_string( $data['org'] ?? null ) || empty( $data['exp'] ) || (int) $data['exp'] < time()
		) {
			return $this->invalid_token_error();
		}

		return array(
			'sid' => sanitize_key( $data['sid'] ),
			'eid' => sanitize_key( $data['eid'] ),
			'org' => sanitize_text_field( $data['org'] ),
			'exp' => (int) $data['exp'],
		);
	}

	/** Public session transient key. */
	public function session_key( string $session_uuid ): string {
		return 'sd_ai_agent_public_chat_' . md5( $session_uuid );
	}

	/**
	 * Resolve an active token/session and enforce its embed and origin bindings.
	 *
	 * @return array{session_uuid:string,session:array<int|string,mixed>,origin:string,token_hash:string}|WP_Error
	 */
	public function authorize_session( WP_REST_Request $request, string $token, string $embed_id = '', bool $require_speech = false ): array|WP_Error {
		$available = $this->check_available( $request, $require_speech );
		if ( true !== $available ) {
			return $available;
		}

		$config = $this->settings();
		$parsed = $this->parse_token( $token );
		if ( $parsed instanceof WP_Error ) {
			return $parsed;
		}

		$origin  = $this->request_origin( $request );
		$session = get_transient( $this->session_key( $parsed['sid'] ) );
		if ( ! is_array( $session ) ) {
			return new WP_Error( 'sd_ai_agent_public_chat_session_expired', __( 'Public chat session expired.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}

		$configured_embed = (string) $config['embed_id'];
		$session_embed    = isset( $session['embed_id'] ) && is_string( $session['embed_id'] ) ? $session['embed_id'] : '';
		$session_origin   = isset( $session['origin_hash'] ) && is_string( $session['origin_hash'] ) ? $session['origin_hash'] : '';
		if ( '' === $configured_embed || $parsed['eid'] !== $configured_embed || $session_embed !== $configured_embed
			|| ( '' !== $embed_id && $embed_id !== $configured_embed )
			|| ! hash_equals( $parsed['org'], $this->origin_hash( $origin ) )
			|| ! hash_equals( $session_origin, $this->origin_hash( $origin ) )
		) {
			return $this->invalid_token_error();
		}

		return array(
			'session_uuid' => $parsed['sid'],
			'session'      => $session,
			'origin'       => $origin,
			'token_hash'   => hash( 'sha256', $token ),
		);
	}

	/** Consume a scoped per-session/IP operation token. */
	public function check_rate_limit( string $session_uuid, int $limit, string $scope = 'chat' ): true|WP_Error {
		$ip  = sanitize_text_field( wp_unslash( (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) ) );
		$key = 'sd_ai_agent_public_' . sanitize_key( $scope ) . '_rate_' . md5( $session_uuid . '|' . $ip . '|' . gmdate( 'YmdHi' ) );
		$hit = (int) get_transient( $key );
		if ( $hit >= $limit ) {
			return new WP_Error( 'sd_ai_agent_public_chat_rate_limited', __( 'Too many public requests. Please wait before trying again.', 'superdav-ai-agent' ), array( 'status' => 429 ) );
		}

		set_transient( $key, $hit + 1, MINUTE_IN_SECONDS + 5 );
		return true;
	}

	/** Consume the stricter speech-specific request rate. */
	public function check_speech_rate_limit( string $session_uuid ): true|WP_Error {
		return $this->check_rate_limit( $session_uuid, self::SPEECH_RATE_LIMIT_PER_MINUTE, 'speech' );
	}

	/**
	 * Acquire one per-session and one bounded site-wide speech execution slot.
	 *
	 * @return array{owner:string,keys:list<string>}|WP_Error
	 */
	public function acquire_speech_lock( string $session_uuid ): array|WP_Error {
		$owner       = wp_generate_uuid4();
		$expires_at  = time() + 60;
		$session_key = 'sd_ai_agent_public_speech_lock_' . md5( $session_uuid );
		if ( ! $this->acquire_option_lock( $session_key, $owner, $expires_at ) ) {
			return $this->speech_busy_error();
		}

		for ( $slot = 0; $slot < self::SPEECH_SITE_CONCURRENCY; ++$slot ) {
			$site_key = 'sd_ai_agent_public_speech_site_lock_' . $slot;
			if ( $this->acquire_option_lock( $site_key, $owner, $expires_at ) ) {
				return array(
					'owner' => $owner,
					'keys'  => array( $session_key, $site_key ),
				);
			}
		}

		$this->release_option_lock( $session_key, $owner );
		return $this->speech_busy_error();
	}

	/** @param array{owner:string,keys:list<string>} $lock */
	public function release_speech_lock( array $lock ): void {
		foreach ( $lock['keys'] as $key ) {
			$this->release_option_lock( $key, $lock['owner'] );
		}
	}

	/** Reserve bounded anonymous speech units before upstream spend. */
	public function consume_speech_budget( string $session_uuid, string $operation, int $units ): true|WP_Error {
		$is_transcription = 'transcription' === $operation;
		$session_limit    = $is_transcription ? self::SPEECH_SESSION_SECONDS_BUDGET : self::SPEECH_SESSION_CHARACTER_BUDGET;
		$site_limit       = $is_transcription ? self::SPEECH_SITE_SECONDS_BUDGET : self::SPEECH_SITE_CHARACTER_BUDGET;
		$unit_key         = $is_transcription ? 'seconds' : 'characters';
		$session_key      = 'sd_ai_agent_public_speech_' . $unit_key . '_' . md5( $session_uuid );
		$site_key         = 'sd_ai_agent_public_speech_site_' . $unit_key . '_' . gmdate( 'Ymd' );
		$session_used     = (int) get_transient( $session_key );
		$site_used        = (int) get_transient( $site_key );

		if ( $units < 1 || $session_used + $units > $session_limit || $site_used + $units > $site_limit ) {
			return new WP_Error( 'sd_ai_agent_public_speech_limit_exceeded', __( 'The public speech limit was reached. Please continue with typed chat.', 'superdav-ai-agent' ), array( 'status' => 429 ) );
		}

		set_transient( $session_key, $session_used + $units, self::SESSION_TTL );
		set_transient( $site_key, $site_used + $units, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		return true;
	}

	/** Save a normalized detected language as an ephemeral session hint. */
	public function update_session_language( string $session_uuid, mixed $language ): void {
		$language = is_string( $language ) ? $this->locale_resolver->normalize_client_locale( $language ) : null;
		if ( null === $language ) {
			return;
		}

		$key     = $this->session_key( $session_uuid );
		$session = get_transient( $key );
		if ( ! is_array( $session ) ) {
			return;
		}
		$session['speech_locale'] = $language;
		set_transient( $key, $session, self::SESSION_TTL );
	}

	/** Normalize an optional public client locale. */
	public function normalize_locale( mixed $locale ): string|null|WP_Error {
		if ( null === $locale || '' === $locale ) {
			return null;
		}
		if ( ! is_string( $locale ) ) {
			return $this->invalid_language_error();
		}

		$normalized = $this->locale_resolver->normalize_client_locale( $locale );
		return null !== $normalized ? $normalized : $this->invalid_language_error();
	}

	/**
	 * Emit content-free public speech telemetry for an operator-provided sink.
	 *
	 * @param string $operation        Availability, transcription, or synthesis.
	 * @param string $outcome          Fixed success or failure category.
	 * @param int    $status_code      HTTP-style result status.
	 * @param float  $started_at       Request start from microtime(true).
	 * @param int    $audio_bytes      Validated recording bytes, when known.
	 * @param float  $duration_seconds Validated recording duration, when known.
	 */
	public function record_speech_metric( string $operation, string $outcome, int $status_code, float $started_at, int $audio_bytes = 0, float $duration_seconds = 0.0 ): void {
		$operations = array( 'availability', 'transcription', 'synthesis' );
		$outcomes   = array( 'available', 'unavailable', 'success', 'permission_denied', 'limit_denied', 'validation_failed', 'failed' );
		$operation  = in_array( $operation, $operations, true ) ? $operation : 'availability';
		$outcome    = in_array( $outcome, $outcomes, true ) ? $outcome : 'failed';
		$latency_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		/**
		 * Fires content-free public speech metrics for an external metrics sink.
		 *
		 * No transcript, audio, token, IP address, origin, user agent, or session
		 * identifier is included in this deliberately low-cardinality envelope.
		 *
		 * @param array<string,int|string> $metric Safe metric dimensions.
		 */
		do_action(
			'sd_ai_agent_public_speech_metric',
			array(
				'operation'       => $operation,
				'outcome'         => $outcome,
				'status_code'     => max( 0, $status_code ),
				'latency_bucket'  => $this->latency_bucket( $latency_ms ),
				'bytes_bucket'    => $this->bytes_bucket( $audio_bytes ),
				'duration_bucket' => $this->duration_bucket( $duration_seconds ),
			)
		);
	}

	/**
	 * Issue one short-lived grant for the exact completed assistant reply.
	 *
	 * @return array{grant:string,expires_in:int}|null
	 */
	public function issue_synthesis_grant( string $session_uuid, string $token, string $origin, string $reply ): ?array {
		$config = $this->settings();
		if ( empty( $config['speech_enabled'] ) ) {
			return null;
		}

		$session = get_transient( $this->session_key( $session_uuid ) );
		if ( ! is_array( $session ) || ! hash_equals( (string) ( $session['origin_hash'] ?? '' ), $this->origin_hash( $origin ) ) ) {
			return null;
		}

		$text = $this->speakable_excerpt( $reply, (int) $config['speech_max_tts_characters'] );
		if ( '' === $text ) {
			return null;
		}

		$grant_id = wp_generate_uuid4();
		$expires  = time() + self::SYNTHESIS_GRANT_TTL;
		$voice    = 'auto' === $config['speech_voice'] ? SuperdavAiTextToSpeechConversionModel::DEFAULT_VOICE : (string) $config['speech_voice'];
		$language = isset( $session['speech_locale'] ) && is_string( $session['speech_locale'] ) ? $session['speech_locale'] : '';
		if ( 'en' === $language ) {
			$language = 'en-US';
		}
		if ( ! in_array( $language, SuperdavAiTextToSpeechConversionModel::SUPPORTED_LANGUAGES, true ) ) {
			$language = '';
		}

		$state = array(
			'grant_id'    => $grant_id,
			'sid'         => $session_uuid,
			'token_hash'  => hash( 'sha256', $token ),
			'origin_hash' => $this->origin_hash( $origin ),
			'embed_id'    => (string) $config['embed_id'],
			'text'        => $text,
			'text_hash'   => hash( 'sha256', $text ),
			'voice'       => $voice,
			'language'    => $language,
			'exp'         => $expires,
		);
		set_transient( $this->grant_key( $grant_id ), $state, self::SYNTHESIS_GRANT_TTL );

		$payload = wp_json_encode(
			array(
				'gid' => $grant_id,
				'sid' => $session_uuid,
				'eid' => (string) $config['embed_id'],
				'org' => $state['origin_hash'],
				'txt' => $state['text_hash'],
				'exp' => $expires,
			)
		);
		if ( false === $payload ) {
			delete_transient( $this->grant_key( $grant_id ) );
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe HMAC grant encoding.
		$body = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
		$sig  = hash_hmac( 'sha256', self::SYNTHESIS_GRANT_PURPOSE . '|' . $body, wp_salt( 'auth' ) );

		return array(
			'grant'      => $body . '.' . $sig,
			'expires_in' => self::SYNTHESIS_GRANT_TTL,
		);
	}

	/**
	 * Consume a one-purpose synthesis grant while the caller holds the session lock.
	 *
	 * @return array{text:string,voice:string,language:string}|WP_Error
	 */
	public function consume_synthesis_grant( string $grant, string $session_uuid, string $token_hash, string $origin, string $embed_id ): array|WP_Error {
		$parts = explode( '.', $grant, 2 );
		if ( 2 !== count( $parts ) ) {
			return $this->invalid_grant_error();
		}
		[ $body, $sig ] = $parts;
		$expected       = hash_hmac( 'sha256', self::SYNTHESIS_GRANT_PURPOSE . '|' . $body, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return $this->invalid_grant_error();
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a verified URL-safe grant payload.
		$decoded = base64_decode( strtr( $body, '-_', '+/' ), true );
		$data    = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $data ) || ! is_string( $data['gid'] ?? null ) || ! is_string( $data['sid'] ?? null )
			|| ! is_string( $data['eid'] ?? null ) || ! is_string( $data['org'] ?? null ) || ! is_string( $data['txt'] ?? null )
			|| empty( $data['exp'] ) || (int) $data['exp'] < time()
		) {
			return $this->invalid_grant_error();
		}

		$key   = $this->grant_key( sanitize_key( $data['gid'] ) );
		$state = get_transient( $key );
		if ( ! is_array( $state )
			|| ! hash_equals( (string) $data['sid'], $session_uuid )
			|| ! hash_equals( (string) $data['eid'], $embed_id )
			|| ! hash_equals( (string) $data['org'], $this->origin_hash( $origin ) )
			|| ! hash_equals( (string) $data['txt'], (string) ( $state['text_hash'] ?? '' ) )
			|| ! hash_equals( $token_hash, (string) ( $state['token_hash'] ?? '' ) )
			|| (int) ( $state['exp'] ?? 0 ) < time()
		) {
			return $this->invalid_grant_error();
		}

		delete_transient( $key );
		return array(
			'text'     => (string) $state['text'],
			'voice'    => (string) $state['voice'],
			'language' => (string) $state['language'],
		);
	}

	private function acquire_option_lock( string $key, string $owner, int $expires_at ): bool {
		$value = array(
			'owner' => $owner,
			'exp'   => $expires_at,
		);
		if ( add_option( $key, $value, '', false ) ) {
			return true;
		}

		$current = get_option( $key );
		if ( is_array( $current ) && (int) ( $current['exp'] ?? 0 ) < time() ) {
			delete_option( $key );
			return add_option( $key, $value, '', false );
		}

		return false;
	}

	private function release_option_lock( string $key, string $owner ): void {
		$current = get_option( $key );
		if ( is_array( $current ) && hash_equals( $owner, (string) ( $current['owner'] ?? '' ) ) ) {
			delete_option( $key );
		}
	}

	private function origin_hash( string $origin ): string {
		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return hash( 'sha256', '' );
		}
		$normalized = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$normalized .= ':' . absint( $parts['port'] );
		}

		return hash( 'sha256', $normalized );
	}

	private function grant_key( string $grant_id ): string {
		return 'sd_ai_agent_public_speech_grant_' . md5( $grant_id );
	}

	private function latency_bucket( int $milliseconds ): string {
		return match ( true ) {
			$milliseconds < 250 => 'under_250ms',
			$milliseconds < 1000 => 'under_1s',
			$milliseconds < 5000 => 'under_5s',
			default => 'at_least_5s',
		};
	}

	private function bytes_bucket( int $bytes ): string {
		return match ( true ) {
			$bytes < 1 => 'not_measured',
			$bytes <= 65536 => 'up_to_64kb',
			$bytes <= 262144 => 'up_to_256kb',
			$bytes <= 1048576 => 'up_to_1mb',
			default => 'over_1mb',
		};
	}

	private function duration_bucket( float $seconds ): string {
		return match ( true ) {
			$seconds <= 0 => 'not_measured',
			$seconds <= 5 => 'up_to_5s',
			$seconds <= 15 => 'up_to_15s',
			$seconds <= 30 => 'up_to_30s',
			default => 'over_30s',
		};
	}

	private function speakable_excerpt( string $value, int $maximum ): string {
		$text = preg_replace( '/```[\s\S]*?```/u', '', $value );
		$text = is_string( $text ) ? preg_replace( '/`([^`\r\n]+)`/u', '$1', $text ) : '';
		$text = is_string( $text ) ? preg_replace( '/!\[[^\]]*\]\([^)]+\)/u', '', $text ) : '';
		$text = is_string( $text ) ? preg_replace( '/\[([^\]]+)\]\([^)]+\)/u', '$1', $text ) : '';
		$text = is_string( $text ) ? preg_replace( '/^[#>*+\-]\s*/mu', '', $text ) : '';
		$text = is_string( $text ) ? preg_replace( '/\*{1,3}([^*\r\n]+)\*{1,3}/u', '$1', $text ) : '';
		$text = is_string( $text ) ? preg_replace( '/_{1,3}([^_\r\n]+)_{1,3}/u', '$1', $text ) : '';
		$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( is_string( $text ) ? $text : '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
		if ( ! is_string( $text ) || '' === $text || 1 !== preg_match( '/[\p{L}\p{N}]/u', $text ) ) {
			return '';
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $maximum, 'UTF-8' ) : substr( $text, 0, $maximum );
	}

	/**
	 * Sanitize a mixed public settings list.
	 *
	 * @param array<int|string,mixed> $values   Candidate values.
	 * @param callable(string):string $sanitize Sanitizer callback.
	 * @return list<string>
	 */
	private function sanitize_string_list( array $values, callable $sanitize ): array {
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

	private function invalid_token_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_public_chat_invalid_token', __( 'Invalid public chat token.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
	}

	private function invalid_grant_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_public_speech_invalid_grant', __( 'The speech playback grant is invalid or expired.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
	}

	private function invalid_language_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_invalid_speech_language', __( 'The speech language is invalid.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
	}

	private function speech_busy_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_public_speech_busy', __( 'Another speech request is already running. Please continue with typed chat or try again.', 'superdav-ai-agent' ), array( 'status' => 429 ) );
	}
}
