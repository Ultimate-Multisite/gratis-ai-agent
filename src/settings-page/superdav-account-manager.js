/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
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
 * Format an actual usage charge without rounding sub-cent costs to zero.
 *
 * @param {number|string|null|undefined} micros Amount in USD micros.
 * @return {string} Localized usage cost.
 */
export function formatUsageCost( micros ) {
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
		minimumFractionDigits: 2,
		maximumFractionDigits: 6,
	} ).format( amount / 1_000_000 );
}

/**
 * Format a service timestamp in the WordPress site's configured timezone.
 *
 * @param {string|null|undefined} timestamp ISO-8601 timestamp.
 * @param {string|null|undefined} timeZone  WordPress site timezone identifier.
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
 * @param {number|string|null|undefined} tokens Token count.
 * @return {string} Localized integer token count.
 */
export function formatTokenCount( tokens ) {
	const count = Number( tokens );

	return new Intl.NumberFormat().format(
		Number.isFinite( count ) && count > 0 ? Math.floor( count ) : 0
	);
}

/**
 * @param {string} modelId Managed model identifier.
 * @return {string} User-facing model name.
 */
export function formatManagedModelName( modelId ) {
	const names = {
		'superdav-chat-fast': __( 'Speedy', 'superdav-ai-agent' ),
		'superdav-chat-pro': __( 'Standard', 'superdav-ai-agent' ),
		'superdav-chat-strong': __( 'Strong', 'superdav-ai-agent' ),
		'superdav-image': __( 'Image', 'superdav-ai-agent' ),
	};

	return (
		names[ modelId ] ||
		modelId ||
		__( 'Unknown model', 'superdav-ai-agent' )
	);
}

/**
 * Build a same-origin deep link to one local chat session.
 *
 * @param {number} sessionId Local chat session ID.
 * @return {string} Chat review URL.
 */
function getChatSessionUrl( sessionId ) {
	return `${ window.location.href.split( '#' )[ 0 ] }#/chat/${ sessionId }`;
}

/**
 * Render actual managed-service charges grouped by local chat session.
 *
 * @param {Object}     props          Component props.
 * @param {Array|null} props.sessions Safe owned session summaries.
 * @param {string}     props.timeZone Site timezone.
 * @return {JSX.Element} Session usage content.
 */
