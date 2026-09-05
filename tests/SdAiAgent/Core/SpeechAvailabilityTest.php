<?php

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\SpeechAvailability;
use WP_UnitTestCase;

/** Covers the stable, safe managed-speech availability contract. */
final class SpeechAvailabilityTest extends WP_UnitTestCase {

	public function test_defaults_to_available_when_all_authoritative_inputs_allow_speech(): void {
		$this->assertSame( array( 'available' => true, 'reason' => SpeechAvailability::AVAILABLE ), SpeechAvailability::for_conditions( true )->to_array() );
	}

	/** @dataProvider unavailable_conditions */
	public function test_returns_a_stable_reason_for_each_unavailable_condition( bool $feature, bool $connected, bool $entitled, bool $capable, bool $public_site, string $reason ): void {
		$result = SpeechAvailability::for_conditions( $feature, $connected, $entitled, $capable, $public_site );
		$this->assertFalse( $result->is_available() );
		$this->assertSame( $reason, $result->reason() );
		$this->assertTrue( SpeechAvailability::is_reason( $result->reason() ) );
	}

	/** @return array<string, array{bool, bool, bool, bool, bool, string}> */
	public static function unavailable_conditions(): array {
		return array(
			'feature' => array( false, true, true, true, true, SpeechAvailability::FEATURE_DISABLED ),
			'connection' => array( true, false, true, true, true, SpeechAvailability::CONNECTION_UNAVAILABLE ),
			'entitlement' => array( true, true, false, true, true, SpeechAvailability::ENTITLEMENT_UNAVAILABLE ),
			'capability' => array( true, true, true, false, true, SpeechAvailability::CAPABILITY_UNAVAILABLE ),
			'public site' => array( true, true, true, true, false, SpeechAvailability::PUBLIC_SITE_DISABLED ),
		);
	}
}
