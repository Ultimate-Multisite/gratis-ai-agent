/**
 * Generic chat banner host.
 *
 * Renders an array of banners returned by `/sd-ai-agent/v1/chat-banners`
 * at the top of the chat panel. Banners are produced by other plugins
 * via the `sd_ai_agent_chat_banners` PHP filter — this component is
 * intentionally domain-agnostic.
 *
 * Each banner contributed by a producer is expected to have the shape:
 *
 * ```
 * {
 *   id:          string  // stable identifier; used as React key
 *   severity:    'info' | 'warning' | 'error'
 *   message:     string  // plain-text body (no HTML)
 *   cta_label?:  string  // optional; required if cta_url is set
 *   cta_url?:    string  // optional; opened in a new tab when present
 * }
 * ```
 *
 * Behaviour:
 *
 * - Polls the endpoint every 5 minutes so producers (e.g. usage caps)
 *   can change state between turns without a page reload.
 * - Hides itself silently on 404, 401, or any network error so a
 *   missing or unauthorised producer never blocks the chat UI.
 * - Skips any banner that is missing a `severity` or `message` to keep
 *   ill-formed producer output from crashing the renderer.
 *
 * Producers that need to surface live data should declare their own
 * REST endpoint and filter into `sd_ai_agent_chat_banners` server-side.
 * The AI Agent never inspects the banner payload beyond the schema
 * documented above.
 *
 * @return {JSX.Element|null} The banner stack, or null when nothing to show.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Refresh interval for the chat-banners endpoint.
 */
const REFRESH_MS = 5 * 60 * 1000;

/**
 * Map a producer-supplied severity to a CSS modifier class.
 *
 * @param {string} severity One of 'info', 'warning', 'error'.
 * @return {string} CSS modifier class, falling back to 'info'.
 */
function severityClass( severity ) {
	if ( 'error' === severity ) {
		return 'sd-ai-agent-chat-banner--error';
	}
	if ( 'warning' === severity ) {
		return 'sd-ai-agent-chat-banner--warning';
	}
	return 'sd-ai-agent-chat-banner--info';
}

/**
 * Validate that a banner payload has the minimum required fields.
 *
 * @param {Object} banner Banner object from the REST endpoint.
 * @return {boolean} True if the banner is renderable.
 */
function isRenderable( banner ) {
	if ( ! banner || 'object' !== typeof banner ) {
		return false;
	}
	if ( ! banner.message || 'string' !== typeof banner.message ) {
		return false;
	}
	const severity = banner.severity;
	if (
		'info' !== severity &&
		'warning' !== severity &&
		'error' !== severity
	) {
		return false;
	}
	return true;
}

/**
 * Chat banners component.
 *
 * @return {JSX.Element|null} Banner stack, or null when nothing to render.
 */
export default function ChatBanners() {
	const [ banners, setBanners ] = useState( [] );

	useEffect( () => {
		let cancelled = false;

		const fetchBanners = () => {
			apiFetch( { path: '/sd-ai-agent/v1/chat-banners' } )
				.then( ( data ) => {
					if ( cancelled ) {
						return;
					}
					const list = Array.isArray( data?.banners )
						? data.banners.filter( isRenderable )
						: [];
					setBanners( list );
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setBanners( [] );
					}
				} );
		};

		fetchBanners();
		const interval = setInterval( fetchBanners, REFRESH_MS );

		return () => {
			cancelled = true;
			clearInterval( interval );
		};
	}, [] );

	if ( banners.length === 0 ) {
		return null;
	}

	return (
		<div className="sd-ai-agent-chat-banners">
			{ banners.map( ( banner, index ) => {
				// Producers SHOULD supply a stable id; fall back to the
				// array index so React doesn't warn when they don't.
				const key =
					banner.id && 'string' === typeof banner.id
						? banner.id
						: `banner-${ index }`;
				const className = `sd-ai-agent-chat-banner ${ severityClass(
					banner.severity
				) }`;
				const role = 'error' === banner.severity ? 'alert' : 'status';

				return (
					<div key={ key } className={ className } role={ role }>
						<span className="sd-ai-agent-chat-banner__message">
							{ banner.message }
						</span>
						{ banner.cta_url && banner.cta_label && (
							<a
								className="sd-ai-agent-chat-banner__cta"
								href={ banner.cta_url }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ banner.cta_label }
							</a>
						) }
					</div>
				);
			} ) }
		</div>
	);
}
