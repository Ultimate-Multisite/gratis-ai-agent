import {
	getFrontendOnboardingEndpoint,
	hasLiveSiteChangeActivity,
	isFrontendOnboardingEnabled,
	isMobileViewport,
	probeSiteHasContent,
	shouldSendFrontendOnboardingKickoff,
	startFrontendOnboarding,
} from '../frontend-onboarding';

describe( 'frontend onboarding helpers', () => {
	beforeEach( () => {
		document.body.className = '';
		window.history.pushState( {}, '', '/' );
		Object.defineProperty( window, 'matchMedia', {
			configurable: true,
			writable: true,
			value: undefined,
		} );
	} );

	test( 'enables onboarding only for incomplete frontend pages', () => {
		expect(
			isFrontendOnboardingEnabled( {
				context: 'frontend',
				onboarding_complete: false,
			} )
		).toBe( true );
		expect(
			isFrontendOnboardingEnabled( {
				context: 'frontend',
				onboarding_complete: '',
				isFrontend: '1',
			} )
		).toBe( true );

		expect(
			isFrontendOnboardingEnabled( {
				context: 'admin',
				onboarding_complete: false,
			} )
		).toBe( false );

		expect(
			isFrontendOnboardingEnabled( {
				context: 'frontend',
				onboarding_complete: '1',
			} )
		).toBe( false );
	} );

	test( 'falls back to page context when localized context is absent', () => {
		expect(
			isFrontendOnboardingEnabled( { onboarding_complete: false } )
		).toBe( true );

		document.body.classList.add( 'wp-admin' );
		expect(
			isFrontendOnboardingEnabled( { onboarding_complete: false } )
		).toBe( false );
	} );

	test( 'probes published content using the admin-page threshold', async () => {
		await expect(
			probeSiteHasContent( jest.fn().mockResolvedValue( [ {}, {} ] ) )
		).resolves.toBe( true );

		await expect(
			probeSiteHasContent( jest.fn().mockResolvedValue( [ {} ] ) )
		).resolves.toBe( false );

		await expect(
			probeSiteHasContent( jest.fn().mockRejectedValue( new Error() ) )
		).resolves.toBe( true );
	} );

	test( 'selects the matching onboarding endpoint', () => {
		expect( getFrontendOnboardingEndpoint( true ) ).toBe(
			'/sd-ai-agent/v1/onboarding/bootstrap-start'
		);
		expect( getFrontendOnboardingEndpoint( false ) ).toBe(
			'/sd-ai-agent/v1/onboarding/theme-builder-start'
		);
	} );

	test( 'skips duplicate theme-builder kickoff on resume', () => {
		expect(
			shouldSendFrontendOnboardingKickoff( false, {
				is_fresh_start: false,
			} )
		).toBe( false );
		expect(
			shouldSendFrontendOnboardingKickoff( false, {
				is_fresh_start: true,
			} )
		).toBe( true );
		expect(
			shouldSendFrontendOnboardingKickoff( true, {
				is_fresh_start: false,
			} )
		).toBe( true );
	} );

	test( 'detects mobile viewport preference', () => {
		Object.defineProperty( window, 'matchMedia', {
			configurable: true,
			writable: true,
			value: jest.fn().mockReturnValue( { matches: true } ),
		} );

		expect( isMobileViewport() ).toBe( true );
		expect( window.matchMedia ).toHaveBeenCalledWith(
			'(max-width: 600px)'
		);
	} );

	test( 'detects live site-change activity from affected tool responses', () => {
		expect(
			hasLiveSiteChangeActivity( [
				{ type: 'call', name: 'sd-ai-agent/post-update' },
				{ type: 'response', response: { ok: true } },
			] )
		).toBe( false );

		expect(
			hasLiveSiteChangeActivity( [
				{
					type: 'response',
					response: { affected: { kind: 'post', url: '/' } },
				},
			] )
		).toBe( true );
	} );

	test( 'starts established-site onboarding from the frontend', async () => {
		const apiFetch = jest
			.fn()
			.mockResolvedValueOnce( [ {}, {} ] )
			.mockResolvedValueOnce( {
				agent_id: 7,
				session_id: 42,
				kickoff_message: 'Welcome',
			} );
		const openSession = jest.fn().mockResolvedValue( undefined );
		const sendMessage = jest.fn().mockResolvedValue( undefined );
		const setSelectedAgentId = jest.fn();

		await startFrontendOnboarding( {
			apiFetch,
			openSession,
			sendMessage,
			setSelectedAgentId,
			fallbackMessage: 'Fallback',
		} );

		expect( apiFetch ).toHaveBeenNthCalledWith( 2, {
			path: '/sd-ai-agent/v1/onboarding/bootstrap-start',
			method: 'POST',
		} );
		expect( setSelectedAgentId ).toHaveBeenCalledWith( 7 );
		expect( openSession ).toHaveBeenCalledWith( 42 );
		expect( sendMessage ).toHaveBeenCalledWith( 'Welcome' );
	} );

	test( 'resumes theme-builder onboarding without duplicate sends', async () => {
		const apiFetch = jest
			.fn()
			.mockResolvedValueOnce( [] )
			.mockResolvedValueOnce( {
				agent_id: 7,
				session_id: 42,
				kickoff_message: 'Describe the site',
				is_fresh_start: false,
			} );
		const openSession = jest.fn().mockResolvedValue( undefined );
		const sendMessage = jest.fn().mockResolvedValue( undefined );

		await startFrontendOnboarding( {
			apiFetch,
			openSession,
			sendMessage,
			setSelectedAgentId: jest.fn(),
			fallbackMessage: 'Fallback',
		} );

		expect( apiFetch ).toHaveBeenNthCalledWith( 2, {
			path: '/sd-ai-agent/v1/onboarding/theme-builder-start',
			method: 'POST',
		} );
		expect( openSession ).toHaveBeenCalledWith( 42 );
		expect( sendMessage ).not.toHaveBeenCalled();
	} );
} );
