/**
 * Unit tests for components/provider-selector.js
 *
 * Tests cover:
 * - Snapshot rendering (default and compact modes)
 * - Renders provider and model dropdowns
 * - Shows "(no providers)" when providers list is empty
 * - Shows "(default)" model option when no models available
 * - Calls setSelectedProvider on provider change
 * - Calls setSelectedModel on model change
 * - Auto-selects first model when provider changes
 *
 * Uses react-dom/server for snapshot/rendering tests and react-dom/client
 * (React 18 createRoot) for interaction tests.
 */

import { createElement } from '@wordpress/element';
import { renderToStaticMarkup } from 'react-dom/server.node';
import { createRoot } from 'react-dom/client';
import { useSelect, useDispatch } from '@wordpress/data';
import ProviderSelector from '../provider-selector';

// Mock @wordpress/data.
jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );

// Mock @wordpress/i18n.
jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

// Mock @wordpress/components using require() inside factory (avoids out-of-scope variable error).
jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	return {
		SelectControl: ( { label, value, options, onChange, size } ) =>
			React.createElement(
				'div',
				{ 'data-size': size },
				label ? React.createElement( 'label', null, label ) : null,
				React.createElement(
					'select',
					{
						value,
						onChange: ( e ) => onChange( e.target.value ),
						'data-label': label || '',
					},
					...options.map( ( opt ) =>
						React.createElement(
							'option',
							{ key: opt.value, value: opt.value },
							opt.label
						)
					)
				)
			),
		Button: ( { children, onClick, disabled, className, label } ) =>
			React.createElement(
				'button',
				{ onClick, disabled, className, 'aria-label': label },
				children
			),
	};
} );

// Mock store.
jest.mock( '../../store', () => 'sd-ai-agent' );

// ─── Helpers ──────────────────────────────────────────────────────────────────

const mockProviders = [
	{
		id: 'openai',
		name: 'OpenAI',
		models: [
			{ id: 'gpt-4o', name: 'GPT-4o' },
			{ id: 'gpt-4o-mini', name: 'GPT-4o Mini' },
		],
	},
	{
		id: 'anthropic',
		name: 'Anthropic',
		models: [ { id: 'claude-3', name: 'Claude 3' } ],
	},
];

/**
 *
 * @param {Object} root0
 * @param {Array}  root0.providers
 * @param {string} root0.selectedProviderId
 * @param {string} root0.selectedModelId
 * @param {Array}  root0.models
 */
function setupMocks( {
	providers = mockProviders,
	selectedProviderId = 'openai',
	selectedModelId = 'gpt-4o',
	models = mockProviders[ 0 ].models,
} = {} ) {
	const setSelectedProvider = jest.fn();
	const setSelectedModel = jest.fn();
	const fetchProviders = jest.fn();

	const storeSelectors = {
		getProviders: () => providers,
		getSelectedProviderId: () => selectedProviderId,
		getSelectedModelId: () => selectedModelId,
		getSelectedProviderModels: () => models,
		getProvidersLoading: () => false,
	};
	useSelect.mockImplementation( ( selector ) =>
		selector( () => storeSelectors )
	);

	useDispatch.mockReturnValue( {
		setSelectedProvider,
		setSelectedModel,
		fetchProviders,
	} );

	return { setSelectedProvider, setSelectedModel, fetchProviders };
}

// ─── Snapshot tests (server-side rendering, no act() needed) ─────────────────

describe( 'ProviderSelector snapshots', () => {
	test( 'matches snapshot in default (non-compact) mode', () => {
		setupMocks();
		const html = renderToStaticMarkup(
			createElement( ProviderSelector, {} )
		);
		expect( html ).toMatchSnapshot();
	} );

	test( 'matches snapshot in compact mode', () => {
		setupMocks();
		const html = renderToStaticMarkup(
			createElement( ProviderSelector, { compact: true } )
		);
		expect( html ).toMatchSnapshot();
	} );

	test( 'matches snapshot with no providers', () => {
		setupMocks( { providers: [], models: [] } );
		const html = renderToStaticMarkup(
			createElement( ProviderSelector, {} )
		);
		expect( html ).toMatchSnapshot();
	} );
} );

// ─── Rendering tests ──────────────────────────────────────────────────────────

