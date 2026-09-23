<?php
/**
 * AbilitiesReference: generates docs/reference/abilities.md from the operation registry
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Interfaces\Operations\AbilitiesAdapter;
use SEOCart\Support\Error\ErrorTable;

/**
 * Documents every ability: its operation, capability, annotations, agent exposure, error codes,
 * input and output.
 *
 * This generator owns docs/reference/abilities.md whole. Every operation that declares an ability
 * is documented; the others are not abilities, so nothing is skipped. An operation that cannot be
 * built or documented fails the run.
 *
 * @since 0.1.0
 */
final class AbilitiesReference implements Generator {

	/**
	 * The operations.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationRegistry
	 */
	private OperationRegistry $registry;

	/**
	 * The error table, for each declared code's status.
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
	 * @param OperationRegistry $registry The operations.
	 * @param ErrorTable        $errors   The error table.
	 */
	public function __construct( OperationRegistry $registry, ErrorTable $errors ) {
		$this->registry = $registry;
		$this->errors   = $errors;
	}

	/**
	 * Returns the generator's stable identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string The identifier.
	 */
	public function id(): string {
		return 'reference-abilities';
	}

	/**
	 * Returns the file this generator owns.
	 *
	 * @since 0.1.0
	 *
	 * @return string Path relative to the repository root.
	 */
	public function target(): string {
		return 'docs/reference/abilities.md';
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
	 * @throws \RuntimeException When an operation cannot be built or documented.
	 *
	 * @param string $current The current content. Ignored: the file is generated whole.
	 * @return GenerationResult The document. Nothing is skipped.
	 */
	public function generate( string $current ): GenerationResult {
		$lines = array(
			'# Abilities',
			'',
			FieldDocs::generatedNotice( 'the operation registry' ),
			'',
			'SEOCart registers an ability for each operation that declares one, in the ability category `' . AbilitiesAdapter::CATEGORY . '`. An ability runs the same application service as the operation\'s REST route and WP-CLI command, with the same input schema, permission check and error codes. It is exposed to agents and other clients only when its operation allows it, and an operation that is destructive, moves money or reads personal data in bulk never does.',
		);
		$count = 0;

		try {
			foreach ( $this->registry->all() as $definition ) {
				if ( null === $definition->abilityName() ) {
					continue;
				}

				$annotations = array();

				foreach ( $definition->annotations()->toArray() as $name => $value ) {
					$annotations[] = $name . ' `' . ( $value ? 'true' : 'false' ) . '`';
				}

				array_push(
					$lines,
					'',
					'## `' . $definition->abilityName() . '`',
					'',
					$definition->summary(),
					'',
					...FieldDocs::operationFacts( $definition, $this->errors )
				);
				array_push(
					$lines,
					'- Annotations: ' . implode( ', ', $annotations ),
					'- Exposed to agents: ' . ( $definition->isAgentExposed() ? 'yes' : 'no' ),
					'',
					'### Input',
					''
				);

				foreach ( $definition->input() as $field ) {
					$lines[] = '- `' . $field->name() . '`' . ( $field->isRequired() ? ' (required)' : '' ) . ': ' . $field->description() . ' ' . FieldDocs::describe( $field, false );
				}

				array_push( $lines, '', '### Output', '' );

				foreach ( $definition->output()->serializedFields() as $field ) {
					$lines[] = '- `' . $field->name() . '`' . ( $field->isRequired() ? ' (always)' : '' ) . ': ' . $field->description() . ' ' . FieldDocs::describe( $field, true );
				}

				++$count;
			}
		} catch ( \LogicException $exception ) {
			throw new \RuntimeException( $exception->getMessage(), 0, $exception );
		}

		if ( 0 === $count ) {
			array_push( $lines, '', 'SEOCart has no abilities yet.' );
		}

		return new GenerationResult( implode( "\n", $lines ) . "\n" );
	}
}
