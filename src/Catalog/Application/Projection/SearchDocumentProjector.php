<?php
/**
 * SearchDocumentProjector: keeps each product's search documents in step with its saves
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Projection;

use SEOCart\Catalog\Application\Search\CatalogSearch;
use SEOCart\Catalog\Application\Search\SearchDocuments;
use SEOCart\Catalog\Domain\Event\ProductSaved;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An event the projector does not hear is a wiring error for the developer, never HTML.

/**
 * Rebuilds a product's search documents after each save, one per post that presents it.
 *
 * Owns one fact: when a search document is written. It hears every product save (ProductSaved):
 * the plugin's own, a repair of the generation marker, and a write to a product's post by any other
 * path, which the post lifecycle publishes, since a title changed in Quick Edit changes the words.
 * For each product it reads the documents its posts and variants give (SearchDocuments), whatever
 * each post's status, since the listing reads the status as it is, and hands them to the search
 * (CatalogSearch::index()), which writes only a document that changed or is missing and deletes
 * those of posts the product no longer has; a product with no post left loses them all. A second
 * run writes nothing. The words are read when the event is delivered, never taken from its payload.
 *
 * The hook bridge puts it on its actions before the first dispatch of a process
 * (Modules::EVENT_LISTENERS), so a drain in any process delivers to it.
 *
 * @since 0.2.0
 */
final class SearchDocumentProjector {

	/**
	 * The documents' sources.
	 *
	 * @since 0.2.0
	 *
	 * @var SearchDocuments
	 */
	private SearchDocuments $documents;

	/**
	 * The search, which stores the documents.
	 *
	 * @since 0.2.0
	 *
	 * @var CatalogSearch
	 */
	private CatalogSearch $search;

	/**
	 * Creates the projector. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param SearchDocuments $documents The documents' sources.
	 * @param CatalogSearch   $search    The search, which stores the documents.
	 */
	public function __construct( SearchDocuments $documents, CatalogSearch $search ) {
		$this->documents = $documents;
		$this->search    = $search;
	}

	/**
	 * Rebuilds a saved product's documents: the listener.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the event is not one the projector hears.
	 *
	 * @param DomainEvent   $event    The event.
	 * @param EventEnvelope $envelope Its delivery.
	 */
	public function on( DomainEvent $event, EventEnvelope $envelope ): void {
		unset( $envelope );

		if ( ! $event instanceof ProductSaved ) {
			throw new \LogicException( sprintf( 'The search document projector does not hear %s; list it in Modules::EVENT_LISTENERS only with a case here.', get_class( $event ) ) );
		}

		$this->project( array( $event->productId ) );
	}

	/**
	 * Rebuilds the documents of some products and writes what differs.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $productIds The products.
	 * @return int How many documents were written or deleted.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	public function project( array $productIds ): int {
		if ( array() === $productIds ) {
			return 0;
		}

		$documents = $this->documents->documents( $productIds );
		$written   = $this->search->index( ...array_merge( ...array_values( $documents ) ) );

		foreach ( array_diff( $productIds, array_keys( $documents ) ) as $bare ) {
			$written += $this->search->delete( $bare );
		}

		return $written;
	}
}
