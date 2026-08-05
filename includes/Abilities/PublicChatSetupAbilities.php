<?php

declare(strict_types=1);
/**
 * Public customer chat setup abilities.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\Settings;
use SdAiAgent\Knowledge\KnowledgeDatabase;
use SdAiAgent\REST\RestController;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets an administrator ask the AI agent to inspect/configure public chat.
 */
class PublicChatSetupAbilities {

	/** Safe abilities exposed to anonymous/customer public chat by this setup tool. */
	private const SAFE_PUBLIC_ABILITIES = array( 'sd-ai-agent/knowledge-search' );

	/** Register public chat setup abilities. */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/public-chat-setup',
			array(
				'label'               => __( 'Setup Customer Chat', 'superdav-ai-agent' ),
				'description'         => __( 'Inspect, configure, disable, or generate the embed snippet for the public customer documentation chat. Use this when an administrator asks the AI agent to set up the frontend chat interface for customers.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'action'             => array(
							'type'        => 'string',
							'description' => 'Action to perform: status, configure, disable, or snippet.',
							'enum'        => array( 'status', 'configure', 'disable', 'snippet' ),
						),
						'enabled'            => array(
							'type'        => 'boolean',
							'description' => 'Whether public chat should be enabled during configure. Defaults to the currently saved state when omitted.',
						),
						'origins'            => array(
							'type'        => 'array',
							'description' => 'Allowed public docs/front-end origins such as https://docs.example.com. Empty means same-origin only.',
							'items'       => array( 'type' => 'string' ),
						),
						'collection_slugs'   => array(
							'type'        => 'array',
							'description' => 'Existing knowledge collection slugs allowed for anonymous docs answers.',
							'items'       => array( 'type' => 'string' ),
						),
						'provider_id'        => array(
							'type'        => 'string',
							'description' => 'Optional provider ID to force for public chat. Omit to use the saved public/default provider.',
						),
						'model_id'           => array(
							'type'        => 'string',
							'description' => 'Optional model ID to force for public chat. Omit to use the saved public/default model.',
						),
						'agent_id'           => array(
							'type'        => 'integer',
							'description' => 'Optional fixed public agent ID. Defaults to the saved value or 0.',
						),
						'embed_id'           => array(
							'type'        => 'string',
							'description' => 'Public embed identifier. Defaults to docs.',
						),
						'rate_limit_per_min' => array(
							'type'        => 'integer',
							'description' => 'Per-session/IP public message limit per minute, 1–60.',
						),
						'message_max_length' => array(
							'type'        => 'integer',
							'description' => 'Maximum customer message length, 1–8000 characters.',
						),
						'max_iterations'     => array(
							'type'        => 'integer',
							'description' => 'Maximum tool-calling iterations for public chat, 1–8.',
						),
					),
					'required'   => array( 'action' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'status'        => array( 'type' => 'string' ),
						'configured'    => array( 'type' => 'boolean' ),
						'settings'      => array( 'type' => 'object' ),
						'checks'        => array( 'type' => 'object' ),
						'warnings'      => array( 'type' => 'array' ),
						'next_steps'    => array( 'type' => 'array' ),
						'embed_snippet' => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'mcp'         => array( 'public' => true ),
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
				'execute_callback'    => array( __CLASS__, 'handle_public_chat_setup' ),
				'permission_callback' => static function (): bool {
					return ToolCapabilities::current_user_can( 'sd-ai-agent/public-chat-setup' );
				},
			)
		);
	}

	/**
	 * Handle public chat setup requests.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function handle_public_chat_setup( array $input ): array|WP_Error {
		$settings = Settings::instance();
		$action   = sanitize_key( (string) ( $input['action'] ?? 'status' ) );

		switch ( $action ) {
			case 'status':
				return self::build_response( $settings, 'status' );

			case 'snippet':
				return self::build_response( $settings, 'snippet' );

			case 'disable':
				$settings->update( array( 'public_chat_enabled' => false ) );
				return self::build_response( $settings, 'disabled' );

			case 'configure':
				$configured = self::configure_public_chat( $settings, $input );
				if ( is_wp_error( $configured ) ) {
					return $configured;
				}
				return self::build_response( $settings, 'configured' );
		}

		return new WP_Error(
			'sd_ai_agent_public_chat_setup_invalid_action',
			__( 'Invalid public chat setup action.', 'superdav-ai-agent' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Configure public chat settings from ability input.
	 *
	 * @param Settings            $settings Settings service.
	 * @param array<string,mixed> $input    Ability input.
	 * @return true|WP_Error
	 */
	private static function configure_public_chat( Settings $settings, array $input ): true|WP_Error {
		$current = self::public_chat_settings( $settings );
		$enabled = array_key_exists( 'enabled', $input ) ? (bool) $input['enabled'] : (bool) $current['public_chat_enabled'];
		$updates = array(
			'public_chat_enabled'           => $enabled,
			'public_chat_allowed_abilities' => self::SAFE_PUBLIC_ABILITIES,
		);

		if ( array_key_exists( 'origins', $input ) ) {
			$origins = self::sanitize_origins( $input['origins'] );
			if ( is_wp_error( $origins ) ) {
				return $origins;
			}
			$updates['public_chat_allowed_origins'] = $origins;
		}

		if ( array_key_exists( 'collection_slugs', $input ) ) {
			$collections = self::sanitize_string_list( $input['collection_slugs'], 'sanitize_key' );
			$missing     = self::missing_collection_slugs( $collections );
			if ( ! empty( $missing ) ) {
				return new WP_Error(
					'sd_ai_agent_public_chat_setup_missing_collection',
					sprintf(
						/* translators: %s: comma-separated collection slugs. */
						__( 'Public chat setup cannot continue because these knowledge collections do not exist: %s', 'superdav-ai-agent' ),
						implode( ', ', $missing )
					),
					array( 'status' => 400 )
				);
			}
			$updates['public_chat_collection_ids'] = $collections;
		}

		$effective_collections = $updates['public_chat_collection_ids'] ?? $current['public_chat_collection_ids'];
		if ( ! is_array( $effective_collections ) || empty( $effective_collections ) ) {
			return new WP_Error(
				'sd_ai_agent_public_chat_setup_collection_required',
				__( 'Choose at least one existing knowledge collection before enabling public customer chat.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		foreach ( array( 'provider_id', 'model_id' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$updates[ 'public_chat_' . $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		if ( array_key_exists( 'agent_id', $input ) ) {
			$updates['public_chat_agent_id'] = absint( $input['agent_id'] );
		}

		if ( array_key_exists( 'embed_id', $input ) ) {
			$embed_id = sanitize_key( (string) $input['embed_id'] );
			if ( '' !== $embed_id ) {
				$updates['public_chat_embed_id'] = $embed_id;
			}
		}

		$int_fields = array(
			'rate_limit_per_min' => array( 'public_chat_rate_limit_per_min', 1, 60 ),
			'message_max_length' => array( 'public_chat_message_max_length', 1, 8000 ),
			'max_iterations'     => array( 'public_chat_max_iterations', 1, 8 ),
		);
		foreach ( $int_fields as $input_key => $spec ) {
			if ( array_key_exists( $input_key, $input ) ) {
				$updates[ $spec[0] ] = max( (int) $spec[1], min( (int) $spec[2], (int) $input[ $input_key ] ) );
			}
		}

		$settings->update( $updates );
		return true;
	}

	/**
	 * Build a status/configuration response.
	 *
	 * @param Settings $settings Settings service.
	 * @param string   $status   Response status.
	 * @return array<string,mixed>
	 */
	private static function build_response( Settings $settings, string $status ): array {
		$public_settings = self::public_chat_settings( $settings );
		$checks          = self::build_checks( $settings, $public_settings );
		$warnings        = self::build_warnings( $checks, $public_settings );

		return array(
			'status'        => $status,
			'configured'    => self::is_configured( $public_settings, $checks ),
			'settings'      => $public_settings,
			'checks'        => $checks,
			'warnings'      => $warnings,
			'next_steps'    => self::build_next_steps( $warnings, $public_settings ),
			'embed_snippet' => self::build_embed_snippet( $public_settings ),
		);
	}

	/**
	 * Return public-chat settings only, with no secret-bearing settings exposed.
	 *
	 * @param Settings $settings Settings service.
	 * @return array<string,mixed>
	 */
	private static function public_chat_settings( Settings $settings ): array {
		$all = $settings->get();

		return array(
			'public_chat_enabled'            => (bool) ( $all['public_chat_enabled'] ?? false ),
			'public_chat_embed_id'           => sanitize_key( (string) ( $all['public_chat_embed_id'] ?? 'docs' ) ),
			'public_chat_allowed_origins'    => self::sanitize_string_list( $all['public_chat_allowed_origins'] ?? array(), 'sanitize_text_field' ),
			'public_chat_provider_id'        => sanitize_text_field( (string) ( $all['public_chat_provider_id'] ?? '' ) ),
			'public_chat_model_id'           => sanitize_text_field( (string) ( $all['public_chat_model_id'] ?? '' ) ),
			'public_chat_agent_id'           => absint( $all['public_chat_agent_id'] ?? 0 ),
			'public_chat_collection_ids'     => self::sanitize_string_list( $all['public_chat_collection_ids'] ?? array(), 'sanitize_key' ),
			'public_chat_allowed_abilities'  => self::sanitize_string_list( $all['public_chat_allowed_abilities'] ?? self::SAFE_PUBLIC_ABILITIES, 'sanitize_text_field' ),
			'public_chat_max_iterations'     => max( 1, min( 8, (int) ( $all['public_chat_max_iterations'] ?? 4 ) ) ),
			'public_chat_message_max_length' => max( 1, min( 8000, (int) ( $all['public_chat_message_max_length'] ?? 2000 ) ) ),
			'public_chat_rate_limit_per_min' => max( 1, min( 60, (int) ( $all['public_chat_rate_limit_per_min'] ?? 10 ) ) ),
		);
	}

	/**
	 * Build setup checks.
	 *
	 * @param Settings            $settings        Settings service.
	 * @param array<string,mixed> $public_settings Public settings.
	 * @return array<string,mixed>
	 */
	private static function build_checks( Settings $settings, array $public_settings ): array {
		$all                = $settings->get();
		$effective_provider = (string) ( $public_settings['public_chat_provider_id'] ?: ( $all['default_provider'] ?? '' ) );
		$effective_model    = (string) ( $public_settings['public_chat_model_id'] ?: ( $all['default_model'] ?? '' ) );
		$collections        = self::sanitize_string_list( $public_settings['public_chat_collection_ids'] ?? array(), 'sanitize_key' );
		$missing            = self::missing_collection_slugs( $collections );

		$safe_abilities = self::SAFE_PUBLIC_ABILITIES === ( $public_settings['public_chat_allowed_abilities'] ?? array() );

		return array(
			'enabled'                      => (bool) $public_settings['public_chat_enabled'],
			'effective_provider_id'        => sanitize_text_field( $effective_provider ),
			'effective_model_id'           => sanitize_text_field( $effective_model ),
			'provider_configured'          => '' !== $effective_provider,
			'model_configured'             => '' !== $effective_model,
			'collections_configured'       => ! empty( $collections ) && empty( $missing ),
			'missing_collection_slugs'     => array_values( $missing ),
			'allowed_origin_count'         => is_array( $public_settings['public_chat_allowed_origins'] ) ? count( $public_settings['public_chat_allowed_origins'] ) : 0,
			'safe_ability_allowlist'       => $safe_abilities,
			'uses_public_token_flow'       => true,
			'uses_customer_simple_ui'      => true,
			'sends_credentials_from_embed' => false,
		);
	}

	/**
	 * Determine whether public chat has enough configuration to run.
	 *
	 * @param array<string,mixed> $public_settings Public settings.
	 * @param array<string,mixed> $checks          Setup checks.
	 */
	private static function is_configured( array $public_settings, array $checks ): bool {
		return ! empty( $public_settings['public_chat_enabled'] )
			&& ! empty( $checks['provider_configured'] )
			&& ! empty( $checks['model_configured'] )
			&& ! empty( $checks['collections_configured'] )
			&& ! empty( $checks['safe_ability_allowlist'] );
	}

	/**
	 * Build warnings for incomplete setup.
	 *
	 * @param array<string,mixed> $checks          Setup checks.
	 * @param array<string,mixed> $public_settings Public settings.
	 * @return list<string>
	 */
	private static function build_warnings( array $checks, array $public_settings ): array {
		$warnings = array();
		if ( empty( $checks['provider_configured'] ) || empty( $checks['model_configured'] ) ) {
			$warnings[] = __( 'Choose a public provider and model, or configure global defaults, before sending customer traffic.', 'superdav-ai-agent' );
		}
		if ( empty( $checks['collections_configured'] ) ) {
			$warnings[] = __( 'Choose at least one existing documentation knowledge collection.', 'superdav-ai-agent' );
		}
		if ( empty( $checks['allowed_origin_count'] ) ) {
			$warnings[] = __( 'No explicit allowed origins are configured; public chat will only work for same-origin frontend pages.', 'superdav-ai-agent' );
		}
		if ( self::SAFE_PUBLIC_ABILITIES !== ( $public_settings['public_chat_allowed_abilities'] ?? array() ) ) {
			$warnings[] = __( 'The setup tool forces the anonymous ability allowlist back to knowledge-search only.', 'superdav-ai-agent' );
		}

		return $warnings;
	}

	/**
	 * Build next-step guidance.
	 *
	 * @param list<string>        $warnings        Warnings.
	 * @param array<string,mixed> $public_settings Public settings.
	 * @return list<string>
	 */
	// phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<string> is valid PHPStan but not a native PHP type.
	private static function build_next_steps( array $warnings, array $public_settings ): array {
		$steps = array();
		if ( ! empty( $warnings ) ) {
			$steps[] = __( 'Fix the warnings, then rerun this ability with action=status.', 'superdav-ai-agent' );
		}

		$steps[] = __( 'Build the plugin assets with pnpm run build and copy build/embed-widget.js plus build/style-embed-widget.css to the docs site.', 'superdav-ai-agent' );
		$steps[] = __( 'Embed the returned snippet on the customer/docs frontend.', 'superdav-ai-agent' );
		$steps[] = __( 'Run a logged-out browser smoke test with a real docs question and confirm only public-chat endpoints are called.', 'superdav-ai-agent' );

		if ( ! empty( $public_settings['public_chat_allowed_origins'] ) ) {
			$steps[] = __( 'Verify a non-allowlisted Origin receives sd_ai_agent_public_chat_origin_forbidden from /public-chat/session.', 'superdav-ai-agent' );
		}

		return $steps;
	}

	/**
	 * Build static-site embed snippet.
	 *
	 * @param array<string,mixed> $public_settings Public settings.
	 */
	private static function build_embed_snippet( array $public_settings ): string {
		$collections = is_array( $public_settings['public_chat_collection_ids'] ) ? $public_settings['public_chat_collection_ids'] : array();
		$collection  = ! empty( $collections ) ? (string) $collections[0] : 'docs';
		$embed_id    = (string) ( $public_settings['public_chat_embed_id'] ?: 'docs' );
		$api_base    = esc_url( rest_url( RestController::NAMESPACE ) );

		return sprintf(
			'<' . "script\n  src=\"/assets/superdav/embed-widget.js\"\n  data-api-base=\"%s\"\n  data-embed-id=\"%s\"\n  data-theme=\"light\"\n  data-locale=\"en\"\n  data-collection=\"%s\"\n  defer\n></" . 'script>',
			$api_base,
			esc_attr( $embed_id ),
			esc_attr( $collection )
		);
	}

	/**
	 * Sanitize a list-like value.
	 *
	 * @param mixed    $value    Raw value.
	 * @param callable $sanitize Sanitizer callback.
	 * @return list<string>
	 */
	private static function sanitize_string_list( mixed $value, callable $sanitize ): array {
		$items = is_array( $value ) ? $value : ( '' === (string) $value ? array() : array( $value ) );
		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$sanitized = (string) $sanitize( (string) $item );
			if ( '' !== $sanitized && ! in_array( $sanitized, $clean, true ) ) {
				$clean[] = $sanitized;
			}
		}

		return $clean;
	}

	/**
	 * Sanitize allowed origins.
	 *
	 * @param mixed $value Raw origin list.
	 * @return list<string>|WP_Error
	 */
	private static function sanitize_origins( mixed $value ): array|WP_Error {
		$origins = self::sanitize_string_list( $value, 'sanitize_text_field' );
		$clean   = array();

		foreach ( $origins as $origin ) {
			$parts = wp_parse_url( $origin );
			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return new WP_Error( 'sd_ai_agent_public_chat_setup_invalid_origin', __( 'Allowed origins must include a scheme and host, for example https://docs.example.com.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
			}

			$scheme = strtolower( (string) $parts['scheme'] );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return new WP_Error( 'sd_ai_agent_public_chat_setup_invalid_origin', __( 'Allowed origins must use http or https.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
			}

			$host       = strtolower( (string) $parts['host'] );
			$port       = isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
			$normalized = $scheme . '://' . $host . $port;
			if ( ! in_array( $normalized, $clean, true ) ) {
				$clean[] = $normalized;
			}
		}

		return $clean;
	}

	/**
	 * Return missing knowledge collection slugs.
	 *
	 * @param list<string> $slugs Collection slugs.
	 * @return list<string>
	 */
	// phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<string> is valid PHPStan but not a native PHP type.
	private static function missing_collection_slugs( array $slugs ): array {
		$missing = array();
		foreach ( $slugs as $slug ) {
			if ( ! KnowledgeDatabase::get_collection_by_slug( $slug ) ) {
				$missing[] = $slug;
			}
		}

		return $missing;
	}
}
