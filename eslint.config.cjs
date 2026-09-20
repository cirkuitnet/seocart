/**
 * ESLint flat config: the @wordpress/scripts defaults plus this repository's ignores.
 *
 * The default config ignores only build/, node_modules/ and vendor/. Everything else
 * that is generated or third-party is listed here, so `npm run lint:js` lints first-party
 * source only.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	{
		ignores: [
			'**/vendor-scoped/**',
			'**/dist/**',
			'**/artifacts/**',
			'**/test-results/**',
			'**/playwright-report/**',
		],
	},
	...defaultConfig,
	{
		// Command-line tools run under Node, not in a browser, and report to the console.
		files: [ 'bin/**/*.js', '*.config.{js,cjs,mjs}' ],
		languageOptions: {
			globals: {
				__dirname: 'readonly',
				module: 'writable',
				process: 'readonly',
				require: 'readonly',
			},
		},
		rules: {
			'no-console': 'off',
		},
	},
];
