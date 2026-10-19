/**
 * The builder config from PHP (window.pegworkFormBuilder), parsed once.
 */

/**
 * Internal dependencies
 */
import { parseConfig } from './logic';

const config = parseConfig( window.pegworkFormBuilder );

export default config;
