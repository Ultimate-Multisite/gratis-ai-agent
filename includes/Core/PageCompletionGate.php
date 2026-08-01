<?php

declare(strict_types=1);
/**
 * Stateful rendered-page quality gate for agent-created and agent-edited pages.
 *
 * Unlike GeneratedThemeCompletionGate, this lifecycle is tied to published
 * page revisions rather than generated theme files. It applies to the Setup
 * Assistant's first-build flow and to focused page edits made by the General
 * agent. Every accepted browser report is bound to a mutation token so a
 * later content, media, template, navigation, logo, or style change makes the
 * report stale immediately.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

/**
 * Tracks rendered-page quality evidence for one agent run.
 */
final class PageCompletionGate {

	public const CLIENT_ABILITY = 'sd-ai-agent-js/validate-page-quality';

	public const PROFILE_OFF = 'off';

	public const PROFILE_SETUP = 'setup';

	public const PROFILE_INCREMENTAL = 'incremental';

	/**
	 * Full first-impression viewport matrix used by the Setup Assistant.
	 *
	 * @var list<array{label:string,width:int,height:int}>
	 */
	public const SETUP_VIEWPORTS = array(
		array(
			'label'  => 'mobile',
			'width'  => 375,
			'height' => 812,
		),
		array(
			'label'  => 'tablet',
			'width'  => 768,
			'height' => 1024,
		),
		array(
			'label'  => 'desktop',
			'width'  => 1280,
			'height' => 800,
		),
	);

	/**
	 * Focused matrix for small General-agent page edits.
	 *
	 * @var list<array{label:string,width:int,height:int}>
	 */
	public const INCREMENTAL_VIEWPORTS = array(
		array(
			'label'  => 'mobile',
			'width'  => 375,
			'height' => 812,
		),
		array(
			'label'  => 'desktop',
			'width'  => 1280,
			'height' => 800,
		),
	);

	/** @var callable|null */
	private $home_url_resolver;

	/** @var array<string,list<array<string,mixed>>> */
	private array $pending_calls = array();

	/**
	 * Current published page targets keyed by post ID.
	 *
	 * @var array<int,array{post_id:int,revision_id:int,url:string,fields:list<string>}>
	 */
	private array $targets = array();

	/** @var array<string,mixed> */
	private array $hero_contract = array(
		'strategy'                         => 'balanced',
		'media_role'                       => 'supporting',
		'desktop_media_min_viewport_ratio' => 0.0,
		'desktop_min_height_vh'            => 0,
		'primary_cta_above_fold'           => false,
	);

	/** @var array<string,mixed> */
	private array $last_report = array();

	private bool $client_validator_available;

	private bool $passed = false;

	private bool $deterministic_report_passed = false;

	private bool $visual_review_passed = false;

	private int $front_page_id = 0;

	private int $mutation_version = 0;

	private string $last_failure = '';

	/**
	 * @param string        $profile              Quality profile for this agent.
	 * @param array<string> $client_ability_names Client ability names available in this run.
	 * @param callable|null $home_url_resolver    Optional testable homepage URL resolver.
	 */
	public function __construct( string $profile, array $client_ability_names = array(), ?callable $home_url_resolver = null ) {
		$this->profile                    = self::normalize_profile( $profile );
		$this->client_validator_available = in_array( self::CLIENT_ABILITY, $client_ability_names, true );
		$this->home_url_resolver          = $home_url_resolver;
	}

	private string $profile;

	/**
	 * Rebuild state from persisted tool activity after a paused client-tool run.
	 *
	 * @param list<array<string,mixed>> $tool_call_log Ordered activity log.
	 */
	public function replay_tool_call_log( array $tool_call_log ): void {
		foreach ( $tool_call_log as $entry ) {
			if ( 'call' === ( $entry['type'] ?? '' ) ) {
				$args = $entry['args'] ?? array();
				$this->record_tool_call(
					(string) ( $entry['name'] ?? '' ),
					is_array( $args ) ? $args : array()
				);
				continue;
			}

			if ( 'response' === ( $entry['type'] ?? '' ) ) {
				$this->record_tool_response(
					(string) ( $entry['name'] ?? '' ),
					$entry['response'] ?? array()
				);
			}
		}
	}

