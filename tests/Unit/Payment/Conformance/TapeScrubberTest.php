<?php
/**
 * Tests what the tape scrubber takes out of a recording, and what it refuses
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\Exchange;
use SEOCart\Tests\Conformance\Payment\TapeRefused;
use SEOCart\Tests\Conformance\Payment\TapeScrubber;

/**
 * The scrubber's four steps: credentials replaced in every form, secret headers dropped, deliveries signed again, and every part of a recording read field by field for a secret, an opaque token or card data.
 *
 * @since 0.2.0
 */
final class TapeScrubberTest extends TestCase {

	/**
	 * A credential of the run.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const KEY = 'sk_test_51Credential/With+Signs';

	/**
	 * Where every refusal of an exchange begins.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WHERE = 'Exchange 1 of a-scenario';

	/**
	 * A timestamp as RFC 3986 encodes it in a form, `2026-06-01 12:00:05`, whose 14 digits pass the card rule's check.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ESCAPED_TIME = '2026-06-01%2012%3A00%3A05';

	/**
	 * The response headers the tests plant values in, which their scrubbers keep so the scan reads them.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const PLANTED_HEADERS = array( 'x-signing', 'location' );

	/**
	 * A scratch tape file, removed after each test.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $file = '';

	/**
	 * Sets Brain Monkey up, for wp_json_encode() and wp_parse_url().
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The stand-in of wp_parse_url() itself.
	}

	/**
	 * Removes the scratch tape file and tears Brain Monkey down.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		if ( '' !== $this->file && is_file( $this->file ) ) {
			unlink( $this->file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A scratch file of the test.
		}

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that a credential is replaced wherever it is, in every form it can be sent in.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_credential_is_replaced_in_every_form(): void {
		$scrubber = self::scrubber();
		$scrubbed = $scrubber->exchange(
			new Exchange(
				'POST',
				'https://api.example.com/v1/charges?key=' . rawurlencode( self::KEY ),
				array(
					'X-Key'         => base64_encode( self::KEY ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The form a credential is planted in.
					'Authorization' => 'Bearer ' . self::KEY,
				),
				'key=' . urlencode( self::KEY ),
				200,
				array( 'Set-Cookie' => 'id=1' ),
				(string) wp_json_encode( array( 'echo' => self::KEY ) )
			)
		);

		$text = (string) wp_json_encode( $scrubbed->toTape() );

		$this->assertStringNotContainsString( 'Credential', $text );
		$this->assertSame( 'https://api.example.com/v1/charges?key={{secret:secret_key}}', $scrubbed->url );
		$this->assertSame( array( 'x-key' => '{{secret:secret_key}}' ), $scrubbed->headers, 'The Authorization header is dropped, and the rest scrubbed.' );
		$this->assertSame( 'key={{secret:secret_key}}', $scrubbed->body );
		$this->assertSame( array(), $scrubbed->responseHeaders, 'Set-Cookie is dropped.' );
		$this->assertSame( '{"echo":"{{secret:secret_key}}"}', $scrubbed->responseBody );
		$scrubber->scanExchange( $scrubbed, self::WHERE );
	}

	/**
	 * Returns text in a placeholder's shape a provider could send, each planted in a part of an exchange, with where its refusal must name it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string|array<string>, string}> The part, the value planted, where the refusal says it is.
	 */
	public function forgedPlaceholders(): array {
		$forged = '{{secret:abcdefghijklmnopqrst}}';

		return array(
			'in a response body'        => array( 'responseBody', '{"note":"' . $forged . '"}', 'its response body field note' ),
			'in a request body'         => array( 'body', 'amount=1000&note=' . rawurlencode( $forged ), 'its request body field note' ),
			'in a response header'      => array( 'responseHeaders', $forged, 'its response header x-signing' ),
			'in a redirect\'s query'    => array( 'responseHeaders', array( 'location' => 'https://pay.example.com/return?session=' . rawurlencode( $forged ) ), 'its response header location query field session' ),
			'in a body of no structure' => array( 'responseBody', 'session ' . $forged, 'its response body' ),
			'one the scrubber writes'   => array( 'responseBody', '{"note":"{{secret:secret_key}}","echo":"' . self::KEY . '"}', 'its response body field note' ),
		);
	}

	/**
	 * Tests that text in a placeholder's shape the scrubber did not write refuses the recording, by the part it is in: no provider's value passes for a scrubbed secret.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider forgedPlaceholders
	 * @group conformance
	 *
	 * @param string               $part  The part of the exchange the text is planted in.
	 * @param string|array<string> $value The value planted.
	 * @param string               $named Where the refusal says it is, after the exchange.
	 */
	public function test_a_placeholder_the_scrubber_did_not_write_refuses_the_recording( string $part, string|array $value, string $named ): void {
		$scrubber = self::scrubber();

		$this->assertRefused( self::WHERE . ', ' . $named . ' holds text in the shape of a scrubbed secret\'s placeholder', 'abcdefghijklmnopqrst', fn() => $scrubber->scanExchange( $scrubber->exchange( self::exchangeWith( $part, $value ) ), self::WHERE ) );
	}

