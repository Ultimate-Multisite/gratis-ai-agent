/**
 * WordPress dependencies
 */
import { useEffect, useState, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { Button, TextareaControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { trash, pencil, plus } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import STORE_NAME from '../store';

const CATEGORIES = [
	{ label: __( 'General', 'sd-ai-agent' ), value: 'general' },
	{ label: __( 'Site Info', 'sd-ai-agent' ), value: 'site_info' },
	{ label: __( 'Site Brief', 'sd-ai-agent' ), value: 'site_brief' },
	{
		label: __( 'User Preferences', 'sd-ai-agent' ),
		value: 'user_preferences',
	},
	{
		label: __( 'Technical Notes', 'sd-ai-agent' ),
		value: 'technical_notes',
	},
	{ label: __( 'Workflows', 'sd-ai-agent' ), value: 'workflows' },
];

/**
 *
 */
export default function MemoryManager() {
	const { fetchMemories, createMemory, updateMemory, deleteMemory } =
		useDispatch( STORE_NAME );
	const { memories, memoriesLoaded } = useSelect(
		( select ) => ( {
			memories: select( STORE_NAME ).getMemories(),
			memoriesLoaded: select( STORE_NAME ).getMemoriesLoaded(),
		} ),
		[]
	);

	const [ showForm, setShowForm ] = useState( false );
	const [ editId, setEditId ] = useState( null );
	const [ formCategory, setFormCategory ] = useState( 'general' );
	const [ formContent, setFormContent ] = useState( '' );

	useEffect( () => {
		fetchMemories();
	}, [ fetchMemories ] );

	const handleSubmit = useCallback( async () => {
		if ( ! formContent.trim() ) {
			return;
		}

		if ( editId ) {
			await updateMemory( editId, {
				category: formCategory,
				content: formContent,
			} );
		} else {
			await createMemory( formCategory, formContent );
		}

		setShowForm( false );
		setEditId( null );
		setFormCategory( 'general' );
		setFormContent( '' );
	}, [ editId, formCategory, formContent, createMemory, updateMemory ] );

	const handleEdit = useCallback( ( memory ) => {
		setEditId( memory.id );
		setFormCategory( memory.category );
		setFormContent( memory.content );
		setShowForm( true );
	}, [] );

	const handleDelete = useCallback(
		async ( id ) => {
			if (
				// eslint-disable-next-line no-alert
				window.confirm( __( 'Delete this memory?', 'sd-ai-agent' ) )
			) {
				await deleteMemory( id );
			}
		},
		[ deleteMemory ]
	);

	const handleCancel = useCallback( () => {
		setShowForm( false );
		setEditId( null );
		setFormCategory( 'general' );
		setFormContent( '' );
	}, [] );

	return (
		<div className="sdaa-memory-manager">
			<div className="sdaa-memory-header">
				<h3>{ __( 'Stored Memories', 'sd-ai-agent' ) }</h3>
				{ ! showForm && (
					<Button
						variant="secondary"
						icon={ plus }
						onClick={ () => setShowForm( true ) }
						size="compact"
					>
						{ __( 'Add Memory', 'sd-ai-agent' ) }
					</Button>
				) }
			</div>

			{ showForm && (
				<div className="sdaa-memory-form">
					<SelectControl
						label={ __( 'Category', 'sd-ai-agent' ) }
						value={ formCategory }
						options={ CATEGORIES }
						onChange={ setFormCategory }
						__nextHasNoMarginBottom
					/>
					<TextareaControl
						label={ __( 'Content', 'sd-ai-agent' ) }
						value={ formContent }
						onChange={ setFormContent }
						rows={ 3 }
					/>
					<div className="sdaa-memory-form-actions">
						<Button
							variant="primary"
							onClick={ handleSubmit }
							disabled={ ! formContent.trim() }
							size="compact"
						>
							{ editId
								? __( 'Update', 'sd-ai-agent' )
								: __( 'Save', 'sd-ai-agent' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ handleCancel }
							size="compact"
						>
							{ __( 'Cancel', 'sd-ai-agent' ) }
						</Button>
					</div>
				</div>
			) }

			{ ! memoriesLoaded && (
				<p className="description">
					{ __( 'Loading…', 'sd-ai-agent' ) }
				</p>
			) }

			{ memoriesLoaded && memories.length === 0 && (
				<p className="description">
					{ __(
						'No memories stored yet. The AI will save memories as you interact, or you can add them manually.',
						'sd-ai-agent'
					) }
				</p>
			) }

			{ memories.length > 0 && (
				<table className="sdaa-memory-table widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Category', 'sd-ai-agent' ) }</th>
							<th>{ __( 'Content', 'sd-ai-agent' ) }</th>
							<th>{ __( 'Actions', 'sd-ai-agent' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ memories.map( ( memory ) => (
							<tr key={ memory.id }>
								<td>
									<span className="sdaa-memory-category">
										{ memory.category.replace( /_/g, ' ' ) }
									</span>
								</td>
								<td>{ memory.content }</td>
								<td>
									<div className="sdaa-memory-actions">
										<Button
											icon={ pencil }
											size="small"
											label={ __(
												'Edit',
												'sd-ai-agent'
											) }
											onClick={ () =>
												handleEdit( memory )
											}
										/>
										<Button
											icon={ trash }
											size="small"
											label={ __(
												'Delete',
												'sd-ai-agent'
											) }
											isDestructive
											onClick={ () =>
												handleDelete( memory.id )
											}
										/>
									</div>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
