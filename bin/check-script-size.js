#!/usr/bin/env node
/**
 * Fails when a built file is larger than a gzipped byte budget.
 *
 * Usage: node bin/check-script-size.js <file> <max gzipped bytes>
 * (npm run check:size: the filters view module, at most 10 KB gzipped, the automation builder
 * script, at most 26.5 KB gzipped, and the record editor's automation actions script, at most 3 KB
 * gzipped; WordPress packages are external and not counted.)
 * The builder budget was 25 KB until Automation v3 PR 7 (loops and lists): the loop block, its
 * Move to and inserter rules, list insertion and Test run item headings took it to 26,994 bytes,
 * so it was raised by the minimum, to 27,136 (26.5 KB). Lazy loading was ruled out: a split chunk
 * needs its own script translations and a runtime public path.
 */
const { readFileSync } = require( 'fs' );
const { gzipSync } = require( 'zlib' );

const [ file, max ] = process.argv.slice( 2 );
if ( ! file || ! max ) {
	process.stderr.write(
		'Usage: check-script-size.js <file> <max gzipped bytes>\n'
	);
	process.exit( 2 );
}

const raw = readFileSync( file );
const size = gzipSync( raw, { level: 9 } ).length;
const line = `${ file }: ${ raw.length } bytes, ${ size } bytes gzipped (budget ${ max })\n`;
if ( size > Number( max ) ) {
	process.stderr.write( `Over budget. ${ line }` );
	process.exit( 1 );
}
process.stdout.write( line );
