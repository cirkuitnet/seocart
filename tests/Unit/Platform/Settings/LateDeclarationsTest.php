<?php
/**
 * Tests the settings registry's late declarations: asked once, only when a read needs them
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Settings;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\SettingsRegistry;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\SchemaException;
use SEOCart\Tests\Support\SettingsFixtures;

/**
 * Late declarations join the registry the first time it is asked for a setting or a group it does not hold, or for every setting or option, and never for a read the declared settings answer, nor for the exposed settings; they are checked with the rest, and may not be exposed.
 *
 * Planted violation, shown red and removed: in SettingsRegistry's constructor, call compose() at
 * once: the late declarations are then asked for on a read that does not need them.
 *
 * @since 0.2.0
 */
final class LateDeclarationsTest extends TestCase {

	/**
	 * Tests that the declared settings, a group of them and the exposed settings are read without asking for the late declarations, and that a setting declared late is read after asking for them once.
	 *
	 * @since 0.2.0
	 */
	public function test_late_declarations_are_asked_for_once_and_only_when_needed(): void {
		$asked    = 0;
		$registry = self::registry(
			static function () use ( &$asked ): array {
				++$asked;

				return self::gatewayDocument();
			}
		);

		$registry->setting( 'mode' );
		$registry->group( SettingsFixtures::DOCUMENT );
		$registry->exposed();

		$this->assertSame( 0, $asked, 'Reads the declared settings answer ask for nothing.' );
		$this->assertTrue( $registry->setting( 'example_test_secret_key' )->isSecret(), 'A setting declared late is read once declared.' );
		$this->assertSame( 1, $asked );

		$registry->all();
		$registry->options();
		$registry->group( 'gateway_example' );

		$this->assertSame( 1, $asked, 'The late declarations are asked for once.' );
		$this->assertArrayHasKey( 'seocart_gateway_example', $registry->options() );
	}

	/**
	 * Tests that every read of every setting or option asks for the late declarations.
	 *
	 * @since 0.2.0
	 */
	public function test_every_whole_read_asks_for_the_late_declarations(): void {
		foreach ( array( 'all', 'options', 'optionDefinitions' ) as $read ) {
			$asked    = 0;
			$registry = self::registry(
				static function () use ( &$asked ): array {
					++$asked;

					return self::gatewayDocument();
				}
			);

			$registry->{$read}();

			$this->assertSame( 1, $asked, $read );
		}
	}

	/**
	 * Tests that a late setting is refused exposed, and checked with the rest: here, against an option already declared.
	 *
	 * @since 0.2.0
	 */
	public function test_late_declarations_are_checked_with_the_rest(): void {
		$exposed = self::registry(
			static fn(): array => array(
				'settings'  => array( Setting::scalar( 'late', self::field( 'shown', Privacy::Public, 'x' ), true ) ),
				'documents' => array(),
			)
		);

		try {
			$exposed->all();
			$this->fail( 'A late setting was accepted exposed.' );
		} catch ( SchemaException $refused ) {
			$this->assertStringContainsString( 'declared late and exposed', $refused->getMessage() );
		}

		$clash = self::registry(
			static fn(): array => array(
				'settings'  => array( Setting::inDocument( SettingsFixtures::DOCUMENT, self::field( 'mode', Privacy::Public, 'x' ), false ) ),
				'documents' => array(),
			)
		);

		$this->expectException( SchemaException::class );

		$clash->setting( 'nope' );
	}

	/**
	 * Tests that late declarations that cannot be given are asked for again at the next read that needs them.
	 *
	 * @since 0.2.0
	 */
	public function test_late_declarations_that_throw_are_asked_for_again(): void {
		$asked    = 0;
		$registry = self::registry(
			static function () use ( &$asked ): array {
				if ( 1 === ++$asked ) {
					throw new \LogicException( 'Not yet.' );
				}

				return self::gatewayDocument();
			}
		);

		$failed = false;

		try {
			$registry->all();
		} catch ( \LogicException ) {
			$failed = true;
		}

		$this->assertTrue( $failed, 'The first ask failed.' );
		$this->assertSame( 'example_test_secret_key', $registry->setting( 'example_test_secret_key' )->name() );
		$this->assertSame( 2, $asked );
	}

	/**
	 * Builds the fixture registry with late declarations.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $late The late declarations.
	 * @return SettingsRegistry The registry.
	 */
	private static function registry( \Closure $late ): SettingsRegistry {
		return new SettingsRegistry(
			array_merge( SettingsFixtures::scalars(), SettingsFixtures::document() ),
			array( SettingsFixtures::DOCUMENT => SettingsFixtures::DOCUMENT_PURPOSE ),
			$late
		);
	}

	/**
	 * Returns the late declarations of one gateway's document.
	 *
	 * @since 0.2.0
	 *
	 * @return array{settings: list<Setting>, documents: array<string, string>} The declarations.
	 */
	private static function gatewayDocument(): array {
		return array(
			'settings'  => array( Setting::inDocument( 'gateway_example', self::field( 'example_test_secret_key', Privacy::Secret, null ), false ) ),
			'documents' => array( 'gateway_example' => 'The settings of the example gateway.' ),
		);
	}

	/**
	 * Declares a text field.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $name     The name.
	 * @param Privacy     $privacy  The privacy class.
	 * @param string|null $fallback The default, or null.
	 * @return FieldSpec The field.
	 */
	private static function field( string $name, Privacy $privacy, ?string $fallback ): FieldSpec {
		return new FieldSpec( name: $name, type: FieldType::String, description: 'A late setting.', label: static fn(): string => 'Late', example: 'x', default_value: $fallback, privacy: $privacy );
	}
}
