<?php

declare(strict_types=1);
/**
 * Scaffold Block Theme ability — creates the on-disk skeleton for a new
 * WordPress block theme inside the active themes directory.
 *
 * Writes:
 *   wp-content/themes/{slug}/theme.json
 *   wp-content/themes/{slug}/style.css
 *   wp-content/themes/{slug}/functions.php
 *
 * Subsequent template parts and page templates (parts/header.html,
 * templates/index.html, etc.) are produced by the AI agent via the existing
 * `sd-ai-agent/file-write` ability — this scaffolder only lays down the
 * three mandatory files so the theme is detectable by WordPress.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scaffold Block Theme ability.
 *
 * @since 1.6.0
 */
class ScaffoldBlockThemeAbility extends AbstractAbility {

	/**
	 * Valid theme-slug pattern: lowercase letters, digits, and hyphens only.
	 *
	 * Mirrors PluginInstaller::SLUG_PATTERN; the theme directory becomes part
	 * of the URL space (asset paths) so the same conservative pattern applies.
	 */
	private const SLUG_PATTERN = '/^[a-z0-9-]+$/';

	/**
	 * Default minimum WordPress version for the scaffolded theme.
	 */
	private const REQUIRES_WP = '7.0';

	/**
	 * Default minimum PHP version for the scaffolded theme.
	 */
	private const REQUIRES_PHP = '8.2';

