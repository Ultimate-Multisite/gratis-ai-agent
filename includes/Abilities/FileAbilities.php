<?php

declare(strict_types=1);
/**
 * File operation abilities for the AI agent.
 *
 * Provides read, write, edit, delete, list, and search operations
 * scoped to the wp-content directory with path traversal protection.
 *
 * Modelled after akirk/ai-assistant's file tools with WordPress Abilities API integration.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\Filesystem\PathCanonicalizer;
use SdAiAgent\Core\Settings;
use SdAiAgent\Core\WordPressPaths;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FileAbilities {

	/**
	 * Return a consistent disabled response when an advanced-only file ability is unavailable.
	 *
	 * @param string $ability_name Ability name.
	 * @return WP_Error
	 */
	private static function advanced_plugin_required( string $ability_name ): WP_Error {
		return new WP_Error(
			'sd_ai_agent_advanced_plugin_required',
			sprintf(
				/* translators: %s: ability name */
				__( 'The %s ability is provided by Superdav AI Agent Advanced. Install and activate the advanced plugin to use this tool.', 'superdav-ai-agent' ),
				$ability_name
			)
		);
	}

	// ─── Static proxy methods (for backwards-compatible test access) ─────────

	/**
	 * Read a file.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_read_file( array $input = [] ) {
		$ability = new FileReadAbility(
			'sd-ai-agent/file-read',
			[
				'label'       => __( 'Read File', 'superdav-ai-agent' ),
				'description' => __( 'Read the contents of a file within the wp-content directory, optionally limited to a line range.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Return a token-efficient file outline.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_outline_file( array $input = [] ) {
		$ability = new FileOutlineAbility(
			'sd-ai-agent/file-outline',
			[
				'label'       => __( 'File Outline', 'superdav-ai-agent' ),
				'description' => __( 'Return a bounded outline of landmarks in a file within wp-content before reading line ranges.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Write a file.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_write_file( array $input = [] ) {
		if ( ! class_exists( FileWriteAbility::class ) ) {
			return self::advanced_plugin_required( 'sd-ai-agent/file-write' );
		}

		$ability = new FileWriteAbility(
			'sd-ai-agent/file-write',
			[
				'label'       => __( 'Write File', 'superdav-ai-agent' ),
				'description' => __( 'Write or overwrite a file within wp-content. Use for creating NEW files. For modifying existing files, use sd-ai-agent/file-edit instead.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Edit a file.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_edit_file( array $input = [] ) {
		if ( ! class_exists( FileEditAbility::class ) ) {
			return self::advanced_plugin_required( 'sd-ai-agent/file-edit' );
		}

		$ability = new FileEditAbility(
			'sd-ai-agent/file-edit',
			[
				'label'       => __( 'Edit File', 'superdav-ai-agent' ),
				'description' => __( 'Edit an existing file by applying search and replace operations. More efficient than write for targeted changes. Each edit finds a unique string and replaces it.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Delete a file.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_delete_file( array $input = [] ) {
		if ( ! class_exists( FileDeleteAbility::class ) ) {
			return self::advanced_plugin_required( 'sd-ai-agent/file-delete' );
		}

		$ability = new FileDeleteAbility(
			'sd-ai-agent/file-delete',
			[
				'label'       => __( 'Delete File', 'superdav-ai-agent' ),
				'description' => __( 'Delete a file within the wp-content directory.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * List a directory.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_list_directory( array $input = [] ) {
		$ability = new FileListAbility(
			'sd-ai-agent/file-list',
			[
				'label'       => __( 'List Directory', 'superdav-ai-agent' ),
				'description' => __( 'List files and directories within a directory in wp-content.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Search for files matching a glob pattern.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_search_files( array $input = [] ) {
		$ability = new FileSearchAbility(
			'sd-ai-agent/file-search',
			[
				'label'       => __( 'Search Files', 'superdav-ai-agent' ),
				'description' => __( 'Search for files matching a glob pattern within wp-content.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Search for text content within files.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_search_content( array $input = [] ) {
		$ability = new ContentSearchAbility(
			'sd-ai-agent/content-search',
			[
				'label'       => __( 'Search Content', 'superdav-ai-agent' ),
				'description' => __( 'Search for text content within files in wp-content.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Register all file operation abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/file-read',
			[
				'label'         => __( 'Read File', 'superdav-ai-agent' ),
				'description'   => __( 'Read the contents of a file within the wp-content directory, optionally limited to a line range.', 'superdav-ai-agent' ),
				'ability_class' => FileReadAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/file-outline',
			[
				'label'         => __( 'File Outline', 'superdav-ai-agent' ),
				'description'   => __( 'Return a bounded outline of landmarks in a file within wp-content before reading line ranges.', 'superdav-ai-agent' ),
				'ability_class' => FileOutlineAbility::class,
			]
		);

		// Mutating filesystem abilities (file-write, file-edit, file-delete)
		// are registered by Superdav AI Agent Advanced when that companion
		// plugin is installed and active. Core keeps only read/list/search
		// file tools for WordPress.org distribution.

		wp_register_ability(
			'sd-ai-agent/file-list',
			[
				'label'         => __( 'List Directory', 'superdav-ai-agent' ),
				'description'   => __( 'List files and directories within a directory in wp-content.', 'superdav-ai-agent' ),
				'ability_class' => FileListAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/file-search',
			[
				'label'         => __( 'Search Files', 'superdav-ai-agent' ),
				'description'   => __( 'Search for files matching a glob pattern within wp-content.', 'superdav-ai-agent' ),
				'ability_class' => FileSearchAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/content-search',
			[
				'label'         => __( 'Search Content', 'superdav-ai-agent' ),
				'description'   => __( 'Search for text content within files in wp-content.', 'superdav-ai-agent' ),
				'ability_class' => ContentSearchAbility::class,
			]
		);
	}
}

/**
 * Shared file path resolution and PHP linting helpers.
 *
 * @since 1.0.0
 */
