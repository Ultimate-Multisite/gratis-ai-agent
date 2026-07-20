<?php

declare(strict_types=1);
/**
 * Stateful completion gate for generated block-theme runs.
 *
 * The gate is deliberately driven by the tool-call log rather than transient
 * browser screenshots. A report only passes when it covers the activated
 * generated stylesheet, the fingerprint from the current project validation,
 * every required URL, and every required viewport.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

/**
 * Tracks generated-theme lifecycle evidence for one agent run.
 */
final class GeneratedThemeCompletionGate {

	public const CLIENT_ABILITY = 'sd-ai-agent-js/validate-theme-completion';

	/**
	 * Required frontend viewports. These values are duplicated in the browser
	 * validator so either side rejects an incomplete or substituted report.
	 *
	 * @var list<array{label:string,width:int,height:int}>
	 */
	public const REQUIRED_VIEWPORTS = array(
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

	/** @var callable|null */
	private $home_url_resolver;

	/** @var array<string,list<array<string,mixed>>> */
	private array $pending_calls = array();

	/** @var array<int,string> Published generated-run page URL by post ID. */
	private array $published_page_urls = array();

	/** @var int Front-page post ID selected during the generated run. */
	private int $front_page_id = 0;

	/** @var array<string,mixed> */
	private array $last_report = array();

	private bool $client_validator_available;

	private bool $generated_theme_started = false;

	private bool $validation_current = false;

	private bool $activation_current = false;

	private bool $passed = false;

	private bool $requires_restore = false;

	private bool $restored_after_fatal_failure = false;

	private string $expected_stylesheet = '';

	private string $validated_fingerprint = '';

	private string $previous_stylesheet = '';

	private string $last_failure = '';

	/**
	 * @param array<string> $client_ability_names Client ability names available in this run.
	 * @param callable|null $home_url_resolver    Optional testable homepage URL resolver.
	 */
	public function __construct( array $client_ability_names = array(), ?callable $home_url_resolver = null ) {
		$this->client_validator_available = in_array( self::CLIENT_ABILITY, $client_ability_names, true );
		$this->home_url_resolver          = $home_url_resolver;
	}

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
		if ( '' === $name ) {
			return;
		}

		if ( ! isset( $this->pending_calls[ $name ] ) ) {
			$this->pending_calls[ $name ] = array();
		}

		$this->pending_calls[ $name ][] = $args;
	}

	/**
	 * Record a tool response and transition lifecycle evidence when applicable.
	 *
	 * @param string $tool_name Ability name as returned by the provider/client.
	 * @param mixed  $response  Raw response payload.
	 */
	public function record_tool_response( string $tool_name, $response ): void {
		$name       = self::normalize_tool_name( $tool_name );
		$call_args  = $this->consume_pending_call( $name );
		$normalized = self::normalize_response( $response );

		if ( '' === $name ) {
			return;
		}

		// A failed browser report is evidence too: it must keep completion
		// incomplete and can require rollback after a fatal render failure.
		if ( self::CLIENT_ABILITY === $name ) {
			$this->record_completion_report( $call_args, $normalized );
			return;
		}

		if ( ! self::is_successful_response( $normalized ) ) {
			return;
		}

		switch ( $name ) {
			case 'sd-ai-agent/scaffold-block-theme':
				$this->record_scaffold( $call_args, $normalized );
				return;

			case 'sd-ai-agent/validate-block-theme-project':
				$this->record_project_validation( $call_args, $normalized );
				return;

			case 'sd-ai-agent/activate-theme':
				$this->record_activation( $call_args, $normalized );
				return;

		}

		if ( ! $this->generated_theme_started ) {
			return;
		}

		if ( 'sd-ai-agent/update-option' === $name ) {
			$this->record_front_page_selection( $call_args );
		}

		if ( in_array( $name, array( 'sd-ai-agent/create-post', 'sd-ai-agent/update-post' ), true ) ) {
			$this->record_published_page( $normalized );
			$this->invalidate( 'A page changed after generated-theme evidence was collected.' );
			return;
		}

		if (
			in_array( $name, array( 'sd-ai-agent/file-write', 'sd-ai-agent/file-edit', 'sd-ai-agent/file-delete' ), true )
			&& $this->is_generated_theme_file_mutation( $call_args, $normalized )
		) {
			$this->invalidate( 'A generated theme file changed after generated-theme evidence was collected.' );
			return;
		}

		if ( in_array( $name, self::get_related_mutation_abilities(), true ) ) {
			$this->invalidate( 'A generated-theme page, Global Styles, variation, or activation setting changed after generated-theme evidence was collected.' );
		}
	}

