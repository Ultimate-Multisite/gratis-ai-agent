/*
 * Superdav AI Agent — Block Preferences admin sub-page behaviour.
 *
 * Extracted from the inline <script> tag previously emitted by
 * includes/Admin/BlockPreferencesPage.php so the admin sub-page complies
 * with the WordPress.org Plugin Review guideline against inline scripts.
 *
 * Loaded only on the Block Preferences sub-page by
 * includes/Bootstrap/BlockPreferencesAdminHandler::enqueue_assets().
 *
 * Reads the localised `sdAiAgentBlockPrefs` global (provided via
 * wp_localize_script) for translated strings so this file stays
 * server-string-free.
 */
( function () {
	'use strict';

	const data =
		( typeof window !== 'undefined' && window.sdAiAgentBlockPrefs ) || {};
	const removeLabel = data.removeLabel || 'Remove';
	const defaultTier = data.defaultTier || 'acceptable';

	// Remove row (delegated to support rows added after page load).
	document.addEventListener( 'click', function ( e ) {
		const target = e.target;
		if (
			target &&
			target.classList &&
			target.classList.contains( 'sd-remove-row' )
		) {
			const row = target.closest( 'tr' );
			if ( row ) {
				row.remove();
			}
		}
	} );

	// Add preference row.
	const addPrefBtn = document.getElementById( 'sd-add-pref-row' );
	if ( addPrefBtn ) {
		addPrefBtn.addEventListener( 'click', function () {
			const tbody = document.querySelector( '#sd-pref-table tbody' );
			if ( ! tbody ) {
				return;
			}

			const tr = document.createElement( 'tr' );

			const tdKey = document.createElement( 'td' );
			const inputKey = document.createElement( 'input' );
			inputKey.type = 'text';
			inputKey.name = 'sd_pref_keys[]';
			inputKey.value = '';
			inputKey.className =
				'regular-text sd-ai-agent-block-prefs-input-text';
			tdKey.appendChild( inputKey );

			const tdScore = document.createElement( 'td' );
			const inputScore = document.createElement( 'input' );
			inputScore.type = 'number';
			inputScore.name = 'sd_pref_scores[]';
			inputScore.value = '50';
			inputScore.min = '0';
			inputScore.max = '100';
			inputScore.className = 'sd-ai-agent-block-prefs-input-score';
			tdScore.appendChild( inputScore );

			const tdTier = document.createElement( 'td' );
			const tierSpan = document.createElement( 'span' );
			tierSpan.className = 'sd-tier-label';
			tierSpan.textContent = defaultTier;
			tdTier.appendChild( tierSpan );

			const tdRemove = document.createElement( 'td' );
			const removeBtn = document.createElement( 'button' );
			removeBtn.type = 'button';
			removeBtn.className = 'button button-small sd-remove-row';
			removeBtn.textContent = removeLabel;
			tdRemove.appendChild( removeBtn );

			tr.appendChild( tdKey );
			tr.appendChild( tdScore );
			tr.appendChild( tdTier );
			tr.appendChild( tdRemove );
			tbody.appendChild( tr );
		} );
	}

	// Add replacement row.
	const addReplBtn = document.getElementById( 'sd-add-repl-row' );
	if ( addReplBtn ) {
		addReplBtn.addEventListener( 'click', function () {
			const tbody = document.querySelector( '#sd-repl-table tbody' );
			if ( ! tbody ) {
				return;
			}

			const tr = document.createElement( 'tr' );

			const tdLegacy = document.createElement( 'td' );
			const inputLegacy = document.createElement( 'input' );
			inputLegacy.type = 'text';
			inputLegacy.name = 'sd_repl_legacy[]';
			inputLegacy.value = '';
			inputLegacy.className =
				'regular-text sd-ai-agent-block-prefs-input-text';
			tdLegacy.appendChild( inputLegacy );

			const tdModern = document.createElement( 'td' );
			const inputModern = document.createElement( 'input' );
			inputModern.type = 'text';
			inputModern.name = 'sd_repl_modern[]';
			inputModern.value = '';
			inputModern.className =
				'regular-text sd-ai-agent-block-prefs-input-text';
			tdModern.appendChild( inputModern );

			const tdRemove = document.createElement( 'td' );
			const removeBtn = document.createElement( 'button' );
			removeBtn.type = 'button';
			removeBtn.className = 'button button-small sd-remove-row';
			removeBtn.textContent = removeLabel;
			tdRemove.appendChild( removeBtn );

			tr.appendChild( tdLegacy );
			tr.appendChild( tdModern );
			tr.appendChild( tdRemove );
			tbody.appendChild( tr );
		} );
	}
} )();
