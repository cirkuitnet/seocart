<?php
/**
 * ReportCode: the machine codes the outbound HTTP client writes log lines under
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Machine codes that reach the log, never a client.
 *
 * This enum owns one fact: the codes of the one line the outbound client writes per request.
 * None is ever thrown, none has a row in the error table, and none may spell a code the error
 * table or another module's reports use; a test composes them and checks it.
 *
 * @since 0.2.0
 */
enum ReportCode: string {

	/**
	 * A request was answered, with any status. A debug line: the URL without its query, the
	 * method, the status, the duration and a hash of the body.
	 *
	 * @since 0.2.0
	 */
	case Answered = 'http.request_answered';

	/**
	 * A request got no usable answer. An info line: the URL without its query, the method, the
	 * duration and the kind of failure.
	 *
	 * @since 0.2.0
	 */
	case Failed = 'http.request_failed';
}