	/**
	 * Whether this run has started a generated-theme lifecycle.
	 */
	public function is_required(): bool {
		return $this->generated_theme_started;
	}

	/**
	 * Whether a current, full browser report has passed.
	 */
	public function has_current_passing_report(): bool {
		return $this->generated_theme_started && $this->passed;
	}

	/**
	 * Whether the loop must spend another repair/validation turn before finalizing.
	 */
	public function requires_repair(): bool {
		return $this->generated_theme_started
			&& ! $this->passed
			&& ! $this->restored_after_fatal_failure;
	}

	/**
	 * Whether all renders failed and the prior stylesheet must be restored.
	 */
	public function requires_restore(): bool {
		return $this->requires_restore && ! $this->restored_after_fatal_failure;
	}

	/**
	 * Return the expected current report inputs.
	 *
	 * @return array{stylesheet:string,fingerprint:string,homepage_url:string,interior_url:string,viewports:list<array{label:string,width:int,height:int}>}
	 */
	public function get_expected_report_inputs(): array {
		$urls = $this->get_required_urls();

		return array(
			'stylesheet'   => $this->expected_stylesheet,
			'fingerprint'  => $this->validated_fingerprint,
			'homepage_url' => $urls[0] ?? '',
			'interior_url' => $urls[1] ?? '',
			'viewports'    => self::REQUIRED_VIEWPORTS,
		);
	}

	/**
	 * Return an actionable next-step prompt for the agent.
	 */
	public function get_repair_guidance(): string {
		if ( ! $this->generated_theme_started ) {
			return '';
		}

		if ( ! $this->client_validator_available ) {
			return 'Generated-theme completion is incomplete because the browser completion validator is unavailable in this client. Do not report the generated theme as complete. Browser QA is required; request an upgraded browser client instead of substituting previews, screenshots, or a structural-only review.';
		}

		if ( ! $this->validation_current || '' === $this->validated_fingerprint ) {
			return sprintf(
				'Generated-theme completion is blocked. Call sd-ai-agent/validate-block-theme-project for stylesheet "%s", repair every error, and continue only when its marked and valid fields are true with a non-empty current fingerprint.',
				$this->expected_stylesheet
			);
		}

		if ( ! $this->activation_current ) {
			return sprintf(
				'Generated-theme completion is blocked. Activate the expected stylesheet "%s" after its current project validation. Do not use a preview, an uploads HTML file, or cached screenshot as activation evidence.',
				$this->expected_stylesheet
			);
		}

		if ( $this->requires_restore() ) {
			return sprintf(
				'The activated generated theme was unrenderable at every required frontend viewport. Restore previous_stylesheet "%s" with sd-ai-agent/activate-theme before ending this run. Do not call this a completed theme; explain the rollback and the render evidence instead.',
				$this->previous_stylesheet
			);
		}

		$urls = $this->get_required_urls();
		if ( count( $urls ) < 2 ) {
			return 'Generated-theme completion is blocked because no representative published interior page is available. Create and publish a real interior page, then rerun project validation and the frontend completion validator.';
		}

		return sprintf(
			'Generated-theme completion is blocked. Call %1$s with stylesheet "%2$s", fingerprint "%3$s", and exactly these frontend URLs: %4$s. It must validate each URL at 375x812, 768x1024, and 1280x800. Repair every reported violation, rerun project validation after mutations, reactivate if needed, and rerun the browser validator. Standalone previews, uploaded HTML, cached images, and subjective approval do not satisfy this gate.',
			self::CLIENT_ABILITY,
			$this->expected_stylesheet,
			$this->validated_fingerprint,
			implode( ', ', $urls )
		);
	}

