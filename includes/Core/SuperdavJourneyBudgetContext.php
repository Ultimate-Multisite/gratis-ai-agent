<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the narrowly scoped r002 managed-edge journey reservation.
 *
 * The option contains attribution only. It must never contain an edge token,
 * customer data, prompt content, or an endpoint URL.
 */
final class SuperdavJourneyBudgetContext {

	public const OPTION_NAME = 'sd_ai_agent_superdav_r002_journey_context';
	public const RUN_MARKER  = 'r002';
	public const QA_EMAIL    = 'qa-contact@example.com';

	/** Activate the fixed QA reservation context without autoloading it. */
	public static function activate( string $journey_id, int $qa_user_id, string $expires_at ): bool {
		$user = $qa_user_id > 0 ? get_userdata( $qa_user_id ) : false;
		if (
			! self::is_valid_journey_id( $journey_id )
			|| ! $user instanceof \WP_User
			|| self::QA_EMAIL !== $user->user_email
			|| ! self::is_valid_utc_expiry( $expires_at )
			|| strtotime( $expires_at ) <= time()
		) {
			return false;
		}
		$context = array(
			'journey_id' => strtolower( $journey_id ),
			'run_marker' => self::RUN_MARKER,
			'qa_user_id' => $qa_user_id,
			'expires_at' => $expires_at,
		);
		if ( $context === get_option( self::OPTION_NAME, null ) ) {
			return true;
		}

		return update_option(
			self::OPTION_NAME,
			$context,
			false
		);
	}

	/** Remove the active QA reservation context. */
	public static function deactivate(): bool {
		return false === get_option( self::OPTION_NAME, false ) || delete_option( self::OPTION_NAME );
	}

	/**
	 * Resolve the reservation for a server-owned chat session.
	 *
	 * @return string|\WP_Error|null Opaque journey ID, local validation error, or no matching context.
	 */
	public static function resolve_for_session( int $session_id ): string|\WP_Error|null {
		$context = get_option( self::OPTION_NAME, null );
		if ( ! is_array( $context ) ) {
			return null;
		}

		$session = $session_id > 0 ? Database::get_session( $session_id ) : null;
		$user_id = is_object( $session ) ? (int) ( $session->user_id ?? 0 ) : 0;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		$email   = $user instanceof \WP_User ? $user->user_email : '';

		// A reservation can never leak onto a different user's session.
		if ( self::QA_EMAIL !== $email ) {
			return null;
		}

		$journey_id = $context['journey_id'] ?? null;
		$run_marker = $context['run_marker'] ?? null;
		$qa_user_id = $context['qa_user_id'] ?? null;
		$expires_at = $context['expires_at'] ?? null;
		if (
			! is_string( $journey_id )
			|| ! is_string( $run_marker )
			|| ! is_int( $qa_user_id )
			|| ! is_string( $expires_at )
			|| self::RUN_MARKER !== $run_marker
			|| $user_id !== $qa_user_id
			|| ! self::is_valid_journey_id( $journey_id )
			|| ! self::is_valid_utc_expiry( $expires_at )
			|| strtotime( $expires_at ) <= time()
		) {
			return new \WP_Error(
				'sd_ai_agent_journey_context_invalid',
				__( 'The managed QA journey reservation is invalid or expired. The request was not sent.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		return strtolower( $journey_id );
	}

	/** Validate a UUID-shaped opaque journey identifier. */
	private static function is_valid_journey_id( string $journey_id ): bool {
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $journey_id );
	}

	/** Validate the canonical UTC expiry representation. */
	private static function is_valid_utc_expiry( string $expires_at ): bool {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d\\TH:i:s\\Z', $expires_at, new \DateTimeZone( 'UTC' ) );
		return $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d\\TH:i:s\\Z' ) === $expires_at;
	}
}
