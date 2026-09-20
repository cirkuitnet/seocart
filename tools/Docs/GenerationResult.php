<?php
/**
 * GenerationResult: what one generator run produced
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

/**
 * Carries a generator's output together with the source items it left out.
 *
 * The two travel together so that a generator cannot produce content without also
 * accounting for what is missing from it.
 *
 * @since 0.1.0
 */
final class GenerationResult {

	/**
	 * The complete new content of the target file.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public readonly string $content;

	/**
	 * Identifiers of the source items the generator left out of the content.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public readonly array $skipped;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $content The complete new content of the target file.
	 * @param string[] $skipped Optional. Identifiers of the source items left out. Default none.
	 */
	public function __construct( string $content, array $skipped = array() ) {
		$this->content = $content;
		$this->skipped = $skipped;
	}
}
