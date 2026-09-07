<?php
/**
 * Tests for the Advanced companion version status.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\AdvancedPluginManager;
use WP_Error;
use WP_UnitTestCase;

final class AdvancedPluginManagerTest extends WP_UnitTestCase {

	private string $plugin_file;

	public function set_up(): void {
		parent::set_up();

		$this->plugin_file = WP_PLUGIN_DIR . '/' . AdvancedPluginManager::PLUGIN_BASENAME;
		wp_mkdir_p( dirname( $this->plugin_file ) );
		$this->write_plugin( '1.20.0' );
		add_filter( 'sd_ai_agent_advanced_plugin_bundled_copy', '__return_false' );
	}

	public function tear_down(): void {
		remove_all_filters( 'sd_ai_agent_advanced_plugin_bundled_copy' );
		delete_option( AdvancedPluginManager::DIAGNOSTIC_OPTION );
		delete_site_transient( 'update_plugins' );
		wp_delete_file( $this->plugin_file );
		rmdir( dirname( $this->plugin_file ) );
		parent::tear_down();
	}

	public function test_status_reports_version_drift_from_wordpress_update_metadata(): void {
		$this->set_update_metadata( '1.23.0' );

		$status = ( new AdvancedPluginManager() )->get_status();

		$this->assertSame( '1.20.0', $status['version'] );
		$this->assertSame( '1.23.0', $status['latest_version'] );
		$this->assertFalse( $status['compatible'] );
		$this->assertTrue( $status['update_available'] );
		$this->assertSame( 'incompatible', $status['status'] );
		$this->assertNull( $status['last_error_code'] );
	}

	public function test_status_reports_unavailable_update_metadata(): void {
		$status = ( new AdvancedPluginManager() )->get_status();

		$this->assertSame( 'metadata_unavailable', $status['status'] );
		$this->assertNull( $status['latest_version'] );
	}

	public function test_status_reports_a_previous_automatic_update_failure(): void {
		$this->set_update_metadata( '1.23.0' );
		update_option( AdvancedPluginManager::DIAGNOSTIC_OPTION, 'download_failed', false );

		$status = ( new AdvancedPluginManager() )->get_status();

		$this->assertSame( 'update_failed', $status['status'] );
		$this->assertSame( 'download_failed', $status['last_error_code'] );
	}

	public function test_status_reports_current_when_versions_match(): void {
		$core_version = (string) SD_AI_AGENT_VERSION;
		$this->write_plugin( $core_version );
		$this->set_update_metadata( $core_version );

		$status = ( new AdvancedPluginManager() )->get_status();

		$this->assertTrue( $status['compatible'] );
		$this->assertFalse( $status['update_available'] );
		$this->assertSame( 'current', $status['status'] );
	}

	public function test_status_reports_an_available_compatible_update(): void {
		$core_version = (string) SD_AI_AGENT_VERSION;
		$this->write_plugin( $core_version );
		$this->set_update_metadata( $core_version . '.1' );

		$status = ( new AdvancedPluginManager() )->get_status();

		$this->assertTrue( $status['compatible'] );
		$this->assertTrue( $status['update_available'] );
		$this->assertSame( 'update_available', $status['status'] );
	}

	public function test_automatic_update_failure_persists_only_its_error_code(): void {
		$manager = new AdvancedPluginManager();
		$manager->record_automatic_update_result(
			true,
			'plugin',
			(object) array( 'plugin' => AdvancedPluginManager::PLUGIN_BASENAME ),
			new WP_Error( 'download_failed', 'Raw update failure details must not be exposed.' )
		);

		$diagnostic = get_option( AdvancedPluginManager::DIAGNOSTIC_OPTION, '' );

		$this->assertSame( 'download_failed', $diagnostic );
		$this->assertStringNotContainsString( 'Raw update failure details', (string) $diagnostic );
	}

	private function set_update_metadata( string $version ): void {
		$updates           = new \stdClass();
		$updates->last_checked = time();
		$updates->response = array(
			AdvancedPluginManager::PLUGIN_BASENAME => (object) array( 'new_version' => $version ),
		);
		set_site_transient( 'update_plugins', $updates );
	}

	private function write_plugin( string $version ): void {
		file_put_contents(
			$this->plugin_file,
			"<?php\n/*\nPlugin Name: SD AI Agent Advanced\nVersion: {$version}\n*/\n"
		);
	}
}
