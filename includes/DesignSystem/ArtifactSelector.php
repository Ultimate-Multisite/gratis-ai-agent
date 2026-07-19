<?php

declare(strict_types=1);
/**
 * Deterministic compatibility and maturity resolution for design artifacts.
 *
 * @package SdAiAgent\DesignSystem
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\DesignSystem;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves one compatible version for every logical artifact ID.
 */
final class ArtifactSelector {

	/**
	 * Resolve a normalized manifest against current site capabilities and explicit choices.
	 *
	 * @param array<string,mixed> $manifest Schema-v1 registry.
	 * @param array<string,mixed> $context  Site capability, pin, and opt-in context.
	 * @return array{selected:list<array<string,mixed>>,trace:list<array<string,mixed>>,skipped:list<string>}|WP_Error Resolution result or error.
	 */
	public function resolve( array $manifest, array $context = [] ): array|WP_Error {
		$normalized = ArtifactManifest::normalize( $manifest );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$groups = $this->group_artifacts( $normalized );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}

		$selected = [];
		$trace    = [];
		$skipped  = [];
		$pins     = $this->pins( $context );
		$current  = $this->current_selection( $context );

		foreach ( $groups as $id => $candidates ) {
			$current_version = $current[ $id ] ?? null;
			$pin             = $pins[ $id ] ?? null;
			$preserved       = null === $pin ? $this->preserved_deprecated( $candidates, $current_version, $context ) : null;
			if ( null !== $preserved ) {
				$compatibility = $this->is_compatible( $preserved, $context );
				if ( true === $compatibility ) {
					$selected[] = $preserved;
					$trace[]    = $this->trace( $preserved, 'selected', 'preserved_selected_deprecated' );
					continue;
				}
				$trace[] = $this->trace( $preserved, 'rejected', (string) $compatibility );
			}

			$eligible  = [];
			$pin_found = false;
			foreach ( $candidates as $candidate ) {
				if ( null !== $preserved && $candidate['version'] === $preserved['version'] ) {
					continue;
				}
				$compatibility = $this->is_compatible( $candidate, $context );
				if ( true !== $compatibility ) {
					$trace[] = $this->trace( $candidate, 'rejected', (string) $compatibility );
					continue;
				}

				if ( null !== $pin && $candidate['version'] === $pin ) {
					$pin_found = true;
				}

				$eligibility = $this->is_eligible( $candidate, $id, $context, $current_version, null !== $pin && $candidate['version'] === $pin );
				if ( true !== $eligibility ) {
					$trace[] = $this->trace( $candidate, 'rejected', (string) $eligibility );
					continue;
				}

				if ( null !== $pin && $candidate['version'] !== $pin ) {
					$trace[] = $this->trace( $candidate, 'rejected', 'different_from_exact_pin' );
					continue;
				}

				$eligible[] = $candidate;
			}

			if ( null !== $pin && ( ! $pin_found || [] === $eligible ) ) {
				$trace[]   = [
					'id'       => $id,
					'decision' => 'skipped',
					'reason'   => 'exact_pin_unavailable_or_incompatible',
					'pin'      => $pin,
				];
				$skipped[] = $id;
				continue;
			}

			if ( [] === $eligible ) {
				$trace[]   = [
					'id'       => $id,
					'decision' => 'skipped',
					'reason'   => 'no_eligible_candidate',
				];
				$skipped[] = $id;
				continue;
			}

			usort( $eligible, [ self::class, 'compare_candidates' ] );
			$winner     = $eligible[0];
			$selected[] = $winner;
			$trace[]    = $this->trace( $winner, 'selected', null !== $pin ? 'exact_compatible_pin' : 'highest_eligible_version' );
		}

