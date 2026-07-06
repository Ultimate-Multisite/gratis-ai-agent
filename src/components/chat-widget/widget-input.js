/**
 * Compact input row for the widget — same wiring as the chat-redesign
 * InputArea (sendMessage / stopGeneration / speech / attachments) but
 * rendered as a tight 1-line textarea with paperclip, optional model chip, mic
 * and send/stop in a single toolbar.
 */

import { useState, useRef, useCallback, useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, arrowUp } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

import STORE_NAME from '../../store';
import useSpeechRecognition from '../use-speech-recognition';
import SlashCommandMenu from '../slash-command-menu';
import FeedbackConsentModal from '../feedback-consent-modal';
import { Paperclip, Microphone, Stop } from '../chat-redesign/icons';
import ModelPicker from '../chat-redesign/ModelPicker';
import AgentPicker from '../chat-redesign/AgentPicker';

const MAX_FILE_SIZE = 10 * 1024 * 1024;
const ACCEPTED_IMAGE_TYPES = [
	'image/jpeg',
	'image/png',
	'image/gif',
	'image/webp',
];
const ACCEPTED_DOC_TYPES = [ 'text/plain', 'text/csv', 'application/pdf' ];
const ACCEPTED_TYPES = [ ...ACCEPTED_IMAGE_TYPES, ...ACCEPTED_DOC_TYPES ];

/**
 *
 * @param {*} file
 */
function readAsDataUrl( file ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = ( e ) => resolve( e.target.result );
		reader.onerror = () => reject( new Error( 'read failed' ) );
		reader.readAsDataURL( file );
	} );
}

/**
 *
 * @param {Object}  root0
 * @param {boolean} root0.isSimpleMode Whether customer/simple UI mode is active.
 */
