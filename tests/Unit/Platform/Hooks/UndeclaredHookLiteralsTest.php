<?php
/**
 * Tests that every literal `seocart_` filter or action under src/ is declared or an event's action
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Hooks;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Hooks\FilterDeclarations;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Tests\Unit\Support\PhpSource;
use SEOCart\Tools\Docs\HookLiteralScan;

/**
 * A parallel-list gate (rule 11): FilterDeclarations::ALL is a hand-kept list, so this holds it
 * to what src/ actually calls. HookLiteralScan finds every `apply_filters()`/`do_action()` call
 * (plain, `_ref_array` or `_deprecated`, either quote style) under src/ whose first argument is,
 * or starts as, a `seocart_` string literal. Each finding must be either a declared filter, a
 * catalogued event's action (EventEnvelope::hookFor() over Modules::EVENT_CLASSES), or nothing at
 * all — a dynamic finding (built with interpolation or concatenation) is always a violation,
 * because its real name cannot be known from the source.
 *
 * The bridge itself (HookBridge, EventWake) fires events and reads the declared filter through a
 * dynamic expression (a class constant), never a `'seocart_…'` literal, so this scan does not see
 * them; it exists to catch a hand-added filter or action that skipped the declaration
 * FilterDeclarations::ALL and the hooks reference exist for.
 *
 * Planted violation, put back afterwards: add `apply_filters( 'seocart_planted', true )` anywhere
 * under src/. This test then names the file and `seocart_planted`.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class UndeclaredHookLiteralsTest extends TestCase {

	/**
	 * Tests that every literal `seocart_` hook under src/ is declared or an event's action, and
	 * that a dynamic finding is always reported.
	 *
	 * @since 0.1.0
	 */
	public function test_every_literal_seocart_hook_under_src_is_declared_or_an_event_action(): void {
		// Each list's own conformance (every entry really implements the right interface) is
		// already enforced where it is authoritative: HooksReference refuses a bad entry when
		// composer docs:generate runs (GeneratorsTest exercises that on every run). This test only
		// needs each list's declared names, to compare against what src/ literally calls.
		$declared = array();

		foreach ( FilterDeclarations::ALL as $class ) {
			$declared[] = $class::name();
		}

		foreach ( Modules::EVENT_CLASSES as $class ) {
			$declared[] = EventEnvelope::hookFor( $class::eventName() );
		}

		$violations = array();

		foreach ( HookLiteralScan::find( PhpSource::files( 'src' ) ) as $finding ) {
			if ( $finding['dynamic'] || ! in_array( $finding['hook'], $declared, true ) ) {
				$violations[] = $finding['file'] . ': ' . $finding['hook'];
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"Every literal 'seocart_' apply_filters()/do_action() call under src/ must name either a filter declared in FilterDeclarations::ALL or a catalogued event's action, and must not be built dynamically. Undeclared: " . implode( ', ', $violations )
		);
	}
}
