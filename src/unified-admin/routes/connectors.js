/**
 * Connectors Route — polyfill Connectors admin page for WP 6.9.
 *
 * On WordPress 7.0+ (or WP 6.9 with Gutenberg 22.8.0+), provider API keys
 * are managed on the official Connectors page at
 * options-general.php?page=options-connectors-wp-admin. This route provides
 * an equivalent UI for WordPress 6.9 installations without Gutenberg.
 *
 * Features:
 * - Lists AI provider connector plugins and bundled Superdav AI
 * - Shows plugin install/activation status with action buttons
 * - Allows API key entry and storage (saved as connectors_ai_{id}_api_key)
 * - Detects WP 7.0+ and shows a redirect link to the native page
 *
 * Plugin install/activate calls the native /wp/v2/plugins REST API.
 * API key management calls /sd-ai-agent/v1/connectors/{id}/key.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	TextControl,
	Tooltip,
} from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useReducer,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import './connectors-style.css';

// ---------------------------------------------------------------------------
// State helpers
// ---------------------------------------------------------------------------

const INITIAL_STATE = {
	loading: true,
	error: null,
	providers: [],
	hasNative: false,
};

/**
 * State reducer for connectors page.
 *
 * @param {Object} state  Current state.
 * @param {Object} action Dispatched action.
 * @return {Object} Next state.
 */
function reducer( state, action ) {
	switch ( action.type ) {
		case 'FETCH_SUCCESS':
			return {
				...state,
				loading: false,
				providers: action.providers,
				hasNative: action.hasNative,
			};
		case 'FETCH_ERROR':
			return { ...state, loading: false, error: action.error };
		case 'PROVIDER_UPDATED':
			return {
				...state,
				providers: state.providers.map( ( p ) =>
					p.id === action.provider.id
						? { ...p, ...action.provider }
						: p
				),
			};
		default:
			return state;
	}
}

// ---------------------------------------------------------------------------
// ProviderCard
// ---------------------------------------------------------------------------

/**
 * Individual AI provider card with install/activate and API key controls.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.provider  Provider data from REST API.
 * @param {Function} props.onRefresh Callback to refresh the provider list.
 * @return {JSX.Element} Provider card element.
 */
