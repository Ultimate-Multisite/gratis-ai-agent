<?php

declare(strict_types=1);
/**
 * Site-scoped WooCommerce configuration abilities.
 *
 * These abilities provide a deliberately narrow alternative to arbitrary PHP
 * for marketplace setup. Every mutable plan is tied to one explicit blog,
 * persisted for human review, and executed only from the approved payload.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Automations\HumanApprovalGate;
use SdAiAgent\Core\ChangeLogger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooCommerceAbilities {

	private const INSPECT_ABILITY    = 'sd-ai-agent/commerce-inspect';
	private const PLAN_ABILITY       = 'sd-ai-agent/commerce-plan';
	private const EXECUTE_ABILITY    = 'sd-ai-agent/commerce-execute-approved-plan';
	private const APPROVAL_ACTION    = 'commerce-site-plan';
	private const PLAN_VERSION       = 1;
	private const TAXONOMY           = 'product_cat';
	private const WOOCOMMERCE_PLUGIN = 'woocommerce/woocommerce.php';

	/**
	 * The only WooCommerce options that this ability family may change.
	 *
	 * Each value is a non-secret yes/no flag. Keys outside this list, including
	 * vendor-plugin settings, are deliberately treated as prerequisites instead
	 * of falling through to update_option().
	 *
	 * @var array<string, array{label:string, description:string}>
	 */
	private const SUPPORTED_SETTINGS = [
		'woocommerce_enable_coupons'     => [
			'label'       => 'Enable coupons',
			'description' => 'Allow customers to use WooCommerce coupons.',
		],
		'woocommerce_calc_taxes'         => [
			'label'       => 'Enable tax calculations',
			'description' => 'Enable WooCommerce tax calculations for this site.',
		],
		'woocommerce_prices_include_tax' => [
			'label'       => 'Prices include tax',
			'description' => 'Treat catalogue prices as tax-inclusive on this site.',
		],
	];

	/**
	 * Known vendor plugins that can be reported during inspection.
	 *
	 * Reporting a plugin as active does not make its private settings writable.
	 * Vendor settings need a separately reviewed, typed implementation.
	 *
	 * @var array<string, array{name:string, plugin_files:list<string>}>
	 */
	private const VENDOR_PLUGINS = [
		'dokan'                       => [
			'name'         => 'Dokan',
			'plugin_files' => [ 'dokan/dokan.php', 'dokan-lite/dokan.php' ],
		],
		'wcfm-marketplace'            => [
			'name'         => 'WCFM Marketplace',
			'plugin_files' => [ 'wc-multivendor-marketplace/wc-multivendor-marketplace.php' ],
		],
		'woocommerce-product-vendors' => [
			'name'         => 'WooCommerce Product Vendors',
			'plugin_files' => [ 'woocommerce-product-vendors/woocommerce-product-vendors.php' ],
		],
	];

	/** Register commerce inspection, planning, and approved execution abilities. */
	public static function register_abilities(): void {
		HumanApprovalGate::register_handler( self::APPROVAL_ACTION, [ __CLASS__, 'execute_approved_plan' ] );

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_inspect_ability();
		self::register_plan_ability();
		self::register_execute_ability();
	}

	/**
	 * Inspect only the current blog's commerce configuration.
	 */
	private static function register_inspect_ability(): void {
		wp_register_ability(
			self::INSPECT_ABILITY,
			[
				'label'               => __( 'Inspect Site Commerce Configuration', 'superdav-ai-agent' ),
				'description'         => __( 'Inspect the current site’s WooCommerce runtime, active vendor plugins, product categories, and the narrowly supported non-secret setting keys. This ability never changes a site.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => (object) [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'blog_id'                => [ 'type' => 'integer' ],
						'woocommerce'            => [ 'type' => 'object' ],
						'vendor_plugins'         => [ 'type' => 'array' ],
						'product_categories'     => [ 'type' => 'array' ],
						'supported_setting_keys' => [ 'type' => 'array' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_inspect_current_site' ],
				'permission_callback' => static fn(): bool => ToolCapabilities::current_user_can( self::INSPECT_ABILITY ),
			]
		);
	}

	/**
	 * Register the site-scoped plan ability.
	 */
	private static function register_plan_ability(): void {
		wp_register_ability(
			self::PLAN_ABILITY,
			[
				'label'               => __( 'Plan Site Commerce Configuration', 'superdav-ai-agent' ),
				'description'         => __( 'Create a reviewed, single-site WooCommerce category and safe-setting plan. An explicit target blog ID is required. Network-wide changes and unverified vendor settings are refused.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'target_blog_id' => [
							'type'        => 'integer',
							'description' => 'The one WordPress site that this plan may affect.',
						],
						'network_wide'   => [
							'type'        => 'boolean',
							'description' => 'Must remain false. Network-wide commerce configuration is not supported by this ability.',
							'default'     => false,
						],
						'operations'     => [
							'type'        => 'array',
							'description' => 'The complete requested change set. The exact normalized list is what human approval covers.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'operation'   => [
										'type' => 'string',
										'enum' => [ 'create_category', 'update_setting' ],
									],
									'name'        => [ 'type' => 'string' ],
									'slug'        => [ 'type' => 'string' ],
									'parent_id'   => [ 'type' => 'integer' ],
									'setting_key' => [ 'type' => 'string' ],
									'value'       => [ 'type' => 'string' ],
								],
							],
						],
					],
					'required'   => [ 'target_blog_id', 'operations' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'status'              => [ 'type' => 'string' ],
						'plan'                => [ 'type' => 'object' ],
						'prerequisites'       => [ 'type' => 'array' ],
						'approval_request_id' => [ 'type' => 'integer' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_create_plan' ],
				'permission_callback' => static fn(): bool => ToolCapabilities::current_user_can( self::PLAN_ABILITY ),
			]
		);
	}

	/**
	 * Register the executor for a plan that a human has already approved.
	 */
	private static function register_execute_ability(): void {
		wp_register_ability(
			self::EXECUTE_ABILITY,
			[
				'label'               => __( 'Execute Approved Site Commerce Plan', 'superdav-ai-agent' ),
				'description'         => __( 'Execute only the immutable, fully reviewed payload of a human-approved site commerce plan. This ability cannot approve a plan or add operations.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'approval_request_id' => [ 'type' => 'integer' ],
					],
					'required'   => [ 'approval_request_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'status' => [ 'type' => 'string' ],
						'result' => [ 'type' => 'object' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_execute_approved_plan' ],
				'permission_callback' => static fn(): bool => ToolCapabilities::current_user_can( self::EXECUTE_ABILITY ),
			]
		);
	}

	/**
	 * Inspect the active blog without switching into another site.
	 *
	 * @param array<string, mixed> $input Ability input (unused).
	 * @return array<string, mixed>
	 */
	public static function handle_inspect_current_site( array $input = [] ): array {
		unset( $input );

		return self::inspect_current_site();
	}

	/**
	 * Build a normalized, approval-backed plan for exactly one blog.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_create_plan( array $input ): array|WP_Error {
		$target_blog_id = self::validate_requested_target( $input );
		if ( is_wp_error( $target_blog_id ) ) {
			return $target_blog_id;
		}

		$prepared = self::in_target_blog(
			$target_blog_id,
			static function () use ( $input, $target_blog_id ): array|WP_Error {
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					return self::target_permission_error( $target_blog_id );
				}

				if ( ! self::is_woocommerce_active_on_current_site() ) {
					return [
						'operations'    => [],
						'prerequisites' => [ self::woocommerce_activation_prerequisite( $target_blog_id ) ],
					];
				}

				if ( ! self::is_woocommerce_runtime_available() ) {
					return [
						'operations'    => [],
						'prerequisites' => [ self::woocommerce_runtime_prerequisite( $target_blog_id ) ],
					];
				}

				return self::normalize_operations( $input['operations'] ?? null, self::vendor_plugin_capabilities() );
			}
		);

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$plan      = [
			'version'        => self::PLAN_VERSION,
			'scope'          => 'site',
			'target_blog_id' => $target_blog_id,
			'operations'     => $prepared['operations'],
		];
		$plan_hash = self::hash_plan( $plan );
		if ( '' === $plan_hash ) {
			return new WP_Error( 'sd_ai_agent_commerce_plan_encode_failed', __( 'The commerce plan could not be encoded for review.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}
		$plan['plan_hash'] = $plan_hash;

		if ( [] !== $prepared['prerequisites'] ) {
			return [
				'status'        => 'requires_prerequisite',
				'plan'          => $plan,
				'prerequisites' => $prepared['prerequisites'],
			];
		}

		$approval = HumanApprovalGate::create_pending(
			[
				'source_type' => 'commerce',
				'source_id'   => $target_blog_id,
				'action_type' => self::APPROVAL_ACTION,
				'payload'     => [ 'plan' => $plan ],
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);

		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		return [
			'status'              => 'pending_approval',
			'plan'                => $plan,
			'prerequisites'       => [],
			'approval_request_id' => (int) $approval['id'],
			'approval_status'     => (string) $approval['status'],
			'expires_at'          => (string) ( $approval['expires_at'] ?? '' ),
		];
	}

	/**
	 * Execute an approved request without accepting caller-supplied operations.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_execute_approved_plan( array $input ): array|WP_Error {
		$approval_id = absint( $input['approval_request_id'] ?? 0 );
		if ( 0 === $approval_id ) {
			return new WP_Error( 'sd_ai_agent_commerce_approval_required', __( 'An approved commerce plan request ID is required.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$approval = HumanApprovalGate::get( $approval_id );
		if ( null === $approval || self::APPROVAL_ACTION !== $approval['action_type'] ) {
			return new WP_Error( 'sd_ai_agent_commerce_approval_not_found', __( 'The requested commerce approval plan was not found.', 'superdav-ai-agent' ), [ 'status' => 404 ] );
		}

		if ( HumanApprovalGate::STATUS_PENDING === $approval['status'] ) {
			return new WP_Error( 'sd_ai_agent_commerce_human_approval_required', __( 'A human must review and approve the complete commerce plan before it can run.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		if ( HumanApprovalGate::STATUS_EXECUTED === $approval['status'] ) {
			return [
				'status' => HumanApprovalGate::STATUS_EXECUTED,
				'result' => is_array( $approval['result'] ) ? $approval['result'] : [],
			];
		}

		if ( HumanApprovalGate::STATUS_APPROVED !== $approval['status'] ) {
			return new WP_Error( 'sd_ai_agent_commerce_approval_not_executable', __( 'The commerce plan is not approved for execution.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		return HumanApprovalGate::execute( $approval_id );
	}

	/**
	 * Execute the exact payload stored by HumanApprovalGate after human review.
	 *
	 * @param array<string, mixed> $payload Approval payload.
	 * @param array<string, mixed> $request Approval request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_approved_plan( array $payload, array $request = [] ): array|WP_Error {
		unset( $request );

		$plan = is_array( $payload['plan'] ?? null ) ? $payload['plan'] : [];
		if ( ! self::has_valid_plan_hash( $plan ) ) {
			return new WP_Error( 'sd_ai_agent_commerce_plan_tampered', __( 'The approved commerce plan did not pass its integrity check.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$target_blog_id = absint( $plan['target_blog_id'] ?? 0 );
		if ( 'site' !== ( $plan['scope'] ?? '' ) || 0 === $target_blog_id ) {
			return new WP_Error( 'sd_ai_agent_commerce_invalid_scope', __( 'The approved commerce plan must target exactly one site.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$validated_target = self::validate_target_blog_id( $target_blog_id );
		if ( is_wp_error( $validated_target ) ) {
			return $validated_target;
		}

		$started_logging = ! ChangeLogger::is_active();
		if ( $started_logging ) {
			ChangeLogger::begin( 0, self::EXECUTE_ABILITY );
		}

		try {
			return self::in_target_blog(
				$target_blog_id,
				static function () use ( $plan, $target_blog_id ): array|WP_Error {
					if ( ! current_user_can( 'manage_woocommerce' ) ) {
						return self::target_permission_error( $target_blog_id );
					}

					if ( ! self::is_woocommerce_active_on_current_site() ) {
						return new WP_Error( 'sd_ai_agent_commerce_prerequisite_changed', __( 'WooCommerce is no longer active for the approved target site.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
					}

					if ( ! self::is_woocommerce_runtime_available() ) {
						return new WP_Error( 'sd_ai_agent_commerce_prerequisite_changed', __( 'WooCommerce is no longer loaded for the approved target site.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
					}

					$operations = self::validate_approved_operations( $plan['operations'] ?? null );
					if ( is_wp_error( $operations ) ) {
						return $operations;
					}

					return self::execute_operations( $target_blog_id, $operations );
				}
			);
		} finally {
			if ( $started_logging ) {
				ChangeLogger::end();
			}
		}
	}

	/**
	 * Inspect the current blog's safe commerce facts.
	 *
	 * @return array<string, mixed>
	 */
	private static function inspect_current_site(): array {
		$categories = [];
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			$terms = get_terms(
				[
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
					'number'     => 100,
					'orderby'    => 'name',
					'order'      => 'ASC',
				]
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$categories[] = [
						'id'        => (int) $term->term_id,
						'name'      => (string) $term->name,
						'slug'      => (string) $term->slug,
						'parent_id' => (int) $term->parent,
						'count'     => (int) $term->count,
					];
				}
			}
		}

		return [
			'blog_id'                => get_current_blog_id(),
			'woocommerce'            => [
				'plugin_active'     => self::is_woocommerce_active_on_current_site(),
				'runtime_available' => self::is_woocommerce_runtime_available(),
				'version'           => defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : '',
			],
			'vendor_plugins'         => self::vendor_plugin_capabilities(),
			'product_categories'     => $categories,
			'supported_setting_keys' => self::supported_setting_keys(),
		];
	}

	/**
	 * Normalize request operations while running in the target site context.
	 *
	 * @param mixed                     $raw_operations Raw input operations.
	 * @param list<array<string,mixed>> $vendor_plugins Active/inactive vendor capabilities.
	 * @return array{operations:list<array<string,mixed>>, prerequisites:list<array<string,mixed>>}|WP_Error
	 */
	private static function normalize_operations( mixed $raw_operations, array $vendor_plugins ): array|WP_Error {
		if ( ! is_array( $raw_operations ) || [] === $raw_operations ) {
			return new WP_Error( 'sd_ai_agent_commerce_operations_required', __( 'At least one supported commerce operation is required.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$operations    = [];
		$prerequisites = [];

		foreach ( array_values( $raw_operations ) as $index => $raw_operation ) {
			if ( ! is_array( $raw_operation ) ) {
				return new WP_Error( 'sd_ai_agent_commerce_operation_invalid', __( 'Each commerce operation must be an object.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
			}

			$type = sanitize_key( (string) ( $raw_operation['operation'] ?? '' ) );
			if ( 'create_category' === $type ) {
				$category = self::normalize_category_operation( $raw_operation, (int) $index );
				if ( is_wp_error( $category ) ) {
					return $category;
				}
				$operations[] = $category;
				continue;
			}

			if ( 'update_setting' === $type ) {
				$setting = self::normalize_setting_operation( $raw_operation, (int) $index );
				if ( is_wp_error( $setting ) ) {
					if ( 'sd_ai_agent_commerce_vendor_prerequisite' === $setting->get_error_code() ) {
						$prerequisites[] = self::vendor_setting_prerequisite( (string) ( $raw_operation['setting_key'] ?? '' ), $vendor_plugins );
						continue;
					}
					return $setting;
				}
				$operations[] = $setting;
				continue;
			}

			return new WP_Error( 'sd_ai_agent_commerce_operation_unsupported', __( 'Only create_category and update_setting commerce operations are supported.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		return [
			'operations'    => $operations,
			'prerequisites' => $prerequisites,
		];
	}

	/**
	 * Normalize a product-category creation operation.
	 *
	 * @param array<string,mixed> $operation Raw operation.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function normalize_category_operation( array $operation, int $index ): array|WP_Error {
		$name = sanitize_text_field( (string) ( $operation['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'sd_ai_agent_commerce_category_name_required', __( 'A product category name is required.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$parent_id = absint( $operation['parent_id'] ?? 0 );
		if ( $parent_id > 0 && ! term_exists( $parent_id, self::TAXONOMY ) ) {
			return new WP_Error( 'sd_ai_agent_commerce_category_parent_missing', __( 'The requested product category parent does not exist on the target site.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$slug = sanitize_title( (string) ( $operation['slug'] ?? '' ) );

		return [
			'index'     => $index,
			'operation' => 'create_category',
			'name'      => $name,
			'slug'      => $slug,
			'parent_id' => $parent_id,
		];
	}

	/**
	 * Normalize an allowlisted WooCommerce setting operation.
	 *
	 * @param array<string,mixed> $operation Raw operation.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function normalize_setting_operation( array $operation, int $index ): array|WP_Error {
		$key = sanitize_key( (string) ( $operation['setting_key'] ?? '' ) );
		if ( ! isset( self::SUPPORTED_SETTINGS[ $key ] ) ) {
			return new WP_Error( 'sd_ai_agent_commerce_vendor_prerequisite', __( 'The requested commerce setting is not a verified built-in setting.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$value = self::normalize_yes_no_value( $operation['value'] ?? null );
		if ( is_wp_error( $value ) ) {
			return $value;
		}

		return [
			'index'       => $index,
			'operation'   => 'update_setting',
			'setting_key' => $key,
			'value'       => $value,
		];
	}

	/**
	 * Apply the immutable, normalized list of approved operations.
	 *
	 * @param int                       $target_blog_id Target site ID.
	 * @param list<array<string,mixed>> $operations Normalized operations.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function execute_operations( int $target_blog_id, array $operations ): array|WP_Error {
		if ( [] === $operations ) {
			return new WP_Error( 'sd_ai_agent_commerce_operations_missing', __( 'The approved commerce plan contains no executable operations.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$changes = [];
		foreach ( $operations as $operation ) {
			$type = (string) ( $operation['operation'] ?? '' );
			if ( 'create_category' === $type ) {
				$result = self::create_category( $operation );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$changes[] = $result;
				continue;
			}

			if ( 'update_setting' === $type ) {
				$result = self::update_safe_setting( $operation );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$changes[] = $result;
				continue;
			}

			return new WP_Error( 'sd_ai_agent_commerce_plan_operation_invalid', __( 'The approved commerce plan contains an unsupported operation.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		return [
			'success'        => true,
			'target_blog_id' => $target_blog_id,
			'changes'        => $changes,
			'change_log'     => __( 'Changes were recorded in the target site’s change log.', 'superdav-ai-agent' ),
		];
	}

	/**
	 * Revalidate an immutable stored plan before execution.
	 *
	 * Approval records are durable data, so execute only the exact normalized
	 * operation list that was presented for approval. This also fails safely if
	 * a malformed record is restored or changed outside the ability workflow.
	 *
	 * @param mixed $raw_operations Stored approved operations.
	 * @return list<array<string,mixed>>|WP_Error
	 */
	private static function validate_approved_operations( mixed $raw_operations ): array|WP_Error {
		if ( ! is_array( $raw_operations ) ) {
			return new WP_Error( 'sd_ai_agent_commerce_plan_operations_invalid', __( 'The approved commerce plan contains an invalid operation list.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$operations = array_values( $raw_operations );
		$normalized = self::normalize_operations( $operations, [] );
		if ( is_wp_error( $normalized ) ) {
			return new WP_Error( 'sd_ai_agent_commerce_plan_operations_invalid', __( 'The approved commerce plan contains an invalid operation.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		if ( [] !== $normalized['prerequisites'] || $normalized['operations'] !== $operations ) {
			return new WP_Error( 'sd_ai_agent_commerce_plan_operations_invalid', __( 'The approved commerce plan was not stored in its reviewed form.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		return $normalized['operations'];
	}

	/**
	 * Create a category or return an idempotent existing result.
	 *
	 * @param array<string,mixed> $operation Normalized operation.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function create_category( array $operation ): array|WP_Error {
		$name      = (string) $operation['name'];
		$parent_id = (int) $operation['parent_id'];
		$existing  = term_exists( $name, self::TAXONOMY, $parent_id );
		if ( $existing ) {
			$term_id = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
			return [
				'operation' => 'create_category',
				'status'    => 'unchanged',
				'term_id'   => $term_id,
				'name'      => $name,
			];
		}

		$args = [ 'parent' => $parent_id ];
		if ( '' !== (string) $operation['slug'] ) {
			$args['slug'] = (string) $operation['slug'];
		}

		$created = wp_insert_term( $name, self::TAXONOMY, $args );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$term_id = (int) $created['term_id'];
		ChangeLogger::record_term_created( $term_id, self::TAXONOMY, $name, self::EXECUTE_ABILITY );

		return [
			'operation' => 'create_category',
			'status'    => 'created',
			'term_id'   => $term_id,
			'name'      => $name,
		];
	}

	/**
	 * Update an explicitly allowlisted, non-secret WooCommerce setting.
	 *
	 * @param array<string,mixed> $operation Normalized operation.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function update_safe_setting( array $operation ): array|WP_Error {
		$key   = (string) $operation['setting_key'];
		$value = (string) $operation['value'];
		if ( ! isset( self::SUPPORTED_SETTINGS[ $key ] ) || ! in_array( $value, [ 'yes', 'no' ], true ) ) {
			return new WP_Error( 'sd_ai_agent_commerce_setting_invalid', __( 'The approved commerce plan contains an unsupported setting.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$before = (string) get_option( $key, '' );
		if ( $before === $value ) {
			return [
				'operation'   => 'update_setting',
				'status'      => 'unchanged',
				'setting_key' => $key,
			];
		}

		$updated = update_option( $key, $value );
		if ( ! $updated && (string) get_option( $key, '' ) !== $value ) {
			return new WP_Error( 'sd_ai_agent_commerce_setting_update_failed', __( 'The approved WooCommerce setting could not be updated.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}

		return [
			'operation'   => 'update_setting',
			'status'      => 'updated',
			'setting_key' => $key,
		];
	}

	/**
	 * Reject network scope and validate the requested target exists.
	 *
	 * @param array<string,mixed> $input Raw ability input.
	 * @return int|WP_Error
	 */
	private static function validate_requested_target( array $input ): int|WP_Error {
		if ( ! empty( $input['network_wide'] ) || 'network' === sanitize_key( (string) ( $input['scope'] ?? 'site' ) ) ) {
			return new WP_Error( 'sd_ai_agent_commerce_network_scope_forbidden', __( 'Commerce plans must target one explicit site. Network-wide configuration requires a separate network capability.', 'superdav-ai-agent' ), [ 'status' => 403 ] );
		}

		return self::validate_target_blog_id( absint( $input['target_blog_id'] ?? 0 ) );
	}

	/**
	 * Validate a single blog ID without switching to it.
	 *
	 * @return int|WP_Error
	 */
	private static function validate_target_blog_id( int $target_blog_id ): int|WP_Error {
		if ( 0 === $target_blog_id ) {
			return new WP_Error( 'sd_ai_agent_commerce_target_required', __( 'An explicit target blog ID is required for commerce configuration.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		if ( ! is_multisite() ) {
			if ( $target_blog_id !== get_current_blog_id() ) {
				return new WP_Error( 'sd_ai_agent_commerce_target_not_found', __( 'The requested target site is not available in this single-site WordPress installation.', 'superdav-ai-agent' ), [ 'status' => 404 ] );
			}
			return $target_blog_id;
		}

		$site = get_site( $target_blog_id );
		if ( null === $site || '1' === $site->deleted || '1' === $site->spam || '1' === $site->archived ) {
			return new WP_Error( 'sd_ai_agent_commerce_target_not_found', __( 'The requested target site does not exist or is unavailable.', 'superdav-ai-agent' ), [ 'status' => 404 ] );
		}

		return $target_blog_id;
	}

	/**
	 * Run a callback in exactly one site and always restore the caller context.
	 */
	private static function in_target_blog( int $target_blog_id, callable $callback ): mixed {
		$switched = false;
		if ( is_multisite() && $target_blog_id !== get_current_blog_id() ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
		}

		try {
			return call_user_func( $callback );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Return whether WooCommerce is loaded and has registered product categories.
	 */
	private static function is_woocommerce_runtime_available(): bool {
		return class_exists( 'WooCommerce' ) && taxonomy_exists( self::TAXONOMY );
	}

	/** Return whether WooCommerce is active for the current site or network-wide. */
	private static function is_woocommerce_active_on_current_site(): bool {
		return self::is_plugin_active_on_current_site( self::WOOCOMMERCE_PLUGIN );
	}

	/**
	 * Return active status for a plugin in the current site context.
	 */
	private static function is_plugin_active_on_current_site( string $plugin_file ): bool {
		$active_plugins = (array) get_option( 'active_plugins', [] );
		$network_active = (array) get_site_option( 'active_sitewide_plugins', [] );

		return in_array( $plugin_file, $active_plugins, true ) || isset( $network_active[ $plugin_file ] );
	}

	/**
	 * Return known vendor-plugin activity without allowing vendor option writes.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function vendor_plugin_capabilities(): array {
		$capabilities = [];
		foreach ( self::VENDOR_PLUGINS as $slug => $vendor ) {
			$active = false;
			foreach ( $vendor['plugin_files'] as $plugin_file ) {
				if ( self::is_plugin_active_on_current_site( $plugin_file ) ) {
					$active = true;
					break;
				}
			}

			$capabilities[] = [
				'slug'                => $slug,
				'name'                => $vendor['name'],
				'active'              => $active,
				'execution_supported' => false,
				'guidance'            => $active
					? __( 'Vendor-plugin settings require a separately reviewed typed ability and are not included in this commerce plan.', 'superdav-ai-agent' )
					: __( 'Install and activate the vendor plugin on this site before requesting a dedicated vendor configuration ability.', 'superdav-ai-agent' ),
			];
		}

		return $capabilities;
	}

	/**
	 * Return the safe setting-key catalogue without returning option values.
	 *
	 * @return list<array<string,string>>
	 */
	private static function supported_setting_keys(): array {
		$settings = [];
		foreach ( self::SUPPORTED_SETTINGS as $key => $setting ) {
			$settings[] = [
				'key'         => $key,
				'label'       => $setting['label'],
				'description' => $setting['description'],
				'value_type'  => 'yes_no',
			];
		}

		return $settings;
	}

	/**
	 * Normalize a boolean-like value to WooCommerce's yes/no option values.
	 *
	 * @return string|WP_Error
	 */
	private static function normalize_yes_no_value( mixed $value ): string|WP_Error {
		if ( true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'true' === $value ) {
			return 'yes';
		}
		if ( false === $value || 0 === $value || '0' === $value || 'no' === $value || 'false' === $value ) {
			return 'no';
		}

		return new WP_Error( 'sd_ai_agent_commerce_setting_value_invalid', __( 'Supported WooCommerce settings accept only yes/no values.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
	}

	/**
	 * Hash a normalized plan before it is placed in the approval record.
	 *
	 * @param array<string,mixed> $plan Plan without a plan_hash field.
	 */
	private static function hash_plan( array $plan ): string {
		unset( $plan['plan_hash'] );
		$encoded = wp_json_encode( $plan );

		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	/**
	 * Ensure a stored approval payload still matches its original reviewed plan.
	 *
	 * @param array<string,mixed> $plan Stored plan.
	 */
	private static function has_valid_plan_hash( array $plan ): bool {
		$plan_hash = (string) ( $plan['plan_hash'] ?? '' );

		return '' !== $plan_hash && hash_equals( self::hash_plan( $plan ), $plan_hash );
	}

	/**
	 * Build a prerequisite when WooCommerce is inactive on the target site.
	 *
	 * @return array<string,mixed>
	 */
	private static function woocommerce_activation_prerequisite( int $target_blog_id ): array {
		return [
			'code'           => 'woocommerce_plugin_inactive',
			'target_blog_id' => $target_blog_id,
			'message'        => __( 'WooCommerce must be activated for the requested site or network-activated before a commerce plan can be approved.', 'superdav-ai-agent' ),
		];
	}

	/**
	 * Build a prerequisite when WooCommerce is not loaded on the target site.
	 *
	 * @return array<string,mixed>
	 */
	private static function woocommerce_runtime_prerequisite( int $target_blog_id ): array {
		return [
			'code'           => 'woocommerce_runtime_unavailable',
			'target_blog_id' => $target_blog_id,
			'message'        => __( 'WooCommerce must be loaded for the requested site before a commerce plan can be approved.', 'superdav-ai-agent' ),
		];
	}

	/**
	 * Build a clear prerequisite for an unimplemented vendor or unknown setting.
	 *
	 * @param string                    $setting_key    Requested setting key.
	 * @param list<array<string,mixed>> $vendor_plugins Current vendor capabilities.
	 * @return array<string,mixed>
	 */
	private static function vendor_setting_prerequisite( string $setting_key, array $vendor_plugins ): array {
		$active_vendors = [];
		foreach ( $vendor_plugins as $vendor ) {
			if ( ! empty( $vendor['active'] ) ) {
				$active_vendors[] = (string) $vendor['name'];
			}
		}

		return [
			'code'                  => 'vendor_plugin_setting_unsupported',
			'requested_setting_key' => sanitize_key( $setting_key ),
			'active_vendor_plugins' => $active_vendors,
			'message'               => __( 'This setting is not a verified built-in commerce setting. Install or enable the required vendor module, then use a dedicated typed vendor ability; this plan will not write an unknown option.', 'superdav-ai-agent' ),
		];
	}

	/**
	 * Return a target-site capability error after switching to the target site.
	 */
	private static function target_permission_error( int $target_blog_id ): WP_Error {
		return new WP_Error(
			'sd_ai_agent_commerce_target_permission_denied',
			/* translators: %d: target blog ID. */
			sprintf( __( 'The current user cannot manage WooCommerce on target site %d.', 'superdav-ai-agent' ), $target_blog_id ),
			[ 'status' => 403 ]
		);
	}
}
