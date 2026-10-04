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

use SEOCart\Contracts\OutboundHost;

/**
 * Renders the declared external services as the disclosure WordPress.org requires.
 *
 * Guideline 7 of the plugin directory requires a plugin to document every external
 * service it contacts: what the service is, what is sent, when, and where its terms
 * and privacy policy are. This generator owns the region of readme.txt between the
 * `== External services ==` heading and the next section heading, and nothing else in
 * the file. Where the section sits is a hand-made decision, so the heading must
 * already exist.
 *
 * Nothing is ever skipped. Each entry is an OutboundHost, which refuses an incomplete field
 * when it is constructed; an entry of another type, or a second entry with the same id, is an
 * error here, not an omission.
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
	 * The declarations to render.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int|string, mixed>
	 */
	private array $hosts;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Takes OutboundHost declarations instead of arrays.
	 *
	 * @param array<int|string, mixed> $hosts The declarations: OutboundEndpoints::all().
	 */
	public function __construct( array $hosts ) {
		$this->hosts = $hosts;
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
		if ( array() === $this->hosts ) {
			return 'SEOCart does not connect to any external service: it sends no data from your site to any other server.';
		}

		$blocks = array( 'SEOCart connects to the external services listed below, each only for the purpose stated and only under the condition stated. It contacts no other server.' );

		foreach ( $this->validated() as $host ) {
			$blocks[] = "= {$host->service} =";
			$blocks[] = $host->purpose;
			$blocks[] = implode(
				"\n",
				array(
					"* Endpoint: `{$host->endpoint}`",
					"* Data sent: {$host->dataSent}",
					"* When: {$host->sentWhen}",
					"* Terms of use: {$host->termsUrl}",
					"* Privacy policy: {$host->privacyUrl}",
				)
			);
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Returns the declarations after checking that each one is an OutboundHost with an id of its own.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 OutboundHost checks each field; this checks the type and the ids.
	 *
	 * @throws \RuntimeException When an entry is not an OutboundHost, or its id was seen before.
	 *
	 * @return list<OutboundHost> The declarations, in registry order.
	 */
	private function validated(): array {
		$valid = array();
		$ids   = array();

		foreach ( $this->hosts as $position => $host ) {
			if ( ! $host instanceof OutboundHost ) {
				throw new \RuntimeException( "Outbound endpoint at position {$position} must be an " . OutboundHost::class . '.' );
			}

			if ( isset( $ids[ $host->id ] ) ) {
				throw new \RuntimeException( "Outbound endpoint \"{$host->id}\" is declared twice." );
			}

			$ids[ $host->id ] = true;
			$valid[]          = $host;
		}

		return $valid;
	}
}
