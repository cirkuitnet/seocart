<?php
/**
 * Logger: the plugin's log, as an extension writes to it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a line to the plugin's log, at one of four levels.
 *
 * Owns one fact: how an extension logs. A line is a code of two lower-case words joined by a dot,
 * such as `stripe.decline`, a message, and a context. Everything is redacted before it is written:
 * a context key that names a credential or personal data is dropped or masked, and a card-shaped
 * number is removed wherever it appears. Never pass a provider's response or a credential.
 *
 * @since 0.2.0
 *
 * @api
 */
interface Logger {

	/**
	 * Writes a line for a developer tracing a problem; kept only where WP_DEBUG is on.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $code    The line's code, `{word}.{word}`.
	 * @param string       $message What happened, in English.
	 * @param array<mixed> $context Optional. What the extension knows about it. Default none.
	 */
	public function debug( string $code, string $message, array $context = array() ): void;

	/**
	 * Writes a line about something that happened as expected.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $code    The line's code, `{word}.{word}`.
	 * @param string       $message What happened, in English.
	 * @param array<mixed> $context Optional. What the extension knows about it. Default none.
	 */
	public function info( string $code, string $message, array $context = array() ): void;

	/**
	 * Writes a line about something unexpected the work carried on after.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $code    The line's code, `{word}.{word}`.
	 * @param string       $message What happened, in English.
	 * @param array<mixed> $context Optional. What the extension knows about it. Default none.
	 */
	public function warning( string $code, string $message, array $context = array() ): void;

	/**
	 * Writes a line about a failure.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $code    The line's code, `{word}.{word}`.
	 * @param string       $message What happened, in English.
	 * @param array<mixed> $context Optional. What the extension knows about it. Default none.
	 */
	public function error( string $code, string $message, array $context = array() ): void;
}
