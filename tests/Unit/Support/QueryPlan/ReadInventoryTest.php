<?php
/**
 * Tests ReadInventory: a read is found however its statement is spelled, and one that cannot be read is refused
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\QueryPlan;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\QueryPlan\ClassDependencies;
use SEOCart\Tests\Support\QueryPlan\ReadInventory;
use SEOCart\Tests\Support\QueryPlan\Statement;
use SEOCart\Tests\Unit\TestSupport\TemporaryPluginDirectory;

/**
 * The query-plan run proves every read a module writes was sent. That proof holds only if the
 * inventory sees each read whole, so these tests write small repositories to a temporary
 * directory, load them, and read them as the run reads a module.
 *
 * Runs with no WordPress and no database.
 *
 * Planted violations, each confirmed red then removed: read a constant from the source text
 * again, as its first literal (the statement built from constants is the head `SELECT`, which
 * every statement matches, so the read left out of the run is not named); skip a class whose
 * constants cannot be evaluated instead of refusing it; lift the check on a SELECT that is
 * joined to a constant, a variable or a call; skip the literals of a class constant
 * (the SELECT inside an INSERT ... SELECT constant is no longer a head); read only strings in a
 * constant (an array constant's statements are skipped); read a run of literals one at a time
 * (`"SEL" . "ECT ..."` is not a SELECT); drop the refusal after, before, or handed to sprintf();
 * match a head by its beginning again (the statement that goes on after it is the read); accept
 * any call in place of the placeholder list of an IN clause; read the texts of a ternary or a
 * variable as one; read a parameter as a locking clause whatever the calls pass, or only the first call; let a call that cannot be read pass; ignore a default; read a variable assigned something unreadable as if it were never assigned; count one shape of a read as the sending of all of them; count only `.=` as a step of a stepwise read; give the generic reason for a public method's parameter; read a parameter of a public method; combine the values of a parameter into pairs; read a named argument as positional; read a default only when it is a literal; skip a file that includes another or is not PHP; look for declarations only at the start of a line; read only the first call of a parameter's function, skip a call that cannot be read, ignore a default, or accept a parameter nothing calls; take the first assignment of a variable assigned twice or in a block, or count the parameter list as a body; leave an INSERT ... SELECT in a method unread; read a WITH or a UNION as nothing, in a method or in a constant; ignore an unpacked argument; name a read by its short class
 * name or by a file name alone (two reads of one name overwrite each other); stop at the end of a
 * constant that ends at IN; read an INSERT ... SELECT constant whole instead of its SELECT; leave
 * a heredoc or an interpolated SELECT unread; treat every statement as open, or none; accept a
 * stepwise read that is not on the list of open reads; name no open read on the output; widen the
 * list by a class and a method no repository has.
 *
 * @since 0.2.0
 */
final class ReadInventoryTest extends TestCase {

	use TemporaryPluginDirectory;

	/**
	 * The namespace of the repositories the tests write.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NAMESPACE = 'SEOCart\Tests\Unit\Support\QueryPlan\Generated';

	/**
	 * The modules whose reads the query-plan run compares with their source.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const MODULES = array( 'Catalog', 'Inventory', 'Platform/RateLimiter', 'Cart', 'Checkout', 'Promotion', 'Pricing', 'Order', 'Payment' );

	/**
	 * Removes the temporary directory.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		$this->removePluginDirectory();
	}

	/**
	 * Tests that a statement built from constants is read whole, under the name of its constant.
	 *
	 * @since 0.2.0
	 */
	public function test_a_statement_built_from_constants_is_read_whole(): void {
		$class = $this->repository(
			array(
				'const COLUMNS = "id, uuid";',
				'const BY_ID = "SELECT " . self::COLUMNS . " FROM %i WHERE id = %d";',
				'const BY_UUID = "SELECT " . self::COLUMNS . " FROM %i WHERE uuid = %s";',
				'const WRITE = "UPDATE %i SET status = 1";',
			)
		);

		$this->assertSame(
			array(
				$class . '::BY_ID'   => array( 'SELECT id, uuid FROM {table} WHERE id = ?' ),
				$class . '::BY_UUID' => array( 'SELECT id, uuid FROM {table} WHERE uuid = ?' ),
			),
			ReadInventory::of( $this->pluginDirectory )
		);
	}

	/**
	 * Tests that a read built from a shared constant and left out of the run is named.
	 *
	 * Two reads share their column list. The run sends only one, so a head read from the source
	 * text, `SELECT`, would match the statement it sent and hide the other.
	 *
	 * @since 0.2.0
	 */
	public function test_a_read_built_from_a_shared_constant_and_left_out_of_the_run_is_named(): void {
		$class = $this->repository(
			array(
				'const COLUMNS = "id, uuid";',
				'const BY_ID = "SELECT " . self::COLUMNS . " FROM %i WHERE id = %d";',
				'const BY_UUID = "SELECT " . self::COLUMNS . " FROM %i WHERE uuid = %s";',
			)
		);
		$heads = ReadInventory::of( $this->pluginDirectory );
		$sent  = array( new Statement( 'SELECT id, uuid FROM wp_seocart_things WHERE id = 5', 'wp_' ) );

		$this->assertSame( array( $class . '::BY_UUID SELECT id, uuid FROM {table} WHERE uuid = ?' ), ReadInventory::unsent( $heads, $sent ) );

		$sent[] = new Statement( "SELECT id, uuid FROM wp_seocart_things WHERE uuid = 'u'", 'wp_' );

		$this->assertSame( array(), ReadInventory::unsent( $heads, $sent ), 'Once the run sends it, nothing is left out.' );
	}

	/**
	 * Tests that a class whose constants cannot be evaluated is refused, naming the class.
	 *
	 * @since 0.2.0
	 */
	public function test_a_class_whose_constants_cannot_be_evaluated_is_refused(): void {
		$class = $this->repository( array( 'const READ = "SELECT " . Missing::COLUMNS . " FROM %i";' ) );

		try {
			ReadInventory::of( $this->pluginDirectory );
			$this->fail( 'The inventory read a statement it could not evaluate.' );
		} catch ( \UnexpectedValueException $refusal ) {
			$this->assertStringContainsString( $class, $refusal->getMessage() );
			$this->assertStringContainsString( 'cannot evaluate', $refusal->getMessage() );
		}
	}

