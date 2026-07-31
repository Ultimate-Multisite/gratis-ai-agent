/**
 * Shared chat-composer behavior for the main and floating React surfaces.
 */

import { useState, useRef, useCallback, useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import STORE_NAME from '../../store';
import useSpeechRecognition from '../use-speech-recognition';

const MAX_FILE_SIZE = 10 * 1024 * 1024;
const REMEMBER_PREFIX = '/remember ';
const FORGET_PREFIX = '/forget ';
const REPORT_COMMAND = '/report-issue';
const REPORT_PREFIX = `${ REPORT_COMMAND } `;
const PLAN_COMMAND = '/plan';
const PLAN_PREFIX = `${ PLAN_COMMAND } `;
const ACCEPTED_IMAGE_TYPES = [
	'image/jpeg',
	'image/png',
	'image/gif',
	'image/webp',
];
const ACCEPTED_DOC_TYPES = [ 'text/plain', 'text/csv', 'application/pdf' ];
const ACCEPTED_TYPES = [ ...ACCEPTED_IMAGE_TYPES, ...ACCEPTED_DOC_TYPES ];
export const CHAT_ATTACHMENT_ACCEPT = ACCEPTED_TYPES.join( ',' );

/**
 * Read an accepted attachment as a data URL.
 *
 * @param {File} file Browser file object.
 * @return {Promise<string>} Encoded attachment.
 */
function readAsDataUrl( file ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = ( event ) => resolve( event.target.result );
		reader.onerror = () => reject( new Error( 'read failed' ) );
		reader.readAsDataURL( file );
	} );
}

/**
 * Manage shared composer state, commands, attachments, speech, and submission.
 *
 * @param {Object}  root0
 * @param {boolean} root0.isSimpleMode       Whether customer/simple mode is active.
 * @param {number}  root0.maxTextareaHeight  Maximum auto-grow height in pixels.
 * @param {string}  root0.defaultPlaceholder Surface-specific idle placeholder.
 * @return {Object} Shared composer state and event handlers.
 */