	/**
	 * Return the terminal disclosure when the loop has no repair turn left.
	 */
	public function get_terminal_notice(): string {
		if ( $this->restored_after_fatal_failure ) {
			return 'Generated-theme completion failed: the activated theme was unrenderable at the required frontend viewports, so the previous stylesheet was restored. The generated theme remains incomplete and requires repair plus a new full browser report.';
		}

		if ( ! $this->generated_theme_started || $this->passed ) {
			return '';
		}

		$reason = '' !== $this->last_failure ? ' ' . $this->last_failure : '';
		return 'Generated-theme completion remains incomplete. A current activated-site browser report for the expected stylesheet, project fingerprint, homepage, interior page, and all required viewports was not passed.' . $reason;
	}

	/**
	 * Return serializable state for terminal and paused loop payloads.
	 *
	 * @return array<string,mixed>
	 */
	public function get_status(): array {
		return array(
			'required'                 => $this->generated_theme_started,
			'passed'                   => $this->passed,
			'stylesheet'               => $this->expected_stylesheet,
			'fingerprint'              => $this->validated_fingerprint,
			'required_urls'            => $this->get_required_urls(),
			'viewports'                => self::REQUIRED_VIEWPORTS,
			'client_validator_present' => $this->client_validator_available,
			'requires_restore'         => $this->requires_restore(),
			'previous_stylesheet'      => $this->previous_stylesheet,
			'last_failure'             => $this->last_failure,
			'report'                   => $this->last_report,
		);
	}

	/**
	 * Transition after a successful scaffold response.
	 *
	 * @param array<string,mixed> $call_args Response call arguments.
	 * @param array<string,mixed> $response  Normalized response.
	 */
	private function record_scaffold( array $call_args, array $response ): void {
		$stylesheet = self::sanitize_stylesheet(
			(string) ( $response['stylesheet'] ?? $response['slug'] ?? $call_args['slug'] ?? '' )
		);
		if ( '' === $stylesheet ) {
			return;
		}

		$this->generated_theme_started      = true;
		$this->expected_stylesheet          = $stylesheet;
		$this->validation_current           = false;
		$this->activation_current           = false;
		$this->passed                       = false;
		$this->requires_restore             = false;
		$this->restored_after_fatal_failure = false;
		$this->validated_fingerprint        = '';
		$this->previous_stylesheet          = '';
		$this->published_page_urls          = array();
		$this->front_page_id                = 0;
		$this->last_report                  = array();
		$this->last_failure                 = 'The generated project has not yet passed project validation and activated-site browser QA.';
	}

	/**
	 * Transition after a project-validation response.
	 *
	 * @param array<string,mixed> $call_args Validation call arguments.
	 * @param array<string,mixed> $response  Normalized response.
	 */
	private function record_project_validation( array $call_args, array $response ): void {
		if ( ! $this->generated_theme_started ) {
			return;
		}

		$stylesheet = self::sanitize_stylesheet( (string) ( $call_args['stylesheet'] ?? '' ) );
		if ( $stylesheet !== $this->expected_stylesheet ) {
			return;
		}

		$fingerprint = (string) ( $response['fingerprint'] ?? '' );
		if (
			true !== ( $response['valid'] ?? false )
			|| true !== ( $response['marked'] ?? false )
			|| '' === $fingerprint
		) {
			$this->validation_current = false;
			$this->passed             = false;
			$this->last_failure       = 'The generated project validation did not return marked: true, valid: true, and a current fingerprint.';
			return;
		}

		$this->validation_current    = true;
		$this->activation_current    = false;
		$this->passed                = false;
		$this->validated_fingerprint = $fingerprint;
		$this->last_report           = array();
		$this->last_failure          = 'The project validation passed, but the expected stylesheet still needs activated-site browser QA.';
	}

