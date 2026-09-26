<?php
/**
 * ClientIdentityCases: the requests the client-identity test and its probe both compute identities for
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

/**
 * Owns one fact: which requests the test and its probe process compare, so both compute the same.
 *
 * @since 0.1.0
 */
final class ClientIdentityCases {

	/**
	 * The server variables of each request, by name: a proxy with no header, the same proxy
	 * forwarding a client, and that client connecting directly.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array<string, string>>
	 */
	public const CASES = array(
		'remote only'   => array( 'REMOTE_ADDR' => '203.0.113.9' ),
		'forwarded'     => array(
			'REMOTE_ADDR'          => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.4',
		),
		'client direct' => array( 'REMOTE_ADDR' => '198.51.100.4' ),
	);
}
