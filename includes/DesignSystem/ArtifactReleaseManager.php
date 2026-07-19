<?php

declare(strict_types=1);
/**
 * Transactional materialization and rollback for generated design artifacts.
 *
 * @package SdAiAgent\DesignSystem
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\DesignSystem;

use Closure;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stages generated payloads outside WordPress discovery paths before activation.
 *
 * @phpstan-type ArtifactFileTarget array{content:string,hash:string,artifact_id:string}
 * @phpstan-type ArtifactRecordTarget array{id:string,post_type:string,post_title:string,post_excerpt:string,post_name:string,post_status:string,post_content:string,artifact_id:string,record_key:string}
 * @phpstan-type MaterializationTargets array{files:array<string,ArtifactFileTarget>,records:array<string,ArtifactRecordTarget>}
 */
final class ArtifactReleaseManager {

	/**
	 * Hidden theme-relative storage. WordPress does not auto-discover this path.
	 */
	private const STORAGE_DIRECTORY = '.sd-ai-agent/design-artifacts';

	/**
	 * Registry file holding known, but not necessarily active, artifact versions.
	 */
	private const MANIFEST_FILE = 'manifest.json';

	/**
	 * Pointer changed only after every materialization write succeeds.
	 */
	private const ACTIVE_FILE = 'active.json';

	/**
	 * Immutable source release directory.
	 */
	private const RELEASES_DIRECTORY = 'releases';

	/**
	 * Completed transaction snapshot directory.
	 */
	private const TRANSACTIONS_DIRECTORY = 'transactions';

	/**
	 * Ownership marker for records created by this manager only.
	 */
	private const RECORD_META_KEY = '_sd_ai_agent_design_artifact_record';

	/**
	 * Logical artifact marker for auditability and safe cleanup.
	 */
	private const ARTIFACT_META_KEY = '_sd_ai_agent_design_artifact_id';

	/**
	 * Pure resolver used before any mutation begins.
	 *
	 * @var ArtifactSelector
	 */
	private ArtifactSelector $selector;

	/**
	 * Optional test-only fault injector invoked after every materialization write.
	 *
	 * @var Closure|null
	 */
	private ?Closure $failure_injector;

	/**
	 * @param ArtifactSelector|null $selector         Resolver dependency.
	 * @param callable|null         $failure_injector Optional fault injector for transaction tests.
	 */
	public function __construct( ?ArtifactSelector $selector = null, ?callable $failure_injector = null ) {
		$this->selector         = $selector ?? new ArtifactSelector();
		$this->failure_injector = null === $failure_injector ? null : Closure::fromCallable( $failure_injector );
	}

	/**
	 * Seed a generated theme with a valid empty v1 registry without design defaults.
	 *
	 * Existing valid registries are retained. An invalid pre-existing registry is
	 * not overwritten because it may contain recovery evidence for a prior release.
	 *
	 * @return array<string,mixed>|WP_Error Empty or existing validated manifest.
	 */
	public function seed_empty_manifest( string $theme_dir, string $stylesheet ): array|WP_Error {
		$storage = $this->ensure_storage( $theme_dir );
		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$path = $this->manifest_path( $theme_dir );
		$raw  = $this->read_optional_json( $path );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		if ( null !== $raw ) {
			return ArtifactManifest::normalize( $raw );
		}

		$manifest = ArtifactManifest::empty_manifest( $stylesheet );
		$written  = $this->write_json_atomic( $path, $manifest );

		return is_wp_error( $written ) ? $written : $manifest;
	}