	/**
	 * Record a dispatched tool call before its response is available.
	 *
	 * @param string              $tool_name Ability name as sent to the provider.
	 * @param array<string,mixed> $args      Normalized tool arguments.
	 */
	public function record_tool_call( string $tool_name, array $args ): void {
		$name = self::normalize_tool_name( $tool_name );
		if ( '' === $name || self::PROFILE_OFF === $this->profile ) {
			return;
		}

		$this->pending_calls[ $name ][] = $args;
	}

	/**
	 * Record a tool response and transition page-quality evidence.
	 *
	 * @param string $tool_name Ability name as returned by the provider/client.
	 * @param mixed  $response  Raw response payload.
	 */
	public function record_tool_response( string $tool_name, $response ): void {
		$name       = self::normalize_tool_name( $tool_name );
		$call_args  = $this->consume_pending_call( $name );
		$normalized = self::normalize_response( $response );

		if ( '' === $name || self::PROFILE_OFF === $this->profile ) {
			return;
		}

		// Meta-tool responses retain the real target ability and result. Replay
		// that nested pair so Tier-2 page/option/template operations participate
		// in the same completion lifecycle as direct Tier-1 calls.
		if ( 'sd-ai-agent/ability-call' === $name && true === ( $normalized['success'] ?? false ) ) {
			$target = self::normalize_tool_name( (string) ( $normalized['ability'] ?? $call_args['ability'] ?? '' ) );
			$args   = $call_args['arguments'] ?? array();
			$result = $normalized['result'] ?? array();
			if ( '' !== $target && 'sd-ai-agent/ability-call' !== $target ) {
				$this->record_tool_call( $target, is_array( $args ) ? $args : array() );
				$this->record_tool_response( $target, $result );
			}
			return;
		}

		if ( self::CLIENT_ABILITY === $name ) {
			$this->record_quality_report( $call_args, $normalized );
			return;
		}

		if ( 'sd-ai-agent/submit-page-visual-review' === $name ) {
			$this->record_visual_review( $call_args, $normalized );
			return;
		}

		if ( ! self::is_successful_response( $normalized ) ) {
			return;
		}

		if ( 'sd-ai-agent/select-landing-page-pattern-family' === $name ) {
			$this->record_hero_contract( $normalized );
			return;
		}

		if ( 'sd-ai-agent/update-option' === $name ) {
			$this->record_front_page_selection( $call_args );
			if ( $this->is_required() ) {
				$this->invalidate( 'The front-page configuration changed after page-quality evidence was collected.' );
			}
			return;
		}

		if ( in_array( $name, array( 'sd-ai-agent/create-post', 'sd-ai-agent/update-post', 'sd-ai-agent/append-post-content' ), true ) ) {
			$this->record_page_target( $call_args, $normalized );
			return;
		}

		if ( $this->is_required() && in_array( $name, self::related_visual_mutations(), true ) ) {
			$this->invalidate( 'A page-adjacent visual surface changed after page-quality evidence was collected.' );
		}
	}

	/** Whether this run currently requires rendered-page evidence. */
	public function is_required(): bool {
		return self::PROFILE_OFF !== $this->profile && ! empty( $this->targets );
	}

	/** Whether a current complete browser report has passed. */
	public function has_current_passing_report(): bool {
		return $this->is_required() && $this->passed;
	}

	/** Whether the loop should spend another repair/validation turn. */
	public function requires_repair(): bool {
		return $this->is_required() && ! $this->passed && $this->client_validator_available;
	}

	/**
	 * Return the exact current report inputs.
	 *
	 * @return array<string,mixed>
	 */
	public function get_expected_report_inputs(): array {
		$pages = $this->get_required_pages();

		return array(
			'profile'       => $this->profile,
			'quality_token' => $this->quality_token( $pages ),
			'pages'         => $pages,
			'hero_contract' => $this->hero_contract,
			'viewports'     => $this->required_viewports(),
		);
	}