abstract class AbstractFileAbility extends AbstractAbility {

	/**
	 * Validate and resolve a path within wp-content.
	 *
	 * @param string $relative_path Path relative to wp-content.
	 * @return string|WP_Error Full path on success, WP_Error on failure.
	 */
	protected function resolve_path( string $relative_path ) {
		$relative_path = ltrim( $relative_path, '/\\' );

		if ( empty( $relative_path ) ) {
			return new WP_Error( 'sd_ai_agent_empty_path', __( 'Path cannot be empty.', 'superdav-ai-agent' ) );
		}

		$wp_content_path = WordPressPaths::content_dir();
		$full_path       = $wp_content_path . '/' . $relative_path;

		// Resolve real path for security check.
		$real_path = realpath( dirname( $full_path ) );
		if ( false === $real_path ) {
			// Directory doesn't exist yet, check parent chain.
			$parent = dirname( $full_path );
			while ( ! file_exists( $parent ) && $parent !== dirname( $parent ) ) {
				$parent = dirname( $parent );
			}
			$real_path = realpath( $parent );
		}

		$wp_content_real = realpath( $wp_content_path );

		if ( false === $real_path || false === $wp_content_real ) {
			return new WP_Error(
				'sd_ai_agent_path_resolve_failed',
				__( 'Cannot resolve path.', 'superdav-ai-agent' )
			);
		}

		if ( ! PathCanonicalizer::path_is_inside( $real_path, $wp_content_real ) ) {
			return new WP_Error(
				'sd_ai_agent_path_traversal',
				__( 'Access denied: path is outside wp-content directory.', 'superdav-ai-agent' )
			);
		}

		$canonical = PathCanonicalizer::canonicalize_missing_path_inside( $full_path, $wp_content_real );
		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		return $canonical;
	}

