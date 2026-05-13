<?php

declare(strict_types=1);
/**
 * Skill model — on-demand instruction guides for the AI agent.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models;

use SdAiAgent\Core\Settings;
use SdAiAgent\Models\DTO\SkillRow;

class Skill {

	/**
	 * Get the skills table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_skills';
	}

	/**
	 * Get all skills, optionally filtered by enabled status.
	 *
	 * @param bool|null $enabled Filter by enabled status (null = all).
	 * @return list<SkillRow>|null
	 */
	public static function get_all( ?bool $enabled = null ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();

		if ( null !== $enabled ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE enabled = %d ORDER BY name ASC',
					$table,
					$enabled ? 1 : 0
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY name ASC',
					$table
				)
			);
		}

		if ( null === $rows ) {
			return null;
		}

		return array_map( [ SkillRow::class, 'from_row' ], $rows );
	}

	/**
	 * Get a single skill by ID.
	 *
	 * @param int $id Skill ID.
	 * @return SkillRow|null
	 */
	public static function get( int $id ): ?SkillRow {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				self::table_name(),
				$id
			)
		);

		return $row instanceof \stdClass ? SkillRow::from_row( $row ) : null;
	}

	/**
	 * Get a single skill by slug.
	 *
	 * @param string $slug Skill slug.
	 * @return SkillRow|null
	 */
	public static function get_by_slug( string $slug ): ?SkillRow {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE slug = %s',
				self::table_name(),
				$slug
			)
		);

		return $row instanceof \stdClass ? SkillRow::from_row( $row ) : null;
	}

	/**
	 * Skills whose parent plugin must be active for auto-injection.
	 *
	 * When a skill slug appears here and the corresponding plugin is active,
	 * the skill is treated as enabled for auto-injection regardless of the
	 * DB `enabled` flag. This means admins don't have to manually enable
	 * the WooCommerce skill after installing WooCommerce — it just works.
	 *
	 * @var array<string, string> slug => plugin file (relative to plugins dir).
	 */
	private const PLUGIN_SKILL_MAP = [
		'woocommerce'          => 'woocommerce/woocommerce.php',
		'kadence-blocks'       => 'kadence-blocks/kadence-blocks.php',
		'multisite-management' => '', // No plugin dependency — enabled via is_multisite().
	];

	/**
	 * Skills auto-enabled based on the active WordPress theme.
	 *
	 * Each entry maps a skill slug to a predicate that inspects the active
	 * theme. The predicate receives the active theme's template (parent slug)
	 * and stylesheet (child slug); it returns true when the skill should be
	 * auto-enabled.
	 *
	 * Predicates only run on the WP runtime where wp_get_theme() and
	 * wp_is_block_theme() are available. The detection helper {@see
	 * is_skill_auto_enabled()} short-circuits when those functions are
	 * missing (e.g. in unit tests bootstrapping outside WP).
	 *
	 * @return array<string, \Closure(string, string): bool>
	 */
	private static function theme_skill_map(): array {
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Predicate signatures are uniform; some implementations don't need the theme slugs.
		return [
			'block-themes'   => static fn( string $template, string $stylesheet ): bool => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'classic-themes' => static fn( string $template, string $stylesheet ): bool => function_exists( 'wp_is_block_theme' ) && ! wp_is_block_theme(),
			'kadence-theme'  => static fn( string $template, string $stylesheet ): bool => 'kadence' === $template || 'kadence' === $stylesheet,
		];
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
	}

	/**
	 * Get skill content by slug (convenience method for auto-injection).
	 *
	 * Returns the content of an enabled skill, or null if the skill
	 * doesn't exist or is disabled. Skills with a known plugin dependency
	 * are auto-enabled when the plugin is active.
	 *
	 * @param string $slug Skill slug.
	 * @return string|null Skill content or null.
	 */
	public static function get_content_by_slug( string $slug ): ?string {
		$skill = self::get_by_slug( $slug );

		if ( ! $skill ) {
			return null;
		}

		// Check explicit enabled flag first.
		if ( (int) $skill->enabled ) {
			return $skill->content;
		}

		// Auto-enable skills whose parent plugin is active.
		if ( self::is_skill_auto_enabled( $slug ) ) {
			return $skill->content;
		}

		return null;
	}

	/**
	 * Check if a disabled skill should be auto-enabled based on environment.
	 *
	 * Resolution order:
	 *   1. Plugin-based map (PLUGIN_SKILL_MAP) — woocommerce, kadence-blocks, etc.
	 *   2. Multisite (built into PLUGIN_SKILL_MAP via empty plugin file).
	 *   3. Theme-based map (theme_skill_map()) — block-themes, classic-themes, kadence-theme.
	 *
	 * @param string $slug Skill slug.
	 * @return bool True if the skill should be treated as enabled.
	 */
	private static function is_skill_auto_enabled( string $slug ): bool {
		if ( isset( self::PLUGIN_SKILL_MAP[ $slug ] ) ) {
			$plugin_file = self::PLUGIN_SKILL_MAP[ $slug ];

			// Multisite skill: auto-enable on multisite installs.
			if ( '' === $plugin_file ) {
				return 'multisite-management' === $slug && is_multisite();
			}

			// Plugin-dependent skill: check if plugin is active.
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			return is_plugin_active( $plugin_file );
		}

		$theme_map = self::theme_skill_map();
		if ( isset( $theme_map[ $slug ] ) ) {
			if ( ! function_exists( 'wp_get_theme' ) ) {
				return false;
			}

			$theme      = wp_get_theme();
			$template   = (string) $theme->get_template();
			$stylesheet = (string) $theme->get_stylesheet();
			$predicate  = $theme_map[ $slug ];

			return (bool) $predicate( $template, $stylesheet );
		}

		return false;
	}

	/**
	 * Create a new skill.
	 *
	 * @param array<string, mixed> $data Skill data: slug, name, description, content, is_builtin, enabled, version, content_hash, source_url.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function create( array $data ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// @phpstan-ignore-next-line
		$content = (string) ( $data['content'] ?? '' );
		$now     = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert(
			self::table_name(),
			[
				// @phpstan-ignore-next-line
				'slug'          => sanitize_title( $data['slug'] ?? '' ),
				// @phpstan-ignore-next-line
				'name'          => sanitize_text_field( $data['name'] ?? '' ),
				// @phpstan-ignore-next-line
				'description'   => sanitize_textarea_field( $data['description'] ?? '' ),
				// Skill content is markdown for AI consumption, not HTML for browser rendering.
				// It is stored verbatim; SQL injection is prevented by $wpdb->insert() parameterisation.
				'content'       => $content,
				'is_builtin'    => ! empty( $data['is_builtin'] ) ? 1 : 0,
				'enabled'       => isset( $data['enabled'] ) ? ( $data['enabled'] ? 1 : 0 ) : 1,
				// @phpstan-ignore-next-line
				'version'       => (string) ( $data['version'] ?? '' ),
				// @phpstan-ignore-next-line
				'content_hash'  => '' !== $content ? hash( 'sha256', $content ) : (string) ( $data['content_hash'] ?? '' ),
				// @phpstan-ignore-next-line
				'source_url'    => esc_url_raw( (string) ( $data['source_url'] ?? '' ) ),
				'user_modified' => 0,
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an existing skill.
	 *
	 * When a built-in skill's content is changed by an admin, `user_modified` is
	 * automatically set to `1` to protect the customisation from auto-updates.
	 * Pass `'user_modified' => false` explicitly to bypass this (e.g. when
	 * {@see apply_update()} applies a remote update to an unmodified skill).
	 *
	 * @param int                  $id   Skill ID.
	 * @param array<string, mixed> $data Fields to update (name, description, content, enabled, version, source_url, user_modified).
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$allowed = [ 'name', 'description', 'content', 'enabled', 'version', 'source_url', 'user_modified' ];
		$data    = array_intersect_key( $data, array_flip( $allowed ) );

		if ( isset( $data['name'] ) ) {
			// @phpstan-ignore-next-line
			$data['name'] = sanitize_text_field( $data['name'] );
		}
		if ( isset( $data['description'] ) ) {
			// @phpstan-ignore-next-line
			$data['description'] = sanitize_textarea_field( $data['description'] );
		}
		if ( isset( $data['source_url'] ) ) {
			// @phpstan-ignore-next-line
			$data['source_url'] = esc_url_raw( (string) $data['source_url'] );
		}
		// Skill content is markdown for AI consumption, not HTML for browser rendering.
		// Stored verbatim; SQL injection is prevented by $wpdb->update() parameterisation.
		// No sanitization needed: content only flows through REST/admin, never rendered as raw HTML.

		if ( isset( $data['enabled'] ) ) {
			$data['enabled'] = $data['enabled'] ? 1 : 0;
		}

		// When an admin modifies a built-in skill's content, mark it as user-modified
		// so that automatic remote updates do not overwrite their customisations.
		// The caller may pass user_modified => false explicitly to suppress this
		// (used by apply_update() when applying a remote update to an unmodified skill).
		if ( isset( $data['content'] ) && ! array_key_exists( 'user_modified', $data ) ) {
			$skill = self::get( $id );
			if ( $skill && $skill->is_builtin ) {
				$data['user_modified'] = 1;
			}
		}

		if ( isset( $data['user_modified'] ) ) {
			$data['user_modified'] = $data['user_modified'] ? 1 : 0;
		}

		// Update content_hash whenever content changes.
		if ( isset( $data['content'] ) ) {
			$data['content_hash'] = hash( 'sha256', (string) $data['content'] );
		}

		$data['updated_at'] = current_time( 'mysql', true );

		$formats = [];
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, [ 'enabled', 'user_modified' ], true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->update(
			self::table_name(),
			$data,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Delete a skill by ID (refuses built-in skills).
	 *
	 * @param int $id Skill ID.
	 * @return bool|string True on success, error message string if built-in.
	 */
	public static function delete( int $id ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$skill = self::get( $id );

		if ( ! $skill ) {
			return false;
		}

		if ( $skill->is_builtin ) {
			return 'builtin';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->delete(
			self::table_name(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Reset a built-in skill to its original content.
	 *
	 * Tries to pull the canonical content from the remote manifest URL first
	 * (when `skill_manifest_url` is configured), so that the "reset" action
	 * restores the latest upstream version rather than the locally-bundled one.
	 * Falls back to the bundled .md file when the remote fetch fails or is not
	 * configured.
	 *
	 * Clears `user_modified` so that subsequent remote auto-updates will apply.
	 *
	 * @param int $id Skill ID.
	 * @return bool
	 */
	public static function reset_builtin( int $id ): bool {
		$skill = self::get( $id );

		if ( ! $skill || ! $skill->is_builtin ) {
			return false;
		}

		// Prefer the latest upstream content from the remote manifest.
		$remote_entry = self::fetch_manifest_entry( $skill->slug );
		if ( null !== $remote_entry ) {
			return self::apply_update( $id, $remote_entry );
		}

		// Fall back to the locally-bundled .md file.
		$builtins = self::get_builtin_definitions();

		if ( ! isset( $builtins[ $skill->slug ] ) ) {
			return false;
		}

		$definition = $builtins[ $skill->slug ];

		return self::update(
			$id,
			[
				// @phpstan-ignore-next-line
				'name'          => $definition['name'],
				// @phpstan-ignore-next-line
				'description'   => $definition['description'],
				// @phpstan-ignore-next-line
				'content'       => $definition['content'],
				// Explicitly clear user_modified so future remote updates can apply.
				'user_modified' => false,
			]
		);
	}

	/**
	 * Fetch a single skill entry from the remote manifest URL.
	 *
	 * Returns null when skill_manifest_url is not configured, the remote
	 * request fails, the response is not valid JSON, or the slug is absent
	 * from the manifest.
	 *
	 * @param string $slug Skill slug to look up in the manifest.
	 * @return array<array-key, mixed>|null Manifest entry for the slug, or null.
	 */
	private static function fetch_manifest_entry( string $slug ): ?array {
		$settings     = Settings::instance()->get();
		$manifest_url = (string) ( $settings['skill_manifest_url'] ?? '' );

		if ( '' === $manifest_url ) {
			return null;
		}

		$response = wp_remote_get(
			$manifest_url,
			[
				'timeout'     => 15,
				'redirection' => 3,
				'headers'     => [ 'Accept' => 'application/json' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body     = wp_remote_retrieve_body( $response );
		$manifest = json_decode( $body, true );

		if ( ! is_array( $manifest ) ) {
			return null;
		}

		return isset( $manifest[ $slug ] ) && is_array( $manifest[ $slug ] )
			? $manifest[ $slug ]
			: null;
	}

	/**
	 * Check whether a remote update is available for a skill.
	 *
	 * Compares the skill's stored `content_hash` against a hash of the remote
	 * manifest entry. Returns the remote skill data when the hashes differ, or
	 * null when the skill is up to date or the manifest entry is absent.
	 *
	 * This method only detects differences; it does not apply any changes.
	 * Use {@see apply_update()} to apply a detected update.
	 *
	 * @param SkillRow             $skill        The skill to check.
	 * @param array<string, mixed> $manifest_entry Remote manifest entry for this slug
	 *                                             (keys: version, content, source_url).
	 * @return array<string, mixed>|null Remote entry when an update is available, null otherwise.
	 */
	public static function check_for_updates( SkillRow $skill, array $manifest_entry ): ?array {
		// @phpstan-ignore-next-line
		$remote_content = (string) ( $manifest_entry['content'] ?? '' );

		if ( '' === $remote_content ) {
			return null;
		}

		$remote_hash = hash( 'sha256', $remote_content );

		// No update needed if content is identical.
		if ( $remote_hash === $skill->content_hash ) {
			return null;
		}

		return $manifest_entry;
	}

	/**
	 * Apply a remote manifest update to a built-in skill.
	 *
	 * Skips silently if the skill has been locally modified by an admin
	 * (`user_modified = 1`), to protect customisations.
	 *
	 * Passes `'user_modified' => false` to {@see update()} so the built-in
	 * skill's modification flag is not re-set during the remote sync.
	 *
	 * @param int                  $id            Skill ID.
	 * @param array<string, mixed> $manifest_entry Remote manifest entry (keys: version, content, source_url).
	 * @return bool True when the update was applied, false when skipped or failed.
	 */
	public static function apply_update( int $id, array $manifest_entry ): bool {
		$skill = self::get( $id );

		if ( ! $skill || ! $skill->is_builtin ) {
			return false;
		}

		// Never overwrite admin customisations.
		if ( $skill->user_modified ) {
			return false;
		}

		// @phpstan-ignore-next-line
		$remote_content = (string) ( $manifest_entry['content'] ?? '' );

		if ( '' === $remote_content ) {
			return false;
		}

		return self::update(
			$id,
			[
				// @phpstan-ignore-next-line
				'content'       => $remote_content,
				// @phpstan-ignore-next-line
				'version'       => (string) ( $manifest_entry['version'] ?? '' ),
				// @phpstan-ignore-next-line
				'source_url'    => (string) ( $manifest_entry['source_url'] ?? '' ),
				// Explicitly keep user_modified=0; this is a remote sync, not an admin edit.
				'user_modified' => false,
			]
		);
	}

	/**
	 * Get a compact skill index for the system prompt (enabled skills only).
	 *
	 * @return string Formatted index or empty string if no skills enabled.
	 */
	public static function get_index_for_prompt(): string {
		$skills = self::get_all( true );

		if ( empty( $skills ) ) {
			return '';
		}

		$lines = [];
		foreach ( $skills as $skill ) {
			$lines[] = "- {$skill->slug}: {$skill->description}";
		}

		return "## Available Skills\n"
			. "You have access to specialized skill guides. When a user's request matches a skill topic,\n"
			. "use the sd-ai-agent/skill-load tool to load the full instructions before proceeding.\n\n"
			. "Available skills:\n"
			. implode( "\n", $lines );
	}

	/**
	 * Idempotent seeding of built-in skills (skips if slug exists).
	 *
	 * Also performs one-time slug migrations for renamed built-ins (e.g. the
	 * `full-site-editing` skill was renamed to `block-themes`). The legacy
	 * slug is migrated in-place when the new slug does not yet exist; the
	 * caller's customisations are preserved.
	 */
	public static function seed_builtins(): void {
		self::migrate_renamed_builtins();

		foreach ( self::get_builtin_definitions() as $slug => $definition ) {
			$existing = self::get_by_slug( $slug );

			if ( $existing ) {
				continue;
			}

			self::create(
				[
					'slug'        => $slug,
					// @phpstan-ignore-next-line
					'name'        => $definition['name'],
					// @phpstan-ignore-next-line
					'description' => $definition['description'],
					// @phpstan-ignore-next-line
					'content'     => $definition['content'],
					'is_builtin'  => true,
					// @phpstan-ignore-next-line
					'enabled'     => $definition['enabled'],
				]
			);
		}
	}

	/**
	 * One-time migrations of legacy built-in skill slugs to their current names.
	 *
	 * Renames the row in-place so admin customisations (`user_modified=1`) and
	 * usage telemetry (which references skill_id, not slug) survive the move.
	 * No-op when the new slug already exists or the legacy slug is absent.
	 */
	private static function migrate_renamed_builtins(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$renames = [
			// Renamed in t-XXXX (block-themes scope expansion).
			'full-site-editing' => 'block-themes',
		];

		foreach ( $renames as $old_slug => $new_slug ) {
			$old = self::get_by_slug( $old_slug );
			$new = self::get_by_slug( $new_slug );

			if ( ! $old || $new ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; one-time migration.
			$wpdb->update(
				self::table_name(),
				[ 'slug' => $new_slug ],
				[ 'id' => $old->id ],
				[ '%s' ],
				[ '%d' ]
			);
		}
	}

	/**
	 * Directory containing built-in skill markdown files.
	 */
	const SKILLS_DIR = __DIR__ . '/skills';

	/**
	 * Built-in skill metadata: slug => [name, description, enabled].
	 *
	 * Content is loaded from markdown files in the skills/ directory.
	 * Each file is named {slug}.md and contains the full skill instructions.
	 *
	 * @var array<string, array{name: string, description: string, enabled: bool}>
	 */
	private const BUILTIN_META = [
		'wordpress-admin'      => [
			'name'        => 'WordPress Administration',
			'description' => 'General WordPress administration (settings, updates, users, options)',
			'enabled'     => true,
		],
		'content-management'   => [
			'name'        => 'Content Management',
			'description' => 'Managing posts, pages, media, taxonomies',
			'enabled'     => true,
		],
		'woocommerce'          => [
			'name'        => 'WooCommerce Store Management',
			'description' => 'WooCommerce store management (products, orders, coupons)',
			'enabled'     => false,
		],
		'site-troubleshooting' => [
			'name'        => 'Site Troubleshooting',
			'description' => 'Debugging errors, site health, performance diagnosis',
			'enabled'     => true,
		],
		'multisite-management' => [
			'name'        => 'Multisite Network Management',
			'description' => 'WordPress Multisite network administration',
			'enabled'     => false,
		],
		'seo-optimization'     => [
			'name'        => 'SEO Optimization',
			'description' => 'SEO auditing, on-page optimization, meta tags, technical SEO checks',
			'enabled'     => true,
		],
		'site-specification'   => [
			'name'        => 'Site Specification',
			'description' => 'Extract a structured site spec (name, type, audience, tone, sections, typography) from a user description. Foundation for theme generation and content workflows.',
			'enabled'     => true,
		],
		'content-marketing'    => [
			'name'        => 'Content Marketing',
			'description' => 'Content strategy, editorial workflows, content audits, publishing analysis',
			'enabled'     => true,
		],
		'competitive-analysis' => [
			'name'        => 'Competitive Analysis',
			'description' => 'Analyzing competitor sites, tech stack discovery, content gap analysis',
			'enabled'     => false,
		],
		'analytics-reporting'  => [
			'name'        => 'Analytics & Reporting',
			'description' => 'Content performance reports, site growth metrics, publishing analytics',
			'enabled'     => true,
		],
		'gutenberg-blocks'     => [
			'name'        => 'Gutenberg Blocks',
			'description' => 'Creating content with Gutenberg blocks, converting markdown, building layouts',
			'enabled'     => true,
		],
		'block-themes'         => [
			'name'        => 'Block Themes (FSE)',
			'description' => 'Block theme templates, template parts, theme.json, Site Editor and site-wide layout',
			// Auto-enabled when the active theme is a block theme (see theme_skill_map()).
			'enabled'     => false,
		],
		'classic-themes'       => [
			'name'        => 'Classic Themes',
			'description' => 'Classic theme work — PHP templates, Customizer, widgets, child themes',
			// Auto-enabled when the active theme is a classic theme.
			'enabled'     => false,
		],
		'kadence-blocks'       => [
			'name'        => 'Kadence Blocks',
			'description' => 'Kadence Blocks markup — kadence/rowlayout, kadence/column, kadence/advancedheading, kadence/advancedbtn validation rules',
			// Auto-enabled when the Kadence Blocks plugin is active.
			'enabled'     => false,
		],
		'kadence-theme'        => [
			'name'        => 'Kadence Theme',
			'description' => 'Kadence theme — header/footer builder, customizer panels, hooks, child theme guidance',
			// Auto-enabled when the active (parent) theme is Kadence.
			'enabled'     => false,
		],
	];

	/**
	 * Return the built-in skill definitions.
	 *
	 * Metadata is defined in BUILTIN_META. Content is loaded from
	 * markdown files in the skills/ directory ({slug}.md).
	 *
	 * @return array<string, array{name: string, description: string, enabled: bool, content: string}> Keyed by slug.
	 */
	public static function get_builtin_definitions(): array {
		$definitions = [];

		foreach ( self::BUILTIN_META as $slug => $meta ) {
			$definitions[ $slug ] = array_merge(
				$meta,
				[ 'content' => self::load_skill_file( $slug ) ]
			);
		}

		return $definitions;
	}

	/**
	 * Load a built-in skill's markdown content from disk.
	 *
	 * @param string $slug The skill slug (matches the .md filename).
	 * @return string The markdown content, or an empty string if the file is missing.
	 */
	private static function load_skill_file( string $slug ): string {
		$path = self::SKILLS_DIR . '/' . $slug . '.md';

		if ( ! file_exists( $path ) ) {
			return '';
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local bundled plugin file

		return false !== $content ? trim( $content ) : '';
	}
}
