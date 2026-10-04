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
use SEOCart\Contracts\OutboundHost;
use SEOCart\Platform\Http\OutboundEndpoints;
use SEOCart\Tools\Docs\ExternalServicesSection;

/**
 * Covers rendering from the registry, replacing only the owned region, and rejecting bad entries.
 *
 * Planted violation, shown red and removed: add a parameter `public string $retention = 'Kept
 * for thirty days.'` to OutboundHost's constructor, and nothing to this generator. The field
 * test then names "retention" as a field the readme does not show.
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
	 * The OutboundHost parameters a disclosure leaves out, each with the reason.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private const NOT_DISCLOSED = array(
		'id'             => 'the key a request names the service by; the readme names the service instead',
		'timeoutSeconds' => 'a limit for the client, not something sent',
	);

	/**
	 * Returns a complete, valid declaration.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Returns an OutboundHost.
	 *
	 * @param array<string, mixed> $overrides Optional. Fields to replace, by parameter name. Default none.
	 * @return OutboundHost The declaration.
	 */
	private static function host( array $overrides = array() ): OutboundHost {
		return new OutboundHost(
			...array_merge(
				array(
					'id'         => 'example-rates',
					'service'    => 'Example Rates',
					'purpose'    => 'Example Rates is a currency exchange-rate service. SEOCart uses it to refresh exchange rates.',
					'endpoint'   => 'https://api.example.com/v1/rates',
					'dataSent'   => 'The store currency code. No personal data.',
					'sentWhen'   => 'Once a day, and only after an administrator enables automatic exchange rates.',
					'termsUrl'   => 'https://example.com/terms',
					'privacyUrl' => 'https://example.com/privacy',
				),
				$overrides
			)
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
	 * Tests that an entry renders exactly as the readme has always shown one.
	 *
	 * @since 0.2.0
	 */
	public function test_renders_an_entry_in_the_readme_format(): void {
		$this->assertSame(
			"SEOCart connects to the external services listed below, each only for the purpose stated and only under the condition stated. It contacts no other server.\n\n"
			. "= Example Rates =\n\n"
			. "Example Rates is a currency exchange-rate service. SEOCart uses it to refresh exchange rates.\n\n"
			. "* Endpoint: `https://api.example.com/v1/rates`\n"
			. "* Data sent: The store currency code. No personal data.\n"
			. "* When: Once a day, and only after an administrator enables automatic exchange rates.\n"
			. "* Terms of use: https://example.com/terms\n"
			. '* Privacy policy: https://example.com/privacy',
			( new ExternalServicesSection( array( self::host() ) ) )->render()
		);
	}

	/**
	 * Tests that every field a declaration has reaches the readme, but the two that are not disclosures.
	 *
	 * OutboundHost's constructor is the one list of what a disclosure says, so a field added
	 * there must be rendered here, or named in NOT_DISCLOSED with a reason.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Reads the fields from OutboundHost's constructor.
	 */
	public function test_renders_every_field_of_an_entry(): void {
		$host       = self::host();
		$body       = ( new ExternalServicesSection( array( $host ) ) )->render();
		$parameters = array();

		foreach ( ( new \ReflectionMethod( OutboundHost::class, '__construct' ) )->getParameters() as $parameter ) {
			$parameters[] = $parameter->getName();

			if ( ! isset( self::NOT_DISCLOSED[ $parameter->getName() ] ) ) {
				$this->assertStringContainsString( (string) $host->{$parameter->getName()}, $body, "The \"{$parameter->getName()}\" field is not rendered." );
			}
		}

		$this->assertSame( array(), array_values( array_diff( array_keys( self::NOT_DISCLOSED ), $parameters ) ), 'A field left out of the disclosure no longer exists.' );
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
	 * Each field's own rules are OutboundHost's, and its tests cover them.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<int, mixed>}> Registries, keyed by the defect.
	 */
	public static function invalidRegistries(): array {
		return array(
			'entry is an array'     => array( array( array( 'id' => 'example-rates' ) ) ),
			'entry is a string'     => array( array( 'example-rates' ) ),
			'duplicate id'          => array( array( self::host(), self::host() ) ),
			'duplicate id, renamed' => array( array( self::host(), self::host( array( 'service' => 'Example Rates, again' ) ) ) ),
		);
	}

	/**
	 * Tests that a malformed registry entry is an error, never an omission.
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
