/**
 * WordPress dependencies
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

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
 * Connector gate shown before onboarding when no AI provider is configured.
 *
 * This is a hard gate: the chat and onboarding are inaccessible until at
 * least one AI connector (OpenAI, Anthropic, Google AI) is configured via
 * the WordPress Connectors page. The user sees only this screen until a
 * provider becomes available.
 *
 * On WP 6.9 without Gutenberg 22.8.0+, the Connectors page does not exist.
 * In that case, the user can install and activate Gutenberg with one click.
 *
 * Polling is handled by the parent (AdminPageApp) which calls fetchProviders
 * every 5 s and re-renders this component away once providers become available.
 *
 * @param {Object}   root0             Component props.
 * @param {Function} root0.onConnected Callback fired after managed connection.
 * @return {JSX.Element} The connector gate element.
 */
export default function ConnectorGate( { onConnected } = {} ) {
	const connectorsAvailable = isConnectorsAvailable();
	const [ installing, setInstalling ] = useState( false );
	const [ connecting, setConnecting ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const handleInstallGutenberg = useCallback( async () => {
		setInstalling( true );
		setError( null );
		try {
			await apiFetch( {
				path: '/wp/v2/plugins',
				method: 'POST',
				data: { slug: 'gutenberg', status: 'active' },
			} );
			// Reload so PHP detects GUTENBERG_VERSION and enables Connectors.
			window.location.reload();
		} catch ( err ) {
			setError(
				err?.message ||
					__(
						'Failed to install Gutenberg. Please install it manually from the Plugins page.',
						'superdav-ai-agent'
					)
			);
			setInstalling( false );
		}
	}, [] );

	const handleConnectSuperdavAi = useCallback( async () => {
		setConnecting( true );
		setError( null );
		setNotice( null );
		try {
			await apiFetch( {
				path: '/sd-ai-agent/v1/connectors/sd-ai-agent-cloud/connect',
				method: 'POST',
			} );
			setNotice(
				__(
					'Superdav AI is connected. Loading available models…',
					'superdav-ai-agent'
				)
			);
			if ( typeof onConnected === 'function' ) {
				await onConnected();
			}
		} catch ( err ) {
			setError(
				err?.message ||
					__(
						'Failed to connect Superdav AI. Please try again from the Connectors page.',
						'superdav-ai-agent'
					)
			);
		} finally {
			setConnecting( false );
		}
	}, [ onConnected ] );

	return (
		<div className="sdaa-connector-gate">
			<div className="sdaa-connector-gate__inner">
				<h2 className="sdaa-connector-gate__title">
					{ __( 'Connect Superdav AI', 'superdav-ai-agent' ) }
				</h2>

				<p className="sdaa-connector-gate__description">
					{ __(
						'Superdav AI is the recommended managed connection for first-time setup. Connect this site without pasting API keys, or choose another provider connector.',
						'superdav-ai-agent'
					) }
				</p>

				{ notice && (
					<Notice status="success" isDismissible={ false }>
						{ notice }
					</Notice>
				) }

				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				{ connectorsAvailable ? (
					<>
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Superdav AI uses a service-managed site token. Raw token values are never shown in the admin UI.',
								'superdav-ai-agent'
							) }
						</Notice>

						<div className="sdaa-connector-gate__actions">
							<Button
								variant="primary"
								onClick={ handleConnectSuperdavAi }
								isBusy={ connecting }
								disabled={ connecting }
								className="sdaa-connector-gate__cta"
							>
								{ connecting ? (
									<>
										<Spinner />
										{ __(
											'Connecting Superdav AI…',
											'superdav-ai-agent'
										) }
									</>
								) : (
									__(
										'Connect Superdav AI',
										'superdav-ai-agent'
									)
								) }
							</Button>
							<Button
								variant="secondary"
								href={ getConnectorsUrl() }
								className="sdaa-connector-gate__cta"
							>
								{ __(
									'Choose another connector →',
									'superdav-ai-agent'
								) }
							</Button>
						</div>
					</>
				) : (
					<>
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Your WordPress version does not include the Connectors page. Install the Gutenberg plugin (version 22.8.0 or newer) to configure AI providers.',
								'superdav-ai-agent'
							) }
						</Notice>

						<div className="sdaa-connector-gate__actions">
							<Button
								variant="primary"
								onClick={ handleInstallGutenberg }
								isBusy={ installing }
								disabled={ installing }
								className="sdaa-connector-gate__cta"
							>
								{ installing ? (
									<>
										<Spinner />
										{ __(
											'Installing Gutenberg…',
											'superdav-ai-agent'
										) }
									</>
								) : (
									__(
										'Install & Activate Gutenberg',
										'superdav-ai-agent'
									)
								) }
							</Button>
						</div>
					</>
				) }
			</div>
		</div>
	);
}