	/**
	 * Tests that a placeholder passes only in the scan of the scrub that wrote it: in a delivery, in settings, in another exchange, and in a committed tape only when it names a secret the scrubber replaces.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_placeholder_passes_only_where_the_scrubber_wrote_it(): void {
		$scrubber = self::scrubber();
		$signer   = static fn( array $headers ): array => $headers;
		$sent     = array(
			'mode'      => 'test',
			'signed_at' => '2026-06-01T12:00:00+00:00',
			'headers'   => array( 'x-note' => '{{secret:abcdefghijklmnopqrst}}' ),
			'body'      => '{"key":"' . self::KEY . '"}',
		);
		$written  = $scrubber->delivery( array( 'headers' => array() ) + $sent, $signer );

		$scrubber->scanDelivery( $written, 'The delivery authorized' );
		$this->assertSame( '{"key":"{{secret:secret_key}}"}', $written['body'], 'The scrubber\'s own placeholder is written.' );
		$this->assertRefused( 'The delivery authorized, its header x-note holds text in the shape of a scrubbed secret\'s placeholder', 'abcdefghijklmnopqrst', fn() => $scrubber->scanDelivery( $scrubber->delivery( $sent, $signer ), 'The delivery authorized' ) );
		$this->assertRefused( 'The delivery authorized, its body field key holds text in the shape of a scrubbed secret\'s placeholder', 'secret_key', fn() => self::scrubber()->scanDelivery( $written, 'The delivery authorized' ) );
		$this->assertRefused( 'settings.json, the setting merchant holds text in the shape of a scrubbed secret\'s placeholder', 'secret_key', fn() => $scrubber->scanSettings( array( 'merchant' => '{{secret:secret_key}}' ), 'settings.json' ) );

		// The scrubber writes {{secret:secret_key}} in the URL, and the answer brings the same text: neither passes.
		$both = new Exchange( 'POST', 'https://api.example.com/v1/charges?key=' . rawurlencode( self::KEY ), array(), 'amount=1000', 200, array(), '{"note":"{{secret:secret_key}}"}' );
		$this->assertRefused( self::WHERE . ', its URL query field key holds text in the shape of a scrubbed secret\'s placeholder', 'secret_key', fn() => $scrubber->scanExchange( $scrubber->exchange( $both ), self::WHERE ) );

		$this->file = (string) tempnam( sys_get_temp_dir(), 'seocart-tape-' );

		foreach ( array(
			'{{secret:secret_key}}'           => true,
			'{{secret:abcdefghijklmnopqrst}}' => false,
		) as $placeholder => $passes ) {
			file_put_contents( $this->file, (string) wp_json_encode( self::exchangeWith( 'responseBody', '{"note":"' . $placeholder . '"}' )->toTape() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A scratch file of the test.

			if ( $passes ) {
				$scrubber->scanFile( $this->file );
				continue;
			}

			$this->assertRefused( $this->file . ', its response body field note holds text in the shape of a scrubbed secret\'s placeholder', 'abcdefghijklmnopqrst', fn() => $scrubber->scanFile( $this->file ) );
		}
	}

	/**
	 * Tests that a credential too short to tell from other text refuses the recording.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_short_credential_refuses_the_recording(): void {
		$this->expectException( TapeRefused::class );

		new TapeScrubber( array( 'secret_key' => array( 'short' ) ) );
	}

	/**
	 * Returns a card's verification code or full number, each under a name a tape never holds, with the field its refusal names and the value it must not quote.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string, string}> The body, the field the refusal names after the part, and the value.
	 */
	public function cardFields(): array {
		return array(
			'a verification code in JSON'     => array( '{"card":{"cvc":"737"}}', 'card.cvc is named cvc', '737' ),
			'a verification code in a form'   => array( 'amount=1000&card[cvv2]=737', 'card.cvv2 is named cvv2', '737' ),
			'a card security code'            => array( '{"card":{"csc":"737"}}', 'card.csc is named csc', '737' ),
			'a security code in another case' => array( '{"card":{"Security_Code":"7373"}}', 'card.Security_Code is named Security_Code', '7373' ),
			'a full number holding no digits' => array( '{"source":{"number":"tok_visa"}}', 'source.number is named number', 'tok_visa' ),
			'a card number field of digits'   => array( '{"card_number":"000000000"}', 'card_number is named card_number', '000000000' ),
			'a bank account number in a form' => array( 'account_number=000123456789', 'account_number is named account_number', '000123456789' ),
		);
	}

	/**
	 * Tests that a field named for a card's verification code or its full number refuses the recording whatever its value, the part and the field named, in a body read field by field and in a text of no structure.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider cardFields
	 * @group conformance
	 *
	 * @param string $body  The request body planted.
	 * @param string $named The field the refusal names, after the part.
	 * @param string $value The value the refusal must not quote.
	 */
	public function test_a_verification_code_or_a_full_number_refuses_the_recording( string $body, string $named, string $value ): void {
		$this->assertRefused( self::WHERE . ', its request body field ' . $named . ', for a card\'s verification code or its full number', $value, fn() => self::scrubber()->scanExchange( self::scrubber()->exchange( self::exchangeWith( 'body', $body ) ), self::WHERE ) );
		$this->assertRefused( 'The text holds a field named ', $value, fn() => self::scrubber()->scan( $body, 'The text' ) );
	}