	/**
	 * Transition after a theme activation response.
	 *
	 * @param array<string,mixed> $call_args Activation call arguments.
	 * @param array<string,mixed> $response  Normalized response.
	 */
	private function record_activation( array $call_args, array $response ): void {
		if ( ! $this->generated_theme_started ) {
			return;
		}

		$requested = self::sanitize_stylesheet( (string) ( $call_args['stylesheet'] ?? '' ) );
		$observed  = self::sanitize_stylesheet( (string) ( $response['stylesheet'] ?? '' ) );

		if (
			$this->requires_restore
			&& '' !== $this->previous_stylesheet
			&& $observed === $this->previous_stylesheet
		) {
			$this->activation_current           = false;
			$this->passed                       = false;
			$this->requires_restore             = false;
			$this->restored_after_fatal_failure = true;
			$this->last_failure                 = 'The prior stylesheet was restored after the generated theme could not render.';
			return;
		}

		if ( $requested !== $this->expected_stylesheet || $observed !== $this->expected_stylesheet ) {
			$this->activation_current = false;
			$this->passed             = false;
			$this->last_failure       = 'The active stylesheet did not match the generated stylesheet that was validated.';
			return;
		}

		if ( ! $this->validation_current || true !== ( $response['is_block_theme'] ?? false ) ) {
			$this->activation_current = false;
			$this->passed             = false;
			$this->last_failure       = 'Activation was not backed by a current valid generated block-theme project.';
			return;
		}

		$this->activation_current  = true;
		$this->passed              = false;
		$this->previous_stylesheet = self::sanitize_stylesheet( (string) ( $response['previous_stylesheet'] ?? '' ) );
		$this->last_report         = array();
		$this->last_failure        = 'The expected stylesheet is active, but its frontend completion report has not passed.';
	}

	/**
	 * Record one client-side activated-site report.
	 *
	 * @param array<string,mixed> $call_args Report call arguments.
	 * @param array<string,mixed> $response  Normalized browser report.
	 */
	private function record_completion_report( array $call_args, array $response ): void {
		$this->last_report = $response;
		$this->passed      = false;

		if ( ! $this->generated_theme_started || ! $this->activation_current ) {
			$this->last_failure = 'A browser report arrived before the current generated stylesheet was activated.';
			return;
		}

		if ( ! $this->client_validator_available ) {
			$this->last_failure = 'The browser report was not accepted because this client did not advertise the completion validator.';
			return;
		}

		if (
			(string) ( $call_args['stylesheet'] ?? '' ) !== $this->expected_stylesheet
			|| (string) ( $call_args['fingerprint'] ?? '' ) !== $this->validated_fingerprint
			|| (string) ( $response['stylesheet'] ?? '' ) !== $this->expected_stylesheet
			|| (string) ( $response['fingerprint'] ?? '' ) !== $this->validated_fingerprint
		) {
			$this->last_failure = 'The browser report was stale or was tied to a different stylesheet or project fingerprint.';
			return;
		}

		if ( self::is_fatal_render_report( $response ) ) {
			$this->requires_restore = '' !== $this->previous_stylesheet;
			$this->last_failure     = 'Every required frontend render was unavailable after activation.';
			return;
		}

		if ( true === ( $response['browser_execution_unavailable'] ?? false ) ) {
			$this->last_failure = 'Browser execution was unavailable, so frontend completion evidence could not be collected. Restore browser capability and rerun the full validator; do not treat this as a fatal theme activation failure.';
			return;
		}

		if ( ! $this->report_covers_required_surface( $call_args, $response ) ) {
			$this->last_failure = 'The browser report was partial, failed a required render, or contained deterministic quality violations.';
			return;
		}

		$this->passed       = true;
		$this->last_failure = '';
	}

	/**
	 * Record a successful published page as a possible interior-page target.
	 *
	 * @param array<string,mixed> $response Normalized response.
	 */
	private function record_published_page( array $response ): void {
		if ( 'page' !== (string) ( $response['post_type'] ?? '' ) || 'publish' !== (string) ( $response['status'] ?? '' ) ) {
			return;
		}

		$post_id = (int) ( $response['post_id'] ?? 0 );
		$url     = self::normalize_url( (string) ( $response['permalink'] ?? '' ) );
		if ( $post_id <= 0 || '' === $url ) {
			return;
		}

		$this->published_page_urls[ $post_id ] = $url;
	}

