<?php

declare(strict_types=1);
/**
 * Autosave-backed preview workspaces for existing published pages.
 *
 * Visual page mutations made by the Setup and General agents are staged in
 * the current user's WordPress autosave revision. The published parent remains
 * unchanged until PageCompletionGate accepts the exact preview and AgentLoop
 * commits it. WordPress core's authenticated preview URL renders the autosave
 * through the real page ID, permalink, active theme, and front-page template.
 *
 * This intentionally governs only existing published pages and only fields
 * WordPress can faithfully preview: title, content, excerpt, and featured
 * media. Theme, template, taxonomy, navigation, option, and arbitrary-meta
 * changes remain outside this narrow workspace.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use WP_Error;
use WP_Post;

/** Manages one per-user WordPress autosave as a private page working copy. */
final class PagePreviewWorkspace {

	private const META_KEY = '_sd_ai_agent_page_preview_workspace';

	private const FORMAT_VERSION = 1;

	private const CONTEXT_LOCK_PREFIX = 'sd_ai_agent_page_preview_scope_';

	private static string $profile = PageCompletionGate::PROFILE_OFF;

	private static int $session_id = 0;

	private static string $job_id = '';

	private static bool $client_validator_available = false;

	/**
	 * Enable preview routing for one agent-loop request.
	 *
	 * @param string $profile                    Page-quality profile.
	 * @param int    $session_id                 Conversation session ID.
	 * @param string $job_id                     Active background-job UUID.
	 * @param bool   $client_validator_available Whether browser preview QA is available.
	 */
	public static function activate( string $profile, int $session_id, string $job_id, bool $client_validator_available ): void {
		self::$profile                    = $profile;
		self::$session_id                 = max( 0, $session_id );
		self::$job_id                     = sanitize_text_field( $job_id );
		self::$client_validator_available = $client_validator_available;
	}

	/** Clear request-scoped preview routing. */
	public static function deactivate(): void {
		self::$profile                    = PageCompletionGate::PROFILE_OFF;
		self::$session_id                 = 0;
		self::$job_id                     = '';
		self::$client_validator_available = false;
	}

	/** Whether this request governs the supplied existing published page. */
	public static function governs( WP_Post $post ): bool {
		return in_array( self::$profile, array( PageCompletionGate::PROFILE_SETUP, PageCompletionGate::PROFILE_INCREMENTAL ), true )
			&& get_current_user_id() > 0
			&& 'page' === $post->post_type
			&& 'publish' === $post->post_status
			&& '' !== self::context_id();
	}

	/** Whether this request can stage mutations for the supplied parent page. */
	public static function should_stage( WP_Post $post ): bool {
		return self::$client_validator_available && self::governs( $post );
	}

	/** Whether the current context owns an active workspace for a page. */
	public static function has_workspace( int $post_id ): bool {
		$workspace = self::load_owned_workspace( $post_id, false );
		return is_array( $workspace );
	}