	protected function label(): string {
		return __( 'Scaffold Block Theme', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __(
			'Create the on-disk skeleton for a new WordPress block theme. Writes theme.json, style.css, and functions.php into wp-content/themes/{slug}/. Subsequent template HTML files are produced via the file-write ability.',
			'superdav-ai-agent'
		);
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug'        => [
					'type'        => 'string',
					'description' => 'Theme slug (directory name). Must match [a-z0-9-]+.',
				],
				'name'        => [
					'type'        => 'string',
					'description' => 'Human-readable theme name shown in Appearance > Themes.',
				],
				'description' => [
					'type'        => 'string',
					'description' => 'Short description of the theme (stored in style.css Description header).',
				],
				'author'      => [
					'type'        => 'string',
					'description' => 'Theme author name (defaults to site name).',
				],
				'theme_json'  => [
					'type'        => 'object',
					'description' => 'Optional theme.json document. When omitted a minimal default is written.',
				],
				'overwrite'   => [
					'type'        => 'boolean',
					'description' => 'Overwrite an existing theme directory with the same slug. Defaults to false.',
				],
			],
			'required'   => [ 'slug', 'name' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug'            => [ 'type' => 'string' ],
				'theme_dir'       => [ 'type' => 'string' ],
				'stylesheet'      => [ 'type' => 'string' ],
				'files'           => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'overwritten'     => [ 'type' => 'boolean' ],
				'version_coerced' => [
					'type'        => 'boolean',
					'description' => 'True when the supplied theme_json used a schema version older than 3 and was silently upgraded to version 3.',
				],
				'cta_warning'     => [
					'type'        => 'boolean',
					'description' => 'Always true: the scaffolded templates/front-page.html contains a placeholder CTA (href="#"). You MUST replace it with a real published page URL via the file-write ability before activating the theme.',
				],
			],
		];
	}

	protected function execute_callback( $input ): array|WP_Error {
		$slug_input = isset( $input['slug'] ) ? (string) $input['slug'] : '';
		$slug       = sanitize_title( $slug_input );

		if ( '' === $slug || ! preg_match( self::SLUG_PATTERN, $slug ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_slug',
				__( 'Theme slug must contain only lowercase letters, digits, and hyphens.', 'superdav-ai-agent' )
			);
		}

		$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error(
				'sd_ai_agent_empty_theme_name',
				__( 'Theme name must not be empty.', 'superdav-ai-agent' )
			);
		}

		$description = isset( $input['description'] ) ? (string) $input['description'] : '';
		$author      = isset( $input['author'] ) ? trim( (string) $input['author'] ) : '';
		if ( '' === $author ) {
			$author = (string) get_bloginfo( 'name' );
		}
		$overwrite = ! empty( $input['overwrite'] );

		$theme_root = self::theme_root();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Pre-flight check before WP_Filesystem is initialised; mirrors PluginInstaller pattern.
		if ( ! is_dir( $theme_root ) || ! is_writable( $theme_root ) ) {
			return new WP_Error(
				'sd_ai_agent_themes_dir_unwritable',
				/* translators: %s: themes directory path */
				sprintf( __( 'Themes directory is not writable: %s', 'superdav-ai-agent' ), $theme_root )
			);
		}

		// Resolve any `..` segments from WP_CONTENT_DIR before passing the
		// path to wp_mkdir_p(). WordPress core's wp_mkdir_p() refuses to
		// create directories whose path contains unresolved parent-dir
		// references even when the parent itself is writable — a real
		// failure mode on dev installs and some shared-host configs where
		// WP_CONTENT_DIR is defined with a relative ".." segment.
		$resolved_root = realpath( $theme_root );
		if ( false !== $resolved_root ) {
			$theme_root = $resolved_root;
		}

		$theme_dir   = trailingslashit( $theme_root ) . $slug;
		$existed     = is_dir( $theme_dir );
		$overwritten = false;

		if ( $existed && ! $overwrite ) {
			return new WP_Error(
				'sd_ai_agent_theme_exists',
				/* translators: %s: theme slug */
				sprintf( __( 'A theme with slug "%s" already exists. Pass overwrite=true to replace it.', 'superdav-ai-agent' ), $slug )
			);
		}

		if ( ! wp_mkdir_p( $theme_dir ) ) {
			return new WP_Error(
				'sd_ai_agent_mkdir_failed',
				/* translators: %s: directory path */
				sprintf( __( 'Could not create theme directory: %s', 'superdav-ai-agent' ), $theme_dir )
			);
		}

		// theme.json — minimal default unless the caller supplied one.
		$theme_json = isset( $input['theme_json'] ) && is_array( $input['theme_json'] )
			? $input['theme_json']
			: self::default_theme_json();

		// Server-side guardrail: coerce stale schema versions to v3.
		// The plugin requires WordPress 7.0+ where theme.json version 3 is
		// the standard format; normalise silently and log for transparency.
		$version_coerced = false;
		if ( isset( $theme_json['version'] ) && is_int( $theme_json['version'] ) && $theme_json['version'] < 3 ) {
			$old_version           = $theme_json['version'];
			$theme_json['version'] = 3;
			$version_coerced       = true;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic log for schema-version coercion transparency (GH#1511).
			error_log(
				sprintf(
					'ScaffoldBlockThemeAbility: theme.json version coerced from %d to 3 for theme "%s" — WordPress 7.0+ requires version 3.',
					$old_version,
					$slug
				)
			);
		}

		$encoded_theme_json = wp_json_encode( $theme_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded_theme_json ) {
			return new WP_Error(
				'sd_ai_agent_theme_json_encode_failed',
				__( 'Could not encode theme.json document.', 'superdav-ai-agent' )
			);
		}

		$files = [
			'theme.json'    => $encoded_theme_json,
			'style.css'     => self::build_style_css( $slug, $name, $description, $author ),
			'functions.php' => self::build_functions_php( $slug ),
		];

		// Lay down minimum template files so the theme is valid on activation:
		// a block theme requires at least templates/index.html. We provide a
		// trivial placeholder; the agent is expected to overwrite it via the
		// existing file-write ability with richer markup during Phase 4.
		$files['templates/index.html'] = self::default_index_template();

		// Front-page template with a hero section and a CTA button slot.
		// The button link uses `href="#"` as a placeholder — the agent MUST
		// replace it with a real published page URL before activating the theme
		// (see the `cta_warning` output flag and the CTA rules in the agent
		// system prompt).
		$files['templates/front-page.html'] = self::default_front_page_template();

		$written = [];
		foreach ( $files as $relative => $contents ) {
			$relative = ltrim( $relative, '/\\' );
			$abs      = trailingslashit( $theme_dir ) . $relative;
			$dir      = dirname( $abs );

			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error(
					'sd_ai_agent_mkdir_failed',
					/* translators: %s: directory path */
					sprintf( __( 'Could not create directory: %s', 'superdav-ai-agent' ), $dir )
				);
			}

			$result = self::write_file( $abs, $contents );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$written[] = $relative;
		}

		if ( $existed && $overwrite ) {
			$overwritten = true;
		}

		return [
			'slug'            => $slug,
			'theme_dir'       => $theme_dir,
			'stylesheet'      => $slug,
			'files'           => $written,
			'overwritten'     => $overwritten,
			'version_coerced' => $version_coerced,
			// Always true: the scaffold writes front-page.html with href="#"
			// as a placeholder. The agent must replace it with a real URL.
			'cta_warning'     => true,
		];
	}

	protected function permission_callback( $input ): bool {
		// Tool-level cap (delegatable via roles) AND the WordPress core
		// theme-install capability. Both must hold so an administrator who
		// has explicitly delegated the tool cap to a less-privileged role
		// still cannot bypass core's install_themes check.
		return ToolCapabilities::current_user_can( $this->name )
			&& current_user_can( 'install_themes' );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			],
			'show_in_rest' => true,
		];
	}

	/**
	 * Resolve the absolute themes-root directory.
	 *
	 * Prefers get_theme_root() (which honours multisite + custom theme roots)
	 * and falls back to WP_CONTENT_DIR/themes when the function is not yet
	 * loaded (e.g. during boot-time ability registration in tests).
	 *
	 * @return string Absolute themes-root path (no trailing slash).
	 */
	private static function theme_root(): string {
		if ( function_exists( 'get_theme_root' ) ) {
			$root = get_theme_root();
			if ( is_string( $root ) && '' !== $root ) {
				return untrailingslashit( $root );
			}
		}

		return untrailingslashit( WP_CONTENT_DIR . '/themes' );
	}

	/**
	 * Build the style.css header block.
	 *
	 * @param string $slug        Theme slug.
	 * @param string $name        Theme display name.
	 * @param string $description Theme description.
	 * @param string $author      Theme author.
	 * @return string style.css contents.
	 */
	private static function build_style_css(
		string $slug,
		string $name,
		string $description,
		string $author
	): string {
		// Escape the WordPress style.css header values: collapse newlines
		// (which would break the header parser) and trim. The values are
		// sanitized for use inside a CSS comment, not HTML output.
		$normalise = static function ( string $value ): string {
			$value = preg_replace( '/\r\n|\r|\n/', ' ', $value ) ?? '';
			return trim( $value );
		};

		$name_safe        = $normalise( $name );
		$description_safe = $normalise( $description );
		$author_safe      = $normalise( $author );

		// Theme URI / Author URI are left empty so WordPress doesn't render
		// stale links; admins can hand-edit after generation.
		return "/*\n" .
			'Theme Name: ' . $name_safe . "\n" .
			'Theme URI: ' . "\n" .
			'Author: ' . $author_safe . "\n" .
			'Author URI: ' . "\n" .
			'Description: ' . $description_safe . "\n" .
			'Version: 1.0.0' . "\n" .
			'Requires at least: ' . self::REQUIRES_WP . "\n" .
			'Tested up to: ' . self::REQUIRES_WP . "\n" .
			'Requires PHP: ' . self::REQUIRES_PHP . "\n" .
			'License: GPL-2.0-or-later' . "\n" .
			'License URI: https://www.gnu.org/licenses/gpl-2.0.html' . "\n" .
			'Text Domain: ' . $slug . "\n" .
			'Tags: block-theme, full-site-editing' . "\n" .
			"*/\n";
	}

	/**
	 * Build the functions.php scaffold.
	 *
	 * Adds a single after_setup_theme hook that enables auto-feed-link, title
	 * tag, post-thumbnails, and HTML5 support — the minimum a modern block
	 * theme is expected to opt into. Uses a slug-prefixed function so the
	 * file passes `wp plugin-check`-style namespace checks even when the
	 * agent later concatenates additional theme code.
	 *
	 * @param string $slug Theme slug used for the prefixed function name.
	 * @return string functions.php contents.
	 */
	private static function build_functions_php( string $slug ): string {
		// Function names must be valid PHP identifiers: hyphens → underscores.
		$prefix = str_replace( '-', '_', $slug );

		return "<?php\n" .
			"/**\n" .
			" * Theme setup for {$slug}.\n" .
			" *\n" .
			" * Scaffolded by Superdav AI Agent.\n" .
			" *\n" .
			" * @package {$slug}\n" .
			" */\n\n" .
			"if ( ! defined( 'ABSPATH' ) ) {\n" .
			"\texit;\n" .
			"}\n\n" .
			"if ( ! function_exists( '{$prefix}_setup' ) ) {\n" .
			"\t/**\n" .
			"\t * Register theme support flags.\n" .
			"\t */\n" .
			"\tfunction {$prefix}_setup() {\n" .
			"\t\tadd_theme_support( 'automatic-feed-links' );\n" .
			"\t\tadd_theme_support( 'title-tag' );\n" .
			"\t\tadd_theme_support( 'post-thumbnails' );\n" .
			"\t\tadd_theme_support( 'responsive-embeds' );\n" .
			"\t\tadd_theme_support( 'editor-styles' );\n" .
			"\t\tadd_theme_support( 'wp-block-styles' );\n" .
			"\t\tadd_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );\n" .
			"\t}\n" .
			"}\n" .
			"add_action( 'after_setup_theme', '{$prefix}_setup' );\n";
	}

	/**
	 * Build the default minimal templates/index.html so the theme is
	 * recognised as a valid block theme by WordPress on activation.
	 *
	 * @return string index.html contents.
	 */
	private static function default_index_template(): string {
		return "<!-- wp:template-part {\"slug\":\"header\",\"tagName\":\"header\"} /-->\n\n" .
			"<!-- wp:group {\"tagName\":\"main\",\"layout\":{\"type\":\"constrained\"}} -->\n" .
			"<main class=\"wp-block-group\">\n" .
			"\t<!-- wp:post-title {\"level\":1} /-->\n" .
			"\t<!-- wp:post-content /-->\n" .
			"</main>\n" .
			"<!-- /wp:group -->\n\n" .
			"<!-- wp:template-part {\"slug\":\"footer\",\"tagName\":\"footer\"} /-->\n";
	}

	/**
	 * Build the default front-page.html template.
	 *
	 * Produces a hero section with a cover block, heading, tagline paragraph,
	 * and a primary CTA button. The button `href` is intentionally set to `#`
	 * as a placeholder — the agent MUST replace it with the URL of a real
	 * published page before activating the theme (signalled by `cta_warning`
	 * in the execute_callback return value).
	 *
	 * @return string templates/front-page.html contents.
	 */
	private static function default_front_page_template(): string {
		return "<!-- wp:template-part {\"slug\":\"header\",\"tagName\":\"header\"} /-->\n\n" .
			"<!-- wp:cover {\"dimRatio\":50,\"isDark\":false,\"style\":{\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|80\",\"bottom\":\"var:preset|spacing|80\"}}}} -->\n" .
			'<div class="wp-block-cover is-light" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)">' .
			'<span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>' .
			"<div class=\"wp-block-cover__inner-container\">\n" .
			"\t<!-- wp:heading {\"level\":1,\"textAlign\":\"center\"} -->\n" .
			"\t<h1 class=\"wp-block-heading has-text-align-center\">Welcome</h1>\n" .
			"\t<!-- /wp:heading -->\n\n" .
			"\t<!-- wp:paragraph {\"align\":\"center\"} -->\n" .
			"\t<p class=\"has-text-align-center\">Tell visitors what makes you special.</p>\n" .
			"\t<!-- /wp:paragraph -->\n\n" .
			"\t<!-- wp:buttons {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"center\"},\"style\":{\"spacing\":{\"margin\":{\"top\":\"var:preset|spacing|50\"}}}} -->\n" .
			"\t<div class=\"wp-block-buttons\" style=\"margin-top:var(--wp--preset--spacing--50)\"><!-- wp:button -->\n" .
			"\t<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"#\">Call to action</a></div>\n" .
			"\t<!-- /wp:button --></div>\n" .
			"\t<!-- /wp:buttons -->\n" .
			"</div></div>\n" .
			"<!-- /wp:cover -->\n\n" .
			"<!-- wp:template-part {\"slug\":\"footer\",\"tagName\":\"footer\"} /-->\n";
	}

	/**
	 * Build the minimal default theme.json document.
	 *
	 * @return array<string,mixed> theme.json structure.
	 */
	private static function default_theme_json(): array {
		return [
			'$schema'       => 'https://schemas.wp.org/trunk/theme.json',
			'version'       => 3,
			'settings'      => [
				'appearanceTools' => true,
				'layout'          => [
					'contentSize' => '720px',
					'wideSize'    => '1200px',
				],
				'color'           => [
					'palette' => [
						[
							'slug'  => 'foreground',
							'name'  => 'Foreground',
							'color' => '#1a1a1a',
						],
						[
							'slug'  => 'background',
							'name'  => 'Background',
							'color' => '#ffffff',
						],
						[
							'slug'  => 'accent',
							'name'  => 'Accent',
							'color' => '#3858e9',
						],
					],
				],
				'typography'      => [
					'fluid' => true,
				],
			],
			'styles'        => [
				'color' => [
					'background' => 'var(--wp--preset--color--background)',
					'text'       => 'var(--wp--preset--color--foreground)',
				],
			],
			'templateParts' => [
				[
					'name'  => 'header',
					'title' => 'Header',
					'area'  => 'header',
				],
				[
					'name'  => 'footer',
					'title' => 'Footer',
					'area'  => 'footer',
				],
			],
		];
	}

	/**
	 * Check whether a rendered front-page HTML contains a real CTA button.
	 *
	 * A CTA is considered "real" when there is at least one element with the
	 * `wp-block-button__link` class whose `href` attribute is present and is
	 * neither empty nor the bare placeholder `#`.
	 *
	 * Mirrors the acceptance-criteria CSS selector:
	 * `.wp-block-button__link[href]:not([href="#"])`
	 *
	 * @param string $html HTML to inspect (rendered front-page or template source).
	 * @return bool True when a real CTA link is found; false when missing or placeholder.
	 */
	public static function validate_front_page_has_cta( string $html ): bool {
		// Find every anchor tag that carries the wp-block-button__link class.
		if ( ! preg_match_all(
			'/<a\b[^>]*\bwp-block-button__link\b[^>]*>/i',
			$html,
			$matches
		) ) {
			return false;
		}

		foreach ( $matches[0] as $tag ) {
			if ( preg_match( '/\bhref="([^"]*)"/', $tag, $href ) ) {
				$url = trim( $href[1] );
				if ( '' !== $url && '#' !== $url ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Write a file using WP_Filesystem when available, falling back to
	 * file_put_contents so the ability remains usable in environments where
	 * the filesystem credentials prompt would otherwise block writes.
	 *
	 * @param string $abs_path Absolute file path.
	 * @param string $contents File contents.
	 * @return true|WP_Error
	 */
	private static function write_file( string $abs_path, string $contents ): bool|WP_Error {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) && function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! empty( $wp_filesystem ) ) {
			$ok = $wp_filesystem->put_contents( $abs_path, $contents, FS_CHMOD_FILE );
			if ( true === $ok ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Theme scaffold writes local files; WP_Filesystem may be unavailable during boot or in tests.
		$bytes = file_put_contents( $abs_path, $contents );
		if ( false === $bytes ) {
			return new WP_Error(
				'sd_ai_agent_write_failed',
				/* translators: %s: file path */
				sprintf( __( 'Could not write file: %s', 'superdav-ai-agent' ), $abs_path )
			);
		}

		return true;
	}
}
