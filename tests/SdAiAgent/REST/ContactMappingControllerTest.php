<?php

declare(strict_types=1);
/**
 * Tests for attendee contact mapping REST endpoints.
 *
 * @package SdAiAgent\Tests\REST
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\REST;

use SdAiAgent\Core\Database;
use SdAiAgent\Core\Settings;
use SdAiAgent\REST\SettingsController;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Covers admin-only contact mapping CRUD routes.
 */
final class ContactMappingControllerTest extends WP_UnitTestCase {

	/** @var WP_REST_Server REST server. */
	private WP_REST_Server $server;

	/** @var int Admin user ID. */
	private int $admin_id;

	/** @var int Subscriber user ID. */
	private int $subscriber_id;

	/**
	 * Set up REST server and database.
	 */
	public function set_up(): void {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		$controller = new SettingsController( new Settings(), new Database() );
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		do_action( 'rest_api_init' );

		parent::set_up();

		delete_option( Database::DB_VERSION_OPTION );
		Database::install();
		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	/**
	 * Tear down REST server.
	 */
	public function tear_down(): void {
		global $wp_rest_server;

		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Subscriber users cannot manage contact mappings.
	 */
	public function test_contact_mapping_routes_are_admin_only(): void {
		wp_set_current_user( $this->subscriber_id );

		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/settings/contact-mappings' );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Admin users can create, update, list, and delete mappings.
	 */
	public function test_admin_can_manage_contact_mappings(): void {
		wp_set_current_user( $this->admin_id );

		$create = $this->dispatch(
			'POST',
			'/sd-ai-agent/v1/settings/contact-mappings',
			array(
				'attendee_email' => 'Person@Example.com',
				'phone_e164'     => '+15551234567',
				'sms_consent'    => true,
			)
		);

		$this->assertSame( 201, $create->get_status() );
		$created = $create->get_data();
		$this->assertSame( 'person@example.com', $created['attendee_email'] );

		$update = $this->dispatch( 'PATCH', '/sd-ai-agent/v1/settings/contact-mappings/' . $created['id'], array( 'sms_consent' => false ) );
		$this->assertSame( 200, $update->get_status() );
		$this->assertFalse( $update->get_data()['sms_consent'] );

		$list = $this->dispatch( 'GET', '/sd-ai-agent/v1/settings/contact-mappings' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertSame( 'person@example.com', $list->get_data()['contacts'][0]['attendee_email'] );

		$delete = $this->dispatch( 'DELETE', '/sd-ai-agent/v1/settings/contact-mappings/' . $created['id'] );
		$this->assertSame( 200, $delete->get_status() );
		$this->assertTrue( $delete->get_data()['deleted'] );
	}

	/**
	 * Dispatch a REST request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route path.
	 * @param array<string, mixed> $params Request params.
	 * @return \WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		if ( in_array( $method, [ 'POST', 'PATCH', 'PUT' ], true ) ) {
			$request->set_body( wp_json_encode( $params ) );
			$request->set_header( 'Content-Type', 'application/json' );
		} else {
			$request->set_query_params( $params );
		}

		return $this->server->dispatch( $request );
	}
}
