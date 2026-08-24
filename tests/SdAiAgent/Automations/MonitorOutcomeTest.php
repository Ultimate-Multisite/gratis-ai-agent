<?php

declare(strict_types=1);
/**
 * Unit tests for the strict Monitor outcome contract.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Automations;

use SdAiAgent\Automations\MonitorOutcome;
use WP_UnitTestCase;

class MonitorOutcomeTest extends WP_UnitTestCase {

	/** A valid Monitor reply retains its explicit outcome and summary. */
	public function test_parse_accepts_an_exact_outcome_contract(): void {
		$this->assertSame(
			[
				'outcome' => 'notify',
				'summary' => 'The administrator needs to review the failed backup.',
			],
			MonitorOutcome::parse( '{"outcome":"notify","summary":"The administrator needs to review the failed backup."}' )
		);
	}

	/** Prose and unrecognised JSON shapes cannot control Monitor notification state. */
	public function test_parse_rejects_non_contract_responses(): void {
		$invalid_replies = [
			'Please notify the administrator.',
			'{"outcome":"notify"}',
			'{"outcome":"notify","summary":"Review this.","extra":"ignored"}',
			'{"outcome":"unknown","summary":"Review this."}',
			'{"outcome":"blocked","summary":""}',
			'[{"outcome":"quiet","summary":""}]',
		];

		foreach ( $invalid_replies as $reply ) {
			$this->assertNull( MonitorOutcome::parse( $reply ), "Expected invalid Monitor response: {$reply}" );
		}
	}

	/** Durable Monitor summaries redact credential-shaped model output. */
	public function test_parse_redacts_credential_shaped_summary_text(): void {
		$parsed = MonitorOutcome::parse( '{"outcome":"notify","summary":"api_key: should-not-survive"}' );

		$this->assertNotNull( $parsed );
		$this->assertSame( 'notify', $parsed['outcome'] );
		$this->assertStringContainsString( '[redacted]', $parsed['summary'] );
		$this->assertStringNotContainsString( 'should-not-survive', $parsed['summary'] );
	}

	/** The isolated prompt includes current administrator context and fixed output rules. */
	public function test_build_prompt_keeps_contract_after_administrator_context(): void {
		$prompt = MonitorOutcome::build_prompt(
			[
				'monitor_scratch' => 'Check whether backups completed.',
				'prompt'          => 'Use only the configured site-health tools.',
			]
		);

		$this->assertStringContainsString( 'Check whether backups completed.', $prompt );
		$this->assertStringContainsString( 'Use only the configured site-health tools.', $prompt );
		$this->assertStringContainsString( 'Return exactly one JSON object', $prompt );
		$this->assertStringContainsString( '"quiet", "notify", "blocked", or "error"', $prompt );
	}

	/** Quiet and notify are successful checks; blocked and error remain distinct. */
	public function test_lifecycle_status_preserves_monitor_outcome_meaning(): void {
		$this->assertSame( 'succeeded', MonitorOutcome::lifecycle_status( 'quiet' ) );
		$this->assertSame( 'succeeded', MonitorOutcome::lifecycle_status( 'notify' ) );
		$this->assertSame( 'blocked', MonitorOutcome::lifecycle_status( 'blocked' ) );
		$this->assertSame( 'failed', MonitorOutcome::lifecycle_status( 'error' ) );
	}

	/** The prompt boundary retains only source-specific identifiers and bounded counts. */
	public function test_wake_context_discards_unapproved_metadata_before_prompt_rendering(): void {
		$context = MonitorOutcome::sanitize_wake_context(
			[
				'source'      => 'delete_post',
				'event_count' => 999,
				'identifiers' => [
					'post_id'    => '42',
					'unretained' => 'must-not-persist',
				],
			]
		);

		$this->assertSame(
			[
				'source'      => 'delete_post',
				'event_count' => 50,
				'identifiers' => [ 'post_id' => 42 ],
			],
			$context
		);

		$prompt = MonitorOutcome::build_prompt(
			[ 'monitor_scratch' => 'Check the site.' ],
			[
				'source'      => 'delete_post',
				'event_count' => 999,
				'identifiers' => [
					'post_id'    => '42',
					'unretained' => 'must-not-persist',
				],
			]
		);

		$this->assertStringContainsString( 'Source: delete_post', $prompt );
		$this->assertStringContainsString( 'Coalesced deliveries: 50', $prompt );
		$this->assertStringContainsString( 'post_id=42', $prompt );
		$this->assertStringNotContainsString( 'must-not-persist', $prompt );
	}
}
