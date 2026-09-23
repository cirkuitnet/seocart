<?php
/**
 * Tests the generated OpenAPI document
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Platform\Rest\ErrorShape;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\ResourceSchema;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Fixtures\Operations\FixtureStockError;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Fixtures\Operations\FixtureStoreError;
use SEOCart\Tools\Docs\OpenApiDocument;

/**
 * The document for an empty registry and for the fixture, the rule that a component exists only
 * when something refers to it, the declared error responses and the one `Error` component they
 * all refer to, and the query parameters of a GET.
 *
 * @since 0.1.0
 */
final class OpenApiDocumentTest extends TestCase {

	/**
	 * The paths and components the fixture compiles to, reviewed by hand.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FIXTURE_PATHS_AND_COMPONENTS = <<<'JSON'
		{
		    "paths": {
		        "/fixture-stock/{item_id}/adjustments": {
		            "post": {
		                "operationId": "fixture_stock.adjust_stock",
		                "summary": "Adjusts the stock level of one fixture item by a signed change.",
		                "description": "Requires the capability `seocart_manage_inventory`.",
		                "parameters": [
		                    {
		                        "name": "item_id",
		                        "in": "path",
		                        "required": true,
		                        "description": "Identifier of the stock item whose level is adjusted.",
		                        "schema": {
		                            "type": "string",
		                            "format": "uuid",
		                            "examples": [
		                                "0b6f2c52-7f0a-4c1e-9a55-3f0a4e0f6d21"
		                            ]
		                        }
		                    }
		                ],
		                "requestBody": {
		                    "required": true,
		                    "content": {
		                        "application/json": {
		                            "schema": {
		                                "type": "object",
		                                "properties": {
		                                    "delta": {
		                                        "type": "integer",
		                                        "minimum": -1000000,
		                                        "maximum": 1000000,
		                                        "description": "Signed change to the stock level. A negative value decreases it.",
		                                        "examples": [
		                                            -3
		                                        ]
		                                    },
		                                    "reason": {
		                                        "type": "string",
		                                        "enum": [
		                                            "recount",
		                                            "damage",
		                                            "return",
		                                            "correction"
		                                        ],
		                                        "default": "correction",
		                                        "description": "Why the stock level changed.",
		                                        "examples": [
		                                            "recount"
		                                        ]
		                                    },
		                                    "note": {
		                                        "type": "string",
		                                        "maxLength": 500,
		                                        "description": "Free-text note stored with the adjustment.",
		                                        "examples": [
		                                            "Two units were found behind the shelf."
		                                        ]
		                                    }
		                                },
		                                "required": [
		                                    "delta"
		                                ]
		                            }
		                        }
		                    }
		                },
		                "responses": {
		                    "200": {
		                        "description": "The operation succeeded. The body is the FixtureStockLevel resource.",
		                        "content": {
		                            "application/json": {
		                                "schema": {
		                                    "$ref": "#/components/schemas/FixtureStockLevel"
		                                }
		                            }
		                        }
		                    },
		                    "400": {
		                        "description": "The request does not match the input schema: `rest_invalid_param` or `rest_missing_callback_param`.",
		                        "content": {
		                            "application/json": {
		                                "schema": {
		                                    "$ref": "#/components/schemas/Error"
		                                }
		                            }
		                        }
		                    },
		                    "401": {
		                        "description": "No user is logged in, and the operation requires the capability `seocart_manage_inventory`: `rest_forbidden`.",
		                        "content": {
		                            "application/json": {
		                                "schema": {
		                                    "$ref": "#/components/schemas/Error"
		                                }
		                            }
		                        }
		                    },
		                    "403": {
		                        "description": "The user does not hold the capability `seocart_manage_inventory`: `rest_forbidden`.",
		                        "content": {
		                            "application/json": {
		                                "schema": {
		                                    "$ref": "#/components/schemas/Error"
		                                }
		                            }
		                        }
		                    },
		                    "409": {
		                        "description": "`fixture_stock.insufficient`: You asked to remove {requested}, but only {available} are in stock.",
		                        "content": {
		                            "application/json": {
		                                "schema": {
		                                    "$ref": "#/components/schemas/Error"
		                                }
		                            }
		                        }
		                    }
		                }
		            }
		        }
		    },
		    "components": {
		        "schemas": {
		            "Error": {
		                "type": "object",
		                "description": "An error. WordPress writes it as its code, its message and its data; the data members are the same for every error of these routes, including WordPress's own refusal of a request that does not match the input schema or of a user who lacks the capability, but not its refusal of a JSONP callback, which it sends before any route is matched.",
		                "properties": {
		                    "code": {
		                        "type": "string",
		                        "description": "The error code: one of those in docs/reference/errors.md, or a code of the WordPress REST API such as `rest_invalid_param` or `rest_forbidden`."
		                    },
		                    "message": {
		                        "type": "string",
		                        "description": "What went wrong, for people, in the language of the site. An internal error has a generic message."
		                    },
		                    "data": {
		                        "type": "object",
		                        "properties": {
		                            "status": {
		                                "type": "integer",
		                                "description": "The HTTP status of the error."
		                            },
		                            "details": {
		                                "type": "object",
		                                "description": "The values the message was built from, keyed by name. For an error WordPress raised, such as its refusal of a request that does not match the input schema, every other member of its data under the name WordPress gave it, such as `params`. Always an object, and empty for an internal error."
		                            },
		                            "correlation_id": {
		                                "type": [
		                                    "string",
		                                    "null"
		                                ],
		                                "description": "The identifier of the request the error happened in, which the site's log records with the error; null when the request has none."
		                            }
		                        },
		                        "required": [
		                            "status",
		                            "details",
		                            "correlation_id"
		                        ]
		                    }
		                },
		                "required": [
		                    "code",
		                    "message",
		                    "data"
		                ]
		            },
		            "FixtureStockLevel": {
		                "type": "object",
		                "properties": {
		                    "item_id": {
		                        "type": "string",
		                        "format": "uuid",
		                        "description": "Identifier of the stock item whose level is adjusted.",
		                        "examples": [
		                            "0b6f2c52-7f0a-4c1e-9a55-3f0a4e0f6d21"
		                        ]
		                    },
		                    "on_hand": {
		                        "type": "integer",
		                        "description": "Stock level of the item after the adjustment.",
		                        "examples": [
		                            12
		                        ]
		                    },
		                    "reason": {
		                        "type": "string",
		                        "enum": [
		                            "recount",
		                            "damage",
		                            "return",
		                            "correction"
		                        ],
		                        "description": "Why the stock level changed.",
		                        "examples": [
		                            "recount"
		                        ]
		                    },
		                    "note": {
		                        "type": [
		                            "string",
		                            "null"
		                        ],
		                        "description": "Free-text note stored with the adjustment, or null when there is none.",
		                        "examples": [
		                            "Two units were found behind the shelf."
		                        ]
		                    }
		                },
		                "required": [
		                    "item_id",
		                    "on_hand",
		                    "reason"
		                ]
		            }
		        }
		    }
		}
		JSON;

	/**
	 * Sets up Brain Monkey: gettext returns its text, as it does when no translation is loaded.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests the document of an empty registry: the information and the server, no path and no component.
	 *
	 * @since 0.1.0
	 */
	public function test_an_empty_registry_has_no_path_and_no_component(): void {
		$generator = new OpenApiDocument( new OperationRegistry(), ErrorTable::compose( SupportError::class ) );
		$result    = $generator->generate( '' );
		$document  = self::decode( $result->content );

		$this->assertSame( 'docs/openapi.json', $generator->target() );
		$this->assertSame( array(), $result->skipped );
		$this->assertSame( '3.1.0', $document['openapi'] );
		$this->assertSame( 'v1', $document['info']['version'] );
		$this->assertSame( OpenApiDocument::SCHEMA_DIALECT, $document['jsonSchemaDialect'] );
		$this->assertSame( '{site}/wp-json/seocart/v1', $document['servers'][0]['url'] );
		$this->assertStringContainsString( '"paths": {}', $result->content, 'An empty path list is a JSON object, not an array.' );
		$this->assertArrayNotHasKey( 'components', $document, 'No operation refers to a component, so there must be none.' );
	}

