/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * @param {number|string|null|undefined} micros Amount in millionths of a US dollar.
 * @return {string} Localized amount, or an em dash when unknown.
 */
export function formatWalletAmount( micros ) {
	if ( micros === null || micros === undefined || micros === '' ) {
		return '—';
	}

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
 * Format a service timestamp in the WordPress site's configured timezone.
 *
 * @param {string|null|undefined} timestamp ISO-8601 timestamp.
 * @param {string|null|undefined} timeZone WordPress site timezone identifier.
 * @return {string} Localized timestamp, or an unavailable label.
 */
export function formatCreditActivityDate( timestamp, timeZone ) {
	const date = new Date( timestamp || '' );
	if ( Number.isNaN( date.getTime() ) ) {
		return __( 'Unavailable', 'superdav-ai-agent' );
	}

	try {
		return new Intl.DateTimeFormat( undefined, {
			dateStyle: 'medium',
			timeStyle: 'short',
			timeZone: timeZone || undefined,
		} ).format( date );
	} catch {
		return __( 'Unavailable', 'superdav-ai-agent' );
	}
}

/**
 * Return a neutral, translated label for a safe credit activity type.
 *
 * @param {string} type Safe event type from the managed service.
 * @return {string} Activity label.
 */
export function formatCreditActivityType( type ) {
	const labels = {
		purchase: __( 'Purchased credit', 'superdav-ai-agent' ),
		promotion: __( 'Promotional credit', 'superdav-ai-agent' ),
		redeemed: __( 'Coupon redeemed', 'superdav-ai-agent' ),
		consumed: __( 'Credit usage', 'superdav-ai-agent' ),
		pending: __( 'Pending adjustment', 'superdav-ai-agent' ),
		expired: __( 'Expired credit', 'superdav-ai-agent' ),
	};

	return labels[ type ] || __( 'Credit activity', 'superdav-ai-agent' );
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
	const [ hasLoadedAccount, setHasLoadedAccount ] = useState( false );

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
			setHasLoadedAccount( true );
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
			<div className="sd-ai-agent-superdav-account-loading">
				<Spinner />
			</div>
		);
	}

	const wallet = account?.wallet || {};
	const accountUrl = account?.account_portal_url || '';
	const configured = !! account?.configured;
	const tier = account?.tier || '';
	const creditActivity = Array.isArray( account?.credit_activity )
		? account.credit_activity
		: null;
	const siteTimezone = account?.site_timezone || '';

	return (
		<div className="sd-ai-agent-superdav-account">
			<div className="sd-ai-agent-superdav-account-header">
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

			{ hasLoadedAccount &&
				( ! configured ? (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Superdav AI is not connected for this site yet. Connect a provider before managing account credits.',
							'superdav-ai-agent'
						) }
					</Notice>
				) : (
					<>
						<div className="sd-ai-agent-superdav-account-balance">
							<div className="sd-ai-agent-superdav-account-balance-label">
								{ __(
									'Available balance',
									'superdav-ai-agent'
								) }
							</div>
							<div className="sd-ai-agent-superdav-account-balance-value">
								{ formatWalletAmount(
									wallet.total_usd_micros
								) }
							</div>
							{ tier && (
								<div className="sd-ai-agent-superdav-account-tier">
									{ sprintf(
										/* translators: %s: Superdav AI service tier. */
										__( 'Plan: %s', 'superdav-ai-agent' ),
										tier
									) }
								</div>
							) }
						</div>

						<div className="sd-ai-agent-superdav-account-breakdown">
							<div>
								<span>
									{ __(
										'Purchased credits',
										'superdav-ai-agent'
									) }
								</span>
								<strong>
									{ formatWalletAmount(
										wallet.cash_usd_micros
									) }
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

						<section
							className="sd-ai-agent-superdav-credit-activity"
							aria-labelledby="sd-ai-agent-superdav-credit-activity-heading"
						>
							<h4 id="sd-ai-agent-superdav-credit-activity-heading">
								{ __( 'Credit activity', 'superdav-ai-agent' ) }
							</h4>
							{ creditActivity === null ? (
								<p className="description">
									{ __(
										'Recent credit activity is unavailable.',
										'superdav-ai-agent'
									) }
								</p>
							) : creditActivity.length === 0 ? (
								<p className="description">
									{ __(
										'No recent credit activity is available.',
										'superdav-ai-agent'
									) }
								</p>
							) : (
								<ol className="sd-ai-agent-superdav-credit-activity-list">
									{ creditActivity.map( ( event, index ) => {
										const showsExpiry =
											event.type === 'promotion' || event.expires_at;

										return (
											<li
												key={ `${ event.type }-${ event.effective_at }-${ index }` }
											>
												<div>
													<strong>
														{ formatCreditActivityType(
															event.type
														) }
													</strong>
													{ event.label && (
														<span className="sd-ai-agent-superdav-credit-activity-label">
															{ event.label }
														</span>
													) }
													<span className="sd-ai-agent-superdav-credit-activity-date">
														{ sprintf(
															/* translators: %s: localized effective timestamp. */
															__(
																'Effective: %s',
																'superdav-ai-agent'
															),
															formatCreditActivityDate(
																event.effective_at,
																siteTimezone
															)
														) }
													</span>
													{ showsExpiry && (
														<span className="sd-ai-agent-superdav-credit-activity-expiry">
															{ sprintf(
																/* translators: %s: localized expiry timestamp or unavailable label. */
																__(
																	'Expiry: %s',
																	'superdav-ai-agent'
																),
																formatCreditActivityDate(
																	event.expires_at,
																	siteTimezone
																)
															) }
														</span>
													) }
												</div>
												<strong className="sd-ai-agent-superdav-credit-activity-amount">
													{ formatWalletAmount(
														event.amount_usd_micros
													) }
												</strong>
											</li>
										);
									} ) }
								</ol>
							) }
						</section>

						{ accountUrl ? (
							<div className="sd-ai-agent-superdav-account-actions">
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
				) ) }
		</div>
	);
}
