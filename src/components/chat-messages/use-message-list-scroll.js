/**
 * Shared auto-scroll and unseen-message behavior for React chat surfaces.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/** Distance (px) from the bottom treated as following the latest message. */
const SCROLL_THRESHOLD = 100;

/**
 * Manage a chat scroll container without coupling it to surface CSS.
 *
 * @param {Object}  root0
 * @param {number}  root0.visibleCount       Number of visible messages.
 * @param {*}       root0.currentSessionId   Current session ID.
 * @param {boolean} root0.sending            Whether generation is active.
 * @param {Array}   root0.liveToolCalls      Live activity affecting row height.
 * @param {boolean} root0.preservePageScroll Restore window scroll after updates.
 * @return {{containerRef: Object, unseenCount: number, scrollToBottom: Function}}
 *   Scroll state and controls.
 */
export default function useMessageListScroll( {
	visibleCount,
	currentSessionId,
	sending,
	liveToolCalls,
	preservePageScroll = false,
} ) {
	const elementRef = useRef( null );
	const isAtBottomRef = useRef( true );
	const previousVisibleCountRef = useRef( 0 );
	const [ unseenCount, setUnseenCount ] = useState( 0 );

	const handleScroll = useCallback( () => {
		const element = elementRef.current;
		if ( ! element ) {
			return;
		}
		const atBottom =
			element.scrollHeight - element.scrollTop - element.clientHeight <
			SCROLL_THRESHOLD;
		isAtBottomRef.current = atBottom;
		if ( atBottom ) {
			setUnseenCount( 0 );
		}
	}, [] );

	const containerRef = useCallback(
		( element ) => {
			elementRef.current?.removeEventListener( 'scroll', handleScroll );
			elementRef.current = element;
			element?.addEventListener( 'scroll', handleScroll, {
				passive: true,
			} );
		},
		[ handleScroll ]
	);

	useEffect( () => {
		isAtBottomRef.current = true;
		previousVisibleCountRef.current = 0;
		setUnseenCount( 0 );
	}, [ currentSessionId ] );

	useEffect(
		() => () => {
			elementRef.current?.removeEventListener( 'scroll', handleScroll );
		},
		[ handleScroll ]
	);

	useEffect( () => {
		const element = elementRef.current;
		if ( ! element ) {
			return;
		}

		const previousCount = previousVisibleCountRef.current;
		previousVisibleCountRef.current = visibleCount;

		if ( isAtBottomRef.current ) {
			const savedY = preservePageScroll ? window.scrollY : null;
			element.scrollTop = element.scrollHeight;
			if ( preservePageScroll && window.scrollY !== savedY ) {
				window.scrollTo( 0, savedY );
			}
		} else if ( visibleCount > previousCount ) {
			setUnseenCount(
				( count ) => count + ( visibleCount - previousCount )
			);
		}
	}, [ visibleCount, sending, liveToolCalls, preservePageScroll ] );

	const scrollToBottom = useCallback( () => {
		const element = elementRef.current;
		if ( ! element ) {
			return;
		}
		element.scrollTo( { top: element.scrollHeight, behavior: 'smooth' } );
		isAtBottomRef.current = true;
		setUnseenCount( 0 );
	}, [] );

	return { containerRef, unseenCount, scrollToBottom };
}