	/**
	 * Tests the fixture's path and component against the structure reviewed by hand.
	 *
	 * @since 0.1.0
	 */
	public function test_the_fixture_is_documented_as_reviewed(): void {
		$document = self::decode( self::fixtureDocument() );
		$expected = self::decode( self::FIXTURE_PATHS_AND_COMPONENTS );

		$this->assertSame( $expected['paths'], $document['paths'] );
		$this->assertSame( $expected['components'], $document['components'] );
	}

	/**
	 * Tests that every component is referred to and every reference resolves.
	 *
	 * @since 0.1.0
	 */
	public function test_every_component_is_referred_to_and_every_reference_resolves(): void {
		$content  = self::fixtureDocument();
		$document = self::decode( $content );

		preg_match_all( '~"\$ref": "#/components/schemas/([A-Za-z0-9]+)"~', $content, $matches );

		$referenced = array_values( array_unique( $matches[1] ) );
		$declared   = array_keys( $document['components']['schemas'] );

		sort( $referenced );
		sort( $declared );

		$this->assertNotSame( array(), $referenced );
		$this->assertSame( $declared, $referenced );
	}

	/**
	 * Tests that the secret output field appears nowhere in the document.
	 *
	 * @since 0.1.0
	 */
	public function test_a_secret_field_is_nowhere_in_the_document(): void {
		$this->assertStringNotContainsString( 'audit_token', self::fixtureDocument() );
	}

