const { expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

/**
 * Core WordPress nodes that fail a rule we cannot fix, matched by rule id, frame and markup so
 * the rule still runs on everything else. Each entry names the core component and the axe version
 * that started reporting it.
 *
 * axe-core 4.14 enables label-content-name-mismatch (WCAG 2.5.3) by default. These core
 * controls set an aria-label that does not contain their visible text:
 * - block-editor DefaultBlockAppender, in the editor canvas iframe:
 *   <p role="button" aria-label="Add default block">Type / to choose a block</p>
 * - editor PostTemplatePanel (block themes), in the Document sidebar: the DropdownMenu toggle
 *   labelled "Template options" whose text is the template name, such as "Single Posts".
 * - components Snackbar: <div role="button" aria-label="Dismiss this notice"> around the
 *   notice text (our text, such as "Form saved.", in core's markup).
 * - admin menu collapse button (wp-admin/menu-header.php):
 *   <button id="collapse-button" aria-label="Collapse Main menu">...Collapse Menu</button>
 */
const CORE_KNOWN_FAILURES = [
	{
		rule: 'label-content-name-mismatch',
		inCanvas: true,
		html: /^<p [^>]*class="block-editor-default-block-appender__content"/,
	},
	{
		rule: 'label-content-name-mismatch',
		inCanvas: false,
		html: /^<button [^>]*class="[^"]*components-dropdown-menu__toggle[^"]*"[^>]*aria-label="Template options"/,
	},
	{
		rule: 'label-content-name-mismatch',
		inCanvas: false,
		html: /^<div class="components-snackbar"[^>]*aria-label="Dismiss this notice"/,
	},
	{
		rule: 'label-content-name-mismatch',
		inCanvas: false,
		html: /^<button [^>]*id="collapse-button"/,
	},
];

/**
 * Whether an axe node is one of the core known failures for that rule.
 *
 * @param {string} rule Axe rule id.
 * @param {Object} node Axe result node (target and html).
 * @return {boolean} True to ignore the node.
 */
function isCoreKnownFailure( rule, node ) {
	const inCanvas =
		node.target.length === 2 &&
		String( node.target[ 0 ] ).startsWith( 'iframe' );
	return CORE_KNOWN_FAILURES.some(
		( known ) =>
			known.rule === rule &&
			known.inCanvas === inCanvas &&
			known.html.test( node.html )
	);
}

/**
 * Runs axe on the current page and fails on serious or critical violations.
 * Moderate and minor ones are ignored, and so are the core known failures above.
 *
 * @param {import('@playwright/test').Page}               page
 * @param {{exclude?: string[], disableRules?: string[]}} options
 */
async function expectNoSeriousA11yViolations( page, options = {} ) {
	let builder = new AxeBuilder( { page } ).withTags( [
		'wcag2a',
		'wcag2aa',
		'wcag21a',
		'wcag21aa',
	] );
	for ( const selector of options.exclude || [] ) {
		builder = builder.exclude( selector );
	}
	if ( options.disableRules ) {
		builder = builder.disableRules( options.disableRules );
	}
	const { violations } = await builder.analyze();
	const serious = violations
		.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' )
		.map( ( v ) => ( {
			...v,
			nodes: v.nodes.filter( ( n ) => ! isCoreKnownFailure( v.id, n ) ),
		} ) )
		.filter( ( v ) => v.nodes.length );
	const summary = serious.map(
		( v ) =>
			`${ v.impact } ${ v.id }: ${ v.help } (${ v.nodes.length } nodes, first: ${ v.nodes[ 0 ]?.target } ${ v.nodes[ 0 ]?.html })`
	);
	expect( summary, 'serious or critical axe violations' ).toEqual( [] );
}

module.exports = { expectNoSeriousA11yViolations };
