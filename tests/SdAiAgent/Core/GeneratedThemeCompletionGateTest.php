<?php

declare(strict_types=1);
/**
 * Tests for the generated-theme activated-site completion gate.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\GeneratedThemeCompletionGate;
use WP_UnitTestCase;

/**
 * Verifies current-report, invalidation, and rollback invariants.
 */
class GeneratedThemeCompletionGateTest extends WP_UnitTestCase {

	private const STYLESHEET = 'generated-demo-theme';

	private const FINGERPRINT = 'current-project-fingerprint';

	private const HOME_URL = 'https://example.test/';

	private const INTERIOR_URL = 'https://example.test/contact/';

	/**
	 * A complete current browser report is the only passing state.
	 */
	public function test_current_activated_site_report_passes(): void {
		$gate = $this->prepare_activated_gate();
		$inputs = $gate->get_expected_report_inputs();

		$gate->record_tool_call( GeneratedThemeCompletionGate::CLIENT_ABILITY, $inputs );
		$gate->record_tool_response(
			GeneratedThemeCompletionGate::CLIENT_ABILITY,
			$this->passing_report( $inputs )
		);

		$this->assertTrue( $gate->is_required() );
		$this->assertTrue( $gate->has_current_passing_report() );
		$this->assertFalse( $gate->requires_repair() );
		$this->assertSame( '', $gate->get_terminal_notice() );
	}

	/**
	 * Any relevant page mutation invalidates a previously passing report.
	 */
	public function test_page_mutation_invalidates_passing_report(): void {
		$gate   = $this->prepare_activated_gate();
		$inputs = $gate->get_expected_report_inputs();
		$gate->record_tool_call( GeneratedThemeCompletionGate::CLIENT_ABILITY, $inputs );
		$gate->record_tool_response(
			GeneratedThemeCompletionGate::CLIENT_ABILITY,
			$this->passing_report( $inputs )
		);

		$this->assertTrue( $gate->has_current_passing_report() );

		$gate->record_tool_call( 'sd-ai-agent/update-post', array( 'post_id' => 42 ) );
		$gate->record_tool_response(
			'sd-ai-agent/update-post',
			array(
				'post_id'   => 42,
				'post_type' => 'page',
				'status'    => 'publish',
				'permalink' => self::INTERIOR_URL,
			)
		);

		$this->assertFalse( $gate->has_current_passing_report() );
		$this->assertTrue( $gate->requires_repair() );
		$this->assertStringContainsString(
			'validate-block-theme-project',
			$gate->get_repair_guidance()
		);
	}

	/**
	 * Global Styles changes invalidate frontend evidence because they alter the rendered theme.
	 */
	public function test_global_styles_mutation_invalidates_passing_report(): void {
		$gate   = $this->prepare_activated_gate();
		$inputs = $gate->get_expected_report_inputs();
		$gate->record_tool_call( GeneratedThemeCompletionGate::CLIENT_ABILITY, $inputs );
		$gate->record_tool_response(
			GeneratedThemeCompletionGate::CLIENT_ABILITY,
			$this->passing_report( $inputs )
		);

		$gate->record_tool_call( 'sd-ai-agent/reset-global-styles', array() );
		$gate->record_tool_response(
			'sd-ai-agent/reset-global-styles',
			array( 'success' => true )
		);

		$this->assertFalse( $gate->has_current_passing_report() );
		$this->assertTrue( $gate->requires_repair() );
	}

	/**
	 * A report bound to an old fingerprint cannot satisfy the gate.
	 */
	public function test_stale_fingerprint_report_is_rejected(): void {
		$gate   = $this->prepare_activated_gate();
		$inputs = $gate->get_expected_report_inputs();
		$stale  = $this->passing_report( $inputs );
		$stale['fingerprint'] = 'stale-project-fingerprint';

		$gate->record_tool_call( GeneratedThemeCompletionGate::CLIENT_ABILITY, $inputs );
		$gate->record_tool_response( GeneratedThemeCompletionGate::CLIENT_ABILITY, $stale );

		$this->assertFalse( $gate->has_current_passing_report() );
		$this->assertStringContainsString(
			'stale',
			strtolower( $gate->get_terminal_notice() )
		);
	}

	/**
	 * A report must include one clean result for every required URL and viewport.
	 */
	public function test_partial_report_is_rejected_even_when_marked_passed(): void {
		$gate   = $this->prepare_activated_gate();
		$inputs = $gate->get_expected_report_inputs();
		$report = $this->passing_report( $inputs );
		/** @var array<int,mixed> $reports */
		$reports = is_array( $report['reports'] ?? null ) ? $report['reports'] : array();
		array_pop( $reports );
		$report['reports'] = $reports;

		$gate->record_tool_call( GeneratedThemeCompletionGate::CLIENT_ABILITY, $inputs );
		$gate->record_tool_response( GeneratedThemeCompletionGate::CLIENT_ABILITY, $report );

		$this->assertFalse( $gate->has_current_passing_report() );
		$this->assertTrue( $gate->requires_repair() );
		$this->assertStringContainsString(
			'partial',
			strtolower( $gate->get_terminal_notice() )
		);
	}

