<?php
/**
 * Tests the generated "External services" section of readme.txt
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Http\OutboundEndpoints;
use SEOCart\Tools\Docs\ExternalServicesSection;

/**
 * Covers rendering from the registry, replacing only the owned region, and rejecting bad entries.
 *
 * @since 0.1.0
 */
final class ExternalServicesSectionTest extends TestCase {

	/**
	 * A readme with a hand-edited External services section between two other sections.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const README = "=== Name ===\nStable tag: 1.0.0\n\nShort.\n\n== Description ==\n\nHand-written.\n\n== External services ==\n\nEdited by hand.\n\n= A subsection =\n\nMore.\n\n== Changelog ==\n\n= 1.0.0 =\n* First.\n";

	/**
	 * Returns a complete, valid registry entry.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $overrides Optional. Fields to replace. Default none.
	 * @return array<string, mixed> The entry.
	 */
	private static function endpoint( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'          => 'example-rates',
				'service'     => 'Example Rates',
				'purpose'     => 'Example Rates is a currency exchange-rate service. SEOCart uses it to refresh exchange rates.',
				'endpoint'    => 'https://api.example.com/v1/rates',
				'data_sent'   => 'The store currency code. No personal data.',
				'sent_when'   => 'Once a day, and only after an administrator enables automatic exchange rates.',
				'terms_url'   => 'https://example.com/terms',
				'privacy_url' => 'https://example.com/privacy',
			),
			$overrides
		);
	}

	/**
	 * Tests that an empty registry renders one plain sentence.
	 *
	 * @since 0.1.0
	 */
	public function test_empty_registry_states_that_nothing_is_contacted(): void {
		$this->assertSame(
			'SEOCart does not connect to any external service: it sends no data from your site to any other server.',
			( new ExternalServicesSection( array() ) )->render()
		);
	}

	/**
	 * Tests that the real registry renders, which validates every entry it declares.
	 *
	 * @since 0.1.0
	 */
	public function test_the_real_registry_renders(): void {
		$this->assertNotSame( '', ( new ExternalServicesSection( OutboundEndpoints::all() ) )->render() );
	}

	/**
	 * Tests that only the owned region changes, and that regenerating is idempotent.
	 *
	 * @since 0.1.0
	 */
	public function test_replaces_only_its_own_section(): void {
		$generator = new ExternalServicesSection( array() );
		$result    = $generator->generate( self::README );

		$this->assertSame(
			"=== Name ===\nStable tag: 1.0.0\n\nShort.\n\n== Description ==\n\nHand-written.\n\n== External services ==\n\n" . $generator->render() . "\n\n== Changelog ==\n\n= 1.0.0 =\n* First.\n",
			$result->content
		);
		$this->assertSame( array(), $result->skipped );
		$this->assertSame( $result->content, $generator->generate( $result->content )->content );
	}

	/**
	 * Tests that a section at the end of the file is replaced up to the end.
	 *
	 * @since 0.1.0
	 */
	public function test_replaces_a_section_at_the_end_of_the_file(): void {
		$generator = new ExternalServicesSection( array() );

		$this->assertSame(
			"== Description ==\n\nText.\n\n== External services ==\n\n" . $generator->render() . "\n",
			$generator->generate( "== Description ==\n\nText.\n\n== External services ==\n\nOld.\nOlder.\n" )->content
		);
	}

	/**
	 * Tests that every declared field of an entry reaches the readme.
	 *
	 * @since 0.1.0
	 */
	public function test_renders_every_field_of_an_entry(): void {
		$body = ( new ExternalServicesSection( array( self::endpoint() ) ) )->render();

		foreach ( array_diff( OutboundEndpoints::FIELDS, array( 'id' ) ) as $field ) {
			$this->assertStringContainsString( self::endpoint()[ $field ], $body, "The \"{$field}\" field is not rendered." );
		}

		$this->assertStringContainsString( "\n\n= Example Rates =\n\n", $body );
		$this->assertStringNotContainsString( 'does not connect', $body );
	}

	/**
	 * Provides readmes that cannot host the section.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> Readme texts, keyed by the defect.
	 */
	public static function unusableReadmes(): array {
		return array(
			'no heading'           => array( "== Description ==\n\nText.\n" ),
			'two headings'         => array( "== External services ==\n\nOne.\n\n== External services ==\n\nTwo.\n" ),
			'windows line endings' => array( "== External services ==\r\n\r\nText.\r\n" ),
		);
	}

	/**
	 * Tests that the generator fails rather than guessing where the section belongs.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider unusableReadmes
	 *
	 * @param string $readme A readme that cannot host the section.
	 */
	public function test_fails_when_the_readme_cannot_host_the_section( string $readme ): void {
		$this->expectException( \RuntimeException::class );

		( new ExternalServicesSection( array() ) )->generate( $readme );
	}

	/**
	 * Provides registries with one defect each.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<int, mixed>}> Registries, keyed by the defect.
	 */
	public static function invalidRegistries(): array {
		$missing = self::endpoint();
		unset( $missing['privacy_url'] );

		return array(
			'entry is not an array' => array( array( 'example-rates' ) ),
			'missing field'         => array( array( $missing ) ),
			'unknown field'         => array( array( self::endpoint( array( 'notes' => 'An undeclared field.' ) ) ) ),
			'empty field'           => array( array( self::endpoint( array( 'data_sent' => ' ' ) ) ) ),
			'multi-line field'      => array( array( self::endpoint( array( 'purpose' => "One.\n== Changelog ==" ) ) ) ),
			'non-string field'      => array( array( self::endpoint( array( 'endpoint' => 42 ) ) ) ),
			'id not kebab-case'     => array( array( self::endpoint( array( 'id' => 'Example Rates' ) ) ) ),
			'insecure terms link'   => array( array( self::endpoint( array( 'terms_url' => 'http://example.com/terms' ) ) ) ),
			'duplicate id'          => array( array( self::endpoint(), self::endpoint() ) ),
		);
	}

	/**
	 * Tests that an incomplete or malformed registry entry is an error, never an omission.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider invalidRegistries
	 *
	 * @param array<int, mixed> $registry A registry with one defect.
	 */
	public function test_rejects_an_invalid_registry_entry( array $registry ): void {
		$this->expectException( \RuntimeException::class );

		( new ExternalServicesSection( $registry ) )->render();
	}
}
