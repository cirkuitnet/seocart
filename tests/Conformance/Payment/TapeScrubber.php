<?php
/**
 * TapeScrubber: what a recording may not keep, taken out before it is written or compared
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

use SEOCart\Platform\Logging\CardNumbers;

/**
 * Takes a run's secrets out of what a gateway sent and was answered, re-signs webhook deliveries with a published secret, and refuses a tape that still holds a secret or card data.
 *
 * Owns one fact: what a committed tape may hold. Its steps, in order:
 *
 * 1. every value equal to a credential of the run becomes `{{secret:<name>}}`, wherever it is (the
 *    URL, a header, a body) and in whatever form it was sent: as it is, URL-encoded, escaped for
 *    JSON, base64-encoded or as hexadecimal; so does every secret the provider issues that the test
 *    names (GatewayConformanceCase::providerSecrets(), such as a client secret in an answer);
 * 2. the `authorization`, `proxy-authorization` and `cookie` request headers and the `set-cookie`
 *    response header are dropped;
 * 3. a webhook delivery is signed again, with PUBLISHED_WEBHOOK_SECRET, by the signer the test
 *    gives (the gateway's own scheme), so the provider's signing secret never reaches the tape;
 * 4. what is about to be written is read field by field (each header; each field of a JSON or form
 *    body, of a URL's query and of a URL held in a field; each setting), and the recording refused,
 *    the part and the field named and nothing written, when a value holds a credential of the run,
 *    a value shaped like a provider's secret (SECRET_SHAPES), a card number, a card field, or an
 *    opaque token: a run of TOKEN_LENGTH or more letters, digits, `_` and `-`, in any case; then
 *    the raw text of each body, query and tape file, for an opaque token or a card number that
 *    none of its decoded fields holds (scanUnread()), as decoding drops the first of a field sent
 *    twice and the digits of a number too long to read as one.
 *
 * An opaque token passes only in a public shape: a UUID; a value the test declares as one of the
 * provider's enumerated codes (GatewayConformanceCase::publicCodes(), exact strings, such as a long
 * status); an id of exactly a shape the test declares (GatewayConformanceCase::publicIdShapes(): a
 * prefix and the number of letters and digits after it, or the least and the most, such as
 * `'pi_' => 24`); a 64-hex digest in a field the test declares
 * (GatewayConformanceCase::publicDigestFields()); the media type of a `content-type` or `accept`
 * header. No public shape passes for a token with a secret's prefix (SECRET_PREFIX), in a field
 * named like a credential (CREDENTIAL_FIELD), or in the query of a redirect's `location`. A field's
 * name is the structure's, not a value: it is checked for a credential, a secret's shape and a card
 * field, and is not held to the token rule.
 *
 * A `{{secret:<name>}}` placeholder passes only when this scrubber wrote it: a scan of a scrubbed
 * exchange or delivery lets through the placeholders its scrub wrote, none that was already in what
 * the gateway sent or was answered; a scan of a committed tape, those of the secrets this scrubber
 * replaces. Any other text in a placeholder's shape refuses the recording, as no provider's value
 * may pass for a scrubbed secret.
 *
 * The same first two steps are applied to what a gateway sends on a replay, before it is compared
 * with the tape, so the stand-in credentials of a replay compare equal to the real ones recorded.
 * A refusal names the part, the field and the shape it found, never the value.
 *
 * @since 0.2.0
 */
final class TapeScrubber {

