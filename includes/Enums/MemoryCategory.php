<?php
/**
 * Memory category enum.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Enums;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Valid memory categories for the AI agent's persistent memory system.
 */
enum MemoryCategory: string {

	case SiteInfo        = 'site_info';
	case SiteBrief       = 'site_brief';
	case UserPreferences = 'user_preferences';
	case TechnicalNotes  = 'technical_notes';
	case Workflows       = 'workflows';
	case General         = 'general';

	/**
	 * Get all category values as an array.
	 *
	 * @return array<string>
	 */
	public static function values(): array {
		return array_column( self::cases(), 'value' );
	}

	/**
	 * Check if a value is a valid category.
	 *
	 * @param string $value The category string to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function isValid( string $value ): bool {
		return in_array( $value, self::values(), true );
	}

	/**
	 * Get a category from string, defaulting to General if invalid.
	 *
	 * @param string $value The category string to convert.
	 * @return self The matching enum case, or General if the value is invalid.
	 */
	public static function fromStringOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::General;
	}
}
