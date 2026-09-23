<?php
/**
 * Tests which error rows a client may read: public by default, every database row internal
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Rest;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Support\Error\ErrorDefinition;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Fixtures\Operations\FixtureStoreError;
use SEOCart\Tests\Unit\Support\Error\Fixtures\FixtureError;

/**
 * The exposure of an error is declared once, on its row. A row is public unless it says
 * `internal: true`, and every row of the database catalog says it: a database failure can carry
 * a statement, a lock name or a server's words, so a client gets its status and a generic
 * message, and the log gets the rest. The translator reads nothing but the row's flag.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class ErrorExposureTest extends TestCase {

	/**
	 * Tests that a row is public unless it is declared internal.
	 *
	 * @since 0.1.0
	 */
	public function test_a_row_is_public_by_default(): void {
		$this->assertFalse( ( new ErrorDefinition( FixtureError::NotFound, 404, static fn(): string => 'Nothing was found.' ) )->isInternal() );
		$this->assertTrue( ( new ErrorDefinition( FixtureError::NotFound, 404, static fn(): string => 'Nothing was found.', array(), true ) )->isInternal() );
		$this->assertFalse( ErrorDefinition::of( SupportError::UnknownCurrency )->isInternal(), 'A client must read which currency it asked for.' );
	}

	/**
	 * Tests that a row is raised only by the operations that declare it, unless it says any write may raise it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_row_is_for_the_operations_that_declare_it_by_default(): void {
		$this->assertFalse( ( new ErrorDefinition( FixtureError::NotFound, 404, static fn(): string => 'Nothing was found.' ) )->isAnyWrite() );
		$this->assertTrue( ErrorDefinition::of( FixtureStoreError::Unavailable )->isAnyWrite() );
		$this->assertFalse( ErrorDefinition::of( FixtureStoreError::Unavailable )->isInternal(), 'Any write may raise it, and a client still reads it.' );
	}

	/**
	 * Tests that every row of the database catalog is internal.
	 *
	 * @since 0.1.0
	 */
	public function test_every_database_row_is_internal(): void {
		$public = array();

		foreach ( DatabaseError::definitions() as $row ) {
			if ( ! $row->isInternal() ) {
				$public[] = (string) $row->code()->value;
			}
		}

		$this->assertCount( count( DatabaseError::cases() ), DatabaseError::definitions(), 'The catalog has one row per code, so every code was checked.' );
		$this->assertSame( array(), $public, 'A database failure must never be rendered to a client: declare the row internal.' );
	}
}
