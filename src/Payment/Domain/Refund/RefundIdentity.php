<?php
/**
 * RefundIdentity: the uuid of a refund, named by what it asks for and the state it was asked in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Derives a refund's uuid from the refund itself, so the same refund asked again is the same refund.
 *
 * Owns one fact: what makes two refund requests the same refund. The uuid is the refund
 * document's, the idempotency key the gateway receives, and so the name of the refund object the
 * gateway makes. It is a name-based uuid (version 5: SHA-1 over a namespace and a name, RFC 9562)
 * whose name is, exactly, one item per line, in this order:
 *
 * 1. `order {uuid}`: the order's stored uuid;
 * 2. `line {line_uuid} {quantity}`: each line asked for, by its stored uuid with the units asked
 *    of it, sorted by line uuid, so the order the lines were asked in does not matter;
 * 3. `shipping 1` when the request asks for the shipping, `shipping 0` when not;
 * 4. `refunded {minor units}`: the intent's `refunded_minor` as the refund's reads found it;
 * 5. `declined {count}`: how many refunds of the intent the ledger held declined, only when there
 *    was at least one, so a refund asked before any decline keeps the name it always had.
 *
 * The reason and the restock flag are not in it: they move no money. The last two items are what
 * make a refund of the same units asked after an earlier one ended a new refund: after one was
 * recorded the intent's refunded amount moved, and after one was declined the count of declines
 * did, so the name, and the key, are new, and so is the claim the refund makes before the gateway
 * is asked. Asked before the earlier one ended, it is the same refund, whose claim it finds. Uuids
 * are hexadecimal digits and dashes, so no item can be read as another.
 *
 * @since 0.1.0
 */
final class RefundIdentity {

	/**
	 * The namespace of refund uuids. Fixed for good: another namespace would give every refund a new key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UUID_NAMESPACE = '7aa67fde-461c-4089-b87f-897b54580b81';

	/**
	 * Returns the uuid of the refund a request asks for, in the state its reads found.
	 *
	 * @since 0.1.0
	 *
	 * @param string $orderUuid The order's stored uuid.
	 * @param array  $units     The units asked of each line, by the line's stored uuid; empty for the shipping alone.
	 * @param bool   $shipping  Whether the request asks for the shipping.
	 * @param Money  $refunded  What the intent had refunded when the refund read it.
	 * @param int    $declined  Optional. How many refunds of the intent the ledger held declined when the refund read it. Default 0.
	 * @return string A lowercase version 5 uuid.
	 *
	 * @phpstan-param array<string, int> $units
	 */
	public static function uuid( string $orderUuid, array $units, bool $shipping, Money $refunded, int $declined = 0 ): string {
		ksort( $units, SORT_STRING );

		$name = array( 'order ' . $orderUuid );

		foreach ( $units as $lineUuid => $quantity ) {
			$name[] = 'line ' . $lineUuid . ' ' . $quantity;
		}

		$name[] = 'shipping ' . ( $shipping ? '1' : '0' );
		$name[] = 'refunded ' . $refunded->minorUnits();

		if ( $declined > 0 ) {
			$name[] = 'declined ' . $declined;
		}

		return self::nameBased( implode( "\n", $name ) );
	}

	/**
	 * Returns the version 5 uuid of a name in the refund namespace.
	 *
	 * The first 16 bytes of SHA-1 over the namespace's bytes and the name, with the version, 5, in
	 * the thirteenth hexadecimal digit, and the RFC variant, 10 in binary, in the top bits of the
	 * seventeenth.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The name.
	 * @return string The uuid, lowercase, grouped 8-4-4-4-12.
	 */
	private static function nameBased( string $name ): string {
		$hash    = sha1( (string) hex2bin( str_replace( '-', '', self::UUID_NAMESPACE ) ) . $name );
		$variant = '89ab'[ intval( $hash[16], 16 ) & 3 ];
		$digits  = substr( $hash, 0, 12 ) . '5' . substr( $hash, 13, 3 ) . $variant . substr( $hash, 17, 15 );

		return implode( '-', array( substr( $digits, 0, 8 ), substr( $digits, 8, 4 ), substr( $digits, 12, 4 ), substr( $digits, 16, 4 ), substr( $digits, 20, 12 ) ) );
	}
}
