/**
 * Providers slice — AI provider list and selected provider/model.
 */

/**
 * @typedef {import('../../types').Provider} Provider
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Resolve a persisted provider/model selection against a fresh provider list.
 *
 * @param {Provider[]} providers       Available providers from REST.
 * @param {string}     savedProviderId Provider ID from localStorage.
 * @param {string}     savedModelId    Model ID from localStorage.
 * @return {{providerId: string, modelId: string}} Resolved provider/model IDs.
 */
export function resolveProviderSelection(
	providers,
	savedProviderId,
	savedModelId
) {
	const provider =
		providers.find( ( candidate ) => candidate.id === savedProviderId ) ||
		providers.find( ( candidate ) => candidate.models?.length ) ||
		providers[ 0 ];

	if ( ! provider ) {
		return { providerId: '', modelId: '' };
	}

	const models = Array.isArray( provider.models ) ? provider.models : [];
	const hasSavedModel = models.some( ( model ) => model.id === savedModelId );
	const defaultModelId = provider.default_model || '';
	const hasProviderDefaultModel = models.some(
		( model ) => model.id === defaultModelId
	);
	let modelId = models[ 0 ]?.id || '';

	if ( hasProviderDefaultModel ) {
		modelId = defaultModelId;
	}

	if ( hasSavedModel ) {
		modelId = savedModelId;
	}

	return {
		providerId: provider.id,
		modelId,
	};
}

export const initialState = {
	providers: [],
	providersLoaded: false,
	providersLoading: false,
	selectedProviderId: localStorage.getItem( 'sdAiAgentProvider' ) || '',
	selectedModelId: localStorage.getItem( 'sdAiAgentModel' ) || '',
};

export const actions = {
	/**
	 * Replace the providers list.
	 *
	 * @param {Provider[]} providers - Available AI providers.
	 * @return {Object} Redux action.
	 */
	setProviders( providers ) {
		return { type: 'SET_PROVIDERS', providers };
	},

	/**
	 * Select an AI provider and persist the choice to localStorage.
	 *
	 * @param {string} providerId - Provider identifier.
	 * @return {Object} Redux action.
	 */
	setSelectedProvider( providerId ) {
		localStorage.setItem( 'sdAiAgentProvider', providerId );
		return { type: 'SET_SELECTED_PROVIDER', providerId };
	},

	/**
	 * Select a model and persist the choice to localStorage.
	 *
	 * @param {string} modelId - Model identifier.
	 * @return {Object} Redux action.
	 */
	setSelectedModel( modelId ) {
		localStorage.setItem( 'sdAiAgentModel', modelId );
		return { type: 'SET_SELECTED_MODEL', modelId };
	},

	/**
	 * Fetch available AI providers from the REST API and populate the store.
	 * Auto-selects the first provider/model when none is saved or the saved
	 * provider is no longer available.
	 *
	 * Deduplicates concurrent in-flight calls: if a fetch is already in-flight
	 * (tracked via the shared Redux store, so cross-bundle dedup works too),
	 * the call is a no-op. This prevents duplicate REST requests when multiple
	 * plugin bundles (e.g. floating-widget.js and admin-page.js) are loaded on
	 * the same admin page and each mount a component that calls fetchProviders()
	 * on mount. Intentional refreshes (e.g. after saving API credentials) are
	 * not blocked — once the in-flight fetch settles, the next call starts a
	 * new request.
	 *
	 * @return {Function} Redux thunk.
	 */
	fetchProviders() {
		return async ( { dispatch, select } ) => {
			// Skip if a fetch is already in-flight or a boot error was raised.
			if ( select.getProvidersLoading() || select.getBootError() ) {
				return;
			}
			dispatch( { type: 'SET_PROVIDERS_LOADING', loading: true } );
			try {
				const providers = await apiFetch( {
					path: '/sd-ai-agent/v1/providers',
				} );
				dispatch.setProviders( providers );

				const savedProviderId =
					localStorage.getItem( 'sdAiAgentProvider' ) || '';
				const savedModelId =
					localStorage.getItem( 'sdAiAgentModel' ) || '';
				const selection = resolveProviderSelection(
					providers,
					savedProviderId,
					savedModelId
				);

				if ( selection.providerId !== savedProviderId ) {
					dispatch.setSelectedProvider( selection.providerId );
				}

				if ( selection.modelId !== savedModelId ) {
					dispatch.setSelectedModel( selection.modelId );
				}
			} catch ( err ) {
				dispatch.setProviders( [] );
				const status = err?.data?.status ?? err?.code;
				if ( status === 403 || status === 401 ) {
					dispatch.setBootError( {
						message:
							err?.message ||
							__(
								'Unable to connect to the AI Agent API.',
								'sd-ai-agent'
							),
						status,
					} );
				}
			} finally {
				dispatch( { type: 'SET_PROVIDERS_LOADING', loading: false } );
			}
		};
	},
};

export const selectors = {
	/**
	 * @param {import('../../types').StoreState} state
	 * @return {Provider[]} Available AI providers.
	 */
	getProviders( state ) {
		return state.providers;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether providers have been fetched.
	 */
	getProvidersLoaded( state ) {
		return state.providersLoaded;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether a providers fetch is currently in-flight.
	 */
	getProvidersLoading( state ) {
		return state.providersLoading;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {string} Currently selected provider ID.
	 */
	getSelectedProviderId( state ) {
		return state.selectedProviderId;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {string} Currently selected model ID.
	 */
	getSelectedModelId( state ) {
		return state.selectedModelId;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {import('../../types').ProviderModel[]} Models for the selected provider.
	 */
	getSelectedProviderModels( state ) {
		const provider = state.providers.find(
			( p ) => p.id === state.selectedProviderId
		);
		return provider?.models || [];
	},

	/**
	 * Return the context window size (in tokens) for a given model.
	 * Looks through all loaded providers for a model entry that carries
	 * a `context_window` field from the REST API response. Falls back to
	 * `settings.context_window_default` and then to 128,000.
	 *
	 * @param {import('../../types').StoreState} state
	 * @param {string}                           modelId - Model identifier to look up.
	 * @return {number} Context window size in tokens.
	 */
	getModelContextWindow( state, modelId ) {
		for ( const provider of state.providers ) {
			if ( ! Array.isArray( provider.models ) ) {
				continue;
			}
			const model = provider.models.find( ( m ) => m.id === modelId );
			if ( model?.context_window ) {
				return model.context_window;
			}
		}
		return state.settings?.context_window_default || 128000;
	},
};

/**
 * @param {import('../../types').StoreState} state  - Current state.
 * @param {Object}                           action - Dispatched action.
 * @return {import('../../types').StoreState} Next state.
 */
export function reducer( state, action ) {
	switch ( action.type ) {
		case 'SET_PROVIDERS':
			return {
				...state,
				providers: action.providers,
				providersLoaded: true,
			};
		case 'SET_PROVIDERS_LOADING':
			return { ...state, providersLoading: action.loading };
		case 'SET_SELECTED_PROVIDER':
			return { ...state, selectedProviderId: action.providerId };
		case 'SET_SELECTED_MODEL':
			return { ...state, selectedModelId: action.modelId };
		default:
			return state;
	}
}
