<?php
/**
 * Tests reading a cart and changing its lines, against real tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Application\CartService;
use SEOCart\Cart\Domain\Cart;
use SEOCart\Cart\Domain\CartLine;
use SEOCart\Cart\Domain\CartStatus;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Cart\Domain\LineIdentity;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Cart\CartTestCase;
use SEOCart\Tests\Support\SecondConnection;

/**
 * A cart exists from its first line; every change moves its version on by one behind a
 * compare-and-swap; lines of one identity merge; an expired cart is gone to every read and write
 * before any sweep; and a cart an order was placed from refuses every write.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class CartServiceTest extends CartTestCase {

	/**
	 * Tests that a read without a live cart answers an empty cart at version 0, creates no row and issues no token.
	 *
	 * @since 0.1.0
	 */
	public function test_a_read_without_a_cart_answers_an_empty_cart_and_creates_nothing(): void {
		$this->assertEmptyCart( $this->service->getCart( array(), self::guest() ), 'No token.' );

		$this->tokens->presented = CartToken::generate();

		$this->assertEmptyCart( $this->service->getCart( array(), self::guest() ), 'A token no cart has.' );
		$this->assertSame( 0, $this->rowsOf( CartTables::CARTS ) );
		$this->assertSame( array(), $this->tokens->issued );
	}

	/**
	 * Tests that the first line creates exactly one cart at version 1 with its line, issues its token once, and answers with its totals.
	 *
	 * The variant costs 12.50 net and the stub tax is 20 %: two units come to 25.00 net, 5.00 tax
	 * and 30.00 in all; with no address yet, there is no shipping.
	 *
	 * @since 0.1.0
	 */
	public function test_the_first_line_creates_exactly_one_cart_and_issues_its_token_once(): void {
		$variant = self::variant();

		$this->price( array( $variant => 1250 ) );

		$answer = $this->service->addLines(
			array(
				'lines' => array(
					array(
						'variant_id' => $variant,
						'quantity'   => 2,
					),
				),
			),
			self::guest()
		);

		$this->assertSame( 1, $answer['version'] );
		$this->assertSame(
			array(
				array(
					'line_identity' => hash( 'sha256', 'variant:' . $variant ),
					'variant_id'    => $variant,
					'quantity'      => 2,
				),
			),
			$answer['lines']
		);
		$this->assertSame( array(), $answer['unpriced_lines'] );
		$this->assertSame( array( hash( 'sha256', 'variant:' . $variant ) ), array_column( $answer['totals']['lines'], 'key' ), 'The calculation keys each line by its identity.' );
		$this->assertSame( array( 2500, 500, 3000, 0 ), array( $answer['totals']['summary']['net_minor'], $answer['totals']['summary']['tax_minor'], $answer['totals']['summary']['grand_minor'], $answer['totals']['summary']['shipping_total_minor'] ) );
		$this->assertSame( 1, $this->rowsOf( CartTables::CARTS ) );
		$this->assertSame( 1, $this->rowsOf( CartTables::LINES ) );
		$this->assertCount( 1, $this->tokens->issued );

		$row = $this->db->fetchRow(
			'SELECT token_hash, version, status, order_id, currency, locale, channel, line_count, item_count, promotion_codes, TIMESTAMPDIFF( SECOND, UTC_TIMESTAMP(), expires_at ) AS lives FROM %i',
			$this->table( CartTables::CARTS )
		);

		$this->assertNotNull( $row );
		$this->assertSame( $this->tokens->issued[0]->hash(), $row['token_hash'], 'The cart is stored under its token\'s hash.' );
		$this->assertSame( array( '1', 'open', null, self::CURRENCY, self::LOCALE, 'storefront', '1', '2', '[]' ), array( $row['version'], $row['status'], $row['order_id'], $row['currency'], $row['locale'], $row['channel'], $row['line_count'], $row['item_count'], $row['promotion_codes'] ) );
		$this->assertGreaterThanOrEqual( self::TTL_SECONDS - 60, (int) $row['lives'], 'A new cart lives the guest period of the carts policy.' );
		$this->assertLessThanOrEqual( self::TTL_SECONDS, (int) $row['lives'] );
	}

	/**
	 * Tests that a refused first write creates no cart and issues no token.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refused_first_write_creates_no_cart_and_no_token(): void {
		try {
			$this->service->add( self::lines( array( self::variant() => 1 ) ), 3, self::guest() );
			$this->fail( 'A write based on a version of a cart that does not exist was accepted.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CartError::NotFound, $refused->errorCode() );
		}

		$this->assertSame( 0, $this->rowsOf( CartTables::CARTS ) );
		$this->assertSame( 0, $this->rowsOf( CartTables::LINES ) );
		$this->assertSame( array(), $this->tokens->issued );
	}

	/**
	 * Tests that identical lines merge, within a batch and with the cart's lines, and that other lines are added after them.
	 *
	 * @since 0.1.0
	 */
	public function test_identical_lines_merge_and_other_lines_are_added_in_order(): void {
		$shirt = self::variant();
		$mug   = self::variant();
		$cart  = $this->service->add( array( CartLine::of( $shirt, 1 ), CartLine::of( $shirt, 1 ) ), 0, self::guest() );

		$this->assertSame( array( $shirt . ':2' ), self::summary( $cart ), 'Two identical lines of one batch are one line.' );

		$this->tokens->keepIssued();

		$cart = $this->service->add( array( CartLine::of( $mug, 1 ), CartLine::of( $shirt, 3 ) ), 1, self::guest() );

		$this->assertSame( 2, $cart->version );
		$this->assertSame( array( $shirt . ':5', $mug . ':1' ), self::summary( $cart ), 'The known line gained the units; the new line comes after it.' );
		$this->assertSame( array( $shirt . ':5', $mug . ':1' ), self::summary( $this->current() ) );
		$this->assertCount( 1, $this->tokens->issued, 'Only the write that created the cart issues a token.' );
	}

	/**
	 * Tests that adding to a line fills it to its cap, in the statement, and never beyond.
	 *
	 * @since 0.1.0
	 */
	public function test_adding_to_a_line_fills_it_to_its_cap_at_most(): void {
		$variant = self::variant();

		$this->startCart( array( $variant => CartLine::MAX_QUANTITY - 1 ) );

		$cart = $this->service->add( self::lines( array( $variant => 5 ) ), 1, self::guest() );

		$this->assertSame( array( $variant . ':' . CartLine::MAX_QUANTITY ), self::summary( $cart ) );
	}

	/**
	 * Tests that a write replayed with the version it was first sent with is refused as stale, with the current version, and changes nothing.
	 *
	 * Planted violation: in MysqlCartRepository::OPEN_AT_VERSION, replace `version = %d` with
	 * `%d > 0`: the replay then adds its lines a second time.
	 *
	 * @since 0.1.0
	 */
	public function test_a_replayed_write_is_refused_as_stale_and_changes_nothing(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );

		$cart  = $this->startCart( array( $shirt => 1 ) );
		$batch = self::lines( array( $shirt => 2 ) );

		$this->assertSame( 2, $this->service->add( $batch, 1, self::guest() )->version );

		$writes = array(
			'the replay' => 1,
			'a first write from a client that holds the cart' => 0,
		);

		foreach ( $writes as $write => $version ) {
			try {
				$this->service->add( $batch, $version, self::guest() );
				$this->fail( sprintf( 'Applied %s, based on version %d.', $write, $version ) );
			} catch ( CodedException $stale ) {
				$this->assertSame( CartError::VersionStale, $stale->errorCode(), $write );
				$this->assertSame( array( 'current_version' => 2 ), $stale->context(), $write );
				$this->assertSame( array( 'totals' ), array_keys( $stale->details() ), $write );
				$this->assertSame( 3600, $stale->details()['totals']['summary']['grand_minor'] ?? null, $write . ': the totals are the cart\'s as it is now, three units at 10.00 and 20 % tax.' );
			}
		}

		$current = $this->current();

		$this->assertSame( 2, $current->version );
		$this->assertSame( array( $shirt . ':3' ), self::summary( $current ) );
		$this->assertSame( $cart->id, $current->id );
	}

	/**
	 * Tests that a line with no price is reported beside the totals, left out of them, and never written back by a read.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unpriced_line_is_reported_and_never_written(): void {
		$priced   = self::variant();
		$unpriced = self::variant();

		$this->price( array( $priced => 500 ) );
		$this->startCart(
			array(
				$priced   => 1,
				$unpriced => 3,
			)
		);

		$answer = $this->service->getCart( array(), self::guest() );

		$this->assertSame(
			array(
				array(
					'line_identity' => LineIdentity::of( $unpriced )->value(),
					'variant_id'    => $unpriced,
					'reason'        => 'unknown_variant',
				),
			),
			$answer['unpriced_lines']
		);
		$this->assertSame( array( LineIdentity::of( $priced )->value() ), array_column( $answer['totals']['lines'], 'key' ) );
		$this->assertSame( 600, $answer['totals']['summary']['grand_minor'] );
		$this->assertSame( '0', $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE unavailable_reason IS NOT NULL', $this->table( CartTables::LINES ) ), 'A read wrote an unavailable reason.' );
	}

	/**
	 * Tests that a cart that is placing or converted refuses every write with `cart.not_open`, and keeps its version and lines.
	 *
	 * Planted violation: in MysqlCartRepository::OPEN_AT_VERSION, replace `status = 'open'` with
	 * `'open' = 'open'`: the writes then go through.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cart_an_order_was_placed_from_refuses_every_write(): void {
		foreach ( array( CartStatus::Placing, CartStatus::Converted ) as $status ) {
			$shirt = self::variant();
			$cart  = $this->startCart( array( $shirt => 1 ) );

			$this->plantStatus( $cart->id, $status, 77 );

			$writes = array(
				'add'    => fn() => $this->service->add( self::lines( array( $shirt => 1 ) ), 1, self::guest() ),
				'change' => fn() => $this->service->changeQuantity( LineIdentity::of( $shirt ), 4, 1 ),
				'remove' => fn() => $this->service->changeQuantity( LineIdentity::of( $shirt ), 0, 1 ),
			);

			foreach ( $writes as $write => $send ) {
				try {
					$send();
					$this->fail( sprintf( 'A %s cart accepted a write (%s).', $status->value, $write ) );
				} catch ( CodedException $refused ) {
					$this->assertSame( CartError::NotOpen, $refused->errorCode(), $write );
					$this->assertSame( array( 'status' => $status->value ), $refused->context(), $write );
				}
			}

			$current = $this->current();

			$this->assertSame( 1, $current->version );
			$this->assertSame( array( $shirt . ':1' ), self::summary( $current ) );
		}
	}

	/**
	 * Tests that an expired cart is gone to reads and writes with no sweep, and that a new first write starts a new cart beside it.
	 *
	 * Planted violations: in MysqlCartRepository::FIND_BY_TOKEN_HASH, drop
	 * `AND expires_at > UTC_TIMESTAMP()`: the read then answers the expired cart, and the writes
	 * find it.
	 *
	 * @since 0.1.0
	 */
	public function test_an_expired_cart_is_invisible_to_reads_and_writes_without_the_sweep(): void {
		$shirt = self::variant();
		$cart  = $this->startCart( array( $shirt => 1 ) );

		$this->expire( $cart->id );

		$this->assertEmptyCart( $this->service->getCart( array(), self::guest() ) );

		$writes = array(
			'add'    => fn() => $this->service->add( self::lines( array( $shirt => 1 ) ), 1, self::guest() ),
			'change' => fn() => $this->service->changeQuantity( LineIdentity::of( $shirt ), 4, 1 ),
		);

		foreach ( $writes as $write => $send ) {
			try {
				$send();
				$this->fail( sprintf( 'An expired cart accepted a write (%s).', $write ) );
			} catch ( CodedException $refused ) {
				$this->assertSame( CartError::NotFound, $refused->errorCode(), $write );
			}
		}

		$fresh = $this->service->add( self::lines( array( $shirt => 2 ) ), 0, self::guest() );

		$this->assertNotSame( $cart->id, $fresh->id );
		$this->assertSame( 1, $fresh->version );
		$this->assertCount( 2, $this->tokens->issued );
		$this->assertFalse( $this->tokens->issued[0]->equals( $this->tokens->issued[1] ), 'A new cart gets a new token, never the expired one\'s.' );
		$this->assertSame( 2, $this->rowsOf( CartTables::CARTS ), 'Nothing was swept: the expired cart is still there, and invisible.' );
	}

	/**
	 * Tests that a line's quantity is set, that 0 removes it, and that a line the cart does not have is refused and changes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_is_set_removed_or_refused_when_the_cart_does_not_have_it(): void {
		$shirt = self::variant();
		$mug   = self::variant();

		$this->startCart(
			array(
				$shirt => 2,
				$mug   => 1,
			)
		);

		$cart = $this->service->changeQuantity( LineIdentity::of( $shirt ), 5, 1 );

		$this->assertSame( array( 2, $shirt . ':5', $mug . ':1' ), array( $cart->version, ...self::summary( $cart ) ) );

		$cart = $this->service->changeQuantity( LineIdentity::of( $mug ), 0, 2 );

		$this->assertSame( array( 3, $shirt . ':5' ), array( $cart->version, ...self::summary( $cart ) ) );

		$unknown = LineIdentity::of( self::variant() );

		foreach ( array( $unknown->value(), 'not-an-identity' ) as $sent ) {
			try {
				$this->service->updateLine(
					array(
						'line_identity' => $sent,
						'quantity'      => 1,
						'cart_version'  => 3,
					),
					self::guest()
				);
				$this->fail( 'A line the cart does not have was changed.' );
			} catch ( CodedException $refused ) {
				$this->assertSame( CartError::LineNotFound, $refused->errorCode() );
				$this->assertSame( array( 'line_identity' => $sent ), $refused->context() );
			}
		}

		$this->assertSame( 3, $this->current()->version, 'A refused change moved the version.' );
	}

	/**
	 * Tests that a cart holds at most Cart::MAX_LINES lines: a write that would add one more is refused whole.
	 *
	 * Planted violation: in MysqlCartRepository::PROJECT, compare with `<= %d + 1`: the fifty-first
	 * line is then kept.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cart_holds_at_most_fifty_lines(): void {
		$quantities = array();

		for ( $line = 0; $line < Cart::MAX_LINES; $line++ ) {
			$quantities[ self::variant() ] = 1;
		}

		$full  = $this->startCart( $quantities );
		$first = array_key_first( $quantities );
		$extra = self::variant();

		$this->assertCount( Cart::MAX_LINES, $full->lines );

		try {
			$this->service->add(
				self::lines(
					array(
						$first => 1,
						$extra => 1,
					)
				),
				1,
				self::guest()
			);
			$this->fail( 'A fifty-first line was added.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CartError::TooManyLines, $refused->errorCode() );
			$this->assertSame( array( 'max_lines' => Cart::MAX_LINES ), $refused->context() );
		}

		$current = $this->current();

		$this->assertSame( 1, $current->version );
		$this->assertCount( Cart::MAX_LINES, $current->lines );
		$this->assertSame( 1, $current->lines[0]->quantity, 'The refused batch left the line it also named unchanged.' );
		$this->assertSame( 2, $this->service->add( self::lines( array( $first => 1 ) ), 1, self::guest() )->version, 'A full cart still takes more units of a line it has.' );
	}

	/**
	 * Tests that the cart's row counts its lines and units after every kind of write.
	 *
	 * @since 0.1.0
	 */
	public function test_the_cart_row_counts_its_lines_and_units(): void {
		$shirt = self::variant();
		$mug   = self::variant();
		$b     = $this->secondConnection();
		$cart  = $this->startCart( array( $shirt => 2 ) );

		$this->assertSame( array( 1, 2 ), $this->counts( $b, $cart->id ) );

		$this->service->add(
			self::lines(
				array(
					$mug   => 3,
					$shirt => 1,
				)
			),
			1,
			self::guest()
		);
		$this->assertSame( array( 2, 6 ), $this->counts( $b, $cart->id ) );

		$this->service->changeQuantity( LineIdentity::of( $mug ), 1, 2 );
		$this->assertSame( array( 2, 4 ), $this->counts( $b, $cart->id ) );

		$this->service->changeQuantity( LineIdentity::of( $shirt ), 0, 3 );
		$this->assertSame( array( 1, 1 ), $this->counts( $b, $cart->id ) );

		$this->service->changeQuantity( LineIdentity::of( $mug ), 0, 4 );
		$this->assertSame( array( 0, 0 ), $this->counts( $b, $cart->id ), 'An emptied cart keeps its row and its version.' );
		$this->assertSame( 5, $this->current()->version );
	}

	/**
	 * Tests that the cap on new carts counts the client, not its tokens, and that a refused start leaves nothing.
	 *
	 * Planted violation: in CartService::start(), count the presented token's identity
	 * (ClientIdentities::ofCart()) instead of the client's: a client that sends a new token each
	 * time then escapes the cap.
	 *
	 * @since 0.1.0
	 */
	public function test_the_cap_on_new_carts_counts_the_client_not_its_tokens(): void {
		$variant = self::variant();

		for ( $cart = 0; $cart < CartService::CREATION_LIMIT; $cart++ ) {
			$this->tokens->presented = CartToken::generate();
			$this->service->add( self::lines( array( $variant => 1 ) ), 0, self::guest() );
		}

		$this->tokens->presented = CartToken::generate();
		$issued                  = count( $this->tokens->issued );

		try {
			$this->service->add( self::lines( array( $variant => 1 ) ), 0, self::guest() );
			$this->fail( 'A client started more carts than its cap.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CartError::CreationLimited, $refused->errorCode() );
		}

		$this->assertSame( CartService::CREATION_LIMIT, $this->rowsOf( CartTables::CARTS ) );
		$this->assertSame( $issued, count( $this->tokens->issued ), 'The refused start issued a token.' );
	}

	/**
	 * Returns the request's cart, failing the test when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @return Cart The cart.
	 */
	private function current(): Cart {
		$cart = $this->service->current();

		$this->assertNotNull( $cart, 'The request has no live cart.' );

		return $cart;
	}

	/**
	 * Reads a cart's committed line and unit counts.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b      Connection B.
	 * @param int              $cartId The cart.
	 * @return array{0: int, 1: int} line_count and item_count.
	 */
	private function counts( SecondConnection $b, int $cartId ): array {
		$row = $this->committedCart( $b, $cartId );

		$this->assertNotNull( $row );

		return array( $row['line_count'], $row['item_count'] );
	}
}