	/**
	 * Tests that a SELECT joined to a constant is refused, naming where it is written.
	 *
	 * @since 0.2.0
	 */
	public function test_a_select_joined_to_a_constant_is_refused(): void {
		$this->repository(
			array(
				'const COLUMNS = "id";',
				'public function read() { return "SELECT " . self::COLUMNS . " FROM %i"; }',
			)
		);

		try {
			ReadInventory::of( $this->pluginDirectory );
			$this->fail( 'The inventory took a head it could not read.' );
		} catch ( \UnexpectedValueException $refusal ) {
			$this->assertMatchesRegularExpression( '/^Repository\.php:5 starts a SELECT that is joined to a constant/', $refusal->getMessage() );
		}
	}

	/**
	 * Tests that the statements written inside methods are read: one with an IN list of placeholders, a whole one, and one split into string literals.
	 *
	 * @since 0.2.0
	 */
	public function test_statements_inside_methods_are_read_whole(): void {
		$this->repository(
			array(
				'public function inList( array $ids ) { return "SELECT id FROM %i WHERE id IN ( " . implode( ", ", array_fill( 0, count( $ids ), "%d" ) ) . " )"; }',
				'public function lock() { return "SELECT GET_LOCK( %s, 0 )"; }',
				'public function split() { return "SELECT id," . " name" . " FROM %i"; }',
			)
		);

		$this->assertSame(
			array(
				'Repository.php:4' => array( 'SELECT id FROM {table} WHERE id IN ( ?+ )' ),
				'Repository.php:5' => array( 'SELECT GET_LOCK( ?, ? )' ),
				'Repository.php:6' => array( 'SELECT id, name FROM {table}' ),
			),
			ReadInventory::of( $this->pluginDirectory )
		);
	}

	/**
	 * Tests that an array constant is read at every depth, a read left out of the run is named, and a statement it holds is not skipped.
	 *
	 * @since 0.2.0
	 */
	public function test_the_statements_of_an_array_constant_are_read_at_every_depth(): void {
		$class = $this->repository(
			array(
				'const COLUMNS = "id, uuid";',
				'const READS = array( "by_id" => "SELECT " . self::COLUMNS . " FROM %i WHERE id = %d", "more" => array( "by_uuid" => "SELECT " . self::COLUMNS . " FROM %i WHERE uuid = %s" ), "write" => "UPDATE %i SET a = 1" );',
			)
		);
		$heads = ReadInventory::of( $this->pluginDirectory );

		$this->assertSame(
			array(
				$class . '::READS[by_id]'         => array( 'SELECT id, uuid FROM {table} WHERE id = ?' ),
				$class . '::READS[more][by_uuid]' => array( 'SELECT id, uuid FROM {table} WHERE uuid = ?' ),
			),
			$heads
		);
		$this->assertSame(
			array( $class . '::READS[more][by_uuid] SELECT id, uuid FROM {table} WHERE uuid = ?' ),
			ReadInventory::unsent( $heads, array( new Statement( 'SELECT id, uuid FROM wp_seocart_things WHERE id = 5', 'wp_' ) ) )
		);
	}

	/**
	 * Tests that literals joined by dots are folded before the head is read, so a SELECT spelled in pieces is seen.
	 *
	 * @since 0.2.0
	 */
	public function test_literals_joined_by_dots_are_folded_before_the_head_is_read(): void {
		$this->repository( array( 'public function read() { return "SEL" . "ECT id FROM %i WHERE id = 1"; }' ) );

		$this->assertSame( array( 'Repository.php:4' => array( 'SELECT id FROM {table} WHERE id = ?' ) ), ReadInventory::of( $this->pluginDirectory ) );
	}

	/**
	 * Tests that a SELECT is refused, by name, however the rest of the statement reaches it, whether or not it names its table.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider unreadableSelects
	 *
	 * @param string $member  The class member that holds it, on line 4 of the file.
	 * @param string $reason  What the refusal says it is joined to.
	 */
	public function test_a_select_is_refused_by_name_however_the_rest_reaches_it( string $member, string $reason ): void {
		$this->repository( array( $member ) );

		try {
			ReadInventory::of( $this->pluginDirectory );
			$this->fail( 'The inventory took a head it could not read.' );
		} catch ( \UnexpectedValueException $refusal ) {
			$this->assertSame( 'Repository.php:4 starts a SELECT that is ' . $reason . ', so its statement cannot be read from the source. Write the statement as one class constant, or as string literals.', $refusal->getMessage() );
		}
	}

	/**
	 * Returns members whose SELECT cannot be read, with what the refusal says.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: string}> The cases.
	 */
	public static function unreadableSelects(): array {
		return array(
			'pieces, then a constant' => array( 'public function read() { return "SEL" . "ECT " . self::COLUMNS . " FROM %i"; }', 'joined to a constant, a variable or a call' ),
			'then a variable'         => array( 'public function read( $columns ) { return "SELECT " . $columns . " FROM %i"; }', 'completed by a parameter of a method that is not private, or protected in a final class, so that its callers may be outside the class' ),
			'then a call'             => array( 'public function read() { return "SELECT " . $this->columns() . " FROM %i"; }', 'joined to a constant, a variable or a call' ),
			'after a variable'        => array( 'public function read( $head ) { return $head . "SELECT *"; }', 'joined after a constant, a variable or a call' ),
			'handed to sprintf'       => array( 'public function read( $body ) { return sprintf( "SELECT %s", $body ); }', 'handed to sprintf()' ),
			'a WHERE from a constant' => array( 'public function read() { return "SELECT id FROM %i" . self::ACTIVE_WHERE; }', 'joined to a constant, a variable or a call' ),
			'a WHERE from a variable' => array( 'public function read( $where ) { return "SELECT id FROM %i WHERE id > 0" . $where; }', 'completed by a parameter of a method that is not private, or protected in a final class, so that its callers may be outside the class' ),
			'a call in the middle'    => array( 'public function read() { return "SELECT id FROM %i" . $this->where() . " ORDER BY id"; }', 'joined to a constant, a variable or a call' ),
			'an IN list of values'    => array( 'public function read( $ids ) { return "SELECT id FROM %i WHERE id IN ( " . implode( ", ", $ids ) . " )"; }', 'joined to a constant, a variable or a call' ),
			'an INSERT ... SELECT'    => array( 'public function copy() { return "INSERT INTO %i ( id ) " . "SELECT " . self::COLUMNS . " FROM %i WHERE id = %d"; }', 'joined to a constant, a variable or a call' ),
			'an interpolated string'  => array( 'public function read( $table ) { return "SELECT id FROM $table"; }', 'written in an interpolated string or a heredoc' ),
			'a WITH'                  => array( 'public function read() { return "WITH recent AS ( SELECT id FROM %i ) SELECT id FROM recent"; }', 'written as a WITH or a UNION' ),
			'a UNION'                 => array( 'public function read() { return "( SELECT id FROM %i ) UNION ( SELECT id FROM %i )"; }', 'written as a WITH or a UNION' ),
			'a list not in an IN'     => array( 'public function read( $ids ) { return "SELECT id FROM %i WHERE id > " . implode( ", ", array_fill( 0, count( $ids ), "%d" ) ) . " )"; }', 'joined to a constant, a variable or a call' ),
		);
	}

