#!/usr/bin/env node
/**
 * Fails when a built file is larger than a gzipped byte budget.
 *
 * Usage: node bin/check-script-size.js <file> <max gzipped bytes>
 * (npm run check:size: the filters view module, at most 10 KB gzipped; core's interactivity and router
 * modules are external and not counted.)
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
