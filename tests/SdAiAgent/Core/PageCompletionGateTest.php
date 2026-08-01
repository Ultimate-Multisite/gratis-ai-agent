<?php

declare(strict_types=1);
/**
 * Tests for the rendered page-quality completion gate.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\PageCompletionGate;
use WP_UnitTestCase;

/** Verifies agent profiles, stale-report binding, and viewport coverage. */
class PageCompletionGateTest extends WP_UnitTestCase {

	private const HOME_URL = 'https://example.test/';

	private const PAGE_URL = 'https://example.test/portfolio/';

	/** Setup keeps every published page target and requires three viewports. */
	public function test_setup_profile_tracks_all_pages_and_passes_complete_report(): void {
		$gate = $this->gate( PageCompletionGate::PROFILE_SETUP );
		$this->record_page( $gate, 41, self::PAGE_URL, 101 );
		$this->record_page( $gate, 42, 'https://example.test/contact/', 102 );

		$inputs = $gate->get_expected_report_inputs();
		$this->assertCount( 2, $inputs['pages'] );
		$this->assertSame( PageCompletionGate::SETUP_VIEWPORTS, $inputs['viewports'] );

		$gate->record_tool_call( PageCompletionGate::CLIENT_ABILITY, $inputs );
		$gate->record_tool_response( PageCompletionGate::CLIENT_ABILITY, $this->passing_report( $inputs ) );

		$this->assertTrue( $gate->has_current_passing_report() );
		$this->assertFalse( $gate->requires_repair() );
		$this->assertSame( '', $gate->get_terminal_notice() );
	}

	/** General replaces the prior target and uses only mobile and desktop. */
	public function test_incremental_profile_tracks_latest_page_only(): void {
		$gate = $this->gate( PageCompletionGate::PROFILE_INCREMENTAL );
		$this->record_page( $gate, 41, self::PAGE_URL, 101 );
		$this->record_page( $gate, 42, 'https://example.test/about/', 102 );

		$inputs = $gate->get_expected_report_inputs();
		$this->assertCount( 1, $inputs['pages'] );
		$this->assertSame( 42, $inputs['pages'][0]['post_id'] );
		$this->assertSame( PageCompletionGate::INCREMENTAL_VIEWPORTS, $inputs['viewports'] );
	}

	/** Stable block mutations participate in the same rendered-page gate. */
	public function test_block_mutation_requires_incremental_page_quality(): void {
		$gate = $this->gate( PageCompletionGate::PROFILE_INCREMENTAL );
		$gate->record_tool_call(
			'sd-ai-agent/update-blocks',
			array(
				'post_id'          => 41,
				'expected_revision' => 101,
			)
		);
		$gate->record_tool_response(
			'sd-ai-agent/update-blocks',
			array(
				'success'     => true,
				'post_id'     => 41,
				'revision_id' => 102,
				'affected'    => array(
					'post_id'   => 41,
					'post_type' => 'page',
					'status'    => 'publish',
					'url'       => self::PAGE_URL,
					'fields'    => array( 'post_content' ),
				),
			)
		);

		$inputs = $gate->get_expected_report_inputs();
		$this->assertTrue( $gate->is_required() );
		$this->assertSame( 41, $inputs['pages'][0]['post_id'] );
		$this->assertSame( 102, $inputs['pages'][0]['revision_id'] );
		$this->assertSame( array( 'post_content' ), $inputs['pages'][0]['fields'] );
		$this->assertSame( PageCompletionGate::INCREMENTAL_VIEWPORTS, $inputs['viewports'] );
	}

