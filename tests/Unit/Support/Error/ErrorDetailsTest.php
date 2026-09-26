<?php
/**
 * Tests the structured details an error carries beyond its message
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\Error;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorDefinition;
use SEOCart\Support\Error\ErrorTableException;
use SEOCart\Tests\Unit\Support\Error\Fixtures\DetailedError;
use SEOCart\Tests\Unit\Support\Error\Fixtures\FixtureError;

/**
 * An error carries structured details under the detail keys its row declares, beside the
 * placeholders' values and never in the message; an undeclared key, or a value that is not JSON
 * data, is a programming error; and an internal row declares none.
 *
 * @since 0.1.0
 */
final class ErrorDetailsTest extends TestCase {

	/**
	 * Tests that declared details are carried as they were given, and are no part of the context or the message.
	 *
	 * @since 0.1.0
	 */
	public function test_declared_details_are_carried_beside_the_context(): void {
		$state = array(
			'lines' => array(
				array(
					'key'   => 'a',
					'gross' => 1200,
				),
			),
			'note'  => null,
			'open'  => true,
		);
		$error = CodedException::because( DetailedError::Stale, array( 'current' => 3 ), null, array( 'state' => $state ) );

		$this->assertSame( array( 'current' => 3 ), $error->context() );
		$this->assertSame( array( 'state' => $state ), $error->details() );
		$this->assertSame( array( 'state' ), ErrorDefinition::of( DetailedError::Stale )->detailKeys() );
		$this->assertSame( 'It is at version 3 now.', ErrorDefinition::of( DetailedError::Stale )->render( $error->context() ) );
		$this->assertSame( array(), CodedException::because( DetailedError::Stale, array( 'current' => 3 ) )->details(), 'Details are optional.' );
	}

	/**
	 * Tests that raise() carries the details too.
	 *
	 * @since 0.1.0
	 */
	public function test_raise_carries_the_details(): void {
		try {
			CodedException::raise( DetailedError::Stale, array( 'current' => 4 ), array( 'state' => array( 'x' => 1 ) ) );
		} catch ( CodedException $raised ) {
			$this->assertSame( array( 'state' => array( 'x' => 1 ) ), $raised->details() );
		}
	}

	/**
	 * Tests that a detail under a key the row does not declare is refused, as a programming error.
	 *
	 * @since 0.1.0
	 */
	public function test_an_undeclared_detail_key_is_refused(): void {
		$refused = array(
			'a key the row does not declare' => static fn() => CodedException::because( DetailedError::Stale, array( 'current' => 1 ), null, array( 'totals' => array() ) ),
			'details on a row with none'     => static fn() => CodedException::because( FixtureError::NotFound, array(), null, array( 'state' => 1 ) ),
			'an object as a detail'          => static fn() => CodedException::because( DetailedError::Stale, array( 'current' => 1 ), null, array( 'state' => new \stdClass() ) ),
			'a float in a detail'            => static fn() => CodedException::because( DetailedError::Stale, array( 'current' => 1 ), null, array( 'state' => array( 1.5 ) ) ),
		);

		foreach ( $refused as $case => $build ) {
			try {
				$build();
				$this->fail( sprintf( 'Accepted %s.', $case ) );
			} catch ( ErrorTableException $refusal ) {
				$this->assertInstanceOf( \LogicException::class, $refusal, $case );
			}
		}
	}

	/**
	 * Tests the rows that cannot declare detail keys: an internal one, and one that names a key twice.
	 *
	 * @since 0.1.0
	 */
	public function test_a_row_declares_its_detail_keys_once_and_never_when_internal(): void {
		$rows = array(
			'an internal row'                     => static fn() => new ErrorDefinition( DetailedError::Stale, 500, static fn(): string => 'Broken.', internal: true, details: array( 'state' ) ),
			'a detail key named as a placeholder' => static fn() => new ErrorDefinition( DetailedError::Stale, 409, static fn(): string => 'At %1$s.', array( 'current' ), details: array( 'current' ) ),
			'a detail key that is not a word'     => static fn() => new ErrorDefinition( DetailedError::Stale, 409, static fn(): string => 'Stale.', details: array( 'Not a Word' ) ),
		);

		foreach ( $rows as $case => $declare ) {
			try {
				$declare();
				$this->fail( sprintf( 'Accepted %s.', $case ) );
			} catch ( ErrorTableException $refusal ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}
}