	/**
	 * Tests that a SELECT inside a constant that starts with something else, such as an INSERT ... SELECT, is still a head.
	 *
	 * @since 0.2.0
	 */
	public function test_a_select_inside_a_constant_that_starts_with_an_insert_is_still_a_head(): void {
		$class = $this->repository( array( 'const COPY = "INSERT INTO %i ( id ) " . "SELECT id FROM %i WHERE id = %d";' ) );

		$this->assertSame( array( $class . '::COPY' => array( 'SELECT id FROM {table} WHERE id = ?' ) ), ReadInventory::of( $this->pluginDirectory ) );
	}

	/**
	 * Tests that two reads which share their beginning are told apart by their whole text, and the one the run left out is named.
	 *
	 * @since 0.2.0
	 */
	public function test_two_reads_that_share_a_head_are_told_apart_by_their_whole_text(): void {
		$this->repository(
			array(
				'public function active() { return "SELECT id FROM %i WHERE active = 1"; }',
				'public function all() { return "SELECT id FROM %i WHERE id > 0"; }',
			)
		);
		$heads  = ReadInventory::of( $this->pluginDirectory );
		$sent   = array( new Statement( 'SELECT id FROM wp_seocart_things WHERE active = 1', 'wp_' ) );
		$tables = array( 'wp_seocart_things' );

		$this->assertSame( array( 'Repository.php:5 SELECT id FROM {table} WHERE id > ?' ), ReadInventory::unsent( $heads, $sent ) );
		$this->assertSame( array(), ReadInventory::unknown( $heads, $sent, $tables ) );

		$sent[] = new Statement( 'SELECT id FROM wp_seocart_things WHERE id > 0', 'wp_' );

		$this->assertSame( array(), ReadInventory::unsent( $heads, $sent ) );
	}

	/**
	 * Tests that a head matches a statement by its whole text: one that goes on after the head is neither the read nor allowed to stand for it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_head_does_not_match_a_statement_that_goes_on_after_it(): void {
		$this->repository( array( 'public function read() { return "SELECT id FROM %i"; }' ) );
		$heads  = ReadInventory::of( $this->pluginDirectory );
		$longer = array( new Statement( 'SELECT id FROM wp_seocart_things WHERE id = 5', 'wp_' ) );
		$tables = array( 'wp_seocart_things' );

		$this->assertSame( array( 'Repository.php:4 SELECT id FROM {table}' ), ReadInventory::unsent( $heads, $longer ) );
		$this->assertSame( array( 'SELECT id FROM {prefix}seocart_things WHERE id = ?' ), ReadInventory::unknown( $heads, $longer, $tables ) );

		$whole = array( new Statement( 'SELECT id FROM wp_seocart_things', 'wp_' ) );

		$this->assertSame( array(), ReadInventory::unsent( $heads, $whole ) );
		$this->assertSame( array(), ReadInventory::unknown( $heads, $whole, $tables ) );
	}

	/**
	 * Tests that the placeholder list of an IN clause is a template that matches with one placeholder and with three, by full text.
	 *
	 * @since 0.2.0
	 */
	public function test_an_in_list_of_placeholders_matches_with_one_and_with_three(): void {
		$this->repository( array( 'public function some( array $ids ) { return "SELECT id FROM %i WHERE id IN ( " . implode( ", ", array_fill( 0, count( $ids ), "%d" ) ) . " )"; }' ) );
		$heads  = ReadInventory::of( $this->pluginDirectory );
		$tables = array( 'wp_seocart_things' );
		$sent   = array(
			new Statement( 'SELECT id FROM wp_seocart_things WHERE id IN (5)', 'wp_' ),
			new Statement( 'SELECT id FROM wp_seocart_things WHERE id IN (1, 2, 3)', 'wp_' ),
		);

		$this->assertSame( array( 'Repository.php:4' => array( 'SELECT id FROM {table} WHERE id IN ( ?+ )' ) ), $heads );
		$this->assertSame( array(), ReadInventory::unsent( $heads, array( $sent[0] ) ), 'One placeholder.' );
		$this->assertSame( array(), ReadInventory::unsent( $heads, array( $sent[1] ) ), 'Three placeholders.' );
		$this->assertSame( array(), ReadInventory::unknown( $heads, $sent, $tables ) );
		$this->assertSame(
			array( 'SELECT id FROM {prefix}seocart_things WHERE id IN ( ?+ ) AND id > ?' ),
			ReadInventory::unknown( $heads, array( new Statement( 'SELECT id FROM wp_seocart_things WHERE id IN (1, 2) AND id > 0', 'wp_' ) ), $tables ),
			'Whatever follows the list is not the read.'
		);
	}

