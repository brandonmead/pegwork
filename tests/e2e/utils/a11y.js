const { expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

/**
 * Runs axe on the current page and fails on serious or critical violations.
 * Moderate and minor ones are ignored.
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
	const serious = violations.filter(
		( v ) => v.impact === 'serious' || v.impact === 'critical'
	);
	const summary = serious.map(
		( v ) =>
			`${ v.impact } ${ v.id }: ${ v.help } (${ v.nodes.length } nodes, first: ${ v.nodes[ 0 ]?.target })`
	);
	expect( summary, 'serious or critical axe violations' ).toEqual( [] );
}

module.exports = { expectNoSeriousA11yViolations };
