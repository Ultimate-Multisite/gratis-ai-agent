<?php

declare(strict_types=1);
/**
 * Test case for SkillUsageRepository.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Repositories;

use SdAiAgent\Core\Database;
use SdAiAgent\Models\Skill;
use SdAiAgent\Models\DTO\SkillUsageRow;
use SdAiAgent\Repositories\SkillUsageRepository;
use WP_UnitTestCase;

/**
 * Tests for skill usage telemetry persistence and aggregation.
 */
class SkillUsageRepositoryTest extends WP_UnitTestCase {

	/**
	 * Clean skill usage rows before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only cleanup.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::skill_usage_table_name() ) );
	}

	/**
	 * create() persists telemetry and get_by_skill() returns typed DTOs.
	 */
	public function test_create_and_get_by_skill_returns_typed_rows(): void {
		$skill_id = $this->create_test_skill( 'repository-telemetry' );

		$usage_id = SkillUsageRepository::create(
			[
				'skill_id'        => $skill_id,
				'session_id'      => 42,
				'trigger_type'    => 'manual',
				'injected_tokens' => 128,
				'outcome'         => 'helpful',
				'model_id'        => 'gpt-test',
			]
		);

		$this->assertIsInt( $usage_id );

		$rows = SkillUsageRepository::get_by_skill( $skill_id );
		$this->assertCount( 1, $rows );
		$this->assertInstanceOf( SkillUsageRow::class, $rows[0] );
		$this->assertSame( $usage_id, $rows[0]->id );
		$this->assertSame( $skill_id, $rows[0]->skill_id );
		$this->assertSame( 42, $rows[0]->session_id );
		$this->assertSame( 'manual', $rows[0]->trigger_type );
		$this->assertSame( 128, $rows[0]->injected_tokens );
		$this->assertSame( 'helpful', $rows[0]->outcome );
		$this->assertSame( 'gpt-test', $rows[0]->model_id );
	}

	/**
	 * Invalid enum-like values fall back to safe defaults.
	 */
	public function test_create_sanitizes_invalid_trigger_and_outcome(): void {
		$skill_id = $this->create_test_skill( 'repository-sanitize' );

		SkillUsageRepository::create(
			[
				'skill_id'     => $skill_id,
				'trigger_type' => 'invalid-trigger',
				'outcome'      => 'invalid-outcome',
			]
		);

		$rows = SkillUsageRepository::get_by_skill( $skill_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'auto', $rows[0]->trigger_type );
		$this->assertSame( 'unknown', $rows[0]->outcome );
	}

	/**
	 * get_stats() aggregates outcome counts and last-used timestamps per skill.
	 */
	public function test_get_stats_aggregates_usage_by_skill(): void {
		$skill_id = $this->create_test_skill( 'repository-stats' );

		SkillUsageRepository::create( [ 'skill_id' => $skill_id, 'outcome' => 'helpful' ] );
		SkillUsageRepository::create( [ 'skill_id' => $skill_id, 'outcome' => 'neutral' ] );
		SkillUsageRepository::create( [ 'skill_id' => $skill_id, 'outcome' => 'negative' ] );

		$stats = SkillUsageRepository::get_stats();
		$stat  = $this->find_stat_for_skill( $stats, $skill_id );

		$this->assertNotNull( $stat );
		$this->assertSame( '3', (string) $stat->total_loads );
		$this->assertSame( '1', (string) $stat->helpful_count );
		$this->assertSame( '1', (string) $stat->neutral_count );
		$this->assertSame( '1', (string) $stat->negative_count );
		$this->assertNotEmpty( $stat->last_used_at );
	}

	/**
	 * update_outcome() updates a single usage row and rejects unknown outcomes.
	 */
	public function test_update_outcome_updates_single_row(): void {
		$skill_id = $this->create_test_skill( 'repository-update' );
		$usage_id = SkillUsageRepository::create( [ 'skill_id' => $skill_id ] );

		$this->assertIsInt( $usage_id );
		$this->assertTrue( SkillUsageRepository::update_outcome( $usage_id, 'negative' ) );
		$this->assertFalse( SkillUsageRepository::update_outcome( $usage_id, 'invalid' ) );

		$rows = SkillUsageRepository::get_by_skill( $skill_id );
		$this->assertSame( 'negative', $rows[0]->outcome );
	}

	/**
	 * update_session_outcomes() only updates unknown rows for the requested session.
	 */
	public function test_update_session_outcomes_only_updates_unknown_rows(): void {
		$skill_id = $this->create_test_skill( 'repository-session-outcome' );

		SkillUsageRepository::create( [ 'skill_id' => $skill_id, 'session_id' => 77, 'outcome' => 'unknown' ] );
		SkillUsageRepository::create( [ 'skill_id' => $skill_id, 'session_id' => 77, 'outcome' => 'negative' ] );
		SkillUsageRepository::create( [ 'skill_id' => $skill_id, 'session_id' => 78, 'outcome' => 'unknown' ] );

		$this->assertSame( 1, SkillUsageRepository::update_session_outcomes( 77, 'helpful' ) );
		$this->assertSame( 0, SkillUsageRepository::update_session_outcomes( 0, 'helpful' ) );
		$this->assertSame( 0, SkillUsageRepository::update_session_outcomes( 77, 'unknown' ) );

		$rows     = SkillUsageRepository::get_by_skill( $skill_id, 10 );
		$outcomes = [];
		foreach ( $rows as $row ) {
			$outcomes[ $row->session_id ][] = $row->outcome;
		}

		$this->assertContains( 'helpful', $outcomes[77] );
		$this->assertContains( 'negative', $outcomes[77] );
		$this->assertSame( [ 'unknown' ], $outcomes[78] );
	}

	/**
	 * estimate_tokens() uses the documented chars/4 approximation.
	 */
	public function test_estimate_tokens_uses_chars_divided_by_four(): void {
		$this->assertSame( 0, SkillUsageRepository::estimate_tokens( '' ) );
		$this->assertSame( 1, SkillUsageRepository::estimate_tokens( 'abcd' ) );
		$this->assertSame( 2, SkillUsageRepository::estimate_tokens( 'abcde' ) );
	}

	/**
	 * Create a simple enabled skill and return its ID.
	 *
	 * @param string $slug Skill slug.
	 * @return int Skill ID.
	 */
	private function create_test_skill( string $slug ): int {
		$skill_id = Skill::create(
			[
				'slug'        => $slug,
				'name'        => 'Repository Telemetry',
				'description' => 'Repository telemetry test skill',
				'content'     => 'Repository telemetry content',
				'enabled'     => true,
			]
		);

		$this->assertIsInt( $skill_id );

		return $skill_id;
	}

	/**
	 * Find an aggregate row by skill ID.
	 *
	 * @param list<object> $stats Aggregate rows.
	 * @param int          $skill_id Skill ID.
	 * @return object|null Matching row.
	 */
	private function find_stat_for_skill( array $stats, int $skill_id ): ?object {
		foreach ( $stats as $stat ) {
			if ( $skill_id === (int) $stat->skill_id ) {
				return $stat;
			}
		}

		return null;
	}
}
