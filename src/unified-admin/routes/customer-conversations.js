/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './customer-conversations.css';

const PAGE_SIZE = 20;

const sourceOptions = [
	{ label: __( 'All sources', 'superdav-ai-agent' ), value: '' },
	{ label: __( 'Public chat', 'superdav-ai-agent' ), value: 'public_embed' },
	{
		label: __( 'Customer runtime', 'superdav-ai-agent' ),
		value: 'customer_runtime',
	},
];

const statusOptions = [
	{ label: __( 'All statuses', 'superdav-ai-agent' ), value: '' },
	{ label: __( 'Queued', 'superdav-ai-agent' ), value: 'queued' },
	{
		label: __( 'Processing', 'superdav-ai-agent' ),
		value: 'processing',
	},
	{ label: __( 'Complete', 'superdav-ai-agent' ), value: 'complete' },
	{ label: __( 'Failed', 'superdav-ai-agent' ), value: 'failed' },
	{ label: __( 'Cancelled', 'superdav-ai-agent' ), value: 'cancelled' },
];

/**
 * Render an administrator-only, sanitized conversation review interface.
 *
 * @return {JSX.Element} Customer conversation review route.
 */
export default function CustomerConversationsRoute() {
	const [ draftFilters, setDraftFilters ] = useState( createInitialFilters );
	const [ filters, setFilters ] = useState( createInitialFilters );
	const [ offset, setOffset ] = useState( 0 );
	const [ reviews, setReviews ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ selected, setSelected ] = useState( null );
	const [ detailLoading, setDetailLoading ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ confirmation, setConfirmation ] = useState( null );

	const loadReviews = useCallback( async () => {
		setLoading( true );
		try {
			const params = new URLSearchParams( {
				limit: String( PAGE_SIZE ),
				offset: String( offset ),
			} );
			Object.entries( filters ).forEach( ( [ key, value ] ) => {
				if ( value ) {
					params.set( key, value );
				}
			} );
			const response = await apiFetch( {
				path: `/sd-ai-agent/v1/customer-conversations?${ params.toString() }`,
			} );
			setReviews(
				Array.isArray( response?.conversations )
					? response.conversations
					: []
			);
			setTotal( Number( response?.total ) || 0 );
		} catch {
			setReviews( [] );
			setTotal( 0 );
			setNotice( {
				status: 'error',
				message: __(
					'Unable to load customer conversations.',
					'superdav-ai-agent'
				),
			} );
		} finally {
			setLoading( false );
		}
	}, [ filters, offset ] );

	useEffect( () => {
		loadReviews();
	}, [ loadReviews ] );

	const loadDetail = useCallback(
		async ( id, turnOffset = 0, appendOlderTurns = false ) => {
			setDetailLoading( true );
			try {
				const response = await apiFetch( {
					path: `/sd-ai-agent/v1/customer-conversations/${ encodeURIComponent(
						id
					) }?turn_limit=100&turn_offset=${ turnOffset }`,
				} );
				setSelected( ( current ) => {
					if ( ! appendOlderTurns || current?.id !== response?.id ) {
						return response;
					}

					return {
						...response,
						transcript: [
							...( response.transcript || [] ),
							...( current.transcript || [] ),
						],
					};
				} );
			} catch {
				setNotice( {
					status: 'error',
					message: __(
						'Unable to load this customer conversation.',
						'superdav-ai-agent'
					),
				} );
			} finally {
				setDetailLoading( false );
			}
		},
		[]
	);

	const applyFilters = ( event ) => {
		event.preventDefault();
		setOffset( 0 );
		setFilters( draftFilters );
	};

	const updateDraftFilter = ( key, value ) => {
		setDraftFilters( ( current ) => ( { ...current, [ key ]: value } ) );
	};

	const deleteReview = async () => {
		if ( ! selected ) {
			return;
		}

		try {
			await apiFetch( {
				path: `/sd-ai-agent/v1/customer-conversations/${ encodeURIComponent(
					selected.id
				) }`,
				method: 'DELETE',
			} );
			setSelected( null );
			setNotice( {
				status: 'success',
				message: __(
					'Customer conversation deleted.',
					'superdav-ai-agent'
				),
			} );
			loadReviews();
		} catch {
			setNotice( {
				status: 'error',
				message: __(
					'Unable to delete this customer conversation.',
					'superdav-ai-agent'
				),
			} );
		}
	};

	const purgeReviews = async () => {
		try {
			const response = await apiFetch( {
				path: '/sd-ai-agent/v1/customer-conversations/purge',
				method: 'POST',
				data: { confirm: true, limit: 1000 },
			} );
			setSelected( null );
			setNotice( {
				status: 'success',
				message: sprintf(
					/* translators: %d: number of deleted retained customer conversations. */
					__(
						'Deleted %d retained customer conversations.',
						'superdav-ai-agent'
					),
					Number( response?.purged ) || 0
				),
			} );
			loadReviews();
		} catch {
			setNotice( {
				status: 'error',
				message: __(
					'Unable to purge retained customer conversations.',
					'superdav-ai-agent'
				),
			} );
		}
	};

	const confirmAction = () => {
		const action = confirmation;
		setConfirmation( null );
		if ( action === 'delete' ) {
			deleteReview();
			return;
		}
		if ( action === 'purge' ) {
			purgeReviews();
		}
	};

	let reviewList = null;
	if ( loading ) {
		reviewList = (
			<div className="sd-ai-agent-customer-conversations__loading">
				<Spinner />
			</div>
		);
	} else if ( reviews.length === 0 ) {
		reviewList = (
			<p className="sd-ai-agent-customer-conversations__empty">
				{ __(
					'No retained customer conversations match these filters.',
					'superdav-ai-agent'
				) }
			</p>
		);
	} else {
		reviewList = <ReviewList reviews={ reviews } onSelect={ loadDetail } />;
	}

	return (
		<div className="sdaa-route sd-ai-agent-customer-conversations">
			<div className="sd-ai-agent-customer-conversations__header">
				<div>
					<h2>
						{ __( 'Customer Conversations', 'superdav-ai-agent' ) }
					</h2>
					<p>
						{ __(
							'Review only sanitized, retained conversation content. Customer identifiers, tokens, tool data, and raw provider payloads are not shown here.',
							'superdav-ai-agent'
						) }
					</p>
				</div>
				<Button
					variant="secondary"
					isDestructive
					onClick={ () => setConfirmation( 'purge' ) }
				>
					{ __(
						'Purge retained conversations',
						'superdav-ai-agent'
					) }
				</Button>
			</div>

			{ notice && (
				<Notice
					status={ notice.status }
					isDismissible
					onDismiss={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<FilterControls
				filters={ draftFilters }
				onChange={ updateDraftFilter }
				onSubmit={ applyFilters }
			/>

			<div className="sd-ai-agent-customer-conversations__layout">
				<section
					aria-label={ __(
						'Conversation summaries',
						'superdav-ai-agent'
					) }
				>
					<div className="sd-ai-agent-customer-conversations__list-meta">
						{ sprintf(
							/* translators: %d: number of retained conversations. */
							__(
								'%d retained conversations',
								'superdav-ai-agent'
							),
							total
						) }
					</div>
					{ reviewList }
					<div className="sd-ai-agent-customer-conversations__pagination">
						<Button
							variant="secondary"
							disabled={ offset === 0 || loading }
							onClick={ () =>
								setOffset( Math.max( 0, offset - PAGE_SIZE ) )
							}
						>
							{ __( 'Previous', 'superdav-ai-agent' ) }
						</Button>
						<Button
							variant="secondary"
							disabled={ offset + PAGE_SIZE >= total || loading }
							onClick={ () => setOffset( offset + PAGE_SIZE ) }
						>
							{ __( 'Next', 'superdav-ai-agent' ) }
						</Button>
					</div>
				</section>

				<ConversationDetail
					detail={ selected }
					loading={ detailLoading }
					onClose={ () => setSelected( null ) }
					onDelete={ () => setConfirmation( 'delete' ) }
					onLoadEarlier={ () =>
						loadDetail(
							selected.id,
							Number( selected.transcript_offset ) +
								Number( selected.transcript_limit ),
							true
						)
					}
				/>
			</div>

			{ confirmation && (
				<ConfirmationModal
					action={ confirmation }
					onCancel={ () => setConfirmation( null ) }
					onConfirm={ confirmAction }
				/>
			) }
		</div>
	);
}

/**
 * Render review filter controls.
 *
 * @param {Object}   props          Filter control props.
 * @param {Object}   props.filters  Current draft filters.
 * @param {Function} props.onChange Filter change callback.
 * @param {Function} props.onSubmit Filter submit callback.
 * @return {JSX.Element} Filter controls.
 */
function FilterControls( { filters, onChange, onSubmit } ) {
	return (
		<form
			className="sd-ai-agent-customer-conversations__filters"
			onSubmit={ onSubmit }
		>
			<SelectControl
				label={ __( 'Source', 'superdav-ai-agent' ) }
				value={ filters.source }
				options={ sourceOptions }
				onChange={ ( value ) => onChange( 'source', value ) }
			/>
			<SelectControl
				label={ __( 'Status', 'superdav-ai-agent' ) }
				value={ filters.status }
				options={ statusOptions }
				onChange={ ( value ) => onChange( 'status', value ) }
			/>
			<TextControl
				label={ __( 'Agent ID', 'superdav-ai-agent' ) }
				type="number"
				min="0"
				value={ filters.agent }
				onChange={ ( value ) => onChange( 'agent', value ) }
			/>
			<TextControl
				label={ __( 'Search previews', 'superdav-ai-agent' ) }
				value={ filters.search }
				onChange={ ( value ) => onChange( 'search', value ) }
			/>
			<TextControl
				label={ __( 'From date', 'superdav-ai-agent' ) }
				type="date"
				value={ filters.date_from }
				onChange={ ( value ) => onChange( 'date_from', value ) }
			/>
			<TextControl
				label={ __( 'To date', 'superdav-ai-agent' ) }
				type="date"
				value={ filters.date_to }
				onChange={ ( value ) => onChange( 'date_to', value ) }
			/>
			<Button variant="primary" type="submit">
				{ __( 'Apply filters', 'superdav-ai-agent' ) }
			</Button>
		</form>
	);
}

/**
 * Render a sanitized review summary table.
 *
 * @param {Object}   props          Summary list props.
 * @param {Array}    props.reviews  Sanitized review summaries.
 * @param {Function} props.onSelect Detail selection callback.
 * @return {JSX.Element} Review summary table.
 */
function ReviewList( { reviews, onSelect } ) {
	return (
		<div className="sd-ai-agent-customer-conversations__table-wrap">
			<table className="widefat striped">
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Source', 'superdav-ai-agent' ) }
						</th>
						<th scope="col">
							{ __( 'Status', 'superdav-ai-agent' ) }
						</th>
						<th scope="col">
							{ __( 'Preview', 'superdav-ai-agent' ) }
						</th>
						<th scope="col">
							{ __( 'Turns', 'superdav-ai-agent' ) }
						</th>
						<th scope="col">
							{ __( 'Updated', 'superdav-ai-agent' ) }
						</th>
						<th scope="col">
							<span className="screen-reader-text">
								{ __( 'Actions', 'superdav-ai-agent' ) }
							</span>
						</th>
					</tr>
				</thead>
				<tbody>
					{ reviews.map( ( review ) => (
						<tr key={ review.id }>
							<td>{ formatSource( review.source ) }</td>
							<td>{ review.status || '—' }</td>
							<td>{ review.preview || '—' }</td>
							<td>{ Number( review.turn_count ) || 0 }</td>
							<td>{ formatDate( review.updated_at ) }</td>
							<td>
								<Button
									variant="tertiary"
									onClick={ () => onSelect( review.id ) }
								>
									{ __( 'View', 'superdav-ai-agent' ) }
								</Button>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * Render one bounded, sanitized review transcript.
 *
 * @param {Object}   props               Detail panel props.
 * @param {Object}   props.detail        Sanitized review detail.
 * @param {boolean}  props.loading       Whether a detail request is in progress.
 * @param {Function} props.onClose       Detail close callback.
 * @param {Function} props.onDelete      Deletion callback.
 * @param {Function} props.onLoadEarlier Earlier-transcript callback.
 * @return {JSX.Element} Detail panel.
 */
function ConversationDetail( {
	detail,
	loading,
	onClose,
	onDelete,
	onLoadEarlier,
} ) {
	return (
		<aside
			className="sd-ai-agent-customer-conversations__detail"
			aria-label={ __( 'Conversation detail', 'superdav-ai-agent' ) }
		>
			{ loading && <Spinner /> }
			{ ! detail && ! loading && (
				<p>
					{ __(
						'Select a conversation to view its sanitized transcript.',
						'superdav-ai-agent'
					) }
				</p>
			) }
			{ detail && (
				<>
					<div className="sd-ai-agent-customer-conversations__detail-header">
						<div>
							<strong>{ formatSource( detail.source ) }</strong>
							<span>{ detail.status || '—' }</span>
						</div>
						<Button variant="tertiary" onClick={ onClose }>
							{ __( 'Close', 'superdav-ai-agent' ) }
						</Button>
					</div>
					{ detail.transcript_has_more && (
						<Button
							variant="secondary"
							disabled={ loading }
							onClick={ onLoadEarlier }
						>
							{ __(
								'Load earlier messages',
								'superdav-ai-agent'
							) }
						</Button>
					) }
					<div className="sd-ai-agent-customer-conversations__transcript">
						{ ( detail.transcript || [] ).map( ( turn, index ) => (
							<div
								className={ `sd-ai-agent-customer-conversations__turn sd-ai-agent-customer-conversations__turn--${ turn.role }` }
								key={ `${ turn.role }-${ index }` }
							>
								<strong>{ formatRole( turn.role ) }</strong>
								<p>{ turn.content }</p>
							</div>
						) ) }
					</div>
					<Button
						variant="secondary"
						isDestructive
						onClick={ onDelete }
					>
						{ __(
							'Delete this conversation',
							'superdav-ai-agent'
						) }
					</Button>
				</>
			) }
		</aside>
	);
}

/**
 * Render confirmation before permanently deleting retained content.
 *
 * @param {Object}   props           Confirmation modal props.
 * @param {string}   props.action    Destructive action identifier.
 * @param {Function} props.onCancel  Cancellation callback.
 * @param {Function} props.onConfirm Confirmation callback.
 * @return {JSX.Element} Destructive-action confirmation modal.
 */
function ConfirmationModal( { action, onCancel, onConfirm } ) {
	const isPurge = action === 'purge';
	const title = isPurge
		? __( 'Purge retained conversations?', 'superdav-ai-agent' )
		: __( 'Delete retained conversation?', 'superdav-ai-agent' );
	let message = __(
		'This retained customer conversation will be permanently deleted. This cannot be undone.',
		'superdav-ai-agent'
	);
	if ( isPurge ) {
		message = __(
			'All currently retained customer conversations will be permanently deleted. This cannot be undone.',
			'superdav-ai-agent'
		);
	}

	return (
		<Modal title={ title } onRequestClose={ onCancel }>
			<p>{ message }</p>
			<div className="sd-ai-agent-customer-conversations__modal-actions">
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'superdav-ai-agent' ) }
				</Button>
				<Button variant="primary" isDestructive onClick={ onConfirm }>
					{ __( 'Delete permanently', 'superdav-ai-agent' ) }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * @return {Object} Empty review filters.
 */
function createInitialFilters() {
	return {
		source: '',
		status: '',
		agent: '',
		search: '',
		date_from: '',
		date_to: '',
	};
}

/**
 * @param {string} source Stored safe source enum.
 * @return {string} Human-readable source label.
 */
function formatSource( source ) {
	if ( source === 'public_embed' ) {
		return __( 'Public chat', 'superdav-ai-agent' );
	}
	if ( source === 'customer_runtime' ) {
		return __( 'Customer runtime', 'superdav-ai-agent' );
	}

	return __( 'Unknown', 'superdav-ai-agent' );
}

/**
 * @param {string} role Stored safe turn role.
 * @return {string} Human-readable turn role.
 */
function formatRole( role ) {
	return role === 'assistant'
		? __( 'Assistant', 'superdav-ai-agent' )
		: __( 'Visitor', 'superdav-ai-agent' );
}

/**
 * @param {string} value UTC MySQL timestamp.
 * @return {string} Localized timestamp or a neutral fallback.
 */
function formatDate( value ) {
	if ( ! value ) {
		return '—';
	}

	const date = new Date( `${ value }Z` );
	return Number.isNaN( date.getTime() ) ? '—' : date.toLocaleString();
}
