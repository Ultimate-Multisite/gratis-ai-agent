/**
 * Tool call card — collapsible, status chip + name + summary,
 * expanded body shows arguments and result with optional revert strip.
 *
 * Adapts the design spec to the store's `{ type: 'call', ... }` +
 * `{ type: 'result', ... }` pair shape.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon, check, chevronDown, undo, caution } from '@wordpress/icons';
import DesignPreviewGallery from '../design-preview-gallery';

/**
 *
 * @param {*} name
 */
function formatName( name ) {
	let display = name || '';
	if ( display.startsWith( 'wpab__' ) ) {
		display = display.substring( 6 );
	}
	return display.replace( /__/g, '/' );
}

/**
 *
 * @param {*} value
 */
function formatValue( value ) {
	if ( value === null || value === undefined ) {
		return '';
	}
	if ( typeof value === 'string' ) {
		return value;
	}
	try {
		return JSON.stringify( value, null, 2 );
	} catch {
		return String( value );
	}
}

/**
 *
 * @param {Object} root0
 * @param {*}      root0.status
 */
function StatusChip( { status } ) {
	if ( status === 'running' ) {
		return (
			<span className="sdaa-cr-tool-status is-running">
				<span className="sdaa-cr-tool-spin" />
			</span>
		);
	}
	if ( status === 'error' ) {
		return (
			<span className="sdaa-cr-tool-status is-error">
				<span
					style={ { fontWeight: 700, fontSize: 11, lineHeight: 1 } }
				>
					!
				</span>
			</span>
		);
	}
	if ( status === 'warn' ) {
		return (
			<span className="sdaa-cr-tool-status is-warn">
				<span
					style={ { fontWeight: 700, fontSize: 11, lineHeight: 1 } }
				>
					!
				</span>
			</span>
		);
	}
	return (
		<span className="sdaa-cr-tool-status is-ok">
			<Icon icon={ check } size={ 12 } />
		</span>
	);
}

/**
 *
 * @param {*} call
 * @param {*} response
 */
function deriveStatus( call, response ) {
	if ( ! response ) {
		return 'running';
	}
	const r = response.response;
	if ( r && typeof r === 'object' ) {
		if ( r.success === false || r.error ) {
			return 'error';
		}
		if ( r.warning ) {
			return 'warn';
		}
	}
	return 'ok';
}

/**
 *
 * @param {*} call
 * @param {*} response
 */
function deriveSummary( call, response ) {
	if ( ! response ) {
		return '';
	}
	const r = response.response;
	if ( r && typeof r === 'object' ) {
		if ( r.summary ) {
			return r.summary;
		}
		if ( r.title ) {
			return r.title;
		}
		if ( r.id && r.status ) {
			return `ID ${ r.id } · ${ r.status }`;
		}
	}
	if ( typeof r === 'string' && r.length < 120 ) {
		return r;
	}
	return '';
}

/**
 * Return design_previews from a render-design-previews tool response, or null.
 *
 * The ability name can be bare ('sd-ai-agent/render-design-previews') or
 * prefixed with the WP abilities bridge prefix ('wpab__sd-ai-agent__render-design-previews').
 *
 * @param {*} call
 * @param {*} response
 * @return {Array|null} Array of design preview objects, or null if not a preview tool call.
 */
function extractDesignPreviews( call, response ) {
	const rawName = ( call.name || '' ).toLowerCase();
	const isPreviewTool =
		rawName === 'sd-ai-agent/render-design-previews' ||
		rawName.includes( 'render-design-previews' ) ||
		rawName.includes( 'render__design__previews' );

	if ( ! isPreviewTool ) {
		return null;
	}
	const r = response && response.response;
	if (
		r &&
		Array.isArray( r.design_previews ) &&
		r.design_previews.length > 0
	) {
		return r.design_previews;
	}
	return null;
}

/**
 *
 * @param {*} call
 */
function isMutating( call ) {
	// Heuristic: any tool whose name hints at creating/updating/deleting
	// is considered mutating. The store doesn't carry this flag natively.
	const n = ( call.name || '' ).toLowerCase();
	return /create|update|delete|install|activate|deactivate|switch|set|move|publish|draft|revert|bulk|import/.test(
		n
	);
}

/**
 *
 * @param {Object} root0
 * @param {*}      root0.call
 * @param {*}      root0.response
 * @param {*}      root0.defaultOpen
 * @param {*}      root0.onRevert
 */
export default function ToolCard( {
	call,
	response,
	defaultOpen = false,
	onRevert,
} ) {
	const status = deriveStatus( call, response );
	const [ open, setOpen ] = useState( defaultOpen );

	const name = formatName( call.name );
	const summary = deriveSummary( call, response );
	const args = formatValue( call.args );
	const result = response ? formatValue( response.response ) : '';
	const mutating = isMutating( call );

	// Extract design previews when this is a render-design-previews tool call.
	const designPreviews = extractDesignPreviews( call, response );
	const previewMessage = response?.response?.message || null;

	return (
		<div
			className={ `sdaa-cr-tool-card${ open ? ' is-open' : '' }${
				status === 'running' ? ' is-running' : ''
			}${ status === 'error' ? ' is-error' : '' }` }
		>
			<button
				type="button"
				className="sdaa-cr-tool-card-head"
				onClick={ () => setOpen( ( v ) => ! v ) }
				aria-expanded={ open }
			>
				<StatusChip status={ status } />
				<div className="sdaa-cr-tool-card-name">
					<code>{ name }</code>
					{ summary && (
						<span className="sdaa-cr-tool-card-summary">
							{ summary }
						</span>
					) }
				</div>
				<div
					className={ `sdaa-cr-tool-card-meta${
						status === 'running' ? ' is-running' : ''
					}` }
				>
					{ status === 'running' && (
						<span>{ __( 'Running…', 'sd-ai-agent' ) }</span>
					) }
					<span className="sdaa-cr-tool-card-chevron">
						<Icon icon={ chevronDown } size={ 16 } />
					</span>
				</div>
			</button>

			{ /* Design preview gallery is always visible when previews exist */ }
			{ designPreviews && (
				<DesignPreviewGallery
					designPreviews={ designPreviews }
					message={ previewMessage }
				/>
			) }

			{ open && (
				<div className="sdaa-cr-tool-card-body">
					{ args && args !== '{}' && args !== 'null' && (
						<div>
							<div className="sdaa-cr-tool-detail-label">
								{ __( 'Arguments', 'sd-ai-agent' ) }
							</div>
							<pre className="sdaa-cr-tool-detail-value">
								{ args }
							</pre>
						</div>
					) }
					{ result && (
						<div>
							<div className="sdaa-cr-tool-detail-label">
								{ __( 'Result', 'sd-ai-agent' ) }
							</div>
							<pre className="sdaa-cr-tool-detail-value">
								{ result }
							</pre>
						</div>
					) }
					{ mutating && response && (
						<div className="sdaa-cr-tool-detail-revert">
							<span className="sdaa-cr-tool-detail-revert-note">
								<Icon icon={ caution } size={ 14 } />
								{ __(
									'This action modified your site.',
									'sd-ai-agent'
								) }
							</span>
							{ onRevert && (
								<button
									type="button"
									className="sdaa-cr-btn-sm"
									onClick={ onRevert }
								>
									<Icon icon={ undo } size={ 12 } />
									{ __( 'Revert this', 'sd-ai-agent' ) }
								</button>
							) }
						</div>
					) }
				</div>
			) }
		</div>
	);
}
