<?php
/**
 * Runs one of the PHP steps of a payment extension's live workflow, for bin/ci/extension-live.sh and its lib
 *
 * Usage: php workflow-step.php <step> <extension root> [<argument> ...]
 *
 *   redact <root>                              copies the standard input to the output, credentials and card
 *                                              numbers blanked out
 *   describe <root> slug | group | filter <part>
 *                                              prints the release zip's slug, the live tests' group, or a live
 *                                              part's PHPUnit filter
 *   suite <root> <output>                      checks the conformance suite's run against the provider
 *   harvest <root> <tapes> <mark> <run list>   adds what the suite created at the provider to the run list,
 *                                              from the tapes it recorded after the mark was made
 *   live <root> <part> <output> <report>       checks a run of a part's live tests
 *   optional <root> <job>                      says whether the optional part's secret is set, never its value
 *   verdict <root>                             judges the run: the jobs' results and outputs in
 *                                              SEOCART_LIVE_NEEDS, the parts in SEOCART_LIVE_PARTS, the
 *                                              summary's title in GITHUB_WORKFLOW
 *
 * The extension's tests/live.php states what is its provider's (LiveDeclaration). Outputs go to
 * the file GITHUB_OUTPUT names, and the verdict's table to the one GITHUB_STEP_SUMMARY names; to
 * the standard output where neither is set. Needs no WordPress, no Composer install and no
 * autoloader: the classes beside this file and the declaration are required by path, as the
 * verdict's job installs nothing.
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;
use SEOCart\Tests\Conformance\Payment\Live\WorkflowStep;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$seocart_live_classes = glob( __DIR__ . '/[A-Z]*.php' );

foreach ( false === $seocart_live_classes ? array() : $seocart_live_classes as $seocart_live_class ) {
	require_once $seocart_live_class;
}

$seocart_live_steps = array( 'redact', 'describe', 'suite', 'harvest', 'live', 'optional', 'verdict' );

if ( ! in_array( $argv[1] ?? '', $seocart_live_steps, true ) || '' === ( $argv[2] ?? '' ) ) {
	fwrite( STDERR, "usage: php workflow-step.php redact|describe|suite|harvest|live|optional|verdict <extension root> [<argument> ...]\n" );
	exit( 2 );
}

try {
	$seocart_live_declaration = LiveDeclaration::load( $argv[2] );
} catch ( \UnexpectedValueException $seocart_live_refused ) {
	fwrite( STDOUT, '::error::' . str_replace( array( "\r", "\n" ), ' ', $seocart_live_refused->getMessage() ) . "\n" );
	exit( 1 );
}

$seocart_live_outputs = fopen( '' === (string) getenv( 'GITHUB_OUTPUT' ) ? 'php://stdout' : (string) getenv( 'GITHUB_OUTPUT' ), 'ab' );
$seocart_live_summary = fopen( '' === (string) getenv( 'GITHUB_STEP_SUMMARY' ) ? 'php://stdout' : (string) getenv( 'GITHUB_STEP_SUMMARY' ), 'ab' );
$seocart_live_read    = static fn( int $position ): string => is_readable( $argv[ $position ] ?? '' ) ? (string) file_get_contents( $argv[ $position ] ) : '';
$seocart_live_secret  = $seocart_live_declaration->optionalPart()['secret'] ?? '';

if ( false === $seocart_live_outputs || false === $seocart_live_summary ) {
	fwrite( STDERR, "workflow-step: the outputs or the summary cannot be written.\n" );
	exit( 1 );
}

exit(
	match ( $argv[1] ) {
		'redact'   => WorkflowStep::redact( $seocart_live_declaration, STDIN, STDOUT ),
		'describe' => WorkflowStep::describe( $seocart_live_declaration, $argv[3] ?? '', $argv[4] ?? '', STDOUT, STDERR ),
		'suite'    => WorkflowStep::suite( $seocart_live_declaration, $seocart_live_read( 3 ), $seocart_live_outputs, STDOUT ),
		'harvest'  => WorkflowStep::harvest( $seocart_live_declaration, $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? '', STDOUT ),
		'live'     => WorkflowStep::live( $seocart_live_declaration, $argv[3] ?? '', $seocart_live_read( 4 ), $seocart_live_read( 5 ), $seocart_live_outputs, STDOUT ),
		'optional' => WorkflowStep::optional( $seocart_live_declaration, $argv[3] ?? '', '' === $seocart_live_secret ? '' : (string) getenv( $seocart_live_secret ), $seocart_live_outputs, STDOUT ),
		'verdict'  => WorkflowStep::verdict( $seocart_live_declaration, (string) getenv( 'SEOCART_LIVE_NEEDS' ), (string) getenv( 'SEOCART_LIVE_PARTS' ), (string) getenv( 'GITHUB_WORKFLOW' ), $seocart_live_summary, STDOUT ),
	}
);
