<?php
/**
 * OutboundEndpoints: the registry of every external service the plugin contacts
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Declares every server, other than the site itself, that SEOCart may contact.
 *
 * This class owns one fact: which external services the plugin talks to, what it sends
 * to each one, and when. The "External services" section of readme.txt is generated
 * from this list (`composer docs:generate`) and drift-tested against it
 * (`composer docs:check`), so the disclosure the WordPress.org directory requires
 * cannot fall behind the code. Code that calls a service that is not declared here is
 * a defect.
 *
 * The list is pure data. It is read by command-line tools that never load WordPress,
 * so it performs no I/O, calls no WordPress function and translates nothing: the
 * strings are English sentences written for readme.txt, which the directory
 * translates on its own.
 *
 * @since 0.1.0
 *
 * @phpstan-type OutboundEndpoint array{
 *     id: string,
 *     service: string,
 *     purpose: string,
 *     endpoint: string,
 *     data_sent: string,
 *     sent_when: string,
 *     terms_url: string,
 *     privacy_url: string
 * }
 */
final class OutboundEndpoints {

	/**
	 * The keys every entry of all() carries — all of them, and no others.
	 *
	 * Every value is a non-empty string.
	 *
	 * - `id`          Stable, unique, kebab-case identifier, for example `vies-vat-validation`.
	 * - `service`     The service's public name, as its operator writes it.
	 * - `purpose`     One sentence: what the service is and what SEOCart uses it for.
	 * - `endpoint`    The host or URL pattern contacted, for example `https://api.example.com/v1/*`.
	 * - `data_sent`   One sentence: exactly which data leaves the site.
	 * - `sent_when`   One sentence: what triggers the request, including the setting that enables it.
	 * - `terms_url`   HTTPS link to the service's terms of use.
	 * - `privacy_url` HTTPS link to the service's privacy policy.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const FIELDS = array(
		'id',
		'service',
		'purpose',
		'endpoint',
		'data_sent',
		'sent_when',
		'terms_url',
		'privacy_url',
	);

	/**
	 * Returns every declared external service, in the order readme.txt lists them.
	 *
	 * Empty today: SEOCart contacts no server other than the site it runs on.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array<string, string>> One entry per service, keyed by self::FIELDS.
	 *
	 * @phpstan-return list<OutboundEndpoint>
	 */
	public static function all(): array {
		return array();
	}
}
