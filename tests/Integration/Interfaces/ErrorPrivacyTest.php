<?php
/**
 * Tests that an error never carries a personal-data or secret value to the client
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Interfaces;

use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Interfaces\Operations\OperationInvoker;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\Doubles\ContextEchoError;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_UnitTestCase;

/**
 * A coded error's context is rendered into the message the client receives, so it must obey the
 * privacy of the fields it repeats, as a serialized output does.
 *
 * The operation is the fixture with one more input, a secret `currency`, and a service that fails
 * with an error whose placeholders are named `note` (personal data), `currency` (secret) and `delta`
 * (public). On every surface the note and the currency must arrive as the redaction marker and the
 * delta as it is — for a user who may see personal data too, because an error is not a resource the
 * user reads.
 *
 * @since 0.1.0
 */
final class ErrorPrivacyTest extends WP_UnitTestCase {

	/**
	 * A note that names a person.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NOTE = 'Call Ada Lovelace on 555-0100';

	/**
	 * A secret value.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SECRET = 'sk-secret-4eC39HqLyjWDarjtT1zdp7dc';

	/**
	 * Discards the REST server and the Abilities registries.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();

		parent::tear_down();
	}

	/**
	 * Tests that the personal-data and secret values of an error's context reach no client, on any surface.
	 *
	 * @since 0.1.0
	 */
	public function test_an_error_redacts_personal_data_and_secrets_everywhere(): void {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'seocart_manage_inventory' );
		$user->add_cap( 'seocart_view_customer_pii' );

		wp_set_current_user( $user->ID );

		$registry = new OperationRegistry();
		$registry->add( 'fixture_stock.adjust_private_stock', array( self::class, 'privateDefinition' ) );

		$surfaces = new OperationSurfaces( $registry, self::echoingService() );
		$outcomes = $surfaces->everywhere(
			self::privateDefinition(),
			array(
				'item_id'  => FixtureStockOperation::EXAMPLE_ITEM,
				'delta'    => 3,
				'note'     => self::NOTE,
				'currency' => self::SECRET,
			)
		);

		foreach ( $outcomes as $surface => $outcome ) {
			$this->assertStringNotContainsString( 'Lovelace', $outcome['text'], "{$surface}: personal data reached the client." );
			$this->assertStringNotContainsString( self::SECRET, $outcome['text'], "{$surface}: a secret reached the client." );
			$this->assertStringContainsString( 'fixture_context.echoed', $outcome['text'], "{$surface}: the error code is missing." );

			$marker = OperationInvoker::REDACTED;

			$this->assertStringContainsString( "The note {$marker} in {$marker} cannot be applied to a change of 3.", $outcome['text'], "{$surface}: the message is not the redacted one." );
		}
	}

	/**
	 * Declares the fixture with a secret input, failing with an error that repeats its inputs.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function privateDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			id: 'fixture_stock.adjust_private_stock',
			label: $fixture->label(),
			summary: 'Adjusts the stock level of one fixture item and fails, repeating its inputs.',
			input: array_merge(
				$fixture->input(),
				array(
					new FieldSpec(
						name: 'currency',
						type: FieldType::String,
						description: 'A secret the operation is given.',
						label: static fn(): string => 'Currency key',
						example: 'key',
						privacy: Privacy::Secret
					),
				)
			),
			output: $fixture->output(),
			capability: $fixture->capability(),
			resource_field: null,
			errors: array( ContextEchoError::Echoed ),
			annotations: $fixture->annotations(),
			service: $fixture->service(),
			rest: new RestBinding( '/fixture-private-stock/{item_id}/adjustments', WriteMethod::Post ),
			ability: 'fixture-adjust-private-stock',
			cli: new CliBinding( array( 'fixture-private-stock', 'adjust' ), array( 'item_id' ) )
		);
	}

	/**
	 * Returns a service that fails with an error repeating the note, the currency and the change.
	 *
	 * @since 0.1.0
	 *
	 * @return object The service.
	 */
	private static function echoingService(): object {
		return new class() {

			/**
			 * Fails, repeating three inputs in the error's context.
			 *
			 * @param array<string, mixed> $input The input.
			 * @return never
			 */
			public function adjust( array $input ): never {
				CodedException::raise(
					ContextEchoError::Echoed,
					array(
						'note'     => (string) $input['note'],
						'currency' => (string) $input['currency'],
						'delta'    => (int) $input['delta'],
					)
				);
			}
		};
	}
}
