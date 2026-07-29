/**
 * Input area — frame with auto-grow textarea, bottom toolbar (paperclip /
 * model chip / mic / send).
 *
 * Wraps the existing store's sendMessage + stopGeneration + speech hook.
 * Includes slash-command autocomplete (type / to open).
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
import { Paperclip, Microphone, Stop } from './icons';
import ModelPicker from './ModelPicker';
import AgentPicker from './AgentPicker';

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
 * Input area with optional slash-command autocomplete, file upload, voice input,
 * and send/stop controls.
 *
 * @param {Object}  root0
 * @param {boolean} root0.isSimpleMode Whether customer/simple UI mode is active.
 */
export default function InputArea( { isSimpleMode = false } = {} ) {
	const {
		sendMessage,
		stopGeneration,
		clearCurrentSession,
		compactConversation,
		exportSession,
		setShowShortcutsHelp,
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
	const [ isDragOver, setIsDragOver ] = useState( false );
	const [ feedbackModal, setFeedbackModal ] = useState( {
		isOpen: false,
		reportType: 'user_reported',
		userDescription: '',
	} );
	const taRef = useRef( null );
	const fileRef = useRef( null );

	// Push-to-talk.
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

	// Auto-grow textarea.
	useEffect( () => {
		const el = taRef.current;
		if ( ! el ) {
			return;
		}
		el.style.height = 'auto';
		el.style.height = Math.min( el.scrollHeight, 200 ) + 'px';
	}, [ text ] );

	// Show slash menu while the user is still typing the command name
	// (starts with / and has no space yet). Hide once they start arguments.
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

		// /remember <fact>
		if ( ! isSimpleMode && trimmed.startsWith( '/remember ' ) ) {
			const fact = trimmed.slice( 10 ).trim();
			if ( fact ) {
				apiFetch( {
					path: '/sd-ai-agent/v1/memory',
					method: 'POST',
					data: { category: 'general', content: fact },
				} ).catch( () => {} );
			}
			setText( '' );
			return;
		}

		// /forget <topic>
		if ( ! isSimpleMode && trimmed.startsWith( '/forget ' ) ) {
			const topic = trimmed.slice( 8 ).trim();
			if ( topic ) {
				apiFetch( {
					path: '/sd-ai-agent/v1/memory/forget',
					method: 'POST',
					data: { topic },
				} ).catch( () => {} );
			}
			setText( '' );
			return;
		}

		// /report-issue [description]
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
			return;
		}

		// /plan <site operation>
		if (
			! isSimpleMode &&
			( trimmed === '/plan' || trimmed.startsWith( '/plan ' ) )
		) {
			const request = trimmed.slice( 5 ).trim();
			if ( ! request ) {
				setText( '/plan ' );
				return;
			}
			sendMessage( request, [], { durablePlan: true } );
			setText( '' );
			setAttachments( [] );
			setTimeout(
				() => taRef.current?.focus( { preventScroll: true } ),
				0
			);
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
				// Commands that need further input — prefill and let user continue.
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
				case 'plan':
					setText( '/plan ' );
					setTimeout(
						() => taRef.current?.focus( { preventScroll: true } ),
						0
					);
					return;
				case 'help':
					// Show the keyboard shortcuts overlay.
					setShowShortcutsHelp( true );
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
			setShowShortcutsHelp,
		]
	);

	// When the slash menu is open, arrow/enter/escape are handled by the
	// menu's own keydown listener (document-level). Suppress Enter-to-send
	// so the menu can capture it first.
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

	const handleDrop = useCallback(
		( e ) => {
			e.preventDefault();
			e.stopPropagation();
			setIsDragOver( false );
			if ( e.dataTransfer?.files?.length ) {
				processFiles( e.dataTransfer.files );
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
		'Ask the agent to do something, or type / for commands…',
		'sd-ai-agent'
	);
	if ( isSimpleMode ) {
		placeholder = __( 'Ask a question…', 'sd-ai-agent' );
	}
	if ( sending ) {
		placeholder = __( 'Type to queue a message…', 'sd-ai-agent' );
	}

	return (
		<div className="sdaa-cr-input-area">
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
				<div className="sdaa-cr-queue-indicator">
					{ queueCount === 1
						? __( '1 message queued', 'sd-ai-agent' )
						: sprintf(
								/* translators: %d: queued message count */
								__( '%d messages queued', 'sd-ai-agent' ),
								queueCount
						  ) }
				</div>
			) }
			<div className="sdaa-cr-input-inner">
				<div
					className={ `sdaa-cr-input-frame${
						isDragOver ? ' is-drag-over' : ''
					}` }
					role="presentation"
					onMouseDown={ handleFrameMouseDown }
					onDragOver={ ( e ) => {
						e.preventDefault();
						setIsDragOver( true );
					} }
					onDragLeave={ ( e ) => {
						e.preventDefault();
						setIsDragOver( false );
					} }
					onDrop={ handleDrop }
				>
					{ attachments.length > 0 && (
						<div className="sdaa-cr-attachments">
							{ attachments.map( ( a, i ) => (
								<div
									key={ i }
									className="sdaa-cr-attachment-thumb"
								>
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
									<span className="sdaa-cr-attachment-thumb-name">
										{ a.name }
									</span>
									<button
										type="button"
										className="sdaa-cr-attachment-thumb-remove"
										onClick={ () =>
											setAttachments( ( prev ) =>
												prev.filter(
													( _, j ) => j !== i
												)
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
						className="sdaa-cr-input-textarea"
						placeholder={ placeholder }
						value={ text }
						onChange={ ( e ) => setText( e.target.value ) }
						onKeyDown={ handleKey }
						onPaste={ handlePaste }
						rows={ 2 }
					/>
					<div className="sdaa-cr-input-toolbar">
						<div className="sdaa-cr-input-toolbar-left">
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
								aria-label={ __(
									'Attach file',
									'sd-ai-agent'
								) }
							>
								<Paperclip />
							</button>
							{ ! isSimpleMode && <ModelPicker /> }
							{ ! isSimpleMode && <AgentPicker /> }
						</div>
						<div className="sdaa-cr-input-toolbar-right">
							{ micSupported && (
								<button
									type="button"
									className={ `sdaa-cr-icon-btn${
										isListening ? ' is-active' : ''
									}` }
									onClick={ toggleListening }
									aria-label={
										isListening
											? __(
													'Stop recording',
													'sd-ai-agent'
											  )
											: __( 'Voice input', 'sd-ai-agent' )
									}
									aria-pressed={ isListening }
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
								>
									<Icon icon={ arrowUp } size={ 16 } />
								</button>
							) }
						</div>
					</div>
				</div>
			</div>
		</div>
	);
}
