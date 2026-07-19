/**
 * Tests for Setup Assistant theme-completion ability registration.
 */

const mockEnsureRegistered = jest.fn( () => Promise.resolve() );
const mockRegisterThemeCompletionValidatorAbility = jest.fn( () =>
	Promise.resolve()
);

jest.mock( '../index', () => ( {
	ensureRegistered: mockEnsureRegistered,
} ) );
jest.mock( '../theme-completion-validator', () => ( {
	registerThemeCompletionValidatorAbility:
		mockRegisterThemeCompletionValidatorAbility,
} ) );

const {
	THEME_COMPLETION_REGISTRATION_KEY,
	ensureThemeCompletionValidatorRegistered,
} = require( '../theme-completion-registration' );

describe( 'theme completion registration', () => {
	beforeAll( async () => {
		await window[ THEME_COMPLETION_REGISTRATION_KEY ];
	} );

	beforeEach( () => {
		jest.clearAllMocks();
		window[ THEME_COMPLETION_REGISTRATION_KEY ] = null;
		mockEnsureRegistered.mockResolvedValue();
		mockRegisterThemeCompletionValidatorAbility.mockResolvedValue();
	} );

	test( 'reuses a successful registration promise', async () => {
		const first = ensureThemeCompletionValidatorRegistered();
		const second = ensureThemeCompletionValidatorRegistered();

		expect( second ).toBe( first );
		await first;
		expect( mockEnsureRegistered ).toHaveBeenCalledTimes( 1 );
		expect(
			mockRegisterThemeCompletionValidatorAbility
		).toHaveBeenCalledTimes( 1 );
		expect( window[ THEME_COMPLETION_REGISTRATION_KEY ] ).toBe( first );
	} );

	test( 'clears a rejected registration promise so a later call can retry', async () => {
		mockRegisterThemeCompletionValidatorAbility
			.mockRejectedValueOnce( new Error( 'registration failed' ) )
			.mockResolvedValueOnce();

		const failed = ensureThemeCompletionValidatorRegistered();
		await expect( failed ).rejects.toThrow( 'registration failed' );
		expect( window[ THEME_COMPLETION_REGISTRATION_KEY ] ).toBeNull();

		await expect(
			ensureThemeCompletionValidatorRegistered()
		).resolves.toBeUndefined();
		expect( mockEnsureRegistered ).toHaveBeenCalledTimes( 2 );
		expect(
			mockRegisterThemeCompletionValidatorAbility
		).toHaveBeenCalledTimes( 2 );
	} );
} );
