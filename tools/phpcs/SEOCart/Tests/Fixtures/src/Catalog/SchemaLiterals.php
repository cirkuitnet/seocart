<?php
/**
 * Fixture: JSON-Schema literals in shipped code, outside the schema module
 *
 * A trailing line comment that begins with `Expect:` lists every error code the SEOCart
 * standard must report on that line. A line without one must stay clean.
 * Tests/StandardTest.php compares the two sets exactly.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

// Violations.

$long_syntax = array(
	'type'        => 'string', // Expect: SEOCart.DRY.SchemaLiteral.Type
	'description' => 'A SKU.',
	'enum'        => array( 'draft', 'publish' ), // Expect: SEOCart.DRY.SchemaLiteral.Keyword
);

$short_syntax = [
	"type"                 => "object", // Expect: SEOCart.DRY.SchemaLiteral.Type
	'additionalProperties' => false, // Expect: SEOCart.DRY.SchemaLiteral.Keyword
	'properties'           => [
		'sku'      => [ 'type' => 'string', 'minLength' => 1 ], // Expect: SEOCart.DRY.SchemaLiteral.Type, SEOCart.DRY.SchemaLiteral.Keyword
		'quantity' => [
			'type'    => 'integer', // Expect: SEOCart.DRY.SchemaLiteral.Type
			'minimum' => 0,
		],
		'note'     => [ 'type' => [ 'string', 'null' ] ], // Expect: SEOCart.DRY.SchemaLiteral.Type
		'tags'     => array(
			'type'        => array( 'array', 'null', ), // Expect: SEOCart.DRY.SchemaLiteral.Type
			'uniqueItems' => true, // Expect: SEOCart.DRY.SchemaLiteral.Keyword
			'items'       => array( 'type' => 'string' ), // Expect: SEOCart.DRY.SchemaLiteral.Type
		),
	],
];

register_meta(
	'post',
	'seocart_weight',
	array(
		'type' /* Unit: grams. */ => 'number', // Expect: SEOCart.DRY.SchemaLiteral.Type
		'single'                  => true,
	)
);

$in_a_closure = array(
	'callback' => static function () {
		return array( 'type' => 'boolean' ); // Expect: SEOCart.DRY.SchemaLiteral.Type
	},
	'last'     => array( 'type' => 'null' ) // Expect: SEOCart.DRY.SchemaLiteral.Type
);

$references = array(
	'$schema' => 'http://json-schema.org/draft-04/schema#', // Expect: SEOCart.DRY.SchemaLiteral.Keyword
	'oneOf'   => $variants, // Expect: SEOCart.DRY.SchemaLiteral.Keyword
);

// An array the loop reads from is a literal; only what follows `as` is a destructuring target.
foreach ( [ 'status' => [ 'enum' => $statuses ] ] as $field_schema ) { // Expect: SEOCart.DRY.SchemaLiteral.Keyword
	echo count( $field_schema );
}

// Look-alikes: ordinary WordPress and PHP arrays. None of these may be reported.

wp_admin_notice(
	$message,
	array(
		'type' => 'error',
	)
);

$meta_query = array(
	'key'   => 'seocart_price',
	'type'  => 'NUMERIC',
	'value' => 10,
);

$notice_types = array( 'type' => array( 'error', 'warning' ) );
$mixed_list   = array( 'type' => array( 'string', 'error' ) );
$empty_list   = array( 'type' => array() );
$dynamic      = array( 'type' => $type );
$expression   = array( 'type' => 'string' . $suffix );
$called       = array( 'type' => strtolower( 'STRING' ) );
$constant     = array( 'type' => SEOCART_FIELD_TYPE );
$key_is_tail  = array( $prefix . 'type' => 'string' );
$value_only   = array( 'type', 'string', 'enum' );
$commerce     = array(
	'items'      => array( $line_one, $line_two ),
	'properties' => array( 'colour' => 'red' ),
	'required'   => true,
	'default'    => 'string',
	'format'     => 'Y-m-d',
	'Enum'       => 'keys are case-sensitive',
);

$type = match ( $kind ) {
	'type'  => 'string',
	default => 'object',
};

[ 'type' => $declared, 'enum' => $allowed ] = $schema;

foreach ( $schemas as [ 'enum' => $allowed_values ] ) {
	echo count( $allowed_values );
}

foreach ( $schemas as $name => [ 'enum' => $keyed_values ] ) {
	echo $name, count( $keyed_values );
}

[ 'status' => [ 'enum' => $nested_values ] ] = $schema;

foreach ( $schemas as [ 'status' => [ 'enum' => $deep_values ] ] ) {
	echo count( $deep_values );
}

$read = $schema['enum'];
