<?php
/**
 * Activation / deactivation lifecycle handler.
 *
 * WordPress fires `register_activation_hook` and `register_deactivation_hook`
 * callbacks *before* `plugins_loaded`, which means they run earlier than the
 * DI container bootstrap performed by {@see xwp_load_app()}. For that reason
 * the activation / deactivation wiring cannot be expressed as `#[Handler]`
 * classes inside the DI graph — it has to be a plain static entry-point that
 * the plugin bootstrap file registers directly.
 *
 * This class consolidates the handful of static calls that used to live in
 * `sd-ai-agent.php` so the root file stays thin and responsibilities are
 * grouped by lifecycle stage.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Abilities\ToolCapabilities;
use SdAiAgent\Automations\AutomationRunner;
use SdAiAgent\Core\ActiveJobsCleanupService;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\OnboardingManager;
use SdAiAgent\Core\SkillUpdateChecker;
use SdAiAgent\Core\SiteScanner;
use SdAiAgent\Knowledge\KnowledgeHooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispatches plugin activation / deactivation work.
 *
 * Methods are kept static because WordPress requires serialisable callbacks
 * for the activation / deactivation hook callbacks and because the DI
 * container does not yet exist at the time these fire.
 */
final class LifecycleHandler {

	/**
	 * Runs on plugin activation.
	 *
	 * Install the database schema, reschedule cron automations, kick off the
	 * smart-onboarding flow on fresh installs, and register the per-ability
	 * WordPress capabilities so role-management plugins can discover them.
	 */
	public static function activate(): void {
		Database::install();
		AutomationRunner::reschedule_all();
		OnboardingManager::on_activation();
		SkillUpdateChecker::schedule();
		ActiveJobsCleanupService::schedule();
		ToolCapabilities::register_capabilities( ToolCapabilities::all_ability_ids() );
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * Deactivate knowledge-base cron hooks, unschedule every automation, and
	 * cancel the site-scan cron event. Intentionally does NOT drop database
	 * tables or delete options — that only happens on uninstall.
	 */
	public static function deactivate(): void {
		KnowledgeHooks::deactivate();
		AutomationRunner::unschedule_all();
		SiteScanner::unschedule();
		SkillUpdateChecker::unschedule();
		ActiveJobsCleanupService::unschedule();
	}
}
