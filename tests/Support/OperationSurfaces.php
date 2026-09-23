<?php
/**
 * OperationSurfaces: registers a registry's operations on all three surfaces and calls them, for tests
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Interfaces\Operations\AbilitiesAdapter;
use SEOCart\Interfaces\Operations\CliAdapter;
use SEOCart\Interfaces\Operations\CliCommand;
use SEOCart\Interfaces\Operations\OperationInvoker;
use SEOCart\Interfaces\Operations\RestAdapter;
use SEOCart\Tests\Fixtures\Operations\FixtureStockService;
use SEOCart\Tests\Support\Doubles\TableErrorTranslator;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Wires the three operation adapters the way the kernel will, and records what the command line prints.
 *
 * The REST routes are registered on `rest_api_init` of a freshly booted server; the abilities on the
 * init hooks of fresh Abilities registries, which WordPress otherwise builds once per process; the
 * commands through a recorder that stands in for WP_CLI::add_command(), with recorders for the
 * printed result and the reported failure. Every application service is resolved to one object —
 * a FixtureStockService unless the test passes its own — so a test can read every call it received,
 * and every unexpected failure the invoker reports is recorded. The test framework restores the
 * hooks after each test; discard() resets the REST server and the Abilities registries.
 *
 * @since 0.1.0
 */
final class OperationSurfaces {

	/**
	 * The service every operation runs.
	 *
	 * @since 0.1.0
	 *
	 * @var FixtureStockService
	 */
	public FixtureStockService $service;

	/**
	 * The commands registered, keyed by name, each with the arguments it was registered with.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{command: CliCommand, args: array<string, mixed>}>
	 */
	public array $commands = array();

	/**
	 * What the last command printed: the item and the format, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var array{item: array<string, mixed>, format: string}|null
	 */
	public ?array $printed = null;

	/**
	 * The failure the last command reported, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	public ?string $failure = null;

	/**
	 * Every unexpected failure the invoker reported, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<\Throwable>
	 */
	public array $reported = array();

	/**
	 * The operations.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationRegistry
	 */
	private OperationRegistry $registry;

	/**
	 * The invoker every adapter shares.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationInvoker
	 */
	private OperationInvoker $invoker;

	/**
	 * Wires the adapters for a registry and registers every surface.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $registry The operations.
	 * @param object|null       $service  Optional. The object every service resolves to. Default the
	 *                                    FixtureStockService in $service.
	 */
	public function __construct( OperationRegistry $registry, ?object $service = null ) {
		$this->registry = $registry;
		$this->service  = new FixtureStockService();
		$resolved       = $service ?? $this->service;
		$this->invoker  = new OperationInvoker(
			static function ( string $class_name ) use ( $resolved ): object {
				unset( $class_name );

				return $resolved;
			},
			new TableErrorTranslator(),
			function ( \Throwable $failure ): void {
				$this->reported[] = $failure;
			}
		);

		self::discard();

		$abilities = new AbilitiesAdapter( $this->registry, $this->invoker );

		add_action( 'rest_api_init', array( new RestAdapter( $this->registry, $this->invoker ), 'register' ) );
		add_action( 'wp_abilities_api_categories_init', array( $abilities, 'registerCategory' ) );
		add_action( 'wp_abilities_api_init', array( $abilities, 'registerAbilities' ) );

		( new CliAdapter( $this->registry, $this->invoker ) )->register(
			function ( string $name, callable $command, array $args ): void {
				\PHPUnit\Framework\Assert::assertInstanceOf( CliCommand::class, $command );

				$this->commands[ $name ] = array(
					'command' => $command,
					'args'    => $args,
				);
			},
			function ( array $item, string $format ): void {
				$this->printed = array(
					'item'   => $item,
					'format' => $format,
				);
			},
			function ( string $message ): void {
				$this->failure = $message;
			}
		);

		rest_get_server();
	}

	/**
	 * Discards the REST server and the Abilities registries, so the next use builds them again.
	 *
	 * @since 0.1.0
	 */
	public static function discard(): void {
		global $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- resets core's REST server global: the next use builds a new server and fires rest_api_init again.
		$wp_rest_server = null;

		foreach ( array( WP_Abilities_Registry::class, WP_Ability_Categories_Registry::class ) as $registry ) {
			( new \ReflectionProperty( $registry, 'instance' ) )->setValue( null, null );
		}
	}

