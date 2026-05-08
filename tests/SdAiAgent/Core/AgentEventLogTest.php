<?php

declare(strict_types=1);
/**
 * Test case for AgentEventLog.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\AgentEventLog;
use WP_UnitTestCase;

/**
 * Test AgentEventLog functionality.
 *
 * `error_log()` is captured by redirecting it to a temp file via the
 * `error_log` ini directive, since PHPUnit's @ouputBuffer won't catch it.
 */
class AgentEventLogTest extends WP_UnitTestCase {

	/**
	 * Path to the temp file capturing error_log output.
	 *
	 * @var string
	 */
	private string $log_file = '';

	/**
	 * Captured do_action calls for sd_ai_agent_event_logged.
	 *
	 * @var list<array{event:string, severity:string, context:array<string,mixed>}>
	 */
	private array $captured_actions = array();

	/**
	 * Original error_log ini value, restored in tear_down.
	 *
	 * @var string
	 */
	private string $original_error_log = '';

	public function set_up(): void {
		parent::set_up();

		AgentEventLog::clear_session();

		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'sdai-event-log-' );
		// File must exist and be writable; tempnam creates it 0600.
		$this->original_error_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );

		$this->captured_actions = array();
		add_action(
			'sd_ai_agent_event_logged',
			function ( $event, $severity, $context ): void {
				$this->captured_actions[] = array(
					'event'    => (string) $event,
					'severity' => (string) $severity,
					'context'  => is_array( $context ) ? $context : array(),
				);
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		ini_set( 'error_log', $this->original_error_log );
		if ( '' !== $this->log_file && file_exists( $this->log_file ) ) {
			@unlink( $this->log_file );
		}
		AgentEventLog::clear_session();
		remove_all_actions( 'sd_ai_agent_event_logged' );
		parent::tear_down();
	}

	/**
	 * Read the contents of the captured error_log file.
	 */
	private function read_log(): string {
		if ( ! file_exists( $this->log_file ) ) {
			return '';
		}
		$contents = file_get_contents( $this->log_file );
		return is_string( $contents ) ? $contents : '';
	}

	// ── tool_limit_reached ─────────────────────────────────────────────────

	public function test_emits_tool_limit_reached_with_expected_shape(): void {
		AgentEventLog::log(
			'tool_limit_reached',
			AgentEventLog::SEVERITY_WARNING,
			array(
				'session_id'      => 123,
				'iterations_used' => 10,
				'iterations_max'  => 10,
				'model_id'        => 'claude-opus-4-7',
				'provider_id'     => 'anthropic',
			)
		);

		$contents = $this->read_log();

		$this->assertStringContainsString( '[Superdav AI Agent]', $contents );
		$this->assertStringContainsString( '[session:123]', $contents );
		$this->assertStringContainsString( 'event=tool_limit_reached', $contents );
		$this->assertStringContainsString( 'severity=warning', $contents );
		$this->assertStringContainsString( 'iterations_used=10', $contents );
		$this->assertStringContainsString( 'iterations_max=10', $contents );
		$this->assertStringContainsString( 'model_id=claude-opus-4-7', $contents );
		$this->assertStringContainsString( 'provider_id=anthropic', $contents );
	}

	// ── ability_failed ─────────────────────────────────────────────────────

	public function test_emits_ability_failed_with_truncated_message(): void {
		$long_message = str_repeat( 'x', 500 );

		AgentEventLog::log(
			'ability_failed',
			AgentEventLog::SEVERITY_ERROR,
			array(
				'ability' => 'sd-ai-agent/memory-save',
				'code'    => 'invalid_input',
				'message' => $long_message,
			)
		);

		$contents = $this->read_log();

		$this->assertStringContainsString( 'event=ability_failed', $contents );
		$this->assertStringContainsString( 'severity=error', $contents );
		$this->assertStringContainsString( 'ability=sd-ai-agent/memory-save', $contents );
		$this->assertStringContainsString( 'code=invalid_input', $contents );

		// Message truncated to MAX_MESSAGE_LENGTH (200) — full 500-char string must NOT appear.
		$this->assertStringNotContainsString( str_repeat( 'x', 250 ), $contents );
		$this->assertStringContainsString( '…', $contents );
	}

	// ── provider_http_error ────────────────────────────────────────────────

	public function test_emits_provider_http_error(): void {
		AgentEventLog::log(
			'provider_http_error',
			AgentEventLog::SEVERITY_ERROR,
			array(
				'provider_id' => 'anthropic',
				'model_id'    => 'claude-opus-4-7',
				'status_code' => 529,
			)
		);

		$contents = $this->read_log();

		$this->assertStringContainsString( 'event=provider_http_error', $contents );
		$this->assertStringContainsString( 'status_code=529', $contents );
		$this->assertStringContainsString( 'provider_id=anthropic', $contents );
	}

	// ── agent_loop_aborted ─────────────────────────────────────────────────

	public function test_emits_agent_loop_aborted_with_reason(): void {
		AgentEventLog::log(
			'agent_loop_aborted',
			AgentEventLog::SEVERITY_ERROR,
			array(
				'session_id' => 7,
				'reason'     => 'budget_exceeded',
			)
		);

		$contents = $this->read_log();

		$this->assertStringContainsString( '[session:7]', $contents );
		$this->assertStringContainsString( 'event=agent_loop_aborted', $contents );
		$this->assertStringContainsString( 'reason=budget_exceeded', $contents );
	}

	// ── thread-local session attribution ───────────────────────────────────

	public function test_thread_local_session_id_is_used_when_context_omits_it(): void {
		AgentEventLog::set_session( 42 );
		AgentEventLog::log(
			'ability_failed',
			AgentEventLog::SEVERITY_ERROR,
			array(
				'ability' => 'sd-ai-agent/memory-save',
				'code'    => 'invalid_input',
			)
		);

		$contents = $this->read_log();
		$this->assertStringContainsString( '[session:42]', $contents );
	}

	public function test_explicit_context_session_overrides_thread_local(): void {
		AgentEventLog::set_session( 42 );
		AgentEventLog::log(
			'ability_failed',
			AgentEventLog::SEVERITY_ERROR,
			array(
				'session_id' => 99,
				'ability'    => 'sd-ai-agent/memory-save',
				'code'       => 'invalid_input',
			)
		);

		$contents = $this->read_log();
		$this->assertStringContainsString( '[session:99]', $contents );
		$this->assertStringNotContainsString( '[session:42]', $contents );
	}

	public function test_omits_session_when_unknown(): void {
		AgentEventLog::clear_session();
		AgentEventLog::log(
			'tool_limit_reached',
			AgentEventLog::SEVERITY_WARNING,
			array( 'iterations_used' => 5 )
		);

		$contents = $this->read_log();
		$this->assertStringNotContainsString( '[session:', $contents );
	}

	// ── action hook ─────────────────────────────────────────────────────────

	public function test_fires_sd_ai_agent_event_logged_action(): void {
		AgentEventLog::log(
			'tool_limit_reached',
			AgentEventLog::SEVERITY_WARNING,
			array(
				'session_id'      => 1,
				'iterations_used' => 10,
			)
		);

		$this->assertCount( 1, $this->captured_actions );
		$this->assertSame( 'tool_limit_reached', $this->captured_actions[0]['event'] );
		$this->assertSame( 'warning', $this->captured_actions[0]['severity'] );
		$this->assertSame( 1, $this->captured_actions[0]['context']['session_id'] );
		$this->assertSame( 10, $this->captured_actions[0]['context']['iterations_used'] );
	}

	// ── log-injection / sanitisation ───────────────────────────────────────

	public function test_event_name_strips_unsafe_characters(): void {
		// Newlines or shell metachars in the event name must not survive.
		AgentEventLog::log(
			"bad\nevent name; rm -rf /",
			AgentEventLog::SEVERITY_ERROR
		);

		$contents = $this->read_log();
		$this->assertStringNotContainsString( "rm -rf", $contents );
		$this->assertStringNotContainsString( "\nevent", $contents );
	}

	public function test_message_field_collapses_newlines(): void {
		AgentEventLog::log(
			'ability_failed',
			AgentEventLog::SEVERITY_ERROR,
			array(
				'ability' => 'x',
				'code'    => 'y',
				'message' => "line one\nline two\nline three",
			)
		);

		$contents = $this->read_log();
		// The error_log format itself appends a trailing newline; the
		// message body must not introduce additional newlines.
		$lines = array_filter(
			explode( "\n", trim( $contents ) ),
			static fn( string $l ): bool => '' !== $l
		);
		$this->assertCount( 1, $lines, 'Each emit must produce exactly one log line' );
	}

	// ── unknown severity defaults to error ─────────────────────────────────

	public function test_unknown_severity_defaults_to_error(): void {
		AgentEventLog::log( 'something', 'CRITICAL', array() );
		$contents = $this->read_log();
		$this->assertStringContainsString( 'severity=error', $contents );
	}

	// ── unsafe context keys are dropped ────────────────────────────────────

	public function test_non_whitelisted_context_keys_are_dropped(): void {
		AgentEventLog::log(
			'tool_limit_reached',
			AgentEventLog::SEVERITY_WARNING,
			array(
				'session_id'  => 1,
				// Pretend a careless caller passed a body / API key — these
				// keys are not in SAFE_CONTEXT_KEYS and must NOT appear.
				'request_body' => '{"messages":[{"role":"user","content":"secret"}]}',
				'authorization' => 'Bearer sk-very-secret-token-shhh',
				'api_key'       => 'sk-shhh',
			)
		);

		$contents = $this->read_log();
		$this->assertStringNotContainsString( 'secret', $contents );
		$this->assertStringNotContainsString( 'sk-shhh', $contents );
		$this->assertStringNotContainsString( 'sk-very-secret-token', $contents );
		$this->assertStringNotContainsString( 'Bearer', $contents );
		$this->assertStringNotContainsString( 'request_body', $contents );
		$this->assertStringNotContainsString( 'authorization', $contents );
	}
}
