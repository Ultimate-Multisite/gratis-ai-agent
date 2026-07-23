/**
 * WordPress dependencies
 */
import { createRoot, useState, useEffect, Suspense } from '@wordpress/element';
import { Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.css';
// Register sd-ai-agent-js/* client-side abilities into core/abilities
// before the chat mounts (t165 — closes the wiring gap in #815).
import '../abilities';
import Router from './router';
import { AppProvider } from './context';

/**
 * Derive the initial route from the URL hash (JS-side), falling back to the
 * PHP-localized value (which cannot read fragments) or 'chat'.
 *
 * @return {string} Initial route string.
 */
function getInitialRoute() {
	const hash = window.location.hash;
	if ( hash && hash.startsWith( '#/' ) ) {
		return hash.substring( 2 ) || 'chat';
	}
	return window.sdAiAgentData?.initialRoute || 'chat';
}

/**
 * Unified Admin App Component
 *
 * Main entry point for the unified admin SPA. Manages hash-based routing,
 * listens for hashchange events, and updates the document title on navigation.
 *
 * @return {JSX.Element} App element.
 */
function UnifiedAdminApp() {
	const [ currentRoute, setCurrentRoute ] = useState( getInitialRoute );
	const [ notice, setNotice ] = useState( null );

	// Listen for hash changes.
	useEffect( () => {
		const handleHashChange = () => {
			const hash = window.location.hash;
			if ( hash && hash.startsWith( '#/' ) ) {
				setCurrentRoute( hash.substring( 2 ) || 'chat' );
			} else {
				// Bare '#' or empty hash — default to chat.
				setCurrentRoute( 'chat' );
			}
		};

		window.addEventListener( 'hashchange', handleHashChange );

		return () =>
			window.removeEventListener( 'hashchange', handleHashChange );
	}, [] );

	// Update document title based on route.
	useEffect( () => {
		const menuItems = window.sdAiAgentData?.menuItems || [];
		const baseRoute = currentRoute.split( '/' )[ 0 ];
		const currentItem = menuItems.find(
			( item ) => item.slug === baseRoute
		);
		if ( currentItem ) {
			document.title = `${ currentItem.label } - ${ __(
				'SD AI Agent',
				'superdav-ai-agent'
			) }`;
		}

		// Sync WordPress admin submenu highlight with the current hash route.
		// WordPress marks the active submenu server-side, but since all our
		// submenu items share the same `page=sd-ai-agent` query and only
		// differ by URL fragment (which the server never sees), only the first
		// item is ever highlighted. Update the `current` class client-side.
		const parentMenu = document.getElementById(
			'toplevel_page_sd-ai-agent'
		);
		if ( parentMenu ) {
			const links = parentMenu.querySelectorAll( '.wp-submenu a' );
			links.forEach( ( link ) => {
				const href = decodeURIComponent(
					link.getAttribute( 'href' ) || ''
				);
				const li = link.parentElement;
				let isCurrent = false;
				if ( baseRoute === 'chat' ) {
					isCurrent =
						/[?&]page=sd-ai-agent$/.test( href ) &&
						! href.includes( '#' );
				} else {
					isCurrent = href.endsWith( '#/' + baseRoute );
				}
				if ( isCurrent ) {
					link.classList.add( 'current' );
					li?.classList.add( 'current' );
				} else {
					link.classList.remove( 'current' );
					li?.classList.remove( 'current' );
				}
			} );
		}
	}, [ currentRoute ] );

	const appContext = {
		currentRoute,
		setCurrentRoute,
		showNotice: ( status, message ) => setNotice( { status, message } ),
		clearNotice: () => setNotice( null ),
	};

	return (
		<AppProvider value={ appContext }>
			<div className="sdaa-unified-admin">
				{ notice && (
					<Notice
						status={ notice.status }
						isDismissible
						onRemove={ () => setNotice( null ) }
						className="sd-ai-admin-notice"
					>
						{ notice.message }
					</Notice>
				) }

				<div className="sd-ai-admin-layout">
					<main className="sd-ai-admin-main">
						<Suspense fallback={ <Spinner /> }>
							<Router route={ currentRoute } />
						</Suspense>
					</main>
				</div>
			</div>
		</AppProvider>
	);
}

const container = document.getElementById( 'sdaa-root' );
if ( container ) {
	const root = createRoot( container );
	root.render( <UnifiedAdminApp /> );
}