	/**
	 * An interior request which renders the homepage cannot satisfy completion.
	 */
	public function test_interior_report_rendering_homepage_is_rejected(): void {
		$gate   = $this->prepare_activated_gate();
		$inputs = $gate->get_expected_report_inputs();
		$report = $this->passing_report( $inputs );
		$report['reports'][3]['is_homepage'] = true;

		$gate->record_tool_call( GeneratedThemeCompletionGate::CLIENT_ABILITY, $inputs );
		$gate->record_tool_response( GeneratedThemeCompletionGate::CLIENT_ABILITY, $report );

		$this->assertFalse( $gate->has_current_passing_report() );
	}

	/**
	 * Explicit page-role arguments must not permit an inverted URL assignment.
	 */
	public function test_swapped_explicit_page_role_arguments_are_rejected(): void {
		$gate    = $this->prepare_activated_gate();
		$inputs  = $gate->get_expected_report_inputs();
		$swapped = $inputs;
		$swapped['homepage_url'] = $inputs['interior_url'];
		$swapped['interior_url'] = $inputs['homepage_url'];
		$report = $this->passing_report( $swapped );

		$gate->record_tool_call( GeneratedThemeCompletionGate::CLIENT_ABILITY, $swapped );
		$gate->record_tool_response( GeneratedThemeCompletionGate::CLIENT_ABILITY, $report );

		$this->assertFalse( $gate->has_current_passing_report() );
	}

	/**
	 * A canonical homepage redirect remains valid when it is semantically home
	 * and remains distinct from the requested interior document.
	 */
	public function test_canonical_homepage_redirect_with_distinct_interior_passes(): void {
		$gate   = $this->prepare_activated_gate();
		$inputs = $gate->get_expected_report_inputs();
		$report = $this->passing_report( $inputs );
		foreach ( $report['reports'] as &$row ) {
			if ( 'homepage' === $row['role'] ) {
				$row['final_url'] = 'https://example.test/home';
			}
		}
		unset( $row );

		$gate->record_tool_call( GeneratedThemeCompletionGate::CLIENT_ABILITY, $inputs );
		$gate->record_tool_response( GeneratedThemeCompletionGate::CLIENT_ABILITY, $report );

		$this->assertTrue( $gate->has_current_passing_report() );
	}

	/**
	 * Missing browser capability remains incomplete instead of falling back to review prose.
	 */
	public function test_browser_unavailability_never_passes(): void {
		$gate = $this->prepare_activated_gate( array() );

		$this->assertTrue( $gate->requires_repair() );
		$this->assertFalse( $gate->has_current_passing_report() );
		$this->assertStringContainsString(
			'browser completion validator is unavailable',
			$gate->get_repair_guidance()
		);
	}

	/**
	 * A fully unrenderable activation requires restoring the prior stylesheet.
	 */
	public function test_fatal_unrenderable_report_requires_and_records_restoration(): void {
		$gate   = $this->prepare_activated_gate();
		$inputs = $gate->get_expected_report_inputs();
		$fatal  = array(
			'success'              => false,
			'complete'             => false,
			'passed'               => false,
			'fatal_render_failure' => true,
			'stylesheet'           => self::STYLESHEET,
			'fingerprint'          => self::FINGERPRINT,
			'reports'              => $this->failing_render_reports( $inputs ),
			'violations'           => array(),
		);

		$gate->record_tool_call( GeneratedThemeCompletionGate::CLIENT_ABILITY, $inputs );
		$gate->record_tool_response( GeneratedThemeCompletionGate::CLIENT_ABILITY, $fatal );

		$this->assertTrue( $gate->requires_restore() );
		$this->assertStringContainsString( 'Restore previous_stylesheet', $gate->get_repair_guidance() );

		$gate->record_tool_call( 'sd-ai-agent/activate-theme', array( 'stylesheet' => 'twentytwentyfive' ) );
		$gate->record_tool_response(
			'sd-ai-agent/activate-theme',
			array(
				'stylesheet'    => 'twentytwentyfive',
				'is_block_theme' => true,
			)
		);

		$this->assertFalse( $gate->requires_repair() );
		$this->assertStringContainsString( 'previous stylesheet was restored', $gate->get_terminal_notice() );
	}