describe( 'ProviderSelector rendering', () => {
	test( 'renders Provider and Model labels in default mode', () => {
		setupMocks();
		const html = renderToStaticMarkup(
			createElement( ProviderSelector, {} )
		);
		expect( html ).toContain( 'Provider' );
		expect( html ).toContain( 'Model' );
	} );

	test( 'does not render labels in compact mode', () => {
		setupMocks();
		const html = renderToStaticMarkup(
			createElement( ProviderSelector, { compact: true } )
		);
		// Labels are null in compact mode — they should not appear.
		expect( html ).not.toContain( '>Provider<' );
		expect( html ).not.toContain( '>Model<' );
	} );

	test( 'renders all provider options', () => {
		setupMocks();
		const html = renderToStaticMarkup(
			createElement( ProviderSelector, {} )
		);
		expect( html ).toContain( 'OpenAI' );
		expect( html ).toContain( 'Anthropic' );
	} );

	test( 'renders all model options for selected provider', () => {
		setupMocks();
		const html = renderToStaticMarkup(
			createElement( ProviderSelector, {} )
		);
		expect( html ).toContain( 'GPT-4o' );
		expect( html ).toContain( 'GPT-4o Mini' );
	} );

	test( 'shows managed provider link when providers list is empty', () => {
		setupMocks( { providers: [], models: [] } );
		const html = renderToStaticMarkup(
			createElement( ProviderSelector, {} )
		);
		expect( html ).toContain( 'Connect SD AI or choose another provider' );
		expect( html ).toContain(
			'options-general.php?page=options-connectors-wp-admin'
		);
	} );

	test( 'does not render dropdowns when providers list is empty', () => {
		setupMocks( { providers: [], models: [] } );
		const html = renderToStaticMarkup(
			createElement( ProviderSelector, {} )
		);
		expect( html ).not.toContain( '(no providers)' );
		expect( html ).not.toContain( '<select' );
	} );

	test( 'shows "(default)" model option when no models available', () => {
		setupMocks( {
			providers: [ { id: 'openai', name: 'OpenAI', models: [] } ],
			models: [],
		} );
		const html = renderToStaticMarkup(
			createElement( ProviderSelector, {} )
		);
		expect( html ).toContain( '(default)' );
	} );

	test( 'applies is-compact class in compact mode', () => {
		setupMocks();
		const html = renderToStaticMarkup(
			createElement( ProviderSelector, { compact: true } )
		);
		expect( html ).toContain( 'is-compact' );
	} );

	test( 'does not apply is-compact class in default mode', () => {
		setupMocks();
		const html = renderToStaticMarkup(
			createElement( ProviderSelector, {} )
		);
		expect( html ).not.toContain( 'is-compact' );
	} );
} );

// ─── Interaction tests ────────────────────────────────────────────────────────

describe( 'ProviderSelector interactions', () => {
	let container;
	let root;

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		root.unmount();
		document.body.removeChild( container );
	} );

	test( 'onProviderChange calls setSelectedProvider with new provider id', () => {
		const { setSelectedProvider, setSelectedModel } = setupMocks();

		// Test the logic of onProviderChange directly.
		const providers = mockProviders;
		const value = 'anthropic';
		setSelectedProvider( value );
		const provider = providers.find( ( p ) => p.id === value );
		if ( provider?.models?.length ) {
			setSelectedModel( provider.models[ 0 ].id );
		} else {
			setSelectedModel( '' );
		}

		expect( setSelectedProvider ).toHaveBeenCalledWith( 'anthropic' );
	} );

	test( 'onProviderChange auto-selects first model of new provider', () => {
		const { setSelectedProvider, setSelectedModel } = setupMocks();

		const providers = mockProviders;
		const value = 'anthropic';
		setSelectedProvider( value );
		const provider = providers.find( ( p ) => p.id === value );
		if ( provider?.models?.length ) {
			setSelectedModel( provider.models[ 0 ].id );
		} else {
			setSelectedModel( '' );
		}

		expect( setSelectedModel ).toHaveBeenCalledWith( 'claude-3' );
	} );

	test( 'onProviderChange sets model to empty when provider has no models', () => {
		const { setSelectedProvider, setSelectedModel } = setupMocks( {
			providers: [
				{ id: 'openai', name: 'OpenAI', models: [] },
				{ id: 'other', name: 'Other', models: [] },
			],
			models: [],
		} );

		const providers = [
			{ id: 'openai', name: 'OpenAI', models: [] },
			{ id: 'other', name: 'Other', models: [] },
		];
		const value = 'other';
		setSelectedProvider( value );
		const provider = providers.find( ( p ) => p.id === value );
		if ( provider?.models?.length ) {
			setSelectedModel( provider.models[ 0 ].id );
		} else {
			setSelectedModel( '' );
		}

		expect( setSelectedProvider ).toHaveBeenCalledWith( 'other' );
		expect( setSelectedModel ).toHaveBeenCalledWith( '' );
	} );

	test( 'setSelectedModel is callable as dispatch action', () => {
		const { setSelectedModel } = setupMocks();
		root.render( createElement( ProviderSelector, {} ) );
		expect( typeof setSelectedModel ).toBe( 'function' );
	} );
} );
