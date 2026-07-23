/**
 * White-label branding settings panel (t075).
 *
 * Allows site owners to customise the AI agent's display name, greeting
 * message, brand colours, and logo/avatar URL. All values are stored in
 * the sd_ai_agent_settings WordPress option and applied at runtime in
 * the floating widget via CSS custom properties and React props.
 */

/**
 * WordPress dependencies
 */
import {
	TextControl,
	TextareaControl,
	ColorPicker,
	BaseControl,
	Button,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Live preview of the FAB button, title bar, and greeting using the current branding values.
 *
 * @param {Object} props
 * @param {string} props.agentName       Display name shown in the title bar.
 * @param {string} props.primaryColor    Background colour for the FAB and title bar.
 * @param {string} props.textColor       Text/icon colour for the FAB and title bar.
 * @param {string} props.logoUrl         Optional logo/avatar URL shown in the FAB.
 * @param {string} props.greetingMessage Custom greeting shown in the empty chat state.
 * @return {JSX.Element} Preview element.
 */
function BrandingPreview( {
	agentName,
	primaryColor,
	textColor,
	logoUrl,
	greetingMessage,
} ) {
	const fabBg = primaryColor || 'var(--wp-admin-theme-color, #2271b1)';
	const fabColor = textColor || '#ffffff';
	const displayName = agentName || __( 'SD AI Agent', 'sd-ai-agent' );
	const greeting =
		greetingMessage ||
		__( 'Send a message to start a conversation.', 'sd-ai-agent' );

	return (
		<div className="sdaa-branding-preview">
			<p className="description">
				{ __( 'Live preview', 'sd-ai-agent' ) }
			</p>
			{ /* FAB preview */ }
			<div
				className="sdaa-branding-preview__fab"
				style={ { background: fabBg, color: fabColor } }
				aria-hidden="true"
			>
				{ logoUrl ? (
					<img
						src={ logoUrl }
						alt=""
						className="sdaa-branding-preview__logo"
					/>
				) : (
					<svg
						width="24"
						height="24"
						viewBox="0 0 24 24"
						fill="currentColor"
						aria-hidden="true"
					>
						<path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z" />
					</svg>
				) }
			</div>
			{ /* Title bar preview */ }
			<div
				className="sdaa-branding-preview__titlebar"
				style={ { background: fabBg, color: fabColor } }
				aria-hidden="true"
			>
				{ logoUrl && (
					<img
						src={ logoUrl }
						alt=""
						className="sdaa-branding-preview__titlebar-logo"
					/>
				) }
				<span>{ displayName }</span>
			</div>
			{ /* Greeting preview */ }
			<div className="sdaa-branding-preview__greeting">{ greeting }</div>
		</div>
	);
}

/**
 * Branding settings panel.
 *
 * Receives the current local settings object and an `updateField` callback
 * (same pattern used by the parent SettingsApp).
 *
 * @param {Object}   props
 * @param {Object}   props.local       Current (unsaved) settings state.
 * @param {Function} props.updateField Callback to update a single settings key.
 * @return {JSX.Element} The branding settings panel.
 */
export default function BrandingManager( { local, updateField } ) {
	const [ showPrimaryPicker, setShowPrimaryPicker ] = useState( false );
	const [ showTextPicker, setShowTextPicker ] = useState( false );

	return (
		<div className="sdaa-branding-manager">
			<p className="description">
				{ __(
					'Customise how the AI agent appears to users. Leave fields empty to use the plugin defaults.',
					'sd-ai-agent'
				) }
			</p>

			<TextControl
				label={ __( 'Agent Display Name', 'sd-ai-agent' ) }
				value={ local.agent_name || '' }
				onChange={ ( v ) => updateField( 'agent_name', v ) }
				placeholder={ __( 'SD AI Agent', 'sd-ai-agent' ) }
				help={ __(
					'Name shown in the chat title bar and floating button tooltip. Defaults to "SD AI Agent".',
					'sd-ai-agent'
				) }
				__nextHasNoMarginBottom
			/>

			{ /* Primary / background colour */ }
			<BaseControl
				label={ __( 'Primary Brand Color', 'sd-ai-agent' ) }
				help={ __(
					'Background colour for the FAB button and chat title bar. Leave empty to use the WordPress admin theme colour.',
					'sd-ai-agent'
				) }
				id="sdaa-brand-primary-color"
			>
				<div className="sdaa-color-field">
					<div
						className="sdaa-color-swatch"
						style={ {
							background:
								local.brand_primary_color ||
								'var(--wp-admin-theme-color, #2271b1)',
						} }
						aria-hidden="true"
					/>
					<TextControl
						id="sdaa-brand-primary-color"
						value={ local.brand_primary_color || '' }
						onChange={ ( v ) =>
							updateField( 'brand_primary_color', v )
						}
						placeholder="#2271b1"
						__nextHasNoMarginBottom
					/>
					<Button
						variant="secondary"
						size="small"
						onClick={ () => {
							setShowPrimaryPicker( ( v ) => ! v );
							setShowTextPicker( false );
						} }
					>
						{ showPrimaryPicker
							? __( 'Close', 'sd-ai-agent' )
							: __( 'Pick', 'sd-ai-agent' ) }
					</Button>
				</div>
				{ showPrimaryPicker && (
					<ColorPicker
						color={ local.brand_primary_color || '#2271b1' }
						onChange={ ( v ) =>
							updateField( 'brand_primary_color', v )
						}
						enableAlpha={ false }
					/>
				) }
			</BaseControl>

			{ /* Text / icon colour */ }
			<BaseControl
				label={ __( 'Text & Icon Color', 'sd-ai-agent' ) }
				help={ __(
					'Colour for text and icons inside the FAB button and title bar. Defaults to white (#ffffff).',
					'sd-ai-agent'
				) }
				id="sdaa-brand-text-color"
			>
				<div className="sdaa-color-field">
					<div
						className="sdaa-color-swatch"
						style={ {
							background: local.brand_text_color || '#ffffff',
							border: '1px solid #c3c4c7',
						} }
						aria-hidden="true"
					/>
					<TextControl
						id="sdaa-brand-text-color"
						value={ local.brand_text_color || '' }
						onChange={ ( v ) =>
							updateField( 'brand_text_color', v )
						}
						placeholder="#ffffff"
						__nextHasNoMarginBottom
					/>
					<Button
						variant="secondary"
						size="small"
						onClick={ () => {
							setShowTextPicker( ( v ) => ! v );
							setShowPrimaryPicker( false );
						} }
					>
						{ showTextPicker
							? __( 'Close', 'sd-ai-agent' )
							: __( 'Pick', 'sd-ai-agent' ) }
					</Button>
				</div>
				{ showTextPicker && (
					<ColorPicker
						color={ local.brand_text_color || '#ffffff' }
						onChange={ ( v ) =>
							updateField( 'brand_text_color', v )
						}
						enableAlpha={ false }
					/>
				) }
			</BaseControl>

			{ /* Logo / avatar URL */ }
			<TextControl
				label={ __( 'Logo / Avatar URL', 'sd-ai-agent' ) }
				value={ local.brand_logo_url || '' }
				onChange={ ( v ) => updateField( 'brand_logo_url', v ) }
				placeholder="https://example.com/logo.png"
				help={ __(
					'URL of an image to display inside the FAB button and title bar instead of the default chat icon. Recommended size: 24×24 px.',
					'sd-ai-agent'
				) }
				__nextHasNoMarginBottom
			/>

			{ /* Greeting message */ }
			<TextareaControl
				label={ __( 'Greeting Message', 'sd-ai-agent' ) }
				value={ local.greeting_message || '' }
				onChange={ ( v ) => updateField( 'greeting_message', v ) }
				placeholder={ __(
					'Send a message to start a conversation.',
					'sd-ai-agent'
				) }
				help={ __(
					'Text shown in the chat before the first message. Leave empty to use the default.',
					'sd-ai-agent'
				) }
				rows={ 2 }
			/>

			<BrandingPreview
				agentName={ local.agent_name }
				primaryColor={ local.brand_primary_color }
				textColor={ local.brand_text_color }
				logoUrl={ local.brand_logo_url }
				greetingMessage={ local.greeting_message }
			/>
		</div>
	);
}
