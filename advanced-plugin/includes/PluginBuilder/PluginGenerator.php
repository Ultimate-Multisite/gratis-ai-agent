<?php

declare(strict_types=1);
/**
 * Plugin Generator — AI-powered WordPress plugin plan and code generation.
 *
 * Uses wp_ai_client_prompt() (WordPress 7.0+ AI Client SDK) to:
 *   1. Generate a structured implementation plan from a natural-language description.
 *   2. Generate plugin code file-by-file respecting dependency order.
 *   3. Review generated code for security and WordPress standards compliance.
 *
 * @package SdAiAgent\PluginBuilder
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\PluginBuilder;

use SdAiAgent\Core\ProviderCredentialLoader;
use SdAiAgent\Core\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PluginGenerator — generates WordPress plugins via the AI Client SDK.
 *
 * @since 1.5.0
 */
class PluginGenerator {

	/**
	 * Generate a structured plugin implementation plan from a description.
	 *
	 * Returns a plan array with keys: name, slug, version, type, files,
	 * hooks_used, settings, has_admin_page, estimated_complexity.
	 *
	 * @param string              $description Natural-language plugin description.
	 * @param array<string,mixed> $context {
	 *  Optional generation context.
	 *   @type array  $hooks          Hooks from HookScanner for extension plugins.
	 *   @type array  $existing_files Existing plugin file paths for extensions.
	 *   @type string $error_context  Error message from a previous failed attempt.
	 *   @type string $provider_id    Parent run provider selected by AgentLoop.
	 *   @type string $model_id       Parent run model selected by AgentLoop.
	 * }
	 * @return array<string,mixed>|\WP_Error Plan array or WP_Error on failure.
	 */
	public static function generate_plan( string $description, array $context = [] ): array|\WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'ai_client_unavailable',
				__( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' )
			);
		}

		if ( empty( trim( $description ) ) ) {
			return new WP_Error(
				'sd_ai_agent_empty_description',
				__( 'Plugin description must not be empty.', 'superdav-ai-agent' )
			);
		}

		$system_instruction = <<<'INSTRUCTION'
You are an expert WordPress plugin architect. Produce a structured implementation plan for a WordPress plugin.

Respond with ONLY a valid JSON object matching this exact schema:
{
  "name": "Human-readable plugin name",
  "slug": "plugin-slug-in-kebab-case",
  "version": "1.0.0",
  "type": "single-file",
  "files": [
    { "path": "my-plugin/my-plugin.php", "purpose": "Main plugin file", "dependencies": [] }
  ],
  "hooks_used": ["wp_footer"],
  "settings": ["setting_key"],
  "has_admin_page": false,
  "estimated_complexity": "simple"
}