	/**
	 * The signing secret every recorded delivery is signed with: public on purpose, so a replay can verify it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PUBLISHED_WEBHOOK_SECRET = 'whsec_seocart_conformance_published_test_secret';

	/**
	 * The shortest credential the scrubber replaces: a shorter one would match ordinary text.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const SHORTEST_CREDENTIAL = 8;

	/**
	 * The card fields a tape may not hold, as a JSON key or a form field.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const CARD_FIELDS = array( 'exp_month', 'exp_year', 'last4', 'cvc' );

	/**
	 * The shapes of secrets providers issue, by what each is: a recording that holds one is refused.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const SECRET_SHAPES = array(
		'a secret, restricted or publishable key (sk_, rk_ or pk_, then test_ or live_)' => '/(?<![A-Za-z0-9_])(?:sk|rk|pk)_(?:test|live)_[A-Za-z0-9]{6,}/',
		'a webhook signing secret (whsec_)'          => '/(?<![A-Za-z0-9_])whsec_[A-Za-z0-9]{6,}/',
		'a client secret (cs_, then test_ or live_)' => '/(?<![A-Za-z0-9_])cs_(?:test|live)_[A-Za-z0-9]{6,}/',
		'a client secret (_secret_)'                 => '/_secret_[A-Za-z0-9]{10,}/',
	);

	/**
	 * The length from which a run of letters, digits, `_` and `-`, in any case, is an opaque token.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const TOKEN_LENGTH = 20;

	/**
	 * What the scan of a recording cannot see, as the reference page states it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIMIT = 'Rule 14 reads a recording value by value for an opaque token of ' . self::TOKEN_LENGTH . ' characters or more: a secret shorter than that, or one split across values, is beyond that reading, so every credential of the run is a secret setting the gateway declares, replaced in every form it is sent in, and every secret the provider issues is named in its test\'s `providerSecrets()`. A field\'s name is not held to the token rule, as providers name fields with ' . self::TOKEN_LENGTH . ' characters and more, so a secret sent as a field\'s name is beyond it. A form-encoded copy of a placeholder the scrub itself wrote passes as that placeholder, which names a declared setting and carries no value: the tape keeps the placeholder\'s text, and a replay has that text where the provider sent the encoded copy.';

	/**
	 * The prefix or infix of a secret: a token that has one passes in no public shape.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SECRET_PREFIX = '/^(?:sk|rk|pk|whsec|cs|secret|private|token|key|pass|password)_|_(?:live|test|secret|private|token|key)_/i';

	/**
	 * A field named like a credential: no public shape passes in its value.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CREDENTIAL_FIELD = '/secret|token|passw|passphrase|authori[sz]ation|cookie|credential|^key$|(?:api|access|private|secret|client|signing|publishable|restricted|transaction|merchant|license|encryption|auth)[_.\-]?key$/i';

	/**
	 * A UUID, the one public shape every tape may hold: the store's own references are UUIDs.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * The shape of the placeholder a scrubbed secret leaves, `{{secret:<name>}}`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PLACEHOLDER = '/\{\{secret:[A-Za-z0-9_.\-]+\}\}/';

	/**
	 * A run of 13 to 19 digits, with a space or a dash between any two, that the card rule reads: one in the raw text of a part that no field holds is refused.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CARD_CANDIDATE = '/(?<![0-9])[0-9](?:[ \-]?[0-9]){12,18}(?![0-9])/';

	/**
	 * A 64-hex digest, public only in a field the test declares, such as a delivery's signature.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DIGEST = '/^[0-9a-f]{64}$/i';

	/**
	 * A media type, as a `content-type` or `accept` header names one: words, without digits.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MEDIA_TYPE = '#(?<![A-Za-z0-9_\-])[a-z]+/[a-z.+\-]+(?![A-Za-z0-9_])#i';

	/**
	 * The headers whose media types are public.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const MEDIA_HEADERS = array( 'content-type', 'accept' );

	/**
	 * The request headers never written: each carries credentials.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const DROPPED_REQUEST_HEADERS = array( 'authorization', 'proxy-authorization', 'cookie' );

	/**
	 * The response headers never written.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const DROPPED_RESPONSE_HEADERS = array( 'set-cookie' );

	/**
	 * What each form of each credential is replaced with, longest first.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private array $replacements = array();

	/**
	 * The placeholders this scrubber writes, one per secret it replaces: what a committed tape may hold.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, true>
	 */
	private array $placeholders = array();

	/**
	 * The placeholders the current scrub wrote.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, true>
	 */
	private array $written = array();

	/**
	 * The text in a placeholder's shape the current scrub found already there, in what was sent or answered.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, true>
	 */
	private array $found = array();

	/**
	 * What the current scrub returned: the exchange or the delivery whose scan may let its placeholders through.
	 *
	 * @since 0.2.0
	 *
	 * @var Exchange|array<string, mixed>|null
	 */
	private Exchange|array|null $scrubbed = null;

	/**
	 * The placeholders the current scan lets through.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, true>
	 */
	private array $passing = array();

