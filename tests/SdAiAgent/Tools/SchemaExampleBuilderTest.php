<?php
/**
 * Test case for SchemaExampleBuilder.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Tools;

use SdAiAgent\Tools\SchemaExampleBuilder;
use WP_UnitTestCase;

class SchemaExampleBuilderTest extends WP_UnitTestCase {

	// ─── build_example ────────────────────────────────────────────────

	public function test_build_example_returns_empty_for_schema_without_required(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'foo' => array( 'type' => 'string' ),
			),
		);

		$this->assertSame( array(), SchemaExampleBuilder::build_example( $schema ) );
	}

	public function test_build_example_returns_empty_for_empty_schema(): void {
		$this->assertSame( array(), SchemaExampleBuilder::build_example( array() ) );
		$this->assertSame( array(), SchemaExampleBuilder::build_example( null ) );
	}

	public function test_build_example_includes_only_required_fields(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'username' => array( 'type' => 'string', 'description' => 'The login name.' ),
				'email'    => array( 'type' => 'string', 'description' => 'Contact address.' ),
				'role'     => array( 'type' => 'string', 'description' => 'Optional role.' ),
			),
			'required'   => array( 'username', 'email' ),
		);

		$example = SchemaExampleBuilder::build_example( $schema );

		$this->assertArrayHasKey( 'username', $example );
		$this->assertArrayHasKey( 'email', $example );
		$this->assertArrayNotHasKey( 'role', $example );
	}

	public function test_build_example_renders_type_and_description_in_placeholder(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'username' => array( 'type' => 'string', 'description' => 'The login username for the new user.' ),
			),
			'required'   => array( 'username' ),
		);

		$example = SchemaExampleBuilder::build_example( $schema );

		$this->assertStringContainsString( 'string', $example['username'] );
		$this->assertStringContainsString( 'The login username', $example['username'] );
	}

	public function test_build_example_renders_enum_when_present(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'status' => array(
					'type'        => 'string',
					'description' => 'Verification status.',
					'enum'        => array( 'verified', 'pending', 'none' ),
				),
			),
			'required'   => array( 'status' ),
		);

		$example = SchemaExampleBuilder::build_example( $schema );

		$this->assertStringContainsString( 'one of: verified|pending|none', $example['status'] );
	}

	public function test_build_example_handles_missing_property_definition(): void {
		// `required` lists a field that has no entry in `properties`.
		$schema = array(
			'type'       => 'object',
			'properties' => array(),
			'required'   => array( 'phantom' ),
		);

		$example = SchemaExampleBuilder::build_example( $schema );

		$this->assertArrayHasKey( 'phantom', $example );
		$this->assertStringContainsString( 'value', $example['phantom'] );
	}

	public function test_build_example_expands_nested_required_object_shape(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'styles' => array(
					'type'       => 'object',
					'properties' => array(
						'color'      => array(
							'type'       => 'object',
							'properties' => array(
								'background' => array( 'type' => 'string', 'description' => 'Background colour.' ),
								'text'       => array( 'type' => 'string', 'description' => 'Text colour.' ),
							),
						),
						'typography' => array(
							'type'       => 'object',
							'properties' => array(
								'fontFamily' => array( 'type' => 'string', 'description' => 'Font stack.' ),
							),
						),
					),
				),
			),
			'required'   => array( 'styles' ),
		);

		$example = SchemaExampleBuilder::build_example( $schema );

		$this->assertIsArray( $example['styles'] );
		$this->assertArrayHasKey( 'color', $example['styles'] );
		$this->assertArrayHasKey( 'background', $example['styles']['color'] );
		$this->assertStringContainsString( 'Background colour', $example['styles']['color']['background'] );
	}

	// ─── extract_missing_required ────────────────────────────────────

	public function test_extract_missing_required_handles_standard_phrasing(): void {
		$msg = 'Ability "sd-ai-agent/create-user" has invalid input. Reason: username is a required property of input.';

		$this->assertSame(
			array( 'username' ),
			SchemaExampleBuilder::extract_missing_required( $msg )
		);
	}

	public function test_extract_missing_required_handles_backticks(): void {
		$msg = '`customer_id` is a required property of input.';
		$this->assertSame(
			array( 'customer_id' ),
			SchemaExampleBuilder::extract_missing_required( $msg )
		);
	}

	public function test_extract_missing_required_returns_empty_for_unrelated_message(): void {
		$msg = 'Some other error.';
		$this->assertSame( array(), SchemaExampleBuilder::extract_missing_required( $msg ) );
	}

	public function test_extract_missing_required_returns_empty_for_empty_string(): void {
		$this->assertSame( array(), SchemaExampleBuilder::extract_missing_required( '' ) );
	}
}