	/**
	 * Check if a path is a PHP file.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	protected function is_php_file( string $path ): bool {
		return (bool) preg_match( '/\.php$/i', $path );
	}

	/**
	 * Split file content into logical lines without counting a trailing newline
	 * as an extra empty line.
	 *
	 * @param string $content File content.
	 * @return array<int,string>
	 */
	protected function split_file_lines( string $content ): array {
		$lines = preg_split( '/\R/', $content );
		$lines = is_array( $lines ) ? $lines : [];

		if ( '' !== $content && preg_match( '/\R\z/', $content ) && '' === end( $lines ) ) {
			array_pop( $lines );
		}

		return $lines;
	}

	/**
	 * Lint PHP content for syntax errors.
	 *
	 * Uses {@see token_get_all()} with `TOKEN_PARSE` to surface syntax errors as
	 * `\ParseError`. A scoped `set_error_handler()` converts any notices/warnings
	 * emitted by the tokeniser into `\ErrorException` so they do not leak to the
	 * site-wide error log. The handler is always restored in a `finally` block.
	 *
	 * Note: this intentionally does NOT call `error_reporting()` — toggling the
	 * global reporting level would interfere with the host site's debugging
	 * configuration. The custom error handler already intercepts emitted errors
	 * regardless of the configured reporting level.
	 *
	 * @param string $content PHP source code.
	 * @return array{valid: bool, error?: string, line?: int}
	 */
	protected function lint_php( string $content ): array {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped error handler is required to convert tokeniser notices into exceptions; restored in finally.
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): bool {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ErrorException constructor arguments are not output; PHPCS false positive.
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);

