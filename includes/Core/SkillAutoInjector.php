<?php

declare(strict_types=1);
/**
 * Auto-inject relevant skill content into the system prompt based on
 * the user's message. Mirrors the knowledge-base RAG injection pattern
 * but uses keyword matching instead of vector search.
 *
 * Phase 2 (t216): Auto-injection is now model-aware. Strong models receive
 * the skill index only and call skill-load on demand. Weak models receive
 * auto-injected content (capped at 1 skill) to compensate for unreliable
 * tool-call behaviour.
 *
 * Phase 1 (t215): Injection events are recorded to sd_ai_agent_skill_usage
 * when model_id/session_id context is supplied.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Models\Skill;
use SdAiAgent\Repositories\SkillUsageRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SkillAutoInjector {

	/**
	 * Maximum number of skills to inject per prompt.
	 *
	 * Reduced from 2 to 1 (Phase 2/t216): weak models cannot effectively
	 * use two guides simultaneously, and injecting both wastes tokens.
	 */
	private const MAX_INJECTED_SKILLS = 1;

	/**
	 * Keyword-to-skill trigger map.
	 *
	 * Keys are regex patterns matched against the user message (case-insensitive).
	 * Values are skill slugs from the skills table.
	 *
	 * Order matters: more-specific patterns must appear before catch-all patterns
	 * that share keyword overlap (e.g. kadence before gutenberg, wp-block-development
	 * before gutenberg-blocks, wp-block-themes before block-themes).
	 *
	 * @var array<string, string>
	 */
	private const TRIGGER_MAP = [
		// Kadence-specific triggers must precede generic gutenberg-blocks patterns
		// so that messages mentioning kadence/* blocks route to the kadence skill first.
		'/\bkadence\b|kadence\/(?:rowlayout|column|advancedheading|advancedbtn|singlebtn)|\bkbVersion\b|\bcolLayout\b|kt-adv-heading|kt-inside-inner-col|kb-section-dir-horizontal|kt-highlight/i' => 'kadence-blocks',
		'/\b(?:header\s*builder|footer\s*builder|kadence\s*theme)\b|kadence_(?:before|after)_/i'                                       => 'kadence-theme',

		// WP REST API — precedes wp-plugin-development to avoid ambiguity on 'endpoint'/'register'.
		'/\brest\b.*\bendpoint\b|\bendpoint\b.*\brest\b|wp\/v2|REST_Controller|\/wp-json\/|register_rest_route|rest_api_init\b/i'     => 'wp-rest-api',

		// WP Block development — precedes gutenberg-blocks (content) patterns.
		'/block\.json|register_block_type|apiVersion|edit\.js|save\.js|\bviewScriptModule\b|\bwp-block-development\b/i'               => 'wp-block-development',

		// WP Block themes (structural) — precedes generic block-themes (content/FSE) pattern.
		'/\btheme\.json\b|\bblock\s+theme\b|\btemplates\/|\bparts\/|\bstyle\s+variation/i'                                            => 'wp-block-themes',

		// WP Plugin development — plugin architecture, hooks, settings, security.
		'/plugin\s+header|Plugin\s+Name\s*:|register_activation_hook|add_action\s*\(|add_filter\s*\(/i'                               => 'wp-plugin-development',

		// WP-CLI and ops — wp search-replace, wp cron, wp db, wp-cli.yml.
		'/\bwp-cli\b|\bwp\s+search-replace\b|\bwp\s+db\b|\bwp\s+cron\b|\bwp\s+cache\s+flush\b|\bwp-cli\.yml\b|\bwp_cron\b/i'        => 'wp-wpcli-and-ops',

		'/\b(?:create|build|make|write|generate|add)\b.*\b(?:page|pages|post|posts|blog|article|content|landing|homepage|layout)\b/i' => 'gutenberg-blocks',
		'/\b(?:page|pages|landing|homepage|layout|column|columns|hero|section|block|blocks|gutenberg)\b|\bfull[-\s]?width\b|\bfull[-\s]?bleed\b/i' => 'gutenberg-blocks',
		'/\b(?:woocommerce|product|products|store|shop|order|orders|cart|checkout|coupon)\b/i'                                         => 'woocommerce',
		'/\b(?:seo|ranking|rankings|meta\s*tags?|meta\s*description|sitemap|search\s*engine|keyword|keywords)\b/i'                     => 'seo-optimization',
		'/\b(?:full\s*site\s*edit|fse|block\s*theme|template\s*part|site\s*editor)\b/i'                                               => 'block-themes',
		'/\b(?:classic\s*theme|customizer|functions\.php|widget\s*area|sidebar\s*widget|child\s*theme)\b/i'                          => 'classic-themes',
		'/\b(?:multisite|network|subsite|subsites|sub-site)\b/i'                                                                       => 'multisite-management',
		'/\b(?:content\s*market|editorial|content\s*strateg|publish\s*schedule|content\s*audit)\b/i'                                    => 'content-marketing',
		'/\b(?:analytic|report|metric|dashboard|performance\s*report|growth)\b/i'                                                      => 'analytics-reporting',
		'/\b(?:debug|error|broken|fix|troubleshoot|white\s*screen|500|fatal|crash|slow)\b/i'                                           => 'site-troubleshooting',
	];

	/**
	 * Analyze the user message and return matching skill content to inject.
	 *
	 * When model_id and session_id are provided, each injected skill is
	 * recorded to the skill_usage table for telemetry.
	 *
	 * @param string $user_message The user's chat message.
	 * @param string $model_id     Model ID receiving the injection (for telemetry).
	 * @param int    $session_id   Session ID (0 if unknown) (for telemetry).
	 * @return string Formatted skill content for system prompt injection, or empty string.
	 */
	public static function inject_for_message( string $user_message, string $model_id = '', int $session_id = 0 ): string {
		if ( '' === trim( $user_message ) ) {
			return '';
		}

		$matched_slugs = self::match_skills( $user_message );

		if ( empty( $matched_slugs ) ) {
			return '';
		}

		$sections = [];

		foreach ( $matched_slugs as $slug ) {
			$content = Skill::get_content_by_slug( $slug );

			if ( null === $content || '' === $content ) {
				continue;
			}

			$sections[] = $content;

			// Record the injection event for telemetry (Phase 1 / t215).
			if ( '' !== $model_id ) {
				self::record_injection( $slug, $content, $model_id, $session_id );
			}
		}

		if ( empty( $sections ) ) {
			return '';
		}

		return "## Active Skill Guide\n"
			. 'The following skill guide has been auto-loaded based on your request. '
			. "Follow these instructions for the best results.\n\n"
			. implode( "\n\n---\n\n", $sections );
	}

	/**
	 * Get a context-aware skill hint for strong models.
	 *
	 * Strong models receive the lean skill index (~15 tok/skill) and are
	 * expected to call `sd-ai-agent/skill-load` on their own when needed.
	 * This method supplements the index with a targeted, one-line hint
	 * pointing at which skill(s) are particularly relevant to the current
	 * request — helping the model decide whether to load before proceeding,
	 * without injecting the full 1 500-3 000 token guide.
	 *
	 * Returns an empty string when no trigger pattern matches the message.
	 *
	 * @param string $user_message The user's chat message.
	 * @return string Inline hint text to append after the skill index, or empty string.
	 */
	public static function get_index_description( string $user_message ): string {
		if ( '' === trim( $user_message ) ) {
			return '';
		}

		$matched_slugs = self::match_skills( $user_message );

		if ( empty( $matched_slugs ) ) {
			return '';
		}

		return '> **Skill hint:** the following skill guide(s) are likely relevant to this request — '
			. 'call `sd-ai-agent/skill-load` before proceeding: `'
			. implode( '`, `', $matched_slugs )
			. '`';
	}

	/**
	 * Match user message against the trigger map and return unique skill slugs.
	 *
	 * Collects all pattern matches first, then de-duplicates with array_unique
	 * and caps at MAX_INJECTED_SKILLS. The two-pass approach avoids PHPStan
	 * type-narrowing issues with in_array/isset on a dynamically-growing array.
	 *
	 * @param string $user_message The user's chat message.
	 * @return list<string> Matched skill slugs (max MAX_INJECTED_SKILLS).
	 */
	private static function match_skills( string $user_message ): array {
		$raw = [];

		foreach ( self::TRIGGER_MAP as $pattern => $slug ) {
			if ( preg_match( $pattern, $user_message ) ) {
				$raw[] = $slug;
			}
		}

		$unique = array_values( array_unique( $raw ) );

		return array_slice( $unique, 0, self::MAX_INJECTED_SKILLS );
	}

	/**
	 * Per-request cache of skill slugs already auto-attached to ability
	 * responses, so we attach each skill at most once per chat turn.
	 *
	 * @var array<string, true>
	 */
	private static array $attached_for_request = [];

	/**
	 * Map ability id prefix → skill slug. When an ability matching a prefix
	 * is invoked via ability-call, the corresponding skill content is
	 * attached to the response on first call only.
	 *
	 * @var array<string, string>
	 */
	private const ABILITY_PREFIX_TO_SKILL = [
		'sd-ai-agent/seo-'                   => 'seo-optimization',
		'sd-ai-agent/create-block-content'   => 'gutenberg-blocks',
		'sd-ai-agent/parse-block-content'    => 'gutenberg-blocks',
		'sd-ai-agent/review-block'           => 'gutenberg-blocks',
		'sd-ai-agent/validate-block-content' => 'gutenberg-blocks',
		'sd-ai-agent/markdown-to-blocks'     => 'gutenberg-blocks',
		'sd-ai-agent/list-block-types'       => 'gutenberg-blocks',
		'sd-ai-agent/list-block-templates'   => 'gutenberg-blocks',
		'sd-ai-agent/list-block-patterns'    => 'gutenberg-blocks',
		'sd-ai-agent/get-block-type'         => 'gutenberg-blocks',
		'sd-ai-agent/get-theme-json'         => 'full-site-editing',
		'sd-ai-agent/get-global-styles'      => 'full-site-editing',
		'sd-ai-agent/update-global-styles'   => 'full-site-editing',
		'sd-ai-agent/reset-global-styles'    => 'full-site-editing',
	];

	/**
	 * Resolve the skill slug (if any) associated with a given ability id.
	 *
	 * @param string $ability_id Ability id to look up.
	 * @return string Empty string when no skill applies.
	 */
	public static function skill_for_ability( string $ability_id ): string {
		foreach ( self::ABILITY_PREFIX_TO_SKILL as $prefix => $slug ) {
			if ( str_starts_with( $ability_id, $prefix ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Return the auto-load skill content for an ability if it hasn't been
	 * attached yet this request, marking it as attached for subsequent
	 * calls. Returns null when no skill applies or when already attached.
	 *
	 * @param string $ability_id Ability id being invoked.
	 * @return array{slug:string,name:string,content:string}|null
	 */
	public static function consume_skill_for_ability( string $ability_id ): ?array {
		$slug = self::skill_for_ability( $ability_id );
		if ( '' === $slug ) {
			return null;
		}
		if ( isset( self::$attached_for_request[ $slug ] ) ) {
			return null;
		}

		// @phpstan-ignore-next-line
		$skill = Skill::get_by_slug( $slug );
		if ( ! $skill || ! (int) $skill->enabled ) {
			return null;
		}

		self::$attached_for_request[ $slug ] = true;

		return [
			'slug'    => $slug,
			'name'    => (string) $skill->name,
			'content' => (string) $skill->content,
		];
	}

	/**
	 * Reset per-request state. AgentLoop should call this between requests.
	 *
	 * @return void
	 */
	public static function reset_request_state(): void {
		self::$attached_for_request = [];
	}

	/**
	 * Record a skill auto-injection event to the usage table.
	 *
	 * Looks up the skill by slug to get its ID. Silently no-ops if the
	 * skill cannot be found (e.g. custom slug not yet in DB).
	 *
	 * @param string $slug       Skill slug that was injected.
	 * @param string $content    Injected content (used to estimate token cost).
	 * @param string $model_id   Model receiving the injection.
	 * @param int    $session_id Session context (0 if unknown).
	 * @return void
	 */
	private static function record_injection( string $slug, string $content, string $model_id, int $session_id ): void {
		$skill = Skill::get_by_slug( $slug );
		if ( ! $skill ) {
			return;
		}

		SkillUsageRepository::create(
			[
				'skill_id'        => $skill->id,
				'session_id'      => $session_id,
				'trigger_type'    => 'auto',
				'injected_tokens' => SkillUsageRepository::estimate_tokens( $content ),
				'outcome'         => 'unknown',
				'model_id'        => $model_id,
			]
		);
	}
}