	/** Static-front-page selection changes the target URL and role. */
	public function test_front_page_selection_uses_real_homepage_url(): void {
		$gate = $this->gate( PageCompletionGate::PROFILE_SETUP );
		$this->record_page( $gate, 41, self::PAGE_URL, 101 );
		$gate->record_tool_call(
			'sd-ai-agent/update-option',
			array(
				'option_name'  => 'page_on_front',
				'option_value' => 41,
			)
		);
		$gate->record_tool_response( 'sd-ai-agent/update-option', array( 'success' => true ) );

		$page = $gate->get_expected_report_inputs()['pages'][0];
		$this->assertSame( rtrim( self::HOME_URL, '/' ), $page['url'] );
		$this->assertSame( 'homepage', $page['role'] );

		$inputs = $gate->get_expected_report_inputs();
		$gate->record_tool_call( PageCompletionGate::CLIENT_ABILITY, $inputs );
		$gate->record_tool_response( PageCompletionGate::CLIENT_ABILITY, $this->passing_report( $inputs ) );
		$this->assertFalse( $gate->has_current_passing_report() );
		$this->assertStringContainsString( 'screenshots are attached', $gate->get_repair_guidance() );

		$weak_review                              = $this->passing_visual_review( (string) $inputs['quality_token'] );
		$weak_review['scores']['imagery']          = 70;
		$weak_review['overall_score']               = 89;
		$gate->record_tool_call( 'sd-ai-agent/submit-page-visual-review', $weak_review );
		$gate->record_tool_response( 'sd-ai-agent/submit-page-visual-review', $weak_review );
		$this->assertFalse( $gate->has_current_passing_report() );

		$review = $this->passing_visual_review( (string) $inputs['quality_token'] );
		$gate->record_tool_call( 'sd-ai-agent/submit-page-visual-review', $review );
		$gate->record_tool_response( 'sd-ai-agent/submit-page-visual-review', $review );
		$this->assertTrue( $gate->has_current_passing_report() );
	}

	/** A selected pattern contributes a measurable hero contract. */
	public function test_selected_pattern_sets_hero_contract(): void {
		$gate = $this->gate( PageCompletionGate::PROFILE_SETUP );
		$gate->record_tool_call( 'sd-ai-agent/select-landing-page-pattern-family', array() );
		$gate->record_tool_response(
			'sd-ai-agent/select-landing-page-pattern-family',
			array(
				'selected_variant' => array(
					'hero_contract' => array(
						'strategy'                         => 'immersive-media',
						'media_role'                       => 'primary',
						'desktop_media_min_viewport_ratio' => 0.9,
						'desktop_min_height_vh'            => 60,
						'primary_cta_above_fold'           => true,
					),
				),
			)
		);
		$this->record_page( $gate, 41, self::PAGE_URL, 101 );

		$this->assertSame( 'immersive-media', $gate->get_status()['hero_contract']['strategy'] );
		$this->assertSame( 0.9, $gate->get_status()['hero_contract']['desktop_media_min_viewport_ratio'] );
	}

	/** Any later mutation invalidates a report by changing the quality token. */
	public function test_page_mutation_invalidates_passing_report_and_token(): void {
		$gate = $this->gate( PageCompletionGate::PROFILE_INCREMENTAL );
		$this->record_page( $gate, 41, self::PAGE_URL, 101 );
		$before = $gate->get_expected_report_inputs();
		$gate->record_tool_call( PageCompletionGate::CLIENT_ABILITY, $before );
		$gate->record_tool_response( PageCompletionGate::CLIENT_ABILITY, $this->passing_report( $before ) );
		$this->assertTrue( $gate->has_current_passing_report() );

		$this->record_page( $gate, 41, self::PAGE_URL, 102, 'sd-ai-agent/update-post' );
		$after = $gate->get_expected_report_inputs();

		$this->assertFalse( $gate->has_current_passing_report() );
		$this->assertNotSame( $before['quality_token'], $after['quality_token'] );
		$this->assertTrue( $gate->requires_repair() );
	}

	/** A stale report cannot satisfy a later page revision. */
	public function test_stale_quality_token_is_rejected(): void {
		$gate = $this->gate( PageCompletionGate::PROFILE_SETUP );
		$this->record_page( $gate, 41, self::PAGE_URL, 101 );
		$stale = $gate->get_expected_report_inputs();
		$this->record_page( $gate, 41, self::PAGE_URL, 102, 'sd-ai-agent/update-post' );

		$gate->record_tool_call( PageCompletionGate::CLIENT_ABILITY, $stale );
		$gate->record_tool_response( PageCompletionGate::CLIENT_ABILITY, $this->passing_report( $stale ) );

		$this->assertFalse( $gate->has_current_passing_report() );
		$this->assertStringContainsString( 'stale', strtolower( $gate->get_terminal_notice() ) );
	}

