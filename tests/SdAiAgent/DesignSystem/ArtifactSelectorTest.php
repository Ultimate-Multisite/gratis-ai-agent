<?php

declare(strict_types=1);
/**
 * Tests for deterministic generated design artifact selection.
 *
 * @package SdAiAgent\Tests\DesignSystem
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\DesignSystem;

use SdAiAgent\DesignSystem\ArtifactManifest;
use SdAiAgent\DesignSystem\ArtifactSelector;
use WP_UnitTestCase;

/**
 * Proves compatibility, pins, maturity, SemVer, and trace policy.
 */
class ArtifactSelectorTest extends WP_UnitTestCase {

	/**
	 * Stable candidates win by highest compatible semantic version by default.
	 */
	public function test_selects_highest_compatible_stable_version_with_trace(): void {
		$result = $this->selector()->resolve(
			$this->manifest(
				[
					$this->artifact( '1.0.0' ),
					$this->artifact( '1.4.0' ),
					$this->artifact( '2.0.0', 'candidate' ),
				]
			),
			$this->context()
		);

		$this->assertNotWPError( $result );
		$this->assertSame( '1.4.0', $result['selected'][0]['version'] );
		$trace           = $result['trace'];
		$selection_trace = end( $trace );
		$this->assertSame( 'selected', $selection_trace['decision'] );
		$this->assertSame( 'highest_eligible_version', $selection_trace['reason'] );
	}

