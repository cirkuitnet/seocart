<?php
/**
 * Fixture: the schema module is the one place that may spell out JSON-Schema arrays
 *
 * Nothing here may be reported by SEOCart.DRY.SchemaLiteral: this file lies under the
 * directory that ruleset.xml allow-lists. The other DRY rules still apply to it.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

$compiled = array(
	'type'                 => 'object',
	'additionalProperties' => false,
	'properties'           => array(
		'status' => array(
			'type' => 'string',
			'enum' => array( 'draft', 'publish' ),
		),
	),
);

update_option( 'seocart_schema_cache', $compiled ); // Expect: SEOCart.DRY.RawOptionWrite.Found