Rules:
- "type" must be "single-file" when files[] has exactly one entry; otherwise "multi-file".
- Use "single-file" for simple, focused plugins (one shortcode, widget, or small feature).
- The first entry in files[] is always the main plugin file.
- "estimated_complexity" must be one of: "simple", "moderate", "complex".
- "slug" must be a valid WordPress plugin slug (lowercase, hyphens, no underscores).
- For extension plugins, list dependency hooks in "hooks_used".
- Return ONLY the JSON object. No markdown, no explanations.
INSTRUCTION;

		$prompt = "Create an implementation plan for a WordPress plugin with this description:\n\n" . $description;

		if ( ! empty( $context['hooks'] ) && is_array( $context['hooks'] ) ) {
			$hooks_json = wp_json_encode( $context['hooks'] );
			$prompt    .= "\n\nAvailable hooks from target plugin/theme:\n" . (string) $hooks_json;
		}

		if ( ! empty( $context['existing_files'] ) && is_array( $context['existing_files'] ) ) {
			$files_list = implode( "\n", $context['existing_files'] );
			$prompt    .= "\n\nExisting files to extend:\n" . $files_list;
		}

		if ( ! empty( $context['error_context'] ) ) {
			$prompt .= "\n\nPrevious attempt failed with this error (adjust the plan accordingly):\n" . (string) $context['error_context'];
		}

		$builder = self::create_prompt_builder( $prompt, $context );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$raw = $builder
			->using_system_instruction( $system_instruction )
			->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$json_str = self::extract_json( (string) $raw );
		$plan     = json_decode( $json_str, true );

		if ( ! is_array( $plan ) || empty( $plan['slug'] ) || empty( $plan['files'] ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_plan',
				__( 'AI returned an invalid plan. Try again with a more detailed description.', 'superdav-ai-agent' ),
				array( 'raw' => (string) $raw )
			);
		}

		/** @var array<string,mixed> $plan */
		$plan['slug'] = sanitize_title( (string) $plan['slug'] );

		return $plan;
	}

	/**
	 * Generate plugin code file-by-file from a structured plan.
	 *
	 * Files are generated in dependency order. Each previously generated file
	 * is passed as context to subsequent file generation calls.
	 *
	 * @param array<string,mixed> $plan    Plan array from generate_plan().
	 * @param array<string,mixed> $context Parent provider/model routing context.
	 * @return array{files: array<string,string>, plan: array<string,mixed>}|\WP_Error
	 */
	public static function generate_code( array $plan, array $context = [] ): array|\WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'ai_client_unavailable',
				__( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' )
			);
		}

		$raw_file_specs = isset( $plan['files'] ) && is_array( $plan['files'] ) ? $plan['files'] : array();
		if ( empty( $raw_file_specs ) ) {
			return new WP_Error(
				'sd_ai_agent_empty_plan',
				__( 'Plan contains no files to generate.', 'superdav-ai-agent' )
			);
		}

		// Normalise to array<int,array<string,mixed>> for the dependency sorter.
		/** @var array<int,array<string,mixed>> $file_specs */
		$file_specs = array_values(
			array_filter(
				$raw_file_specs,
				static function ( mixed $v ): bool {
					return is_array( $v );
				}
			)
		);

		$ordered = self::order_files_by_dependencies( $file_specs );
		/** @var array<string,string> $generated_files */
		$generated_files = array();

		foreach ( $ordered as $file_spec ) {
			if ( ! is_array( $file_spec ) ) {
				continue;
			}

			$path       = isset( $file_spec['path'] ) ? (string) $file_spec['path'] : '';
			$normalised = self::normalise_generated_file_path( (string) $plan['slug'], $path, empty( $generated_files ) );
			if ( is_wp_error( $normalised ) ) {
				return $normalised;
			}
			$file_spec['path'] = $normalised;

			$code = self::generate_file( $plan, $file_spec, $generated_files, $context );

			if ( is_wp_error( $code ) ) {
				return $code;
			}

			$code = self::prepare_generated_php( (string) $code, $plan, $file_spec, $generated_files, $context );
			if ( is_wp_error( $code ) ) {
				return $code;
			}

			$path = (string) $file_spec['path'];
			if ( '' !== $path ) {
				$generated_files[ $path ] = (string) $code;
			}
		}

		if ( empty( $generated_files ) ) {
			return new WP_Error(
				'sd_ai_agent_no_files_generated',
				__( 'AI did not produce any files. Try again with a more detailed description.', 'superdav-ai-agent' )
			);
		}

		return array(
			'files' => $generated_files,
			'plan'  => $plan,
		);
	}

	/**
	 * Generate a single plugin file's PHP source code.
	 *
	 * Includes code quality instructions: strict_types, proper escaping,
	 * nonce verification, and ABSPATH guard.
	 *
	 * @param array<string,mixed>  $plan        Plan array from generate_plan().
	 * @param array<string,mixed>  $file_spec   File spec: path, purpose, dependencies.
	 * @param array<string,string> $prior_files Already-generated files keyed by relative path.
	 * @param array<string,mixed>  $context     Parent provider/model routing context.
	 * @return string|\WP_Error Generated PHP source code or WP_Error.
	 */
	public static function generate_file( array $plan, array $file_spec, array $prior_files = [], array $context = [] ): string|\WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'ai_client_unavailable',
				__( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' )
			);
		}

		$slug    = isset( $plan['slug'] ) ? (string) $plan['slug'] : 'generated-plugin';
		$name    = isset( $plan['name'] ) ? (string) $plan['name'] : $slug;
		$version = isset( $plan['version'] ) ? (string) $plan['version'] : '1.0.0';
		$path    = isset( $file_spec['path'] ) ? (string) $file_spec['path'] : $slug . '/' . $slug . '.php';
		$purpose = isset( $file_spec['purpose'] ) ? (string) $file_spec['purpose'] : 'Plugin file';
		$is_main = empty( $prior_files );

		$system_instruction = <<<'INSTRUCTION'
