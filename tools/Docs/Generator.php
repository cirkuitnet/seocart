<?php
/**
 * Generator: the contract every committed, generated document is produced through
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

/**
 * Produces the content of one generated, committed file from its single source.
 *
 * A generator never writes and never prints. DocsRunner decides whether its output is
 * written (`composer docs:generate`) or compared (`composer docs:check`), so every
 * generator gets the drift check and the skip assertion without implementing either.
 *
 * To add a generator: implement this interface and add an instance to the list in
 * bin/generate-docs.php.
 *
 * @since 0.1.0
 */
interface Generator {

	/**
	 * Returns the generator's stable identifier, used in every message about it.
	 *
	 * @since 0.1.0
	 *
	 * @return string Kebab-case identifier, for example 'readme-external-services'.
	 */
	public function id(): string;

	/**
	 * Returns the file this generator owns, or owns a region of.
	 *
	 * @since 0.1.0
	 *
	 * @return string Path relative to the repository root, with forward slashes.
	 */
	public function target(): string;

	/**
	 * Produces the complete new content of the target file.
	 *
	 * A generator that owns a whole file ignores `$current`. A generator that owns a
	 * region of a hand-written file returns `$current` with that region replaced.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the source is invalid or the target cannot host the output.
	 *
	 * @param string $current The target's current content, or '' when the file does not exist.
	 * @return GenerationResult The new content and the identifiers of every source item left out.
	 */
	public function generate( string $current ): GenerationResult;

	/**
	 * Returns the source items this generator is known, and allowed, to leave out.
	 *
	 * Skips are an asserted set, not a log line. DocsRunner fails the run when an item
	 * is skipped that is not listed here, and also when a listed item is no longer
	 * skipped, so the list can neither be outgrown nor go stale. Implementations return
	 * a literal, hand-reviewed list with the reason for each entry in a comment — never
	 * a value computed from what happened to be skipped.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Source item identifiers.
	 */
	public function expectedSkips(): array;
}