	/**
	 * Tests that a card number refuses the recording under a name a tape keeps, `last4` included: the card number's rule reads every value, whatever its field.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_card_number_refuses_the_recording_under_a_kept_name(): void {
		$this->assertRefused( self::WHERE . ', its response body field card.last4 holds a card number', '4242424242424242', fn() => self::scrubber()->scanExchange( self::scrubber()->exchange( self::exchangeWith( 'responseBody', '{"card":{"last4":"4242424242424242"}}' ) ), self::WHERE ) );
		$this->assertRefused( 'The text holds a card number', '4242424242424242', fn() => self::scrubber()->scan( 'last4: 4242424242424242', 'The text' ) );
	}

	/**
	 * Tests that the card fields a receipt shows are written, in an answer, a form and a text: the last four digits, the expiry, the brand, the funding and the country; and that the word `number` in prose passes.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_the_card_fields_a_receipt_shows_are_written(): void {
		$card     = '{"card":{"brand":"visa","country":"US","exp_month":12,"exp_year":2034,"funding":"credit","last4":"0002"}}';
		$scrubber = self::scrubber();
		$clean    = $scrubber->exchange( self::exchangeWith( 'responseBody', $card )->with( array( 'body' => 'card[last4]=0002&card[exp_month]=12&card[exp_year]=2034' ) ) );

		$this->assertSame( $card, $clean->responseBody );
		$scrubber->scanExchange( $clean, self::WHERE );
		$scrubber->scan( 'last4: 0002, exp_month: 12, exp_year: 2034. The card number was declined: enter another number.', 'A decline in words' );
		$scrubber->scan( '{"id":"pi_1","amount":1000,"last4_digits_shown":false,"order_number":"000001"}', 'A clean exchange' );
	}

	/**
	 * Tests that the reference page's sentence on what the scan cannot see names every field a tape never holds, so the page says which names are refused.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_the_limit_names_every_refused_card_field(): void {
		foreach ( TapeScrubber::CARD_FIELDS as $field ) {
			$this->assertStringContainsString( '`' . $field . '`', TapeScrubber::LIMIT );
		}
	}

	/**
	 * Tests that a delivery is signed again with the published secret, by the test's signer.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_delivery_is_signed_again_with_the_published_secret(): void {
		$signed = self::scrubber()->delivery(
			array(
				'mode'      => 'test',
				'signed_at' => '2026-06-01T12:00:00+00:00',
				'headers'   => array( 'signature' => 'by-the-real-secret' ),
				'body'      => '{"key":"' . self::KEY . '"}',
			),
			static fn( array $headers, string $body, string $secret, \DateTimeImmutable $at ): array => array( 'signature' => hash_hmac( 'sha256', $at->getTimestamp() . '.' . $body, $secret ) ) + $headers
		);

		$this->assertSame( '{"key":"{{secret:secret_key}}"}', $signed['body'] );
		$this->assertSame( hash_hmac( 'sha256', '1780315200.' . $signed['body'], TapeScrubber::PUBLISHED_WEBHOOK_SECRET ), $signed['headers']['signature'] );
	}

	/**
	 * Returns what a recording may not hold, each planted in a part of an exchange, with where and what its refusal must name.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string, string, string}> The part, the value planted, where the refusal says it is, what it says it found.
	 */
	public function refused(): array {
		$token = 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4';

		return array(
			'a client secret in a response body'         => array( 'responseBody', '{"client_secret":"cs_test_a1B2c3D4e5F6g7H8"}', 'its response body field client_secret', 'a client secret (cs_' ),
			'a restricted key in the URL\'s query'       => array( 'url', 'https://api.example.com/v1/charges?key=rk_live_abcdef123456', 'its URL query field key', 'a secret or restricted key' ),
			'a signing secret in a response header'      => array( 'responseHeaders', 'whsec_AbC123xyz987', 'its response header x-signing', 'a webhook signing secret' ),
			'a secret key in a request body'             => array( 'body', 'amount=1000&secret=sk_test_abcdef123456', 'its request body field secret', 'a secret or restricted key' ),
			'a client secret by its infix'               => array( 'responseBody', '{"handle":"pi_1_secret_Ab1Cd2Ef3Gh4"}', 'its response body field handle', 'a client secret (_secret_)' ),
			'a mixed-case token in a request header'     => array( 'headers', 'Zx81Qw7Er6Ty5Ui4Op3As2Df', 'its request header x-signing', 'an opaque token of 20 characters or more' ),
			'a lower-case token in a response body'      => array( 'responseBody', '{"session_key":"' . $token . '"}', 'its response body field session_key', 'an opaque token of 20 characters or more' ),
			'a numeric token in a request body'          => array( 'body', 'reference=73519284602738495061', 'its request body field reference', 'an opaque token of 20 characters or more' ),
			'an unlisted secret prefix in a body'        => array( 'responseBody', '{"handle":"auth_live_abcdefghijkl1234567890"}', 'its response body field handle', 'an opaque token of 20 characters or more' ),
			'a token in a URL a body field holds'        => array( 'responseBody', '{"next":"https://pay.example.com/act?session=' . $token . '"}', 'its response body field next query field session', 'an opaque token of 20 characters or more' ),
			'a token in the path of a URL'               => array( 'url', 'https://api.example.com/v1/sessions/' . $token, 'its URL', 'an opaque token of 20 characters or more' ),
			'a token in a URL a header holds'            => array( 'responseHeaders', 'https://pay.example.com/return?session=' . $token, 'its response header x-signing query field session', 'an opaque token of 20 characters or more' ),
			'a token among the fields of a form body'    => array( 'body', 'amount=1000&metadata[session]=' . $token, 'its request body field metadata.session', 'an opaque token of 20 characters or more' ),
			'a token in a response body of no structure' => array( 'responseBody', 'session ' . $token, 'its response body', 'an opaque token of 20 characters or more' ),
		);
	}

	/**
	 * Tests that a recording holding a secret, an opaque token or card data is refused, where and what it found named and the value not.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider refused
	 * @group conformance
	 *
	 * @param string $part   The part of the exchange the value is planted in.
	 * @param string $value  The value planted.
	 * @param string $named  Where the refusal says it is, after the exchange.
	 * @param string $shaped What the refusal says it found.
	 */
	public function test_a_value_no_tape_may_hold_refuses_the_recording( string $part, string $value, string $named, string $shaped ): void {
		$this->assertRefused( self::WHERE . ', ' . $named . ' holds ' . $shaped, $value, fn() => self::scrubber()->scanExchange( self::scrubber()->exchange( self::exchangeWith( $part, $value ) ), self::WHERE ) );
	}

