/**
 * WordPress dependencies
 */
import { Button, SelectControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import STORE_NAME from '../store';

/**
 * Provider and model selector dropdowns.
 *
 * Changing the provider auto-selects the first available model for that
 * provider. Both selections are persisted to localStorage via the store.
 *
 * @param {Object}  props                 - Component props.
 * @param {boolean} [props.compact=false] - When true, hides labels and uses
 *                                        compact control sizing.
 * @return {JSX.Element} The provider/model selector element.
 */
export default function ProviderSelector( { compact = false } ) {
	const { providers, selectedProviderId, selectedModelId, models, loading } =
		useSelect( ( select ) => {
			const store = select( STORE_NAME );
			return {
				providers: store.getProviders(),
				selectedProviderId: store.getSelectedProviderId(),
				selectedModelId: store.getSelectedModelId(),
				models: store.getSelectedProviderModels(),
				loading: store.getProvidersLoading(),
			};
		}, [] );

	const { setSelectedProvider, setSelectedModel, fetchProviders } =
		useDispatch( STORE_NAME );

	const onRefresh = () => {
		fetchProviders();
	};

	if ( ! providers.length ) {
		return (
			<div className="sdaa-provider-selector">
				<p>
					<a
						href={
							window.sdAiAgentData?.connectorsUrl ||
							'options-general.php?page=options-connectors-wp-admin'
						}
					>
						{ __(
							'Connect Superdav AI or choose another provider',
							'superdav-ai-agent'
						) }
					</a>
				</p>
			</div>
		);
	}

	const providerOptions = providers.map( ( p ) => ( {
		label: p.name,
		value: p.id,
	} ) );

	const modelOptions = models.length
		? models.map( ( m ) => ( {
				label: m.name || m.id,
				value: m.id,
		  } ) )
		: [ { label: __( '(default)', 'superdav-ai-agent' ), value: '' } ];

	const onProviderChange = ( value ) => {
		setSelectedProvider( value );
		const provider = providers.find( ( p ) => p.id === value );
		if ( provider?.models?.length ) {
			setSelectedModel( provider.models[ 0 ].id );
		} else {
			setSelectedModel( '' );
		}
	};

	return (
		<div
			className={ `sdaa-provider-selector ${
				compact ? 'is-compact' : ''
			}` }
		>
			<div className="sdaa-provider-selector__row">
				<SelectControl
					label={
						compact ? null : __( 'Provider', 'superdav-ai-agent' )
					}
					value={ selectedProviderId }
					options={ providerOptions }
					onChange={ onProviderChange }
					__nextHasNoMarginBottom
					size={ compact ? 'compact' : 'default' }
				/>
				<Button
					variant="tertiary"
					onClick={ onRefresh }
					disabled={ loading }
					className="sdaa-provider-selector__refresh"
					icon="update"
					label={ __( 'Refresh providers', 'superdav-ai-agent' ) }
					showTooltip
				/>
			</div>
			<SelectControl
				label={ compact ? null : __( 'Model', 'superdav-ai-agent' ) }
				value={ selectedModelId }
				options={ modelOptions }
				onChange={ setSelectedModel }
				__nextHasNoMarginBottom
				size={ compact ? 'compact' : 'default' }
			/>
		</div>
	);
}
