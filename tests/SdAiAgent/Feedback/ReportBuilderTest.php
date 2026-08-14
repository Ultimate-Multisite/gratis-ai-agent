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
 * Test feedback message and tool-log context scoping.
 */
class ReportBuilderTest extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		$this->user_id = self::factory()->user->create();
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Targeted reports include only tool pairs referenced by scoped messages.
	 */
	public function test_targeted_report_scopes_tool_calls_to_message_context(): void {
		$session_id = $this->create_session_with_tool_history();

		$report  = ReportBuilder::build( $session_id, 'thumbs_down', '', false, 5 );
		$summary = ReportBuilder::build_summary( $session_id, false, 5 );

		$this->assertNotNull( $report );
		$this->assertNotNull( $summary );
		$this->assertSame( array( 'target-call', 'target-call' ), array_column( $report['session_data']['tool_calls'], 'id' ) );
		$this->assertSame( array( 'call', 'response' ), array_column( $report['session_data']['tool_calls'], 'type' ) );
		$this->assertSame( 2, $report['session_data']['tool_call_count'] );
		$this->assertSame( 2, $summary['tool_call_count'] );
	}

	/**
	 * Full-session reports preserve the complete tool log.
	 */
	public function test_full_report_preserves_all_tool_calls(): void {
		$session_id = $this->create_session_with_tool_history();

		$report = ReportBuilder::build( $session_id, 'user_reported' );

		$this->assertNotNull( $report );
		$this->assertSame( 6, $report['session_data']['tool_call_count'] );
		$this->assertSame(
			array( 'before-call', 'before-call', 'target-call', 'target-call', 'after-call', 'after-call' ),
			array_column( $report['session_data']['tool_calls'], 'id' )
		);
	}

	/**
	 * Targeted text-only context omits unrelated session-wide tool history.
	 */
	public function test_targeted_report_without_tool_ids_omits_tool_log(): void {
		$session_id = $this->create_session_with_tool_history();
		$this->assertTrue(
			Database::update_session(
				$session_id,
				array(
					'messages' => wp_json_encode(
						array(
							array( 'role' => 'user', 'parts' => array( array( 'type' => 'text', 'text' => 'Text only' ) ) ),
							array( 'role' => 'model', 'parts' => array( array( 'type' => 'text', 'text' => 'No tools here' ) ) ),
						)
					),
				)
			)
		);

		$report = ReportBuilder::build( $session_id, 'thumbs_down', '', false, 1 );

		$this->assertNotNull( $report );
		$this->assertSame( array(), $report['session_data']['tool_calls'] );
		$this->assertSame( 0, $report['session_data']['tool_call_count'] );
	}

	private function create_session_with_tool_history(): int {
		$session_id = Database::create_session(
			array(
				'user_id'     => $this->user_id,
				'title'       => 'Scoped feedback',
				'provider_id' => 'test-provider',
				'model_id'    => 'test-model',
			)
		);
		$this->assertIsInt( $session_id );

		$messages = array(
			array( 'role' => 'user', 'parts' => array( array( 'type' => 'text', 'text' => 'Earlier task' ) ) ),
			$this->function_message( 'model', 'functionCall', 'before-call' ),
			$this->function_message( 'user', 'functionResponse', 'before-call' ),
			array( 'role' => 'model', 'parts' => array( array( 'type' => 'text', 'text' => 'Earlier answer' ) ) ),
			array( 'role' => 'user', 'parts' => array( array( 'type' => 'text', 'text' => 'Target task' ) ) ),
			$this->function_message( 'model', 'functionCall', 'target-call' ),
			$this->function_message( 'user', 'functionResponse', 'target-call' ),
			array( 'role' => 'model', 'parts' => array( array( 'type' => 'text', 'text' => 'Target answer' ) ) ),
			$this->function_message( 'model', 'functionCall', 'after-call' ),
			$this->function_message( 'user', 'functionResponse', 'after-call' ),
		);

		$tool_calls = array(
			array( 'type' => 'call', 'id' => 'before-call', 'name' => 'before-tool', 'args' => array() ),
			array( 'type' => 'response', 'id' => 'before-call', 'name' => 'before-tool', 'response' => array( 'ok' => true ) ),
			array( 'type' => 'call', 'id' => 'target-call', 'name' => 'target-tool', 'args' => array() ),
			array( 'type' => 'response', 'id' => 'target-call', 'name' => 'target-tool', 'response' => array( 'ok' => true ) ),
			array( 'type' => 'call', 'id' => 'after-call', 'name' => 'after-tool', 'args' => array() ),
			array( 'type' => 'response', 'id' => 'after-call', 'name' => 'after-tool', 'response' => array( 'ok' => true ) ),
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

		return $session_id;
	}

	/**
	 * Build a serialized SDK-style function message.
	 *
	 * @return array<string, mixed>
	 */
	private function function_message( string $role, string $key, string $id ): array {
		return array(
			'role'  => $role,
			'parts' => array(
				array(
					'type'   => 'functionCall' === $key ? 'function_call' : 'function_response',
					$key     => array(
						'id'   => $id,
						'name' => 'test-tool',
					),
				),
			),
		);
	}
}
