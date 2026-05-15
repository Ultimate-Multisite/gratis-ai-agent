/**
 * WordPress dependencies
 */
import { useEffect, useState, useCallback } from '@wordpress/element';
import {
	Button,
	TextControl,
	TextareaControl,
	SelectControl,
	ToggleControl,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { trash, pencil, plus, seen } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

const TOOL_TYPES = [
	{ label: __( 'HTTP Request', 'sd-ai-agent' ), value: 'http' },
	{ label: __( 'WordPress Action', 'sd-ai-agent' ), value: 'action' },
	{ label: __( 'WP-CLI Command', 'sd-ai-agent' ), value: 'cli' },
];

const HTTP_METHODS = [
	{ label: 'GET', value: 'GET' },
	{ label: 'POST', value: 'POST' },
	{ label: 'PUT', value: 'PUT' },
	{ label: 'PATCH', value: 'PATCH' },
	{ label: 'DELETE', value: 'DELETE' },
];

/**
 *
 */
function emptyForm() {
	return {
		slug: '',
		name: '',
		description: '',
		type: 'http',
		enabled: true,
		config: { method: 'GET', url: '', headers: '{}', body: '' },
		input_schema: '{}',
	};
}

/**
 *
 */
export default function CustomToolsManager() {
	const [ tools, setTools ] = useState( [] );
	const [ loaded, setLoaded ] = useState( false );
	const [ showForm, setShowForm ] = useState( false );
	const [ editId, setEditId ] = useState( null );
	const [ form, setForm ] = useState( emptyForm() );
	const [ testResult, setTestResult ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const fetchTools = useCallback( async () => {
		try {
			const result = await apiFetch( {
				path: '/sd-ai-agent/v1/custom-tools',
			} );
			setTools( result );
		} catch {
			setTools( [] );
		}
		setLoaded( true );
	}, [] );

	useEffect( () => {
		fetchTools();
	}, [ fetchTools ] );

	const resetForm = useCallback( () => {
		setShowForm( false );
		setEditId( null );
		setForm( emptyForm() );
		setTestResult( null );
	}, [] );

	const updateForm = useCallback( ( key, value ) => {
		setForm( ( prev ) => ( { ...prev, [ key ]: value } ) );
	}, [] );

	const updateConfig = useCallback( ( key, value ) => {
		setForm( ( prev ) => ( {
			...prev,
			config: { ...prev.config, [ key ]: value },
		} ) );
	}, [] );

	const handleSubmit = useCallback( async () => {
		if ( ! form.name.trim() ) {
			return;
		}
		setNotice( null );
		try {
			const data = {
				...form,
				config:
					typeof form.config === 'string'
						? JSON.parse( form.config )
						: form.config,
				input_schema:
					typeof form.input_schema === 'string'
						? JSON.parse( form.input_schema )
						: form.input_schema,
			};
			if ( editId ) {
				await apiFetch( {
					path: `/sd-ai-agent/v1/custom-tools/${ editId }`,
					method: 'PATCH',
					data,
				} );
			} else {
				await apiFetch( {
					path: '/sd-ai-agent/v1/custom-tools',
					method: 'POST',
					data,
				} );
			}
			resetForm();
			fetchTools();
			setNotice( {
				status: 'success',
				message: __( 'Tool saved.', 'sd-ai-agent' ),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message:
					err.message || __( 'Failed to save tool.', 'sd-ai-agent' ),
			} );
		}
	}, [ form, editId, resetForm, fetchTools ] );

	const handleEdit = useCallback( ( tool ) => {
		setEditId( tool.id );
		setForm( {
			slug: tool.slug,
			name: tool.name,
			description: tool.description,
			type: tool.type,
			enabled: tool.enabled,
			config: tool.config || {},
			input_schema: JSON.stringify( tool.input_schema || {}, null, 2 ),
		} );
		setShowForm( true );
		setTestResult( null );
	}, [] );

	const handleDelete = useCallback(
		async ( id ) => {
			if (
				// eslint-disable-next-line no-alert
				window.confirm(
					__( 'Delete this custom tool?', 'sd-ai-agent' )
				)
			) {
				await apiFetch( {
					path: `/sd-ai-agent/v1/custom-tools/${ id }`,
					method: 'DELETE',
				} );
				fetchTools();
			}
		},
		[ fetchTools ]
	);

	const handleToggle = useCallback(
		async ( tool ) => {
			await apiFetch( {
				path: `/sd-ai-agent/v1/custom-tools/${ tool.id }`,
				method: 'PATCH',
				data: { enabled: ! tool.enabled },
			} );
			fetchTools();
		},
		[ fetchTools ]
	);

	const handleTest = useCallback( async () => {
		if ( ! editId ) {
			return;
		}
		setTestResult( null );
		try {
			const result = await apiFetch( {
				path: `/sd-ai-agent/v1/custom-tools/${ editId }/test`,
				method: 'POST',
				data: { args: {} },
			} );
			setTestResult( result );
		} catch ( err ) {
			setTestResult( { success: false, output: err.message } );
		}
	}, [ editId ] );

	const renderConfigFields = () => {
		const cfg = form.config || {};

		switch ( form.type ) {
			case 'http':
				return (
					<>
						<SelectControl
							label={ __( 'HTTP Method', 'sd-ai-agent' ) }
							value={ cfg.method || 'GET' }
							options={ HTTP_METHODS }
							onChange={ ( v ) => updateConfig( 'method', v ) }
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __( 'URL', 'sd-ai-agent' ) }
							value={ cfg.url || '' }
							onChange={ ( v ) => updateConfig( 'url', v ) }
							help={ __(
								'Use {{param}} placeholders for dynamic values.',
								'sd-ai-agent'
							) }
							__nextHasNoMarginBottom
						/>
						<TextareaControl
							label={ __( 'Headers (JSON)', 'sd-ai-agent' ) }
							value={
								typeof cfg.headers === 'object'
									? JSON.stringify( cfg.headers, null, 2 )
									: cfg.headers || '{}'
							}
							onChange={ ( v ) => updateConfig( 'headers', v ) }
							rows={ 3 }
						/>
						<TextareaControl
							label={ __( 'Body Template', 'sd-ai-agent' ) }
							value={ cfg.body || '' }
							onChange={ ( v ) => updateConfig( 'body', v ) }
							rows={ 3 }
							help={ __(
								'Use {{param}} placeholders. Leave empty for GET requests.',
								'sd-ai-agent'
							) }
						/>
					</>
				);

			case 'action':
				return (
					<>
						<TextControl
							label={ __( 'Hook Name', 'sd-ai-agent' ) }
							value={ cfg.hook_name || '' }
							onChange={ ( v ) => updateConfig( 'hook_name', v ) }
							placeholder="my_custom_action"
							help={ __(
								'The WordPress action hook to call via do_action().',
								'sd-ai-agent'
							) }
							__nextHasNoMarginBottom
						/>
					</>
				);

			case 'cli':
				return (
					<>
						<TextControl
							label={ __( 'WP-CLI Command', 'sd-ai-agent' ) }
							value={ cfg.command || '' }
							onChange={ ( v ) => updateConfig( 'command', v ) }
							placeholder="cache flush"
							help={ __(
								'Command to run (without the "wp" prefix). Use {{param}} placeholders.',
								'sd-ai-agent'
							) }
							__nextHasNoMarginBottom
						/>
					</>
				);

			default:
				return null;
		}
	};

	return (
		<div className="sdaa-custom-tools-manager">
			<div className="sdaa-skill-header">
				<div>
					<h3>{ __( 'Custom Tools', 'sd-ai-agent' ) }</h3>
					<p className="description">
						{ __(
							'Create custom tools that the AI can use — HTTP APIs, WordPress actions, or WP-CLI commands.',
							'sd-ai-agent'
						) }
					</p>
				</div>
				{ ! showForm && (
					<Button
						variant="secondary"
						icon={ plus }
						onClick={ () => setShowForm( true ) }
						size="compact"
					>
						{ __( 'Add Tool', 'sd-ai-agent' ) }
					</Button>
				) }
			</div>

			{ notice && (
				<Notice
					status={ notice.status }
					isDismissible
					onDismiss={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			{ showForm && (
				<div className="sdaa-skill-form">
					{ ! editId && (
						<TextControl
							label={ __( 'Slug', 'sd-ai-agent' ) }
							value={ form.slug }
							onChange={ ( v ) => updateForm( 'slug', v ) }
							help={ __(
								'Unique identifier (lowercase, hyphens).',
								'sd-ai-agent'
							) }
							__nextHasNoMarginBottom
						/>
					) }
					<TextControl
						label={ __( 'Name', 'sd-ai-agent' ) }
						value={ form.name }
						onChange={ ( v ) => updateForm( 'name', v ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Description', 'sd-ai-agent' ) }
						value={ form.description }
						onChange={ ( v ) => updateForm( 'description', v ) }
						help={ __(
							'Explains to the AI when this tool should be used.',
							'sd-ai-agent'
						) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Type', 'sd-ai-agent' ) }
						value={ form.type }
						options={ TOOL_TYPES }
						onChange={ ( v ) => {
							updateForm( 'type', v );
							// Reset config for new type.
							if ( v === 'http' ) {
								updateForm( 'config', {
									method: 'GET',
									url: '',
									headers: '{}',
									body: '',
								} );
							} else if ( v === 'action' ) {
								updateForm( 'config', { hook_name: '' } );
							} else {
								updateForm( 'config', { command: '' } );
							}
						} }
						__nextHasNoMarginBottom
					/>

					{ renderConfigFields() }

					<TextareaControl
						label={ __( 'Input Schema (JSON)', 'sd-ai-agent' ) }
						value={ form.input_schema }
						onChange={ ( v ) => updateForm( 'input_schema', v ) }
						rows={ 4 }
						help={ __(
							'JSON Schema describing the parameters the AI should provide.',
							'sd-ai-agent'
						) }
					/>

					<div className="sdaa-skill-form-actions">
						<Button
							variant="primary"
							onClick={ handleSubmit }
							disabled={
								! form.name.trim() ||
								( ! editId && ! form.slug.trim() )
							}
							size="compact"
						>
							{ editId
								? __( 'Update', 'sd-ai-agent' )
								: __( 'Create', 'sd-ai-agent' ) }
						</Button>
						{ editId && (
							<Button
								variant="secondary"
								icon={ seen }
								onClick={ handleTest }
								size="compact"
							>
								{ __( 'Test', 'sd-ai-agent' ) }
							</Button>
						) }
						<Button
							variant="tertiary"
							onClick={ resetForm }
							size="compact"
						>
							{ __( 'Cancel', 'sd-ai-agent' ) }
						</Button>
					</div>

					{ testResult && (
						<div
							className={ `sdaa-test-result ${
								testResult.success ? 'is-success' : 'is-error'
							}` }
						>
							<strong>
								{ testResult.success
									? __( 'Success', 'sd-ai-agent' )
									: __( 'Error', 'sd-ai-agent' ) }
							</strong>
							<pre>
								{ typeof testResult.output === 'object'
									? JSON.stringify(
											testResult.output,
											null,
											2
									  )
									: testResult.output }
							</pre>
						</div>
					) }
				</div>
			) }

			{ ! loaded && (
				<p className="description">
					{ __( 'Loading…', 'sd-ai-agent' ) }
				</p>
			) }

			{ loaded && tools.length === 0 && ! showForm && (
				<p className="description">
					{ __(
						'No custom tools yet. Create one or deactivate/reactivate the plugin to seed examples.',
						'sd-ai-agent'
					) }
				</p>
			) }

			{ tools.length > 0 && (
				<div className="sdaa-skill-cards">
					{ tools.map( ( tool ) => (
						<div
							key={ tool.id }
							className={ `sdaa-skill-card ${
								! tool.enabled
									? 'sdaa-skill-card--disabled'
									: ''
							}` }
						>
							<div className="sdaa-skill-card-header">
								<ToggleControl
									checked={ tool.enabled }
									onChange={ () => handleToggle( tool ) }
									__nextHasNoMarginBottom
								/>
								<div className="sdaa-skill-card-title">
									<strong>{ tool.name }</strong>
									<span className="sdaa-skill-badge">
										{ tool.type.toUpperCase() }
									</span>
								</div>
							</div>
							<p className="sdaa-skill-card-description">
								{ tool.description }
							</p>
							<div className="sdaa-skill-card-footer">
								<span className="sdaa-skill-word-count">
									{ tool.slug }
								</span>
								<div className="sdaa-skill-card-actions">
									<Button
										icon={ pencil }
										size="small"
										label={ __( 'Edit', 'sd-ai-agent' ) }
										onClick={ () => handleEdit( tool ) }
									/>
									<Button
										icon={ trash }
										size="small"
										label={ __( 'Delete', 'sd-ai-agent' ) }
										isDestructive
										onClick={ () =>
											handleDelete( tool.id )
										}
									/>
								</div>
							</div>
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}
