/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const EMPTY_WHATSAPP = {
	configured: false,
	access_token: '',
	phone_number_id: '',
	api_version: 'v25.0',
	test_recipient: '',
};

const EMPTY_TELEGRAM = {
	configured: false,
	bot_token: '',
	test_recipient: '',
};

/** Configure WhatsApp Cloud API and Telegram Bot API messaging. */
export default function MessagingIntegrationsManager() {
	const [ whatsapp, setWhatsapp ] = useState( EMPTY_WHATSAPP );
	const [ telegram, setTelegram ] = useState( EMPTY_TELEGRAM );
	const [ loading, setLoading ] = useState( true );
	const [ busy, setBusy ] = useState( '' );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback( async () => {
		try {
			const [ whatsappStatus, telegramStatus ] = await Promise.all( [
				apiFetch( {
					path: '/sd-ai-agent/v1/settings/whatsapp-provider',
				} ),
				apiFetch( {
					path: '/sd-ai-agent/v1/settings/telegram-provider',
				} ),
			] );
			setWhatsapp( ( current ) => ( {
				...current,
				...whatsappStatus,
				api_version: whatsappStatus.api_version || 'v25.0',
			} ) );
			setTelegram( ( current ) => ( {
				...current,
				...telegramStatus,
			} ) );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error.message ||
					__(
						'Failed to load messaging integrations.',
						'superdav-ai-agent'
					),
			} );
		}
		setLoading( false );
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const saveWhatsApp = async () => {
		setBusy( 'whatsapp-save' );
		setNotice( null );
		try {
			const status = await apiFetch( {
				path: '/sd-ai-agent/v1/settings/whatsapp-provider',
				method: 'POST',
				data: {
					access_token: whatsapp.access_token,
					phone_number_id: whatsapp.phone_number_id,
					api_version: whatsapp.api_version,
				},
			} );
			setWhatsapp( ( current ) => ( {
				...current,
				...status,
				access_token: '',
			} ) );
			setNotice( {
				status: 'success',
				message: __( 'WhatsApp settings saved.', 'superdav-ai-agent' ),
			} );
		} catch ( error ) {
			setNotice( { status: 'error', message: error.message } );
		}
		setBusy( '' );
	};

	const saveTelegram = async () => {
		setBusy( 'telegram-save' );
		setNotice( null );
		try {
			const status = await apiFetch( {
				path: '/sd-ai-agent/v1/settings/telegram-provider',
				method: 'POST',
				data: { bot_token: telegram.bot_token },
			} );
			setTelegram( ( current ) => ( {
				...current,
				...status,
				bot_token: '',
			} ) );
			setNotice( {
				status: 'success',
				message: __( 'Telegram settings saved.', 'superdav-ai-agent' ),
			} );
		} catch ( error ) {
			setNotice( { status: 'error', message: error.message } );
		}
		setBusy( '' );
	};

	const testProvider = async ( provider ) => {
		setBusy( `${ provider }-test` );
		setNotice( null );
		try {
			const isWhatsApp = provider === 'whatsapp';
			await apiFetch( {
				path: `/sd-ai-agent/v1/settings/${ provider }-provider/test`,
				method: 'POST',
				data: isWhatsApp
					? { recipient: whatsapp.test_recipient }
					: { chat_id: telegram.test_recipient },
			} );
			setNotice( {
				status: 'success',
				message: __( 'Test message sent.', 'superdav-ai-agent' ),
			} );
		} catch ( error ) {
			setNotice( { status: 'error', message: error.message } );
		}
		setBusy( '' );
	};

	if ( loading ) {
		return <Spinner />;
	}

	return (
		<div className="sdaa-messaging-integrations">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<h4>{ __( 'WhatsApp Cloud API', 'superdav-ai-agent' ) }</h4>
			<p className="description">
				{ whatsapp.configured
					? __(
							'Configured. Leave the token blank to keep the saved token.',
							'superdav-ai-agent'
					  )
					: __(
							'Configure a Meta access token and WhatsApp phone number ID.',
							'superdav-ai-agent'
					  ) }
			</p>
			<TextControl
				label={ __( 'Access token', 'superdav-ai-agent' ) }
				type="password"
				value={ whatsapp.access_token }
				onChange={ ( accessToken ) =>
					setWhatsapp( ( current ) => ( {
						...current,
						access_token: accessToken,
					} ) )
				}
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Phone number ID', 'superdav-ai-agent' ) }
				value={ whatsapp.phone_number_id }
				placeholder={ whatsapp.phone_number_id_redacted || '' }
				onChange={ ( phoneNumberId ) =>
					setWhatsapp( ( current ) => ( {
						...current,
						phone_number_id: phoneNumberId,
					} ) )
				}
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Graph API version', 'superdav-ai-agent' ) }
				value={ whatsapp.api_version }
				onChange={ ( apiVersion ) =>
					setWhatsapp( ( current ) => ( {
						...current,
						api_version: apiVersion,
					} ) )
				}
				__nextHasNoMarginBottom
			/>
			<Button
				variant="primary"
				onClick={ saveWhatsApp }
				disabled={ busy !== '' }
			>
				{ busy === 'whatsapp-save' ? (
					<Spinner />
				) : (
					__( 'Save WhatsApp', 'superdav-ai-agent' )
				) }
			</Button>
			<TextControl
				label={ __( 'Test recipient (E.164)', 'superdav-ai-agent' ) }
				value={ whatsapp.test_recipient }
				onChange={ ( recipient ) =>
					setWhatsapp( ( current ) => ( {
						...current,
						test_recipient: recipient,
					} ) )
				}
				__nextHasNoMarginBottom
			/>
			<Button
				variant="secondary"
				onClick={ () => testProvider( 'whatsapp' ) }
				disabled={
					! whatsapp.configured ||
					! whatsapp.test_recipient ||
					busy !== ''
				}
			>
				{ __( 'Send WhatsApp test', 'superdav-ai-agent' ) }
			</Button>

			<h4>{ __( 'Telegram Bot API', 'superdav-ai-agent' ) }</h4>
			<p className="description">
				{ telegram.configured
					? __(
							'Configured. Leave the token blank to keep the saved token.',
							'superdav-ai-agent'
					  )
					: __(
							'Configure a Telegram bot token.',
							'superdav-ai-agent'
					  ) }
			</p>
			<TextControl
				label={ __( 'Bot token', 'superdav-ai-agent' ) }
				type="password"
				value={ telegram.bot_token }
				onChange={ ( botToken ) =>
					setTelegram( ( current ) => ( {
						...current,
						bot_token: botToken,
					} ) )
				}
				__nextHasNoMarginBottom
			/>
			<Button
				variant="primary"
				onClick={ saveTelegram }
				disabled={ busy !== '' }
			>
				{ busy === 'telegram-save' ? (
					<Spinner />
				) : (
					__( 'Save Telegram', 'superdav-ai-agent' )
				) }
			</Button>
			<TextControl
				label={ __( 'Test chat ID or @channel', 'superdav-ai-agent' ) }
				value={ telegram.test_recipient }
				onChange={ ( recipient ) =>
					setTelegram( ( current ) => ( {
						...current,
						test_recipient: recipient,
					} ) )
				}
				__nextHasNoMarginBottom
			/>
			<Button
				variant="secondary"
				onClick={ () => testProvider( 'telegram' ) }
				disabled={
					! telegram.configured ||
					! telegram.test_recipient ||
					busy !== ''
				}
			>
				{ __( 'Send Telegram test', 'superdav-ai-agent' ) }
			</Button>
		</div>
	);
}
