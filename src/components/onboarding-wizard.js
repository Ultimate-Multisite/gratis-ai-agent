/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { Button, ToggleControl, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import STORE_NAME from '../store';
import ProviderSelector from './provider-selector';

/**
 * Get the URL for the Connectors admin page.
 *
 * @return {string} Connectors page URL.
 */
function getConnectorsUrl() {
	return (
		window.sdAiAgentData?.connectorsUrl ||
		'options-general.php?page=options-connectors-wp-admin'
	);
}

/**
 * Whether the Connectors page is available (WP 7.0+ or Gutenberg 22.8.0+).
 *
 * wp_localize_script() converts PHP booleans to strings ('1' or ''),
 * so we check for truthiness rather than strict boolean comparison.
 *
 * @return {boolean} True when the Connectors page exists.
 */
function isConnectorsAvailable() {
	return !! window.sdAiAgentData?.connectorsAvailable;
}

/**
 * One-click Gutenberg install and activate button.
 *
 * Uses the WP REST Plugins API to install and activate Gutenberg in a
 * single request. Reloads the page on success so the Connectors page
 * becomes available.
 *
 * @return {JSX.Element} Install button with status feedback.
 */
function GutenbergInstallButton() {
	const [ busy, setBusy ] = useState( false );
	const [ installError, setInstallError ] = useState( null );

	const handleInstall = useCallback( async () => {
		setBusy( true );
		setInstallError( null );
		try {
			await apiFetch( {
				path: '/wp/v2/plugins',
				method: 'POST',
				data: { slug: 'gutenberg', status: 'active' },
			} );
			window.location.reload();
		} catch ( err ) {
			setInstallError(
				err?.message ||
					__( 'Failed to install Gutenberg.', 'sd-ai-agent' )
			);
			setBusy( false );
		}
	}, [] );

	return (
		<>
			{ installError && (
				<Notice status="error" isDismissible={ false }>
					{ installError }
				</Notice>
			) }
			<Button
				variant="primary"
				onClick={ handleInstall }
				isBusy={ busy }
				disabled={ busy }
				className="sdaa-wizard-connectors-link"
			>
				{ busy
					? __( 'Installing Gutenberg…', 'sd-ai-agent' )
					: __( 'Install & Activate Gutenberg', 'sd-ai-agent' ) }
			</Button>
		</>
	);
}

/**
 * Multi-step onboarding wizard shown on first activation.
 *
 * Steps: Welcome → Set Up AI Provider → Choose Your Start →
 *        Configure Abilities → WooCommerce Store → All Set.
 *
 * The provider step directs users to the WordPress Connectors page to
 * configure their API keys. Once at least one provider is available the
 * ProviderSelector appears to choose the default provider/model.
 *
 * The "Choose your start" step lets the user pick between three modes:
 * - 'explore'       → existing bootstrap flow (OnboardingBootstrap)
 * - 'theme-builder' → new theme-builder flow (OnboardingThemeBuilder)
 * - 'skip'          → skip onboarding, go straight to chat
 *
 * Saves settings (default provider/model, onboarding_complete for skip mode)
 * on finish or skip. For explore/theme-builder modes, onboarding_complete is
 * set by the bootstrapper REST endpoint rather than here.
 *
 * @param {Object}   props            - Component props.
 * @param {Function} props.onComplete - Called when the wizard is finished.
 *                                    Receives the chosen mode string:
 *                                    'explore' | 'theme-builder' | 'skip'.
 * @return {JSX.Element} The onboarding wizard element.
 */
export default function OnboardingWizard( { onComplete } ) {
	const [ step, setStep ] = useState( 0 );
	const [ abilities, setAbilities ] = useState( [] );
	const [ wooStatus, setWooStatus ] = useState( null );
	const [ wooLoading, setWooLoading ] = useState( false );
	const [ wooOfferAccepted, setWooOfferAccepted ] = useState( false );

	/**
	 * The user's chosen onboarding mode.
	 * 'explore'       — explore and bootstrap the existing site.
	 * 'theme-builder' — design a custom theme with the Theme Builder agent.
	 * 'skip'          — skip onboarding and go straight to chat.
	 *
	 * Initialised to 'explore'. Updated to 'theme-builder' when the mode-picker
	 * step mounts and the site is found to have no published content.
	 */
	const [ onboardingMode, setOnboardingMode ] = useState( 'explore' );

	/**
	 * Whether the site has any published posts or pages.
	 * null = still loading, true/false = resolved.
	 * Used to pick the default mode in the mode-picker step.
	 */
	const [ siteHasPosts, setSiteHasPosts ] = useState( null );

	const { saveSettings } = useDispatch( STORE_NAME );
	const { providers, selectedProviderId, selectedModelId } = useSelect(
		( select ) => ( {
			providers: select( STORE_NAME ).getProviders(),
			selectedProviderId: select( STORE_NAME ).getSelectedProviderId(),
			selectedModelId: select( STORE_NAME ).getSelectedModelId(),
		} ),
		[]
	);

	useEffect( () => {
		apiFetch( { path: '/sd-ai-agent/v1/abilities' } )
			.then( setAbilities )
			.catch( () => {} );
	}, [] );

	// Fetch WooCommerce status when we reach the WooCommerce step (step 4).
	useEffect( () => {
		if ( step !== 4 ) {
			return;
		}
		setWooLoading( true );
		apiFetch( { path: '/sd-ai-agent/v1/woocommerce/status' } )
			.then( ( data ) => {
				setWooStatus( data );
				setWooLoading( false );
			} )
			.catch( () => {
				setWooStatus( { active: false } );
				setWooLoading( false );
			} );
	}, [ step ] );

	// Probe for published content when we reach the mode-picker step (step 2).
	// Sets the default onboarding mode: 'theme-builder' for an empty install,
	// 'explore' when the site already has content.
	useEffect( () => {
		if ( step !== 2 ) {
			return;
		}
		apiFetch( { path: '/wp/v2/posts?per_page=1&status=publish' } )
			.then( ( posts ) => {
				const hasPosts = Array.isArray( posts ) && posts.length > 0;
				setSiteHasPosts( hasPosts );
				if ( ! hasPosts ) {
					// Empty install — default to theme-builder.
					setOnboardingMode( 'theme-builder' );
				}
			} )
			.catch( () => {
				// On error keep the 'explore' default.
				setSiteHasPosts( true );
			} );
	}, [ step ] );

	const hasAnyProvider = providers.length > 0;

	/**
	 * Finish the wizard for explore/theme-builder modes.
	 *
	 * Saves the default provider/model. Does NOT set onboarding_complete —
	 * that is handled by the bootstrapper REST endpoint (bootstrap-start or
	 * theme-builder-start). Calls onComplete with the chosen mode so the
	 * parent can mount the correct bootstrapper component.
	 */
	const handleFinish = useCallback( async () => {
		await saveSettings( {
			default_provider: selectedProviderId,
			default_model: selectedModelId,
		} );
		onComplete( onboardingMode );
	}, [
		saveSettings,
		selectedProviderId,
		selectedModelId,
		onComplete,
		onboardingMode,
	] );

	/**
	 * Skip all remaining onboarding and go straight to chat.
	 *
	 * Sets onboarding_complete on the server so the wizard is not shown
	 * again on the next visit. Calls onComplete('skip') so the parent can
	 * proceed immediately to the ChatRedesign shell.
	 */
	const handleSkipAll = useCallback( async () => {
		await saveSettings( {
			onboarding_complete: true,
			default_provider: selectedProviderId,
			default_model: selectedModelId,
		} );
		onComplete( 'skip' );
	}, [ saveSettings, selectedProviderId, selectedModelId, onComplete ] );

	/**
	 * Render the WooCommerce onboarding step content.
	 *
	 * Shows a loading spinner while fetching store status, then either:
	 * - A "WooCommerce not detected" message (skip prompt), or
	 * - Store stats + an offer to use AI for product creation.
	 */
	const renderWooCommerceStep = () => {
		if ( wooLoading ) {
			return (
				<div className="sdaa-wizard-woo">
					<Spinner />
					<p>{ __( 'Checking for WooCommerce…', 'sd-ai-agent' ) }</p>
				</div>
			);
		}

		if ( ! wooStatus || ! wooStatus.active ) {
			return (
				<div className="sdaa-wizard-woo">
					<p>
						{ __(
							'WooCommerce is not active on this site. You can skip this step.',
							'sd-ai-agent'
						) }
					</p>
					<p className="description">
						{ __(
							'If you install WooCommerce later, the AI agent will automatically detect it and offer product management abilities.',
							'sd-ai-agent'
						) }
					</p>
				</div>
			);
		}

		const {
			version,
			currency,
			published_products: publishedProducts,
			total_products: totalProducts,
			pending_orders: pendingOrders,
			processing_orders: processingOrders,
		} = wooStatus;

		return (
			<div className="sdaa-wizard-woo">
				<Notice status="success" isDismissible={ false }>
					{ __( 'WooCommerce store detected!', 'sd-ai-agent' ) }
				</Notice>

				<div className="sdaa-wizard-woo-stats">
					<p>
						<strong>
							{ __( 'Store overview:', 'sd-ai-agent' ) }
						</strong>
					</p>
					<ul>
						{ version && (
							<li>
								{ __( 'WooCommerce version:', 'sd-ai-agent' ) }{ ' ' }
								<strong>{ version }</strong>
							</li>
						) }
						{ currency && (
							<li>
								{ __( 'Currency:', 'sd-ai-agent' ) }{ ' ' }
								<strong>{ currency }</strong>
							</li>
						) }
						<li>
							{ __( 'Published products:', 'sd-ai-agent' ) }{ ' ' }
							<strong>{ publishedProducts ?? 0 }</strong>
							{ totalProducts > publishedProducts && (
								<span className="description">
									{ ' ' }
									{ sprintf(
										/* translators: %d: number of total products */
										__(
											'(%d total including drafts)',
											'sd-ai-agent'
										),
										totalProducts
									) }
								</span>
							) }
						</li>
						{ ( pendingOrders > 0 || processingOrders > 0 ) && (
							<li>
								{ __( 'Active orders:', 'sd-ai-agent' ) }{ ' ' }
								<strong>
									{ ( pendingOrders ?? 0 ) +
										( processingOrders ?? 0 ) }
								</strong>{ ' ' }
								<span className="description">
									{ sprintf(
										/* translators: 1: pending count, 2: processing count */
										__(
											'(%1$d pending, %2$d processing)',
											'sd-ai-agent'
										),
										pendingOrders ?? 0,
										processingOrders ?? 0
									) }
								</span>
							</li>
						) }
					</ul>
				</div>

				<div className="sdaa-wizard-woo-offer">
					<p>
						{ __(
							'The AI agent can help you manage your store:',
							'sd-ai-agent'
						) }
					</p>
					<ul>
						<li>
							{ __(
								'Create products from a description or idea',
								'sd-ai-agent'
							) }
						</li>
						<li>
							{ __(
								'List, search, and update existing products',
								'sd-ai-agent'
							) }
						</li>
						<li>
							{ __(
								'Query orders and check store statistics',
								'sd-ai-agent'
							) }
						</li>
					</ul>

					<ToggleControl
						label={ __(
							'Enable WooCommerce abilities',
							'sd-ai-agent'
						) }
						help={ __(
							'Allows the AI agent to create and manage products and query orders. You can change this later in Settings.',
							'sd-ai-agent'
						) }
						checked={ wooOfferAccepted }
						onChange={ setWooOfferAccepted }
						__nextHasNoMarginBottom
					/>

					{ wooOfferAccepted && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'WooCommerce abilities will be enabled. Try asking: "Create a product called Summer T-Shirt for $29.99"',
								'sd-ai-agent'
							) }
						</Notice>
					) }
				</div>
			</div>
		);
	};

	/**
	 * Render the mode-picker step content.
	 *
	 * Shows three option buttons. The recommended default is highlighted
	 * based on whether the site has any published content:
	 * - non-empty install → "Explore my existing site" pre-selected
	 * - empty install     → "Design a custom theme" pre-selected
	 *
	 * Clicking "Explore" or "Design" stores the choice and advances to the
	 * next step. Clicking "Skip — just chat" marks onboarding complete and
	 * exits the wizard immediately.
	 */
	const renderModePickerStep = () => {
		const isLoadingDefault = siteHasPosts === null;

		return (
			<div className="sd-ai-agent-onboarding-mode-picker">
				<p>
					{ __(
						'How would you like to get started with Superdav AI Agent?',
						'superdav-ai-agent'
					) }
				</p>

				{ isLoadingDefault && (
					<div className="sd-ai-agent-onboarding-mode-picker__loading">
						<Spinner />
					</div>
				) }

				<div className="sd-ai-agent-onboarding-mode-picker__options">
					{ /* Explore option */ }
					<button
						type="button"
						className={ [
							'sd-ai-agent-onboarding-mode-picker__button',
							onboardingMode === 'explore'
								? 'sd-ai-agent-onboarding-mode-picker__button--selected'
								: '',
						]
							.filter( Boolean )
							.join( ' ' ) }
						onClick={ () => {
							setOnboardingMode( 'explore' );
							setStep( step + 1 );
						} }
					>
						<span className="sd-ai-agent-onboarding-mode-picker__button-title">
							{ __(
								'Explore my existing site',
								'superdav-ai-agent'
							) }
						</span>
						<span className="sd-ai-agent-onboarding-mode-picker__button-description">
							{ __(
								'The AI agent will analyse your site, introduce itself, and help you get the most out of your existing content.',
								'superdav-ai-agent'
							) }
						</span>
					</button>

					{ /* Theme builder option */ }
					<button
						type="button"
						className={ [
							'sd-ai-agent-onboarding-mode-picker__button',
							onboardingMode === 'theme-builder'
								? 'sd-ai-agent-onboarding-mode-picker__button--selected'
								: '',
						]
							.filter( Boolean )
							.join( ' ' ) }
						onClick={ () => {
							setOnboardingMode( 'theme-builder' );
							setStep( step + 1 );
						} }
					>
						<span className="sd-ai-agent-onboarding-mode-picker__button-title">
							{ __(
								'Design a custom theme',
								'superdav-ai-agent'
							) }
						</span>
						<span className="sd-ai-agent-onboarding-mode-picker__button-description">
							{ __(
								'Work with the Theme Builder agent to design a WordPress theme that matches your vision, colours, and style.',
								'superdav-ai-agent'
							) }
						</span>
					</button>

					{ /* Skip option */ }
					<button
						type="button"
						className="sd-ai-agent-onboarding-mode-picker__button sd-ai-agent-onboarding-mode-picker__button--skip"
						onClick={ handleSkipAll }
					>
						<span className="sd-ai-agent-onboarding-mode-picker__button-title">
							{ __( 'Skip — just chat', 'superdav-ai-agent' ) }
						</span>
						<span className="sd-ai-agent-onboarding-mode-picker__button-description">
							{ __(
								'Go straight to the chat interface. You can always start a guided flow later from the chat.',
								'superdav-ai-agent'
							) }
						</span>
					</button>
				</div>
			</div>
		);
	};

	const steps = [
		// Step 0: Welcome
		{
			title: __( 'Welcome to Superdav AI Agent', 'sd-ai-agent' ),
			content: (
				<div className="sdaa-wizard-welcome">
					<p>
						{ __(
							'Superdav AI Agent is an intelligent assistant that can interact with your WordPress site using registered abilities (tools).',
							'sd-ai-agent'
						) }
					</p>
					<p>
						{ __(
							"It can manage content, query data, run commands, and more — all through a natural chat interface. Let's get set up!",
							'sd-ai-agent'
						) }
					</p>
				</div>
			),
		},
		// Step 1: Provider setup — directs to the Connectors page
		{
			title: __( 'Set Up an AI Provider', 'sd-ai-agent' ),
			content: (
				<div className="sdaa-wizard-provider">
					<p>
						{ __(
							'The AI agent needs an API key for at least one AI provider (OpenAI, Anthropic, or Google AI). API keys are managed on the Connectors page.',
							'sd-ai-agent'
						) }
					</p>

					{ isConnectorsAvailable() ? (
						<Notice status="info" isDismissible={ false }>
							<a
								href={ getConnectorsUrl() }
								className="sdaa-wizard-connectors-link"
							>
								{ __(
									'Open Connectors page to configure a provider →',
									'sd-ai-agent'
								) }
							</a>
						</Notice>
					) : (
						<>
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'Your WordPress version does not include the Connectors page. Install the Gutenberg plugin (version 22.8.0 or newer) to continue.',
									'sd-ai-agent'
								) }
							</Notice>
							<GutenbergInstallButton />
						</>
					) }

					<p className="description">
						{ __(
							'Once you have configured a connector, come back here and continue the setup.',
							'sd-ai-agent'
						) }
					</p>

					{ hasAnyProvider && (
						<div className="sdaa-wizard-provider-selector">
							<p className="sdaa-wizard-provider-selector__label">
								{ __(
									'Choose your default provider and model:',
									'sd-ai-agent'
								) }
							</p>
							<ProviderSelector />
						</div>
					) }
				</div>
			),
		},
		// Step 2: Choose your start — mode picker (NEW).
		// Inserted after Provider setup so the user only sees the picker once
		// a provider is configured. Default selection follows site-content
		// heuristic: non-empty install → explore, empty install → theme-builder.
		{
			title: __( 'Choose Your Start', 'superdav-ai-agent' ),
			content: renderModePickerStep(),
		},
		// Step 3: Abilities overview (auto-discovery — no curation needed).
		{
			title: __( 'Abilities', 'sd-ai-agent' ),
			content: (
				<div className="sdaa-wizard-abilities">
					<p>
						{ __(
							'The agent will automatically discover and use any ability registered by your installed plugins. You do not need to curate them — frequently-used abilities are loaded directly each turn, and the rest are reachable via the built-in ability search.',
							'sd-ai-agent'
						) }
					</p>
					{ abilities.length > 0 && (
						<p className="description">
							{ sprintf(
								/* translators: %d: number of abilities */
								__(
									'%d abilities are currently registered on this site.',
									'sd-ai-agent'
								),
								abilities.length
							) }
						</p>
					) }
				</div>
			),
		},
		// Step 4: WooCommerce (content adapts based on detection result)
		{
			title: __( 'WooCommerce Store', 'sd-ai-agent' ),
			content: renderWooCommerceStep(),
		},
		// Step 5: Done
		{
			title: __( 'All Set!', 'sd-ai-agent' ),
			content: (
				<div className="sdaa-wizard-done">
					<p>
						{ __(
							"You're all set! Superdav AI Agent is ready to help you manage your WordPress site.",
							'sd-ai-agent'
						) }
					</p>
					{ wooStatus?.active && wooOfferAccepted && (
						<p>
							{ __(
								'WooCommerce abilities are enabled. Try asking the agent to create a product or check your store stats.',
								'sd-ai-agent'
							) }
						</p>
					) }
					<p>
						{ __(
							'You can access it from the floating chat bubble on any admin page, or from the full-page chat under Tools > Superdav AI Agent.',
							'sd-ai-agent'
						) }
					</p>
				</div>
			),
		},
	];

	const current = steps[ step ];
	const isLast = step === steps.length - 1;

	return (
		<div className="sdaa-wizard">
			<div className="sdaa-wizard-header">
				<h2>{ current.title }</h2>
				<div className="sdaa-wizard-progress">
					{ steps.map( ( _, i ) => (
						<span
							key={ i }
							className={ `sdaa-wizard-dot ${
								i === step ? 'is-active' : ''
							} ${ i < step ? 'is-complete' : '' }` }
						/>
					) ) }
				</div>
			</div>
			<div className="sdaa-wizard-body">{ current.content }</div>
			<div className="sdaa-wizard-footer">
				{ step > 0 && (
					<Button
						variant="tertiary"
						onClick={ () => setStep( step - 1 ) }
					>
						{ __( 'Back', 'sd-ai-agent' ) }
					</Button>
				) }
				<Button
					variant="link"
					onClick={ handleSkipAll }
					className="sdaa-wizard-skip"
				>
					{ __( 'Skip', 'sd-ai-agent' ) }
				</Button>
				{ isLast ? (
					<Button variant="primary" onClick={ handleFinish }>
						{ __( 'Start Chatting', 'sd-ai-agent' ) }
					</Button>
				) : (
					// Hide Next on the mode-picker step (step 2): the user
					// advances by clicking one of the three mode buttons.
					step !== 2 && (
						<Button
							variant="primary"
							onClick={ () => setStep( step + 1 ) }
						>
							{ __( 'Next', 'sd-ai-agent' ) }
						</Button>
					)
				) }
			</div>
		</div>
	);
}
