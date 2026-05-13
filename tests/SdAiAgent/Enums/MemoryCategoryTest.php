<?php
/**
 * Test case for MemoryCategory enum.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Enums;

use SdAiAgent\Enums\MemoryCategory;
use WP_UnitTestCase;

/**
 * Test MemoryCategory enum functionality.
 */
class MemoryCategoryTest extends WP_UnitTestCase {

	/**
	 * Test all memory category cases exist.
	 */
	public function test_memory_category_cases_exist() {
		$cases = MemoryCategory::cases();

		$this->assertCount(6, $cases);
		$this->assertContains(MemoryCategory::SiteInfo, $cases);
		$this->assertContains(MemoryCategory::SiteBrief, $cases);
		$this->assertContains(MemoryCategory::UserPreferences, $cases);
		$this->assertContains(MemoryCategory::TechnicalNotes, $cases);
		$this->assertContains(MemoryCategory::Workflows, $cases);
		$this->assertContains(MemoryCategory::General, $cases);
	}

	/**
	 * Test memory category values.
	 */
	public function test_memory_category_values() {
		$this->assertSame('site_info', MemoryCategory::SiteInfo->value);
		$this->assertSame('site_brief', MemoryCategory::SiteBrief->value);
		$this->assertSame('user_preferences', MemoryCategory::UserPreferences->value);
		$this->assertSame('technical_notes', MemoryCategory::TechnicalNotes->value);
		$this->assertSame('workflows', MemoryCategory::Workflows->value);
		$this->assertSame('general', MemoryCategory::General->value);
	}

	/**
	 * Test values() returns all values as array.
	 */
	public function test_values_returns_array() {
		$values = MemoryCategory::values();

		$this->assertIsArray($values);
		$this->assertCount(6, $values);
		$this->assertContains('site_info', $values);
		$this->assertContains('site_brief', $values);
		$this->assertContains('user_preferences', $values);
		$this->assertContains('technical_notes', $values);
		$this->assertContains('workflows', $values);
		$this->assertContains('general', $values);
	}

	/**
	 * Test isValid() with valid values.
	 */
	public function test_is_valid_with_valid_values() {
		$this->assertTrue(MemoryCategory::isValid('site_info'));
		$this->assertTrue(MemoryCategory::isValid('site_brief'));
		$this->assertTrue(MemoryCategory::isValid('user_preferences'));
		$this->assertTrue(MemoryCategory::isValid('technical_notes'));
		$this->assertTrue(MemoryCategory::isValid('workflows'));
		$this->assertTrue(MemoryCategory::isValid('general'));
	}

	/**
	 * Test isValid() with invalid values.
	 */
	public function test_is_valid_with_invalid_values() {
		$this->assertFalse(MemoryCategory::isValid('siteinfo')); // No underscore
		$this->assertFalse(MemoryCategory::isValid('Site_Info')); // Wrong case
		$this->assertFalse(MemoryCategory::isValid('custom'));
		$this->assertFalse(MemoryCategory::isValid(''));
	}

	/**
	 * Test tryFrom() with valid values.
	 */
	public function test_try_from_with_valid_values() {
		$this->assertSame(MemoryCategory::SiteInfo, MemoryCategory::tryFrom('site_info'));
		$this->assertSame(MemoryCategory::SiteBrief, MemoryCategory::tryFrom('site_brief'));
		$this->assertSame(MemoryCategory::General, MemoryCategory::tryFrom('general'));
	}

	/**
	 * Test tryFrom() with invalid values.
	 */
	public function test_try_from_with_invalid_values() {
		$this->assertNull(MemoryCategory::tryFrom('invalid'));
		$this->assertNull(MemoryCategory::tryFrom(''));
	}

	/**
	 * Test from() with valid values.
	 */
	public function test_from_with_valid_values() {
		$this->assertSame(MemoryCategory::SiteInfo, MemoryCategory::from('site_info'));
		$this->assertSame(MemoryCategory::Workflows, MemoryCategory::from('workflows'));
	}

	/**
	 * Test from() throws exception with invalid value.
	 */
	public function test_from_throws_exception_with_invalid_value() {
		$this->expectException(\ValueError::class);
		MemoryCategory::from('invalid');
	}

	/**
	 * Test fromStringOrDefault() with valid values.
	 */
	public function test_from_string_or_default_with_valid_values() {
		$this->assertSame(MemoryCategory::SiteInfo, MemoryCategory::fromStringOrDefault('site_info'));
		$this->assertSame(MemoryCategory::SiteBrief, MemoryCategory::fromStringOrDefault('site_brief'));
		$this->assertSame(MemoryCategory::Workflows, MemoryCategory::fromStringOrDefault('workflows'));
	}

	/**
	 * Test fromStringOrDefault() returns General for invalid values.
	 */
	public function test_from_string_or_default_returns_general_for_invalid() {
		$this->assertSame(MemoryCategory::General, MemoryCategory::fromStringOrDefault('invalid'));
		$this->assertSame(MemoryCategory::General, MemoryCategory::fromStringOrDefault(''));
		$this->assertSame(MemoryCategory::General, MemoryCategory::fromStringOrDefault('custom_category'));
	}
}
