/**
 * The storefront's stylesheet keeps its interface: the variables are the one place a theme's
 * values and fixed colours enter, every rule below them selects the storefront's classes and reads
 * its variables only, and the published list in docs/styling.md and the stylesheet name the same
 * variables and the same classes.
 *
 * Read from the source, assets/blocks/storefront.css: the build only adds vendor prefixes and
 * minifies, which changes no colour, variable or class name. The browser spec checks the built
 * file's variables against the source's.
 *
 * Planted violations, each shown red and removed: `color: #111` in a component rule, and
 * `var(--wp--preset--color--contrast)` in one (the rules' test); a class printed by a component
 * and missing from docs/styling.md, and a listed variable removed from the stylesheet (the lists'
 * tests).
 */

const { readFileSync } = require( 'node:fs' );
const { resolve } = require( 'node:path' );

const ROOT = resolve( __dirname, '..', '..', '..' );

/** The stylesheet, without its comments. */
const SOURCE = readFileSync(
	resolve( ROOT, 'assets', 'blocks', 'storefront.css' ),
	'utf8'
).replace( /\/\*[\s\S]*?\*\//g, '' );

/** The published list. */
const PAGE = readFileSync( resolve( ROOT, 'docs', 'styling.md' ), 'utf8' );

/** The variable block: the one rule on `:where(:root)`. */
const VARIABLES = /:where\(\s*:root\s*\)\s*\{([^}]*)\}/.exec( SOURCE );

/** Everything after the variable block: the component rules. */
const COMPONENTS = SOURCE.slice( VARIABLES.index + VARIABLES[ 0 ].length );

/** The CSS colour keywords that name a colour; `transparent` and `currentcolor` name none. */
const NAMED_COLOURS =
	'aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue blueviolet brown burlywood cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue darkcyan darkgoldenrod darkgray darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon darkseagreen darkslateblue darkslategray darkslategrey darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue firebrick floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon lightblue lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen lightgrey lightpink lightsalmon lightseagreen lightskyblue lightslategray lightslategrey lightsteelblue lightyellow lime limegreen linen magenta maroon mediumaquamarine mediumblue mediumorchid mediumpurple mediumseagreen mediumslateblue mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream mistyrose moccasin navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple rebeccapurple red rosybrown royalblue saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue slategray slategrey snow springgreen steelblue tan teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen canvas canvastext'.split(
		' '
	);

/** The generic font families and the system font keyword, which name a font. */
const FONT_NAMES =
	/\b(?:serif|sans-serif|monospace|cursive|fantasy|system-ui|ui-serif|ui-sans-serif|ui-monospace)\b|["']/;

/**
 * Returns the declarations of a part of the stylesheet, as `[ property, value ]` pairs.
 *
 * @param {string} css The part.
 * @return {Array<Array<string>>} The declarations.
 */
function declarations( css ) {
	return [ ...css.matchAll( /([a-z-]+)\s*:\s*([^;{}]+);/g ) ].map(
		( match ) => [ match[ 1 ], match[ 2 ].trim() ]
	);
}

/**
 * Returns the names a column of one of the page's tables lists, in backticks.
 *
 * @param {string} heading The table's section heading.
 * @return {Set<string>} The names.
 */
function listed( heading ) {
	const section = PAGE.split( /^## /m ).find( ( part ) =>
		part.startsWith( heading )
	);

	return new Set(
		[ ...section.matchAll( /^\|\s*`([^`]+)`/gm ) ].map(
			( match ) => match[ 1 ]
		)
	);
}

test( 'the variable block is found, and declares each variable once', () => {
	expect( VARIABLES ).not.toBeNull();

	const names = declarations( VARIABLES[ 1 ].replace( /\s+/g, ' ' ) )
		.map( ( [ name ] ) => name )
		.filter( ( name ) => name.startsWith( '--seocart-' ) );

	expect( names.length ).toBeGreaterThan( 0 );
	expect( names ).toEqual( [ ...new Set( names ) ] );
} );

test( 'below the variables, no rule writes a theme value, a colour or a font', () => {
	const found = [];

	for ( const [ property, value ] of declarations( COMPONENTS ) ) {
		if ( value.includes( '--wp--' ) ) {
			found.push( `${ property }: ${ value } names a theme value` );
		}

		if (
			/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\(/i.test(
				value
			) ||
			value
				.toLowerCase()
				.split( /[^a-z]+/ )
				.some( ( word ) => NAMED_COLOURS.includes( word ) )
		) {
			found.push( `${ property }: ${ value } writes a colour` );
		}

		if ( property.startsWith( 'font' ) && FONT_NAMES.test( value ) ) {
			found.push( `${ property }: ${ value } names a font` );
		}
	}

	expect( found ).toEqual( [] );
} );

test( 'every rule below the variables selects the storefront classes only', () => {
	// An at-rule's condition, such as a container query's, is not a selector.
	const rules = COMPONENTS.replace( /@[a-z-]+[^{]*\{/g, '{' );
	const selectors = [ ...rules.matchAll( /([^{}]+)\{/g ) ]
		.map( ( match ) => match[ 1 ].trim() )
		.filter( ( selector ) => '' !== selector )
		.flatMap( ( selector ) => selector.split( ',' ) )
		.map( ( selector ) => selector.trim() );

	expect( selectors.length ).toBeGreaterThan( 0 );
	expect(
		selectors.filter( ( selector ) => ! /^\.seocart-/.test( selector ) )
	).toEqual( [] );
} );

test( 'every variable read is declared', () => {
	const declared = new Set(
		declarations( VARIABLES[ 1 ] ).map( ( [ name ] ) => name )
	);
	const read = [ ...SOURCE.matchAll( /var\(\s*(--seocart-[a-z-]+)/g ) ].map(
		( match ) => match[ 1 ]
	);

	expect( read.length ).toBeGreaterThan( 0 );
	expect( read.filter( ( name ) => ! declared.has( name ) ) ).toEqual( [] );
} );

test( 'the page and the stylesheet name the same variables', () => {
	const declared = new Set(
		declarations( VARIABLES[ 1 ] )
			.map( ( [ name ] ) => name )
			.filter( ( name ) => name.startsWith( '--seocart-' ) )
	);
	const page = listed( 'Variables' );

	expect( [ ...page ].filter( ( name ) => ! declared.has( name ) ) ).toEqual(
		[]
	);
	expect( [ ...declared ].filter( ( name ) => ! page.has( name ) ) ).toEqual(
		[]
	);
} );

test( 'the page and the stylesheet name the same classes', () => {
	const styled = new Set(
		[ ...COMPONENTS.matchAll( /\.((?:seocart|is)-[a-z0-9_-]+)/g ) ].map(
			( match ) => match[ 1 ]
		)
	);
	const page = listed( 'Classes' );

	expect( [ ...page ].filter( ( name ) => ! styled.has( name ) ) ).toEqual(
		[]
	);
	expect( [ ...styled ].filter( ( name ) => ! page.has( name ) ) ).toEqual(
		[]
	);
} );
