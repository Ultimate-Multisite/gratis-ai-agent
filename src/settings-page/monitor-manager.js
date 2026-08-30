/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	BaseControl,
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { pencil, plus, trash } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

const SCHEDULE_OPTIONS = [
	{ label: __( 'Hourly', 'superdav-ai-agent' ), value: 'hourly' },
	{
		label: __( 'Twice Daily', 'superdav-ai-agent' ),
		value: 'twicedaily',
	},
	{ label: __( 'Daily', 'superdav-ai-agent' ), value: 'daily' },
	{ label: __( 'Weekly', 'superdav-ai-agent' ), value: 'weekly' },
];

const TOOL_PROFILE_OPTIONS = [
	{ label: __( 'Default tool policy', 'superdav-ai-agent' ), value: '' },
	{
		label: __( 'Site health (least privilege)', 'superdav-ai-agent' ),
		value: 'site-health',
	},
];

const CHANNEL_TYPE_OPTIONS = [
	{ label: __( 'Slack', 'superdav-ai-agent' ), value: 'slack' },
	{ label: __( 'Discord', 'superdav-ai-agent' ), value: 'discord' },
];

const MAX_EVENT_WAKE_SOURCES = 4;

/** Return a blank notification channel definition. */
function emptyChannel() {
	return { type: 'slack', webhook_url: '', enabled: true };
}

/** Return a Monitor form definition that cannot schedule recurring work. */
function emptyMonitorForm() {
	return {
		name: '',
		description: '',
		prompt: '',
		monitor_scratch: '',
		monitor_event_wakes_enabled: false,
		monitor_event_sources: [],
		schedule: 'daily',
		tool_profile: '',
		max_iterations: 10,
		notification_channels: [],
		mode: 'monitor',
		enabled: false,
	};
}

/**
 * Return whether an API automation record uses the Monitor contract.
 *
 * @param {Object} automation Automation record.
 * @return {boolean} Whether the record is a Monitor.
 */
function isMonitor( automation ) {
	return automation?.mode === 'monitor';
}

/**
 * Return the human-readable outcome or execution status for a Monitor.
 *
 * @param {Object} monitor Monitor record.
 * @return {string} Localized outcome label.
 */
function getOutcomeLabel( monitor ) {
	if ( [ 'claimed', 'running' ].includes( monitor.execution_status ) ) {
		return __( 'Running', 'superdav-ai-agent' );
	}

	const labels = {
		quiet: __( 'Quiet', 'superdav-ai-agent' ),
		notify: __( 'Attention needed', 'superdav-ai-agent' ),
		blocked: __( 'Blocked', 'superdav-ai-agent' ),
		error: __( 'Error', 'superdav-ai-agent' ),
	};

	return (
		labels[ monitor.last_monitor_outcome ] ||
		__( 'Not checked yet', 'superdav-ai-agent' )
	);
}

/**
 * Return cautious, API-backed WP-Cron timing guidance for a Monitor.
 *
 * @param {Object} monitor Monitor record.
 * @return {string} Localized timing guidance.
 */
function getTimingMessage( monitor ) {
	if ( ! monitor.enabled ) {
		return __(
			'No recurring check is scheduled while this draft is disabled.',
			'superdav-ai-agent'
		);
	}

	if ( ! monitor.next_run_at ) {
		return __(
			'WP-Cron has not reported the next expected check yet. This is not a healthy/on-time signal.',
			'superdav-ai-agent'
		);
	}

	const expectedTime = Date.parse(
		`${ monitor.next_run_at.replace( ' ', 'T' ) }Z`
	);
	if ( ! Number.isNaN( expectedTime ) && expectedTime < Date.now() ) {
		return __(
			'The next expected check time has passed, so WP-Cron may be delayed.',
			'superdav-ai-agent'
		);
	}

	return __(
		'WP-Cron is traffic-dependent. This expected time is not a health or on-time guarantee.',
		'superdav-ai-agent'
	);
}

/**
 * Return a bounded, non-sensitive queue status from a Monitor API record.
 *
 * @param {Object} monitor Monitor record.
 * @return {Object} Compact queue status.
 */
function getWakeStatus( monitor ) {
	const status = monitor?.monitor_wake_status || {};
	const getCount = ( value ) =>
		Math.max( 0, Number.parseInt( value, 10 ) || 0 );

	return {
		pendingGroups: getCount( status.pending_groups ),
		pendingEvents: getCount( status.pending_events ),
		deferredGroups: getCount( status.deferred_groups ),
		claimedGroups: getCount( status.claimed_groups ),
		expiredGroups: getCount( status.expired_groups ),
	};
}

