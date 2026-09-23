<?php
/**
 * AbilitiesAdapter: registers every operation that has an ability
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Interfaces\Operations;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers each operation that declares an ability with the WordPress Abilities API.
 *
 * This class owns one fact: how an operation becomes an ability. Abilities are an adapter over the
 * application services, like the REST routes and the commands, never the other way round. Each
 * ability is registered in the `seocart` category with:
 *
 * - the operation's label, translated now, and its summary as the description;
 * - the compiled input and output schemas, which WordPress validates the input and the output
 *   against before and after the service runs;
 * - `meta.annotations` from the declaration, which is how an agent's client decides whether to ask
 *   a person first;
 * - `meta.public`, which exposes the ability to clients such as the REST API and agents, only when
 *   the definition allows it. A destructive operation, or one that moves money or reads personal
 *   data in bulk, cannot be declared with it;
 * - PermissionFactory's check and OperationInvoker's execution, both on the input OperationInvoker
 *   prepared, so the check sees the value the service acts on.
 *
 * WordPress initialises the Abilities registry on the first request that asks for it, never on an
 * idle one. The kernel hooks the two registration methods:
 *
 *     add_action( 'wp_abilities_api_categories_init', array( $abilities_adapter, 'registerCategory' ) );
 *     add_action( 'wp_abilities_api_init', array( $abilities_adapter, 'registerAbilities' ) );
 *
 * @since 0.1.0
 */
final class AbilitiesAdapter {

	/**
	 * The ability category every SEOCart ability belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CATEGORY = 'seocart';

	/**
	 * The operations.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationRegistry
	 */
	private OperationRegistry $registry;

	/**
	 * Runs an operation's service.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationInvoker
	 */
	private OperationInvoker $invoker;

	/**
	 * Creates the adapter. Registers nothing yet.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $registry The operations.
	 * @param OperationInvoker  $invoker  Runs an operation's service.
	 */
	public function __construct( OperationRegistry $registry, OperationInvoker $invoker ) {
		$this->registry = $registry;
		$this->invoker  = $invoker;
	}

	/**
	 * Registers the `seocart` category, when there is an ability to put in it. Hooked to
	 * `wp_abilities_api_categories_init`.
	 *
	 * @since 0.1.0
	 */
	public function registerCategory(): void {
		if ( array() === $this->abilities() ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Store', 'seocart' ),
				'description' => __( 'Operations on the SEOCart store.', 'seocart' ),
			)
		);
	}

	/**
	 * Registers the ability of every operation that has one. Hooked to `wp_abilities_api_init`.
	 *
	 * @since 0.1.0
	 */
	public function registerAbilities(): void {
		foreach ( $this->abilities() as $definition ) {
			$operation = new CompiledOperation( $definition );

			wp_register_ability(
				(string) $definition->abilityName(),
				array(
					'label'               => ( $definition->label() )(),
					'description'         => $definition->summary(),
					'category'            => self::CATEGORY,
					'input_schema'        => $operation->inputSchema(),
					'output_schema'       => $operation->outputSchema(),
					'execute_callback'    => function ( $input = null ) use ( $operation ): array|WP_Error {
						$prepared = $this->invoker->prepare( $operation, is_array( $input ) ? $input : array() );

						return $prepared instanceof WP_Error ? $prepared : $this->invoker->invoke( $operation, $prepared );
					},
					'permission_callback' => function ( $input = null ) use ( $operation, $definition ): bool {
						$prepared = $this->invoker->prepare( $operation, is_array( $input ) ? $input : array() );

						return ! $prepared instanceof WP_Error && PermissionFactory::allows( $definition, $prepared );
					},
					'meta'                => array(
						'annotations' => $definition->annotations()->toArray(),
						'public'      => $definition->isAgentExposed(),
					),
				)
			);
		}
	}

	/**
	 * Returns the operations that have an ability.
	 *
	 * @since 0.1.0
	 *
	 * @return list<OperationDefinition> The operations, in registry order.
	 */
	private function abilities(): array {
		return array_values(
			array_filter(
				$this->registry->all(),
				static fn( OperationDefinition $definition ): bool => null !== $definition->abilityName()
			)
		);
	}
}