	/**
	 * Tests that each declared error code is listed under its status, with its English message.
	 *
	 * @since 0.1.0
	 */
	public function test_each_declared_error_is_listed_under_its_status(): void {
		$responses = self::decode( self::fixtureDocument() )['paths'][ FixtureStockOperation::ROUTE ]['post']['responses'];

		$this->assertSame( array( '200', '400', '401', '403', '409' ), array_map( 'strval', array_keys( $responses ) ) );
		$this->assertSame( '`fixture_stock.insufficient`: You asked to remove {requested}, but only {available} are in stock.', $responses['409']['description'] );
	}

	/**
	 * Tests that every error response refers to the one Error component, and nothing else does.
	 *
	 * @since 0.1.0
	 */
	public function test_every_error_response_refers_to_the_error_component(): void {
		$document = self::decode( self::fixtureDocument() );
		$error    = array( '$ref' => '#/components/schemas/' . OpenApiDocument::ERROR_COMPONENT );
		$checked  = 0;

		foreach ( $document['paths'] as $operations ) {
			foreach ( $operations as $operation ) {
				foreach ( $operation['responses'] as $status => $response ) {
					if ( (int) $status < 400 ) {
						$this->assertNotSame( $error, $response['content']['application/json']['schema'] ?? null, "The {$status} response is not an error." );
						continue;
					}

					$this->assertSame( $error, $response['content']['application/json']['schema'] ?? null, "The {$status} response does not refer to the Error component." );
					++$checked;
				}
			}
		}

		$this->assertSame( 4, $checked, 'The fixture has four error responses: 400, 401, 403 and 409.' );
		$this->assertArrayHasKey( OpenApiDocument::ERROR_COMPONENT, $document['components']['schemas'] );
	}

	/**
	 * Tests that the Error component carries exactly ErrorShape's data members, required, with ErrorShape's descriptions.
	 *
	 * @since 0.1.0
	 */
	public function test_the_error_component_carries_the_members_of_the_error_shape(): void {
		$component = self::decode( self::fixtureDocument() )['components']['schemas'][ OpenApiDocument::ERROR_COMPONENT ];
		$data      = $component['properties']['data'];

		$this->assertSame( array( 'code', 'message', 'data' ), $component['required'] );
		$this->assertSame( array_keys( ErrorShape::members() ), array_keys( $data['properties'] ) );
		$this->assertSame( array_keys( ErrorShape::members() ), $data['required'] );
		$this->assertSame( array_values( ErrorShape::members() ), array_column( $data['properties'], 'description' ) );
	}

