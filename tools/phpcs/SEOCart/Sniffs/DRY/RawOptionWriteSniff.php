<?php
/**
 * Sniff: plugin settings are written through the settings registry only
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\phpcs\SEOCart\Sniffs\DRY;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use SEOCart\Tools\phpcs\SEOCart\Helpers\PathScope;

/**
 * A syntax tripwire for obvious raw option calls outside `$allowedPaths`.
 *
 * The typed settings registry declares every setting once: its name, type, default,
 * sanitizer and autoload flag. A direct `update_option()` restates the option name and
 * bypasses the rest, so adding, changing and deleting an option, and rewriting its
 * autoload flag, is reserved for the registry's own module. Reads are not restricted.
 *
 * Out of reach, because the sniff goes by the name at the call: a function named as a
 * callback string, and one imported under another name (`use function update_option as save;`).
 *
 * @since 0.1.0
 */
final class RawOptionWriteSniff implements Sniff {

	/**
	 * The WordPress functions that add, change or delete an option, or rewrite its autoload flag.
	 *
	 * @since 0.1.0
	 * @var array<string, true>
	 */
	private const FUNCTIONS = array(
		'add_option'                    => true,
		'update_option'                 => true,
		'delete_option'                 => true,
		'add_site_option'               => true,
		'update_site_option'            => true,
		'delete_site_option'            => true,
		'add_network_option'            => true,
		'update_network_option'         => true,
		'delete_network_option'         => true,
		'add_blog_option'               => true,
		'update_blog_option'            => true,
		'delete_blog_option'            => true,
		'wp_set_option_autoload'        => true,
		'wp_set_options_autoload'       => true,
		'wp_set_option_autoload_values' => true,
	);

	/**
	 * Tokens that, placed before a name, make it something other than a call to a global function.
	 *
	 * @since 0.1.0
	 * @var array<int|string, true>
	 */
	private const NOT_A_GLOBAL_CALL = array(
		T_OBJECT_OPERATOR          => true,
		T_NULLSAFE_OBJECT_OPERATOR => true,
		T_DOUBLE_COLON             => true,
		T_FUNCTION                 => true,
		T_NEW                      => true,
	);

	/**
	 * The shipped code this rule governs. Set once for every DRY sniff, in ruleset.xml.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public $shippedPaths = array();

	/**
	 * Directories whose files may write options directly. Set once, in ruleset.xml.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public $allowedPaths = array();

	/**
	 * Returns the tokens this sniff listens for.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return array( T_STRING );
	}

	/**
	 * Reports a call to one of the option-writing functions.
	 *
	 * @since 0.1.0
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the name token.
	 * @return int|void The position to resume at, when the rest of the file can be skipped.
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		if ( ! PathScope::isUnder( $phpcsFile, $this->shippedPaths ) || PathScope::isUnder( $phpcsFile, $this->allowedPaths ) ) {
			return $phpcsFile->numTokens;
		}

		$tokens   = $phpcsFile->getTokens();
		$function = strtolower( $tokens[ $stackPtr ]['content'] );

		if ( ! isset( self::FUNCTIONS[ $function ] ) ) {
			return;
		}

		$next = $phpcsFile->findNext( Tokens::$emptyTokens, $stackPtr + 1, null, true );

		if ( false === $next || T_OPEN_PARENTHESIS !== $tokens[ $next ]['code'] ) {
			return;
		}

		$prev = $phpcsFile->findPrevious( Tokens::$emptyTokens, $stackPtr - 1, null, true );

		if ( false !== $prev && isset( self::NOT_A_GLOBAL_CALL[ $tokens[ $prev ]['code'] ] ) ) {
			return;
		}

		// `\update_option()` is the global function; `Vendor\update_option()` is somebody else's.
		if ( false !== $prev && T_NS_SEPARATOR === $tokens[ $prev ]['code'] ) {
			$qualifier = $phpcsFile->findPrevious( Tokens::$emptyTokens, $prev - 1, null, true );

			if ( false !== $qualifier && in_array( $tokens[ $qualifier ]['code'], array( T_STRING, T_NAMESPACE ), true ) ) {
				return;
			}
		}

		$phpcsFile->addError(
			'Raw %s() call. A plugin setting is declared once in the settings registry and written through it. Options may be written directly only under: %s',
			$stackPtr,
			'Found',
			array( $function, PathScope::describe( $this->allowedPaths ) )
		);
	}
}