export default function WidgetInput( { isSimpleMode = false } = {} ) {
	const {
		sendMessage,
		stopGeneration,
		clearCurrentSession,
		compactConversation,
		exportSession,
	} = useDispatch( STORE_NAME );
	const { sending, queueCount, currentSessionId } = useSelect(
		( sel ) => ( {
			sending: sel( STORE_NAME ).isSending(),
			queueCount: sel( STORE_NAME ).getMessageQueue().length,
			currentSessionId: sel( STORE_NAME ).getCurrentSessionId(),
		} ),
		[]
	);

	const [ text, setText ] = useState( '' );
	const [ showSlash, setShowSlash ] = useState( false );
	const [ attachments, setAttachments ] = useState( [] );
	const [ feedbackModal, setFeedbackModal ] = useState( {
		isOpen: false,
		reportType: 'user_reported',
		userDescription: '',
	} );
	const taRef = useRef( null );
	const fileRef = useRef( null );

	const handleSpeechResult = useCallback( ( transcript ) => {
		setText( ( prev ) => ( prev ? prev + ' ' + transcript : transcript ) );
	}, [] );
	const {
		isListening,
		isSupported: micSupported,
		toggleListening,
	} = useSpeechRecognition( {
		interimResults: true,
		onResult: handleSpeechResult,
	} );

	useEffect( () => {
		const el = taRef.current;
		if ( ! el ) {
			return;
		}
		el.style.height = 'auto';
		el.style.height = Math.min( el.scrollHeight, 140 ) + 'px';
	}, [ text ] );

	useEffect( () => {
		setShowSlash(
			! isSimpleMode && text.startsWith( '/' ) && ! text.includes( ' ' )
		);
	}, [ text, isSimpleMode ] );

	const processFiles = useCallback( async ( files ) => {
		const next = [];
		for ( const file of Array.from( files ) ) {
			if ( file.size > MAX_FILE_SIZE ) {
				continue;
			}
			if ( ! ACCEPTED_TYPES.includes( file.type ) ) {
				continue;
			}
			try {
				const dataUrl = await readAsDataUrl( file );
				next.push( {
					name: file.name,
					type: file.type,
					dataUrl,
					isImage: ACCEPTED_IMAGE_TYPES.includes( file.type ),
				} );
			} catch {
				// ignore
			}
		}
		if ( next.length ) {
			setAttachments( ( prev ) => [ ...prev, ...next ] );
		}
	}, [] );

	const canSend = !! text.trim() || attachments.length > 0;

	const handleSend = useCallback( () => {
		const trimmed = text.trim();
		if ( ! trimmed && ! attachments.length ) {
			return;
		}

		if ( ! isSimpleMode && trimmed.startsWith( '/remember ' ) ) {
			const fact = trimmed.slice( 10 ).trim();
			if ( fact ) {
				apiFetch( {
					path: '/sd-ai-agent/v1/memory',
					method: 'POST',
					data: { category: 'general', content: fact },
				} ).catch( ( err ) => {
					// eslint-disable-next-line no-console
					console.error( 'Memory save failed:', err );
				} );
			}
			setText( '' );
			setAttachments( [] );
			return;
		}

		if ( ! isSimpleMode && trimmed.startsWith( '/forget ' ) ) {
			const topic = trimmed.slice( 8 ).trim();
			if ( topic ) {
				apiFetch( {
					path: '/sd-ai-agent/v1/memory/forget',
					method: 'POST',
					data: { topic },
				} ).catch( ( err ) => {
					// eslint-disable-next-line no-console
					console.error( 'Memory forget failed:', err );
				} );
			}
			setText( '' );
			setAttachments( [] );
			return;
		}

		if (
			trimmed === '/report-issue' ||
			trimmed.startsWith( '/report-issue ' )
		) {
			const description = trimmed.startsWith( '/report-issue ' )
				? trimmed.slice( 14 ).trim()
				: '';
			setFeedbackModal( {
				isOpen: true,
				reportType: 'user_reported',
				userDescription: description,
			} );
			setText( '' );
			setAttachments( [] );
			return;
		}

		sendMessage( trimmed, attachments );
		setText( '' );
		setAttachments( [] );
		setTimeout( () => taRef.current?.focus( { preventScroll: true } ), 0 );
	}, [ text, attachments, sendMessage, isSimpleMode ] );

	const handleSlashSelect = useCallback(
		( cmd ) => {
			setShowSlash( false );
			setText( '' );

			switch ( cmd.action ) {
				case 'new':
				case 'clear':
					clearCurrentSession();
					break;
				case 'compact':
					compactConversation();
					break;
				case 'export':
					if ( currentSessionId ) {
						exportSession( currentSessionId, 'json' );
					}
					break;
				case 'model':
					setText( '/model ' );
					setTimeout(
						() => taRef.current?.focus( { preventScroll: true } ),
						0
					);
					return;
				case 'remember':
					setText( '/remember ' );
					setTimeout(
						() => taRef.current?.focus( { preventScroll: true } ),
						0
					);
					return;
				case 'forget':
					setText( '/forget ' );
					setTimeout(
						() => taRef.current?.focus( { preventScroll: true } ),
						0
					);
					return;
				case 'report-issue':
					setText( '/report-issue ' );
					setTimeout(
						() => taRef.current?.focus( { preventScroll: true } ),
						0
					);
					return;
				case 'help':
					break;
			}

			setTimeout(
				() => taRef.current?.focus( { preventScroll: true } ),
				0
			);
		},
		[
			clearCurrentSession,
			compactConversation,
			exportSession,
			currentSessionId,
		]
	);

	const handleKey = useCallback(
		( e ) => {
			if ( showSlash ) {
				return;
			}
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				handleSend();
			}
		},
		[ handleSend, showSlash ]
	);

	const handlePaste = useCallback(
		( e ) => {
			const items = e.clipboardData?.items;
			if ( ! items ) {
				return;
			}
			const imageItems = Array.from( items ).filter(
				( item ) =>
					item.kind === 'file' && item.type.startsWith( 'image/' )
			);
			if ( imageItems.length ) {
				e.preventDefault();
				const files = imageItems
					.map( ( i ) => i.getAsFile() )
					.filter( Boolean );
				if ( files.length ) {
					processFiles( files );
				}
			}
		},
		[ processFiles ]
	);

	// Clicking empty space inside the frame (e.g. between the model chip
	// and the microphone icon) focuses the textarea — standard chat-UX.
	const handleFrameMouseDown = useCallback( ( e ) => {
		if (
			e.target.closest(
				'button, input, textarea, a, [role="button"], [role="menu"], [role="menuitem"]'
			)
		) {
			return;
		}
		e.preventDefault();
		taRef.current?.focus( { preventScroll: true } );
	}, [] );
	let placeholder = __(
		'Ask the agent or type / for commands…',
		'sd-ai-agent'
	);
	if ( isSimpleMode ) {
		placeholder = __( 'Ask a question…', 'sd-ai-agent' );
	}
	if ( sending ) {
		placeholder = __( 'Type to queue a message…', 'sd-ai-agent' );
	}

	return (
		<div className="sdaa-w-input">
			{ ! isSimpleMode && showSlash && (
				<SlashCommandMenu
					filter={ text }
					onSelect={ handleSlashSelect }
					onClose={ () => setShowSlash( false ) }
				/>
			) }
			{ feedbackModal.isOpen && (
				<FeedbackConsentModal
					reportType={ feedbackModal.reportType }
					userDescription={ feedbackModal.userDescription }
					sessionId={ currentSessionId }
					onClose={ () =>
						setFeedbackModal( ( prev ) => ( {
							...prev,
							isOpen: false,
						} ) )
					}
				/>
			) }
			{ queueCount > 0 && (
				<div className="sdaa-w-queue-indicator">
					{ queueCount === 1
						? __( '1 message queued', 'sd-ai-agent' )
						: sprintf(
								/* translators: %d: queued message count */
								__( '%d messages queued', 'sd-ai-agent' ),
								queueCount
						  ) }
				</div>
			) }
			<div
				className="sdaa-w-input-frame"
				role="presentation"
				onMouseDown={ handleFrameMouseDown }
			>
				{ attachments.length > 0 && (
					<div className="sdaa-w-attachments">
						{ attachments.map( ( a, i ) => (
							<div key={ i } className="sdaa-w-attachment-thumb">
								{ a.isImage ? (
									<img src={ a.dataUrl } alt={ a.name } />
								) : (
									<span>
										{ a.name
											.split( '.' )
											.pop()
											.toUpperCase() }
									</span>
								) }
								<button
									type="button"
									className="sdaa-w-attachment-thumb-remove"
									onClick={ () =>
										setAttachments( ( prev ) =>
											prev.filter( ( _, j ) => j !== i )
										)
									}
									aria-label={ __(
										'Remove attachment',
										'sd-ai-agent'
									) }
								>
									&times;
								</button>
							</div>
						) ) }
					</div>
				) }
				<textarea
					ref={ taRef }
					className="sdaa-w-input-textarea"
					placeholder={ placeholder }
					value={ text }
					onChange={ ( e ) => setText( e.target.value ) }
					onKeyDown={ handleKey }
					onPaste={ handlePaste }
					rows={ 1 }
				/>
				<div className="sdaa-w-input-toolbar">
					<div className="sdaa-w-input-toolbar-left">
						<input
							ref={ fileRef }
							type="file"
							accept={ ACCEPTED_TYPES.join( ',' ) }
							multiple
							style={ { display: 'none' } }
							onChange={ ( e ) => {
								if ( e.target.files?.length ) {
									processFiles( e.target.files );
									e.target.value = '';
								}
							} }
						/>
						<button
							type="button"
							className="sdaa-cr-icon-btn"
							onClick={ () => fileRef.current?.click() }
							aria-label={ __( 'Attach file', 'sd-ai-agent' ) }
							title={ __( 'Attach file', 'sd-ai-agent' ) }
						>
							<Paperclip />
						</button>
						{ ! isSimpleMode && <ModelPicker /> }
						{ ! isSimpleMode && <AgentPicker /> }
					</div>
					<div className="sdaa-w-input-toolbar-right">
						{ micSupported && (
							<button
								type="button"
								className={ `sdaa-cr-icon-btn${
									isListening ? ' is-active' : ''
								}` }
								onClick={ toggleListening }
								aria-label={
									isListening
										? __( 'Stop recording', 'sd-ai-agent' )
										: __( 'Voice input', 'sd-ai-agent' )
								}
								aria-pressed={ isListening }
								title={
									isListening
										? __( 'Stop recording', 'sd-ai-agent' )
										: __( 'Voice input', 'sd-ai-agent' )
								}
							>
								<Microphone />
							</button>
						) }
						{ sending ? (
							<button
								type="button"
								className="sdaa-cr-send-btn is-stop"
								onClick={ stopGeneration }
								aria-label={ __(
									'Stop generation',
									'sd-ai-agent'
								) }
								title={ __( 'Stop generation', 'sd-ai-agent' ) }
							>
								<Stop />
							</button>
						) : (
							<button
								type="button"
								className="sdaa-cr-send-btn"
								onClick={ handleSend }
								disabled={ ! canSend }
								aria-label={ __(
									'Send message',
									'sd-ai-agent'
								) }
								title={ __( 'Send message', 'sd-ai-agent' ) }
							>
								<Icon icon={ arrowUp } size={ 16 } />
							</button>
						) }
					</div>
				</div>
			</div>
		</div>
	);
}
