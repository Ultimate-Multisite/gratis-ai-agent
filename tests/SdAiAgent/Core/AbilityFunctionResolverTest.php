<?php
/**
 * Tests for the ability function resolver wrapper.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Abilities\KnowledgeAbilities;
use SdAiAgent\Core\AbilityRegistry;
use SdAiAgent\Core\AbilityFunctionResolver;
use SdAiAgent\Core\ConversationTrimmer;
use SdAiAgent\Core\IdenticalFailureTracker;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WP_UnitTestCase;

final class ThrowingValidationAbility extends \WP_Ability {

	/**
	 * @param mixed $input Input passed by the resolver.
	 * @return mixed
	 */
	public function execute( $input = null ) {
		unset( $input );
		throw new \InvalidArgumentException( 'query is a required property of input.' );
	}
}

class AbilityFunctionResolverTest extends WP_UnitTestCase {

	/**
	 * Ability category slugs registered during a test.
	 *
	 * @var string[]
	 */
	private array $registered_test_categories = array();

	public function set_up(): void {
		parent::set_up();
		IdenticalFailureTracker::reset();
	}

	public function tear_down(): void {
		IdenticalFailureTracker::reset();
		if ( function_exists( 'wp_unregister_ability' ) ) {
			wp_unregister_ability( 'test-plugin/schema-thrower' );
		}
		if ( function_exists( 'wp_unregister_ability_category' ) ) {
			foreach ( $this->registered_test_categories as $category_slug ) {
				wp_unregister_ability_category( $category_slug );
			}
		}
		$this->registered_test_categories = array();
		parent::tear_down();
	}

	public function test_validation_exceptions_return_schema_guidance(): void {
		$this->skip_if_resolver_unavailable();

		$ability = $this->register_schema_thrower_ability();
		$this->assertNotNull( $ability );

		$resolver = new AbilityFunctionResolver( $ability );
		$response = $resolver->execute_ability(
			new FunctionCall(
				'call_validation_exception',
				\WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( 'test-plugin/schema-thrower' ),
				array( 'query' => 'trigger callback validation exception' )
			)
		);

		$payload = $this->normalise_response_payload( $response->getResponse() );

		$this->assertSame( 'ability_invalid_input', $payload['code'] );
		$this->assertArrayHasKey( 'input_schema', $payload );
		$this->assertSame( array( 'query' ), $payload['missing_required_fields'] );
		$this->assertSame( array( 'query' => '<string — Search keywords. Required.>' ), $payload['example_arguments'] );
		$this->assertStringContainsString( 'Do not retry with empty arguments', $payload['hint'] );
	}

	public function test_repeated_validation_exceptions_return_hard_nudge(): void {
		$this->skip_if_resolver_unavailable();

		$ability = $this->register_schema_thrower_ability();
		$this->assertNotNull( $ability );

		$resolver      = new AbilityFunctionResolver( $ability );
		$function_name = \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( 'test-plugin/schema-thrower' );

		$args = array( 'query' => 'trigger callback validation exception' );

		$resolver->execute_ability( new FunctionCall( 'call_validation_exception_1', $function_name, $args ) );
		$response = $resolver->execute_ability( new FunctionCall( 'call_validation_exception_2', $function_name, $args ) );

		$payload = $this->normalise_response_payload( $response->getResponse() );

		$this->assertArrayHasKey( 'nudge', $payload );
		$this->assertStringContainsString( 'STOP', $payload['nudge'] );
		$this->assertStringContainsString( 'test-plugin/schema-thrower', $payload['nudge'] );
		$this->assertStringContainsString( 'Retry only with arguments', $payload['nudge'] );
	}

	public function test_empty_arguments_return_schema_guidance_without_dispatching(): void {
		$this->skip_if_resolver_unavailable();

		$ability = $this->register_schema_thrower_ability();
		$this->assertNotNull( $ability );

		$resolver = new AbilityFunctionResolver( $ability );
		$response = $resolver->execute_ability(
			new FunctionCall(
				'call_empty_arguments',
				\WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( 'test-plugin/schema-thrower' ),
				null
			)
		);

		$payload = $this->normalise_response_payload( $response->getResponse() );

		$this->assertSame( 'ability_invalid_input', $payload['code'] );
		$this->assertSame( array( 'query' ), $payload['missing_required_fields'] );
		$this->assertSame( array( 'query' => '<string — Search keywords. Required.>' ), $payload['example_arguments'] );
		$this->assertStringContainsString( 'Do not retry with empty arguments', $payload['hint'] );
	}

	public function test_idless_function_call_response_remains_in_gemini_history(): void {
		$this->skip_if_resolver_unavailable();

		$ability = $this->register_schema_thrower_ability();
		$this->assertNotNull( $ability );

		$function_name = \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( 'test-plugin/schema-thrower' );
		$call          = new FunctionCall( null, $function_name, array( 'query' => 'trigger callback validation exception' ) );
		$resolver      = new AbilityFunctionResolver( $ability );
		$response      = $resolver->execute_ability( $call );

		$this->assertNull( $call->getId() );
		$this->assertSame( '', $response->getId() );

		$history = array(
			new ModelMessage( array( new MessagePart( $call ) ) ),
			new UserMessage( array( new MessagePart( $response ) ) ),
		);

		$this->assertCount( 2, ConversationTrimmer::validate_tool_pairs( $history ) );
	}

