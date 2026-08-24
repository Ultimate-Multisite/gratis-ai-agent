<?php

declare(strict_types=1);
/**
 * Event Trigger Registry — catalog of available WordPress hooks with metadata.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Automations;

class EventTriggerRegistry {

	/**
	 * The only registry sources allowed to wake a Monitor.
	 *
	 * Event automations retain their broader, user-configurable catalog. Monitor
	 * wakes are deliberately narrower because hook arguments often contain raw
	 * user content, credentials, emails, or object graphs. Each entry maps to
	 * fields that can be reduced to bounded identifiers before persistence.
	 *
	 * @var array<string, array{identifier_fields:list<string>,hook_arg_count:int}>
	 */
	private const MONITOR_WAKE_SOURCES = [
		'transition_post_status' => [
			'identifier_fields' => [ 'new_status', 'old_status', 'post_id', 'post_type' ],
			'hook_arg_count'    => 3,
		],
		'delete_post'            => [
			'identifier_fields' => [ 'post_id' ],
			'hook_arg_count'    => 1,
		],
		'activated_plugin'       => [
			'identifier_fields' => [],
			'hook_arg_count'    => 1,
		],
		'deactivated_plugin'     => [
			'identifier_fields' => [],
			'hook_arg_count'    => 1,
		],
		'switch_theme'           => [
			'identifier_fields' => [],
			'hook_arg_count'    => 2,
		],
		'add_attachment'         => [
			'identifier_fields' => [ 'attachment_id' ],
			'hook_arg_count'    => 1,
		],
	];

	/**
	 * Get all available triggers grouped by category.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get_all(): array {
		$triggers = array_merge(
			self::get_wordpress_triggers(),
			self::get_woocommerce_triggers(),
			self::get_form_triggers()
		);

		/**
		 * Filter available event triggers.
		 *
		 * @param array $triggers Array of trigger definitions.
		 */
		/** @var list<array<string, mixed>> $filtered */
		$filtered = apply_filters( 'sd_ai_agent_event_triggers', $triggers );
		return $filtered;
	}

	/**
	 * Get triggers grouped by category for the UI.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_grouped(): array {
		$all = self::get_all();
		/** @var array<string, array{label: string, triggers: list<array<string, mixed>>}> $grouped */
		$grouped = [];

		foreach ( $all as $trigger ) {
			if ( ! is_array( $trigger ) ) {
				continue;
			}
			$cat = isset( $trigger['category'] ) && is_string( $trigger['category'] ) ? $trigger['category'] : 'other';
			if ( ! isset( $grouped[ $cat ] ) ) {
				$grouped[ $cat ] = [
					'label'    => self::get_category_label( $cat ),
					'triggers' => [],
				];
			}
			$grouped[ $cat ]['triggers'][] = $trigger;
		}

		return $grouped;
	}

	/**
	 * Get a trigger definition by hook name.
	 *
	 * @param string $hook_name WordPress hook name.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $hook_name ): ?array {
		foreach ( self::get_all() as $trigger ) {
			if ( is_array( $trigger ) && isset( $trigger['hook_name'] ) && $trigger['hook_name'] === $hook_name ) {
				return $trigger;
			}
		}
		return null;
	}

	/**
	 * Return the strict, presentation-safe event descriptors available to Monitors.
	 *
	 * @return list<array{hook_name:string,label:string,description:string,args:list<string>}>
	 */
	public static function get_monitor_wake_sources(): array {
		$sources = [];
		foreach ( array_keys( self::MONITOR_WAKE_SOURCES ) as $hook_name ) {
			$sources[] = [
				'hook_name'   => $hook_name,
				'label'       => self::get_monitor_wake_source_label( $hook_name ),
				'description' => self::get_monitor_wake_source_description( $hook_name ),
				'args'        => self::get_monitor_wake_identifier_fields( $hook_name ),
			];
		}

		return $sources;
	}

	/** Return whether a hook is one of the strict Monitor wake allowlist entries. */
	public static function is_monitor_wake_source( string $hook_name ): bool {
		return array_key_exists( $hook_name, self::MONITOR_WAKE_SOURCES );
	}

	/** Return the fixed WordPress callback argument count for an approved source. */
	public static function get_monitor_wake_hook_arg_count( string $hook_name ): int {
		return (int) ( self::MONITOR_WAKE_SOURCES[ $hook_name ]['hook_arg_count'] ?? 0 );
	}

	/**
	 * Return a safe, bounded event summary without retaining raw hook arguments.
	 *
	 * @param string $hook_name WordPress action name.
	 * @param array  $hook_args Action arguments.
	 * @phpstan-param list<mixed> $hook_args
	 * @return array{source:string,identifiers:array<string,int|string>}|null
	 */
	public static function summarize_monitor_wake( string $hook_name, array $hook_args ): ?array {
		if ( ! self::is_monitor_wake_source( $hook_name ) ) {
			return null;
		}

		$identifiers = [];
		switch ( $hook_name ) {
			case 'transition_post_status':
				$new_status = self::sanitize_monitor_wake_text_identifier( $hook_args[0] ?? null );
				$old_status = self::sanitize_monitor_wake_text_identifier( $hook_args[1] ?? null );
				if ( '' !== $new_status ) {
					$identifiers['new_status'] = $new_status;
				}
				if ( '' !== $old_status ) {
					$identifiers['old_status'] = $old_status;
				}

				$post = $hook_args[2] ?? null;
				if ( $post instanceof \WP_Post ) {
					$post_id   = self::sanitize_monitor_wake_numeric_identifier( $post->ID );
					$post_type = self::sanitize_monitor_wake_text_identifier( $post->post_type );
					if ( $post_id > 0 ) {
						$identifiers['post_id'] = $post_id;
					}
					if ( '' !== $post_type ) {
						$identifiers['post_type'] = $post_type;
					}
				}
				break;

			case 'delete_post':
			case 'add_attachment':
				$identifier = self::sanitize_monitor_wake_numeric_identifier( $hook_args[0] ?? null );
				if ( $identifier > 0 ) {
					$identifiers[ 'delete_post' === $hook_name ? 'post_id' : 'attachment_id' ] = $identifier;
				}
				break;
		}

		return [
			'source'      => $hook_name,
			'identifiers' => self::sanitize_monitor_wake_identifiers( $hook_name, $identifiers ),
		];
	}

	/**
	 * Reduce stored monitor-wake identifiers to their source-specific schema.
	 *
	 * @param string               $hook_name   Approved source name.
	 * @param array<string, mixed> $identifiers Candidate identifiers.
	 * @return array<string, int|string>
	 */
	public static function sanitize_monitor_wake_identifiers( string $hook_name, array $identifiers ): array {
		$allowed = self::get_monitor_wake_identifier_fields( $hook_name );
		$clean   = [];
		foreach ( $allowed as $field ) {
			if ( ! array_key_exists( $field, $identifiers ) ) {
				continue;
			}

			$value = $identifiers[ $field ];
			if ( in_array( $field, [ 'post_id', 'attachment_id' ], true ) ) {
				$value = self::sanitize_monitor_wake_numeric_identifier( $value );
				if ( $value > 0 ) {
					$clean[ $field ] = $value;
				}
				continue;
			}

			$value = self::sanitize_monitor_wake_text_identifier( $value );
			if ( '' !== $value ) {
				$clean[ $field ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Return safe identifier names for one hard-coded Monitor event source.
	 *
	 * @return list<string>
	 */
	private static function get_monitor_wake_identifier_fields( string $hook_name ): array {
		return self::MONITOR_WAKE_SOURCES[ $hook_name ]['identifier_fields'] ?? [];
	}

	/** Reduce a scalar hook argument to a safe key-like identifier. */
	private static function sanitize_monitor_wake_text_identifier( mixed $value ): string {
		return is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
	}

	/** Reduce one scalar hook argument to a positive integer identifier. */
	private static function sanitize_monitor_wake_numeric_identifier( mixed $value ): int {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return 0;
		}

		return absint( $value );
	}

	/** Return a fixed translated label without consulting filterable trigger metadata. */
	private static function get_monitor_wake_source_label( string $hook_name ): string {
		return match ( $hook_name ) {
			'transition_post_status' => __( 'Post Status Changed', 'superdav-ai-agent' ),
			'delete_post'            => __( 'Post Deleted', 'superdav-ai-agent' ),
			'activated_plugin'       => __( 'Plugin Activated', 'superdav-ai-agent' ),
			'deactivated_plugin'     => __( 'Plugin Deactivated', 'superdav-ai-agent' ),
			'switch_theme'           => __( 'Theme Switched', 'superdav-ai-agent' ),
			'add_attachment'         => __( 'Media Uploaded', 'superdav-ai-agent' ),
			default                  => $hook_name,
		};
	}

	/** Return fixed user-facing source guidance without exposing hook arguments. */
	private static function get_monitor_wake_source_description( string $hook_name ): string {
		return match ( $hook_name ) {
			'transition_post_status' => __( 'Assess a post status change using only its status and ID metadata.', 'superdav-ai-agent' ),
			'delete_post'            => __( 'Assess a deleted post using only its ID metadata.', 'superdav-ai-agent' ),
			'activated_plugin'       => __( 'Assess a plugin activation without retaining plugin path data.', 'superdav-ai-agent' ),
			'deactivated_plugin'     => __( 'Assess a plugin deactivation without retaining plugin path data.', 'superdav-ai-agent' ),
			'switch_theme'           => __( 'Assess a theme switch without retaining theme metadata.', 'superdav-ai-agent' ),
			'add_attachment'         => __( 'Assess a media upload using only its attachment ID metadata.', 'superdav-ai-agent' ),
			default                  => '',
		};
	}

	/**
	 * WordPress core triggers.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function get_wordpress_triggers(): array {
		return [
			[
				'hook_name'    => 'transition_post_status',
				'label'        => __( 'Post Status Changed', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a post status transitions (e.g. draft to publish).', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'new_status', 'old_status', 'post' ],
				'placeholders' => [
					'new_status'   => __( 'New post status', 'superdav-ai-agent' ),
					'old_status'   => __( 'Previous post status', 'superdav-ai-agent' ),
					'post.ID'      => __( 'Post ID', 'superdav-ai-agent' ),
					'post.title'   => __( 'Post title', 'superdav-ai-agent' ),
					'post.type'    => __( 'Post type', 'superdav-ai-agent' ),
					'post.author'  => __( 'Post author ID', 'superdav-ai-agent' ),
					'post.content' => __( 'Post content (excerpt)', 'superdav-ai-agent' ),
				],
				'conditions'   => [
					'post_type'  => __( 'Post type equals', 'superdav-ai-agent' ),
					'new_status' => __( 'New status equals', 'superdav-ai-agent' ),
					'old_status' => __( 'Old status equals', 'superdav-ai-agent' ),
				],
			],
			[
				'hook_name'    => 'user_register',
				'label'        => __( 'New User Registered', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a new user account is created.', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'user_id' ],
				'placeholders' => [
					'user.id'           => __( 'User ID', 'superdav-ai-agent' ),
					'user.login'        => __( 'Username', 'superdav-ai-agent' ),
					'user.email'        => __( 'User email', 'superdav-ai-agent' ),
					'user.display_name' => __( 'Display name', 'superdav-ai-agent' ),
					'user.role'         => __( 'User role', 'superdav-ai-agent' ),
				],
				'conditions'   => [
					'role' => __( 'User role equals', 'superdav-ai-agent' ),
				],
			],
			[
				'hook_name'    => 'wp_login',
				'label'        => __( 'User Login', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a user successfully logs in.', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'user_login', 'user' ],
				'placeholders' => [
					'user.login'        => __( 'Username', 'superdav-ai-agent' ),
					'user.email'        => __( 'User email', 'superdav-ai-agent' ),
					'user.display_name' => __( 'Display name', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			],
			[
				'hook_name'    => 'comment_post',
				'label'        => __( 'New Comment', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a new comment is posted.', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'comment_id', 'comment_approved' ],
				'placeholders' => [
					'comment.id'           => __( 'Comment ID', 'superdav-ai-agent' ),
					'comment.author'       => __( 'Comment author name', 'superdav-ai-agent' ),
					'comment.author_email' => __( 'Comment author email', 'superdav-ai-agent' ),
					'comment.content'      => __( 'Comment text', 'superdav-ai-agent' ),
					'comment.post_id'      => __( 'Post ID', 'superdav-ai-agent' ),
					'comment.approved'     => __( 'Approval status', 'superdav-ai-agent' ),
				],
				'conditions'   => [
					'approved' => __( 'Approval status equals', 'superdav-ai-agent' ),
				],
			],
			[
				'hook_name'    => 'delete_post',
				'label'        => __( 'Post Deleted', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a post is permanently deleted.', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'post_id' ],
				'placeholders' => [
					'post.ID'    => __( 'Post ID', 'superdav-ai-agent' ),
					'post.title' => __( 'Post title', 'superdav-ai-agent' ),
					'post.type'  => __( 'Post type', 'superdav-ai-agent' ),
				],
				'conditions'   => [
					'post_type' => __( 'Post type equals', 'superdav-ai-agent' ),
				],
			],
			[
				'hook_name'    => 'activated_plugin',
				'label'        => __( 'Plugin Activated', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a plugin is activated.', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'plugin' ],
				'placeholders' => [
					'plugin' => __( 'Plugin file path', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			],
			[
				'hook_name'    => 'deactivated_plugin',
				'label'        => __( 'Plugin Deactivated', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a plugin is deactivated.', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'plugin' ],
				'placeholders' => [
					'plugin' => __( 'Plugin file path', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			],
			[
				'hook_name'    => 'switch_theme',
				'label'        => __( 'Theme Switched', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when the active theme is changed.', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'new_name', 'new_theme' ],
				'placeholders' => [
					'new_name' => __( 'New theme name', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			],
			[
				'hook_name'    => 'profile_update',
				'label'        => __( 'User Profile Updated', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a user profile is updated.', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'user_id', 'old_user_data' ],
				'placeholders' => [
					'user.id'           => __( 'User ID', 'superdav-ai-agent' ),
					'user.email'        => __( 'User email', 'superdav-ai-agent' ),
					'user.display_name' => __( 'Display name', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			],
			[
				'hook_name'    => 'wp_login_failed',
				'label'        => __( 'Failed Login Attempt', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a login attempt fails.', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'username' ],
				'placeholders' => [
					'username' => __( 'Attempted username', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			],
			[
				'hook_name'    => 'added_option',
				'label'        => __( 'Option Added', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a new option is added to the database.', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'option_name', 'option_value' ],
				'placeholders' => [
					'option_name' => __( 'Option name', 'superdav-ai-agent' ),
				],
				'conditions'   => [
					'option_name' => __( 'Option name equals', 'superdav-ai-agent' ),
				],
			],
			[
				'hook_name'    => 'updated_option',
				'label'        => __( 'Option Updated', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when an existing option is updated.', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'option_name', 'old_value', 'new_value' ],
				'placeholders' => [
					'option_name' => __( 'Option name', 'superdav-ai-agent' ),
				],
				'conditions'   => [
					'option_name' => __( 'Option name equals', 'superdav-ai-agent' ),
				],
			],
			[
				'hook_name'    => 'add_attachment',
				'label'        => __( 'Media Uploaded', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a new media file is uploaded.', 'superdav-ai-agent' ),
				'category'     => 'wordpress',
				'args'         => [ 'post_id' ],
				'placeholders' => [
					'post.ID'    => __( 'Attachment ID', 'superdav-ai-agent' ),
					'post.title' => __( 'Attachment title', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			],
		];
	}

	/**
	 * WooCommerce triggers.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function get_woocommerce_triggers(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [];
		}

		return [
			[
				'hook_name'    => 'woocommerce_new_order',
				'label'        => __( 'New Order Created', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a new WooCommerce order is created.', 'superdav-ai-agent' ),
				'category'     => 'woocommerce',
				'args'         => [ 'order_id' ],
				'placeholders' => [
					'order.id'     => __( 'Order ID', 'superdav-ai-agent' ),
					'order.total'  => __( 'Order total', 'superdav-ai-agent' ),
					'order.status' => __( 'Order status', 'superdav-ai-agent' ),
					'order.email'  => __( 'Customer email', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			],
			[
				'hook_name'    => 'woocommerce_order_status_changed',
				'label'        => __( 'Order Status Changed', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when an order status changes.', 'superdav-ai-agent' ),
				'category'     => 'woocommerce',
				'args'         => [ 'order_id', 'old_status', 'new_status' ],
				'placeholders' => [
					'order.id'   => __( 'Order ID', 'superdav-ai-agent' ),
					'old_status' => __( 'Previous status', 'superdav-ai-agent' ),
					'new_status' => __( 'New status', 'superdav-ai-agent' ),
				],
				'conditions'   => [
					'new_status' => __( 'New status equals', 'superdav-ai-agent' ),
					'old_status' => __( 'Old status equals', 'superdav-ai-agent' ),
				],
			],
			[
				'hook_name'    => 'woocommerce_low_stock',
				'label'        => __( 'Product Low Stock', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a product reaches low stock threshold.', 'superdav-ai-agent' ),
				'category'     => 'woocommerce',
				'args'         => [ 'product' ],
				'placeholders' => [
					'product.id'    => __( 'Product ID', 'superdav-ai-agent' ),
					'product.name'  => __( 'Product name', 'superdav-ai-agent' ),
					'product.stock' => __( 'Stock quantity', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			],
			[
				'hook_name'    => 'woocommerce_payment_complete',
				'label'        => __( 'Payment Complete', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a payment is completed.', 'superdav-ai-agent' ),
				'category'     => 'woocommerce',
				'args'         => [ 'order_id' ],
				'placeholders' => [
					'order.id'    => __( 'Order ID', 'superdav-ai-agent' ),
					'order.total' => __( 'Order total', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			],
			[
				'hook_name'    => 'woocommerce_product_on_backorder',
				'label'        => __( 'Product On Backorder', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a product goes on backorder.', 'superdav-ai-agent' ),
				'category'     => 'woocommerce',
				'args'         => [ 'item' ],
				'placeholders' => [
					'product.name' => __( 'Product name', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			],
			[
				'hook_name'    => 'woocommerce_refund_created',
				'label'        => __( 'Refund Created', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a refund is created.', 'superdav-ai-agent' ),
				'category'     => 'woocommerce',
				'args'         => [ 'refund_id', 'args' ],
				'placeholders' => [
					'refund_id' => __( 'Refund ID', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			],
		];
	}

	/**
	 * Form plugin triggers.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function get_form_triggers(): array {
		$triggers = [];

		// Contact Form 7.
		if ( defined( 'WPCF7_VERSION' ) ) {
			$triggers[] = [
				'hook_name'    => 'wpcf7_mail_sent',
				'label'        => __( 'CF7 Form Submitted', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a Contact Form 7 submission email is sent.', 'superdav-ai-agent' ),
				'category'     => 'forms',
				'args'         => [ 'contact_form' ],
				'placeholders' => [
					'form.title' => __( 'Form title', 'superdav-ai-agent' ),
					'form.id'    => __( 'Form ID', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			];
		}

		// Gravity Forms.
		if ( class_exists( 'GFForms' ) ) {
			$triggers[] = [
				'hook_name'    => 'gform_after_submission',
				'label'        => __( 'Gravity Form Submitted', 'superdav-ai-agent' ),
				'description'  => __( 'Fires after a Gravity Forms entry is created.', 'superdav-ai-agent' ),
				'category'     => 'forms',
				'args'         => [ 'entry', 'form' ],
				'placeholders' => [
					'form.title' => __( 'Form title', 'superdav-ai-agent' ),
					'form.id'    => __( 'Form ID', 'superdav-ai-agent' ),
					'entry.id'   => __( 'Entry ID', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			];
		}

		// WPForms.
		if ( defined( 'WPFORMS_VERSION' ) ) {
			$triggers[] = [
				'hook_name'    => 'wpforms_process_complete',
				'label'        => __( 'WPForms Form Submitted', 'superdav-ai-agent' ),
				'description'  => __( 'Fires when a WPForms entry is processed.', 'superdav-ai-agent' ),
				'category'     => 'forms',
				'args'         => [ 'fields', 'entry', 'form_data', 'entry_id' ],
				'placeholders' => [
					'form.title' => __( 'Form title', 'superdav-ai-agent' ),
					'entry_id'   => __( 'Entry ID', 'superdav-ai-agent' ),
				],
				'conditions'   => [],
			];
		}

		return $triggers;
	}

	/**
	 * Get a human-readable category label.
	 *
	 * @param string $category Category slug.
	 * @return string
	 */
	private static function get_category_label( string $category ): string {
		$labels = [
			'wordpress'   => __( 'WordPress', 'superdav-ai-agent' ),
			'woocommerce' => __( 'WooCommerce', 'superdav-ai-agent' ),
			'forms'       => __( 'Forms', 'superdav-ai-agent' ),
			'other'       => __( 'Other', 'superdav-ai-agent' ),
		];

		return $labels[ $category ] ?? ucfirst( $category );
	}
}
