<?php

declare(strict_types=1);
/**
 * Code-owned, deterministic landing-page pattern catalog.
 *
 * @package SdAiAgent\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Services;

use SdAiAgent\DesignSystem\ArtifactManifest;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Selects structural landing-page families from a confirmed site brief.
 *
 * The catalog deliberately describes structure only. It never supplies copy,
 * testimonials, statistics, media URLs, or persisted page state.
 */
final class LandingPagePatternCatalog {

	private const CATALOG_VERSION = '1.0.0';

	private const GOVERNANCE_GENERATED_AT = '2026-07-19T00:00:00Z';

	/**
	 * @var list<string>
	 */
	private const CORE_BLOCK_ALLOWLIST = [
		'core/buttons',
		'core/button',
		'core/columns',
		'core/column',
		'core/cover',
		'core/details',
		'core/group',
		'core/heading',
		'core/image',
		'core/list',
		'core/list-item',
		'core/media-text',
		'core/navigation',
		'core/paragraph',
		'core/post-content',
		'core/query',
		'core/separator',
		'core/social-links',
		'core/spacer',
	];

	/**
	 * @var array<string,mixed>|null
	 */
	private static ?array $validatedCatalog = null;

	/**
	 * Return the stable catalog schema version.
	 */
	public static function get_catalog_version(): string {
		return self::CATALOG_VERSION;
	}

	/**
	 * Return the complete immutable catalog after validating every definition.
	 *
	 * @return list<array<string,mixed>>|WP_Error
	 */
	public static function get_families(): array|WP_Error {
		if ( null !== self::$validatedCatalog ) {
			return self::$validatedCatalog;
		}

		$catalog = self::build_catalog();
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		$catalog = self::validate_catalog( $catalog );
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		self::$validatedCatalog = $catalog;

		return self::$validatedCatalog;
	}

	/**
	 * Choose one family and structural variant for a normalized site brief.
	 *
	 * Ranking is lexicographic rather than relying on an opaque aggregate score:
	 * primary goal, site type, required content, layout notes, section requests,
	 * then catalog order. This keeps explicit user intent above inference.
	 *
	 * @param array<string,mixed> $input Site brief and optional known content.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function select_family( array $input ): array|WP_Error {
		$families = self::get_families();
		if ( is_wp_error( $families ) ) {
			return $families;
		}

		$context = self::normalize_selection_context( $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$candidates = [];
		foreach ( $families as $fallback_order => $family ) {
			$candidates[] = self::score_family( $family, $context, $fallback_order );
		}

		$goal_candidates = array_values(
			array_filter(
				$candidates,
				static function ( array $candidate ): bool {
					return $candidate['score_breakdown']['primary_goal']['score'] > 0;
				}
			)
		);
		$eligible_goal_candidates = array_values(
			array_filter(
				$goal_candidates,
				static function ( array $candidate ): bool {
					return $candidate['eligible'];
				}
			)
		);

		/*
		 * A stated goal whose matching patterns are missing business content must
		 * prompt for that content, not silently fall through to an unrelated,
		 * complete family.
		 */
		if ( [] !== $goal_candidates && [] === $eligible_goal_candidates ) {
			self::sort_candidates( $goal_candidates );

			return self::clarification_result( $goal_candidates[0], $candidates );
		}

		$eligible = [] !== $eligible_goal_candidates
			? $eligible_goal_candidates
			: array_values(
				array_filter(
					$candidates,
					static function ( array $candidate ): bool {
						return $candidate['eligible'];
					}
				)
			);

		if ( [] === $eligible ) {
			self::sort_candidates( $candidates );

			return self::clarification_result( $candidates[0], $candidates );
		}

		self::sort_candidates( $eligible );
		$selected         = $eligible[0];
		$variant_selection = self::select_variant( $selected['family'], $context );