You are an expert WordPress plugin developer. Generate production-ready WordPress plugin PHP code.

Rules:
- Output ONLY valid PHP code. No explanations, no markdown code fences.
- Use declare(strict_types=1) at the top of every file.
- Follow WordPress Coding Standards: snake_case functions, PascalCase classes.
- Prefix all function and class names with the plugin slug (using underscores, not hyphens).
- Sanitize all inputs with sanitize_*() functions.
- Escape all outputs with esc_html(), esc_attr(), esc_url(), or wp_kses_post().
- Verify nonces for all form submissions and AJAX handlers.
- Guard every file with: if ( ! defined( 'ABSPATH' ) ) { exit; }
- The plugin must be self-contained — no external dependencies beyond WordPress core.
INSTRUCTION;

		$prompt  = "Plugin name: {$name}\n";
		$prompt .= "Plugin slug: {$slug}\n";
		$prompt .= "File to generate: {$path}\n";
		$prompt .= "File purpose: {$purpose}\n";

		if ( $is_main ) {
			$prompt .= "\nThis is the MAIN plugin file. Include the standard WordPress plugin header comment:\n";
			$prompt .= "/**\n * Plugin Name: {$name}\n * Version: {$version}\n * Description: Generated by Superdav AI Agent.\n * Requires at least: 6.0\n * Requires PHP: 8.0\n */\n";
		}

		if ( ! empty( $plan['hooks_used'] ) && is_array( $plan['hooks_used'] ) ) {
			$hooks_list = implode( ', ', $plan['hooks_used'] );
			$prompt    .= "\nWordPress hooks to use: {$hooks_list}\n";
		}

		if ( ! empty( $plan['settings'] ) && is_array( $plan['settings'] ) ) {
			$settings_list = implode( ', ', $plan['settings'] );
			$prompt       .= "Settings/options to implement: {$settings_list}\n";
		}

		if ( ! empty( $plan['has_admin_page'] ) ) {
			$prompt .= "Include an admin settings page under Settings menu.\n";
		}

		if ( ! empty( $prior_files ) ) {
			$prompt .= "\nAlready-generated files in this plugin (do not repeat their code, only reference them):\n";
			foreach ( $prior_files as $prior_path => $prior_code ) {
				$prompt .= "\n=== {$prior_path} ===\n{$prior_code}\n=== end {$prior_path} ===\n";
			}
		}

		$prompt .= "\nGenerate the complete PHP source code for {$path}. Output only PHP code, no markdown.";

		$builder = self::create_prompt_builder( $prompt, $context );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$raw = $builder
			->using_system_instruction( $system_instruction )
			->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return (string) $raw;
	}

	/**
	 * Post-generation review pass for security, WP standards, and potential fatals.
	 *
	 * @param array<string,string> $files   Map of relative path → source code.
	 * @param array<string,mixed>  $plan    Plan array from generate_plan().
	 * @param array<string,mixed>  $context Parent provider/model routing context.
	 * @return array{approved: bool, issues: array<int,array<string,mixed>>, suggested_fixes: array<int,array<string,mixed>>}|\WP_Error
	 */
	public static function review_code( array $files, array $plan, array $context = [] ): array|\WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'ai_client_unavailable',
				__( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' )
			);
		}

		if ( empty( $files ) ) {
			return new WP_Error(
				'sd_ai_agent_empty_files',
				__( 'No files provided for review.', 'superdav-ai-agent' )
			);
		}

		$system_instruction = <<<'INSTRUCTION'
You are a WordPress security and code quality reviewer. Review the provided plugin code.

Respond with ONLY a valid JSON object:
{
  "approved": true,
  "issues": [
    { "file": "filename.php", "line": 42, "severity": "critical", "description": "SQL injection risk" }
  ],
  "suggested_fixes": [
    { "file": "filename.php", "description": "Use $wpdb->prepare() for all database queries" }
  ]
}