	/**
	 * Returns the REST server the routes are registered on.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Server The server.
	 */
	public function server(): WP_REST_Server {
		return rest_get_server();
	}

	/**
	 * Sends a REST request to a route of the plugin's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $route  The route below the namespace, such as `/fixture-stock/<id>/adjustments`.
	 * @param array<string, mixed> $body   Optional. The JSON body. Default none.
	 * @param array<string, mixed> $query  Optional. The query parameters. Default none.
	 * @return WP_REST_Response The response; an error is a response too.
	 */
	public function rest( string $method, string $route, array $body = array(), array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . RestBinding::NAMESPACE . $route );

		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		$request->set_query_params( $query );

		return rest_do_request( $request );
	}

	/**
	 * Executes an ability.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name  The ability name.
	 * @param mixed  $input The input.
	 * @return mixed The result, or a WP_Error.
	 */
	public function ability( string $name, $input ) {
		$ability = wp_get_ability( $name );

		\PHPUnit\Framework\Assert::assertNotNull( $ability, "The ability {$name} is not registered." );

		return $ability->execute( $input );
	}

	/**
	 * Runs one input on the three surfaces of one operation, as each surface's client would send it.
	 *
	 * The REST route gets the route parameters in its path, as they are, and every other value in a
	 * JSON body; the ability gets the input object; the command gets the positional values and every
	 * other value as option text. Each outcome is also given as one string, so a test can search
	 * everything a client received.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationDefinition  $definition The operation, bound to all three surfaces.
	 * @param array<string, mixed> $input      The input, keyed by wire name.
	 * @return array<string, array{result: mixed, text: string}> The outcome of `rest`, `ability` and `cli`.
	 */
	public function everywhere( OperationDefinition $definition, array $input ): array {
		$rest = $definition->rest();
		$cli  = $definition->cli();

		\PHPUnit\Framework\Assert::assertNotNull( $rest, 'The operation has no REST route.' );
		\PHPUnit\Framework\Assert::assertNotNull( $cli, 'The operation has no command.' );

		$route = $rest->route();
		$body  = $input;

		foreach ( $rest->pathParameters() as $name ) {
			$route = str_replace( '{' . $name . '}', (string) $input[ $name ], $route );
			unset( $body[ $name ] );
		}

		$args  = array();
		$assoc = $input;

		foreach ( $cli->positional() as $name ) {
			$args[] = (string) $input[ $name ];
			unset( $assoc[ $name ] );
		}

		$response = $this->rest( (string) $definition->httpMethod(), $route, $body );
		$ability  = $this->ability( (string) $definition->abilityName(), $input );
		$command  = $this->cli( $cli->command(), $args, array_map( 'strval', $assoc ) );

		return array(
			'rest'    => array(
				'result' => $response,
				'text'   => $response->get_status() . ' ' . wp_json_encode( $response->get_data() ),
			),
			'ability' => array(
				'result' => $ability,
				'text'   => $ability instanceof \WP_Error
					? $ability->get_error_code() . ' ' . $ability->get_error_message() . ' ' . wp_json_encode( $ability->get_error_data() )
					: (string) wp_json_encode( $ability ),
			),
			'cli'     => array(
				'result' => $command,
				'text'   => (string) wp_json_encode( $command ),
			),
		);
	}

	/**
	 * Runs a command and returns what it printed and what it reported.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name       The command, as registered, such as `seocart fixture-stock adjust`.
	 * @param string[]             $args       The positional arguments.
	 * @param array<string, mixed> $assoc_args The options.
	 * @return array{printed: array{item: array<string, mixed>, format: string}|null, failure: string|null} The outcome.
	 *
	 * @phpstan-param list<string> $args
	 */
	public function cli( string $name, array $args, array $assoc_args ): array {
		\PHPUnit\Framework\Assert::assertArrayHasKey( $name, $this->commands, "The command {$name} is not registered." );

		$this->printed = null;
		$this->failure = null;

		( $this->commands[ $name ]['command'] )( $args, $assoc_args );

		return array(
			'printed' => $this->printed,
			'failure' => $this->failure,
		);
	}
}
