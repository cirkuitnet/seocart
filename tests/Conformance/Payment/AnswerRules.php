<?php
/**
 * AnswerRules: the conformance rules on a gateway's answer that need neither WordPress nor a tape
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Support\Money;

/**
 * Judges one answer of a gateway against the request it answers.
 *
 * The suite asks the gateway on the tape and hands the answer here; the suite's own unit tests
 * hand it answers made by hand, the planted gateway's among them. Both meet the same judgement.
 *
 * @since 0.2.0
 */
final class AnswerRules {

	/**
	 * Returns why an answer to a void breaks rule 19, or null when it keeps it.
	 *
	 * An approved void reports the request's amount: SEOCart keeps an approved answer of any other
	 * amount unapplied and parks the order for a person, so a gateway that reports zero parks
	 * every void, those at the end of a customer's time to act included. A declined void, and the
	 * authorization's approval a provider answers when the authorization had already succeeded,
	 * are not held to it.
	 *
	 * @since 0.2.0
	 *
	 * @param VoidRequest   $request The void asked.
	 * @param GatewayResult $answer  The gateway's answer.
	 * @return string|null The failure, beginning with the rule's message; null when the answer keeps the rule.
	 */
	public static function voidAmountFailure( VoidRequest $request, GatewayResult $answer ): ?string {
		if ( Operation::Void !== $answer->operation || Outcome::Approved !== $answer->outcome || $answer->amount->equals( $request->amount ) ) {
			return null;
		}

		return Rule::VoidReportsTheAmount->message() . sprintf(
			'an approved void asked for %1$s reported %2$s. It reports the request\'s amount, what the cancellation released; SEOCart keeps any other amount unapplied and parks the order (`%3$s`).',
			self::shown( $request->amount ),
			self::shown( $answer->amount ),
			PaymentService::AMOUNT_MISMATCH
		);
	}

	/**
	 * Returns why the object an outcome names breaks the contract's object rule, or null when it keeps it.
	 *
	 * The object of an outcome is the provider's object for that attempt, a charge, a capture or a
	 * refund, never the long-lived provider intent; a void names the provider's cancellation object,
	 * or the intent where the provider makes none. It is an object the provider made, so it is in
	 * the provider's answer to the request that made it.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule        $rule             The rule the failure is reported under.
	 * @param string      $what             The outcome, for the message, such as "the capture".
	 * @param string|null $objectId         The object the outcome names.
	 * @param string|null $providerIntentId The provider's intent.
	 * @param string[]    $answers          The bodies of the provider's answers to the outcome's requests; none for a gateway that calls no service.
	 * @param bool        $mayNameTheIntent Whether the outcome may name the intent, as a void may, and any outcome of a provider that keeps one object for a payment.
	 * @return string|null The failure, beginning with the rule's message; null when the object keeps the rule.
	 */
	public static function objectFailure( Rule $rule, string $what, ?string $objectId, ?string $providerIntentId, array $answers, bool $mayNameTheIntent ): ?string {
		if ( null === $objectId || '' === $objectId ) {
			return $rule->message() . sprintf( '%s named no provider object: an outcome names the object the provider made for it, a charge, a capture, a cancellation or a refund.', $what );
		}

		if ( ! $mayNameTheIntent && $objectId === $providerIntentId ) {
			return $rule->message() . sprintf( '%1$s named the provider\'s intent (%2$s) as its object: the object is the outcome\'s own, a charge, a capture or a refund, never the long-lived intent.', $what, $objectId );
		}

		foreach ( $answers as $answer ) {
			if ( str_contains( $answer, $objectId ) ) {
				return null;
			}
		}

		return array() === $answers ? null : $rule->message() . sprintf( '%1$s named %2$s, which none of the provider\'s answers to it holds: the object is one the provider made for the outcome.', $what, $objectId );
	}

	/**
	 * Returns why the object an outcome names breaks the contract's object rule for a provider that keeps one object for a payment, or null when it keeps it.
	 *
	 * Such a provider answers each operation about the object it changed, the payment's own or a
	 * refund of its own, and names it in one field of its answer, which the gateway's test declares
	 * (objectIdField()). The outcome names the object that field of one of the answers to it names:
	 * an answer that is about another object, such as a capture of its own that names the payment
	 * in another field, refuses an outcome that names the payment. An answer is read as JSON once a
	 * leading byte-order mark is taken off; one that is not JSON, or has no value at the field,
	 * names no object.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule        $rule     The rule the failure is reported under.
	 * @param string      $what     The outcome, for the message, such as "the capture".
	 * @param string|null $objectId The object the outcome names.
	 * @param string[]    $answers  The bodies of the provider's answers to the outcome's requests; none for a gateway that calls no service.
	 * @param string      $field    The field of an answer that names the object it is about, dotted, such as `transactionResponse.transId`.
	 * @return string|null The failure, beginning with the rule's message; null when the object keeps the rule.
	 */
	public static function ownObjectFailure( Rule $rule, string $what, ?string $objectId, array $answers, string $field ): ?string {
		if ( null === $objectId || '' === $objectId ) {
			return self::objectFailure( $rule, $what, $objectId, null, $answers, true );
		}

		foreach ( $answers as $answer ) {
			if ( self::fieldOf( $answer, $field ) === $objectId ) {
				return null;
			}
		}

		return array() === $answers ? null : $rule->message() . sprintf( '%1$s named %2$s, which is not the object any of the provider\'s answers to it is about, as its field %3$s names it: where the provider keeps one object for a payment (paymentIsOneObject()), an outcome names the object its answer is about.', $what, $objectId, $field );
	}

	/**
	 * Returns the value at a dotted field of an answer read as JSON.
	 *
	 * @since 0.2.0
	 *
	 * @param string $answer The answer's body.
	 * @param string $field  The field, dotted.
	 * @return string|null The value, as text; null when the answer is no JSON or the field holds no number or text.
	 */
	private static function fieldOf( string $answer, string $field ): ?string {
		$value = json_decode( str_starts_with( $answer, "\xEF\xBB\xBF" ) ? substr( $answer, 3 ) : $answer, true );

		foreach ( explode( '.', $field ) as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return null;
			}

			$value = $value[ $key ];
		}

		return is_string( $value ) || is_int( $value ) ? (string) $value : null;
	}

	/**
	 * Returns an amount as a failure names it.
	 *
	 * @since 0.2.0
	 *
	 * @param Money $amount The amount.
	 * @return string For example "3000 USD (minor units)".
	 */
	private static function shown( Money $amount ): string {
		return sprintf( '%1$d %2$s (minor units)', $amount->minorUnits(), $amount->currency()->code() );
	}
}
