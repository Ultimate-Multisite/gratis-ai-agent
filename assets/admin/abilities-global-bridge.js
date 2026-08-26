/**
 * Expose the WordPress Abilities script-module API to classic plugin bundles.
 *
 * Script modules export functions without creating a `wp.abilities` global.
 * The SD AI Agent bundles are classic scripts, so this small module bridges
 * the core module namespace onto the existing WordPress global.
 */
// eslint-disable-next-line import/no-unresolved -- Resolved by the WordPress 7.0 import map.
import * as abilities from '@wordpress/abilities';

globalThis.wp = globalThis.wp || {};
globalThis.wp.abilities = abilities;
