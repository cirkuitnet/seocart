<?php
/**
 * Tests that a scenario recorded again is byte for byte the tape committed
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Tests\Conformance\Payment\RecordingTransport;
use SEOCart\Tests\Conformance\Payment\ConformanceHarness;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGatewaySetup;
use SEOCart\Tests\Conformance\Payment\Rule;
use SEOCart\Tests\Conformance\Payment\Tape;

/**
 * Records the reference gateway's money path into a scratch directory, twice in two tests, and compares each recording with the committed tape.
 *
 * Every recording equal to the committed one, made in another process at another time, proves a
 * scenario sends the same bytes every run: the ids are seeded per scenario, the amounts fixed, and
 * the provider's answers counted from 1. A request body that held an id the seeded port did not
 * mint, or the time, would differ.
 *
 * @since 0.2.0
 */
final class TapeDeterminismTest extends ConformanceHarness {

	use ReferenceGatewaySetup;

	/**
	 * The scenario recorded.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SCENARIO = 'rule01-money-path-usd-any';

	/**
	 * The scratch directory the recording is written to.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $scratch = '';

	/**
	 * Returns the two recordings.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: int}> The cases.
	 */
	public function recordings(): array {
		return array(
			'first recording'  => array( 1 ),
			'second recording' => array( 2 ),
		);
	}

	/**
	 * Tests that a recording of the money path is the committed tape, byte for byte.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider recordings
	 * @group conformance
	 *
	 * @param int $run Which recording.
	 */
	public function test_a_recording_is_the_committed_tape_byte_for_byte( int $run ): void {
		unset( $run );

		$this->check( Rule::LedgerShape );

		$committed = new Tape( self::TAPES );
		$recorded  = new Tape( $this->scratch );
		$files     = self::exchangeFiles( self::TAPES );

		$this->assertNotSame( array(), $files, 'There is no committed tape of the money path to compare with.' );
		$this->assertSame( $files, self::exchangeFiles( $this->scratch ), 'The recording has other files than the committed tape.' );

		foreach ( $files as $file ) {
			$this->assertSame( (string) file_get_contents( $committed->directory() . '/' . self::SCENARIO . '/' . $file ), (string) file_get_contents( $recorded->directory() . '/' . self::SCENARIO . '/' . $file ), sprintf( 'Exchange %s of the money path was recorded with other bytes than the committed tape holds.', $file ) );
		}
	}

	/**
	 * Removes the scratch recording.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		if ( '' !== $this->scratch ) {
			foreach ( array_reverse( ( new Tape( $this->scratch ) )->files() ) as $file ) {
				unlink( $file );
			}

			foreach ( array( '/' . self::SCENARIO, '' ) as $directory ) {
				if ( is_dir( $this->scratch . $directory ) ) {
					rmdir( $this->scratch . $directory );
				}
			}
		}

		parent::tear_down();
	}

	/**
	 * Records into a scratch directory, whatever SEOCART_TAPE says.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx The transaction manager.
	 * @return RecordingTransport The transport.
	 */
	protected function transportOver( TransactionManager $tx ): RecordingTransport {
		$this->scratch = sys_get_temp_dir() . '/seocart-tape-' . bin2hex( random_bytes( 6 ) );

		return RecordingTransport::recording( new Tape( $this->scratch ), $tx, $this->http );
	}

	/**
	 * Reads the non-secret settings from the committed tape, as a replay does, so the recording uses the same.
	 *
	 * @since 0.2.0
	 *
	 * @return string The scratch directory once the recording started; the committed tapes' before.
	 */
	protected function tapes(): string {
		return '' === $this->scratch ? self::TAPES : $this->scratch;
	}

	/**
	 * Lists the exchange files of the scenario in a tapes' directory.
	 *
	 * @since 0.2.0
	 *
	 * @param string $tapes The tapes' directory.
	 * @return list<string> The files' names, sorted.
	 */
	private static function exchangeFiles( string $tapes ): array {
		$files = glob( $tapes . '/' . self::SCENARIO . '/*.json' );

		return false === $files ? array() : array_map( 'basename', $files );
	}
}