	/**
	 * Tests that a statement that takes one of two texts has a shape for each, and the run must send both.
	 *
	 * @since 0.2.0
	 */
	public function test_a_statement_that_takes_one_of_two_texts_must_be_sent_in_both(): void {
		$this->repository(
			array(
				'private const LOCKING = " FOR UPDATE";',
				'public function find( int $id, bool $locking ) { return $this->db->fetchRow( "SELECT id FROM %i WHERE id = %d" . ( $locking ? self::LOCKING : "" ), $id ); }',
				'public function pick( bool $all ) { $where = $all ? " WHERE id > 0" : " WHERE id < 0"; return "SELECT id FROM %i" . $where; }',
			)
		);
		$heads = ReadInventory::of( $this->pluginDirectory );

		$this->assertSame(
			array(
				'Repository.php:5' => array( 'SELECT id FROM {table} WHERE id = ? FOR UPDATE', 'SELECT id FROM {table} WHERE id = ?' ),
				'Repository.php:6' => array( 'SELECT id FROM {table} WHERE id > ?', 'SELECT id FROM {table} WHERE id < ?' ),
			),
			$heads
		);

		$sent = array( new Statement( 'SELECT id FROM wp_seocart_things WHERE id = 1 FOR UPDATE', 'wp_' ) );

		$this->assertSame(
			array(
				'Repository.php:5 [shape 2 of 2] SELECT id FROM {table} WHERE id = ?',
				'Repository.php:6 [shape 1 of 2] SELECT id FROM {table} WHERE id > ?',
				'Repository.php:6 [shape 2 of 2] SELECT id FROM {table} WHERE id < ?',
			),
			ReadInventory::unsent( $heads, $sent ),
			'One shape sent does not stand for the other.'
		);

		$sent[] = new Statement( 'SELECT id FROM wp_seocart_things WHERE id = 1', 'wp_' );
		$sent[] = new Statement( 'SELECT id FROM wp_seocart_things WHERE id > 0', 'wp_' );

		$this->assertSame( array( 'Repository.php:6 [shape 2 of 2] SELECT id FROM {table} WHERE id < ?' ), ReadInventory::unsent( $heads, $sent ) );
		$this->assertSame(
			array( 'SELECT id FROM {prefix}seocart_things WHERE id = ? AND active = ?' ),
			ReadInventory::unknown( $heads, array( new Statement( 'SELECT id FROM wp_seocart_things WHERE id = 1 AND active = 1', 'wp_' ) ), array( 'wp_seocart_things' ) ),
			'A clause the source does not write is not the read.'
		);
	}

	/**
	 * Tests that a parameter takes the texts the class's own calls pass for it, and the run must send each.
	 *
	 * @since 0.2.0
	 */
	public function test_a_parameter_takes_the_texts_the_calls_pass_for_it(): void {
		$this->repository(
			array(
				'private const LOCKING = " FOR UPDATE";',
				'public function load( int $id, bool $locking ) { $lock = $locking ? self::LOCKING : ""; return $this->one( $id, $lock ); }',
				'public function peek( int $id ) { return $this->one( $id, " FOR SHARE" ); }',
				'private function one( int $id, string $lock ) { return "SELECT id FROM %i WHERE id = %d" . $lock; }',
			)
		);
		$heads = ReadInventory::of( $this->pluginDirectory );

		$this->assertSame(
			array(
				'Repository.php:7' => array(
					'SELECT id FROM {table} WHERE id = ? FOR UPDATE',
					'SELECT id FROM {table} WHERE id = ?',
					'SELECT id FROM {table} WHERE id = ? FOR SHARE',
				),
			),
			$heads
		);
		$this->assertSame(
			array(
				'Repository.php:7 [shape 2 of 3] SELECT id FROM {table} WHERE id = ?',
				'Repository.php:7 [shape 3 of 3] SELECT id FROM {table} WHERE id = ? FOR SHARE',
			),
			ReadInventory::unsent( $heads, array( new Statement( 'SELECT id FROM wp_seocart_things WHERE id = 1 FOR UPDATE', 'wp_' ) ) )
		);
	}

	/**
	 * Tests that a call which leaves out the argument passes the parameter's default.
	 *
	 * @since 0.2.0
	 */
	public function test_a_call_that_leaves_the_argument_out_passes_the_default(): void {
		$this->repository(
			array(
				'public function load( int $id ) { return $this->one( $id ); }',
				'private function one( int $id, string $lock = "" ) { return "SELECT id FROM %i WHERE id = %d" . $lock; }',
			)
		);

		$this->assertSame( array( 'Repository.php:5' => array( 'SELECT id FROM {table} WHERE id = ?' ) ), ReadInventory::of( $this->pluginDirectory ) );
	}

	/**
	 * Tests that a parameter is refused, by name, when a call passes what cannot be read or nothing calls the function.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider unreadableParameters
	 *
	 * @param string[] $members The class's members; the statement is on line 5.
	 */
	public function test_a_parameter_is_refused_by_name_when_a_call_passes_what_cannot_be_read( array $members ): void {
		$this->repository( $members );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Repository.php:5 starts a SELECT that is joined to a constant, a variable or a call, so its statement cannot be read from the source.' );

		ReadInventory::of( $this->pluginDirectory );
	}

	/**
	 * Returns classes whose parameter cannot be read.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: list<string>}> The cases.
	 */
	public static function unreadableParameters(): array {
		$statement = 'private function one( int $id, string $lock ) { return "SELECT id FROM %i WHERE id = %d" . $lock; }';

		return array(
			'a call passes a call'                  => array( array( 'public function load( int $id ) { return $this->one( $id, $this->suffix() ); }', $statement ) ),
			'a call passes a property'              => array( array( 'public function load( int $id ) { return $this->one( $id, $this->suffix ); }', $statement ) ),
			'one call out of two is unread'         => array( array( 'public function load( int $id ) { return $this->one( $id, " FOR UPDATE" ) . $this->one( $id, $this->suffix() ); }', $statement ) ),
			'a call unpacks its arguments'          => array( array( 'public function load( array $arguments ) { return $this->one( ...$arguments ); }', $statement ) ),
			'a call unpacks into a parameter with a default' => array(
				array(
					'public function load( array $arguments ) { return $this->one( ...$arguments ); }',
					'private function one( int $id, string $lock = "" ) { return "SELECT id FROM %i WHERE id = %d" . $lock; }',
				),
			),
			'a call passes its own parameter'       => array( array( 'public function load( int $id, string $lock ) { return $this->one( $id, $lock ); }', $statement ) ),
			'nothing calls the function'            => array( array( 'public function load( int $id ) { return $id; }', $statement ) ),
			'a call leaves out what has no default' => array( array( 'public function load( int $id ) { return $this->one( $id ); }', $statement ) ),
		);
	}

