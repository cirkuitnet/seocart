<?php
/**
 * Tests the error every surface answers with: its shape, internal rows and the generic internal error
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Rest;

use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Platform\Database\StatementDiagnostic;
use SEOCart\Platform\Rest\ErrorShape;
use SEOCart\Platform\Rest\RestErrorTranslator;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorDefinition;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Fixtures\Operations\FixtureStockError;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Unit\Support\Error\Fixtures\DetailedError;
use SEOCart\Tests\Unit\Support\Error\Fixtures\FixtureError;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * The translator on its own, against a table of the shared kernel's, the database's and two
 * fixture catalogs, with a recording reporter.
 *
 * A public row is pinned member by member, as PHP data and as the JSON body the REST API sends.
 * Every database code is translated with context values and a statement diagnostic that stand for
 * a customer's data, and none of them may reach the body; each must reach the reporter.
 *
 * @since 0.1.0
 */
final class RestErrorTranslatorTest extends WP_UnitTestCase {

	/**
	 * The correlation id the provider returns.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CORRELATION_ID = 'req-7f3a9c';

	/**
	 * A statement that writes a customer's e-mail address.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const STATEMENT = "INSERT INTO `wp_seocart_customers` ( email ) VALUES ( 'jane@example.com' )";

	/**
	 * The server's text for a duplicate of that address.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SERVER_TEXT = "Duplicate entry 'jane@example.com' for key 'email'";

	/**
	 * What the reporter received: each failure with the correlation id.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{error: CodedException, correlation_id: string|null}>
	 */
	private array $reported = array();

	/**
	 * Resets WordPress's REST server, for the one test that dispatches a real request.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();

		parent::tear_down();
	}

	/**
	 * Tests every member of a public error, as data and as the REST body.
	 *
	 * @since 0.1.0
	 */
	public function test_a_public_error_has_the_documented_shape(): void {
		$error = $this->translator()->translate(
			CodedException::because(
				FixtureStockError::Insufficient,
				array(
					'requested' => 10,
					'available' => 5,
				)
			)
		);

		$this->assertSame( array( 'fixture_stock.insufficient' ), $error->get_error_codes() );
		$this->assertSame( 'You asked to remove 10, but only 5 are in stock.', $error->get_error_message() );

		$data = $error->get_error_data();

		$this->assertIsArray( $data );
		$this->assertSame( array( 'status', 'details', 'correlation_id' ), array_keys( $data ), 'The data carries exactly these members, in this order.' );
		$this->assertSame( array_keys( ErrorShape::members() ), array_keys( $data ), 'The members are the ones ErrorShape documents.' );
		$this->assertSame( 409, $data['status'] );
		$this->assertInstanceOf( \stdClass::class, $data['details'] );
		$this->assertSame(
			array(
				'requested' => 10,
				'available' => 5,
			),
			(array) $data['details']
		);
		$this->assertSame( self::CORRELATION_ID, $data['correlation_id'] );
		$this->assertSame(
			'{"code":"fixture_stock.insufficient","message":"You asked to remove 10, but only 5 are in stock.","data":{"status":409,"details":{"requested":10,"available":5},"correlation_id":"req-7f3a9c"}}',
			self::body( $error )
		);
		$this->assertSame( array(), $this->reported, 'A public error is not reported.' );
	}

	/**
	 * Tests that the details are a JSON object even when the message holds no value.
	 *
	 * @since 0.1.0
	 */
	public function test_the_details_are_an_object_even_when_empty(): void {
		$error = $this->translator()->translate( CodedException::because( FixtureError::NotFound ) );

		$this->assertSame( '{"code":"fixture.not_found","message":"Nothing was found.","data":{"status":404,"details":{},"correlation_id":"req-7f3a9c"}}', self::body( $error ) );
	}