	/**
	 * Tests that no public shape passes in a field named like a credential, with a secret's prefix, or in a redirect's query.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_no_public_shape_passes_where_a_secret_may_be(): void {
		$public = self::publicScrubber();
		$uuid   = '019b76da-a800-7325-8718-1c4f7d1ef27f';
		$id     = 'pi_3MtwBwLkdIwHu7ix28a3tqPa';

		$public->scanExchange( self::exchangeWith( 'responseBody', '{"id":"' . $id . '","status":"requires_payment_method","intent":"' . $uuid . '"}' ), self::WHERE );

		$this->assertRefused( self::WHERE . ', its response body field client_secret holds an opaque token of 20 characters or more, the shape of a secret, in the field client_secret, named like a credential', $id, fn() => $public->scanExchange( self::exchangeWith( 'responseBody', '{"client_secret":"' . $id . '"}' ), self::WHERE ) );
		$this->assertRefused( self::WHERE . ', its response body field api_key holds an opaque token', $uuid, fn() => $public->scanExchange( self::exchangeWith( 'responseBody', '{"api_key":"' . $uuid . '"}' ), self::WHERE ) );
		$this->assertRefused( self::WHERE . ', its response body field handle holds an opaque token', 'pi_test_', fn() => $public->scanExchange( self::exchangeWith( 'responseBody', '{"handle":"pi_test_3MtwBwLkdIwHu7ix28a3t"}' ), self::WHERE ) );
		$this->assertRefused( self::WHERE . ', its response header location query field intent holds an opaque token of 20 characters or more, the shape of a secret, in a redirect\'s query', $uuid, fn() => $public->scanExchange( self::exchangeWith( 'responseHeaders', array( 'location' => 'https://pay.example.com/return?intent=' . $uuid ) ), self::WHERE ) );
		$public->scanExchange( self::exchangeWith( 'responseHeaders', array( 'location' => 'https://pay.example.com/act/' . $id ) ), self::WHERE );
	}

	/**
	 * Tests that a publishable key is written, in its own field, a URL's query and the settings: one shorter than an opaque token as it is, a longer one in the shape its test declares; and that a secret key is refused even in a declared shape.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_publishable_key_is_written_and_a_secret_key_is_not(): void {
		$publishable = 'pk_test_' . str_repeat( 'Ab1', 8 );
		$secret      = 'sk_test_' . str_repeat( 'Ab1', 8 );
		$declared    = new TapeScrubber(
			array( 'secret_key' => array( self::KEY ) ),
			array(),
			array(
				'pk_test_' => 24,
				'sk_test_' => 24,
			)
		);
		$answer      = '{"publishable_key":"' . $publishable . '","next_action":{"url":"https://hooks.example.com/3ds?publishable_key=' . $publishable . '"}}';
		$clean       = $declared->exchange( new Exchange( 'GET', 'https://api.example.com/v1/elements?publishable_key=' . $publishable, array(), null, 200, array( 'content-type' => 'application/json' ), $answer ) );

		$this->assertSame( $answer, $clean->responseBody );
		$declared->scanExchange( $clean, self::WHERE );
		$declared->scanSettings( array( 'publishable_key' => $publishable ), 'The settings' );
		self::scrubber()->scanSettings( array( 'publishable_key' => 'pk_live_A1b2C3d4' ), 'The settings' );

		$this->assertRefused( self::WHERE . ', its URL query field publishable_key holds an opaque token of 20 characters or more, the shape of a secret: declare it', $publishable, fn() => self::scrubber()->scanExchange( $clean, self::WHERE ) );
		$this->assertRefused( 'The settings, the setting publishable_key holds a secret or restricted key', $secret, fn() => $declared->scanSettings( array( 'publishable_key' => $secret ), 'The settings' ) );
		$this->assertRefused( self::WHERE . ', its response body field secret_key holds an opaque token of 20 characters or more, the shape of a secret, in the field secret_key, named like a credential', $publishable, fn() => $declared->scanExchange( self::exchangeWith( 'responseBody', '{"secret_key":"' . $publishable . '"}' ), self::WHERE ) );
	}

	/**
	 * Returns ids against the shapes `'pi_' => 24`, `'cus_' => array( 16, 20 )` and `'key_' => 24`, with whether each passes.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, bool}> The id, and whether it is of exactly a declared shape.
	 */
	public function ids(): array {
		$letters = 'abcdefghijklmnopqrstuvwxyz';

		return array(
			'pi_ and the 24 declared'                => array( 'pi_3MtwBwLkdIwHu7ix28a3tqPa', true ),
			'pi_ and 24 lower-case letters'          => array( 'pi_' . substr( $letters, 0, 24 ), true ),
			'pi_ and 22, an undeclared secret'       => array( 'pi_' . substr( $letters, 0, 22 ), false ),
			'pi_ and 25'                             => array( 'pi_' . substr( $letters, 0, 25 ), false ),
			'pi_ and 24 with a sign'                 => array( 'pi_3MtwBwLkdIwHu7ix28a3tq-a', false ),
			'cus_ and the least, 16'                 => array( 'cus_' . substr( $letters, 0, 16 ), true ),
			'cus_ and the most, 20'                  => array( 'cus_' . substr( $letters, 0, 20 ), true ),
			'cus_ and 21'                            => array( 'cus_' . substr( $letters, 0, 21 ), false ),
			'another prefix of the same length'      => array( 'px_' . substr( $letters, 0, 24 ), false ),
			'a declared shape of a secret\'s prefix' => array( 'key_' . substr( $letters, 0, 24 ), false ),
			'a secret\'s marker after a declared id' => array( 'pi_' . substr( $letters, 0, 24 ) . '_secret_' . substr( $letters, 0, 10 ), false ),
		);
	}

