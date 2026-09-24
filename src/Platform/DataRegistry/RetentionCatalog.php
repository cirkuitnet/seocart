<?php
/**
 * RetentionCatalog: the retention policies a plugin table may name, with their default periods
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\DataRegistry;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A declaration error is a message for the developer's test run, never HTML; this class may not call WordPress.

/**
 * Every retention policy a table can declare, and how long each keeps rows by default.
 *
 * Owns one fact: which retention policies exist, what each one does and its default periods.
 * A table names one of these ids in its declaration, and the data registry refuses an id that
 * is not here. The retention settings, the sweeps that delete or anonymize old rows and the
 * `doctor` checks take the periods from here; none of them states a period again.
 *
 * A policy either sweeps by age or it never does. One that sweeps names, for each kind of row it
 * treats differently, a default period written as an ISO 8601 duration (`P30D`, `PT24H`, `P7Y`).
 * One that never sweeps has no period: `permanent` rows stay until the store's data is deleted,
 * `entity_lifetime` rows go with the entity they belong to, and `rebuildable` rows are
 * truncated and rebuilt. Every period is the value a new store starts with; a merchant may
 * change it later.
 *
 * The catalog holds exactly the policies the plugin's tables use, and the one its job queue
 * uses for the plugin's own jobs, and grows with them.
 * Declarations are data: building and reading it does no I/O and calls no WordPress function.
 *
 * @since 0.1.0
 */
final class RetentionCatalog {

	/**
	 * The policy of rows that are never removed by age.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PERMANENT = 'permanent';

	/**
	 * Every policy: what it does, and its default periods by the kind of row they apply to.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{rule: string, defaults: array<string, string>}>
	 */
	private const POLICIES = array(
		self::PERMANENT    => array(
			'rule'     => 'Never removed by age: the rows stay until the store\'s data is deleted. A table with a created_at column says in its purpose why it keeps its rows.',
			'defaults' => array(),
		),
		'entity_lifetime'  => array(
			'rule'     => 'Kept as long as the entity the row records or belongs to, and removed with it by the service that deletes that entity; never swept by age.',
			'defaults' => array(),
		),
		'rebuildable'      => array(
			'rule'     => 'Derived rows, truncated and rebuilt from their sources, never swept by age: a row is worth its freshness, not its age.',
			'defaults' => array(),
		),
		'logs'             => array(
			'rule'     => 'Log lines are deleted once they are older than the period.',
			'defaults' => array( 'all' => 'P30D' ),
		),
		'outbox'           => array(
			'rule'     => 'A dispatched event is deleted the first period after its dispatch, a failed one the second period after it failed; a pending event is never swept.',
			'defaults' => array(
				'dispatched' => 'P7D',
				'failed'     => 'P90D',
			),
		),
		'job_history'      => array(
			'rule'     => 'A background job of the plugin\'s own group that completed or was cancelled is deleted the first period after its last run, a failed one the second period after it failed; a waiting or running job is never swept, and neither is another plugin\'s.',
			'defaults' => array(
				'finished' => 'P7D',
				'failed'   => 'P90D',
			),
		),
		'stock_holds'      => array(
			'rule'     => 'A checkout hold is deleted the period after it expires. No decision waits for the sweep: an expired hold is already ignored when stock is read.',
			'defaults' => array( 'expired' => 'PT24H' ),
		),
		'carts'            => array(
			'rule'     => 'A cart expires the period after its last change, one period for a guest and another for a logged-in customer; the expired cart, its lines and its checkout session are then deleted.',
			'defaults' => array(
				'guest'     => 'P7D',
				'logged_in' => 'P30D',
			),
		),
		'idempotency_keys' => array(
			'rule'     => 'An idempotency key expires the period after it is claimed and is then deleted; an expired key is already ignored when read.',
			'defaults' => array( 'all' => 'P30D' ),
		),
		'webhook_receipts' => array(
			'rule'     => 'A webhook receipt is deleted the period after it was received; until then a second delivery of the same event is recognized as a duplicate.',
			'defaults' => array( 'all' => 'P30D' ),
		),
		'financial'        => array(
			'rule'     => 'An order and its children, its payments, refunds and promotion usage are kept for the period, then anonymized, never deleted.',
			'defaults' => array( 'all' => 'P7Y' ),
		),
	);

	/**
	 * Returns every policy id.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The ids, `permanent` first.
	 */
	public function ids(): array {
		return array_keys( self::POLICIES );
	}

	/**
	 * Tells whether a policy exists.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id A policy id, as a table declares it.
	 * @return bool True when the catalog declares it.
	 */
	public function has( string $id ): bool {
		return isset( self::POLICIES[ $id ] );
	}

	/**
	 * Returns a policy's default periods.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the catalog does not declare the policy.
	 *
	 * @param string $id A policy id.
	 * @return array<string, string> ISO 8601 durations keyed by the kind of row each applies to, `all` when
	 *                               there is one; empty for a policy that never sweeps by age.
	 */
	public function defaults( string $id ): array {
		if ( ! $this->has( $id ) ) {
			throw new \InvalidArgumentException( sprintf( 'No retention policy is called "%s".', $id ) );
		}

		return self::POLICIES[ $id ]['defaults'];
	}
}
