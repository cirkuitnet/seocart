<?php
/**
 * Tests which lockfile entries count as runtime packages
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\WpOrg\RuntimePackages;

/**
 * Covers the runtime/dev split and the licence field of both lockfile formats.
 *
 * @since 0.1.0
 */
final class RuntimePackagesTest extends TestCase {

	/**
	 * Tests that composer.lock's `packages-dev` are ignored and licence lists are alternatives.
	 *
	 * @since 0.1.0
	 */
	public function test_composer_runtime_packages(): void {
		$lock = array(
			'packages'     => array(
				array(
					'name'    => 'vendor/single',
					'version' => '1.0.0',
					'license' => array( 'MIT' ),
				),
				array(
					'name'    => 'vendor/dual',
					'version' => '2.0.0',
					'license' => array( 'GPL-2.0-only', 'MIT' ),
				),
				array(
					'name'    => 'vendor/none',
					'version' => '3.0.0',
				),
				array(
					'name'    => 'vendor/empty',
					'version' => '4.0.0',
					'license' => array(),
				),
			),
			'packages-dev' => array(
				array(
					'name'    => 'vendor/dev-tool',
					'version' => '9.0.0',
					'license' => array( 'proprietary' ),
				),
			),
		);

		$this->assertSame(
			array(
				array(
					'name'    => 'vendor/single',
					'version' => '1.0.0',
					'license' => 'MIT',
				),
				array(
					'name'    => 'vendor/dual',
					'version' => '2.0.0',
					'license' => '(GPL-2.0-only) OR (MIT)',
				),
				array(
					'name'    => 'vendor/none',
					'version' => '3.0.0',
					'license' => null,
				),
				array(
					'name'    => 'vendor/empty',
					'version' => '4.0.0',
					'license' => null,
				),
			),
			RuntimePackages::fromComposerLock( $lock )
		);
	}

	/**
	 * Tests that package-lock.json's root, links and dev-only packages are ignored.
	 *
	 * @since 0.1.0
	 */
	public function test_npm_runtime_packages(): void {
		$lock = array(
			'lockfileVersion' => 3,
			'packages'        => array(
				''                                 => array(
					'name'    => 'seocart',
					'license' => 'GPL-3.0-or-later',
				),
				'node_modules/prod'                => array(
					'version' => '1.0.0',
					'license' => 'ISC',
				),
				'node_modules/prod/node_modules/@scope/nested' => array(
					'version' => '1.1.0',
					'license' => 'MIT',
				),
				'node_modules/legacy'              => array(
					'version' => '0.0.1',
					'license' => array( 'type' => 'BSD-3-Clause' ),
				),
				'node_modules/optional-of-runtime' => array(
					'version'     => '2.0.0',
					'devOptional' => true,
					'license'     => 'Apache-2.0',
				),
				'node_modules/unlicensed'          => array(
					'version' => '3.0.0',
				),
				'node_modules/dev-tool'            => array(
					'version' => '9.0.0',
					'dev'     => true,
					'license' => 'proprietary',
				),
				'node_modules/workspace'           => array(
					'resolved' => 'packages/workspace',
					'link'     => true,
				),
			),
		);

		$this->assertSame(
			array(
				array(
					'name'    => 'prod',
					'version' => '1.0.0',
					'license' => 'ISC',
				),
				array(
					'name'    => '@scope/nested',
					'version' => '1.1.0',
					'license' => 'MIT',
				),
				array(
					'name'    => 'legacy',
					'version' => '0.0.1',
					'license' => 'BSD-3-Clause',
				),
				array(
					'name'    => 'optional-of-runtime',
					'version' => '2.0.0',
					'license' => 'Apache-2.0',
				),
				array(
					'name'    => 'unlicensed',
					'version' => '3.0.0',
					'license' => null,
				),
			),
			RuntimePackages::fromPackageLock( $lock )
		);
	}
}