	/**
	 * Tests that an id passes only when it is exactly of a shape the test declares: its prefix and the number of letters and digits after it.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider ids
	 * @group conformance
	 *
	 * @param string $id     The id.
	 * @param bool   $passes Whether it passes.
	 */
	public function test_an_id_passes_only_in_its_declared_shape( string $id, bool $passes ): void {
		$scrubber = new TapeScrubber(
			array( 'secret_key' => array( self::KEY ) ),
			array(),
			array(
				'pi_'  => 24,
				'cus_' => array( 16, 20 ),
				'key_' => 24,
			)
		);
		$scan     = fn() => $scrubber->scanExchange( $scrubber->exchange( self::exchangeWith( 'responseBody', '{"handle":"' . $id . '"}' ) ), self::WHERE );

		if ( $passes ) {
			$scan();
			$this->addToAssertionCount( 1 );

			return;
		}

		$this->assertRefused( self::WHERE . ', its response body field handle holds ', $id, $scan );
	}

	/**
	 * Tests that an id's shape that bounds nothing is refused as a declaration: an empty prefix, no length, or a most below the least.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_shape_that_bounds_nothing_is_refused(): void {
		$refused = 0;

		foreach ( array( array( '' => 24 ), array( 'pi_' => 0 ), array( 'pi_' => array( 24, 20 ) ) ) as $shapes ) {
			try {
				new TapeScrubber( array(), array(), $shapes );
			} catch ( \InvalidArgumentException $invalid ) {
				$this->assertStringStartsWith( 'A public id\'s shape is a prefix with the number of letters and digits after it', $invalid->getMessage() );
				++$refused;
			}
		}

		$this->assertSame( 3, $refused );
	}

	/**
	 * Tests that a long code passes only when the test declares it, as an exact string.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_declared_code_passes_and_an_undeclared_one_refuses(): void {
		$answer = self::exchangeWith( 'responseBody', '{"status":"requires_payment_method"}' );

		self::publicScrubber()->scanExchange( $answer, self::WHERE );
		$this->assertRefused( self::WHERE . ', its response body field status holds an opaque token', 'requires_payment_method', fn() => self::scrubber()->scanExchange( $answer, self::WHERE ) );
		$this->assertRefused( self::WHERE . ', its response body field status holds an opaque token', 'requires_payment_method_now', fn() => self::publicScrubber()->scanExchange( self::exchangeWith( 'responseBody', '{"status":"requires_payment_method_now"}' ), self::WHERE ) );
	}

	/**
	 * Tests that a 64-hex digest passes only in a field the test declares.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_digest_passes_only_in_a_declared_field(): void {
		$signature = 't=1780315200,v1=' . hash_hmac( 'sha256', 'body', 'key' );
		$delivery  = array(
			'headers' => array( 'reference-signature' => $signature ),
			'body'    => '{"id":"evt_1"}',
		);

		self::publicScrubber()->scanDelivery( $delivery, 'The delivery authorized' );
		$this->assertRefused( 'The delivery authorized, its header reference-signature holds an opaque token', $signature, fn() => self::scrubber()->scanDelivery( $delivery, 'The delivery authorized' ) );
		$this->assertRefused( 'The delivery authorized, its header x-other holds an opaque token', $signature, fn() => self::publicScrubber()->scanDelivery( array( 'headers' => array( 'x-other' => $signature ) ) + $delivery, 'The delivery authorized' ) );
	}

	/**
	 * Tests that a header that carries credentials refuses an exchange that keeps it, and that scrubbing drops it.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_header_that_carries_credentials_is_dropped_or_refuses(): void {
		$sent  = new Exchange(
			'POST',
			'https://api.example.com/v1/charges',
			array(
				'Cookie'              => 'session=abc',
				'Proxy-Authorization' => 'Basic abc',
			),
			'amount=1000',
			200,
			array( 'Set-Cookie' => 'id=1' ),
			'{}'
		);
		$clean = self::scrubber()->exchange( $sent );

		$this->assertSame( array(), $clean->headers, 'Cookie and Proxy-Authorization are dropped.' );
		self::scrubber()->scanExchange( $clean, 'The scrubbed exchange' );

		$this->expectException( TapeRefused::class );
		$this->expectExceptionMessage( 'The raw exchange, its request header cookie carries credentials' );

		self::scrubber()->scanExchange( $sent->with( array( 'headers' => array( 'cookie' => 'session=abc' ) ) ), 'The raw exchange' );
	}

	/**
	 * Tests that an answer keeps only `content-type` and the headers its test declares, in any case: every other is dropped unread, a token in it refusing nothing, while a declared one is scanned, and `set-cookie` is dropped even when declared.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_response_header_is_kept_only_when_declared(): void {
		$token  = 'Zx81Qw7Er6Ty5Ui4Op3As2Df';
		$answer = new Exchange(
			'POST',
			'https://api.example.com/v1/charges',
			array( 'X-Trace' => 'trace-1' ),
			'amount=1000',
			200,
			array(
				'Content-Type'              => 'application/json',
				'Request-Id'                => 'req_1',
				'Set-Cookie'                => 'id=1',
				'Strict-Transport-Security' => 'max-age=63072000; includeSubDomains',
				'X-Trace'                   => $token,
			),
			'{"id":"ch_1"}'
		);

		$undeclared = new TapeScrubber( array( 'secret_key' => array( self::KEY ) ) );
		$dropped    = $undeclared->exchange( $answer );

		$this->assertSame( array( 'content-type' => 'application/json' ), $dropped->responseHeaders );
		$this->assertSame( array( 'x-trace' => 'trace-1' ), $dropped->headers, 'A request\'s headers are kept as they were.' );
		$undeclared->scanExchange( $dropped, self::WHERE );

		$declaring = new TapeScrubber( array( 'secret_key' => array( self::KEY ) ), array(), array(), array(), array(), array( 'request-id', 'X-Trace', 'set-cookie' ) );
		$kept      = $declaring->exchange( $answer );

		$this->assertSame(
			array(
				'content-type' => 'application/json',
				'request-id'   => 'req_1',
				'x-trace'      => $token,
			),
			$kept->responseHeaders
		);
		$this->assertRefused( self::WHERE . ', its response header x-trace holds an opaque token of 20 characters or more', $token, fn() => $declaring->scanExchange( $kept, self::WHERE ) );
	}

	/**
	 * Tests that a secret the provider issues is replaced when the test names it, and that a public id passes when the test declares its shape.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_named_provider_secrets_are_replaced_and_declared_public_ids_pass(): void {
		$id       = 'pi_3MtwBwLkdIwHu7ix28a3tqPa';
		$secret   = $id . '_secret_YrKJUKribcBjcG8HVhfZluoGH';
		$scrubber = new TapeScrubber( array( 'secret_key' => array( self::KEY ) ), array( 'client_secret' => '/\bpi_[A-Za-z0-9]+_secret_[A-Za-z0-9]+\b/' ), array( 'pi_' => 24 ) );
		$clean    = $scrubber->exchange( new Exchange( 'POST', 'https://api.example.com/v1/payment_intents', array(), 'amount=1000', 200, array(), '{"id":"' . $id . '","client_secret":"' . $secret . '"}' ) );

		$this->assertSame( '{"id":"' . $id . '","client_secret":"{{secret:client_secret}}"}', $clean->responseBody );
		$scrubber->scanExchange( $clean, 'The exchange' );

		$this->assertRefused( 'The exchange, without the public id declared, its response body field id holds an opaque token', $id, fn() => self::scrubber()->scanExchange( $clean, 'The exchange, without the public id declared' ) );
	}

	/**
	 * Tests that what a tape ordinarily holds passes: uuids, keys derived from them, short ids and codes, media types, the scrubber's own placeholders, the published secret.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_ordinary_values_pass(): void {
		$scrubber = new TapeScrubber(
			array(
				'secret_key'                        => array( self::KEY ),
				'restricted_webhook_signing_secret' => array( 'a long-named credential of the run' ),
			)
		);

		foreach ( array( '019b76da-a800-7325-8718-1c4f7d1ef27f:capture', '{"id":"ri_1","charge":"rch_2","request-id":"req_3","status":"requires_capture"}', TapeScrubber::PUBLISHED_WEBHOOK_SECRET ) as $text ) {
			$scrubber->scan( $text, 'An ordinary value' );
		}

		$clean = $scrubber->exchange(
			new Exchange(
				'POST',
				'https://api.example.com/v1/intents',
				array(
					'Content-Type'    => 'application/x-www-form-urlencoded',
					'Idempotency-Key' => '019b76da-a800-7325-8718-1c4f7d1ef27f:capture',
				),
				'amount=3000&return_url=' . rawurlencode( 'http://example.org/?seocart_payment=019b76da-a800-7325-8718-1c4f7d1ef27f' ),
				200,
				array( 'content-type' => 'application/json; charset=utf-8' ),
				'{"next_action":{"url":"https://pay.example.com/act/ri_1"},"intent_uuid":"019b76da-a800-7325-8718-1c4f7d1ef27f","key":"' . self::KEY . '","signing":"a long-named credential of the run"}'
			)
		);

		$scrubber->scanExchange( $clean, self::WHERE );
		$this->assertStringContainsString( '"key":"{{secret:secret_key}}","signing":"{{secret:restricted_webhook_signing_secret}}"', $clean->responseBody, 'The scrubber\'s own placeholders are written, a long setting name included.' );
	}

	/**
	 * Tests that a setting and a delivery are scanned before they are written, each part named.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_settings_and_deliveries_are_scanned(): void {
		$this->assertRefused( 'settings.json, the setting account_id holds a secret or restricted key', 'sk_live_abcdef123456', fn() => self::scrubber()->scanSettings( array( 'account_id' => 'sk_live_abcdef123456' ), 'settings.json' ) );
		$this->assertRefused( 'settings.json, the setting merchant holds an opaque token', 'abcdefghijklmnopqrstuvwxyz', fn() => self::scrubber()->scanSettings( array( 'merchant' => 'abcdefghijklmnopqrstuvwxyz' ), 'settings.json' ) );
		$this->assertRefused(
			'The delivery authorized, its header x-signing holds a webhook signing secret',
			'whsec_AbC123xyz987',
			fn() => self::scrubber()->scanDelivery(
				array(
					'headers' => array( 'x-signing' => 'whsec_AbC123xyz987' ),
					'body'    => '{}',
				),
				'The delivery authorized'
			)
		);
		$this->assertRefused(
			'The delivery authorized, its body field data.object.session holds an opaque token',
			'abcdefghijklmnopqrstuvwxyz',
			fn() => self::scrubber()->scanDelivery(
				array(
					'headers' => array(),
					'body'    => '{"data":{"object":{"session":"abcdefghijklmnopqrstuvwxyz"}}}',
				),
				'The delivery authorized'
			)
		);
	}

	/**
	 * Tests that a committed tape file is read as the suite reads it: an exchange, a delivery and settings each part by part, and any other field too.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_committed_tape_file_is_read_part_by_part(): void {
		$token                       = 'abcdefghijklmnopqrstuvwxyz';
		$this->file                  = (string) tempnam( sys_get_temp_dir(), 'seocart-tape-' );
		$exchange                    = self::exchangeWith( 'responseBody', '{"id":"ri_1"}' )->toTape();
		$delivery                    = array(
			'mode'      => 'test',
			'signed_at' => '2026-06-01T12:00:00+00:00',
			'headers'   => array( 'reference-signature' => 't=1780315200,v1=' . hash_hmac( 'sha256', 'body', 'key' ) ),
			'body'      => '{"id":"evt_1"}',
		);
		$scrubber                    = self::publicScrubber();
		$planted                     = $exchange;
		$planted['response']['body'] = '{"id":"ri_1","session":"' . $token . '"}';

		foreach ( array( $exchange, $delivery, array( 'account_country' => 'US' ) ) as $clean ) {
			file_put_contents( $this->file, (string) wp_json_encode( $clean ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A scratch file of the test.
			$scrubber->scanFile( $this->file );
		}

		foreach (
			array(
				'its response body field session' => $planted,
				'its field note'                  => $exchange + array( 'note' => $token ),
				'its field request.note'          => array_replace_recursive( $exchange, array( 'request' => array( 'note' => $token ) ) ),
				'its header reference-signature'  => array_replace_recursive( $delivery, array( 'headers' => array( 'reference-signature' => 't=1,v1=' . $token ) ) ),
				'its field account'               => array( 'account' => $token ),
			) as $named => $tape
		) {
			file_put_contents( $this->file, (string) wp_json_encode( $tape ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A scratch file of the test.
			$this->assertRefused( $this->file . ', ' . $named . ' holds an opaque token', $token, fn() => $scrubber->scanFile( $this->file ) );
		}
	}

	/**
	 * Returns parts whose raw text holds what decoding them drops, with where the refusal names it and what it found.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string, string, string, string}> The part, the value planted, where the refusal says it is, what it says it found, the text it must not quote.
	 */
	public function unread(): array {
		$token = 'abcdefghijklmnopqrstuvwxyz01';

		return array(
			'a JSON field sent twice'   => array( 'responseBody', '{"note":"' . $token . '","note":"x"}', 'its response body', 'an opaque token', $token ),
			'a form field sent twice'   => array( 'body', 'note=' . $token . '&note=x', 'its request body', 'an opaque token', $token ),
			'a query field sent twice'  => array( 'url', 'https://api.example.com/v1/charges?session=' . $token . '&session=x', 'its URL query', 'an opaque token', $token ),
			'a number too long to read' => array( 'responseBody', '{"n":735192846027384950617283}', 'its response body', 'an opaque token', '735192846027384950617283' ),
			'a card number sent twice'  => array( 'responseBody', '{"pan":"4242424242424242","pan":"x"}', 'its response body', 'a card number', '4242424242424242' ),
			'a form card number twice'  => array( 'body', 'pan=4242424242424242&pan=x', 'its request body', 'a card number', '4242424242424242' ),
			'a timestamp sent twice'    => array( 'body', 'when=' . self::ESCAPED_TIME . '&when=x', 'its request body', 'a card number', '20260601120005' ),
			'a dotted name sent twice'  => array( 'body', 'metadata.note=' . $token . '&metadata.note=x', 'its request body', 'an opaque token', $token ),
		);
	}