	/** Missing browser support discloses incomplete QA without a futile repair loop. */
	public function test_unavailable_client_blocks_completion_without_looping(): void {
		$gate = new PageCompletionGate(
			PageCompletionGate::PROFILE_SETUP,
			array(),
			static fn(): string => self::HOME_URL
		);
		$this->record_page( $gate, 41, self::PAGE_URL, 101 );

		$this->assertTrue( $gate->is_required() );
		$this->assertFalse( $gate->requires_repair() );
		$this->assertStringContainsString( 'does not provide', $gate->get_repair_guidance() );
		$this->assertStringContainsString( 'cannot call', $gate->get_terminal_notice() );
	}

	/** Drafts do not start a published-page quality lifecycle. */
	public function test_draft_page_does_not_require_frontend_report(): void {
		$gate = $this->gate( PageCompletionGate::PROFILE_SETUP );
		$gate->record_tool_call( 'sd-ai-agent/create-post', array( 'post_type' => 'page', 'status' => 'draft' ) );
		$gate->record_tool_response(
			'sd-ai-agent/create-post',
			array(
				'post_id'     => 41,
				'post_type'   => 'page',
				'status'      => 'draft',
				'permalink'   => self::PAGE_URL,
				'revision_id' => 101,
			)
		);

		$this->assertFalse( $gate->is_required() );
	}

	private function gate( string $profile ): PageCompletionGate {
		return new PageCompletionGate(
			$profile,
			array( PageCompletionGate::CLIENT_ABILITY ),
			static fn(): string => self::HOME_URL
		);
	}

	private function record_page( PageCompletionGate $gate, int $post_id, string $url, int $revision_id, string $ability = 'sd-ai-agent/create-post' ): void {
		$gate->record_tool_call(
			$ability,
			array(
				'post_id'   => $post_id,
				'post_type' => 'page',
				'status'    => 'publish',
				'content'   => '<!-- wp:paragraph --><p>Page</p><!-- /wp:paragraph -->',
			)
		);
		$gate->record_tool_response(
			$ability,
			array(
				'post_id'     => $post_id,
				'post_type'   => 'page',
				'status'      => 'publish',
				'permalink'   => $url,
				'revision_id' => $revision_id,
				'affected'    => array(
					'kind'      => 'post',
					'post_id'   => $post_id,
					'post_type' => 'page',
					'url'       => $url,
					'fields'    => array( 'post_content' ),
				),
			)
		);
	}

	/** Return a passing screenshot-based visual critique. */
	private function passing_visual_review( string $quality_token ): array {
		return array(
			'quality_token'     => $quality_token,
			'passed'            => true,
			'overall_score'     => 90,
			'scores'            => array(
				'hierarchy'           => 90,
				'composition'         => 90,
				'spacing'             => 90,
				'typography'          => 90,
				'imagery'             => 90,
				'coherence'           => 90,
				'content_credibility' => 90,
			),
			'blocking_findings' => array(),
			'summary'           => 'The current mobile and desktop screenshots have a clear hierarchy and coherent first impression.',
		);
	}

	/** @param array<string,mixed> $inputs */
	private function passing_report( array $inputs ): array {
		$reports     = array();
		$screenshots = array();
		foreach ( $inputs['pages'] as $page ) {
			foreach ( $inputs['viewports'] as $viewport ) {
				$reports[] = array(
					'post_id'      => $page['post_id'],
					'revision_id'  => $page['revision_id'],
					'requested_url' => $page['url'],
					'final_url'    => $page['url'],
					'role'         => $page['role'],
					'viewport'     => $viewport,
					'success'      => true,
					'violations'   => array(),
				);
				if ( 'homepage' === $page['role'] && in_array( $viewport['label'], array( 'mobile', 'desktop' ), true ) ) {
					$screenshots[] = array(
						'post_id'           => $page['post_id'],
						'viewport'          => $viewport,
						'success'           => true,
						'attached_to_model' => true,
					);
				}
			}
		}

		return array(
			'success'       => true,
			'complete'      => true,
			'passed'        => true,
			'profile'       => $inputs['profile'],
			'quality_token' => $inputs['quality_token'],
			'reports'       => $reports,
			'violations'    => array(),
			'warnings'      => array(),
			'screenshots'   => $screenshots,
		);
	}
}
