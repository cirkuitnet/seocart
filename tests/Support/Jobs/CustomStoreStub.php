<?php
/**
 * CustomStoreStub: an Action Scheduler store another plugin chose
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Jobs;

/**
 * Stands in for a store another plugin gives Action Scheduler with the `action_scheduler_store_class` filter.
 *
 * Owns one fact: what a custom store looks like to SEOCart. It is a subclass of the library's
 * table store, the usual way such a store is written, and it changes nothing, so the library
 * keeps working in the test; the plugin must still refuse to run jobs from its own triggers,
 * because nothing proves that a subclass keeps the same tables and ids.
 *
 * @since 0.1.0
 */
final class CustomStoreStub extends \ActionScheduler_DBStore {
}