	/**
	 * Tests that a dropped card number is held only by a field whose digits are its digits: one inside a longer number, here a public reference the test declares, is refused.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_dropped_card_number_inside_a_longer_number_is_refused(): void {
		$reference = '99424242424242424299';
		$scrubber  = new TapeScrubber( array( 'secret_key' => array( self::KEY ) ), array(), array(), array(), array( $reference ) );
		$answer    = self::exchangeWith( 'responseBody', '{"pan":"4242424242424242","pan":"x","ref":"' . $reference . '"}' );

		$this->assertRefused( self::WHERE . ', its response body holds, outside every field it was read as, a card number', '4242424242424242', fn() => $scrubber->scanExchange( $scrubber->exchange( $answer ), self::WHERE ) );
	}

	/**
	 * Tests that a form's and a query's field names, as written, hold their tokens: decoding rewrites a `.` in a name as `_`, and the name is still the field's.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_dotted_field_name_is_written(): void {
		$scrubber = self::scrubber();
		$clean    = $scrubber->exchange( new Exchange( 'POST', 'https://api.example.com/v1/charges?metadata.order_reference_number=1', array(), 'metadata.order_reference_number=1&amount=2000', 200, array(), '{}' ) );

		$scrubber->scanExchange( $clean, self::WHERE );
		$this->assertSame( 'metadata.order_reference_number=1&amount=2000', $clean->body );
	}

	/**
	 * Tests that a timestamp whose escaped separators read as breaks in the raw text is its field's: its digits, though they pass the card rule's check, are a decoded value's digits.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_an_escaped_timestamp_is_written(): void {
		$scrubber = self::scrubber();
		$clean    = $scrubber->exchange( self::exchangeWith( 'body', 'amount=1000&when=' . self::ESCAPED_TIME ) );

		$scrubber->scanExchange( $clean, self::WHERE );
		$this->assertSame( 'amount=1000&when=' . self::ESCAPED_TIME, $clean->body );
	}

	/**
	 * Tests that what decoding a part drops, the first of a field sent twice or a number too long to read as one, is read in its raw text and refuses the recording by the part's name.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider unread
	 * @group conformance
	 *
	 * @param string $part   The part of the exchange the value is planted in.
	 * @param string $value  The value planted.
	 * @param string $named  Where the refusal says it is, after the exchange.
	 * @param string $shaped What the refusal says it found.
	 * @param string $quoted The text the refusal must not quote.
	 */
	public function test_what_decoding_drops_is_read_in_the_raw_text( string $part, string $value, string $named, string $shaped, string $quoted ): void {
		$this->assertRefused( self::WHERE . ', ' . $named . ' holds, outside every field it was read as, ' . $shaped, $quoted, fn() => self::scrubber()->scanExchange( self::scrubber()->exchange( self::exchangeWith( $part, $value ) ), self::WHERE ) );
	}