	/**
	 * Builds the scrubber of a run.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused               When a credential is shorter than SHORTEST_CREDENTIAL, so it could not be told from other text.
	 * @throws \InvalidArgumentException When an id's shape is not a prefix with a length, or with a least and a most length, of 1 or more.
	 *
	 * @param array<string, list<string>>  $credentials        Each credential's values in this run, by the setting's name.
	 * @param array<string, string>        $providerSecrets    Optional. Each secret the provider issues, as a regular expression, by a name for its placeholder. Default none.
	 * @param array<string, int|list<int>> $publicIdShapes     Optional. The shapes of the provider's public object ids: by each id's prefix, the number of letters and digits after it, or the least and the most, such as `'pi_' => 24`. Default none.
	 * @param string[]                     $publicDigestFields Optional. The fields whose 64-hex digests are public, such as a delivery's signature header. Default none.
	 * @param string[]                     $publicCodes        Optional. The provider's enumerated codes a tape may hold, as exact strings. Default none.
	 *
	 * @phpstan-param array<string, int|array{int, int}> $publicIdShapes
	 * @phpstan-param list<string> $publicDigestFields
	 * @phpstan-param list<string> $publicCodes
	 */
	public function __construct(
		#[\SensitiveParameter] array $credentials,
		private array $providerSecrets = array(),
		private array $publicIdShapes = array(),
		private array $publicDigestFields = array(),
		private array $publicCodes = array()
	) {
		foreach ( $publicIdShapes as $prefix => $length ) {
			list( $least, $most ) = is_int( $length ) ? array( $length, $length ) : $length;

			if ( '' === (string) $prefix || $least < 1 || $most < $least ) {
				throw new \InvalidArgumentException( sprintf( 'A public id\'s shape is a prefix with the number of letters and digits after it, or the least and the most, each 1 or more; the shape of %s is not.', $prefix ) );
			}
		}

		foreach ( $credentials as $name => $values ) {
			foreach ( $values as $value ) {
				if ( strlen( $value ) < self::SHORTEST_CREDENTIAL ) {
					throw new TapeRefused( sprintf( 'The credential %1$s is shorter than %2$d characters, so a recording could not take it out safely.', $name, self::SHORTEST_CREDENTIAL ) );
				}

				foreach ( self::formsOf( $value ) as $form ) {
					$this->replacements[ $form ] = '{{secret:' . $name . '}}';
				}
			}

			$this->placeholders[ '{{secret:' . $name . '}}' ] = true;
		}

		foreach ( array_keys( $providerSecrets ) as $name ) {
			$this->placeholders[ '{{secret:' . $name . '}}' ] = true;
		}

		uksort( $this->replacements, static fn( string $one, string $other ): int => array( strlen( $other ), $one ) <=> array( strlen( $one ), $other ) );
	}

	/**
	 * Scrubs an exchange: the secrets replaced, the headers that carry credentials dropped.
	 *
	 * It begins a scrub: scanExchange() of the exchange it returns lets through the placeholders
	 * this scrub wrote, and no other.
	 *
	 * @since 0.2.0
	 *
	 * @param Exchange $sent The exchange as sent and answered.
	 * @return Exchange The exchange as a tape may hold it.
	 */
	public function exchange( Exchange $sent ): Exchange {
		$this->written = array();
		$this->found   = array();

		$this->scrubbed = $sent->with(
			array(
				'url'             => $this->text( $sent->url ),
				'headers'         => $this->headers( $sent->headers, self::DROPPED_REQUEST_HEADERS ),
				'body'            => null === $sent->body ? null : $this->text( $sent->body ),
				'responseHeaders' => $this->headers( $sent->responseHeaders, self::DROPPED_RESPONSE_HEADERS ),
				'responseBody'    => $this->text( $sent->responseBody ),
			)
		);

		return $this->scrubbed;
	}

	/**
	 * Replaces every form of every credential, and every secret the provider issues that the test names, with its placeholder.
	 *
	 * Each placeholder it adds is noted as written by the current scrub; text in a placeholder's
	 * shape that was there before is noted as found, and no scan lets it through.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text.
	 * @return string The text, scrubbed.
	 */
	public function text( string $text ): string {
		preg_match_all( self::PLACEHOLDER, $text, $before );

		$scrubbed = array() === $this->replacements ? $text : strtr( $text, $this->replacements );

		foreach ( $this->providerSecrets as $name => $pattern ) {
			$scrubbed = (string) preg_replace( $pattern, '{{secret:' . $name . '}}', $scrubbed );
		}

		preg_match_all( self::PLACEHOLDER, $scrubbed, $after );

		$this->found   += array_fill_keys( $before[0], true );
		$this->written += array_fill_keys( array_diff( $after[0], $before[0] ), true );

		return $scrubbed;
	}

	/**
	 * Tells whether a text holds a credential of the run.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text.
	 * @return bool True when scrubbing would change it.
	 */
	public function holdsCredential( string $text ): bool {
		return ( array() === $this->replacements ? $text : strtr( $text, $this->replacements ) ) !== $text;
	}

