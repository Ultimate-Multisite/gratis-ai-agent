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

/** Shared registration key awaited by the chat send-message thunk. */
const ABILITIES_REGISTRATION_KEY = '__sdAiAgentAbilitiesRegistering';

/**
 * Register the generated-theme completion validator after the shared client
 * ability category and base abilities have completed registration.
 *
 * @return {Promise<void>} Registration promise.
 */
export function ensureThemeCompletionValidatorRegistered() {
	if ( window[ THEME_COMPLETION_REGISTRATION_KEY ] ) {
		return window[ THEME_COMPLETION_REGISTRATION_KEY ];
	}

	const registrationPromise = ensureRegistered().then( () =>
		registerThemeCompletionValidatorAbility()
	);
	window[ THEME_COMPLETION_REGISTRATION_KEY ] = registrationPromise;
	window[ ABILITIES_REGISTRATION_KEY ] = registrationPromise;

	return registrationPromise;
}

ensureThemeCompletionValidatorRegistered();