	/**
	 * Tests that a variable assigned something that cannot be read is refused, by name, and is not read as if it were never assigned.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider unreadableAssignments
	 *
	 * @param string $member The class member that holds the statement, on line 4.
	 */
	public function test_a_variable_assigned_something_unreadable_is_refused_by_name( string $member ): void {
		$this->repository( array( $member ) );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Repository.php:4 starts a SELECT that is joined to a constant, a variable or a call, so its statement cannot be read from the source.' );

		ReadInventory::of( $this->pluginDirectory );
	}

	/**
	 * Returns members whose variable is assigned something that cannot be read.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function unreadableAssignments(): array {
		return array(
			'a call'                              => array( 'public function read() { $lock = $this->suffix(); return "SELECT id FROM %i WHERE id = %d" . $lock; }' ),
			'sometimes nothing, sometimes a call' => array( 'public function read( bool $all ) { $lock = $all ? "" : $this->where(); return "SELECT id FROM %i" . $lock; }' ),
			'a concatenation'                     => array( 'public function read( $a ) { $lock = " WHERE " . $a; return "SELECT id FROM %i" . $lock; }' ),
			'one in each branch'                  => array( 'public function read( bool $byKey ) { if ( $byKey ) { $where = " WHERE key_hash = %s"; } else { $where = " WHERE uuid = %s"; } return "SELECT id FROM %i" . $where; }' ),
			'one in a block only'                 => array( 'public function read( bool $a ) { if ( $a ) { $where = " WHERE a = 1"; } return "SELECT id FROM %i" . $where; }' ),
			'assigned twice'                      => array( 'public function read() { $where = " WHERE a = 1"; $where = " WHERE b = 1"; return "SELECT id FROM %i" . $where; }' ),
			'extended with .='                    => array( 'public function read() { $where = " WHERE a = 1"; $where .= " AND b = 1"; return "SELECT id FROM %i" . $where; }' ),
			'taken from a loop'                   => array( 'public function read( array $all ) { foreach ( $all as $where ) { break; } return "SELECT id FROM %i" . $where; }' ),
		);
	}

	/**
	 * Tests that the SELECT an INSERT ... SELECT constant ends in is the read, and the run is asked to send it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_select_an_insert_select_constant_ends_in_is_a_read_the_run_must_send(): void {
		$class = $this->repository(
			array(
				'const COLUMNS = "id";',
				'const COPY = "INSERT INTO %i ( id ) " . "SELECT " . self::COLUMNS . " FROM %i WHERE id = %d";',
			)
		);
		$heads = ReadInventory::of( $this->pluginDirectory );

		$this->assertSame( array( $class . '::COPY' => array( 'SELECT id FROM {table} WHERE id = ?' ) ), $heads );
		$this->assertSame( array( $class . '::COPY SELECT id FROM {table} WHERE id = ?' ), ReadInventory::unsent( $heads, array() ) );
		$this->assertSame( array(), ReadInventory::unsent( $heads, array( new Statement( 'SELECT id FROM wp_seocart_things WHERE id = 5', 'wp_' ) ) ) );
	}

	/**
	 * Tests that an INSERT ... SELECT constant whose SELECT reads no table of its own, only values, is no read.
	 *
	 * @since 0.2.0
	 */
	public function test_an_insert_select_of_values_is_no_read(): void {
		$this->repository(
			array(
				'const FROM_VALUES = "INSERT INTO %i ( id ) SELECT portion.id FROM ( SELECT %d AS id ) AS portion";',
				'const FROM_DUAL = "INSERT INTO %i ( id ) SELECT %d FROM DUAL WHERE 1 = 1";',
			)
		);

		$this->assertSame( array(), ReadInventory::of( $this->pluginDirectory ) );
	}

	/**
	 * Tests that a constant that stops at IN takes the placeholder list of an IN clause, whatever its length.
	 *
	 * @since 0.2.0
	 */
	public function test_a_constant_that_stops_at_in_takes_a_placeholder_list(): void {
		$class = $this->repository( array( 'const BY_CODES = "SELECT id FROM %i WHERE code IN ";' ) );
		$heads = ReadInventory::of( $this->pluginDirectory );

		$this->assertSame( array( $class . '::BY_CODES' => array( 'SELECT id FROM {table} WHERE code IN ( ?+ )' ) ), $heads );
		$this->assertSame( array(), ReadInventory::unsent( $heads, array( new Statement( "SELECT id FROM wp_seocart_things WHERE code IN ('a', 'b')", 'wp_' ) ) ) );
	}

	/**
	 * Tests that two classes of one short name in one module keep their reads apart, and that two reads of one name are refused.
	 *
	 * @since 0.2.0
	 */
	public function test_reads_are_named_in_full_and_a_name_used_twice_is_refused(): void {
		$suffix = bin2hex( random_bytes( 4 ) );
		$files  = array();

		foreach ( array( 'First', 'Second' ) as $folder ) {
			$files[ $folder . '/Repository.php' ] = "<?php\nnamespace " . self::NAMESPACE . "\\{$folder}{$suffix};\nfinal class Repository {\nconst READ = \"SELECT id FROM %i\";\n}\n";
		}

		$this->createPluginDirectory( $files );
		require_once $this->pluginDirectory . '/First/Repository.php';
		require_once $this->pluginDirectory . '/Second/Repository.php';

		$this->assertSame(
			array(
				self::NAMESPACE . '\\First' . $suffix . '\\Repository::READ',
				self::NAMESPACE . '\\Second' . $suffix . '\\Repository::READ',
			),
			array_keys( ReadInventory::of( $this->pluginDirectory ) )
		);

		$this->removePluginDirectory();
		$this->repository( array( 'public function a() { return "SELECT id FROM %i"; } public function b() { return "SELECT id FROM %i WHERE id = 1"; }' ) );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Two reads are called Repository.php:4' );

		ReadInventory::of( $this->pluginDirectory );
	}

	/**
	 * Tests that a SELECT in a heredoc or a nowdoc is refused, by name.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider documents
	 *
	 * @param string $opening How the document opens.
	 */
	public function test_a_select_in_a_heredoc_is_refused_by_name( string $opening ): void {
		$this->repository( array( "public function read( \$table ) {\nreturn {$opening}\nSELECT id FROM %i\nSQL;\n}" ) );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Repository.php:6 starts a SELECT that is written in an interpolated string or a heredoc, so its statement cannot be read from the source.' );

		ReadInventory::of( $this->pluginDirectory );
	}

