<?php
/**
 * Tests that no database error message or context carries SQL or the server's error text
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Support\Error\ErrorDefinition;

/**
 * Keeps statements and server error text out of what an adapter can render.
 *
 * A statement can carry the values it writes, and the server's text for a duplicate key
 * quotes the duplicate value, so either can hold a customer's data. The context of a coded
 * error is rendered into its message, so the statement and the server text travel only in the
 * exception's previous exception, which no adapter renders; the typed accessors still read them
 * there for a log or doctor.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class DatabaseErrorMessagesTest extends TestCase {

	/**
	 * A statement that writes a customer's e-mail address.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const STATEMENT = "INSERT INTO `wp_seocart_customers` ( email ) VALUES ( 'jane@example.com' )";

	/**
	 * The server's text for a duplicate of that address.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SERVER_TEXT = "Duplicate entry 'jane@example.com' for key 'email'";

	/**
	 * Sets up Brain Monkey, which stands in for WordPress's gettext functions.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Lists a failure of each statement code.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{int, bool}> The error number, and whether it happened inside a window.
	 */
	public static function failures(): array {
		return array(
			'query_failed'          => array( 1064, false ),
			'duplicate_key'         => array( 1062, false ),
			'transaction_retryable' => array( 1213, true ),
			'transaction_lost'      => array( 2006, true ),
		);
	}

	/**
	 * Tests that neither the rendered message nor the context of a failure carries the statement or the server text.
	 *
	 * Planted violation: put `statement` back in the context and the row of QueryFailed.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider failures
	 *
	 * @param int  $errno  The MySQL error number.
	 * @param bool $inside Whether the statement ran inside a transaction window.
	 */
	public function test_no_message_or_context_carries_the_statement_or_the_server_text( int $errno, bool $inside ): void {
		$failure  = QueryFailed::fromErrno( $errno, '23000', self::STATEMENT, self::SERVER_TEXT, $inside );
		$rendered = ErrorDefinition::of( $failure->errorCode() )->render( $failure->context() );
		$visible  = $failure->getMessage() . "\n" . $rendered . "\n" . (string) json_encode( $failure->context() );

		foreach ( array( 'jane@example.com', 'INSERT INTO', 'Duplicate entry' ) as $private ) {
			$this->assertStringNotContainsString( $private, $visible );
		}
	}

	/**
	 * Tests that the statement and the server text stay reachable for a log, through the previous exception.
	 *
	 * @since 0.1.0
	 */
	public function test_the_statement_and_the_server_text_stay_reachable_for_diagnosis(): void {
		$duplicate = QueryFailed::fromErrno( 1062, '23000', self::STATEMENT, self::SERVER_TEXT, false );

		$this->assertInstanceOf( DuplicateKey::class, $duplicate );
		$this->assertStringStartsWith( 'INSERT INTO', $duplicate->statement() );
		$this->assertSame( self::SERVER_TEXT, $duplicate->serverMessage() );
		$this->assertInstanceOf( \RuntimeException::class, $duplicate->getPrevious() );

		$lost = QueryFailed::fromErrno( 2006, 'HY000', self::STATEMENT, 'MySQL server has gone away', true );

		$this->assertInstanceOf( TransactionIntegrityLost::class, $lost );
		$this->assertStringStartsWith( 'INSERT INTO', $lost->statement() );
	}
}