	/**
	 * Scrubs a webhook delivery and signs it again with the published secret.
	 *
	 * It begins a scrub: scanDelivery() of the delivery it returns lets through the placeholders
	 * this scrub wrote, and no other.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $delivery The delivery: mode, signed_at, headers, body.
	 * @param callable             $signer   The test's signer: (headers, body, secret, at) => headers.
	 * @return array<string, mixed> The delivery as a tape may hold it.
	 */
	public function delivery( array $delivery, callable $signer ): array {
		$this->written = array();
		$this->found   = array();

		$body     = $this->text( (string) $delivery['body'] );
		$signedAt = new \DateTimeImmutable( (string) $delivery['signed_at'] );
		$headers  = $signer( (array) $delivery['headers'], $body, self::PUBLISHED_WEBHOOK_SECRET, $signedAt );

		$this->scrubbed = array(
			'mode'      => (string) $delivery['mode'],
			'signed_at' => $signedAt->format( \DateTimeInterface::ATOM ),
			'headers'   => $this->headers( $headers, self::DROPPED_REQUEST_HEADERS ),
			'body'      => $body,
		);

		return $this->scrubbed;
	}

	/**
	 * Refuses a scrubbed exchange any part of which a tape may not hold.
	 *
	 * The placeholders let through are those the scrub that returned this very exchange wrote;
	 * any other exchange is scanned with none.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When one does, naming the part and the field.
	 *
	 * @param Exchange $clean The scrubbed exchange.
	 * @param string   $where Which exchange, for the message, such as "Exchange 2 of rule01-money-path-usd-any".
	 */
	public function scanExchange( Exchange $clean, string $where ): void {
		$this->passing = $clean === $this->scrubbed ? $this->writtenOnly() : array();
		$this->scanExchangeParts( $clean, $where );
	}

	/**
	 * Refuses a scrubbed delivery any part of which a tape may not hold.
	 *
	 * The placeholders let through are those the scrub that returned this very delivery wrote;
	 * any other delivery is scanned with none.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When one does, naming the part and the field.
	 *
	 * @param array<string, mixed> $delivery The scrubbed delivery.
	 * @param string               $where    Which delivery, for the message.
	 */
	public function scanDelivery( array $delivery, string $where ): void {
		$this->passing = $delivery === $this->scrubbed ? $this->writtenOnly() : array();
		$this->scanDeliveryParts( $delivery, $where );
	}

	/**
	 * Refuses settings a tape may not hold: they are never scrubbed, so no placeholder passes in them.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When a value does, naming the setting.
	 *
	 * @param array<string, mixed> $settings The settings by name.
	 * @param string               $where    Where they are written, for the message.
	 */
	public function scanSettings( array $settings, string $where ): void {
		$this->passing = array();
		$this->scanFields( $settings, $where . ', the setting', '', true );
	}

	/**
	 * Refuses a committed tape file any part of which a tape may not hold, read as the suite reads it: an exchange, a delivery or the settings.
	 *
	 * The placeholders that pass in it are those of the secrets this scrubber replaces: a tape a
	 * scrubber wrote holds no other.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When a part is refused, naming the file, the part and the field.
	 *
	 * @param string $file The file.
	 */
	public function scanFile( string $file ): void {
		$text          = (string) file_get_contents( $file );
		$decoded       = json_decode( $text, true );
		$this->passing = $this->placeholders;

		if ( ! is_array( $decoded ) ) {
			$this->scanLoose( $text, $file );
			return;
		}

		$rest = $decoded;

		if ( is_array( $decoded['request'] ?? null ) && is_array( $decoded['response'] ?? null ) ) {
			$this->scanExchangeParts( Exchange::fromTape( $decoded ), $file );

			$rest = array_diff_key( $decoded, array_flip( array( 'request', 'response', 'answer_lost' ) ) ) + array(
				'request'  => array_diff_key( $decoded['request'], array_flip( array( 'method', 'host', 'path', 'headers', 'body' ) ) ),
				'response' => array_diff_key( $decoded['response'], array_flip( array( 'status', 'headers', 'body' ) ) ),
			);
		} elseif ( is_array( $decoded['headers'] ?? null ) && isset( $decoded['body'] ) ) {
			$this->scanDeliveryParts( $decoded, $file );

			$rest = array_diff_key( $decoded, array_flip( array( 'headers', 'body' ) ) );
		}

		$this->scanFields( $rest, $file . ', its field', '', true );
		self::scanUnread( $text, $decoded, $file );
	}

	/**
	 * Refuses a text of no known structure, read as one value of no field: no public shape but a UUID, a declared code or a declared id passes in it, and no placeholder.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When it holds a credential, a secret's shape, an opaque token, a card field or a card number.
	 *
	 * @param string $text  The text.
	 * @param string $where Where it is, for the message.
	 */
	public function scan( string $text, string $where ): void {
		$this->passing = array();
		$this->scanLoose( $text, $where );
	}

