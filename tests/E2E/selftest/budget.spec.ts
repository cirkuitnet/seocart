/**
 * Self-test of support/budget.ts's own logic: fileBudgetBytes()'s behaviour for a path no rule
 * covers, and for a pattern collision between two similarly-named directories. Needs no
 * WordPress site.
 *
 * The rule lookup itself (matching a pattern against a path, first-match-wins) is
 * bin/check-asset-budget.js's own code, imported here rather than reimplemented, so it is
 * proved once, by that file's own tests (tests/JS/check-asset-budget.test.js) -- there is
 * nothing left here to keep in step with a second copy.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { test, expect } from '@playwright/test';
import { fileBudgetBytes } from '../support/budget';

interface BudgetRule {
	pattern: string;
	maxGzipBytes: number;
}

const budget: { rules: BudgetRule[] } = JSON.parse(
	readFileSync(
		resolve( __dirname, '..', '..', '..', 'budget.json' ),
		'utf8'
	)
);

test.describe( 'fileBudgetBytes()', () => {
	test( 'a path with no matching rule is undefined, not a silent 0', () => {
		expect(
			fileBudgetBytes( 'not-a-real-directory/whatever.js' )
		).toBeUndefined();
	} );

	test( 'a ** pattern does not match a sibling directory that only shares the prefix', () => {
		// blocks/cart/**/*.js must not also match blocks/cart-legacy/index.js.
		expect( fileBudgetBytes( 'blocks/cart-legacy/index.js' ) ).not.toBe(
			budget.rules.find(
				( rule ) => rule.pattern === 'blocks/cart/**/*.js'
			)?.maxGzipBytes
		);
	} );
} );
