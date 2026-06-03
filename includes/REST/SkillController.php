<?php

declare(strict_types=1);
/**
 * REST API controller for skills.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use SdAiAgent\Core\Settings;
use SdAiAgent\Core\SkillUpdateChecker;
use SdAiAgent\Models\Skill;
use SdAiAgent\Repositories\SkillUsageRepository;
use SdAiAgent\Services\SkillService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;
use XWP_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages agent skills via REST.
 *
 * Domain logic (formatting, business rules) is delegated to SkillService.
 *
 * Endpoints:
 *  GET    /skills               — list all skills
 *  POST   /skills               — create a custom skill
 *  PATCH  /skills/{id}          — update a skill
 *  DELETE /skills/{id}          — delete a custom skill
 *  POST   /skills/{id}/reset    — reset a built-in skill to defaults
 *  GET    /skills/stats         — aggregated usage stats per skill
 *  POST   /skills/check-updates — check remote manifest for available updates
 */
#[REST_Handler(
	namespace: RestController::NAMESPACE,
	basename: 'skills',
	container: 'sd-ai-agent',
)]
final class SkillController extends XWP_REST_Controller {

	use PermissionTrait;

	/**
	 * Handle GET /skills — list all skills.
	 *
	 * @return WP_REST_Response
	 */
	#[REST_Route(
		route: '',
		methods: WP_REST_Server::READABLE,
		guard: 'check_permission',
	)]
	public function handle_list_skills(): WP_REST_Response {
		$skills = Skill::get_all();
		// @phpstan-ignore-next-line
		$list = array_map( array( SkillService::class, 'format_skill' ), $skills );
		return new WP_REST_Response( $list, 200 );
	}

	/**
	 * Handle POST /skills — create a custom skill.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	#[REST_Route(
		route: '',
		methods: WP_REST_Server::CREATABLE,
		vars: 'get_create_args',
		guard: 'check_permission',
	)]
	public function handle_create_skill( WP_REST_Request $request ) {
		$id = SkillService::create_skill(
			$request->get_param( 'slug' ),
			$request->get_param( 'name' ),
			$request->get_param( 'description' ) ?? '',
			$request->get_param( 'content' )
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$skill = Skill::get( $id );

		if ( ! $skill ) {
			return new WP_Error( 'sd_ai_agent_skill_not_found', __( 'Skill not found after creation.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( SkillService::format_skill( $skill ), 201 );
	}

	/**
	 * Handle PATCH /skills/{id} — update a skill.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	#[REST_Route(
		route: '(?P<id>\d+)',
		methods: 'PATCH',
		vars: 'get_update_args',
		guard: 'check_permission',
	)]
	public function handle_update_skill( WP_REST_Request $request ) {
		$id   = self::get_int_param( $request, 'id' );
		$data = array();

		if ( $request->has_param( 'name' ) ) {
			$data['name'] = $request->get_param( 'name' );
		}
		if ( $request->has_param( 'description' ) ) {
			$data['description'] = $request->get_param( 'description' );
		}
		if ( $request->has_param( 'content' ) ) {
			$data['content'] = $request->get_param( 'content' );
		}
		if ( $request->has_param( 'enabled' ) ) {
			$data['enabled'] = $request->get_param( 'enabled' );
		}

		$updated = Skill::update( $id, $data );

		if ( ! $updated ) {
			return new WP_Error(
				'sd_ai_agent_skill_update_failed',
				__( 'Failed to update skill.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		$skill = Skill::get( $id );

		if ( ! $skill ) {
			return new WP_Error( 'sd_ai_agent_skill_not_found', __( 'Skill not found after update.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( SkillService::format_skill( $skill ), 200 );
	}

	/**
	 * Handle DELETE /skills/{id} — delete a custom skill (refuses built-in).
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	#[REST_Route(
		route: '(?P<id>\d+)',
		methods: WP_REST_Server::DELETABLE,
		vars: 'get_id_args',
		guard: 'check_permission',
	)]
	public function handle_delete_skill( WP_REST_Request $request ) {
		$id     = self::get_int_param( $request, 'id' );
		$result = SkillService::delete_skill( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Handle POST /skills/{id}/reset — reset a built-in skill to defaults.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	#[REST_Route(
		route: '(?P<id>\d+)/reset',
		methods: WP_REST_Server::CREATABLE,
		vars: 'get_id_args',
		guard: 'check_permission',
	)]
	public function handle_reset_skill( WP_REST_Request $request ) {
		$id    = self::get_int_param( $request, 'id' );
		$reset = Skill::reset_builtin( $id );

		if ( ! $reset ) {
			return new WP_Error(
				'sd_ai_agent_skill_reset_failed',
				__( 'Failed to reset skill. Only built-in skills can be reset.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		$skill = Skill::get( $id );

		if ( ! $skill ) {
			return new WP_Error( 'sd_ai_agent_skill_not_found', __( 'Skill not found after reset.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( SkillService::format_skill( $skill ), 200 );
	}

	/**
	 * Schema arguments for POST /skills (create).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_create_args(): array {
		return array(
			'slug'        => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			),
			'name'        => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'content'     => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			),
		);
	}

	/**
	 * Schema arguments for PATCH /skills/{id} (update).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_update_args(): array {
		return array(
			'id'          => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'name'        => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'content'     => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			),
			'enabled'     => array(
				'required' => false,
				'type'     => 'boolean',
			),
		);
	}

	/**
	 * Handle GET /skills/stats — aggregated usage stats per skill.
	 *
	 * Returns total load count, helpful/neutral/negative outcome counts, and
	 * the last-used timestamp for each skill that has at least one usage record.
	 * Skills with no usage records are omitted (frontend treats absence as zero).
	 *
	 * @return WP_REST_Response
	 */
	#[REST_Route(
		route: 'stats',
		methods: WP_REST_Server::READABLE,
		guard: 'check_permission',
	)]
	public function handle_skill_stats(): WP_REST_Response {
		$rows = SkillUsageRepository::get_stats();

		// Index by skill_id for easy frontend lookups.
		$stats = array();
		foreach ( $rows as $row ) {
			$stats[ (int) $row->skill_id ] = array(
				'skill_id'       => (int) $row->skill_id,
				'total_loads'    => (int) $row->total_loads,
				'helpful_count'  => (int) $row->helpful_count,
				'neutral_count'  => (int) $row->neutral_count,
				'negative_count' => (int) $row->negative_count,
				'last_used_at'   => (string) $row->last_used_at,
			);
		}

		return new WP_REST_Response( $stats, 200 );
	}

	/**
	 * Handle POST /skills/check-updates — check remote manifest for updates.
	 *
	 * Fetches the skill manifest URL from settings, compares each built-in
	 * skill's content_hash against the manifest, and optionally applies
	 * updates to skills that have not been user-modified when skill_auto_update
	 * is enabled.
	 *
	 * Returns a map of skill_id => { has_update, remote_version, applied }.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	#[REST_Route(
		route: 'check-updates',
		methods: WP_REST_Server::CREATABLE,
		guard: 'check_permission',
	)]
	public function handle_check_updates(): WP_REST_Response|WP_Error {
		// Read settings directly — avoids constructor injection for a single call.
		// @phpstan-ignore-next-line
		$saved_settings = (array) get_option( Settings::OPTION_NAME, array() );
		$manifest_url   = (string) ( $saved_settings['skill_manifest_url'] ?? '' );
		$auto_update    = isset( $saved_settings['skill_auto_update'] )
			? (bool) $saved_settings['skill_auto_update']
			: true; // default: auto-update enabled

		if ( '' === $manifest_url ) {
			return new WP_Error(
				'sd_ai_agent_no_manifest_url',
				__( 'No skill manifest URL configured. Set skill_manifest_url in settings.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		// Reject non-HTTPS schemes to reduce SSRF surface area.
		// Admin-supplied URLs are lower risk, but an https-only policy blocks accidental
		// plain-http manifests and prevents probing cloud metadata endpoints.
		if ( ! str_starts_with( strtolower( $manifest_url ), 'https://' ) ) {
			return new WP_Error(
				'sd_ai_agent_manifest_invalid_scheme',
				__( 'Skill manifest URL must use HTTPS.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		// Fetch the remote manifest.
		$response = wp_remote_get(
			$manifest_url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'sd_ai_agent_manifest_fetch_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to fetch skill manifest: %s', 'superdav-ai-agent' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status_code ) {
			return new WP_Error(
				'sd_ai_agent_manifest_bad_status',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Skill manifest returned HTTP %d.', 'superdav-ai-agent' ),
					$status_code
				),
				array( 'status' => 502 )
			);
		}

		$body     = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $body, true );
		$manifest = is_array( $decoded ) ? SkillUpdateChecker::normalize_manifest( $decoded ) : [];

		if ( [] === $manifest ) {
			return new WP_Error(
				'sd_ai_agent_manifest_invalid',
				__( 'Skill manifest is not valid JSON or does not contain skill entries.', 'superdav-ai-agent' ),
				array( 'status' => 502 )
			);
		}

		// Compare each built-in skill against the manifest.
		$skills  = Skill::get_all();
		$results = array();

		foreach ( $skills ?? array() as $skill ) {
			if ( ! $skill->is_builtin ) {
				continue;
			}

			$entry = $manifest[ $skill->slug ] ?? null;

			if ( null === $entry || ! is_array( $entry ) ) {
				continue;
			}

			$update_data = Skill::check_for_updates( $skill, $entry );
			$has_update  = null !== $update_data;
			$applied     = false;

			if ( $has_update && $auto_update && ! $skill->user_modified ) {
				$applied = Skill::apply_update( $skill->id, $entry );
			}

			$results[ $skill->id ] = array(
				'skill_id'       => (int) $skill->id,
				'slug'           => (string) $skill->slug,
				'has_update'     => (bool) $has_update,
				'remote_version' => (string) ( $entry['version'] ?? '' ),
				'applied'        => (bool) $applied,
				'user_modified'  => (bool) $skill->user_modified,
			);
		}

		return new WP_REST_Response( $results, 200 );
	}

	/**
	 * Schema arguments for routes that only need an ID parameter.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_id_args(): array {
		return array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}
}
