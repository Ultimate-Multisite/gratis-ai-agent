/**
 * WordPress dependencies
 */
import { lazy } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
// ChatRoute is the default landing route — keep it in the initial chunk so
// the chat UI appears without any async loading on the first visit.
import ChatRoute from './routes/chat';

// All non-default routes are lazy-loaded so their code (SettingsApp and its
// 12 managers, AbilitiesExplorer, ChangesPage, …) is only downloaded when
// the user first navigates to that section.  webpackPrefetch fetches the
// chunks in the background during idle time so transitions feel instant.
const AbilitiesRoute = lazy( () =>
	import(
		/* webpackChunkName: "route-abilities", webpackPrefetch: true */
		'./routes/abilities'
	)
);
const ChangesRoute = lazy( () =>
	import(
		/* webpackChunkName: "route-changes", webpackPrefetch: true */
		'./routes/changes'
	)
);
const SettingsRoute = lazy( () =>
	import(
		/* webpackChunkName: "route-settings", webpackPrefetch: true */
		'./routes/settings'
	)
);

/**
 * Redirect #/connectors to the appropriate destination.
 *
 * On WP 7.0+ or Gutenberg 22.8.0+, redirects to the official Connectors
 * page. On WP 6.9 without Gutenberg, redirects to the plugin installer
 * to install Gutenberg.
 */
function redirectConnectors() {
	if ( window.sdAiAgentData?.connectorsAvailable ) {
		window.location.href =
			window.sdAiAgentData?.connectorsUrl ||
			'options-general.php?page=options-connectors-wp-admin';
	} else {
		window.location.href =
			'plugin-install.php?s=gutenberg&tab=search&type=term';
	}
}

/**
 * Router Component
 *
 * Renders the appropriate route based on the current hash path.
 *
 * @param {Object} props       Component props.
 * @param {string} props.route Current route.
 * @return {JSX.Element} Route component.
 */
export default function Router( { route } ) {
	const routeParts = ( route || '' ).split( '/' );
	const mainRoute = routeParts[ 0 ];
	const subRoute = routeParts.slice( 1 ).join( '/' ) || null;

	switch ( mainRoute ) {
		case 'chat':
		case '': {
			const sessionId = /^\d+$/.test( subRoute || '' )
				? Number( subRoute )
				: null;
			return <ChatRoute sessionId={ sessionId } />;
		}

		case 'abilities':
			return <AbilitiesRoute />;

		case 'changes':
			return <ChangesRoute />;

		case 'connectors':
			redirectConnectors();
			return null;

		case 'settings':
			return <SettingsRoute subRoute={ subRoute } />;

		default:
			return (
				<div className="sdaa-route-not-found">
					<h2>{ __( 'Page Not Found', 'sd-ai-agent' ) }</h2>
					<p>
						{ __(
							'The requested page could not be found.',
							'sd-ai-agent'
						) }
					</p>
				</div>
			);
	}
}