	/**
	 * Return the parent post with staged revision fields overlaid when present.
	 *
	 * @return WP_Post|WP_Error
	 */
	public static function get_working_post( int $post_id ) {
		$parent = get_post( $post_id );
		if ( ! ( $parent instanceof WP_Post ) ) {
			return new WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d not found.', 'superdav-ai-agent' ),
					$post_id
				),
				array( 'status' => 404 )
			);
		}

		$workspace = self::load_owned_workspace( $post_id, false );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}
		if ( ! is_array( $workspace ) ) {
			return $parent;
		}

		$working               = clone $parent;
		$working->post_title   = $workspace['autosave']->post_title;
		$working->post_content = $workspace['autosave']->post_content;
		$working->post_excerpt = $workspace['autosave']->post_excerpt;

		return $working;
	}

	/**
	 * Check optimistic concurrency against either the live base or autosave ID.
	 *
	 * @return true|WP_Error
	 */
	public static function check_expected_revision( int $post_id, string $raw_expected ): true|WP_Error {
		$parsed = RevisionGuard::parse_raw( $raw_expected );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$workspace = self::load_owned_workspace( $post_id, false );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}
		if ( ! is_array( $workspace ) ) {
			return RevisionGuard::check( $post_id, $parsed );
		}

		$integrity = self::verify_workspace_integrity( $workspace );
		if ( is_wp_error( $integrity ) ) {
			return $integrity;
		}
		if ( null === $parsed ) {
			return true;
		}

		$metadata         = $workspace['metadata'];
		$base_revision_id = isset( $metadata['base_revision_id'] ) ? (int) $metadata['base_revision_id'] : 0;
		$autosave_id      = (int) $workspace['autosave']->ID;
		if ( $parsed === $autosave_id || ( $base_revision_id > 0 && $parsed === $base_revision_id ) ) {
			return true;
		}

		return new WP_Error(
			'stale_preview_revision',
			__( 'The page preview changed since you fetched it. Re-fetch the staged block tree and retry.', 'superdav-ai-agent' ),
			array(
				'status'              => 412,
				'current_revision_id' => $autosave_id,
				'expected_revision'   => $parsed,
			)
		);
	}

	/** Return the current autosave ID, falling back to the live revision ID. */
	public static function current_revision_id( int $post_id ): ?int {
		$workspace = self::load_owned_workspace( $post_id, false );
		if ( is_array( $workspace ) ) {
			return (int) $workspace['autosave']->ID;
		}
		return RevisionGuard::current_revision_id( $post_id );
	}

	/**
	 * Stage supported post fields in the current user's autosave.
	 *
	 * Returns null when preview routing is inactive for this post.
	 *
	 * @param int                  $post_id          Published parent page ID.
	 * @param array<string,string> $fields           post_title/content/excerpt replacements.
	 * @param int|null             $featured_image_id Effective featured-media ID, null to preserve.
	 * @param array                $affected_fields  Accumulated transport field names.
	 * @phpstan-param list<string> $affected_fields
	 * @return array<string,mixed>|WP_Error|null
	 */
	public static function stage_fields( int $post_id, array $fields, ?int $featured_image_id = null, array $affected_fields = array() ) {
		$parent = get_post( $post_id );
		if ( ! ( $parent instanceof WP_Post ) || ! self::governs( $parent ) ) {
			return null;
		}
		if ( ! self::$client_validator_available ) {
			return new WP_Error(
				'sd_ai_agent_preview_validator_unavailable',
				__( 'The published page was not changed because this client cannot validate a private WordPress preview.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to edit this page.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		$workspace = self::load_owned_workspace( $post_id, true );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		if ( is_array( $workspace ) ) {
			$integrity = self::verify_workspace_integrity( $workspace );
			if ( is_wp_error( $integrity ) ) {
				return $integrity;
			}
			$metadata = $workspace['metadata'];
			$autosave = $workspace['autosave'];
		} else {
			$metadata = array(
				'format_version'    => self::FORMAT_VERSION,
				'workspace_id'      => wp_generate_uuid4(),
				'context_id'        => self::context_id(),
				'user_id'           => get_current_user_id(),
				'parent_id'         => $post_id,
				'base_revision_id'  => RevisionGuard::current_revision_id( $post_id ),
				'base_fingerprint'  => self::parent_fingerprint( $parent ),
				'generation'        => 0,
				'featured_image_id' => (int) get_post_thumbnail_id( $post_id ),
				'fields'            => array(),
				'created_at'        => time(),
			);
			$autosave = null;
		}

		$title   = $autosave instanceof WP_Post ? $autosave->post_title : $parent->post_title;
		$content = $autosave instanceof WP_Post ? $autosave->post_content : $parent->post_content;
		$excerpt = $autosave instanceof WP_Post ? $autosave->post_excerpt : $parent->post_excerpt;
		if ( array_key_exists( 'post_title', $fields ) ) {
			$title = $fields['post_title'];
		}
		if ( array_key_exists( 'post_content', $fields ) ) {
			$content = $fields['post_content'];
		}
		if ( array_key_exists( 'post_excerpt', $fields ) ) {
			$excerpt = $fields['post_excerpt'];
		}
		if ( null !== $featured_image_id ) {
			$metadata['featured_image_id'] = max( 0, $featured_image_id );
		}

		$effective_featured = (int) ( $metadata['featured_image_id'] ?? 0 );
		if ( null !== $featured_image_id && $effective_featured > 0 && ! self::is_previewable_image( $effective_featured ) ) {
			return new WP_Error(
				'sd_ai_agent_preview_featured_image_invalid',
				__( 'The featured image must be a renderable WordPress image attachment.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}
		$working_hash = self::working_hash( $title, $content, $excerpt, $effective_featured );
		if ( ! ( $autosave instanceof WP_Post ) && $working_hash === self::working_hash( $parent->post_title, $parent->post_content, $parent->post_excerpt, (int) get_post_thumbnail_id( $post_id ) ) ) {
			return new WP_Error(
				'sd_ai_agent_no_effective_preview_updates',
				__( 'The proposed preview is identical to the published page.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		$scope = self::claim_context_scope( $post_id );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$controller  = new \WP_REST_Autosaves_Controller( 'page' );
		$autosave_id = $controller->create_post_autosave(
			array(
				'ID'           => $post_id,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
			)
		);
		if ( is_wp_error( $autosave_id ) ) {
			if ( ! ( $autosave instanceof WP_Post ) ) {
				self::release_context_scope( $post_id );
			}
			return $autosave_id;
		}
		if ( (int) $autosave_id <= 0 ) {
			if ( ! ( $autosave instanceof WP_Post ) ) {
				self::release_context_scope( $post_id );
			}
			return new WP_Error(
				'sd_ai_agent_preview_autosave_failed',
				__( 'WordPress could not create the private page preview.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$metadata['generation']   = (int) ( $metadata['generation'] ?? 0 ) + 1;
		$metadata['working_hash'] = $working_hash;
		$metadata['updated_at']   = time();
		$stored_fields            = array();
		$raw_fields               = is_array( $metadata['fields'] ?? null ) ? $metadata['fields'] : array();
		foreach ( array_merge( $raw_fields, $affected_fields ) as $field ) {
			if ( is_scalar( $field ) ) {
				$stored_fields[] = (string) $field;
			}
		}
		$metadata['fields'] = array_values( array_unique( $stored_fields ) );
		// Use low-level metadata APIs: update_post_meta() redirects revision IDs
		// to their parent and would make the autosave appear unowned on resume.
		update_metadata( 'post', (int) $autosave_id, self::META_KEY, $metadata );

		return self::build_descriptor( $parent, (int) $autosave_id, $metadata );
	}

	/**
	 * Verify a gate-owned preview immediately before a multi-page commit.
	 *
	 * @param array<string,mixed> $target Gate-owned preview target.
	 * @return true|WP_Error
	 */
	public static function preflight_commit( array $target ): true|WP_Error {
		$post_id      = (int) ( $target['post_id'] ?? 0 );
		$workspace_id = (string) ( $target['workspace_id'] ?? '' );
		$autosave_id  = (int) ( $target['revision_id'] ?? 0 );
		$workspace    = self::load_owned_workspace( $post_id, true );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}
		if ( ! is_array( $workspace ) ) {
			return new WP_Error(
				'sd_ai_agent_preview_missing',
				__( 'The approved page preview no longer exists.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}
		$metadata = $workspace['metadata'];
		if ( $workspace_id !== (string) ( $metadata['workspace_id'] ?? '' ) || $autosave_id !== (int) $workspace['autosave']->ID ) {
			return new WP_Error(
				'sd_ai_agent_preview_target_mismatch',
				__( 'The approved page preview does not match the current working copy.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}
		$integrity = self::verify_workspace_integrity( $workspace );
		if ( is_wp_error( $integrity ) ) {
			return $integrity;
		}
		$featured_image_id = (int) ( $metadata['featured_image_id'] ?? 0 );
		$changed_fields    = is_array( $metadata['fields'] ?? null ) ? $metadata['fields'] : array();
		if ( in_array( 'featured_image', $changed_fields, true ) && $featured_image_id > 0 && ! self::is_previewable_image( $featured_image_id ) ) {
			return new WP_Error(
				'sd_ai_agent_preview_featured_image_missing',
				__( 'The approved preview featured image is no longer renderable, so the page was not published.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}
		return true;
	}

	/**
	 * Commit one validated preview to its published parent.
	 *
	 * @param array<string,mixed> $target Gate-owned preview target.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function commit( array $target ) {
		$preflight = self::preflight_commit( $target );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		$post_id      = (int) ( $target['post_id'] ?? 0 );
		$workspace_id = (string) ( $target['workspace_id'] ?? '' );
		$autosave_id  = (int) ( $target['revision_id'] ?? 0 );
		$workspace    = self::load_owned_workspace( $post_id, true );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}
		if ( ! is_array( $workspace ) ) {
			return new WP_Error(
				'sd_ai_agent_preview_missing',
				__( 'The approved page preview no longer exists.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}

		$metadata = $workspace['metadata'];
		if ( $workspace_id !== (string) ( $metadata['workspace_id'] ?? '' ) || $autosave_id !== (int) $workspace['autosave']->ID ) {
			return new WP_Error(
				'sd_ai_agent_preview_target_mismatch',
				__( 'The approved page preview does not match the current working copy.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}
		$integrity = self::verify_workspace_integrity( $workspace );
		if ( is_wp_error( $integrity ) ) {
			return $integrity;
		}

		$autosave = $workspace['autosave'];
		$result   = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $autosave->post_title,
				'post_content' => $autosave->post_content,
				'post_excerpt' => $autosave->post_excerpt,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$featured_image_id = (int) ( $metadata['featured_image_id'] ?? 0 );
		if ( $featured_image_id !== (int) get_post_thumbnail_id( $post_id ) ) {
			if ( $featured_image_id > 0 ) {
				set_post_thumbnail( $post_id, $featured_image_id );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		wp_delete_post_revision( $autosave_id );
		self::release_context_scope( $post_id );
		$published = get_post( $post_id );
		$permalink = get_permalink( $post_id );

		return array(
			'post_id'      => $post_id,
			'post_type'    => $published instanceof WP_Post ? $published->post_type : 'page',
			'status'       => $published instanceof WP_Post ? $published->post_status : 'publish',
			'permalink'    => is_string( $permalink ) ? $permalink : '',
			'revision_id'  => RevisionGuard::current_revision_id( $post_id ),
			'workspace_id' => $workspace_id,
			'affected'     => array(
				'kind'        => 'post',
				'post_id'     => $post_id,
				'post_type'   => 'page',
				'status'      => 'publish',
				'url'         => is_string( $permalink ) ? $permalink : '',
				'fields'      => is_array( $metadata['fields'] ?? null ) ? array_values( $metadata['fields'] ) : array(),
				'render_mode' => 'public',
			),
		);
	}

	/** Discard a plugin-owned preview without touching the published parent. */
	public static function discard( int $post_id, string $workspace_id = '' ): bool|WP_Error {
		$workspace = self::load_owned_workspace( $post_id, true );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}
		if ( ! is_array( $workspace ) ) {
			return true;
		}
		if ( '' !== $workspace_id && $workspace_id !== (string) ( $workspace['metadata']['workspace_id'] ?? '' ) ) {
			return new WP_Error(
				'sd_ai_agent_preview_target_mismatch',
				__( 'The page preview workspace does not match the requested discard operation.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}
		$deleted = false !== wp_delete_post_revision( (int) $workspace['autosave']->ID );
		if ( $deleted ) {
			self::release_context_scope( $post_id );
		}
		return $deleted;
	}

	/**
	 * Enforce the intentionally narrow one-published-page-per-run scope.
	 *
	 * @return true|WP_Error
	 */
	private static function claim_context_scope( int $post_id ): true|WP_Error {
		$key      = self::context_lock_key();
		$existing = '' !== $key ? (int) get_transient( $key ) : 0;
		if ( $existing > 0 && $existing !== $post_id ) {
			return new WP_Error(
				'sd_ai_agent_preview_scope_conflict',
				__( 'Preview-first repair is limited to one existing published page per agent run. Finish or discard the current page preview before editing another page.', 'superdav-ai-agent' ),
				array(
					'status'         => 409,
					'active_post_id' => $existing,
				)
			);
		}
		if ( '' !== $key && $existing <= 0 ) {
			set_transient( $key, $post_id, DAY_IN_SECONDS );
		}
		return true;
	}

	/** Release this context's page scope after commit or discard. */
	private static function release_context_scope( int $post_id ): void {
		$key = self::context_lock_key();
		if ( '' !== $key && $post_id === (int) get_transient( $key ) ) {
			delete_transient( $key );
		}
	}

	private static function context_lock_key(): string {
		$context = self::context_id();
		return '' === $context ? '' : self::CONTEXT_LOCK_PREFIX . substr( hash( 'sha256', $context ), 0, 32 );
	}

	/**
	 * Load the current user's autosave and verify ownership metadata.
	 *
	 * @param int  $post_id            Parent page ID.
	 * @param bool $conflict_on_foreign Whether an unrelated autosave is an error.
	 * @return array{parent:WP_Post,autosave:WP_Post,metadata:array<string,mixed>}|WP_Error|null
	 */
	private static function load_owned_workspace( int $post_id, bool $conflict_on_foreign ) {
		$parent = get_post( $post_id );
		if ( ! ( $parent instanceof WP_Post ) || ! self::governs( $parent ) ) {
			return null;
		}

		$autosave = wp_get_post_autosave( $post_id, get_current_user_id() );
		if ( ! ( $autosave instanceof WP_Post ) ) {
			return null;
		}
		$metadata = get_metadata( 'post', $autosave->ID, self::META_KEY, true );
		$owned    = is_array( $metadata )
			&& self::FORMAT_VERSION === (int) ( $metadata['format_version'] ?? 0 )
			&& self::context_id() === (string) ( $metadata['context_id'] ?? '' )
			&& get_current_user_id() === (int) ( $metadata['user_id'] ?? 0 )
			&& $post_id === (int) ( $metadata['parent_id'] ?? 0 );
		if ( ! $owned ) {
			return $conflict_on_foreign
				? new WP_Error(
					'sd_ai_agent_preview_autosave_conflict',
					__( 'This page already has an unrelated autosave. Preserve or resolve that editor draft before starting an AI preview.', 'superdav-ai-agent' ),
					array( 'status' => 409 )
				)
				: null;
		}

		$normalized_metadata = array();
		foreach ( $metadata as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized_metadata[ $key ] = $value;
			}
		}

		return array(
			'parent'   => $parent,
			'autosave' => $autosave,
			'metadata' => $normalized_metadata,
		);
	}

	/**
	 * Detect live-parent or autosave changes made outside this workspace.
	 *
	 * @param array{parent:WP_Post,autosave:WP_Post,metadata:array<string,mixed>} $workspace Workspace record.
	 * @return true|WP_Error
	 */
	private static function verify_workspace_integrity( array $workspace ): true|WP_Error {
		$metadata = $workspace['metadata'];
		$parent   = $workspace['parent'];
		$autosave = $workspace['autosave'];
		if ( (string) ( $metadata['base_fingerprint'] ?? '' ) !== self::parent_fingerprint( $parent ) ) {
			return new WP_Error(
				'stale_preview_parent',
				__( 'The published page changed after this preview began. The preview was not published; re-fetch the live page before retrying.', 'superdav-ai-agent' ),
				array( 'status' => 412 )
			);
		}

		$current_hash = self::working_hash(
			$autosave->post_title,
			$autosave->post_content,
			$autosave->post_excerpt,
			(int) ( $metadata['featured_image_id'] ?? 0 )
		);
		if ( (string) ( $metadata['working_hash'] ?? '' ) !== $current_hash ) {
			return new WP_Error(
				'sd_ai_agent_preview_autosave_changed',
				__( 'The page autosave changed outside this AI preview. The published page remains untouched.', 'superdav-ai-agent' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * Build a nonce-free descriptor for browser-side core REST resolution.
	 *
	 * @param WP_Post             $page        Published parent page.
	 * @param int                 $autosave_id Autosave revision ID.
	 * @param array<string,mixed> $metadata    Workspace metadata.
	 * @return array<string,mixed>
	 */
	private static function build_descriptor( WP_Post $page, int $autosave_id, array $metadata ): array {
		$rest_base = rest_get_route_for_post_type_items( 'page' );
		if ( '' === $rest_base ) {
			$rest_base = '/wp/v2/pages';
		}
		$permalink = get_permalink( $page );

		return array(
			'render_mode'       => 'preview',
			'workspace_id'      => (string) $metadata['workspace_id'],
			'autosave_id'       => $autosave_id,
			'base_revision_id'  => isset( $metadata['base_revision_id'] ) ? (int) $metadata['base_revision_id'] : 0,
			'generation'        => (int) $metadata['generation'],
			'working_hash'      => (string) $metadata['working_hash'],
			'preview_rest_path' => untrailingslashit( $rest_base ) . '/' . $page->ID . '/autosaves/' . $autosave_id . '?context=edit',
			'featured_image_id' => (int) ( $metadata['featured_image_id'] ?? 0 ),
			'canonical_url'     => is_string( $permalink ) ? $permalink : '',
		);
	}

	private static function context_id(): string {
		if ( self::$session_id > 0 ) {
			return 'session:' . self::$session_id;
		}
		return '' !== self::$job_id ? 'job:' . self::$job_id : '';
	}

	private static function parent_fingerprint( WP_Post $post ): string {
		$payload = wp_json_encode(
			array(
				'ID'             => $post->ID,
				'post_title'     => $post->post_title,
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_status'    => $post->post_status,
				'post_type'      => $post->post_type,
				'featured_image' => (int) get_post_thumbnail_id( $post->ID ),
			)
		);
		return hash( 'sha256', is_string( $payload ) ? $payload : '' );
	}

	/** Whether WordPress can render an attachment as featured media. */
	private static function is_previewable_image( int $attachment_id ): bool {
		return '' !== (string) wp_get_attachment_image( $attachment_id, 'thumbnail' );
	}

	private static function working_hash( string $title, string $content, string $excerpt, int $featured_image_id ): string {
		$payload = wp_json_encode(
			array(
				'post_title'        => $title,
				'post_content'      => $content,
				'post_excerpt'      => $excerpt,
				'featured_image_id' => $featured_image_id,
			)
		);
		return hash( 'sha256', is_string( $payload ) ? $payload : '' );
	}
}