	/** Return an actionable next-step prompt for the agent. */
	public function get_repair_guidance(): string {
		if ( ! $this->is_required() ) {
			return '';
		}

		if ( ! $this->client_validator_available ) {
			return 'Rendered page quality remains unverified because this client does not provide sd-ai-agent-js/validate-page-quality. Do not claim the page is visually complete. Preserve the successful content change, disclose that browser QA could not run, and ask the user to review the live URL in a browser-capable client.';
		}

		$inputs = $this->get_expected_report_inputs();
		if ( $this->requires_visual_review() && $this->deterministic_report_passed && ! $this->visual_review_passed ) {
			return sprintf(
				'The deterministic page report passed and its desktop/mobile homepage screenshots are attached as image inputs. Review them as a critical designer who did not build the page. Score hierarchy, composition, spacing, typography, imagery, coherence, and content credibility independently. If any first-impression defect remains, repair it and rerun sd-ai-agent-js/validate-page-quality. Otherwise call sd-ai-agent/submit-page-visual-review with quality_token "%s", every rubric score, overall_score at least 85, passed=true, no blocking_findings, and a concise evidence-based summary. Do not rubber-stamp your own work.',
				(string) $inputs['quality_token']
			);
		}

		$urls = array();
		if ( is_array( $inputs['pages'] ?? null ) ) {
			foreach ( $inputs['pages'] as $page ) {
				if ( is_array( $page ) ) {
					$urls[] = (string) ( $page['url'] ?? '' );
				}
			}
		}

		return sprintf(
			'Published page quality is a hard completion gate. Call %1$s with profile "%2$s", quality_token "%3$s", the exact pages and hero_contract from page_quality_completion status, and the required viewport matrix. Inspect every violation, repair the smallest relevant page/template/media setting, and rerun the validator after each mutation. Do not substitute create-post success, imported-media success, refresh, or prose review for a passing current report. Required URLs: %4$s.',
			self::CLIENT_ABILITY,
			$this->profile,
			(string) $inputs['quality_token'],
			implode( ', ', $urls )
		);
	}

	/** Return the terminal disclosure when quality evidence is incomplete. */
	public function get_terminal_notice(): string {
		if ( ! $this->is_required() || $this->passed ) {
			return '';
		}

		$reason = '' !== $this->last_failure ? ' ' . $this->last_failure : '';
		if ( $this->deterministic_report_passed && ! $this->visual_review_passed ) {
			return 'The page changes were saved and deterministic browser checks passed, but the required screenshot-based visual critique did not pass, so I cannot call the first impression finished.' . $reason;
		}
		return 'The page changes were saved, but rendered page quality is not complete. A current browser report for every affected published page and required viewport did not pass, so I cannot call the visual work finished.' . $reason;
	}

	/**
	 * Return serializable state for terminal and paused loop payloads.
	 *
	 * @return array<string,mixed>
	 */
	public function get_status(): array {
		$inputs = $this->get_expected_report_inputs();

		return array(
			'required'                    => $this->is_required(),
			'passed'                      => $this->passed,
			'profile'                     => $this->profile,
			'quality_token'               => $inputs['quality_token'],
			'pages'                       => $inputs['pages'],
			'hero_contract'               => $this->hero_contract,
			'viewports'                   => $inputs['viewports'],
			'client_validator_present'    => $this->client_validator_available,
			'deterministic_report_passed' => $this->deterministic_report_passed,
			'visual_review_required'      => $this->requires_visual_review(),
			'visual_review_passed'        => $this->visual_review_passed,
			'last_failure'                => $this->last_failure,
			'report'                      => $this->last_report,
		);
	}

	/**
	 * Record the governed hero contract selected for this page build.
	 *
	 * @param array<string,mixed> $response Selection response.
	 */
	private function record_hero_contract( array $response ): void {
		$selected = $response['selected_variant'] ?? $response['selected_family'] ?? array();
		if ( ! is_array( $selected ) || ! is_array( $selected['hero_contract'] ?? null ) ) {
			return;
		}

		$contract = self::normalize_hero_contract( $selected['hero_contract'] );
		if ( empty( $contract ) ) {
			return;
		}

		$this->hero_contract = $contract;
		if ( $this->is_required() ) {
			$this->invalidate( 'The selected landing-page composition contract changed.' );
		}
	}

	/**
	 * Track the page selected as the static front page.
	 *
	 * @param array<string,mixed> $call_args Update-option arguments.
	 */
	private function record_front_page_selection( array $call_args ): void {
		$option = (string) ( $call_args['option'] ?? $call_args['name'] ?? $call_args['option_name'] ?? '' );
		if ( 'page_on_front' !== $option ) {
			return;
		}

		$this->front_page_id = max( 0, (int) ( $call_args['value'] ?? $call_args['option_value'] ?? 0 ) );
	}

