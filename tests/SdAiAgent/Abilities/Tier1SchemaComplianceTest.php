<?php
/**
 * Tier-1 schema compliance regression guard.
 *
 * Anthropic's Claude tool-use API validates `input_schema` against JSON Schema
 * draft 2020-12 and rejects keywords that are merely "valid JSON Schema" in
 * older drafts but are not supported in its strict subset. In particular,
 * `oneOf` / `anyOf` / `allOf` inside an `items` schema produces a 400
 * "tools.N.custom.input_schema: JSON schema is invalid" response, which fails
 * the cold-start `/v1/run` call before the LLM is ever invoked.
 *
 * See issue #1425 for the original incident: `oneOf: [string, integer]`
 * inside `categories.items` in `create-post`, `update-post`, and
 * `bulk-update-posts` schemas. The fix is to use the native type-array form:
 *
 *     'items' => [ 'type' => [ 'string', 'integer' ] ]
 *
 * This test is a source-level guard against any future regression that
 * reintroduces a forbidden composition keyword inside an ability schema in
 * `includes/Abilities/`. It runs without a WordPress bootstrap and is
 * therefore cheap to keep in every PR pipeline.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Abilities;

use PHPUnit\Framework\TestCase;

/**
 * Pure-PHP source-scan test — no WP_UnitTestCase needed.
 */
final class Tier1SchemaComplianceTest extends TestCase {

	/**
	 * Forbidden top-level JSON Schema composition keywords.
	 *
	 * These are technically valid JSON Schema but are rejected by Claude's
	 * draft 2020-12 validator when used inside tool input schemas.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_KEYWORDS = array( 'oneOf', 'anyOf', 'allOf' );

	/**
	 * Test that no ability source file in `includes/Abilities/` uses a
	 * forbidden composition keyword.
	 *
	 * We do a simple textual scan rather than full AST parsing because the
	 * keywords are highly specific JSON Schema identifiers — false positives
	 * would have to be `'oneOf'` / `'anyOf'` / `'allOf'` written as PHP string
	 * literals, which is almost certainly an ability schema.
	 *
	 * If a legitimate non-schema use ever appears, exclude that file by path.
	 */
	public function test_no_forbidden_keywords_in_ability_schemas(): void {
		$abilities_dir = dirname( __DIR__, 3 ) . '/includes/Abilities';

		$this->assertDirectoryExists( $abilities_dir );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $abilities_dir, \FilesystemIterator::SKIP_DOTS )
		);

		$violations = array();

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			foreach ( self::FORBIDDEN_KEYWORDS as $keyword ) {
				// Match the keyword as a quoted PHP array key, which is the
				// only form that appears in ability schema definitions.
				$pattern = "/['\"]" . preg_quote( $keyword, '/' ) . "['\"]\s*=>/";
				if ( preg_match( $pattern, $contents ) ) {
					$violations[] = sprintf(
						'%s contains forbidden JSON Schema keyword "%s" — Claude 2020-12 rejects this. Use a type-array union, e.g. [ "type" => [ "string", "integer" ] ].',
						$file->getPathname(),
						$keyword
					);
				}
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"Ability schemas must remain Claude 2020-12 compliant:\n" . implode( "\n", $violations )
		);
	}
}
