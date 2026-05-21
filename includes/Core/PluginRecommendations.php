<?php

declare(strict_types=1);
/**
 * Plugin recommendation registry for the block content policy.
 *
 * Ported from Automattic/studio apps/cli/ai/plugin-recommendations.ts.
 * Each PluginRecommendation describes a plugin that provides Gutenberg blocks
 * for a specific use-case that would otherwise end up inside a core/html block
 * (for example, contact forms). The registry is used by:
 *
 * - BlockContentPolicy::get_html_block_policy_issues() — to return a
 *   plugin-specific message instead of the generic core-blocks message.
 * - SystemInstructionBuilder::build() — to inject a
 *   "## Plugin Recommendations" section into the system prompt when at least
 *   one recommendation is registered and the setting is enabled.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @since   1.11.0
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1585
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object describing a plugin recommendation.
 *
 * @since 1.11.0
 */
final class PluginRecommendation {

	/**
	 * @since 1.11.0
	 *
	 * @param string   $name               Human-readable plugin name, e.g. "Jetpack Forms".
	 * @param string   $plugin_slug        WordPress.org plugin slug, e.g. "jetpack".
	 * @param string[] $blocks             Block names this plugin registers, e.g. ['jetpack/contact-form'].
	 * @param string   $guidance           System-prompt guidance appended under ## Plugin Recommendations.
	 * @param string[] $html_patterns      Regex patterns matched against core/html innerHTML.
	 *                                     If any pattern matches, $html_policy_message is returned.
	 * @param string   $html_policy_message Message to return when an html_pattern matches.
	 *                                     Empty string means "fall through to default message".
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $plugin_slug,
		public readonly array $blocks = [],
		public readonly string $guidance = '',
		public readonly array $html_patterns = [],
		public readonly string $html_policy_message = '',
	) {}
}

/**
 * Registry and factory for PluginRecommendation entries.
 *
 * Recommendations are populated in {@see self::get_all()} and cached for the
 * lifetime of the request. Third-party code can extend the registry via the
 * `sd_ai_agent_plugin_recommendations` filter.
 *
 * @since 1.11.0
 */
class PluginRecommendations {

	/**
	 * In-memory cache of registered recommendations (built once per request).
	 *
	 * @since 1.11.0
	 * @var PluginRecommendation[]|null
	 */
	private static ?array $recommendations = null;

	/**
	 * Return all registered plugin recommendations.
	 *
	 * The list is assembled once and then cached for the request lifetime.
	 * It includes the built-in Jetpack Forms entry and any entries added via
	 * the `sd_ai_agent_plugin_recommendations` filter.
	 *
	 * @since 1.11.0
	 *
	 * @return PluginRecommendation[]
	 */
	public static function get_all(): array {
		if ( null !== self::$recommendations ) {
			return self::$recommendations;
		}

		$recommendations = [
			/*
			 * Jetpack Forms — ported from Studio plugin-recommendations.ts.
			 * Matches <form>, <input>, <select>, <textarea>, and <fieldset>
			 * elements that appear inside a core/html block.
			 */
			new PluginRecommendation(
				name: 'Jetpack Forms',
				plugin_slug: 'jetpack',
				blocks: [
					'jetpack/contact-form',
					'jetpack/field-name',
					'jetpack/field-email',
					'jetpack/field-url',
					'jetpack/field-date',
					'jetpack/field-telephone',
					'jetpack/field-textarea',
					'jetpack/field-checkbox',
					'jetpack/field-checkbox-multiple',
					'jetpack/field-option-checkbox',
					'jetpack/field-radio',
					'jetpack/field-option-radio',
					'jetpack/field-select',
					'jetpack/field-option-select',
					'jetpack/field-consent',
					'jetpack/button',
				],
				guidance: "## Forms\n\n"
					. 'When the user asks for a contact form, subscription form, or any HTML form, '
					. 'use Jetpack Forms blocks instead of core/html. '
					. 'Jetpack Forms is included in the Jetpack plugin (plugin_slug: jetpack). '
					. 'Use `jetpack/contact-form` as the outer wrapper, add fields with the '
					. "`jetpack/field-*` blocks, and close with `jetpack/button`.\n\n"
					. 'Never wrap raw <form>, <input>, <select>, or <textarea> elements in a '
					. 'core/html block — the result is not editable and breaks accessibility.',
				html_patterns: [
					'/<(form|input|select|textarea|fieldset)\b/i',
				],
				html_policy_message: 'core/html contains a <form> element. '
					. 'Use Jetpack Forms blocks (jetpack/contact-form and jetpack/field-*) '
					. 'instead of raw HTML form elements. '
					. 'Raw form elements inside core/html are not editable and break accessibility.',
			),
		];

		/*
		 * Allow third-party code to register additional recommendations or
		 * remove built-in ones.
		 *
		 * @param PluginRecommendation[] $recommendations Registered recommendations.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			/** @var PluginRecommendation[] $recommendations */
			$recommendations = apply_filters( 'sd_ai_agent_plugin_recommendations', $recommendations );
		}

		self::$recommendations = $recommendations;

		return self::$recommendations;
	}

	/**
	 * Reset the in-memory cache.
	 *
	 * Intended for use in unit tests only.
	 *
	 * @since 1.11.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$recommendations = null;
	}

	/**
	 * Return the first plugin-specific html_policy_message whose html_patterns
	 * match the given core/html inner content.
	 *
	 * Called by {@see BlockContentPolicy::get_html_block_policy_issues()} before
	 * falling back to the generic default message.
	 *
	 * @since 1.11.0
	 *
	 * @param string $content Stripped inner HTML of the core/html block.
	 * @return string|null The specific policy message, or null when no pattern matches.
	 */
	public static function get_html_policy_message( string $content ): ?string {
		foreach ( self::get_all() as $rec ) {
			if ( empty( $rec->html_patterns ) || '' === $rec->html_policy_message ) {
				continue;
			}

			foreach ( $rec->html_patterns as $pattern ) {
				if ( 1 === preg_match( $pattern, $content ) ) {
					return $rec->html_policy_message;
				}
			}
		}

		return null;
	}

	/**
	 * Build the "## Plugin Recommendations" system-prompt section.
	 *
	 * Returns an empty string when no recommendations are registered (or none
	 * have guidance text), so callers can guard with `if ( '' !== $section )`.
	 *
	 * Modelled on Studio apps/cli/ai/system-prompt.ts buildPluginRecommendationsSection().
	 *
	 * @since 1.11.0
	 *
	 * @return string Formatted Markdown section, or empty string.
	 */
	public static function build_system_prompt_section(): string {
		$sections = [];

		foreach ( self::get_all() as $rec ) {
			if ( '' !== $rec->guidance ) {
				$sections[] = $rec->guidance;
			}
		}

		if ( empty( $sections ) ) {
			return '';
		}

		return "## Plugin Recommendations\n\n" . implode( "\n\n", $sections );
	}
}