	/**
	 * Returns the ways a document opens.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function documents(): array {
		return array(
			'a heredoc' => array( '<<<SQL' ),
			'a nowdoc'  => array( "<<<'SQL'" ),
		);
	}

	/**
	 * Tests that a statement a function builds in steps with `.=` is an open read matched by its beginning, and one it does not extend is whole.
	 *
	 * @since 0.2.0
	 */
	public function test_a_statement_built_in_steps_is_an_open_read(): void {
		$class = $this->repository(
			array(
				'public function scan( int $after ) { $sql = "SELECT id FROM %i"; if ( $after > 0 ) { $sql .= " WHERE id > %d"; } $sql .= " ORDER BY id LIMIT %d"; return $sql; }',
				'public function whole() { $sql = "SELECT id FROM %i WHERE id = 1"; return $sql; }',
			)
		);
		$heads = ReadInventory::of( $this->pluginDirectory, array( $class => array( 'scan' ) ) );

		$this->assertSame(
			array(
				'Repository.php:4' => array( 'SELECT id FROM {table} …' ),
				'Repository.php:5' => array( 'SELECT id FROM {table} WHERE id = ?' ),
			),
			$heads
		);

		$sent = array(
			new Statement( 'SELECT id FROM wp_seocart_things WHERE id > 5 ORDER BY id LIMIT 10', 'wp_' ),
			new Statement( 'SELECT id FROM wp_seocart_things WHERE id = 1 ORDER BY id', 'wp_' ),
		);

		$this->assertSame( array( 'Repository.php:5 SELECT id FROM {table} WHERE id = ?' ), ReadInventory::unsent( $heads, $sent ), 'The open read is sent; the whole one is not, since a clause follows it.' );
	}

	/**
	 * Tests that a statement built in steps by a method that is not on the list of open reads is refused, by name.
	 *
	 * @since 0.2.0
	 */
	public function test_a_stepwise_read_that_is_not_on_the_list_is_refused_by_name(): void {
		$class = $this->repository( array( 'public function scan() { $sql = "SELECT id FROM %i"; $sql .= " ORDER BY id"; return $sql; }' ) );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Repository.php:4 starts a SELECT that is built in steps, by `.=` or by assigning to its variable again, and ' . $class . '::scan is not on the list of open reads (ReadInventory::OPEN_READS)' );

		ReadInventory::of( $this->pluginDirectory );
	}

	/**
	 * Tests that a class on the list is not enough: only the methods it names may build a statement in steps.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_listed_methods_of_a_listed_class_may_build_a_statement_in_steps(): void {
		$class = $this->repository(
			array(
				'public function scan() { $sql = "SELECT id FROM %i"; $sql .= " ORDER BY id"; return $sql; }',
				'public function other() { $sql = "SELECT id FROM %i"; $sql .= " ORDER BY id"; return $sql; }',
			)
		);

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Repository.php:5 starts a SELECT that is built in steps, by `.=` or by assigning to its variable again, and ' . $class . '::other is not on the list' );

		ReadInventory::of( $this->pluginDirectory, array( $class => array( 'scan' ) ) );
	}

	/**
	 * Tests that each open read is named, with its class, method, line and head, for the output of the run.
	 *
	 * @since 0.2.0
	 */
	public function test_each_open_read_is_named_for_the_output_of_the_run(): void {
		$class = $this->repository( array( 'public function scan() { $sql = "SELECT id FROM %i"; $sql .= " ORDER BY id"; return $sql; }' ) );

		$this->assertSame( array(), ReadInventory::openReadLines( array() ) );
		$this->assertSame(
			array(
				'Open reads, accepted for now and matched by their beginning only (the list is ReadInventory::OPEN_READS; the builders are to become complete statements):',
				'  ' . $class . '::scan, Repository.php:4: SELECT id FROM {table} …',
			),
			ReadInventory::openReadLines( ReadInventory::openReadsOf( $this->pluginDirectory ) )
		);
	}

	/**
	 * Tests that the open reads allowed today are exactly the Catalog repository's six, and that no module has another.
	 *
	 * A new stepwise read, in the Catalog or elsewhere, fails here until it is added to the list on purpose.
	 *
	 * @since 0.2.0
	 */
	public function test_the_open_reads_allowed_today_are_the_catalogs_six_and_no_others_exist(): void {
		$pinned = array(
			'SEOCart\Catalog\Infrastructure\MysqlProductRepository' => array(
				'unboundProductIds',
				'invalidSourceBindings',
				'unboundPostIds',
				'boundPostBindings',
				'incompleteMismatchIds',
				'variantIds',
			),
		);

		$this->assertSame( $pinned, ReadInventory::OPEN_READS );

		$found = array();

		foreach ( self::MODULES as $module ) {
			foreach ( ReadInventory::openReadsOf( dirname( __DIR__, 4 ) . '/src/' . $module ) as $read ) {
				$found[ $read['class'] ][] = $read['method'];
			}
		}

		$this->assertSame( $pinned, $found );
	}

	/**
	 * Tests that a constant written as a WITH or a UNION is refused, by name.
	 *
	 * @since 0.2.0
	 */
	public function test_a_constant_written_as_a_with_or_a_union_is_refused_by_name(): void {
		$class = $this->repository( array( 'const RECENT = "WITH recent AS ( SELECT id FROM %i ) SELECT id FROM recent";' ) );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( $class . '::RECENT holds a SELECT written as a WITH or a UNION' );

		ReadInventory::of( $this->pluginDirectory );
	}

	/**
	 * Tests that an INSERT ... SELECT written in a method is read through its SELECT, in one literal or in several.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider insertSelects
	 *
	 * @param string                      $member   The class member, on line 4.
	 * @param array<string, list<string>> $expected The heads.
	 */
	public function test_an_insert_select_written_in_a_method_is_read_through_its_select( string $member, array $expected ): void {
		$this->repository( array( $member ) );

		$this->assertSame( $expected, ReadInventory::of( $this->pluginDirectory ) );
	}

