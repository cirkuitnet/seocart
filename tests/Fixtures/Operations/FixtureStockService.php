<?php
/**
 * FixtureStockService: the application service behind the test-fixture operation
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Fixtures\Operations;

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\SupportError;

/**
 * Keeps stock levels in memory and records every call, so a test can see what each surface passed.
 *
 * Like every application service, it authorizes the actor it is given before it does anything:
 * the Authorizer checks CAPABILITY, which the operation's declaration names as its capability too,
 * for the actor's user. A refusal raises `authorization.denied`. The input and the actor of every
 * call are recorded first, refused calls included.
 *
 * Every item starts at INITIAL_LEVEL. An adjustment that would take a level below zero fails with
 * the fixture's declared code. The result carries, besides the declared public fields, a
 * personal-data note, a secret token and a value the output schema does not declare, so a test
 * sees each privacy rule applied to a real result.
 *
 * The note FAIL_UNDECLARED makes it raise a coded error the operation does not declare, the way a
 * faulty service would.
 *
 * @since 0.1.0
 */
final class FixtureStockService {

	/**
	 * The level every item starts at.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const INITIAL_LEVEL = 5;

	/**
	 * The capability the service requires; the operation declares the same one.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CAPABILITY = 'seocart_manage_inventory';

	/**
	 * The secret token every result carries, which no surface may serialize.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const AUDIT_TOKEN = 'fixture-audit-token-never-serialized';

	/**
	 * The note that makes the service raise a coded error the operation does not declare.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FAIL_UNDECLARED = 'fail with an undeclared code';

	/**
	 * The stock levels, keyed by item id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int>
	 */
	private array $levels = array();

	/**
	 * The input of every call, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $calls = array();

	/**
	 * The actor of every call, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<Actor>
	 */
	public array $actors = array();

	/**
	 * Checks the actor's capability.
	 *
	 * @since 0.1.0
	 *
	 * @var Authorizer
	 */
	private Authorizer $authorizer;

	/**
	 * Creates the service.
	 *
	 * @since 0.1.0
	 *
	 * @param Authorizer|null $authorizer Optional. Checks the actor's capability. Default the
	 *                                    plugin's Authorizer.
	 */
	public function __construct( ?Authorizer $authorizer = null ) {
		$this->authorizer = $authorizer ?? new Authorizer( new CapabilityDeclaration() );
	}

	/**
	 * Adjusts the level of one item, on the actor's authority.
	 *
	 * Raises `authorization.denied` when the actor does not hold CAPABILITY,
	 * FixtureStockError::Insufficient when the level would drop below zero, and
	 * SupportError::UnknownCurrency, which the operation does not declare, for the note
	 * FAIL_UNDECLARED.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input The input values, keyed by wire name.
	 * @param Actor                $actor Who acts.
	 * @return array<string, mixed> The result, keyed by wire name.
	 */
	public function adjust( array $input, Actor $actor ): array {
		$this->calls[]  = $input;
		$this->actors[] = $actor;

		$this->authorizer->authorize( $actor, self::CAPABILITY );

		if ( self::FAIL_UNDECLARED === ( $input['note'] ?? null ) ) {
			CodedException::raise( SupportError::UnknownCurrency, array( 'currency' => 'XYZ' ) );
		}

		$item  = (string) $input['item_id'];
		$delta = (int) $input['delta'];
		$level = $this->levels[ $item ] ?? self::INITIAL_LEVEL;

		if ( $level + $delta < 0 ) {
			CodedException::raise(
				FixtureStockError::Insufficient,
				array(
					'requested' => -$delta,
					'available' => $level,
				)
			);
		}

		$this->levels[ $item ] = $level + $delta;

		return array(
			'item_id'     => $item,
			'on_hand'     => $this->levels[ $item ],
			'reason'      => $input['reason'],
			'note'        => $input['note'] ?? null,
			'audit_token' => self::AUDIT_TOKEN,
			'undeclared'  => 'a value the output schema does not declare',
		);
	}
}
