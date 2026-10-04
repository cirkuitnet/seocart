<?php
/**
 * OutboundHost: the declaration of one external service, and of the one host it is called on
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a mistake in a declaration to its developer; they are never HTML.

defined( 'ABSPATH' ) || exit;

/**
 * An external service the plugin, or one of its extensions, may contact.
 *
 * Owns one fact: what is disclosed about a service and where it may be called. The same
 * declaration feeds both. The "External services" section of a readme is generated from it, as
 * the WordPress.org directory requires, and the outbound HTTP client refuses a request whose host
 * is not the one declared here. So a service cannot be called without being disclosed.
 *
 * The host is read from the endpoint, so it is written once. The endpoint is an https:// URL, or
 * a URL pattern such as `https://api.example.com/v1/*`, on one fixed host: a service whose host
 * varies cannot be declared, because the client could not check it.
 *
 * A declaration is data. Constructing one performs no I/O, calls no WordPress function and
 * translates nothing, so tools that never load WordPress can read it. The texts are English
 * sentences written for a readme, which the plugin directory translates on its own.
 *
 *     new OutboundHost(
 *         id: 'example-rates',
 *         service: 'Example Rates',
 *         purpose: 'Example Rates is an exchange-rate service. SEOCart uses it to refresh exchange rates.',
 *         endpoint: 'https://api.example.com/v1/rates',
 *         dataSent: 'The store currency code. No personal data.',
 *         sentWhen: 'Once a day, after an administrator turns on automatic exchange rates.',
 *         termsUrl: 'https://example.com/terms',
 *         privacyUrl: 'https://example.com/privacy',
 *     );
 *
 * @since 0.2.0
 * @api
 */
final readonly class OutboundHost {

	/**
	 * An endpoint: https://, then a lower-case host name of at least two labels, then nothing or a path.
	 *
	 * No port, no user name or password, no query and no fragment: the host is all the client
	 * checks, so nothing else may change where a request goes.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ENDPOINT = '#^https://(?<host>[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+)(?:/[^\s?\#]*)?$#D';

	/**
	 * The host every request to this service must be sent to, read from the endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public string $host;

	/**
	 * Declares a service.
	 *
	 * Every text is non-empty and on one line.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When a field breaks a rule stated here.
	 *
	 * @param string $id             A stable, unique, kebab-case identifier, for example `vies-vat-validation`.
	 *                               A request names the service it is for by this id.
	 * @param string $service        The service's public name, as its operator writes it.
	 * @param string $purpose        What the service is and what it is used for.
	 * @param string $endpoint       The https:// URL or URL pattern contacted, on one fixed host.
	 * @param string $dataSent       Exactly which data leaves the site.
	 * @param string $sentWhen       What triggers a request, including the setting that enables it.
	 * @param string $termsUrl       An https:// link to the service's terms of use.
	 * @param string $privacyUrl     An https:// link to the service's privacy policy.
	 * @param int    $timeoutSeconds Optional. How long a request may take in all, in seconds, at
	 *                               least 1; the client never waits longer than its own ceiling.
	 *                               Default 15.
	 */
	public function __construct(
		public string $id,
		public string $service,
		public string $purpose,
		public string $endpoint,
		public string $dataSent,
		public string $sentWhen,
		public string $termsUrl,
		public string $privacyUrl,
		public int $timeoutSeconds = 15
	) {
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id ) ) {
			throw new \InvalidArgumentException( 'An outbound host id must be kebab-case, for example "example-rates".' );
		}

		if ( 1 !== preg_match( self::ENDPOINT, $endpoint, $matches ) ) {
			throw new \InvalidArgumentException( "Outbound host \"{$id}\": \"endpoint\" must be an https:// URL on one lower-case host, with no port, credentials, query or fragment." );
		}

		$this->host = $matches['host'];

		// Every text field, so one added later is checked too.
		foreach ( get_object_vars( $this ) as $field => $value ) {
			if ( is_string( $value ) && ( '' === trim( $value ) || 1 === preg_match( '/[\r\n]/', $value ) ) ) {
				throw new \InvalidArgumentException( "Outbound host \"{$id}\": \"{$field}\" must be a non-empty, single-line text." );
			}
		}

		$links = array(
			'termsUrl'   => $termsUrl,
			'privacyUrl' => $privacyUrl,
		);

		foreach ( $links as $field => $link ) {
			if ( ! str_starts_with( $link, 'https://' ) ) {
				throw new \InvalidArgumentException( "Outbound host \"{$id}\": \"{$field}\" must be an https:// link." );
			}
		}

		if ( $timeoutSeconds < 1 ) {
			throw new \InvalidArgumentException( "Outbound host \"{$id}\": \"timeoutSeconds\" must be at least 1." );
		}
	}
}
