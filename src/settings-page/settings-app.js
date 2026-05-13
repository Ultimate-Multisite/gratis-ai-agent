/**
 * WordPress dependencies
 */
import { useEffect, useState, useCallback, useRef } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	TabPanel,
	TextControl,
	TextareaControl,
	RangeControl,
	ToggleControl,
	Button,
	Notice,
	Spinner,
	SelectControl,
	SnackbarList,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	useAvailableVoices,
	isTTSSupported,
} from '../components/use-text-to-speech';
import { isSoundSupported } from '../utils/sound-manager';

/**
 * Internal dependencies
 */
import './style.css';
import STORE_NAME from '../store';
import ErrorBoundary from '../components/error-boundary';
import ModelPricingSelector from '../components/model-pricing-selector';
import MemoryManager from './memory-manager';
import SkillManager from './skill-manager';
import KnowledgeManager from './knowledge-manager';
import UsageDashboard from './usage-dashboard';
import CustomToolsManager from './custom-tools-manager';
import AutomationsManager from './automations-manager';
import EventsManager from './events-manager';
import RolePermissionsManager from './role-permissions-manager';
import AgentBuilder from './agent-builder';
import BrandingManager from './branding-manager';
import AbilitiesManager from './abilities-manager';
import ProviderTraceViewer from './provider-trace-viewer';

/**
 *
 */
