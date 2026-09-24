/**
 * Reads budget.json's per-file compressed-byte ceilings, so a spec that needs one never
 * restates a number from that file.
 *
 * `fileBudgetBytes()` is the same "first matching rule wins" lookup bin/check-asset-budget.js
 * uses to check the build itself -- imported from there, not reimplemented: that script
 * exports its glob matcher and rule lookup for exactly this, and runs its CLI body only when
 * it is the entry point (`require.main === module`), so requiring it here does not run the
 * build check.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

interface BudgetRule {
	pattern: string;
	maxGzipBytes: number;
}

interface CompiledBudgetRule extends BudgetRule {
	expression: RegExp;
}

interface Budget {
	rules: BudgetRule[];
}

interface CheckAssetBudgetModule {
	parseBudgetRules: ( budget: Budget, label: string ) => CompiledBudgetRule[];
	findRule: (
		rules: CompiledBudgetRule[],
		file: string
	) => CompiledBudgetRule | undefined;
}

// A plain CommonJS require, not an import: bin/check-asset-budget.js ships no declaration
// file, and adding one -- or turning on allowJs for the whole Playwright TypeScript project --
// is outside what this module needs to do.
const checkAssetBudget: CheckAssetBudgetModule = require( '../../../bin/check-asset-budget.js' );

const BUDGET_FILE = resolve( __dirname, '..', '..', '..', 'budget.json' );

let cachedRules: CompiledBudgetRule[] | null = null;

function loadRules(): CompiledBudgetRule[] {
	if ( ! cachedRules ) {
		const budget: Budget = JSON.parse(
			readFileSync( BUDGET_FILE, 'utf8' )
		);
		cachedRules = checkAssetBudget.parseBudgetRules(
			budget,
			'budget.json'
		);
	}
	return cachedRules;
}

/**
 * The ceiling budget.json's first matching rule assigns to one build-relative file path, or
 * `undefined` when no rule matches.
 *
 * @param buildRelativePath A path relative to the plugin's build/ directory, forward slashes.
 */
export function fileBudgetBytes(
	buildRelativePath: string
): number | undefined {
	return checkAssetBudget.findRule( loadRules(), buildRelativePath )
		?.maxGzipBytes;
}
