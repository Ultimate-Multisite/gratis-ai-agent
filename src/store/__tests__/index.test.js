/**
 * Unit tests for store/index.js
 *
 * Tests cover:
 * - Action creators (return correct action objects)
 * - Reducer (state transitions for each action type)
 * - Selectors (read state correctly)
 */

// Mock localStorage before importing the store.
const localStorageMock = ( () => {
	let store = {};
	return {
		getItem: ( key ) => store[ key ] ?? null,
		setItem: ( key, value ) => {
			store[ key ] = String( value );
		},
		removeItem: ( key ) => {
			delete store[ key ];
		},
		clear: () => {
			store = {};
		},
	};
} )();

Object.defineProperty( global, 'localStorage', {
	value: localStorageMock,
	writable: true,
} );

// Mock @wordpress/api-fetch.
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// Import the store module — side-effects (register) are mocked above.
// We extract the reducer, actions, and selectors from the module internals
// by re-requiring the raw source via a helper approach.
// Since the module exports only STORE_NAME, we test the internals by
// capturing what createReduxStore receives.

let capturedConfig;
jest.mock( '@wordpress/data', () => ( {
	createReduxStore: ( name, config ) => {
		capturedConfig = config;
		return { name, config };
	},
	register: jest.fn(),
	// select() is called at module load time to guard against double-registration.
	// Return null so the guard evaluates to falsy and register() is called once.
	select: jest.fn( () => null ),
} ) );

// Require after mocks are set up.
require( '../index' );

const { reducer, actions, selectors } = capturedConfig;
const { resolveProviderSelection } = require( '../slices/providersSlice' );

// ─── Default state ────────────────────────────────────────────────────────────

const DEFAULT_STATE = {
	providers: [],
	providersLoaded: false,
	providersLoading: false,
	sessions: [],
	sessionsLoaded: false,
	currentSessionId: null,
	currentSessionMessages: [],
	currentSessionToolCalls: [],
	sending: false,
	currentJobId: null,
	selectedProviderId: '',
	selectedModelId: '',
	floatingOpen: false,
	floatingMinimized: false,
	pageContext: '',
	sessionFilter: 'active',
	sessionFolder: '',
	sessionSearch: '',
	folders: [],
	foldersLoaded: false,
	settings: null,
	settingsLoaded: false,
	settingsLoading: false,
	memories: [],
	memoriesLoaded: false,
	skills: [],
	skillsLoaded: false,
	tokenUsage: { prompt: 0, completion: 0 },
	pendingConfirmation: null,
	sendTimestamp: 0,
};

// ─── Action creators ──────────────────────────────────────────────────────────