	/**
	 * Register one successful published page mutation as a QA target.
	 *
	 * @param array<string,mixed> $call_args Original call arguments.
	 * @param array<string,mixed> $response  Normalized response.
	 */
	private function record_page_target( array $call_args, array $response ): void {
		$post_type = (string) ( $response['post_type'] ?? $response['affected']['post_type'] ?? $call_args['post_type'] ?? '' );
		$status    = (string) ( $response['status'] ?? $call_args['status'] ?? '' );
		if ( 'page' !== $post_type || 'publish' !== $status ) {
			return;
		}

		$post_id = (int) ( $response['post_id'] ?? $response['affected']['post_id'] ?? $call_args['post_id'] ?? 0 );
		$url     = self::normalize_url( (string) ( $response['permalink'] ?? $response['affected']['url'] ?? '' ) );
		if ( $post_id <= 0 || '' === $url || self::is_upload_preview_url( $url ) ) {
			return;
		}

		$revision_id       = max( 0, (int) ( $response['revision_id'] ?? 0 ) );
		$fields            = $response['affected']['fields'] ?? array_keys( $call_args );
		$normalized_fields = array();
		if ( is_array( $fields ) ) {
			foreach ( $fields as $field ) {
				if ( is_scalar( $field ) || null === $field ) {
					$normalized_fields[] = (string) $field;
				}
			}
		}
		$fields = array_values( array_unique( $normalized_fields ) );

		$target = array(
			'post_id'     => $post_id,
			'revision_id' => $revision_id,
			'url'         => $url,
			'fields'      => $fields,
		);

		if ( self::PROFILE_INCREMENTAL === $this->profile ) {
			$this->targets = array( $post_id => $target );
		} else {
			$this->targets[ $post_id ] = $target;
		}

		$this->invalidate( 'The affected published page has not passed rendered quality validation for its current mutation.' );
	}

	/**
	 * Record and verify one client-side quality report.
	 *
	 * @param array<string,mixed> $call_args Report call arguments.
	 * @param array<string,mixed> $response  Browser report.
	 */
	private function record_quality_report( array $call_args, array $response ): void {
		$this->last_report                 = $response;
		$this->passed                      = false;
		$this->deterministic_report_passed = false;
		$this->visual_review_passed        = false;

		if ( ! $this->is_required() ) {
			$this->last_failure = 'A page-quality report arrived without a current published page target.';
			return;
		}

		$expected = $this->get_expected_report_inputs();
		if (
			(string) ( $call_args['profile'] ?? '' ) !== $this->profile
			|| (string) ( $call_args['quality_token'] ?? '' ) !== (string) $expected['quality_token']
			|| (string) ( $response['profile'] ?? '' ) !== $this->profile
			|| (string) ( $response['quality_token'] ?? '' ) !== (string) $expected['quality_token']
		) {
			$this->last_failure = 'The page-quality report was stale or used a different quality profile.';
			return;
		}

		if ( ! $this->report_covers_required_surface( $response, $expected ) ) {
			$this->last_failure = 'The page-quality report was partial, stale, unrenderable, or contained blocking quality violations.';
			return;
		}

		$this->deterministic_report_passed = true;
		$this->passed                      = ! $this->requires_visual_review();
		$this->last_failure                = $this->passed
			? ''
			: 'Deterministic browser checks passed, but the Setup Assistant has not submitted a passing screenshot-based visual critique.';
	}

