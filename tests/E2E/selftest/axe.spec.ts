/**
 * Self-test of the accessibility gate. A gate that cannot fail is worthless, so this spec
 * keeps a planted violation in the repository for good: a page with an image that has no text
 * alternative and a paragraph with too little contrast must make the helper fail, and the
 * same page without those defects must pass. Further defects hold the tag list to its name.
 *
 * It uses Playwright's plain `test`, not tests/E2E/fixtures: the WordPress fixtures need a
 * site, and this spec must run without one.
 */

import { test, expect } from '@playwright/test';
import { expectNoAccessibilityViolations } from '../support/axe';

const PIXEL =
	'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

function fixturePage( body: string ): string {
	return `<!doctype html>
<html lang="en">
	<head><meta charset="utf-8"><title>Accessibility gate self-test</title></head>
	<body><main><h1>Accessibility gate self-test</h1>${ body }</main></body>
</html>`;
}

const CLEAN_REGION = `<section id="clean">
	<img src="${ PIXEL }" alt="A single dark pixel" width="16" height="16">
	<p style="color: #1e1e1e; background: #ffffff;">Text with ample contrast.</p>
</section>`;

const BROKEN_REGION = `<section id="broken">
	<img id="no-alt" src="${ PIXEL }" width="16" height="16">
	<p id="low-contrast" style="color: #bbbbbb; background: #ffffff;">Text with too little contrast.</p>
</section>`;

/**
 * One defect for each tag that a planted defect can reach: `target-size` is the wcag22aa rule
 * and `autocomplete-valid` a wcag21aa one. The only wcag21a rule is experimental, and axe
 * does not run those by tag.
 */
const TINY_BUTTON =
	'width: 10px; height: 10px; padding: 0; border: 0; margin: 0;';
const LATER_CRITERIA_REGION = `<section id="later-criteria">
	<p><button id="tiny-one" aria-label="First" style="${ TINY_BUTTON }"></button><button id="tiny-two" aria-label="Second" style="${ TINY_BUTTON }"></button></p>
	<label>Name <input id="bad-autocomplete" type="text" autocomplete="not-a-token"></label>
</section>`;

async function failureOf( check: Promise< void > ): Promise< string > {
	const error = await check.then(
		() => null,
		( reason: Error ) => reason
	);
	expect( error, 'the accessibility check passed' ).not.toBeNull();
	return ( error as Error ).message;
}

test.describe( 'expectNoAccessibilityViolations()', () => {
	test( 'passes a page without violations', async ( { page } ) => {
		await page.setContent( fixturePage( CLEAN_REGION ) );

		await expectNoAccessibilityViolations( page );
	} );

	test( 'fails on a missing text alternative and on low contrast, naming rule, impact and target', async ( {
		page,
	} ) => {
		await page.setContent( fixturePage( BROKEN_REGION ) );

		const message = await failureOf(
			expectNoAccessibilityViolations( page )
		);

		expect( message ).toContain( '2 WCAG 2.2 AA violation(s)' );
		expect( message ).toMatch(
			/image-alt \[critical\][^\n]*\n[^\n]*\n\s+- #no-alt/
		);
		expect( message ).toMatch(
			/color-contrast \[serious\][^\n]*\n[^\n]*\n\s+- #low-contrast/
		);
	} );

	// Without this, dropping or mistyping a later tag would fail nothing: axe ignores a tag
	// it does not know, and the gate would still call itself WCAG 2.2 AA.
	test( 'applies the rules WCAG 2.1 and 2.2 added, not only those of 2.0', async ( {
		page,
	} ) => {
		await page.setContent( fixturePage( LATER_CRITERIA_REGION ) );

		const message = await failureOf(
			expectNoAccessibilityViolations( page )
		);

		expect( message ).toContain( '2 WCAG 2.2 AA violation(s)' );
		expect( message ).toMatch(
			/target-size \[serious\][^\n]*\n[^\n]*\n\s+- #tiny-one\n\s+- #tiny-two/
		);
		expect( message ).toMatch(
			/autocomplete-valid \[serious\][^\n]*\n[^\n]*\n\s+- #bad-autocomplete/
		);
	} );

	test( 'scans only the included regions and skips the excluded ones', async ( {
		page,
	} ) => {
		await page.setContent( fixturePage( CLEAN_REGION + BROKEN_REGION ) );

		await expectNoAccessibilityViolations( page, { include: '#clean' } );
		await expectNoAccessibilityViolations( page, { exclude: '#broken' } );

		const message = await failureOf(
			expectNoAccessibilityViolations( page, { include: '#broken' } )
		);
		expect( message ).toContain( 'image-alt' );
	} );

	test( 'skips a disabled rule and nothing else', async ( { page } ) => {
		await page.setContent( fixturePage( BROKEN_REGION ) );

		const message = await failureOf(
			expectNoAccessibilityViolations( page, {
				disableRules: [ 'image-alt' ],
			} )
		);

		expect( message ).toContain( '1 WCAG 2.2 AA violation(s)' );
		expect( message ).toContain( 'color-contrast' );
	} );

	test( 'fails when an included region does not exist', async ( {
		page,
	} ) => {
		await page.setContent( fixturePage( CLEAN_REGION ) );

		const message = await failureOf(
			expectNoAccessibilityViolations( page, {
				include: '#not-on-this-page',
			} )
		);

		expect( message ).toContain(
			'Accessibility scan scope "#not-on-this-page" matches no element'
		);
	} );
} );
