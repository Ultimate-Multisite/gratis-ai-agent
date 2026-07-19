/**
 * Register the generated-theme completion validator in the Setup Assistant.
 *
 * The frontend floating widget does not generate site themes, so keeping this
 * registration in the admin entry point avoids adding the validator to its
 * initial bundle.
 */

import { ensureRegistered } from './index';
import { registerThemeCompletionValidatorAbility } from './theme-completion-validator';

/** Window-global key for the Setup Assistant registration promise. */
export const THEME_COMPLETION_REGISTRATION_KEY =
	'__sdAiAgentThemeCompletionValidatorRegistering';

/**
 * Register the generated-theme completion validator after the shared client
 * ability category and base abilities have completed registration. This
 * intentionally does not extend the shared registration promise: the local
 * descriptor and callback are published synchronously before the WordPress
 * registry request settles, so a slow core registration cannot block chat.
 *
 * @return {Promise<void>} Registration promise.
 */
export function ensureThemeCompletionValidatorRegistered() {
	if ( window[ THEME_COMPLETION_REGISTRATION_KEY ] ) {
		return window[ THEME_COMPLETION_REGISTRATION_KEY ];
	}

	const registrationPromise = ensureRegistered()
		.then( () => registerThemeCompletionValidatorAbility() )
		.catch( ( error ) => {
			if (
				window[ THEME_COMPLETION_REGISTRATION_KEY ] ===
				registrationPromise
			) {
				window[ THEME_COMPLETION_REGISTRATION_KEY ] = null;
			}
			throw error;
		} );
	window[ THEME_COMPLETION_REGISTRATION_KEY ] = registrationPromise;

	return registrationPromise;
}

ensureThemeCompletionValidatorRegistered().catch( () => {
	// A later call can retry after the rejected cache entry is cleared.
} );