function ProviderCard( { provider, onRefresh } ) {
	const [ apiKey, setApiKey ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ clearing, setClearing ] = useState( false );
	const [ connecting, setConnecting ] = useState( false );
	const [ installing, setInstalling ] = useState( false );
	const [ activating, setActivating ] = useState( false );
	const [ cardNotice, setCardNotice ] = useState( null );

	const clearCardNotice = useCallback( () => setCardNotice( null ), [] );

	// Derive the plugin file identifier for the WP REST Plugins API.
	// The API expects the plugin encoded as "folder%2Ffile" (folder/file without .php).
	const pluginFileEncoded = provider.plugin_file
		? encodeURIComponent( provider.plugin_file.replace( /\.php$/, '' ) )
		: '';

	const handleInstall = useCallback( async () => {
		setInstalling( true );
		setCardNotice( null );
		try {
			await apiFetch( {
				path: '/wp/v2/plugins',
				method: 'POST',
				data: {
					slug: provider.plugin_slug,
					status: 'inactive',
				},
			} );
			setCardNotice( {
				status: 'success',
				message: __(
					'Plugin installed. Click Activate to enable it.',
					'sd-ai-agent'
				),
			} );
			onRefresh();
		} catch ( err ) {
			setCardNotice( {
				status: 'error',
				message:
					err?.message || __( 'Installation failed.', 'sd-ai-agent' ),
			} );
		} finally {
			setInstalling( false );
		}
	}, [ provider.plugin_slug, onRefresh ] );

	const handleActivate = useCallback( async () => {
		setActivating( true );
		setCardNotice( null );
		try {
			await apiFetch( {
				path: `/wp/v2/plugins/${ pluginFileEncoded }`,
				method: 'PUT',
				data: { status: 'active' },
			} );
			setCardNotice( {
				status: 'success',
				message: __( 'Plugin activated.', 'sd-ai-agent' ),
			} );
			onRefresh();
		} catch ( err ) {
			setCardNotice( {
				status: 'error',
				message:
					err?.message || __( 'Activation failed.', 'sd-ai-agent' ),
			} );
		} finally {
			setActivating( false );
		}
	}, [ pluginFileEncoded, onRefresh ] );

	const handleSaveKey = useCallback( async () => {
		if ( ! apiKey.trim() ) {
			return;
		}
		setSaving( true );
		setCardNotice( null );
		try {
			await apiFetch( {
				path: `/sd-ai-agent/v1/connectors/${ provider.id }/key`,
				method: 'POST',
				data: { api_key: apiKey.trim() },
			} );
			setApiKey( '' );
			setCardNotice( {
				status: 'success',
				message: __( 'API key saved.', 'sd-ai-agent' ),
			} );
			onRefresh();
		} catch ( err ) {
			setCardNotice( {
				status: 'error',
				message:
					err?.message ||
					__( 'Failed to save API key.', 'sd-ai-agent' ),
			} );
		} finally {
			setSaving( false );
		}
	}, [ apiKey, provider.id, onRefresh ] );

	const handleManagedConnect = useCallback( async () => {
		setConnecting( true );
		setCardNotice( null );
		try {
			await apiFetch( {
				path: `/sd-ai-agent/v1/connectors/${ provider.id }/connect`,
				method: 'POST',
			} );
			setCardNotice( {
				status: 'success',
				message: __( 'Superdav AI connected.', 'sd-ai-agent' ),
			} );
			onRefresh();
		} catch ( err ) {
			setCardNotice( {
				status: 'error',
				message:
					err?.message ||
					__( 'Failed to connect Superdav AI.', 'sd-ai-agent' ),
			} );
		} finally {
			setConnecting( false );
		}
	}, [ provider.id, onRefresh ] );

	const handleClearKey = useCallback( async () => {
		/* eslint-disable no-alert */
		const confirmed = window.confirm(
			provider.managed
				? __( 'Disconnect Superdav AI for this site?', 'sd-ai-agent' )
				: __(
						'Clear the saved API key for this provider?',
						'sd-ai-agent'
				  )
		);
		/* eslint-enable no-alert */
		if ( ! confirmed ) {
			return;
		}
		setClearing( true );
		setCardNotice( null );
		try {
			await apiFetch( {
				path: `/sd-ai-agent/v1/connectors/${ provider.id }/key`,
				method: 'DELETE',
			} );
			setCardNotice( {
				status: 'success',
				message: provider.managed
					? __( 'Superdav AI disconnected.', 'sd-ai-agent' )
					: __( 'API key cleared.', 'sd-ai-agent' ),
			} );
			onRefresh();
		} catch ( err ) {
			setCardNotice( {
				status: 'error',
				message:
					err?.message ||
					__( 'Failed to clear API key.', 'sd-ai-agent' ),
			} );
		} finally {
			setClearing( false );
		}
	}, [ provider.id, provider.managed, onRefresh ] );

	const statusBadge = () => {
		if ( provider.managed && provider.configured ) {
			return (
				<span className="sd-ai-connectors__status sd-ai-connectors__status--active">
					{ __( 'Connected', 'sd-ai-agent' ) }
				</span>
			);
		}
		if ( provider.managed ) {
			return (
				<span className="sd-ai-connectors__status sd-ai-connectors__status--inactive">
					{ __( 'Available', 'sd-ai-agent' ) }
				</span>
			);
		}
		if ( provider.active ) {
			return (
				<span className="sd-ai-connectors__status sd-ai-connectors__status--active">
					{ __( 'Active', 'sd-ai-agent' ) }
				</span>
			);
		}
		if ( provider.installed ) {
			return (
				<span className="sd-ai-connectors__status sd-ai-connectors__status--inactive">
					{ __( 'Inactive', 'sd-ai-agent' ) }
				</span>
			);
		}
		return (
			<span className="sd-ai-connectors__status sd-ai-connectors__status--not-installed">
				{ __( 'Not Installed', 'sd-ai-agent' ) }
			</span>
		);
	};

	return (
		<Card className="sd-ai-connectors__provider-card">
			<CardHeader>
				<div className="sd-ai-connectors__provider-header">
					<div className="sd-ai-connectors__provider-title">
						<h3>{ provider.name }</h3>
						{ statusBadge() }
					</div>
					<div className="sd-ai-connectors__provider-actions">
						{ ! provider.managed && ! provider.installed && (
							<Button
								variant="secondary"
								onClick={ handleInstall }
								isBusy={ installing }
								disabled={ installing }
							>
								{ installing
									? __( 'Installing…', 'sd-ai-agent' )
									: __( 'Install', 'sd-ai-agent' ) }
							</Button>
						) }
						{ ! provider.managed &&
							provider.installed &&
							! provider.active && (
								<Button
									variant="primary"
									onClick={ handleActivate }
									isBusy={ activating }
									disabled={ activating }
								>
									{ activating
										? __( 'Activating…', 'sd-ai-agent' )
										: __( 'Activate', 'sd-ai-agent' ) }
								</Button>
							) }
					</div>
				</div>
			</CardHeader>
			<CardBody>
				{ cardNotice && (
					<Notice
						status={ cardNotice.status }
						isDismissible
						onRemove={ clearCardNotice }
						className="sd-ai-connectors__card-notice"
					>
						{ cardNotice.message }
					</Notice>
				) }

				<p className="sd-ai-connectors__description">
					{ provider.description }
				</p>

				{ provider.managed ? (
					<div className="sd-ai-connectors__managed-section">
						<p className="sd-ai-connectors__managed-status">
							{ provider.configured
								? __(
										'This site is connected with a service-managed site token. The token is stored securely and is never displayed.',
										'sd-ai-agent'
								  )
								: __(
										'Connect this site to Superdav AI without pasting API keys or installing a separate provider plugin.',
										'sd-ai-agent'
								  ) }
						</p>
						<div className="sd-ai-connectors__managed-actions">
							{ provider.configured ? (
								<Button
									variant="secondary"
									isDestructive
									onClick={ handleClearKey }
									isBusy={ clearing }
									disabled={ clearing }
								>
									{ clearing
										? __( 'Disconnecting…', 'sd-ai-agent' )
										: __( 'Disconnect', 'sd-ai-agent' ) }
								</Button>
							) : (
								<Button
									variant="primary"
									onClick={ handleManagedConnect }
									isBusy={ connecting }
									disabled={ connecting }
								>
									{ connecting
										? __( 'Connecting…', 'sd-ai-agent' )
										: __(
												'Connect Superdav AI',
												'sd-ai-agent'
										  ) }
								</Button>
							) }
							{ provider.status?.account_connect_url && (
								<Button
									variant="link"
									href={ provider.status.account_connect_url }
								>
									{ __( 'Connect account', 'sd-ai-agent' ) }
								</Button>
							) }
						</div>
					</div>
				) : (
					<div className="sd-ai-connectors__api-key-section">
						<div className="sd-ai-connectors__api-key-label">
							<strong>{ __( 'API Key', 'sd-ai-agent' ) }</strong>
							{ provider.configured && provider.masked_key && (
								<span className="sd-ai-connectors__api-key-configured">
									{ __( 'Configured:', 'sd-ai-agent' ) }{ ' ' }
									<code>{ provider.masked_key }</code>
									<Tooltip
										text={ __(
											'Clear this API key',
											'sd-ai-agent'
										) }
									>
										<Button
											variant="link"
											isDestructive
											onClick={ handleClearKey }
											isBusy={ clearing }
											disabled={ clearing }
											className="sd-ai-connectors__clear-key-btn"
										>
											{ __( 'Clear', 'sd-ai-agent' ) }
										</Button>
									</Tooltip>
								</span>
							) }
						</div>
						<div className="sd-ai-connectors__api-key-input">
							<TextControl
								label={ __( 'Enter API Key', 'sd-ai-agent' ) }
								hideLabelFromVision
								value={ apiKey }
								onChange={ setApiKey }
								type="password"
								placeholder={
									provider.configured
										? __(
												'Enter new key to replace the existing one',
												'sd-ai-agent'
										  )
										: __(
												'Paste your API key here',
												'sd-ai-agent'
										  )
								}
								disabled={ saving }
							/>
							<Button
								variant="primary"
								onClick={ handleSaveKey }
								isBusy={ saving }
								disabled={ saving || ! apiKey.trim() }
							>
								{ saving
									? __( 'Saving…', 'sd-ai-agent' )
									: __( 'Save Key', 'sd-ai-agent' ) }
							</Button>
						</div>
					</div>
				) }
			</CardBody>
		</Card>
	);
}