describe( 'actions', () => {
	test( 'setProviders returns correct action', () => {
		const providers = [ { id: 'openai', name: 'OpenAI' } ];
		expect( actions.setProviders( providers ) ).toEqual( {
			type: 'SET_PROVIDERS',
			providers,
		} );
	} );

	test( 'setSessions returns correct action', () => {
		const sessions = [ { id: 1, title: 'Test' } ];
		expect( actions.setSessions( sessions ) ).toEqual( {
			type: 'SET_SESSIONS',
			sessions,
		} );
	} );

	test( 'setCurrentSession returns correct action', () => {
		expect( actions.setCurrentSession( 42, [ 'msg' ], [ 'tc' ] ) ).toEqual(
			{
				type: 'SET_CURRENT_SESSION',
				sessionId: 42,
				messages: [ 'msg' ],
				toolCalls: [ 'tc' ],
			}
		);
	} );

	test( 'clearCurrentSession returns a thunk function', () => {
		expect( typeof actions.clearCurrentSession() ).toBe( 'function' );
	} );

	test( 'setSending returns correct action', () => {
		expect( actions.setSending( true ) ).toEqual( {
			type: 'SET_SENDING',
			sending: true,
		} );
	} );

	test( 'setCurrentJobId returns correct action', () => {
		expect( actions.setCurrentJobId( 'job-123' ) ).toEqual( {
			type: 'SET_CURRENT_JOB_ID',
			jobId: 'job-123',
		} );
	} );

	test( 'setSelectedProvider persists to localStorage and returns action', () => {
		const action = actions.setSelectedProvider( 'anthropic' );
		expect( action ).toEqual( {
			type: 'SET_SELECTED_PROVIDER',
			providerId: 'anthropic',
		} );
		expect( localStorage.getItem( 'sdAiAgentProvider' ) ).toBe(
			'anthropic'
		);
	} );

	test( 'setSelectedModel persists to localStorage and returns action', () => {
		const action = actions.setSelectedModel( 'claude-3' );
		expect( action ).toEqual( {
			type: 'SET_SELECTED_MODEL',
			modelId: 'claude-3',
		} );
		expect( localStorage.getItem( 'sdAiAgentModel' ) ).toBe( 'claude-3' );
	} );

	test( 'setFloatingOpen returns correct action', () => {
		expect( actions.setFloatingOpen( true ) ).toEqual( {
			type: 'SET_FLOATING_OPEN',
			open: true,
		} );
	} );

	test( 'setFloatingMinimized returns correct action', () => {
		expect( actions.setFloatingMinimized( true ) ).toEqual( {
			type: 'SET_FLOATING_MINIMIZED',
			minimized: true,
		} );
	} );

	test( 'appendMessage returns correct action', () => {
		const message = { role: 'user', parts: [ { text: 'hello' } ] };
		expect( actions.appendMessage( message ) ).toEqual( {
			type: 'APPEND_MESSAGE',
			message,
		} );
	} );

	test( 'removeLastMessage returns correct action', () => {
		expect( actions.removeLastMessage() ).toEqual( {
			type: 'REMOVE_LAST_MESSAGE',
		} );
	} );

	test( 'setSettings returns correct action', () => {
		const settings = { max_tokens: 4096 };
		expect( actions.setSettings( settings ) ).toEqual( {
			type: 'SET_SETTINGS',
			settings,
		} );
	} );

	test( 'setMemories returns correct action', () => {
		const memories = [ { id: 1, content: 'fact' } ];
		expect( actions.setMemories( memories ) ).toEqual( {
			type: 'SET_MEMORIES',
			memories,
		} );
	} );

	test( 'setSkills returns correct action', () => {
		const skills = [ { id: 1, name: 'skill' } ];
		expect( actions.setSkills( skills ) ).toEqual( {
			type: 'SET_SKILLS',
			skills,
		} );
	} );

	test( 'setTokenUsage returns correct action', () => {
		expect(
			actions.setTokenUsage( { prompt: 100, completion: 50 } )
		).toEqual( {
			type: 'SET_TOKEN_USAGE',
			tokenUsage: { prompt: 100, completion: 50 },
		} );
	} );

	test( 'setSessionFilter returns correct action', () => {
		expect( actions.setSessionFilter( 'archived' ) ).toEqual( {
			type: 'SET_SESSION_FILTER',
			filter: 'archived',
		} );
	} );

	test( 'setSessionFolder returns correct action', () => {
		expect( actions.setSessionFolder( 'work' ) ).toEqual( {
			type: 'SET_SESSION_FOLDER',
			folder: 'work',
		} );
	} );

	test( 'setSessionSearch returns correct action', () => {
		expect( actions.setSessionSearch( 'query' ) ).toEqual( {
			type: 'SET_SESSION_SEARCH',
			search: 'query',
		} );
	} );

	test( 'setFolders returns correct action', () => {
		const folders = [ 'work', 'personal' ];
		expect( actions.setFolders( folders ) ).toEqual( {
			type: 'SET_FOLDERS',
			folders,
		} );
	} );

	test( 'setPendingConfirmation returns correct action', () => {
		const confirmation = { jobId: 'j1', tools: [] };
		expect( actions.setPendingConfirmation( confirmation ) ).toEqual( {
			type: 'SET_PENDING_CONFIRMATION',
			confirmation,
		} );
	} );

	test( 'truncateMessagesTo returns correct action', () => {
		expect( actions.truncateMessagesTo( 3 ) ).toEqual( {
			type: 'TRUNCATE_MESSAGES_TO',
			index: 3,
		} );
	} );

	test( 'setSendTimestamp returns correct action', () => {
		expect( actions.setSendTimestamp( 1234567890 ) ).toEqual( {
			type: 'SET_SEND_TIMESTAMP',
			ts: 1234567890,
		} );
	} );

	test( 'setPageContext returns correct action', () => {
		expect( actions.setPageContext( 'page-ctx' ) ).toEqual( {
			type: 'SET_PAGE_CONTEXT',
			context: 'page-ctx',
		} );
	} );

	// Thunks return functions — verify they are callable.
	test( 'fetchProviders returns a thunk function', () => {
		expect( typeof actions.fetchProviders() ).toBe( 'function' );
	} );

	test( 'fetchSessions returns a thunk function', () => {
		expect( typeof actions.fetchSessions() ).toBe( 'function' );
	} );

	test( 'sendMessage returns a thunk function', () => {
		expect( typeof actions.sendMessage( 'hello' ) ).toBe( 'function' );
	} );
} );