	/**
	 * An unavailable browser client needs recovery, not a theme rollback.
	 */
	public function test_browser_execution_unavailable_does_not_require_restoration(): void {
		$gate   = $this->prepare_activated_gate();
		$inputs = $gate->get_expected_report_inputs();
		$report = $this->passing_report( $inputs );
		$report['success']                       = false;
		$report['complete']                      = false;
		$report['passed']                        = false;
		$report['fatal_render_failure']          = true;
		$report['browser_execution_unavailable'] = true;

		$gate->record_tool_call( GeneratedThemeCompletionGate::CLIENT_ABILITY, $inputs );
		$gate->record_tool_response( GeneratedThemeCompletionGate::CLIENT_ABILITY, $report );

		$this->assertFalse( $gate->requires_restore() );
		$this->assertStringContainsString( 'Browser execution was unavailable', $gate->get_terminal_notice() );
	}

	/**
	 * Build an activated gate with a validated generated project and interior page.
	 *
	 * @param list<string>|null $client_abilities Available browser abilities.
	 */
	private function prepare_activated_gate( ?array $client_abilities = null ): GeneratedThemeCompletionGate {
		$gate = new GeneratedThemeCompletionGate(
			$client_abilities ?? array( GeneratedThemeCompletionGate::CLIENT_ABILITY ),
			static fn(): string => self::HOME_URL
		);

		$gate->record_tool_call( 'sd-ai-agent/scaffold-block-theme', array( 'slug' => self::STYLESHEET ) );
		$gate->record_tool_response(
			'sd-ai-agent/scaffold-block-theme',
			array( 'stylesheet' => self::STYLESHEET )
		);
		$gate->record_tool_call( 'sd-ai-agent/create-post', array( 'post_type' => 'page' ) );
		$gate->record_tool_response(
			'sd-ai-agent/create-post',
			array(
				'post_id'   => 42,
				'post_type' => 'page',
				'status'    => 'publish',
				'permalink' => self::INTERIOR_URL,
			)
		);
		$gate->record_tool_call(
			'sd-ai-agent/validate-block-theme-project',
			array( 'stylesheet' => self::STYLESHEET )
		);
		$gate->record_tool_response(
			'sd-ai-agent/validate-block-theme-project',
			array(
				'valid'       => true,
				'marked'      => true,
				'fingerprint' => self::FINGERPRINT,
			)
		);
		$gate->record_tool_call( 'sd-ai-agent/activate-theme', array( 'stylesheet' => self::STYLESHEET ) );
		$gate->record_tool_response(
			'sd-ai-agent/activate-theme',
			array(
				'stylesheet'          => self::STYLESHEET,
				'previous_stylesheet' => 'twentytwentyfive',
				'is_block_theme'      => true,
			)
		);

		return $gate;
	}

	/**
	 * Return a passing deterministic client report for current inputs.
	 *
	 * @param array{stylesheet:string,fingerprint:string,homepage_url:string,interior_url:string,viewports:list<array{label:string,width:int,height:int}>} $inputs Expected inputs.
	 * @return array<string,mixed> Passing browser report.
	 */
	private function passing_report( array $inputs ): array {
		$reports = array();
		foreach (
			array(
				array( 'url' => $inputs['homepage_url'], 'role' => 'homepage', 'is_homepage' => true ),
				array( 'url' => $inputs['interior_url'], 'role' => 'interior', 'is_homepage' => false ),
			) as $surface
		) {
			foreach ( GeneratedThemeCompletionGate::REQUIRED_VIEWPORTS as $viewport ) {
				$reports[] = array(
					'url'              => $surface['url'],
					'requested_url'    => $surface['url'],
					'final_url'        => $surface['url'],
					'role'             => $surface['role'],
					'is_homepage'      => $surface['is_homepage'],
					'viewport'         => $viewport,
					'success'          => true,
					'active_stylesheet' => self::STYLESHEET,
					'violations'       => array(),
				);
			}
		}

		return array(
			'success'              => true,
			'complete'             => true,
			'passed'               => true,
			'fatal_render_failure' => false,
			'stylesheet'           => $inputs['stylesheet'],
			'fingerprint'          => $inputs['fingerprint'],
			'reports'              => $reports,
			'violations'           => array(),
		);
	}

	/**
	 * Return an all-unrenderable report matrix for the fatal rollback path.
	 *
	 * @param array{stylesheet:string,fingerprint:string,homepage_url:string,interior_url:string,viewports:list<array{label:string,width:int,height:int}>} $inputs Expected inputs.
	 * @return list<array<string,mixed>> Failed render rows.
	 */
	private function failing_render_reports( array $inputs ): array {
		$reports = array();
		foreach ( array( $inputs['homepage_url'], $inputs['interior_url'] ) as $url ) {
			foreach ( GeneratedThemeCompletionGate::REQUIRED_VIEWPORTS as $viewport ) {
				$reports[] = array(
					'url'              => $url,
					'viewport'         => $viewport,
					'success'          => false,
					'active_stylesheet' => '',
					'violations'       => array(),
					'error'            => 'Iframe load timed out.',
				);
			}
		}

		return $reports;
	}
}
