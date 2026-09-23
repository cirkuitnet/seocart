<?php
/**
 * Tests that the capability guarding personal data in outputs is one the plugin declares
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Interfaces;

use PHPUnit\Framework\TestCase;
use SEOCart\Interfaces\Operations\OperationInvoker;
use SEOCart\Platform\Authorization\CapabilityDeclaration;

/**
 * The invoker names the capability that lets a user see personal-data fields; the capability
 * declaration is the authority on which capabilities exist. A misspelled name would be a capability
 * nobody holds, and personal data would silently vanish from every output, so the two are pinned.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class PersonalDataCapabilityTest extends TestCase {

	/**
	 * Tests that the personal-data capability is a declared primitive of the data-sensitivity group.
	 *
	 * @since 0.1.0
	 */
	public function test_the_personal_data_capability_is_a_declared_sensitivity_primitive(): void {
		$declaration = new CapabilityDeclaration();

		$this->assertTrue( $declaration->isPrimitive( OperationInvoker::PERSONAL_DATA_CAPABILITY ), OperationInvoker::PERSONAL_DATA_CAPABILITY . ' is not a primitive the plugin declares.' );
		$this->assertSame( CapabilityDeclaration::GROUP_SENSITIVITY, $declaration->group( OperationInvoker::PERSONAL_DATA_CAPABILITY ) );
	}
}