/**
 * Render compact operational status without exposing retained event metadata.
 *
 * @param {Object} status Compact queue status.
 * @return {string} Localized queue summary.
 */
function getWakeQueueMessage( status ) {
	const parts = [];
	if ( status.pendingGroups ) {
		parts.push(
			`${ status.pendingGroups } ${ __(
				'pending group(s)',
				'superdav-ai-agent'
			) } / ${ status.pendingEvents } ${ __(
				'event(s)',
				'superdav-ai-agent'
			) }`
		);
	}
	if ( status.deferredGroups ) {
		parts.push(
			`${ status.deferredGroups } ${ __(
				'deferred group(s)',
				'superdav-ai-agent'
			) }`
		);
	}
	if ( status.claimedGroups ) {
		parts.push(
			`${ status.claimedGroups } ${ __(
				'claimed group(s)',
				'superdav-ai-agent'
			) }`
		);
	}
	if ( status.expiredGroups ) {
		parts.push(
			`${ status.expiredGroups } ${ __(
				'expired group(s)',
				'superdav-ai-agent'
			) }`
		);
	}

	return parts.length
		? parts.join( ', ' )
		: __( 'No event wakes are queued.', 'superdav-ai-agent' );
}

/**
 * Render the saved-draft Monitor configuration form.
 *
 * @param {Object}      root0                 Component props.
 * @param {Object}      root0.form            Current Monitor definition.
 * @param {number|null} root0.editId          Existing Monitor ID, if editing.
 * @param {boolean}     root0.saving          Whether the form is saving.
 * @param {Array}       root0.wakeSources     Approved event wake descriptors.
 * @param {Function}    root0.onChange        Update a form field.
 * @param {Function}    root0.onAddChannel    Add a notification channel.
 * @param {Function}    root0.onUpdateChannel Update one notification channel.
 * @param {Function}    root0.onRemoveChannel Remove one notification channel.
 * @param {Function}    root0.onSubmit        Save the draft.
 * @param {Function}    root0.onCancel        Close the form.
 * @return {JSX.Element} Monitor form.
 */