	/**
	 * An exact compatible site/user pin is deliberate opt-in even for candidate maturity.
	 */
	public function test_honors_exact_compatible_pin_before_default_maturity_selection(): void {
		$id      = 'sd-ai-agent/pattern/hero';
		$result  = $this->selector()->resolve(
			$this->manifest( [ $this->artifact( '1.0.0' ), $this->artifact( '2.0.0', 'candidate' ) ] ),
			$this->context( [ 'pins' => [ $id => '2.0.0' ] ] )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( '2.0.0', $result['selected'][0]['version'] );
		$this->assertSame( 'exact_compatible_pin', $result['trace'][1]['reason'] );
	}

	/**
	 * Candidate and experimental releases stay unavailable without the required opt-ins.
	 */
	public function test_requires_candidate_and_per_artifact_experimental_opt_ins(): void {
		$id       = 'sd-ai-agent/pattern/hero';
		$manifest = $this->manifest(
			[
				$this->artifact( '1.0.0' ),
				$this->artifact( '1.1.0', 'candidate' ),
				$this->artifact( '1.2.0', 'experimental' ),
			]
		);

		$default = $this->selector()->resolve( $manifest, $this->context() );
		$this->assertNotWPError( $default );
		$this->assertSame( '1.0.0', $default['selected'][0]['version'] );

		$opted_in = $this->selector()->resolve(
			$manifest,
			$this->context(
				[
					'allow_candidate'      => true,
					'experimental_opt_ins' => [ $id ],
				]
			)
		);
		$this->assertNotWPError( $opted_in );
		$this->assertSame( '1.2.0', $opted_in['selected'][0]['version'] );
	}

	/**
	 * A selected deprecated version remains active until a replacement/rollback is explicit.
	 */
	public function test_preserves_selected_deprecated_artifact_and_blocks_automatic_major_upgrade(): void {
		$id         = 'sd-ai-agent/pattern/hero';
		$deprecated = $this->artifact( '1.0.0', 'deprecated' );

		$result = $this->selector()->resolve(
			$this->manifest( [ $deprecated, $this->artifact( '1.1.0' ), $this->artifact( '2.0.0' ) ] ),
			$this->context( [ 'current_selection' => [ $id => '1.0.0' ] ] )
		);
		$this->assertNotWPError( $result );
		$this->assertSame( '1.0.0', $result['selected'][0]['version'] );
		$this->assertSame( 'preserved_selected_deprecated', $result['trace'][0]['reason'] );

		$replacement = $this->selector()->resolve(
			$this->manifest( [ $this->artifact( '1.1.0' ), $this->artifact( '2.0.0' ) ] ),
			$this->context(
				[
					'current_selection' => [ $id => '1.0.0' ],
					'replace_deprecated' => true,
				]
			)
		);
		$this->assertNotWPError( $replacement );
		$this->assertSame( '1.1.0', $replacement['selected'][0]['version'] );
	}

	/**
	 * Compatibility rejection happens before a candidate can be selected and is traceable.
	 */
	public function test_rejects_incompatible_artifacts_before_selection_and_orders_groups(): void {
		$incompatible = $this->artifact( '1.0.0' );
		$incompatible['compatibility']['required_blocks'] = [ 'core/missing-block' ];
		$incompatible = ArtifactManifest::create_artifact( $incompatible );
		$this->assertNotWPError( $incompatible );

		$second = $this->artifact( '1.0.0', 'stable', 'sd-ai-agent/pattern/zeta' );
		$result = $this->selector()->resolve( $this->manifest( [ $second, $incompatible ] ), $this->context() );

		$this->assertNotWPError( $result );
		$this->assertSame( [ 'sd-ai-agent/pattern/zeta' ], array_column( $result['selected'], 'id' ) );
		$this->assertSame( 'missing_required_block:core/missing-block', $result['trace'][0]['reason'] );
		$this->assertSame( [ 'sd-ai-agent/pattern/hero' ], $result['skipped'] );
	}

	/**
	 * Semantic prerelease precedence is numeric rather than lexical.
	 */
	public function test_compares_numeric_semantic_versions(): void {
		$this->assertLessThan( 0, ArtifactSelector::compare_versions( '1.0.0-alpha.2', '1.0.0-alpha.10' ) );
		$this->assertLessThan( 0, ArtifactSelector::compare_versions( '1.0.0-rc.1', '1.0.0' ) );
		$this->assertGreaterThan( 0, ArtifactSelector::compare_versions( '2.0.0', '1.99.99' ) );
	}

	/**
	 * Numeric SemVer identifiers must retain precedence beyond PHP integer range.
	 */
	public function test_compares_arbitrarily_large_numeric_semantic_versions(): void {
		$this->assertLessThan(
			0,
			ArtifactSelector::compare_versions( '9223372036854775808.0.0', '9223372036854775809.0.0' )
		);
		$this->assertLessThan(
			0,
			ArtifactSelector::compare_versions( '1.0.0-alpha.9223372036854775808', '1.0.0-alpha.9223372036854775809' )
		);
	}

	/**
	 * Return the pure resolver.
	 */
	private function selector(): ArtifactSelector {
		return new ArtifactSelector();
	}

	/**
	 * Build a validated logical pattern version.
	 *
	 * @return array<string,mixed> Artifact.
	 */
	private function artifact( string $version, string $maturity = 'stable', string $id = 'sd-ai-agent/pattern/hero' ): array {
		$raw_artifact = [
				'id'            => $id,
				'kind'          => 'pattern',
				'version'       => $version,
				'maturity'      => $maturity,
				'provenance'    => [
					'generator_version' => '1.0.0',
					'source_type'       => 'generated',
					'source_reference'  => 'selector-test',
					'generated_at'      => '2026-07-18T00:00:00Z',
					'input_hash'        => hash( 'sha256', $id . $version ),
				],
				'compatibility' => [
					'wordpress'        => [ 'min' => '7.0', 'max' => null ],
					'theme_json'       => [ 'min' => 3, 'max' => 3 ],
					'required_blocks'   => [],
					'required_features' => [],
					'theme_constraints' => [],
				],
				'payload'       => [
					'files'   => [],
					'records' => [],
				],
		];
		if ( 'deprecated' === $maturity ) {
			$raw_artifact['deprecation'] = [
				'reason'         => 'Superseded visual direction.',
				'replacement'    => $id . '@2.0.0',
				'removal_target' => '3.0.0',
			];
		}

		$artifact = ArtifactManifest::create_artifact( $raw_artifact );
		$this->assertNotWPError( $artifact );

		return $artifact;
	}

	/**
	 * Build a schema-v1 registry.
	 *
	 * @param list<array<string,mixed>> $artifacts Artifacts.
	 * @return array<string,mixed> Registry.
	 */
	private function manifest( array $artifacts ): array {
		return [
			'schema_version' => ArtifactManifest::SCHEMA_VERSION,
			'artifacts'      => $artifacts,
		];
	}

	/**
	 * Build deterministic site capability context.
	 *
	 * @param array<string,mixed> $overrides Context overrides.
	 * @return array<string,mixed> Context.
	 */
	private function context( array $overrides = [] ): array {
		return array_replace(
			[
				'wordpress_version' => '7.0',
				'theme_json_version' => 3,
				'blocks'            => [],
				'features'          => [],
			],
			$overrides
		);
	}
}