	/**
	 * Track the page selected as the front page so it is not reused as interior evidence.
	 *
	 * @param array<string,mixed> $call_args Update-option call arguments.
	 */
	private function record_front_page_selection( array $call_args ): void {
		$option = (string) ( $call_args['option'] ?? $call_args['name'] ?? $call_args['option_name'] ?? '' );
		if ( 'page_on_front' !== $option ) {
			return;
		}

		$this->front_page_id = max( 0, (int) ( $call_args['value'] ?? 0 ) );
	}

	/**
	 * Invalidate a previous report after a relevant mutable surface changes.
	 */
	private function invalidate( string $reason ): void {
		$this->validation_current    = false;
		$this->activation_current    = false;
		$this->passed                = false;
		$this->requires_restore      = false;
		$this->validated_fingerprint = '';
		$this->last_report           = array();
		$this->last_failure          = $reason;
	}

	/**
	 * Return the homepage plus one tracked published interior page.
	 *
	 * @return list<string>
	 */
	private function get_required_urls(): array {
		$urls = array();
		$home = self::normalize_url( $this->resolve_home_url() );
		if ( '' !== $home ) {
			$urls[] = $home;
		}

		foreach ( $this->published_page_urls as $post_id => $url ) {
			if ( $post_id === $this->front_page_id ) {
				continue;
			}
			if ( $url === $home ) {
				continue;
			}
			$urls[] = $url;
			break;
		}

		return $urls;
	}

	/**
	 * Resolve the public homepage URL without requiring a full WordPress runtime in unit tests.
	 */
	private function resolve_home_url(): string {
		if ( is_callable( $this->home_url_resolver ) ) {
			$value = call_user_func( $this->home_url_resolver );
			return is_string( $value ) ? $value : '';
		}

		if ( function_exists( 'home_url' ) ) {
			$function = 'home_url';
			return (string) call_user_func( $function, '/' );
		}

		return '/';
	}

	/**
	 * Verify the client report covers every required URL/viewport without violations.
	 *
	 * @param array<string,mixed> $call_args Report call arguments.
	 * @param array<string,mixed> $response  Normalized browser report.
	 */
	private function report_covers_required_surface( array $call_args, array $response ): bool {
		if (
			true !== ( $response['success'] ?? false )
			|| true !== ( $response['complete'] ?? false )
			|| true !== ( $response['passed'] ?? false )
			|| ! empty( $response['violations'] ?? array() )
		) {
			return false;
		}

		$required_urls = $this->get_required_urls();
		if ( count( $required_urls ) < 2 ) {
			return false;
		}

		$homepage_url = self::normalize_url( (string) ( $call_args['homepage_url'] ?? '' ) );
		$interior_url = self::normalize_url( (string) ( $call_args['interior_url'] ?? '' ) );
		if (
			$homepage_url !== $required_urls[0]
			|| $interior_url !== $required_urls[1]
			|| $homepage_url === $interior_url
			|| self::is_upload_preview_url( $homepage_url )
			|| self::is_upload_preview_url( $interior_url )
		) {
			return false;
		}

		$reports = $response['reports'] ?? array();
		if ( ! is_array( $reports ) ) {
			return false;
		}
		/** @var list<mixed> $reports */
		$reports               = array_values( $reports );
		$expected_report_count = count( $required_urls ) * count( self::REQUIRED_VIEWPORTS );
		if ( count( $reports ) !== $expected_report_count ) {
			return false;
		}

		foreach (
			array(
				array(
					'url'         => $homepage_url,
					'role'        => 'homepage',
					'is_homepage' => true,
				),
				array(
					'url'         => $interior_url,
					'role'        => 'interior',
					'is_homepage' => false,
				),
			) as $required_surface
		) {
			$url         = $required_surface['url'];
			$role        = $required_surface['role'];
			$is_homepage = $required_surface['is_homepage'];
			foreach ( self::REQUIRED_VIEWPORTS as $viewport ) {
				if ( ! $this->has_passing_viewport_report( $reports, $url, $role, $is_homepage, $viewport ) ) {
					return false;
				}
			}
		}

		return $this->roles_have_distinct_final_urls( $reports );
	}

