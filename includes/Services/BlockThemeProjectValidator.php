<?php

declare(strict_types=1);
/**
 * Read-only integrity validation for generated WordPress block-theme projects.
 *
 * @package SdAiAgent\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Services;

use SdAiAgent\Core\BlockValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates a complete block-theme project without executing project PHP.
 *
 * The validator deliberately works from a resolved theme root and only reports
 * theme-relative paths. It never follows symlinks, mutates files, evaluates
 * pattern PHP, or requests remote resources.
 *
 * @phpstan-type Diagnostic array{code:string,path:string,message:string,location?:array<string,mixed>}
 * @phpstan-type Diagnostics list<Diagnostic>
 * @phpstan-type ProjectFile array{path:string,size:int}
 * @phpstan-type ProjectFiles array<string,ProjectFile>
 * @phpstan-type TokenMap array<string,true>
 * @phpstan-type MalformedCssVariableReference array{value:string,path:string,offset:int,reason:string}
 */
final class BlockThemeProjectValidator {

	/**
	 * Hidden, theme-relative marker written by the scaffold ability.
	 */
	public const MARKER_PATH = '.sd-ai-agent/block-theme-project.json';

	/**
	 * Supported marker schema version.
	 */
	public const MARKER_SCHEMA_VERSION = 1;

	/**
	 * Supported validation contract version.
	 */
	public const VALIDATION_VERSION = 1;

	/**
	 * Bounded scanner limits.
	 */
	private const MAX_FILE_COUNT      = 500;
	private const MAX_FILE_SIZE       = 1048576;
	private const MAX_DIRECTORY_DEPTH = 8;

	/**
	 * The only remote schema URL permitted in theme.json documents.
	 */
	private const THEME_JSON_SCHEMA = 'https://schemas.wp.org/trunk/theme.json';

	/**
	 * Return the marker payload for a newly scaffolded project.
	 *
	 * @return array<string,int|string>
	 */
	public static function marker_payload(): array {
		return [
			'schema_version'     => self::MARKER_SCHEMA_VERSION,
			'generator'          => 'sd-ai-agent',
			'generator_version'  => defined( 'SD_AI_AGENT_VERSION' ) ? (string) SD_AI_AGENT_VERSION : '1.0.0',
			'validation_version' => self::VALIDATION_VERSION,
		];
	}

	/**
	 * Return a stable marker document suitable for writing to the theme root.
	 */
	public static function marker_contents(): string {
		$encoded = wp_json_encode( self::marker_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return ( is_string( $encoded ) ? $encoded : '{}' ) . "\n";
	}

	/**
	 * Find malformed CSS var() functions in a value tree without parsing CSS.
	 *
	 * This deliberately checks only the small CSS-function contract that WordPress
	 * theme documents emit. It leaves fallback values untouched, including nested
	 * functions, while rejecting missing delimiters and invalid variable names.
	 *
	 * @param mixed  $value     Value tree or text source to inspect.
	 * @param string $json_path JSON path for value-tree results.
	 * @return list<MalformedCssVariableReference>
	 */
	public static function find_malformed_css_variable_references( mixed $value, string $json_path = '' ): array {
		$references = [];
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$next_path = '' === $json_path ? (string) $key : $json_path . '.' . $key;
				foreach ( self::find_malformed_css_variable_references( $child, $next_path ) as $reference ) {
					$references[] = $reference;
				}
			}

			return $references;
		}

		if ( ! is_string( $value ) ) {
			return $references;
		}

		$offset = 0;
		while ( false !== ( $start = stripos( $value, 'var(', $offset ) ) ) {
			$depth       = 1;
			$end         = null;
			$length      = strlen( $value );
			$open_offset = $start + 3;
			for ( $index = $open_offset + 1; $index < $length; ++$index ) {
				if ( '(' === $value[ $index ] ) {
					++$depth;
				} elseif ( ')' === $value[ $index ] && 0 === --$depth ) {
					$end = $index;
					break;
				}
			}

			if ( ! is_int( $end ) ) {
				$references[] = [
					'value'  => substr( $value, $start ),
					'path'   => $json_path,
					'offset' => $start,
					'reason' => 'missing_closing_parenthesis',
				];
				break;
			}

			$arguments = trim( substr( $value, $open_offset + 1, $end - $open_offset - 1 ) );
			$name      = trim( explode( ',', $arguments, 2 )[0] );
			$reason    = '';
			if ( '' === $name ) {
				$reason = 'empty_variable_name';
			} elseif ( ! preg_match( '/^--[A-Za-z_][A-Za-z0-9_-]*$/', $name ) ) {
				$reason = 'invalid_variable_name';
			} elseif ( str_starts_with( strtolower( $name ), '--wp--' ) && ! preg_match( '/^--wp--(?:preset|custom)--[a-z0-9]+(?:-+[a-z0-9]+)*$/', $name ) ) {
				$reason = 'invalid_wordpress_token_name';
			}

			if ( '' !== $reason ) {
				$references[] = [
					'value'  => substr( $value, $start, $end - $start + 1 ),
					'path'   => $json_path,
					'offset' => $start,
					'reason' => $reason,
				];
			}

			$offset = $start + 4;
		}

