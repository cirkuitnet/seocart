<?php
/**
 * MultilingualFixtures: the multilingual plugin the conformance suite runs against, chosen by the environment
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Localization;

/**
 * Chooses the fixture of the multilingual plugin a run was started with.
 *
 * Owns one fact: which fixture SEOCART_ML_ADAPTER selects. The integration bootstrap loads the
 * plugin itself; this only says which fixture drives it. A later adapter adds its case here.
 *
 * @since 0.1.0
 */
final class MultilingualFixtures {

	/**
	 * The environment variable that names the multilingual plugin.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SWITCH = 'SEOCART_ML_ADAPTER';

	/**
	 * Returns the fixture of the multilingual plugin the run was started with, when that plugin is loaded.
	 *
	 * @since 0.1.0
	 *
	 * @return MultilingualFixture|null The fixture, or null when the run names no multilingual plugin, or it is not loaded.
	 */
	public static function fromEnvironment(): ?MultilingualFixture {
		return match ( getenv( self::SWITCH ) ) {
			'polylang' => PolylangFixture::loaded() ? new PolylangFixture() : null,
			default    => null,
		};
	}
}
