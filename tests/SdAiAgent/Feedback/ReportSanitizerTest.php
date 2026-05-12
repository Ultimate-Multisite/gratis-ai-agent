<?php

declare(strict_types=1);
/**
 * Test case for feedback report sanitization.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Feedback;

use SdAiAgent\Feedback\ReportSanitizer;
use WP_UnitTestCase;

/**
 * Test ReportSanitizer credential scrubbing.
 */
class ReportSanitizerTest extends WP_UnitTestCase {

	/**
	 * Test chat session messages are scrubbed before feedback payload transmission.
	 */
	public function test_sanitize_redacts_credentials_from_session_messages(): void {
		$openai_key         = 'sk-' . 'proj-' . str_repeat( 'a', 40 );
		$high_entropy_value = str_repeat( 'b', 44 );

		$payload = array(
			'user_description' => 'Bearer bearer-token-value-abcdefghijklmnopqrstuvwxyz0123456789',
			'session_data'     => array(
				'messages'          => array(
					array(
						'role'    => 'user',
						'content' => 'OpenAI key ' . $openai_key . ' should not leave this site.',
					),
					array(
						'role'    => 'user',
						'content' => 'token: ' . $high_entropy_value,
					),
				),
				'session_messages' => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'api_key=' . $high_entropy_value,
							),
						),
					),
				),
				'tool_calls'        => array(
					array(
						'input'  => array(
							'nested' => array(
								'authorization' => 'Bearer nested-token-value-abcdefghijklmnopqrstuvwxyz0123456789',
							),
						),
						'result' => 'password: secret-password-value',
					),
				),
			),
		);

		$sanitized = ReportSanitizer::sanitize( $payload );
		$encoded   = (string) wp_json_encode( $sanitized );

		$this->assertStringNotContainsString( $openai_key, $encoded );
		$this->assertStringNotContainsString( $high_entropy_value, $encoded );
		$this->assertStringNotContainsString( 'bearer-token-value-abcdefghijklmnopqrstuvwxyz0123456789', $encoded );
		$this->assertStringNotContainsString( 'secret-password-value', $encoded );
		$this->assertStringContainsString( '[REDACTED:', $encoded );
	}
}
