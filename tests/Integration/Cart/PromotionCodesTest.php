<?php
/**
 * Tests applying promotion codes to a cart and removing them, and the caps on codes tried, against real tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Application\PromotionCodeLimits;
use SEOCart\Cart\Application\StoreApiError;
use SEOCart\Cart\Domain\Cart;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Cart\Infrastructure\MysqlCartRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\RateLimiter\ClientIdentity;
use SEOCart\Platform\RateLimiter\RateCountersTable;
use SEOCart\Platform\RateLimiter\RateLimit;
use SEOCart\Platform\RateLimiter\TableRateLimiter;
use SEOCart\Pricing\Application\CalculationRequest;
use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Promotion\Application\PromotionError;
use SEOCart\Promotion\Domain\Promotion;
use SEOCart\Promotion\Infrastructure\MysqlPromotionRepository;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Cart\CartTestCase;

/**
 * A code whose promotion applies joins the cart and takes its discount off the totals; a refused
 * code changes nothing and gets one answer whatever the reason; every code tried is counted against
 * its cart and its client, until each is closed to every code for the hour; a cart holds at most
 * five codes, and a code not written as a code is refused like any other; a token that names no
 * cart starts no counter; and a list of codes read before another change is never written over
 * that change.
 *
 * The shirt costs 10.00 net and the stub tax rate is 20 %. Promotions without a window apply at any
 * instant; the windows that are planted are far in the past or far in the future, so no test reads
 * the clock.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class PromotionCodesTest extends CartTestCase {

	/**
	 * Tests that a code joins the cart in the compare-and-swap and takes its discount off, that applying it again leaves the list as it is, and that removing it gives the discount back.
	 *
	 * A code is trimmed and upper-cased as it arrives. Every accepted write moves the version on and
	 * issues the cart's token again, as every cart write does.
	 *
	 * @since 0.1.0
	 */
	public function test_a_code_joins_the_cart_and_takes_its_discount_off(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );
		$this->plantPromotion( 'SAVE10' );

		$cart   = $this->startCart( array( $shirt => 2 ) );
		$issued = count( $this->tokens->issued );

		$applied = $this->service->applyCode(
			array(
				'code'         => ' save10 ',
				'cart_version' => 1,
			),
			self::guest()
		);

		$this->assertSame( array( 2, array( array( 'code' => 'SAVE10' ) ) ), array( $applied['version'], $applied['promotion_codes'] ) );
		$this->assertSame( array( -200, 2160 ), array( $applied['totals']['summary']['discount_total_minor'], $applied['totals']['summary']['grand_minor'] ), '10 % off two shirts at 10.00: 18.00 and 3.60 tax.' );
		$this->assertContains( 'promotion:' . $this->uuidOf( 'SAVE10' ), array_column( $applied['totals']['adjustments'], 'source' ) );
		$this->assertSame( '["SAVE10"]', $this->storedCodes( $cart->id ) );
		$this->assertCount( $issued + 1, $this->tokens->issued, 'The write did not issue the cart\'s token again.' );

		$again = $this->service->applyCode(
			array(
				'code'         => 'SAVE10',
				'cart_version' => 2,
			),
			self::guest()
		);

		$this->assertSame( array( 3, array( array( 'code' => 'SAVE10' ) ), -200 ), array( $again['version'], $again['promotion_codes'], $again['totals']['summary']['discount_total_minor'] ), 'A code applied twice is listed, and taken off, once.' );

		$removed = $this->service->removeCode(
			array(
				'code'         => 'save10',
				'cart_version' => 3,
			),
			self::guest()
		);

		$this->assertSame( array( 4, array(), 0, 2400 ), array( $removed['version'], $removed['promotion_codes'], $removed['totals']['summary']['discount_total_minor'], $removed['totals']['summary']['grand_minor'] ) );
		$this->assertSame( '[]', $this->storedCodes( $cart->id ) );

		$absent = $this->service->removeCode(
			array(
				'code'         => 'NEVER',
				'cart_version' => 4,
			),
			self::guest()
		);

		$this->assertSame( array( 5, array() ), array( $absent['version'], $absent['promotion_codes'] ), 'Removing a code the cart does not hold leaves its codes as they are.' );
	}

	/**
	 * Tests that a code that cannot be applied changes nothing and gets one answer, whatever the reason, and that each refusal is counted against the cart.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refused_code_changes_nothing_and_gets_one_answer_whatever_the_reason(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );
		$this->plantPromotion( 'GONE', array( 'ends_at' => '2001-01-01 00:00:00' ) );
		$this->plantPromotion( 'SOON', array( 'starts_at' => '2999-01-01 00:00:00' ) );
		$this->plantPromotion( 'DRAFT', array( 'status' => 'draft' ) );
		$this->plantPromotion(
			'POUNDS',
			array(
				'effect_kind'                 => 'fixed',
				'effect_percent_micropercent' => null,
				'effect_amount_minor'         => 500,
				'effect_currency'             => 'GBP',
				'effect_amount_basis'         => 'net',
			)
		);
		$this->plantUsage(
			$this->plantPromotion(
				'ONCE',
				array(
					'usage_limit' => 1,
					'used'        => 1,
				)
			),
			1,
			'committed'
		);

		$cart    = $this->startCart( array( $shirt => 1 ) );
		$answers = array();

		foreach ( array( 'NOPE', 'GONE', 'SOON', 'DRAFT', 'ONCE', 'POUNDS' ) as $code ) {
			$answers[ $code ] = $this->refusal(
				fn() => $this->service->applyPromotionCode( $code, 1, self::guest() )
			);
		}

		$this->assertSame( array( PromotionError::CodeInvalid, array(), array() ), $answers['NOPE'] );
		$this->assertSame( array_fill_keys( array_keys( $answers ), $answers['NOPE'] ), $answers, 'Unknown, ended, not started, not active, used up or in another currency: one answer.' );
		$this->assertSame( 1, $this->committedCart( $this->secondConnection(), $cart->id )['version'] ?? null );
		$this->assertSame( '[]', $this->storedCodes( $cart->id ) );
		$this->assertSame( 6, $this->counted( PromotionCodeLimits::perCart(), $this->identities->ofCart( $this->presentedToken() ) ) );
		$this->assertSame( 6, $this->counted( PromotionCodeLimits::perClient(), $this->identities->of( 0 ) ) );
	}

	/**
	 * Tests that a code whose promotion is used up after it joined the cart stays listed and takes nothing off, and that the calculation traces it as used up.
	 *
	 * An order takes the promotion's one use, as placement's claim would leave it: the count, and a
	 * committed usage row. The cart still lists the code, so the shopper sees it and may remove it.
	 *
	 * Planted violation: in MysqlPromotionRepository::promotion(), read the limit as null: the
	 * used-up code then still takes 10 % off, and this test fails.
	 *
	 * @since 0.1.0
	 */
	public function test_a_code_used_up_on_a_cart_stays_listed_and_takes_nothing_off(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );

		$once = $this->plantPromotion( 'ONCE', array( 'usage_limit' => 1 ) );

		$this->startCart( array( $shirt => 1 ) );

		$applied = $this->service->applyCode( $this->codeInput( 'ONCE', 1 ), self::guest() );

		$this->assertSame( -100, $applied['totals']['summary']['discount_total_minor'], 'The code took nothing off while its promotion had a use left.' );

		$this->plantUsage( $once, 1, 'committed' );
		$this->setUsed( $once, 1 );

		$read = $this->service->getCart( array(), self::guest() );

		$this->assertSame( array( array( 'code' => 'ONCE' ) ), $read['promotion_codes'] );
		$this->assertSame( array( 0, 1200 ), array( $read['totals']['summary']['discount_total_minor'], $read['totals']['summary']['grand_minor'] ), 'A used-up code took something off.' );

		$trace    = self::calculatorOver( $this->db )->calculate( new CalculationRequest( Currency::of( self::CURRENCY ), array( new LineRequest( 'shirt', $shirt, 1 ) ), null, array( 'ONCE' ) ) )->totals->trace;
		$rejected = array_values( array_filter( $trace->entries, static fn( TraceEntry $entry ): bool => 'rejected_code' === ( $entry->data['record'] ?? null ) ) );

		$this->assertSame( array( array( 'ONCE', Promotion::USED_UP ) ), array_map( static fn( TraceEntry $entry ): array => array( $entry->data['code'] ?? null, $entry->data['reason'] ?? null ), $rejected ) );
	}

	/**
	 * Tests that after ten codes tried a cart is closed to every code, a valid one too, while the client's other carts are not.
	 *
	 * Planted violation: in CartService::countCodeTried(), compare the count with `>=` instead of
	 * `>`: the tenth code is then refused for the cap instead of being looked at.
	 *
	 * @since 0.1.0
	 */
	public function test_ten_refused_codes_close_the_cart_to_every_code(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );
		$this->plantPromotion( 'GOOD' );

		$closed = $this->startCart( array( $shirt => 1 ) );

		$this->refuseCodes( PromotionCodeLimits::CART_LIMIT );

		$this->assertSame( array( StoreApiError::RateLimited, array(), array() ), $this->refusal( fn() => $this->service->applyPromotionCode( 'GOOD', 1, self::guest() ) ), 'A valid code was looked at on a cart past its cap.' );
		$this->assertSame( '[]', $this->storedCodes( $closed->id ) );
		$this->assertSame( PromotionCodeLimits::CART_LIMIT + 1, $this->counted( PromotionCodeLimits::perCart(), $this->identities->ofCart( $this->presentedToken() ) ), 'The code refused for the cap was not counted as a code tried.' );

		$open = $this->startCart( array( $shirt => 1 ) );

		$this->assertSame( array( array( 'code' => 'GOOD' ) ), $this->service->applyCode( $this->codeInput( 'GOOD', 1 ), self::guest() )['promotion_codes'], 'The cap of one cart closed another.' );
		$this->assertNotSame( $closed->id, $open->id );
	}

	/**
	 * Tests that after forty codes tried across its carts a client is closed to every code, while another client is not.
	 *
	 * Planted violation: in CartService::countCodeTried(), leave out the client's counter: the
	 * client's fifth cart then applies the valid code.
	 *
	 * @since 0.1.0
	 */
	public function test_forty_refused_codes_close_the_client_to_every_code(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );
		$this->plantPromotion( 'GOOD' );

		for ( $cart = 0; $cart < PromotionCodeLimits::CLIENT_LIMIT / PromotionCodeLimits::CART_LIMIT; $cart++ ) {
			$this->startCart( array( $shirt => 1 ) );
			$this->refuseCodes( PromotionCodeLimits::CART_LIMIT );
		}

		$fifth = $this->startCart( array( $shirt => 1 ) );

		$this->assertSame( array( StoreApiError::RateLimited, array(), array() ), $this->refusal( fn() => $this->service->applyPromotionCode( 'GOOD', 1, self::guest() ) ), 'A client past its cap had a code looked at on a fresh cart.' );
		$this->assertSame( '[]', $this->storedCodes( $fifth->id ) );

		$another = $this->service->applyPromotionCode( 'GOOD', 1, Actor::user( 7 ) );

		$this->assertSame( array( 'GOOD' ), $another->promotionCodes, 'The cap of one client closed another.' );
	}

	/**
	 * Tests that a token that names no cart is refused before anything is counted, so a made-up token never starts a counter.
	 *
	 * Planted violation: in CartService::applyPromotionCode(), count the code against the token the
	 * request presents before its cart is found
	 * (`$this->limiter->hit( PromotionCodeLimits::CART_BUCKET, $this->identities->ofCart( $this->tokens->presented() ), PromotionCodeLimits::WINDOW_SECONDS )`
	 * first): the made-up token then leaves a counter row.
	 *
	 * @since 0.1.0
	 */
	public function test_a_token_that_names_no_cart_never_starts_a_counter(): void {
		$this->plantPromotion( 'GOOD' );

		foreach ( array( 'NOPE', 'GOOD' ) as $code ) {
			$this->tokens->presented = CartToken::generate();

			$this->assertSame( array( CartError::NotFound, array(), array() ), $this->refusal( fn() => $this->service->applyPromotionCode( $code, 1, self::guest() ) ) );
		}

		$this->assertSame( 0, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( RateCountersTable::NAME ) ), 'A token that names no cart started a counter.' );
	}

	/**
	 * Tests that a list of codes read before another change of the cart is never written over that change.
	 *
	 * A applies a code to a cart at version 2 that lists code ONE. After A has read the cart, B
	 * removes ONE with the repository's own statement, moving the cart to version 3. A then sends
	 * version 3, which it never read. A is refused as stale, and the cart keeps B's list: ONE is not
	 * brought back.
	 *
	 * Planted violation: in CartService::changeCodes(), leave out the check that the write is based
	 * on the version the list was read at: A's compare-and-swap at version 3 then goes through, and
	 * writes ONE back with TWO.
	 *
	 * @since 0.1.0
	 */
	public function test_a_list_read_before_another_change_is_never_written_over_it(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );
		$this->plantPromotion( 'ONE' );
		$this->plantPromotion( 'TWO' );

		$cart = $this->startCart( array( $shirt => 1 ) );

		$this->service->applyPromotionCode( 'ONE', 1, self::guest() );

		$b     = $this->secondConnection();
		$raced = $this->beforeStatement(
			self::shapeOf( MysqlPromotionRepository::FIND_BY_CODES . '( %s )' ),
			function () use ( $b, $cart ): void {
				$b->query( $this->raw( MysqlCartRepository::SWAP_PROMOTION_CODES, $this->table( CartTables::CARTS ), '[]', self::TTL_SECONDS, $cart->id, 2 ) );
			}
		);

		$refused = $this->refusal( fn() => $this->service->applyPromotionCode( 'TWO', 3, self::guest() ) );

		$this->assertTrue( $raced->fired, 'B never changed the cart.' );
		$this->assertSame( array( CartError::VersionStale, array( 'current_version' => 3 ) ), array_slice( $refused, 0, 2 ) );
		$this->assertSame( '[]', $this->storedCodes( $cart->id ), 'A wrote the list it read over the one B left.' );
		$this->assertSame( 3, $this->committedCart( $b, $cart->id )['version'] ?? null );
	}

	/**
	 * Tests that a cart holds at most five codes: a sixth is refused before it is counted or looked at, while a code it holds is applied again, and a code it lost makes room.
	 *
	 * Planted violation: in CartService::applyPromotionCode(), leave out the check of the cart's
	 * codes: the sixth code then joins the cart, and this test fails.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cart_holds_at_most_five_codes(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );

		$codes = array();

		for ( $code = 1; $code <= Cart::MAX_CODES + 1; $code++ ) {
			$codes[] = 'FIVEOFF' . $code;

			$this->plantPromotion( 'FIVEOFF' . $code, array( 'effect_percent_micropercent' => 5000000 ) );
		}

		$cart = $this->startCart( array( $shirt => 1 ) );

		foreach ( array_slice( $codes, 0, Cart::MAX_CODES ) as $index => $code ) {
			$this->service->applyPromotionCode( $code, $index + 1, self::guest() );
		}

		$tried = $this->counted( PromotionCodeLimits::perCart(), $this->identities->ofCart( $this->presentedToken() ) );
		$sixth = $this->captureQueries( fn() => $this->assertSame( array( CartError::TooManyCodes, array( 'max_codes' => Cart::MAX_CODES ), array() ), $this->refusal( fn() => $this->service->applyPromotionCode( $codes[ Cart::MAX_CODES ], Cart::MAX_CODES + 1, self::guest() ) ) ) );

		$this->assertQueryCount( 0, $sixth->forTable( $this->table( PromotionTables::PROMOTIONS ) ), 'Reads of the promotions for a code a full cart refuses' );
		$this->assertSame( $tried, $this->counted( PromotionCodeLimits::perCart(), $this->identities->ofCart( $this->presentedToken() ) ), 'A code a full cart refused was counted.' );
		$this->assertSame( (string) wp_json_encode( array_slice( $codes, 0, Cart::MAX_CODES ) ), $this->storedCodes( $cart->id ) );

		$again = $this->service->applyPromotionCode( $codes[2], Cart::MAX_CODES + 1, self::guest() );

		$this->assertSame( array( Cart::MAX_CODES + 2, array_slice( $codes, 0, Cart::MAX_CODES ) ), array( $again->version, $again->promotionCodes ), 'A code a full cart holds was not applied again.' );

		$this->service->removePromotionCode( $codes[0], Cart::MAX_CODES + 2, self::guest() );

		$last = $this->service->applyPromotionCode( $codes[ Cart::MAX_CODES ], Cart::MAX_CODES + 3, self::guest() );

		$this->assertSame( array_slice( $codes, 1 ), $last->promotionCodes, 'Removing a code made no room for another.' );
	}

	/**
	 * Tests that a code with a character no code has is refused like any other code, counted, and never looked up, even when a promotion was stored with it.
	 *
	 * Each code below was stored with a promotion, except the one longer than the column allows, so
	 * without the check each would apply: a slash, which no path segment carries, so the code could
	 * never be removed; a space inside the code; a letter outside A to Z, which upper-casing leaves as
	 * it is.
	 *
	 * Planted violation: in CartService::applyPromotionCode(), leave out the check of the code's
	 * characters: the stored codes then apply, and this test fails.
	 *
	 * @since 0.1.0
	 */
	public function test_a_code_outside_the_alphabet_is_refused_like_any_other_and_never_looked_up(): void {
		$shirt   = self::variant();
		$outside = array( 'SAVE/10', 'SAVE 10', "\u{00C9}T\u{00C9}10", str_repeat( 'A', 65 ) );

		$this->price( array( $shirt => 1000 ) );

		foreach ( array_slice( $outside, 0, 3 ) as $code ) {
			$this->plantPromotion( $code );
		}

		$cart    = $this->startCart( array( $shirt => 1 ) );
		$answers = array();
		$tried   = $this->captureQueries(
			function () use ( $outside, &$answers ): void {
				foreach ( $outside as $code ) {
					$answers[ $code ] = $this->refusal( fn() => $this->service->applyPromotionCode( $code, 1, self::guest() ) );
				}
			}
		);

		$this->assertSame( array_fill_keys( $outside, array( PromotionError::CodeInvalid, array(), array() ) ), $answers, 'A code outside the alphabet was not refused as any other code is.' );
		$this->assertQueryCount( 0, $tried->forTable( $this->table( PromotionTables::PROMOTIONS ) ), 'Reads of the promotions for codes outside the alphabet' );
		$this->assertSame( count( $outside ), $this->counted( PromotionCodeLimits::perCart(), $this->identities->ofCart( $this->presentedToken() ) ) );
		$this->assertSame( '[]', $this->storedCodes( $cart->id ) );
		$this->assertFalse( Promotion::isCode( 'save10' ), 'A code is held to the alphabet after it is upper-cased.' );
		$this->assertTrue( Promotion::isCode( 'AZ09_-' ) );
	}

	/**
	 * Tests that a write of codes based on an old version is refused as stale, with the cart's current totals, and changes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_stale_write_of_codes_is_refused_with_the_current_totals(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );
		$this->plantPromotion( 'SAVE10' );

		$cart = $this->startCart( array( $shirt => 1 ) );

		$this->service->applyPromotionCode( 'SAVE10', 1, self::guest() );

		foreach ( array(
			'apply'  => fn() => $this->service->applyPromotionCode( 'SAVE10', 1, self::guest() ),
			'remove' => fn() => $this->service->removePromotionCode( 'SAVE10', 1, self::guest() ),
		) as $write => $stale ) {
			$refused = $this->refusal( $stale );

			$this->assertSame( array( CartError::VersionStale, array( 'current_version' => 2 ) ), array_slice( $refused, 0, 2 ), $write );
			$this->assertSame( -100, $refused[2]['totals']['summary']['discount_total_minor'] ?? null, $write . ': the refusal carries the totals of the cart as it is, its code applied.' );
		}

		$this->assertSame( '["SAVE10"]', $this->storedCodes( $cart->id ) );
	}

	/**
	 * Tests what codes cost: a cart's promotions are one read however many codes it holds, and applying a code is a fixed number of statements.
	 *
	 * Reading a cart is its row, its lines and its checkout session, which gives the totals their
	 * destination; the calculation reads the lines' prices, and, when the cart holds codes, their
	 * promotions, once. Applying a code reads the cart's row, counts the
	 * code in its two counters, reads the code's promotion, then swaps the list and reads the lines back in
	 * a transaction (its start, savepoint, release and commit are four statements more), and prices
	 * the answer.
	 *
	 * @since 0.1.0
	 */
	public function test_codes_cost_one_promotion_read_however_many(): void {
		$shirt = self::variant();
		$mug   = self::variant();
		$hat   = self::variant();

		$this->price(
			array(
				$shirt => 1000,
				$mug   => 500,
				$hat   => 700,
			)
		);

		foreach ( array( 'ONE', 'TWO', 'THREE' ) as $code ) {
			$this->plantPromotion( $code );
		}

		$this->startCart(
			array(
				$shirt => 1,
				$mug   => 2,
				$hat   => 3,
			)
		);

		$plain = $this->captureQueries( fn() => $this->service->getCart( array(), self::guest() ) );

		$this->service->applyPromotionCode( 'ONE', 1, self::guest() );
		$this->service->applyPromotionCode( 'TWO', 2, self::guest() );

		$applied = $this->captureQueries( fn() => $this->service->applyCode( $this->codeInput( 'THREE', 3 ), self::guest() ) );
		$coded   = $this->captureQueries( fn() => $this->service->getCart( array(), self::guest() ) );

		$this->assertQueryCount( 4, $plain, 'Reading a cart without codes: its row, its lines, its checkout session and their prices' );
		$this->assertQueryCount( 5, $coded, 'Reading a cart with three codes: its row, its lines, its checkout session, their prices and the promotions' );
		$this->assertQueryCount( 1, $coded->forTable( $this->table( PromotionTables::PROMOTIONS ) ), 'Reads of the promotions for three codes' );
		$this->assertQueryCount( 13, $applied, 'Applying a code: the cart, two counters, the promotion; the swap and the lines, in a transaction of four control statements; the answer\'s checkout session, prices and promotions' );
		$this->assertQueryCount( 2, $applied->forTable( $this->table( PromotionTables::PROMOTIONS ) ), 'Reads of the promotions when a code is applied: the code, then the cart\'s codes for its totals' );
	}

	/**
	 * Has the cart the test presents refuse codes no promotion has, each counted.
	 *
	 * @since 0.1.0
	 *
	 * @param int $count How many.
	 */
	private function refuseCodes( int $count ): void {
		for ( $code = 1; $code <= $count; $code++ ) {
			$this->assertSame( PromotionError::CodeInvalid, $this->refusal( fn() => $this->service->applyPromotionCode( 'NOPE' . $code, 1, self::guest() ) )[0] );
		}
	}

	/**
	 * Returns the token the test presents.
	 *
	 * @since 0.1.0
	 *
	 * @return CartToken The token.
	 */
	private function presentedToken(): CartToken {
		$this->assertNotNull( $this->tokens->presented, 'The test presents no token.' );

		return $this->tokens->presented;
	}

	/**
	 * Runs a write that must be refused, and returns the refusal.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $write The write.
	 * @return array{0: mixed, 1: array<string, mixed>, 2: array<string, mixed>} The refusal's code, context and details.
	 */
	private function refusal( callable $write ): array {
		try {
			$write();
		} catch ( CodedException $refused ) {
			return array( $refused->errorCode(), $refused->context(), $refused->details() );
		}

		$this->fail( 'The write was not refused.' );
	}

	/**
	 * Returns the input of a write of one code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code    The code.
	 * @param int    $version The cart version.
	 * @return array{code: string, cart_version: int} The input.
	 */
	private function codeInput( string $code, int $version ): array {
		return array(
			'code'         => $code,
			'cart_version' => $version,
		);
	}

	/**
	 * Returns how many requests a counter holds in its current window.
	 *
	 * @since 0.1.0
	 *
	 * @param RateLimit      $limit    The cap whose counter it is.
	 * @param ClientIdentity $identity Whom it counts.
	 * @return int The count.
	 */
	private function counted( RateLimit $limit, ClientIdentity $identity ): int {
		return ( new TableRateLimiter( $this->db ) )->peek( $limit->bucket(), $identity, $limit->windowSeconds() );
	}

	/**
	 * Returns a cart's stored list of promotion codes, as committed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId The cart.
	 * @return string The column's JSON.
	 */
	private function storedCodes( int $cartId ): string {
		return (string) $this->secondConnection()->fetchValue( sprintf( 'SELECT promotion_codes FROM `%s` WHERE id = %d', $this->table( CartTables::CARTS ), $cartId ) );
	}

	/**
	 * Returns a planted promotion's uuid.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code Its code.
	 * @return string The uuid.
	 */
	private function uuidOf( string $code ): string {
		return (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE code = %s', $this->table( PromotionTables::PROMOTIONS ), $code );
	}
}
