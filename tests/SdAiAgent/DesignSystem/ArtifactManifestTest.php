<?php

declare(strict_types=1);
/**
 * Tests for schema-v1 generated design artifact manifests.
 *
 * @package SdAiAgent\Tests\DesignSystem
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\DesignSystem;

use SdAiAgent\DesignSystem\ArtifactManifest;
use WP_UnitTestCase;

/**
 * Proves strict artifact lifecycle metadata and canonical content integrity.
 */
class ArtifactManifestTest extends WP_UnitTestCase {

	/**
	 * Producer helper creates a canonical record and payload hash.
	 */
	public function test_create_artifact_normalizes_a_complete_schema_v1_record(): void {
		$artifact = ArtifactManifest::create_artifact( $this->raw_artifact() );

		$this->assertNotWPError( $artifact );
		$this->assertSame( 'sd-ai-agent/pattern/hero', $artifact['id'] );
		$this->assertSame( '1.2.3', $artifact['version'] );
		$this->assertSame( 'stable', $artifact['maturity'] );
		$this->assertSame(
			ArtifactManifest::hash_payload( $artifact['payload'] ),
			$artifact['integrity']['content_hash']
		);
	}

	/**
	 * Canonical hashing must not depend on associative input order.
	 */
	public function test_canonical_payload_hash_is_order_independent(): void {
		$left  = ArtifactManifest::hash_payload( [ 'z' => [ 'b' => 2, 'a' => 1 ], 'a' => true ] );
		$right = ArtifactManifest::hash_payload( [ 'a' => true, 'z' => [ 'a' => 1, 'b' => 2 ] ] );

		$this->assertNotWPError( $left );
		$this->assertNotWPError( $right );
		$this->assertSame( $left, $right );
	}

	/**
	 * Strict parsing rejects invalid versioning, lifecycle, and integrity records.
	 */
	public function test_normalize_rejects_invalid_lifecycle_records(): void {
		$invalid_version            = $this->raw_artifact();
		$invalid_version['version'] = '1.2';
		$this->assertWPError( ArtifactManifest::create_artifact( $invalid_version ) );

		$invalid_maturity             = $this->raw_artifact();
		$invalid_maturity['maturity'] = 'preview';
		$this->assertWPError( ArtifactManifest::create_artifact( $invalid_maturity ) );

		$deprecated             = $this->raw_artifact();
		$deprecated['maturity'] = 'deprecated';
		$this->assertWPError( ArtifactManifest::create_artifact( $deprecated ) );

		$artifact = ArtifactManifest::create_artifact( $this->raw_artifact() );
		$this->assertNotWPError( $artifact );
		$artifact['integrity']['content_hash'] = str_repeat( '0', 64 );
		$this->assertWPError(
			ArtifactManifest::normalize(
				[
					'schema_version' => ArtifactManifest::SCHEMA_VERSION,
					'artifacts'      => [ $artifact ],
				]
			)
		);
	}

	/**
	 * Unknown schema versions fail safely while additive v1 fields are retained.
	 */
	public function test_normalize_rejects_unknown_schema_versions_and_preserves_additions(): void {
		$artifact = ArtifactManifest::create_artifact( $this->raw_artifact() );
		$this->assertNotWPError( $artifact );
		$artifact['producer_note'] = 'additive metadata';

		$normalized = ArtifactManifest::normalize(
			[
				'schema_version' => ArtifactManifest::SCHEMA_VERSION,
				'artifacts'      => [ $artifact ],
			]
		);
		$this->assertNotWPError( $normalized );
		$this->assertSame( 'additive metadata', $normalized['artifacts'][0]['producer_note'] );

		$this->assertWPError(
			ArtifactManifest::normalize(
				[
					'schema_version' => 2,
					'artifacts'      => [],
				]
			)
		);
	}

	/**
	 * Build a valid pattern artifact suitable for manifest tests.
	 *
	 * @return array<string,mixed> Raw producer artifact.
	 */
	private function raw_artifact(): array {
		return [
			'id'            => 'sd-ai-agent/pattern/hero',
			'kind'          => 'pattern',
			'version'       => '1.2.3',
			'maturity'      => 'stable',
			'provenance'    => [
				'generator_version' => '1.0.0',
				'source_type'       => 'generated',
				'source_reference'  => 'test-suite',
				'generated_at'      => '2026-07-18T00:00:00Z',
				'input_hash'        => hash( 'sha256', 'artifact-input' ),
			],
			'compatibility' => [
				'wordpress'        => [ 'min' => '7.0', 'max' => null ],
				'theme_json'       => [ 'min' => 3, 'max' => 3 ],
				'required_blocks'   => [],
				'required_features' => [],
				'theme_constraints' => [],
			],
			'payload'       => [
				'files'   => [
					[
						'path'    => 'patterns/hero.php',
						'content' => "<?php\n/** Pattern */\n",
					],
				],
				'records' => [],
			],
		];
	}
}
