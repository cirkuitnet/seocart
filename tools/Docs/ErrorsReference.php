<?php
/**
 * ErrorsReference: generates docs/reference/errors.md from the one error table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Platform\Rest\ErrorShape;
use SEOCart\Support\Error\ErrorTable;

/**
 * Documents the shape of an error and every error code: its HTTP status, whether it is internal,
 * its English message and the values the message holds.
 *
 * This generator owns docs/reference/errors.md whole. The shape is ErrorShape's members, stated
 * once. The message is the English source string of the row's gettext call, with each numbered
 * placeholder shown as `{name}`; an internal row says that a client never receives it, and a row
 * any write may raise says so. Nothing is skipped: a row that cannot be rendered is an error, not
 * an omission.
 *
 * @since 0.1.0
 */
final class ErrorsReference implements Generator {

	/**
	 * The error table to document.
	 *
	 * @since 0.1.0
	 *
	 * @var ErrorTable
	 */
	private ErrorTable $errors;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param ErrorTable $errors The error table, composed from every catalog.
	 */
	public function __construct( ErrorTable $errors ) {
		$this->errors = $errors;
	}

	/**
	 * Returns the generator's stable identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string The identifier.
	 */
	public function id(): string {
		return 'reference-errors';
	}

	/**
	 * Returns the file this generator owns.
	 *
	 * @since 0.1.0
	 *
	 * @return string Path relative to the repository root.
	 */
	public function target(): string {
		return 'docs/reference/errors.md';
	}

	/**
	 * Returns the source items this generator may leave out: none.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Always empty.
	 */
	public function expectedSkips(): array {
		return array();
	}

	/**
	 * Renders the document.
	 *
	 * @since 0.1.0
	 *
	 * @param string $current The current content. Ignored: the file is generated whole.
	 * @return GenerationResult The document. Nothing is skipped.
	 */
	public function generate( string $current ): GenerationResult {
		$lines = array(
			'# Error codes',
			'',
			FieldDocs::generatedNotice( 'the error catalogs under `src/`' ),
			'',
			'Every failure a client can cause is reported with one of these codes, on every surface: the REST API answers with the HTTP status shown, an ability returns the error, and a WP-CLI command prints `<code>: <message>` and the correlation id, and exits with a non-zero status. In a message, `{name}` stands for a value the error fills in.',
			'',
			'## The shape of an error',
			'',
			'The REST API sends an error as `{ code, message, data }`, and an ability returns a `WP_Error` with the same code, message and data. The data has these members on every error of a SEOCart REST route, including WordPress\'s own refusal of a request that does not match the input schema or of a user who lacks the capability, but not its refusal of a JSONP callback, which it sends before any route is matched, and on every error an ability returns once it runs; the Abilities API\'s own refusals before that, such as `ability_invalid_input`, are WordPress\'s:',
			'',
		);

		foreach ( ErrorShape::members() as $name => $description ) {
			$lines[] = '- `' . $name . '`: ' . $description;
		}

		array_push(
			$lines,
			'',
			'An internal error carries a generic message and empty details: it is a code marked internal below, or a failure no code describes, `' . ErrorTranslator::INTERNAL_ERROR . '` with HTTP status ' . ErrorTranslator::INTERNAL_STATUS . '. What went wrong is written to the site\'s error log with the correlation id, which is how the site administrator finds it.'
		);

		foreach ( $this->errors->definitions() as $row ) {
			$placeholders = array();

			foreach ( $row->placeholders() as $name ) {
				$placeholders[ $name ] = '{' . $name . '}';
			}

			array_push(
				$lines,
				'',
				'## `' . $row->code()->value . '`',
				'',
				'- HTTP status: ' . $row->httpStatus()
			);

			if ( $row->isInternal() ) {
				$lines[] = '- Internal: a client receives the code, the status, a generic message and the correlation id. This message and its values go to the site\'s error log only.';
			}

			if ( $row->isAnyWrite() ) {
				$lines[] = '- Any write: every operation that changes the store may answer with this code, whether or not it lists it.';
			}

			array_push(
				$lines,
				'- Message: ' . $row->render( $placeholders ),
				'- Values: ' . ( array() === $placeholders ? 'none' : implode( ', ', array_map( static fn( string $name ): string => '`' . $name . '`', array_keys( $placeholders ) ) ) )
			);
		}

		return new GenerationResult( implode( "\n", $lines ) . "\n" );
	}
}
