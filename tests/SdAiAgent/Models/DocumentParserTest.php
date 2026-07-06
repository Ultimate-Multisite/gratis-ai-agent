<?php

declare(strict_types=1);
/**
 * Unit tests for DocumentParser model.
 *
 * Tests extract_from_file() with text, markdown, HTML, and unsupported
 * formats, plus extract_from_attachment() error handling.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Models\DocumentParser;
use WP_UnitTestCase;

/**
 * Tests for DocumentParser model.
 *
 * @since 1.1.0
 */
class DocumentParserTest extends WP_UnitTestCase {

	/**
	 * Temporary files created during tests, for cleanup.
	 *
	 * @var string[]
	 */
	private array $temp_files = [];

	/**
	 * Tear down: remove any temporary files created during the test.
	 */
	public function tear_down(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = [];
		parent::tear_down();
	}

	/**
	 * Helper: create a temporary file with given content and extension.
	 *
	 * @param string $content   File content.
	 * @param string $extension File extension (without dot).
	 * @return string Absolute path to the temp file.
	 */
	private function create_temp_file( string $content, string $extension ): string {
		$path = sys_get_temp_dir() . '/sd-test-' . uniqid() . '.' . $extension;
		file_put_contents( $path, $content );
		$this->temp_files[] = $path;
		return $path;
	}

	// ─── extract_from_file() — file not found ────────────────────────────────

	/**
	 * extract_from_file() returns WP_Error when file does not exist.
	 */
	public function test_extract_from_file_returns_error_for_missing_file(): void {
		$result = DocumentParser::extract_from_file( '/tmp/nonexistent-file-xyz.txt' );

		$this->assertWPError( $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
	}

	// ─── extract_from_file() — text/plain ────────────────────────────────────

	/**
	 * extract_from_file() extracts text from a .txt file.
	 */
	public function test_extract_from_file_parses_txt(): void {
		$content = "Hello world.\nThis is a test file.";
		$path    = $this->create_temp_file( $content, 'txt' );

		$result = DocumentParser::extract_from_file( $path );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Hello world', $result );
		$this->assertStringContainsString( 'This is a test file', $result );
	}

	/**
	 * extract_from_file() extracts text from a .txt file with explicit MIME.
	 */
	public function test_extract_from_file_parses_txt_with_explicit_mime(): void {
		$content = 'Plain text content here.';
		$path    = $this->create_temp_file( $content, 'txt' );

		$result = DocumentParser::extract_from_file( $path, 'text/plain' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Plain text content here', $result );
	}

	// ─── extract_from_file() — text/markdown ─────────────────────────────────

	/**
	 * extract_from_file() extracts text from a .md file.
	 */
	public function test_extract_from_file_parses_markdown(): void {
		$content = "# Heading\n\nSome paragraph text.";
		$path    = $this->create_temp_file( $content, 'md' );

		$result = DocumentParser::extract_from_file( $path );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Heading', $result );
		$this->assertStringContainsString( 'Some paragraph text', $result );
	}

	/**
	 * extract_from_file() extracts text from a .markdown file.
	 */
	public function test_extract_from_file_parses_markdown_extension(): void {
		$content = 'Markdown content.';
		$path    = $this->create_temp_file( $content, 'markdown' );

		$result = DocumentParser::extract_from_file( $path );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Markdown content', $result );
	}

	/**
	 * extract_markdown_content() preserves prose that starts with capitalized import/export words.
	 */
	public function test_extract_markdown_content_preserves_capitalized_import_export_prose(): void {
		$result = DocumentParser::extract_markdown_content( "import Widget from './Widget';\nImport the file before continuing.\nExport your settings after setup." );

		$this->assertStringNotContainsString( 'import Widget', $result['text'] );
		$this->assertStringContainsString( 'Import the file before continuing.', $result['text'] );
		$this->assertStringContainsString( 'Export your settings after setup.', $result['text'] );
	}

	// ─── extract_from_file() — text/html ─────────────────────────────────────

	/**
	 * extract_from_file() strips HTML tags from .html files.
	 */
	public function test_extract_from_file_strips_html_tags(): void {
		$content = '<html><body><h1>Title</h1><p>Paragraph text.</p></body></html>';
		$path    = $this->create_temp_file( $content, 'html' );

		$result = DocumentParser::extract_from_file( $path );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Title', $result );
		$this->assertStringContainsString( 'Paragraph text', $result );
		$this->assertStringNotContainsString( '<h1>', $result );
		$this->assertStringNotContainsString( '<p>', $result );
	}

	/**
	 * extract_from_file() handles .htm extension as HTML.
	 */
	public function test_extract_from_file_parses_htm_extension(): void {
		$content = '<p>HTM content</p>';
		$path    = $this->create_temp_file( $content, 'htm' );

		$result = DocumentParser::extract_from_file( $path );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'HTM content', $result );
	}

	// ─── extract_from_file() — text fallback extensions ──────────────────────

	/**
	 * extract_from_file() falls back to text parsing for .csv files.
	 */
	public function test_extract_from_file_parses_csv_as_text(): void {
		$content = "col1,col2,col3\nval1,val2,val3";
		$path    = $this->create_temp_file( $content, 'csv' );

		$result = DocumentParser::extract_from_file( $path );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'col1', $result );
	}

	/**
	 * extract_from_file() falls back to text parsing for .json files.
	 */
	public function test_extract_from_file_parses_json_as_text(): void {
		$content = '{"key": "value"}';
		$path    = $this->create_temp_file( $content, 'json' );

		$result = DocumentParser::extract_from_file( $path );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'key', $result );
	}

	// ─── extract_from_file() — unsupported format ────────────────────────────

	/**
	 * extract_from_file() returns WP_Error for unsupported MIME type.
	 */
	public function test_extract_from_file_returns_error_for_unsupported_mime(): void {
		$path = $this->create_temp_file( 'binary content', 'bin' );

		$result = DocumentParser::extract_from_file( $path, 'application/octet-stream' );

		$this->assertWPError( $result );
		$this->assertSame( 'unsupported_format', $result->get_error_code() );
	}

	// ─── extract_from_file() — text cleaning ─────────────────────────────────

	/**
	 * extract_from_file() normalises Windows line endings.
	 */
	public function test_extract_from_file_normalises_line_endings(): void {
		$content = "Line one\r\nLine two\r\nLine three";
		$path    = $this->create_temp_file( $content, 'txt' );

		$result = DocumentParser::extract_from_file( $path );

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( "\r", $result );
		$this->assertStringContainsString( 'Line one', $result );
		$this->assertStringContainsString( 'Line two', $result );
	}

	/**
	 * extract_from_file() collapses excessive blank lines.
	 */
	public function test_extract_from_file_collapses_excessive_blank_lines(): void {
		$content = "Line one\n\n\n\n\nLine two";
		$path    = $this->create_temp_file( $content, 'txt' );

		$result = DocumentParser::extract_from_file( $path );

		$this->assertIsString( $result );
		// Should not have 3+ consecutive newlines.
		$this->assertStringNotContainsString( "\n\n\n", $result );
	}

	// ─── extract_from_attachment() ───────────────────────────────────────────

	/**
	 * extract_from_attachment() returns WP_Error for a non-existent attachment.
	 */
	public function test_extract_from_attachment_returns_error_for_missing_attachment(): void {
		$result = DocumentParser::extract_from_attachment( 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
	}
}