describe( 'resolveProviderSelection', () => {
	test( 'replaces a stale saved model with the provider default model', () => {
		const selection = resolveProviderSelection(
			[
				{
					id: 'sd-ai-agent-cloud',
					default_model: 'superdav-chat-pro',
					models: [
						{ id: 'superdav-chat-fast' },
						{ id: 'superdav-chat-pro' },
					],
				},
			],
			'sd-ai-agent-cloud',
			'chat-fast'
		);

		expect( selection ).toEqual( {
			providerId: 'sd-ai-agent-cloud',
			modelId: 'superdav-chat-pro',
		} );
	} );

	test( 'keeps a saved model when it is still advertised', () => {
		const selection = resolveProviderSelection(
			[
				{
					id: 'sd-ai-agent-cloud',
					default_model: 'superdav-chat-pro',
					models: [
						{ id: 'superdav-chat-fast' },
						{ id: 'superdav-chat-pro' },
					],
				},
			],
			'sd-ai-agent-cloud',
			'superdav-chat-fast'
		);

		expect( selection ).toEqual( {
			providerId: 'sd-ai-agent-cloud',
			modelId: 'superdav-chat-fast',
		} );
	} );

	test( 'moves away from a saved provider that has no advertised models', () => {
		const selection = resolveProviderSelection(
			[
				{
					id: 'ollama',
					models: [],
				},
				{
					id: 'sd-ai-agent-cloud',
					default_model: 'superdav-chat-pro',
					models: [
						{ id: 'superdav-chat-fast' },
						{ id: 'superdav-chat-pro' },
					],
				},
			],
			'ollama',
			''
		);

		expect( selection ).toEqual( {
			providerId: 'sd-ai-agent-cloud',
			modelId: 'superdav-chat-pro',
		} );
	} );
} );

// ─── Reducer ──────────────────────────────────────────────────────────────────

