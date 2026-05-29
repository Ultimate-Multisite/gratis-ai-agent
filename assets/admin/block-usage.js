/*
 * Superdav AI Agent — Block Usage admin sub-page behaviour.
 *
 * Replaces the inline `onclick="return confirm( ... );"` attribute
 * previously emitted by includes/Admin/BlockUsagePage.php so the admin
 * sub-page complies with the WordPress.org Plugin Review guideline
 * against inline scripts and inline event handlers.
 *
 * Loaded only on the Block Usage sub-page by
 * includes/Bootstrap/BlockUsageAdminHandler::enqueue_assets().
 *
 * Reads the localised `sdAiAgentBlockUsage` global (provided via
 * wp_localize_script) for the translated confirmation prompt so this
 * file stays server-string-free.
 */
( function () {
	'use strict';

	const data =
		( typeof window !== 'undefined' && window.sdAiAgentBlockUsage ) || {};
	const confirmMessage =
		data.confirmMessage ||
		'Refresh block usage stats now? This may take a moment on large sites.';

	const form = document.querySelector( '.sd-ai-agent-block-usage-form' );
	if ( ! form ) {
		return;
	}

	form.addEventListener( 'submit', function ( event ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( confirmMessage ) ) {
			event.preventDefault();
		}
	} );
} )();
