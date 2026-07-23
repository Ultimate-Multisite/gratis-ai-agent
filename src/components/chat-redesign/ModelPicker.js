/**
 * Model chip — combined provider + model selector.
 *
 * Clicking the chip opens an upward popover with provider-grouped model
 * rows. Selecting a model updates the store's selectedProviderId and
 * selectedModelId. The popover is portaled to document.body and positioned
 * with fixed coordinates so it is not clipped by any ancestor overflow.
 */

import {
	useState,
	useRef,
	useEffect,
	useLayoutEffect,
	useCallback,
} from '@wordpress/element';
import { createPortal } from 'react-dom';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Icon, chevronDown, check } from '@wordpress/icons';

import STORE_NAME from '../../store';

/**
 *
 */
export default function ModelPicker() {
	const { providers, selectedProviderId, selectedModelId } = useSelect(
		( sel ) => {
			const s = sel( STORE_NAME );
			return {
				providers: s.getProviders(),
				selectedProviderId: s.getSelectedProviderId(),
				selectedModelId: s.getSelectedModelId(),
			};
		},
		[]
	);
	const { setSelectedProvider, setSelectedModel, fetchProviders } =
		useDispatch( STORE_NAME );
	const [ open, setOpen ] = useState( false );
	const [ pos, setPos ] = useState( {
		left: 0,
		bottom: 0,
		width: 0,
		maxHeight: 400,
	} );
	const chipRef = useRef( null );
	const popoverRef = useRef( null );

	const updatePosition = useCallback( () => {
		if ( ! chipRef.current ) {
			return;
		}
		const rect = chipRef.current.getBoundingClientRect();
		const gap = 6;
		const topMargin = 16;
		// Anchor the bottom of the popover just above the chip, and limit its
		// height so it never exceeds the space between the viewport top and
		// the chip (minus margins).
		setPos( {
			left: rect.left,
			bottom: window.innerHeight - rect.top + gap,
			width: rect.width,
			maxHeight: Math.max( 120, rect.top - gap - topMargin ),
		} );
	}, [] );

	useLayoutEffect( () => {
		if ( open ) {
			updatePosition();
		}
	}, [ open, updatePosition ] );

	// When the popover opens, move focus to the active menu item so keyboard
	// users land on a sensible starting point without having to tab through.
	useEffect( () => {
		if ( ! open || ! popoverRef.current ) {
			return;
		}
		const active = popoverRef.current.querySelector(
			'[role="menuitem"].is-active'
		);
		const first = popoverRef.current.querySelector( '[role="menuitem"]' );
		( active ?? first )?.focus();
	}, [ open ] );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		const handler = ( e ) => {
			if (
				chipRef.current &&
				! chipRef.current.contains( e.target ) &&
				popoverRef.current &&
				! popoverRef.current.contains( e.target )
			) {
				setOpen( false );
			}
		};
		const onScrollOrResize = () => updatePosition();
		const onKeyDown = ( e ) => {
			if ( ! popoverRef.current ) {
				return;
			}
			const items = Array.from(
				popoverRef.current.querySelectorAll( '[role="menuitem"]' )
			);
			const focused = popoverRef.current.ownerDocument.activeElement;
			const currentIndex = items.indexOf( focused );

			if ( e.key === 'Escape' ) {
				e.preventDefault();
				setOpen( false );
				chipRef.current?.focus();
			} else if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				const next = items[ currentIndex + 1 ] ?? items[ 0 ];
				next?.focus();
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				const prev =
					items[ currentIndex - 1 ] ?? items[ items.length - 1 ];
				prev?.focus();
			} else if ( e.key === 'Home' ) {
				e.preventDefault();
				items[ 0 ]?.focus();
			} else if ( e.key === 'End' ) {
				e.preventDefault();
				items[ items.length - 1 ]?.focus();
			}
		};
		document.addEventListener( 'mousedown', handler );
		document.addEventListener( 'keydown', onKeyDown );
		window.addEventListener( 'resize', onScrollOrResize );
		window.addEventListener( 'scroll', onScrollOrResize, true );
		return () => {
			document.removeEventListener( 'mousedown', handler );
			document.removeEventListener( 'keydown', onKeyDown );
			window.removeEventListener( 'resize', onScrollOrResize );
			window.removeEventListener( 'scroll', onScrollOrResize, true );
		};
	}, [ open, updatePosition ] );

	const activeProvider =
		providers.find( ( p ) => p.id === selectedProviderId ) ||
		providers[ 0 ];
	const activeModel =
		activeProvider?.models?.find( ( m ) => m.id === selectedModelId ) ||
		activeProvider?.models?.[ 0 ];

	if ( ! activeProvider ) {
		return null;
	}

	const unavailableLabel = __(
		'(models unavailable; retry)',
		'superdav-ai-agent'
	);
	const pick = ( providerId, modelId ) => {
		if ( providerId !== selectedProviderId ) {
			setSelectedProvider( providerId );
		}
		setSelectedModel( modelId );
		setOpen( false );
	};

	const popover = open
		? createPortal(
				<div
					ref={ popoverRef }
					className="sdaa-cr-popover sdaa-cr-popover-fixed"
					role="menu"
					aria-label={ __( 'Select model', 'sd-ai-agent' ) }
					style={ {
						left: pos.left,
						bottom: pos.bottom,
						minWidth: Math.max( pos.width, 240 ),
						maxHeight: pos.maxHeight,
					} }
				>
					{ providers.map( ( p ) => (
						<div key={ p.id }>
							<div className="sdaa-cr-popover-section-label">
								{ p.name }
							</div>
							{ ( p.models || [] ).map( ( m ) => {
								const active =
									p.id === selectedProviderId &&
									m.id === selectedModelId;
								return (
									<button
										type="button"
										key={ m.id }
										role="menuitem"
										className={ `sdaa-cr-popover-item${
											active ? ' is-active' : ''
										}` }
										onClick={ () => pick( p.id, m.id ) }
									>
										<span>{ m.name || m.id }</span>
										{ m.sub && (
											<span className="sdaa-cr-popover-item-sub">
												{ m.sub }
											</span>
										) }
										{ active && (
											<span className="sdaa-cr-popover-item-check">
												<Icon
													icon={ check }
													size={ 14 }
												/>
											</span>
										) }
									</button>
								);
							} ) }
						</div>
					) ) }
				</div>,
				document.body
		  )
		: null;

	return (
		<div className="sdaa-cr-model-chip-wrap">
			<button
				ref={ chipRef }
				type="button"
				className="sdaa-cr-model-chip"
				onClick={ () =>
					! activeProvider.models?.length &&
					activeProvider.model_discovery
						? fetchProviders()
						: setOpen( ( v ) => ! v )
				}
				aria-haspopup="menu"
				aria-expanded={ open }
				title={ __( 'Change model', 'sd-ai-agent' ) }
			>
				<span className="sdaa-cr-model-chip-provider">
					{ activeProvider.name }
				</span>
				<span className="sdaa-cr-model-chip-model">
					{ activeModel?.name ||
						activeModel?.id ||
						( activeProvider.model_discovery
							? unavailableLabel
							: __( '(default)', 'sd-ai-agent' ) ) }
				</span>
				<Icon icon={ chevronDown } size={ 14 } />
			</button>
			{ popover }
		</div>
	);
}