describe( 'reducer', () => {
	test( 'returns default state for unknown action', () => {
		const state = reducer( undefined, { type: '@@INIT' } );
		expect( state.providers ).toEqual( [] );
		expect( state.sending ).toBe( false );
		expect( state.floatingOpen ).toBe( false );
	} );

	test( 'SET_PROVIDERS sets providers and marks loaded', () => {
		const providers = [ { id: 'openai' } ];
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_PROVIDERS',
			providers,
		} );
		expect( state.providers ).toEqual( providers );
		expect( state.providersLoaded ).toBe( true );
	} );

	test( 'SET_PROVIDERS_LOADING sets providersLoading flag', () => {
		const loading = reducer( DEFAULT_STATE, {
			type: 'SET_PROVIDERS_LOADING',
			loading: true,
		} );
		expect( loading.providersLoading ).toBe( true );

		const done = reducer( loading, {
			type: 'SET_PROVIDERS_LOADING',
			loading: false,
		} );
		expect( done.providersLoading ).toBe( false );
	} );

	test( 'SET_SESSIONS sets sessions and marks loaded', () => {
		const sessions = [ { id: 1 } ];
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SESSIONS',
			sessions,
		} );
		expect( state.sessions ).toEqual( sessions );
		expect( state.sessionsLoaded ).toBe( true );
	} );

	test( 'SET_SESSIONS merges pendingTitles into incoming sessions', () => {
		// Simulate the state after updateSessionTitle() fired: pendingTitles has
		// the generated title, but the server-side session still says "Untitled".
		const stateWithPending = {
			...DEFAULT_STATE,
			pendingTitles: { 5: 'My Generated Title' },
		};
		const serverSessions = [ { id: 5, title: 'Untitled' } ];
		const state = reducer( stateWithPending, {
			type: 'SET_SESSIONS',
			sessions: serverSessions,
		} );
		// The optimistic title should survive the fetchSessions() round-trip.
		expect( state.sessions[ 0 ].title ).toBe( 'My Generated Title' );
		// pendingTitles is cleared after merging.
		expect( state.pendingTitles ).toEqual( {} );
	} );

	test( 'SET_SESSIONS clears pendingTitles after merging', () => {
		const stateWithPending = {
			...DEFAULT_STATE,
			pendingTitles: { 3: 'Some Title' },
		};
		const state = reducer( stateWithPending, {
			type: 'SET_SESSIONS',
			sessions: [],
		} );
		expect( state.pendingTitles ).toEqual( {} );
	} );

	test( 'SET_SESSIONS normalizes string IDs to integers', () => {
		const sessions = [
			{ id: '42', title: 'Test' },
			{ id: 7, title: 'Already int' },
		];
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SESSIONS',
			sessions,
		} );
		expect( state.sessions[ 0 ].id ).toBe( 42 );
		expect( state.sessions[ 1 ].id ).toBe( 7 );
	} );

	test( 'SET_CURRENT_SESSION normalizes string sessionId to integer', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_CURRENT_SESSION',
			sessionId: '15',
			messages: [],
			toolCalls: [],
		} );
		expect( state.currentSessionId ).toBe( 15 );
	} );

	test( 'UPDATE_SESSION_TITLE records title in pendingTitles', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'UPDATE_SESSION_TITLE',
			sessionId: 7,
			title: 'Auto Title',
		} );
		expect( state.pendingTitles[ 7 ] ).toBe( 'Auto Title' );
	} );

	test( 'SET_CURRENT_SESSION sets session id, messages, toolCalls', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_CURRENT_SESSION',
			sessionId: 7,
			messages: [ { role: 'user' } ],
			toolCalls: [ { type: 'call' } ],
		} );
		expect( state.currentSessionId ).toBe( 7 );
		expect( state.currentSessionMessages ).toEqual( [ { role: 'user' } ] );
		expect( state.currentSessionToolCalls ).toEqual( [ { type: 'call' } ] );
	} );

	test( 'CLEAR_CURRENT_SESSION resets session state and token usage', () => {
		const populated = {
			...DEFAULT_STATE,
			currentSessionId: 5,
			currentSessionMessages: [ { role: 'user' } ],
			currentSessionToolCalls: [ {} ],
			tokenUsage: { prompt: 100, completion: 50 },
		};
		const state = reducer( populated, { type: 'CLEAR_CURRENT_SESSION' } );
		expect( state.currentSessionId ).toBeNull();
		expect( state.currentSessionMessages ).toEqual( [] );
		expect( state.currentSessionToolCalls ).toEqual( [] );
		expect( state.tokenUsage ).toEqual( { prompt: 0, completion: 0 } );
	} );

	test( 'SET_SENDING updates sending flag', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SENDING',
			sending: true,
		} );
		expect( state.sending ).toBe( true );
	} );

	test( 'SET_CURRENT_JOB_ID updates jobId', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_CURRENT_JOB_ID',
			jobId: 'abc',
		} );
		expect( state.currentJobId ).toBe( 'abc' );
	} );

	test( 'SET_SELECTED_PROVIDER updates selectedProviderId', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SELECTED_PROVIDER',
			providerId: 'anthropic',
		} );
		expect( state.selectedProviderId ).toBe( 'anthropic' );
	} );

	test( 'SET_SELECTED_MODEL updates selectedModelId', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SELECTED_MODEL',
			modelId: 'claude-3',
		} );
		expect( state.selectedModelId ).toBe( 'claude-3' );
	} );

	test( 'SET_FLOATING_OPEN updates floatingOpen', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_FLOATING_OPEN',
			open: true,
		} );
		expect( state.floatingOpen ).toBe( true );
	} );

	test( 'SET_FLOATING_MINIMIZED updates floatingMinimized', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_FLOATING_MINIMIZED',
			minimized: true,
		} );
		expect( state.floatingMinimized ).toBe( true );
	} );

	test( 'SET_PAGE_CONTEXT updates pageContext', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_PAGE_CONTEXT',
			context: 'my-page',
		} );
		expect( state.pageContext ).toBe( 'my-page' );
	} );

	test( 'APPEND_MESSAGE appends to currentSessionMessages', () => {
		const msg = { role: 'user', parts: [ { text: 'hi' } ] };
		const state = reducer( DEFAULT_STATE, {
			type: 'APPEND_MESSAGE',
			message: msg,
		} );
		expect( state.currentSessionMessages ).toHaveLength( 1 );
		expect( state.currentSessionMessages[ 0 ] ).toEqual( msg );
	} );

	test( 'APPEND_MESSAGE preserves existing messages', () => {
		const existing = { role: 'user', parts: [ { text: 'first' } ] };
		const populated = {
			...DEFAULT_STATE,
			currentSessionMessages: [ existing ],
		};
		const msg = { role: 'model', parts: [ { text: 'reply' } ] };
		const state = reducer( populated, {
			type: 'APPEND_MESSAGE',
			message: msg,
		} );
		expect( state.currentSessionMessages ).toHaveLength( 2 );
		expect( state.currentSessionMessages[ 1 ] ).toEqual( msg );
	} );

	test( 'REMOVE_LAST_MESSAGE removes the last message', () => {
		const populated = {
			...DEFAULT_STATE,
			currentSessionMessages: [
				{ role: 'user', parts: [ { text: 'a' } ] },
				{ role: 'model', parts: [ { text: 'b' } ] },
			],
		};
		const state = reducer( populated, { type: 'REMOVE_LAST_MESSAGE' } );
		expect( state.currentSessionMessages ).toHaveLength( 1 );
		expect( state.currentSessionMessages[ 0 ].role ).toBe( 'user' );
	} );

	test( 'SET_SETTINGS sets settings and marks loaded', () => {
		const settings = { max_tokens: 4096 };
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SETTINGS',
			settings,
		} );
		expect( state.settings ).toEqual( settings );
		expect( state.settingsLoaded ).toBe( true );
	} );

	test( 'SET_SETTINGS_LOADING sets settingsLoading flag', () => {
		const loading = reducer( DEFAULT_STATE, {
			type: 'SET_SETTINGS_LOADING',
			loading: true,
		} );
		expect( loading.settingsLoading ).toBe( true );

		const done = reducer( loading, {
			type: 'SET_SETTINGS_LOADING',
			loading: false,
		} );
		expect( done.settingsLoading ).toBe( false );
	} );

	test( 'SET_MEMORIES sets memories and marks loaded', () => {
		const memories = [ { id: 1 } ];
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_MEMORIES',
			memories,
		} );
		expect( state.memories ).toEqual( memories );
		expect( state.memoriesLoaded ).toBe( true );
	} );

	test( 'SET_SKILLS sets skills and marks loaded', () => {
		const skills = [ { id: 1 } ];
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SKILLS',
			skills,
		} );
		expect( state.skills ).toEqual( skills );
		expect( state.skillsLoaded ).toBe( true );
	} );

	test( 'SET_TOKEN_USAGE updates tokenUsage', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_TOKEN_USAGE',
			tokenUsage: { prompt: 200, completion: 100 },
		} );
		expect( state.tokenUsage ).toEqual( { prompt: 200, completion: 100 } );
	} );

	test( 'SET_SESSION_FILTER updates sessionFilter', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SESSION_FILTER',
			filter: 'archived',
		} );
		expect( state.sessionFilter ).toBe( 'archived' );
	} );

	test( 'SET_SESSION_FOLDER updates sessionFolder', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SESSION_FOLDER',
			folder: 'work',
		} );
		expect( state.sessionFolder ).toBe( 'work' );
	} );

	test( 'SET_SESSION_SEARCH updates sessionSearch', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SESSION_SEARCH',
			search: 'query',
		} );
		expect( state.sessionSearch ).toBe( 'query' );
	} );

	test( 'SET_FOLDERS sets folders and marks loaded', () => {
		const folders = [ 'work', 'personal' ];
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_FOLDERS',
			folders,
		} );
		expect( state.folders ).toEqual( folders );
		expect( state.foldersLoaded ).toBe( true );
	} );

	test( 'SET_PENDING_CONFIRMATION sets pendingConfirmation', () => {
		const confirmation = { jobId: 'j1', tools: [] };
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_PENDING_CONFIRMATION',
			confirmation,
		} );
		expect( state.pendingConfirmation ).toEqual( confirmation );
	} );

	test( 'TRUNCATE_MESSAGES_TO slices messages to given index', () => {
		const populated = {
			...DEFAULT_STATE,
			currentSessionMessages: [
				{ role: 'user', parts: [ { text: 'a' } ] },
				{ role: 'model', parts: [ { text: 'b' } ] },
				{ role: 'user', parts: [ { text: 'c' } ] },
			],
		};
		const state = reducer( populated, {
			type: 'TRUNCATE_MESSAGES_TO',
			index: 1,
		} );
		expect( state.currentSessionMessages ).toHaveLength( 1 );
		expect( state.currentSessionMessages[ 0 ].parts[ 0 ].text ).toBe( 'a' );
	} );

	test( 'SET_SEND_TIMESTAMP updates sendTimestamp', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SEND_TIMESTAMP',
			ts: 9999,
		} );
		expect( state.sendTimestamp ).toBe( 9999 );
	} );

	test( 'unknown action returns state unchanged', () => {
		const state = reducer( DEFAULT_STATE, { type: 'UNKNOWN_ACTION' } );
		expect( state ).toBe( DEFAULT_STATE );
	} );
} );

