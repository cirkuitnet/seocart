<?php
/**
 * StatedDeclaration: the fixture extension's declaration, and declarations that state one thing otherwise, for the tests of the live workflow's pieces
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;

/**
 * A declaration that states what another states, but for the values it is given; and the fixture extension's own.
 *
 * A stated declaration is not loaded from an extension, so it has no repository, slug or root:
 * the tests that need those use fixture() itself.
 *
 * @since 0.2.0
 */
final class StatedDeclaration extends LiveDeclaration {

	/**
	 * The fixture extension's root.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FIXTURE = __DIR__ . '/../../../../Fixtures/LiveExtension';

	/**
	 * The declaration whose values are stated where none is given.
	 *
	 * @since 0.2.0
	 *
	 * @var LiveDeclaration
	 */
	private LiveDeclaration $base;

	/**
	 * The values stated otherwise, by the method that returns each.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, mixed>
	 */
	private array $stated;

	/**
	 * Builds a declaration.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration      $base   The declaration whose values are stated where none is given.
	 * @param array<string, mixed> $stated The values stated otherwise, by method; `createdBy` a callable.
	 */
	private function __construct( LiveDeclaration $base, array $stated ) {
		$this->base   = $base;
		$this->stated = $stated;
	}

	/**
	 * Returns the fixture extension's declaration, loaded from its tests/live.php.
	 *
	 * @since 0.2.0
	 *
	 * @return LiveDeclaration The declaration.
	 */
	public static function fixture(): LiveDeclaration {
		return LiveDeclaration::load( self::FIXTURE );
	}

	/**
	 * Returns the fixture's declaration with some values stated otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $stated The values, by method.
	 * @return self The declaration.
	 */
	public static function with( array $stated ): self {
		return new self( self::fixture(), $stated );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	public function workflow(): string {
		return $this->stated['workflow'] ?? $this->base->workflow();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	public function jobs(): array {
		return $this->stated['jobs'] ?? $this->base->jobs();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	public function liveParts(): array {
		return $this->stated['liveParts'] ?? $this->base->liveParts();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	public function proofs(): array {
		return $this->stated['proofs'] ?? $this->base->proofs();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	public function facts(): array {
		return $this->stated['facts'] ?? $this->base->facts();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	public function suiteTests(): int {
		return $this->stated['suiteTests'] ?? $this->base->suiteTests();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	public function suiteSkips(): array {
		return $this->stated['suiteSkips'] ?? $this->base->suiteSkips();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	public function shapes(): array {
		return $this->stated['shapes'] ?? $this->base->shapes();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>> $exchanges The exchanges, each in a tape's shape.
	 */
	public function createdBy( array $exchanges ): array {
		return isset( $this->stated['createdBy'] ) ? ( $this->stated['createdBy'] )( $exchanges ) : $this->base->createdBy( $exchanges );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	public function optionalPart(): ?array {
		return array_key_exists( 'optionalPart', $this->stated ) ? $this->stated['optionalPart'] : $this->base->optionalPart();
	}
}
