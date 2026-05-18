/**
 * WordPress dependencies
 */
import { useState, useCallback, useRef, useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { Icon, copy, pencil, redo, check, thumbsDown } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import STORE_NAME from '../store';

/**
 * Per-message action buttons (Copy, Edit, Regenerate, Thumbs-down).
 *
 * - Copy: available on all messages.
 * - Edit: available on user messages; opens an inline edit textarea.
 * - Regenerate: available on model messages; re-runs from the preceding user message.
 * - Thumbs-down: available on model messages; opens the feedback consent modal (t186).
 *
 * @param {Object}                     props                - Component props.
 * @param {import('../types').Message} props.message        - The message to act on.
 * @param {number}                     props.index          - Index of the message in the list.
 * @param {Function}                   [props.onThumbsDown] - Called with no args when the
 *                                                          thumbs-down button is clicked.
 *                                                          Only rendered on model messages.
 * @return {JSX.Element} The message actions element.
 */
export default function MessageActions( { message, index, onThumbsDown } ) {
	const [ copied, setCopied ] = useState( false );
	const [ editing, setEditing ] = useState( false );
	const [ editText, setEditText ] = useState( '' );
	const editInputRef = useRef( null );

	useEffect( () => {
		if ( editing && editInputRef.current ) {
			editInputRef.current.focus();
		}
	}, [ editing ] );

	const { regenerateMessage, editAndResend } = useDispatch( STORE_NAME );
	const sending = useSelect(
		( select ) => select( STORE_NAME ).isSending(),
		[]
	);

	const text = message.parts
		?.filter( ( p ) => p.text )
		.map( ( p ) => p.text )
		.join( '' );

	const handleCopy = useCallback( () => {
		navigator.clipboard.writeText( text || '' ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} );
	}, [ text ] );

	const handleEdit = useCallback( () => {
		setEditText( text || '' );
		setEditing( true );
	}, [ text ] );

	const handleEditSubmit = useCallback( () => {
		// Always resend when Send is clicked, regardless of whether the user
		// edited the text. The user opens this editor explicitly to resend
		// (typically after a model-availability failure), so clicking Send
		// must always re-dispatch through editAndResend → sendMessage →
		// streamMessage so the request body picks up the current
		// selectedProviderId / selectedModelId from the store (GH#1495).
		const trimmed = editText.trim();
		if ( trimmed ) {
			editAndResend( index, trimmed );
		} else if ( text ) {
			// Empty draft — fall back to the original text so the resend
			// still fires with the current dropdown selection.
			editAndResend( index, text );
		}
		setEditing( false );
	}, [ editText, text, index, editAndResend ] );

	if ( editing ) {
		return (
			<div className="sdaa-message-edit">
				<textarea
					ref={ editInputRef }
					className="sdaa-message-edit-input"
					value={ editText }
					onChange={ ( e ) => setEditText( e.target.value ) }
					rows={ 3 }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' && ! e.shiftKey ) {
							e.preventDefault();
							handleEditSubmit();
						}
						if ( e.key === 'Escape' ) {
							setEditing( false );
						}
					} }
				/>
				<div className="sdaa-message-edit-actions">
					<Button
						variant="primary"
						onClick={ handleEditSubmit }
						disabled={ sending }
						size="small"
					>
						{ __( 'Send', 'sd-ai-agent' ) }
					</Button>
					<Button
						variant="tertiary"
						onClick={ () => setEditing( false ) }
						size="small"
					>
						{ __( 'Cancel', 'sd-ai-agent' ) }
					</Button>
				</div>
			</div>
		);
	}

	const isUser = message.role === 'user';
	const isModel = message.role === 'model';

	return (
		<div className="sdaa-message-actions">
			<Button
				className="sdaa-action-btn"
				onClick={ handleCopy }
				label={
					copied
						? __( 'Copied!', 'sd-ai-agent' )
						: __( 'Copy', 'sd-ai-agent' )
				}
				showTooltip
				icon={ <Icon icon={ copied ? check : copy } size={ 14 } /> }
				size="small"
			/>
			{ isUser && (
				<Button
					className="sdaa-action-btn"
					onClick={ handleEdit }
					disabled={ sending }
					label={ __( 'Edit message', 'sd-ai-agent' ) }
					showTooltip
					icon={ <Icon icon={ pencil } size={ 14 } /> }
					size="small"
				/>
			) }
			{ isModel && (
				<Button
					className="sdaa-action-btn"
					onClick={ () => regenerateMessage( index ) }
					disabled={ sending }
					label={ __( 'Regenerate', 'sd-ai-agent' ) }
					showTooltip
					icon={ <Icon icon={ redo } size={ 14 } /> }
					size="small"
				/>
			) }
			{ isModel && onThumbsDown && (
				<Button
					className="sdaa-action-btn sdaa-thumbs-down-btn"
					onClick={ onThumbsDown }
					disabled={ sending }
					label={ __(
						'Report an issue with this response',
						'sd-ai-agent'
					) }
					showTooltip
					icon={ <Icon icon={ thumbsDown } size={ 14 } /> }
					size="small"
				/>
			) }
		</div>
	);
}
