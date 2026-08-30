<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates opaque identifiers used by managed journey requests. */
final class SuperdavManagedRequestIdentifiers {

	/** Validate the managed edge's canonical journey identifier. */
	public static function is_journey_id( string $journey_id ): bool {
		return str_starts_with( $journey_id, 'journey_' ) && self::is_uuid( substr( $journey_id, 8 ) );
	}

	/** Validate a logical-request idempotency key. */
	public static function is_idempotency_key( string $idempotency_key ): bool {
		return self::is_uuid( $idempotency_key );
	}

	/** Validate an RFC 4122 UUID from the accepted version family. */
	private static function is_uuid( string $identifier ): bool {
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $identifier );
	}
}
