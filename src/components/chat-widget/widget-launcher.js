/**
 * Circular floating launcher (FAB) — opens the widget panel.
 *
 * Matches the redesigned widget: primary-coloured 52px circle with a
 * sparkles glyph (or white-label logo), a small notification dot when
 * the agent has an active job, and an alert badge for proactive alerts.
 */

import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import STORE_NAME from '../../store';
import { getBranding } from '../../utils/branding';
import { AiIcon } from '../chat-redesign/icons';
import useDrag from './use-drag';

const LAUNCHER_POSITION_STORAGE_KEY = 'aiAgentWidgetLauncherPosition';
const LAUNCHER_CLASS_NAME = 'sd-ai-agent-w-launcher';

/**
 * @param {Object}   root0              Component props.
 * @param {Function} [root0.onActivate] Optional activation override.
 * @param {string}   [root0.label]      Optional accessible label override.
 */
export default function WidgetLauncher( { onActivate, label: labelOverride } ) {
	const { setFloatingOpen } = useDispatch( STORE_NAME );
	const { alertCount, isRunning } = useSelect( ( sel ) => {
		const store = sel( STORE_NAME );
		const jobs = store.getSessionJobs() || {};
		const running = Object.values( jobs ).some(
			( j ) => j && j.status === 'processing'
		);
		return {
			alertCount: store.getAlertCount(),
			isRunning: running,
		};
	}, [] );

	const branding = getBranding();
	const label =
		labelOverride ||
		( branding.agentName
			? sprintf(
					/* translators: %s: agent display name */
					__( 'Open %s', 'sd-ai-agent' ),
					branding.agentName
			  )
			: __( 'Open AI Agent', 'sd-ai-agent' ) );

	const { position, moved, handleMouseDown } = useDrag( {
		storageKey: LAUNCHER_POSITION_STORAGE_KEY,
		sizeFallback: { w: 52, h: 52 },
	} );

	const handleClick = useCallback( () => {
		// Swallow the synthetic click that follows a drag gesture so
		// moving the FAB doesn't also open the panel.
		if ( moved.current ) {
			moved.current = false;
			return;
		}
		if ( onActivate ) {
			onActivate();
			return;
		}
		setFloatingOpen( true );
	}, [ moved, onActivate, setFloatingOpen ] );

	const positionStyle = position
		? {
				left: `${ position.x }px`,
				bottom: `${ position.y }px`,
				right: 'auto',
				top: 'auto',
		  }
		: undefined;

	return (
		<button
			type="button"
			className={ LAUNCHER_CLASS_NAME }
			data-drag-target="true"
			style={ positionStyle }
			onMouseDown={ handleMouseDown }
			onClick={ handleClick }
			aria-label={ label }
			title={ label }
		>
			{ branding.logoUrl ? (
				<img
					src={ branding.logoUrl }
					alt=""
					className={ `${ LAUNCHER_CLASS_NAME }-logo` }
					aria-hidden="true"
				/>
			) : (
				<AiIcon thinking={ isRunning } size={ 24 } />
			) }
			{ isRunning && (
				<span className={ `${ LAUNCHER_CLASS_NAME }-pulse` } />
			) }
			{ alertCount > 0 && (
				<span
					className={ `${ LAUNCHER_CLASS_NAME }-badge` }
					aria-label={ sprintf(
						/* translators: %d: number of alerts */
						__( '%d alert(s)', 'sd-ai-agent' ),
						alertCount
					) }
				>
					{ alertCount > 9 ? '9+' : alertCount }
				</span>
			) }
		</button>
	);
}
