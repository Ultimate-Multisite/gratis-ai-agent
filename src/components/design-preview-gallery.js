/**
 * DesignPreviewGallery — renders desktop + mobile previews for Theme Builder
 * design-direction selection (issue #1532).
 *
 * Rendered inside a ToolCard when the `sd-ai-agent/render-design-previews`
 * ability response contains a `design_previews` array.
 *
 * Each preview card shows:
 *  - A desktop viewport (1280×800) — PNG screenshot or scaled iframe fallback.
 *  - A mobile viewport (375×812)  — PNG screenshot or scaled iframe fallback.
 * Clicking either viewport opens a full-size zoom modal.
 */

import { useState, useRef, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Compute the CSS transform scale needed to fit a virtual viewport inside a
 * container of a given CSS pixel width.
 *
 * @param {number} containerWidth Available container width in CSS pixels.
 * @param {number} viewportWidth  Actual viewport width the iframe is sized to.
 * @return {number} Scale factor (≤ 1).
 */
function computeScale( containerWidth, viewportWidth ) {
	if ( containerWidth <= 0 || viewportWidth <= 0 ) {
		return 1;
	}
	return Math.min( 1, containerWidth / viewportWidth );
}

/**
 * A responsive iframe that is scaled down to fit inside its container while
 * preserving the aspect ratio of the real viewport.
 *
 * Uses a ResizeObserver so the scale updates when the panel is resized.
 *
 * @param {Object} root0
 * @param {string} root0.src            URL of the HTML file.
 * @param {number} root0.viewportWidth  Real viewport width (e.g. 1280).
 * @param {number} root0.viewportHeight Real viewport height (e.g. 800).
 * @param {string} root0.title          Accessible title for the iframe.
 */
function ScaledIframe( { src, viewportWidth, viewportHeight, title } ) {
	const wrapperRef = useRef( null );
	const [ scale, setScale ] = useState( 0.25 );

	useEffect( () => {
		const el = wrapperRef.current;
		if ( ! el ) {
			return;
		}

		const update = () => {
			setScale( computeScale( el.offsetWidth, viewportWidth ) );
		};

		update();

		const ro = new ResizeObserver( update );
		ro.observe( el );
		return () => ro.disconnect();
	}, [ viewportWidth ] );

	const containerHeight = Math.round( viewportHeight * scale );

	return (
		<div
			ref={ wrapperRef }
			className="sd-ai-agent-preview-iframe-container"
			style={ { height: containerHeight } }
		>
			<iframe
				src={ src }
				title={ title }
				width={ viewportWidth }
				height={ viewportHeight }
				style={ {
					transform: `scale(${ scale })`,
					transformOrigin: 'top left',
				} }
				sandbox="allow-same-origin allow-scripts"
				loading="lazy"
			/>
		</div>
	);
}

/**
 * Full-size zoom modal. Displays either:
 *  - A PNG screenshot image (when `isImage` is true), or
 *  - A responsive iframe at the full target viewport size.
 *
 * @param {Object}   root0
 * @param {string}   root0.src            URL of the screenshot or HTML file.
 * @param {boolean}  root0.isImage        True when showing a PNG screenshot.
 * @param {string}   root0.label          Modal aria-label and heading text.
 * @param {Function} root0.onClose        Called when the modal is dismissed.
 * @param {number}   root0.viewportWidth  Real viewport width (for iframe mode).
 * @param {number}   root0.viewportHeight Real viewport height (for iframe mode).
 */
function ZoomModal( {
	src,
	isImage,
	label,
	onClose,
	viewportWidth,
	viewportHeight,
} ) {
	// Close on Escape key.
	useEffect( () => {
		const handleKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				onClose();
			}
		};
		document.addEventListener( 'keydown', handleKey );
		return () => document.removeEventListener( 'keydown', handleKey );
	}, [ onClose ] );

	return (
		/* eslint-disable jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions, jsx-a11y/no-noninteractive-element-interactions */
		<div
			className="sd-ai-agent-preview-zoom-backdrop"
			onClick={ onClose }
			role="dialog"
			aria-modal="true"
			aria-label={ label }
		>
			<div
				className="sd-ai-agent-preview-zoom-inner"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<button
					type="button"
					className="sd-ai-agent-preview-zoom-close"
					onClick={ onClose }
					aria-label={ __( 'Close preview', 'superdav-ai-agent' ) }
				>
					&times;
				</button>
				<p className="sd-ai-agent-preview-zoom-label">{ label }</p>
				{ isImage ? (
					<img
						src={ src }
						alt={ label }
						className="sd-ai-agent-preview-zoom-img"
					/>
				) : (
					<iframe
						src={ src }
						title={ label }
						className="sd-ai-agent-preview-zoom-iframe"
						width={ viewportWidth }
						height={ viewportHeight }
						sandbox="allow-same-origin allow-scripts"
					/>
				) }
			</div>
		</div>
		/* eslint-enable jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions, jsx-a11y/no-noninteractive-element-interactions */
	);
}

