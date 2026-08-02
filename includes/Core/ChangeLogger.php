<?php

declare(strict_types=1);
/**
 * Change logger — hooks into WordPress core actions to record AI-made changes.
 *
 * Uses a thread-local flag (set by AgentLoop before executing abilities) to
 * distinguish AI-initiated changes from user-initiated ones. Only records
 * changes when the flag is active.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Models\ChangesLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChangeLogger {

	/**
	 * Whether AI-initiated change logging is currently active.
	 *
	 * Set to true by AgentLoop before executing abilities, false after.
	 *
	 * @var bool
	 */
	private static bool $active = false;

	/**
	 * Current session ID for attributing changes.
	 *
	 * @var int
	 */
	private static int $session_id = 0;

	/**
	 * Current ability name for attributing changes.
	 *
	 * @var string
	 */
	private static string $ability_name = '';

	/**
	 * Cache of term names captured before a term edit fires.
	 *
	 * Keyed by term_id so the before-value is available inside
	 * on_edited_term() which fires after the save.
	 * Read via array-key access: self::$term_name_cache[$term_id] in on_edited_term().
	 *
	 * @var array<int,string>
	 */
	private static array $term_name_cache = [];

	/**
	 * Begin recording changes for an AI session.
	 *
	 * Call this before executing an ability that may make changes.
	 *
	 * @param int    $session_id   Session ID.
	 * @param string $ability_name Ability slug being executed.
	 */
	public static function begin( int $session_id = 0, string $ability_name = '' ): void {
		self::$active       = true;
		self::$session_id   = $session_id;
		self::$ability_name = $ability_name;
	}

	/**
	 * Stop recording changes.
	 */
	public static function end(): void {
		self::$active       = false;
		self::$session_id   = 0;
		self::$ability_name = '';
	}

	/**
	 * Whether change logging is currently active.
	 */
	public static function is_active(): bool {
		return self::$active;
	}

	/**
	 * Return the active session ID (0 when logging is inactive).
	 */
	public static function get_session_id(): int {
		return self::$session_id;
	}

	/**
	 * Return the active ability name (empty string when logging is inactive).
	 */
	public static function get_ability_name(): string {
		return self::$ability_name;
	}

	/**
	 * Record a newly created post while AI change logging is active.
	 *
	 * WordPress core's post_updated hook only captures before/after field
	 * transitions for existing posts. A successful wp_insert_post() therefore
	 * needs an explicit creation marker so the Changes UI can audit and revert
	 * agent-created content.
	 *
	 * @param int      $post_id      Created post ID.
	 * @param \WP_Post $post         Created post object.
	 * @param string   $ability_name Ability slug to record when no active ability name is set.
	 */
	public static function record_post_created( int $post_id, \WP_Post $post, string $ability_name = 'sd-ai-agent/create-post' ): void {
		if ( ! self::$active ) {
			return;
		}

		ChangesLog::record(
			[
				'session_id'   => self::$session_id,
				'object_type'  => $post->post_type,
				'object_id'    => $post_id,
				'object_title' => $post->post_title,
				'ability_name' => self::$ability_name ?: $ability_name,
				'field_name'   => 'post_created',
				'before_value' => '',
				'after_value'  => $post->post_title,
			]
		);
	}

	/**
	 * Record a newly created taxonomy term while AI change logging is active.
	 *
	 * WordPress exposes term-edit hooks but no symmetric before/after record for
	 * a newly created term. Typed commerce plans use this marker so category
	 * creation remains auditable without treating a term name as an option value.
	 *
	 * @param int    $term_id      Created term ID.
	 * @param string $taxonomy     Taxonomy slug.
	 * @param string $term_name    Created term name.
	 * @param string $ability_name Ability slug when no active name is set.
	 */
	public static function record_term_created( int $term_id, string $taxonomy, string $term_name, string $ability_name = 'sd-ai-agent/create-term' ): void {
		if ( ! self::$active ) {
			return;
		}

		ChangesLog::record(
			[
				'session_id'   => self::$session_id,
				'object_type'  => 'term',
				'object_id'    => $term_id,
				'object_title' => $term_name,
				'ability_name' => self::$ability_name ?: $ability_name,
				'field_name'   => $taxonomy,
				'before_value' => '',
				'after_value'  => $term_name,
				'revertable'   => false,
			]
		);
	}

	/**
	 * Cache of post meta before-values, keyed by "{object_id}:{meta_key}".
	 *
	 * Read via array-key access in on_updated_post_meta().
	 *
	 * @var array<string,string>
	 */
	private static array $post_meta_cache = [];

	/**
	 * Cache of option before-values pending deletion, keyed by option name.
	 *
	 * Read via array-key access in on_deleted_option().
	 *
	 * @var array<string,string>
	 */
	private static array $option_delete_cache = [];

	/**
	 * Cache of nav menu snapshots before deletion, keyed by menu term_id.
	 *
	 * Read via array-key access in on_deleted_nav_menu().
	 *
	 * @var array<int,string>
	 */
	private static array $nav_menu_cache = [];

	/**
	 * Register WordPress hooks for change logging.
	 */
	public static function register(): void {
		// Post content/title/status changes.
		add_action( 'post_updated', [ __CLASS__, 'on_post_updated' ], 10, 3 );

		// Post meta changes (Gap 1): cache before-value then record after.
		// update_post_meta fires with 4 args but we only need the first 3
		// (meta_id, object_id, meta_key); we read the live value via get_post_meta().
		add_action( 'update_post_meta', [ __CLASS__, 'on_update_post_meta' ], 10, 3 );
		add_action( 'updated_post_meta', [ __CLASS__, 'on_updated_post_meta' ], 10, 4 );
		add_action( 'added_post_meta', [ __CLASS__, 'on_added_post_meta' ], 10, 4 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'on_deleted_post_meta' ], 10, 4 );

		// Option changes (existing + Gap 2: deleted options).
		add_action( 'updated_option', [ __CLASS__, 'on_updated_option' ], 10, 3 );
		add_action( 'added_option', [ __CLASS__, 'on_added_option' ], 10, 2 );
		add_action( 'delete_option', [ __CLASS__, 'on_pre_delete_option' ], 10, 1 );
		add_action( 'deleted_option', [ __CLASS__, 'on_deleted_option' ], 10, 1 );

		// Term changes: capture before-value before the save, record after.
		add_action( 'edit_terms', [ __CLASS__, 'on_edit_terms' ], 10, 2 );
		add_action( 'edited_term', [ __CLASS__, 'on_edited_term' ], 10, 3 );

		// Nav menu changes (Gap 3).
		add_action( 'wp_create_nav_menu', [ __CLASS__, 'on_created_nav_menu' ], 10, 2 );
		add_action( 'wp_delete_nav_menu', [ __CLASS__, 'on_pre_delete_nav_menu' ], 10, 1 );
		add_action( 'deleted_term', [ __CLASS__, 'on_deleted_nav_menu_term' ], 10, 4 );

		// User profile changes (existing) + role changes (Gap 4).
		add_action( 'profile_update', [ __CLASS__, 'on_profile_update' ], 10, 2 );
		add_action( 'set_user_role', [ __CLASS__, 'on_set_user_role' ], 10, 3 );
	}

	/**
	 * Record post field changes.
	 *
	 * Uses the actual post_type (e.g. 'post', 'page', 'product') as the
	 * object_type so the revert service can call wp_update_post() for any
	 * registered post type, and the UI can filter by the real type.
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post_after  Post object after update.
	 * @param \WP_Post $post_before Post object before update.
	 */
	public static function on_post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( ! self::$active || 'revision' === $post_after->post_type ) {
			return;
		}

		$tracked_fields = [
			'post_title',
			'post_content',
			'post_excerpt',
			'post_status',
			'post_name',
		];

		foreach ( $tracked_fields as $field ) {
			$before = $post_before->$field ?? '';
			$after  = $post_after->$field ?? '';

			if ( $before === $after ) {
				continue;
			}

			ChangesLog::record(
				[
					'session_id'   => self::$session_id,
					'object_type'  => $post_after->post_type,
					'object_id'    => $post_id,
					'object_title' => $post_after->post_title,
					'ability_name' => self::$ability_name,
					'field_name'   => $field,
					'before_value' => (string) $before,
					'after_value'  => (string) $after,
				]
			);
		}
	}

	/**
	 * Record option value changes.
	 *
	 * Skips transients and internal WordPress options to avoid noise.
	 *
	 * Values are stored via maybe_serialize() so that complex types (arrays,
	 * objects) round-trip correctly through maybe_unserialize() in the revert
	 * service. Scalar strings are unaffected by maybe_serialize().
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $new_value New value.
	 */
	public static function on_updated_option( string $option, $old_value, $new_value ): void {
		if ( ! self::$active ) {
			return;
		}

		if ( self::is_noisy_option( $option ) ) {
			return;
		}

		// Use maybe_serialize so arrays/objects can be restored correctly by
		// maybe_unserialize() in ChangeRevertService. For scalars this is a no-op.
		$before = maybe_serialize( $old_value );
		$after  = maybe_serialize( $new_value );

		if ( $before === $after ) {
			return;
		}

		if ( self::is_sensitive_option( $option ) ) {
			$before = self::redact_value();
			$after  = self::redact_value();
		}

		ChangesLog::record(
			[
				'session_id'   => self::$session_id,
				'object_type'  => 'option',
				'object_id'    => 0,
				'object_title' => $option,
				'ability_name' => self::$ability_name,
				'field_name'   => $option,
				'before_value' => (string) $before,
				'after_value'  => (string) $after,
			]
		);
	}

	/**
	 * Record newly added options.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 */
	public static function on_added_option( string $option, $value ): void {
		if ( ! self::$active ) {
			return;
		}

		if ( self::is_noisy_option( $option ) ) {
			return;
		}

		$after = maybe_serialize( $value );

		if ( self::is_sensitive_option( $option ) ) {
			$after = self::redact_value();
		}

		ChangesLog::record(
			[
				'session_id'   => self::$session_id,
				'object_type'  => 'option',
				'object_id'    => 0,
				'object_title' => $option,
				'ability_name' => self::$ability_name,
				'field_name'   => $option,
				'before_value' => '',
				'after_value'  => (string) $after,
			]
		);
	}

	/**
	 * Cache the term's current name before it is overwritten by wp_update_term().
	 *
	 * WordPress fires the 'edit_terms' action before the row is saved, which is
	 * the only opportunity to capture the old name. The value is stored in
	 * $term_name_cache keyed by term_id so on_edited_term() can use it.
	 *
	 * @param int    $term_id  Term ID being edited.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function on_edit_terms( int $term_id, string $taxonomy ): void {
		if ( ! self::$active ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			self::$term_name_cache[ $term_id ] = $term->name;
		}
	}

	/**
	 * Record term changes.
	 *
	 * Reads the before-name from $term_name_cache which was populated by
	 * on_edit_terms() before the save occurred.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function on_edited_term( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! self::$active ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			unset( self::$term_name_cache[ $term_id ] );
			return;
		}

		$before_name = self::$term_name_cache[ $term_id ] ?? '';
		unset( self::$term_name_cache[ $term_id ] );

		// Skip if name did not actually change.
		if ( $before_name === $term->name ) {
			return;
		}

		ChangesLog::record(
			[
				'session_id'   => self::$session_id,
				'object_type'  => 'term',
				'object_id'    => $term_id,
				'object_title' => $term->name,
				'ability_name' => self::$ability_name,
				'field_name'   => $taxonomy,
				'before_value' => $before_name,
				'after_value'  => $term->name,
			]
		);
	}

	/**
	 * Record user profile changes.
	 *
	 * @param int      $user_id       User ID.
	 * @param \WP_User $old_user_data User object before update.
	 */
	public static function on_profile_update( int $user_id, \WP_User $old_user_data ): void {
		if ( ! self::$active ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$tracked_fields = [ 'user_email', 'display_name', 'user_url' ];

		foreach ( $tracked_fields as $field ) {
			$before = $old_user_data->$field ?? '';
			$after  = $user->$field ?? '';

			if ( $before === $after ) {
				continue;
			}

			// Redact PII fields — log that the field changed without storing the value.
			if ( 'user_email' === $field ) {
				$before = self::redact_value();
				$after  = self::redact_value();
			}

			ChangesLog::record(
				[
					'session_id'   => self::$session_id,
					'object_type'  => 'user',
					'object_id'    => $user_id,
					'object_title' => $user->display_name,
					'ability_name' => self::$ability_name,
					'field_name'   => $field,
					'before_value' => (string) $before,
					'after_value'  => (string) $after,
				]
			);
		}
	}

	/**
	 * Whether an option name is too noisy to track.
	 *
	 * Skips transients, cron schedules, and other high-frequency internal options.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private static function is_noisy_option( string $option ): bool {
		$noisy_prefixes = [
			'_transient_',
			'_site_transient_',
			'_transient_timeout_',
			'_site_transient_timeout_',
			'cron',
			'rewrite_rules',
			'sd_ai_agent_',
		];

		foreach ( $noisy_prefixes as $prefix ) {
			if ( str_starts_with( $option, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an option name likely contains sensitive data (API keys, tokens, passwords, etc.).
	 *
	 * Matches common naming patterns used by WordPress core and plugins for
	 * credential-like options to prevent secrets/PII from being stored in the
	 * change log in plain text.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private static function is_sensitive_option( string $option ): bool {
		return (bool) preg_match(
			'/(api[_-]?key|token|secret|password|pass|auth|client|authorization|cookie)/i',
			$option
		);
	}

	/**
	 * Redact a value that should not be stored in plain text.
	 *
	 * Returns a constant placeholder so the change log records that a sensitive
	 * field was modified without persisting the actual value.
	 *
	 * @return string
	 */
	private static function redact_value(): string {
		return '[REDACTED]';
	}

	// ── GAP 1: Post meta ─────────────────────────────────────────────────────

	/**
	 * Whether a post meta key is internal WordPress noise that should not be logged.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	private static function is_noisy_meta( string $meta_key ): bool {
		static $noisy = [
			'_edit_lock',
			'_edit_last',
			'_encloseme',
			'_pingme',
			'_wp_old_slug',
			'_wp_old_date',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'_wp_desired_post_slug',
			'_sd_ai_agent_page_preview_workspace',
		];
		if ( in_array( $meta_key, $noisy, true ) ) {
			return true;
		}
		// Skip revision / autosave internal meta prefixes.
		if ( str_starts_with( $meta_key, '_wp_trash_' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Cache the current meta value before an update so on_updated_post_meta()
	 * has access to the before-value.
	 *
	 * @param int    $meta_id   Meta row ID (unused; hook arg, accepted_args=3).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function on_update_post_meta( int $meta_id, int $object_id, string $meta_key ): void {
		if ( ! self::$active || self::is_noisy_meta( $meta_key ) ) {
			return;
		}
		$current = get_post_meta( $object_id, $meta_key, true );
		self::$post_meta_cache[ "{$object_id}:{$meta_key}" ] = (string) maybe_serialize( $current );
	}

	/**
	 * Record a post meta update after it has been saved.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public static function on_updated_post_meta( int $meta_id, int $object_id, string $meta_key, $meta_value ): void {
		if ( ! self::$active || self::is_noisy_meta( $meta_key ) ) {
			unset( self::$post_meta_cache[ "{$object_id}:{$meta_key}" ] );
			return;
		}

		$cache_key    = "{$object_id}:{$meta_key}";
		$before_value = self::$post_meta_cache[ $cache_key ] ?? '';
		unset( self::$post_meta_cache[ $cache_key ] );

		$after_value = maybe_serialize( $meta_value );

		if ( $before_value === $after_value ) {
			return;
		}

		$post  = get_post( $object_id );
		$title = $post ? $post->post_title : '';

		ChangesLog::record(
			[
				'session_id'   => self::$session_id,
				'object_type'  => 'post_meta',
				'object_id'    => $object_id,
				'object_title' => $title,
				'ability_name' => self::$ability_name,
				'field_name'   => $meta_key,
				'before_value' => $before_value,
				'after_value'  => $after_value,
			]
		);
	}

	/**
	 * Record a newly added post meta entry.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public static function on_added_post_meta( int $meta_id, int $object_id, string $meta_key, $meta_value ): void {
		if ( ! self::$active || self::is_noisy_meta( $meta_key ) ) {
			return;
		}

		$post  = get_post( $object_id );
		$title = $post ? $post->post_title : '';

		ChangesLog::record(
			[
				'session_id'   => self::$session_id,
				'object_type'  => 'post_meta',
				'object_id'    => $object_id,
				'object_title' => $title,
				'ability_name' => self::$ability_name,
				'field_name'   => $meta_key,
				'before_value' => '',
				'after_value'  => maybe_serialize( $meta_value ),
			]
		);
	}

	/**
	 * Record deletion of a post meta entry.
	 *
	 * WordPress passes the old meta value in the `deleted_post_meta` action so
	 * no pre-hook cache is needed.
	 *
	 * @param int[]  $meta_ids   Array of deleted meta row IDs.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value The value that was deleted.
	 */
	public static function on_deleted_post_meta( array $meta_ids, int $object_id, string $meta_key, $meta_value ): void {
		if ( ! self::$active || self::is_noisy_meta( $meta_key ) ) {
			return;
		}

		$post  = get_post( $object_id );
		$title = $post ? $post->post_title : '';

		ChangesLog::record(
			[
				'session_id'   => self::$session_id,
				'object_type'  => 'post_meta',
				'object_id'    => $object_id,
				'object_title' => $title,
				'ability_name' => self::$ability_name,
				'field_name'   => $meta_key,
				'before_value' => maybe_serialize( $meta_value ),
				'after_value'  => '',
			]
		);
	}

	// ── GAP 2: Deleted options ────────────────────────────────────────────────

	/**
	 * Cache the option's current value before it is deleted.
	 *
	 * `delete_option` fires immediately before the DB DELETE; at that point
	 * get_option() still returns the live value.
	 *
	 * @param string $option Option name.
	 */
	public static function on_pre_delete_option( string $option ): void {
		if ( ! self::$active || self::is_noisy_option( $option ) ) {
			return;
		}
		$value                                = get_option( $option );
		self::$option_delete_cache[ $option ] = (string) maybe_serialize( $value );
	}

	/**
	 * Record an option deletion after it has completed.
	 *
	 * @param string $option Option name.
	 */
	public static function on_deleted_option( string $option ): void {
		if ( ! self::$active || self::is_noisy_option( $option ) ) {
			unset( self::$option_delete_cache[ $option ] );
			return;
		}

		$before_value = self::$option_delete_cache[ $option ] ?? '';
		unset( self::$option_delete_cache[ $option ] );

		ChangesLog::record(
			[
				'session_id'   => self::$session_id,
				'object_type'  => 'option',
				'object_id'    => 0,
				'object_title' => $option,
				'ability_name' => self::$ability_name,
				'field_name'   => $option,
				'before_value' => $before_value,
				'after_value'  => '',
			]
		);
	}

	// ── GAP 3: Nav menus ─────────────────────────────────────────────────────

	/**
	 * Record creation of a new navigation menu.
	 *
	 * Revert: delete the empty new menu (wp_delete_nav_menu).
	 *
	 * @param int                 $term_id   The nav menu term ID.
	 * @param array<string,mixed> $menu_data Menu creation args (e.g. 'menu-name').
	 */
	public static function on_created_nav_menu( int $term_id, array $menu_data ): void {
		if ( ! self::$active ) {
			return;
		}

		$menu_name = (string) ( $menu_data['menu-name'] ?? '' );

		ChangesLog::record(
			[
				'session_id'   => self::$session_id,
				'object_type'  => 'nav_menu',
				'object_id'    => $term_id,
				'object_title' => $menu_name,
				'ability_name' => self::$ability_name,
				'field_name'   => 'menu',
				'before_value' => '',
				'after_value'  => $menu_name,
			]
		);
	}

	/**
	 * Snapshot the menu structure before deletion so the record has a useful
	 * before_value even though the deletion itself cannot be automatically reversed.
	 *
	 * `wp_delete_nav_menu` fires before the term is removed. At that point the
	 * menu term and its items still exist in the database.
	 *
	 * @param \WP_Term|false $menu The menu term object (or false).
	 */
	public static function on_pre_delete_nav_menu( $menu ): void {
		if ( ! self::$active || ! ( $menu instanceof \WP_Term ) ) {
			return;
		}

		$items    = wp_get_nav_menu_items( $menu->term_id );
		$snapshot = [
			'name'  => $menu->name,
			'items' => array_map(
				static fn( $i ) => [
					'title' => $i->title,
					'url'   => $i->url,
					'type'  => $i->type,
				],
				is_array( $items ) ? $items : []
			),
		];

		self::$nav_menu_cache[ $menu->term_id ] = (string) wp_json_encode( $snapshot );
	}

	/**
	 * Record a nav menu deletion as an UNREVERTABLE audit entry.
	 *
	 * Restoring a deleted nav menu from a JSON snapshot would require
	 * recreating the term AND all items — too complex to automate safely.
	 * The entry still appears in the log so the user can see what was lost.
	 *
	 * Triggered by the `deleted_term` action which fires for any taxonomy,
	 * so we filter to `nav_menu` taxonomy only.
	 *
	 * @param int    $term_id      Deleted term ID.
	 * @param int    $tt_id        Term taxonomy ID.
	 * @param string $taxonomy     Taxonomy slug.
	 * @param object $deleted_term The deleted WP_Term object.
	 */
	public static function on_deleted_nav_menu_term( int $term_id, int $tt_id, string $taxonomy, object $deleted_term ): void {
		if ( ! self::$active || 'nav_menu' !== $taxonomy ) {
			unset( self::$nav_menu_cache[ $term_id ] );
			return;
		}

		$before_value = self::$nav_menu_cache[ $term_id ] ?? wp_json_encode(
			[
				'name'  => $deleted_term->name,
				'items' => [],
			]
		);
		unset( self::$nav_menu_cache[ $term_id ] );

		ChangesLog::record(
			[
				'session_id'   => self::$session_id,
				'object_type'  => 'nav_menu',
				'object_id'    => $term_id,
				'object_title' => $deleted_term->name,
				'ability_name' => self::$ability_name,
				'field_name'   => 'menu',
				'before_value' => $before_value,
				'after_value'  => '',
				'revertable'   => false,
			]
		);
	}

	// ── GAP 4: User role changes ──────────────────────────────────────────────

	/**
	 * Record a user role change triggered by wp_update_user( ['role' => …] )
	 * or direct $user->set_role() calls.
	 *
	 * The `set_user_role` action fires with both the old and new role values,
	 * making it the cleanest hook for this purpose.
	 *
	 * @param int               $user_id   User ID.
	 * @param string            $new_role  New role slug.
	 * @param array<int,string> $old_roles Array of previous role slugs.
	 */
	public static function on_set_user_role( int $user_id, string $new_role, array $old_roles ): void {
		if ( ! self::$active ) {
			return;
		}

		$before = implode( ',', array_map( 'strval', $old_roles ) );
		$after  = $new_role;

		if ( $before === $after ) {
			return;
		}

		$user  = get_userdata( $user_id );
		$title = $user ? $user->display_name : "user #{$user_id}";

		ChangesLog::record(
			[
				'session_id'   => self::$session_id,
				'object_type'  => 'user',
				'object_id'    => $user_id,
				'object_title' => $title,
				'ability_name' => self::$ability_name,
				'field_name'   => 'role',
				'before_value' => $before,
				'after_value'  => $after,
			]
		);
	}
}
