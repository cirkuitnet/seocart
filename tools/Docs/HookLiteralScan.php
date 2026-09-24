<?php
/**
 * HookLiteralScan: finds every literal `seocart_` filter or action call in a source map
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

/**
 * Finds every `apply_filters()`/`do_action()` call — and their `_ref_array` and `_deprecated`
 * variants — whose first argument is, or starts as, a `seocart_` string literal, in either quote
 * style.
 *
 * A call whose name is built entirely from a constant, such as
 * `apply_filters( EndResponseEarlyFilter::NAME, ... )`, never appears here: no quoted literal
 * follows the opening parenthesis, so there is nothing to find. That is by design, not a gap —
 * the constant *is* the declaration, so the name cannot be undeclared, and FilterDeclarationsTest
 * already holds every declared filter's class to what src/ contains. This scan exists only for a
 * hook name that is still hand-typed as a string.
 *
 * A finding is either a clean literal (the whole hook name, as written) or dynamic: the literal
 * fragment starting `seocart_` is followed, within the same call, by string interpolation
 * (`$` or `{` inside a double-quoted literal) or by concatenation (`.` immediately after the
 * closing quote). A dynamic hook's real name cannot be known from the source, so a caller reports
 * it unconditionally — there is no declared name to compare it against.
 *
 * @since 0.1.0
 */
final class HookLiteralScan {

	/**
	 * Finds every `seocart_` filter or action call in a source map.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $sources Source text, keyed by a label used only in the
	 *                                       result — a file path, or any other identifier.
	 * @return list<array{file: string, hook: string, dynamic: bool}> One entry per call found, in
	 *         source order within each file. `hook` is the literal name for a clean call, or the
	 *         literal fragment found, followed by `…`, for a dynamic one.
	 */
	public static function find( array $sources ): array {
		$found   = array();
		$pattern = '/\b(?:apply_filters|do_action)(?:_ref_array|_deprecated)?\s*\(\s*/';

		foreach ( $sources as $file => $source ) {
			$offset = 0;
			$length = strlen( $source );

			while ( $offset < $length && preg_match( $pattern, $source, $call, PREG_OFFSET_CAPTURE, $offset ) ) {
				$after  = $call[0][1] + strlen( $call[0][0] );
				$result = self::firstArgument( $source, $after );

				if ( null !== $result ) {
					$found[] = array( 'file' => $file ) + $result;
				}

				$offset = $after;
			}
		}

		return $found;
	}

	/**
	 * Reads the first argument at a position right after a call's opening `(`, when it is, or
	 * starts as, a `seocart_` string literal.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The file's source.
	 * @param int    $start  The offset right after the call's opening `(`.
	 * @return array{hook: string, dynamic: bool}|null The finding, or null when the first argument
	 *                                                 is not a `seocart_` string literal.
	 */
	private static function firstArgument( string $source, int $start ): ?array {
		if ( ! isset( $source[ $start ] ) || ( "'" !== $source[ $start ] && '"' !== $source[ $start ] ) ) {
			return null;
		}

		$quote  = $source[ $start ];
		$length = strlen( $source );
		$end    = $start + 1;

		while ( $end < $length && $quote !== $source[ $end ] ) {
			$end += ( '\\' === $source[ $end ] ) ? 2 : 1;
		}

		if ( $end >= $length ) {
			return null;
		}

		$content = substr( $source, $start + 1, $end - $start - 1 );

		if ( ! str_starts_with( $content, 'seocart_' ) ) {
			return null;
		}

		$interpolated = '"' === $quote && ( str_contains( $content, '$' ) || str_contains( $content, '{' ) );
		$rest         = ltrim( substr( $source, $end + 1, 8 ) );
		$concatenated = isset( $rest[0] ) && '.' === $rest[0];

		if ( $interpolated ) {
			return array(
				'hook'    => substr( $content, 0, strcspn( $content, '${' ) ) . '…',
				'dynamic' => true,
			);
		}

		if ( $concatenated ) {
			return array(
				'hook'    => $content . '…',
				'dynamic' => true,
			);
		}

		return array(
			'hook'    => $content,
			'dynamic' => false,
		);
	}
}