function ChatSessionUsage( { sessions, timeZone } ) {
	const [ expandedSessionId, setExpandedSessionId ] = useState( null );

	if ( sessions === null ) {
		return (
			<p className="description">
				{ __(
					'Session-level usage is unavailable. Reload the page and try again.',
					'superdav-ai-agent'
				) }
			</p>
		);
	}

	if ( sessions.length === 0 ) {
		return (
			<p className="description">
				{ __(
					'No chat session usage is available yet.',
					'superdav-ai-agent'
				) }
			</p>
		);
	}

	return (
		<ul className="sd-ai-agent-superdav-session-usage-list">
			{ sessions.map( ( session ) => {
				const sessionId = Number( session.session_id );
				const expanded = expandedSessionId === sessionId;
				const detailsId = `sd-ai-agent-session-usage-${ sessionId }`;
				const models = Array.isArray( session.models )
					? session.models
					: [];
				const modelNames = models
					.map( ( model ) =>
						formatManagedModelName( model.model_id )
					)
					.join( ', ' );

				return (
					<li key={ sessionId }>
						<button
							type="button"
							className="sd-ai-agent-superdav-session-usage-summary"
							aria-expanded={ expanded }
							aria-controls={ detailsId }
							onClick={ () =>
								setExpandedSessionId(
									expanded ? null : sessionId
								)
							}
						>
							<span className="sd-ai-agent-superdav-session-identity">
								<strong>{ session.title }</strong>
								<span>
									{ modelNames ||
										__(
											'Unknown model',
											'superdav-ai-agent'
										) }
									{ ' · ' }
									{ formatCreditActivityDate(
										session.last_used_at,
										timeZone
									) }
								</span>
							</span>
							<span className="sd-ai-agent-superdav-session-usage-totals">
								<span>
									{ sprintf(
										/* translators: %s: total number of tokens. */
										__( '%s tokens', 'superdav-ai-agent' ),
										formatTokenCount( session.total_tokens )
									) }
								</span>
								<strong>
									{ formatUsageCost(
										session.cost_usd_micros
									) }
								</strong>
							</span>
						</button>

						{ expanded && (
							<div
								id={ detailsId }
								className="sd-ai-agent-superdav-session-usage-details"
							>
								<dl>
									<div>
										<dt>
											{ __(
												'Input tokens',
												'superdav-ai-agent'
											) }
										</dt>
										<dd>
											{ formatTokenCount(
												session.input_tokens
											) }
										</dd>
									</div>
									<div>
										<dt>
											{ __(
												'Cached input tokens',
												'superdav-ai-agent'
											) }
										</dt>
										<dd>
											{ formatTokenCount(
												session.cached_input_tokens
											) }
										</dd>
									</div>
									<div>
										<dt>
											{ __(
												'Output tokens',
												'superdav-ai-agent'
											) }
										</dt>
										<dd>
											{ formatTokenCount(
												session.output_tokens
											) }
										</dd>
									</div>
									<div>
										<dt>
											{ __(
												'Agent loops',
												'superdav-ai-agent'
											) }
										</dt>
										<dd>{ session.loop_count || 0 }</dd>
									</div>
									<div>
										<dt>
											{ __(
												'Tool calls',
												'superdav-ai-agent'
											) }
										</dt>
										<dd>
											{ session.tool_call_count || 0 }
										</dd>
									</div>
									<div>
										<dt>
											{ __(
												'Actual cost',
												'superdav-ai-agent'
											) }
										</dt>
										<dd>
											{ formatUsageCost(
												session.cost_usd_micros
											) }
										</dd>
									</div>
								</dl>

								{ models.length > 1 && (
									<table className="sd-ai-agent-superdav-session-models">
										<thead>
											<tr>
												<th>
													{ __(
														'Model',
														'superdav-ai-agent'
													) }
												</th>
												<th>
													{ __(
														'Tokens',
														'superdav-ai-agent'
													) }
												</th>
												<th>
													{ __(
														'Loops',
														'superdav-ai-agent'
													) }
												</th>
												<th>
													{ __(
														'Cost',
														'superdav-ai-agent'
													) }
												</th>
											</tr>
										</thead>
										<tbody>
											{ models.map( ( model ) => (
												<tr key={ model.model_id }>
													<td>
														{ formatManagedModelName(
															model.model_id
														) }
													</td>
													<td>
														{ formatTokenCount(
															model.total_tokens
														) }
													</td>
													<td>
														{ model.loop_count }
													</td>
													<td>
														{ formatUsageCost(
															model.cost_usd_micros
														) }
													</td>
												</tr>
											) ) }
										</tbody>
									</table>
								) }

								<Button
									variant="secondary"
									href={ getChatSessionUrl( sessionId ) }
								>
									{ __(
										'Review full session',
										'superdav-ai-agent'
									) }
								</Button>
							</div>
						) }
					</li>
				);
			} ) }
		</ul>
	);
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
	const [ isCouponModalOpen, setIsCouponModalOpen ] = useState( false );
	const [ couponCode, setCouponCode ] = useState( '' );
	const [ redeeming, setRedeeming ] = useState( false );
	const [ redemptionNotice, setRedemptionNotice ] = useState( null );
	const [ actionNotice, setActionNotice ] = useState( null );
	const [ openingAction, setOpeningAction ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ hasLoadedAccount, setHasLoadedAccount ] = useState( false );

	const loadAccount = useCallback( async () => {
		setLoading( true );
		setError( '' );

		try {
			const result = await apiFetch( {
				path: '/sd-ai-agent/v1/superdav-account',
				method: 'POST',
			} );
			setAccount( result );
			setHasLoadedAccount( true );
		} catch ( err ) {
			setError(
				err?.message ||
					__(
						'Unable to load your SD AI account.',
						'superdav-ai-agent'
					)
			);
		} finally {
			setLoading( false );
		}
	}, [] );

	const openCouponModal = useCallback( () => {
		setCouponCode( '' );
		setRedemptionNotice( null );
		setIsCouponModalOpen( true );
	}, [] );

	const closeCouponModal = useCallback( () => {
		if ( redeeming ) {
			return;
		}

		setCouponCode( '' );
		setRedemptionNotice( null );
		setIsCouponModalOpen( false );
	}, [ redeeming ] );

	const openAccountAction = useCallback(
		async ( action ) => {
			if ( openingAction ) {
				return;
			}

			const portalWindow = window.open( 'about:blank', '_blank' );
			if ( portalWindow ) {
				portalWindow.opener = null;
			}

			setOpeningAction( action );
			setActionNotice( null );

			try {
				const result = await apiFetch( {
					path: '/sd-ai-agent/v1/superdav-account/action',
					method: 'POST',
					data: { action },
				} );

				if ( ! result?.url ) {
					throw new Error( 'invalid_account_action' );
				}

				if ( portalWindow ) {
					portalWindow.location.assign( result.url );
				} else {
					window.location.assign( result.url );
				}
			} catch {
				if ( portalWindow ) {
					portalWindow.close();
				}

				setActionNotice( {
					status: 'info',
					message: __(
						'Unable to open that SD AI account action. Try again.',
						'superdav-ai-agent'
					),
				} );
			} finally {
				setOpeningAction( '' );
			}
		},
		[ openingAction ]
	);

	const redeemCoupon = useCallback(
		async ( event ) => {
			event.preventDefault();
			const trimmedCode = couponCode.trim();
			if ( ! trimmedCode || redeeming ) {
				return;
			}

			setRedeeming( true );
			setRedemptionNotice( null );

			try {
				const result = await apiFetch( {
					path: '/sd-ai-agent/v1/superdav-account/redeem-coupon',
					method: 'POST',
					data: { coupon_code: trimmedCode },
				} );
				setAccount( result );
				setRedemptionNotice( {
					status: 'success',
					message: __(
						'Coupon redeemed. Your balance has been updated.',
						'superdav-ai-agent'
					),
				} );
				setCouponCode( '' );
				setIsCouponModalOpen( false );
			} catch ( err ) {
				const messages = {
					sd_ai_agent_coupon_invalid: __(
						'The coupon is invalid.',
						'superdav-ai-agent'
					),
					sd_ai_agent_coupon_expired: __(
						'The coupon has expired.',
						'superdav-ai-agent'
					),
					sd_ai_agent_coupon_revoked: __(
						'The coupon is no longer available.',
						'superdav-ai-agent'
					),
					sd_ai_agent_coupon_not_eligible: __(
						'The coupon is not eligible for this site.',
						'superdav-ai-agent'
					),
				};
				setRedemptionNotice( {
					status: 'error',
					message:
						messages[ err?.code ] ||
						__(
							'Coupon redemption is temporarily unavailable.',
							'superdav-ai-agent'
						),
				} );
			} finally {
				setRedeeming( false );
			}
		},
		[ couponCode, redeeming ]
	);

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
	const accountPortalAvailable = !! account?.account_portal_available;
	const purchaseCreditsAvailable = !! account?.purchase_credits_available;
	const paymentMethodsAvailable = !! account?.payment_methods_available;
	const linkAccountAvailable = !! account?.link_account_available;
	const linkedUser = account?.linked_user || null;
	const hasAccountActions =
		purchaseCreditsAvailable ||
		paymentMethodsAvailable ||
		accountPortalAvailable ||
		linkAccountAvailable;
	const configured = !! account?.configured;
	const tier = account?.tier || '';
	const chatSessions = Array.isArray( account?.chat_sessions )
		? account.chat_sessions
		: null;
	const creditActivity = Array.isArray( account?.credit_activity )
		? account.credit_activity.filter(
				( event ) =>
					event.type !== 'consumed' &&
					( event.type !== 'pending' ||
						event.label === 'Credit adjustment' )
		  )
		: null;
	const siteTimezone = account?.site_timezone || '';
	let creditActivityContent = (
		<p className="description">
			{ __(
				'Other credit activity is unavailable.',
				'superdav-ai-agent'
			) }
		</p>
	);

	if ( creditActivity !== null && creditActivity.length === 0 ) {
		creditActivityContent = (
			<p className="description">
				{ __(
					'No purchases, promotions, or adjustments are available.',
					'superdav-ai-agent'
				) }
			</p>
		);
	}

	if ( creditActivity !== null && creditActivity.length > 0 ) {
		creditActivityContent = (
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
									{ formatCreditActivityType( event.type ) }
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
		);
	}

	return (
		<div className="sd-ai-agent-superdav-account">
			<div className="sd-ai-agent-superdav-account-header">
				<div>
					<h3>{ __( 'SD AI account', 'superdav-ai-agent' ) }</h3>
					<p className="description">
						{ __(
							'View your available credits and securely manage billing with SD AI.',
							'superdav-ai-agent'
						) }
					</p>
				</div>
				<div className="sd-ai-agent-superdav-account-header-actions">
					{ accountPortalAvailable && (
						<Button
							variant="tertiary"
							onClick={ () =>
								openAccountAction( 'account_portal' )
							}
							isBusy={ openingAction === 'account_portal' }
							disabled={ !! openingAction }
						>
							{ __( 'Open account portal', 'superdav-ai-agent' ) }
						</Button>
					) }
					{ paymentMethodsAvailable && (
						<Button
							variant="secondary"
							onClick={ () =>
								openAccountAction( 'payment_methods' )
							}
							isBusy={ openingAction === 'payment_methods' }
							disabled={ !! openingAction }
						>
							{ __(
								'Manage payment methods',
								'superdav-ai-agent'
							) }
						</Button>
					) }
					<Button
						variant="primary"
						onClick={ openCouponModal }
						disabled={ ! configured }
					>
						{ __( 'Redeem Coupon', 'superdav-ai-agent' ) }
					</Button>
					{ purchaseCreditsAvailable && (
						<Button
							variant="secondary"
							onClick={ () =>
								openAccountAction( 'purchase_credits' )
							}
							isBusy={ openingAction === 'purchase_credits' }
							disabled={ !! openingAction }
						>
							{ __( 'Add credits', 'superdav-ai-agent' ) }
						</Button>
					) }
				</div>
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ redemptionNotice && ! isCouponModalOpen && (
				<Notice
					status={ redemptionNotice.status }
					isDismissible={ false }
				>
					{ redemptionNotice.message }
				</Notice>
			) }
			{ actionNotice && (
				<Notice status={ actionNotice.status } isDismissible={ false }>
					{ actionNotice.message }
				</Notice>
			) }

			{ hasLoadedAccount &&
				( ! configured ? (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'SD AI is not connected for this site yet. Connect a provider before managing account credits.',
							'superdav-ai-agent'
						) }
					</Notice>
				) : (
					<>
						<section className="sd-ai-agent-superdav-linked-user">
							<div>
								<h4>
									{ __( 'Linked user', 'superdav-ai-agent' ) }
								</h4>
								{ linkedUser ? (
									<>
										<strong>
											{ linkedUser.display_name }
										</strong>
										<span>{ linkedUser.masked_email }</span>
										<span className="sd-ai-agent-superdav-linked-user-status">
											{ __(
												'Email verified',
												'superdav-ai-agent'
											) }
										</span>
									</>
								) : (
									<p className="description">
										{ __(
											'No verified user is linked to this site yet.',
											'superdav-ai-agent'
										) }
									</p>
								) }
							</div>
							{ linkAccountAvailable && (
								<Button
									variant="secondary"
									onClick={ () =>
										openAccountAction( 'link_account' )
									}
									isBusy={ openingAction === 'link_account' }
									disabled={ !! openingAction }
								>
									{ linkedUser
										? __(
												'Link a different user',
												'superdav-ai-agent'
										  )
										: __(
												'Connect account to site',
												'superdav-ai-agent'
										  ) }
								</Button>
							) }
						</section>

						<section
							className="sd-ai-agent-superdav-account-overview"
							aria-label={ __(
								'Available credits',
								'superdav-ai-agent'
							) }
						>
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
											__(
												'Plan: %s',
												'superdav-ai-agent'
											),
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
						</section>

						<section
							className="sd-ai-agent-superdav-session-usage"
							aria-labelledby="sd-ai-agent-superdav-session-usage-heading"
						>
							<h4 id="sd-ai-agent-superdav-session-usage-heading">
								{ __(
									'Credit usage by chat session',
									'superdav-ai-agent'
								) }
							</h4>
							<p className="description">
								{ __(
									'Actual SD AI charges and tokens are grouped by the chat that created them.',
									'superdav-ai-agent'
								) }
							</p>
							<ChatSessionUsage
								sessions={ chatSessions }
								timeZone={ siteTimezone }
							/>
						</section>

						<section
							className="sd-ai-agent-superdav-credit-activity"
							aria-labelledby="sd-ai-agent-superdav-credit-activity-heading"
						>
							<h4 id="sd-ai-agent-superdav-credit-activity-heading">
								{ __(
									'Other credit activity',
									'superdav-ai-agent'
								) }
							</h4>
							{ creditActivityContent }
						</section>

						{ ! hasAccountActions && (
							<Notice status="info" isDismissible={ false }>
								{ __(
									'Account billing is managed by your SD AI service administrator.',
									'superdav-ai-agent'
								) }
							</Notice>
						) }
					</>
				) ) }

			{ isCouponModalOpen && (
				<Modal
					title={ __( 'Redeem coupon', 'superdav-ai-agent' ) }
					onRequestClose={ closeCouponModal }
					className="sd-ai-agent-superdav-coupon-modal"
				>
					<form
						className="sd-ai-agent-superdav-coupon-redemption"
						onSubmit={ redeemCoupon }
					>
						<p className="description">
							{ __(
								'Enter your coupon code to verify it and add the credit to this account.',
								'superdav-ai-agent'
							) }
						</p>
						<TextControl
							label={ __( 'Coupon code', 'superdav-ai-agent' ) }
							value={ couponCode }
							onChange={ setCouponCode }
							autoComplete="off"
							disabled={ redeeming }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						{ redemptionNotice && (
							<Notice
								status={ redemptionNotice.status }
								isDismissible={ false }
							>
								{ redemptionNotice.message }
							</Notice>
						) }
						<div className="sd-ai-agent-superdav-coupon-modal-actions">
							<Button
								variant="tertiary"
								onClick={ closeCouponModal }
								disabled={ redeeming }
							>
								{ __( 'Cancel', 'superdav-ai-agent' ) }
							</Button>
							<Button
								variant="primary"
								type="submit"
								isBusy={ redeeming }
								disabled={ redeeming || ! couponCode.trim() }
							>
								{ __( 'Redeem coupon', 'superdav-ai-agent' ) }
							</Button>
						</div>
					</form>
				</Modal>
			) }
		</div>
	);
}