	/**
	 * Record the Setup Assistant's screenshot-based visual critique.
	 *
	 * @param array<string,mixed> $call_args Visual-review call arguments.
	 * @param array<string,mixed> $response  Normalized visual-review response.
	 */
	private function record_visual_review( array $call_args, array $response ): void {
		if ( ! $this->requires_visual_review() || ! $this->deterministic_report_passed ) {
			$this->last_failure = 'A visual review arrived before the current deterministic page report passed.';
			return;
		}

		$expected_token = (string) $this->get_expected_report_inputs()['quality_token'];
		$scores         = is_array( $response['scores'] ?? null ) ? $response['scores'] : array();
		$required       = array( 'hierarchy', 'composition', 'spacing', 'typography', 'imagery', 'coherence', 'content_credibility' );
		$score_floor_ok = true;
		$score_total    = 0;
		foreach ( $required as $key ) {
			$score        = (int) ( $scores[ $key ] ?? 0 );
			$score_total += $score;
			if ( $score < 80 ) {
				$score_floor_ok = false;
			}
		}
		$average_score = $score_total / count( $required );

		if (
			(string) ( $call_args['quality_token'] ?? '' ) !== $expected_token
			|| (string) ( $response['quality_token'] ?? '' ) !== $expected_token
			|| true !== ( $response['passed'] ?? false )
			|| (int) ( $response['overall_score'] ?? 0 ) < 85
			|| $average_score < 85
			|| ! $score_floor_ok
			|| ! empty( $response['blocking_findings'] ?? array() )
			|| '' === trim( (string) ( $response['summary'] ?? '' ) )
		) {
			$this->visual_review_passed = false;
			$this->passed               = false;
			$this->last_failure         = 'The screenshot-based visual critique was stale, incomplete, below the score floor, or retained blocking findings.';
			return;
		}

		$this->visual_review_passed = true;
		$this->passed               = true;
		$this->last_failure         = '';
	}

