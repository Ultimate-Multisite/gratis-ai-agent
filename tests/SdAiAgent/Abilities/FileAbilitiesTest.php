<?php
/**
 * Test case for FileAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\FileAbilities;
use SdAiAgent\Core\AgentEventLog;
use SdAiAgent\Core\WordPressPaths;
use WP_UnitTestCase;

/**
 * Test FileAbilities handler methods.
 */
class FileAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Temporary test file path (relative to wp-content).
	 *
	 * @var string
	 */
	private string $test_file = 'uploads/sd-ai-agent-test-file.txt';

	/**
	 * Full path to the test file.
	 *
	 * @var string
	 */
	private string $test_file_full;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->test_file_full = WP_CONTENT_DIR . '/' . $this->test_file;
		// Ensure uploads directory exists.
		wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );
		add_filter( 'sd_ai_agent_skip_health_check', '__return_true' );
	}

	/**
	 * Tear down: remove test file if it exists.
	 */
	public function tearDown(): void {
		if ( file_exists( $this->test_file_full ) ) {
			unlink( $this->test_file_full );
		}
		remove_filter( 'sd_ai_agent_skip_health_check', '__return_true' );
		parent::tearDown();
	}

	// ─── file-write ───────────────────────────────────────────────

	/**
	 * Test handle_write_file creates a new file.
	 */
	public function test_handle_write_file_creates_file() {
		$result = FileAbilities::handle_write_file( [
			'path'    => $this->test_file,
			'content' => 'Hello, test content!',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'path', $result );
		$this->assertArrayHasKey( 'action', $result );
		$this->assertArrayHasKey( 'size', $result );
		$this->assertSame( 'created', $result['action'] );
		$this->assertTrue( file_exists( $this->test_file_full ) );
	}

	/**
	 * Test handle_write_file updates existing file.
	 */
	public function test_handle_write_file_updates_existing() {
		// Create first.
		file_put_contents( $this->test_file_full, 'original content' );

		$result = FileAbilities::handle_write_file( [
			'path'    => $this->test_file,
			'content' => 'updated content',
		] );

		$this->assertSame( 'updated', $result['action'] );
		$this->assertSame( 'updated content', file_get_contents( $this->test_file_full ) );
	}

	/**
	 * Test handle_write_file size matches content length.
	 */
	public function test_handle_write_file_size_matches_content() {
		$content = 'Test content for size check';

		$result = FileAbilities::handle_write_file( [
			'path'    => $this->test_file,
			'content' => $content,
		] );

		$this->assertSame( strlen( $content ), $result['size'] );
	}

	/**
	 * Test handle_write_file rejects path traversal.
	 */
	public function test_handle_write_file_rejects_path_traversal() {
		$result = FileAbilities::handle_write_file( [
			'path'    => '../../../etc/passwd',
			'content' => 'malicious',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_path_traversal', $result->get_error_code() );
	}

	/**
	 * Test handle_write_file rejects PHP with syntax error.
	 */
	public function test_handle_write_file_rejects_invalid_php() {
		$result = FileAbilities::handle_write_file( [
			'path'    => 'uploads/sd-ai-agent-test-bad.php',
			'content' => '<?php this is not valid php !!!',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_php_syntax_error', $result->get_error_code() );
	}

	/**
	 * Test handle_write_file accepts valid PHP.
	 */
	public function test_handle_write_file_accepts_valid_php() {
		$php_file = 'uploads/sd-ai-agent-test-valid.php';
		$full_path = WP_CONTENT_DIR . '/' . $php_file;

		$result = FileAbilities::handle_write_file( [
			'path'    => $php_file,
			'content' => '<?php echo "hello";',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'action', $result );

		// Cleanup.
		if ( file_exists( $full_path ) ) {
			unlink( $full_path );
		}
	}

	// ─── file-read ────────────────────────────────────────────────

	/**
	 * Test handle_read_file reads existing file.
	 */
	public function test_handle_read_file_reads_existing() {
		file_put_contents( $this->test_file_full, 'Read test content' );

		$result = FileAbilities::handle_read_file( [
			'path' => $this->test_file,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'path', $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertArrayHasKey( 'size', $result );
		$this->assertArrayHasKey( 'modified', $result );
		$this->assertSame( 'Read test content', $result['content'] );
	}

	/**
	 * Test handle_read_file returns WP_Error for non-existent file.
	 */
	public function test_handle_read_file_not_found() {
		$events   = [];
		$listener = static function ( string $event, string $severity, array $context ) use ( &$events ): void {
			$events[] = compact( 'event', 'severity', 'context' );
		};
		add_action( 'sd_ai_agent_event_logged', $listener, 10, 3 );

		$result = FileAbilities::handle_read_file( [
			'path' => 'uploads/nonexistent-file-xyz.txt',
		] );
		remove_action( 'sd_ai_agent_event_logged', $listener, 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_file_not_found', $result->get_error_code() );
		$this->assertSame(
			[
				'content_root_resolved' => true,
				'parent_exists'         => true,
				'parent_resolved'       => true,
				'reason'                => 'missing_leaf',
			],
			$result->get_error_data()
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'file_read_not_found', $events[0]['event'] );
		$this->assertSame( AgentEventLog::SEVERITY_WARNING, $events[0]['severity'] );
		$this->assertSame( 'missing_leaf', $events[0]['context']['reason'] );
		$this->assertArrayHasKey( 'args_hash', $events[0]['context'] );
		$this->assertArrayNotHasKey( 'path', $events[0]['context'] );
	}

	/**
	 * An existing sibling must remain correlated to its own result when the
	 * caller dispatches a missing and an existing read in the same batch.
	 */
	public function test_handle_read_file_keeps_sibling_batch_results_distinct() {
		file_put_contents( $this->test_file_full, 'Existing sibling content' );

		$requests = [
			'call_missing_sibling'  => [ 'path' => 'uploads/sd-ai-agent-missing-sibling.txt' ],
			'call_existing_sibling' => [ 'path' => $this->test_file ],
		];
		$results  = [];
		foreach ( $requests as $call_id => $request ) {
			$results[ $call_id ] = FileAbilities::handle_read_file( $request );
		}

		$this->assertInstanceOf( \WP_Error::class, $results['call_missing_sibling'] );
		$this->assertSame( 'sd_ai_agent_file_not_found', $results['call_missing_sibling']->get_error_code() );
		$this->assertIsArray( $results['call_existing_sibling'] );
		$this->assertSame( $this->test_file, $results['call_existing_sibling']['path'] );
		$this->assertSame( 'Existing sibling content', $results['call_existing_sibling']['content'] );
	}

	/**
	 * Repeated and resumed reads must resolve the same unchanged canonical path.
	 */
	public function test_handle_read_file_repeated_requests_bypass_stale_filesystem_cache() {
		file_put_contents( $this->test_file_full, 'Stable repeated read content' );

		for ( $request = 0; $request < 3; $request++ ) {
			$result = FileAbilities::handle_read_file( [ 'path' => $this->test_file ] );

			$this->assertIsArray( $result );
			$this->assertSame( $this->test_file, $result['path'] );
			$this->assertSame( 'Stable repeated read content', $result['content'] );
		}
	}

	/**
	 * File abilities must anchor their allowed root to WordPress' content root,
	 * not to a theme path that may be symlinked elsewhere.
	 */
	public function test_file_abilities_use_the_wordpress_content_root() {
		$this->assertSame( untrailingslashit( WP_CONTENT_DIR ), WordPressPaths::content_dir() );
	}

	/**
	 * Test handle_read_file rejects path traversal.
	 */
	public function test_handle_read_file_rejects_path_traversal() {
		$result = FileAbilities::handle_read_file( [
			'path' => '../../../etc/passwd',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_path_traversal', $result->get_error_code() );
	}

	/**
	 * Test handle_read_file size matches actual file size.
	 */
	public function test_handle_read_file_size_matches() {
		$content = 'Size check content';
		file_put_contents( $this->test_file_full, $content );

		$result = FileAbilities::handle_read_file( [
			'path' => $this->test_file,
		] );

		$this->assertSame( strlen( $content ), $result['size'] );
	}

	/**
	 * Test handle_read_file returns a requested line range.
	 */
	public function test_handle_read_file_reads_line_range() {
		file_put_contents( $this->test_file_full, "line one\nline two\nline three\nline four" );

		$result = FileAbilities::handle_read_file( [
			'path'       => $this->test_file,
			'start_line' => 2,
			'end_line'   => 3,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( "line two\nline three", $result['content'] );
		$this->assertSame( 2, $result['start_line'] );
		$this->assertSame( 3, $result['end_line'] );
		$this->assertSame( 4, $result['total_lines'] );
	}

	/**
	 * Test handle_read_file clamps line ranges to available lines.
	 */
	public function test_handle_read_file_clamps_line_range() {
		file_put_contents( $this->test_file_full, "line one\nline two\nline three" );

		$result = FileAbilities::handle_read_file( [
			'path'       => $this->test_file,
			'start_line' => 0,
			'end_line'   => 99,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( "line one\nline two\nline three", $result['content'] );
		$this->assertSame( 1, $result['start_line'] );
		$this->assertSame( 3, $result['end_line'] );
		$this->assertSame( 3, $result['total_lines'] );
	}

	/**
	 * Test handle_read_file rejects nonsensical line ranges.
	 */
	public function test_handle_read_file_rejects_invalid_line_range() {
		file_put_contents( $this->test_file_full, "line one\nline two" );

		$result = FileAbilities::handle_read_file( [
			'path'       => $this->test_file,
			'start_line' => 2,
			'end_line'   => 1,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_invalid_line_range', $result->get_error_code() );
	}

	/**
	 * Test handle_outline_file returns bounded source landmarks without content.
	 */
	public function test_handle_outline_file_returns_source_landmarks() {
		$php_file = 'uploads/sd-ai-agent-outline-test.php';
		$full_path = WP_CONTENT_DIR . '/' . $php_file;
		$content  = "<?php\n";
		$content .= "class Outline_Test {\n";
		$content .= "\tpublic function render() {}\n";
		$content .= "}\n";
		$content .= "add_action( 'init', 'outline_test_init' );\n";
		$content .= "get_template_part( 'parts/card' );\n";
		file_put_contents( $full_path, $content );

		$result = FileAbilities::handle_outline_file( [
			'path' => $php_file,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( $php_file, $result['path'] );
		$this->assertSame( 'php', $result['type'] );
		$this->assertSame( 6, $result['total_lines'] );
		$this->assertArrayNotHasKey( 'content', $result );
		$this->assertLessThanOrEqual( 200, count( $result['outline'] ) );
		$this->assertContains( 'Outline_Test', wp_list_pluck( $result['outline'], 'name' ) );
		$this->assertContains( 'render', wp_list_pluck( $result['outline'], 'name' ) );
		$this->assertContains( 'action:init', wp_list_pluck( $result['outline'], 'name' ) );

		if ( file_exists( $full_path ) ) {
			unlink( $full_path );
		}
	}

	/**
	 * Test handle_outline_file covers CSS, JS, and HTML landmarks.
	 */
	public function test_handle_outline_file_covers_frontend_landmarks() {
		$files = [
			'uploads/sd-ai-agent-outline.css'  => "@media (min-width: 600px) {\n.sd-ai-agent-card { color: red; }",
			'uploads/sd-ai-agent-outline.js'   => "class Controller {}\nconst boot = () => {};\ndocument.addEventListener( 'click', boot );",
			'uploads/sd-ai-agent-outline.html' => '<main id="content"><h1>Title</h1></main>',
		];

		foreach ( $files as $relative => $content ) {
			file_put_contents( WP_CONTENT_DIR . '/' . $relative, $content );
		}

		$css_result  = FileAbilities::handle_outline_file( [ 'path' => 'uploads/sd-ai-agent-outline.css' ] );
		$js_result   = FileAbilities::handle_outline_file( [ 'path' => 'uploads/sd-ai-agent-outline.js' ] );
		$html_result = FileAbilities::handle_outline_file( [ 'path' => 'uploads/sd-ai-agent-outline.html' ] );

		$this->assertContains( 'css_at_rule', wp_list_pluck( $css_result['outline'], 'type' ) );
		$this->assertContains( 'js_class', wp_list_pluck( $js_result['outline'], 'type' ) );
		$this->assertContains( 'js_event_listener', wp_list_pluck( $js_result['outline'], 'type' ) );
		$this->assertContains( 'html_landmark', wp_list_pluck( $html_result['outline'], 'type' ) );

		foreach ( array_keys( $files ) as $relative ) {
			$full_path = WP_CONTENT_DIR . '/' . $relative;
			if ( file_exists( $full_path ) ) {
				unlink( $full_path );
			}
		}
	}

	// ─── file-edit ────────────────────────────────────────────────

	/**
	 * Test handle_edit_file applies search-replace.
	 */
	public function test_handle_edit_file_applies_edit() {
		file_put_contents( $this->test_file_full, 'Hello world, this is a test.' );

		$result = FileAbilities::handle_edit_file( [
			'path'  => $this->test_file,
			'edits' => [
				[
					'search'  => 'Hello world',
					'replace' => 'Goodbye world',
				],
			],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['edits_applied'] );
		$this->assertSame( 0, $result['edits_failed'] );
		$this->assertStringContainsString( 'Goodbye world', file_get_contents( $this->test_file_full ) );
	}

	/**
	 * Test handle_edit_file fails when search string not found.
	 */
	public function test_handle_edit_file_search_not_found() {
		file_put_contents( $this->test_file_full, 'Some content here.' );

		$result = FileAbilities::handle_edit_file( [
			'path'  => $this->test_file,
			'edits' => [
				[
					'search'  => 'nonexistent string xyz',
					'replace' => 'replacement',
				],
			],
		] );

		$this->assertSame( 0, $result['edits_applied'] );
		$this->assertSame( 1, $result['edits_failed'] );
	}

	/**
	 * Test handle_edit_file fails when search string is not unique.
	 */
	public function test_handle_edit_file_search_not_unique() {
		file_put_contents( $this->test_file_full, 'duplicate duplicate duplicate' );

		$result = FileAbilities::handle_edit_file( [
			'path'  => $this->test_file,
			'edits' => [
				[
					'search'  => 'duplicate',
					'replace' => 'unique',
				],
			],
		] );

		$this->assertSame( 0, $result['edits_applied'] );
		$this->assertSame( 1, $result['edits_failed'] );
	}

	/**
	 * Test handle_edit_file returns WP_Error for non-existent file.
	 */
	public function test_handle_edit_file_file_not_found() {
		$result = FileAbilities::handle_edit_file( [
			'path'  => 'uploads/nonexistent-edit-file.txt',
			'edits' => [
				[ 'search' => 'x', 'replace' => 'y' ],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_file_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_edit_file rejects path traversal.
	 */
	public function test_handle_edit_file_rejects_path_traversal() {
		$result = FileAbilities::handle_edit_file( [
			'path'  => '../../../etc/passwd',
			'edits' => [
				[ 'search' => 'root', 'replace' => 'hacked' ],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_path_traversal', $result->get_error_code() );
	}

	// ─── file-delete ──────────────────────────────────────────────

	/**
	 * Test handle_delete_file deletes existing file.
	 */
	public function test_handle_delete_file_deletes_file() {
		file_put_contents( $this->test_file_full, 'To be deleted' );

		$result = FileAbilities::handle_delete_file( [
			'path' => $this->test_file,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'deleted', $result['action'] );
		$this->assertFalse( file_exists( $this->test_file_full ) );
	}

	/**
	 * Test handle_delete_file returns WP_Error for non-existent file.
	 */
	public function test_handle_delete_file_not_found() {
		$result = FileAbilities::handle_delete_file( [
			'path' => 'uploads/nonexistent-delete-file.txt',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_file_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_delete_file rejects path traversal.
	 */
	public function test_handle_delete_file_rejects_path_traversal() {
		$result = FileAbilities::handle_delete_file( [
			'path' => '../../../etc/passwd',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_path_traversal', $result->get_error_code() );
	}

	// ─── file-list ────────────────────────────────────────────────

	/**
	 * Test handle_list_directory lists uploads directory.
	 */
	public function test_handle_list_directory_uploads() {
		$result = FileAbilities::handle_list_directory( [
			'path' => 'uploads',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'path', $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'count', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['count'] );
	}

	/**
	 * Test handle_list_directory each item has required fields.
	 */
	public function test_handle_list_directory_item_structure() {
		// Create a test file to ensure at least one item.
		file_put_contents( $this->test_file_full, 'list test' );

		$result = FileAbilities::handle_list_directory( [
			'path' => 'uploads',
		] );

		if ( ! empty( $result['items'] ) ) {
			$item = $result['items'][0];
			$this->assertArrayHasKey( 'name', $item );
			$this->assertArrayHasKey( 'type', $item );
			$this->assertArrayHasKey( 'modified', $item );
			$this->assertContains( $item['type'], [ 'file', 'directory' ] );
		}
	}

	/**
	 * Test handle_list_directory returns WP_Error for non-existent directory.
	 */
	public function test_handle_list_directory_not_found() {
		$result = FileAbilities::handle_list_directory( [
			'path' => 'uploads/nonexistent-dir-xyz-12345',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_dir_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_list_directory rejects path traversal.
	 */
	public function test_handle_list_directory_rejects_path_traversal() {
		$result = FileAbilities::handle_list_directory( [
			'path' => '../../../etc',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_path_traversal', $result->get_error_code() );
	}

	// ─── file-search ──────────────────────────────────────────────

	/**
	 * Test handle_search_files returns array.
	 */
	public function test_handle_search_files_returns_array() {
		$result = FileAbilities::handle_search_files( [
			'pattern' => 'uploads/*.txt',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'pattern', $result );
		$this->assertArrayHasKey( 'matches', $result );
		$this->assertArrayHasKey( 'count', $result );
		$this->assertIsArray( $result['matches'] );
		$this->assertIsInt( $result['count'] );
	}

	/**
	 * Test handle_search_files finds created file.
	 */
	public function test_handle_search_files_finds_file() {
		file_put_contents( $this->test_file_full, 'search test' );

		$result = FileAbilities::handle_search_files( [
			'pattern' => 'uploads/sd-ai-agent-test-file.txt',
		] );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( $this->test_file, $result['matches'][0]['path'] );
	}

	// ─── content-search ───────────────────────────────────────────

	/**
	 * Test handle_search_content with empty needle returns WP_Error.
	 */
	public function test_handle_search_content_empty_needle() {
		$result = FileAbilities::handle_search_content( [
			'needle' => '',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_empty_needle', $result->get_error_code() );
	}

	/**
	 * The model-facing schema makes the search term requirement actionable.
	 */
	public function test_content_search_schema_exposes_required_needle_guidance(): void {
		$ability = new \SdAiAgent\Abilities\ContentSearchAbility(
			'sd-ai-agent/content-search',
			[
				'label'       => 'Search Content',
				'description' => 'Search for text content within files in wp-content.',
			]
		);
		$schema  = $ability->get_input_schema();

		$this->assertSame( [ 'needle' ], $schema['required'] );
		$this->assertStringContainsString( 'Required literal text', $schema['properties']['needle']['description'] );
		$this->assertStringContainsString( 'user request', $schema['properties']['needle']['description'] );
	}

	/**
	 * Test handle_search_content returns array structure.
	 */
	public function test_handle_search_content_returns_structure() {
		$result = FileAbilities::handle_search_content( [
			'needle'    => 'sd-ai-agent',
			'directory' => 'plugins/sd-ai-agent',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'needle', $result );
		$this->assertArrayHasKey( 'matches', $result );
		$this->assertArrayHasKey( 'count', $result );
	}

	/**
	 * Test handle_search_content rejects path traversal in directory.
	 */
	public function test_handle_search_content_rejects_path_traversal() {
		$result = FileAbilities::handle_search_content( [
			'needle'    => 'test',
			'directory' => '../../../etc',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_path_traversal', $result->get_error_code() );
	}
}