	/**
	 * Tests that a row's structured details go into the details beside the placeholders' values, and never into the message.
	 *
	 * @since 0.1.0
	 */
	public function test_structured_details_join_the_values_in_the_details(): void {
		$error = $this->translator()->translate( CodedException::because( DetailedError::Stale, array( 'current' => 3 ), null, array( 'state' => array( 'lines' => array( array( 'gross' => 1200 ) ) ) ) ) );

		$this->assertSame( '{"code":"detailed.stale","message":"It is at version 3 now.","data":{"status":409,"details":{"current":3,"state":{"lines":[{"gross":1200}]}},"correlation_id":"req-7f3a9c"}}', self::body( $error ) );
	}

	/**
	 * Tests that a request without a correlation id carries null, and that an empty id counts as none.
	 *
	 * @since 0.1.0
	 */
	public function test_a_request_without_a_correlation_id_carries_null(): void {
		foreach ( array( null, '' ) as $none ) {
			$data = $this->translator( $none )->translate( CodedException::because( FixtureError::NotFound ) )->get_error_data();

			$this->assertIsArray( $data );
			$this->assertArrayHasKey( 'correlation_id', $data );
			$this->assertNull( $data['correlation_id'] );
		}
	}

	/**
	 * Lists every database code.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{DatabaseError}> Each code.
	 */
	public static function databaseCodes(): array {
		$codes = array();

		foreach ( DatabaseError::cases() as $code ) {
			$codes[ $code->value ] = array( $code );
		}

		return $codes;
	}

	/**
	 * Tests that a database failure reaches the client only as its code, its status, a generic
	 * message and the correlation id, and reaches the reporter whole.
	 *
	 * Every value of the context and the statement diagnostic the failure carries stands for a
	 * customer's data or a server detail; none may be in the body.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider databaseCodes
	 *
	 * @param DatabaseError $code The code.
	 */
	public function test_a_database_failure_reaches_no_client( DatabaseError $code ): void {
		$row     = ErrorDefinition::of( $code );
		$context = array();

		foreach ( $row->placeholders() as $name ) {
			$context[ $name ] = 'private-' . $name . '-4711';
		}

		$failure    = CodedException::because( $code, $context, StatementDiagnostic::of( self::STATEMENT, self::SERVER_TEXT ) );
		$translator = $this->translator();
		$error      = $translator->translate( $failure );
		$body       = self::body( $error );

		foreach ( array_merge( array_values( $context ), array( 'jane@example.com', 'INSERT INTO', 'Duplicate entry', $row->render( $context ) ) ) as $private ) {
			$this->assertStringNotContainsString( $private, $body );
		}

		$this->assertSame( $code->value, $error->get_error_code() );
		$this->assertSame( $translator->unexpected()->get_error_message(), $error->get_error_message(), 'The message is the generic one.' );
		$this->assertSame(
			'{"status":' . $row->httpStatus() . ',"details":{},"correlation_id":"req-7f3a9c"}',
			wp_json_encode( $error->get_error_data() ),
			'The status is the row\'s, the details are empty, the correlation id is there.'
		);
		$this->assertTrue( $row->isInternal(), $code->value . ' is not declared internal.' );
		$this->assertCount( 1, $this->reported );
		$this->assertSame( $failure, $this->reported[0]['error'], 'The reporter receives the failure itself, with its context and its previous exceptions.' );
		$this->assertSame( self::CORRELATION_ID, $this->reported[0]['correlation_id'], 'The reporter receives the correlation id the client was given.' );
		$this->assertInstanceOf( StatementDiagnostic::class, $this->reported[0]['error']->getPrevious() );
	}