		return [
			'selected' => $selected,
			'trace'    => $trace,
			'skipped'  => $skipped,
		];
	}

	/**
	 * Compare semantic versions according to Semantic Versioning 2.0.0.
	 *
	 * @return int Negative when left is lower, positive when left is higher.
	 */
	public static function compare_versions( string $left, string $right ): int {
		if ( $left === $right ) {
			return 0;
		}

		$left_parts  = self::parse_version( $left );
		$right_parts = self::parse_version( $right );
		foreach ( [ 'major', 'minor', 'patch' ] as $key ) {
			if ( $left_parts[ $key ] !== $right_parts[ $key ] ) {
				return self::compare_numeric_identifiers( $left_parts[ $key ], $right_parts[ $key ] );
			}
		}

		$left_pre  = $left_parts['pre'];
		$right_pre = $right_parts['pre'];
		if ( [] === $left_pre ) {
			return 1;
		}
		if ( [] === $right_pre ) {
			return -1;
		}

		$length = max( count( $left_pre ), count( $right_pre ) );
		for ( $index = 0; $index < $length; ++$index ) {
			if ( ! array_key_exists( $index, $left_pre ) ) {
				return -1;
			}
			if ( ! array_key_exists( $index, $right_pre ) ) {
				return 1;
			}

			$left_identifier  = $left_pre[ $index ];
			$right_identifier = $right_pre[ $index ];
			if ( $left_identifier === $right_identifier ) {
				continue;
			}

			$left_numeric  = ctype_digit( $left_identifier );
			$right_numeric = ctype_digit( $right_identifier );
			if ( $left_numeric && $right_numeric ) {
				return self::compare_numeric_identifiers( $left_identifier, $right_identifier );
			}
			if ( $left_numeric ) {
				return -1;
			}
			if ( $right_numeric ) {
				return 1;
			}

			return strcmp( $left_identifier, $right_identifier );
		}

		return 0;
	}

	/**
	 * Sort candidates by highest SemVer, then safest maturity and content hash.
	 *
	 * @param mixed $left  Candidate.
	 * @param mixed $right Candidate.
	 */
	public static function compare_candidates( mixed $left, mixed $right ): int {
		if ( ! is_array( $left ) || ! is_array( $right ) ) {
			return 0;
		}

		$left_version  = isset( $left['version'] ) && is_string( $left['version'] ) ? $left['version'] : '';
		$right_version = isset( $right['version'] ) && is_string( $right['version'] ) ? $right['version'] : '';
		$version       = self::compare_versions( $right_version, $left_version );
		if ( 0 !== $version ) {
			return $version;
		}

		$left_maturity  = isset( $left['maturity'] ) && is_string( $left['maturity'] ) ? $left['maturity'] : '';
		$right_maturity = isset( $right['maturity'] ) && is_string( $right['maturity'] ) ? $right['maturity'] : '';
		$rank           = self::maturity_rank( $right_maturity ) <=> self::maturity_rank( $left_maturity );
		if ( 0 !== $rank ) {
			return $rank;
		}

		$left_integrity  = isset( $left['integrity'] ) && is_array( $left['integrity'] ) ? $left['integrity'] : [];
		$right_integrity = isset( $right['integrity'] ) && is_array( $right['integrity'] ) ? $right['integrity'] : [];
		$left_hash       = isset( $left_integrity['content_hash'] ) && is_string( $left_integrity['content_hash'] ) ? $left_integrity['content_hash'] : '';
		$right_hash      = isset( $right_integrity['content_hash'] ) && is_string( $right_integrity['content_hash'] ) ? $right_integrity['content_hash'] : '';

		return strcmp( $left_hash, $right_hash );
	}

	/**
	 * Decide whether a candidate can run on the target site.
	 *
	 * @param array<string,mixed> $artifact Normalized artifact.
	 * @param array<string,mixed> $context  Site context.
	 * @return true|string True or a traceable rejection reason.
	 */
	private function is_compatible( array $artifact, array $context ): true|string {
		$compatibility = $artifact['compatibility'];
		$wordpress     = isset( $context['wordpress_version'] ) ? (string) $context['wordpress_version'] : $this->wordpress_version();
		$theme_json    = isset( $context['theme_json_version'] ) ? (int) $context['theme_json_version'] : 3;

		if ( version_compare( $wordpress, $compatibility['wordpress']['min'], '<' ) || ( null !== $compatibility['wordpress']['max'] && version_compare( $wordpress, $compatibility['wordpress']['max'], '>' ) ) ) {
			return 'incompatible_wordpress';
		}

		if ( $theme_json < $compatibility['theme_json']['min'] || ( null !== $compatibility['theme_json']['max'] && $theme_json > $compatibility['theme_json']['max'] ) ) {
			return 'incompatible_theme_json';
		}

		$blocks = $this->string_set( $context['blocks'] ?? [] );
		foreach ( $compatibility['required_blocks'] as $block ) {
			if ( ! isset( $blocks[ $block ] ) ) {
				return 'missing_required_block:' . $block;
			}
		}

		$features = $this->string_set( $context['features'] ?? [] );
		foreach ( $compatibility['required_features'] as $feature ) {
			if ( ! isset( $features[ $feature ] ) ) {
				return 'missing_required_feature:' . $feature;
			}
		}

		$constraints = $compatibility['theme_constraints'];
		if ( [] !== $constraints ) {
			$theme = isset( $context['theme'] ) ? (string) $context['theme'] : ( function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '' );
			if ( ! in_array( $theme, $constraints, true ) ) {
				return 'incompatible_theme';
			}
		}

		return true;
	}

	/**
	 * Decide whether a compatible candidate is eligible for automatic selection.
	 *
	 * @param array<string,mixed> $artifact        Normalized artifact.
	 * @param string              $id              Logical artifact ID.
	 * @param array<string,mixed> $context         Selection context.
	 * @param string|null         $current_version Currently selected version.
	 * @param bool                $is_pin          Whether this is an exact explicit pin.
	 * @return true|string True or a traceable rejection reason.
	 */
	private function is_eligible( array $artifact, string $id, array $context, ?string $current_version, bool $is_pin ): true|string {
		$maturity = $artifact['maturity'];
		if ( 'deprecated' === $maturity ) {
			return 'deprecated_for_new_selection';
		}

		if ( 'candidate' === $maturity && empty( $context['allow_candidate'] ) && ! $is_pin ) {
			return 'candidate_requires_opt_in';
		}

		if ( 'experimental' === $maturity && ! $this->experimental_opted_in( $id, $context ) && ! $is_pin ) {
			return 'experimental_requires_per_artifact_opt_in';
		}

		if ( null !== $current_version && ! $is_pin && ! $this->major_upgrade_allowed( $id, $context ) ) {
			$current = self::parse_version( $current_version );
			$next    = self::parse_version( (string) $artifact['version'] );
			if ( $current['major'] !== $next['major'] ) {
				return 'automatic_major_upgrade_forbidden';
			}
		}

		return true;
	}

	/**
	 * Preserve an already selected deprecated version until an explicit replacement choice.
	 *
	 * @param list<array<string,mixed>> $candidates      Logical artifact versions.
	 * @param string|null               $current_version Current selection.
	 * @param array<string,mixed>       $context         Selection context.
	 * @return array<string,mixed>|null Preserved artifact when policy requires it.
	 */
	private function preserved_deprecated( array $candidates, ?string $current_version, array $context ): ?array {
		if ( null === $current_version || ! empty( $context['replace_deprecated'] ) || ! empty( $context['rollback'] ) ) {
			return null;
		}

		foreach ( $candidates as $candidate ) {
			if ( $candidate['version'] === $current_version && 'deprecated' === $candidate['maturity'] ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Merge site and user exact pins, with user pins taking precedence.
	 *
	 * @param array<string,mixed> $context Selection context.
	 * @return array<string,string> Pin map.
	 */
	private function pins( array $context ): array {
		$pins = [];
		foreach ( [ $context['site_pins'] ?? [], $context['pins'] ?? [], $context['user_pins'] ?? [] ] as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			foreach ( $source as $id => $version ) {
				if ( is_string( $id ) && is_string( $version ) && ArtifactManifest::is_valid_semver( $version ) ) {
					$pins[ $id ] = $version;
				}
			}
		}

		return $pins;
	}

	/**
	 * Normalize the active release's logical artifact selections.
	 *
	 * @param array<string,mixed> $context Selection context.
	 * @return array<string,string> Artifact ID to selected version.
	 */
	private function current_selection( array $context ): array {
		$result = [];
		$value  = $context['current_selection'] ?? [];
		if ( ! is_array( $value ) ) {
			return $result;
		}

		foreach ( $value as $id => $selection ) {
			$version = is_array( $selection ) ? ( $selection['version'] ?? null ) : $selection;
			if ( is_string( $id ) && is_string( $version ) && ArtifactManifest::is_valid_semver( $version ) ) {
				$result[ $id ] = $version;
			}
		}

		return $result;
	}

	/**
	 * Group a normalized artifact list by its stable logical identifier.
	 *
	 * This repeats a minimal runtime check at the resolver boundary because the
	 * persisted manifest is decoded JSON and may have been changed after initial
	 * validation.
	 *
	 * @param array<string,mixed> $manifest Normalized manifest.
	 * @return array<string,list<array<string,mixed>>>|WP_Error Artifact groups or an error.
	 */
	private function group_artifacts( array $manifest ): array|WP_Error {
		$raw_artifacts = $manifest['artifacts'] ?? null;
		if ( ! is_array( $raw_artifacts ) || ! array_is_list( $raw_artifacts ) ) {
			return new WP_Error( 'sd_ai_agent_design_artifact_invalid_manifest', __( 'Generated design artifacts must be a list.', 'superdav-ai-agent' ) );
		}

		$groups = [];
		foreach ( $raw_artifacts as $artifact ) {
			if ( ! is_array( $artifact ) ) {
				return new WP_Error( 'sd_ai_agent_design_artifact_invalid_manifest', __( 'Generated design artifact entries must be objects.', 'superdav-ai-agent' ) );
			}
			$normalized = [];
			foreach ( $artifact as $key => $value ) {
				if ( ! is_string( $key ) ) {
					return new WP_Error( 'sd_ai_agent_design_artifact_invalid_manifest', __( 'Generated design artifact properties must use string keys.', 'superdav-ai-agent' ) );
				}
				$normalized[ $key ] = $value;
			}
			$id = $normalized['id'] ?? null;
			if ( ! is_string( $id ) || '' === $id ) {
				return new WP_Error( 'sd_ai_agent_design_artifact_invalid_manifest', __( 'Generated design artifact IDs must be non-empty strings.', 'superdav-ai-agent' ) );
			}
			$groups[ $id ][] = $normalized;
		}
		ksort( $groups, SORT_STRING );

		return $groups;
	}

	/**
	 * Check the per-artifact experimental opt-in map/list.
	 *
	 * @param string              $id      Artifact ID.
	 * @param array<string,mixed> $context Selection context.
	 */
	private function experimental_opted_in( string $id, array $context ): bool {
		$opt_ins = $context['experimental_opt_ins'] ?? [];
		if ( ! is_array( $opt_ins ) ) {
			return false;
		}

		if ( array_is_list( $opt_ins ) ) {
			return in_array( $id, $opt_ins, true );
		}

		return ! empty( $opt_ins[ $id ] );
	}

	/**
	 * Check whether a major jump was intentionally authorized for this ID.
	 *
	 * @param string              $id      Artifact ID.
	 * @param array<string,mixed> $context Selection context.
	 */
	private function major_upgrade_allowed( string $id, array $context ): bool {
		$allowed = $context['allow_major_upgrade'] ?? false;
		if ( is_bool( $allowed ) ) {
			return $allowed;
		}

		return is_array( $allowed ) && ! empty( $allowed[ $id ] );
	}

	/**
	 * Turn a list into a fast membership map.
	 *
	 * @param mixed $items Candidate list.
	 * @return array<string,true> Membership map.
	 */
	private function string_set( mixed $items ): array {
		$result = [];
		if ( ! is_array( $items ) ) {
			return $result;
		}

		foreach ( $items as $item ) {
			if ( is_string( $item ) ) {
				$result[ $item ] = true;
			}
		}

		return $result;
	}

	/**
	 * Read a safe default WordPress version when the selector runs in WordPress.
	 */
	private function wordpress_version(): string {
		if ( function_exists( 'get_bloginfo' ) ) {
			$version = get_bloginfo( 'version' );
			if ( is_string( $version ) && '' !== $version ) {
				return $version;
			}
		}

		return '7.0';
	}

	/**
	 * Build an ordered, explainable decision trace entry.
	 *
	 * @param array<string,mixed> $artifact Artifact being evaluated.
	 * @return array<string,mixed> Trace entry.
	 */
	private function trace( array $artifact, string $decision, string $reason ): array {
		return [
			'id'       => $artifact['id'],
			'version'  => $artifact['version'],
			'maturity' => $artifact['maturity'],
			'decision' => $decision,
			'reason'   => $reason,
		];
	}

	/**
	 * Map lifecycle values to deterministic safety tiebreak ranks.
	 */
	private static function maturity_rank( string $maturity ): int {
		return match ( $maturity ) {
			'stable'       => 3,
			'candidate'    => 2,
			'experimental' => 1,
			default        => 0,
		};
	}

	/**
	 * Compare SemVer numeric identifiers without overflowing PHP integers.
	 *
	 * Valid SemVer identifiers do not have leading zeroes, so their length then
	 * lexical ordering is equivalent to numeric ordering at any magnitude.
	 */
	private static function compare_numeric_identifiers( string $left, string $right ): int {
		$left  = ltrim( $left, '0' );
		$right = ltrim( $right, '0' );
		$left  = '' === $left ? '0' : $left;
		$right = '' === $right ? '0' : $right;

		$length = strlen( $left ) <=> strlen( $right );

		return 0 !== $length ? $length : strcmp( $left, $right );
	}

	/**
	 * Parse a previously validated semantic version.
	 *
	 * @return array{major:string,minor:string,patch:string,pre:list<string>}
	 */
	private static function parse_version( string $version ): array {
		preg_match( '/^(\d+)\.(\d+)\.(\d+)(?:-([^+]+))?/', $version, $matches );

		return [
			'major' => (string) ( $matches[1] ?? '0' ),
			'minor' => (string) ( $matches[2] ?? '0' ),
			'patch' => (string) ( $matches[3] ?? '0' ),
			'pre'   => isset( $matches[4] ) ? explode( '.', $matches[4] ) : [],
		];
	}
}
