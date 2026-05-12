<?php
/**
 * Test case for WpCliAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\WpCliAbilities;
use WP_UnitTestCase;

/**
 * Test WP-CLI ability guard behaviour.
 */
class WpCliAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Set up an administrator user because WP-CLI execution requires admin caps.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Clean up filters after each test.
	 */
	public function tear_down(): void {
		remove_all_filters( 'sd_ai_agent_wp_cli_proc_open_available' );

		parent::tear_down();
	}

	/**
	 * Test proc_open-disabled hosts receive a clear actionable error before process setup.
	 */
	public function test_execute_returns_clear_error_when_proc_open_unavailable(): void {
		add_filter( 'sd_ai_agent_wp_cli_proc_open_available', '__return_false' );

		$result = WpCliAbilities::execute( 'post list --format=json' );

		$this->assertWPError( $result );
		$this->assertSame( 'proc_open_unavailable', $result->get_error_code() );
		$this->assertSame( 501, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'proc_open() is disabled', $result->get_error_message() );
		$this->assertStringContainsString( 'Use the REST', $result->get_error_message() );
	}
}