/**
 * A single design-direction preview card showing desktop and mobile viewports
 * side by side.
 *
 * When screenshots (PNG) are available they are shown as `<img>` elements.
 * When only the HTML URL is available (iframe fallback) the preview is
 * rendered as a scaled `<iframe>`.
 *
 * @param {Object} root0
 * @param {Object} root0.preview Design preview object from the ability response.
 * @param {number} root0.index   Zero-based card index for accessible labels.
 */
function DesignPreviewCard( { preview, index } ) {
	const [ zoom, setZoom ] = useState( null ); // { src, isImage, label, vw, vh }

	const openZoom = useCallback( ( src, isImage, label, vw, vh ) => {
		setZoom( { src, isImage, label, vw, vh } );
	}, [] );

	const closeZoom = useCallback( () => setZoom( null ), [] );

	const {
		name,
		html_url: htmlUrl,
		desktop_url: desktopUrl,
		mobile_url: mobileUrl,
		desktop_unavailable: desktopUnavailable,
		mobile_unavailable: mobileUnavailable,
		rendering_method: renderingMethod,
	} = preview;

	const cardLabel = name || `Design ${ index + 1 }`;
	const usesScreenshots = renderingMethod === 'screenshot';

	// Desktop viewport config.
	const desktopVW = 1280;
	const desktopVH = 800;
	// Mobile viewport config.
	const mobileVW = 375;
	const mobileVH = 812;

	const desktopLabel = `${ cardLabel } — ${ __(
		'Desktop preview',
		'superdav-ai-agent'
	) }`;
	const mobileLabel = `${ cardLabel } — ${ __(
		'Mobile preview',
		'superdav-ai-agent'
	) }`;

	// Pre-compute desktop viewport content to avoid nested ternary expressions.
	let desktopContent;
	if ( desktopUnavailable ) {
		desktopContent = (
			<div className="sd-ai-agent-preview-unavailable">
				{ __( 'Desktop preview unavailable', 'superdav-ai-agent' ) }
			</div>
		);
	} else if ( usesScreenshots && desktopUrl ) {
		desktopContent = (
			<button
				type="button"
				className="sd-ai-agent-preview-thumb-btn"
				onClick={ () =>
					openZoom(
						desktopUrl,
						true,
						desktopLabel,
						desktopVW,
						desktopVH
					)
				}
				title={ __( 'Click to zoom', 'superdav-ai-agent' ) }
			>
				<img
					src={ desktopUrl }
					alt={ desktopLabel }
					className="sd-ai-agent-preview-thumb-img"
					loading="lazy"
				/>
			</button>
		);
	} else {
		desktopContent = (
			<button
				type="button"
				className="sd-ai-agent-preview-thumb-btn"
				onClick={ () =>
					openZoom(
						htmlUrl,
						false,
						desktopLabel,
						desktopVW,
						desktopVH
					)
				}
				title={ __( 'Click to zoom', 'superdav-ai-agent' ) }
			>
				<ScaledIframe
					src={ htmlUrl }
					viewportWidth={ desktopVW }
					viewportHeight={ desktopVH }
					title={ desktopLabel }
				/>
			</button>
		);
	}

	// Pre-compute mobile viewport content to avoid nested ternary expressions.
	let mobileContent;
	if ( mobileUnavailable ) {
		mobileContent = (
			<div className="sd-ai-agent-preview-unavailable">
				{ __( 'Mobile preview unavailable', 'superdav-ai-agent' ) }
			</div>
		);
	} else if ( usesScreenshots && mobileUrl ) {
		mobileContent = (
			<button
				type="button"
				className="sd-ai-agent-preview-thumb-btn"
				onClick={ () =>
					openZoom( mobileUrl, true, mobileLabel, mobileVW, mobileVH )
				}
				title={ __( 'Click to zoom', 'superdav-ai-agent' ) }
			>
				<img
					src={ mobileUrl }
					alt={ mobileLabel }
					className="sd-ai-agent-preview-thumb-img"
					loading="lazy"
				/>
			</button>
		);
	} else {
		mobileContent = (
			<button
				type="button"
				className="sd-ai-agent-preview-thumb-btn"
				onClick={ () =>
					openZoom( htmlUrl, false, mobileLabel, mobileVW, mobileVH )
				}
				title={ __( 'Click to zoom', 'superdav-ai-agent' ) }
			>
				<ScaledIframe
					src={ htmlUrl }
					viewportWidth={ mobileVW }
					viewportHeight={ mobileVH }
					title={ mobileLabel }
				/>
			</button>
		);
	}

	return (
		<div className="sd-ai-agent-design-preview-card">
			<h4 className="sd-ai-agent-design-preview-card-name">
				{ cardLabel }
			</h4>

			<div className="sd-ai-agent-design-preview-viewports">
				{ /* ── Desktop ─────────────────────────────────────────── */ }
				<div className="sd-ai-agent-preview-viewport-desktop">
					<div className="sd-ai-agent-preview-viewport-label">
						{ __( 'Desktop', 'superdav-ai-agent' ) }
					</div>
					{ desktopContent }
				</div>

				{ /* ── Mobile ──────────────────────────────────────────── */ }
				<div className="sd-ai-agent-preview-viewport-mobile">
					<div className="sd-ai-agent-preview-viewport-label">
						{ __( 'Mobile', 'superdav-ai-agent' ) }
					</div>
					{ mobileContent }
				</div>
			</div>

			{ zoom && (
				<ZoomModal
					src={ zoom.src }
					isImage={ zoom.isImage }
					label={ zoom.label }
					onClose={ closeZoom }
					viewportWidth={ zoom.vw }
					viewportHeight={ zoom.vh }
				/>
			) }
		</div>
	);
}

/**
 * DesignPreviewGallery — renders a row of DesignPreviewCard elements from a
 * `design_previews` array returned by the `sd-ai-agent/render-design-previews`
 * ability.
 *
 * @param {Object} root0
 * @param {Array}  root0.designPreviews Array of design preview objects.
 * @param {string} [root0.message]      Optional summary message from the ability.
 */
export default function DesignPreviewGallery( { designPreviews, message } ) {
	if ( ! Array.isArray( designPreviews ) || designPreviews.length === 0 ) {
		return null;
	}

	return (
		<div className="sd-ai-agent-design-preview-gallery">
			{ message && (
				<p className="sd-ai-agent-design-preview-gallery-msg">
					{ message }
				</p>
			) }
			{ designPreviews.map( ( preview, i ) => (
				<DesignPreviewCard
					key={ preview.name || i }
					preview={ preview }
					index={ i }
				/>
			) ) }
		</div>
	);
}