		try {
			$tokens = token_get_all( $content, TOKEN_PARSE );
			unset( $tokens ); // Result unused — we only care about parse errors.
			return [ 'valid' => true ];
		} catch ( \ParseError | \ErrorException $e ) {
			return [
				'valid' => false,
				'error' => $e->getMessage(),
				'line'  => $e->getLine(),
			];
		} catch ( \Throwable $e ) {
			return [
				'valid' => false,
				'error' => $e->getMessage(),
				'line'  => $e->getLine(),
			];
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Check the tool permission for this ability.
	 *
	 * Returns the permission level: 'auto', 'propose', or 'disabled'.
	 * Defaults to 'auto' unless explicitly set in settings.
	 *
	 * @return string Permission level.
	 */
	protected function get_tool_permission(): string {
		$settings = Settings::instance();
		$perms    = $settings->get( 'tool_permissions' ) ?? [];

		// Check if there's an explicit permission set for this ability.
		if ( isset( $perms[ $this->name ] ) ) {
			return (string) $perms[ $this->name ];
		}

		// Default to 'auto' for all abilities.
		return 'auto';
	}

	/**
	 * Generate a unified diff for a file change.
	 *
	 * @param string $file_path The file path (relative to wp-content).
	 * @param string $new_content The new content.
	 * @return string The unified diff.
	 */
	protected function generate_diff( string $file_path, string $new_content ): string {
		// @phpstan-ignore-next-line
		$full_path = $this->resolve_path( $file_path );
		if ( is_wp_error( $full_path ) ) {
			return '';
		}

		if ( ! file_exists( $full_path ) ) {
			// New file — show all lines as additions.
			$lines = explode( "\n", $new_content );
			$diff  = "--- /dev/null\n+++ $file_path\n";
			foreach ( $lines as $line ) {
				$diff .= '+' . $line . "\n";
			}
			return $diff;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file.
		$old_content = file_get_contents( $full_path );
		if ( false === $old_content ) {
			return '';
		}

		// Use a simple line-by-line diff.
		$old_lines = explode( "\n", $old_content );
		$new_lines = explode( "\n", $new_content );

		$diff = "--- $file_path\n+++ $file_path\n";

		// Simple unified diff: show context and changes.
		$max_lines = max( count( $old_lines ), count( $new_lines ) );
		for ( $i = 0; $i < $max_lines; $i++ ) {
			$old_line = $old_lines[ $i ] ?? '';
			$new_line = $new_lines[ $i ] ?? '';

			if ( $old_line === $new_line ) {
				$diff .= ' ' . $old_line . "\n";
			} else {
				if ( isset( $old_lines[ $i ] ) ) {
					$diff .= '-' . $old_line . "\n";
				}
				if ( isset( $new_lines[ $i ] ) ) {
					$diff .= '+' . $new_line . "\n";
				}
			}
		}

		return $diff;
	}
}

/**
 * File Read ability.
 *
 * @since 1.0.0
 */
class FileReadAbility extends AbstractFileAbility {

	protected function label(): string {
		return __( 'Read File', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Read the contents of a file within the wp-content directory, optionally limited to a line range.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'path'       => [
					'type'        => 'string',
					'description' => 'Relative path from wp-content (e.g., "plugins/my-plugin/file.php")',
				],
				'start_line' => [
					'type'        => 'integer',
					'description' => 'Optional 1-based first line to read.',
				],
				'end_line'   => [
					'type'        => 'integer',
					'description' => 'Optional 1-based final line to read.',
				],
			],
			'required'   => [ 'path' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'path'        => [ 'type' => 'string' ],
				'content'     => [ 'type' => 'string' ],
				'size'        => [ 'type' => 'integer' ],
				'modified'    => [ 'type' => 'string' ],
				'start_line'  => [ 'type' => 'integer' ],
				'end_line'    => [ 'type' => 'integer' ],
				'total_lines' => [ 'type' => 'integer' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$path = $input['path'] ?? '';
		// @phpstan-ignore-next-line
		$full_path = $this->resolve_path( $path );

		if ( is_wp_error( $full_path ) ) {
			return $full_path;
		}

		if ( ! file_exists( $full_path ) ) {
			// @phpstan-ignore-next-line
			return new WP_Error( 'sd_ai_agent_file_not_found', sprintf( 'File not found: %s', $path ) );
		}

		if ( ! is_readable( $full_path ) ) {
			// @phpstan-ignore-next-line
			return new WP_Error( 'sd_ai_agent_file_not_readable', sprintf( 'File not readable: %s', $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file, not remote URL.
		$content = file_get_contents( $full_path );
		if ( false === $content ) {
			// @phpstan-ignore-next-line
			return new WP_Error( 'sd_ai_agent_file_read_failed', sprintf( 'Failed to read file: %s', $path ) );
		}

		$range_requested = array_key_exists( 'start_line', $input ) || array_key_exists( 'end_line', $input );
		if ( $range_requested ) {
			$start_line = isset( $input['start_line'] ) ? (int) $input['start_line'] : 1;
			$end_line   = isset( $input['end_line'] ) ? (int) $input['end_line'] : null;

			if ( null !== $end_line && $end_line < $start_line ) {
				return new WP_Error(
					'sd_ai_agent_invalid_line_range',
					__( 'Invalid line range: end_line must be greater than or equal to start_line.', 'superdav-ai-agent' )
				);
			}

			$lines       = $this->split_file_lines( $content );
			$total_lines = count( $lines );
			$start_line  = max( 1, $start_line );
			$end_line    = null === $end_line ? $total_lines : max( 1, $end_line );
			if ( $total_lines > 0 ) {
				$start_line = min( $start_line, $total_lines );
				$end_line   = min( $end_line, $total_lines );
			}

			$content = implode( "\n", array_slice( $lines ?: [], $start_line - 1, max( 0, $end_line - $start_line + 1 ) ) );

			return [
				'path'        => $path,
				'content'     => $content,
				'size'        => filesize( $full_path ),
				'modified'    => gmdate( 'Y-m-d H:i:s', (int) filemtime( $full_path ) ),
				'start_line'  => $start_line,
				'end_line'    => $end_line,
				'total_lines' => $total_lines,
			];
		}

		return [
			'path'     => $path,
			'content'  => $content,
			'size'     => filesize( $full_path ),
			'modified' => gmdate( 'Y-m-d H:i:s', (int) filemtime( $full_path ) ),
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * File Outline ability.
 *
 * @since 1.0.0
 */
class FileOutlineAbility extends AbstractFileAbility {

	private const MAX_OUTLINE_ITEMS = 200;

	protected function label(): string {
		return __( 'File Outline', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Return a bounded outline of landmarks in a file within wp-content before reading line ranges.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'path' => [
					'type'        => 'string',
					'description' => 'Relative path from wp-content (e.g., "themes/my-theme/functions.php")',
				],
			],
			'required'   => [ 'path' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'path'        => [ 'type' => 'string' ],
				'type'        => [ 'type' => 'string' ],
				'total_lines' => [ 'type' => 'integer' ],
				'outline'     => [ 'type' => 'array' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$path = $input['path'] ?? '';
		// @phpstan-ignore-next-line
		$full_path = $this->resolve_path( $path );

		if ( is_wp_error( $full_path ) ) {
			return $full_path;
		}

		if ( ! file_exists( $full_path ) || ! is_file( $full_path ) ) {
			// @phpstan-ignore-next-line
			return new WP_Error( 'sd_ai_agent_file_not_found', sprintf( 'File not found: %s', $path ) );
		}

		if ( ! is_readable( $full_path ) ) {
			// @phpstan-ignore-next-line
			return new WP_Error( 'sd_ai_agent_file_not_readable', sprintf( 'File not readable: %s', $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file, not remote URL.
		$content = file_get_contents( $full_path );
		if ( false === $content ) {
			// @phpstan-ignore-next-line
			return new WP_Error( 'sd_ai_agent_file_read_failed', sprintf( 'Failed to read file: %s', $path ) );
		}

		$lines = $this->split_file_lines( $content );
		$type  = $this->detect_type( $path );

		return [
			'path'        => $path,
			'type'        => $type,
			'total_lines' => count( $lines ),
			'outline'     => $this->build_outline( $lines, $type ),
		];
	}

	/**
	 * Detect a coarse file type for outline generation.
	 *
	 * @param string $path Relative file path.
	 * @return string
	 */
	private function detect_type( string $path ): string {
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return match ( $extension ) {
			'php' => 'php',
			'css', 'scss', 'sass' => 'css',
			'js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs' => 'js',
			'html', 'htm' => 'html',
			default => 'text',
		};
	}

	/**
	 * Build deterministic landmarks for the supported source types.
	 *
	 * @param array<int,string> $lines File lines.
	 * @param string            $type  Detected type.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_outline( array $lines, string $type ): array {
		$outline = [];
		foreach ( $lines as $index => $line ) {
			$line_number = $index + 1;
			$trimmed     = trim( $line );

			if ( '' === $trimmed ) {
				continue;
			}

			if ( 'php' === $type ) {
				$this->collect_php_landmarks( $outline, $trimmed, $line_number );
			} elseif ( 'css' === $type ) {
				$this->collect_css_landmarks( $outline, $trimmed, $line_number );
			} elseif ( 'js' === $type ) {
				$this->collect_js_landmarks( $outline, $trimmed, $line_number );
			} elseif ( 'html' === $type ) {
				$this->collect_html_landmarks( $outline, $trimmed, $line_number );
			}

			if ( count( $outline ) >= self::MAX_OUTLINE_ITEMS ) {
				break;
			}
		}

		return $outline;
	}

	/**
	 * Collect PHP and PHP-template landmarks from one line.
	 *
	 * @param array<int,array<string,mixed>> $outline     Outline accumulator.
	 * @param string                         $line        Trimmed line.
	 * @param int                            $line_number 1-based line number.
	 */
	private function collect_php_landmarks( array &$outline, string $line, int $line_number ): void {
		if ( preg_match( '/^(?:abstract\s+|final\s+)?(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $line, $match ) ) {
			$this->add_outline_entry( $outline, $line_number, 'php_symbol', $match[1] );
		}

		if ( preg_match( '/^(?:public|protected|private|static|final|abstract|\s)*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $match ) ) {
			$this->add_outline_entry( $outline, $line_number, 'php_function', $match[1] );
		}

		if ( preg_match( '/\badd_(action|filter)\s*\(\s*[\'\"]([^\'\"]+)/', $line, $match ) ) {
			$this->add_outline_entry( $outline, $line_number, 'wp_hook', $match[1] . ':' . $match[2] );
		}

		if ( preg_match( '/\b(get_template_part|get_header|get_footer|get_sidebar)\s*\(([^)]*)\)/', $line, $match ) ) {
			$this->add_outline_entry( $outline, $line_number, 'template_part', $match[1] . '(' . trim( $match[2] ) . ')' );
		}
	}

	/**
	 * Collect CSS landmarks from one line.
	 *
	 * @param array<int,array<string,mixed>> $outline     Outline accumulator.
	 * @param string                         $line        Trimmed line.
	 * @param int                            $line_number 1-based line number.
	 */
	private function collect_css_landmarks( array &$outline, string $line, int $line_number ): void {
		if ( preg_match( '/^@(media|supports|container|keyframes)\b\s*([^{}]*)/', $line, $match ) ) {
			$this->add_outline_entry( $outline, $line_number, 'css_at_rule', '@' . $match[1] . ' ' . trim( $match[2] ) );
			return;
		}

		if ( str_contains( $line, '{' ) && preg_match( '/^([^{}@][^{]+)\{/', $line, $match ) ) {
			$this->add_outline_entry( $outline, $line_number, 'css_selector', trim( $match[1] ) );
		}
	}

	/**
	 * Collect JavaScript landmarks from one line.
	 *
	 * @param array<int,array<string,mixed>> $outline     Outline accumulator.
	 * @param string                         $line        Trimmed line.
	 * @param int                            $line_number 1-based line number.
	 */
	private function collect_js_landmarks( array &$outline, string $line, int $line_number ): void {
		if ( preg_match( '/^(?:export\s+default\s+|export\s+)?class\s+([A-Za-z_$][A-Za-z0-9_$]*)\b/', $line, $match ) ) {
			$this->add_outline_entry( $outline, $line_number, 'js_class', $match[1] );
		}

		if ( preg_match( '/^(?:export\s+)?(?:async\s+)?function\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $line, $match ) ) {
			$this->add_outline_entry( $outline, $line_number, 'js_function', $match[1] );
		}

		if ( preg_match( '/^(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(?:async\s*)?\(?[^=]*\)?\s*=>/', $line, $match ) ) {
			$this->add_outline_entry( $outline, $line_number, 'js_function', $match[1] );
		}

		if ( preg_match( '/\.addEventListener\s*\(\s*[\'\"]([^\'\"]+)/', $line, $match ) ) {
			$this->add_outline_entry( $outline, $line_number, 'js_event_listener', $match[1] );
		}
	}

	/**
	 * Collect HTML landmarks from one line.
	 *
	 * @param array<int,array<string,mixed>> $outline     Outline accumulator.
	 * @param string                         $line        Trimmed line.
	 * @param int                            $line_number 1-based line number.
	 */
	private function collect_html_landmarks( array &$outline, string $line, int $line_number ): void {
		if ( preg_match_all( '/<\s*(main|header|footer|nav|section|article|aside|form|h[1-6])\b([^>]*)>/i', $line, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name = strtolower( $match[1] );
				if ( preg_match( '/\b(?:id|class)\s*=\s*[\'\"]([^\'\"]+)/', $match[2], $attribute_match ) ) {
					$name .= '#' . $attribute_match[1];
				}
				$this->add_outline_entry( $outline, $line_number, 'html_landmark', $name );
			}
		}

		if ( preg_match( '/<!--\s+wp:(template-part|pattern)\s+({.*?})?\s+-->/', $line, $match ) ) {
			$this->add_outline_entry( $outline, $line_number, 'block_' . $match[1], isset( $match[2] ) ? $match[2] : $match[1] );
		}
	}

	/**
	 * Add one outline entry when within the output bound.
	 *
	 * @param array<int,array<string,mixed>> $outline Outline accumulator.
	 * @param int                            $line    1-based line number.
	 * @param string                         $type    Landmark type.
	 * @param string                         $name    Landmark name.
	 */
	private function add_outline_entry( array &$outline, int $line, string $type, string $name ): void {
		if ( count( $outline ) >= self::MAX_OUTLINE_ITEMS ) {
			return;
		}

		$outline[] = [
			'line' => $line,
			'type' => $type,
			'name' => mb_substr( $name, 0, 160 ),
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * File List ability.
 *
 * @since 1.0.0
 */
class FileListAbility extends AbstractFileAbility {

	protected function label(): string {
		return __( 'List Directory', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'List files and directories within a directory in wp-content.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'path' => [
					'type'        => 'string',
					'description' => 'Relative path from wp-content (e.g., "plugins" or "themes/theme-name")',
				],
			],
			'required'   => [ 'path' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'path'  => [ 'type' => 'string' ],
				'items' => [ 'type' => 'array' ],
				'count' => [ 'type' => 'integer' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$path = $input['path'] ?? '';
		// @phpstan-ignore-next-line
		$full_path = $this->resolve_path( $path );

		if ( is_wp_error( $full_path ) ) {
			return $full_path;
		}

		if ( ! file_exists( $full_path ) || ! is_dir( $full_path ) ) {
			// @phpstan-ignore-next-line
			return new WP_Error( 'sd_ai_agent_dir_not_found', sprintf( 'Directory not found: %s', $path ) );
		}

		$entries = scandir( $full_path );
		$items   = [];

		if ( false !== $entries ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$entry_path = $full_path . '/' . $entry;
				// Guard filemtime/filesize against broken symlinks (e.g. wp-content/db.php
				// pointing at a removed dropin) — they emit warnings otherwise.
				$is_dir   = is_dir( $entry_path );
				$readable = $is_dir || is_file( $entry_path );
				$items[]  = [
					'name'     => $entry,
					'type'     => $is_dir ? 'directory' : 'file',
					'size'     => ( ! $is_dir && $readable ) ? filesize( $entry_path ) : null,
					'modified' => $readable ? gmdate( 'Y-m-d H:i:s', (int) filemtime( $entry_path ) ) : null,
				];
			}
		}

		return [
			'path'  => $path,
			'items' => $items,
			'count' => count( $items ),
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * File Search ability.
 *
 * @since 1.0.0
 */
class FileSearchAbility extends AbstractFileAbility {

	protected function label(): string {
		return __( 'Search Files', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Search for files matching a glob pattern within wp-content.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'pattern' => [
					'type'        => 'string',
					'description' => 'Glob pattern (e.g., "plugins/*/*.php" or "themes/**/*.css")',
				],
			],
			'required'   => [ 'pattern' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'pattern' => [ 'type' => 'string' ],
				'matches' => [ 'type' => 'array' ],
				'count'   => [ 'type' => 'integer' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$pattern         = $input['pattern'] ?? '';
		$wp_content_path = WordPressPaths::content_dir();
		$wp_content_root = trailingslashit( $wp_content_path );
		// @phpstan-ignore-next-line
		$full_pattern = $wp_content_root . ltrim( $pattern, '/' );

		$files   = glob( $full_pattern );
		$results = [];

		if ( false !== $files ) {
			foreach ( $files as $file ) {
				$relative  = str_replace( $wp_content_root, '', $file );
				$results[] = [
					'path' => $relative,
					'type' => is_dir( $file ) ? 'directory' : 'file',
					'size' => is_file( $file ) ? filesize( $file ) : null,
				];
			}
		}

		return [
			'pattern' => $pattern,
			'matches' => $results,
			'count'   => count( $results ),
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Content Search ability.
 *
 * @since 1.0.0
 */
class ContentSearchAbility extends AbstractFileAbility {

	protected function label(): string {
		return __( 'Search Content', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Search for literal text within files in wp-content. The needle argument is required and must contain the text to find; derive it from the user request before calling. Never call this ability with empty arguments.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'needle'       => [
					'type'        => 'string',
					'description' => 'Required literal text to search for (for example, a template name, CSS class, URL fragment, or visible label from the user request).',
				],
				'directory'    => [
					'type'        => 'string',
					'description' => 'Directory to search in (relative to wp-content), default is entire wp-content',
				],
				'file_pattern' => [
					'type'        => 'string',
					'description' => 'File extension filter (e.g., "*.php")',
				],
			],
			'required'   => [ 'needle' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'needle'  => [ 'type' => 'string' ],
				'matches' => [ 'type' => 'array' ],
				'count'   => [ 'type' => 'integer' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$needle       = $input['needle'] ?? '';
		$directory    = $input['directory'] ?? '';
		$file_pattern = $input['file_pattern'] ?? '*.php';

		if ( empty( $needle ) ) {
			return new WP_Error( 'sd_ai_agent_empty_needle', __( 'Search text cannot be empty.', 'superdav-ai-agent' ) );
		}

		$search_path = WordPressPaths::content_dir();
		if ( ! empty( $directory ) ) {
			// @phpstan-ignore-next-line
			$resolved = $this->resolve_path( $directory );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$search_path = $resolved;
		}

		$results = [];
		// @phpstan-ignore-next-line
		$this->search_content_recursive( $search_path, $needle, $file_pattern, $results );

		return [
			'needle'    => $needle,
			'directory' => $directory ?: 'wp-content',
			'matches'   => $results,
			'count'     => count( $results ),
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}

	/**
	 * Recursively search file contents.
	 *
	 * @param string                     $dir     Directory to search.
	 * @param string                     $needle  Text to find.
	 * @param string                     $pattern File glob pattern.
	 * @param list<array<string, mixed>> $results Results accumulator (passed by reference).
	 * @param int                        $limit   Maximum results.
	 */
	private function search_content_recursive( string $dir, string $needle, string $pattern, array &$results, int $limit = 50 ): void {
		if ( count( $results ) >= $limit || ! is_dir( $dir ) ) {
			return;
		}

		$files           = glob( $dir . '/' . $pattern );
		$wp_content_root = trailingslashit( WordPressPaths::content_dir() );
		if ( false !== $files ) {
			foreach ( $files as $file ) {
				if ( count( $results ) >= $limit ) {
					return;
				}

				if ( ! is_file( $file ) ) {
					continue;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file.
				$content = file_get_contents( $file );
				if ( false === $content || stripos( $content, $needle ) === false ) {
					continue;
				}

				$lines          = explode( "\n", $content );
				$matching_lines = [];
				foreach ( $lines as $line_num => $line ) {
					if ( stripos( $line, $needle ) !== false ) {
						$matching_lines[] = [
							'line'    => $line_num + 1,
							'content' => trim( substr( $line, 0, 200 ) ),
						];
					}
				}

				$results[] = [
					'path'    => str_replace( $wp_content_root, '', $file ),
					'matches' => array_slice( $matching_lines, 0, 5 ),
				];
			}
		}

		// Search subdirectories.
		$subdirs = glob( $dir . '/*', GLOB_ONLYDIR );
		if ( false !== $subdirs ) {
			foreach ( $subdirs as $subdir ) {
				if ( count( $results ) >= $limit ) {
					return;
				}
				$basename = basename( $subdir );
				if ( 'vendor' === $basename || 'node_modules' === $basename ) {
					continue;
				}
				$this->search_content_recursive( $subdir, $needle, $pattern, $results, $limit );
			}
		}
	}
}
