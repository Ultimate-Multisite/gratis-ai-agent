<?php

declare(strict_types=1);
/**
 * Test case for feedback report construction.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Feedback;

use SdAiAgent\Core\Database;
use SdAiAgent\Feedback\ReportBuilder;
use WP_UnitTestCase;

/**
 * Test targeted feedback report context.
 */
class ReportBuilderTest extends WP_UnitTestCase {

	/**
	 * Targeted feedback reports omit tool logs that cannot be mapped to the selected message window.
	 */
	public function test_targeted_report_omits_unmappable_tool_calls_and_summary_matches(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$session_id = Database::create_session(
			array(
				'user_id' => $user_id,
				'title'   => 'Targeted feedback context',
			)
		);
		$this->assertIsInt( $session_id );

		$messages = array(
			array( 'role' => 'user', 'content' => 'Before the first tool call.' ),
			array( 'role' => 'assistant', 'content' => 'First response.' ),
			array( 'role' => 'user', 'content' => 'Context before the selected message.' ),
			array( 'role' => 'assistant', 'content' => 'Context response.' ),
			array( 'role' => 'user', 'content' => 'Selected feedback message.' ),
			array( 'role' => 'assistant', 'content' => 'Context after the selected message.' ),
			array( 'role' => 'user', 'content' => 'After the selected message.' ),
		);
		$tool_calls = array(
			array( 'type' => 'call', 'id' => 'before-call', 'name' => 'site-info' ),
			array( 'type' => 'response', 'id' => 'before-call', 'name' => 'site-info' ),
			array( 'type' => 'call', 'id' => 'within-call', 'name' => 'list-posts' ),
			array( 'type' => 'response', 'id' => 'within-call', 'name' => 'list-posts' ),
			array( 'type' => 'call', 'id' => 'after-call', 'name' => 'update-post' ),
			array( 'type' => 'response', 'id' => 'after-call', 'name' => 'update-post' ),
		);

		$this->assertTrue(
			Database::update_session(
				$session_id,
				array(
					'messages'   => wp_json_encode( $messages ),
					'tool_calls' => wp_json_encode( $tool_calls ),
				)
			)
		);

		$payload = ReportBuilder::build( $session_id, 'user_reported', '', false, 4 );
		$summary = ReportBuilder::build_summary( $session_id, false, 4 );

		$this->assertIsArray( $payload );
		$this->assertIsArray( $summary );
		$this->assertCount( 5, $payload['session_data']['messages'] );
		$this->assertSame( array(), $payload['session_data']['tool_calls'] );
		$this->assertSame( 0, $payload['session_data']['tool_call_count'] );
		$this->assertSame( 0, $summary['tool_call_count'] );
		$this->assertSame( $payload['session_data']['message_count'], $summary['message_count'] );

		$complete_payload = ReportBuilder::build( $session_id, 'user_reported' );
		$complete_summary = ReportBuilder::build_summary( $session_id );
		$this->assertIsArray( $complete_payload );
		$this->assertIsArray( $complete_summary );
		$this->assertSame( $tool_calls, $complete_payload['session_data']['tool_calls'] );
		$this->assertSame( count( $tool_calls ), $complete_payload['session_data']['tool_call_count'] );
		$this->assertSame( count( $tool_calls ), $complete_summary['tool_call_count'] );
	}
}
