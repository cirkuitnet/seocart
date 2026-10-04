<?php
/**
 * Tests that fields declared after the redactor was built join what it redacts
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Logging;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

/**
 * A payment gateway registers while a request runs, after the logger and its redactor may have been built; its settings' names join the redactor then, and only ever add to what it redacts.
 *
 * Planted violation, shown red and removed: make Redactor::declareFields() do nothing: a
 * gateway's credential is then logged under its declared name.
 *
 * @since 0.2.0
 */
final class RedactorDeclaredLaterTest extends TestCase {

	/**
	 * Tests that a secret declared later is dropped and personal data declared later replaced, and that a later personal-data field does not make a secret visible.
	 *
	 * @since 0.2.0
	 */
	public function test_fields_declared_later_are_redacted_from_then_on(): void {
		$redactor = Redactor::fromDeclarations( new DataRegistry( new CapabilityDeclaration() ), self::field( 'webhook_secret', Privacy::Secret ) );
		$context  = array(
			'secret_key'     => 'sk_test_51planted',
			'merchant_email' => 'shop@example.test',
			'webhook_secret' => 'whsec_planted',
			'gateway_id'     => 'example',
		);

		$this->assertSame( 'sk_test_51planted', $redactor->context( $context )['secret_key'], 'Not declared yet, so not redacted yet.' );

		$redactor->declareFields( self::field( 'secret_key', Privacy::Secret ), self::field( 'merchant_email', Privacy::Pii ), self::field( 'webhook_secret', Privacy::Pii ) );

		$this->assertSame(
			array(
				'merchant_email' => Redactor::REDACTED,
				'gateway_id'     => 'example',
			),
			$redactor->context( $context ),
			'A secret is dropped and personal data replaced; a secret stays one.'
		);
	}

	/**
	 * Declares a text field.
	 *
	 * @since 0.2.0
	 *
	 * @param string  $name    The name.
	 * @param Privacy $privacy The privacy class.
	 * @return FieldSpec The field.
	 */
	private static function field( string $name, Privacy $privacy ): FieldSpec {
		return new FieldSpec( name: $name, type: FieldType::String, description: 'A gateway setting.', label: static fn(): string => 'Setting', example: 'x', privacy: $privacy );
	}
}
