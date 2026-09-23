<?php
/**
 * Tests the surface bindings and the annotations an operation is declared with
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Application\Operations;

use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Support\Schema\SchemaException;

/**
 * RestBinding, CliBinding and Annotations: what each carries and what each refuses.
 *
 * @since 0.1.0
 */
final class BindingsTest extends TestCase {

	/**
	 * Tests that a route yields its parameters in order and keeps its method.
	 *
	 * @since 0.1.0
	 */
	public function test_a_route_yields_its_parameters(): void {
		$rest = new RestBinding( '/stores/{store_id}/stock-items/{item_id}', WriteMethod::Patch );

		$this->assertSame( '/stores/{store_id}/stock-items/{item_id}', $rest->route() );
		$this->assertSame( array( 'store_id', 'item_id' ), $rest->pathParameters() );
		$this->assertSame( WriteMethod::Patch, $rest->writeMethod() );
		$this->assertNull( ( new RestBinding( '/stock-items' ) )->writeMethod() );
		$this->assertSame( array(), ( new RestBinding( '/stock-items' ) )->pathParameters() );
	}

	/**
	 * Tests that no write method is GET: a change can only be declared with POST, PUT, PATCH or DELETE.
	 *
	 * @since 0.1.0
	 */
	public function test_no_write_method_is_get(): void {
		$this->assertSame( array( 'POST', 'PUT', 'PATCH', 'DELETE' ), array_map( static fn( WriteMethod $method ): string => $method->value, WriteMethod::cases() ) );
	}

	/**
	 * Provides routes the binding refuses.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string}> Route, expected message part.
	 */
	public static function refusedRoutes(): array {
		return array(
			'no leading slash'        => array( 'stock-items', 'must start with a slash' ),
			'only a slash'            => array( '/', 'has a segment that is neither' ),
			'trailing slash'          => array( '/stock-items/', 'has a segment that is neither' ),
			'upper case'              => array( '/Stock-Items', 'has a segment that is neither' ),
			'regular expression'      => array( '/stock-items/(?P<id>\d+)', 'has a segment that is neither' ),
			'parameter in kebab-case' => array( '/stock-items/{item-id}', 'has a segment that is neither' ),
			'parameter first'         => array( '/{item_id}/adjustments', 'must start with a text segment' ),
			'parameter named twice'   => array( '/items/{item_id}/copies/{item_id}', 'names the parameter item_id twice' ),
		);
	}

	/**
	 * Tests that a malformed route is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedRoutes
	 *
	 * @param string $route            The route.
	 * @param string $expected_message Part of the message.
	 */
	public function test_a_malformed_route_is_refused( string $route, string $expected_message ): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( $expected_message );

		new RestBinding( $route, WriteMethod::Post );
	}

	/**
	 * Tests that a command is named below the plugin's root command.
	 *
	 * @since 0.1.0
	 */
	public function test_a_command_is_named_below_the_root(): void {
		$cli = new CliBinding( array( 'stock', 'adjust' ), array( 'item_id' ) );

		$this->assertSame( 'seocart stock adjust', $cli->command() );
		$this->assertSame( array( 'item_id' ), $cli->positional() );
		$this->assertSame( array(), ( new CliBinding( array( 'doctor' ) ) )->positional() );
	}

	/**
	 * Provides commands the binding refuses.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{list<string>, list<string>, string}> Path, positional names, expected message part.
	 */
	public static function refusedCommands(): array {
		return array(
			'no path'                => array( array(), array(), 'needs a path below wp seocart' ),
			'word with a space'      => array( array( 'stock adjust' ), array(), 'is not kebab-case' ),
			'word in snake_case'     => array( array( 'stock_items' ), array(), 'is not kebab-case' ),
			'positional named twice' => array( array( 'stock', 'adjust' ), array( 'item_id', 'item_id' ), 'names a positional argument twice' ),
		);
	}

	/**
	 * Tests that a malformed command is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedCommands
	 *
	 * @param string[] $path             The command path.
	 * @param string[] $positional       The positional names.
	 * @param string   $expected_message Part of the message.
	 *
	 * @phpstan-param list<string> $path
	 * @phpstan-param list<string> $positional
	 */
	public function test_a_malformed_command_is_refused( array $path, array $positional, string $expected_message ): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( $expected_message );

		new CliBinding( $path, $positional );
	}

	/**
	 * Tests that the annotations are carried and written under the keys the Abilities API reads.
	 *
	 * @since 0.1.0
	 */
	public function test_annotations_are_written_for_the_abilities_api(): void {
		$annotations = new Annotations( read_only: false, destructive: true, idempotent: true );

		$this->assertFalse( $annotations->isReadOnly() );
		$this->assertTrue( $annotations->isDestructive() );
		$this->assertTrue( $annotations->isIdempotent() );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			$annotations->toArray()
		);
	}

	/**
	 * Tests that an operation cannot be both read-only and destructive.
	 *
	 * @since 0.1.0
	 */
	public function test_read_only_and_destructive_together_is_refused(): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'cannot be both read-only and destructive' );

		new Annotations( read_only: true, destructive: true, idempotent: true );
	}
}
