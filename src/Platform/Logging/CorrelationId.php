<?php
/**
 * CorrelationId: the id that ties together everything one request caused
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

use SEOCart\Support\IdGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the correlation id of the work in progress.
 *
 * Owns one fact: which correlation id the current work carries. A request has one id, minted
 * the first time anything asks for it, or accepted from the client at the start of the
 * request. Work done later on behalf of another request, such as delivering the events that
 * request stored, runs inside scoped() with that request's id, so a support search for one id
 * finds the error body, the log lines, the stored events and every job they caused.
 *
 * An id is a UUID in its canonical 36-character form, lowercase, which is also the shape of
 * every column that stores one.
 *
 * @since 0.1.0
 */
final class CorrelationId {

	/**
	 * The canonical form of a UUID, lowercase.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	/**
	 * Mints new ids.
	 *
	 * @since 0.1.0
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * The id in force, or null until one is needed.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $id = null;

	/**
	 * Creates the holder. It mints nothing until asked.
	 *
	 * @since 0.1.0
	 *
	 * @param IdGenerator $ids Mints the request's id when none was accepted.
	 */
	public function __construct( IdGenerator $ids ) {
		$this->ids = $ids;
	}

	/**
	 * Returns the id in force, minting the request's own id the first time it is needed.
	 *
	 * @since 0.1.0
	 *
	 * @return string A lowercase UUID.
	 */
	public function current(): string {
		if ( null === $this->id ) {
			$this->id = $this->ids->generate();
		}

		return $this->id;
	}

	/**
	 * Adopts an id the client sent, if it is a UUID; anything else is ignored.
	 *
	 * Call it once, at the start of the request, before anything reads current(). An id that is
	 * not a UUID in the canonical form is ignored, and the request keeps, or later mints, an id
	 * of its own; so a client can never write anything but a UUID into a log line or a column.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $candidate What the client sent, for example the X-Request-Id header, or null.
	 */
	public function accept( ?string $candidate ): void {
		$id = self::normalize( $candidate );

		if ( null !== $id ) {
			$this->id = $id;
		}
	}

	/**
	 * Runs work under another id and puts the previous one back afterwards, also when the work throws.
	 *
	 * With null, or with something that is not a UUID, the work runs under the id in force.
	 *
	 * @since 0.1.0
	 *
	 * @param-immediately-invoked-callable $work
	 *
	 * @param string|null       $id   The id to run under, for example the one stored with an event.
	 * @param callable(): mixed $work The work.
	 * @return mixed What the work returned.
	 */
	public function scoped( ?string $id, callable $work ): mixed {
		$id = self::normalize( $id );

		if ( null === $id ) {
			return $work();
		}

		$previous = $this->id;
		$this->id = $id;

		try {
			return $work();
		} finally {
			$this->id = $previous;
		}
	}

	/**
	 * Returns the canonical form of a UUID, or null for anything else.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $candidate The candidate.
	 * @return string|null The lowercase UUID, or null.
	 */
	private static function normalize( ?string $candidate ): ?string {
		if ( null === $candidate ) {
			return null;
		}

		$lower = strtolower( trim( $candidate ) );

		return 1 === preg_match( self::PATTERN, $lower ) ? $lower : null;
	}
}