	/**
	 * Tests that an internal code is documented without its message, which no client receives.
	 *
	 * @since 0.1.0
	 */
	public function test_an_internal_code_is_documented_without_its_message(): void {
		$registry = new OperationRegistry();
		$registry->add( 'fixture_stock.adjust_contended_stock', array( self::class, 'contendedDefinition' ) );

		$responses = self::decode( ( new OpenApiDocument( $registry, ErrorTable::compose( SupportError::class, DatabaseError::class ) ) )->generate( '' )->content )['paths']['/fixture-contended-stock/{item_id}']['post']['responses'];

		$this->assertSame( '`database.transaction_retryable`: an internal failure, answered with a generic message and empty details. The site\'s error log has what went wrong, under the correlation id.', $responses['503']['description'] );
		$this->assertStringNotContainsString( 'SQLSTATE', (string) json_encode( $responses ) );
	}

	/**
	 * Tests that a code any write may raise is documented on every changing operation, and on no read-only one.
	 *
	 * @since 0.1.0
	 */
	public function test_a_code_any_write_may_raise_is_documented_on_every_changing_operation(): void {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );
		$registry->add( 'fixture_stock.show_stock', array( self::class, 'shownDefinition' ) );

		$paths = self::decode( ( new OpenApiDocument( $registry, ErrorTable::compose( SupportError::class, FixtureStockError::class, FixtureStoreError::class ) ) )->generate( '' )->content )['paths'];

