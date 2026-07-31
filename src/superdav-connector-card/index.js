/**
 * Custom Superdav AI card for WordPress Settings > Connectors.
 *
 * The core Connectors page normally renders API-key providers with a raw key
 * input. Superdav AI uses a service-managed site token, so this module replaces
 * that input with safe account status and a link to the plugin account settings.
 */

const MODULE_ID = '@sd-ai-agent/superdav-connector-card';
const dataElement = document.getElementById(
	`wp-script-module-data-${ MODULE_ID }`
);
const data = JSON.parse( dataElement?.textContent ?? '{}' );
const { createElement, useState } = window.wp.element;
const { Button, Notice } = window.wp.components;
const { __ } = window.wp.i18n;
let ConnectorItem;

/**
 * Format wallet values provided in millionths of a US dollar.
 *
 * @param {number|string|null|undefined} micros Amount in US-dollar micros.
 * @return {string} Formatted currency value, or an em dash when unknown.
 */
export function formatWalletAmount( micros ) {
	const amount = Number( micros );

	if (
		micros === null ||
		micros === undefined ||
		micros === '' ||
		! Number.isFinite( amount )
	) {
		return '—';
	}

	return new Intl.NumberFormat( undefined, {
		style: 'currency',
		currency: 'USD',
	} ).format( amount / 1_000_000 );
}

/**
 * Render the service-managed Superdav AI connector state.
 *
 * @param {Object} props             Connector render properties.
 * @param {string} props.name        Connector name in WordPress core.
 * @param {string} props.label       Connector label in Gutenberg.
 * @param {string} props.description Connector description.
 * @param {Object} props.logo        Connector logo in WordPress core.
 * @param {Object} props.icon        Connector icon in Gutenberg.
 * @return {JSX.Element} Connector card.
 */
function SuperdavConnectorCard( props ) {
	const { name, label, description, logo, icon } = props;
	const [ account, setAccount ] = useState( data.account || {} );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ error, setError ] = useState( '' );
	const configured = Boolean( account.configured );
	const wallet = account.wallet || {};

	const refreshAccount = async () => {
		setRefreshing( true );
		setError( '' );

		try {
			const response = await window.fetch( data.accountEndpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.nonce,
				},
			} );
			const result = await response.json();
			if ( ! response.ok ) {
				throw new Error( result?.message );
			}
			setAccount( result );
		} catch ( requestError ) {
			setError(
				requestError?.message ||
					__(
						'Unable to refresh your SD AI account.',
						'superdav-ai-agent'
					)
			);
		} finally {
			setRefreshing( false );
		}
	};

	const content = createElement(
		'section',
		{ className: 'sd-ai-agent-superdav-connector-card' },
		createElement(
			'p',
			{ className: 'description' },
			configured
				? __(
						'Connected with a secure service-managed token.',
						'superdav-ai-agent'
				  )
				: __(
						'Connect SD AI from the account settings page.',
						'superdav-ai-agent'
				  )
		),
		configured
			? createElement(
					'div',
					{
						className:
							'sd-ai-agent-superdav-connector-card__balance',
					},
					createElement(
						'span',
						{
							className:
								'sd-ai-agent-superdav-connector-card__balance-label',
						},
						__( 'Available balance', 'superdav-ai-agent' )
					),
					createElement(
						'strong',
						{
							className:
								'sd-ai-agent-superdav-connector-card__balance-value',
						},
						formatWalletAmount( wallet.total_usd_micros )
					),
					account.tier
						? createElement(
								'span',
								{
									className:
										'sd-ai-agent-superdav-connector-card__tier',
								},
								account.tier
						  )
						: null
			  )
			: null,
		error
			? createElement(
					Notice,
					{ status: 'error', isDismissible: false },
					error
			  )
			: null,
		createElement(
			'div',
			{ className: 'sd-ai-agent-superdav-connector-card__actions' },
			configured
				? createElement(
						Button,
						{
							variant: 'secondary',
							onClick: refreshAccount,
							isBusy: refreshing,
							disabled: refreshing,
						},
						__( 'Refresh balance', 'superdav-ai-agent' )
				  )
				: null,
			createElement(
				Button,
				{ variant: 'primary', href: data.settingsUrl },
				configured
					? __( 'Manage account and credits', 'superdav-ai-agent' )
					: __( 'Connect SD AI', 'superdav-ai-agent' )
			)
		)
	);

	return createElement(
		ConnectorItem,
		{
			name: name || label || data.name,
			label: name || label || data.name,
			description: description || data.description,
			logo: logo || icon || null,
			icon: logo || icon || null,
		},
		content
	);
}

// eslint-disable-next-line import/no-unresolved -- resolved by the WP import map.
import( '@wordpress/connectors' ).then( ( connectors ) => {
	const registerConnector =
		connectors.__experimentalRegisterConnector ||
		connectors.registerConnector;
	ConnectorItem =
		connectors.__experimentalConnectorItem || connectors.ConnectorItem;

	registerConnector( data.providerId, {
		name: data.name,
		label: data.name,
		description: data.description,
		render: SuperdavConnectorCard,
	} );
} );
