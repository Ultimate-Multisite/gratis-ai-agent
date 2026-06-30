/**
 * WordPress dependencies
 */
/* eslint-disable camelcase -- REST responses and payloads use snake_case field names. */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const DEFAULT_MESSAGE_TEMPLATE = __(
	'Reminder: {event_summary} starts at {event_start}.',
	'superdav-ai-agent'
);

const emptyContact = () => ( {
	id: null,
	attendee_email: '',
	phone_e164: '',
	sms_consent: false,
	display_name: '',
} );

const statusLabel = ( configured ) =>
	configured
		? __( 'Configured', 'superdav-ai-agent' )
		: __( 'Needs setup', 'superdav-ai-agent' );

const normalizeContactMappings = ( mappingRows ) => {
	if ( Array.isArray( mappingRows?.contacts ) ) {
		return mappingRows.contacts;
	}

	return Array.isArray( mappingRows ) ? mappingRows : [];
};

/**
 * Calendar SMS reminder setup surface.
 *
 * @return {JSX.Element} Setup UI.
 */
export default function CalendarSmsManager() {
	const [ loaded, setLoaded ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ running, setRunning ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ calendarStatus, setCalendarStatus ] = useState( null );
	const [ smsStatus, setSmsStatus ] = useState( null );
	const [ contacts, setContacts ] = useState( [] );
	const [ automations, setAutomations ] = useState( [] );
	const [ approvals, setApprovals ] = useState( [] );
	const [ reminderRecords, setReminderRecords ] = useState( [] );
	const [ dryRunResult, setDryRunResult ] = useState( null );
	const [ contactForm, setContactForm ] = useState( emptyContact );
	const [ smsForm, setSmsForm ] = useState( {
		api_key: '',
		device_id: '',
		api_base_url: '',
	} );
	const [ calendarForm, setCalendarForm ] = useState( {
		client_id: '',
		client_secret: '',
		refresh_token: '',
		default_calendar_id: 'primary',
	} );
	const [ testForm, setTestForm ] = useState( {
		recipient: '',
		message: __(
			'This is a Superdav AI Agent TextBee test message.',
			'superdav-ai-agent'
		),
	} );
	const [ dryRunForm, setDryRunForm ] = useState( {
		calendar_id: 'primary',
		lookahead_hours: 24,
		message_template: DEFAULT_MESSAGE_TEMPLATE,
		max_events: 10,
		max_recipients: 50,
	} );
	const [ approvalMode, setApprovalMode ] = useState( 'require_approval' );

	const dailyReminderAutomation = useMemo(
		() =>
			automations.find( ( automation ) => {
				const prompt = `${ automation.name || '' } ${
					automation.prompt || ''
				}`;
				return (
					prompt.toLowerCase().includes( 'calendar' ) &&
					prompt.toLowerCase().includes( 'sms' )
				);
			} ),
		[ automations ]
	);

	const fetchAll = useCallback( async () => {
		try {
			const [
				calendar,
				sms,
				mappingRows,
				automationRows,
				approvalRows,
				records,
			] = await Promise.all( [
				apiFetch( {
					path: '/sd-ai-agent/v1/settings/google-calendar',
				} ),
				apiFetch( { path: '/sd-ai-agent/v1/settings/sms-provider' } ),
				apiFetch( {
					path: '/sd-ai-agent/v1/settings/contact-mappings',
				} ),
				apiFetch( { path: '/sd-ai-agent/v1/automations' } ),
				apiFetch( {
					path: '/sd-ai-agent/v1/automation-approvals?status=pending&limit=25',
				} ),
				apiFetch( {
					path: '/sd-ai-agent/v1/calendar-reminder-records?limit=25',
				} ),
			] );

			setCalendarStatus( calendar );
			setSmsStatus( sms );
			setContacts( normalizeContactMappings( mappingRows ) );
			setAutomations(
				Array.isArray( automationRows ) ? automationRows : []
			);
			setApprovals( Array.isArray( approvalRows ) ? approvalRows : [] );
			setReminderRecords( Array.isArray( records ) ? records : [] );
			setSmsForm( ( current ) => ( {
				...current,
				api_base_url: sms?.api_base_url || current.api_base_url || '',
				device_id: '',
			} ) );
			setCalendarForm( ( current ) => ( {
				...current,
				default_calendar_id:
					calendar?.default_calendar_id ||
					current.default_calendar_id ||
					'primary',
			} ) );
			setDryRunForm( ( current ) => ( {
				...current,
				calendar_id:
					calendar?.default_calendar_id ||
					current.calendar_id ||
					'primary',
			} ) );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message:
					err?.message ||
					__(
						'Failed to load calendar SMS setup data.',
						'superdav-ai-agent'
					),
			} );
		} finally {
			setLoaded( true );
		}
	}, [] );

	useEffect( () => {
		fetchAll();
	}, [ fetchAll ] );

	const saveSmsProvider = useCallback( async () => {
		setSaving( true );
		setNotice( null );
		try {
			const result = await apiFetch( {
				path: '/sd-ai-agent/v1/settings/sms-provider',
				method: 'POST',
				data: { provider: 'textbee', ...smsForm },
			} );
			setSmsStatus( result );
			setSmsForm( ( current ) => ( { ...current, api_key: '' } ) );
			setNotice( {
				status: 'success',
				message: __( 'TextBee settings saved.', 'superdav-ai-agent' ),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message:
					err?.message ||
					__(
						'Failed to save TextBee settings.',
						'superdav-ai-agent'
					),
			} );
		} finally {
			setSaving( false );
		}
	}, [ smsForm ] );

	const saveCalendar = useCallback( async () => {
		setSaving( true );
		setNotice( null );
		try {
			const result = await apiFetch( {
				path: '/sd-ai-agent/v1/settings/google-calendar',
				method: 'POST',
				data: { type: 'oauth2_refresh_token', ...calendarForm },
			} );
			setCalendarStatus( result );
			setCalendarForm( ( current ) => ( {
				...current,
				client_secret: '',
				refresh_token: '',
			} ) );
			setNotice( {
				status: 'success',
				message: __(
					'Google Calendar settings saved.',
					'superdav-ai-agent'
				),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message:
					err?.message ||
					__(
						'Failed to save Google Calendar settings.',
						'superdav-ai-agent'
					),
			} );
		} finally {
			setSaving( false );
		}
	}, [ calendarForm ] );

	const saveContact = useCallback( async () => {
		setSaving( true );
		setNotice( null );
		try {
			const method = contactForm.id ? 'PATCH' : 'POST';
			const path = contactForm.id
				? `/sd-ai-agent/v1/settings/contact-mappings/${ contactForm.id }`
				: '/sd-ai-agent/v1/settings/contact-mappings';
			await apiFetch( { path, method, data: contactForm } );
			setContactForm( emptyContact() );
			await fetchAll();
			setNotice( {
				status: 'success',
				message: __( 'Contact mapping saved.', 'superdav-ai-agent' ),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message:
					err?.message ||
					__(
						'Failed to save contact mapping.',
						'superdav-ai-agent'
					),
			} );
		} finally {
			setSaving( false );
		}
	}, [ contactForm, fetchAll ] );

	const deleteContact = useCallback(
		async ( id ) => {
			setSaving( true );
			try {
				await apiFetch( {
					path: `/sd-ai-agent/v1/settings/contact-mappings/${ id }`,
					method: 'DELETE',
				} );
				await fetchAll();
			} catch ( err ) {
				setNotice( {
					status: 'error',
					message:
						err?.message ||
						__(
							'Failed to delete contact mapping.',
							'superdav-ai-agent'
						),
				} );
			} finally {
				setSaving( false );
			}
		},
		[ fetchAll ]
	);

	const sendTestSms = useCallback( async () => {
		setRunning( true );
		setNotice( null );
		try {
			await apiFetch( {
				path: '/sd-ai-agent/v1/settings/sms-provider/test',
				method: 'POST',
				data: testForm,
			} );
			setNotice( {
				status: 'success',
				message: __(
					'Test SMS request completed.',
					'superdav-ai-agent'
				),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message:
					err?.message ||
					__( 'Test SMS failed.', 'superdav-ai-agent' ),
			} );
		} finally {
			setRunning( false );
		}
	}, [ testForm ] );

	const runDryRun = useCallback( async () => {
		setRunning( true );
		setNotice( null );
		try {
			const result = await apiFetch( {
				path: '/sd-ai-agent/v1/settings/calendar-reminders/dry-run',
				method: 'POST',
				data: dryRunForm,
			} );
			setDryRunResult( result );
			setNotice( {
				status: 'success',
				message: __(
					'Dry run completed without sending SMS.',
					'superdav-ai-agent'
				),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message:
					err?.message ||
					__( 'Dry run failed.', 'superdav-ai-agent' ),
			} );
		} finally {
			setRunning( false );
		}
	}, [ dryRunForm ] );

	const updateApproval = useCallback(
		async ( id, action ) => {
			setRunning( true );
			try {
				await apiFetch( {
					path: `/sd-ai-agent/v1/automation-approvals/${ id }/${ action }`,
					method: 'POST',
				} );
				await fetchAll();
				setNotice( {
					status: 'success',
					message: __(
						'Approval request updated.',
						'superdav-ai-agent'
					),
				} );
			} catch ( err ) {
				setNotice( {
					status: 'error',
					message:
						err?.message ||
						__(
							'Failed to update approval request.',
							'superdav-ai-agent'
						),
				} );
			} finally {
				setRunning( false );
			}
		},
		[ fetchAll ]
	);

	if ( ! loaded ) {
		return <Spinner />;
	}

	return (
		<div className="sdaa-calendar-sms-manager">
			{ notice && (
				<Notice
					status={ notice.status }
					isDismissible
					onDismiss={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<div className="sdaa-settings-grid">
				<div className="sdaa-card">
					<h4>{ __( 'Setup status', 'superdav-ai-agent' ) }</h4>
					<ul>
						<li>
							{ sprintf(
								/* translators: %s: Google Calendar setup status. */
								__(
									'Google Calendar: %s',
									'superdav-ai-agent'
								),
								statusLabel( calendarStatus?.has_credentials )
							) }
						</li>
						<li>
							{ sprintf(
								/* translators: %s: TextBee setup status. */
								__( 'TextBee SMS: %s', 'superdav-ai-agent' ),
								statusLabel( smsStatus?.configured )
							) }
						</li>
						<li>
							{ sprintf(
								/* translators: %d: Number of contacts with SMS consent. */
								__(
									'Consented contacts: %d',
									'superdav-ai-agent'
								),
								contacts.filter(
									( contact ) => contact.sms_consent
								).length
							) }
						</li>
						<li>
							{ sprintf(
								/* translators: %s: Daily reminder automation enabled/setup status. */
								__(
									'Daily reminder automation: %s',
									'superdav-ai-agent'
								),
								dailyReminderAutomation?.enabled
									? __( 'Enabled', 'superdav-ai-agent' )
									: __( 'Not enabled', 'superdav-ai-agent' )
							) }
						</li>
					</ul>
					{ dailyReminderAutomation?.last_run_at && (
						<p>
							{ sprintf(
								/* translators: %s: Last automation run datetime. */
								__( 'Last run: %s', 'superdav-ai-agent' ),
								dailyReminderAutomation.last_run_at
							) }
						</p>
					) }
				</div>

				<div className="sdaa-card">
					<h4>
						{ __(
							'Human approval preference',
							'superdav-ai-agent'
						) }
					</h4>
					<SelectControl
						label={ __(
							'Default reminder mode',
							'superdav-ai-agent'
						) }
						value={ approvalMode }
						options={ [
							{
								label: __(
									'Require approval before sending',
									'superdav-ai-agent'
								),
								value: 'require_approval',
							},
							{
								label: __(
									'Dry run only',
									'superdav-ai-agent'
								),
								value: 'dry_run',
							},
							{
								label: __(
									'Send automatically',
									'superdav-ai-agent'
								),
								value: 'auto',
							},
						] }
						onChange={ setApprovalMode }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					{ approvalMode === 'auto' && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Automatic mode sends SMS without a human approval step. Run a dry run first and confirm mappings are correct.',
								'superdav-ai-agent'
							) }
						</Notice>
					) }
				</div>
			</div>

			<h4>
				{ __( 'Google Calendar credentials', 'superdav-ai-agent' ) }
			</h4>
			<TextControl
				label={ __( 'Client ID', 'superdav-ai-agent' ) }
				value={ calendarForm.client_id }
				onChange={ ( client_id ) =>
					setCalendarForm( ( form ) => ( { ...form, client_id } ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Client secret', 'superdav-ai-agent' ) }
				type="password"
				value={ calendarForm.client_secret }
				onChange={ ( client_secret ) =>
					setCalendarForm( ( form ) => ( {
						...form,
						client_secret,
					} ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Refresh token', 'superdav-ai-agent' ) }
				type="password"
				value={ calendarForm.refresh_token }
				onChange={ ( refresh_token ) =>
					setCalendarForm( ( form ) => ( {
						...form,
						refresh_token,
					} ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Default calendar ID', 'superdav-ai-agent' ) }
				value={ calendarForm.default_calendar_id }
				onChange={ ( default_calendar_id ) =>
					setCalendarForm( ( form ) => ( {
						...form,
						default_calendar_id,
					} ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<Button
				variant="secondary"
				isBusy={ saving }
				disabled={ saving }
				onClick={ saveCalendar }
			>
				{ __( 'Save Google Calendar settings', 'superdav-ai-agent' ) }
			</Button>

			<h4>{ __( 'TextBee SMS provider', 'superdav-ai-agent' ) }</h4>
			{ smsStatus?.has_api_key && (
				<p>
					{ __(
						'API key is saved and hidden.',
						'superdav-ai-agent'
					) }
				</p>
			) }
			<TextControl
				label={ __( 'TextBee API key', 'superdav-ai-agent' ) }
				type="password"
				value={ smsForm.api_key }
				onChange={ ( api_key ) =>
					setSmsForm( ( form ) => ( { ...form, api_key } ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Device ID', 'superdav-ai-agent' ) }
				value={ smsForm.device_id }
				placeholder={ smsStatus?.device_id_redacted || '' }
				onChange={ ( device_id ) =>
					setSmsForm( ( form ) => ( { ...form, device_id } ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'API base URL', 'superdav-ai-agent' ) }
				value={ smsForm.api_base_url }
				onChange={ ( api_base_url ) =>
					setSmsForm( ( form ) => ( { ...form, api_base_url } ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<Button
				variant="secondary"
				isBusy={ saving }
				disabled={ saving }
				onClick={ saveSmsProvider }
			>
				{ __( 'Save TextBee settings', 'superdav-ai-agent' ) }
			</Button>

			<h4>{ __( 'Contact mappings', 'superdav-ai-agent' ) }</h4>
			<TextControl
				label={ __( 'Attendee email', 'superdav-ai-agent' ) }
				value={ contactForm.attendee_email }
				onChange={ ( attendee_email ) =>
					setContactForm( ( form ) => ( {
						...form,
						attendee_email,
					} ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Phone number (E.164)', 'superdav-ai-agent' ) }
				value={ contactForm.phone_e164 }
				onChange={ ( phone_e164 ) =>
					setContactForm( ( form ) => ( { ...form, phone_e164 } ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Display name', 'superdav-ai-agent' ) }
				value={ contactForm.display_name }
				onChange={ ( display_name ) =>
					setContactForm( ( form ) => ( { ...form, display_name } ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<ToggleControl
				label={ __( 'SMS consent recorded', 'superdav-ai-agent' ) }
				checked={ contactForm.sms_consent }
				onChange={ ( sms_consent ) =>
					setContactForm( ( form ) => ( { ...form, sms_consent } ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<Button
				variant="primary"
				isBusy={ saving }
				disabled={ saving }
				onClick={ saveContact }
			>
				{ contactForm.id
					? __( 'Update mapping', 'superdav-ai-agent' )
					: __( 'Add mapping', 'superdav-ai-agent' ) }
			</Button>
			<ul className="sdaa-list">
				{ contacts.map( ( contact ) => (
					<li key={ contact.id }>
						<strong>{ contact.attendee_email }</strong>{ ' ' }
						{ contact.display_name
							? `(${ contact.display_name })`
							: '' }{ ' ' }
						— { contact.phone_e164 } —{ ' ' }
						{ contact.sms_consent
							? __( 'consented', 'superdav-ai-agent' )
							: __( 'no consent', 'superdav-ai-agent' ) }
						<Button
							variant="link"
							onClick={ () => setContactForm( contact ) }
						>
							{ __( 'Edit', 'superdav-ai-agent' ) }
						</Button>
						<Button
							variant="link"
							isDestructive
							onClick={ () => deleteContact( contact.id ) }
						>
							{ __( 'Delete', 'superdav-ai-agent' ) }
						</Button>
					</li>
				) ) }
			</ul>

			<h4>{ __( 'Safe tests', 'superdav-ai-agent' ) }</h4>
			<TextControl
				label={ __( 'Explicit test recipient', 'superdav-ai-agent' ) }
				value={ testForm.recipient }
				onChange={ ( recipient ) =>
					setTestForm( ( form ) => ( { ...form, recipient } ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextareaControl
				label={ __( 'Test message', 'superdav-ai-agent' ) }
				value={ testForm.message }
				onChange={ ( message ) =>
					setTestForm( ( form ) => ( { ...form, message } ) )
				}
				rows={ 3 }
				__nextHasNoMarginBottom
			/>
			<Button
				variant="secondary"
				isBusy={ running }
				disabled={ running || ! testForm.recipient }
				onClick={ sendTestSms }
			>
				{ __( 'Send explicit test SMS', 'superdav-ai-agent' ) }
			</Button>
			<TextControl
				label={ __( 'Dry-run lookahead hours', 'superdav-ai-agent' ) }
				type="number"
				value={ dryRunForm.lookahead_hours }
				onChange={ ( lookahead_hours ) =>
					setDryRunForm( ( form ) => ( {
						...form,
						lookahead_hours: parseInt( lookahead_hours, 10 ) || 24,
					} ) )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextareaControl
				label={ __( 'Dry-run message template', 'superdav-ai-agent' ) }
				value={ dryRunForm.message_template }
				onChange={ ( message_template ) =>
					setDryRunForm( ( form ) => ( {
						...form,
						message_template,
					} ) )
				}
				rows={ 3 }
				__nextHasNoMarginBottom
			/>
			<Button
				variant="secondary"
				isBusy={ running }
				disabled={ running }
				onClick={ runDryRun }
			>
				{ __( 'Run dry run without sending', 'superdav-ai-agent' ) }
			</Button>
			{ dryRunResult?.counts && (
				<p>
					{ sprintf(
						/* translators: 1: skipped count, 2: pending count, 3: failed count, 4: sent count. */
						__(
							'Dry run: %1$d skipped, %2$d pending, %3$d failed, %4$d sent.',
							'superdav-ai-agent'
						),
						dryRunResult.counts.skipped,
						dryRunResult.counts.pending,
						dryRunResult.counts.failed,
						dryRunResult.counts.sent
					) }
				</p>
			) }

			<h4>{ __( 'Pending approvals', 'superdav-ai-agent' ) }</h4>
			{ approvals.length === 0 && (
				<p>
					{ __(
						'No pending approval requests.',
						'superdav-ai-agent'
					) }
				</p>
			) }
			<ul className="sdaa-list">
				{ approvals.map( ( approval ) => (
					<li key={ approval.id }>
						<strong>
							{ approval.action ||
								__( 'Reminder approval', 'superdav-ai-agent' ) }
						</strong>{ ' ' }
						— { approval.status }
						<pre>
							{ JSON.stringify(
								approval.payload || {},
								null,
								2
							) }
						</pre>
						<Button
							variant="primary"
							disabled={ running }
							onClick={ () =>
								updateApproval( approval.id, 'approve' )
							}
						>
							{ __( 'Approve', 'superdav-ai-agent' ) }
						</Button>
						<Button
							variant="secondary"
							isDestructive
							disabled={ running }
							onClick={ () =>
								updateApproval( approval.id, 'reject' )
							}
						>
							{ __( 'Reject', 'superdav-ai-agent' ) }
						</Button>
					</li>
				) ) }
			</ul>

			<h4>{ __( 'Reminder history', 'superdav-ai-agent' ) }</h4>
			{ reminderRecords.length === 0 && (
				<p>{ __( 'No reminder records yet.', 'superdav-ai-agent' ) }</p>
			) }
			<ul className="sdaa-list">
				{ reminderRecords.map( ( record ) => (
					<li key={ record.id }>
						{ record.updated_at } — { record.attendee_email } —{ ' ' }
						{ record.status }{ ' ' }
						{ record.skip_reason
							? `(${ record.skip_reason })`
							: '' }
					</li>
				) ) }
			</ul>
		</div>
	);
}
