<?php

declare(strict_types=1);
/**
 * Tests for generated design artifact ability registration.
 *
 * @package SdAiAgent\Tests\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\DesignSystemArtifactAbilities;
use WP_UnitTestCase;

/**
 * Verifies public ability IDs, capability gates, and destructive annotations.
 */
class DesignSystemArtifactAbilitiesTest extends WP_UnitTestCase {

	/**
	 * The five separate lifecycle operations remain discoverable with safe metadata.
	 */
	public function test_registers_list_inspect_resolve_apply_and_rollback_abilities(): void {
		$readonly = [
			'sd-ai-agent/list-design-artifacts',
			'sd-ai-agent/inspect-design-artifact',
			'sd-ai-agent/resolve-design-artifacts',
		];
		foreach ( $readonly as $id ) {
			$ability = wp_get_ability( $id );
			$this->assertNotNull( $ability, $id . ' must be registered.' );
			$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );
			$this->assertFalse( $ability->get_meta()['annotations']['destructive'] );
		}

		foreach ( [ 'sd-ai-agent/apply-design-artifact-release', 'sd-ai-agent/rollback-design-artifact-release' ] as $id ) {
			$ability = wp_get_ability( $id );
			$this->assertNotNull( $ability, $id . ' must be registered.' );
			$this->assertFalse( $ability->get_meta()['annotations']['readonly'] );
			$this->assertTrue( $ability->get_meta()['annotations']['destructive'] );
		}
	}

	/**
	 * Read-only resolution of a theme without a registry returns a deterministic empty selection.
	 */
	public function test_resolve_returns_empty_selection_when_active_theme_has_no_registry(): void {
		$result = DesignSystemArtifactAbilities::handle_resolve( [] );

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['selected'] );
		$this->assertSame( [], $result['skipped'] );
	}
}
