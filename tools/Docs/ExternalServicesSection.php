<?php
/**
 * ExternalServicesSection: generates the "External services" section of readme.txt
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

use SEOCart\Platform\Http\OutboundEndpoints;

/**
 * Renders the outbound-endpoint registry as the disclosure WordPress.org requires.
 *
 * Guideline 7 of the plugin directory requires a plugin to document every external
 * service it contacts: what the service is, what is sent, when, and where its terms
 * and privacy policy are. This generator owns the region of readme.txt between the
 * `== External services ==` heading and the next section heading, and nothing else in
 * the file. Where the section sits is a hand-made decision, so the heading must
 * already exist.
 *
 * Nothing is ever skipped: an incomplete registry entry is an error, not an omission.
 *
 * @since 0.1.0
 */
final class ExternalServicesSection implements Generator {

	/**
	 * The section's title in readme.txt. The readme validator requires a section of this name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TITLE = 'External services';

	/**
	 * The registry entries to render.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int|string, mixed>
	 */
	private array $endpoints;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int|string, mixed> $endpoints The registry entries: OutboundEndpoints::all().
	 */
	public function __construct( array $endpoints ) {
		$this->endpoints = $endpoints;
	}

	/**
	 * Returns the generator's stable identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string The identifier.
	 */
	public function id(): string {
		return 'readme-external-services';
	}

	/**
	 * Returns the file this generator owns a region of.
	 *
	 * @since 0.1.0
	 *
	 * @return string Path relative to the repository root.
	 */
	public function target(): string {
		return 'readme.txt';
	}

	/**
	 * Returns the source items this generator may leave out: none.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Always empty.
	 */
	public function expectedSkips(): array {
		return array();
	}

	/**
	 * Replaces the body of the External services section of readme.txt.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When a registry entry is invalid or readme.txt cannot host the section.
	 *
	 * @param string $current The current content of readme.txt.
	 * @return GenerationResult The readme with the section regenerated. Nothing is skipped.
	 */
	public function generate( string $current ): GenerationResult {
		if ( str_contains( $current, "\r" ) ) {
			throw new \RuntimeException( 'readme.txt must use LF line endings.' );
		}

		$heading = '==[ \t]*' . preg_quote( self::TITLE, '/' ) . '[ \t]*==[ \t]*';
		$count   = preg_match_all( '/^' . $heading . '$/m', $current );

		if ( 1 !== $count ) {
			throw new \RuntimeException( 'readme.txt must contain exactly one "== ' . self::TITLE . " ==\" heading; found {$count}. Add the heading where the section belongs, then regenerate." );
		}

		// From the heading up to, but not including, the next `== Section ==` heading or the end of the file.
		preg_match( '/^' . $heading . '$.*?(?=^==[^=]|\z)/ms', $current, $matches, PREG_OFFSET_CAPTURE );

		$start   = $matches[0][1];
		$before  = substr( $current, 0, $start );
		$after   = substr( $current, $start + strlen( $matches[0][0] ) );
		$section = '== ' . self::TITLE . " ==\n\n" . $this->render() . "\n" . ( '' === $after ? '' : "\n" );

		return new GenerationResult( $before . $section . $after );
	}

	/**
	 * Renders the body of the section, without its heading and without a trailing line ending.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When a registry entry is invalid.
	 *
	 * @return string The section body.
	 */
	public function render(): string {
		if ( array() === $this->endpoints ) {
			return 'SEOCart does not connect to any external service: it sends no data from your site to any other server.';
		}

		$blocks = array( 'SEOCart connects to the external services listed below, each only for the purpose stated and only under the condition stated. It contacts no other server.' );

		foreach ( $this->validated() as $endpoint ) {
			$blocks[] = "= {$endpoint['service']} =";
			$blocks[] = $endpoint['purpose'];
			$blocks[] = implode(
				"\n",
				array(
					"* Endpoint: `{$endpoint['endpoint']}`",
					"* Data sent: {$endpoint['data_sent']}",
					"* When: {$endpoint['sent_when']}",
					"* Terms of use: {$endpoint['terms_url']}",
					"* Privacy policy: {$endpoint['privacy_url']}",
				)
			);
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Returns the registry entries after checking each one against the declared shape.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When an entry is invalid.
	 *
	 * @return list<array<string, string>> The entries, in registry order.
	 */
	private function validated(): array {
		$valid = array();
		$ids   = array();

		foreach ( $this->endpoints as $position => $endpoint ) {
			$name = is_array( $endpoint ) && is_string( $endpoint['id'] ?? null ) ? "\"{$endpoint['id']}\"" : "at position {$position}";

			if ( ! is_array( $endpoint ) || array() !== array_diff( OutboundEndpoints::FIELDS, array_keys( $endpoint ) ) || array() !== array_diff( array_keys( $endpoint ), OutboundEndpoints::FIELDS ) ) {
				throw new \RuntimeException( "Outbound endpoint {$name} must have exactly these keys: " . implode( ', ', OutboundEndpoints::FIELDS ) . '.' );
			}

			foreach ( $endpoint as $field => $value ) {
				if ( ! is_string( $value ) || '' === trim( $value ) || 1 === preg_match( '/[\r\n]/', $value ) ) {
					throw new \RuntimeException( "Outbound endpoint {$name}: \"{$field}\" must be a non-empty, single-line string." );
				}
			}

			if ( 1 !== preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $endpoint['id'] ) ) {
				throw new \RuntimeException( "Outbound endpoint {$name}: the id must be kebab-case." );
			}

			if ( isset( $ids[ $endpoint['id'] ] ) ) {
				throw new \RuntimeException( "Outbound endpoint {$name} is declared twice." );
			}

			foreach ( array( 'terms_url', 'privacy_url' ) as $field ) {
				if ( ! str_starts_with( $endpoint[ $field ], 'https://' ) ) {
					throw new \RuntimeException( "Outbound endpoint {$name}: \"{$field}\" must be an https:// link." );
				}
			}

			$ids[ $endpoint['id'] ] = true;
			$valid[]                = $endpoint;
		}

		return $valid;
	}
}