		return [
			'catalog_version'      => self::CATALOG_VERSION,
			'selected_family'      => $selected['family'],
			'selected_variant'     => $variant_selection['variant'],
			'score_breakdown'      => array_merge(
				$selected['score_breakdown'],
				[ 'variant' => $variant_selection['score_breakdown'] ]
			),
			'reasons'              => array_merge( $selected['reasons'], $variant_selection['reasons'] ),
			'missing_content'      => [],
			'rejected_alternatives' => self::rejected_alternatives( $candidates, $selected['family']['slug'] ),
			'requires_clarification' => false,
		];
	}

	/**
	 * Validate a catalog supplied by tests or future code-owned revisions.
	 *
	 * @param list<array<string,mixed>> $families Catalog definitions.
	 * @return list<array<string,mixed>>|WP_Error
	 */
	public static function validate_catalog( array $families ): array|WP_Error {
		if ( [] === $families || ! array_is_list( $families ) ) {
			return self::error( 'invalid_catalog', __( 'The landing-page pattern catalog must be a non-empty list.', 'superdav-ai-agent' ) );
		}

		$slugs = [];
		foreach ( $families as $family ) {
			if ( ! is_array( $family ) ) {
				return self::error( 'invalid_family', __( 'Every landing-page pattern family must be an object.', 'superdav-ai-agent' ) );
			}

			$validation = self::validate_family( $family );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			if ( isset( $slugs[ $family['slug'] ] ) ) {
				return self::error(
					'duplicate_family_slug',
					__( 'Landing-page pattern family slugs must be unique.', 'superdav-ai-agent' ),
					[ 'slug' => $family['slug'] ]
				);
			}
			$slugs[ $family['slug'] ] = true;
		}

		return $families;
	}

	/**
	 * Build the bounded initial catalog without user or WordPress state.
	 *
	 * @return list<array<string,mixed>>|WP_Error
	 */
	private static function build_catalog(): array|WP_Error {
		$families = [
			self::family(
				'lead-generation',
				'Lead Generation',
				'Convert qualified visitors into inquiries, consultations, quotes, or demos.',
				[ 'lead', 'lead generation', 'inquiry', 'inquiries', 'consultation', 'consultations', 'quote', 'quotes', 'demo', 'demos' ],
				[ 'saas', 'technology', 'professional services', 'consulting', 'agency', 'law firm', 'clinic', 'service business' ],
				[ 'site_name', 'offer', 'cta_destination' ],
				[ 'social_proof', 'services', 'case_studies', 'testimonials', 'faq', 'team' ],
				self::section_roles( [ 'hero', 'proof', 'offer', 'process', 'faq', 'final-cta' ], [ 'proof', 'faq' ] ),
				[
					self::variant(
						'proof-led',
						'Proof-led Conversion',
						self::section_roles( [ 'hero', 'social-proof', 'offer', 'case-studies', 'faq', 'final-cta' ], [ 'social-proof', 'case-studies', 'faq' ] ),
						[ 'Use a compact proof band immediately after the hero and alternate card and narrative sections.' ],
						[ 'social proof', 'testimonials', 'case studies', 'logos' ]
					),
					self::variant(
						'consultative',
						'Consultative Service',
						self::section_roles( [ 'hero', 'services', 'process', 'qualification', 'faq', 'final-cta' ], [ 'qualification', 'faq' ] ),
						[ 'Use a wide service grid followed by a linear process so visitors can self-qualify before the CTA.' ],
						[ 'services', 'process', 'consultation', 'qualification' ]
					),
				],
				[ 'Use focused product conversion when the primary action is an immediate product purchase.' ],
				[ 'services', 'expertise', 'consultation', 'demo', 'quote' ]
			),
			self::family(
				'focused-product-conversion',
				'Focused Product Conversion',
				'Move a visitor from one clearly defined product offer to a purchase or checkout action.',
				[ 'product', 'purchase', 'purchases', 'buy', 'sales', 'sale', 'checkout', 'order', 'conversion' ],
				[ 'e-commerce', 'ecommerce', 'retail', 'shop', 'store', 'product', 'subscription commerce' ],
				[ 'site_name', 'product', 'cta_destination' ],
				[ 'product_media', 'price_or_offer', 'reviews', 'shipping_or_fulfilment', 'faq' ],
				self::section_roles( [ 'hero', 'product-focus', 'benefits', 'trust', 'purchase-cta', 'faq' ], [ 'trust', 'faq' ] ),
				[
					self::variant(
						'benefit-led',
						'Benefit-led Product',
						self::section_roles( [ 'hero', 'benefits', 'product-focus', 'trust', 'purchase-cta', 'faq' ], [ 'trust', 'faq' ] ),
						[ 'Use a single product focus area, then a concise equal-height benefit grid before the purchase CTA.' ],
						[ 'benefits', 'features', 'single product' ]
					),
					self::variant(
						'comparison-led',
						'Comparison-led Product',
						self::section_roles( [ 'hero', 'product-focus', 'comparison', 'trust', 'purchase-cta', 'faq' ], [ 'comparison', 'trust', 'faq' ] ),
						[ 'Place the comparison in a horizontally scrollable mobile-safe wrapper and keep the purchase CTA before and after it.' ],
						[ 'comparison', 'plans', 'options' ]
					),
				],
				[ 'Use lead generation when the visitor should request a consultation before receiving an offer.' ],
				[ 'product', 'purchase', 'checkout', 'benefits', 'pricing' ]
			),
			self::family(
				'booking-reservation',
				'Booking and Reservation',
				'Help visitors reserve a table, appointment, stay, service slot, or event attendance.',
				[ 'booking', 'book', 'reservation', 'reservations', 'appointment', 'appointments', 'reserve' ],
				[ 'restaurant', 'cafe', 'bar', 'food service', 'hotel', 'venue', 'event venue', 'salon', 'spa', 'clinic' ],
				[ 'site_name', 'booking_method' ],
				[ 'menu_or_service_list', 'location_or_contact', 'hours', 'availability', 'gallery', 'accessibility_details' ],
				self::section_roles( [ 'hero', 'availability', 'offer', 'location', 'reservation-cta', 'faq' ], [ 'availability', 'location', 'faq' ] ),
				[
					self::variant(
						'menu-led',
						'Menu-led Reservation',
						self::section_roles( [ 'hero', 'menu-or-offer', 'atmosphere', 'location', 'reservation-cta', 'faq' ], [ 'atmosphere', 'location', 'faq' ] ),
						[ 'Place the reservation CTA after the hero and repeat it after the menu or offer preview without inventing menu items.' ],
						[ 'menu', 'dining', 'restaurant', 'food' ]
					),
					self::variant(
						'appointment-led',
						'Appointment-led Booking',
						self::section_roles( [ 'hero', 'services', 'availability', 'process', 'booking-cta', 'faq' ], [ 'availability', 'faq' ] ),
						[ 'Use service cards followed by a clear booking process; keep any availability representation factual and supplied by the business.' ],
						[ 'appointment', 'services', 'availability', 'schedule' ]
					),
				],
				[ 'Use local visit/contact when the primary action is directions or a conversation rather than a reservable slot.' ],
				[ 'reservation', 'booking', 'appointment', 'menu', 'hours' ]
			),
			self::family(
				'local-visit-contact',
				'Local Visit and Contact',
				'Guide nearby visitors to a location, contact channel, or practical next step.',
				[ 'visit', 'local visit', 'contact', 'directions', 'location', 'call', 'foot traffic' ],
				[ 'local business', 'restaurant', 'cafe', 'bar', 'retail', 'shop', 'clinic', 'service business', 'community organisation' ],
				[ 'site_name', 'location_or_contact' ],
				[ 'hours', 'services', 'map', 'parking_or_access', 'local_reviews', 'gallery' ],
				self::section_roles( [ 'hero', 'local-value', 'location-contact', 'practical-details', 'contact-cta' ], [ 'practical-details' ] ),
				[
					self::variant(
						'location-led',
						'Location-led Visit',
						self::section_roles( [ 'hero', 'location-contact', 'hours-access', 'local-value', 'contact-cta' ], [ 'hours-access' ] ),
						[ 'Bring location and contact controls above the fold; keep maps or directions as supplied, accessible links rather than decorative embeds.' ],
						[ 'location', 'directions', 'map', 'hours' ]
					),
					self::variant(
						'service-led',
						'Service-led Local Contact',
						self::section_roles( [ 'hero', 'services', 'local-trust', 'location-contact', 'contact-cta' ], [ 'local-trust' ] ),
						[ 'Use a wide services grid, then show only known local proof and contact options.' ],
						[ 'services', 'local reviews', 'contact' ]
					),
				],
				[ 'Use booking/reservation when the main conversion requires selecting an available date or time.' ],
				[ 'location', 'address', 'contact', 'hours', 'directions' ]
			),
			self::family(
				'portfolio-inquiry',
				'Portfolio Inquiry',
				'Show verified work samples and invite a prospective client to start an inquiry.',
				[ 'portfolio', 'showcase', 'showcase work', 'inquiry', 'inquiries', 'creative inquiry', 'project inquiry' ],
				[ 'portfolio', 'photographer', 'designer', 'creative', 'artist', 'developer', 'architect', 'studio' ],
				[ 'site_name', 'portfolio_items', 'inquiry_method' ],
				[ 'services', 'bio', 'process', 'testimonials', 'awards_or_press' ],
				self::section_roles( [ 'hero', 'selected-work', 'capabilities', 'process', 'inquiry-cta' ], [ 'process' ] ),
				[
					self::variant(
						'work-first',
						'Work-first Portfolio',
						self::section_roles( [ 'hero', 'selected-work', 'project-context', 'capabilities', 'inquiry-cta' ], [ 'project-context' ] ),
						[ 'Use an editorial work grid with accessible project labels; defer biography until after real work samples.' ],
						[ 'gallery', 'projects', 'work', 'case studies' ]
					),
					self::variant(
						'process-first',
						'Process-first Portfolio',
						self::section_roles( [ 'hero', 'capabilities', 'process', 'selected-work', 'inquiry-cta' ], [ 'process' ] ),
						[ 'Explain the supplied process structurally before selected work; do not invent client outcomes or testimonials.' ],
						[ 'process', 'services', 'how it works' ]
					),
				],
				[ 'Use lead generation when no verified portfolio items are available to show.' ],
				[ 'portfolio', 'projects', 'work', 'case studies', 'gallery' ]
			),
			self::family(
				'donation-volunteering',
				'Donation and Volunteering',
				'Connect supporters to a verified donation or volunteering path without fabricating impact evidence.',
				[ 'donation', 'donate', 'fundraising', 'volunteer', 'volunteering', 'support', 'awareness' ],
				[ 'non-profit', 'nonprofit', 'charity', 'organisation', 'organization', 'community', 'foundation' ],
				[ 'site_name', 'mission', 'donation_or_volunteer_path' ],
				[ 'programs', 'impact_evidence', 'stories', 'events', 'newsletter', 'contact' ],
				self::section_roles( [ 'hero', 'mission', 'programs', 'ways-to-help', 'support-cta', 'updates' ], [ 'programs', 'updates' ] ),
				[
					self::variant(
						'donation-led',
						'Donation-led Support',
						self::section_roles( [ 'hero', 'mission', 'impact-evidence', 'ways-to-help', 'donation-cta', 'updates' ], [ 'impact-evidence', 'updates' ] ),
						[ 'Repeat the verified donation action after the mission and any supplied impact evidence; never invent totals, stories, or beneficiary claims.' ],
						[ 'donate', 'donation', 'impact', 'fundraising' ]
					),
					self::variant(
						'volunteer-led',
						'Volunteer-led Support',
						self::section_roles( [ 'hero', 'mission', 'volunteer-roles', 'process', 'volunteer-cta', 'updates' ], [ 'process', 'updates' ] ),
						[ 'Show only known volunteer roles and requirements, followed by the supplied sign-up path.' ],
						[ 'volunteer', 'roles', 'get involved' ]
					),
				],
				[ 'Use content subscription when the primary relationship is receiving ongoing editorial content rather than supporting a mission.' ],
				[ 'mission', 'donate', 'volunteer', 'programs', 'impact' ]
			),
			self::family(
				'content-subscription',
				'Content Subscription',
				'Help readers subscribe to a known publication, newsletter, or recurring content offer.',
				[ 'subscription', 'subscribe', 'newsletter', 'readers', 'audience', 'reader growth' ],
				[ 'blog', 'media', 'newsletter', 'publication', 'magazine', 'podcast', 'creator' ],
				[ 'site_name', 'publication_or_topic', 'subscription_method' ],
				[ 'featured_content', 'recent_content', 'author_or_host', 'categories', 'social_proof' ],
				self::section_roles( [ 'hero', 'editorial-value', 'featured-content', 'subscription-cta', 'recent-content', 'about' ], [ 'featured-content', 'recent-content', 'about' ] ),
				[
					self::variant(
						'editorial-led',
						'Editorial-led Subscription',
						self::section_roles( [ 'hero', 'featured-content', 'editorial-value', 'recent-content', 'subscription-cta', 'about' ], [ 'featured-content', 'recent-content', 'about' ] ),
						[ 'Lead with supplied editorial material and retain a persistent subscription CTA without inventing article titles or excerpts.' ],
						[ 'articles', 'posts', 'editorial', 'featured content' ]
					),
					self::variant(
						'creator-led',
						'Creator-led Subscription',
						self::section_roles( [ 'hero', 'editorial-value', 'about', 'featured-content', 'subscription-cta' ], [ 'about', 'featured-content' ] ),
						[ 'Place supplied creator or host context before the subscription action, with a compact recent-content area afterwards.' ],
						[ 'author', 'host', 'creator', 'podcast' ]
					),
				],
				[ 'Use donation/volunteering when the primary relationship is mission support rather than recurring editorial delivery.' ],
				[ 'newsletter', 'subscribe', 'articles', 'blog', 'podcast' ]
			),
		];

		return self::add_governance( $families );
	}

	/**
	 * Create one complete family definition with shared safety metadata.
	 *
	 * @param list<string>              $goal_aliases Goal aliases.
	 * @param list<string>              $site_types Compatible site types.
	 * @param list<string>              $required_content Required known-content keys.
	 * @param list<string>              $optional_content Optional known-content keys.
	 * @param list<array<string,mixed>> $section_roles Ordered section roles.
	 * @param list<array<string,mixed>> $variants Structural variants.
	 * @param list<string>              $contraindications Contraindications.
	 * @param list<string>              $layout_keywords Layout matching terms.
	 * @return array<string,mixed>
	 */
	private static function family( string $slug, string $title, string $visitor_goal, array $goal_aliases, array $site_types, array $required_content, array $optional_content, array $section_roles, array $variants, array $contraindications, array $layout_keywords ): array {
		foreach ( $variants as $index => $variant ) {
			$variants[ $index ]['required_content']           = $required_content;
			$variants[ $index ]['optional_content']           = $optional_content;
			$variants[ $index ]['core_block_allowlist']       = self::CORE_BLOCK_ALLOWLIST;
			$variants[ $index ]['responsive_behavior']        = self::responsive_behavior();
			$variants[ $index ]['accessibility_requirements'] = self::accessibility_requirements();
		}

		return [
			'slug'                       => $slug,
			'title'                      => $title,
			'visitor_goal'               => $visitor_goal,
			'goal_aliases'               => $goal_aliases,
			'compatible_site_types'      => $site_types,
			'required_content'           => $required_content,
			'optional_content'           => $optional_content,
			'section_roles'              => $section_roles,
			'core_block_allowlist'       => self::CORE_BLOCK_ALLOWLIST,
			'responsive_behavior'        => self::responsive_behavior(),
			'accessibility_requirements' => self::accessibility_requirements(),
			'variants'                   => $variants,
			'contraindications'          => $contraindications,
			'layout_keywords'            => $layout_keywords,
		];
	}

	/**
	 * Create one structural variant without user-facing page content.
	 *
	 * @param list<array<string,mixed>> $section_roles Ordered section roles.
	 * @param list<string>              $layout_cues Layout instructions.
	 * @param list<string>              $selection_keywords Selector hints.
	 * @return array<string,mixed>
	 */
	private static function variant( string $slug, string $title, array $section_roles, array $layout_cues, array $selection_keywords ): array {
		return [
			'slug'               => $slug,
			'title'              => $title,
			'section_roles'      => $section_roles,
			'layout_cues'        => $layout_cues,
			'selection_keywords' => $selection_keywords,
		];
	}

	/**
	 * Return ordered role metadata for structural composition.
	 *
	 * @param list<string> $roles Required and optional roles in display order.
	 * @param list<string> $optional_roles Roles that may be omitted when no facts exist.
	 * @return list<array{role:string,required:bool}>
	 */
	private static function section_roles( array $roles, array $optional_roles = [] ): array {
		$sections = [];
		foreach ( $roles as $role ) {
			$sections[] = [
				'role'     => $role,
				'required' => ! in_array( $role, $optional_roles, true ),
			];
		}

		return $sections;
	}

	/**
	 * Describe concrete ordering behavior at each viewport class.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function responsive_behavior(): array {
		return [
			'mobile'  => [
				'columns' => 'Stack multi-column content into one column in source order, with the primary CTA before secondary actions.',
				'media'   => 'Place meaningful media before the related text only when it preserves the source reading order; decorative media remains hidden from assistive technology.',
			],
			'tablet'  => [
				'columns' => 'Use two columns only when each column retains readable line lengths; otherwise keep the mobile source order.',
				'cta'     => 'Keep primary and secondary actions grouped and visible without horizontal scrolling.',
			],
			'desktop' => [
				'columns' => 'Use wide aligned columns or media-text layouts for complementary content while retaining the mobile source order in markup.',
				'cta'     => 'Use full-width section bands with constrained content and repeat only the verified primary action at logical decision points.',
			],
		];
	}

	/**
	 * Return non-negotiable accessibility metadata for every family and variant.
	 *
	 * @return array<string,string>
	 */
	private static function accessibility_requirements(): array {
		return [
			'heading_hierarchy' => 'Use exactly one H1, then a logical heading hierarchy without skipped levels.',
			'landmarks'         => 'Use semantic header, main, navigation, and footer landmarks through core blocks and template parts.',
			'descriptive_ctas'  => 'Use descriptive CTA labels that identify the destination or action without relying on surrounding context.',
			'focus_behavior'    => 'Keep visible keyboard focus, logical tab order, and no keyboard trap in interactive controls.',
			'media_alternatives' => 'Provide supplied alt text or captions for meaningful media and hide decorative media from assistive technology.',
			'contrast'          => 'Meet WCAG AA contrast for text, controls, and focus indicators using the validated design-token palette.',
			'reduced_motion'    => 'Honor prefers-reduced-motion and never make motion necessary to understand or operate the page.',
		];
	}

	/**
	 * Add complete #2248-compatible governance metadata and canonical hashes.
	 *
	 * @param list<array<string,mixed>> $families Raw family definitions.
	 * @return list<array<string,mixed>>|WP_Error
	 */
	private static function add_governance( array $families ): array|WP_Error {
		foreach ( $families as $family_index => $family ) {
			$family_governance = self::governance_for( 'landing-page-' . $family['slug'], $family );
			if ( is_wp_error( $family_governance ) ) {
				return $family_governance;
			}
			$families[ $family_index ]['governance'] = $family_governance;

			foreach ( $family['variants'] as $variant_index => $variant ) {
				$source = [
					'family_slug'  => $family['slug'],
					'family_title' => $family['title'],
					'variant'      => $variant,
				];
				$governance = self::governance_for( 'landing-page-' . $family['slug'] . '-' . $variant['slug'], $source );
				if ( is_wp_error( $governance ) ) {
					return $governance;
				}
				$families[ $family_index ]['variants'][ $variant_index ]['governance'] = $governance;
			}
		}

		return $families;
	}

	/**
	 * Return #2248-compatible metadata for static code-owned catalog content.
	 *
	 * @param array<string,mixed> $source Hash source with no governance field.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function governance_for( string $slug, array $source ): array|WP_Error {
		$hash = ArtifactManifest::hash_payload( $source );
		if ( is_wp_error( $hash ) ) {
			return self::error(
				'governance_hash_failed',
				__( 'Landing-page pattern metadata could not be canonically hashed.', 'superdav-ai-agent' ),
				[ 'slug' => $slug, 'cause' => $hash->get_error_code() ]
			);
		}

		return [
			'id'            => 'sd-ai-agent/pattern/' . $slug,
			'version'       => self::CATALOG_VERSION,
			'maturity'      => 'stable',
			'provenance'    => [
				'generator_version' => self::CATALOG_VERSION,
				'source_type'       => 'code-owned-catalog',
				'source_reference'  => 'LandingPagePatternCatalog',
				'generated_at'      => self::GOVERNANCE_GENERATED_AT,
				'input_hash'        => $hash,
			],
			'compatibility' => [
				'wordpress'         => [ 'min' => '7.0', 'max' => null ],
				'theme_json'        => [ 'min' => 3, 'max' => 3 ],
				'required_blocks'   => self::CORE_BLOCK_ALLOWLIST,
				'required_features' => [ 'block-themes' ],
				'theme_constraints' => [ 'block-theme' ],
			],
			'deprecation'   => null,
			'integrity'     => [ 'content_hash' => $hash ],
		];
	}

	/**
	 * Validate one complete family and its variants.
	 *
	 * @param array<string,mixed> $family Family definition.
	 * @return true|WP_Error
	 */
	private static function validate_family( array $family ): true|WP_Error {
		$required_fields = [
			'slug',
			'title',
			'visitor_goal',
			'goal_aliases',
			'compatible_site_types',
			'required_content',
			'optional_content',
			'section_roles',
			'core_block_allowlist',
			'responsive_behavior',
			'accessibility_requirements',
			'variants',
			'contraindications',
			'layout_keywords',
			'governance',
		];
		foreach ( $required_fields as $field ) {
			if ( ! array_key_exists( $field, $family ) ) {
				return self::error(
					'incomplete_family',
					__( 'Every landing-page pattern family must include complete metadata.', 'superdav-ai-agent' ),
					[ 'field' => $field ]
				);
			}
		}

		if ( ! is_string( $family['slug'] ) || ! self::is_slug( $family['slug'] ) || ! is_string( $family['title'] ) || '' === trim( $family['title'] ) || ! is_string( $family['visitor_goal'] ) || '' === trim( $family['visitor_goal'] ) ) {
			return self::error( 'invalid_family_identity', __( 'Landing-page pattern families require stable slugs, titles, and visitor goals.', 'superdav-ai-agent' ) );
		}

		foreach ( [ 'goal_aliases', 'compatible_site_types', 'required_content', 'optional_content', 'contraindications', 'layout_keywords' ] as $field ) {
			if ( ! self::is_non_empty_string_list( $family[ $field ] ) ) {
				return self::error( 'invalid_family_list', __( 'Landing-page pattern family metadata must use non-empty string lists.', 'superdav-ai-agent' ), [ 'family' => $family['slug'], 'field' => $field ] );
			}
		}

		if ( ! self::is_section_role_list( $family['section_roles'] ) ) {
			return self::error( 'invalid_section_roles', __( 'Landing-page pattern section roles must be ordered role objects.', 'superdav-ai-agent' ), [ 'family' => $family['slug'] ] );
		}
		if ( ! self::is_core_block_list( $family['core_block_allowlist'] ) ) {
			return self::error( 'invalid_block_allowlist', __( 'Landing-page pattern block allowlists must use canonical core/* names only.', 'superdav-ai-agent' ), [ 'family' => $family['slug'] ] );
		}
		if ( ! self::is_responsive_behavior( $family['responsive_behavior'] ) ) {
			return self::error( 'invalid_responsive_behavior', __( 'Landing-page patterns must specify mobile, tablet, and desktop behavior.', 'superdav-ai-agent' ), [ 'family' => $family['slug'] ] );
		}
		if ( ! self::is_accessibility_requirements( $family['accessibility_requirements'] ) ) {
			return self::error( 'invalid_accessibility_requirements', __( 'Landing-page patterns must include complete accessibility requirements.', 'superdav-ai-agent' ), [ 'family' => $family['slug'] ] );
		}

		$family_source = $family;
		unset( $family_source['governance'] );
		$governance = self::validate_governance( $family['governance'], $family_source, $family['slug'] );
		if ( is_wp_error( $governance ) ) {
			return $governance;
		}

		if ( ! is_array( $family['variants'] ) || ! array_is_list( $family['variants'] ) || [] === $family['variants'] ) {
			return self::error( 'missing_variants', __( 'Every landing-page pattern family must include at least one structural variant.', 'superdav-ai-agent' ), [ 'family' => $family['slug'] ] );
		}

		$variant_slugs = [];
		foreach ( $family['variants'] as $variant ) {
			if ( ! is_array( $variant ) ) {
				return self::error( 'invalid_variant', __( 'Landing-page pattern variants must be objects.', 'superdav-ai-agent' ), [ 'family' => $family['slug'] ] );
			}
			$variant_validation = self::validate_variant( $family, $variant );
			if ( is_wp_error( $variant_validation ) ) {
				return $variant_validation;
			}
			if ( isset( $variant_slugs[ $variant['slug'] ] ) ) {
				return self::error( 'duplicate_variant_slug', __( 'Landing-page pattern variant slugs must be unique within a family.', 'superdav-ai-agent' ), [ 'family' => $family['slug'], 'slug' => $variant['slug'] ] );
			}
			$variant_slugs[ $variant['slug'] ] = true;
		}

		return true;
	}

	/**
	 * Validate one complete structural variant.
	 *
	 * @param array<string,mixed> $family Family definition.
	 * @param array<string,mixed> $variant Variant definition.
	 * @return true|WP_Error
	 */
	private static function validate_variant( array $family, array $variant ): true|WP_Error {
		$required_fields = [ 'slug', 'title', 'required_content', 'optional_content', 'section_roles', 'core_block_allowlist', 'responsive_behavior', 'accessibility_requirements', 'layout_cues', 'selection_keywords', 'governance' ];
		foreach ( $required_fields as $field ) {
			if ( ! array_key_exists( $field, $variant ) ) {
				return self::error( 'incomplete_variant', __( 'Every landing-page pattern variant must include complete governed metadata.', 'superdav-ai-agent' ), [ 'family' => $family['slug'], 'field' => $field ] );
			}
		}
		if ( ! is_string( $variant['slug'] ) || ! self::is_slug( $variant['slug'] ) || ! is_string( $variant['title'] ) || '' === trim( $variant['title'] ) ) {
			return self::error( 'invalid_variant_identity', __( 'Landing-page pattern variants require stable slugs and titles.', 'superdav-ai-agent' ), [ 'family' => $family['slug'] ] );
		}
		foreach ( [ 'required_content', 'optional_content', 'layout_cues', 'selection_keywords' ] as $field ) {
			if ( ! self::is_non_empty_string_list( $variant[ $field ] ) ) {
				return self::error( 'invalid_variant_list', __( 'Landing-page pattern variant metadata must use non-empty string lists.', 'superdav-ai-agent' ), [ 'family' => $family['slug'], 'variant' => $variant['slug'], 'field' => $field ] );
			}
		}
		if ( ! self::is_section_role_list( $variant['section_roles'] ) || ! self::is_core_block_list( $variant['core_block_allowlist'] ) || ! self::is_responsive_behavior( $variant['responsive_behavior'] ) || ! self::is_accessibility_requirements( $variant['accessibility_requirements'] ) ) {
			return self::error( 'invalid_variant_structure', __( 'Landing-page pattern variants must include complete structural, responsive, accessibility, and core-block metadata.', 'superdav-ai-agent' ), [ 'family' => $family['slug'], 'variant' => $variant['slug'] ] );
		}

		$source = [
			'family_slug'  => $family['slug'],
			'family_title' => $family['title'],
			'variant'      => $variant,
		];
		unset( $source['variant']['governance'] );

		return self::validate_governance( $variant['governance'], $source, $family['slug'] . '/' . $variant['slug'] );
	}

	/**
	 * Validate complete, canonical #2248 governance metadata.
	 *
	 * @param mixed               $governance Governance metadata.
	 * @param array<string,mixed> $source Canonical hash source.
	 * @return true|WP_Error
	 */
	private static function validate_governance( mixed $governance, array $source, string $identifier ): true|WP_Error {
		if ( ! is_array( $governance ) || ! isset( $governance['id'], $governance['version'], $governance['maturity'], $governance['provenance'], $governance['compatibility'], $governance['integrity'] ) ) {
			return self::error( 'incomplete_governance', __( 'Landing-page patterns require complete design-artifact governance metadata.', 'superdav-ai-agent' ), [ 'identifier' => $identifier ] );
		}
		if ( ! is_string( $governance['id'] ) || ! str_starts_with( $governance['id'], 'sd-ai-agent/pattern/' ) || ! is_string( $governance['version'] ) || ! ArtifactManifest::is_valid_semver( $governance['version'] ) || 'stable' !== $governance['maturity'] ) {
			return self::error( 'invalid_governance_identity', __( 'Landing-page pattern governance must use a stable sd-ai-agent/pattern ID and Semantic Versioning.', 'superdav-ai-agent' ), [ 'identifier' => $identifier ] );
		}
		if ( ! is_array( $governance['provenance'] ) || ! is_array( $governance['compatibility'] ) || ! is_array( $governance['integrity'] ) ) {
			return self::error( 'invalid_governance_shape', __( 'Landing-page pattern governance must include provenance, compatibility, and integrity objects.', 'superdav-ai-agent' ), [ 'identifier' => $identifier ] );
		}
		$hash = ArtifactManifest::hash_payload( $source );
		if ( is_wp_error( $hash ) ) {
			return self::error( 'governance_hash_failed', __( 'Landing-page pattern governance could not be verified.', 'superdav-ai-agent' ), [ 'identifier' => $identifier ] );
		}
		if ( ! isset( $governance['provenance']['input_hash'], $governance['integrity']['content_hash'] ) || ! is_string( $governance['provenance']['input_hash'] ) || ! is_string( $governance['integrity']['content_hash'] ) || ! hash_equals( $hash, $governance['provenance']['input_hash'] ) || ! hash_equals( $hash, $governance['integrity']['content_hash'] ) ) {
			return self::error( 'governance_integrity_mismatch', __( 'Landing-page pattern governance hashes must match the complete structural metadata.', 'superdav-ai-agent' ), [ 'identifier' => $identifier ] );
		}
		$compatibility = $governance['compatibility'];
		if ( ! isset( $compatibility['wordpress']['min'], $compatibility['theme_json']['min'], $compatibility['required_blocks'], $compatibility['required_features'], $compatibility['theme_constraints'] ) || '7.0' !== $compatibility['wordpress']['min'] || 3 !== $compatibility['theme_json']['min'] || ! self::is_core_block_list( $compatibility['required_blocks'] ) ) {
			return self::error( 'invalid_governance_compatibility', __( 'Landing-page pattern governance must declare its WordPress, theme.json, feature, theme, and core block compatibility.', 'superdav-ai-agent' ), [ 'identifier' => $identifier ] );
		}

		return true;
	}

	/**
	 * Normalize the selector input without accepting page markup or mutations.
	 *
	 * @param array<string,mixed> $input Raw selection input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function normalize_selection_context( array $input ): array|WP_Error {
		$brief = $input['site_brief'] ?? $input['siteBrief'] ?? [];
		if ( ! is_array( $brief ) || array_is_list( $brief ) ) {
			return self::error( 'invalid_site_brief', __( 'site_brief must be an object when supplied.', 'superdav-ai-agent' ) );
		}

		$layout_notes = self::normalize_string_list( $input['layout_notes'] ?? $brief['layoutNotes'] ?? $brief['layout_notes'] ?? [], 'layout_notes' );
		if ( is_wp_error( $layout_notes ) ) {
			return $layout_notes;
		}
		$section_requests = self::normalize_string_list( $input['section_requests'] ?? $brief['sectionRequests'] ?? $brief['section_requests'] ?? [], 'section_requests' );
		if ( is_wp_error( $section_requests ) ) {
			return $section_requests;
		}
		$content = self::normalize_available_content( $input['available_content'] ?? [], $brief, $input );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return [
			'primary_goal'     => self::normalize_text( self::first_string( $brief, [ 'primaryGoal', 'primary_goal', 'goal' ] ) ),
			'site_type'        => self::normalize_text( self::first_string( $brief, [ 'siteType', 'site_type', 'type' ] ) ),
			'layout_notes'     => $layout_notes,
			'section_requests' => $section_requests,
			'available_content' => $content,
		];
	}

	/**
	 * Score one candidate using the documented selection order.
	 *
	 * @param array<string,mixed> $family Family definition.
	 * @param array<string,mixed> $context Normalized selector input.
	 * @return array<string,mixed>
	 */
	private static function score_family( array $family, array $context, int $fallback_order ): array {
		$goal_matches    = self::matching_terms( $context['primary_goal'], $family['goal_aliases'] );
		$site_matches    = self::matching_terms( $context['site_type'], $family['compatible_site_types'] );
		$missing_content = [];
		foreach ( $family['required_content'] as $content_key ) {
			if ( ! isset( $context['available_content'][ $content_key ] ) ) {
				$missing_content[] = $content_key;
			}
		}
		$layout_matches  = self::matching_terms_in_list( $context['layout_notes'], $family['layout_keywords'] );
		$section_matches = self::matching_terms_in_list( $context['section_requests'], self::section_role_names( $family['section_roles'] ) );
		$required_count  = count( $family['required_content'] );
		$content_score   = $required_count - count( $missing_content );

		$reasons = [];
		if ( [] !== $goal_matches ) {
			$reasons[] = sprintf( __( 'Matches the explicit primary goal through: %s.', 'superdav-ai-agent' ), implode( ', ', $goal_matches ) );
		}
		if ( [] !== $site_matches ) {
			$reasons[] = sprintf( __( 'Matches the site type through: %s.', 'superdav-ai-agent' ), implode( ', ', $site_matches ) );
		}
		if ( [] !== $layout_matches ) {
			$reasons[] = sprintf( __( 'Honors layout notes referencing: %s.', 'superdav-ai-agent' ), implode( ', ', $layout_matches ) );
		}
		if ( [] !== $section_matches ) {
			$reasons[] = sprintf( __( 'Includes requested section roles: %s.', 'superdav-ai-agent' ), implode( ', ', $section_matches ) );
		}
		if ( [] === $reasons ) {
			$reasons[] = __( 'Uses the catalog’s deterministic fallback order because the brief contains no stronger structural signal.', 'superdav-ai-agent' );
		}

		return [
			'family'          => $family,
			'eligible'        => [] === $missing_content,
			'missing_content' => $missing_content,
			'reasons'         => $reasons,
			'score_breakdown' => [
				'primary_goal'     => [ 'score' => [] === $goal_matches ? 0 : 1, 'matched_terms' => $goal_matches ],
				'site_type'        => [ 'score' => [] === $site_matches ? 0 : 1, 'matched_terms' => $site_matches ],
				'required_content' => [ 'score' => $content_score, 'required' => $required_count, 'missing' => $missing_content ],
				'layout_notes'     => [ 'score' => count( $layout_matches ), 'matched_terms' => $layout_matches ],
				'section_requests' => [ 'score' => count( $section_matches ), 'matched_terms' => $section_matches ],
				'fallback_order'   => $fallback_order,
			],
		];
	}

	/**
	 * Select a structural variant after the family is known.
	 *
	 * @param array<string,mixed> $family Family definition.
	 * @param array<string,mixed> $context Normalized selector input.
	 * @return array{variant:array<string,mixed>,score_breakdown:array<string,mixed>,reasons:list<string>}
	 */
	private static function select_variant( array $family, array $context ): array {
		$candidates = [];
		foreach ( $family['variants'] as $order => $variant ) {
			$layout_matches  = self::matching_terms_in_list( $context['layout_notes'], $variant['selection_keywords'] );
			$section_matches = self::matching_terms_in_list( $context['section_requests'], $variant['selection_keywords'] );
			$candidates[]     = [
				'variant'         => $variant,
				'layout_matches'  => $layout_matches,
				'section_matches' => $section_matches,
				'order'           => $order,
			];
		}
		usort(
			$candidates,
			static function ( array $left, array $right ): int {
				$left_score  = [ count( $left['layout_matches'] ), count( $left['section_matches'] ), -$left['order'] ];
				$right_score = [ count( $right['layout_matches'] ), count( $right['section_matches'] ), -$right['order'] ];
				return $right_score <=> $left_score;
			}
		);
		$selected = $candidates[0];
		$reasons  = [];
		if ( [] !== $selected['layout_matches'] ) {
			$reasons[] = sprintf( __( 'Selects the variant for layout notes referencing: %s.', 'superdav-ai-agent' ), implode( ', ', $selected['layout_matches'] ) );
		}
		if ( [] !== $selected['section_matches'] ) {
			$reasons[] = sprintf( __( 'Selects the variant for requested structural cues: %s.', 'superdav-ai-agent' ), implode( ', ', $selected['section_matches'] ) );
		}
		if ( [] === $reasons ) {
			$reasons[] = __( 'Uses the family’s first stable structural variant as the deterministic fallback.', 'superdav-ai-agent' );
		}

		return [
			'variant'         => $selected['variant'],
			'score_breakdown' => [
				'layout_notes'     => [ 'score' => count( $selected['layout_matches'] ), 'matched_terms' => $selected['layout_matches'] ],
				'section_requests' => [ 'score' => count( $selected['section_matches'] ), 'matched_terms' => $selected['section_matches'] ],
				'fallback_order'   => $selected['order'],
			],
			'reasons'         => $reasons,
		];
	}

	/**
	 * Return a clarification response with no content-generating fallback.
	 *
	 * @param array<string,mixed>       $best_candidate Highest-priority candidate.
	 * @param list<array<string,mixed>> $candidates All candidates.
	 * @return array<string,mixed>
	 */
	private static function clarification_result( array $best_candidate, array $candidates ): array {
		return [
			'catalog_version'       => self::CATALOG_VERSION,
			'selected_family'       => null,
			'selected_variant'      => null,
			'score_breakdown'       => $best_candidate['score_breakdown'],
			'reasons'               => array_merge(
				$best_candidate['reasons'],
				[ __( 'No family is selected until the missing business content is confirmed; the selector will not fabricate it.', 'superdav-ai-agent' ) ]
			),
			'missing_content'       => $best_candidate['missing_content'],
			'fallback'              => [
				'slug'   => $best_candidate['family']['slug'],
				'title'  => $best_candidate['family']['title'],
				'reason' => __( 'Deterministic clarification fallback only; it is not safe to compose without the listed content.', 'superdav-ai-agent' ),
			],
			'rejected_alternatives' => self::rejected_alternatives( $candidates, null ),
			'requires_clarification' => true,
		];
	}

	/**
	 * Return the non-selected family decisions in deterministic catalog order.
	 *
	 * @param list<array<string,mixed>> $candidates Candidate decisions.
	 * @return list<array<string,mixed>>
	 */
	private static function rejected_alternatives( array $candidates, ?string $selected_slug ): array {
		self::sort_candidates( $candidates );
		$rejected = [];
		foreach ( $candidates as $candidate ) {
			if ( null !== $selected_slug && $selected_slug === $candidate['family']['slug'] ) {
				continue;
			}
			$reasons = $candidate['reasons'];
			if ( [] !== $candidate['missing_content'] ) {
				$reasons[] = sprintf( __( 'Rejected because required content is missing: %s.', 'superdav-ai-agent' ), implode( ', ', $candidate['missing_content'] ) );
			}
			$rejected[] = [
				'slug'            => $candidate['family']['slug'],
				'title'           => $candidate['family']['title'],
				'eligible'        => $candidate['eligible'],
				'score_breakdown' => $candidate['score_breakdown'],
				'reasons'         => $reasons,
				'missing_content' => $candidate['missing_content'],
			];
		}

		return $rejected;
	}

	/**
	 * Sort candidates in the documented deterministic order.
	 *
	 * @param list<array<string,mixed>> $candidates Candidate decisions.
	 */
	private static function sort_candidates( array &$candidates ): void {
		usort(
			$candidates,
			static function ( array $left, array $right ): int {
				$left_score  = $left['score_breakdown'];
				$right_score = $right['score_breakdown'];
				$left_vector = [
					$left['eligible'] ? 1 : 0,
					$left_score['primary_goal']['score'],
					$left_score['site_type']['score'],
					$left_score['required_content']['score'],
					$left_score['layout_notes']['score'],
					$left_score['section_requests']['score'],
					-$left_score['fallback_order'],
				];
				$right_vector = [
					$right['eligible'] ? 1 : 0,
					$right_score['primary_goal']['score'],
					$right_score['site_type']['score'],
					$right_score['required_content']['score'],
					$right_score['layout_notes']['score'],
					$right_score['section_requests']['score'],
					-$right_score['fallback_order'],
				];

				return $right_vector <=> $left_vector;
			}
		);
	}

	/**
	 * Normalize available content and derive only explicitly evidenced keys.
	 *
	 * @param mixed               $raw_content Caller-supplied content map or list.
	 * @param array<string,mixed> $brief Site brief.
	 * @param array<string,mixed> $input Full selector input.
	 * @return array<string,true>|WP_Error
	 */
	private static function normalize_available_content( mixed $raw_content, array $brief, array $input ): array|WP_Error {
		if ( ! is_array( $raw_content ) ) {
			return self::error( 'invalid_available_content', __( 'available_content must be an object or list of known content keys.', 'superdav-ai-agent' ) );
		}

		$content = [];
		foreach ( $raw_content as $key => $value ) {
			if ( is_int( $key ) ) {
				if ( ! is_string( $value ) || '' === trim( $value ) ) {
					return self::error( 'invalid_available_content', __( 'available_content lists may contain only non-empty string keys.', 'superdav-ai-agent' ) );
				}
				$content[ self::normalize_content_key( $value ) ] = true;
				continue;
			}
			if ( ! is_string( $key ) ) {
				return self::error( 'invalid_available_content', __( 'available_content keys must be strings.', 'superdav-ai-agent' ) );
			}
			if ( self::has_value( $value ) ) {
				$content[ self::normalize_content_key( $key ) ] = true;
			}
		}

		$source = array_merge( $input, $brief );
		$evidence_map = [
			'site_name'                  => [ 'siteName', 'site_name', 'name' ],
			'offer'                      => [ 'description', 'tagline', 'offer', 'offers', 'service', 'services', 'product', 'products' ],
			'cta_destination'            => [ 'cta_destination', 'cta_url', 'contact_url', 'booking_url', 'reservation_url', 'checkout_url', 'inquiry_url' ],
			'product'                    => [ 'product', 'products', 'product_name', 'catalog' ],
			'booking_method'             => [ 'booking_method', 'booking_url', 'reservation_url', 'appointment_url', 'phone' ],
			'location_or_contact'        => [ 'location', 'address', 'phone', 'email', 'contact_url', 'map_url' ],
			'portfolio_items'            => [ 'portfolio_items', 'projects', 'work', 'case_studies', 'gallery' ],
			'inquiry_method'             => [ 'inquiry_method', 'inquiry_url', 'contact_url', 'email', 'phone' ],
			'mission'                    => [ 'mission', 'mission_statement', 'cause' ],
			'donation_or_volunteer_path' => [ 'donation_url', 'volunteer_url', 'donation_or_volunteer_path', 'support_url' ],
			'publication_or_topic'       => [ 'publication', 'topic', 'newsletter_topic', 'content_topic' ],
			'subscription_method'        => [ 'subscription_url', 'newsletter_url', 'email_signup_url', 'subscription_method' ],
		];
		foreach ( $evidence_map as $content_key => $source_keys ) {
			foreach ( $source_keys as $source_key ) {
				if ( array_key_exists( $source_key, $source ) && self::has_value( $source[ $source_key ] ) ) {
					$content[ $content_key ] = true;
					break;
				}
			}
		}

		return $content;
	}

	/**
	 * Normalize a bounded string list from selector input.
	 *
	 * @param mixed $value Raw list.
	 * @return list<string>|WP_Error
	 */
	private static function normalize_string_list( mixed $value, string $field ): array|WP_Error {
		if ( [] === $value || null === $value ) {
			return [];
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 24 ) {
			return self::error( 'invalid_' . $field, __( 'Layout and section request inputs must be bounded lists of strings.', 'superdav-ai-agent' ), [ 'field' => $field ] );
		}
		$normalized = [];
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === trim( $item ) || strlen( $item ) > 500 ) {
				return self::error( 'invalid_' . $field, __( 'Layout and section request inputs must contain bounded non-empty strings.', 'superdav-ai-agent' ), [ 'field' => $field ] );
			}
			$normalized[] = self::normalize_text( $item );
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Return matched terms from a normalized input string.
	 *
	 * @param list<string> $terms Candidate terms.
	 * @return list<string>
	 */
	private static function matching_terms( string $value, array $terms ): array {
		if ( '' === $value ) {
			return [];
		}
		$matches = [];
		foreach ( $terms as $term ) {
			$normalized_term = self::normalize_text( $term );
			if ( '' !== $normalized_term && str_contains( ' ' . $value . ' ', ' ' . $normalized_term . ' ' ) ) {
				$matches[] = $term;
			}
		}

		return $matches;
	}

	/**
	 * Return terms matched by any supplied layout note or section request.
	 *
	 * @param list<string> $values Input strings.
	 * @param list<string> $terms Candidate terms.
	 * @return list<string>
	 */
	private static function matching_terms_in_list( array $values, array $terms ): array {
		$matches = [];
		foreach ( $values as $value ) {
			foreach ( self::matching_terms( $value, $terms ) as $term ) {
				$matches[ $term ] = true;
			}
		}

		return array_keys( $matches );
	}

	/**
	 * Extract names from ordered role definitions.
	 *
	 * @param list<array<string,mixed>> $roles Ordered role metadata.
	 * @return list<string>
	 */
	private static function section_role_names( array $roles ): array {
		$names = [];
		foreach ( $roles as $role ) {
			$names[] = $role['role'];
		}

		return $names;
	}

	/**
	 * Return the first string from a known set of site-brief keys.
	 *
	 * @param array<string,mixed> $source Source data.
	 * @param list<string>        $keys Candidate keys.
	 */
	private static function first_string( array $source, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $source[ $key ] ) && is_string( $source[ $key ] ) ) {
				return $source[ $key ];
			}
		}

		return '';
	}

	/**
	 * Normalize free text for deterministic matching without preserving content.
	 */
	private static function normalize_text( string $value ): string {
		$value = strtolower( trim( remove_accents( $value ) ) );
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Normalize a supplied content key to the catalog's stable vocabulary.
	 */
	private static function normalize_content_key( string $key ): string {
		return str_replace( ' ', '_', self::normalize_text( $key ) );
	}

	/**
	 * Test whether a source value is actual known business content.
	 */
	private static function has_value( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			return [] !== $value;
		}

		return is_int( $value ) || is_float( $value );
	}

	/**
	 * Check a stable catalog slug.
	 */
	private static function is_slug( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value );
	}

	/**
	 * Check a non-empty, unique string list.
	 */
	private static function is_non_empty_string_list( mixed $value ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || [] === $value ) {
			return false;
		}
		$seen = [];
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === trim( $item ) || isset( $seen[ $item ] ) ) {
				return false;
			}
			$seen[ $item ] = true;
		}

		return true;
	}

	/**
	 * Check ordered role object structure.
	 */
	private static function is_section_role_list( mixed $value ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || [] === $value ) {
			return false;
		}
		$seen = [];
		foreach ( $value as $role ) {
			if ( ! is_array( $role ) || ! isset( $role['role'], $role['required'] ) || ! is_string( $role['role'] ) || '' === trim( $role['role'] ) || ! is_bool( $role['required'] ) || isset( $seen[ $role['role'] ] ) ) {
				return false;
			}
			$seen[ $role['role'] ] = true;
		}

		return true;
	}

	/**
	 * Check canonical core block names.
	 */
	private static function is_core_block_list( mixed $value ): bool {
		if ( ! self::is_non_empty_string_list( $value ) ) {
			return false;
		}
		foreach ( $value as $block ) {
			if ( ! str_starts_with( $block, 'core/' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check all required viewport classes have concrete behavior.
	 */
	private static function is_responsive_behavior( mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( [ 'mobile', 'tablet', 'desktop' ] as $viewport ) {
			if ( ! isset( $value[ $viewport ] ) || ! is_array( $value[ $viewport ] ) || count( $value[ $viewport ] ) < 2 ) {
				return false;
			}
			foreach ( $value[ $viewport ] as $instruction ) {
				if ( ! is_string( $instruction ) || '' === trim( $instruction ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Check the shared accessibility contract remains complete.
	 */
	private static function is_accessibility_requirements( mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		$required = [ 'heading_hierarchy', 'landmarks', 'descriptive_ctas', 'focus_behavior', 'media_alternatives', 'contrast', 'reduced_motion' ];
		foreach ( $required as $key ) {
			if ( ! isset( $value[ $key ] ) || ! is_string( $value[ $key ] ) || '' === trim( $value[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return a consistently namespaced catalog validation error.
	 *
	 * @param array<string,mixed> $data Error metadata.
	 */
	private static function error( string $code, string $message, array $data = [] ): WP_Error {
		return new WP_Error( 'sd_ai_agent_landing_page_pattern_' . $code, $message, $data );
	}
}
