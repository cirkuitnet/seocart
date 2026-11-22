<?php
/**
 * Tests the declaration a payment extension's live workflow is stated by, and what is derived from it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;
use SEOCart\Tools\Packaging\Tests\TemporaryDirectory;

/**
 * An extension's tests/live.php is read once per root and must return a declaration; the repository, the slug, the live group, the script and the concurrency group are derived from it and its composer.json; a declaration that states what cannot be is refused, naming its file.
 *
 * Planted violation, shown red and removed: tests/live.php returning an array: the refusal names
 * the file and what it returned.
 *
 * @since 0.2.0
 */
final class LiveDeclarationTest extends TestCase {

	use TemporaryDirectory;

	/**
	 * Tests what is derived from the fixture's declaration and its composer.json.
	 *
	 * @since 0.2.0
	 */
	public function test_what_is_derived_comes_from_the_composer_name_and_the_workflow(): void {
		$declaration = StatedDeclaration::fixture();

		$this->assertSame( (string) realpath( StatedDeclaration::FIXTURE ), $declaration->root() );
		$this->assertSame( 'example/seocart-gateway-for-fixture-pay', $declaration->repository() );
		$this->assertSame( 'seocart-gateway-for-fixture-pay', $declaration->slug() );
		$this->assertSame( 'fixture-pay-live', $declaration->liveGroup() );
		$this->assertSame( 'fixture-pay-sandbox', $declaration->basename() );
		$this->assertSame( 'bin/ci/fixture-pay-sandbox.sh', $declaration->script() );
		$this->assertSame( 'live-conformance', $declaration->suiteJob() );
		$this->assertSame( array(), $declaration->problems() );
	}

	/**
	 * Tests that a root is read once, however it is spelled.
	 *
	 * @since 0.2.0
	 */
	public function test_a_root_is_read_once(): void {
		$this->assertSame( StatedDeclaration::fixture(), LiveDeclaration::load( StatedDeclaration::FIXTURE . '/../LiveExtension/' ) );
	}

	/**
	 * Tests that the parts a provider of four live parts states, one of them waiting for a secret, are stated with no change to SEOCart: the fixture's.
	 *
	 * @since 0.2.0
	 */
	public function test_four_parts_and_an_optional_one_are_stated_as_data(): void {
		$declaration = StatedDeclaration::fixture();

		$this->assertSame( array( 'conformance', 'payments', 'settled-refunds', 'webhooks', 'checkout' ), array_values( $declaration->jobs() ) );
		$this->assertSame( array( 'payments', 'settled-refunds', 'webhooks' ), array_keys( $declaration->liveParts() ) );
		$this->assertSame( 'webhooks', $declaration->optionalPart()['job'] ?? null );
	}

	/**
	 * Tests that a missing tests/live.php is refused, naming the file.
	 *
	 * @since 0.2.0
	 */
	public function test_a_missing_file_is_refused_naming_it(): void {
		$this->writeFile( 'extension/composer.json', '{"name": "example/seocart-gateway-for-nothing"}' );

		$this->assertRefused( 'tests/live.php is missing' );
	}

	/**
	 * Tests that a tests/live.php that returns anything but a declaration is refused, naming the file and what it returned.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider notDeclarations
	 *
	 * @param string $code     The file's code.
	 * @param string $returned What the refusal says it returned.
	 */
	public function test_a_file_returning_anything_else_is_refused_naming_it( string $code, string $returned ): void {
		$this->writeFile( 'extension/composer.json', '{"name": "example/seocart-gateway-for-nothing"}' );
		$this->writeFile( 'extension/tests/live.php', "<?php\n" . $code . "\n" );

		$this->assertRefused( 'tests/live.php returns ' . $returned . ', not an instance of ' . LiveDeclaration::class );
	}

	/**
	 * Returns code that returns no declaration.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> The code, and what it returns as the refusal names it; by name.
	 */
	public static function notDeclarations(): array {
		return array(
			'an array'           => array( 'return array( \'workflow\' => \'x.yml\' );', 'array' ),
			'nothing'            => array( '$x = 1;', 'int' ),
			'an object of other' => array( 'return new \stdClass();', 'stdClass' ),
		);
	}

	/**
	 * Tests that a composer.json that names no payment extension is refused, naming the file.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider composerFiles
	 *
	 * @param string $composer The composer.json's text.
	 * @param string $refused  Text of the refusal.
	 */
	public function test_a_composer_name_that_is_no_payment_extensions_is_refused( string $composer, string $refused ): void {
		$this->writeFile( 'extension/composer.json', $composer );
		$this->writeFile( 'extension/tests/live.php', (string) file_get_contents( StatedDeclaration::FIXTURE . '/tests/live.php' ) );

		$this->assertRefused( $refused );
	}

