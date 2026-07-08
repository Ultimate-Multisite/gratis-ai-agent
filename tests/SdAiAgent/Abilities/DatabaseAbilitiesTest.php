<?php
/**
 * Test case for DatabaseAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\DatabaseAbilities;
use WP_UnitTestCase;

/**
 * Test DatabaseAbilities handler methods.
 */
class DatabaseAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Test handle_db_query with valid SELECT returns results.
	 */
	public function test_handle_db_query_valid_select() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql' => 'SELECT 1 AS value',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'query', $result );
		$this->assertArrayHasKey( 'rows', $result );
		$this->assertArrayHasKey( 'count', $result );
		$this->assertIsArray( $result['rows'] );
		$this->assertIsInt( $result['count'] );
	}

	/**
	 * Test handle_db_query returns correct row count.
	 */
	public function test_handle_db_query_row_count() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql' => 'SELECT 1 AS value',
		] );

		$this->assertSame( 1, $result['count'] );
		$this->assertCount( 1, $result['rows'] );
	}

	/**
	 * Test handle_db_query replaces {prefix} placeholder.
	 */
	public function test_handle_db_query_prefix_substitution() {
		global $wpdb;

		$result = DatabaseAbilities::handle_db_query( [
			'sql'    => 'SELECT option_name FROM {prefix}options WHERE option_name = %s LIMIT 1',
			'params' => [ 'siteurl' ],
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'rows', $result );
		// The query should have been executed (prefix replaced).
		$this->assertStringContainsString( $wpdb->prefix . 'options', $result['query'] );
		$this->assertStringNotContainsString( '{prefix}', $result['query'] );
	}

	/**
	 * Test handle_db_query with real WordPress table.
	 */
	public function test_handle_db_query_real_table() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql'    => 'SELECT option_name FROM {prefix}options WHERE option_name = %s LIMIT 1',
			'params' => [ 'siteurl' ],
		] );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'siteurl', $result['rows'][0]['option_name'] );
	}

	/**
	 * Test handle_db_query rejects non-SELECT queries.
	 */
	public function test_handle_db_query_rejects_insert() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql' => 'INSERT INTO wp_options (option_name) VALUES ("test")',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_sql_not_select', $result->get_error_code() );
	}

	/**
	 * Test handle_db_query rejects UPDATE queries.
	 */
	public function test_handle_db_query_rejects_update() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql' => 'UPDATE wp_options SET option_value = "x" WHERE option_name = "siteurl"',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_sql_not_select', $result->get_error_code() );
	}

	/**
	 * Test handle_db_query rejects DELETE queries.
	 */
	public function test_handle_db_query_rejects_delete() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql' => 'DELETE FROM wp_options WHERE option_name = "test"',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_sql_not_select', $result->get_error_code() );
	}

	/**
	 * Test handle_db_query rejects DROP queries.
	 */
	public function test_handle_db_query_rejects_drop() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql' => 'DROP TABLE wp_options',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_sql_not_select', $result->get_error_code() );
	}

	/**
	 * Test handle_db_query with empty SQL returns WP_Error.
	 */
	public function test_handle_db_query_empty_sql() {
		$result = DatabaseAbilities::handle_db_query( [ 'sql' => '' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_empty_sql', $result->get_error_code() );
	}

	/**
	 * Test handle_db_query with missing sql key returns WP_Error.
	 */
	public function test_handle_db_query_missing_sql() {
		$result = DatabaseAbilities::handle_db_query( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test handle_db_query with whitespace-only SQL returns WP_Error.
	 */
	public function test_handle_db_query_whitespace_sql() {
		$result = DatabaseAbilities::handle_db_query( [ 'sql' => '   ' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_empty_sql', $result->get_error_code() );
	}

	/**
	 * Test handle_db_query returns query in result.
	 */
	public function test_handle_db_query_returns_executed_query() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql' => 'SELECT 1 AS test_col',
		] );

		$this->assertArrayHasKey( 'query', $result );
		$this->assertStringContainsString( 'SELECT', $result['query'] );
	}

	/**
	 * Test handle_db_query with SELECT that returns no rows.
	 */
	public function test_handle_db_query_empty_result() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql'    => 'SELECT option_name FROM {prefix}options WHERE option_name = %s LIMIT 1',
			'params' => [ 'nonexistent_option_xyz_12345' ],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['count'] );
		$this->assertIsArray( $result['rows'] );
		$this->assertEmpty( $result['rows'] );
	}

	/**
	 * Test handle_db_query rejects a SELECT that names a secret option
	 * as a single-quoted string literal.
	 */
	public function test_handle_db_query_rejects_secret_literal_single_quoted() {
		update_option( 'auth_key', 'do-not-leak-this-secret' );

		$result = DatabaseAbilities::handle_db_query( [
			'sql'    => 'SELECT option_value FROM {prefix}options WHERE option_name = %s',
			'params' => [ 'auth_key' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_sql_secret_literal', $result->get_error_code() );

		delete_option( 'auth_key' );
	}

	/**
	 * Test handle_db_query rejects a SELECT that names a secret option
	 * as a double-quoted string literal.
	 */
	public function test_handle_db_query_rejects_secret_literal_double_quoted() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql'    => 'SELECT option_value FROM {prefix}options WHERE option_name = %s',
			'params' => [ 'secure_auth_salt' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_sql_secret_literal', $result->get_error_code() );
	}

	/**
	 * Test handle_db_query scrubs option_value in any row whose option_name
	 * is on the secret read blocklist (catches `SELECT *` wildcard reads).
	 */
	public function test_handle_db_query_scrubs_secret_value_in_wildcard_row() {
		update_option( 'auth_key', 'do-not-leak-this-secret' );

		// SELECT * does not name `auth_key` as a literal, so the pre-check
		// allows it; the post-process must still redact the value.
		$result = DatabaseAbilities::handle_db_query( [
			'sql'    => 'SELECT option_name, option_value, autoload FROM {prefix}options WHERE option_name LIKE %s LIMIT 5',
			'params' => [ 'auth_%' ],
		] );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['rows'] );

		$encoded = wp_json_encode( $result );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( 'do-not-leak-this-secret', $encoded );

		$found_redacted = false;
		foreach ( $result['rows'] as $row ) {
			if ( ( $row['option_name'] ?? '' ) === 'auth_key' ) {
				$this->assertSame(
					\SdAiAgent\Abilities\OptionsAbilities::SECRET_REDACTED_PLACEHOLDER,
					$row['option_value']
				);
				$found_redacted = true;
			}
		}
		$this->assertTrue( $found_redacted, 'Expected auth_key row to be redacted by the post-process.' );

		delete_option( 'auth_key' );
	}

	/**
	 * Test handle_db_query still returns non-secret rows untouched.
	 */
	public function test_handle_db_query_does_not_scrub_safe_rows() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql'    => 'SELECT option_name, option_value FROM {prefix}options WHERE option_name = %s LIMIT 1',
			'params' => [ 'blogname' ],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'blogname', $result['rows'][0]['option_name'] );
		$this->assertNotSame(
			\SdAiAgent\Abilities\OptionsAbilities::SECRET_REDACTED_PLACEHOLDER,
			$result['rows'][0]['option_value']
		);
	}

	/**
	 * Filter-extended secret names must also be enforced by db-query.
	 */
	public function test_handle_db_query_honours_filter_extended_secret_literal() {
		add_filter(
			'sd_ai_agent_options_read_blocklist',
			static function ( array $list ): array {
				$list[] = 'third_party_api_token';
				return $list;
			}
		);

		$result = DatabaseAbilities::handle_db_query( [
			'sql'    => 'SELECT option_value FROM {prefix}options WHERE option_name = %s',
			'params' => [ 'third_party_api_token' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_sql_secret_literal', $result->get_error_code() );

		remove_all_filters( 'sd_ai_agent_options_read_blocklist' );
	}

	/**
	 * Test handle_db_query rejects raw string literals that are not passed via params.
	 */
	public function test_handle_db_query_rejects_unprepared_string_literal() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql' => "SELECT option_name FROM {prefix}options WHERE option_name = 'blogname' LIMIT 1",
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_sql_unprepared_literal', $result->get_error_code() );
	}

	/**
	 * Test handle_db_query rejects mismatched placeholders and params.
	 */
	public function test_handle_db_query_rejects_placeholder_param_mismatch() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql'    => 'SELECT option_name FROM {prefix}options WHERE option_name = %s AND autoload = %s',
			'params' => [ 'blogname' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_sql_placeholder_mismatch', $result->get_error_code() );
	}

	/**
	 * Test handle_db_query rejects the expensive knowledge count join shape.
	 */
	public function test_handle_db_query_rejects_expensive_knowledge_count_join() {
		$result = DatabaseAbilities::handle_db_query( [
			'sql'    => 'SELECT col.slug, COUNT(DISTINCT s.id) AS sources, COUNT(c.id) AS chunks FROM {prefix}sd_ai_agent_knowledge_collections col LEFT JOIN {prefix}sd_ai_agent_knowledge_sources s ON s.collection_id = col.id LEFT JOIN {prefix}sd_ai_agent_knowledge_chunks c ON c.collection_id = col.id WHERE col.slug IN (%s, %s, %s) GROUP BY col.slug ORDER BY col.slug',
			'params' => [ 'docs', 'kb', 'public' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_sql_expensive_knowledge_count_join', $result->get_error_code() );
	}
}