	/**
	 * Tests that a dropped value is held only by a whole token of a field: one that is only part of a longer token, a declared public id, is refused.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_dropped_value_inside_a_longer_token_is_refused(): void {
		$scrubber = self::publicScrubber();
		$answer   = self::exchangeWith( 'responseBody', '{"session":"abcdefghijklmnopqrst","session":"x","id":"pi_abcdefghijklmnopqrst1234"}' );

		$this->assertRefused( self::WHERE . ', its response body holds, outside every field it was read as, an opaque token', 'abcdefghijklmnopqrst', fn() => $scrubber->scanExchange( $scrubber->exchange( $answer ), self::WHERE ) );
	}

	/**
	 * Tests that a dropped value a field holds as a whole token is written, though it is also part of a public id: a sentence holds its words.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_dropped_value_a_field_holds_as_a_word_is_written(): void {
		$code     = 'abcdefghijklmnopqrst1234';
		$scrubber = new TapeScrubber( array( 'secret_key' => array( self::KEY ) ), array(), array( 'pi_' => 24 ), array(), array( $code ) );
		$clean    = $scrubber->exchange( self::exchangeWith( 'responseBody', '{"status":"' . $code . '","status":"x","id":"pi_' . $code . '","note":"the code ' . $code . ' is the provider\'s"}' ) );

		$scrubber->scanExchange( $clean, self::WHERE );
		$this->assertStringContainsString( '"status":"' . $code . '"', $clean->responseBody, 'The first of the field sent twice is written as it was sent.' );
	}

	/**
	 * Tests that a committed tape whose own JSON sends a field twice is refused by its file's name.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_committed_tape_that_sends_a_field_twice_is_refused(): void {
		$token      = 'abcdefghijklmnopqrstuvwxyz01';
		$this->file = (string) tempnam( sys_get_temp_dir(), 'seocart-tape-' );
		$tape       = (string) wp_json_encode( self::exchangeWith( 'responseBody', '{"id":"ri_1"}' )->toTape() );

		file_put_contents( $this->file, substr( $tape, 0, -1 ) . ',"note":"' . $token . '","note":"x"}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A scratch file of the test.

		$this->assertRefused( $this->file . ' holds, outside every field it was read as, an opaque token', $token, fn() => self::publicScrubber()->scanFile( $this->file ) );
	}

	/**
	 * Tests that the raw text of an ordinary exchange passes: every token in it is a decoded key's or value's, however it is escaped.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_an_ordinary_raw_text_passes(): void {
		$uuid  = '019b76da-a800-7325-8718-1c4f7d1ef27f';
		$clean = self::publicScrubber()->exchange(
			new Exchange(
				'POST',
				'https://api.example.com/v1/intents?intent_uuid=' . $uuid . '&expand%5B%5D=latest_charge',
				array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'amount=3000&return_url=' . rawurlencode( 'http://example.org/?seocart_payment=' . $uuid ) . '&metadata%5Bintent_uuid%5D=' . $uuid,
				200,
				array( 'content-type' => 'application/json' ),
				'{"id":"pi_3MtwBwLkdIwHu7ix28a3tqPa","intent_uuid":"' . $uuid . '","next_action":{"url":"https:\/\/pay.example.com\/act\/pi_3MtwBwLkdIwHu7ix28a3tqPa"},"note":"caf\u00e9 latest_charge","amount":3000,"captured":false}'
			)
		);

		self::publicScrubber()->scanExchange( $clean, self::WHERE );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that a credential sent as hexadecimal is replaced too.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_credential_in_hexadecimal_is_replaced(): void {
		$this->assertSame( 'k={{secret:secret_key}}&K={{secret:secret_key}}', self::scrubber()->text( 'k=' . bin2hex( self::KEY ) . '&K=' . strtoupper( bin2hex( self::KEY ) ) ) );
	}

	/**
	 * Asserts a scan is refused with a message that begins as given and never quotes the value.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $begins How the refusal begins.
	 * @param string   $value  The value it must not quote.
	 * @param \Closure $scan   The scan.
	 */
	private function assertRefused( string $begins, string $value, \Closure $scan ): void {
		try {
			$scan();
		} catch ( TapeRefused $refused ) {
			$this->assertStringStartsWith( $begins, $refused->getMessage() );
			$this->assertStringNotContainsString( $value, $refused->getMessage(), 'A refusal never quotes the value.' );

			return;
		}

		self::fail( sprintf( 'The scan was not refused: %s', $begins ) );
	}