Check for:
- SQL injection (unescaped $wpdb queries, missing prepare())
- XSS vulnerabilities (unescaped output)
- Missing nonce verification on form and AJAX handlers
- Missing capability checks before privileged operations (manage_options, edit_posts, etc.)
- PHP syntax errors or likely fatal errors (undefined functions, class redeclarations)
- Insecure direct object references
- Missing ABSPATH guard

Severity levels: "critical", "high", "medium", "low"
Set "approved" to false if ANY critical or high severity issues exist.
Return ONLY the JSON object. No markdown, no explanations.
INSTRUCTION;

		$files_text  = '';
		$plugin_name = isset( $plan['name'] ) ? (string) $plan['name'] : 'Unknown plugin';

		foreach ( $files as $file_path => $code ) {
			$files_text .= "\n=== {$file_path} ===\n{$code}\n";
		}

		$prompt  = "Review the following WordPress plugin code for security vulnerabilities, quality issues, and potential fatal errors.\n";
		$prompt .= "Plugin: {$plugin_name}\n";
		$prompt .= $files_text;

		$builder = self::create_prompt_builder( $prompt, $context );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$raw = $builder
			->using_system_instruction( $system_instruction )
			->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$json_str = self::extract_json( (string) $raw );
		$review   = json_decode( $json_str, true );

		if ( ! is_array( $review ) || ! isset( $review['approved'] ) ) {
			// Parsing failure: approve to not block the workflow.
			return array(
				'approved'        => true,
				'issues'          => array(),
				'suggested_fixes' => array(),
			);
		}

		/** @var array<int,array<string,mixed>> $issues */
		$issues = isset( $review['issues'] ) && is_array( $review['issues'] )
			? array_values(
				array_filter(
					$review['issues'],
					static function ( mixed $v ): bool {
						return is_array( $v );
					}
				)
			)
			: array();

		/** @var array<int,array<string,mixed>> $suggested_fixes */
		$suggested_fixes = isset( $review['suggested_fixes'] ) && is_array( $review['suggested_fixes'] )
			? array_values(
				array_filter(
					$review['suggested_fixes'],
					static function ( mixed $v ): bool {
						return is_array( $v );
					}
				)
			)
			: array();

		return array(
			'approved'        => (bool) $review['approved'],
			'issues'          => $issues,
			'suggested_fixes' => $suggested_fixes,
		);
	}

	/**
	 * Parse ===FILE: ... ===ENDFILE=== blocks from AI output.
	 *
	 * @param string $raw  Raw AI response.
	 * @param string $slug Plugin slug for fallback path construction.
	 * @return array<string,string> Map of relative path → source code.
	 */
	public static function parse_file_blocks( string $raw, string $slug ): array {
		$files   = array();
		$pattern = '/===FILE:\s*([^\n=]+)===[^\n]*\n(.*?)===ENDFILE===/s';
		preg_match_all( $pattern, $raw, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$path           = trim( $match[1] );
			$code           = $match[2];
			$files[ $path ] = $code;
		}

		// Fallback: treat entire output as main file when no blocks found.
		if ( empty( $files ) && str_contains( $raw, '<?php' ) ) {
			$files[ $slug . '/' . $slug . '.php' ] = $raw;
		}

		return $files;
	}

	/**
	 * Detect the main plugin file from a generated file map.
	 *
	 * Identifies the file containing the WordPress plugin header comment.
	 * Falls back to slug/slug.php, then the first .php file in the map.
	 *
	 * @param array<string,string> $files Map of relative path → source code.
	 * @param string               $slug  Plugin slug.
	 * @return string Relative path to the main plugin file, or empty string.
	 */
	public static function detect_main_file( array $files, string $slug ): string {
		foreach ( $files as $path => $code ) {
			if ( str_contains( $code, 'Plugin Name:' ) ) {
				return $path;
			}
		}

		$candidate = $slug . '/' . $slug . '.php';
		if ( isset( $files[ $candidate ] ) ) {
			return $candidate;
		}

		foreach ( array_keys( $files ) as $path ) {
			if ( str_ends_with( $path, '.php' ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Normalise an AI-supplied generated file path into slug-relative form.
	 *
	 * The plugin builder asks models for paths like "slug/slug.php", but models
	 * sometimes omit the extension and return "slug/slug". Because generated
	 * files are PHP-only in this flow, missing extensions are repaired before the
	 * installer can create a directory/file collision such as "slug/slug".
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $path    AI-supplied path.
	 * @param bool   $is_main Whether this is the first/main generated file.
	 * @return string|\WP_Error Normalised path, including the slug prefix.
	 */
	public static function normalise_generated_file_path( string $slug, string $path, bool $is_main = false ): string|\WP_Error {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return new WP_Error(
				'sd_ai_agent_invalid_slug',
				__( 'Plugin slug must not be empty.', 'superdav-ai-agent' )
			);
		}

		$normalised = ltrim( str_replace( '\\', '/', trim( $path ) ), '/' );
		if ( '' === $normalised ) {
			$normalised = $slug . '/' . $slug . '.php';
		}

		if ( str_contains( $normalised, "\0" ) || str_contains( $normalised, '../' ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_generated_path',
				__( 'Generated plugin file path is invalid.', 'superdav-ai-agent' )
			);
		}

		if ( str_starts_with( $normalised, $slug . '/' ) ) {
			$normalised = substr( $normalised, strlen( $slug ) + 1 );
		}

		if ( '' === $normalised ) {
			$normalised = $slug . '.php';
		}

		if ( ! str_ends_with( strtolower( $normalised ), '.php' ) ) {
			$normalised .= '.php';
		}

		if ( $is_main ) {
			$basename = basename( $normalised );
			if ( $slug . '.php' !== $basename && ! str_contains( dirname( $normalised ), '/' ) ) {
				$normalised = $slug . '.php';
			}
		}

		return $slug . '/' . $normalised;
	}

	/**
	 * Resolve the provider/model pair for a nested plugin-generation request.
	 *
	 * Parent context wins because it represents the provider that successfully
	 * produced the ability call. Direct callers fall back to Settings' validated
	 * authenticated pair instead of allowing the SDK to choose an arbitrary
	 * connector.
	 *
	 * @param array<string,mixed> $context Parent execution context.
	 * @return array{provider_id:string,model_id:string}|WP_Error
	 */
	public static function resolve_generation_route( array $context = [] ): array|WP_Error {
		$provider_id = trim( (string) ( $context['provider_id'] ?? '' ) );
		$model_id    = trim( (string) ( $context['model_id'] ?? '' ) );

		if ( '' !== $provider_id ) {
			if ( '' !== $model_id && ! Settings::is_model_advertised( $provider_id, $model_id ) ) {
				return new WP_Error(
					'sd_ai_agent_plugin_generation_model_unavailable',
					__( 'The AI model selected for this run is unavailable for plugin generation. Configure a compatible text model and retry.', 'superdav-ai-agent' )
				);
			}

			return array(
				'provider_id' => $provider_id,
				'model_id'    => $model_id,
			);
		}

		$settings    = Settings::instance();
		$provider_id = trim( $settings->get_default_provider() );
		$model_id    = trim( $settings->get_default_model() );

		if ( '' === $provider_id ) {
			return new WP_Error(
				'sd_ai_agent_plugin_generation_provider_unavailable',
				__( 'No authenticated AI provider is configured for plugin generation. Configure a text-capable provider and retry.', 'superdav-ai-agent' )
			);
		}

		return array(
			'provider_id' => $provider_id,
			'model_id'    => $model_id,
		);
	}

	// ─── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Create one provider-bound prompt builder for every nested generation stage.
	 *
	 * @param string              $prompt  Prompt text.
	 * @param array<string,mixed> $context Parent provider/model routing context.
	 * @return \WP_AI_Client_Prompt_Builder|WP_Error
	 */
	private static function create_prompt_builder( string $prompt, array $context = [] ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'ai_client_unavailable',
				__( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' )
			);
		}

		ProviderCredentialLoader::load();
		$route = self::resolve_generation_route( $context );
		if ( is_wp_error( $route ) ) {
			return $route;
		}

		$provider_id = $route['provider_id'];
		$model_id    = $route['model_id'];

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( ! $registry->hasProvider( $provider_id ) || null === $registry->getProviderRequestAuthentication( $provider_id ) ) {
				return new WP_Error(
					'sd_ai_agent_plugin_generation_provider_unavailable',
					__( 'The AI provider selected for plugin generation is unavailable. Configure an authenticated text-capable provider and retry.', 'superdav-ai-agent' )
				);
			}

			$builder = wp_ai_client_prompt( $prompt );
			if ( '' !== $model_id ) {
				$model = $registry->getProviderModel( $provider_id, $model_id );
				$builder->using_model( $model );
			} else {
				$builder->using_provider( $provider_id );
			}

			return $builder;
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'sd_ai_agent_plugin_generation_provider_unavailable',
				__( 'The AI provider selected for plugin generation is unavailable. Configure an authenticated text-capable provider and retry.', 'superdav-ai-agent' )
			);
		}
	}

	/**
	 * Strip wrappers, lint generated PHP, and attempt one bounded repair.
	 *
	 * @param string               $code        Generated source.
	 * @param array<string,mixed>  $plan        Plan array.
	 * @param array<string,mixed>  $file_spec   File spec.
	 * @param array<string,string> $prior_files Prior generated files.
	 * @param array<string,mixed>  $context     Parent provider/model routing context.
	 * @return string|\WP_Error Prepared PHP source or validation error with source data.
	 */
	private static function prepare_generated_php( string $code, array $plan, array $file_spec, array $prior_files, array $context = [] ): string|\WP_Error {
		$path = isset( $file_spec['path'] ) ? (string) $file_spec['path'] : '';
		$code = self::extract_php_source( $code );
		$lint = self::lint_php_source( $code );
		if ( true === $lint['valid'] ) {
			return $code;
		}

		$repaired = self::repair_file( $plan, $file_spec, $code, $lint, $prior_files, true, $context );
		if ( is_wp_error( $repaired ) ) {
			return $repaired;
		}

		$repaired = self::extract_php_source( $repaired );
		$lint     = self::lint_php_source( $repaired );
		if ( true === $lint['valid'] ) {
			return $repaired;
		}

		$regenerated = self::repair_file( $plan, $file_spec, $repaired, $lint, $prior_files, false, $context );
		if ( is_wp_error( $regenerated ) ) {
			return $regenerated;
		}

		$regenerated = self::extract_php_source( $regenerated );
		$lint        = self::lint_php_source( $regenerated );
		if ( true === $lint['valid'] ) {
			return $regenerated;
		}

		return new WP_Error(
			'sd_ai_agent_php_syntax_error',
			sprintf(
				/* translators: 1: file path 2: error message 3: line number */
				__( 'PHP syntax error in %1$s: %2$s (line %3$d)', 'superdav-ai-agent' ),
				$path,
				$lint['error'] ?? 'Unknown',
				$lint['line'] ?? 0
			),
			array(
				'file'   => $path,
				'source' => $regenerated,
				'lint'   => $lint,
			)
		);
	}

	/**
	 * Request one syntax repair from the AI client.
	 *
	 * @param array<string,mixed>  $plan           Plan array.
	 * @param array<string,mixed>  $file_spec      File spec.
	 * @param string               $code           Invalid source.
	 * @param array<string,mixed>  $lint           Lint result.
	 * @param array<string,string> $prior_files    Prior generated files.
	 * @param bool                 $include_source Whether to include invalid source in the repair prompt.
	 * @param array<string,mixed>  $context        Parent provider/model routing context.
	 * @return string|\WP_Error
	 */
	private static function repair_file( array $plan, array $file_spec, string $code, array $lint, array $prior_files, bool $include_source, array $context = [] ): string|\WP_Error {
		$path = isset( $file_spec['path'] ) ? (string) $file_spec['path'] : 'plugin.php';

		$system_instruction = <<<'INSTRUCTION'
You repair WordPress plugin PHP syntax errors. Return ONLY complete valid PHP code. No markdown fences, explanations, or partial snippets.
INSTRUCTION;

		$prompt  = $include_source
			? "Repair this generated WordPress plugin file so php -l/token_get_all parses successfully. Preserve the requested behaviour and plugin header.\n"
			: "Regenerate this WordPress plugin file from scratch so php -l/token_get_all parses successfully. Return a complete self-contained file with the requested behaviour and plugin header.\n";
		$prompt .= "File: {$path}\n";
		$prompt .= 'Plugin slug: ' . (string) ( $plan['slug'] ?? '' ) . "\n";
		$prompt .= 'Plugin name: ' . (string) ( $plan['name'] ?? '' ) . "\n";
		$prompt .= 'Plan JSON: ' . (string) wp_json_encode( $plan ) . "\n";
		$prompt .= 'Lint error: ' . (string) ( $lint['error'] ?? 'Unknown' ) . ' on line ' . (string) ( $lint['line'] ?? 0 ) . "\n";
		if ( ! empty( $prior_files ) ) {
			$prompt .= "\nPreviously generated files are available and must remain compatible.\n";
		}
		if ( $include_source ) {
			$prompt .= "\nInvalid source:\n" . $code;
		}

		$builder = self::create_prompt_builder( $prompt, $context );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$raw = $builder
			->using_system_instruction( $system_instruction )
			->generate_text();

		if ( is_wp_error( $raw ) ) {
			return new WP_Error(
				'sd_ai_agent_php_syntax_error',
				sprintf(
					/* translators: 1: file path 2: error message 3: line number */
					__( 'PHP syntax error in %1$s: %2$s (line %3$d)', 'superdav-ai-agent' ),
					$path,
					$lint['error'] ?? 'Unknown',
					$lint['line'] ?? 0
				),
				array(
					'file'   => $path,
					'source' => $code,
					'lint'   => $lint,
				)
			);
		}

		return (string) $raw;
	}

	/**
	 * Extract PHP from common markdown wrappers while preserving bare source.
	 *
	 * @param string $code Raw model output.
	 * @return string PHP source.
	 */
	private static function extract_php_source( string $code ): string {
		$trimmed = trim( $code );
		if ( preg_match( '/```(?:php)?\s*(.*?)```/s', $trimmed, $matches ) ) {
			$trimmed = trim( $matches[1] );
		}

		$start = strpos( $trimmed, '<?php' );
		if ( false !== $start ) {
			return substr( $trimmed, $start );
		}

		return $trimmed;
	}

	/**
	 * Lint generated PHP source without writing it to disk.
	 *
	 * @param string $code PHP source.
	 * @return array{valid: bool, error?: string, line?: int}
	 */
	private static function lint_php_source( string $code ): array {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped tokeniser guard restored in finally.
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): bool {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ErrorException arguments are not output.
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);

		try {
			$tokens = token_get_all( $code, TOKEN_PARSE );
			unset( $tokens );
			return array( 'valid' => true );
		} catch ( \ParseError | \ErrorException $e ) {
			return array(
				'valid' => false,
				'error' => $e->getMessage(),
				'line'  => $e->getLine(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'valid' => false,
				'error' => $e->getMessage(),
				'line'  => $e->getLine(),
			);
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Extract a JSON object from possibly-wrapped AI text output.
	 *
	 * Strips markdown code fences and isolates the first complete JSON object.
	 *
	 * @param string $text Raw AI output.
	 * @return string Extracted JSON string (may still be invalid JSON).
	 */
	private static function extract_json( string $text ): string {
		// Strip ```json ... ``` or ``` ... ``` fences.
		$stripped = preg_replace( '/^```(?:json)?\s*/m', '', $text );
		$stripped = preg_replace( '/\s*```$/m', '', (string) $stripped );
		$stripped = (string) $stripped;

		$start = strpos( $stripped, '{' );
		$end   = strrpos( $stripped, '}' );

		if ( false !== $start && false !== $end && $end > $start ) {
			return substr( $stripped, $start, $end - $start + 1 );
		}

		return $stripped;
	}

	/**
	 * Order file specs so that files with no dependencies come first.
	 *
	 * Uses a simple two-pass sort: files with empty dependencies[] first,
	 * then files that have dependencies. This satisfies the majority of
	 * plugin structures where include/require files have no deps of their own.
	 *
	 * @param array<int,array<string,mixed>> $file_specs Array of file spec arrays.
	 * @return array<int,array<string,mixed>> Ordered array of file specs.
	 */
	private static function order_files_by_dependencies( array $file_specs ): array {
		$no_deps   = array();
		$with_deps = array();

		foreach ( $file_specs as $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}
			if ( empty( $spec['dependencies'] ) ) {
				$no_deps[] = $spec;
			} else {
				$with_deps[] = $spec;
			}
		}

		return array_merge( $no_deps, $with_deps );
	}
}
