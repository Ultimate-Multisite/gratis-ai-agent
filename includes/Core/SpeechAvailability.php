<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed, low-cardinality managed-speech availability decision.
 *
 * The result is intentionally safe to expose to authenticated and anonymous
 * clients: it identifies a stable rollout category, not account, billing, or
 * provider implementation detail.
 */
final class SpeechAvailability {

	public const AVAILABLE               = 'available';
	public const FEATURE_DISABLED        = 'feature_disabled';
	public const CONNECTION_UNAVAILABLE  = 'connection_unavailable';
	public const ENTITLEMENT_UNAVAILABLE = 'entitlement_unavailable';
	public const CAPABILITY_UNAVAILABLE  = 'capability_unavailable';
	public const PUBLIC_SITE_DISABLED    = 'public_site_disabled';
	public const BROWSER_UNSUPPORTED     = 'browser_unsupported';
	public const TEMPORARY_UNAVAILABLE   = 'temporary_unavailable';

	private const REASONS = array(
		self::AVAILABLE,
		self::FEATURE_DISABLED,
		self::CONNECTION_UNAVAILABLE,
		self::ENTITLEMENT_UNAVAILABLE,
		self::CAPABILITY_UNAVAILABLE,
		self::PUBLIC_SITE_DISABLED,
		self::BROWSER_UNSUPPORTED,
		self::TEMPORARY_UNAVAILABLE,
	);

	private bool $available;
	private string $reason;

	private function __construct( bool $available, string $reason ) {
		$this->available = $available;
		$this->reason    = $reason;
	}

	/**
	 * Make a feature-first availability decision without inferring remote state.
	 */
	public static function for_conditions( bool $feature_enabled, bool $connected = true, bool $entitled = true, bool $capable = true, bool $public_site_enabled = true ): self {
		if ( ! $feature_enabled ) {
			return new self( false, self::FEATURE_DISABLED );
		}
		if ( ! $connected ) {
			return new self( false, self::CONNECTION_UNAVAILABLE );
		}
		if ( ! $entitled ) {
			return new self( false, self::ENTITLEMENT_UNAVAILABLE );
		}
		if ( ! $capable ) {
			return new self( false, self::CAPABILITY_UNAVAILABLE );
		}
		if ( ! $public_site_enabled ) {
			return new self( false, self::PUBLIC_SITE_DISABLED );
		}

		return new self( true, self::AVAILABLE );
	}

	/** Return a safe public response shape. */
	public function to_array(): array {
		return array(
			'available' => $this->available,
			'reason'    => $this->reason,
		);
	}

	/** Return the stable reason code. */
	public function reason(): string {
		return $this->reason;
	}

	/** Whether managed speech may be presented as available. */
	public function is_available(): bool {
		return $this->available;
	}

	/** Validate an externally supplied stable reason without reflecting it. */
	public static function is_reason( string $reason ): bool {
		return in_array( $reason, self::REASONS, true );
	}
}