export default function SettingsApp() {
	const {
		fetchSettings,
		fetchProviders,
		saveSettings,
		setTtsEnabled,
		setTtsVoiceURI,
		setTtsRate,
		setTtsPitch,
		setSoundSuccessEnabled,
		setSoundErrorEnabled,
		setSoundThinkingEnabled,
	} = useDispatch( STORE_NAME );
	const {
		settings,
		settingsLoaded,
		providers,
		ttsEnabled,
		ttsVoiceURI,
		ttsRate,
		ttsPitch,
		soundSuccessEnabled,
		soundErrorEnabled,
		soundThinkingEnabled,
	} = useSelect(
		( select ) => ( {
			settings: select( STORE_NAME ).getSettings(),
			settingsLoaded: select( STORE_NAME ).getSettingsLoaded(),
			providers: select( STORE_NAME ).getProviders(),
			ttsEnabled: select( STORE_NAME ).isTtsEnabled(),
			ttsVoiceURI: select( STORE_NAME ).getTtsVoiceURI(),
			ttsRate: select( STORE_NAME ).getTtsRate(),
			ttsPitch: select( STORE_NAME ).getTtsPitch(),
			soundSuccessEnabled: select( STORE_NAME ).isSoundSuccessEnabled(),
			soundErrorEnabled: select( STORE_NAME ).isSoundErrorEnabled(),
			soundThinkingEnabled: select( STORE_NAME ).isSoundThinkingEnabled(),
		} ),
		[]
	);

	// Available TTS voices (loaded asynchronously in some browsers).
	const ttsVoices = useAvailableVoices();

	const { createNotice, removeNotice } = useDispatch( 'core/notices' );
	const snackbarNotices = useSelect( ( select ) =>
		select( 'core/notices' )
			.getNotices()
			.filter( ( n ) => n.type === 'snackbar' )
	);

	const [ local, setLocal ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ abilities, setAbilities ] = useState( [] );
	const [ activeTab, setActiveTab ] = useState( 'general' );

	// Scroll affordance: ref to the wrapper div, state for fade indicators.
	const tabsWrapperRef = useRef( null );
	const [ hasScrollLeft, setHasScrollLeft ] = useState( false );
	const [ hasScrollRight, setHasScrollRight ] = useState( false );

	// Tabs that manage their own save actions — hide the global Save Settings button.
	// Note: 'access-branding' was removed from this list because BrandingManager
	// does not have its own save button — it uses the global Save Settings button.
	const selfSavingTabs = [ 'provider-trace' ];

	// Google Analytics integration state.
	const [ gaPropertyId, setGaPropertyId ] = useState( '' );
	const [ gaServiceJson, setGaServiceJson ] = useState( '' );
	const [ gaStatus, setGaStatus ] = useState( null ); // { has_credentials, property_id, has_service_key }
	const [ gaSaving, setGaSaving ] = useState( false );
	const [ gaNotice, setGaNotice ] = useState( null );

	// Brave Search API key state.
	const [ braveApiKey, setBraveApiKey ] = useState( '' );
	const [ braveConfigured, setBraveConfigured ] = useState( false );
	const [ braveSaving, setBraveSaving ] = useState( false );
	const [ braveNotice, setBraveNotice ] = useState( null );

	// Tavily API key state.
	const [ tavilyApiKey, setTavilyApiKey ] = useState( '' );
	const [ tavilyConfigured, setTavilyConfigured ] = useState( false );
	const [ tavilySaving, setTavilySaving ] = useState( false );
	const [ tavilyNotice, setTavilyNotice ] = useState( null );

	useEffect( () => {
		fetchSettings();
		fetchProviders();
		// Fetch abilities list.
		apiFetch( { path: '/sd-ai-agent/v1/abilities' } )
			.then( setAbilities )
			.catch( () => {} );
		// Fetch Google Analytics credential status.
		apiFetch( { path: '/sd-ai-agent/v1/settings/google-analytics' } )
			.then( ( data ) => {
				setGaStatus( data );
				if ( data?.property_id ) {
					setGaPropertyId( data.property_id );
				}
			} )
			.catch( () => {} );
		// Fetch search provider key status from the general settings response.
		apiFetch( { path: '/sd-ai-agent/v1/settings' } )
			.then( ( data ) => {
				setBraveConfigured( !! data?._brave_search_key_configured );
				setTavilyConfigured( !! data?._tavily_api_key_configured );
			} )
			.catch( () => {} );
	}, [ fetchSettings, fetchProviders ] );

	const handleGaSave = useCallback( async () => {
		setGaSaving( true );
		setGaNotice( null );
		try {
			const result = await apiFetch( {
				path: '/sd-ai-agent/v1/settings/google-analytics',
				method: 'POST',
				data: {
					property_id: gaPropertyId,
					service_account_json: gaServiceJson,
				},
			} );
			setGaStatus( {
				has_credentials: true,
				has_property_id: true,
				property_id: result.property_id,
				has_service_key: true,
			} );
			setGaServiceJson( '' ); // Clear the JSON field after saving.
			setGaNotice( {
				status: 'success',
				message: __(
					'Google Analytics credentials saved.',
					'sd-ai-agent'
				),
			} );
		} catch ( err ) {
			setGaNotice( {
				status: 'error',
				message:
					err?.message ||
					__(
						'Failed to save Google Analytics credentials.',
						'sd-ai-agent'
					),
			} );
		}
		setGaSaving( false );
	}, [ gaPropertyId, gaServiceJson ] );

	const handleGaClear = useCallback( async () => {
		setGaSaving( true );
		setGaNotice( null );
		try {
			await apiFetch( {
				path: '/sd-ai-agent/v1/settings/google-analytics',
				method: 'DELETE',
			} );
			setGaStatus( {
				has_credentials: false,
				has_property_id: false,
				property_id: '',
				has_service_key: false,
			} );
			setGaPropertyId( '' );
			setGaServiceJson( '' );
			setGaNotice( {
				status: 'success',
				message: __(
					'Google Analytics credentials cleared.',
					'sd-ai-agent'
				),
			} );
		} catch {
			setGaNotice( {
				status: 'error',
				message: __(
					'Failed to clear Google Analytics credentials.',
					'sd-ai-agent'
				),
			} );
		}
		setGaSaving( false );
	}, [] );

	const handleBraveSave = useCallback( async () => {
		setBraveSaving( true );
		setBraveNotice( null );
		try {
			await apiFetch( {
				path: '/sd-ai-agent/v1/settings/brave-search-key',
				method: 'POST',
				data: { api_key: braveApiKey },
			} );
			setBraveConfigured( true );
			setBraveApiKey( '' ); // Clear the field after saving.
			setBraveNotice( {
				status: 'success',
				message: __( 'Brave Search API key saved.', 'sd-ai-agent' ),
			} );
		} catch ( err ) {
			setBraveNotice( {
				status: 'error',
				message:
					err?.message ||
					__( 'Failed to save Brave Search API key.', 'sd-ai-agent' ),
			} );
		}
		setBraveSaving( false );
	}, [ braveApiKey ] );

	const handleBraveClear = useCallback( async () => {
		setBraveSaving( true );
		setBraveNotice( null );
		try {
			await apiFetch( {
				path: '/sd-ai-agent/v1/settings/brave-search-key',
				method: 'DELETE',
			} );
			setBraveConfigured( false );
			setBraveNotice( {
				status: 'success',
				message: __( 'Brave Search API key removed.', 'sd-ai-agent' ),
			} );
		} catch {
			setBraveNotice( {
				status: 'error',
				message: __(
					'Failed to remove Brave Search API key.',
					'sd-ai-agent'
				),
			} );
		}
		setBraveSaving( false );
	}, [] );

	const handleTavilySave = useCallback( async () => {
		setTavilySaving( true );
		setTavilyNotice( null );
		try {
			await apiFetch( {
				path: '/sd-ai-agent/v1/settings/tavily-api-key',
				method: 'POST',
				data: { api_key: tavilyApiKey },
			} );
			setTavilyConfigured( true );
			setTavilyApiKey( '' ); // Clear the field after saving.
			setTavilyNotice( {
				status: 'success',
				message: __( 'Tavily API key saved.', 'sd-ai-agent' ),
			} );
		} catch ( err ) {
			setTavilyNotice( {
				status: 'error',
				message:
					err?.message ||
					__( 'Failed to save Tavily API key.', 'sd-ai-agent' ),
			} );
		}
		setTavilySaving( false );
	}, [ tavilyApiKey ] );

	const handleTavilyClear = useCallback( async () => {
		setTavilySaving( true );
		setTavilyNotice( null );
		try {
			await apiFetch( {
				path: '/sd-ai-agent/v1/settings/tavily-api-key',
				method: 'DELETE',
			} );
			setTavilyConfigured( false );
			setTavilyNotice( {
				status: 'success',
				message: __( 'Tavily API key removed.', 'sd-ai-agent' ),
			} );
		} catch {
			setTavilyNotice( {
				status: 'error',
				message: __(
					'Failed to remove Tavily API key.',
					'sd-ai-agent'
				),
			} );
		}
		setTavilySaving( false );
	}, [] );

	useEffect( () => {
		if ( settings && ! local ) {
			setLocal( { ...settings } );
		}
	}, [ settings, local ] );

	// Attach scroll affordance listener to the tab bar.
	useEffect( () => {
		const wrapper = tabsWrapperRef.current;
		if ( ! wrapper ) {
			return;
		}
		const tabBar = wrapper.querySelector( '.components-tab-panel__tabs' );
		if ( ! tabBar ) {
			return;
		}

		const updateIndicators = () => {
			const { scrollLeft, scrollWidth, clientWidth } = tabBar;
			setHasScrollLeft( scrollLeft > 0 );
			setHasScrollRight( scrollLeft + clientWidth < scrollWidth - 1 );
		};

		// Initial check.
		updateIndicators();

		tabBar.addEventListener( 'scroll', updateIndicators, {
			passive: true,
		} );

		// Re-check on resize (e.g. window resize changes available width).
		const resizeObserver = new ResizeObserver( updateIndicators );
		resizeObserver.observe( tabBar );

		return () => {
			tabBar.removeEventListener( 'scroll', updateIndicators );
			resizeObserver.disconnect();
		};
	} );

	// Scroll the active tab into view whenever activeTab changes.
	useEffect( () => {
		const wrapper = tabsWrapperRef.current;
		if ( ! wrapper ) {
			return;
		}
		const activeButton = wrapper.querySelector(
			`.components-tab-panel__tabs-item.is-active`
		);
		if ( activeButton ) {
			activeButton.scrollIntoView( {
				behavior: 'smooth',
				block: 'nearest',
				inline: 'nearest',
			} );
		}
	}, [ activeTab ] );

	const updateField = useCallback( ( key, value ) => {
		setLocal( ( prev ) => ( { ...prev, [ key ]: value } ) );
	}, [] );

	const handleSave = useCallback( async () => {
		setSaving( true );
		try {
			await saveSettings( local );
			createNotice( 'success', __( 'Settings saved.', 'sd-ai-agent' ), {
				type: 'snackbar',
				isDismissible: true,
				id: 'sdaa-settings-save',
			} );
		} catch {
			createNotice(
				'error',
				__( 'Failed to save settings.', 'sd-ai-agent' ),
				{
					type: 'snackbar',
					isDismissible: true,
					id: 'sdaa-settings-save',
				}
			);
		}
		setSaving( false );
	}, [ local, saveSettings, createNotice ] );

	if ( ! settingsLoaded || ! local ) {
		return (
			<div className="sdaa-settings-loading">
				<Spinner />
			</div>
		);
	}

	// Build provider/model options.
	const providerOptions = [
		{ label: __( '(default)', 'sd-ai-agent' ), value: '' },
		...providers.map( ( p ) => ( { label: p.name, value: p.id } ) ),
	];

	const selectedProvider = providers.find(
		( p ) => p.id === local.default_provider
	);

	// Provider trace is a debug-only feature — only show the tab when
	// WP_DEBUG is active (communicated from PHP via sdAiAgentData.wpDebug).
	const isWpDebug = !! window.sdAiAgentData?.wpDebug;
	// Feature flags injected by PHP (UnifiedAdminMenu::enqueueAssets).
	// Fall back to all-enabled when the global is absent (e.g. unit tests).
	const features = window.sdAiAgentData?.features ?? {
		branding: true,
		access_control: true,
	};

	// Consolidated tab list. Providers are configured network-wide via the
	// WP Multisite WaaS Connectors page, so no Providers tab is rendered here.
	// The 'access-branding' tab is only included when at least one of the two
	// features it hosts (access_control, branding) is enabled.
	const allTabs = [
		{
			name: 'general',
			title: __( 'General', 'sd-ai-agent' ),
			className: 'sdaa-settings-tab',
		},
		{
			name: 'memory-knowledge',
			title: __( 'Memory & Knowledge', 'sd-ai-agent' ),
			className: 'sdaa-settings-tab',
		},
		{
			name: 'skills',
			title: __( 'Skills', 'sd-ai-agent' ),
			className: 'sdaa-settings-tab',
		},
		{
			name: 'tools',
			title: __( 'Tools', 'sd-ai-agent' ),
			className: 'sdaa-settings-tab',
		},
		{
			name: 'automations',
			title: __( 'Automations', 'sd-ai-agent' ),
			className: 'sdaa-settings-tab',
		},
		{
			name: 'agents',
			title: __( 'Agents', 'sd-ai-agent' ),
			className: 'sdaa-settings-tab',
		},
		{
			name: 'access-branding',
			title: __( 'Access & Branding', 'sd-ai-agent' ),
			className: 'sdaa-settings-tab',
			// Hide when both constituent features are disabled.
			hidden: ! features.access_control && ! features.branding,
		},
		{
			name: 'usage',
			title: __( 'Usage', 'sd-ai-agent' ),
			className: 'sdaa-settings-tab',
		},
		// Only visible when WP_DEBUG is active.
		...( isWpDebug
			? [
					{
						name: 'provider-trace',
						title: __( 'Provider Trace', 'sd-ai-agent' ),
						className: 'sdaa-settings-tab',
					},
			  ]
			: [] ),
		{
			name: 'advanced',
			title: __( 'Advanced', 'sd-ai-agent' ),
			className: 'sdaa-settings-tab',
		},
	];

	const tabs = allTabs.filter( ( tab ) => ! tab.hidden );

	const scrollWrapperClasses = [
		'sdaa-tabs-scroll-wrapper',
		hasScrollLeft ? 'has-scroll-left' : '',
		hasScrollRight ? 'has-scroll-right' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div className="sdaa-settings">
			<Notice
				status="info"
				isDismissible={ false }
				className="sdaa-providers-link-notice"
			>
				{ __(
					'Provider API keys are configured on the Connectors page.',
					'sd-ai-agent'
				) }{ ' ' }
				<a
					href={
						window.sdAiAgentData?.connectorsUrl ||
						'options-general.php?page=options-connectors-wp-admin'
					}
				>
					{ __( 'Open Connectors →', 'sd-ai-agent' ) }
				</a>
			</Notice>
			<div ref={ tabsWrapperRef } className={ scrollWrapperClasses }>
				<TabPanel tabs={ tabs } onSelect={ setActiveTab }>
					{ ( tab ) => {
						switch ( tab.name ) {
							case 'general':
								return (
									<div className="sdaa-settings-section">
										<h3 className="sdaa-settings-section-title">
											{ __( 'Model', 'sd-ai-agent' ) }
										</h3>
										<table className="form-table sdaa-form-table">
											<tbody>
												<tr>
													<th scope="row">
														<label htmlFor="sd-default-provider">
															{ __(
																'Default Provider',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<SelectControl
															id="sd-default-provider"
															value={
																local.default_provider
															}
															options={
																providerOptions
															}
															onChange={ ( v ) =>
																updateField(
																	'default_provider',
																	v
																)
															}
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														<label htmlFor="sd-default-model">
															{ __(
																'Default Model',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<ModelPricingSelector
															id="sd-default-model"
															value={
																local.default_model
															}
															models={
																selectedProvider?.models ||
																[]
															}
															providerName={
																selectedProvider?.name ||
																''
															}
															onChange={ ( v ) =>
																updateField(
																	'default_model',
																	v
																)
															}
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														<label htmlFor="sd-max-iterations">
															{ __(
																'Max Iterations',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<TextControl
															id="sd-max-iterations"
															type="number"
															min={ 1 }
															max={ 500 }
															value={
																local.max_iterations
															}
															onChange={ ( v ) =>
																updateField(
																	'max_iterations',
																	parseInt(
																		v,
																		10
																	) || 100
																)
															}
															help={ __(
																'Maximum tool-call iterations per request.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
											</tbody>
										</table>

										<h3 className="sdaa-settings-section-title">
											{ __(
												'Chat Behaviour',
												'sd-ai-agent'
											) }
										</h3>
										<table className="form-table sdaa-form-table">
											<tbody>
												<tr>
													<th scope="row">
														<label htmlFor="sd-greeting-message">
															{ __(
																'Greeting Message',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<TextareaControl
															id="sd-greeting-message"
															value={
																local.greeting_message
															}
															onChange={ ( v ) =>
																updateField(
																	'greeting_message',
																	v
																)
															}
															placeholder={
																settings
																	?._defaults
																	?.greeting_message ||
																''
															}
															help={ __(
																'Shown in the chat before the first message. Leave empty for the default.',
																'sd-ai-agent'
															) }
															rows={ 2 }
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														<label htmlFor="sd-keyboard-shortcut">
															{ __(
																'Keyboard Shortcut',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<TextControl
															id="sd-keyboard-shortcut"
															value={
																local.keyboard_shortcut ??
																'alt+a'
															}
															onChange={ ( v ) =>
																updateField(
																	'keyboard_shortcut',
																	v
																)
															}
															help={ __(
																'Shortcut to open/close the floating chat widget. Use modifier keys joined by "+", e.g. "alt+a" or "ctrl+shift+k". Leave empty to disable.',
																'sd-ai-agent'
															) }
															placeholder="alt+a"
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														{ __(
															'YOLO Mode',
															'sd-ai-agent'
														) }
													</th>
													<td>
														<div className="sdaa-settings-yolo-section">
															<ToggleControl
																label={ __(
																	'Skip all confirmation dialogs',
																	'sd-ai-agent'
																) }
																checked={
																	!! local.yolo_mode
																}
																onChange={ (
																	v
																) =>
																	updateField(
																		'yolo_mode',
																		v
																	)
																}
																help={ __(
																	'Destructive actions will run without prompting. Use with caution.',
																	'sd-ai-agent'
																) }
																__nextHasNoMarginBottom
															/>
															{ local.yolo_mode && (
																<div className="sdaa-yolo-warning">
																	{ __(
																		'Warning: YOLO mode is active. All tool confirmations are skipped automatically. Destructive operations will execute without asking.',
																		'sd-ai-agent'
																	) }
																</div>
															) }
														</div>
													</td>
												</tr>
												<tr>
													<th scope="row">
														{ __(
															'Frontend Widget',
															'sd-ai-agent'
														) }
													</th>
													<td>
														<ToggleControl
															label={ __(
																'Show on public-facing pages',
																'sd-ai-agent'
															) }
															checked={
																!! local.show_on_frontend
															}
															onChange={ ( v ) =>
																updateField(
																	'show_on_frontend',
																	v
																)
															}
															help={ __(
																'Display the floating chat widget on public-facing pages for logged-in administrators.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														{ __(
															'Token Costs',
															'sd-ai-agent'
														) }
													</th>
													<td>
														<ToggleControl
															label={ __(
																'Show token count and estimated cost',
																'sd-ai-agent'
															) }
															checked={
																local.show_token_costs !==
																false
															}
															onChange={ ( v ) =>
																updateField(
																	'show_token_costs',
																	v
																)
															}
															help={ __(
																'Display token count and estimated cost below the chat input and after each AI response.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
											</tbody>
										</table>

										<h3 className="sdaa-settings-section-title">
											{ __(
												'AI Image Generation',
												'sd-ai-agent'
											) }
										</h3>
										<p className="description">
											{ __(
												'Settings for the Generate AI Image ability (DALL-E 3). Requires an OpenAI API key configured in the Providers tab.',
												'sd-ai-agent'
											) }
										</p>
										<table className="form-table sdaa-form-table">
											<tbody>
												<tr>
													<th scope="row">
														<label htmlFor="sd-image-size">
															{ __(
																'Default Image Size',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<SelectControl
															id="sd-image-size"
															value={
																local.image_generation_size ||
																'1024x1024'
															}
															options={ [
																{
																	label: __(
																		'Square (1024×1024)',
																		'sd-ai-agent'
																	),
																	value: '1024x1024',
																},
																{
																	label: __(
																		'Landscape (1792×1024)',
																		'sd-ai-agent'
																	),
																	value: '1792x1024',
																},
																{
																	label: __(
																		'Portrait (1024×1792)',
																		'sd-ai-agent'
																	),
																	value: '1024x1792',
																},
															] }
															onChange={ ( v ) =>
																updateField(
																	'image_generation_size',
																	v
																)
															}
															help={ __(
																'Default dimensions for generated images. Can be overridden per request.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														<label htmlFor="sd-image-quality">
															{ __(
																'Default Image Quality',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<SelectControl
															id="sd-image-quality"
															value={
																local.image_generation_quality ||
																'standard'
															}
															options={ [
																{
																	label: __(
																		'Standard',
																		'sd-ai-agent'
																	),
																	value: 'standard',
																},
																{
																	label: __(
																		'HD (higher detail, higher cost)',
																		'sd-ai-agent'
																	),
																	value: 'hd',
																},
															] }
															onChange={ ( v ) =>
																updateField(
																	'image_generation_quality',
																	v
																)
															}
															help={ __(
																'HD produces finer details and greater consistency but costs more per image.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														<label htmlFor="sd-image-style">
															{ __(
																'Default Image Style',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<SelectControl
															id="sd-image-style"
															value={
																local.image_generation_style ||
																'vivid'
															}
															options={ [
																{
																	label: __(
																		'Vivid (hyper-real, dramatic)',
																		'sd-ai-agent'
																	),
																	value: 'vivid',
																},
																{
																	label: __(
																		'Natural (subdued, realistic)',
																		'sd-ai-agent'
																	),
																	value: 'natural',
																},
															] }
															onChange={ ( v ) =>
																updateField(
																	'image_generation_style',
																	v
																)
															}
															help={ __(
																'Vivid is hyper-real and dramatic; Natural is more subdued and realistic.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
											</tbody>
										</table>

										<h3 className="sdaa-settings-section-title">
											{ __(
												'Spending Limits',
												'sd-ai-agent'
											) }
										</h3>
										<p className="description">
											{ __(
												'Set daily and monthly budget caps to prevent runaway API costs. Spend is estimated from the usage log.',
												'sd-ai-agent'
											) }
										</p>
										<table className="form-table sdaa-form-table">
											<tbody>
												<tr>
													<th scope="row">
														<label htmlFor="sd-budget-daily">
															{ __(
																'Daily Budget Cap (USD)',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<TextControl
															id="sd-budget-daily"
															type="number"
															min={ 0 }
															step={ 0.01 }
															value={
																local.budget_daily_cap ??
																''
															}
															onChange={ ( v ) =>
																updateField(
																	'budget_daily_cap',
																	v === ''
																		? 0
																		: parseFloat(
																				v
																		  ) || 0
																)
															}
															placeholder="0.00"
															help={ __(
																'Maximum estimated spend per day in USD. Set to 0 for unlimited.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														<label htmlFor="sd-budget-monthly">
															{ __(
																'Monthly Budget Cap (USD)',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<TextControl
															id="sd-budget-monthly"
															type="number"
															min={ 0 }
															step={ 0.01 }
															value={
																local.budget_monthly_cap ??
																''
															}
															onChange={ ( v ) =>
																updateField(
																	'budget_monthly_cap',
																	v === ''
																		? 0
																		: parseFloat(
																				v
																		  ) || 0
																)
															}
															placeholder="0.00"
															help={ __(
																'Maximum estimated spend per month in USD. Set to 0 for unlimited.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														<label htmlFor="sd-budget-warning-threshold">
															{ __(
																'Warning Threshold (%)',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<RangeControl
															id="sd-budget-warning-threshold"
															label={ __(
																'Warning Threshold (%)',
																'sd-ai-agent'
															) }
															value={
																local.budget_warning_threshold ??
																80
															}
															onChange={ ( v ) =>
																updateField(
																	'budget_warning_threshold',
																	v
																)
															}
															min={ 50 }
															max={ 99 }
															step={ 1 }
															help={ __(
																'Show a warning banner when spend reaches this percentage of the cap.',
																'sd-ai-agent'
															) }
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														<label htmlFor="sd-budget-exceeded-action">
															{ __(
																'Action When Budget Exceeded',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<SelectControl
															id="sd-budget-exceeded-action"
															value={
																local.budget_exceeded_action ||
																'pause'
															}
															options={ [
																{
																	label: __(
																		'Pause — block new requests',
																		'sd-ai-agent'
																	),
																	value: 'pause',
																},
																{
																	label: __(
																		'Warn — show warning but allow',
																		'sd-ai-agent'
																	),
																	value: 'warn',
																},
															] }
															onChange={ ( v ) =>
																updateField(
																	'budget_exceeded_action',
																	v
																)
															}
															help={ __(
																'"Pause" stops all new AI requests until the period resets. "Warn" shows a banner but still allows requests.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
											</tbody>
										</table>

										<h3 className="sdaa-settings-section-title">
											{ __(
												'Text-to-Speech',
												'sd-ai-agent'
											) }
										</h3>
										{ ! isTTSSupported && (
											<p className="description">
												{ __(
													'Text-to-speech is not supported in this browser.',
													'sd-ai-agent'
												) }
											</p>
										) }
										{ isTTSSupported && (
											<table className="form-table sdaa-form-table">
												<tbody>
													<tr>
														<th scope="row">
															{ __(
																'Text-to-Speech',
																'sd-ai-agent'
															) }
														</th>
														<td>
															<ToggleControl
																label={ __(
																	'Read AI responses aloud automatically',
																	'sd-ai-agent'
																) }
																checked={
																	ttsEnabled
																}
																onChange={
																	setTtsEnabled
																}
																help={ __(
																	'Use the speaker button in the chat header to toggle on the fly.',
																	'sd-ai-agent'
																) }
																__nextHasNoMarginBottom
															/>
														</td>
													</tr>
													{ ttsVoices.length > 0 && (
														<tr>
															<th scope="row">
																<label htmlFor="sd-tts-voice">
																	{ __(
																		'Voice',
																		'sd-ai-agent'
																	) }
																</label>
															</th>
															<td>
																<SelectControl
																	id="sd-tts-voice"
																	value={
																		ttsVoiceURI
																	}
																	options={ [
																		{
																			label: __(
																				'(Browser default)',
																				'sd-ai-agent'
																			),
																			value: '',
																		},
																		...ttsVoices.map(
																			(
																				v
																			) => ( {
																				label: `${ v.name } (${ v.lang })`,
																				value: v.voiceURI,
																			} )
																		),
																	] }
																	onChange={
																		setTtsVoiceURI
																	}
																	help={ __(
																		'Select the voice used for speech synthesis.',
																		'sd-ai-agent'
																	) }
																	__nextHasNoMarginBottom
																/>
															</td>
														</tr>
													) }
													<tr>
														<th scope="row">
															<label htmlFor="sd-tts-rate">
																{ __(
																	'Speech Rate',
																	'sd-ai-agent'
																) }
															</label>
														</th>
														<td>
															<RangeControl
																id="sd-tts-rate"
																value={
																	ttsRate
																}
																onChange={
																	setTtsRate
																}
																min={ 0.5 }
																max={ 2 }
																step={ 0.1 }
																help={ __(
																	'Speed of speech. 1 is normal speed.',
																	'sd-ai-agent'
																) }
															/>
														</td>
													</tr>
													<tr>
														<th scope="row">
															<label htmlFor="sd-tts-pitch">
																{ __(
																	'Pitch',
																	'sd-ai-agent'
																) }
															</label>
														</th>
														<td>
															<RangeControl
																id="sd-tts-pitch"
																value={
																	ttsPitch
																}
																onChange={
																	setTtsPitch
																}
																min={ 0 }
																max={ 2 }
																step={ 0.1 }
																help={ __(
																	'Pitch of speech. 1 is normal pitch.',
																	'sd-ai-agent'
																) }
															/>
														</td>
													</tr>
												</tbody>
											</table>
										) }

										<h3 className="sdaa-settings-section-title">
											{ __(
												'Sound Notifications',
												'sd-ai-agent'
											) }
										</h3>
										{ ! isSoundSupported && (
											<p className="description">
												{ __(
													'Sound notifications are not supported in this browser.',
													'sd-ai-agent'
												) }
											</p>
										) }
										{ isSoundSupported && (
											<table className="form-table sdaa-form-table">
												<tbody>
													<tr>
														<th scope="row">
															{ __(
																'Success Sound',
																'sd-ai-agent'
															) }
														</th>
														<td>
															<ToggleControl
																label={ __(
																	'Play a "ding" when the agent finishes successfully',
																	'sd-ai-agent'
																) }
																checked={
																	soundSuccessEnabled
																}
																onChange={
																	setSoundSuccessEnabled
																}
																help={ __(
																	'A short ascending tone plays when the AI completes a request without error.',
																	'sd-ai-agent'
																) }
																__nextHasNoMarginBottom
															/>
														</td>
													</tr>
													<tr>
														<th scope="row">
															{ __(
																'Error Sound',
																'sd-ai-agent'
															) }
														</th>
														<td>
															<ToggleControl
																label={ __(
																	'Play a "dong" when the agent encounters an error',
																	'sd-ai-agent'
																) }
																checked={
																	soundErrorEnabled
																}
																onChange={
																	setSoundErrorEnabled
																}
																help={ __(
																	'A descending tone plays when the AI returns an error response.',
																	'sd-ai-agent'
																) }
																__nextHasNoMarginBottom
															/>
														</td>
													</tr>
													<tr>
														<th scope="row">
															{ __(
																'Thinking Sound',
																'sd-ai-agent'
															) }
														</th>
														<td>
															<ToggleControl
																label={ __(
																	'Play a tick when a tool action completes',
																	'sd-ai-agent'
																) }
																checked={
																	soundThinkingEnabled
																}
																onChange={
																	setSoundThinkingEnabled
																}
																help={ __(
																	'A subtle tick plays each time the agent completes a tool action during processing.',
																	'sd-ai-agent'
																) }
																__nextHasNoMarginBottom
															/>
														</td>
													</tr>
												</tbody>
											</table>
										) }
									</div>
								);

							case 'memory-knowledge':
								return (
									<div className="sdaa-settings-section">
										<h3 className="sdaa-settings-section-title">
											{ __( 'Memory', 'sd-ai-agent' ) }
										</h3>
										<table className="form-table sdaa-form-table">
											<tbody>
												<tr>
													<th scope="row">
														{ __(
															'Auto-Memory',
															'sd-ai-agent'
														) }
													</th>
													<td>
														<ToggleControl
															label={ __(
																'Proactively save and recall memories',
																'sd-ai-agent'
															) }
															checked={
																local.auto_memory
															}
															onChange={ ( v ) =>
																updateField(
																	'auto_memory',
																	v
																)
															}
															help={ __(
																'When enabled, the AI can proactively save and recall memories.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
											</tbody>
										</table>
										<ErrorBoundary
											label={ __(
												'Memory manager',
												'sd-ai-agent'
											) }
										>
											<MemoryManager />
										</ErrorBoundary>

										<h3 className="sdaa-settings-section-title">
											{ __(
												'Knowledge Base',
												'sd-ai-agent'
											) }
										</h3>
										<table className="form-table sdaa-form-table">
											<tbody>
												<tr>
													<th scope="row">
														{ __(
															'Knowledge Base',
															'sd-ai-agent'
														) }
													</th>
													<td>
														<ToggleControl
															label={ __(
																'Enable knowledge base search',
																'sd-ai-agent'
															) }
															checked={
																local.knowledge_enabled
															}
															onChange={ ( v ) =>
																updateField(
																	'knowledge_enabled',
																	v
																)
															}
															help={ __(
																'When enabled, the AI can search indexed documents and posts for relevant context.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														{ __(
															'Auto-Index',
															'sd-ai-agent'
														) }
													</th>
													<td>
														<ToggleControl
															label={ __(
																'Index posts on publish or update',
																'sd-ai-agent'
															) }
															checked={
																local.knowledge_auto_index
															}
															onChange={ ( v ) =>
																updateField(
																	'knowledge_auto_index',
																	v
																)
															}
															help={ __(
																'Automatically index posts when they are published or updated.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
											</tbody>
										</table>
										<ErrorBoundary
											label={ __(
												'Knowledge manager',
												'sd-ai-agent'
											) }
										>
											<KnowledgeManager />
										</ErrorBoundary>
									</div>
								);

							case 'skills':
								return (
									<div className="sdaa-settings-section">
										<h3 className="sdaa-settings-section-title">
											{ __( 'Skills', 'sd-ai-agent' ) }
										</h3>
										<ErrorBoundary
											label={ __(
												'Skill manager',
												'sd-ai-agent'
											) }
										>
											<SkillManager />
										</ErrorBoundary>

										<h3 className="sdaa-settings-section-title">
											{ __( 'Abilities', 'sd-ai-agent' ) }
										</h3>
										<p className="description">
											{ __(
												'Control how each tool behaves. "Auto" runs without asking, "Confirm" pauses to ask before running, "Disabled" prevents the tool from being used.',
												'sd-ai-agent'
											) }
										</p>
										<AbilitiesManager
											abilities={ abilities }
											toolPermissions={
												local.tool_permissions || {}
											}
											onPermChange={ ( name, value ) => {
												const updated = {
													...( local.tool_permissions ||
														{} ),
												};
												if ( value === 'auto' ) {
													delete updated[ name ];
												} else {
													updated[ name ] = value;
												}
												updateField(
													'tool_permissions',
													updated
												);
											} }
										/>
									</div>
								);

							case 'tools':
								return (
									<div className="sdaa-settings-section">
										<h3 className="sdaa-settings-section-title">
											{ __(
												'Custom Tools',
												'sd-ai-agent'
											) }
										</h3>
										<ErrorBoundary
											label={ __(
												'Custom tools manager',
												'sd-ai-agent'
											) }
										>
											<CustomToolsManager />
										</ErrorBoundary>
									</div>
								);

							case 'automations':
								return (
									<div className="sdaa-settings-section">
										<h3 className="sdaa-settings-section-title">
											{ __(
												'Automations',
												'sd-ai-agent'
											) }
										</h3>
										<ErrorBoundary
											label={ __(
												'Automations manager',
												'sd-ai-agent'
											) }
										>
											<AutomationsManager />
										</ErrorBoundary>

										<h3 className="sdaa-settings-section-title">
											{ __( 'Events', 'sd-ai-agent' ) }
										</h3>
										<ErrorBoundary
											label={ __(
												'Events manager',
												'sd-ai-agent'
											) }
										>
											<EventsManager />
										</ErrorBoundary>
									</div>
								);

							case 'agents':
								return (
									<div className="sdaa-settings-section">
										<ErrorBoundary
											label={ __(
												'Agent builder',
												'sd-ai-agent'
											) }
										>
											<AgentBuilder />
										</ErrorBoundary>
									</div>
								);

							case 'access-branding':
								return (
									<div className="sdaa-settings-section">
										{ features.access_control && (
											<>
												<h3 className="sdaa-settings-section-title">
													{ __(
														'Role Permissions',
														'sd-ai-agent'
													) }
												</h3>
												<ErrorBoundary
													label={ __(
														'Role permissions manager',
														'sd-ai-agent'
													) }
												>
													<RolePermissionsManager />
												</ErrorBoundary>
											</>
										) }

										{ features.branding && (
											<>
												<h3 className="sdaa-settings-section-title">
													{ __(
														'Branding',
														'sd-ai-agent'
													) }
												</h3>
												<BrandingManager
													local={ local }
													updateField={ updateField }
												/>
											</>
										) }
									</div>
								);

							case 'usage':
								return (
									<div className="sdaa-settings-section">
										<ErrorBoundary
											label={ __(
												'Usage dashboard',
												'sd-ai-agent'
											) }
										>
											<UsageDashboard />
										</ErrorBoundary>
									</div>
								);

							case 'provider-trace':
								return (
									<div className="sdaa-settings-section">
										<ErrorBoundary
											label={ __(
												'Provider trace viewer',
												'sd-ai-agent'
											) }
										>
											<ProviderTraceViewer />
										</ErrorBoundary>
									</div>
								);

							case 'advanced':
								return (
									<div className="sdaa-settings-section">
										<h3 className="sdaa-settings-section-title">
											{ __(
												'Model Parameters',
												'sd-ai-agent'
											) }
										</h3>
										<table className="form-table sdaa-form-table">
											<tbody>
												<tr>
													<th scope="row">
														<label htmlFor="sd-temperature">
															{ __(
																'Temperature',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<RangeControl
															id="sd-temperature"
															value={
																local.temperature
															}
															onChange={ ( v ) =>
																updateField(
																	'temperature',
																	v
																)
															}
															min={ 0 }
															max={ 1 }
															step={ 0.1 }
															help={ __(
																'Higher = more creative, lower = more deterministic.',
																'sd-ai-agent'
															) }
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														<label htmlFor="sd-max-output-tokens">
															{ __(
																'Max Output Tokens',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<TextControl
															id="sd-max-output-tokens"
															type="number"
															min={ 256 }
															max={ 32768 }
															value={
																local.max_output_tokens
															}
															onChange={ ( v ) =>
																updateField(
																	'max_output_tokens',
																	parseInt(
																		v,
																		10
																	) || 4096
																)
															}
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														<label htmlFor="sd-context-window">
															{ __(
																'Default Context Window',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<TextControl
															id="sd-context-window"
															type="number"
															min={ 4096 }
															max={ 2000000 }
															value={
																local.context_window_default
															}
															onChange={ ( v ) =>
																updateField(
																	'context_window_default',
																	parseInt(
																		v,
																		10
																	) || 128000
																)
															}
															help={ __(
																'Used as fallback when model context size is unknown.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
											</tbody>
										</table>

										<h3 className="sdaa-settings-section-title">
											{ __(
												'Integrations',
												'sd-ai-agent'
											) }
										</h3>
										<h4 className="sdaa-settings-subsection-title">
											{ __(
												'Google Analytics 4',
												'sd-ai-agent'
											) }
										</h4>
										<p className="description">
											{ __(
												'Connect to Google Analytics 4 to enable traffic analysis in the AI chat. You need a GA4 property ID and a Google service account JSON key with the "Viewer" role on your GA4 property.',
												'sd-ai-agent'
											) }
										</p>
										{ gaStatus?.has_credentials && (
											<Notice
												status="success"
												isDismissible={ false }
											>
												{ __(
													'Google Analytics is connected.',
													'sd-ai-agent'
												) }{ ' ' }
												{ gaStatus.property_id && (
													<strong>
														{ __(
															'Property ID:',
															'sd-ai-agent'
														) }{ ' ' }
														{ gaStatus.property_id }
													</strong>
												) }
											</Notice>
										) }
										{ gaNotice && (
											<Notice
												status={ gaNotice.status }
												isDismissible
												onDismiss={ () =>
													setGaNotice( null )
												}
											>
												{ gaNotice.message }
											</Notice>
										) }
										<table className="form-table sdaa-form-table">
											<tbody>
												<tr>
													<th scope="row">
														<label htmlFor="sd-ga-property-id">
															{ __(
																'GA4 Property ID',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<TextControl
															id="sd-ga-property-id"
															value={
																gaPropertyId
															}
															onChange={
																setGaPropertyId
															}
															placeholder="123456789"
															help={ __(
																'Your numeric GA4 property ID. Found in Google Analytics > Admin > Property Settings.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
												<tr>
													<th scope="row">
														<label htmlFor="sd-ga-service-json">
															{ __(
																'Service Account JSON Key',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<TextareaControl
															id="sd-ga-service-json"
															value={
																gaServiceJson
															}
															onChange={
																setGaServiceJson
															}
															placeholder={ __(
																'Paste the contents of your service account JSON key file here.',
																'sd-ai-agent'
															) }
															help={ __(
																'Download from Google Cloud Console > IAM & Admin > Service Accounts > Keys. Grant the service account "Viewer" access in GA4 Admin > Property Access Management.',
																'sd-ai-agent'
															) }
															rows={ 6 }
														/>
													</td>
												</tr>
											</tbody>
										</table>
										<div className="sdaa-settings-row-actions">
											<Button
												variant="primary"
												onClick={ handleGaSave }
												isBusy={ gaSaving }
												disabled={
													gaSaving ||
													! gaPropertyId ||
													! gaServiceJson
												}
											>
												{ __(
													'Save GA Credentials',
													'sd-ai-agent'
												) }
											</Button>
											{ gaStatus?.has_credentials && (
												<Button
													variant="secondary"
													onClick={ handleGaClear }
													isBusy={ gaSaving }
													disabled={ gaSaving }
												>
													{ __(
														'Disconnect',
														'sd-ai-agent'
													) }
												</Button>
											) }
										</div>

										<h4 className="sdaa-settings-subsection-title">
											{ __(
												'Internet Search',
												'sd-ai-agent'
											) }
										</h4>
										<p className="description">
											{ __(
												'The agent searches the internet to research topics and answer questions about current events. Configure one or more search providers below. Provider priority: Tavily > Brave > DuckDuckGo (free fallback). You can also paste an API key into the chat and ask the agent to save it.',
												'sd-ai-agent'
											) }
										</p>
										{ /* Active provider indicator */ }
										<Notice
											status="info"
											isDismissible={ false }
										>
											{ ( () => {
												if ( tavilyConfigured ) {
													return __(
														'Active provider: Tavily (best for AI agents).',
														'sd-ai-agent'
													);
												}
												if ( braveConfigured ) {
													return __(
														'Active provider: Brave Search.',
														'sd-ai-agent'
													);
												}
												return __(
													'Active provider: DuckDuckGo (free fallback — limited to instant answers). Configure Tavily or Brave for full web search.',
													'sd-ai-agent'
												);
											} )() }
										</Notice>

										{ /* ── Tavily ── */ }
										<h5 className="sdaa-settings-subsection-title">
											{ __( 'Tavily', 'sd-ai-agent' ) }
											{ tavilyConfigured && ' \u2713' }
										</h5>
										<p className="description">
											{ __(
												'Purpose-built search API for AI agents. Free tier includes 1,000 searches/month. Get an API key at',
												'sd-ai-agent'
											) }{ ' ' }
											<a
												href="https://app.tavily.com/"
												target="_blank"
												rel="noopener noreferrer"
											>
												app.tavily.com
											</a>
										</p>
										{ tavilyNotice && (
											<Notice
												status={ tavilyNotice.status }
												isDismissible
												onDismiss={ () =>
													setTavilyNotice( null )
												}
											>
												{ tavilyNotice.message }
											</Notice>
										) }
										<table className="form-table sdaa-form-table">
											<tbody>
												<tr>
													<th scope="row">
														<label htmlFor="sd-tavily-api-key">
															{ __(
																'Tavily API Key',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<TextControl
															id="sd-tavily-api-key"
															type="password"
															value={
																tavilyApiKey
															}
															onChange={
																setTavilyApiKey
															}
															placeholder={
																tavilyConfigured
																	? __(
																			'Key saved — enter a new key to replace it',
																			'sd-ai-agent'
																	  )
																	: 'tvly-...'
															}
															help={ __(
																'Get a free API key at app.tavily.com — the free tier includes 1,000 searches/month.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
											</tbody>
										</table>
										<div className="sdaa-settings-row-actions">
											<Button
												variant="primary"
												onClick={ handleTavilySave }
												isBusy={ tavilySaving }
												disabled={
													tavilySaving ||
													! tavilyApiKey
												}
											>
												{ __(
													'Save Tavily API Key',
													'sd-ai-agent'
												) }
											</Button>
											{ tavilyConfigured && (
												<Button
													variant="secondary"
													onClick={
														handleTavilyClear
													}
													isBusy={ tavilySaving }
													disabled={ tavilySaving }
												>
													{ __(
														'Remove Key',
														'sd-ai-agent'
													) }
												</Button>
											) }
										</div>

										{ /* ── Brave Search ── */ }
										<h5 className="sdaa-settings-subsection-title">
											{ __(
												'Brave Search',
												'sd-ai-agent'
											) }
											{ braveConfigured && ' \u2713' }
										</h5>
										<p className="description">
											{ __(
												'Rich web search results with snippets, news, and videos. Free tier includes 2,000 queries/month. Get an API key at',
												'sd-ai-agent'
											) }{ ' ' }
											<a
												href="https://brave.com/search/api/"
												target="_blank"
												rel="noopener noreferrer"
											>
												brave.com/search/api/
											</a>
										</p>
										{ braveNotice && (
											<Notice
												status={ braveNotice.status }
												isDismissible
												onDismiss={ () =>
													setBraveNotice( null )
												}
											>
												{ braveNotice.message }
											</Notice>
										) }
										<table className="form-table sdaa-form-table">
											<tbody>
												<tr>
													<th scope="row">
														<label htmlFor="sd-brave-api-key">
															{ __(
																'Brave Search API Key',
																'sd-ai-agent'
															) }
														</label>
													</th>
													<td>
														<TextControl
															id="sd-brave-api-key"
															type="password"
															value={
																braveApiKey
															}
															onChange={
																setBraveApiKey
															}
															placeholder={
																braveConfigured
																	? __(
																			'Key saved — enter a new key to replace it',
																			'sd-ai-agent'
																	  )
																	: 'BSA...'
															}
															help={ __(
																'Get a free API key at brave.com/search/api/ — the free tier includes 2,000 queries/month.',
																'sd-ai-agent'
															) }
															__nextHasNoMarginBottom
														/>
													</td>
												</tr>
											</tbody>
										</table>
										<div className="sdaa-settings-row-actions">
											<Button
												variant="primary"
												onClick={ handleBraveSave }
												isBusy={ braveSaving }
												disabled={
													braveSaving || ! braveApiKey
												}
											>
												{ __(
													'Save Brave API Key',
													'sd-ai-agent'
												) }
											</Button>
											{ braveConfigured && (
												<Button
													variant="secondary"
													onClick={ handleBraveClear }
													isBusy={ braveSaving }
													disabled={ braveSaving }
												>
													{ __(
														'Remove Key',
														'sd-ai-agent'
													) }
												</Button>
											) }
										</div>
									</div>
								);

							default:
								return null;
						}
					} }
				</TabPanel>
			</div>
			{ ! selfSavingTabs.includes( activeTab ) && (
				<div className="sdaa-settings-actions">
					<Button
						variant="primary"
						onClick={ handleSave }
						isBusy={ saving }
						disabled={ saving }
					>
						{ __( 'Save Settings', 'sd-ai-agent' ) }
					</Button>
				</div>
			) }
			<SnackbarList
				notices={ snackbarNotices }
				className="sdaa-snackbar-list"
				onRemove={ removeNotice }
			/>
		</div>
	);
}