	/**
	 * Returns composer.json files that name no payment extension.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> The text and the refusal's; by name.
	 */
	public static function composerFiles(): array {
		return array(
			'no name'           => array( '{"type": "wordpress-plugin"}', 'composer.json names no repository' ),
			'not JSON'          => array( 'name: x', 'composer.json names no repository' ),
			'another plugin'    => array( '{"name": "example/seocart-shipping-for-post"}', 'is not `<vendor>/seocart-gateway-for-<provider>`' ),
			'the prefix alone'  => array( '{"name": "example/seocart-gateway-for-"}', 'is not `<vendor>/seocart-gateway-for-<provider>`' ),
			'capitals in names' => array( '{"name": "Example/seocart-gateway-for-Pay"}', 'is not `<vendor>/seocart-gateway-for-<provider>`' ),
		);
	}

	/**
	 * Tests that each thing a declaration cannot state is found.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider impossibleStatements
	 *
	 * @param array<string, mixed> $stated   The values stated otherwise.
	 * @param string               $expected Text of the problem expected.
	 */
	public function test_a_declaration_stating_what_cannot_be_is_found( array $stated, string $expected ): void {
		$this->assertStringContainsString( $expected, implode( "\n", StatedDeclaration::with( $stated )->problems() ) );
	}

	/**
	 * Returns one statement per thing a declaration cannot state.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{array<string, mixed>, string}> The values and the problem; by name.
	 */
	public static function impossibleStatements(): array {
		$jobs = array(
			'live-conformance' => 'conformance',
			'payments'         => 'payments',
		);

		return array(
			'a workflow that is no file'  => array( array( 'workflow' => 'sandbox' ), 'The workflow `sandbox` is not a workflow\'s file name.' ),
			'no job'                      => array( array( 'jobs' => array() ), 'It states no job.' ),
			'a job named verdict'         => array( array( 'jobs' => $jobs + array( 'verdict' => 'verdict' ) ), 'The job `verdict` chosen by `verdict` is not a job and a choice of their own' ),
			'a choice named all'          => array( array( 'jobs' => array( 'everything' => 'all' ) ), 'The job `everything` chosen by `all`' ),
			'two jobs of one choice'      => array( array( 'jobs' => $jobs + array( 'payments-again' => 'payments' ) ), 'Two jobs share a choice of the parts input.' ),
			'a live part of no job'       => array( array( 'jobs' => $jobs ), 'The live part `settled-refunds` is not a choice of a job' ),
			'a live part of no test'      => array(
				array(
					'liveParts' => array(
						'payments' => array(
							'filter' => '/x/',
							'tests'  => 0,
						),
					),
				),
				'The live part `payments` is not a choice of a job',
			),
			'a proof of no job'           => array( array( 'proofs' => array( 'Everything' => 'cli' ) + StatedDeclaration::fixture()->proofs() ), 'The proof `Everything` names no job: `cli`.' ),
			'a job proving nothing'       => array( array( 'proofs' => array( 'The suite' => 'live-conformance' ) ), 'The job `checkout` proves nothing the verdict lists.' ),
			'a fact of no name'           => array( array( 'facts' => array( 'Extra Presses' ) ), 'The fact `Extra Presses` is not a name' ),
			'a suite of no test'          => array( array( 'suiteTests' => 0 ), 'The conformance test holds no test.' ),
			'a skip of no test'           => array( array( 'suiteSkips' => array( 'rule 9' => 'expires' ) ), 'The skip `rule 9` for `expires`' ),
			'a shape that is no pattern'  => array( array( 'shapes' => array( 'a key' => '/(unclosed/' ) ), 'The shape of a key is no regular expression' ),
			'an optional part of no job'  => array(
				array(
					'optionalPart' => array(
						'job'    => 'cli',
						'secret' => 'KEY',
						'output' => 'key',
						'reason' => 'none yet',
					),
				),
				'The optional part names no job: `cli`.',
			),
			'an optional secret in lower' => array(
				array(
					'optionalPart' => array(
						'job'    => 'webhooks',
						'secret' => 'key',
						'output' => 'key',
						'reason' => 'none yet',
					),
				),
				'The optional part\'s secret `key` is not a secret\'s name.',
			),
			'an optional part, no reason' => array(
				array(
					'optionalPart' => array(
						'job'    => 'webhooks',
						'secret' => 'KEY',
						'output' => 'key',
						'reason' => ' ',
					),
				),
				'The optional part gives no reason.',
			),
		);
	}

	/**
	 * Tests that a declaration no extension's file returned has nothing derived: it is no extension's.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declaration_not_loaded_has_no_repository(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'has no repository, slug or root' );

		StatedDeclaration::with( array() )->slug();
	}

	/**
	 * Asserts that loading the scratch extension is refused with a message that names its tests/live.php or its composer.json and holds a text.
	 *
	 * @since 0.2.0
	 *
	 * @param string $expected Text of the refusal.
	 */
	private function assertRefused( string $expected ): void {
		try {
			LiveDeclaration::load( $this->directory . '/extension' );
			$this->fail( 'The extension was loaded.' );
		} catch ( \UnexpectedValueException $refused ) {
			$this->assertStringContainsString( realpath( $this->directory ) . '/extension/', $refused->getMessage() );
			$this->assertStringContainsString( $expected, $refused->getMessage() );
		}
	}
}