	/**
	 * Tests that a code the table does not hold is answered with the generic internal error, reported, and flagged to the developer.
	 *
	 * @since 0.1.0
	 */
	public function test_a_code_missing_from_the_table_is_answered_generically(): void {
		$this->setExpectedIncorrectUsage( RestErrorTranslator::class . '::translate' );

		$failure = CodedException::because(
			FixtureStockError::Insufficient,
			array(
				'requested' => 10,
				'available' => 5,
			)
		);
		$error   = $this->translator( self::CORRELATION_ID, ErrorTable::compose( SupportError::class ) )->translate( $failure );

		$this->assertSame( ErrorTranslator::INTERNAL_ERROR, $error->get_error_code() );
		$this->assertSame( '{"status":500,"details":{},"correlation_id":"req-7f3a9c"}', wp_json_encode( $error->get_error_data() ) );
		$this->assertStringNotContainsString( 'You asked', self::body( $error ) );
		$this->assertCount( 1, $this->reported );
		$this->assertSame( $failure, $this->reported[0]['error'] );
	}

	/**
	 * Tests the generic internal error: its code, its status, a message about nothing, and the shape.
	 *
	 * @since 0.1.0
	 */
	public function test_the_generic_internal_error_has_the_shape(): void {
		$error = $this->translator()->unexpected();

		$this->assertSame( 'seocart_internal_error', $error->get_error_code() );
		$this->assertSame( 'The operation failed because of an internal error. The site administrator can find the details in the error log.', $error->get_error_message() );
		$this->assertSame( '{"status":500,"details":{},"correlation_id":"req-7f3a9c"}', wp_json_encode( $error->get_error_data() ) );
		$this->assertSame( array(), $this->reported, 'Reporting an unexpected failure is the invoker\'s: it holds the exception.' );
	}

