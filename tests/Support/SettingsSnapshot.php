<?php
/**
 * SettingsSnapshot: everything the settings declarations answer, as plain data
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsOperations;

/**
 * Builds the production settings registry and both settings operations, compiles every dialect,
 * and returns what they answer.
 *
 * Owns one fact: what "the settings declarations answer the same" means, so the process without
 * WordPress (tests/Support/settings-probe.php) and the test process compare the same thing.
 *
 * @since 0.1.0
 */
final class SettingsSnapshot {

	/**
	 * Takes the snapshot.
	 *
	 * @since 0.1.0
	 *
	 * @return array{options: array<string, list<array<string, mixed>>>, operations: array<string, array<string, mixed>>} The answers.
	 */
	public static function take(): array {
		$options = array();

		foreach ( Settings::registry()->options() as $option => $settings ) {
			$options[ $option ] = array_map(
				static fn( Setting $setting ): array => array(
					'name'    => $setting->name(),
					'group'   => $setting->group(),
					'storage' => $setting->storage()->name,
					'exposed' => $setting->isExposed(),
					'default' => $setting->field()->defaultValue(),
				),
				$settings
			);
		}

		$operations = array();

		foreach ( array( SettingsOperations::get(), SettingsOperations::update() ) as $definition ) {
			$compiled = new CompiledOperation( $definition );

			$operations[ $definition->id() ] = array(
				'method'         => $definition->httpMethod(),
				'rest_arguments' => $compiled->restArguments(),
				'input_schema'   => $compiled->inputSchema(),
				'output_schema'  => $compiled->outputSchema(),
				'cli_synopsis'   => $compiled->cliSynopsis(),
				'errors'         => array_map( static fn( $code ): string => (string) $code->value, $definition->errors() ),
			);
		}

		return array(
			'options'    => $options,
			'operations' => $operations,
		);
	}
}
