/**
 * Reads the tables of the plugin's published pages under docs/, so a spec checks the page it
 * renders against what the documentation promises, never against a list of its own.
 *
 * A table's rows are the lines that start with a code span (`| \`…\``); its header and divider are
 * left out. Each page's own Jest test holds the page equal to the code that declares it.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/** The repository's documentation folder. */
const DOCS = resolve( __dirname, '..', '..', '..', 'docs' );

/**
 * The rows of the table under a heading of a page, each a list of its cells.
 *
 * @param page    The page's file name under docs/, such as `styling.md`.
 * @param heading The heading, without its hashes.
 * @return The rows; none when the page has no such heading.
 */
export function tableRows( page: string, heading: string ): string[][] {
	const section = readFileSync( resolve( DOCS, page ), 'utf8' )
		.split( /^## /m )
		.find( ( part ) => part.startsWith( `${ heading }\n` ) );

	return ( section ?? '' )
		.split( '\n' )
		.filter( ( line ) => line.startsWith( '| `' ) )
		.map( ( line ) =>
			line
				.split( '|' )
				.slice( 1, -1 )
				.map( ( cell ) => cell.trim() )
		);
}

/**
 * The code spans of a cell, in order.
 *
 * @param cell The cell.
 * @return What each code span holds.
 */
export function codeSpans( cell: string ): string[] {
	return [ ...cell.matchAll( /`([^`]+)`/g ) ].map( ( [ , code ] ) => code );
}
