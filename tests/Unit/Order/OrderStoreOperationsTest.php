<?php
/**
 * Tests the declaration of the Store API's order-status read
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\RequestHeader;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Interfaces\StoreApi\OrderStoreOperations;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

/**
 * `order.get_status` is a public read of one order, named by its uuid, that answers with nothing personal.
 *
 * Planted violations, each shown red and removed: add the order's email to the resource, as a
 * `Privacy::Pii` field named `email`; add the same field to the fields of each line. The
 * classification test then names it, at the depth it was added.
 *
 * @since 0.1.0
 */
final class OrderStoreOperationsTest extends TestCase {

	/**
	 * Tests that the read is public, served by GET in the Store API, at the order's uuid, and refuses with one code.
	 *
	 * @since 0.1.0
	 */
	public function test_the_read_is_public_by_uuid_with_one_refusal(): void {
		$definition = OrderStoreOperations::getStatus();
		$rest       = $definition->rest();

		$this->assertTrue( $definition->isPublic() );
		$this->assertNull( $definition->publicWrite() );
		$this->assertSame( 'GET', $definition->httpMethod() );
		$this->assertNotNull( $rest );
		$this->assertSame( RestBinding::STORE_NAMESPACE, $rest->restNamespace() );
		$this->assertSame( array( 'uuid' ), $rest->pathParameters() );
		$this->assertSame( array( OrderStoreOperations::KEY => OrderStoreOperations::KEY_HEADER ), array_map( static fn( RequestHeader $header ): string => $header->name, $rest->headers() ), 'The access key is read from its header and nowhere else.' );
		$this->assertSame( array( OrderError::NotFound ), $definition->errors(), 'Every refusal is order.not_found, so the read declares no other code.' );
		$this->assertNull( $definition->abilityName() );
		$this->assertNull( $definition->cli() );
		$this->assertFalse( $definition->isAgentExposed() );
	}

	/**
	 * Tests that the order is named by a required uuid, and the key is an optional secret.
	 *
	 * @since 0.1.0
	 */
	public function test_the_order_is_named_by_its_uuid_and_the_key_is_a_secret(): void {
		$input = self::byName( OrderStoreOperations::getStatus()->input() );

		$this->assertSame( array( 'uuid', 'order_key' ), array_keys( $input ), 'The key\'s name is the order\'s alone: the log redactor drops every context of a secret\'s name, and a job\'s `key` must stay.' );
		$this->assertSame( FieldType::Uuid, $input['uuid']->type() );
		$this->assertTrue( $input['uuid']->isRequired() );
		$this->assertSame( Privacy::Secret, $input[ OrderStoreOperations::KEY ]->privacy(), 'The key is a secret: no answer carries it, and the logs redact it.' );
		$this->assertFalse( $input[ OrderStoreOperations::KEY ]->isRequired(), 'The key is optional: the order\'s customer needs none.' );
	}

	/**
	 * Tests that the answer carries no personal data and no secret, at any depth: a status page needs no address and no email.
	 *
	 * @since 0.1.0
	 */
	public function test_the_answer_carries_nothing_personal(): void {
		$fields = OrderStoreOperations::getStatus()->output()->fields();

		$this->assertSame( FieldType::ObjectList, self::byName( $fields )['lines']->type(), 'The answer carries the order\'s lines, so the walk below reads their fields too.' );
		$this->assertSame( array(), self::personal( $fields, '' ), 'The order-status answer carries a field that is personal data or a secret.' );
	}

	/**
	 * Lists every field, at any depth, that is neither public nor financial.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec[] $fields The fields.
	 * @param string      $path   The path of the object they belong to, empty at the top.
	 * @return list<string> Each such field's path and privacy class.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 */
	private static function personal( array $fields, string $path ): array {
		$found = array();

		foreach ( $fields as $field ) {
			$name = $path . $field->name();

			if ( Privacy::Public !== $field->privacy() && Privacy::Financial !== $field->privacy() ) {
				$found[] = $name . ' is ' . $field->privacy()->value;
			}

			$found = array_merge( $found, self::personal( $field->fields(), $name . '.' ) );
		}

		return $found;
	}

	/**
	 * Indexes fields by name.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec[] $fields The fields.
	 * @return array<string, FieldSpec> The fields, by name, in order.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 */
	private static function byName( array $fields ): array {
		$named = array();

		foreach ( $fields as $field ) {
			$named[ $field->name() ] = $field;
		}

		return $named;
	}
}
