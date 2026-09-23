<?php
/**
 * ReportCode: the machine codes the Logging module writes lines under itself
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Machine codes that reach the log, never a client.
 *
 * This enum owns one fact: the codes the logging module gives a line when its caller has
 * none to give. None is ever thrown, none has a row in the error table, and none may spell a
 * code the error table or another module's reports use; a test composes them and checks it.
 *
 * @since 0.1.0
 */
enum ReportCode: string {

	/**
	 * An operation's service failed with something other than a coded error; the client got the
	 * generic internal error. The line's channel is `operations`, the part of the plugin that
	 * reported it.
	 *
	 * @since 0.1.0
	 */
	case OperationFailed = 'operations.unexpected_failure';

	/**
	 * A line was logged with a code that is not lowercase dotted words, or that holds a card
	 * number; the code it was given is in the line's context, redacted.
	 *
	 * @since 0.1.0
	 */
	case InvalidCode = 'logging.invalid_code';

	/**
	 * A card number was removed from a log line. The line naming it follows the offending one and
	 * carries its channel and machine code only, never its content or a digit of the card.
	 *
	 * @since 0.1.0
	 */
	case CardNumberRemoved = 'logging.card_number_removed';
}
