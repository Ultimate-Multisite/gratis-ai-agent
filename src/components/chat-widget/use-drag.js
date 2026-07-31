/**
 * Drag hook for the floating widget's panel and launcher.
 *
 * Usage:
 *   const { position, isDragging, handleMouseDown, resetPosition } =
 *     useDrag( { storageKey, sizeFallback } );
 *
 *   <div style={ position && {
 *       left: position.x + 'px',
 *       bottom: position.y + 'px',
 *       right: 'auto',
 *       top: 'auto',
 *   } } onMouseDown={ handleMouseDown } />
 *
 * Position shape is `{ x: left-offset, y: bottom-offset }` — bottom-anchored
 * deliberately so a height change (e.g. minimizing) keeps the element
 * visually pinned at the bottom of its previous rect instead of the top.
 *
 * `handleMouseDown` looks up the closest `data-drag-target` ancestor as
 * the element to move — falling back to the element the handler was
 * attached to. Position is clamped to the viewport on drag and on
 * window resize, and persisted to localStorage under `storageKey`.
 * Returns `isDragging` so callers can ignore the synthetic click that
 * fires at mouseup after an actual drag (useful for the launcher, which
 * must not re-open the panel when the user was only moving it).
 */

import { useState, useRef, useCallback, useEffect } from '@wordpress/element';

/**
 *
 * @param {string} storageKey
 */
function readPosition( storageKey ) {
	try {
		const saved = localStorage.getItem( storageKey );
		return saved ? JSON.parse( saved ) : null;
	} catch {
		return null;
	}
}

/**
 *
 * @param {string} storageKey
 * @param {Object} pos
 */
function writePosition( storageKey, pos ) {
	try {
		localStorage.setItem( storageKey, JSON.stringify( pos ) );
	} catch {
		// ignore storage errors
	}
}

const DRAG_THRESHOLD_PX = 4;

/**
 *
 * @param {Object} root0
 * @param {string} root0.storageKey
 * @param {Object} root0.sizeFallback
 */
export default function useDrag( {
	storageKey,
	sizeFallback = { w: 400, h: 640 },
} ) {
	const [ position, setPosition ] = useState( () =>
		readPosition( storageKey )
	);
	const [ isDragging, setIsDragging ] = useState( false );
	const movedRef = useRef( false );
	const dragOffset = useRef( { x: 0, y: 0 } );
	const targetRef = useRef( null );
	const startPointRef = useRef( null );

	const clampToViewport = useCallback(
		( x, y, w, h ) => {
			const vw = window.innerWidth;
			const vh = window.innerHeight;
			const width = w || targetRef.current?.offsetWidth || sizeFallback.w;
			const height =
				h || targetRef.current?.offsetHeight || sizeFallback.h;
			// x = left-offset, y = bottom-offset. Keep element fully visible.
			return {
				x: Math.max( 0, Math.min( x, vw - width ) ),
				y: Math.max( 0, Math.min( y, vh - height ) ),
			};
		},
		[ sizeFallback.w, sizeFallback.h ]
	);

	const handleMouseDown = useCallback( ( e ) => {
		if ( e.button !== 0 ) {
			return;
		}
		// Ignore mousedowns on buttons/inputs/links — keep them interactive.
		if (
			e.target.closest(
				'button, input, textarea, select, a, [role="button"]'
			)
		) {
			return;
		}
		const el = e.target.closest( '[data-drag-target]' ) || e.currentTarget;
		if ( ! el ) {
			return;
		}
		targetRef.current = el;
		const rect = el.getBoundingClientRect();
		// x: cursor→element-left distance.
		// y: element-bottom→cursor distance (so position stays anchored
		// to the bottom-left corner as the cursor moves).
		dragOffset.current = {
			x: e.clientX - rect.left,
			y: rect.bottom - e.clientY,
		};
		startPointRef.current = { x: e.clientX, y: e.clientY };
		movedRef.current = false;
		setIsDragging( true );
		document.body.style.userSelect = 'none';
	}, [] );

	useEffect( () => {
		if ( ! isDragging ) {
			return undefined;
		}
		const handleMouseMove = ( e ) => {
			if ( startPointRef.current ) {
				const dx = e.clientX - startPointRef.current.x;
				const dy = e.clientY - startPointRef.current.y;
				if (
					! movedRef.current &&
					Math.sqrt( dx * dx + dy * dy ) >= DRAG_THRESHOLD_PX
				) {
					movedRef.current = true;
				}
			}
			if ( ! movedRef.current ) {
				return;
			}
			const x = e.clientX - dragOffset.current.x;
			// bottom-offset: viewport height minus element-bottom (cursor-Y + offset)
			const y = window.innerHeight - ( e.clientY + dragOffset.current.y );
			setPosition( clampToViewport( x, y ) );
		};
		const handleMouseUp = () => {
			setIsDragging( false );
			document.body.style.userSelect = '';
			setPosition( ( prev ) => {
				if ( ! prev ) {
					return prev;
				}
				writePosition( storageKey, prev );
				return prev;
			} );
		};
		window.addEventListener( 'mousemove', handleMouseMove );
		window.addEventListener( 'mouseup', handleMouseUp );
		return () => {
			window.removeEventListener( 'mousemove', handleMouseMove );
			window.removeEventListener( 'mouseup', handleMouseUp );
		};
	}, [ isDragging, storageKey, clampToViewport ] );

	// Reclamp when the viewport shrinks.
	useEffect( () => {
		const onResize = () => {
			setPosition( ( prev ) =>
				prev ? clampToViewport( prev.x, prev.y ) : prev
			);
		};
		window.addEventListener( 'resize', onResize );
		return () => window.removeEventListener( 'resize', onResize );
	}, [ clampToViewport ] );

	const resetPosition = useCallback( () => {
		setPosition( null );
		try {
			localStorage.removeItem( storageKey );
		} catch {
			// ignore
		}
	}, [ storageKey ] );

	/**
	 * Re-clamp the current position so an element of (width, height) fits
	 * entirely in the viewport with at least `margin` px of breathing room
	 * on all sides. Used when the panel transitions from minimized to
	 * expanded — the stored position was clamped against the tiny header
	 * height and can now leave the full panel overflowing.
	 *
	 * @param {number} width
	 * @param {number} height
	 * @param {number} [margin=16]
	 */
	const reclampForSize = useCallback(
		( width, height, margin = 16 ) => {
			if ( ! width || ! height ) {
				return;
			}
			setPosition( ( prev ) => {
				if ( ! prev ) {
					return prev;
				}
				const vw = window.innerWidth;
				const vh = window.innerHeight;
				const nextX = Math.max(
					margin,
					Math.min( prev.x, vw - width - margin )
				);
				const nextY = Math.max(
					margin,
					Math.min( prev.y, vh - height - margin )
				);
				if ( nextX === prev.x && nextY === prev.y ) {
					return prev;
				}
				const next = { x: nextX, y: nextY };
				writePosition( storageKey, next );
				return next;
			} );
		},
		[ storageKey ]
	);

	return {
		position,
		isDragging,
		moved: movedRef,
		handleMouseDown,
		resetPosition,
		reclampForSize,
	};
}