	/**
	 * Return whether the report list contains a passing row for one URL/viewport pair.
	 *
	 * @param array<int,mixed>                         $reports  Browser report rows.
	 * @param string                                   $url      Required normalized URL.
	 * @param string                                   $role     Expected semantic page role.
	 * @param bool                                     $is_homepage Whether the document must be the homepage.
	 * @param array{label:string,width:int,height:int} $viewport Required viewport.
	 */
	private function has_passing_viewport_report( array $reports, string $url, string $role, bool $is_homepage, array $viewport ): bool {
		$matches = 0;
		foreach ( $reports as $report ) {
			if (
				! is_array( $report )
				|| self::normalize_url( (string) ( $report['requested_url'] ?? '' ) ) !== $url
				|| $role !== (string) ( $report['role'] ?? '' )
				|| $is_homepage !== ( $report['is_homepage'] ?? null )
				|| ! self::same_origin_url( $url, (string) ( $report['final_url'] ?? '' ) )
			) {
				continue;
			}

			$reported_viewport = $report['viewport'] ?? array();
			if (
				! is_array( $reported_viewport )
				|| (int) ( $reported_viewport['width'] ?? 0 ) !== $viewport['width']
				|| (int) ( $reported_viewport['height'] ?? 0 ) !== $viewport['height']
			) {
				continue;
			}

			++$matches;
			if (
				true !== ( $report['success'] ?? false )
				|| $this->expected_stylesheet !== (string) ( $report['active_stylesheet'] ?? '' )
				|| ! empty( $report['violations'] ?? array() )
			) {
				return false;
			}
		}

		return 1 === $matches;
	}

	/**
	 * Reject a redirect that maps homepage and interior evidence to one document.
	 *
	 * @param array<int,mixed> $reports Browser report rows.
	 */
	private function roles_have_distinct_final_urls( array $reports ): bool {
		$final_urls = array(
			'homepage' => array(),
			'interior' => array(),
		);
		foreach ( $reports as $report ) {
			if ( ! is_array( $report ) ) {
				continue;
			}
			$role      = (string) ( $report['role'] ?? '' );
			$final_url = self::normalize_url( (string) ( $report['final_url'] ?? '' ) );
			if ( isset( $final_urls[ $role ] ) && '' !== $final_url ) {
				$final_urls[ $role ][ $final_url ] = true;
			}
		}

		return ! empty( $final_urls['homepage'] )
			&& ! empty( $final_urls['interior'] )
			&& empty( array_intersect_key( $final_urls['homepage'], $final_urls['interior'] ) );
	}

	/**
	 * Return whether a redirected final document remains on the requested origin.
	 */
	private static function same_origin_url( string $requested_url, string $final_url ): bool {
		$requested = wp_parse_url( $requested_url );
		$final     = wp_parse_url( $final_url );
		if ( ! is_array( $requested ) || ! is_array( $final ) || empty( $final_url ) ) {
			return false;
		}

		return ( $requested['scheme'] ?? '' ) === ( $final['scheme'] ?? '' )
			&& ( $requested['host'] ?? '' ) === ( $final['host'] ?? '' )
			&& ( $requested['port'] ?? null ) === ( $final['port'] ?? null );
	}

