<?php

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\Database;
use SdAiAgent\Core\SuperdavJourneyBudgetContext;
use WP_UnitTestCase;

final class SuperdavJourneyBudgetContextTest extends WP_UnitTestCase {

	public function tear_down(): void {
		SuperdavJourneyBudgetContext::deactivate();
		parent::tear_down();
	}

	/** Only the exact reserved QA identity can resolve an active reservation. */
	public function test_resolve_binds_the_reservation_to_the_qa_session_owner(): void {
		$qa_user_id    = self::factory()->user->create( array( 'user_email' => SuperdavJourneyBudgetContext::QA_EMAIL ) );
		$other_user_id = self::factory()->user->create( array( 'user_email' => 'other@example.com' ) );
		$qa_session    = Database::create_session( array( 'user_id' => $qa_user_id, 'title' => 'QA' ) );
		$other_session = Database::create_session( array( 'user_id' => $other_user_id, 'title' => 'Other' ) );
		$journey_id    = 'journey_123e4567-e89b-42d3-a456-426614174000';

		$this->assertTrue( SuperdavJourneyBudgetContext::activate( $journey_id, $qa_user_id, gmdate( 'Y-m-d\\TH:i:s\\Z', time() + HOUR_IN_SECONDS ) ) );
		$this->assertSame( $journey_id, SuperdavJourneyBudgetContext::resolve_for_session( (int) $qa_session ) );
		$this->assertNull( SuperdavJourneyBudgetContext::resolve_for_session( (int) $other_session ) );
	}

	/** Activation accepts only a future reservation for the exact QA identity. */
	public function test_activate_rejects_invalid_or_non_qa_contexts_and_is_idempotent(): void {
		$qa_user_id    = self::factory()->user->create( array( 'user_email' => SuperdavJourneyBudgetContext::QA_EMAIL ) );
		$other_user_id = self::factory()->user->create( array( 'user_email' => 'other@example.com' ) );
		$journey_id    = 'journey_123e4567-e89b-42d3-a456-426614174000';
		$future        = gmdate( 'Y-m-d\\TH:i:s\\Z', time() + HOUR_IN_SECONDS );

		$this->assertFalse( SuperdavJourneyBudgetContext::activate( 'not-a-uuid', $qa_user_id, $future ) );
		$this->assertFalse( SuperdavJourneyBudgetContext::activate( '123e4567-e89b-42d3-a456-426614174000', $qa_user_id, $future ) );
		$this->assertFalse( SuperdavJourneyBudgetContext::activate( $journey_id, $other_user_id, $future ) );
		$this->assertFalse( SuperdavJourneyBudgetContext::activate( $journey_id, $qa_user_id, gmdate( 'Y-m-d\\TH:i:s\\Z', time() - HOUR_IN_SECONDS ) ) );
		$this->assertTrue( SuperdavJourneyBudgetContext::activate( $journey_id, $qa_user_id, $future ) );
		$this->assertTrue( SuperdavJourneyBudgetContext::activate( $journey_id, $qa_user_id, $future ) );
		$this->assertTrue( SuperdavJourneyBudgetContext::deactivate() );
		$this->assertTrue( SuperdavJourneyBudgetContext::deactivate() );
	}

	/** Expired or malformed contexts fail locally for the reserved identity. */
	public function test_resolve_fails_closed_for_expired_or_malformed_context(): void {
		$qa_user_id = self::factory()->user->create( array( 'user_email' => SuperdavJourneyBudgetContext::QA_EMAIL ) );
		$session_id = Database::create_session( array( 'user_id' => $qa_user_id, 'title' => 'QA' ) );

		update_option(
			SuperdavJourneyBudgetContext::OPTION_NAME,
			array(
				'journey_id' => 'journey_123e4567-e89b-42d3-a456-426614174000',
				'run_marker' => SuperdavJourneyBudgetContext::RUN_MARKER,
				'qa_user_id' => $qa_user_id,
				'expires_at' => gmdate( 'Y-m-d\\TH:i:s\\Z', time() - HOUR_IN_SECONDS ),
			),
			false
		);

		$result = SuperdavJourneyBudgetContext::resolve_for_session( (int) $session_id );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_journey_context_invalid', $result->get_error_code() );

		update_option(
			SuperdavJourneyBudgetContext::OPTION_NAME,
			array(
				'journey_id' => 'not-a-uuid',
				'run_marker' => SuperdavJourneyBudgetContext::RUN_MARKER,
				'qa_user_id' => $qa_user_id,
				'expires_at' => gmdate( 'Y-m-d\\TH:i:s\\Z', time() + HOUR_IN_SECONDS ),
			),
			false
		);
		$this->assertWPError( SuperdavJourneyBudgetContext::resolve_for_session( (int) $session_id ) );

		update_option(
			SuperdavJourneyBudgetContext::OPTION_NAME,
			array(
				'journey_id' => 'journey_123e4567-e89b-42d3-a456-426614174000',
				'run_marker' => 'another-run',
				'qa_user_id' => $qa_user_id,
				'expires_at' => gmdate( 'Y-m-d\\TH:i:s\\Z', time() + HOUR_IN_SECONDS ),
			),
			false
		);
		$this->assertWPError( SuperdavJourneyBudgetContext::resolve_for_session( (int) $session_id ) );
	}
}
