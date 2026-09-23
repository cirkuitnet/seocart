<?php
/**
 * RestBinding: where an operation is served in the plugin's REST namespace
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Application\Operations;

use SEOCart\Support\Schema\SchemaException;

defined( 'ABSPATH' ) || exit;

/**
 * The REST route of an operation, written as an OpenAPI path template.
 *
 * This class owns one fact: the address of an operation on the REST surface. The route is written
 * the way the OpenAPI document shows it, `/stock-items/{item_id}/adjustments`, so the document
 * uses it as it is and the REST adapter compiles it into a WordPress route pattern. Each
 * `{parameter}` names an input field, whose value is then read from the URL and from nowhere else.
 *
 * The method is not chosen here freely: OperationDefinition derives it, GET for a read-only
 * operation and the declared WriteMethod otherwise.
 *
 * @since 0.1.0
 */
final class RestBinding {

	/**
	 * The REST namespace every operation is served in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAMESPACE = 'seocart/v1';

	/**
	 * One segment of a route: kebab-case text, or a `{parameter}` in snake_case.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SEGMENT_PATTERN = '/^(?:[a-z0-9]+(?:-[a-z0-9]+)*|\{[a-z][a-z0-9]*(?:_[a-z0-9]+)*\})\z/';

	/**
	 * The route, relative to the namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $route;

	/**
	 * The method of an operation that changes something, or null for a read-only one.
	 *
	 * @since 0.1.0
	 *
	 * @var WriteMethod|null
	 */
	private ?WriteMethod $writeMethod;

	/**
	 * The names of the route's parameters, in the order they appear.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $pathParameters = array();

	/**
	 * Declares the route.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the route does not start with a slash and a text segment, has an
	 *                         empty or malformed segment, or names a parameter twice.
	 *
	 * @param string           $route        The route relative to the namespace, such as
	 *                                       `/stock-items/{item_id}/adjustments`.
	 * @param WriteMethod|null $write_method Optional. The method, for an operation that changes
	 *                                       something. Default null, for a read-only operation.
	 */
	public function __construct( string $route, ?WriteMethod $write_method = null ) {
		if ( ! str_starts_with( $route, '/' ) ) {
			SchemaException::raise( 'The route "%1$s" must start with a slash.', $route );
		}

		$segments = explode( '/', substr( $route, 1 ) );

		foreach ( $segments as $index => $segment ) {
			if ( 1 !== preg_match( self::SEGMENT_PATTERN, $segment ) ) {
				SchemaException::raise( 'The route "%1$s" has a segment that is neither kebab-case text nor a {parameter} in snake_case.', $route );
			}

			if ( ! str_starts_with( $segment, '{' ) ) {
				continue;
			}

			if ( 0 === $index ) {
				SchemaException::raise( 'The route "%1$s" must start with a text segment.', $route );
			}

			$name = substr( $segment, 1, -1 );

			if ( in_array( $name, $this->pathParameters, true ) ) {
				SchemaException::raise( 'The route "%1$s" names the parameter %2$s twice.', $route, $name );
			}

			$this->pathParameters[] = $name;
		}

		$this->route       = $route;
		$this->writeMethod = $write_method;
	}

	/**
	 * Returns the route, as an OpenAPI path template relative to the namespace.
	 *
	 * @since 0.1.0
	 *
	 * @return string The route.
	 */
	public function route(): string {
		return $this->route;
	}

	/**
	 * Returns the declared write method.
	 *
	 * @since 0.1.0
	 *
	 * @return WriteMethod|null The method, or null for a read-only operation.
	 */
	public function writeMethod(): ?WriteMethod {
		return $this->writeMethod;
	}

	/**
	 * Returns the names of the route's parameters.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The input fields read from the URL, in the order they appear.
	 */
	public function pathParameters(): array {
		return $this->pathParameters;
	}
}