export default function useChatComposer( {
	isSimpleMode = false,
	maxTextareaHeight = 200,
	defaultPlaceholder,
} ) {
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
	const [ attachmentError, setAttachmentError ] = useState( '' );
	const [ isDragOver, setIsDragOver ] = useState( false );
	const [ feedbackModal, setFeedbackModal ] = useState( {
		isOpen: false,
		reportType: 'user_reported',
		userDescription: '',
	} );
	const textareaRef = useRef( null );
	const fileInputRef = useRef( null );

	const focusTextarea = useCallback( () => {
		setTimeout(
			() => textareaRef.current?.focus( { preventScroll: true } ),
			0
		);
	}, [] );

	const handleSpeechResult = useCallback( ( transcript ) => {
		setText( ( previous ) =>
			previous ? previous + ' ' + transcript : transcript
		);
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
		const element = textareaRef.current;
		if ( ! element ) {
			return;
		}
		element.style.height = 'auto';
		element.style.height = `${ Math.min(
			element.scrollHeight,
			maxTextareaHeight
		) }px`;
	}, [ text, maxTextareaHeight ] );

	useEffect( () => {
		setShowSlash(
			! isSimpleMode && text.startsWith( '/' ) && ! text.includes( ' ' )
		);
	}, [ text, isSimpleMode ] );

	const processFiles = useCallback( async ( files ) => {
		const next = [];
		const rejectedNames = [];
		setAttachmentError( '' );
		for ( const file of Array.from( files ) ) {
			if (
				file.size > MAX_FILE_SIZE ||
				! ACCEPTED_TYPES.includes( file.type )
			) {
				rejectedNames.push( file.name );
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
				rejectedNames.push( file.name );
			}
		}
		if ( next.length ) {
			setAttachments( ( previous ) => [ ...previous, ...next ] );
		}
		if ( rejectedNames.length ) {
			setAttachmentError(
				sprintf(
					/* translators: %s: comma-separated attachment file names */
					__( 'Could not attach: %s', 'superdav-ai-agent' ),
					rejectedNames.join( ', ' )
				)
			);
		}
	}, [] );

	const clearComposer = useCallback( () => {
		setText( '' );
		setAttachments( [] );
		setAttachmentError( '' );
	}, [] );

	const handleSend = useCallback( () => {
		const trimmed = text.trim();
		if ( ! trimmed && ! attachments.length ) {
			return;
		}

		if ( ! isSimpleMode && trimmed.startsWith( REMEMBER_PREFIX ) ) {
			const fact = trimmed.slice( REMEMBER_PREFIX.length ).trim();
			if ( fact ) {
				apiFetch( {
					path: '/sd-ai-agent/v1/memory',
					method: 'POST',
					data: { category: 'general', content: fact },
				} ).catch( () => undefined );
			}
			clearComposer();
			return;
		}

		if ( ! isSimpleMode && trimmed.startsWith( FORGET_PREFIX ) ) {
			const topic = trimmed.slice( FORGET_PREFIX.length ).trim();
			if ( topic ) {
				apiFetch( {
					path: '/sd-ai-agent/v1/memory/forget',
					method: 'POST',
					data: { topic },
				} ).catch( () => undefined );
			}
			clearComposer();
			return;
		}

		if (
			! isSimpleMode &&
			( trimmed === REPORT_COMMAND ||
				trimmed.startsWith( REPORT_PREFIX ) )
		) {
			setFeedbackModal( {
				isOpen: true,
				reportType: 'user_reported',
				userDescription: trimmed.startsWith( REPORT_PREFIX )
					? trimmed.slice( REPORT_PREFIX.length ).trim()
					: '',
			} );
			clearComposer();
			return;
		}

		if (
			! isSimpleMode &&
			( trimmed === PLAN_COMMAND || trimmed.startsWith( PLAN_PREFIX ) )
		) {
			const request = trimmed.slice( PLAN_PREFIX.length ).trim();
			if ( ! request ) {
				setText( PLAN_PREFIX );
				return;
			}
			sendMessage( request, attachments, { durablePlan: true } );
			clearComposer();
			focusTextarea();
			return;
		}

		sendMessage( trimmed, attachments );
		clearComposer();
		focusTextarea();
	}, [
		text,
		attachments,
		isSimpleMode,
		sendMessage,
		clearComposer,
		focusTextarea,
	] );

	const handleSlashSelect = useCallback(
		( command ) => {
			setShowSlash( false );
			setText( '' );

			switch ( command.action ) {
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
				case 'remember':
				case 'forget':
				case 'report-issue':
				case 'plan':
					setText( `/${ command.action } ` );
					focusTextarea();
					return;
				case 'help':
					setShowShortcutsHelp?.( true );
					break;
			}

			focusTextarea();
		},
		[
			clearCurrentSession,
			compactConversation,
			exportSession,
			currentSessionId,
			setShowShortcutsHelp,
			focusTextarea,
		]
	);

	const handleKeyDown = useCallback(
		( event ) => {
			if ( showSlash ) {
				return;
			}
			if ( event.key === 'Enter' && ! event.shiftKey ) {
				event.preventDefault();
				handleSend();
			}
		},
		[ handleSend, showSlash ]
	);

	const handlePaste = useCallback(
		( event ) => {
			const items = event.clipboardData?.items;
			if ( ! items ) {
				return;
			}
			const files = Array.from( items )
				.filter(
					( item ) =>
						item.kind === 'file' && item.type.startsWith( 'image/' )
				)
				.map( ( item ) => item.getAsFile() )
				.filter( Boolean );
			if ( files.length ) {
				event.preventDefault();
				processFiles( files );
			}
		},
		[ processFiles ]
	);

	const handleDrop = useCallback(
		( event ) => {
			event.preventDefault();
			event.stopPropagation();
			setIsDragOver( false );
			if ( event.dataTransfer?.files?.length ) {
				processFiles( event.dataTransfer.files );
			}
		},
		[ processFiles ]
	);

	const handleFrameMouseDown = useCallback( ( event ) => {
		if (
			event.target.closest(
				'button, input, textarea, a, [role="button"], [role="menu"], [role="menuitem"]'
			)
		) {
			return;
		}
		event.preventDefault();
		textareaRef.current?.focus( { preventScroll: true } );
	}, [] );

	const removeAttachment = useCallback( ( index ) => {
		setAttachments( ( previous ) =>
			previous.filter( ( _, currentIndex ) => currentIndex !== index )
		);
	}, [] );

	const closeFeedbackModal = useCallback( () => {
		setFeedbackModal( ( previous ) => ( {
			...previous,
			isOpen: false,
		} ) );
	}, [] );

	let placeholder = defaultPlaceholder;
	if ( isSimpleMode ) {
		placeholder = __( 'Ask a question…', 'superdav-ai-agent' );
	}
	if ( sending ) {
		placeholder = __( 'Type to queue a message…', 'superdav-ai-agent' );
	}

	return {
		text,
		setText,
		showSlash,
		setShowSlash,
		attachments,
		attachmentError,
		isDragOver,
		setIsDragOver,
		feedbackModal,
		textareaRef,
		fileInputRef,
		sending,
		queueCount,
		currentSessionId,
		isListening,
		micSupported,
		toggleListening,
		canSend: !! text.trim() || attachments.length > 0,
		placeholder,
		processFiles,
		removeAttachment,
		handleSend,
		handleSlashSelect,
		handleKeyDown,
		handlePaste,
		handleDrop,
		handleFrameMouseDown,
		stopGeneration,
		closeFeedbackModal,
	};
}