	public function test_public_knowledge_search_hydrates_empty_args_from_customer_query(): void {
		$this->skip_if_resolver_unavailable();
		$this->ensure_knowledge_search_registered();

		KnowledgeAbilities::set_public_collection_allowlist( array( 'docs' ), 'How do I embed the customer widget?' );

		try {
			$resolver = new AbilityFunctionResolver( 'sd-ai-agent/knowledge-search' );
			$response = $resolver->execute_ability(
				new FunctionCall(
					'call_public_knowledge_empty_args',
					\WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( 'sd-ai-agent/knowledge-search' ),
					null
				)
			);
		} finally {
			KnowledgeAbilities::clear_public_collection_allowlist();
		}

		$payload = $this->normalise_response_payload( $response->getResponse() );

		$this->assertArrayHasKey( 'results', $payload );
		$this->assertArrayNotHasKey( 'code', $payload );
	}

	/**
	 * Failed batch block updates expose only safe, itemized repair context.
	 */
	public function test_update_blocks_validation_failure_includes_safe_recovery_details(): void {
		$this->skip_if_resolver_unavailable();
		$this->ensure_block_abilities_registered();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create(
			array( 'post_content' => '<!-- wp:paragraph --><p>Existing content.</p><!-- /wp:paragraph -->' )
		);
		$resolver = new AbilityFunctionResolver( 'sd-ai-agent/update-blocks' );
		$response = $resolver->execute_ability(
			new FunctionCall(
				'call_update_blocks_validation_failure',
				\WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( 'sd-ai-agent/update-blocks' ),
				array(
					'post_id' => $post_id,
					'updates' => array(
						array(
							'op'         => 'update-attrs',
							'ref'        => 'blk_missing',
							'attributes' => array( 'dropCap' => true ),
						),
					),
				)
			)
		);

		$payload = $this->normalise_response_payload( $response->getResponse() );

		$this->assertSame( 'batch_validation_failed', $payload['code'] );
		$this->assertSame( 400, $payload['details']['status'] );
		$this->assertSame( 0, $payload['details']['errors'][0]['index'] );
		$this->assertSame( 'block_not_found', $payload['details']['errors'][0]['code'] );
		$this->assertArrayHasKey( 'message', $payload['details']['errors'][0] );
		$this->assertNotEmpty( $payload['details']['errors'][0]['message'] );
		$this->assertSame( 'update-attrs', $payload['details']['errors'][0]['op'] );
		$this->assertSame( 'blk_missing', $payload['details']['errors'][0]['ref'] );
		$this->assertArrayNotHasKey( 'attributes', $payload['details']['errors'][0] );
	}

	private function skip_if_resolver_unavailable(): void {
		if (
			! class_exists( 'WP_AI_Client_Ability_Function_Resolver' )
			|| ! class_exists( FunctionCall::class )
			|| ! function_exists( 'wp_register_ability' )
		) {
			$this->markTestSkipped( 'WordPress AI Client ability resolver is unavailable.' );
		}
	}

	private function register_schema_thrower_ability(): ?\WP_Ability {
		$this->ensure_test_category( 'test-plugin' );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress hook stack global.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';

		try {
			return wp_register_ability(
				'test-plugin/schema-thrower',
				array(
					'ability_class'       => ThrowingValidationAbility::class,
					'label'               => 'Schema Thrower',
					'description'         => 'Test ability that throws a WordPress input validation-shaped error.',
					'category'            => 'test-plugin',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'query' => array(
								'type'        => 'string',
								'description' => 'Search keywords. Required.',
							),
						),
						'required'   => array( 'query' ),
					),
					'execute_callback'    => static function ( array $input ): array {
						unset( $input );
						return array( 'unused' => true );
					},
					'permission_callback' => static function (): bool {
						return true;
					},
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	private function ensure_knowledge_search_registered(): void {
		if ( AbilityRegistry::get( 'sd-ai-agent/knowledge-search' ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress hook stack global.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';

		try {
			KnowledgeAbilities::register_abilities();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	private function ensure_block_abilities_registered(): void {
		if ( AbilityRegistry::get( 'sd-ai-agent/update-blocks' ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress hook stack global.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';

		try {
			BlockAbilities::register_abilities();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	private function ensure_test_category( string $slug ): void {
		if (
			! function_exists( 'wp_register_ability_category' )
			|| ! function_exists( 'wp_has_ability_category' )
			|| wp_has_ability_category( $slug )
		) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress hook stack global.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';

		try {
			wp_register_ability_category(
				$slug,
				array(
					'label'       => 'Test Category ' . $slug,
					'description' => 'Auto-registered by AbilityFunctionResolverTest for ' . $slug,
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->registered_test_categories[] = $slug;
	}

	/**
	 * @param mixed $payload Raw FunctionResponse payload.
	 * @return array<string, mixed>
	 */
	private function normalise_response_payload( $payload ): array {
		if ( is_string( $payload ) ) {
			$decoded = json_decode( $payload, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $payload ) ? $payload : array();
	}
}
