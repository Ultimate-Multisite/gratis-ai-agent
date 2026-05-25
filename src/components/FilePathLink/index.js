/**
 * FilePathLink component for rendering file paths as clickable links.
 *
 * Renders file paths as:
 * 1. Links to plugin-editor.php or theme-editor.php when file-mods are allowed
 * 2. Inline read-only viewer when file-mods are disallowed or path is outside plugins/themes
 *
 * @package
 * @license GPL-2.0-or-later
 */

import { useState, useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.css';

/**
 * Determine if a path is inside the plugin directory.
 *
 * @param {string} path        - The file path to check.
 * @param {string} wpPluginDir - The WP_PLUGIN_DIR from localized data.
 * @return {Object|null} Object with {pluginFile, pluginFolder} or null if not in plugin dir.
 */
function getPluginInfo( path, wpPluginDir ) {
	if ( ! wpPluginDir || ! path.includes( wpPluginDir ) ) {
		return null;
	}

	// Extract the relative path from wp-content/plugins/...
	const pluginDirIndex = path.indexOf( wpPluginDir );
	if ( pluginDirIndex === -1 ) {
		return null;
	}

	const relativePath = path
		.substring( pluginDirIndex + wpPluginDir.length )
		.replace( /^\//, '' );
	const parts = relativePath.split( '/' );

	if ( parts.length === 0 ) {
		return null;
	}

	// Single-file plugin: plugin={filename}
	if ( parts.length === 1 ) {
		return {
			pluginFile: parts[ 0 ],
			pluginFolder: parts[ 0 ],
		};
	}

	// Multi-file plugin: plugin={folder}/{entry}.php
	return {
		pluginFile: `${ parts[ 0 ] }/${ parts[ 1 ] }`,
		pluginFolder: parts[ 0 ],
	};
}

/**
 * Determine if a path is inside the theme directory.
 *
 * @param {string} path        - The file path to check.
 * @param {string} wpThemeRoot - The theme root directory from localized data.
 * @return {Object|null} Object with {themeStylesheet, relativePath} or null if not in theme dir.
 */
function getThemeInfo( path, wpThemeRoot ) {
	if ( ! wpThemeRoot || ! path.includes( wpThemeRoot ) ) {
		return null;
	}

	const themeRootIndex = path.indexOf( wpThemeRoot );
	if ( themeRootIndex === -1 ) {
		return null;
	}

	const relativePath = path
		.substring( themeRootIndex + wpThemeRoot.length )
		.replace( /^\//, '' );
	const parts = relativePath.split( '/' );

	if ( parts.length < 2 ) {
		return null;
	}

	// Theme stylesheet is the first folder
	const themeStylesheet = parts[ 0 ];
	const themeFilePath = parts.slice( 1 ).join( '/' );

	return {
		themeStylesheet,
		relativePath: themeFilePath,
	};
}

/**
 * FilePathLink component.
 *
 * @param {Object} props      - Component props.
 * @param {string} props.path - The file path to render.
 * @return {JSX.Element} The rendered link or viewer.
 */
export default function FilePathLink( { path } ) {
	const [ showViewer, setShowViewer ] = useState( false );
	const [ viewerContent, setViewerContent ] = useState( '' );
	const [ viewerLoading, setViewerLoading ] = useState( false );

	// Get localized data from window.sdAiAgentData
	const data = useMemo( () => window.sdAiAgentData || {}, [] );
	const wpPluginDir = useMemo( () => data.wpPluginDir || '', [ data ] );
	const wpThemeRoot = useMemo( () => data.wpThemeRoot || '', [ data ] );
	const pluginEditorUrl = useMemo(
		() => data.pluginEditorUrl || '',
		[ data ]
	);
	const themeEditorUrl = useMemo( () => data.themeEditorUrl || '', [ data ] );
	const fileModAllowed = useMemo( () => data.fileModAllowed || {}, [ data ] );

	const pluginInfo = getPluginInfo( path, wpPluginDir );
	const themeInfo = getThemeInfo( path, wpThemeRoot );

	// Determine if we should show an editor link or a viewer
	const canEditPlugin = pluginInfo && fileModAllowed.plugin;
	const canEditTheme = themeInfo && fileModAllowed.theme;

	const handleViewerClick = useCallback( async () => {
		if ( showViewer ) {
			setShowViewer( false );
			return;
		}

		setShowViewer( true );
		setViewerLoading( true );

		try {
			// Call the sd-ai-agent/file-read ability via REST
			const response = await fetch( `${ data.restNamespace }/file-read`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.nonce,
				},
				body: JSON.stringify( { path } ),
			} );

			if ( ! response.ok ) {
				setViewerContent(
					__(
						'Error reading file. Check the path and try again.',
						'superdav-ai-agent'
					)
				);
				setViewerLoading( false );
				return;
			}

			const result = await response.json();
			setViewerContent( result.content || '' );
		} catch ( error ) {
			setViewerContent(
				__(
					'Error reading file. Check the path and try again.',
					'superdav-ai-agent'
				)
			);
		} finally {
			setViewerLoading( false );
		}
	}, [ showViewer, data, path ] );

	// Render editor link for plugins
	if ( canEditPlugin ) {
		const editorUrl = new URL( pluginEditorUrl, window.location.origin );
		editorUrl.searchParams.set( 'plugin', pluginInfo.pluginFile );
		editorUrl.searchParams.set( 'file', pluginInfo.pluginFile );

		return (
			<a
				href={ editorUrl.toString() }
				target="_blank"
				rel="noopener noreferrer"
				className="sd-ai-agent-file-path-link sd-ai-agent-file-path-link--editor"
				title={ __( 'Open in plugin editor', 'superdav-ai-agent' ) }
			>
				<code>{ path }</code>
			</a>
		);
	}

	// Render editor link for themes
	if ( canEditTheme ) {
		const editorUrl = new URL( themeEditorUrl, window.location.origin );
		editorUrl.searchParams.set( 'theme', themeInfo.themeStylesheet );
		editorUrl.searchParams.set( 'file', themeInfo.relativePath );

		return (
			<a
				href={ editorUrl.toString() }
				target="_blank"
				rel="noopener noreferrer"
				className="sd-ai-agent-file-path-link sd-ai-agent-file-path-link--editor"
				title={ __( 'Open in theme editor', 'superdav-ai-agent' ) }
			>
				<code>{ path }</code>
			</a>
		);
	}

	// Render viewer toggle for other cases
	return (
		<>
			<button
				type="button"
				className="sd-ai-agent-file-path-link sd-ai-agent-file-path-link--viewer"
				onClick={ handleViewerClick }
				title={ __( 'View file content', 'superdav-ai-agent' ) }
			>
				<code>{ path }</code>
			</button>
			{ showViewer && (
				<div className="sd-ai-agent-file-viewer">
					<div className="sd-ai-agent-file-viewer-header">
						<span className="sd-ai-agent-file-viewer-title">
							{ __(
								'File content (read-only)',
								'superdav-ai-agent'
							) }
						</span>
						<button
							type="button"
							className="sd-ai-agent-file-viewer-close"
							onClick={ () => setShowViewer( false ) }
							aria-label={ __(
								'Close viewer',
								'superdav-ai-agent'
							) }
						>
							×
						</button>
					</div>
					<div className="sd-ai-agent-file-viewer-content">
						{ viewerLoading ? (
							<div className="sd-ai-agent-file-viewer-loading">
								{ __( 'Loading…', 'superdav-ai-agent' ) }
							</div>
						) : (
							<pre>
								<code>{ viewerContent }</code>
							</pre>
						) }
					</div>
					<div className="sd-ai-agent-file-viewer-footer">
						<p>
							{ __(
								'To edit this file, use SFTP or WP-CLI.',
								'superdav-ai-agent'
							) }
						</p>
					</div>
				</div>
			) }
		</>
	);
}
