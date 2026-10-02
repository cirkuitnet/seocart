<?php
/**
 * Plants what the two Store API end-to-end tests that need a seeded site need
 *
 * Test tooling, never shipped and never loaded by the plugin. Run it inside a disposable site
 * with SEOCart active, from the plugin's checkout:
 *
 *     wp eval-file tests/E2E/seed.php
 *
 * It refuses a site that is not marked disposable (see tests/Support/Seed/disposable-guard.php): a
 * 10 % code with no limit is a loss on a live store. wp-env sets the environment type; the run
 * still needs SEOCART_SEED_DISPOSABLE=1, and a site from bin/dev/provision-site.sh has both.
 *
 * Nothing in the Store API, the CLI or the admin creates a promotion or enables a currency yet,
 * so the seed plants:
 *
 * - an active promotion code worth 10 % off, with no usage limit and no fixed amount, so it
 *   applies to any cart; and
 * - a currency besides the base currency (EUR, or USD when the base is EUR), enabled, with a
 *   rate from the base currency saved as the next exchange-rate version. Saving a version
 *   supersedes the rates of the version before it, which a disposable site does not mind.
 *
 * The rate goes through the plugin's rates service. The two rows have no service to go through,
 * so they are written by the same test-support writers the integration tests plant with.
 *
 * It is safe to run twice: a promotion and a currency that are already in place are left alone.
 * A promotion with its code that is not what the tests need, or a currency with a row that is not
 * offered, is refused with exit code 1 before anything is written; the seed repairs no row.
 * It prints the two names the end-to-end suite reads, one per line, in the form a shell or a
 * GitHub Actions environment file takes:
 *
 *     SEOCART_E2E_CODE=E2E10
 *     SEOCART_E2E_CURRENCY=EUR
 *
 * These are the one place the values are stated. They are test data, not credentials.
 *
 * `wp eval-file` evaluates the file's code, so it declares no strict types.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Kernel\Kernel;
use SEOCart\Platform\Settings\InternationalSettings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Pricing\Application\ExchangeRates;
use SEOCart\Pricing\Application\ManualRate;
use SEOCart\Pricing\Application\PresentmentCurrencies;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Infrastructure\PricingTables;
use SEOCart\Promotion\Application\PromotionRepository;
use SEOCart\Support\Clock;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Tests\Support\Pricing\CurrencyRows;
use SEOCart\Tests\Support\Promotion\PromotionRows;
use SEOCart\Tests\Support\Promotion\Promotions;

defined( 'ABSPATH' ) || exit;

( static function (): void {
	require_once dirname( __DIR__ ) . '/Support/Seed/disposable-guard.php';
	require_once dirname( __DIR__ ) . '/Support/Seed/test-autoloader.php';

	$code      = 'E2E10';
	$container = Kernel::container();
	$db        = $container->get( Database::class );
	$base      = Currency::of( (string) $container->get( SettingsStore::class )->value( InternationalSettings::BASE_CURRENCY ) );
	$currency  = Currency::of( 'EUR' === $base->code() ? 'USD' : 'EUR' );

	$promotions = $container->get( PromotionRepository::class )->findByCodes( array( $code ) );
	$offered    = null !== $container->get( PresentmentCurrencies::class )->find( $base, $currency );
	$stored     = null !== $db->fetchValue( 'SELECT code FROM %i WHERE code = %s', $db->table( PricingTables::CURRENCIES ), $currency->code() );
	$refusals   = array();

	// A site that was seeded before may hold rows of the same names that are not what the tests need. Refuse before writing anything; rows are never repaired.
	if ( array() !== $promotions && ( null !== $promotions[0]->rejectionFor( $base, $container->get( Clock::class )->now() ) || null !== $promotions[0]->usageLimit || PromotionEffect::FIXED === $promotions[0]->effect->kind ) ) {
		$refusals[] = sprintf( 'The promotion %s exists but is not an active code with no usage limit and no fixed amount that applies now. Delete its row, or use a site that has none.', $code );
	}

	if ( ! $offered && $stored ) {
		$refusals[] = sprintf( 'The currency %s has a row but is not offered: it is disabled or has no rate in the current version. Delete its row, or use a site that has none.', $currency->code() );
	}

	if ( array() !== $refusals ) {
		fwrite( STDERR, 'Error: refused, and nothing was written. ' . implode( ' ', $refusals ) . "\n" );
		exit( 1 );
	}

	if ( array() === $promotions ) {
		PromotionRows::plant( $db, Promotions::uuid( 910000 ), $code );
	}

	// The rate first: a currency is offered only when it has a rate in the current version, so a run that stops between the two leaves nothing half offered.
	if ( ! $offered ) {
		$container->get( ExchangeRates::class )->saveVersion( array( new ManualRate( $base, $currency, Decimal::of( '0.92' ) ) ), Actor::user( 0 ) );
		CurrencyRows::enable( $db, $currency->code() );
	}

	printf( "SEOCART_E2E_CODE=%s\nSEOCART_E2E_CURRENCY=%s\n", $code, $currency->code() );
} )();
