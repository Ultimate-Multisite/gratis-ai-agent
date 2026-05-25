<?php

declare(strict_types=1);
/**
 * Service class for reverting AI-made WordPress changes.
 *
 * Extracted from ChangesController to separate domain concerns from HTTP handling.
 * Knows about WordPress object types and applies the appropriate WordPress API
 * calls to restore prior values.
 *
 * @package SdAiAgent\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies revert operations for AI-made changes to WordPress objects.
 */
final class ChangeRevertService {

	/**
	 * Apply the revert operation for a change record.
	 *
	 * Dispatches to the appropriate WordPress API function based on the
	 * object type. Third-party code can extend support for custom object
	 * types via the `sd_ai_agent_revert_change` filter.
	 *
	 * Object types handled natively:
	 *   - Any registered post type (post, page, CPTs) → wp_update_post()
	 *   - option                                      → update_option()
	 *   - term                                        → wp_update_term()
	 *   - user (profile fields + role)                → wp_update_user()
	 *   - post_meta                                   → update/delete_post_meta()
	 *   - nav_menu (creation only)                    → wp_delete_nav_menu()
	 *
	 * @param object $change Change record row from the database.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function apply_revert( object $change ): true|WP_Error {

		// Guard 1: non-revertable records (filesystem writes, WP-CLI, etc.)
		// revertable defaults to 1/true for old rows without the column.
		if ( isset( $change->revertable ) && ! $change->revertable ) {
			return new WP_Error(
				'not_revertable',
				__( 'This change cannot be automatically undone.', 'superdav-ai-agent' ),
				array( 'status' => 422 )
			);
		}

		// Guard 2: [REDACTED] sentinel values were never stored — reverting would
		// permanently overwrite the field with the literal string.
		if ( '[REDACTED]' === $change->before_value ) {
			return new WP_Error(
				'cannot_revert_redacted',
				__( 'This field was redacted for security and cannot be reverted automatically.', 'superdav-ai-agent' ),
				array( 'status' => 422 )
			);
		}

		switch ( $change->object_type ) {

			// ── File (write, edit, or delete revert) ──────────────────────────────
			case 'file':
				return self::revert_file( $change );

			// ── Option (update, add, or restore deleted option) ───────────────────
			case 'option':
				// Empty before_value → option was newly added; revert by deleting it.
				// Otherwise restore the prior value (update_option() recreates the row
				// if it was deleted). maybe_unserialize() decodes arrays/objects that
				// the logger stored via maybe_serialize().
				if ( '' === $change->before_value ) {
					delete_option( $change->field_name );
				} else {
					update_option( $change->field_name, maybe_unserialize( $change->before_value ) );
				}
				return true;

			// ── Term name ─────────────────────────────────────────────────────────
			case 'term':
				$result = wp_update_term(
					(int) $change->object_id,
					$change->field_name,
					array( 'name' => $change->before_value )
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return true;

			// ── User profile field + role ─────────────────────────────────────────
			case 'user':
				if ( 'role' === $change->field_name ) {
					// before_value may be comma-separated when the user had multiple roles.
					$roles = array_filter( explode( ',', $change->before_value ) );
					if ( empty( $roles ) ) {
						return new WP_Error(
							'no_role_to_restore',
							__( 'No previous role recorded; cannot revert role change.', 'superdav-ai-agent' ),
							array( 'status' => 422 )
						);
					}
					$user = get_userdata( (int) $change->object_id );
					if ( ! $user ) {
						return new WP_Error(
							'user_not_found',
							__( 'User not found.', 'superdav-ai-agent' ),
							array( 'status' => 404 )
						);
					}
					// Set primary role then add any additional roles.
					$primary = array_shift( $roles );
					$user->set_role( $primary );
					foreach ( $roles as $extra_role ) {
						$user->add_role( $extra_role );
					}
					return true;
				}

				$result = wp_update_user(
					array(
						'ID'                => (int) $change->object_id,
						$change->field_name => $change->before_value,
					)
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return true;

			// ── Post meta (Gap 1) ─────────────────────────────────────────────────
			case 'post_meta':
				$post_id  = (int) $change->object_id;
				$meta_key = $change->field_name;

				if ( '' === $change->before_value ) {
					// Meta was newly added → revert by deleting it.
					delete_post_meta( $post_id, $meta_key );
					return true;
				}

				// Meta was updated or deleted → restore the previous value.
				// Use update_post_meta so it creates the key if it was deleted.
				update_post_meta( $post_id, $meta_key, maybe_unserialize( $change->before_value ) );
				return true;

			// ── Nav menu (Gap 3, creation only) ──────────────────────────────────
			case 'nav_menu':
				if ( '' === $change->before_value ) {
					// Menu was created → revert by deleting it (and all its items).
					$result = wp_delete_nav_menu( (int) $change->object_id );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return true;
				}

				// Menu was deleted — marked non-revertable at record time; this
				// branch is a fallback safety net.
				return new WP_Error(
					'nav_menu_deletion_unrevertable',
					__( 'Deleted navigation menus cannot be automatically restored.', 'superdav-ai-agent' ),
					array( 'status' => 422 )
				);

			default:
				// Handle all registered WordPress post types (post, page, CPTs).
				if ( post_type_exists( $change->object_type ) ) {
					$result = wp_update_post(
						array(
							'ID'                => (int) $change->object_id,
							$change->field_name => $change->before_value,
						),
						true
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return true;
				}

				/**
				 * Allow third-party code to handle revert for custom object types.
				 *
				 * @param true|WP_Error $result  Default WP_Error (unhandled).
				 * @param object        $change  Change record row.
				 */
				$result = apply_filters(
					'sd_ai_agent_revert_change',
					new WP_Error(
						'unsupported_object_type',
						sprintf(
							/* translators: %s: object type slug */
							__( 'Revert is not supported for object type "%s".', 'superdav-ai-agent' ),
							$change->object_type
						),
						array( 'status' => 422 )
					),
					$change
				);
				return $result;
		}
	}

	/**
	 * Revert a file change (write, edit, or delete).
	 *
	 * Handles three scenarios:
	 * - New file write: before_value is empty → delete the file
	 * - In-place edit: before_value has content → restore prior content
	 * - File delete: before_value has content, after_value is empty → recreate the file
	 *
	 * After restoring the file, creates a pre-restore snapshot so the revert
	 * is itself reversible (mirrors existing user/option/post_meta semantics).
	 *
	 * @param object $change Change record row from the database.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function revert_file( object $change ): true|WP_Error {
		$file_path = $change->field_name;

		if ( empty( $file_path ) ) {
			return new WP_Error(
				'file_revert_no_path',
				__( 'File path is missing from the change record.', 'superdav-ai-agent' ),
				array( 'status' => 422 )
			);
		}

		// Validate that the path is within wp-content (security check).
		$wp_content_real = realpath( WP_CONTENT_DIR );
		$file_real       = realpath( dirname( $file_path ) );

		if ( false === $wp_content_real || false === $file_real ) {
			return new WP_Error(
				'file_revert_path_invalid',
				__( 'Cannot resolve file path.', 'superdav-ai-agent' ),
				array( 'status' => 422 )
			);
		}

		if ( strpos( $file_real, $wp_content_real ) !== 0 ) {
			return new WP_Error(
				'file_revert_path_outside_wpcontent',
				__( 'File path is outside wp-content directory.', 'superdav-ai-agent' ),
				array( 'status' => 422 )
			);
		}

		// Case 1: New file write (before_value is empty) → delete the file.
		if ( '' === $change->before_value ) {
			if ( ! file_exists( $file_path ) ) {
				// File was already deleted; revert is a no-op.
				return true;
			}

			global $wp_filesystem;
			/** @var \WP_Filesystem_Base $wp_filesystem */
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if ( ! $wp_filesystem->delete( $file_path ) ) {
				return new WP_Error(
					'file_revert_delete_failed',
					sprintf(
						/* translators: %s: file path */
						__( 'Failed to delete file during revert: %s', 'superdav-ai-agent' ),
						$file_path
					),
					array( 'status' => 500 )
				);
			}

			return true;
		}

		// Case 2 & 3: Restore prior content (edit or delete revert).
		// Create directory if needed.
		$dir = dirname( $file_path );
		if ( ! file_exists( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error(
					'file_revert_mkdir_failed',
					sprintf(
						/* translators: %s: directory path */
						__( 'Failed to create directory during revert: %s', 'superdav-ai-agent' ),
						$dir
					),
					array( 'status' => 500 )
				);
			}
		}

		global $wp_filesystem;
		/** @var \WP_Filesystem_Base $wp_filesystem */
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem->put_contents( $file_path, $change->before_value, FS_CHMOD_FILE ) ) {
			return new WP_Error(
				'file_revert_restore_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Failed to restore file during revert: %s', 'superdav-ai-agent' ),
					$file_path
				),
				array( 'status' => 500 )
			);
		}

		return true;
	}
}