function MonitorForm( {
	form,
	editId,
	saving,
	wakeSources,
	onChange,
	onAddChannel,
	onUpdateChannel,
	onRemoveChannel,
	onSubmit,
	onCancel,
} ) {
	const selectedWakeSources = Array.isArray( form.monitor_event_sources )
		? form.monitor_event_sources
		: [];
	const hasSelectedWakeSource = selectedWakeSources.length > 0;
	const updateWakeSource = ( hookName, selected ) => {
		const nextSources = selected
			? [ ...selectedWakeSources, hookName ]
			: selectedWakeSources.filter( ( source ) => source !== hookName );
		onChange( 'monitor_event_sources', nextSources );
		if ( nextSources.length === 0 ) {
			onChange( 'monitor_event_wakes_enabled', false );
		}
	};

	return (
		<div className="sd-ai-agent-monitor-form">
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'New Monitors are saved as disabled drafts. Saving an existing Monitor does not change its enabled state. Recurring checks begin only when you choose Enable monitoring.',
					'superdav-ai-agent'
				) }
			</Notice>
			<TextControl
				label={ __( 'Monitor name', 'superdav-ai-agent' ) }
				value={ form.name }
				onChange={ ( value ) => onChange( 'name', value ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Description', 'superdav-ai-agent' ) }
				value={ form.description }
				onChange={ ( value ) => onChange( 'description', value ) }
				__nextHasNoMarginBottom
			/>
			<TextareaControl
				label={ __( 'Check instructions', 'superdav-ai-agent' ) }
				value={ form.prompt }
				onChange={ ( value ) => onChange( 'prompt', value ) }
				rows={ 5 }
				help={ __(
					'Describe what the Monitor should assess. It reports quietly unless attention is needed.',
					'superdav-ai-agent'
				) }
			/>
			<TextareaControl
				label={ __( 'Checklist / scratchpad', 'superdav-ai-agent' ) }
				value={ form.monitor_scratch }
				onChange={ ( value ) => onChange( 'monitor_scratch', value ) }
				rows={ 4 }
				help={ __(
					'Optional checklist context stored with this Monitor. An empty checklist completes quietly without an AI request.',
					'superdav-ai-agent'
				) }
			/>
			<div className="sd-ai-agent-monitor-event-wakes">
				<ToggleControl
					label={ __(
						'Allow selected site events to wake this Monitor',
						'superdav-ai-agent'
					) }
					checked={ Boolean( form.monitor_event_wakes_enabled ) }
					disabled={ ! hasSelectedWakeSource }
					help={
						hasSelectedWakeSource
							? __(
									'Event wakes are coalesced and processed by WP-Cron. They do not run the Monitor immediately.',
									'superdav-ai-agent'
							  )
							: __(
									'Select at least one approved source before enabling event wakes.',
									'superdav-ai-agent'
							  )
					}
					onChange={ ( value ) =>
						onChange( 'monitor_event_wakes_enabled', value )
					}
					__nextHasNoMarginBottom
				/>
				<BaseControl
					id="sd-ai-agent-monitor-event-wake-sources"
					label={ __(
						'Approved event sources',
						'superdav-ai-agent'
					) }
					help={ __(
						'Select up to four sources. Only the selected sources can create a coalesced Monitor wake.',
						'superdav-ai-agent'
					) }
					__nextHasNoMarginBottom
				>
					<div className="sd-ai-agent-monitor-event-wake-sources">
						{ wakeSources.length > 0 ? (
							wakeSources.map( ( source ) => {
								const hookName = source?.hook_name || '';
								if ( ! hookName ) {
									return null;
								}

								const isSelected =
									selectedWakeSources.includes( hookName );
								return (
									<CheckboxControl
										key={ hookName }
										label={ source.label || hookName }
										help={ source.description || '' }
										checked={ isSelected }
										disabled={
											! isSelected &&
											selectedWakeSources.length >=
												MAX_EVENT_WAKE_SOURCES
										}
										onChange={ ( value ) =>
											updateWakeSource( hookName, value )
										}
										__nextHasNoMarginBottom
									/>
								);
							} )
						) : (
							<p className="description">
								{ __(
									'No approved event sources are available right now.',
									'superdav-ai-agent'
								) }
							</p>
						) }
					</div>
				</BaseControl>
			</div>
			<div className="sd-ai-agent-monitor-form-grid">
				<SelectControl
					label={ __( 'Cadence', 'superdav-ai-agent' ) }
					value={ form.schedule }
					options={ SCHEDULE_OPTIONS }
					onChange={ ( value ) => onChange( 'schedule', value ) }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Tool profile', 'superdav-ai-agent' ) }
					value={ form.tool_profile }
					options={ TOOL_PROFILE_OPTIONS }
					onChange={ ( value ) => onChange( 'tool_profile', value ) }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Max iterations', 'superdav-ai-agent' ) }
					type="number"
					min={ 1 }
					max={ 50 }
					value={ form.max_iterations }
					onChange={ ( value ) =>
						onChange(
							'max_iterations',
							parseInt( value, 10 ) || 10
						)
					}
					__nextHasNoMarginBottom
				/>
			</div>
			<BaseControl
				id="sd-ai-agent-monitor-notification-channels"
				label={ __( 'Notification channels', 'superdav-ai-agent' ) }
				help={ __(
					'Only a validated attention-needed outcome sends a notification.',
					'superdav-ai-agent'
				) }
				__nextHasNoMarginBottom
			>
				{ ( form.notification_channels || [] ).map(
					( channel, index ) => (
						<div
							key={ index }
							className="sd-ai-agent-monitor-channel"
						>
							<SelectControl
								label={
									index === 0
										? __(
												'Channel type',
												'superdav-ai-agent'
										  )
										: undefined
								}
								value={ channel.type }
								options={ CHANNEL_TYPE_OPTIONS }
								onChange={ ( value ) =>
									onUpdateChannel( index, 'type', value )
								}
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={
									index === 0
										? __(
												'Webhook URL',
												'superdav-ai-agent'
										  )
										: undefined
								}
								value={ channel.webhook_url }
								onChange={ ( value ) =>
									onUpdateChannel(
										index,
										'webhook_url',
										value
									)
								}
								__nextHasNoMarginBottom
							/>
							<ToggleControl
								label={ __( 'Enabled', 'superdav-ai-agent' ) }
								checked={ Boolean( channel.enabled ) }
								onChange={ ( value ) =>
									onUpdateChannel( index, 'enabled', value )
								}
								__nextHasNoMarginBottom
							/>
							<Button
								icon={ trash }
								label={ __(
									'Remove notification channel',
									'superdav-ai-agent'
								) }
								onClick={ () => onRemoveChannel( index ) }
								isDestructive
								size="small"
							/>
						</div>
					)
				) }
				<Button
					variant="tertiary"
					icon={ plus }
					onClick={ onAddChannel }
					size="small"
				>
					{ __( 'Add channel', 'superdav-ai-agent' ) }
				</Button>
			</BaseControl>
			<div className="sd-ai-agent-monitor-form-actions">
				<Button
					variant="primary"
					onClick={ onSubmit }
					disabled={
						saving || ! form.name.trim() || ! form.prompt.trim()
					}
				>
					{ saving ? <Spinner /> : null }
					{ editId
						? __( 'Save Monitor', 'superdav-ai-agent' )
						: __( 'Save Monitor draft', 'superdav-ai-agent' ) }
				</Button>
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'superdav-ai-agent' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Render a Monitor/Pulse status card and its separate enable/check actions.
 *
 * @param {Object}      root0            Component props.
 * @param {Object}      root0.monitor    Monitor record.
 * @param {number|null} root0.running    Monitor currently executing a check.
 * @param {Array}       root0.logs       Loaded durable logs.
 * @param {number|null} root0.viewLogsId Monitor whose logs are visible.
 * @param {Function}    root0.onEnable   Enable recurring monitoring.
 * @param {Function}    root0.onDisable  Disable recurring monitoring.
 * @param {Function}    root0.onCheckNow Run a disabled draft once.
 * @param {Function}    root0.onViewLogs Toggle durable log visibility.
 * @param {Function}    root0.onEdit     Edit the Monitor definition.
 * @param {Function}    root0.onDelete   Delete the Monitor definition.
 * @return {JSX.Element} Monitor status card.
 */
function MonitorCard( {
	monitor,
	running,
	logs,
	viewLogsId,
	onEnable,
	onDisable,
	onCheckNow,
	onViewLogs,
	onEdit,
	onDelete,
} ) {
	const notificationCount = ( monitor.notification_channels || [] ).filter(
		( channel ) => channel.enabled
	).length;
	const isRunning = [ 'claimed', 'running' ].includes(
		monitor.execution_status
	);
	const outcome =
		monitor.last_monitor_outcome || monitor.execution_status || 'idle';
	const eventWakeSources = Array.isArray( monitor.monitor_event_sources )
		? monitor.monitor_event_sources
		: [];
	const eventWakesEnabled = Boolean( monitor.monitor_event_wakes_enabled );
	const wakeStatus = getWakeStatus( monitor );
	const hasWakeActivity = Object.values( wakeStatus ).some( Boolean );

	return (
		<article
			className={ `sd-ai-agent-monitor-card ${
				! monitor.enabled ? 'sd-ai-agent-monitor-card--disabled' : ''
			}` }
		>
			<div className="sd-ai-agent-monitor-card-header">
				<div>
					<h4>{ monitor.name }</h4>
					<p className="description">
						{ monitor.description || monitor.prompt }
					</p>
				</div>
				<span
					className={ `sd-ai-agent-monitor-status sd-ai-agent-monitor-status--${ outcome }` }
				>
					{ getOutcomeLabel( monitor ) }
				</span>
			</div>

			{ ! monitor.enabled && (
				<div className="sd-ai-agent-monitor-consent">
					<strong>
						{ __( 'Disabled draft', 'superdav-ai-agent' ) }
					</strong>
					<p>
						{ __(
							'Saving or checking this Monitor does not enable recurring checks. Review the context below, then use Enable monitoring to opt in.',
							'superdav-ai-agent'
						) }
					</p>
				</div>
			) }

			<dl className="sd-ai-agent-monitor-details">
				<div>
					<dt>{ __( 'Cadence', 'superdav-ai-agent' ) }</dt>
					<dd>{ monitor.schedule }</dd>
				</div>
				<div>
					<dt>{ __( 'Event wakes', 'superdav-ai-agent' ) }</dt>
					<dd>
						{ eventWakesEnabled
							? `${ eventWakeSources.length } ${ __(
									'selected source(s)',
									'superdav-ai-agent'
							  ) }`
							: __( 'Off', 'superdav-ai-agent' ) }
					</dd>
				</div>
				<div>
					<dt>{ __( 'Owner', 'superdav-ai-agent' ) }</dt>
					<dd>
						{ monitor.owner_user_id
							? `${ __( 'User', 'superdav-ai-agent' ) } #${
									monitor.owner_user_id
							  }`
							: __( 'Needs configuration', 'superdav-ai-agent' ) }
					</dd>
				</div>
				<div>
					<dt>{ __( 'Tool profile', 'superdav-ai-agent' ) }</dt>
					<dd>
						{ monitor.tool_profile ||
							__( 'Default tool policy', 'superdav-ai-agent' ) }
					</dd>
				</div>
				<div>
					<dt>
						{ __( 'Notification policy', 'superdav-ai-agent' ) }
					</dt>
					<dd>
						{ notificationCount
							? `${ notificationCount } ${ __(
									'attention channel(s)',
									'superdav-ai-agent'
							  ) }`
							: __(
									'No attention notifications',
									'superdav-ai-agent'
							  ) }
					</dd>
				</div>
				<div>
					<dt>{ __( 'Last check', 'superdav-ai-agent' ) }</dt>
					<dd>
						{ monitor.last_run_at ||
							__( 'Not checked yet', 'superdav-ai-agent' ) }
					</dd>
				</div>
				<div>
					<dt>{ __( 'Next expected', 'superdav-ai-agent' ) }</dt>
					<dd>
						{ monitor.next_run_at ||
							__( 'Not scheduled', 'superdav-ai-agent' ) }
					</dd>
				</div>
				{ isRunning && monitor.lease_expires_at && (
					<div>
						<dt>
							{ __( 'Execution lease', 'superdav-ai-agent' ) }
						</dt>
						<dd>{ monitor.lease_expires_at }</dd>
					</div>
				) }
			</dl>

			<p className="sd-ai-agent-monitor-timing">
				{ getTimingMessage( monitor ) }
			</p>
			{ monitor.monitor_timing_help && (
				<p className="sd-ai-agent-monitor-timing">
					{ monitor.monitor_timing_help }
				</p>
			) }
			{ ( eventWakesEnabled || hasWakeActivity ) && (
				<p className="sd-ai-agent-monitor-event-wake-status">
					<strong>
						{ __( 'Event wake queue:', 'superdav-ai-agent' ) }
					</strong>{ ' ' }
					{ getWakeQueueMessage( wakeStatus ) }
				</p>
			) }
			<p className="sd-ai-agent-monitor-cost">
				{ __( 'Cost context:', 'superdav-ai-agent' ) }{ ' ' }
				{ `${ monitor.max_iterations || 10 } ${ __(
					'AI iterations per check at most; actual provider cost depends on your configured model and budget.',
					'superdav-ai-agent'
				) }` }
			</p>
			{ monitor.last_monitor_summary && (
				<p className="sd-ai-agent-monitor-summary">
					<strong>
						{ __( 'Latest result:', 'superdav-ai-agent' ) }
					</strong>{ ' ' }
					{ monitor.last_monitor_summary }
				</p>
			) }
			{ monitor.last_run_error && (
				<p className="sd-ai-agent-monitor-error">
					<strong>
						{ __( 'Latest error:', 'superdav-ai-agent' ) }
					</strong>{ ' ' }
					{ monitor.last_run_error }
				</p>
			) }

			<div className="sd-ai-agent-monitor-actions">
				{ monitor.enabled ? (
					<Button
						variant="secondary"
						onClick={ () => onDisable( monitor ) }
					>
						{ __( 'Disable monitoring', 'superdav-ai-agent' ) }
					</Button>
				) : (
					<>
						<Button
							variant="primary"
							onClick={ () => onEnable( monitor ) }
						>
							{ __( 'Enable monitoring', 'superdav-ai-agent' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => onCheckNow( monitor ) }
							disabled={ running === monitor.id }
						>
							{ running === monitor.id ? <Spinner /> : null }
							{ __( 'Check now', 'superdav-ai-agent' ) }
						</Button>
					</>
				) }
				<Button
					variant="tertiary"
					onClick={ () => onViewLogs( monitor.id ) }
				>
					{ viewLogsId === monitor.id
						? __( 'Hide logs', 'superdav-ai-agent' )
						: __( 'Inspect logs', 'superdav-ai-agent' ) }
				</Button>
				<Button
					icon={ pencil }
					label={ __( 'Edit Monitor', 'superdav-ai-agent' ) }
					onClick={ () => onEdit( monitor ) }
					size="small"
				/>
				<Button
					icon={ trash }
					label={ __( 'Delete Monitor', 'superdav-ai-agent' ) }
					onClick={ () => onDelete( monitor ) }
					isDestructive
					size="small"
				/>
			</div>

			{ viewLogsId === monitor.id && (
				<div className="sd-ai-agent-monitor-logs">
					{ logs.length === 0 ? (
						<p className="description">
							{ __(
								'No durable logs have been recorded yet.',
								'superdav-ai-agent'
							) }
						</p>
					) : (
						logs.map( ( log ) => (
							<div
								key={ log.id }
								className="sd-ai-agent-monitor-log"
							>
								<strong>
									{ log.monitor_outcome ||
										log.lifecycle_status }
								</strong>
								<span>{ log.created_at }</span>
								{ log.error_message && (
									<p>{ log.error_message }</p>
								) }
							</div>
						) )
					) }
				</div>
			) }
		</article>
	);
}

/** Render the opt-in Monitor/Pulse manager separate from scheduled tasks. */
export default function MonitorManager() {
	const [ monitors, setMonitors ] = useState( [] );
	const [ templates, setTemplates ] = useState( [] );
	const [ wakeSources, setWakeSources ] = useState( [] );
	const [ loaded, setLoaded ] = useState( false );
	const [ showForm, setShowForm ] = useState( false );
	const [ editId, setEditId ] = useState( null );
	const [ form, setForm ] = useState( emptyMonitorForm() );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ running, setRunning ] = useState( null );
	const [ logs, setLogs ] = useState( [] );
	const [ viewLogsId, setViewLogsId ] = useState( null );

	const fetchAll = useCallback( async () => {
		try {
			const [ automationResult, templateResult, sourceResult ] =
				await Promise.all( [
					apiFetch( { path: '/sd-ai-agent/v1/automations' } ),
					apiFetch( {
						path: '/sd-ai-agent/v1/automation-templates',
					} ),
					apiFetch( {
						path: '/sd-ai-agent/v1/monitor-wake-sources',
					} ).catch( () => [] ),
				] );
			setMonitors(
				Array.isArray( automationResult )
					? automationResult.filter( isMonitor )
					: []
			);
			setTemplates(
				Array.isArray( templateResult )
					? templateResult.filter( isMonitor )
					: []
			);
			setWakeSources( Array.isArray( sourceResult ) ? sourceResult : [] );
		} catch {
			setMonitors( [] );
			setTemplates( [] );
			setWakeSources( [] );
		} finally {
			setLoaded( true );
		}
	}, [] );

	useEffect( () => {
		fetchAll();
	}, [ fetchAll ] );

	const resetForm = useCallback( () => {
		setShowForm( false );
		setEditId( null );
		setForm( emptyMonitorForm() );
	}, [] );

	const updateForm = useCallback( ( key, value ) => {
		setForm( ( previous ) => ( { ...previous, [ key ]: value } ) );
	}, [] );

	const updateChannel = useCallback( ( index, key, value ) => {
		setForm( ( previous ) => {
			const channels = [ ...( previous.notification_channels || [] ) ];
			channels[ index ] = { ...channels[ index ], [ key ]: value };
			return { ...previous, notification_channels: channels };
		} );
	}, [] );

	const addChannel = useCallback( () => {
		setForm( ( previous ) => ( {
			...previous,
			notification_channels: [
				...( previous.notification_channels || [] ),
				emptyChannel(),
			],
		} ) );
	}, [] );

	const removeChannel = useCallback( ( index ) => {
		setForm( ( previous ) => {
			const channels = [ ...( previous.notification_channels || [] ) ];
			channels.splice( index, 1 );
			return { ...previous, notification_channels: channels };
		} );
	}, [] );

	const handleSubmit = useCallback( async () => {
		if ( ! form.name.trim() || ! form.prompt.trim() ) {
			setNotice( {
				status: 'error',
				message: __(
					'A Monitor name and check instructions are required.',
					'superdav-ai-agent'
				),
			} );
			return;
		}

		setSaving( true );
		setNotice( null );
		const payload = { ...form, mode: 'monitor', enabled: false };

		try {
			if ( editId ) {
				const { enabled, ...updates } = payload;
				await apiFetch( {
					path: `/sd-ai-agent/v1/automations/${ editId }`,
					method: 'PATCH',
					data: updates,
				} );
			} else {
				await apiFetch( {
					path: '/sd-ai-agent/v1/automations',
					method: 'POST',
					data: payload,
				} );
			}

			resetForm();
			await fetchAll();
			setNotice( {
				status: 'success',
				message: editId
					? __(
							'Monitor saved without changing its enabled state.',
							'superdav-ai-agent'
					  )
					: __(
							'Monitor saved as a disabled draft.',
							'superdav-ai-agent'
					  ),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error?.message ||
					__( 'Failed to save the Monitor.', 'superdav-ai-agent' ),
			} );
		} finally {
			setSaving( false );
		}
	}, [ editId, fetchAll, form, resetForm ] );

	const handleEdit = useCallback( ( monitor ) => {
		setEditId( monitor.id );
		setForm( {
			...emptyMonitorForm(),
			name: monitor.name || '',
			description: monitor.description || '',
			prompt: monitor.prompt || '',
			monitor_scratch: monitor.monitor_scratch || '',
			monitor_event_wakes_enabled: Boolean(
				monitor.monitor_event_wakes_enabled
			),
			monitor_event_sources: Array.isArray(
				monitor.monitor_event_sources
			)
				? monitor.monitor_event_sources
				: [],
			schedule: monitor.schedule || 'daily',
			tool_profile: monitor.tool_profile || '',
			max_iterations: monitor.max_iterations || 10,
			notification_channels: monitor.notification_channels || [],
			enabled: false,
		} );
		setShowForm( true );
	}, [] );

	const handleUseTemplate = useCallback( ( template ) => {
		setForm( {
			...emptyMonitorForm(),
			name: template.name || '',
			description: template.description || '',
			prompt: template.prompt || '',
			monitor_scratch: template.monitor_scratch || '',
			schedule: template.schedule || 'daily',
			tool_profile: template.tool_profile || '',
			max_iterations: template.max_iterations || 10,
			enabled: false,
		} );
		setEditId( null );
		setShowForm( true );
	}, [] );

	const updateEnabled = useCallback( async ( monitor, enabled ) => {
		const previous = monitor;
		setNotice( null );
		setMonitors( ( current ) =>
			current.map( ( item ) =>
				item.id === monitor.id ? { ...item, enabled } : item
			)
		);

		try {
			const updated = await apiFetch( {
				path: `/sd-ai-agent/v1/automations/${ monitor.id }`,
				method: 'PATCH',
				data: { enabled },
			} );
			setMonitors( ( current ) =>
				current.map( ( item ) =>
					item.id === monitor.id
						? { ...item, ...( updated || {} ) }
						: item
				)
			);
			setNotice( {
				status: 'success',
				message: enabled
					? __(
							'Monitor enabled. Its first recurring check is scheduled by WP-Cron.',
							'superdav-ai-agent'
					  )
					: __(
							'Monitor disabled. Future recurring checks were removed.',
							'superdav-ai-agent'
					  ),
			} );
		} catch ( error ) {
			setMonitors( ( current ) =>
				current.map( ( item ) =>
					item.id === monitor.id ? previous : item
				)
			);
			setNotice( {
				status: 'error',
				message:
					error?.message ||
					__(
						'The Monitor state could not be changed. Its previous state was restored.',
						'superdav-ai-agent'
					),
			} );
		}
	}, [] );

	const handleCheckNow = useCallback(
		async ( monitor ) => {
			setRunning( monitor.id );
			setNotice( null );

			try {
				const result = await apiFetch( {
					path: `/sd-ai-agent/v1/automations/${ monitor.id }/run`,
					method: 'POST',
					data: { manual_monitor_draft: true },
				} );
				await fetchAll();
				setNotice( {
					status:
						result.lifecycle_status === 'blocked'
							? 'warning'
							: 'success',
					message:
						result.lifecycle_status === 'blocked'
							? result.error_message ||
							  __(
									'The Monitor check was blocked.',
									'superdav-ai-agent'
							  )
							: __(
									'Monitor check completed without enabling recurring checks.',
									'superdav-ai-agent'
							  ),
				} );
			} catch ( error ) {
				setNotice( {
					status: 'error',
					message:
						error?.message ||
						__(
							'The Monitor check could not be started.',
							'superdav-ai-agent'
						),
				} );
			} finally {
				setRunning( null );
			}
		},
		[ fetchAll ]
	);

	const handleViewLogs = useCallback(
		async ( monitorId ) => {
			if ( viewLogsId === monitorId ) {
				setViewLogsId( null );
				setLogs( [] );
				return;
			}

			try {
				const result = await apiFetch( {
					path: `/sd-ai-agent/v1/automations/${ monitorId }/logs`,
				} );
				setLogs( Array.isArray( result ) ? result : [] );
				setViewLogsId( monitorId );
			} catch ( error ) {
				setNotice( {
					status: 'error',
					message:
						error?.message ||
						__(
							'Monitor logs could not be loaded.',
							'superdav-ai-agent'
						),
				} );
			}
		},
		[ viewLogsId ]
	);

	const handleDelete = useCallback(
		async ( monitor ) => {
			// eslint-disable-next-line no-alert
			const isConfirmed = window.confirm(
				__( 'Delete this Monitor?', 'superdav-ai-agent' )
			);
			if ( ! isConfirmed ) {
				return;
			}

			try {
				await apiFetch( {
					path: `/sd-ai-agent/v1/automations/${ monitor.id }`,
					method: 'DELETE',
				} );
				await fetchAll();
			} catch ( error ) {
				setNotice( {
					status: 'error',
					message:
						error?.message ||
						__(
							'The Monitor could not be deleted.',
							'superdav-ai-agent'
						),
				} );
			}
		},
		[ fetchAll ]
	);

	return (
		<section
			className="sd-ai-agent-monitor-manager"
			aria-labelledby="sd-ai-agent-monitor-heading"
		>
			<div className="sd-ai-agent-monitor-header">
				<div>
					<h3 id="sd-ai-agent-monitor-heading">
						{ __( 'Monitor/Pulse', 'superdav-ai-agent' ) }
					</h3>
					<p className="description">
						{ __(
							'Monitors assess a checklist on a cadence and stay quiet unless attention is needed. They are disabled until you explicitly enable them.',
							'superdav-ai-agent'
						) }
					</p>
				</div>
				{ ! showForm && (
					<Button
						variant="secondary"
						icon={ plus }
						onClick={ () => {
							resetForm();
							setShowForm( true );
						} }
					>
						{ __( 'Add Monitor', 'superdav-ai-agent' ) }
					</Button>
				) }
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

			{ ! loaded && (
				<p className="description">
					{ __(
						'Loading Monitor/Pulse configuration…',
						'superdav-ai-agent'
					) }
				</p>
			) }

			{ ! showForm && templates.length > 0 && (
				<div className="sd-ai-agent-monitor-templates">
					<h4>{ __( 'Monitor templates', 'superdav-ai-agent' ) }</h4>
					{ templates.map( ( template ) => (
						<div
							key={ template.name }
							className="sd-ai-agent-monitor-template"
						>
							<div>
								<strong>{ template.name }</strong>
								<p>{ template.description }</p>
							</div>
							<Button
								variant="secondary"
								onClick={ () => handleUseTemplate( template ) }
							>
								{ __(
									'Use as disabled draft',
									'superdav-ai-agent'
								) }
							</Button>
						</div>
					) ) }
				</div>
			) }

			{ showForm && (
				<MonitorForm
					form={ form }
					editId={ editId }
					saving={ saving }
					wakeSources={ wakeSources }
					onChange={ updateForm }
					onAddChannel={ addChannel }
					onUpdateChannel={ updateChannel }
					onRemoveChannel={ removeChannel }
					onSubmit={ handleSubmit }
					onCancel={ resetForm }
				/>
			) }

			{ loaded && ! showForm && monitors.length === 0 && (
				<p className="sd-ai-agent-monitor-empty">
					{ __(
						'No Monitor/Pulse drafts have been created. Scheduled Tasks stay separate and continue to run their own work on every schedule.',
						'superdav-ai-agent'
					) }
				</p>
			) }

			{ monitors.length > 0 && (
				<div className="sd-ai-agent-monitor-list">
					{ monitors.map( ( monitor ) => (
						<MonitorCard
							key={ monitor.id }
							monitor={ monitor }
							running={ running }
							logs={ logs }
							viewLogsId={ viewLogsId }
							onEnable={ ( item ) => updateEnabled( item, true ) }
							onDisable={ ( item ) =>
								updateEnabled( item, false )
							}
							onCheckNow={ handleCheckNow }
							onViewLogs={ handleViewLogs }
							onEdit={ handleEdit }
							onDelete={ handleDelete }
						/>
					) ) }
				</div>
			) }
		</section>
	);
}
