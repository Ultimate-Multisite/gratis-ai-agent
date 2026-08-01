/**
 * Unit tests for browser-executed site navigation.
 */

/**
 * Load isolated navigation and registry modules.
 *
 * @return {{ navigation: Object, registry: Object }} Isolated module exports.
 */
function loadNavigationAndRegistry() {
	let navigation;
	let registry;
	jest.isolateModules( () => {
		// eslint-disable-next-line global-require
		navigation = require( '../navigation' );
		// eslint-disable-next-line global-require
		registry = require( '../registry' );
	} );
	return { navigation, registry };
}

describe( 'browser site navigation', () => {
	beforeEach( () => {
		delete window._sdAiAgentPendingNavigation;
		delete global.wp;
	} );

	afterEach( () => {
		delete window._sdAiAgentPendingNavigation;
	} );

	test( 'schedules a same-site URL after the client tool result is posted', async () => {
		const { navigation, registry } = loadNavigationAndRegistry();
		await navigation.registerNavigationAbility();

		await expect(
			registry.executeClientAbility( 'sd-ai-agent/navigate', {
				url: '/portfolio/',
			} )
		).resolves.toEqual( {
			navigated: true,
			url: `${ window.location.origin }/portfolio/`,
		} );
		expect( window._sdAiAgentPendingNavigation ).toBe(
			`${ window.location.origin }/portfolio/`
		);
	} );

	test( 'rejects an external URL so the agent can provide its fallback link', async () => {
		const { navigation, registry } = loadNavigationAndRegistry();
		await navigation.registerNavigationAbility();

		await expect(
			registry.executeClientAbility( 'sd-ai-agent/navigate', {
				url: 'https://example.com/',
			} )
		).rejects.toThrow( 'limited to the current WordPress site' );
		expect( window._sdAiAgentPendingNavigation ).toBeUndefined();
	} );
} );