	/**
	 * Detect whether an all-unrenderable browser result requires rollback.
	 *
	 * @param array<string,mixed> $response Normalized browser report.
	 */
	private static function is_fatal_render_report( array $response ): bool {
		if (
			true !== ( $response['fatal_render_failure'] ?? false )
			|| true === ( $response['browser_execution_unavailable'] ?? false )
		) {
			return false;
		}

		$reports = $response['reports'] ?? array();
		if ( ! is_array( $reports ) || empty( $reports ) ) {
			return false;
		}

		foreach ( $reports as $report ) {
			if ( ! is_array( $report ) || true === ( $report['success'] ?? false ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return mutation names that invalidate generated-theme proof.
	 *
	 * @return list<string>
	 */
	private static function get_related_mutation_abilities(): array {
		return array(
			'sd-ai-agent/append-post-content',
			'sd-ai-agent/generate-menu-page',
			'sd-ai-agent/update-global-styles',
			'sd-ai-agent/reset-global-styles',
			'sd-ai-agent/theme-json-presets',
			'sd-ai-agent/create-style-variation',
			'sd-ai-agent/update-style-variation',
			'sd-ai-agent/select-style-variation',
			'sd-ai-agent/reset-style-variation',
			'sd-ai-agent/apply-design-artifact-release',
			'sd-ai-agent/rollback-design-artifact-release',
			'sd-ai-agent/create-menu',
			'sd-ai-agent/delete-menu',
			'sd-ai-agent/add-menu-item',
			'sd-ai-agent/remove-menu-item',
			'sd-ai-agent/assign-menu-location',
			'sd-ai-agent/generate-logo-svg',
			'sd-ai-agent/update-option',
			'sd-ai-agent/delete-post',
		);
	}

	/**
	 * Return whether a filesystem mutation target belongs to the tracked generated theme.
	 *
	 * @param array<string,mixed> $call_args Response call arguments.
	 * @param array<string,mixed> $response  Normalized response.
	 */
	private function is_generated_theme_file_mutation( array $call_args, array $response ): bool {
		if ( '' === $this->expected_stylesheet ) {
			return false;
		}

		$path = (string) ( $call_args['path'] ?? $response['path'] ?? '' );
		$path = str_replace( '\\', '/', $path );
		return str_contains( $path, '/themes/' . $this->expected_stylesheet . '/' )
			|| str_starts_with( $path, 'themes/' . $this->expected_stylesheet . '/' );
	}

	/**
	 * Consume the oldest matching call arguments for a tool response.
	 *
	 * @return array<string,mixed>
	 */
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

	/**
	 * Normalize a provider function name to its registered ability name.
	 */
	private static function normalize_tool_name( string $tool_name ): string {
		if ( str_starts_with( $tool_name, 'wpab__sd-ai-agent__' ) ) {
			return 'sd-ai-agent/' . substr( $tool_name, strlen( 'wpab__sd-ai-agent__' ) );
		}

		if ( str_starts_with( $tool_name, 'wpab__sd-ai-agent-js__' ) ) {
			return 'sd-ai-agent-js/' . substr( $tool_name, strlen( 'wpab__sd-ai-agent-js__' ) );
		}

		return $tool_name;
	}

	/**
	 * Decode serialized tool responses while preserving object-shaped arrays.
	 *
	 * @param mixed $response Raw response.
	 * @return array<string,mixed>
	 */
	private static function normalize_response( $response ): array {
		if ( is_string( $response ) && '' !== $response ) {
			$decoded = json_decode( $response, true );
			if ( ! is_array( $decoded ) ) {
				/** @var array<string,mixed> $error_response */
				$error_response = array( 'error' => $response );
				return $error_response;
			}
			$response = $decoded;
		}

		if ( ! is_array( $response ) ) {
			/** @var array<string,mixed> $empty_response */
			$empty_response = array();
			return $empty_response;
		}

		/** @var array<string,mixed> $normalized */
		$normalized = array();
		foreach ( $response as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * Return whether a normalized response represents a successful mutation/read.
	 *
	 * @param array<string,mixed> $response Normalized response.
	 */
	private static function is_successful_response( array $response ): bool {
		if ( empty( $response ) || ! empty( $response['error'] ?? '' ) ) {
			return false;
		}

		if ( false === ( $response['success'] ?? true ) ) {
			return false;
		}

		return 'proposal_pending' !== (string) ( $response['status'] ?? '' );
	}

	/**
	 * Sanitize a generated-theme stylesheet without mutating canonical values.
	 */
	private static function sanitize_stylesheet( string $stylesheet ): string {
		$stylesheet = strtolower( trim( $stylesheet ) );
		return preg_match( '/^[a-z0-9-]+$/', $stylesheet ) ? $stylesheet : '';
	}

	/**
	 * Normalize comparable public URLs without following or fetching them.
	 */
	private static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$normalized = rtrim( $url, '/' );
		return '' === $normalized && '/' === $url ? '/' : $normalized;
	}

	/**
	 * Reject upload-backed preview artifacts as frontend completion targets.
	 */
	private static function is_upload_preview_url( string $url ): bool {
		return str_contains( strtolower( $url ), '/wp-content/uploads/' );
	}
}