	/**
	 * Tests that WordPress's own errors get exactly the three members: the status stays, every
	 * other member moves into the details under its own name, and the correlation id is added.
	 *
	 * @since 0.1.0
	 */
	public function test_a_wordpress_error_is_given_exactly_the_three_members(): void {
		$translator = $this->translator();

		$forbidden = $translator->conform( new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', array( 'status' => 401 ) ) );

		$this->assertSame( '{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.","data":{"status":401,"details":{},"correlation_id":"req-7f3a9c"}}', self::body( $forbidden ) );
		$this->assertInstanceOf( \stdClass::class, $forbidden->get_error_data()['details'], 'The details are an object, never an array, even when they hold nothing.' );

		$invalid = $translator->conform(
			new WP_Error(
				'rest_invalid_param',
				'Invalid parameter(s): delta',
				array(
					'status'  => 400,
					'params'  => array( 'delta' => 'delta is not of type integer.' ),
					'details' => array(
						'delta' => array(
							'code'    => 'rest_invalid_type',
							'message' => 'delta is not of type integer.',
							'data'    => array( 'param' => 'delta' ),
						),
					),
				)
			)
		);

		$this->assertSame(
			'{"code":"rest_invalid_param","message":"Invalid parameter(s): delta","data":{"status":400,"details":{"params":{"delta":"delta is not of type integer."},"param_codes":{"delta":"rest_invalid_type"}},"correlation_id":"req-7f3a9c"}}',
			self::body( $invalid ),
			'WordPress\'s params stay at their name; its own details are flattened into param_codes, so nothing named details ever nests inside details.'
		);
		$this->assertArrayNotHasKey( 'details', (array) $invalid->get_error_data()['details'], 'WordPress\'s own details member does not survive under its own name.' );

		$patterned = $translator->conform(
			new WP_Error(
				'rest_invalid_param',
				'Invalid parameter(s): sku',
				array(
					'status'  => 400,
					'params'  => array( 'sku' => 'sku does not match pattern ^[A-Z0-9-]+$.' ),
					'details' => array(
						'sku' => array(
							'code'    => 'rest_invalid_pattern',
							'message' => 'sku does not match pattern ^[A-Z0-9-]+$.',
							'data'    => null,
						),
					),
				)
			)
		);

		$this->assertSame(
			'{"status":400,"details":{"params":{"sku":"sku does not match pattern ^[A-Z0-9-]+$."},"param_codes":{"sku":"rest_invalid_pattern"}},"correlation_id":"req-7f3a9c"}',
			wp_json_encode( $patterned->get_error_data() ),
			'A pattern violation is conformed the same way as any other kind: the conforming step is not a per-code patch.'
		);

		$missing = $translator->conform(
			new WP_Error(
				'rest_missing_callback_param',
				'Missing parameter(s): delta',
				array(
					'status' => 400,
					'params' => array( 'delta' ),
				)
			)
		);

		$this->assertSame( '{"status":400,"details":{"params":["delta"]},"correlation_id":"req-7f3a9c"}', wp_json_encode( $missing->get_error_data() ) );

		$bare = $translator->conform( new WP_Error( 'some_error', 'Something.' ) );

		$this->assertSame( '{"status":500,"details":{},"correlation_id":"req-7f3a9c"}', wp_json_encode( $bare->get_error_data() ), 'An error without data gets the status WordPress would answer with.' );

		$built = $translator->translate( CodedException::because( FixtureError::NotFound ) );

		$this->assertSame( self::body( $built ), self::body( $translator->conform( $built ) ), 'An error the translator built already has every member.' );
		$this->assertSame( self::body( $translator->conform( $invalid ) ), self::body( $invalid ), 'Conforming twice changes nothing.' );

		$foreign = $translator->conform(
			new WP_Error(
				'foreign_error',
				'Foreign.',
				array(
					'status'         => '409',
					'correlation_id' => 'someone-elses-id',
				)
			)
		);

		$this->assertSame( '{"status":409,"details":{"correlation_id":"someone-elses-id"},"correlation_id":"req-7f3a9c"}', wp_json_encode( $foreign->get_error_data() ), 'Data that is not exactly the three members is reshaped, whatever it names.' );

		$two = new WP_Error( 'first', 'First.', array( 'status' => 400 ) );
		$two->add( 'second', 'Second.', array( 'status' => 409 ) );

		$conformed = $translator->conform( $two );

		$this->assertSame( array( 'first', 'second' ), $conformed->get_error_codes() );
		$this->assertSame( '{"status":409,"details":{},"correlation_id":"req-7f3a9c"}', wp_json_encode( $conformed->get_error_data( 'second' ) ), 'Every code gets the members.' );
		$this->assertSame( 'Second.', $conformed->get_error_message( 'second' ) );
	}

	/**
	 * Tests that a `validate_callback` returning `false` — which WordPress records only in `params`,
	 * never in its own `details` — never leaves `param_codes` present but empty: the member is
	 * omitted. A mixed batch, one parameter false and the other a WP_Error, keeps `param_codes` for
	 * the one WordPress gave a code, omits the other, and `params` still names both.
	 *
	 * Planted violation: in ErrorShape::reshaped(), always assign `details[param_codes]` once
	 * WordPress's own `details` member is present, instead of only when it is not empty — the
	 * "false only" case below fails, printing `"param_codes":[]`.
	 *
	 * @since 0.1.0
	 */
	public function test_a_false_validate_callback_omits_param_codes(): void {
		add_action( 'rest_api_init', array( self::class, 'registerValidateFalseRoutes' ) );

		$server     = rest_get_server();
		$translator = $this->translator();

		$false_only = new WP_REST_Request( 'POST', '/fixture-validate-false/v1/one' );
		$false_only->set_body_params( array( 'a' => 'x' ) );

		$only = $translator->conform( $server->dispatch( $false_only )->as_error() );

		$this->assertSame(
			'{"status":400,"details":{"params":{"a":"Invalid parameter."}},"correlation_id":"req-7f3a9c"}',
			wp_json_encode( $only->get_error_data() ),
			'A validate_callback that returns false gets no param_codes entry, and the member itself is omitted rather than emitted empty.'
		);

		$mixed_request = new WP_REST_Request( 'POST', '/fixture-validate-false/v1/two' );
		$mixed_request->set_body_params(
			array(
				'a' => 'x',
				'b' => 'y',
			)
		);

		$mixed = $translator->conform( $server->dispatch( $mixed_request )->as_error() );

		$this->assertSame(
			'{"status":400,"details":{"params":{"a":"Invalid parameter.","b":"b is refused."},"param_codes":{"b":"fixture_refused"}},"correlation_id":"req-7f3a9c"}',
			wp_json_encode( $mixed->get_error_data() ),
			'A mixed batch keeps param_codes only for the parameter WordPress gave a code, and still names both parameters in params.'
		);
	}

	/**
	 * Registers two routes: one arg whose `validate_callback` always returns `false`, and — on the
	 * second route — a second arg whose `validate_callback` always returns a WP_Error. Hooked to
	 * `rest_api_init`.
	 *
	 * @since 0.1.0
	 */
	public static function registerValidateFalseRoutes(): void {
		register_rest_route(
			'fixture-validate-false/v1',
			'/one',
			array(
				'methods'             => 'POST',
				'args'                => array(
					'a' => array( 'validate_callback' => static fn(): bool => false ),
				),
				'callback'            => static fn(): WP_REST_Response => new WP_REST_Response( array( 'ok' => true ) ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'fixture-validate-false/v1',
			'/two',
			array(
				'methods'             => 'POST',
				'args'                => array(
					'a' => array( 'validate_callback' => static fn(): bool => false ),
					'b' => array(
						'validate_callback' => static fn(): WP_Error => new WP_Error( 'fixture_refused', 'b is refused.' ),
					),
				),
				'callback'            => static fn(): WP_REST_Response => new WP_REST_Response( array( 'ok' => true ) ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Tests the default reporter: one line in PHP's error log with the code, the correlation id, the context and the causes.
	 *
	 * @since 0.1.0
	 */
	public function test_the_default_reporter_writes_the_whole_failure_to_the_error_log(): void {
		$log      = (string) wp_tempnam( 'seocart-error-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- captures the default reporter's line in a temporary file; restored below.

		try {
			$translator = new RestErrorTranslator( ErrorTable::compose( DatabaseError::class ), static fn(): string => self::CORRELATION_ID );

			$translator->translate(
				CodedException::because(
					DatabaseError::DuplicateKey,
					array(
						'errno'    => 1062,
						'sqlstate' => '23000',
					),
					StatementDiagnostic::of( self::STATEMENT, self::SERVER_TEXT )
				)
			);
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restores the error log.
		}

		$line = (string) file_get_contents( $log );

		unlink( $log );

		$this->assertStringContainsString( 'SEOCart: internal error database.duplicate_key (correlation id req-7f3a9c): {"errno":1062,"sqlstate":"23000"}; caused by ' . StatementDiagnostic::class . ': INSERT INTO', $line );
		$this->assertStringContainsString( self::SERVER_TEXT, $line, 'The server\'s text goes to the log, for the administrator.' );
		$this->assertSame( 1, substr_count( trim( $line ), "\n" ) + 1, 'The failure is one line.' );
	}

	/**
	 * Builds the translator under test.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null     $correlation_id Optional. What the provider returns. Default CORRELATION_ID.
	 * @param ErrorTable|null $errors         Optional. The table. Default the shared kernel's, the
	 *                                        database's and the fixture catalogs.
	 * @return RestErrorTranslator The translator, reporting to $reported.
	 */
	private function translator( ?string $correlation_id = self::CORRELATION_ID, ?ErrorTable $errors = null ): RestErrorTranslator {
		return new RestErrorTranslator(
			$errors ?? ErrorTable::compose( SupportError::class, DatabaseError::class, FixtureStockError::class, FixtureError::class, DetailedError::class ),
			static fn(): ?string => $correlation_id,
			function ( CodedException $error, ?string $id ): void {
				$this->reported[] = array(
					'error'          => $error,
					'correlation_id' => $id,
				);
			}
		);
	}

	/**
	 * Returns the JSON body the REST API sends for an error.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $error The error.
	 * @return string The body.
	 */
	private static function body( WP_Error $error ): string {
		return (string) wp_json_encode( rest_convert_error_to_response( $error )->get_data() );
	}
}
