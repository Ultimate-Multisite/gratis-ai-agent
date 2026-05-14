<?php

declare(strict_types=1);
/**
 * Content analysis abilities for the AI agent.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContentAbilities {

	/**
	 * Register content analysis abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/content-analyze',
			[
				'label'               => __( 'Analyze Content Strategy', 'superdav-ai-agent' ),
				'description'         => __( 'Analyze content strategy: publishing frequency, word counts, category distribution, missing featured images, and content gaps.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_type' => [
							'type'        => 'string',
							'description' => 'Post type to analyze (default: "post").',
						],
						'limit'     => [
							'type'        => 'integer',
							'description' => 'Number of recent posts to analyze (default: 20).',
						],
						'site_url'  => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_type'                      => [ 'type' => 'string' ],
						'total_analyzed'                 => [ 'type' => 'integer' ],
						'publishing_frequency'           => [ 'type' => 'object' ],
						'avg_word_count'                 => [ 'type' => 'integer' ],
						'min_word_count'                 => [ 'type' => 'integer' ],
						'max_word_count'                 => [ 'type' => 'integer' ],
						'category_distribution'          => [ 'type' => 'object' ],
						'posts_without_featured_image'   => [ 'type' => 'array' ],
						'posts_without_meta_description' => [ 'type' => 'array' ],
						'content_gap_categories'         => [ 'type' => 'array' ],
						'thin_content_count'             => [ 'type' => 'integer' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_content_analyze' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/content-performance-report',
			[
				'label'               => __( 'Content Performance Report', 'superdav-ai-agent' ),
				'description'         => __( 'Generate a content performance summary for a given time period: posts published, category breakdown, word counts, drafts pending.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'days'     => [
							'type'        => 'integer',
							'description' => 'Number of days to look back (default: 30).',
						],
						'site_url' => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'period_days'               => [ 'type' => 'integer' ],
						'posts_published'           => [ 'type' => 'integer' ],
						'previous_period_published' => [ 'type' => 'integer' ],
						'avg_word_count'            => [ 'type' => 'integer' ],
						'posts_by_category'         => [ 'type' => 'object' ],
						'posts_by_author'           => [ 'type' => 'object' ],
						'all_posts_by_status'       => [ 'type' => 'object' ],
						'drafts_pending_review'     => [ 'type' => 'array' ],
						'drafts_pending_count'      => [ 'type' => 'integer' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_performance_report' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/create-contact-form',
			[
				'label'               => __( 'Create Contact Form', 'superdav-ai-agent' ),
				'description'         => __( 'Create a simple contact form for a page. Uses Contact Form 7 when available and otherwise returns a Gutenberg HTML block with a dependency-free form.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'title'           => [
							'type'        => 'string',
							'description' => 'Form title (default: "Contact Form").',
						],
						'recipient_email' => [
							'type'        => 'string',
							'description' => 'Email address that should receive submissions. Defaults to the site admin email.',
						],
						'submit_label'    => [
							'type'        => 'string',
							'description' => 'Submit button label (default: "Send Message").',
						],
						'site_url'        => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'provider'  => [ 'type' => 'string' ],
						'title'     => [ 'type' => 'string' ],
						'shortcode' => [ 'type' => 'string' ],
						'block'     => [ 'type' => 'string' ],
						'form_id'   => [ 'type' => 'integer' ],
						'message'   => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_create_contact_form' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	/**
	 * Handle the content-analyze ability call.
	 *
	 * @param array<string,mixed> $input Input with optional post_type, limit, site_url.
	 * @return array<string,mixed> Content analysis results.
	 */
	public static function handle_content_analyze( array $input ): array {
		// @phpstan-ignore-next-line
		$post_type = sanitize_text_field( $input['post_type'] ?? 'post' );
		// @phpstan-ignore-next-line
		$limit    = min( 100, max( 1, (int) ( $input['limit'] ?? 20 ) ) );
		$site_url = $input['site_url'] ?? '';

		$switched = false;

		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);

			if ( $blog_id && $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		$result = self::analyze_content_strategy( $posts, $post_type );

		if ( $switched ) {
			restore_current_blog();
		}

		return $result;
	}

	/**
	 * Analyze content strategy across posts.
	 *
	 * @param \WP_Post[] $posts     Array of posts.
	 * @param string     $post_type Post type being analyzed.
	 * @return array<string,mixed> Analysis data.
	 */
	private static function analyze_content_strategy( array $posts, string $post_type ): array {
		$total = count( $posts );

		if ( $total === 0 ) {
			return [
				'post_type'                      => $post_type,
				'total_analyzed'                 => 0,
				'publishing_frequency'           => (object) [],
				'avg_word_count'                 => 0,
				'min_word_count'                 => 0,
				'max_word_count'                 => 0,
				'category_distribution'          => (object) [],
				'posts_without_featured_image'   => [],
				'posts_without_meta_description' => [],
				'content_gap_categories'         => [],
				'thin_content_count'             => 0,
				'message'                        => 'No published posts found.',
			];
		}

		$word_counts           = [];
		$category_distribution = [];
		$without_featured      = [];
		$without_meta_desc     = [];
		$dates                 = [];

		foreach ( $posts as $post ) {
			$plain         = wp_strip_all_tags( $post->post_content );
			$wc            = str_word_count( $plain );
			$word_counts[] = $wc;
			$dates[]       = $post->post_date;

			// Categories.
			$cats = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );
			if ( ! is_wp_error( $cats ) ) {
				foreach ( $cats as $cat ) {
					if ( ! isset( $category_distribution[ $cat ] ) ) {
						$category_distribution[ $cat ] = 0;
					}
					++$category_distribution[ $cat ];
				}
			}

			// Featured image.
			if ( ! has_post_thumbnail( $post->ID ) ) {
				$without_featured[] = [
					'id'    => $post->ID,
					'title' => $post->post_title,
				];
			}

			// Meta description (Yoast or RankMath).
			$meta_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
			if ( empty( $meta_desc ) ) {
				$meta_desc = get_post_meta( $post->ID, 'rank_math_description', true );
			}
			if ( empty( $meta_desc ) ) {
				$without_meta_desc[] = [
					'id'    => $post->ID,
					'title' => $post->post_title,
				];
			}
		}

		// Publishing frequency.
		$frequency = [];
		if ( count( $dates ) >= 2 ) {
			$newest    = strtotime( $dates[0] );
			$oldest    = strtotime( end( $dates ) );
			$days      = max( 1, ( $newest - $oldest ) / DAY_IN_SECONDS );
			$frequency = [
				'posts_per_week'  => round( ( $total / $days ) * 7, 1 ),
				'posts_per_month' => round( ( $total / $days ) * 30, 1 ),
				'date_range_days' => (int) $days,
			];
		}

		arsort( $category_distribution );

		// Content gaps: categories with few posts.
		$content_gaps = [];
		foreach ( $category_distribution as $cat => $count ) {
			if ( $count <= 2 ) {
				$content_gaps[] = $cat;
			}
		}

		return [
			'post_type'                      => $post_type,
			'total_analyzed'                 => $total,
			'publishing_frequency'           => (object) $frequency,
			'avg_word_count'                 => (int) round( array_sum( $word_counts ) / $total ),
			'min_word_count'                 => min( $word_counts ),
			'max_word_count'                 => max( $word_counts ),
			'category_distribution'          => (object) $category_distribution,
			'posts_without_featured_image'   => $without_featured,
			'posts_without_meta_description' => $without_meta_desc,
			'content_gap_categories'         => $content_gaps,
			'thin_content_count'             => count( array_filter( $word_counts, fn( $wc ) => $wc < 300 ) ),
		];
	}

	/**
	 * Handle the content-performance-report ability call.
	 *
	 * @param array<string,mixed> $input Input with optional days, site_url.
	 * @return array<string,mixed> Performance report.
	 */
	public static function handle_performance_report( array $input ): array {
		// @phpstan-ignore-next-line
		$days     = max( 1, min( 365, (int) ( $input['days'] ?? 30 ) ) );
		$site_url = $input['site_url'] ?? '';

		$switched = false;

		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);

			if ( $blog_id && $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$result = self::generate_performance_report( $days );

		if ( $switched ) {
			restore_current_blog();
		}

		return $result;
	}

	/**
	 * Handle the create-contact-form ability call.
	 *
	 * @param array<string,mixed> $input Input with optional title, recipient_email, submit_label, site_url.
	 * @return array<string,mixed> Contact form result.
	 */
	public static function handle_create_contact_form( array $input ): array {
		// @phpstan-ignore-next-line
		$title = sanitize_text_field( $input['title'] ?? __( 'Contact Form', 'superdav-ai-agent' ) );
		if ( '' === $title ) {
			$title = __( 'Contact Form', 'superdav-ai-agent' );
		}

		// @phpstan-ignore-next-line
		$recipient_email = sanitize_email( $input['recipient_email'] ?? get_option( 'admin_email' ) );
		if ( '' === $recipient_email ) {
			$recipient_email = (string) get_option( 'admin_email' );
		}

		// @phpstan-ignore-next-line
		$submit_label = sanitize_text_field( $input['submit_label'] ?? __( 'Send Message', 'superdav-ai-agent' ) );
		if ( '' === $submit_label ) {
			$submit_label = __( 'Send Message', 'superdav-ai-agent' );
		}

		$site_url = $input['site_url'] ?? '';
		$switched = false;

		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);

			if ( $blog_id && $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$result = self::create_contact_form_result( $title, $recipient_email, $submit_label );

		if ( $switched ) {
			restore_current_blog();
		}

		return $result;
	}

	/**
	 * Create the best available contact form representation.
	 *
	 * @param string $title           Form title.
	 * @param string $recipient_email Recipient email address.
	 * @param string $submit_label    Submit button label.
	 * @return array<string,mixed> Contact form result.
	 */
	private static function create_contact_form_result( string $title, string $recipient_email, string $submit_label ): array {
		$cf7_class = 'WPCF7_ContactForm';

		if ( class_exists( $cf7_class ) ) {
			$cf7_result = self::create_contact_form_7_form( $cf7_class, $title, $recipient_email, $submit_label );

			if ( ! empty( $cf7_result ) ) {
				return $cf7_result;
			}
		}

		$block = self::build_html_contact_form_block( $title, $recipient_email, $submit_label );

		return [
			'provider'  => 'html',
			'title'     => $title,
			'shortcode' => '',
			'block'     => $block,
			'form_id'   => 0,
			'message'   => __( 'Contact Form 7 is not active; use the returned Gutenberg HTML block in the page content.', 'superdav-ai-agent' ),
		];
	}

	/**
	 * Create a Contact Form 7 form when the plugin is active.
	 *
	 * @param string $cf7_class       Contact Form 7 class name.
	 * @param string $title           Form title.
	 * @param string $recipient_email Recipient email address.
	 * @param string $submit_label    Submit button label.
	 * @return array<string,mixed> Contact Form 7 result, or empty array on unsupported CF7 API.
	 */
	private static function create_contact_form_7_form( string $cf7_class, string $title, string $recipient_email, string $submit_label ): array {
		$form_markup = self::build_contact_form_7_markup( $submit_label );
		$mail        = [
			'subject'            => sprintf(
				/* translators: %s: contact form title */
				__( '%s submission', 'superdav-ai-agent' ),
				$title
			),
			'sender'             => '[your-name] <[your-email]>',
			'body'               => "From: [your-name] <[your-email]>\nSubject: [your-subject]\n\nMessage:\n[your-message]",
			'recipient'          => $recipient_email,
			'additional_headers' => 'Reply-To: [your-email]',
			'attachments'        => '',
			'use_html'           => false,
			'exclude_blank'      => false,
		];

		if ( method_exists( $cf7_class, 'create' ) ) {
			$create_method = new \ReflectionMethod( $cf7_class, 'create' );
			$form          = $create_method->getNumberOfParameters() > 0 ? $create_method->invoke( null, $title ) : $create_method->invoke( null );
		} elseif ( method_exists( $cf7_class, 'get_template' ) ) {
			$template_method = new \ReflectionMethod( $cf7_class, 'get_template' );
			$form            = $template_method->invoke(
				null,
				[
					'title' => $title,
				]
			);
		} else {
			return [];
		}

		if ( is_object( $form ) && method_exists( $form, 'set_properties' ) ) {
			$form->set_properties(
				[
					'form' => $form_markup,
					'mail' => $mail,
				]
			);
		}

		if ( is_object( $form ) && method_exists( $form, 'save' ) ) {
			$form->save();
		}

		if ( ! is_object( $form ) || ! method_exists( $form, 'id' ) ) {
			return [];
		}

		$form_id = (int) $form->id();
		if ( $form_id <= 0 ) {
			return [];
		}

		$shortcode = sprintf(
			'[contact-form-7 id="%d" title="%s"]',
			$form_id,
			esc_attr( $title )
		);

		return [
			'provider'  => 'contact-form-7',
			'title'     => $title,
			'shortcode' => $shortcode,
			'block'     => '<!-- wp:shortcode -->' . "\n" . $shortcode . "\n" . '<!-- /wp:shortcode -->',
			'form_id'   => $form_id,
			'message'   => __( 'Contact Form 7 form created; insert the shortcode block into page content.', 'superdav-ai-agent' ),
		];
	}

	/**
	 * Build Contact Form 7 form markup.
	 *
	 * @param string $submit_label Submit button label.
	 * @return string Contact Form 7 markup.
	 */
	private static function build_contact_form_7_markup( string $submit_label ): string {
		return sprintf(
			"<label>Your name\n[text* your-name autocomplete:name]</label>\n\n<label>Your email\n[email* your-email autocomplete:email]</label>\n\n<label>Subject\n[text your-subject]</label>\n\n<label>Your message\n[textarea* your-message]</label>\n\n[submit \"%s\"]",
			esc_attr( $submit_label )
		);
	}

	/**
	 * Build a dependency-free Gutenberg HTML contact form block.
	 *
	 * @param string $title           Form title.
	 * @param string $recipient_email Recipient email address.
	 * @param string $submit_label    Submit button label.
	 * @return string Gutenberg HTML block markup.
	 */
	private static function build_html_contact_form_block( string $title, string $recipient_email, string $submit_label ): string {
		$mailto = add_query_arg(
			[
				'subject' => $title,
			],
			'mailto:' . $recipient_email
		);

		$html = sprintf(
			'<form class="sd-ai-agent-contact-form" action="%1$s" method="post"><p><label>%2$s<br><input type="text" name="name" autocomplete="name" required></label></p><p><label>%3$s<br><input type="email" name="email" autocomplete="email" required></label></p><p><label>%4$s<br><input type="text" name="subject"></label></p><p><label>%5$s<br><textarea name="message" rows="6" required></textarea></label></p><p><button type="submit">%6$s</button></p></form>',
			esc_url( $mailto ),
			esc_html__( 'Name', 'superdav-ai-agent' ),
			esc_html__( 'Email', 'superdav-ai-agent' ),
			esc_html__( 'Subject', 'superdav-ai-agent' ),
			esc_html__( 'Message', 'superdav-ai-agent' ),
			esc_html( $submit_label )
		);

		return '<!-- wp:html -->' . "\n" . $html . "\n" . '<!-- /wp:html -->';
	}

	/**
	 * Generate a content performance report.
	 *
	 * @param int $days Number of days to look back.
	 * @return array<string,mixed> Report data.
	 */
	private static function generate_performance_report( int $days ): array {
		$after_date = gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$days} days" ) );

		// Published posts in period.
		$published = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'date_query'     => [
					[ 'after' => $after_date ],
				],
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		$total = count( $published );

		// Category breakdown.
		$by_category = [];
		$word_counts = [];
		$by_author   = [];

		foreach ( $published as $post ) {
			$plain         = wp_strip_all_tags( $post->post_content );
			$word_counts[] = str_word_count( $plain );

			$cats = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );
			if ( ! is_wp_error( $cats ) ) {
				foreach ( $cats as $cat ) {
					if ( ! isset( $by_category[ $cat ] ) ) {
						$by_category[ $cat ] = 0;
					}
					++$by_category[ $cat ];
				}
			}

			$author_name = get_the_author_meta( 'display_name', (int) $post->post_author );
			if ( ! isset( $by_author[ $author_name ] ) ) {
				$by_author[ $author_name ] = 0;
			}
			++$by_author[ $author_name ];
		}

		arsort( $by_category );
		arsort( $by_author );

		// Posts by status (all types).
		$status_counts = [];
		foreach ( [ 'publish', 'draft', 'pending', 'future', 'private' ] as $status ) {
			$count = (int) wp_count_posts( 'post' )->$status;
			if ( $count > 0 ) {
				$status_counts[ $status ] = $count;
			}
		}

		// Pending review drafts.
		$pending_drafts = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => [ 'draft', 'pending' ],
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		$pending_list = [];
		foreach ( $pending_drafts as $draft ) {
			$pending_list[] = [
				'id'     => $draft->ID,
				'title'  => $draft->post_title,
				'status' => $draft->post_status,
				'date'   => $draft->post_date,
			];
		}

		// Previous period for comparison.
		$prev_after     = gmdate( 'Y-m-d H:i:s', (int) strtotime( '-' . ( $days * 2 ) . ' days' ) );
		$prev_before    = $after_date;
		$prev_published = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'date_query'     => [
					[
						'after'  => $prev_after,
						'before' => $prev_before,
					],
				],
			]
		);

		return [
			'period_days'               => $days,
			'posts_published'           => $total,
			'previous_period_published' => count( $prev_published ),
			'avg_word_count'            => $total > 0 ? (int) round( array_sum( $word_counts ) / $total ) : 0,
			'posts_by_category'         => (object) $by_category,
			'posts_by_author'           => (object) $by_author,
			'all_posts_by_status'       => (object) $status_counts,
			'drafts_pending_review'     => $pending_list,
			'drafts_pending_count'      => count( $pending_list ),
		];
	}
}
