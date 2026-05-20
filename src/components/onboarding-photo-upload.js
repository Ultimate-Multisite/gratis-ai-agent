/**
 * Onboarding photo-upload panel — drag-and-drop tile that sits above the
 * chat during the Theme Builder interview and uploads user photos directly
 * into the WordPress media library.
 *
 * Uploaded files are POSTed to /sd-ai-agent/v1/onboarding/interview-uploads.
 * The server tags each attachment with the interview-upload meta so the
 * Theme Builder agent can recall them later via the
 * `sd-ai-agent/list-interview-uploads` ability.
 *
 * After a successful upload batch this component also fires a user message
 * into the chat ("I've uploaded N photos: …") so the agent acknowledges the
 * uploads immediately and folds them into its plan.
 */

/**
 * WordPress dependencies
 */
import { useState, useRef, useCallback, useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __, sprintf, _n } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import STORE_NAME from '../store';

import './onboarding-photo-upload.css';

const MAX_FILE_SIZE = 12 * 1024 * 1024;
const ACCEPTED_TYPES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];

const CATEGORY_LABELS = {
	space: __( 'space', 'superdav-ai-agent' ),
	product: __( 'product', 'superdav-ai-agent' ),
	team: __( 'team', 'superdav-ai-agent' ),
	event: __( 'event', 'superdav-ai-agent' ),
	other: __( 'other', 'superdav-ai-agent' ),
};

/**
 * Build the per-file form-data for a single upload request.
 *
 * @param {File}        file      File from the input or drag-drop.
 * @param {number|null} sessionId Optional Theme Builder session id.
 * @return {FormData} Populated multipart payload.
 */
function buildFormData( file, sessionId ) {
	const fd = new FormData();
	fd.append( 'files', file, file.name );
	if ( sessionId ) {
		fd.append( 'session_id', String( sessionId ) );
	}
	return fd;
}

/**
 * Summarise an upload batch as a chat message that the Theme Builder agent
 * can acknowledge. The shape is deliberately compact: a one-line headline
 * with per-category counts followed by the attachment IDs so the agent can
 * pass them straight to `sd-ai-agent/list-interview-uploads` if it wants
 * full details.
 *
 * @param {Array<Object>} uploads Array of upload objects from the REST response.
 * @return {string} Human-readable summary.
 */
export function buildUploadSummary( uploads ) {
	if ( ! uploads || uploads.length === 0 ) {
		return '';
	}

	const counts = {};
	const ids = [];
	for ( const u of uploads ) {
		const cat = u.category || 'other';
		counts[ cat ] = ( counts[ cat ] || 0 ) + 1;
		if ( u.attachment_id ) {
			ids.push( u.attachment_id );
		}
	}

	const parts = Object.keys( counts )
		.sort()
		.map( ( cat ) =>
			sprintf(
				/* translators: 1: count, 2: category label */
				__( '%1$d %2$s', 'superdav-ai-agent' ),
				counts[ cat ],
				CATEGORY_LABELS[ cat ] || cat
			)
		);

	const headline = sprintf(
		/* translators: %d: number of photos uploaded */
		_n(
			"I've uploaded %d photo for the Theme Builder to use.",
			"I've uploaded %d photos for the Theme Builder to use.",
			uploads.length,
			'superdav-ai-agent'
		),
		uploads.length
	);

	const breakdown = sprintf(
		/* translators: %s: comma-separated category breakdown like "3 space, 2 product" */
		__( 'Breakdown: %s.', 'superdav-ai-agent' ),
		parts.join( ', ' )
	);

	const idLine = sprintf(
		/* translators: %s: comma-separated attachment IDs */
		__( 'Attachment IDs: %s.', 'superdav-ai-agent' ),
		ids.join( ', ' )
	);

	return `${ headline } ${ breakdown } ${ idLine }`;
}

/**
 * Drag-and-drop upload panel for the Theme Builder onboarding interview.
 *
 * @param {Object}      props
 * @param {number|null} props.sessionId Optional Theme Builder session id.
 * @return {JSX.Element} Panel element.
 */