	/**
	 * Verify complete page and viewport coverage.
	 *
	 * @param array<string,mixed> $response Browser report.
	 * @param array<string,mixed> $expected Expected report inputs.
	 */
	private function report_covers_required_surface( array $response, array $expected ): bool {
		if (
			true !== ( $response['success'] ?? false )
			|| true !== ( $response['complete'] ?? false )
			|| true !== ( $response['passed'] ?? false )
			|| ! empty( $response['violations'] ?? array() )
		) {
			return false;
		}

		$reports = $response['reports'] ?? array();
		if ( ! is_array( $reports ) ) {
			return false;
		}
		$reports   = array_values( $reports );
		$pages     = is_array( $expected['pages'] ?? null ) ? $expected['pages'] : array();
		$viewports = is_array( $expected['viewports'] ?? null ) ? $expected['viewports'] : array();
		if ( count( $reports ) !== count( $pages ) * count( $viewports ) ) {
			return false;
		}
		if ( $this->requires_visual_review() && ! $this->has_visual_screenshot_coverage( $response, $pages ) ) {
			return false;
		}

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				return false;
			}
			foreach ( $viewports as $viewport ) {
				if ( ! is_array( $viewport ) || ! $this->has_passing_report( $reports, $page, $viewport ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Require attached mobile and desktop homepage screenshots for visual review.
	 *
	 * @param array<string,mixed> $response Browser report.
	 * @param array<mixed>        $pages    Required pages.
	 */
	private function has_visual_screenshot_coverage( array $response, array $pages ): bool {
		$homepage_id = 0;
		foreach ( $pages as $page ) {
			if ( is_array( $page ) && 'homepage' === ( $page['role'] ?? '' ) ) {
				$homepage_id = (int) ( $page['post_id'] ?? 0 );
				break;
			}
		}
		$screenshots = $response['screenshots'] ?? array();
		if ( $homepage_id <= 0 || ! is_array( $screenshots ) ) {
			return false;
		}

		$covered = array();
		foreach ( $screenshots as $screenshot ) {
			if ( ! is_array( $screenshot ) || true !== ( $screenshot['success'] ?? false ) || (int) ( $screenshot['post_id'] ?? 0 ) !== $homepage_id ) {
				continue;
			}
			$viewport = $screenshot['viewport'] ?? array();
			$label    = is_array( $viewport ) ? (string) ( $viewport['label'] ?? '' ) : '';
			$attached = true === ( $screenshot['attached_to_model'] ?? false ) || ( is_string( $screenshot['image'] ?? null ) && str_starts_with( $screenshot['image'], 'data:image/' ) );
			if ( $attached && in_array( $label, array( 'mobile', 'desktop' ), true ) ) {
				$covered[ $label ] = true;
			}
		}

		return isset( $covered['mobile'], $covered['desktop'] );
	}

	/**
	 * Return whether exactly one passing report matches a page and viewport.
	 *
	 * @param array<int,mixed>    $reports  Report rows.
	 * @param array<string,mixed> $page     Required page.
	 * @param array<string,mixed> $viewport Required viewport.
	 */
	private function has_passing_report( array $reports, array $page, array $viewport ): bool {
		$matches = 0;
		foreach ( $reports as $report ) {
			if ( ! is_array( $report ) ) {
				continue;
			}

			$reported_viewport = $report['viewport'] ?? array();
			if (
				(int) ( $report['post_id'] ?? 0 ) !== (int) ( $page['post_id'] ?? 0 )
				|| (int) ( $report['revision_id'] ?? 0 ) !== (int) ( $page['revision_id'] ?? 0 )
				|| (string) ( $report['role'] ?? '' ) !== (string) ( $page['role'] ?? '' )
				|| self::normalize_url( (string) ( $report['requested_url'] ?? '' ) ) !== self::normalize_url( (string) ( $page['url'] ?? '' ) )
				|| ! is_array( $reported_viewport )
				|| (int) ( $reported_viewport['width'] ?? 0 ) !== (int) ( $viewport['width'] ?? 0 )
				|| (int) ( $reported_viewport['height'] ?? 0 ) !== (int) ( $viewport['height'] ?? 0 )
				|| ! self::same_origin_url( (string) ( $page['url'] ?? '' ), (string) ( $report['final_url'] ?? '' ) )
			) {
				continue;
			}

			++$matches;
			if ( true !== ( $report['success'] ?? false ) || ! empty( $report['violations'] ?? array() ) ) {
				return false;
			}
		}

		return 1 === $matches;
	}

	/**
	 * Return normalized page targets with current semantic roles.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function get_required_pages(): array {
		$pages = array();
		$home  = self::normalize_url( $this->resolve_home_url() );

		foreach ( $this->targets as $post_id => $target ) {
			$is_home = $post_id === $this->front_page_id || self::normalize_url( $target['url'] ) === $home;
			$pages[] = array_merge(
				$target,
				array(
					'url'  => $is_home && '' !== $home ? $home : $target['url'],
					'role' => $is_home ? 'homepage' : 'page',
				)
			);
		}

		return $pages;
	}

	/** Whether the current Setup run includes a homepage first impression. */
	private function requires_visual_review(): bool {
		if ( self::PROFILE_SETUP !== $this->profile ) {
			return false;
		}
		foreach ( $this->get_required_pages() as $page ) {
			if ( 'homepage' === ( $page['role'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Invalidate any prior report and advance the mutation token. */
	private function invalidate( string $reason ): void {
		++$this->mutation_version;
		$this->passed                      = false;
		$this->deterministic_report_passed = false;
		$this->visual_review_passed        = false;
		$this->last_report                 = array();
		$this->last_failure                = $reason;
	}

	/** @return list<array{label:string,width:int,height:int}> */
	private function required_viewports(): array {
		return self::PROFILE_SETUP === $this->profile ? self::SETUP_VIEWPORTS : self::INCREMENTAL_VIEWPORTS;
	}

	/**
	 * Build a deterministic stale-report token.
	 *
	 * @param list<array<string,mixed>> $pages Current page targets.
	 */
	private function quality_token( array $pages ): string {
		$payload = wp_json_encode(
			array(
				'profile'          => $this->profile,
				'mutation_version' => $this->mutation_version,
				'pages'            => $pages,
				'hero_contract'    => $this->hero_contract,
			)
		);

		return hash( 'sha256', is_string( $payload ) ? $payload : '' );
	}

	/** Resolve the public homepage URL with a testable fallback. */
	private function resolve_home_url(): string {
		if ( is_callable( $this->home_url_resolver ) ) {
			$value = call_user_func( $this->home_url_resolver );
			return is_string( $value ) ? $value : '';
		}

		return function_exists( 'home_url' ) ? (string) home_url( '/' ) : '/';
	}

	/** @return list<string> */
	private static function related_visual_mutations(): array {
		return array(
			'sd-ai-agent/set-featured-image',
			'sd-ai-agent/update-global-styles',
			'sd-ai-agent/reset-global-styles',
			'sd-ai-agent/create-style-variation',
			'sd-ai-agent/update-style-variation',
			'sd-ai-agent/select-style-variation',
			'sd-ai-agent/reset-style-variation',
			'sd-ai-agent/create-menu',
			'sd-ai-agent/delete-menu',
			'sd-ai-agent/add-menu-item',
			'sd-ai-agent/remove-menu-item',
			'sd-ai-agent/assign-menu-location',
			'sd-ai-agent/set-site-logo',
			'sd-ai-agent/generate-logo-svg',
			'sd-ai-agent/generate-menu-page',
		);
	}

	/** Consume the oldest matching call arguments for a response. */
	private function consume_pending_call( string $name ): array {
		if ( '' === $name || empty( $this->pending_calls[ $name ] ) ) {
			return array();
		}

		$args = array_shift( $this->pending_calls[ $name ] );
		if ( empty( $this->pending_calls[ $name ] ) ) {
			unset( $this->pending_calls[ $name ] );
		}

		return is_array( $args ) ? $args : array();
	}

	/** Normalize a provider function name to its registered ability name. */
	private static function normalize_tool_name( string $tool_name ): string {
		if ( str_starts_with( $tool_name, 'wpab__sd-ai-agent__' ) ) {
			return 'sd-ai-agent/' . substr( $tool_name, strlen( 'wpab__sd-ai-agent__' ) );
		}
		if ( str_starts_with( $tool_name, 'wpab__sd-ai-agent-js__' ) ) {
			return 'sd-ai-agent-js/' . substr( $tool_name, strlen( 'wpab__sd-ai-agent-js__' ) );
		}
		return $tool_name;
	}

	/** @param mixed $response */
	private static function normalize_response( $response ): array {
		if ( is_string( $response ) && '' !== $response ) {
			$decoded  = json_decode( $response, true );
			$response = is_array( $decoded ) ? $decoded : array( 'error' => $response );
		}
		if ( ! is_array( $response ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $response as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}
		return $normalized;
	}

	/**
	 * Check whether a normalized ability response succeeded.
	 *
	 * @param array<string,mixed> $response Normalized ability response.
	 */
	private static function is_successful_response( array $response ): bool {
		return ! empty( $response )
			&& empty( $response['error'] ?? '' )
			&& false !== ( $response['success'] ?? true )
			&& 'proposal_pending' !== (string) ( $response['status'] ?? '' );
	}

	private static function normalize_profile( string $profile ): string {
		return in_array( $profile, array( self::PROFILE_SETUP, self::PROFILE_INCREMENTAL ), true )
			? $profile
			: self::PROFILE_OFF;
	}

	/**
	 * @param array<string,mixed> $contract Raw contract.
	 * @return array<string,mixed>
	 */
	private static function normalize_hero_contract( array $contract ): array {
		$strategy = (string) ( $contract['strategy'] ?? '' );
		if ( ! in_array( $strategy, array( 'balanced', 'immersive-media', 'split-media', 'editorial-feature', 'product-focus' ), true ) ) {
			return array();
		}

		return array(
			'strategy'                         => $strategy,
			'media_role'                       => sanitize_key( (string) ( $contract['media_role'] ?? 'supporting' ) ),
			'desktop_media_min_viewport_ratio' => max( 0.0, min( 1.0, (float) ( $contract['desktop_media_min_viewport_ratio'] ?? 0.0 ) ) ),
			'desktop_min_height_vh'            => max( 0, min( 100, (int) ( $contract['desktop_min_height_vh'] ?? 0 ) ) ),
			'primary_cta_above_fold'           => true === ( $contract['primary_cta_above_fold'] ?? false ),
		);
	}

	private static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$normalized = rtrim( $url, '/' );
		return '' === $normalized && '/' === $url ? '/' : $normalized;
	}

	private static function same_origin_url( string $requested_url, string $final_url ): bool {
		$requested = wp_parse_url( $requested_url );
		$final     = wp_parse_url( $final_url );
		if ( ! is_array( $requested ) || ! is_array( $final ) || '' === $final_url ) {
			return false;
		}
		return ( $requested['scheme'] ?? '' ) === ( $final['scheme'] ?? '' )
			&& ( $requested['host'] ?? '' ) === ( $final['host'] ?? '' )
			&& ( $requested['port'] ?? null ) === ( $final['port'] ?? null );
	}

	private static function is_upload_preview_url( string $url ): bool {
		return str_contains( strtolower( $url ), '/wp-content/uploads/' );
	}
}