		return $references;
	}

	/**
	 * Return an empty typed diagnostic list.
	 *
	 * @return Diagnostics
	 */
	private static function empty_diagnostics(): array {
		return [];
	}

	/**
	 * Return an empty typed project-file map.
	 *
	 * @return ProjectFiles
	 */
	private static function empty_project_files(): array {
		return [];
	}

	/**
	 * Return an empty typed CSS-token map.
	 *
	 * @return TokenMap
	 */
	private static function empty_token_map(): array {
		return [];
	}

	/**
	 * Return an empty typed decoded JSON object.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_json_object(): array {
		return [];
	}

	/**
	 * Determine whether the project is opt-in marked for activation gating.
	 */
	public static function is_marked_project( string $theme_root ): bool {
		return file_exists( untrailingslashit( $theme_root ) . '/' . self::MARKER_PATH );
	}

	/**
	 * Validate one on-disk project and return exhaustive normalized diagnostics.
	 *
	 * @return array{
	 *   valid:bool,
	 *   marked:bool,
	 *   project_version:int,
	 *   fingerprint:string,
	 *   files_scanned:int,
	 *   errors:Diagnostics,
	 *   warnings:Diagnostics
	 * }
	 */
	public function validate( string $theme_root ): array {
		$errors   = self::empty_diagnostics();
		$warnings = self::empty_diagnostics();
		$root     = $this->resolve_root( $theme_root, $errors );

		if ( null === $root ) {
			return $this->build_report( false, false, 0, '', 0, $errors, $warnings );
		}

		$files        = $this->collect_files( $root, $errors );
		$before       = $this->project_fingerprint( $files );
		$marker       = $this->validate_marker( $files, $errors );
		$is_marked    = self::is_marked_project( $root );
		$project_ver  = is_array( $marker ) ? (int) ( $marker['schema_version'] ?? 0 ) : 0;
		$theme_json   = $this->validate_required_project_files( $files, $errors );
		$token_values = self::empty_token_map();

		if ( is_array( $theme_json ) ) {
			$token_values = $this->validate_theme_json( $theme_json, $files, $errors );
		}

		$this->validate_template_files( $files, $theme_json, $token_values, $errors );
		$this->validate_pattern_files( $files, basename( $root ), $token_values, $errors );
		$this->validate_style_variations( $files, $token_values, $errors );
		$this->validate_text_file_sources( $files, $token_values, $errors );

		$after_diagnostics = self::empty_diagnostics();
		$after_files       = $this->collect_files( $root, $after_diagnostics );
		$after             = $this->project_fingerprint( $after_files );
		if ( $before !== $after ) {
			$this->add_diagnostic(
				$errors,
				'project_changed_during_scan',
				'.',
				__( 'The theme project changed during validation. Retry after writes have finished.', 'superdav-ai-agent' ),
				[ 'retryable' => true ]
			);
		}

		return $this->build_report(
			empty( $errors ),
			$is_marked,
			$is_marked ? $project_ver : 0,
			$before,
			count( $files ),
			$errors,
			$warnings
		);
	}

	/**
	 * Resolve a root only when it is a non-symlinked directory.
	 *
	 * @param string $theme_root Theme root supplied for validation.
	 * @param array  $errors     Diagnostics collected during validation.
	 * @phpstan-param Diagnostics $errors
	 */
	private function resolve_root( string $theme_root, array &$errors ): ?string {
		$theme_root = untrailingslashit( $theme_root );
		if ( '' === $theme_root || ! is_dir( $theme_root ) ) {
			$this->add_diagnostic(
				$errors,
				'theme_root_not_found',
				'.',
				__( 'The requested theme project directory is unavailable.', 'superdav-ai-agent' )
			);
			return null;
		}

		if ( is_link( $theme_root ) ) {
			$this->add_diagnostic(
				$errors,
				'symlink_theme_root',
				'.',
				__( 'The theme project root must not be a symlink.', 'superdav-ai-agent' )
			);
			return null;
		}

		$resolved = realpath( $theme_root );
		if ( false === $resolved ) {
			$this->add_diagnostic(
				$errors,
				'theme_root_unresolvable',
				'.',
				__( 'The theme project root could not be resolved safely.', 'superdav-ai-agent' )
			);
			return null;
		}

		return untrailingslashit( $resolved );
	}

	/**
	 * Collect bounded, non-symlinked files beneath a resolved root.
	 *
	 * @param string $root        Resolved project root.
	 * @param array  $diagnostics Diagnostics collected during validation.
	 * @phpstan-param Diagnostics $diagnostics
	 * @return ProjectFiles
	 */
	private function collect_files( string $root, array &$diagnostics ): array {
		$files = self::empty_project_files();

		try {
			$directory = new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS );
			$iterator  = new \RecursiveIteratorIterator( $directory, \RecursiveIteratorIterator::SELF_FIRST );
			$iterator->setMaxDepth( self::MAX_DIRECTORY_DEPTH );

			foreach ( $iterator as $item ) {
				$path     = $item->getPathname();
				$relative = $this->relative_path( $root, $path );

				if ( $item->isLink() ) {
					$this->add_diagnostic(
						$diagnostics,
						'symlink_escape',
						$relative,
						__( 'Symlinked project entries are not permitted because they can escape the theme root.', 'superdav-ai-agent' )
					);
					continue;
				}

				if ( $item->isDir() ) {
					if ( $iterator->getDepth() >= self::MAX_DIRECTORY_DEPTH ) {
						$this->add_diagnostic(
							$diagnostics,
							'directory_depth_exceeded',
							$relative,
							__( 'The project contains a directory deeper than the supported validation limit.', 'superdav-ai-agent' )
						);
					}
					continue;
				}

				if ( ! $item->isFile() ) {
					continue;
				}

				$resolved = realpath( $path );
				if ( false === $resolved || ! $this->is_within_root( $root, $resolved ) ) {
					$this->add_diagnostic(
						$diagnostics,
						'path_escape',
						$relative,
						__( 'A project file resolves outside the validated theme root.', 'superdav-ai-agent' )
					);
					continue;
				}

				$size = $item->getSize();
				if ( ! is_int( $size ) ) {
					$this->add_diagnostic(
						$diagnostics,
						'file_size_unavailable',
						$relative,
						__( 'The project file size could not be determined safely.', 'superdav-ai-agent' )
					);
					continue;
				}
				if ( $size > self::MAX_FILE_SIZE ) {
					$this->add_diagnostic(
						$diagnostics,
						'file_too_large',
						$relative,
						__( 'The project file exceeds the supported validation size limit.', 'superdav-ai-agent' ),
						[ 'max_bytes' => self::MAX_FILE_SIZE ]
					);
				}

				$files[ $relative ] = [
					'path' => $resolved,
					'size' => $size,
				];

				if ( count( $files ) > self::MAX_FILE_COUNT ) {
					$this->add_diagnostic(
						$diagnostics,
						'file_count_exceeded',
						'.',
						__( 'The project exceeds the supported validation file-count limit.', 'superdav-ai-agent' ),
						[ 'max_files' => self::MAX_FILE_COUNT ]
					);
					break;
				}
			}
		} catch ( \UnexpectedValueException ) {
			$this->add_diagnostic(
				$diagnostics,
				'project_scan_failed',
				'.',
				__( 'The theme project could not be scanned safely.', 'superdav-ai-agent' )
			);
		}

		ksort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * Return a content fingerprint without including absolute paths.
	 *
	 * @param array $files Project files keyed by relative path.
	 * @phpstan-param ProjectFiles $files
	 */
	private function project_fingerprint( array $files ): string {
		$snapshot = [];
		foreach ( $files as $relative => $file ) {
			clearstatcache( true, $file['path'] );
			$mtime = filemtime( $file['path'] );
			$hash  = $file['size'] <= self::MAX_FILE_SIZE ? hash_file( 'sha256', $file['path'] ) : false;

			$snapshot[ $relative ] = [
				'size'  => $file['size'],
				'mtime' => false === $mtime ? 0 : $mtime,
				'hash'  => false === $hash ? '' : $hash,
			];
		}

		return hash( 'sha256', (string) wp_json_encode( $snapshot ) );
	}

	/**
	 * Validate the optional generated-project marker.
	 *
	 * @param array $files  Project files keyed by relative path.
	 * @param array $errors Diagnostics collected during validation.
	 * @phpstan-param ProjectFiles $files
	 * @phpstan-param Diagnostics $errors
	 * @return array<string,mixed>|null Normalized marker when valid.
	 */
	private function validate_marker( array $files, array &$errors ): ?array {
		if ( ! isset( $files[ self::MARKER_PATH ] ) ) {
			return null;
		}

		$marker = $this->read_json_file( self::MARKER_PATH, $files, $errors );
		if ( ! is_array( $marker ) ) {
			return null;
		}

		$schema_version = $marker['schema_version'] ?? null;
		if ( self::MARKER_SCHEMA_VERSION !== $schema_version ) {
			$this->add_diagnostic(
				$errors,
				'unknown_marker_version',
				self::MARKER_PATH,
				__( 'The generated-project marker uses an unsupported schema version.', 'superdav-ai-agent' ),
				[ 'json_path' => 'schema_version' ]
			);
		}

		if ( 'sd-ai-agent' !== ( $marker['generator'] ?? null ) ) {
			$this->add_diagnostic(
				$errors,
				'invalid_project_marker',
				self::MARKER_PATH,
				__( 'The generated-project marker does not identify the expected generator.', 'superdav-ai-agent' ),
				[ 'json_path' => 'generator' ]
			);
		}

		if ( ! is_string( $marker['generator_version'] ?? null ) || '' === trim( (string) $marker['generator_version'] ) ) {
			$this->add_diagnostic(
				$errors,
				'invalid_project_marker',
				self::MARKER_PATH,
				__( 'The generated-project marker must include a generator version.', 'superdav-ai-agent' ),
				[ 'json_path' => 'generator_version' ]
			);
		}

		if ( self::VALIDATION_VERSION !== ( $marker['validation_version'] ?? null ) ) {
			$this->add_diagnostic(
				$errors,
				'unknown_validation_version',
				self::MARKER_PATH,
				__( 'The generated-project marker requires an unsupported validator version.', 'superdav-ai-agent' ),
				[ 'json_path' => 'validation_version' ]
			);
		}

		return $marker;
	}

	/**
	 * Validate required files and return decoded theme.json when possible.
	 *
	 * @param array $files  Project files keyed by relative path.
	 * @param array $errors Diagnostics collected during validation.
	 * @phpstan-param ProjectFiles $files
	 * @phpstan-param Diagnostics $errors
	 * @return array<string,mixed>|null
	 */
	private function validate_required_project_files( array $files, array &$errors ): ?array {
		foreach ( [ 'theme.json', 'style.css', 'templates/index.html' ] as $required ) {
			if ( ! isset( $files[ $required ] ) ) {
				$this->add_diagnostic(
					$errors,
					'missing_required_file',
					$required,
					__( 'This file is required for a complete WordPress block theme.', 'superdav-ai-agent' )
				);
			}
		}

		if ( isset( $files['style.css'] ) ) {
			$style_css = $this->read_file( 'style.css', $files, $errors );
			if ( is_string( $style_css ) && ! preg_match( '/^\s*Theme Name:\s*\S/m', $style_css ) ) {
				$this->add_diagnostic(
					$errors,
					'missing_theme_header',
					'style.css',
					__( 'style.css must declare a non-empty Theme Name header.', 'superdav-ai-agent' )
				);
			}
		}

		return $this->read_json_file( 'theme.json', $files, $errors );
	}

	/**
	 * Validate theme.json structure and return declared CSS token values.
	 *
	 * @param array $theme_json Decoded theme.json object.
	 * @param array $files      Project files keyed by relative path.
	 * @param array $errors     Diagnostics collected during validation.
	 * @phpstan-param array<string,mixed> $theme_json
	 * @phpstan-param ProjectFiles $files
	 * @phpstan-param Diagnostics $errors
	 * @return TokenMap CSS variable definitions.
	 */
	private function validate_theme_json( array $theme_json, array $files, array &$errors ): array {
		if ( 3 !== ( $theme_json['version'] ?? null ) ) {
			$this->add_diagnostic(
				$errors,
				'invalid_theme_json_version',
				'theme.json',
				__( 'theme.json must declare version 3 for this WordPress 7.0+ project.', 'superdav-ai-agent' ),
				[ 'json_path' => 'version' ]
			);
		}

		if ( self::THEME_JSON_SCHEMA !== ( $theme_json['$schema'] ?? null ) ) {
			$this->add_diagnostic(
				$errors,
				'invalid_theme_json_schema',
				'theme.json',
				__( 'theme.json must declare the canonical WordPress theme.json schema URL.', 'superdav-ai-agent' ),
				[ 'json_path' => '$schema' ]
			);
		}

		$settings = $theme_json['settings'] ?? null;
		// json_decode( ..., true ) represents a valid empty JSON object as [],
		// so permit that one ambiguous shape while rejecting populated lists.
		if ( ! is_array( $settings ) || ( [] !== $settings && array_is_list( $settings ) ) ) {
			$this->add_diagnostic(
				$errors,
				'invalid_theme_json_settings',
				'theme.json',
				__( 'theme.json settings must be an object.', 'superdav-ai-agent' ),
				[ 'json_path' => 'settings' ]
			);
			$settings = [];
		}

		$token_values = $this->collect_token_values( $settings, $errors );
		$this->validate_font_faces( $settings, $files, $errors );
		$this->validate_token_references( $theme_json, $token_values, 'theme.json', '', $errors );

		return $token_values;
	}

	/**
	 * Collect and validate native preset and custom CSS variable definitions.
	 *
	 * @param array $settings Decoded theme.json settings object.
	 * @param array $errors   Diagnostics collected during validation.
	 * @phpstan-param array<string,mixed> $settings
	 * @phpstan-param Diagnostics $errors
	 * @return TokenMap
	 */
	private function collect_token_values( array $settings, array &$errors ): array {
		$tokens = self::empty_token_map();
		$this->collect_preset_collection(
			$settings['color']['palette'] ?? [],
			'color',
			'color',
			[ 'color' ],
			$tokens,
			$errors
		);
		$this->collect_preset_collection(
			$settings['typography']['fontFamilies'] ?? [],
			'font-family',
			'fontFamily',
			[ 'typography', 'fontFamilies' ],
			$tokens,
			$errors
		);
		$this->collect_preset_collection(
			$settings['typography']['fontSizes'] ?? [],
			'font-size',
			'size',
			[ 'typography', 'fontSizes' ],
			$tokens,
			$errors
		);
		$this->collect_preset_collection(
			$settings['spacing']['spacingSizes'] ?? [],
			'spacing',
			'size',
			[ 'spacing', 'spacingSizes' ],
			$tokens,
			$errors
		);

		if ( isset( $settings['custom'] ) ) {
			if ( ! is_array( $settings['custom'] ) || ( [] !== $settings['custom'] && array_is_list( $settings['custom'] ) ) ) {
				$this->add_diagnostic(
					$errors,
					'invalid_custom_token_definitions',
					'theme.json',
					__( 'theme.json settings.custom must be an object when present.', 'superdav-ai-agent' ),
					[ 'json_path' => 'settings.custom' ]
				);
			} else {
				$this->collect_custom_token_values( $settings['custom'], [], $tokens );
			}
		}

		return $tokens;
	}

	/**
	 * Validate one native WordPress preset collection.
	 *
	 * @param mixed  $items      Preset definitions to validate.
	 * @param string $kind       WordPress preset kind.
	 * @param string $value_key  Item key holding the preset value.
	 * @param array  $json_path  JSON path segments for diagnostics.
	 * @param array  $tokens     Declared CSS variable names.
	 * @param array  $errors     Diagnostics collected during validation.
	 * @phpstan-param list<string> $json_path
	 * @phpstan-param TokenMap $tokens
	 * @phpstan-param Diagnostics $errors
	 */
	private function collect_preset_collection(
		mixed $items,
		string $kind,
		string $value_key,
		array $json_path,
		array &$tokens,
		array &$errors
	): void {
		if ( [] === $items ) {
			return;
		}

		$path = implode( '.', $json_path );
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			$this->add_diagnostic(
				$errors,
				'invalid_preset_definitions',
				'theme.json',
				__( 'WordPress preset definitions must be an array of objects.', 'superdav-ai-agent' ),
				[ 'json_path' => 'settings.' . $path ]
			);
			return;
		}

		$seen = [];
		foreach ( $items as $index => $item ) {
			$item_path = 'settings.' . $path . '.' . $index;
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				$this->add_diagnostic(
					$errors,
					'invalid_preset_definition',
					'theme.json',
					__( 'Each WordPress preset must be an object.', 'superdav-ai-agent' ),
					[ 'json_path' => $item_path ]
				);
				continue;
			}

			$slug  = $item['slug'] ?? null;
			$value = $item[ $value_key ] ?? null;
			$name  = $item['name'] ?? null;
			if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
				$this->add_diagnostic(
					$errors,
					'invalid_preset_slug',
					'theme.json',
					__( 'Each WordPress preset must have a lowercase kebab-case slug.', 'superdav-ai-agent' ),
					[ 'json_path' => $item_path . '.slug' ]
				);
				continue;
			}

			if ( isset( $seen[ $slug ] ) ) {
				$this->add_diagnostic(
					$errors,
					'duplicate_preset_slug',
					'theme.json',
					__( 'WordPress preset slugs must be unique within their collection.', 'superdav-ai-agent' ),
					[
						'json_path' => $item_path . '.slug',
						'slug'      => $slug,
					]
				);
				continue;
			}
			$seen[ $slug ] = true;

			if ( ! is_string( $value ) || '' === trim( $value ) || ! is_string( $name ) || '' === trim( $name ) ) {
				$this->add_diagnostic(
					$errors,
					'invalid_preset_definition',
					'theme.json',
					__( 'Each WordPress preset must define non-empty slug, name, and value fields.', 'superdav-ai-agent' ),
					[ 'json_path' => $item_path ]
				);
				continue;
			}

			$tokens[ '--wp--preset--' . $kind . '--' . $slug ] = true;
		}
	}

	/**
	 * Recursively convert settings.custom leaves to WordPress CSS variable names.
	 *
	 * @param mixed $value    Custom token value tree.
	 * @param array $segments CSS variable path segments.
	 * @param array $tokens   Declared CSS variable names.
	 * @phpstan-param list<string> $segments
	 * @phpstan-param TokenMap $tokens
	 */
	private function collect_custom_token_values( mixed $value, array $segments, array &$tokens ): void {
		if ( ! is_array( $value ) ) {
			if ( [] !== $segments ) {
				$tokens[ '--wp--custom--' . implode( '--', $segments ) ] = true;
			}
			return;
		}

		foreach ( $value as $key => $child ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			$next   = $segments;
			$next[] = $this->css_variable_segment( $key );
			$this->collect_custom_token_values( $child, $next, $tokens );
		}
	}

	/**
	 * Convert a JSON custom-setting key to its CSS variable segment.
	 */
	private function css_variable_segment( string $key ): string {
		$key = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $key ) ?? $key;
		$key = str_replace( '_', '-', $key );

		return strtolower( $key );
	}

	/**
	 * Validate local fontFace source references without fetching them.
	 *
	 * @param array $settings Decoded theme.json settings object.
	 * @param array $files    Project files keyed by relative path.
	 * @param array $errors   Diagnostics collected during validation.
	 * @phpstan-param array<string,mixed> $settings
	 * @phpstan-param ProjectFiles $files
	 * @phpstan-param Diagnostics $errors
	 */
	private function validate_font_faces( array $settings, array $files, array &$errors ): void {
		$families = $settings['typography']['fontFamilies'] ?? [];
		if ( ! is_array( $families ) || ! array_is_list( $families ) ) {
			return;
		}

		foreach ( $families as $family_index => $family ) {
			if ( ! is_array( $family ) || ! isset( $family['fontFace'] ) ) {
				continue;
			}

			$faces = $family['fontFace'];
			if ( ! is_array( $faces ) || ! array_is_list( $faces ) ) {
				$this->add_diagnostic(
					$errors,
					'invalid_font_face',
					'theme.json',
					__( 'fontFace must be an array of local font declarations.', 'superdav-ai-agent' ),
					[ 'json_path' => 'settings.typography.fontFamilies.' . $family_index . '.fontFace' ]
				);
				continue;
			}

			foreach ( $faces as $face_index => $face ) {
				$sources = is_array( $face ) ? ( $face['src'] ?? null ) : null;
				$sources = is_string( $sources ) ? [ $sources ] : $sources;
				if ( ! is_array( $sources ) || [] === $sources ) {
					$this->add_diagnostic(
						$errors,
						'invalid_font_face',
						'theme.json',
						__( 'Each fontFace declaration must include one or more local source files.', 'superdav-ai-agent' ),
						[ 'json_path' => 'settings.typography.fontFamilies.' . $family_index . '.fontFace.' . $face_index . '.src' ]
					);
					continue;
				}

				foreach ( $sources as $source_index => $source ) {
					$this->validate_asset_reference(
						is_string( $source ) ? $source : '',
						'theme.json',
						'font',
						$files,
						$errors,
						[ 'json_path' => 'settings.typography.fontFamilies.' . $family_index . '.fontFace.' . $face_index . '.src.' . $source_index ]
					);
				}
			}
		}
	}

	/**
	 * Validate all emitted WordPress preset/custom CSS references in a value tree.
	 *
	 * @param mixed  $value     Value tree to inspect.
	 * @param array  $tokens    Declared CSS variable names.
	 * @param string $relative  Theme-relative source path.
	 * @param string $json_path JSON path for diagnostics.
	 * @param array  $errors    Diagnostics collected during validation.
	 * @phpstan-param TokenMap $tokens
	 * @phpstan-param Diagnostics $errors
	 */
	private function validate_token_references( mixed $value, array $tokens, string $relative, string $json_path, array &$errors ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$next_path = '' === $json_path ? (string) $key : $json_path . '.' . $key;
				$this->validate_token_references( $child, $tokens, $relative, $next_path, $errors );
			}
			return;
		}

		if ( ! is_string( $value ) ) {
			return;
		}

		$this->add_malformed_css_variable_diagnostics( self::find_malformed_css_variable_references( $value, $json_path ), $relative, $errors );
		if ( ! preg_match_all( '/var\(\s*(--wp--(?:preset|custom)--[a-z0-9-]+)\s*(?:,[^)]*)?\)/i', $value, $matches ) ) {
			return;
		}

		foreach ( $matches[1] as $reference ) {
			$reference = strtolower( $reference );
			if ( isset( $tokens[ $reference ] ) ) {
				continue;
			}

			$this->add_diagnostic(
				$errors,
				'unresolved_token_reference',
				$relative,
				__( 'A WordPress preset or custom token reference does not resolve in theme.json settings.', 'superdav-ai-agent' ),
				[
					'json_path' => $json_path,
					'reference' => $reference,
				]
			);
		}
	}

	/**
	 * Validate templates, parts, declarations, references, and block markup.
	 *
	 * @param array      $files      Project files keyed by relative path.
	 * @param array|null $theme_json Decoded theme.json object when valid.
	 * @param array      $tokens     Declared CSS variable names.
	 * @param array      $errors     Diagnostics collected during validation.
	 * @phpstan-param ProjectFiles $files
	 * @phpstan-param array<string,mixed>|null $theme_json
	 * @phpstan-param TokenMap $tokens
	 * @phpstan-param Diagnostics $errors
	 */
	private function validate_template_files( array $files, ?array $theme_json, array $tokens, array &$errors ): void {
		$templates = [];
		$parts     = [];

		foreach ( array_keys( $files ) as $relative ) {
			if ( str_starts_with( $relative, 'templates/' ) && str_ends_with( $relative, '.html' ) ) {
				$templates[] = $relative;
				if ( 1 !== substr_count( $relative, '/' ) ) {
					$this->add_diagnostic(
						$errors,
						'invalid_template_path',
						$relative,
						__( 'Theme templates must live directly under templates/.', 'superdav-ai-agent' )
					);
				}
			}
			if ( str_starts_with( $relative, 'parts/' ) && str_ends_with( $relative, '.html' ) ) {
				$parts[] = $relative;
				if ( 1 !== substr_count( $relative, '/' ) ) {
					$this->add_diagnostic(
						$errors,
						'invalid_template_part_path',
						$relative,
						__( 'Theme template parts must live directly under parts/.', 'superdav-ai-agent' )
					);
				}
			}
		}

		$declared_parts   = $this->validate_template_part_declarations( $theme_json, $parts, $errors );
		$declared_customs = $this->validate_custom_template_declarations( $theme_json, $templates, $errors );
		unset( $declared_customs );

		foreach ( array_merge( $templates, $parts ) as $relative ) {
			$contents = $this->read_file( $relative, $files, $errors );
			if ( ! is_string( $contents ) ) {
				continue;
			}

			$this->validate_template_part_references( $contents, $relative, $parts, $declared_parts, $errors );
			$this->validate_block_markup( $contents, $relative, $errors );
			$this->validate_markup_safety( $contents, $relative, $files, $errors );
			$this->validate_markup_token_references( $contents, $relative, $tokens, $errors );
		}
	}

	/**
	 * Validate theme.json template-part declarations against actual files.
	 *
	 * @param array|null $theme_json Decoded theme.json object when valid.
	 * @param array      $parts      Theme-relative template-part paths.
	 * @param array      $errors     Diagnostics collected during validation.
	 * @phpstan-param array<string,mixed>|null $theme_json
	 * @phpstan-param list<string> $parts
	 * @phpstan-param Diagnostics $errors
	 * @return TokenMap Declared part names.
	 */
	private function validate_template_part_declarations( ?array $theme_json, array $parts, array &$errors ): array {
		$declared = self::empty_token_map();
		$entries  = is_array( $theme_json ) ? ( $theme_json['templateParts'] ?? [] ) : [];

		if ( [] !== $entries && ( ! is_array( $entries ) || ! array_is_list( $entries ) ) ) {
			$this->add_diagnostic(
				$errors,
				'invalid_template_part_declarations',
				'theme.json',
				__( 'theme.json templateParts must be an array of declarations.', 'superdav-ai-agent' ),
				[ 'json_path' => 'templateParts' ]
			);
			$entries = [];
		}

		foreach ( $entries as $index => $entry ) {
			$name = is_array( $entry ) ? ( $entry['name'] ?? null ) : null;
			if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name ) ) {
				$this->add_diagnostic(
					$errors,
					'invalid_template_part_declaration',
					'theme.json',
					__( 'Each template-part declaration requires a lowercase kebab-case name.', 'superdav-ai-agent' ),
					[ 'json_path' => 'templateParts.' . $index . '.name' ]
				);
				continue;
			}
			if ( isset( $declared[ $name ] ) ) {
				$this->add_diagnostic(
					$errors,
					'duplicate_template_part_declaration',
					'theme.json',
					__( 'Template-part declarations must not repeat a name.', 'superdav-ai-agent' ),
					[
						'json_path' => 'templateParts.' . $index . '.name',
						'name'      => $name,
					]
				);
				continue;
			}
			$declared[ $name ] = true;
			if ( ! in_array( 'parts/' . $name . '.html', $parts, true ) ) {
				$this->add_diagnostic(
					$errors,
					'missing_template_part_file',
					'parts/' . $name . '.html',
					__( 'A declared template part must have a matching parts/{name}.html file.', 'superdav-ai-agent' ),
					[ 'declaration' => 'templateParts.' . $index ]
				);
			}
		}

		foreach ( $parts as $part ) {
			$name = basename( $part, '.html' );
			if ( ! isset( $declared[ $name ] ) ) {
				$this->add_diagnostic(
					$errors,
					'undeclared_template_part',
					$part,
					__( 'Every generated template-part file must be declared in theme.json templateParts.', 'superdav-ai-agent' )
				);
			}
		}

		return $declared;
	}

	/**
	 * Validate custom template declarations against actual template files.
	 *
	 * @param array|null $theme_json Decoded theme.json object when valid.
	 * @param array      $templates  Theme-relative template paths.
	 * @param array      $errors     Diagnostics collected during validation.
	 * @phpstan-param array<string,mixed>|null $theme_json
	 * @phpstan-param list<string> $templates
	 * @phpstan-param Diagnostics $errors
	 * @return TokenMap
	 */
	private function validate_custom_template_declarations( ?array $theme_json, array $templates, array &$errors ): array {
		$declared = self::empty_token_map();
		$entries  = is_array( $theme_json ) ? ( $theme_json['customTemplates'] ?? [] ) : [];

		if ( [] !== $entries && ( ! is_array( $entries ) || ! array_is_list( $entries ) ) ) {
			$this->add_diagnostic(
				$errors,
				'invalid_custom_template_declarations',
				'theme.json',
				__( 'theme.json customTemplates must be an array of declarations.', 'superdav-ai-agent' ),
				[ 'json_path' => 'customTemplates' ]
			);
			return $declared;
		}

		foreach ( $entries as $index => $entry ) {
			$name      = is_array( $entry ) ? ( $entry['name'] ?? null ) : null;
			$title     = is_array( $entry ) ? ( $entry['title'] ?? null ) : null;
			$posttypes = is_array( $entry ) ? ( $entry['postTypes'] ?? null ) : null;
			if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name ) || ! is_string( $title ) || '' === trim( $title ) || ! is_array( $posttypes ) || [] === $posttypes ) {
				$this->add_diagnostic(
					$errors,
					'invalid_custom_template_declaration',
					'theme.json',
					__( 'Each custom template must declare name, title, and one or more post types.', 'superdav-ai-agent' ),
					[ 'json_path' => 'customTemplates.' . $index ]
				);
				continue;
			}

			$declared[ $name ] = true;
			if ( ! in_array( 'templates/' . $name . '.html', $templates, true ) ) {
				$this->add_diagnostic(
					$errors,
					'missing_custom_template_file',
					'templates/' . $name . '.html',
					__( 'A declared custom template must have a matching templates/{name}.html file.', 'superdav-ai-agent' ),
					[ 'declaration' => 'customTemplates.' . $index ]
				);
			}
		}

		return $declared;
	}

	/**
	 * Validate wp:template-part blocks without executing template content.
	 *
	 * @param string $contents Template content to inspect.
	 * @param string $relative Theme-relative template path.
	 * @param array  $parts    Theme-relative template-part paths.
	 * @param array  $declared Declared template-part names.
	 * @param array  $errors   Diagnostics collected during validation.
	 * @phpstan-param list<string> $parts
	 * @phpstan-param TokenMap $declared
	 * @phpstan-param Diagnostics $errors
	 */
	private function validate_template_part_references( string $contents, string $relative, array $parts, array $declared, array &$errors ): void {
		if ( ! preg_match_all( '/<!--\s*wp:template-part\s+(\{.*?\})\s*\/?>/s', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
			return;
		}

		foreach ( $matches[1] as $match ) {
			$attributes = json_decode( $match[0], true );
			$line       = $this->line_for_offset( $contents, $match[1] );
			$slug       = is_array( $attributes ) ? ( $attributes['slug'] ?? null ) : null;
			if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
				$this->add_diagnostic(
					$errors,
					'invalid_template_part_reference',
					$relative,
					__( 'A wp:template-part block must provide a lowercase kebab-case slug.', 'superdav-ai-agent' ),
					[ 'line' => $line ]
				);
				continue;
			}

			if ( ! in_array( 'parts/' . $slug . '.html', $parts, true ) ) {
				$this->add_diagnostic(
					$errors,
					'missing_template_part_reference',
					$relative,
					__( 'A wp:template-part block references a missing template-part file.', 'superdav-ai-agent' ),
					[
						'line' => $line,
						'slug' => $slug,
					]
				);
			}
			if ( ! isset( $declared[ $slug ] ) ) {
				$this->add_diagnostic(
					$errors,
					'undeclared_template_part_reference',
					$relative,
					__( 'A wp:template-part block references a part not declared in theme.json.', 'superdav-ai-agent' ),
					[
						'line' => $line,
						'slug' => $slug,
					]
				);
			}
		}
	}

	/**
	 * Validate filesystem pattern headers, slugs, and static block bodies.
	 *
	 * @param array  $files      Project files keyed by relative path.
	 * @param string $theme_slug Current theme directory slug.
	 * @param array  $tokens     Declared CSS variable names.
	 * @param array  $errors     Diagnostics collected during validation.
	 * @phpstan-param ProjectFiles $files
	 * @phpstan-param TokenMap $tokens
	 * @phpstan-param Diagnostics $errors
	 */
	private function validate_pattern_files( array $files, string $theme_slug, array $tokens, array &$errors ): void {
		$slugs = self::empty_token_map();
		foreach ( array_keys( $files ) as $relative ) {
			if ( ! str_starts_with( $relative, 'patterns/' ) || ! str_ends_with( $relative, '.php' ) ) {
				continue;
			}

			if ( 1 !== substr_count( $relative, '/' ) ) {
				$this->add_diagnostic(
					$errors,
					'invalid_pattern_path',
					$relative,
					__( 'Filesystem patterns must live directly under patterns/.', 'superdav-ai-agent' )
				);
			}

			$contents = $this->read_file( $relative, $files, $errors );
			if ( ! is_string( $contents ) ) {
				continue;
			}

			$end = strpos( $contents, '?>' );
			if ( false === $end ) {
				$this->add_diagnostic(
					$errors,
					'invalid_pattern_header',
					$relative,
					__( 'A static filesystem pattern must have a PHP header followed by a closing ?> delimiter.', 'superdav-ai-agent' )
				);
				continue;
			}

			$header = substr( $contents, 0, $end );
			$body   = substr( $contents, $end + 2 );
			$title  = $this->pattern_header_value( $header, 'Title' );
			$slug   = $this->pattern_header_value( $header, 'Slug' );
			if ( '' === $title ) {
				$this->add_diagnostic(
					$errors,
					'missing_pattern_title',
					$relative,
					__( 'A filesystem pattern header must declare a non-empty Title.', 'superdav-ai-agent' )
				);
			}
			if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*\/[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) || ! str_starts_with( $slug, $theme_slug . '/' ) ) {
				$this->add_diagnostic(
					$errors,
					'invalid_pattern_slug',
					$relative,
					__( 'A filesystem pattern Slug must use the current theme slug and a lowercase kebab-case pattern name.', 'superdav-ai-agent' )
				);
			} elseif ( isset( $slugs[ $slug ] ) ) {
				$this->add_diagnostic(
					$errors,
					'duplicate_pattern_slug',
					$relative,
					__( 'Filesystem pattern slugs must be unique within the project.', 'superdav-ai-agent' ),
					[ 'slug' => $slug ]
				);
			} else {
				$slugs[ $slug ] = true;
			}

			if ( preg_match( '/<\?(?:php|=)?/i', $body ) ) {
				$this->add_diagnostic(
					$errors,
					'executable_pattern_content',
					$relative,
					__( 'Filesystem pattern bodies must contain static block markup and must not execute PHP.', 'superdav-ai-agent' )
				);
			}

			$this->validate_block_markup( $body, $relative, $errors );
			$this->validate_markup_safety( $body, $relative, $files, $errors );
			$this->validate_markup_token_references( $body, $relative, $tokens, $errors );
		}
	}

	/**
	 * Return a normalized filesystem pattern header field.
	 */
	private function pattern_header_value( string $header, string $field ): string {
		if ( ! preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/mi', $header, $matches ) ) {
			return '';
		}

		return trim( $matches[1] );
	}

	/**
	 * Validate style-variation documents and their references to root tokens.
	 *
	 * @param array $files  Project files keyed by relative path.
	 * @param array $tokens Declared CSS variable names.
	 * @param array $errors Diagnostics collected during validation.
	 * @phpstan-param ProjectFiles $files
	 * @phpstan-param TokenMap $tokens
	 * @phpstan-param Diagnostics $errors
	 */
	private function validate_style_variations( array $files, array $tokens, array &$errors ): void {
		foreach ( array_keys( $files ) as $relative ) {
			if ( ! str_starts_with( $relative, 'styles/' ) || ! str_ends_with( $relative, '.json' ) ) {
				continue;
			}

			if ( 1 !== substr_count( $relative, '/' ) ) {
				$this->add_diagnostic(
					$errors,
					'invalid_style_variation_path',
					$relative,
					__( 'Style variations must live directly under styles/.', 'superdav-ai-agent' )
				);
			}

			$variation = $this->read_json_file( $relative, $files, $errors );
			if ( ! is_array( $variation ) ) {
				continue;
			}

			if ( 3 !== ( $variation['version'] ?? null ) || self::THEME_JSON_SCHEMA !== ( $variation['$schema'] ?? null ) ) {
				$this->add_diagnostic(
					$errors,
					'invalid_style_variation_schema',
					$relative,
					__( 'A style variation must declare the canonical theme.json schema and version 3.', 'superdav-ai-agent' )
				);
			}

			$slug = $variation['slug'] ?? null;
			if ( ! is_string( $slug ) || basename( $relative, '.json' ) !== $slug ) {
				$this->add_diagnostic(
					$errors,
					'invalid_style_variation_slug',
					$relative,
					__( 'A style variation slug must match its filename.', 'superdav-ai-agent' ),
					[ 'json_path' => 'slug' ]
				);
			}
			if ( ! is_string( $variation['title'] ?? null ) || '' === trim( (string) $variation['title'] ) ) {
				$this->add_diagnostic(
					$errors,
					'missing_style_variation_title',
					$relative,
					__( 'A style variation must declare a non-empty title.', 'superdav-ai-agent' ),
					[ 'json_path' => 'title' ]
				);
			}

			$this->validate_token_references( $variation, $tokens, $relative, '', $errors );
		}
	}

	/**
	 * Validate blocks with both a lightweight delimiter pass and existing policy.
	 *
	 * @param string $contents Template or pattern content to inspect.
	 * @param string $relative Theme-relative source path.
	 * @param array  $errors   Diagnostics collected during validation.
	 * @phpstan-param Diagnostics $errors
	 */
	private function validate_block_markup( string $contents, string $relative, array &$errors ): void {
		$this->validate_block_delimiters( $contents, $relative, $errors );
		if ( ! function_exists( 'parse_blocks' ) || ! class_exists( BlockValidator::class ) ) {
			return;
		}

		$report = ( new BlockValidator() )->validate_server_side( $contents );
		foreach ( (array) ( $report['results'] ?? [] ) as $result ) {
			if ( ! is_array( $result ) || ! empty( $result['isValid'] ) ) {
				continue;
			}
			$issues = [];
			foreach ( (array) ( $result['issues'] ?? [] ) as $issue ) {
				if ( is_string( $issue ) ) {
					$issues[] = $issue;
				}
			}
			$this->add_diagnostic(
				$errors,
				'invalid_block_markup',
				$relative,
				__( 'WordPress block validation found invalid markup or a disallowed core/html block.', 'superdav-ai-agent' ),
				[
					'block'  => (string) ( $result['blockName'] ?? '' ),
					'issues' => $issues,
				]
			);
		}
	}

	/**
	 * Detect malformed or unclosed block comment delimiters that parse_blocks()
	 * may otherwise treat as freeform content.
	 *
	 * @param string $contents Template or pattern content to inspect.
	 * @param string $relative Theme-relative source path.
	 * @param array  $errors   Diagnostics collected during validation.
	 * @phpstan-param Diagnostics $errors
	 */
	private function validate_block_delimiters( string $contents, string $relative, array &$errors ): void {
		if ( ! str_contains( $contents, '<!-- wp:' ) ) {
			return;
		}

		$stack = [];
		if ( ! preg_match_all( '/<!--\s*(\/?)wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)(.*?)-->/is', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
			$this->add_diagnostic(
				$errors,
				'malformed_block_delimiter',
				$relative,
				__( 'The template contains a malformed WordPress block comment delimiter.', 'superdav-ai-agent' )
			);
			return;
		}

		foreach ( $matches[0] as $index => $full_match ) {
			$is_closing = '/' === $matches[1][ $index ][0];
			$name       = strtolower( $matches[2][ $index ][0] );
			$tail       = trim( $matches[3][ $index ][0] );
			$line       = $this->line_for_offset( $contents, $full_match[1] );
			if ( $is_closing ) {
				$opened = array_pop( $stack );
				if ( ! is_array( $opened ) || $name !== $opened['name'] ) {
					$this->add_diagnostic(
						$errors,
						'mismatched_block_delimiter',
						$relative,
						__( 'A closing WordPress block comment does not match its opening block.', 'superdav-ai-agent' ),
						[
							'line'  => $line,
							'block' => $name,
						]
					);
				}
				continue;
			}

			if ( ! str_ends_with( $tail, '/' ) ) {
				$stack[] = [
					'name' => $name,
					'line' => $line,
				];
			}
		}

		foreach ( $stack as $opened ) {
			$this->add_diagnostic(
				$errors,
				'unclosed_block_delimiter',
				$relative,
				__( 'A WordPress block comment is missing its closing delimiter.', 'superdav-ai-agent' ),
				[
					'line'  => $opened['line'],
					'block' => $opened['name'],
				]
			);
		}
	}

	/**
	 * Validate source safety for non-template text project files.
	 *
	 * @param array $files  Project files keyed by relative path.
	 * @param array $tokens Declared CSS variable names.
	 * @param array $errors Diagnostics collected during validation.
	 * @phpstan-param ProjectFiles $files
	 * @phpstan-param TokenMap $tokens
	 * @phpstan-param Diagnostics $errors
	 */
	private function validate_text_file_sources( array $files, array $tokens, array &$errors ): void {
		foreach ( array_keys( $files ) as $relative ) {
			if ( in_array( $relative, [ 'theme.json' ], true ) || str_starts_with( $relative, '.sd-ai-agent/' ) || str_starts_with( $relative, 'templates/' ) || str_starts_with( $relative, 'parts/' ) || str_starts_with( $relative, 'patterns/' ) || str_starts_with( $relative, 'styles/' ) || ! $this->is_text_project_file( $relative ) ) {
				continue;
			}

			$contents = $this->read_file( $relative, $files, $errors );
			if ( ! is_string( $contents ) ) {
				continue;
			}
			$this->validate_markup_safety( $contents, $relative, $files, $errors );
			$this->validate_markup_token_references( $contents, $relative, $tokens, $errors );
		}
	}

	/**
	 * Determine whether an arbitrary project file is safe to inspect as text.
	 */
	private function is_text_project_file( string $relative ): bool {
		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );

		return in_array( $extension, [ 'css', 'html', 'json', 'php', 'svg', 'txt' ], true );
	}

	/**
	 * Validate placeholders, local asset references, and remote asset URLs.
	 *
	 * @param string $contents Template or text source content.
	 * @param string $relative Theme-relative source path.
	 * @param array  $files    Project files keyed by relative path.
	 * @param array  $errors   Diagnostics collected during validation.
	 * @phpstan-param ProjectFiles $files
	 * @phpstan-param Diagnostics $errors
	 */
	private function validate_markup_safety( string $contents, string $relative, array $files, array &$errors ): void {
		$this->validate_placeholder_content( $contents, $relative, $errors );

		if ( preg_match_all( '/\b(?:src|srcset|poster)\s*=\s*(["\'])(.*?)\1/is', $contents, $attributes, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $attributes[2] as $match ) {
				$this->validate_asset_reference( $match[0], $relative, 'asset', $files, $errors, [ 'line' => $this->line_for_offset( $contents, $match[1] ) ] );
			}
		}

		if ( preg_match_all( '/url\(\s*(["\']?)(.*?)\1\s*\)/is', $contents, $urls, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $urls[2] as $match ) {
				$this->validate_asset_reference( $match[0], $relative, 'asset', $files, $errors, [ 'line' => $this->line_for_offset( $contents, $match[1] ) ] );
			}
		}

		if ( preg_match_all( '/"(?:url|src)"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/i', $contents, $json_urls, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $json_urls[1] as $match ) {
				$this->validate_asset_reference( stripcslashes( $match[0] ), $relative, 'asset', $files, $errors, [ 'line' => $this->line_for_offset( $contents, $match[1] ) ] );
			}
		}
	}

	/**
	 * Validate CSS variable references in markup/CSS source.
	 *
	 * @param string $contents Template or text source content.
	 * @param string $relative Theme-relative source path.
	 * @param array  $tokens   Declared CSS variable names.
	 * @param array  $errors   Diagnostics collected during validation.
	 * @phpstan-param TokenMap $tokens
	 * @phpstan-param Diagnostics $errors
	 */
	private function validate_markup_token_references( string $contents, string $relative, array $tokens, array &$errors ): void {
		foreach ( self::find_malformed_css_variable_references( $contents ) as $malformed ) {
			$this->add_diagnostic(
				$errors,
				'malformed_css_variable_reference',
				$relative,
				__( 'A CSS var() reference is malformed.', 'superdav-ai-agent' ),
				[
					'line'   => $this->line_for_offset( $contents, $malformed['offset'] ),
					'reason' => $malformed['reason'],
					'value'  => $malformed['value'],
				]
			);
		}

		if ( ! preg_match_all( '/var\(\s*(--wp--(?:preset|custom)--[a-z0-9-]+)\s*(?:,[^)]*)?\)/i', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
			return;
		}

		foreach ( $matches[1] as $match ) {
			$reference = strtolower( $match[0] );
			if ( isset( $tokens[ $reference ] ) ) {
				continue;
			}
			$this->add_diagnostic(
				$errors,
				'unresolved_token_reference',
				$relative,
				__( 'A WordPress preset or custom token reference does not resolve in theme.json settings.', 'superdav-ai-agent' ),
				[
					'line'      => $this->line_for_offset( $contents, $match[1] ),
					'reference' => $reference,
				]
			);
		}
	}

	/**
	 * Add path-specific malformed CSS variable diagnostics for decoded JSON.
	 *
	 * @param list<MalformedCssVariableReference> $references Malformed references.
	 * @param string                              $relative   Theme-relative source path.
	 * @param array                               $errors     Diagnostics collected during validation.
	 * @phpstan-param Diagnostics $errors
	 */
	private function add_malformed_css_variable_diagnostics( array $references, string $relative, array &$errors ): void {
		foreach ( $references as $reference ) {
			$this->add_diagnostic(
				$errors,
				'malformed_css_variable_reference',
				$relative,
				__( 'A CSS var() reference is malformed.', 'superdav-ai-agent' ),
				[
					'json_path' => $reference['path'],
					'reason'    => $reference['reason'],
					'value'     => $reference['value'],
				]
			);
		}
	}

	/**
	 * Validate placeholder copy, links, and known placeholder image services.
	 *
	 * @param string $contents Template or text source content.
	 * @param string $relative Theme-relative source path.
	 * @param array  $errors   Diagnostics collected during validation.
	 * @phpstan-param Diagnostics $errors
	 */
	private function validate_placeholder_content( string $contents, string $relative, array &$errors ): void {
		$placeholder_pattern = '/\b(?:lorem ipsum|replace (?:this|me|with)|your (?:headline|text|content)|sample (?:text|content)|call to action|todo|tbd)\b/i';
		if ( preg_match_all( $placeholder_pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$this->add_diagnostic(
					$errors,
					'placeholder_content',
					$relative,
					__( 'Replace placeholder copy before activating the generated theme.', 'superdav-ai-agent' ),
					[ 'line' => $this->line_for_offset( $contents, $match[1] ) ]
				);
			}
		}

		if ( preg_match_all( '/\bhref\s*=\s*(["\'])\s*(?:#|)\s*\1/i', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$this->add_diagnostic(
					$errors,
					'placeholder_link',
					$relative,
					__( 'Replace empty or # placeholder links before activating the generated theme.', 'superdav-ai-agent' ),
					[ 'line' => $this->line_for_offset( $contents, $match[1] ) ]
				);
			}
		}

		if ( preg_match_all( '/(?:placehold\.(?:co|it|com)|via\.placeholder\.com|picsum\.photos|loremflickr\.com|dummyimage\.com|source\.unsplash\.com)/i', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$this->add_diagnostic(
					$errors,
					'placeholder_image_service',
					$relative,
					__( 'Placeholder image services are not permitted in generated theme projects.', 'superdav-ai-agent' ),
					[ 'line' => $this->line_for_offset( $contents, $match[1] ) ]
				);
			}
		}
	}

	/**
	 * Validate a local asset reference without requesting its URL.
	 *
	 * @param string $reference Asset reference value.
	 * @param string $relative  Theme-relative source path.
	 * @param string $kind      Asset kind being checked.
	 * @param array  $files     Project files keyed by relative path.
	 * @param array  $errors    Diagnostics collected during validation.
	 * @param array  $location  Source location metadata.
	 * @phpstan-param ProjectFiles $files
	 * @phpstan-param Diagnostics $errors
	 * @phpstan-param array<string,mixed> $location
	 */
	private function validate_asset_reference( string $reference, string $relative, string $kind, array $files, array &$errors, array $location = [] ): void {
		$reference = trim( $reference );
		if ( '' === $reference ) {
			$this->add_diagnostic(
				$errors,
				'missing_asset_reference',
				$relative,
				__( 'An asset reference must name a local bundled file.', 'superdav-ai-agent' ),
				$location
			);
			return;
		}

		if ( str_starts_with( strtolower( $reference ), 'data:' ) ) {
			$this->add_diagnostic(
				$errors,
				'non_local_asset',
				$relative,
				__( 'Embedded data assets are not permitted; bundle a local project file instead.', 'superdav-ai-agent' ),
				$location
			);
			return;
		}

		if ( preg_match( '#^(?:https?:)?//#i', $reference ) ) {
			if ( ! $this->is_local_site_url( $reference ) ) {
				$this->add_diagnostic(
					$errors,
					'remote_asset_url',
					$relative,
					__( 'Remote asset URLs are not permitted; import or bundle the asset locally.', 'superdav-ai-agent' ),
					$location
				);
			}
			return;
		}

		if ( ! in_array( $kind, [ 'asset', 'font' ], true ) ) {
			return;
		}

		$path = $this->normalise_asset_reference( $reference );
		if ( null === $path ) {
			$this->add_diagnostic(
				$errors,
				'asset_path_traversal',
				$relative,
				__( 'Asset paths must stay within the generated theme project.', 'superdav-ai-agent' ),
				$location
			);
			return;
		}

		if ( str_starts_with( $path, 'wp-content/' ) || str_starts_with( $path, 'wp-includes/' ) ) {
			return;
		}

		if ( ! isset( $files[ $path ] ) ) {
			$this->add_diagnostic(
				$errors,
				'missing_local_asset',
				$relative,
				__( 'A referenced local asset file is missing from the generated theme project.', 'superdav-ai-agent' ),
				array_merge( $location, [ 'asset' => $path ] )
			);
		}
	}

	/**
	 * Normalize a theme-relative asset path or return null for unsafe paths.
	 */
	private function normalise_asset_reference( string $reference ): ?string {
		$reference = preg_replace( '/[?#].*$/', '', $reference ) ?? $reference;
		$reference = preg_replace( '#^file:#i', '', $reference ) ?? $reference;
		$reference = rawurldecode( trim( $reference ) );
		$reference = ltrim( $reference, '/' );
		while ( str_starts_with( $reference, './' ) ) {
			$reference = substr( $reference, 2 );
		}

		if ( '' === $reference || str_contains( $reference, "\0" ) ) {
			return null;
		}

		$segments = explode( '/', $reference );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
		}

		return implode( '/', $segments );
	}

	/**
	 * Allow local-site absolute media URLs without treating them as remote.
	 */
	private function is_local_site_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return is_string( $site_host ) && strtolower( $host ) === strtolower( $site_host );
	}

	/**
	 * Read and decode an object-shaped JSON project file.
	 *
	 * @param string $relative Theme-relative source path.
	 * @param array  $files    Project files keyed by relative path.
	 * @param array  $errors   Diagnostics collected during validation.
	 * @phpstan-param ProjectFiles $files
	 * @phpstan-param Diagnostics $errors
	 * @return array<string,mixed>|null
	 */
	private function read_json_file( string $relative, array $files, array &$errors ): ?array {
		$contents = $this->read_file( $relative, $files, $errors );
		if ( ! is_string( $contents ) ) {
			return null;
		}

		$decoded = json_decode( $contents, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! str_starts_with( ltrim( $contents ), '{' ) || ! is_array( $decoded ) || ( [] !== $decoded && array_is_list( $decoded ) ) ) {
			$this->add_diagnostic(
				$errors,
				'invalid_json',
				$relative,
				__( 'This file must contain a valid JSON object.', 'superdav-ai-agent' )
			);
			return null;
		}

		$object = self::empty_json_object();
		foreach ( $decoded as $key => $value ) {
			if ( ! is_string( $key ) ) {
				$this->add_diagnostic(
					$errors,
					'invalid_json',
					$relative,
					__( 'This file must contain a JSON object with string property names.', 'superdav-ai-agent' )
				);
				return null;
			}
			$object[ $key ] = $value;
		}

		return $object;
	}

	/**
	 * Read one bounded local project file without exposing its absolute path.
	 *
	 * @param string $relative Theme-relative source path.
	 * @param array  $files    Project files keyed by relative path.
	 * @param array  $errors   Diagnostics collected during validation.
	 * @phpstan-param ProjectFiles $files
	 * @phpstan-param Diagnostics $errors
	 */
	private function read_file( string $relative, array $files, array &$errors ): ?string {
		if ( ! isset( $files[ $relative ] ) ) {
			return null;
		}
		if ( $files[ $relative ]['size'] > self::MAX_FILE_SIZE || ! is_readable( $files[ $relative ]['path'] ) ) {
			$this->add_diagnostic(
				$errors,
				'file_unreadable',
				$relative,
				__( 'The project file could not be read safely.', 'superdav-ai-agent' )
			);
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only validation of a bounded local project file.
		$contents = file_get_contents( $files[ $relative ]['path'] );
		if ( false === $contents ) {
			$this->add_diagnostic(
				$errors,
				'file_unreadable',
				$relative,
				__( 'The project file could not be read safely.', 'superdav-ai-agent' )
			);
			return null;
		}

		return $contents;
	}

	/**
	 * Return a normalized relative path for an item below a root.
	 */
	private function relative_path( string $root, string $path ): string {
		$relative = substr( str_replace( '\\', '/', $path ), strlen( str_replace( '\\', '/', $root ) ) + 1 );

		return ltrim( (string) $relative, '/' );
	}

	/**
	 * Test whether a resolved path remains below the resolved root.
	 */
	private function is_within_root( string $root, string $path ): bool {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$path = str_replace( '\\', '/', $path );

		return $path === $root || str_starts_with( $path, $root . '/' );
	}

	/**
	 * Return a one-indexed source line for an offset.
	 */
	private function line_for_offset( string $contents, int $offset ): int {
		return substr_count( substr( $contents, 0, $offset ), "\n" ) + 1;
	}

	/**
	 * Add a stable, theme-relative diagnostic.
	 *
	 * @param array  $diagnostics Diagnostics collected during validation.
	 * @param string $code        Stable diagnostic code.
	 * @param string $path        Theme-relative diagnostic path.
	 * @param string $message     Human-readable diagnostic message.
	 * @param array  $location    Source location metadata.
	 * @phpstan-param Diagnostics $diagnostics
	 * @param-out Diagnostics     $diagnostics
	 * @phpstan-param array<string,mixed> $location
	 */
	private function add_diagnostic( array &$diagnostics, string $code, string $path, string $message, array $location = [] ): void {
		$diagnostic = [
			'code'    => $code,
			'path'    => $path,
			'message' => $message,
		];
		if ( [] !== $location ) {
			$diagnostic['location'] = $location;
		}
		$diagnostics[] = $diagnostic;
	}

	/**
	 * Normalize and sort the public report deterministically.
	 *
	 * @return array{
	 *   valid:bool,
	 *   marked:bool,
	 *   project_version:int,
	 *   fingerprint:string,
	 *   files_scanned:int,
	 *   errors:Diagnostics,
	 *   warnings:Diagnostics
	 * }
	 * @param bool   $valid           Whether earlier checks consider the project valid.
	 * @param bool   $marked          Whether the project marker exists.
	 * @param int    $project_version Marker schema version.
	 * @param string $fingerprint     Stable project fingerprint.
	 * @param int    $files_scanned   Number of project files scanned.
	 * @param array  $errors          Diagnostics collected during validation.
	 * @param array  $warnings        Warnings collected during validation.
	 * @phpstan-param Diagnostics $errors
	 * @phpstan-param Diagnostics $warnings
	 */
	private function build_report( bool $valid, bool $marked, int $project_version, string $fingerprint, int $files_scanned, array $errors, array $warnings ): array {
		$this->sort_diagnostics( $errors );
		$this->sort_diagnostics( $warnings );

		return [
			'valid'           => $valid && [] === $errors,
			'marked'          => $marked,
			'project_version' => $project_version,
			'fingerprint'     => $fingerprint,
			'files_scanned'   => $files_scanned,
			'errors'          => $errors,
			'warnings'        => $warnings,
		];
	}

	/**
	 * Sort diagnostics by path, code, location, then message.
	 *
	 * @param array $diagnostics Diagnostics to sort.
	 * @phpstan-param Diagnostics $diagnostics
	 * @param-out Diagnostics $diagnostics
	 */
	private function sort_diagnostics( array &$diagnostics ): void {
		usort(
			$diagnostics,
			static function ( array $left, array $right ): int {
				$left_location  = (array) ( $left['location'] ?? [] );
				$right_location = (array) ( $right['location'] ?? [] );
				$left_key       = implode(
					'|',
					[
						(string) $left['path'],
						(string) $left['code'],
						(string) ( $left_location['line'] ?? '' ),
						(string) ( $left_location['json_path'] ?? '' ),
						(string) $left['message'],
					]
				);
				$right_key      = implode(
					'|',
					[
						(string) $right['path'],
						(string) $right['code'],
						(string) ( $right_location['line'] ?? '' ),
						(string) ( $right_location['json_path'] ?? '' ),
						(string) $right['message'],
					]
				);

				return $left_key <=> $right_key;
			}
		);
	}
}
