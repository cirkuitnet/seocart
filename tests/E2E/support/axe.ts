/**
 * The accessibility gate: axe-core, run inside the Playwright suite, held to WCAG 2.2 AA.
 *
 * axe finds roughly half of real accessibility defects. This is a regression gate, not a
 * substitute for the manual keyboard and screen-reader pass in the definition of done.
 *
 * `tests/E2E/selftest/axe.spec.ts` proves, without a WordPress site, that this helper fails
 * when it should.
 */

import { AxeBuilder } from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

/** axe rule tags that together make up WCAG 2.2 level A and AA. axe ignores a tag it does not know. */
const WCAG_22_AA_TAGS = [
	'wcag2a',
	'wcag2aa',
	'wcag21a',
	'wcag21aa',
	'wcag22aa',
] as const;

export interface AccessibilityCheckOptions {
	/** CSS selectors of the regions to scan. Default: the whole page. */
	include?: string | string[];
	/** CSS selectors of regions to leave out, for markup this plugin does not own. */
	exclude?: string | string[];
	/** axe rule ids to skip. Each use needs a comment at the call site saying why. */
	disableRules?: string[];
}

type Violation = Awaited<
	ReturnType< AxeBuilder[ 'analyze' ] >
>[ 'violations' ][ number ];

function toList( value: string | string[] | undefined ): string[] {
	return value === undefined ? [] : [ value ].flat();
}

function describeViolation( violation: Violation, index: number ): string {
	const targets = violation.nodes.map(
		( node ) => `       - ${ node.target.join( ' ' ) }`
	);
	return [
		`  ${ index + 1 }. ${ violation.id } [${
			violation.impact ?? 'unknown'
		}] ${ violation.help }`,
		`     ${ violation.helpUrl }`,
		...targets,
	].join( '\n' );
}

/**
 * Fails when axe reports a WCAG 2.2 AA violation. The failure lists each rule id, its impact
 * and the selector of every offending element.
 *
 * A selector in `include` that matches nothing fails too: a scan of nothing proves nothing.
 *
 * @param page    The page to scan, in whatever state the test has brought it to.
 * @param options Scope and exceptions; see `AccessibilityCheckOptions`.
 */
export async function expectNoAccessibilityViolations(
	page: Page,
	options: AccessibilityCheckOptions = {}
): Promise< void > {
	let builder = new AxeBuilder( { page } ).withTags( [ ...WCAG_22_AA_TAGS ] );

	for ( const selector of toList( options.include ) ) {
		await expect(
			page.locator( selector ),
			`Accessibility scan scope "${ selector }" matches no element`
		).not.toHaveCount( 0 );
		builder = builder.include( selector );
	}
	for ( const selector of toList( options.exclude ) ) {
		builder = builder.exclude( selector );
	}
	if ( options.disableRules?.length ) {
		builder = builder.disableRules( options.disableRules );
	}

	const { violations } = await builder.analyze();

	expect(
		violations.length,
		[
			`${ violations.length } WCAG 2.2 AA violation(s) at ${ page.url() }:`,
			...violations.map( describeViolation ),
		].join( '\n' )
	).toBe( 0 );
}
