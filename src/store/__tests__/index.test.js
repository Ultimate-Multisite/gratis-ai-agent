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

// Client ability execution is exercised through the job polling thunk below.
jest.mock( '../../abilities/registry', () => ( {
	executeClientAbility: jest.fn(),
	registerCategory: jest.fn().mockResolvedValue(),
	registerClientAbility: jest.fn().mockResolvedValue(),
	snapshotDescriptors: jest.fn().mockResolvedValue( [] ),
} ) );

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
const {
	resolveProviderSelection,
	preserveRecoverableProviderModels,
} = require( '../slices/providersSlice' );
const { buildFailedJobActivityMessage } = require( '../slices/jobSlice' );
const {
	buildActiveJobFailureCard,
	normalizeActiveJobFailureDiagnostic,
} = require( '../slices/active-job-failure-diagnostic' );
const apiFetch = require( '@wordpress/api-fetch' );
const { executeClientAbility } = require( '../../abilities/registry' );
const clientToolRunner = require( '../slices/client-tool-runner' );

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
	isNewChatPending: false,
	sending: false,
	streamError: false,
	streamErrorSessionId: null,
	lastUserMessage: '',
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

	test( 'setPendingToolResultRetry returns correct action', () => {
		const retry = { sessionId: 7, jobId: 'job-1', toolResults: [] };
		expect( actions.setPendingToolResultRetry( retry ) ).toEqual( {
			type: 'SET_PENDING_TOOL_RESULT_RETRY',
			data: retry,
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

	test( 'setStreamError scopes the error to a session', () => {
		expect( actions.setStreamError( true, 42 ) ).toEqual( {
			type: 'SET_STREAM_ERROR',
			error: true,
			sessionId: 42,
		} );
	} );

	test( 'setLastUserMessage stores retry text', () => {
		expect( actions.setLastUserMessage( 'retry me' ) ).toEqual( {
			type: 'SET_LAST_USER_MESSAGE',
			message: 'retry me',
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

	test( 'Trash bulk actions call their endpoints and refresh sessions', async () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( { updated: 2 } );
		const dispatch = {
			fetchSessions: jest.fn(),
			clearCurrentSession: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 4 ),
			getSessions: jest.fn( () => [ { id: 4 } ] ),
		};

		await actions.bulkSessionAction(
			[ 4, 5 ],
			'restore'
		)( {
			dispatch,
			select,
		} );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/sd-ai-agent/v1/sessions/bulk',
			method: 'POST',
			data: { ids: [ 4, 5 ], action: 'restore' },
		} );

		await actions.bulkSessionAction(
			[ 4, 5 ],
			'delete'
		)( {
			dispatch,
			select,
		} );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/sd-ai-agent/v1/sessions/bulk',
			method: 'POST',
			data: { ids: [ 4, 5 ], action: 'delete' },
		} );

		dispatch.clearCurrentSession.mockClear();
		await actions.emptySessionTrash()( { dispatch, select } );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/sd-ai-agent/v1/sessions/trash',
			method: 'DELETE',
		} );
		expect( dispatch.clearCurrentSession ).toHaveBeenCalledTimes( 1 );

		select.getCurrentSessionId.mockReturnValue( 9 );
		await actions.emptySessionTrash()( { dispatch, select } );
		expect( dispatch.clearCurrentSession ).toHaveBeenCalledTimes( 1 );
		expect( dispatch.fetchSessions ).toHaveBeenCalledTimes( 4 );
	} );

	test( 'sendMessage returns a thunk function', () => {
		expect( typeof actions.sendMessage( 'hello' ) ).toBe( 'function' );
	} );

	test( 'retryClientToolSubmission returns a thunk function', () => {
		expect( typeof actions.retryClientToolSubmission() ).toBe( 'function' );
	} );

	test( 'resumeRecoverableJob returns a thunk function', () => {
		expect( typeof actions.resumeRecoverableJob() ).toBe( 'function' );
	} );

	test( 'compactConversation uses the server compact endpoint without resending transcript', async () => {
		apiFetch.mockReset();
		const compactedMessages = [
			{
				role: 'user',
				parts: [ { text: 'Server compacted context' } ],
			},
		];
		apiFetch.mockResolvedValue( {
			id: '77',
			messages: compactedMessages,
			tool_calls: [],
		} );

		const dispatch = {
			setCurrentSession: jest.fn(),
			setTokenUsage: jest.fn(),
			resetSessionTokens: jest.fn(),
			fetchSessions: jest.fn(),
			sendMessage: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 12 ),
			getSelectedProviderId: jest.fn( () => 'openai' ),
			getSelectedModelId: jest.fn( () => 'gpt-test' ),
			getCurrentSessionMessages: jest.fn( () => [
				{ role: 'user', parts: [ { text: 'Do not resubmit me' } ] },
			] ),
		};

		const compacted = await actions.compactConversation()( {
			dispatch,
			select,
		} );

		expect( compacted ).toBe( true );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/sd-ai-agent/v1/sessions/12/compact',
			method: 'POST',
			data: {
				provider_id: 'openai',
				model_id: 'gpt-test',
			},
		} );
		expect( select.getCurrentSessionMessages ).not.toHaveBeenCalled();
		expect( dispatch.setCurrentSession ).toHaveBeenCalledWith(
			77,
			compactedMessages,
			[]
		);
		expect( dispatch.setTokenUsage ).toHaveBeenCalledWith( {
			prompt: 0,
			completion: 0,
		} );
		expect( dispatch.resetSessionTokens ).toHaveBeenCalled();
		expect( dispatch.fetchSessions ).toHaveBeenCalled();
		expect( dispatch.sendMessage ).not.toHaveBeenCalled();
	} );

	test( 'compactConversation returns a display-safe failure message', async () => {
		apiFetch.mockReset();
		apiFetch.mockRejectedValue(
			new Error( 'Compaction service unavailable' )
		);
		const dispatch = {};
		const select = {
			getCurrentSessionId: jest.fn( () => 12 ),
			getSelectedProviderId: jest.fn( () => 'openai' ),
			getSelectedModelId: jest.fn( () => 'gpt-test' ),
		};

		const result = await actions.compactConversation()( {
			dispatch,
			select,
		} );

		expect( result ).toEqual( {
			error: 'Compaction service unavailable',
		} );
	} );

	test( 'retryLastMessage rewinds to the last user message and resends it', async () => {
		const dispatch = {
			truncateMessagesTo: jest.fn(),
			setStreamError: jest.fn(),
			streamMessage: jest.fn(),
		};
		const select = {
			getCurrentSessionMessages: jest.fn( () => [
				{ role: 'model', parts: [ { text: 'Previous reply' } ] },
				{
					role: 'user',
					parts: [ { text: 'Retry this' } ],
					attachments: [ { name: 'image.png', isImage: true } ],
				},
				{ role: 'system', parts: [ { text: 'Error: failed' } ] },
			] ),
			getLastUserMessage: jest.fn( () => 'fallback' ),
		};

		await actions.retryLastMessage()( { dispatch, select } );

		expect( dispatch.truncateMessagesTo ).toHaveBeenCalledWith( 1 );
		expect( dispatch.setStreamError ).toHaveBeenCalledWith( false );
		expect( dispatch.streamMessage ).toHaveBeenCalledWith( 'Retry this', [
			{ name: 'image.png', isImage: true },
		] );
	} );

	test( 'retryLastMessage does not delete messages when no user message exists', async () => {
		const dispatch = {
			truncateMessagesTo: jest.fn(),
			setStreamError: jest.fn(),
			streamMessage: jest.fn(),
		};
		const select = {
			getCurrentSessionMessages: jest.fn( () => [
				{ role: 'system', parts: [ { text: 'Error only' } ] },
			] ),
			getLastUserMessage: jest.fn( () => '' ),
		};

		await actions.retryLastMessage()( { dispatch, select } );

		expect( dispatch.truncateMessagesTo ).not.toHaveBeenCalled();
		expect( dispatch.setStreamError ).not.toHaveBeenCalled();
		expect( dispatch.streamMessage ).not.toHaveBeenCalled();
	} );

	test( 'retryLastMessage resumes a recoverable job without replaying the user turn', async () => {
		const dispatch = {
			resumeRecoverableJob: jest.fn(),
			truncateMessagesTo: jest.fn(),
		};
		const select = {
			getPendingActionCard: jest.fn( () => ( {
				type: 'resume_recoverable_job',
				sessionId: 17,
			} ) ),
			getCurrentSessionMessages: jest.fn(),
		};

		await actions.retryLastMessage()( { dispatch, select } );

		expect( dispatch.resumeRecoverableJob ).toHaveBeenCalledTimes( 1 );
		expect( select.getCurrentSessionMessages ).not.toHaveBeenCalled();
		expect( dispatch.truncateMessagesTo ).not.toHaveBeenCalled();
	} );

	test( 'retryClientToolSubmission resubmits preserved results and resumes the same job once', async () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {} );
		const retry = {
			sessionId: 17,
			jobId: 'client-tool-job',
			toolResults: [
				{
					id: 'call-completion',
					name: 'sd-ai-agent-js/validate-theme-completion',
					result: { passed: true },
				},
			],
			toolNames: [ 'sd-ai-agent-js/validate-theme-completion' ],
		};
		let pendingRetry = retry;
		const dispatch = {
			setPendingToolResultRetry: jest.fn( ( data ) => {
				pendingRetry = data;
			} ),
			setPendingActionCard: jest.fn(),
			setSending: jest.fn(),
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
			pollJob: jest.fn(),
		};
		const select = {
			getPendingToolResultRetry: jest.fn( () => pendingRetry ),
		};

		await actions.retryClientToolSubmission()( { dispatch, select } );
		await actions.retryClientToolSubmission()( { dispatch, select } );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/sd-ai-agent/v1/chat/tool-result',
			method: 'POST',
			data: {
				session_id: 17,
				job_id: 'client-tool-job',
				tool_results: retry.toolResults,
			},
		} );
		expect( dispatch.setPendingToolResultRetry ).toHaveBeenCalledWith(
			null
		);
		expect( dispatch.setCurrentJobId ).toHaveBeenCalledWith(
			'client-tool-job'
		);
		expect( dispatch.setSessionJob ).toHaveBeenCalledWith( 17, {
			jobId: 'client-tool-job',
			toolCalls: [],
			status: 'processing',
		} );
		expect( dispatch.pollJob ).toHaveBeenCalledWith(
			'client-tool-job',
			17
		);
	} );

	test( 'retryClientToolSubmission treats a duplicate 409 response as resumed', async () => {
		apiFetch.mockReset();
		apiFetch.mockRejectedValue( {
			data: { status: 409 },
		} );
		const retry = {
			sessionId: 17,
			jobId: 'client-tool-job',
			toolResults: [
				{
					id: 'call-completion',
					name: 'sd-ai-agent-js/validate-theme-completion',
					result: { passed: true },
				},
			],
			toolNames: [ 'sd-ai-agent-js/validate-theme-completion' ],
		};
		const dispatch = {
			setPendingToolResultRetry: jest.fn(),
			setPendingActionCard: jest.fn(),
			setSending: jest.fn(),
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
			pollJob: jest.fn(),
		};
		const select = {
			getPendingToolResultRetry: jest.fn( () => retry ),
		};

		await actions.retryClientToolSubmission()( { dispatch, select } );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( dispatch.setPendingToolResultRetry ).toHaveBeenCalledWith(
			null
		);
		expect( dispatch.setPendingActionCard ).toHaveBeenCalledWith( null );
		expect( dispatch.pollJob ).toHaveBeenCalledWith(
			'client-tool-job',
			17
		);
	} );

	test( 'retryClientToolSubmission preserves results after terminal POST failures', async () => {
		apiFetch.mockReset();
		apiFetch.mockRejectedValue( new Error( 'Network unavailable' ) );
		const retry = {
			sessionId: 17,
			jobId: 'client-tool-job',
			toolResults: [
				{
					id: 'call-completion',
					name: 'sd-ai-agent-js/validate-theme-completion',
					result: { passed: true },
				},
			],
			toolNames: [ 'sd-ai-agent-js/validate-theme-completion' ],
		};
		const dispatch = {
			setPendingToolResultRetry: jest.fn(),
			setPendingActionCard: jest.fn(),
			setSending: jest.fn(),
			appendMessage: jest.fn(),
		};
		const select = {
			getPendingToolResultRetry: jest.fn( () => retry ),
		};
		const setTimeoutSpy = jest
			.spyOn( global, 'setTimeout' )
			.mockImplementation( ( callback ) => {
				callback();
				return 0;
			} );

		try {
			await actions.retryClientToolSubmission()( { dispatch, select } );
		} finally {
			setTimeoutSpy.mockRestore();
		}

		expect( apiFetch ).toHaveBeenCalledTimes( 3 );
		expect( dispatch.setPendingToolResultRetry ).toHaveBeenLastCalledWith(
			retry
		);
		expect( dispatch.setPendingActionCard ).toHaveBeenLastCalledWith( {
			type: 'retry_client_tools',
			toolNames: retry.toolNames,
		} );
		expect( dispatch.appendMessage ).toHaveBeenCalledWith(
			expect.objectContaining( {
				role: 'system',
			} )
		);
		expect( dispatch.setSending ).toHaveBeenLastCalledWith( false );
	} );

	test( 'buildFailedJobActivityMessage preserves live tool calls after errors', () => {
		const activity = [
			{
				type: 'call',
				id: 'call-1',
				name: 'wpab__sd-ai-agent__update-blocks',
				args: { post_id: 8 },
			},
			{
				type: 'response',
				id: 'call-1',
				name: 'wpab__sd-ai-agent__update-blocks',
				response: { error: 'batch_validation_failed' },
			},
		];

		const message = buildFailedJobActivityMessage( activity );

		expect( message ).toMatchObject( {
			role: 'model',
			toolCalls: activity,
		} );
		expect( message.parts[ 0 ].text ).toContain(
			'Work completed before the error is preserved below'
		);
		expect( buildFailedJobActivityMessage( [] ) ).toBeNull();
	} );

	test( 'buildActiveJobFailureCard keeps only the safe diagnostic contract', () => {
		const card = buildActiveJobFailureCard( 17, {
			reason: 'provider_timeout',
			last_safe_phase: 'before_provider_call',
			retryable: true,
			next_action: 'retry',
			correlation_id: 'job-1234abcd5678',
			message: 'PRIVATE_PROVIDER_MESSAGE',
			prompt: 'PRIVATE_PROMPT_CONTENT',
			error_context: { trace: [ '/private/path.php:99' ] },
		} );

		expect( card ).toMatchObject( {
			type: 'active_job_failure',
			sessionId: 17,
			diagnostic: {
				reason: 'provider_timeout',
				last_safe_phase: 'before_provider_call',
				retryable: true,
				next_action: 'retry',
				correlation_id: 'job-1234abcd5678',
			},
		} );
		expect( JSON.stringify( card ) ).not.toContain(
			'PRIVATE_PROVIDER_MESSAGE'
		);
		expect( JSON.stringify( card ) ).not.toContain(
			'PRIVATE_PROMPT_CONTENT'
		);
		expect( JSON.stringify( card ) ).not.toContain( '/private/path.php' );

		expect(
			normalizeActiveJobFailureDiagnostic( {
				reason: 'not-a-shipped-reason',
				next_action: 'unsafe-action',
				last_safe_phase: 'unsafe phase',
				correlation_id: 'private-correlation',
			} )
		).toEqual( {
			reason: 'unknown',
			last_safe_phase: '',
			retryable: false,
			next_action: 'contact_support',
			correlation_id: '',
		} );
	} );

	test( 'pollJob starts only one poller for the same session and job', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {
			status: 'complete',
			tool_calls: [],
		} );

		const dispatch = {
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
			setSending: jest.fn(),
			setLiveToolCalls: jest.fn(),
			drainMessageQueue: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
		};

		try {
			actions.pollJob(
				'deduplicated-job',
				17
			)( {
				dispatch,
				select,
			} );
			actions.pollJob(
				'deduplicated-job',
				17
			)( {
				dispatch,
				select,
			} );
			await jest.advanceTimersByTimeAsync( 2000 );

			expect( apiFetch ).toHaveBeenCalledTimes( 1 );
			expect( apiFetch ).toHaveBeenCalledWith( {
				path: '/sd-ai-agent/v1/job/deduplicated-job',
			} );
		} finally {
			jest.clearAllTimers();
			jest.useRealTimers();
		}
	} );

	test( 'pollJob executes only server-confirmed mutating client abilities', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		executeClientAbility.mockReset();
		executeClientAbility.mockResolvedValue( { inserted: true } );
		apiFetch.mockImplementation( ( request ) => {
			if ( request.path === '/sd-ai-agent/v1/job/client-tool-job' ) {
				return Promise.resolve( {
					status: 'awaiting_client_tools',
					pending_client_tool_calls: [
						{
							id: 'call-confirmed-insert',
							name: 'sd-ai-agent-js/insert-block',
							annotations: { readonly: false },
							user_confirmed: true,
							args: { blockName: 'core/paragraph' },
						},
						{
							id: 'call-unconfirmed-insert',
							name: 'sd-ai-agent-js/insert-block',
							annotations: { readonly: false },
							args: { blockName: 'core/image' },
						},
					],
				} );
			}

			return Promise.resolve( {} );
		} );

		const dispatch = {
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'client-tool-job' ),
		};

		try {
			actions.pollJob( 'client-tool-job', 17 )( { dispatch, select } );
			await jest.advanceTimersByTimeAsync( 2000 );

			expect( executeClientAbility ).toHaveBeenCalledTimes( 1 );
			expect( executeClientAbility ).toHaveBeenCalledWith(
				'sd-ai-agent-js/insert-block',
				{ blockName: 'core/paragraph' }
			);
			expect( apiFetch ).toHaveBeenCalledWith( {
				path: '/sd-ai-agent/v1/chat/tool-result',
				method: 'POST',
				data: {
					session_id: 17,
					job_id: 'client-tool-job',
					tool_results: [
						{
							id: 'call-confirmed-insert',
							name: 'sd-ai-agent-js/insert-block',
							result: { inserted: true },
						},
						{
							id: 'call-unconfirmed-insert',
							name: 'sd-ai-agent-js/insert-block',
							error: 'Confirmation required.',
						},
					],
				},
			} );
		} finally {
			jest.clearAllTimers();
			jest.useRealTimers();
		}
	} );

	test( 'pollJob posts normalized failures when the client tool runner rejects', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		const runnerError = new Error( 'Client tool runner failed to load.' );
		const runClientTools = jest
			.spyOn( clientToolRunner, 'runClientTools' )
			.mockRejectedValueOnce( runnerError );
		apiFetch.mockImplementation( ( request ) => {
			if ( request.path === '/sd-ai-agent/v1/job/runner-failure-job' ) {
				return Promise.resolve( {
					status: 'awaiting_client_tools',
					pending_client_tool_calls: [
						{
							id: 'runner-failure-screenshot',
							name: 'sd-ai-agent-js/screenshot-url',
							annotations: { readonly: true },
							args: { url: '/' },
						},
					],
				} );
			}

			return Promise.resolve( { status: 'processing' } );
		} );

		const dispatch = {
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'runner-failure-job' ),
		};

		try {
			actions.pollJob( 'runner-failure-job', 17 )( { dispatch, select } );
			await jest.advanceTimersByTimeAsync( 2000 );

			expect( runClientTools ).toHaveBeenCalledTimes( 1 );
			expect( apiFetch ).toHaveBeenCalledWith( {
				path: '/sd-ai-agent/v1/chat/tool-result',
				method: 'POST',
				data: {
					session_id: 17,
					job_id: 'runner-failure-job',
					tool_results: [
						{
							id: 'runner-failure-screenshot',
							name: 'sd-ai-agent-js/screenshot-url',
							error: runnerError.message,
						},
					],
				},
			} );
		} finally {
			runClientTools.mockRestore();
			jest.clearAllTimers();
			jest.useRealTimers();
		}
	} );

	test( 'pollJob waits for restored client-ability callbacks before executing or posting once', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		executeClientAbility.mockReset();
		let resolveReadiness;
		window.__sdAiAgentAbilitiesRegistering = new Promise( ( resolve ) => {
			resolveReadiness = resolve;
		} );
		executeClientAbility.mockResolvedValue( { captured: true } );
		apiFetch.mockImplementation( ( request ) => {
			if (
				request.path === '/sd-ai-agent/v1/job/restored-client-tool-job'
			) {
				return Promise.resolve( {
					status: 'awaiting_client_tools',
					pending_client_tool_calls: [
						{
							id: 'restored-screenshot',
							name: 'sd-ai-agent-js/screenshot-url',
							annotations: { readonly: true },
							args: { url: '/' },
						},
					],
				} );
			}

			return Promise.resolve( {} );
		} );

		const dispatch = {
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'restored-client-tool-job' ),
		};

		try {
			actions.pollJob(
				'restored-client-tool-job',
				17
			)( {
				dispatch,
				select,
			} );
			await jest.advanceTimersByTimeAsync( 2000 );

			expect( executeClientAbility ).not.toHaveBeenCalled();
			expect(
				apiFetch.mock.calls.filter(
					( [ request ] ) =>
						request.path === '/sd-ai-agent/v1/chat/tool-result'
				)
			).toHaveLength( 0 );

			resolveReadiness();
			await jest.advanceTimersByTimeAsync( 0 );

			expect( executeClientAbility ).toHaveBeenCalledTimes( 1 );
			expect( apiFetch ).toHaveBeenCalledWith( {
				path: '/sd-ai-agent/v1/chat/tool-result',
				method: 'POST',
				data: {
					session_id: 17,
					job_id: 'restored-client-tool-job',
					tool_results: [
						{
							id: 'restored-screenshot',
							name: 'sd-ai-agent-js/screenshot-url',
							result: { captured: true },
						},
					],
				},
			} );
		} finally {
			delete window.__sdAiAgentAbilitiesRegistering;
			jest.clearAllTimers();
			jest.useRealTimers();
		}
	} );

	test( 'pollJob posts a bounded readiness failure without executing client abilities', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		executeClientAbility.mockReset();
		let rejectReadiness;
		window.__sdAiAgentAbilitiesRegistering = new Promise(
			( _resolve, reject ) => {
				rejectReadiness = reject;
			}
		);
		apiFetch.mockImplementation( ( request ) => {
			if (
				request.path === '/sd-ai-agent/v1/job/readiness-failure-job'
			) {
				return Promise.resolve( {
					status: 'awaiting_client_tools',
					pending_client_tool_calls: [
						{
							id: 'failed-readiness-screenshot',
							name: 'sd-ai-agent-js/screenshot-url',
							annotations: { readonly: true },
							args: { url: '/' },
						},
					],
				} );
			}

			return Promise.resolve( {} );
		} );

		const dispatch = {
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'readiness-failure-job' ),
		};

		try {
			actions.pollJob(
				'readiness-failure-job',
				17
			)( {
				dispatch,
				select,
			} );
			await jest.advanceTimersByTimeAsync( 2000 );
			rejectReadiness( new Error( '' ) );
			await jest.advanceTimersByTimeAsync( 0 );

			expect( executeClientAbility ).not.toHaveBeenCalled();
			expect( apiFetch ).toHaveBeenCalledWith( {
				path: '/sd-ai-agent/v1/chat/tool-result',
				method: 'POST',
				data: {
					session_id: 17,
					job_id: 'readiness-failure-job',
					tool_results: [
						{
							id: 'failed-readiness-screenshot',
							name: 'sd-ai-agent-js/screenshot-url',
							error: 'Error',
						},
					],
				},
			} );
		} finally {
			delete window.__sdAiAgentAbilitiesRegistering;
			jest.clearAllTimers();
			jest.useRealTimers();
		}
	} );

	test( 'pollJob times out stalled client-ability registration without executing abilities', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		executeClientAbility.mockReset();
		window.__sdAiAgentAbilitiesRegistering = new Promise( () => {} );
		apiFetch.mockImplementation( ( request ) => {
			if (
				request.path === '/sd-ai-agent/v1/job/readiness-timeout-job'
			) {
				return Promise.resolve( {
					status: 'awaiting_client_tools',
					pending_client_tool_calls: [
						{
							id: 'timed-out-readiness-screenshot',
							name: 'sd-ai-agent-js/screenshot-url',
							annotations: { readonly: true },
							args: { url: '/' },
						},
					],
				} );
			}

			return Promise.resolve( {} );
		} );

		const dispatch = {
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'readiness-timeout-job' ),
		};

		try {
			actions.pollJob(
				'readiness-timeout-job',
				17
			)( {
				dispatch,
				select,
			} );
			await jest.advanceTimersByTimeAsync( 2000 );
			await jest.advanceTimersByTimeAsync( 30_000 );

			expect( executeClientAbility ).not.toHaveBeenCalled();
			expect( apiFetch ).toHaveBeenCalledWith( {
				path: '/sd-ai-agent/v1/chat/tool-result',
				method: 'POST',
				data: {
					session_id: 17,
					job_id: 'readiness-timeout-job',
					tool_results: [
						{
							id: 'timed-out-readiness-screenshot',
							name: 'sd-ai-agent-js/screenshot-url',
							error: 'Client ability registration timed out after 30 seconds.',
						},
					],
				},
			} );
		} finally {
			delete window.__sdAiAgentAbilitiesRegistering;
			jest.clearAllTimers();
			jest.useRealTimers();
		}
	} );

	test( 'pollJob gives a restored client ability its full timeout after readiness', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		executeClientAbility.mockReset();
		let resolveReadiness;
		window.__sdAiAgentAbilitiesRegistering = new Promise( ( resolve ) => {
			resolveReadiness = resolve;
		} );
		executeClientAbility.mockImplementation(
			() => new Promise( () => {} )
		);
		apiFetch.mockImplementation( ( request ) => {
			if ( request.path === '/sd-ai-agent/v1/job/readiness-window-job' ) {
				return Promise.resolve( {
					status: 'awaiting_client_tools',
					pending_client_tool_calls: [
						{
							id: 'readiness-window-screenshot',
							name: 'sd-ai-agent-js/screenshot-url',
							annotations: { readonly: true },
							args: { url: '/' },
						},
					],
				} );
			}

			return Promise.resolve( {} );
		} );

		const dispatch = {
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'readiness-window-job' ),
		};

		try {
			actions.pollJob(
				'readiness-window-job',
				17
			)( {
				dispatch,
				select,
			} );
			await jest.advanceTimersByTimeAsync( 29000 );
			resolveReadiness();
			await jest.advanceTimersByTimeAsync( 0 );
			await jest.advanceTimersByTimeAsync( 29000 );

			expect( executeClientAbility ).toHaveBeenCalledTimes( 1 );
			expect(
				apiFetch.mock.calls.filter(
					( [ request ] ) =>
						request.path === '/sd-ai-agent/v1/chat/tool-result'
				)
			).toHaveLength( 0 );

			await jest.advanceTimersByTimeAsync( 1000 );
			expect( apiFetch ).toHaveBeenCalledWith( {
				path: '/sd-ai-agent/v1/chat/tool-result',
				method: 'POST',
				data: {
					session_id: 17,
					job_id: 'readiness-window-job',
					tool_results: [
						{
							id: 'readiness-window-screenshot',
							name: 'sd-ai-agent-js/screenshot-url',
							error: 'Client tool timed out after 30 seconds.',
						},
					],
				},
			} );
		} finally {
			delete window.__sdAiAgentAbilitiesRegistering;
			jest.clearAllTimers();
			jest.useRealTimers();
		}
	} );

	test( 'pollJob posts a timeout error when a client ability never resolves', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		executeClientAbility.mockReset();
		executeClientAbility.mockImplementation(
			() => new Promise( () => {} )
		);
		apiFetch.mockImplementation( ( request ) => {
			if ( request.path === '/sd-ai-agent/v1/job/client-tool-job' ) {
				return Promise.resolve( {
					status: 'awaiting_client_tools',
					pending_client_tool_calls: [
						{
							id: 'call-screenshot',
							name: 'sd-ai-agent-js/capture-screenshot',
							annotations: { readonly: true },
							args: { fullPage: true },
						},
					],
				} );
			}

			return Promise.resolve( {} );
		} );

		const dispatch = {
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'client-tool-job' ),
		};

		try {
			actions.pollJob( 'client-tool-job', 17 )( { dispatch, select } );
			await jest.advanceTimersByTimeAsync( 32000 );

			expect( executeClientAbility ).toHaveBeenCalledWith(
				'sd-ai-agent-js/capture-screenshot',
				{ fullPage: true }
			);
			expect( apiFetch ).toHaveBeenCalledWith( {
				path: '/sd-ai-agent/v1/chat/tool-result',
				method: 'POST',
				data: {
					session_id: 17,
					job_id: 'client-tool-job',
					tool_results: [
						{
							id: 'call-screenshot',
							name: 'sd-ai-agent-js/capture-screenshot',
							error: 'Client tool timed out after 30 seconds.',
						},
					],
				},
			} );
		} finally {
			jest.clearAllTimers();
			jest.useRealTimers();
		}
	} );

	test( 'pollJob allows page-quality validation to exceed the generic client timeout', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		executeClientAbility.mockReset();
		executeClientAbility.mockImplementation(
			() =>
				new Promise( ( resolve ) =>
					setTimeout( () => resolve( { passed: true } ), 35000 )
				)
		);
		apiFetch.mockImplementation( ( request ) => {
			if ( request.path === '/sd-ai-agent/v1/job/page-quality-job' ) {
				return Promise.resolve( {
					status: 'awaiting_client_tools',
					pending_client_tool_calls: [
						{
							id: 'call-page-quality',
							name: 'sd-ai-agent-js/validate-page-quality',
							annotations: { readonly: true },
							args: { profile: 'setup' },
						},
					],
				} );
			}

			return Promise.resolve( {} );
		} );

		const dispatch = {
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'page-quality-job' ),
		};

		try {
			actions.pollJob( 'page-quality-job', 17 )( { dispatch, select } );
			await jest.advanceTimersByTimeAsync( 37000 );

			expect( apiFetch ).toHaveBeenCalledWith( {
				path: '/sd-ai-agent/v1/chat/tool-result',
				method: 'POST',
				data: {
					session_id: 17,
					job_id: 'page-quality-job',
					tool_results: [
						{
							id: 'call-page-quality',
							name: 'sd-ai-agent-js/validate-page-quality',
							result: { passed: true },
						},
					],
				},
			} );
		} finally {
			jest.clearAllTimers();
			jest.useRealTimers();
		}
	} );

	test( 'resumeRecoverableJob starts and polls the replacement job', async () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( { job_id: 'resumed-job' } );
		const dispatch = {
			setSending: jest.fn(),
			setPendingActionCard: jest.fn(),
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
			pollJob: jest.fn(),
			appendMessage: jest.fn(),
		};
		const select = {
			getPendingActionCard: jest.fn( () => ( {
				type: 'resume_recoverable_job',
				sessionId: 17,
			} ) ),
		};

		await actions.resumeRecoverableJob()( { dispatch, select } );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/sd-ai-agent/v1/sessions/17/resume',
			method: 'POST',
		} );
		expect( dispatch.setPendingActionCard ).toHaveBeenCalledWith( null );
		expect( dispatch.setCurrentJobId ).toHaveBeenCalledWith(
			'resumed-job'
		);
		expect( dispatch.setSessionJob ).toHaveBeenCalledWith( 17, {
			jobId: 'resumed-job',
			toolCalls: [],
			status: 'processing',
		} );
		expect( dispatch.pollJob ).toHaveBeenCalledWith( 'resumed-job', 17 );
	} );

	test( 'pollJob preserves live activity when an error session reload fails', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		apiFetch
			.mockResolvedValueOnce( {
				status: 'error',
				message: 'The provider interrupted this step.',
				session_id: 17,
				tool_calls: [
					{
						type: 'call',
						id: 'call-1',
						name: 'sd-ai-agent-js/capture-screenshot',
						args: { fullPage: false },
					},
				],
			} )
			.mockRejectedValueOnce( new Error( 'Session unavailable' ) );
		const dispatch = {
			appendMessage: jest.fn(),
			setPendingConfirmation: jest.fn(),
			setPendingActionCard: jest.fn(),
			setStreamError: jest.fn(),
			setSending: jest.fn(),
			setLiveToolCalls: jest.fn(),
			drainMessageQueue: jest.fn(),
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'failed-job' ),
		};

		try {
			actions.pollJob( 'failed-job', 17 )( { dispatch, select } );
			await jest.advanceTimersByTimeAsync( 2000 );
		} finally {
			jest.useRealTimers();
		}

		expect( dispatch.appendMessage ).toHaveBeenNthCalledWith(
			1,
			expect.objectContaining( {
				role: 'model',
				toolCalls: [ expect.objectContaining( { id: 'call-1' } ) ],
			} )
		);
		expect( dispatch.appendMessage ).toHaveBeenNthCalledWith(
			2,
			expect.objectContaining( { role: 'system' } )
		);
		expect( dispatch.setPendingConfirmation ).toHaveBeenCalledWith( null );
		expect( dispatch.setPendingActionCard ).toHaveBeenCalledWith( null );
	} );

	test( 'pollJob preserves a durable plan card while handling its terminal error', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		const plan = {
			plan_id: '00000000-0000-0000-0000-000000000017',
			status: 'failed',
		};
		apiFetch
			.mockResolvedValueOnce( {
				status: 'error',
				message: 'The provider interrupted this phase.',
				session_id: 17,
				durable_plan: plan,
			} )
			.mockResolvedValueOnce( {
				id: 17,
				messages: [],
				tool_calls: [],
			} );
		const dispatch = {
			appendMessage: jest.fn(),
			setCurrentSession: jest.fn(),
			setPendingConfirmation: jest.fn(),
			setPendingActionCard: jest.fn(),
			setStreamError: jest.fn(),
			setSending: jest.fn(),
			setLiveToolCalls: jest.fn(),
			drainMessageQueue: jest.fn(),
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'durable-failed-job' ),
		};

		try {
			actions.pollJob( 'durable-failed-job', 17 )( { dispatch, select } );
			await jest.advanceTimersByTimeAsync( 2000 );
			await Promise.resolve();
			await Promise.resolve();
		} finally {
			jest.useRealTimers();
		}

		expect( dispatch.setCurrentSession ).toHaveBeenCalledWith( 17, [], [] );
		expect( dispatch.appendMessage ).toHaveBeenCalledWith(
			expect.objectContaining( { role: 'system' } )
		);
		expect( dispatch.setStreamError ).toHaveBeenCalledWith( true, 17 );
		expect( dispatch.setPendingActionCard ).toHaveBeenCalledWith( {
			type: 'durable_plan',
			sessionId: 17,
			plan,
		} );
		expect( dispatch.setPendingActionCard ).not.toHaveBeenCalledWith(
			null
		);
	} );

	test( 'pollJob preserves a recoverable error as a resume action card', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {
			status: 'error',
			message: 'The provider interrupted this step.',
			recoverable: true,
		} );
		const dispatch = {
			appendMessage: jest.fn(),
			setPendingActionCard: jest.fn(),
			setStreamError: jest.fn(),
			setSending: jest.fn(),
			setLiveToolCalls: jest.fn(),
			drainMessageQueue: jest.fn(),
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'failed-job' ),
		};

		try {
			actions.pollJob( 'failed-job', 17 )( { dispatch, select } );
			await jest.advanceTimersByTimeAsync( 2000 );
		} finally {
			jest.useRealTimers();
		}

		expect( dispatch.appendMessage ).toHaveBeenCalledWith(
			expect.objectContaining( { role: 'system' } )
		);
		expect( dispatch.setPendingActionCard ).toHaveBeenCalledWith( {
			type: 'resume_recoverable_job',
			sessionId: 17,
			diagnostic: null,
		} );
	} );

	test( 'pollJob uses a safe diagnostic card instead of a raw provider error', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {
			status: 'error',
			message: 'PRIVATE_PROVIDER_MESSAGE',
			diagnostic: {
				reason: 'local_payload_guard',
				last_safe_phase: 'before_provider_call',
				retryable: false,
				next_action: 'compact',
				correlation_id: 'job-1234abcd5678',
			},
			error_context: {
				trace: [ '/private/path.php:99' ],
			},
		} );
		const dispatch = {
			appendMessage: jest.fn(),
			setPendingConfirmation: jest.fn(),
			setPendingActionCard: jest.fn(),
			setStreamError: jest.fn(),
			setSending: jest.fn(),
			setLiveToolCalls: jest.fn(),
			drainMessageQueue: jest.fn(),
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'failed-job' ),
		};

		try {
			actions.pollJob( 'failed-job', 17 )( { dispatch, select } );
			await jest.advanceTimersByTimeAsync( 2000 );
		} finally {
			jest.useRealTimers();
		}

		const cardCalls = dispatch.setPendingActionCard.mock.calls;
		const card = cardCalls[ cardCalls.length - 1 ][ 0 ];

		expect( card ).toMatchObject( {
			type: 'active_job_failure',
			sessionId: 17,
			diagnostic: {
				reason: 'local_payload_guard',
				next_action: 'compact',
			},
		} );
		expect( dispatch.setStreamError ).not.toHaveBeenCalled();
		expect( JSON.stringify( card ) ).not.toContain(
			'PRIVATE_PROVIDER_MESSAGE'
		);
		expect( JSON.stringify( card ) ).not.toContain( '/private/path.php' );
		expect(
			dispatch.appendMessage.mock.calls[ 0 ][ 0 ].parts[ 0 ].text
		).not.toContain( 'PRIVATE_PROVIDER_MESSAGE' );
		expect(
			dispatch.appendMessage.mock.calls[ 0 ][ 0 ].parts[ 0 ].text
		).not.toContain( '/private/path.php' );
	} );

	test( 'pollJob renders a managed-credit diagnostic as a purchase notice', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {
			status: 'error',
			message: 'PRIVATE_PROVIDER_MESSAGE',
			diagnostic: {
				reason: 'credit_exhausted',
				last_safe_phase: 'agent_loop',
				retryable: false,
				next_action: 'purchase_credits',
				correlation_id: 'job-1234abcd5678',
			},
		} );
		const dispatch = {
			appendMessage: jest.fn(),
			setPendingConfirmation: jest.fn(),
			setPendingActionCard: jest.fn(),
			setStreamError: jest.fn(),
			setSending: jest.fn(),
			setLiveToolCalls: jest.fn(),
			drainMessageQueue: jest.fn(),
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'failed-job' ),
			getProviders: jest.fn( () => [
				{
					id: 'sd-ai-agent-cloud',
					status: {
						account_connect_url:
							'https://account.example.test/login',
					},
				},
			] ),
		};

		try {
			actions.pollJob( 'failed-job', 17 )( { dispatch, select } );
			await jest.advanceTimersByTimeAsync( 2000 );
		} finally {
			jest.useRealTimers();
		}

		expect( dispatch.appendMessage ).toHaveBeenCalledWith(
			expect.objectContaining( {
				role: 'system',
				notice: {
					type: 'account_action',
					reason: 'credit_exhausted',
					action: 'purchase_credits',
					actionUrl: 'https://account.example.test/login',
				},
			} )
		);
		expect( dispatch.appendMessage ).toHaveBeenCalledTimes( 1 );
		// The stale terminal card is cleared, but no generic failure card replaces it.
		expect( dispatch.setPendingActionCard ).toHaveBeenLastCalledWith(
			null
		);
		expect( dispatch.setStreamError ).not.toHaveBeenCalled();
	} );

	test( 'pollJob shows max-iteration feedback from a raw diagnostic reason', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {
			status: 'error',
			message: 'PRIVATE_PROVIDER_MESSAGE',
			reason: 'max_iterations',
			diagnostic: {
				reason: 'provider_timeout',
				last_safe_phase: 'before_provider_call',
				retryable: false,
				next_action: 'contact_support',
				correlation_id: 'job-1234abcd5678',
			},
		} );
		const dispatch = {
			appendMessage: jest.fn(),
			setFeedbackBanner: jest.fn(),
			setPendingConfirmation: jest.fn(),
			setPendingActionCard: jest.fn(),
			setStreamError: jest.fn(),
			setSending: jest.fn(),
			setLiveToolCalls: jest.fn(),
			drainMessageQueue: jest.fn(),
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'failed-job' ),
		};

		try {
			actions.pollJob( 'failed-job', 17 )( { dispatch, select } );
			await jest.advanceTimersByTimeAsync( 2000 );
		} finally {
			jest.useRealTimers();
		}

		expect( dispatch.setFeedbackBanner ).toHaveBeenCalledWith( {
			exitReason: 'max_iterations',
		} );
	} );

	test( 'pollJob prioritizes a compact continuation after a local envelope rejection', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {
			status: 'error',
			message: 'This request is too large to send safely.',
			recoverable: true,
			payload_recovery: {
				action: 'compact_session',
				source_session_id: 17,
			},
		} );
		const dispatch = {
			appendMessage: jest.fn(),
			setPendingConfirmation: jest.fn(),
			setPendingActionCard: jest.fn(),
			setStreamError: jest.fn(),
			setSending: jest.fn(),
			setLiveToolCalls: jest.fn(),
			drainMessageQueue: jest.fn(),
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'payload-job' ),
		};

		try {
			actions.pollJob( 'payload-job', 17 )( { dispatch, select } );
			await jest.advanceTimersByTimeAsync( 2000 );
		} finally {
			jest.useRealTimers();
		}

		expect( dispatch.setPendingActionCard ).toHaveBeenCalledWith( {
			type: 'compact_session',
			sessionId: 17,
			sourceSessionId: 17,
		} );
	} );

	test( 'pollJob rejects compact recovery metadata for another session', async () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {
			status: 'error',
			message: 'This request is too large to send safely.',
			recoverable: true,
			payload_recovery: {
				action: 'compact_session',
				source_session_id: 99,
			},
		} );
		const dispatch = {
			appendMessage: jest.fn(),
			setPendingConfirmation: jest.fn(),
			setPendingActionCard: jest.fn(),
			setStreamError: jest.fn(),
			setSending: jest.fn(),
			setLiveToolCalls: jest.fn(),
			drainMessageQueue: jest.fn(),
			setCurrentJobId: jest.fn(),
			setSessionJob: jest.fn(),
		};
		const select = {
			getCurrentSessionId: jest.fn( () => 17 ),
			getCurrentJobId: jest.fn( () => 'mismatched-payload-job' ),
		};

		try {
			actions.pollJob(
				'mismatched-payload-job',
				17
			)( {
				dispatch,
				select,
			} );
			await jest.advanceTimersByTimeAsync( 2000 );
		} finally {
			jest.useRealTimers();
		}

		expect( dispatch.setPendingActionCard ).toHaveBeenCalledWith( {
			type: 'resume_recoverable_job',
			sessionId: 17,
			diagnostic: null,
		} );
		expect( dispatch.setPendingActionCard ).not.toHaveBeenCalledWith(
			expect.objectContaining( { type: 'compact_session' } )
		);
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
						{ id: 'superdav-chat-strong' },
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
						{ id: 'superdav-chat-strong' },
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
						{ id: 'superdav-chat-strong' },
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

	test( 'preserves a saved model during a retryable discovery outage', () => {
		const selection = resolveProviderSelection(
			[
				{
					id: 'sd-ai-agent-cloud',
					models: [],
					model_discovery: {
						state: 'retryable_unavailable',
						retryable: true,
					},
				},
				{
					id: 'openai',
					models: [ { id: 'gpt-4o' } ],
				},
			],
			'sd-ai-agent-cloud',
			'superdav-chat-pro'
		);

		expect( selection ).toEqual( {
			providerId: 'sd-ai-agent-cloud',
			modelId: 'superdav-chat-pro',
		} );
	} );

	test( 'retains last-known models only for retryable discovery failures', () => {
		const providers = preserveRecoverableProviderModels(
			[
				{
					id: 'sd-ai-agent-cloud',
					models: [ { id: 'superdav-chat-fast' } ],
				},
				{
					id: 'openai',
					models: [ { id: 'gpt-4o' } ],
				},
			],
			[
				{
					id: 'sd-ai-agent-cloud',
					models: [],
					model_discovery: {
						state: 'retryable_unavailable',
						retryable: true,
					},
				},
				{
					id: 'openai',
					models: [],
					model_discovery: {
						state: 'unavailable',
						retryable: false,
					},
				},
			]
		);

		expect( providers[ 0 ].models ).toEqual( [
			{ id: 'superdav-chat-fast' },
		] );
		expect( providers[ 1 ].models ).toEqual( [] );
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
		expect( state.isNewChatPending ).toBe( false );
	} );

	test( 'SET_CURRENT_SESSION preserves per-turn model metadata during hydration', () => {
		const messages = [
			{
				role: 'user',
				parts: [ { text: 'First turn' } ],
				provider_id: 'superdav',
				model_id: 'fast',
			},
			{
				role: 'model',
				parts: [ { text: 'First reply' } ],
				provider_id: 'superdav',
				model_id: 'fast',
			},
			{
				role: 'user',
				parts: [ { text: 'Second turn' } ],
				provider_id: 'superdav',
				model_id: 'pro',
			},
		];
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_CURRENT_SESSION',
			sessionId: 7,
			messages,
			toolCalls: [],
		} );

		expect( state.currentSessionMessages[ 0 ].model_id ).toBe( 'fast' );
		expect( state.currentSessionMessages[ 2 ].model_id ).toBe( 'pro' );
	} );

	test( 'CLEAR_CURRENT_SESSION resets session state and token usage', () => {
		const populated = {
			...DEFAULT_STATE,
			currentSessionId: 5,
			currentSessionMessages: [ { role: 'user' } ],
			currentSessionToolCalls: [ {} ],
			tokenUsage: { prompt: 100, completion: 50 },
			streamError: true,
			streamErrorSessionId: 5,
			lastUserMessage: 'hello',
		};
		const state = reducer( populated, { type: 'CLEAR_CURRENT_SESSION' } );
		expect( state.currentSessionId ).toBeNull();
		expect( state.currentSessionMessages ).toEqual( [] );
		expect( state.currentSessionToolCalls ).toEqual( [] );
		expect( state.tokenUsage ).toEqual( { prompt: 0, completion: 0 } );
		expect( state.streamError ).toBe( false );
		expect( state.streamErrorSessionId ).toBeNull();
		expect( state.lastUserMessage ).toBe( '' );
		expect( state.isNewChatPending ).toBe( true );
	} );

	test( 'SET_SENDING updates sending flag', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SENDING',
			sending: true,
		} );
		expect( state.sending ).toBe( true );
	} );

	test( 'SET_STREAM_ERROR records the current session when no session is provided', () => {
		const state = reducer(
			{ ...DEFAULT_STATE, currentSessionId: 42 },
			{ type: 'SET_STREAM_ERROR', error: true, sessionId: null }
		);

		expect( state.streamError ).toBe( true );
		expect( state.streamErrorSessionId ).toBe( 42 );
	} );

	test( 'SET_STREAM_ERROR clears the error session when dismissed', () => {
		const state = reducer(
			{
				...DEFAULT_STATE,
				streamError: true,
				streamErrorSessionId: 42,
			},
			{ type: 'SET_STREAM_ERROR', error: false, sessionId: null }
		);

		expect( state.streamError ).toBe( false );
		expect( state.streamErrorSessionId ).toBeNull();
	} );

	test( 'SET_LAST_USER_MESSAGE stores retry text', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_LAST_USER_MESSAGE',
			message: 'retry text',
		} );

		expect( state.lastUserMessage ).toBe( 'retry text' );
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

	test( 'ENQUEUE_MESSAGE preserves deferred message options', () => {
		const options = { durable_plan: true };
		const state = reducer(
			{ ...DEFAULT_STATE, messageQueue: [] },
			actions.enqueueMessage( 'Prepare a plan.', [], options )
		);

		expect( state.messageQueue ).toEqual( [
			expect.objectContaining( {
				text: 'Prepare a plan.',
				attachments: [],
				options,
			} ),
		] );
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
		streamError: true,
		streamErrorSessionId: 42,
		lastUserMessage: 'retry text',
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

	test( 'hasStreamError returns true only for the active session', () => {
		expect( selectors.hasStreamError( state ) ).toBe( true );
		expect(
			selectors.hasStreamError( { ...state, currentSessionId: 7 } )
		).toBe( false );
	} );

	test( 'getLastUserMessage returns retry text', () => {
		expect( selectors.getLastUserMessage( state ) ).toBe( 'retry text' );
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
