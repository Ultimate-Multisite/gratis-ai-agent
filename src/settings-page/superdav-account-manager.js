/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * @param {number|string} micros Amount in millionths of a US dollar.
 * @return {string} Localized amount, or an em dash when unknown.
 */
function formatWalletAmount( micros ) {
	const amount = Number( micros );
	if ( ! Number.isFinite( amount ) ) {
		return '—';
	}

	return new Intl.NumberFormat( undefined, {
		style: 'currency',
		currency: 'USD',
	} ).format( amount / 1_000_000 );
}

/**
 * Manage the connected site's Superdav AI service account.
 *
 * Payment information is never entered or stored in WordPress. Billing actions
 * open the service-provided account portal in a separate tab.
 *
 * @return {JSX.Element} Account management panel.
 */
export default function SuperdavAccountManager() {
	const [ account, setAccount ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ error, setError ] = useState( '' );

	const loadAccount = useCallback( async ( refresh = false ) => {
		if ( refresh ) {
			setRefreshing( true );
		} else {
			setLoading( true );
		}
		setError( '' );

		try {
			const result = await apiFetch( {
				path: '/sd-ai-agent/v1/superdav-account',
				method: refresh ? 'POST' : 'GET',
			} );
			setAccount( result );
		} catch ( err ) {
			setError(
				err?.message ||
					__(
						'Unable to load your Superdav AI account.',
						'superdav-ai-agent'
					)
			);
		} finally {
			setLoading( false );
			setRefreshing( false );
		}
	}, [] );

	useEffect( () => {
		loadAccount();
	}, [ loadAccount ] );

	if ( loading ) {
		return (
			<div className="sdaa-superdav-account-loading">
				<Spinner />
			</div>
		);
	}

	const wallet = account?.wallet || {};
	const accountUrl = account?.account_portal_url || '';
	const configured = !! account?.configured;
	const tier = account?.tier || '';

	return (
		<div className="sdaa-superdav-account">
			<div className="sdaa-superdav-account-header">
				<div>
					<h3>
						{ __( 'Superdav AI account', 'superdav-ai-agent' ) }
					</h3>
					<p className="description">
						{ __(
							'View your available credits and securely manage billing with Superdav AI.',
							'superdav-ai-agent'
						) }
					</p>
				</div>
				<Button
					variant="secondary"
					onClick={ () => loadAccount( true ) }
					isBusy={ refreshing }
					disabled={ refreshing || ! configured }
				>
					{ __( 'Refresh balance', 'superdav-ai-agent' ) }
				</Button>
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! configured ? (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Superdav AI is not connected for this site yet. Connect a provider before managing account credits.',
						'superdav-ai-agent'
					) }
				</Notice>
			) : (
				<>
					<div className="sdaa-superdav-account-balance">
						<div className="sdaa-superdav-account-balance-label">
							{ __( 'Available balance', 'superdav-ai-agent' ) }
						</div>
						<div className="sdaa-superdav-account-balance-value">
							{ formatWalletAmount( wallet.total_usd_micros ) }
						</div>
						{ tier && (
							<div className="sdaa-superdav-account-tier">
								{ sprintf(
									/* translators: %s: Superdav AI service tier. */
									__( 'Plan: %s', 'superdav-ai-agent' ),
									tier
								) }
							</div>
						) }
					</div>

					<div className="sdaa-superdav-account-breakdown">
						<div>
							<span>
								{ __(
									'Purchased credits',
									'superdav-ai-agent'
								) }
							</span>
							<strong>
								{ formatWalletAmount( wallet.cash_usd_micros ) }
							</strong>
						</div>
						<div>
							<span>
								{ __(
									'Promotional credits',
									'superdav-ai-agent'
								) }
							</span>
							<strong>
								{ formatWalletAmount(
									wallet.promo_usd_micros
								) }
							</strong>
						</div>
					</div>

					{ accountUrl ? (
						<div className="sdaa-superdav-account-actions">
							<Button
								variant="primary"
								href={ accountUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Add credits', 'superdav-ai-agent' ) }
							</Button>
							<Button
								variant="secondary"
								href={ accountUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __(
									'Manage payment methods',
									'superdav-ai-agent'
								) }
							</Button>
							<Button
								variant="tertiary"
								href={ accountUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __(
									'Open account portal',
									'superdav-ai-agent'
								) }
							</Button>
						</div>
					) : (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Account billing is managed by your Superdav AI service administrator.',
								'superdav-ai-agent'
							) }
						</Notice>
					) }
				</>
			) }
		</div>
	);
}