	/**
	 * Returns the placeholders the current scrub wrote and found nowhere before it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, true> The placeholders.
	 */
	private function writtenOnly(): array {
		return array_diff_key( $this->written, $this->found );
	}

	/**
	 * Refuses an exchange's parts, each by its name, with the placeholders the current scan lets through.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When one is refused.
	 *
	 * @param Exchange $clean The exchange.
	 * @param string   $where Which exchange, for the message.
	 */
	private function scanExchangeParts( Exchange $clean, string $where ): void {
		$forbidden = array_merge( self::DROPPED_REQUEST_HEADERS, self::DROPPED_RESPONSE_HEADERS );

		$this->scanUrl( $clean->url, '', $where . ', its URL', true, true );
		$this->scanHeaders( $clean->headers, $where . ', its request header', $forbidden );
		$this->scanBody( (string) $clean->body, $where . ', its request body' );
		$this->scanHeaders( $clean->responseHeaders, $where . ', its response header', $forbidden );
		$this->scanBody( $clean->responseBody, $where . ', its response body' );
	}

	/**
	 * Refuses a delivery's headers and body, with the placeholders the current scan lets through.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When one is refused.
	 *
	 * @param array<string, mixed> $delivery The delivery.
	 * @param string               $where    Which delivery, for the message.
	 */
	private function scanDeliveryParts( array $delivery, string $where ): void {
		$this->scanHeaders( (array) $delivery['headers'], $where . ', its header', self::DROPPED_REQUEST_HEADERS );
		$this->scanBody( (string) $delivery['body'], $where . ', its body' );
	}

	/**
	 * Refuses a text of no known structure, with the placeholders the current scan lets through.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When it is refused.
	 *
	 * @param string $text  The text.
	 * @param string $where Where it is, for the message.
	 */
	private function scanLoose( string $text, string $where ): void {
		foreach ( self::CARD_FIELDS as $field ) {
			if ( 1 === preg_match( '/(?<![A-Za-z0-9_])' . $field . '(?![A-Za-z0-9_])/', $text ) ) {
				throw new TapeRefused( sprintf( '%1$s holds the card field %2$s: an adapter never asks for card details, and a tape never keeps them.', $where, $field ) );
			}
		}

		$this->scanText( $text, '', $where, true, true );
	}

