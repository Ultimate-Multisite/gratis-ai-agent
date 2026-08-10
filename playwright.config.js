/**
 * Playwright E2E test configuration for Superdav AI Agent.
 *
 * Tests run against a wp-env WordPress environment.
 * Start the environment with `pnpm run wp-env:start` before running tests.
 *
 * @see https://playwright.dev/docs/test-configuration
 */

const { defineConfig, devices } = require( '@playwright/test' );

const ciWorkers = Number.parseInt( process.env.PLAYWRIGHT_WORKERS || '2', 10 );
const useSystemChrome = !! process.env.PLAYWRIGHT_USE_SYSTEM_CHROME;

/** @param {number} n @returns {number} */
function getWorkerCount( n ) {
	return Number.isFinite( n ) && n > 0 ? n : 2;
}

module.exports = defineConfig( {
	testDir: './tests/e2e',
	testMatch: '**/*.spec.js',

	/* Maximum time one test can run for.
	 * 90 s gives loginToWordPress (60 s redirect wait) + goToAgentPage
	 * (30 s SPA mount wait) enough headroom on CI runners. The 60 s
	 * timeout was too tight — login + navigation alone consumed the full
	 * budget, leaving no time for assertions. */
	timeout: 90_000,

	/* Fail the build on CI if you accidentally left test.only in the source code. */
	forbidOnly: !! process.env.CI,

	/* Retry on CI only — 2 retries to handle CI flakiness from slow
	 * Docker-based wp-env on GitHub Actions runners. */
	retries: process.env.CI ? 2 : 0,

	/* Worker count on CI is tunable via PLAYWRIGHT_WORKERS env var.
	 * Both WP 6.9 and trunk use 2 workers (set in e2e.yml) to avoid
	 * overloading the wp-env environment — more workers cause resource
	 * contention that manifests as login and SPA-render timeouts on CI
	 * runners. The suite is sharded (3 shards per WP version) so 2 workers
	 * per shard is sufficient to complete within the 45-min shard timeout. */
	workers: process.env.CI ? getWorkerCount( ciWorkers ) : undefined,

	/* Reporter to use.
	 * CI uses list + github + html: list outputs per-test names and errors
	 * to the log (the github reporter only emits a compact progress line
	 * and annotations on completion — if the job times out, no annotations
	 * are created and errors are invisible). */
	reporter: process.env.CI
		? [ [ 'list' ], [ 'github' ], [ 'html', { open: 'never' } ] ]
		: [ [ 'list' ], [ 'html', { open: 'on-failure' } ] ],

	use: {
		/* wp-env default URL — must match .wp-env.json "port" (8890). */
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8890',

		/* Collect trace when retrying the failed test. */
		trace: 'on-first-retry',

		/* Take screenshot on failure. */
		screenshot: 'only-on-failure',

		/* Keep CI retries lightweight when launching the preinstalled system
		 * Chrome. The Playwright ffmpeg download can hang before tests start on
		 * GitHub runners; traces and failure screenshots provide the artifacts we
		 * need without requiring the browser-bundled ffmpeg binary. */
		video: useSystemChrome ? 'off' : 'on-first-retry',
	},

	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				...( useSystemChrome ? { channel: 'chrome' } : {} ),
			},
		},
	],
} );