	/**
	 * Returns INSERT ... SELECT statements, with the heads they give.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: array<string, list<string>>}> The cases.
	 */
	public static function insertSelects(): array {
		$read = array( 'Repository.php:4' => array( 'SELECT id FROM {table} WHERE order_id = ?' ) );

		return array(
			'in one literal'      => array( 'public function copy() { return "INSERT INTO %i ( refund_id ) SELECT id FROM %i WHERE order_id = 1"; }', $read ),
			'in two literals'     => array( 'public function copy() { return "INSERT INTO %i ( refund_id ) SELECT id " . "FROM %i WHERE order_id = 1"; }', $read ),
			'in three literals'   => array( 'public function copy() { return "INSERT INTO %i ( refund_id ) " . "SELECT id " . "FROM %i WHERE order_id = 1"; }', $read ),
			'of values only'      => array( 'public function copy() { return "INSERT INTO %i ( id ) SELECT portion.id FROM ( SELECT %d AS id ) AS portion"; }', array() ),
			'from DUAL'           => array( 'public function copy() { return "INSERT INTO %i ( id ) SELECT %d FROM DUAL WHERE 1 = 1"; }', array() ),
			'an INSERT of values' => array( 'public function copy() { return "INSERT INTO %i ( id ) VALUES ( %d )"; }', array() ),
		);
	}

	/**
	 * Tests that an INSERT ... SELECT whose tail is completed by something that cannot be read is refused, by name.
	 *
	 * @since 0.2.0
	 */
	public function test_an_insert_select_completed_by_a_variable_is_refused_by_name(): void {
		$this->repository( array( 'public function copy( $orderId ) { return "INSERT INTO %i ( refund_id ) SELECT id FROM %i WHERE order_id = " . $orderId; }' ) );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Repository.php:4 starts a SELECT that is completed by a parameter of a method that is not private, or protected in a final class, so that its callers may be outside the class, so its statement cannot be read from the source.' );

		ReadInventory::of( $this->pluginDirectory );
	}

	/**
	 * Tests that any later assignment to the variable of a statement makes it a read built in steps, not only `.=`.
	 *
	 * @since 0.2.0
	 */
	public function test_any_later_assignment_to_the_variable_makes_a_read_stepwise(): void {
		$class = $this->repository( array( 'public function scan( bool $lock ) { $sql = "SELECT id FROM %i"; if ( $lock ) { $sql = $sql . " FOR UPDATE"; } return $sql; }' ) );

		$this->assertSame( array( 'Repository.php:4' => array( 'SELECT id FROM {table} …' ) ), ReadInventory::of( $this->pluginDirectory, array( $class => array( 'scan' ) ) ) );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Repository.php:4 starts a SELECT that is built in steps, by `.=` or by assigning to its variable again, and ' . $class . '::scan is not on the list' );

		ReadInventory::of( $this->pluginDirectory );
	}

	/**
	 * Tests that a parameter is read on a private method, and on a protected one only in a final class.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider visibilities
	 *
	 * @param string $modifier   What stands before `class`.
	 * @param string $visibility The method's visibility.
	 * @param bool   $readable   Whether the read is accepted.
	 */
	public function test_a_parameter_is_read_only_where_every_call_is_in_the_class( string $modifier, string $visibility, bool $readable ): void {
		$this->repository(
			array(
				'public function load( int $id ) { return $this->one( $id, " FOR UPDATE" ); }',
				$visibility . ' function one( int $id, string $lock ) { return "SELECT id FROM %i WHERE id = %d" . $lock; }',
			),
			$modifier
		);

		if ( ! $readable ) {
			$this->expectException( \UnexpectedValueException::class );
			$this->expectExceptionMessage( 'Repository.php:5 starts a SELECT that is completed by a parameter of a method that is not private, or protected in a final class, so that its callers may be outside the class' );
		}

		$this->assertSame( array( 'Repository.php:5' => array( 'SELECT id FROM {table} WHERE id = ? FOR UPDATE' ) ), ReadInventory::of( $this->pluginDirectory ) );
	}

	/**
	 * Returns the visibilities of a method, in a final class and in one that is not.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}> The cases.
	 */
	public static function visibilities(): array {
		return array(
			'private, final class'       => array( 'final ', 'private', true ),
			'private, open class'        => array( '', 'private', true ),
			'protected, final class'     => array( 'final ', 'protected', true ),
			'protected, open class'      => array( '', 'protected', false ),
			'public, final class'        => array( 'final ', 'public', false ),
			'no visibility (public)'     => array( 'final ', '', false ),
			'public static, final class' => array( 'final ', 'public static', false ),
		);
	}

	/**
	 * Tests that the arguments of one call stay together: two parameters called three times with paired values give three shapes, not nine.
	 *
	 * @since 0.2.0
	 */
	public function test_the_arguments_of_a_call_stay_together(): void {
		$this->repository(
			array(
				'public function all() { return $this->one( " WHERE a = 1", " ORDER BY x" ) . $this->one( " WHERE b = 1", " ORDER BY y" ) . $this->one( " WHERE c = 1", " ORDER BY z" ); }',
				'private function one( string $where, string $order ) { return "SELECT id FROM %i" . $where . $order; }',
			)
		);
		$heads = ReadInventory::of( $this->pluginDirectory );

		$this->assertSame(
			array(
				'Repository.php:5' => array(
					'SELECT id FROM {table} WHERE a = ? ORDER BY x',
					'SELECT id FROM {table} WHERE b = ? ORDER BY y',
					'SELECT id FROM {table} WHERE c = ? ORDER BY z',
				),
			),
			$heads
		);
		$this->assertCount( 2, ReadInventory::unsent( $heads, array( new Statement( 'SELECT id FROM wp_seocart_things WHERE b = 1 ORDER BY y', 'wp_' ) ) ) );
	}

