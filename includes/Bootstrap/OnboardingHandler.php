<?php
/**
 * DI handler for smart onboarding and site-scanner hooks.
 *
 * Replaces the `OnboardingManager::register()` call in CoreServicesHandler
 * (which internally called `SiteScanner::register()` plus two `add_action()`
 * calls) by wiring each hook directly via `#[Action]` attributes.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Core\ActiveJobsCleanupService;
use SdAiAgent\Core\OnboardingManager;
use SdAiAgent\Core\Settings;
use SdAiAgent\Core\SkillUpdateChecker;
use SdAiAgent\Core\SiteScanner;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the smart-onboarding flow and background site-scanner cron job.
 *
 * CTX_GLOBAL is required because the handler spans multiple contexts:
 * - `admin_init` fires in CTX_ADMIN.
 * - The site-scanner cron hook fires in CTX_CRON.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class OnboardingHandler {

	/**
	 * Run the background site-scanner cron job.
	 *
	 * Scheduled as a one-time event via {@see SiteScanner::schedule()}.
	 */
	#[Action( tag: SiteScanner::CRON_HOOK, priority: 10 )]
	public function run_site_scan(): void {
		SiteScanner::run();
	}

	/**
	 * Run the daily skill update check cron job.
	 *
	 * Scheduled as a recurring daily event via {@see SkillUpdateChecker::schedule()}.
	 */
	#[Action( tag: SkillUpdateChecker::CRON_HOOK, priority: 10 )]
	public function run_skill_update_check(): void {
		SkillUpdateChecker::run();
	}

	/**
	 * Run the hourly stale active-jobs reaper cron job.
	 *
	 * Scheduled as a recurring hourly event via {@see ActiveJobsCleanupService::schedule()}.
	 * Marks rows as 'abandoned' when status='processing' and updated_at has
	 * not advanced within the configured threshold (default 15 minutes).
	 */
	#[Action( tag: ActiveJobsCleanupService::CRON_HOOK, priority: 10 )]
	public function run_active_jobs_cleanup(): void {
		ActiveJobsCleanupService::run();
	}

	/**
	 * Trigger the onboarding flow on admin_init if conditions are met.
	 */
	#[Action( tag: 'admin_init', priority: 10 )]
	public function maybe_trigger_onboarding(): void {
		OnboardingManager::maybe_trigger();
	}

	/**
	 * Register the onboarding REST endpoints on rest_api_init.
	 */
	#[Action( tag: 'rest_api_init', priority: 10 )]
	public function register_onboarding_rest_routes(): void {
		OnboardingManager::register_rest_routes();
	}

	/**
	 * Auto-enable WooCommerce abilities on first load when a provider is
	 * detected and WooCommerce is active.
	 */
	#[Action( tag: 'admin_init', priority: 20 )]
	public function maybe_auto_enable_woo_abilities(): void {
		Settings::instance()->maybe_auto_enable_woo_abilities();
	}
}
