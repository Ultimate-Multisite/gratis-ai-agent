/**
 * WordPress dependencies
 */
import { useEffect, useState, useCallback } from '@wordpress/element';
import {
	Button,
	TextControl,
	TextareaControl,
	SelectControl,
	ToggleControl,
	Notice,
	Spinner,
	BaseControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { trash, pencil, plus } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

const CHANNEL_TYPE_OPTIONS = [
	{ label: __( 'Slack', 'sd-ai-agent' ), value: 'slack' },
	{ label: __( 'Discord', 'sd-ai-agent' ), value: 'discord' },
	{ label: __( 'WhatsApp', 'superdav-ai-agent' ), value: 'whatsapp' },
	{ label: __( 'Telegram', 'superdav-ai-agent' ), value: 'telegram' },
];

/**
 *
 */
function emptyChannel() {
	return { type: 'slack', webhook_url: '', recipient: '', enabled: true };
}

const isMessagingChannel = ( type ) =>
	[ 'whatsapp', 'telegram' ].includes( type );

const getChannelFieldLabel = ( type ) =>
	isMessagingChannel( type )
		? __( 'Recipient', 'superdav-ai-agent' )
		: __( 'Webhook URL', 'superdav-ai-agent' );

/**
 *
 * @param {string} type Channel type.
 */
function getChannelPlaceholder( type ) {
	switch ( type ) {
		case 'whatsapp':
			return '+15551234567';
		case 'telegram':
			return '@channel or numeric chat ID';
		case 'slack':
			return 'https://hooks.slack.com/…';
		default:
			return 'https://discord.com/api/webhooks/…';
	}
}

const SCHEDULE_OPTIONS = [
	{ label: __( 'Hourly', 'sd-ai-agent' ), value: 'hourly' },
	{ label: __( 'Twice Daily', 'sd-ai-agent' ), value: 'twicedaily' },
	{ label: __( 'Daily', 'sd-ai-agent' ), value: 'daily' },
	{ label: __( 'Weekly', 'sd-ai-agent' ), value: 'weekly' },
];

/**
 *
 */
function emptyForm() {
	return {
		name: '',
		description: '',
		prompt: '',
		schedule: 'daily',
		max_iterations: 10,
		enabled: true,
		notification_channels: [],
	};
}

/**
 *
 */
export default function AutomationsManager() {
	const [ automations, setAutomations ] = useState( [] );
	const [ loaded, setLoaded ] = useState( false );
	const [ templates, setTemplates ] = useState( [] );
	const [ showForm, setShowForm ] = useState( false );
	const [ editId, setEditId ] = useState( null );
	const [ form, setForm ] = useState( emptyForm() );
	const [ logs, setLogs ] = useState( [] );
	const [ viewLogsId, setViewLogsId ] = useState( null );
	const [ running, setRunning ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ testingChannel, setTestingChannel ] = useState( null );

	const fetchAll = useCallback( async () => {
		try {
			const [ result, tpl ] = await Promise.all( [
				apiFetch( { path: '/sd-ai-agent/v1/automations' } ),
				apiFetch( {
					path: '/sd-ai-agent/v1/automation-templates',
				} ),
			] );
			setAutomations( result );
			setTemplates( tpl );
		} catch {
			setAutomations( [] );
		}
		setLoaded( true );
	}, [] );

	useEffect( () => {
		fetchAll();
	}, [ fetchAll ] );

	const resetForm = useCallback( () => {
		setShowForm( false );
		setEditId( null );
		setForm( emptyForm() );
	}, [] );

	const updateForm = useCallback( ( key, value ) => {
		setForm( ( prev ) => ( { ...prev, [ key ]: value } ) );
	}, [] );

	const handleSubmit = useCallback( async () => {
		if ( ! form.name.trim() || ! form.prompt.trim() ) {
			return;
		}
		setNotice( null );
		try {
			if ( editId ) {
				await apiFetch( {
					path: `/sd-ai-agent/v1/automations/${ editId }`,
					method: 'PATCH',
					data: form,
				} );
			} else {
				await apiFetch( {
					path: '/sd-ai-agent/v1/automations',
					method: 'POST',
					data: form,
				} );
			}
			resetForm();
			fetchAll();
			setNotice( {
				status: 'success',
				message: __( 'Automation saved.', 'sd-ai-agent' ),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message || __( 'Failed to save.', 'sd-ai-agent' ),
			} );
		}
	}, [ form, editId, resetForm, fetchAll ] );

	const handleEdit = useCallback( ( auto ) => {
		setEditId( auto.id );
		setForm( {
			name: auto.name,
			description: auto.description || '',
			prompt: auto.prompt,
			schedule: auto.schedule,
			max_iterations: auto.max_iterations || 10,
			enabled: auto.enabled,
			notification_channels: auto.notification_channels || [],
		} );
		setShowForm( true );
	}, [] );

	const addChannel = useCallback( () => {
		setForm( ( prev ) => ( {
			...prev,
			notification_channels: [
				...( prev.notification_channels || [] ),
				emptyChannel(),
			],
		} ) );
	}, [] );

	const removeChannel = useCallback( ( idx ) => {
		setForm( ( prev ) => {
			const channels = [ ...( prev.notification_channels || [] ) ];
			channels.splice( idx, 1 );
			return { ...prev, notification_channels: channels };
		} );
	}, [] );

	const updateChannel = useCallback( ( idx, key, value ) => {
		setForm( ( prev ) => {
			const channels = [ ...( prev.notification_channels || [] ) ];
			channels[ idx ] = { ...channels[ idx ], [ key ]: value };
			return { ...prev, notification_channels: channels };
		} );
	}, [] );

	const handleTestChannel = useCallback(
		async ( idx ) => {
			const channel = form.notification_channels[ idx ];
			const destination = isMessagingChannel( channel?.type )
				? channel?.recipient
				: channel?.webhook_url;
			if ( ! destination ) {
				return;
			}
			setTestingChannel( idx );
			try {
				const result = await apiFetch( {
					path: '/sd-ai-agent/v1/automations/test-notification',
					method: 'POST',
					data: {
						type: channel.type,
						...( isMessagingChannel( channel.type )
							? { recipient: channel.recipient }
							: { webhook_url: channel.webhook_url } ),
					},
				} );
				setNotice( {
					status: result.success ? 'success' : 'error',
					message: result.message,
				} );
			} catch ( err ) {
				setNotice( {
					status: 'error',
					message: err.message || __( 'Test failed.', 'sd-ai-agent' ),
				} );
			}
			setTestingChannel( null );
		},
		[ form.notification_channels ]
	);

	const handleDelete = useCallback(
		async ( id ) => {
			if (
				// eslint-disable-next-line no-alert
				window.confirm( __( 'Delete this automation?', 'sd-ai-agent' ) )
			) {
				await apiFetch( {
					path: `/sd-ai-agent/v1/automations/${ id }`,
					method: 'DELETE',
				} );
				fetchAll();
			}
		},
		[ fetchAll ]
	);

	const handleToggle = useCallback(
		async ( auto ) => {
			await apiFetch( {
				path: `/sd-ai-agent/v1/automations/${ auto.id }`,
				method: 'PATCH',
				data: { enabled: ! auto.enabled },
			} );
			fetchAll();
		},
		[ fetchAll ]
	);

	const handleRun = useCallback(
		async ( id ) => {
			setRunning( id );
			setNotice( null );
			try {
				const result = await apiFetch( {
					path: `/sd-ai-agent/v1/automations/${ id }/run`,
					method: 'POST',
				} );
				setNotice( {
					status: result.success ? 'success' : 'warning',
					message: result.success
						? __( 'Automation ran successfully.', 'sd-ai-agent' )
						: result.error ||
						  __(
								'Automation completed with errors.',
								'sd-ai-agent'
						  ),
				} );
				fetchAll();
			} catch ( err ) {
				setNotice( { status: 'error', message: err.message } );
			}
			setRunning( null );
		},
		[ fetchAll ]
	);

	const handleViewLogs = useCallback(
		async ( id ) => {
			if ( viewLogsId === id ) {
				setViewLogsId( null );
				setLogs( [] );
				return;
			}
			try {
				const result = await apiFetch( {
					path: `/sd-ai-agent/v1/automations/${ id }/logs`,
				} );
				setLogs( result );
				setViewLogsId( id );
			} catch {
				setLogs( [] );
			}
		},
		[ viewLogsId ]
	);

	const handleUseTemplate = useCallback( ( tpl ) => {
		setForm( {
			...emptyForm(),
			name: tpl.name,
			description: tpl.description || '',
			prompt: tpl.prompt,
			schedule: tpl.schedule,
			notification_channels: [],
		} );
		setShowForm( true );
		setEditId( null );
	}, [] );

	return (
		<div className="sdaa-automations-manager">
			<div className="sdaa-skill-header">
				<div>
					<h3>{ __( 'Scheduled Automations', 'sd-ai-agent' ) }</h3>
					<p className="description">
						{ __(
							'Cron-based AI tasks that run on a schedule.',
							'sd-ai-agent'
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
						size="compact"
					>
						{ __( 'Add Automation', 'sd-ai-agent' ) }
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

			{ ! showForm &&
				templates.length > 0 &&
				automations.length === 0 && (
					<div style={ { marginBottom: '16px' } }>
						<h4>
							{ __( 'Quick Start Templates', 'sd-ai-agent' ) }
						</h4>
						<div className="sdaa-skill-cards">
							{ templates.map( ( tpl, idx ) => (
								<div key={ idx } className="sdaa-skill-card">
									<div className="sdaa-skill-card-header">
										<div className="sdaa-skill-card-title">
											<strong>{ tpl.name }</strong>
										</div>
									</div>
									<p className="sdaa-skill-card-description">
										{ tpl.description }
									</p>
									<div className="sdaa-skill-card-footer">
										<span className="sdaa-skill-word-count">
											{ tpl.schedule }
										</span>
										<Button
											variant="secondary"
											size="compact"
											onClick={ () =>
												handleUseTemplate( tpl )
											}
										>
											{ __(
												'Use Template',
												'sd-ai-agent'
											) }
										</Button>
									</div>
								</div>
							) ) }
						</div>
					</div>
				) }

			{ showForm && (
				<div className="sdaa-skill-form">
					<TextControl
						label={ __( 'Name', 'sd-ai-agent' ) }
						value={ form.name }
						onChange={ ( v ) => updateForm( 'name', v ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Description', 'sd-ai-agent' ) }
						value={ form.description }
						onChange={ ( v ) => updateForm( 'description', v ) }
						__nextHasNoMarginBottom
					/>
					<TextareaControl
						label={ __( 'Prompt', 'sd-ai-agent' ) }
						value={ form.prompt }
						onChange={ ( v ) => updateForm( 'prompt', v ) }
						rows={ 6 }
						help={ __(
							'The instruction sent to the AI when this automation runs.',
							'sd-ai-agent'
						) }
					/>
					<SelectControl
						label={ __( 'Schedule', 'sd-ai-agent' ) }
						value={ form.schedule }
						options={ SCHEDULE_OPTIONS }
						onChange={ ( v ) => updateForm( 'schedule', v ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Max Iterations', 'sd-ai-agent' ) }
						type="number"
						min={ 1 }
						max={ 50 }
						value={ form.max_iterations }
						onChange={ ( v ) =>
							updateForm(
								'max_iterations',
								parseInt( v, 10 ) || 10
							)
						}
						__nextHasNoMarginBottom
					/>

					<BaseControl
						id="sdaa-notification-channels"
						label={ __( 'Notification Channels', 'sd-ai-agent' ) }
						help={ __(
							'Send Slack, Discord, WhatsApp, or Telegram messages after each run.',
							'superdav-ai-agent'
						) }
						__nextHasNoMarginBottom
					>
						{ ( form.notification_channels || [] ).map(
							( channel, idx ) => (
								<div
									key={ idx }
									className="sdaa-notification-channel"
									style={ {
										display: 'flex',
										gap: '8px',
										alignItems: 'flex-end',
										marginBottom: '8px',
										flexWrap: 'wrap',
									} }
								>
									<SelectControl
										label={
											idx === 0
												? __( 'Type', 'sd-ai-agent' )
												: undefined
										}
										value={ channel.type }
										options={ CHANNEL_TYPE_OPTIONS }
										onChange={ ( v ) =>
											updateChannel( idx, 'type', v )
										}
										style={ { minWidth: '100px' } }
										__nextHasNoMarginBottom
									/>
									<TextControl
										label={
											idx === 0
												? getChannelFieldLabel(
														channel.type
												  )
												: undefined
										}
										value={
											isMessagingChannel( channel.type )
												? channel.recipient
												: channel.webhook_url
										}
										onChange={ ( v ) =>
											updateChannel(
												idx,
												isMessagingChannel(
													channel.type
												)
													? 'recipient'
													: 'webhook_url',
												v
											)
										}
										placeholder={ getChannelPlaceholder(
											channel.type
										) }
										style={ { flex: 1, minWidth: '220px' } }
										__nextHasNoMarginBottom
									/>
									<ToggleControl
										label={ __( 'On', 'sd-ai-agent' ) }
										checked={ channel.enabled }
										onChange={ ( v ) =>
											updateChannel( idx, 'enabled', v )
										}
										__nextHasNoMarginBottom
									/>
									<Button
										variant="secondary"
										size="compact"
										onClick={ () =>
											handleTestChannel( idx )
										}
										disabled={
											! ( isMessagingChannel(
												channel.type
											)
												? channel.recipient
												: channel.webhook_url ) ||
											testingChannel === idx
										}
									>
										{ testingChannel === idx ? (
											<Spinner />
										) : (
											__( 'Test', 'sd-ai-agent' )
										) }
									</Button>
									<Button
										icon={ trash }
										size="compact"
										isDestructive
										label={ __(
											'Remove channel',
											'sd-ai-agent'
										) }
										onClick={ () => removeChannel( idx ) }
									/>
								</div>
							)
						) }
						<Button
							variant="tertiary"
							icon={ plus }
							size="compact"
							onClick={ addChannel }
						>
							{ __( 'Add Channel', 'sd-ai-agent' ) }
						</Button>
					</BaseControl>

					<div className="sdaa-skill-form-actions">
						<Button
							variant="primary"
							onClick={ handleSubmit }
							disabled={
								! form.name.trim() || ! form.prompt.trim()
							}
							size="compact"
						>
							{ editId
								? __( 'Update', 'sd-ai-agent' )
								: __( 'Create', 'sd-ai-agent' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ resetForm }
							size="compact"
						>
							{ __( 'Cancel', 'sd-ai-agent' ) }
						</Button>
					</div>
				</div>
			) }

			{ ! loaded && (
				<p className="description">
					{ __( 'Loading…', 'sd-ai-agent' ) }
				</p>
			) }

			{ loaded && automations.length > 0 && (
				<div
					className="sdaa-skill-cards"
					style={ { marginTop: '16px' } }
				>
					{ automations.map( ( auto ) => (
						<div
							key={ auto.id }
							className={ `sdaa-skill-card ${
								! auto.enabled
									? 'sdaa-skill-card--disabled'
									: ''
							}` }
						>
							<div className="sdaa-skill-card-header">
								<ToggleControl
									checked={ auto.enabled }
									onChange={ () => handleToggle( auto ) }
									__nextHasNoMarginBottom
								/>
								<div className="sdaa-skill-card-title">
									<strong>{ auto.name }</strong>
									<span className="sdaa-skill-badge">
										{ auto.schedule }
									</span>
									{ auto.notification_channels?.filter(
										( c ) => c.enabled
									).length > 0 && (
										<span
											className="sdaa-skill-badge"
											title={ __(
												'Notifications configured',
												'sd-ai-agent'
											) }
										>
											{
												auto.notification_channels.filter(
													( c ) => c.enabled
												).length
											}{ ' ' }
											{ __(
												'notification',
												'sd-ai-agent'
											) }
											{ auto.notification_channels.filter(
												( c ) => c.enabled
											).length > 1
												? 's'
												: '' }
										</span>
									) }
								</div>
							</div>
							<p className="sdaa-skill-card-description">
								{ auto.description ||
									auto.prompt.slice( 0, 100 ) + '...' }
							</p>
							<div className="sdaa-skill-card-footer">
								<span className="sdaa-skill-word-count">
									{ auto.run_count }{ ' ' }
									{ __( 'runs', 'sd-ai-agent' ) }
									{ auto.last_run_at && (
										<>
											{ ' ' }
											&middot;{ ' ' }
											{ __(
												'Last:',
												'sd-ai-agent'
											) }{ ' ' }
											{ auto.last_run_at }
										</>
									) }
								</span>
								<div className="sdaa-skill-card-actions">
									<Button
										variant="secondary"
										size="small"
										onClick={ () => handleRun( auto.id ) }
										disabled={ running === auto.id }
									>
										{ running === auto.id ? (
											<Spinner />
										) : (
											__( 'Run Now', 'sd-ai-agent' )
										) }
									</Button>
									<Button
										variant="tertiary"
										size="small"
										onClick={ () =>
											handleViewLogs( auto.id )
										}
									>
										{ viewLogsId === auto.id
											? __( 'Hide Logs', 'sd-ai-agent' )
											: __( 'Logs', 'sd-ai-agent' ) }
									</Button>
									<Button
										icon={ pencil }
										size="small"
										label={ __( 'Edit', 'sd-ai-agent' ) }
										onClick={ () => handleEdit( auto ) }
									/>
									<Button
										icon={ trash }
										size="small"
										label={ __( 'Delete', 'sd-ai-agent' ) }
										isDestructive
										onClick={ () =>
											handleDelete( auto.id )
										}
									/>
								</div>
							</div>

							{ viewLogsId === auto.id && (
								<div className="sdaa-automation-logs">
									{ logs.length === 0 && (
										<p className="description">
											{ __(
												'No logs yet.',
												'sd-ai-agent'
											) }
										</p>
									) }
									{ logs.map( ( log ) => (
										<div
											key={ log.id }
											className={ `sdaa-log-entry sdaa-log--${ log.status }` }
										>
											<div className="sdaa-log-meta">
												<span
													className={ `sdaa-log-status sdaa-log-status--${ log.status }` }
												>
													{ log.status }
												</span>
												<span>{ log.created_at }</span>
												<span>
													{ log.duration_ms }ms
												</span>
												{ log.prompt_tokens > 0 && (
													<span>
														{ log.prompt_tokens +
															log.completion_tokens }{ ' ' }
														tokens
													</span>
												) }
											</div>
											{ log.error_message && (
												<p className="sdaa-log-error">
													{ log.error_message }
												</p>
											) }
											{ log.reply && (
												<details>
													<summary>
														{ __(
															'Response',
															'sd-ai-agent'
														) }
													</summary>
													<pre className="sdaa-log-reply">
														{ log.reply }
													</pre>
												</details>
											) }
										</div>
									) ) }
								</div>
							) }
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}