	/**
	 * Tests that a call may give an argument by name, in any order, and that an omitted argument passes its default, which may be an expression of literals and class constants.
	 *
	 * @since 0.2.0
	 */
	public function test_arguments_by_name_and_defaults_that_are_expressions_are_read(): void {
		$this->repository(
			array(
				'private const LOCKING = " FOR UPDATE";',
				'public function load( int $id ) { return $this->one( $id, lock: " FOR SHARE" ) . $this->one( lock: " LOCK IN SHARE MODE", id: 2 ) . $this->one( $id ); }',
				'private function one( int $id, string $lock = self::LOCKING . " SKIP LOCKED" ) { return "SELECT id FROM %i WHERE id = %d" . $lock; }',
			)
		);

		$this->assertSame(
			array(
				'Repository.php:6' => array(
					'SELECT id FROM {table} WHERE id = ? FOR SHARE',
					'SELECT id FROM {table} WHERE id = ? LOCK IN SHARE MODE',
					'SELECT id FROM {table} WHERE id = ? FOR UPDATE SKIP LOCKED',
				),
			),
			ReadInventory::of( $this->pluginDirectory )
		);
	}

	/**
	 * Tests that a file which includes another is refused, by name, and a method named require is not an include.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider includes
	 *
	 * @param string $statement The include, in a method on line 4.
	 */
	public function test_a_file_that_includes_another_is_refused_by_name( string $statement ): void {
		$this->repository( array( 'public function load() { ' . $statement . ' }' ) );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Repository.php:4 includes a file' );

		ReadInventory::of( $this->pluginDirectory );
	}

	/**
	 * Returns the four ways to include a file.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function includes(): array {
		return array(
			'include'      => array( 'include __DIR__ . "/Other.php";' ),
			'include_once' => array( 'include_once __DIR__ . "/Other.php";' ),
			'require'      => array( 'require __DIR__ . "/Other.php";' ),
			'require_once' => array( 'require_once __DIR__ . "/Other.php";' ),
		);
	}

	/**
	 * Tests that a method called require, or a call of one, is no include.
	 *
	 * @since 0.2.0
	 */
	public function test_a_method_named_require_is_no_include(): void {
		$this->repository( array( 'public function require( $code ) { return $this->payments->require( $code ) . static::require( 1 ); }' ) );

		$this->assertSame( array(), ReadInventory::of( $this->pluginDirectory ) );
	}

	/**
	 * Tests that a file that is not PHP, in the directory of a module, is refused, by name.
	 *
	 * @since 0.2.0
	 */
	public function test_a_file_that_is_not_php_in_the_directory_is_refused_by_name(): void {
		$this->createPluginDirectory(
			array(
				'Repository.php' => "<?php\nnamespace " . self::NAMESPACE . ";\nfinal class Plain {}\n",
				'Queries.sql'    => 'SELECT id FROM wp_seocart_things',
			)
		);

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Queries.sql is not a PHP file' );

		ReadInventory::of( $this->pluginDirectory );
	}

	/**
	 * Tests that no class of a scanned module takes code from outside its module, but the one trait pinned here, which writes no SQL.
	 *
	 * The inventory scans the files of a module's directory. A parent class or a trait written
	 * elsewhere would carry reads it never sees, so a new one fails here, by name, until it is
	 * looked at and added on purpose. A class that extends WordPress is not a repository, and is
	 * pinned too.
	 *
	 * @since 0.2.0
	 */
	public function test_no_class_of_a_scanned_module_takes_code_from_outside_it(): void {
		$outside     = array();
		$unloadable  = array();
		$source_root = dirname( __DIR__, 4 ) . '/src/';

		foreach ( self::MODULES as $module ) {
			$directory = (string) realpath( $source_root . $module ) . '/';

			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( ! $file instanceof \SplFileInfo ) {
					continue;
				}

				foreach ( ClassDependencies::of( (string) file_get_contents( $file->getPathname() ) ) as $class => $taken ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- source files of the plugin.
					foreach ( $taken as $name ) {
						$path = self::definedIn( $name );

						if ( null === $path ) {
							$unloadable[ $class ][] = $name;
						} elseif ( false !== $path && 0 !== strpos( (string) realpath( $path ), $directory ) ) {
							$outside[ $class ][] = $name;
						}
					}
				}
			}
		}

		$this->assertSame( array( 'SEOCart\Catalog\Domain\Product' => array( 'SEOCart\Support\Events\RecordsEvents' ) ), $outside );
		$this->assertSame( array( 'SEOCart\Catalog\Interfaces\Rest\ProductPostsController' => array( 'WP_REST_Posts_Controller' ) ), $unloadable );
		$this->assertStringNotContainsStringIgnoringCase( 'SELECT', (string) file_get_contents( $source_root . 'Support/Events/RecordsEvents.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- source files of the plugin.
	}

	/**
	 * Finds the file a class, trait or interface is written in.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The full name.
	 * @return string|false|null The path; false for a class of PHP itself; null when it cannot be loaded.
	 */
	private static function definedIn( string $name ) {
		try {
			return ( new \ReflectionClass( $name ) )->getFileName();
		} catch ( \Throwable $failure ) {
			unset( $failure );

			return null;
		}
	}

	/**
	 * Tests that the inventory reads every module the query-plan run compares, and sees a constant of the payment module whole.
	 *
	 * @since 0.2.0
	 */
	public function test_every_module_of_the_run_can_be_read(): void {
		foreach ( self::MODULES as $module ) {
			$this->assertNotSame( array(), ReadInventory::of( dirname( __DIR__, 4 ) . '/src/' . $module ), $module );
		}

		$payment = ReadInventory::of( dirname( __DIR__, 4 ) . '/src/Payment' );

		$name = 'SEOCart\Payment\Infrastructure\MysqlRefundRepository::LOCK_FOR_CLAIM';

		$this->assertArrayHasKey( $name, $payment );
		$this->assertStringEndsWith( ' FROM {table} intent WHERE intent.id = ? FOR UPDATE', $payment[ $name ][0] );
	}

	/**
	 * Writes a repository class to the temporary directory and loads it.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines    What the class holds, one member to a line; the first is line 4 of the file.
	 * @param string   $modifier Optional. What stands before `class`. Default 'final '.
	 * @return string The class's short name, unique to this call.
	 */
	private function repository( array $lines, string $modifier = 'final ' ): string {
		$class = 'Repository' . bin2hex( random_bytes( 4 ) );
		$code  = "<?php\nnamespace " . self::NAMESPACE . ";\n{$modifier}class {$class} {\n" . implode( "\n", $lines ) . "\n}\n";

		$this->createPluginDirectory( array( 'Repository.php' => $code ) );

		require_once $this->pluginDirectory . '/Repository.php';

		return self::NAMESPACE . '\\' . $class;
	}
}