		$this->assertSame( '`fixture_store.unavailable`: The store is being updated. Try again in a minute.', $paths[ FixtureStockOperation::ROUTE ]['post']['responses']['503']['description'] ?? null, 'The changing operation does not list it.' );
		$this->assertArrayNotHasKey( '503', $paths['/fixture-stock/{item_id}']['get']['responses'], 'A read-only operation lists only what it declares.' );
	}

	/**
	 * Tests that a resource named like the Error component fails the run instead of replacing it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_resource_named_like_the_error_component_fails(): void {
		$registry = new OperationRegistry();
		$registry->add( 'fixture_stock.show_stock', array( self::class, 'errorNamedDefinition' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'A resource is named Error' );

		( new OpenApiDocument( $registry, self::fixtureErrors() ) )->generate( '' );
	}

	/**
	 * Tests that a read-only operation is documented as GET, with its other inputs as query parameters.
	 *
	 * @since 0.1.0
	 */
	public function test_a_read_only_operation_takes_its_inputs_in_the_query(): void {
		$registry = new OperationRegistry();
		$registry->add( 'fixture_stock.show_stock', array( self::class, 'readOnlyDefinition' ) );

		$operation = self::decode( ( new OpenApiDocument( $registry, self::fixtureErrors() ) )->generate( '' )->content )['paths']['/fixture-stock/{item_id}']['get'];

		$this->assertArrayNotHasKey( 'requestBody', $operation );
		$this->assertSame(
			array( array( 'item_id', 'path', true ), array( 'history', 'query', false ) ),
			array_map( static fn( array $parameter ): array => array( $parameter['name'], $parameter['in'], $parameter['required'] ), $operation['parameters'] )
		);
	}

	/**
	 * Tests that two different resources under one name fail the run instead of one hiding the other.
	 *
	 * @since 0.1.0
	 */
	public function test_two_different_resources_with_one_name_fail(): void {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );
		$registry->add( 'fixture_stock.show_stock', array( self::class, 'readOnlyDefinition' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Two different resources are named FixtureStockLevel' );

		( new OpenApiDocument( $registry, self::fixtureErrors() ) )->generate( '' );
	}

	/**
	 * Tests that the generator fails, rather than skips, when a declared code has no row in the table.
	 *
	 * @since 0.1.0
	 */
	public function test_a_declared_code_without_a_row_fails(): void {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'fixture_stock.insufficient is not in this error table' );

		( new OpenApiDocument( $registry, ErrorTable::compose( SupportError::class ) ) )->generate( '' );
	}

	/**
	 * Declares a read-only variant of the fixture, whose resource differs from the fixture's under the same name.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function readOnlyDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();
		$history = new FieldSpec(
			name: 'history',
			type: FieldType::Integer,
			description: 'How many past adjustments to include.',
			label: static fn(): string => 'History',
			example: 3,
			minimum: 0
		);

		return new OperationDefinition(
			id: 'fixture_stock.show_stock',
			label: $fixture->label(),
			summary: 'Shows the stock level of one fixture item.',
			input: array( $fixture->input()[0], $history ),
			output: new ResourceSchema( 'FixtureStockLevel', array( $fixture->output()->fields()[0] ) ),
			capability: 'seocart_manage_inventory',
			resource_field: null,
			errors: array(),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: $fixture->service(),
			rest: new RestBinding( '/fixture-stock/{item_id}' )
		);
	}

	/**
	 * Declares a variant of the fixture that may fail with an internal database code.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function contendedDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			id: 'fixture_stock.adjust_contended_stock',
			label: $fixture->label(),
			summary: 'Adjusts the stock level of one fixture item, and may meet a deadlock.',
			input: array( $fixture->input()[0], $fixture->input()[1] ),
			output: new ResourceSchema( 'FixtureContendedStock', array( $fixture->output()->fields()[0] ) ),
			capability: 'seocart_manage_inventory',
			resource_field: null,
			errors: array( DatabaseError::TransactionRetryable ),
			annotations: new Annotations( read_only: false, destructive: false, idempotent: false ),
			service: $fixture->service(),
			rest: new RestBinding( '/fixture-contended-stock/{item_id}', WriteMethod::Post )
		);
	}

	/**
	 * Declares a read-only variant of the fixture with a resource of its own.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function shownDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			id: 'fixture_stock.show_stock',
			label: $fixture->label(),
			summary: 'Shows the stock level of one fixture item.',
			input: array( $fixture->input()[0] ),
			output: new ResourceSchema( 'FixtureStockShown', array( $fixture->output()->fields()[0] ) ),
			capability: 'seocart_manage_inventory',
			resource_field: null,
			errors: array(),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: $fixture->service(),
			rest: new RestBinding( '/fixture-stock/{item_id}' )
		);
	}

	/**
	 * Declares a read-only variant of the fixture whose resource is named Error.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function errorNamedDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			id: 'fixture_stock.show_stock',
			label: $fixture->label(),
			summary: 'Shows the stock level of one fixture item.',
			input: array( $fixture->input()[0] ),
			output: new ResourceSchema( OpenApiDocument::ERROR_COMPONENT, array( $fixture->output()->fields()[0] ) ),
			capability: 'seocart_manage_inventory',
			resource_field: null,
			errors: array(),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: $fixture->service(),
			rest: new RestBinding( '/fixture-stock/{item_id}' )
		);
	}

	/**
	 * Generates the document for a registry holding the fixture.
	 *
	 * @since 0.1.0
	 *
	 * @return string The document.
	 */
	private static function fixtureDocument(): string {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );

		return ( new OpenApiDocument( $registry, self::fixtureErrors() ) )->generate( '' )->content;
	}

	/**
	 * Returns the error table the fixture's document is generated with.
	 *
	 * @since 0.1.0
	 *
	 * @return ErrorTable The shared kernel's catalog and the fixture's.
	 */
	private static function fixtureErrors(): ErrorTable {
		return ErrorTable::compose( SupportError::class, FixtureStockError::class );
	}

	/**
	 * Decodes a JSON document.
	 *
	 * @since 0.1.0
	 *
	 * @param string $json The document.
	 * @return array<string, mixed> The decoded document.
	 */
	private static function decode( string $json ): array {
		$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

		self::assertIsArray( $decoded );

		return $decoded;
	}
}