export default function OnboardingPhotoUpload( { sessionId = null } ) {
	const { sendMessage } = useDispatch( STORE_NAME );

	const [ isDragOver, setIsDragOver ] = useState( false );
	const [ uploads, setUploads ] = useState( [] );
	const [ errors, setErrors ] = useState( [] );
	const [ isUploading, setIsUploading ] = useState( false );
	const fileRef = useRef( null );

	// Rehydrate previously-uploaded photos for this session so the panel
	// still shows them after a page reload.
	useEffect( () => {
		let cancelled = false;
		const params = new URLSearchParams();
		if ( sessionId ) {
			params.set( 'session_id', String( sessionId ) );
		}
		const qs = params.toString() ? `?${ params.toString() }` : '';
		apiFetch( {
			path: `/sd-ai-agent/v1/onboarding/interview-uploads${ qs }`,
			method: 'GET',
		} )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				if ( data?.items?.length ) {
					setUploads( data.items );
				}
			} )
			.catch( () => {
				// Non-fatal — panel starts empty.
			} );
		return () => {
			cancelled = true;
		};
	}, [ sessionId ] );

	const processFiles = useCallback(
		async ( files ) => {
			if ( ! files || files.length === 0 ) {
				return;
			}
			setIsUploading( true );
			const accepted = [];
			const localErrors = [];
			for ( const f of Array.from( files ) ) {
				if ( ! ACCEPTED_TYPES.includes( f.type ) ) {
					localErrors.push( {
						filename: f.name,
						message: __(
							'Only JPEG, PNG, GIF or WebP images are accepted.',
							'superdav-ai-agent'
						),
					} );
					continue;
				}
				if ( f.size > MAX_FILE_SIZE ) {
					localErrors.push( {
						filename: f.name,
						message: __(
							'File is larger than 12 MB. Please compress before uploading.',
							'superdav-ai-agent'
						),
					} );
					continue;
				}
				accepted.push( f );
			}

			const batchUploads = [];

			for ( const file of accepted ) {
				try {
					const body = buildFormData( file, sessionId );
					const resp = await apiFetch( {
						path: '/sd-ai-agent/v1/onboarding/interview-uploads',
						method: 'POST',
						body,
					} );
					if ( resp?.uploads?.length ) {
						batchUploads.push( ...resp.uploads );
					}
					if ( resp?.errors?.length ) {
						localErrors.push( ...resp.errors );
					}
				} catch ( e ) {
					localErrors.push( {
						filename: file.name,
						message:
							e?.message ||
							__( 'Upload failed.', 'superdav-ai-agent' ),
					} );
				}
			}

			if ( batchUploads.length ) {
				setUploads( ( prev ) => [ ...batchUploads, ...prev ] );
				const summary = buildUploadSummary( batchUploads );
				if ( summary ) {
					sendMessage( summary );
				}
			}
			if ( localErrors.length ) {
				setErrors( localErrors );
			} else {
				setErrors( [] );
			}
			setIsUploading( false );
		},
		[ sessionId, sendMessage ]
	);

	const onDrop = useCallback(
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

	const handlePick = useCallback(
		( e ) => {
			if ( e.target.files?.length ) {
				processFiles( e.target.files );
				e.target.value = '';
			}
		},
		[ processFiles ]
	);

	const totalCount = uploads.length;

	return (
		<div
			className={ `sdaa-onboarding-photo-upload${
				isDragOver ? ' is-drag-over' : ''
			}` }
			data-testid="sdaa-onboarding-photo-upload"
		>
			<div
				className="sdaa-onboarding-photo-upload__dropzone"
				role="button"
				tabIndex={ 0 }
				onDragOver={ ( e ) => {
					e.preventDefault();
					setIsDragOver( true );
				} }
				onDragLeave={ ( e ) => {
					e.preventDefault();
					setIsDragOver( false );
				} }
				onDrop={ onDrop }
				onClick={ () => fileRef.current?.click() }
				onKeyDown={ ( e ) => {
					if ( e.key === 'Enter' || e.key === ' ' ) {
						e.preventDefault();
						fileRef.current?.click();
					}
				} }
				aria-label={ __(
					'Drop photos here or click to browse',
					'superdav-ai-agent'
				) }
			>
				<input
					ref={ fileRef }
					type="file"
					accept={ ACCEPTED_TYPES.join( ',' ) }
					multiple
					style={ { display: 'none' } }
					onChange={ handlePick }
				/>
				<div className="sdaa-onboarding-photo-upload__headline">
					{ __(
						'Drop photos of your space, products, team or events',
						'superdav-ai-agent'
					) }
				</div>
				<div className="sdaa-onboarding-photo-upload__hint">
					{ isUploading
						? __( 'Uploading…', 'superdav-ai-agent' )
						: __(
								'JPEG, PNG, GIF or WebP up to 12 MB each. I will categorise them and weave them into your site.',
								'superdav-ai-agent'
						  ) }
				</div>
			</div>

			{ totalCount > 0 && (
				<div className="sdaa-onboarding-photo-upload__list">
					<div className="sdaa-onboarding-photo-upload__count">
						{ sprintf(
							/* translators: %d: number of photos uploaded */
							_n(
								'%d photo uploaded',
								'%d photos uploaded',
								totalCount,
								'superdav-ai-agent'
							),
							totalCount
						) }
					</div>
					<ul className="sdaa-onboarding-photo-upload__thumbs">
						{ uploads.map( ( u ) => (
							<li
								key={ u.attachment_id }
								className="sdaa-onboarding-photo-upload__thumb"
								title={ `${ u.filename } — ${ u.category }` }
							>
								<img
									src={ u.thumbnail || u.url }
									alt={ u.title || u.filename || '' }
								/>
								<span className="sdaa-onboarding-photo-upload__thumb-cat">
									{ CATEGORY_LABELS[ u.category ] ||
										u.category }
								</span>
							</li>
						) ) }
					</ul>
				</div>
			) }

			{ errors.length > 0 && (
				<ul className="sdaa-onboarding-photo-upload__errors">
					{ errors.map( ( err, idx ) => (
						<li key={ idx }>
							<strong>{ err.filename }</strong>: { err.message }
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
