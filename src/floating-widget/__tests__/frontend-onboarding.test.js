import {
	getHydrationSessionId,
	hasLiveSiteChangeActivity,
	isFrontendOnboardingEnabled,
	isMobileViewport,
	shouldStartFrontendOnboarding,
	startOnboarding,
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

	test( 'hydrates a running frontend build session before latest recency', () => {
		expect(
			getHydrationSessionId(
				[
					{ id: '12', title: 'Latest chat' },
					{ id: '7', title: 'Build session' },
				],
				{
					7: { status: 'processing', jobId: 'job-7' },
				}
			)
		).toBe( 7 );
	} );

	test( 'hydrates the latest session when no build is running', () => {
		expect(
			getHydrationSessionId(
				[
					{ id: '12', title: 'Latest chat' },
					{ id: '7', title: 'Older chat' },
				],
				{
					7: { status: 'complete', jobId: 'job-7' },
				}
			)
		).toBe( 12 );
	} );

	test( 'starts frontend onboarding only after sessions load empty', () => {
		expect(
			shouldStartFrontendOnboarding( {
				enabled: true,
				started: false,
				providersLoaded: true,
				providerCount: 1,
				sessionsLoaded: false,
				sessionCount: 0,
				currentSessionId: null,
			} )
		).toBe( false );

		expect(
			shouldStartFrontendOnboarding( {
				enabled: true,
				started: false,
				providersLoaded: true,
				providerCount: 1,
				sessionsLoaded: true,
				sessionCount: 1,
				currentSessionId: null,
			} )
		).toBe( false );

		expect(
			shouldStartFrontendOnboarding( {
				enabled: true,
				started: false,
				providersLoaded: true,
				providerCount: 1,
				sessionsLoaded: true,
				sessionCount: 0,
				currentSessionId: null,
			} )
		).toBe( true );
	} );

	test( 'starts unified onboarding from the frontend', async () => {
		const apiFetch = jest.fn().mockResolvedValueOnce( {
			agent_id: 7,
			session_id: 42,
			kickoff_message: 'Welcome',
		} );
		const openSession = jest.fn().mockResolvedValue( undefined );
		const sendMessage = jest.fn().mockResolvedValue( undefined );
		const setSelectedAgentId = jest.fn();

		await startOnboarding( {
			apiFetch,
			openSession,
			sendMessage,
			setSelectedAgentId,
			fallbackMessage: 'Fallback',
		} );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/sd-ai-agent/v1/onboarding/start',
			method: 'POST',
		} );
		expect( setSelectedAgentId ).toHaveBeenCalledWith( 7 );
		expect( openSession ).toHaveBeenCalledWith( 42 );
		expect( sendMessage ).toHaveBeenCalledWith( 'Welcome' );
	} );

	test( 'returns null when onboarding start omits a session id', async () => {
		const apiFetch = jest.fn().mockResolvedValueOnce( {
			agent_id: 7,
		} );
		const openSession = jest.fn().mockResolvedValue( undefined );
		const sendMessage = jest.fn().mockResolvedValue( undefined );

		await expect(
			startOnboarding( {
				apiFetch,
				openSession,
				sendMessage,
				setSelectedAgentId: jest.fn(),
				fallbackMessage: 'Fallback',
			} )
		).resolves.toBeNull();

		expect( openSession ).not.toHaveBeenCalled();
		expect( sendMessage ).not.toHaveBeenCalled();
	} );

	test( 'uses the fallback kickoff when the route omits a message', async () => {
		const apiFetch = jest.fn().mockResolvedValueOnce( {
			session_id: 42,
		} );
		const openSession = jest.fn().mockResolvedValue( undefined );
		const sendMessage = jest.fn().mockResolvedValue( undefined );

		await startOnboarding( {
			apiFetch,
			openSession,
			sendMessage,
			setSelectedAgentId: jest.fn(),
			fallbackMessage: 'Fallback',
		} );

		expect( openSession ).toHaveBeenCalledWith( 42 );
		expect( sendMessage ).toHaveBeenCalledWith( 'Fallback' );
	} );
} );