// ─── Selectors ────────────────────────────────────────────────────────────────

describe( 'selectors', () => {
	const state = {
		...DEFAULT_STATE,
		providers: [
			{ id: 'openai', name: 'OpenAI', models: [ { id: 'gpt-4o' } ] },
		],
		providersLoaded: true,
		sessions: [ { id: 1 } ],
		sessionsLoaded: true,
		currentSessionId: 42,
		currentSessionMessages: [ { role: 'user' } ],
		currentSessionToolCalls: [ { type: 'call' } ],
		sending: true,
		currentJobId: 'job-1',
		selectedProviderId: 'openai',
		selectedModelId: 'gpt-4o',
		floatingOpen: true,
		floatingMinimized: false,
		pageContext: 'ctx',
		settings: { max_tokens: 4096 },
		settingsLoaded: true,
		memories: [ { id: 1 } ],
		memoriesLoaded: true,
		skills: [ { id: 1 } ],
		skillsLoaded: true,
		tokenUsage: { prompt: 1000, completion: 500 },
		sessionFilter: 'archived',
		sessionFolder: 'work',
		sessionSearch: 'query',
		folders: [ 'work' ],
		foldersLoaded: true,
		pendingConfirmation: { jobId: 'j1' },
		sendTimestamp: 12345,
	};

	test( 'getProviders returns providers array', () => {
		expect( selectors.getProviders( state ) ).toEqual( state.providers );
	} );

	test( 'getProvidersLoaded returns true when loaded', () => {
		expect( selectors.getProvidersLoaded( state ) ).toBe( true );
	} );

	test( 'getProvidersLoading returns false when not loading', () => {
		expect( selectors.getProvidersLoading( state ) ).toBe( false );
	} );

	test( 'getSessions returns sessions array', () => {
		expect( selectors.getSessions( state ) ).toEqual( state.sessions );
	} );

	test( 'getSessionsLoaded returns true when loaded', () => {
		expect( selectors.getSessionsLoaded( state ) ).toBe( true );
	} );

	test( 'getCurrentSessionId returns current session id', () => {
		expect( selectors.getCurrentSessionId( state ) ).toBe( 42 );
	} );

	test( 'getCurrentSessionMessages returns messages', () => {
		expect( selectors.getCurrentSessionMessages( state ) ).toEqual(
			state.currentSessionMessages
		);
	} );

	test( 'getCurrentSessionToolCalls returns tool calls', () => {
		expect( selectors.getCurrentSessionToolCalls( state ) ).toEqual(
			state.currentSessionToolCalls
		);
	} );

	test( 'isSending returns sending flag', () => {
		expect( selectors.isSending( state ) ).toBe( true );
	} );

	test( 'getCurrentJobId returns job id', () => {
		expect( selectors.getCurrentJobId( state ) ).toBe( 'job-1' );
	} );

	test( 'getSelectedProviderId returns selected provider id', () => {
		expect( selectors.getSelectedProviderId( state ) ).toBe( 'openai' );
	} );

	test( 'getSelectedModelId returns selected model id', () => {
		expect( selectors.getSelectedModelId( state ) ).toBe( 'gpt-4o' );
	} );

	test( 'getSelectedProviderModels returns models for selected provider', () => {
		expect( selectors.getSelectedProviderModels( state ) ).toEqual( [
			{ id: 'gpt-4o' },
		] );
	} );

	test( 'getSelectedProviderModels returns empty array when provider not found', () => {
		const noProviderState = { ...state, selectedProviderId: 'unknown' };
		expect(
			selectors.getSelectedProviderModels( noProviderState )
		).toEqual( [] );
	} );

	test( 'isFloatingOpen returns floatingOpen', () => {
		expect( selectors.isFloatingOpen( state ) ).toBe( true );
	} );

	test( 'isFloatingMinimized returns floatingMinimized', () => {
		expect( selectors.isFloatingMinimized( state ) ).toBe( false );
	} );

	test( 'getPageContext returns pageContext', () => {
		expect( selectors.getPageContext( state ) ).toBe( 'ctx' );
	} );

	test( 'getSettings returns settings', () => {
		expect( selectors.getSettings( state ) ).toEqual( state.settings );
	} );

	test( 'getSettingsLoaded returns true when loaded', () => {
		expect( selectors.getSettingsLoaded( state ) ).toBe( true );
	} );

	test( 'getSettingsLoading returns false when not loading', () => {
		expect( selectors.getSettingsLoading( state ) ).toBe( false );
	} );

	test( 'getMemories returns memories', () => {
		expect( selectors.getMemories( state ) ).toEqual( state.memories );
	} );

	test( 'getMemoriesLoaded returns true when loaded', () => {
		expect( selectors.getMemoriesLoaded( state ) ).toBe( true );
	} );

	test( 'getSkills returns skills', () => {
		expect( selectors.getSkills( state ) ).toEqual( state.skills );
	} );

	test( 'getSkillsLoaded returns true when loaded', () => {
		expect( selectors.getSkillsLoaded( state ) ).toBe( true );
	} );

	test( 'getTokenUsage returns tokenUsage', () => {
		expect( selectors.getTokenUsage( state ) ).toEqual( {
			prompt: 1000,
			completion: 500,
		} );
	} );

	test( 'getSessionFilter returns sessionFilter', () => {
		expect( selectors.getSessionFilter( state ) ).toBe( 'archived' );
	} );

	test( 'getSessionFolder returns sessionFolder', () => {
		expect( selectors.getSessionFolder( state ) ).toBe( 'work' );
	} );

	test( 'getSessionSearch returns sessionSearch', () => {
		expect( selectors.getSessionSearch( state ) ).toBe( 'query' );
	} );

	test( 'getFolders returns folders', () => {
		expect( selectors.getFolders( state ) ).toEqual( [ 'work' ] );
	} );

	test( 'getFoldersLoaded returns true when loaded', () => {
		expect( selectors.getFoldersLoaded( state ) ).toBe( true );
	} );

	test( 'getPendingConfirmation returns pendingConfirmation', () => {
		expect( selectors.getPendingConfirmation( state ) ).toEqual( {
			jobId: 'j1',
		} );
	} );

	test( 'getSendTimestamp returns sendTimestamp', () => {
		expect( selectors.getSendTimestamp( state ) ).toBe( 12345 );
	} );

	test( 'getContextPercentage calculates percentage from known model', () => {
		// gpt-4o has 128000 context window; prompt=1000 → ~0.78%
		const pct = selectors.getContextPercentage( state );
		expect( pct ).toBeCloseTo( ( 1000 / 128000 ) * 100, 2 );
	} );

	test( 'getContextPercentage uses settings fallback when model unknown', () => {
		const s = {
			...state,
			selectedModelId: 'unknown-model',
			settings: { context_window_default: 64000 },
			tokenUsage: { prompt: 32000, completion: 0 },
		};
		expect( selectors.getContextPercentage( s ) ).toBeCloseTo( 50, 1 );
	} );

	test( 'isContextWarning returns false when below 80%', () => {
		expect( selectors.isContextWarning( state ) ).toBe( false );
	} );

	test( 'isContextWarning returns true when above 80%', () => {
		const highUsage = {
			...state,
			selectedModelId: 'gpt-4o',
			tokenUsage: { prompt: 110000, completion: 0 },
		};
		expect( selectors.isContextWarning( highUsage ) ).toBe( true );
	} );
} );
