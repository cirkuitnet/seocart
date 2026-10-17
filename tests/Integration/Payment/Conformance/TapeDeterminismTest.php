<?php
/**
 * Tests that a scenario recorded again is byte for byte the tape committed, and that another nonce draws its ids anew
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
 * Records the reference gateway's money path into a scratch directory, twice in two tests, and compares each recording with the committed tape; then records it with another nonce, and replays that recording.
 *
 * Every recording equal to the committed one, made in another process at another time, proves a
 * scenario sends the same bytes every run: the ids are seeded per scenario, the amounts fixed, and
 * the provider's answers counted from 1. A request body that held an id the seeded port did not
 * mint, or the time, would differ. A recording with another nonce differs from the committed tape
 * in every seeded id and in nothing else, and its replay matches it only when the replay seeds its
 * ids with the nonce the recording's settings hold.
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
	 * The nonce of the recording that must differ from the committed one in its seeded ids.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const OTHER_NONCE = '2026-10-16.b';

	/**
	 * The test that records with OTHER_NONCE.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const RECORDS_WITH_A_NONCE = 'test_a_recording_with_another_nonce_differs_in_its_seeded_ids_alone';

	/**
	 * The test that replays that recording.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REPLAYS_IT = 'test_a_replay_seeds_its_ids_with_the_nonce_its_tape_holds';

	/**
	 * An id a scenario draws from its seed: a UUID, the version 7 ones the seeded port mints and the version 5 ones derived from them, such as a refund's key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SEEDED_ID = '/[0-9a-f]{8}-[0-9a-f]{4}-[57][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/';

	/**
	 * The scratch directory the recording is written to, or replayed from.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $scratch = '';

	/**
	 * The recording made with OTHER_NONCE, kept for the test that replays it; empty until it is made.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private static string $kept = '';

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
	 * Tests that a recording with another nonce writes the nonce to its settings, and differs from the committed tape in every seeded id and in nothing else.
	 *
	 * Each id is numbered by where it first appears in its file, so the comparison also holds each
	 * id to the places the committed tape has it.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 *
	 * @return string The recording's directory, which the next test replays.
	 */
	public function test_a_recording_with_another_nonce_differs_in_its_seeded_ids_alone(): string {
		$this->check( Rule::LedgerShape );

		$files = self::exchangeFiles( self::TAPES );
		$ids   = 0;

		$this->assertSame( self::OTHER_NONCE, ( new Tape( $this->scratch ) )->settings()[ Tape::NONCE ] ?? null, 'The recording\'s settings do not hold its nonce.' );
		$this->assertNotSame( array(), $files, 'There is no committed tape of the money path to compare with.' );
		$this->assertSame( $files, self::exchangeFiles( $this->scratch ), 'The recording has other files than the committed tape.' );

		foreach ( $files as $file ) {
			$committed = (string) file_get_contents( self::TAPES . '/' . self::SCENARIO . '/' . $file );
			$recorded  = (string) file_get_contents( $this->scratch . '/' . self::SCENARIO . '/' . $file );
			$ids      += count( self::seededIds( $committed ) );

			$this->assertSame( array(), array_intersect( self::seededIds( $committed ), self::seededIds( $recorded ) ), sprintf( 'Exchange %s holds a seeded id of the committed tape.', $file ) );
			$this->assertSame( self::numbered( $committed ), self::numbered( $recorded ), sprintf( 'Exchange %s differs from the committed tape in more than its seeded ids.', $file ) );
		}

		$this->assertGreaterThan( 0, $ids, 'The committed tape holds no seeded id to compare.' );

		self::$kept    = $this->scratch;
		$this->scratch = '';

		return self::$kept;
	}

	/**
	 * Tests that a replay of the recording made with another nonce seeds its ids with the nonce its settings hold, so its requests carry the recorded ids and match the tape.
	 *
	 * @since 0.2.0
	 *
	 * @depends test_a_recording_with_another_nonce_differs_in_its_seeded_ids_alone
	 * @group conformance
	 *
	 * @param string $kept The recording's directory.
	 */
	public function test_a_replay_seeds_its_ids_with_the_nonce_its_tape_holds( string $kept ): void {
		$this->assertTrue( $this->transport->replays(), 'The test does not replay.' );
		$this->assertSame( $kept, $this->scratch, 'The test replays another recording than the one made with the nonce.' );

		$this->check( Rule::LedgerShape );
	}

	/**
	 * Removes the scratch recording.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		self::remove( $this->scratch );

		parent::tear_down();
	}

	/**
	 * Removes the recording made with another nonce, should the test that replays it not have run.
	 *
	 * @since 0.2.0
	 */
	public static function tear_down_after_class(): void {
		self::remove( self::$kept );
		self::$kept = '';

		parent::tear_down_after_class();
	}

	/**
	 * Records into a scratch directory, whatever SEOCART_TAPE says; the replay test replays the recording made with another nonce instead.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx The transaction manager.
	 * @return RecordingTransport The transport.
	 */
	protected function transportOver( TransactionManager $tx ): RecordingTransport {
		if ( self::REPLAYS_IT === $this->getName( false ) ) {
			$this->scratch = self::$kept;

			return RecordingTransport::replaying( new Tape( $this->scratch ), $tx, $this->http );
		}

		$this->scratch = sys_get_temp_dir() . '/seocart-tape-' . bin2hex( random_bytes( 6 ) );

		return RecordingTransport::recording( new Tape( $this->scratch ), $tx, $this->http );
	}

	/**
	 * Returns the nonce of the recording, whatever SEOCART_TAPE_NONCE says: none, so it is the committed tape, but in the test that records with another.
	 *
	 * @since 0.2.0
	 *
	 * @return string The nonce.
	 */
	protected function recordingNonce(): string {
		return self::RECORDS_WITH_A_NONCE === $this->getName( false ) ? self::OTHER_NONCE : '';
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

	/**
	 * Returns the seeded ids a tape file holds, each once, in the order they first appear.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The file's text.
	 * @return list<string> The ids.
	 */
	private static function seededIds( string $text ): array {
		preg_match_all( self::SEEDED_ID, $text, $ids );

		return array_values( array_unique( $ids[0] ) );
	}

	/**
	 * Returns a tape file's text with each seeded id replaced by its number, in the order the ids first appear.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The file's text.
	 * @return string The text, its ids numbered.
	 */
	private static function numbered( string $text ): string {
		$numbers = array_flip( self::seededIds( $text ) );

		return (string) preg_replace_callback( self::SEEDED_ID, static fn( array $id ): string => '<id ' . $numbers[ $id[0] ] . '>', $text );
	}

	/**
	 * Removes a scratch tapes' directory, its files and its directories.
	 *
	 * @since 0.2.0
	 *
	 * @param string $directory The directory; nothing happens when it is empty or gone.
	 */
	private static function remove( string $directory ): void {
		if ( '' === $directory || ! is_dir( $directory ) ) {
			return;
		}

		foreach ( ( new Tape( $directory ) )->files() as $file ) {
			unlink( $file );
		}

		foreach ( array( '/' . self::SCENARIO, '' ) as $within ) {
			if ( is_dir( $directory . $within ) ) {
				rmdir( $directory . $within );
			}
		}
	}
}
