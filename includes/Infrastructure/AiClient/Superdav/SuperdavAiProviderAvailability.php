<?php

declare(strict_types=1);

namespace SdAiAgent\Infrastructure\AiClient\Superdav;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight availability check for the Superdav AI provider.
 */
final class SuperdavAiProviderAvailability implements ProviderAvailabilityInterface, WithRequestAuthenticationInterface {

	use WithRequestAuthenticationTrait;

	/**
	 * The provider is configured when the SDK registry has request auth for it.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		try {
			$this->getRequestAuthentication();
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
