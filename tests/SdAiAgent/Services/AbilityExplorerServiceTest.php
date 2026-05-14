<?php

declare(strict_types=1);
/**
 * Tests for AbilityExplorerService tri-state annotation handling.
 *
 * Covers the bug where missing/null MCP annotation hints were silently
 * coerced to `false`, causing the Abilities Explorer UI to mis-classify
 * unknown-destructive abilities as safe (see beads sd-ai-dam / GH #1407).
 *
 * The fix introduces two private helpers — `normalise_annotations()` and
 * `read_tristate()` — that preserve `true`/`false`/`null` distinctly and
 * are shared between the PHP-ability and JS-ability formatters.
 *
 * @package SdAiAgent\Tests\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Services;

use SdAiAgent\Services\AbilityExplorerService;
use WP_UnitTestCase;

/**
 * Tests for AbilityExplorerService private tri-state helpers and formatters.
 */
class AbilityExplorerServiceTest extends WP_UnitTestCase {

	/**
	 * Invoke a private static method on AbilityExplorerService.
	 *
	 * @param string             $method Method name.
	 * @param array<int, mixed>  $args   Positional arguments.
	 * @return mixed Method return value.
	 */
	private function invoke_private( string $method, array $args ) {
		$ref = new \ReflectionMethod( AbilityExplorerService::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, $args );
	}

	/**
	 * `true` and `false` booleans are preserved verbatim.
	 */
	public function test_normalise_annotations_preserves_real_booleans(): void {
		$out = $this->invoke_private(
			'normalise_annotations',
			array(
				array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			)
		);

		$this->assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			$out
		);
	}

	/**
	 * Missing keys and explicit nulls both map to null (not false).
	 *
	 * This is the core regression fix: previous `(bool) ( ... ?? false )`
	 * coercion silently classified unknown-destructive abilities as safe.
	 */
	public function test_normalise_annotations_returns_null_for_missing_and_null(): void {
		$out = $this->invoke_private(
			'normalise_annotations',
			array(
				array(
					// readonly omitted entirely.
					'destructive' => null,
					'idempotent'  => null,
				),
			)
		);

		$this->assertNull( $out['readonly'] );
		$this->assertNull( $out['destructive'] );
		$this->assertNull( $out['idempotent'] );
	}

	/**
	 * Non-bool truthy values (1, "true", "yes", ["x"]) all map to null.
	 *
	 * The tri-state contract is strict: only real PHP booleans count as a
	 * declared hint. Anything else is treated as "author did not declare".
	 *
	 * @dataProvider provide_non_bool_values
	 *
	 * @param mixed $value Junk annotation value.
	 */
	public function test_read_tristate_returns_null_for_non_bool( $value ): void {
		$out = $this->invoke_private(
			'read_tristate',
			array(
				array( 'destructive' => $value ),
				'destructive',
			)
		);

		$this->assertNull( $out );
	}

	/**
	 * Provider of non-bool values that must be treated as undeclared.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_non_bool_values(): array {
		return array(
			'integer one'    => array( 1 ),
			'integer zero'   => array( 0 ),
			'string true'    => array( 'true' ),
			'string false'   => array( 'false' ),
			'string yes'     => array( 'yes' ),
			'empty string'   => array( '' ),
			'array'          => array( array( 'x' ) ),
			'object'         => array( new \stdClass() ),
			'explicit null'  => array( null ),
		);
	}

	/**
	 * When the annotations slot itself is not an array, all three slots
	 * are null. Defends against malformed third-party meta payloads.
	 */
	public function test_normalise_annotations_handles_non_array_input(): void {
		foreach ( array( null, 'string', 42, new \stdClass() ) as $junk ) {
			$out = $this->invoke_private( 'normalise_annotations', array( $junk ) );

			$this->assertSame(
				array(
					'readonly'    => null,
					'destructive' => null,
					'idempotent'  => null,
				),
				$out,
				sprintf( 'Junk input of type %s should produce all-null annotations.', gettype( $junk ) )
			);
		}
	}

	/**
	 * The JS-ability formatter delegates to the same tri-state helper.
	 *
	 * This guards against the historical regression where the JS code path
	 * hard-coded `destructive => false` and `idempotent => false`, which
	 * mis-classified every client-side ability as definitively safe.
	 */
	public function test_format_js_ability_for_explorer_preserves_tristate(): void {
		$descriptor = array(
			'name'         => 'sd-ai-agent/test-js-ability',
			'label'        => 'Test JS Ability',
			'description'  => 'JS ability used for tri-state coverage.',
			'category'     => 'sd-ai-agent-js',
			'input_schema' => array(
				'properties' => array(
					'foo' => array( 'type' => 'string' ),
				),
				'required'   => array( 'foo' ),
			),
			'annotations'  => array(
				'readonly'    => true,
				// destructive omitted on purpose -> must become null.
				'idempotent'  => false,
			),
			'output_schema' => array( 'type' => 'object' ),
		);

		$formatted = $this->invoke_private(
			'format_js_ability_for_explorer',
			array( $descriptor )
		);

		$this->assertArrayHasKey( 'annotations', $formatted );
		$this->assertSame( true, $formatted['annotations']['readonly'] );
		$this->assertNull( $formatted['annotations']['destructive'] );
		$this->assertSame( false, $formatted['annotations']['idempotent'] );

		// Spot-check unrelated fields remain intact.
		$this->assertSame( 'sd-ai-agent/test-js-ability', $formatted['name'] );
		$this->assertSame( 1, $formatted['param_count'] );
		$this->assertSame( array( 'foo' ), $formatted['required_params'] );
		$this->assertFalse( $formatted['show_in_rest'] );
	}

	/**
	 * The JS-ability formatter still produces a fully-populated annotations
	 * key when the descriptor omits the slot entirely.
	 */
	public function test_format_js_ability_for_explorer_handles_missing_annotations(): void {
		$descriptor = array(
			'name'         => 'sd-ai-agent/no-annotations',
			'label'        => 'No Annotations',
			'description'  => '',
			'category'     => 'sd-ai-agent-js',
			'input_schema' => array(),
			// annotations key omitted entirely.
		);

		$formatted = $this->invoke_private(
			'format_js_ability_for_explorer',
			array( $descriptor )
		);

		$this->assertSame(
			array(
				'readonly'    => null,
				'destructive' => null,
				'idempotent'  => null,
			),
			$formatted['annotations']
		);
	}
}