	/**
	 * Refuses the value of a field: a URL is read with its query field by field, any other value as a text.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When it is refused.
	 *
	 * @param string $value      The value.
	 * @param string $field      The field's name; empty for none.
	 * @param string $where      Where it is, the field included, for the message.
	 * @param bool   $exemptable Whether a public shape may pass in it.
	 */
	private function scanValue( string $value, string $field, string $where, bool $exemptable ): void {
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $value ) ) {
			$this->scanUrl( $value, $field, $where, $exemptable, $exemptable );
			return;
		}

		$this->scanText( $value, $field, $where, $exemptable, true );
	}

	/**
	 * Refuses a text: a credential, a secret's shape, a card number, or an opaque token in no public shape.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When it holds one, naming where and the shape, never the value.
	 *
	 * @param string $text       The text.
	 * @param string $field      The field's name; empty for none.
	 * @param string $where      Where it is, the field included, for the message.
	 * @param bool   $exemptable Whether a public shape may pass in it: not in a redirect's query.
	 * @param bool   $tokens     Whether opaque tokens are refused: not in a field's name.
	 */
	private function scanText( string $text, string $field, string $where, bool $exemptable, bool $tokens ): void {
		$text = str_replace( self::PUBLISHED_WEBHOOK_SECRET, '', $text );

		if ( $this->holdsCredential( $text ) ) {
			throw new TapeRefused( sprintf( '%s holds a credential of the run, which a tape never keeps.', $where ) );
		}

		foreach ( self::SECRET_SHAPES as $shape => $pattern ) {
			if ( 1 === preg_match( $pattern, $text ) ) {
				throw new TapeRefused( sprintf( '%1$s holds %2$s, which a tape never keeps: declare it as a secret setting, or name it in providerSecrets() if the provider issues it.', $where, $shape ) );
			}
		}

		if ( CardNumbers::contains( $text ) ) {
			throw new TapeRefused( sprintf( '%s holds a card number: an adapter never asks for card details, and a tape never keeps them.', $where ) );
		}

		if ( ! $tokens ) {
			return;
		}

		$text            = $this->withoutPassingPlaceholders( $text, $where );
		$credentialField = 1 === preg_match( self::CREDENTIAL_FIELD, $field );

		if ( in_array( strtolower( $field ), self::MEDIA_HEADERS, true ) ) {
			$text = (string) preg_replace( self::MEDIA_TYPE, '', $text );
		}

		preg_match_all( '/[A-Za-z0-9_\-]{' . self::TOKEN_LENGTH . ',}/', $text, $found );

		foreach ( $found[0] as $token ) {
			if ( $exemptable && ! $credentialField && 1 !== preg_match( self::SECRET_PREFIX, $token ) && $this->isPublic( $token, $field ) ) {
				continue;
			}

			throw new TapeRefused( sprintf( '%1$s holds an opaque token of %2$d characters or more, the shape of a secret%3$s.', $where, self::TOKEN_LENGTH, self::exemptionOf( $field, $exemptable, $credentialField ) ) );
		}
	}

	/**
	 * Takes out of a text each placeholder the current scan lets through, and refuses any other text in a placeholder's shape.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When the text holds a placeholder's shape the scrubber did not write.
	 *
	 * @param string $text  The text.
	 * @param string $where Where it is, for the message.
	 * @return string The text, without the placeholders let through.
	 */
	private function withoutPassingPlaceholders( string $text, string $where ): string {
		return (string) preg_replace_callback(
			self::PLACEHOLDER,
			function ( array $placeholder ) use ( $where ): string {
				if ( ! isset( $this->passing[ $placeholder[0] ] ) ) {
					throw new TapeRefused( sprintf( '%s holds text in the shape of a scrubbed secret\'s placeholder, {{secret:<name>}}, that the scrubber did not write alone: a tape keeps only the placeholders of the secrets it replaced, none that was also in what was sent or answered.', $where ) );
				}

				return '';
			},
			$text
		);
	}

	/**
	 * Says why a token was refused where it was, and how to pass a public one.
	 *
	 * @since 0.2.0
	 *
	 * @param string $field           The field's name.
	 * @param bool   $exemptable      Whether a public shape could pass there.
	 * @param bool   $credentialField Whether the field is named like a credential.
	 * @return string The clause.
	 */
	private static function exemptionOf( string $field, bool $exemptable, bool $credentialField ): string {
		if ( ! $exemptable ) {
			return ', in a redirect\'s query, where no public shape passes';
		}

		if ( $credentialField ) {
			return sprintf( ', in the field %s, named like a credential, where no public shape passes', $field );
		}

		return ': declare it as a secret setting, or name it in providerSecrets() if the provider issues it; if it is public, declare it in publicCodes(), the shape of its id in publicIdShapes(), or, for a 64-hex digest, its field in publicDigestFields(); a public shape never passes with a secret\'s prefix';
	}

	/**
	 * Tells whether an opaque token has a public shape in a field.
	 *
	 * @since 0.2.0
	 *
	 * @param string $token The token.
	 * @param string $field The field's name.
	 * @return bool True for a UUID, a declared code, an id of exactly a declared shape, or a 64-hex digest in a declared field.
	 */
	private function isPublic( string $token, string $field ): bool {
		if ( 1 === preg_match( self::UUID, $token ) || in_array( $token, $this->publicCodes, true ) ) {
			return true;
		}

		foreach ( $this->publicIdShapes as $prefix => $length ) {
			list( $least, $most ) = is_int( $length ) ? array( $length, $length ) : $length;

			if ( 1 === preg_match( '/^' . preg_quote( $prefix, '/' ) . '[A-Za-z0-9]{' . $least . ',' . $most . '}$/D', $token ) ) {
				return true;
			}
		}

		return 1 === preg_match( self::DIGEST, $token ) && in_array( strtolower( $field ), array_map( 'strtolower', $this->publicDigestFields ), true );
	}

	/**
	 * Refuses a URL a tape may not hold: what is not its query as one value of the field, each field of its query as its own.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When a part of it is refused.
	 *
	 * @param string $url             The URL.
	 * @param string $field           The field that holds it; empty for none.
	 * @param string $where           Where it is, for the message.
	 * @param bool   $exemptable      Whether a public shape may pass outside its query.
	 * @param bool   $queryExemptable Whether a public shape may pass in its query: not in a redirect's.
	 */
	private function scanUrl( string $url, string $field, string $where, bool $exemptable, bool $queryExemptable ): void {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		$this->scanText( '' === $query ? $url : str_replace( '?' . $query, '', $url ), $field, $where, $exemptable, true );

		if ( '' !== $query ) {
			$fields = array();
			parse_str( $query, $fields );
			$this->scanFields( $fields, $where . ' query field', '', $queryExemptable && 1 !== preg_match( self::CREDENTIAL_FIELD, $field ) );
			self::scanUnread( $query, $fields, $where . ' query', self::formNames( $query ) );
		}
	}

	/**
	 * Returns the names of a form's or a query's fields as they are written, URL-decoded: decoding the form rewrites a `.` or a space in a name as `_`.
	 *
	 * @since 0.2.0
	 *
	 * @param string $form The form or the query.
	 * @return list<string> The names, in order.
	 */
	private static function formNames( string $form ): array {
		$names = array();

		foreach ( explode( '&', $form ) as $pair ) {
			$equals  = strpos( $pair, '=' );
			$names[] = urldecode( false === $equals ? $pair : substr( $pair, 0, $equals ) );
		}

		return $names;
	}

	/**
	 * Refuses a body a tape may not hold: a JSON or form body field by field, any other as one text.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When a part of it is refused.
	 *
	 * @param string $body  The body.
	 * @param string $where Where it is, for the message.
	 */
	private function scanBody( string $body, string $where ): void {
		if ( '' === $body ) {
			return;
		}

		$decoded = json_decode( $body, true );
		$names   = array();

		if ( ! is_array( $decoded ) && 1 === preg_match( '/^[^\s=&{}\[\]"]+(?:\[[^\s=&]*\])*=[^\s]*$/', $body ) ) {
			$decoded = array();
			$names   = self::formNames( $body );
			parse_str( $body, $decoded );
		}

		if ( is_array( $decoded ) ) {
			$this->scanFields( $decoded, $where . ' field', '', true );
			self::scanUnread( $body, $decoded, $where, $names );
			return;
		}

		$this->scanLoose( $body, $where );
	}

	/**
	 * Refuses what the raw text of a part holds outside the fields it was read as: an opaque token, or a card number, that no key or value of them holds.
	 *
	 * Decoding keeps the last of a field sent twice, and reads a number too long for an integer as
	 * a float, so the reading field by field never sees the first value or the digits; the raw
	 * text, as it is written, still holds them. Its escapes (`\u00e9`, `\/`, `%3D`) are read as
	 * breaks, so what is left between them is text a decoded key or value holds as it is. A token
	 * in it passes only when it is a whole token of one, in any case: one a sentence holds as a
	 * word passes, one inside a longer token, such as a public id, does not. A card number passes
	 * only when its digits are the digits of one, whatever stands between them: a timestamp whose
	 * escaped separators read as breaks is its field's, and a card number inside a longer number
	 * is not. A form's or a query's field names, as
	 * written, hold their tokens too, as decoding rewrites a `.` in a name; a field's name is not
	 * held to the token rule in any case.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When it holds one, naming the part.
	 *
	 * @param string       $raw     The part's text, as written.
	 * @param array<mixed> $decoded Its fields, decoded.
	 * @param string       $where   The part, for the message.
	 * @param string[]     $names   Optional. A form's or a query's field names, as written, URL-decoded. Default none.
	 *
	 * @phpstan-param list<string> $names
	 */
	private static function scanUnread( string $raw, array $decoded, string $where, array $names = array() ): void {
		$fields = self::keysAndValues( $decoded );
		$held   = array();
		$digits = array();
		$raw    = (string) preg_replace( '/\\\\(?:u[0-9a-fA-F]{4}|.)|%[0-9a-fA-F]{2}/s', ' ', $raw );

		foreach ( $names as $name ) {
			preg_match_all( '/[A-Za-z0-9_\-]+/', strtolower( $name ), $words );
			$held += array_fill_keys( $words[0], true );
		}

		foreach ( $fields as $text ) {
			preg_match_all( '/[A-Za-z0-9_\-]+/', strtolower( $text ), $words );
			$held += array_fill_keys( $words[0], true );

			$digits[ (string) preg_replace( '/[^0-9]+/', '', $text ) ] = true;
		}

		preg_match_all( '/[A-Za-z0-9_\-]{' . self::TOKEN_LENGTH . ',}/', $raw, $tokens );

		foreach ( $tokens[0] as $token ) {
			if ( ! isset( $held[ strtolower( $token ) ] ) ) {
				throw new TapeRefused( sprintf( '%1$s holds, outside every field it was read as, an opaque token of %2$d characters or more, the shape of a secret: the first of a field sent twice, or a number too long to read as one, is written as it was sent and never read.', $where, self::TOKEN_LENGTH ) );
			}
		}

		preg_match_all( self::CARD_CANDIDATE, $raw, $candidates );

		foreach ( $candidates[0] as $candidate ) {
			if ( CardNumbers::contains( $candidate ) && ! isset( $digits[ (string) preg_replace( '/[^0-9]+/', '', $candidate ) ] ) ) {
				throw new TapeRefused( sprintf( '%s holds, outside every field it was read as, a card number: an adapter never asks for card details, and a tape never keeps them.', $where ) );
			}
		}
	}

	/**
	 * Returns the keys and the scalar values of decoded fields, nested ones included, as text.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $decoded The fields.
	 * @return list<string> The keys and the values; a boolean as `true` or `false`.
	 */
	private static function keysAndValues( array $decoded ): array {
		$texts = array();

		foreach ( $decoded as $key => $value ) {
			$texts[] = (string) $key;

			if ( is_array( $value ) ) {
				$texts = array_merge( $texts, self::keysAndValues( $value ) );
			} elseif ( is_scalar( $value ) ) {
				$texts[] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
			}
		}

		return $texts;
	}

	/**
	 * Refuses the fields of a decoded structure: each name as the structure's, each value under its field's name.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When one is refused.
	 *
	 * @param array<mixed> $fields     The fields.
	 * @param string       $where      Where they are, for the message.
	 * @param string       $path       The path of the field they sit in, dotted; empty at the top.
	 * @param bool         $exemptable Whether a public shape may pass in them.
	 * @param string       $enclosing  The name of the field they sit in, for a list's items; empty at the top.
	 */
	private function scanFields( array $fields, string $where, string $path, bool $exemptable, string $enclosing = '' ): void {
		foreach ( $fields as $key => $value ) {
			$field = is_string( $key ) ? $key : $enclosing;
			$named = '' === $path ? (string) $key : $path . '.' . $key;

			if ( in_array( strtolower( $field ), self::CARD_FIELDS, true ) ) {
				throw new TapeRefused( sprintf( '%1$s %2$s is the card field %3$s: an adapter never asks for card details, and a tape never keeps them.', $where, $named, $field ) );
			}

			if ( is_string( $key ) ) {
				$this->scanText( $key, '', $where . ' name at ' . ( '' === $path ? 'the top' : $path ), true, false );
			}

			if ( is_array( $value ) ) {
				$this->scanFields( $value, $where, $named, $exemptable, $field );
			} elseif ( is_scalar( $value ) ) {
				$this->scanValue( (string) $value, $field, $where . ' ' . $named, $exemptable );
			}
		}
	}

	/**
	 * Refuses headers a tape may not hold: one that carries credentials, or a value the scan refuses.
	 *
	 * @since 0.2.0
	 *
	 * @throws TapeRefused When one is, naming the header.
	 *
	 * @param array<string, string> $headers   The headers.
	 * @param string                $where     Where they are, for the message.
	 * @param string[]              $forbidden The names never written.
	 *
	 * @phpstan-param list<string> $forbidden
	 */
	private function scanHeaders( array $headers, string $where, array $forbidden ): void {
		foreach ( $headers as $name => $value ) {
			$name = strtolower( (string) $name );

			if ( in_array( $name, $forbidden, true ) ) {
				throw new TapeRefused( sprintf( '%1$s %2$s carries credentials, and a tape never keeps it.', $where, $name ) );
			}

			$this->scanText( $name, '', $where . ' name', true, false );

			if ( 'location' === $name ) {
				$this->scanUrl( (string) $value, $name, $where . ' ' . $name, true, false );
			} else {
				$this->scanValue( (string) $value, $name, $where . ' ' . $name, true );
			}
		}
	}

	/**
	 * Scrubs headers: their names in lower case and sorted, the dropped ones left out.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $headers The headers.
	 * @param string[]     $dropped The names left out.
	 * @return array<string, string> The headers.
	 *
	 * @phpstan-param list<string> $dropped
	 */
	private function headers( array $headers, array $dropped ): array {
		$kept = array();

		foreach ( $headers as $name => $value ) {
			$name = strtolower( (string) $name );

			if ( ! in_array( $name, $dropped, true ) ) {
				$kept[ $name ] = $this->text( (string) $value );
			}
		}

		ksort( $kept, SORT_STRING );

		return $kept;
	}

	/**
	 * Returns the forms a credential can be sent in.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The credential.
	 * @return list<string> The forms: as it is, URL-encoded both ways, escaped for JSON, base64-encoded and hexadecimal.
	 */
	private static function formsOf( #[\SensitiveParameter] string $value ): array {
		return array_values(
			array_unique(
				array(
					$value,
					rawurlencode( $value ),
					urlencode( $value ),
					substr( (string) wp_json_encode( $value ), 1, -1 ),
					base64_encode( $value ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A form a credential is sent in, such as HTTP Basic.
					bin2hex( $value ),
					strtoupper( bin2hex( $value ) ),
				)
			)
		);
	}
}