	/**
	 * Builds an exchange of ordinary parts, with one part given.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $part  The part: url, headers, body, responseHeaders or responseBody.
	 * @param string|array<string> $value Its value; a header's value goes under `x-signing` unless headers are given.
	 * @return Exchange The exchange.
	 */
	private static function exchangeWith( string $part, string|array $value ): Exchange {
		$parts = array(
			'url'             => 'https://api.example.com/v1/charges',
			'headers'         => array( 'Content-Type' => 'application/json' ),
			'body'            => 'amount=1000',
			'responseHeaders' => array( 'content-type' => 'application/json' ),
			'responseBody'    => '{"id":"ch_1"}',
		);

		$parts[ $part ] = in_array( $part, array( 'headers', 'responseHeaders' ), true ) && is_string( $value ) ? array( 'x-signing' => $value ) : $value;

		return new Exchange( 'POST', (string) $parts['url'], (array) $parts['headers'], (string) $parts['body'], 200, (array) $parts['responseHeaders'], (string) $parts['responseBody'] );
	}

	/**
	 * Builds the scrubber of the test's credential, keeping the response headers the tests plant values in.
	 *
	 * @since 0.2.0
	 *
	 * @return TapeScrubber The scrubber.
	 */
	private static function scrubber(): TapeScrubber {
		return new TapeScrubber( array( 'secret_key' => array( self::KEY ) ), array(), array(), array(), array(), self::PLANTED_HEADERS );
	}

	/**
	 * Builds the scrubber of a test that declares its provider's public shapes: an id's shape, a signature header and a code.
	 *
	 * @since 0.2.0
	 *
	 * @return TapeScrubber The scrubber.
	 */
	private static function publicScrubber(): TapeScrubber {
		return new TapeScrubber( array( 'secret_key' => array( self::KEY ) ), array(), array( 'pi_' => 24 ), array( 'reference-signature' ), array( 'requires_payment_method' ), self::PLANTED_HEADERS );
	}
}