// ---------------------------------------------------------------------------
// ConnectorsRoute (main export)
// ---------------------------------------------------------------------------

/**
 * Connectors Route Component.
 *
 * Shows AI provider connector cards on WP 6.9. On WP 7.0+, renders a
 * redirect notice pointing to the native Connectors page.
 *
 * @return {JSX.Element} Connectors route element.
 */
export default function ConnectorsRoute() {
	const [ state, dispatch ] = useReducer( reducer, INITIAL_STATE );

	const fetchProviders = useCallback( async () => {
		try {
			const data = await apiFetch( {
				path: '/sd-ai-agent/v1/connectors',
			} );
			dispatch( {
				type: 'FETCH_SUCCESS',
				providers: data.providers || [],
				hasNative: !! data.wp_has_native,
			} );
		} catch ( err ) {
			dispatch( {
				type: 'FETCH_ERROR',
				error:
					err?.message ||
					__( 'Failed to load connectors.', 'sd-ai-agent' ),
			} );
		}
	}, [] );

	useEffect( () => {
		fetchProviders();
	}, [ fetchProviders ] );

	const connectorsUrl =
		window.sdAiAgentData?.connectorsUrl ||
		'options-general.php?page=options-connectors-wp-admin';

	if ( state.loading ) {
		return (
			<div className="sdaa-route sdaa-route-connectors">
				<div className="sd-ai-connectors__loading">
					<Spinner />
					<span>{ __( 'Loading connectors…', 'sd-ai-agent' ) }</span>
				</div>
			</div>
		);
	}

	if ( state.error ) {
		return (
			<div className="sdaa-route sdaa-route-connectors">
				<Notice status="error" isDismissible={ false }>
					{ state.error }
				</Notice>
			</div>
		);
	}

	// On WP 7.0+: show a redirect notice instead of the full UI.
	// The native Connectors page handles everything.
	if ( state.hasNative ) {
		return (
			<div className="sdaa-route sdaa-route-connectors">
				<div className="sd-ai-connectors__native-redirect">
					<h2>{ __( 'Connectors', 'sd-ai-agent' ) }</h2>
					<Notice status="info" isDismissible={ false }>
						{ __(
							'WordPress 7.0+ includes a built-in Connectors page for managing AI provider API keys.',
							'sd-ai-agent'
						) }
					</Notice>
					<Button variant="primary" href={ connectorsUrl }>
						{ __( 'Open Connectors Page →', 'sd-ai-agent' ) }
					</Button>
				</div>
			</div>
		);
	}

	return (
		<div className="sdaa-route sdaa-route-connectors">
			<div className="sd-ai-connectors__header">
				<h2>{ __( 'Connectors', 'sd-ai-agent' ) }</h2>
				<p className="sd-ai-connectors__intro">
					{ __(
						'Install and configure AI provider plugins. Each provider needs an API key to connect Superdav AI Agent to its models.',
						'sd-ai-agent'
					) }
				</p>
			</div>

			<div className="sd-ai-connectors__grid">
				{ state.providers.map( ( provider ) => (
					<ProviderCard
						key={ provider.id }
						provider={ provider }
						onRefresh={ fetchProviders }
					/>
				) ) }
			</div>
		</div>
	);
}
