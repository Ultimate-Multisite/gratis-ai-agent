<?php

declare(strict_types=1);
/**
 * Tests for durable human approval requests.
 *
 * @package SdAiAgent
 * @subpackage Tests\Automations
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Automations;

use SdAiAgent\Automations\HumanApprovalGate;
use SdAiAgent\Core\Database;
use WP_Error;
use WP_UnitTestCase;

class HumanApprovalGateTest extends WP_UnitTestCase {

	private int $admin_id;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		/** @var \wpdb $wpdb */
		Database::install();
		HumanApprovalGate::clear_handlers();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup for custom table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', HumanApprovalGate::table_name() ) );
	}

	public function tear_down(): void {
		HumanApprovalGate::clear_handlers();
		parent::tear_down();
	}

	public function test_create_pending_redacts_secrets_and_masks_phone_numbers(): void {
		$request = HumanApprovalGate::create_pending( $this->make_request_args() );

		$this->assertIsArray( $request );
		$this->assertSame( HumanApprovalGate::STATUS_PENDING, $request['status'] );
		$this->assertSame( '[redacted]', $request['payload']['api_key'] );
		$this->assertSame( '[redacted]', $request['payload']['nested']['token'] );
		$this->assertSame( '***4567', $request['payload']['recipient_phone'] );
	}

	public function test_create_pending_reuses_equivalent_pending_request(): void {
		$first  = HumanApprovalGate::create_pending( $this->make_request_args() );
		$second = HumanApprovalGate::create_pending( $this->make_request_args() );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( $first['id'], $second['id'] );
	}

	public function test_approve_executes_registered_handler_exactly_once(): void {
		$calls = 0;
		HumanApprovalGate::register_handler(
			'sms-send',
			static function ( array $payload ) use ( &$calls ): array {
				++$calls;
				return [ 'message_id' => 'msg_' . $payload['source_id'] ];
			}
		);

		$request = HumanApprovalGate::create_pending( $this->make_request_args( [ 'payload' => [ 'source_id' => 42 ] ] ) );
		$this->assertIsArray( $request );

		$approved = HumanApprovalGate::approve( (int) $request['id'], $this->admin_id );
		$again    = HumanApprovalGate::execute( (int) $request['id'] );

		$this->assertIsArray( $approved );
		$this->assertSame( HumanApprovalGate::STATUS_EXECUTED, $approved['status'] );
		$this->assertSame( 'msg_42', $approved['result']['data']['message_id'] );
		$this->assertSame( $approved['id'], $again['id'] );
		$this->assertSame( 1, $calls );
	}

	public function test_reject_prevents_execution(): void {
		$request = HumanApprovalGate::create_pending( $this->make_request_args() );
		$this->assertIsArray( $request );

		$rejected = HumanApprovalGate::reject( (int) $request['id'], $this->admin_id );
		$execute  = HumanApprovalGate::execute( (int) $request['id'] );

		$this->assertIsArray( $rejected );
		$this->assertSame( HumanApprovalGate::STATUS_REJECTED, $rejected['status'] );
		$this->assertInstanceOf( WP_Error::class, $execute );
	}

	public function test_expired_pending_request_cannot_be_approved(): void {
		$request = HumanApprovalGate::create_pending( $this->make_request_args( [ 'expires_at' => '2000-01-01 00:00:00' ] ) );
		$this->assertIsArray( $request );

		$approved = HumanApprovalGate::approve( (int) $request['id'], $this->admin_id );

		$this->assertIsArray( $approved );
		$this->assertSame( HumanApprovalGate::STATUS_EXPIRED, $approved['status'] );
	}

	/**
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function make_request_args( array $overrides = [] ): array {
		return array_replace_recursive(
			[
				'source_type'  => 'automation',
				'source_id'    => 123,
				'action_type'  => 'sms-send',
				'requested_by' => $this->admin_id,
				'payload'      => [
					'recipient_phone' => '+1 (555) 123-4567',
					'message'         => 'Reminder message',
					'api_key'         => 'secret-key',
					'nested'          => [ 'token' => 'secret-token' ],
				],
			],
			$overrides
		);
	}
}