	/**
	 * List the registry and retained release IDs for a generated theme.
	 *
	 * @return array<string,mixed>|WP_Error Registry details or an error.
	 */
	public function list( string $theme_dir, string $stylesheet = '' ): array|WP_Error {
		$manifest = $this->load_manifest( $theme_dir, $stylesheet );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$active = $this->read_optional_json( $this->active_path( $theme_dir ) );
		if ( is_wp_error( $active ) ) {
			return $active;
		}

		$release_directory = $this->storage_path( $theme_dir, self::RELEASES_DIRECTORY );
		$releases          = [];
		if ( is_dir( $release_directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Listing a hidden local release registry that is not exposed publicly.
			$entries = scandir( $release_directory );
			if ( false !== $entries ) {
				foreach ( $entries as $entry ) {
					if ( '.' === $entry || '..' === $entry || ! is_dir( $release_directory . '/' . $entry ) ) {
						continue;
					}
					$releases[] = $entry;
				}
			}
		}
		sort( $releases, SORT_STRING );

		return [
			'manifest'          => $manifest,
			'active_release_id' => is_array( $active ) && isset( $active['release_id'] ) ? (string) $active['release_id'] : null,
			'releases'          => $releases,
		];
	}

	/**
	 * Inspect one logical artifact, optionally narrowed to an exact version.
	 *
	 * @return array<string,mixed>|WP_Error Artifact details or a not-found error.
	 */
	public function inspect( string $theme_dir, string $id, ?string $version = null, string $stylesheet = '' ): array|WP_Error {
		$manifest = $this->load_manifest( $theme_dir, $stylesheet );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$matches = [];
		foreach ( $manifest['artifacts'] as $artifact ) {
			if ( $id === $artifact['id'] && ( null === $version || $version === $artifact['version'] ) ) {
				$matches[] = $artifact;
			}
		}

		if ( [] === $matches ) {
			return $this->error( 'artifact_not_found', __( 'No matching generated design artifact was found.', 'superdav-ai-agent' ) );
		}

		return [
			'id'        => $id,
			'artifacts' => $matches,
		];
	}

	/**
	 * Resolve a manifest without changing its active pointer.
	 *
	 * @param string              $theme_dir  Theme directory.
	 * @param array<string,mixed> $context    Selection context.
	 * @param string              $stylesheet Theme stylesheet slug.
	 * @return array<string,mixed>|WP_Error Selection result or an error.
	 */
	public function resolve( string $theme_dir, array $context = [], string $stylesheet = '' ): array|WP_Error {
		$manifest = $this->load_manifest( $theme_dir, $stylesheet );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$active = $this->read_optional_json( $this->active_path( $theme_dir ) );
		if ( is_wp_error( $active ) ) {
			return $active;
		}

		if ( ! isset( $context['current_selection'] ) && is_array( $active ) && isset( $active['selected'] ) && is_array( $active['selected'] ) ) {
			$context['current_selection'] = $active['selected'];
		}

		return $this->selector->resolve( $manifest, $context );
	}

	/**
	 * Stage, validate, materialize, and activate a selected release transactionally.
	 *
	 * @param string              $theme_dir Theme directory.
	 * @param array<string,mixed> $manifest  Input schema-v1 registry.
	 * @param array<string,mixed> $context   Selection context.
	 * @return array<string,mixed>|WP_Error Release result or an error.
	 */
	public function apply( string $theme_dir, array $manifest, array $context = [] ): array|WP_Error {
		$normalized = ArtifactManifest::normalize( $manifest );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$storage = $this->ensure_storage( $theme_dir );
		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$active = $this->read_optional_json( $this->active_path( $theme_dir ) );
		if ( is_wp_error( $active ) ) {
			return $active;
		}
		if ( ! isset( $context['current_selection'] ) && is_array( $active ) && isset( $active['selected'] ) && is_array( $active['selected'] ) ) {
			$context['current_selection'] = $active['selected'];
		}

		$selection = $this->selector->resolve( $normalized, $context );
		if ( is_wp_error( $selection ) ) {
			return $selection;
		}

		$selected = $this->artifact_list( $selection['selected'] ?? null );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$theme = $normalized['theme'] ?? [];
		if ( ! is_array( $theme ) ) {
			return $this->error( 'invalid_manifest_theme', __( 'Generated-artifact manifest theme metadata is invalid.', 'superdav-ai-agent' ) );
		}
		$release = $this->build_release( $selected, $theme );
		if ( is_wp_error( $release ) ) {
			return $release;
		}

		return $this->activate_release( $theme_dir, $release, $active, $normalized, $selection );
	}

	/**
	 * Restore exactly one retained release after re-verifying all source hashes.
	 *
	 * @return array<string,mixed>|WP_Error Rollback result or an error.
	 */
	public function rollback( string $theme_dir, string $release_id ): array|WP_Error {
		if ( ! $this->is_safe_release_id( $release_id ) ) {
			return $this->error( 'invalid_release_id', __( 'Release ID is invalid.', 'superdav-ai-agent' ) );
		}

		$release = $this->read_optional_json( $this->release_path( $theme_dir, $release_id ) );
		if ( is_wp_error( $release ) ) {
			return $release;
		}
		if ( null === $release ) {
			return $this->error( 'release_not_found', __( 'The requested generated-artifact release is not retained.', 'superdav-ai-agent' ) );
		}

		$validated = $this->validate_retained_release( $release );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$storage = $this->ensure_storage( $theme_dir );
		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$active = $this->read_optional_json( $this->active_path( $theme_dir ) );
		if ( is_wp_error( $active ) ) {
			return $active;
		}

		return $this->activate_release(
			$theme_dir,
			$validated,
			$active,
			null,
			[
				'selected' => $validated['artifacts'],
				'trace'    => [
					[
						'decision' => 'rollback',
						'reason'   => 'exact_retained_release',
						'release'  => $release_id,
					],
				],
			]
		);
	}

	/**
	 * Run one release under a per-theme lock and compensate every completed mutation on error.
	 *
	 * @param string                   $theme_dir Theme directory.
	 * @param array<string,mixed>      $release   Validated release source.
	 * @param array<string,mixed>|null $active    Existing active pointer.
	 * @param array<string,mixed>|null $manifest  Registry to stage before activation.
	 * @param array<string,mixed>      $selection Resolver trace.
	 * @return array<string,mixed>|WP_Error Release result or an error.
	 */
	private function activate_release( string $theme_dir, array $release, ?array $active, ?array $manifest, array $selection ): array|WP_Error {
		$release_id = $release['release_id'] ?? null;
		if ( ! is_string( $release_id ) || ! $this->is_safe_release_id( $release_id ) ) {
			return $this->error( 'invalid_retained_release', __( 'Generated-artifact release metadata is invalid.', 'superdav-ai-agent' ) );
		}
		$artifacts = $this->artifact_list( $release['artifacts'] ?? null );
		if ( is_wp_error( $artifacts ) ) {
			return $artifacts;
		}

		$lock = $this->acquire_lock( $theme_dir );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$current_active = $this->read_optional_json( $this->active_path( $theme_dir ) );
			if ( is_wp_error( $current_active ) ) {
				return $current_active;
			}
			if ( is_array( $current_active ) ) {
				$active = $current_active;
			}

			if ( is_array( $active ) && ( $active['release_id'] ?? null ) === $release_id ) {
				return [
					'release_id' => $release_id,
					'no_op'      => true,
					'selected'   => $artifacts,
					'trace'      => $selection['trace'] ?? [],
				];
			}

			$targets = $this->materialization_targets( $artifacts );
			if ( is_wp_error( $targets ) ) {
				return $targets;
			}

			$ownership = $this->verify_active_ownership( $theme_dir, $active );
			if ( is_wp_error( $ownership ) ) {
				return $ownership;
			}
			$target_ownership = $this->verify_target_ownership( $theme_dir, $targets, $active );
			if ( is_wp_error( $target_ownership ) ) {
				return $target_ownership;
			}

			$snapshot = $this->snapshot( $theme_dir, $targets, $active );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}

			$staged = $this->stage_release( $theme_dir, $release, $snapshot, $manifest );
			if ( is_wp_error( $staged ) ) {
				return $staged;
			}

			$completed = [];
			$pointer   = $this->materialize( $theme_dir, $targets, $active, $completed );
			if ( is_wp_error( $pointer ) ) {
				return $this->compensate( $theme_dir, $pointer, $snapshot, $active, $completed );
			}

			$pointer['schema_version'] = ArtifactManifest::SCHEMA_VERSION;
			$pointer['release_id']     = $release_id;
			$pointer['selected']       = $this->selection_map( $artifacts );

			$activated = $this->write_json_atomic( $this->active_path( $theme_dir ), $pointer );
			if ( is_wp_error( $activated ) ) {
				return $this->compensate( $theme_dir, $activated, $snapshot, $active, $completed );
			}

			return [
				'release_id' => $release_id,
				'no_op'      => false,
				'selected'   => $artifacts,
				'trace'      => $selection['trace'] ?? [],
				'pointer'    => $pointer,
			];
		} finally {
			$this->release_lock( $lock );
		}
	}

	/**
	 * Build a content-addressed release source whose ID is idempotent for identical content.
	 *
	 * @param list<array<string,mixed>> $artifacts Selected artifacts.
	 * @param array<string,mixed>       $theme     Theme descriptor.
	 * @return array<string,mixed> Immutable release source.
	 */
	private function build_release( array $artifacts, array $theme ): array|WP_Error {
		$identity = [];
		foreach ( $artifacts as $artifact ) {
			$id        = $artifact['id'] ?? null;
			$version   = $artifact['version'] ?? null;
			$integrity = $artifact['integrity'] ?? null;
			$hash      = is_array( $integrity ) ? ( $integrity['content_hash'] ?? null ) : null;
			if ( ! is_string( $id ) || ! is_string( $version ) || ! is_string( $hash ) ) {
				return $this->error( 'invalid_artifact', __( 'Generated-artifact release entries are invalid.', 'superdav-ai-agent' ) );
			}
			$identity[] = [
				'id'      => $id,
				'version' => $version,
				'hash'    => $hash,
			];
		}

		$hash = ArtifactManifest::hash_payload(
			[
				'theme'     => $theme,
				'artifacts' => $identity,
			]
		);
		if ( is_wp_error( $hash ) ) {
			return $hash;
		}
		$release_id = 'release-' . substr( $hash, 0, 20 );

		return [
			'schema_version'  => ArtifactManifest::SCHEMA_VERSION,
			'release_id'      => $release_id,
			'theme'           => $theme,
			'artifacts'       => $artifacts,
			'artifact_hashes' => array_column( $identity, 'hash', 'id' ),
		];
	}

	/**
	 * Verify an immutable release source and its retained content hashes.
	 *
	 * @param array<string,mixed> $release Raw retained release.
	 * @return array<string,mixed>|WP_Error Validated release or error.
	 */
	private function validate_retained_release( array $release ): array|WP_Error {
		if ( ! isset( $release['release_id'], $release['artifacts'] ) || ! is_string( $release['release_id'] ) || ! is_array( $release['artifacts'] ) || ! array_is_list( $release['artifacts'] ) ) {
			return $this->error( 'invalid_retained_release', __( 'Retained release metadata is invalid.', 'superdav-ai-agent' ) );
		}

		$manifest = ArtifactManifest::normalize(
			[
				'schema_version' => ArtifactManifest::SCHEMA_VERSION,
				'theme'          => is_array( $release['theme'] ?? null ) ? $release['theme'] : [],
				'artifacts'      => $release['artifacts'],
			]
		);
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$expected_hashes = $release['artifact_hashes'] ?? [];
		if ( ! is_array( $expected_hashes ) ) {
			return $this->error( 'invalid_retained_hashes', __( 'Retained release hashes are invalid.', 'superdav-ai-agent' ) );
		}
		$artifacts = $this->artifact_list( $manifest['artifacts'] ?? null );
		if ( is_wp_error( $artifacts ) ) {
			return $artifacts;
		}
		foreach ( $artifacts as $artifact ) {
			$id        = $artifact['id'] ?? null;
			$integrity = $artifact['integrity'] ?? null;
			$hash      = is_array( $integrity ) ? ( $integrity['content_hash'] ?? null ) : null;
			if ( ! is_string( $id ) || ! is_string( $hash ) || ! isset( $expected_hashes[ $id ] ) || ! is_string( $expected_hashes[ $id ] ) || ! hash_equals( $hash, $expected_hashes[ $id ] ) ) {
				return $this->error( 'retained_hash_mismatch', __( 'Retained release content no longer matches its recorded hash.', 'superdav-ai-agent' ) );
			}
		}

		$release['artifacts'] = $artifacts;
		$release['theme']     = $manifest['theme'];

		return $release;
	}

	/**
	 * Re-check JSON-decoded artifact entries before they reach mutation code.
	 *
	 * @param mixed $artifacts Candidate artifact list.
	 * @return list<array<string,mixed>>|WP_Error Validated list or an error.
	 */
	private function artifact_list( mixed $artifacts ): array|WP_Error {
		if ( ! is_array( $artifacts ) || ! array_is_list( $artifacts ) ) {
			return $this->error( 'invalid_artifact_list', __( 'Generated-artifact releases must contain an artifact list.', 'superdav-ai-agent' ) );
		}

		$result = [];
		foreach ( $artifacts as $artifact ) {
			if ( ! is_array( $artifact ) ) {
				return $this->error( 'invalid_artifact', __( 'Generated-artifact release entries must be objects.', 'superdav-ai-agent' ) );
			}
			$normalized = [];
			foreach ( $artifact as $key => $value ) {
				if ( ! is_string( $key ) ) {
					return $this->error( 'invalid_artifact', __( 'Generated-artifact release properties must use string keys.', 'superdav-ai-agent' ) );
				}
				$normalized[ $key ] = $value;
			}
			$result[] = $normalized;
		}

		return $result;
	}

	/**
	 * Convert selected payloads to allowed WordPress file and record write targets.
	 *
	 * @param list<array<string,mixed>> $artifacts Selected artifacts.
	 * @return MaterializationTargets|WP_Error Targets or error.
	 */
	private function materialization_targets( array $artifacts ): array|WP_Error {
		$files   = [];
		$records = [];
		foreach ( $artifacts as $artifact ) {
			$kind        = $artifact['kind'] ?? null;
			$artifact_id = $artifact['id'] ?? null;
			$payload     = $artifact['payload'] ?? null;
			if ( ! is_string( $kind ) || ! is_string( $artifact_id ) || ! is_array( $payload ) ) {
				return $this->error( 'invalid_artifact', __( 'Generated-artifact release entries are invalid.', 'superdav-ai-agent' ) );
			}
			$payload_files   = $payload['files'] ?? null;
			$payload_records = $payload['records'] ?? null;
			if ( ! is_array( $payload_files ) || ! array_is_list( $payload_files ) || ! is_array( $payload_records ) || ! array_is_list( $payload_records ) ) {
				return $this->error( 'invalid_payload_targets', __( 'Generated-artifact payload targets are invalid.', 'superdav-ai-agent' ) );
			}

			foreach ( $payload_files as $file ) {
				$file_path    = is_array( $file ) ? ( $file['path'] ?? null ) : null;
				$file_content = is_array( $file ) ? ( $file['content'] ?? null ) : null;
				if ( ! is_string( $file_path ) || ! is_string( $file_content ) ) {
					return $this->error( 'invalid_payload_file', __( 'Generated-artifact file entries are invalid.', 'superdav-ai-agent' ) );
				}
				$path = $this->normalize_relative_path( $file_path );
				if ( is_wp_error( $path ) ) {
					return $path;
				}
				if ( ! $this->is_allowed_file_target( $kind, $path ) ) {
					return $this->error( 'invalid_file_target', __( 'Artifact tried to write outside its permitted generated design path.', 'superdav-ai-agent' ) );
				}
				if ( isset( $files[ $path ] ) ) {
					return $this->error( 'duplicate_file_target', __( 'Two selected artifacts target the same generated file.', 'superdav-ai-agent' ) );
				}

				$files[ $path ] = [
					'content'     => $file_content,
					'hash'        => hash( 'sha256', $file_content ),
					'artifact_id' => $artifact_id,
				];
			}

			foreach ( $payload_records as $record ) {
				$record_id    = is_array( $record ) ? ( $record['id'] ?? null ) : null;
				$post_type    = is_array( $record ) ? ( $record['post_type'] ?? null ) : null;
				$post_content = is_array( $record ) ? ( $record['post_content'] ?? null ) : null;
				$post_title   = is_array( $record ) && isset( $record['post_title'] ) ? $record['post_title'] : '';
				$post_excerpt = is_array( $record ) && isset( $record['post_excerpt'] ) ? $record['post_excerpt'] : '';
				$post_name    = is_array( $record ) && isset( $record['post_name'] ) ? $record['post_name'] : '';
				$post_status  = is_array( $record ) && isset( $record['post_status'] ) ? $record['post_status'] : 'publish';
				if ( ! is_string( $record_id ) || ! is_string( $post_type ) || ! is_string( $post_content ) || ! is_string( $post_title ) || ! is_string( $post_excerpt ) || ! is_string( $post_name ) || ! is_string( $post_status ) || ! $this->is_allowed_record_target( $kind, $post_type ) || ! $this->is_safe_record_id( $record_id ) ) {
					return $this->error( 'invalid_record_target', __( 'Artifact tried to write an unsupported WordPress record.', 'superdav-ai-agent' ) );
				}

				$key = $artifact_id . ':' . $record_id;
				if ( isset( $records[ $key ] ) ) {
					return $this->error( 'duplicate_record_target', __( 'Two selected artifacts target the same generated WordPress record.', 'superdav-ai-agent' ) );
				}

				$records[ $key ] = [
					'id'           => $record_id,
					'post_type'    => $post_type,
					'post_title'   => $post_title,
					'post_excerpt' => $post_excerpt,
					'post_name'    => $post_name,
					'post_status'  => $post_status,
					'post_content' => $post_content,
					'artifact_id'  => $artifact_id,
					'record_key'   => $key,
				];
			}
		}
		ksort( $files, SORT_STRING );
		ksort( $records, SORT_STRING );

		return [
			'files'   => $files,
			'records' => $records,
		];
	}

	/**
	 * Confirm no managed target was manually changed since the active release.
	 *
	 * @param string                   $theme_dir Theme directory.
	 * @param array<string,mixed>|null $active Active pointer.
	 * @return true|WP_Error True or an ownership conflict.
	 */
	private function verify_active_ownership( string $theme_dir, ?array $active ): true|WP_Error {
		if ( ! is_array( $active ) ) {
			return true;
		}

		$files = $active['files'] ?? [];
		if ( is_array( $files ) ) {
			foreach ( $files as $path => $descriptor ) {
				if ( ! is_string( $path ) || ! is_array( $descriptor ) || ! isset( $descriptor['hash'] ) || ! is_string( $descriptor['hash'] ) ) {
					return $this->error( 'invalid_active_pointer', __( 'Active generated-artifact pointer is invalid.', 'superdav-ai-agent' ) );
				}
				$absolute = $this->absolute_path( $theme_dir, $path );
				if ( is_wp_error( $absolute ) ) {
					return $absolute;
				}
				if ( ! is_file( $absolute ) ) {
					return $this->error( 'managed_file_missing', __( 'A managed generated file was removed outside its release transaction.', 'superdav-ai-agent' ) );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Comparing an owned local release target before a guarded mutation.
				$content = file_get_contents( $absolute );
				if ( false === $content || ! hash_equals( $descriptor['hash'], hash( 'sha256', $content ) ) ) {
					return $this->error( 'managed_file_modified', __( 'A managed generated file was modified outside its release transaction.', 'superdav-ai-agent' ) );
				}
			}
		}

		$records = $active['records'] ?? [];
		if ( is_array( $records ) ) {
			foreach ( $records as $key => $descriptor ) {
				if ( ! is_string( $key ) || ! is_array( $descriptor ) || ! isset( $descriptor['hash'] ) || ! is_string( $descriptor['hash'] ) ) {
					return $this->error( 'invalid_active_pointer', __( 'Active generated-artifact pointer is invalid.', 'superdav-ai-agent' ) );
				}
				$record = $this->find_record( $key );
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				if ( ! $record instanceof WP_Post || ! hash_equals( $descriptor['hash'], $this->record_hash( $record ) ) ) {
					return $this->error( 'managed_record_modified', __( 'A managed generated record was changed outside its release transaction.', 'superdav-ai-agent' ) );
				}
			}
		}

		return true;
	}

	/**
	 * Refuse to claim an existing file unless the active release already owns it.
	 *
	 * A matching allowed path alone is not proof of ownership: a site owner may
	 * have created a pattern, style variation, or theme.json manually before the
	 * generated-artifact lifecycle was enabled.
	 *
	 * @param string                   $theme_dir Theme directory.
	 * @param array                    $targets   Next materialization targets.
	 * @phpstan-param MaterializationTargets $targets
	 * @param array<string,mixed>|null $active Active pointer.
	 * @return true|WP_Error True or an unmanaged-target error.
	 */
	private function verify_target_ownership( string $theme_dir, array $targets, ?array $active ): true|WP_Error {
		$active_files = is_array( $active['files'] ?? null ) ? $active['files'] : [];
		foreach ( array_keys( $targets['files'] ) as $path ) {
			if ( isset( $active_files[ $path ] ) ) {
				continue;
			}
			$absolute = $this->absolute_path( $theme_dir, $path );
			if ( is_wp_error( $absolute ) ) {
				return $absolute;
			}
			if ( file_exists( $absolute ) || is_link( $absolute ) ) {
				return $this->error( 'unmanaged_file_conflict', __( 'A generated artifact cannot overwrite an unmanaged theme file.', 'superdav-ai-agent' ) );
			}
		}

		return true;
	}

	/**
	 * Snapshot every next and prior managed file/record before any target changes.
	 *
	 * @param string                   $theme_dir Theme directory.
	 * @param array                    $targets   Next materialization targets.
	 * @phpstan-param MaterializationTargets $targets
	 * @param array<string,mixed>|null $active Active pointer.
	 * @return array<string,mixed>|WP_Error Exact prior state or error.
	 */
	private function snapshot( string $theme_dir, array $targets, ?array $active ): array|WP_Error {
		$paths = array_unique(
			array_merge(
				array_keys( $targets['files'] ),
				is_array( $active['files'] ?? null ) ? array_keys( $active['files'] ) : []
			)
		);
		sort( $paths, SORT_STRING );

		$files = [];
		foreach ( $paths as $path ) {
			$absolute = $this->absolute_path( $theme_dir, $path );
			if ( is_wp_error( $absolute ) ) {
				return $absolute;
			}
			if ( is_dir( $absolute ) ) {
				return $this->error( 'file_target_is_directory', __( 'Generated artifact file target is a directory.', 'superdav-ai-agent' ) );
			}
			if ( ! is_file( $absolute ) ) {
				$files[ $path ] = [ 'exists' => false ];
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Capturing exact local source before a reversible managed write.
			$content = file_get_contents( $absolute );
			if ( false === $content ) {
				return $this->error( 'snapshot_file_failed', __( 'Could not snapshot a generated artifact file.', 'superdav-ai-agent' ) );
			}
			$files[ $path ] = [
				'exists'  => true,
				'content' => $content,
			];
		}

		$record_keys = array_unique(
			array_merge(
				array_keys( $targets['records'] ),
				is_array( $active['records'] ?? null ) ? array_keys( $active['records'] ) : []
			)
		);
		sort( $record_keys, SORT_STRING );
		$records = [];
		foreach ( $record_keys as $key ) {
			$record = $this->find_record( $key );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			$records[ $key ] = $record instanceof WP_Post ? $this->record_snapshot( $record ) : [ 'exists' => false ];
		}

		return [
			'files'   => $files,
			'records' => $records,
			'active'  => $active,
		];
	}

	/**
	 * Persist immutable source and a recovery snapshot before materializing any target.
	 *
	 * @param string                   $theme_dir Theme directory.
	 * @param array<string,mixed>      $release   Release source.
	 * @param array<string,mixed>      $snapshot  Prior state snapshot.
	 * @param array<string,mixed>|null $manifest  Registry staged by apply, not rollback.
	 * @return true|WP_Error True or a staging error.
	 */
	private function stage_release( string $theme_dir, array $release, array $snapshot, ?array $manifest ): true|WP_Error {
		$release_id = $release['release_id'] ?? null;
		if ( ! is_string( $release_id ) || ! $this->is_safe_release_id( $release_id ) ) {
			return $this->error( 'invalid_retained_release', __( 'Generated-artifact release metadata is invalid.', 'superdav-ai-agent' ) );
		}
		$release_path = $this->release_path( $theme_dir, $release_id );
		$existing     = $this->read_optional_json( $release_path );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( is_array( $existing ) ) {
			$existing_json = ArtifactManifest::canonical_json( $existing );
			$release_json  = ArtifactManifest::canonical_json( $release );
			if ( is_wp_error( $existing_json ) || is_wp_error( $release_json ) || $existing_json !== $release_json ) {
				return $this->error( 'release_id_collision', __( 'Release ID already belongs to different artifact content.', 'superdav-ai-agent' ) );
			}
		} else {
			$written = $this->write_json_atomic( $release_path, $release );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
		}

		if ( is_array( $manifest ) ) {
			$written = $this->write_json_atomic( $this->manifest_path( $theme_dir ), $manifest );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
		}

		$transaction_id = 'transaction-' . substr( hash( 'sha256', $release_id . microtime( true ) ), 0, 20 );
		return $this->write_json_atomic(
			$this->storage_path( $theme_dir, self::TRANSACTIONS_DIRECTORY . '/' . $transaction_id . '/snapshot.json' ),
			$snapshot
		);
	}

	/**
	 * Materialize targets and remove superseded owned targets, recording reverse actions.
	 *
	 * @param string                              $theme_dir Theme directory.
	 * @param array                               $targets   Targets to materialize.
	 * @phpstan-param MaterializationTargets $targets
	 * @param array<string,mixed>|null            $active    Active pointer.
	 * @param list<array{type:string,key:string}> $completed Completed reversible operations.
	 * @return array<string,mixed>|WP_Error New pointer payload or a mutation error.
	 */
	private function materialize( string $theme_dir, array $targets, ?array $active, array &$completed ): array|WP_Error {
		$pointer = [
			'files'   => [],
			'records' => [],
		];

		foreach ( $targets['files'] as $path => $file ) {
			$absolute = $this->absolute_path( $theme_dir, $path );
			if ( is_wp_error( $absolute ) ) {
				return $absolute;
			}
			$written = $this->write_file_atomic( $absolute, $file['content'] );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			$completed[]               = [
				'type' => 'file',
				'key'  => $path,
			];
			$pointer['files'][ $path ] = [
				'hash'        => $file['hash'],
				'artifact_id' => $file['artifact_id'],
			];
			$failure                   = $this->inject_failure( 'after_file_write', $path );
			if ( is_wp_error( $failure ) ) {
				return $failure;
			}
		}

		foreach ( $targets['records'] as $key => $record ) {
			$saved = $this->write_record( $record );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$completed[]                = [
				'type' => 'record',
				'key'  => $key,
			];
			$pointer['records'][ $key ] = $saved;
			$failure                    = $this->inject_failure( 'after_record_write', $key );
			if ( is_wp_error( $failure ) ) {
				return $failure;
			}
		}

		$old_files = is_array( $active['files'] ?? null ) ? $active['files'] : [];
		foreach ( $old_files as $path => $descriptor ) {
			if ( isset( $targets['files'][ $path ] ) ) {
				continue;
			}
			$absolute = $this->absolute_path( $theme_dir, (string) $path );
			if ( is_wp_error( $absolute ) ) {
				return $absolute;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing only an active-pointer-owned generated file after integrity verification.
			if ( is_file( $absolute ) && ! unlink( $absolute ) ) {
				return $this->error( 'remove_file_failed', __( 'Could not remove a superseded generated artifact file.', 'superdav-ai-agent' ) );
			}
			$completed[] = [
				'type' => 'file',
				'key'  => (string) $path,
			];
		}

		$old_records = is_array( $active['records'] ?? null ) ? $active['records'] : [];
		foreach ( $old_records as $key => $descriptor ) {
			if ( isset( $targets['records'][ $key ] ) ) {
				continue;
			}
			$deleted = $this->delete_record( (string) $key );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
			$completed[] = [
				'type' => 'record',
				'key'  => (string) $key,
			];
			$failure     = $this->inject_failure( 'after_record_delete', (string) $key );
			if ( is_wp_error( $failure ) ) {
				return $failure;
			}
		}

		return $pointer;
	}

	/**
	 * Restore completed mutations in reverse order and leave the old active pointer intact.
	 *
	 * @param string                              $theme_dir Theme directory.
	 * @param WP_Error                            $error     Original mutation error.
	 * @param array<string,mixed>                 $snapshot  Prior state.
	 * @param array<string,mixed>|null            $active    Prior pointer.
	 * @param list<array{type:string,key:string}> $completed Completed operations.
	 * @return WP_Error Original operation error, augmented when compensation fails.
	 */
	private function compensate( string $theme_dir, WP_Error $error, array $snapshot, ?array $active, array $completed ): WP_Error {
		$restored = [];
		foreach ( array_reverse( $completed ) as $operation ) {
			$key = $operation['type'] . ':' . $operation['key'];
			if ( isset( $restored[ $key ] ) ) {
				continue;
			}
			$restored[ $key ] = true;
			$file_snapshot    = is_array( $snapshot['files'] ?? null ) ? ( $snapshot['files'][ $operation['key'] ] ?? null ) : null;
			$record_snapshot  = is_array( $snapshot['records'] ?? null ) ? ( $snapshot['records'][ $operation['key'] ] ?? null ) : null;
			$result           = 'file' === $operation['type']
				? $this->restore_file( $theme_dir, $operation['key'], is_array( $file_snapshot ) ? $file_snapshot : null )
				: $this->restore_record( $operation['key'], is_array( $record_snapshot ) ? $record_snapshot : null );
			if ( is_wp_error( $result ) ) {
				$error->add_data( [ 'compensation_error' => $result->get_error_message() ] );
			}
		}

		$pointer_result = is_array( $active )
			? $this->write_json_atomic( $this->active_path( $theme_dir ), $active )
			: $this->remove_file( $this->active_path( $theme_dir ) );
		if ( is_wp_error( $pointer_result ) ) {
			$error->add_data( [ 'pointer_restore_error' => $pointer_result->get_error_message() ] );
		}

		return $error;
	}

	/**
	 * Create or update exactly one manager-owned record.
	 *
	 * @param array $record Normalized record target.
	 * @phpstan-param ArtifactRecordTarget $record
	 * @return array<string,mixed>|WP_Error Pointer descriptor or an error.
	 */
	private function write_record( array $record ): array|WP_Error {
		$existing = $this->find_record( $record['record_key'] );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( $existing instanceof WP_Post && $existing->post_type !== $record['post_type'] ) {
			return $this->error( 'record_type_conflict', __( 'A managed artifact record has a different WordPress post type.', 'superdav-ai-agent' ) );
		}

		$post_data = [
			'post_type'    => $record['post_type'],
			'post_title'   => $record['post_title'],
			'post_excerpt' => $record['post_excerpt'],
			'post_name'    => $record['post_name'],
			'post_status'  => $record['post_status'],
			'post_content' => $record['post_content'],
		];
		if ( $existing instanceof WP_Post ) {
			$post_data['ID'] = $existing->ID;
			$result          = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) $result;
		$meta    = update_post_meta( $post_id, self::RECORD_META_KEY, $record['record_key'] );
		if ( false === $meta && $record['record_key'] !== get_post_meta( $post_id, self::RECORD_META_KEY, true ) ) {
			return $this->error( 'record_meta_failed', __( 'Could not mark a generated artifact record as manager-owned.', 'superdav-ai-agent' ) );
		}
		update_post_meta( $post_id, self::ARTIFACT_META_KEY, $record['artifact_id'] );

		if ( 'wp_global_styles' === $record['post_type'] && taxonomy_exists( 'wp_theme' ) && function_exists( 'get_stylesheet' ) ) {
			$assigned = wp_set_object_terms( $post_id, get_stylesheet(), 'wp_theme' );
			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->error( 'record_save_failed', __( 'Could not retrieve the saved generated artifact record.', 'superdav-ai-agent' ) );
		}

		return [
			'post_id'     => $post_id,
			'post_type'   => $post->post_type,
			'artifact_id' => $record['artifact_id'],
			'hash'        => $this->record_hash( $post ),
		];
	}

	/**
	 * Delete only an ownership-marked generated record.
	 */
	private function delete_record( string $key ): true|WP_Error {
		$record = $this->find_record( $key );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		if ( ! $record instanceof WP_Post ) {
			return true;
		}
		$deleted = wp_delete_post( $record->ID, true );
		if ( ! $deleted instanceof WP_Post ) {
			return $this->error( 'record_delete_failed', __( 'Could not remove a superseded generated artifact record.', 'superdav-ai-agent' ) );
		}

		return true;
	}

	/**
	 * Find a unique manager-owned record by exact ownership key.
	 *
	 * @return WP_Post|null|WP_Error Record, null, or an ambiguity error.
	 */
	private function find_record( string $key ): WP_Post|null|WP_Error {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The exact private ownership key is indexed by the bounded generated-artifact record set.
		$posts = get_posts(
			[
				'post_type'      => [ 'wp_block', 'wp_global_styles' ],
				'post_status'    => 'any',
				'posts_per_page' => 2,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The exact private ownership key is indexed by the bounded generated-artifact record set.
				'meta_key'       => self::RECORD_META_KEY,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The exact private ownership key is indexed by the bounded generated-artifact record set.
				'meta_value'     => $key,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);
		if ( count( $posts ) > 1 ) {
			return $this->error( 'record_ownership_ambiguous', __( 'More than one record claims one generated artifact ownership key.', 'superdav-ai-agent' ) );
		}

		return $posts[0] ?? null;
	}

	/**
	 * Make a reversible snapshot of an owned WordPress record.
	 *
	 * @return array<string,mixed> Record snapshot.
	 */
	private function record_snapshot( WP_Post $post ): array {
		$themes = [];
		if ( 'wp_global_styles' === $post->post_type && taxonomy_exists( 'wp_theme' ) ) {
			$terms = wp_get_object_terms( $post->ID, 'wp_theme', [ 'fields' => 'names' ] );
			if ( is_array( $terms ) ) {
				$themes = array_values( array_filter( $terms, 'is_string' ) );
			}
		}

		return [
			'exists' => true,
			'post'   => [
				'ID'           => (int) $post->ID,
				'post_type'    => $post->post_type,
				'post_title'   => $post->post_title,
				'post_excerpt' => $post->post_excerpt,
				'post_name'    => $post->post_name,
				'post_status'  => $post->post_status,
				'post_content' => $post->post_content,
			],
			'meta'   => [
				'record_key'  => (string) get_post_meta( $post->ID, self::RECORD_META_KEY, true ),
				'artifact_id' => (string) get_post_meta( $post->ID, self::ARTIFACT_META_KEY, true ),
			],
			'themes' => $themes,
		];
	}

	/**
	 * Restore a file's exact snapshot state.
	 *
	 * @param string                  $theme_dir Theme directory.
	 * @param string                  $path      Theme-relative target path.
	 * @param array<mixed,mixed>|null $snapshot Snapshot entry.
	 * @return true|WP_Error Restoration result.
	 */
	private function restore_file( string $theme_dir, string $path, ?array $snapshot ): true|WP_Error {
		$absolute = $this->absolute_path( $theme_dir, $path );
		if ( is_wp_error( $absolute ) ) {
			return $absolute;
		}
		if ( ! is_array( $snapshot ) || empty( $snapshot['exists'] ) ) {
			return $this->remove_file( $absolute );
		}
		if ( ! isset( $snapshot['content'] ) || ! is_string( $snapshot['content'] ) ) {
			return $this->error( 'invalid_file_snapshot', __( 'Generated artifact file snapshot is invalid.', 'superdav-ai-agent' ) );
		}

		return $this->write_file_atomic( $absolute, $snapshot['content'] );
	}

	/**
	 * Restore a record's exact snapshot state without touching user-owned records.
	 *
	 * @param string                  $key      Ownership key.
	 * @param array<mixed,mixed>|null $snapshot Snapshot entry.
	 * @return true|WP_Error Restoration result.
	 */
	private function restore_record( string $key, ?array $snapshot ): true|WP_Error {
		$current = $this->find_record( $key );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		if ( ! is_array( $snapshot ) || empty( $snapshot['exists'] ) ) {
			return $this->delete_record( $key );
		}
		$post = $snapshot['post'] ?? null;
		$meta = $snapshot['meta'] ?? null;
		if ( ! is_array( $post ) || ! is_array( $meta ) ) {
			return $this->error( 'invalid_record_snapshot', __( 'Generated artifact record snapshot is invalid.', 'superdav-ai-agent' ) );
		}
		$snapshot_id = $post['ID'] ?? null;
		if ( ! is_int( $snapshot_id ) || $snapshot_id < 1 ) {
			return $this->error( 'invalid_record_snapshot', __( 'Generated artifact record snapshot is invalid.', 'superdav-ai-agent' ) );
		}

		$post_type    = $post['post_type'] ?? null;
		$post_title   = $post['post_title'] ?? null;
		$post_excerpt = $post['post_excerpt'] ?? null;
		$post_name    = $post['post_name'] ?? null;
		$post_status  = $post['post_status'] ?? null;
		$post_content = $post['post_content'] ?? null;
		if ( ! is_string( $post_type ) || ! is_string( $post_title ) || ! is_string( $post_excerpt ) || ! is_string( $post_name ) || ! is_string( $post_status ) || ! is_string( $post_content ) ) {
			return $this->error( 'invalid_record_snapshot', __( 'Generated artifact record snapshot is invalid.', 'superdav-ai-agent' ) );
		}

		$post_data = [
			'post_type'    => $post_type,
			'post_title'   => $post_title,
			'post_excerpt' => $post_excerpt,
			'post_name'    => $post_name,
			'post_status'  => $post_status,
			'post_content' => $post_content,
		];
		if ( $current instanceof WP_Post ) {
			if ( $snapshot_id !== (int) $current->ID ) {
				return $this->error( 'record_restore_conflict', __( 'A generated artifact record no longer matches its recovery snapshot.', 'superdav-ai-agent' ) );
			}
			$post_data['ID'] = $current->ID;
			$result          = wp_update_post( $post_data, true );
		} else {
			$post_data['import_id'] = $snapshot_id;
			$result                 = wp_insert_post( $post_data, true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) $result;
		if ( $snapshot_id !== $post_id ) {
			return $this->error( 'record_restore_id_mismatch', __( 'A generated artifact record could not be restored with its original ID.', 'superdav-ai-agent' ) );
		}
		$record_key  = isset( $meta['record_key'] ) && is_string( $meta['record_key'] ) ? $meta['record_key'] : $key;
		$artifact_id = isset( $meta['artifact_id'] ) && is_string( $meta['artifact_id'] ) ? $meta['artifact_id'] : '';
		update_post_meta( $post_id, self::RECORD_META_KEY, $record_key );
		update_post_meta( $post_id, self::ARTIFACT_META_KEY, $artifact_id );
		if ( 'wp_global_styles' === $post_data['post_type'] && taxonomy_exists( 'wp_theme' ) && isset( $snapshot['themes'] ) && is_array( $snapshot['themes'] ) ) {
			$themes   = array_values( array_filter( $snapshot['themes'], 'is_string' ) );
			$assigned = wp_set_object_terms( $post_id, $themes, 'wp_theme' );
			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}
		}

		return true;
	}

	/**
	 * Store the release pointer's stable record hash.
	 */
	private function record_hash( WP_Post $post ): string {
		$hash = ArtifactManifest::hash_payload(
			[
				'post_type'    => $post->post_type,
				'post_title'   => $post->post_title,
				'post_excerpt' => $post->post_excerpt,
				'post_name'    => $post->post_name,
				'post_status'  => $post->post_status,
				'post_content' => $post->post_content,
			]
		);

		return is_string( $hash ) ? $hash : '';
	}

	/**
	 * Invoke the test fault injector after a completed write.
	 */
	private function inject_failure( string $step, string $target ): true|WP_Error {
		if ( null === $this->failure_injector ) {
			return true;
		}
		$result = ( $this->failure_injector )( $step, $target );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		if ( true === $result ) {
			return $this->error( 'injected_failure', __( 'Generated artifact release failed at an injected test point.', 'superdav-ai-agent' ) );
		}

		return true;
	}

	/**
	 * Load and validate the saved registry, returning an unsaved empty registry when absent.
	 *
	 * @return array<string,mixed>|WP_Error Manifest or an error.
	 */
	private function load_manifest( string $theme_dir, string $stylesheet ): array|WP_Error {
		$raw = $this->read_optional_json( $this->manifest_path( $theme_dir ) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		if ( null === $raw ) {
			return ArtifactManifest::empty_manifest( $stylesheet );
		}

		return ArtifactManifest::normalize( $raw );
	}

	/**
	 * Acquire an exclusive advisory lock for one theme's release pointer.
	 *
	 * @return resource|WP_Error Lock stream or an error.
	 */
	private function acquire_lock( string $theme_dir ) {
		$storage = $this->ensure_storage( $theme_dir );
		if ( is_wp_error( $storage ) ) {
			return $storage;
		}
		$path = $this->storage_path( $theme_dir, 'release.lock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local advisory lock serializes one theme's activation transaction.
		$lock = fopen( $path, 'c' );
		if ( false === $lock ) {
			return $this->error( 'lock_open_failed', __( 'Could not open the generated-artifact release lock.', 'superdav-ai-agent' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Local advisory lock serializes one theme's activation transaction.
		if ( ! flock( $lock, LOCK_EX ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a failed local advisory lock stream.
			fclose( $lock );
			return $this->error( 'lock_failed', __( 'Could not acquire the generated-artifact release lock.', 'superdav-ai-agent' ) );
		}

		return $lock;
	}

	/**
	 * Release a successful or failed transaction lock.
	 *
	 * @param resource $lock Lock stream.
	 */
	private function release_lock( $lock ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Releasing a local advisory lock stream.
		flock( $lock, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a local advisory lock stream.
		fclose( $lock );
	}

	/**
	 * Create hidden release storage beneath an existing theme directory.
	 */
	private function ensure_storage( string $theme_dir ): true|WP_Error {
		if ( ! is_dir( $theme_dir ) ) {
			return $this->error( 'theme_directory_missing', __( 'Generated-artifact storage requires an existing theme directory.', 'superdav-ai-agent' ) );
		}
		$storage = $this->storage_path( $theme_dir );
		if ( ! is_dir( $storage ) && ! wp_mkdir_p( $storage ) ) {
			return $this->error( 'storage_create_failed', __( 'Could not create hidden generated-artifact storage.', 'superdav-ai-agent' ) );
		}

		return true;
	}

	/**
	 * Read JSON from a local hidden registry file when it exists.
	 *
	 * @return array<string,mixed>|null|WP_Error Decoded data, null, or an error.
	 */
	private function read_optional_json( string $path ): array|null|WP_Error {
		if ( ! is_file( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local hidden manifest after path validation.
		$json = file_get_contents( $path );
		if ( false === $json ) {
			return $this->error( 'read_failed', __( 'Could not read generated-artifact metadata.', 'superdav-ai-agent' ) );
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			return $this->error( 'invalid_json', __( 'Generated-artifact metadata is not valid JSON.', 'superdav-ai-agent' ) );
		}
		$data = [];
		foreach ( $decoded as $key => $value ) {
			if ( ! is_string( $key ) ) {
				return $this->error( 'invalid_json', __( 'Generated-artifact metadata must be a JSON object.', 'superdav-ai-agent' ) );
			}
			$data[ $key ] = $value;
		}

		return $data;
	}

	/**
	 * Encode and atomically write JSON to hidden storage.
	 *
	 * @param string              $path JSON destination path.
	 * @param array<string,mixed> $data JSON data.
	 * @return true|WP_Error Write result.
	 */
	private function write_json_atomic( string $path, array $data ): true|WP_Error {
		$json = ArtifactManifest::canonical_json( $data );
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		return $this->write_file_atomic( $path, $json );
	}

	/**
	 * Atomic local write with a same-directory temporary file and rename.
	 *
	 * @return true|WP_Error Write result.
	 */
	private function write_file_atomic( string $path, string $content ): true|WP_Error {
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return $this->error( 'directory_create_failed', __( 'Could not create a generated-artifact directory.', 'superdav-ai-agent' ) );
		}
		$temp = $path . '.tmp-' . substr( hash( 'sha256', uniqid( '', true ) ), 0, 12 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same-directory temporary write preserves atomic activation semantics.
		$bytes = file_put_contents( $temp, $content );
		if ( false === $bytes || strlen( $content ) !== $bytes ) {
			if ( is_file( $temp ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleaning up an uncommitted same-directory temporary write.
				unlink( $temp );
			}
			return $this->error( 'write_failed', __( 'Could not stage a generated-artifact file.', 'superdav-ai-agent' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-filesystem replacement of a managed release target.
		if ( ! rename( $temp, $path ) ) {
			if ( is_file( $temp ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleaning up an uncommitted same-directory temporary write.
				unlink( $temp );
			}
			return $this->error( 'activate_write_failed', __( 'Could not activate a generated-artifact file.', 'superdav-ai-agent' ) );
		}

		return true;
	}

	/**
	 * Remove a local file only when it exists.
	 *
	 * @return true|WP_Error Removal result.
	 */
	private function remove_file( string $path ): true|WP_Error {
		if ( ! is_file( $path ) ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing a manager-owned hidden pointer or a snapshotted managed target.
		if ( ! unlink( $path ) ) {
			return $this->error( 'remove_failed', __( 'Could not remove generated-artifact metadata.', 'superdav-ai-agent' ) );
		}

		return true;
	}

	/**
	 * Resolve a normalized and traversal-safe theme-relative target path.
	 *
	 * @return string|WP_Error Relative path or an error.
	 */
	private function normalize_relative_path( string $path ): string|WP_Error {
		$path = str_replace( '\\', '/', $path );
		if ( '' === $path || str_starts_with( $path, '/' ) || str_contains( $path, "\0" ) || str_contains( $path, '//' ) ) {
			return $this->error( 'unsafe_path', __( 'Generated artifact paths must be safe theme-relative paths.', 'superdav-ai-agent' ) );
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return $this->error( 'unsafe_path', __( 'Generated artifact paths must be safe theme-relative paths.', 'superdav-ai-agent' ) );
			}
		}

		return $path;
	}

	/**
	 * Convert a verified relative path into an absolute theme path.
	 *
	 * @return string|WP_Error Absolute path or an error.
	 */
	private function absolute_path( string $theme_dir, string $relative_path ): string|WP_Error {
		$relative = $this->normalize_relative_path( $relative_path );
		if ( is_wp_error( $relative ) ) {
			return $relative;
		}

		return rtrim( $theme_dir, '/\\' ) . '/' . $relative;
	}

	/**
	 * Check artifact-specific auto-discovery targets.
	 */
	private function is_allowed_file_target( string $kind, string $path ): bool {
		return match ( $kind ) {
			'token_set'       => 'theme.json' === $path,
			'pattern'         => str_starts_with( $path, 'patterns/' ) && str_ends_with( $path, '.php' ),
			'style_variation' => str_starts_with( $path, 'styles/' ) && str_ends_with( $path, '.json' ),
			default           => false,
		};
	}

	/**
	 * Check that only generated block/global-style records can be materialized.
	 */
	private function is_allowed_record_target( string $kind, string $post_type ): bool {
		if ( 'pattern' === $kind ) {
			return 'wp_block' === $post_type;
		}

		return in_array( $post_type, [ 'wp_global_styles', 'wp_block' ], true );
	}

	/**
	 * Check path-safe producer record IDs.
	 */
	private function is_safe_record_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $id );
	}

	/**
	 * Check content-addressed release IDs used in hidden paths.
	 */
	private function is_safe_release_id( string $release_id ): bool {
		return 1 === preg_match( '/^release-[a-f0-9]{20}$/', $release_id );
	}

	/**
	 * Build a stable active-pointer selection map.
	 *
	 * @param list<array<string,mixed>> $artifacts Selected artifacts.
	 * @return array<string,array<string,string>> Selection map.
	 */
	private function selection_map( array $artifacts ): array {
		$selected = [];
		foreach ( $artifacts as $artifact ) {
			$id        = $artifact['id'] ?? null;
			$version   = $artifact['version'] ?? null;
			$maturity  = $artifact['maturity'] ?? null;
			$integrity = $artifact['integrity'] ?? null;
			$hash      = is_array( $integrity ) ? ( $integrity['content_hash'] ?? null ) : null;
			if ( ! is_string( $id ) || ! is_string( $version ) || ! is_string( $maturity ) || ! is_string( $hash ) ) {
				continue;
			}
			$selected[ $id ] = [
				'version'      => $version,
				'content_hash' => $hash,
				'maturity'     => $maturity,
			];
		}
		ksort( $selected, SORT_STRING );

		return $selected;
	}

	/**
	 * Return a path inside hidden design-artifact storage.
	 */
	private function storage_path( string $theme_dir, string $suffix = '' ): string {
		$base = rtrim( $theme_dir, '/\\' ) . '/' . self::STORAGE_DIRECTORY;
		return '' === $suffix ? $base : $base . '/' . ltrim( $suffix, '/' );
	}

	/**
	 * Return the hidden registry file path.
	 */
	private function manifest_path( string $theme_dir ): string {
		return $this->storage_path( $theme_dir, self::MANIFEST_FILE );
	}

	/**
	 * Return the hidden active pointer file path.
	 */
	private function active_path( string $theme_dir ): string {
		return $this->storage_path( $theme_dir, self::ACTIVE_FILE );
	}

	/**
	 * Return an immutable retained release source file path.
	 */
	private function release_path( string $theme_dir, string $release_id ): string {
		return $this->storage_path( $theme_dir, self::RELEASES_DIRECTORY . '/' . $release_id . '/release.json' );
	}

	/**
	 * Build a namespaced manager error.
	 */
	private function error( string $reason, string $message ): WP_Error {
		return new WP_Error( 'sd_ai_agent_design_artifact_' . $reason, $message );
	}
}
