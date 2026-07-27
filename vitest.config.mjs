/**
 * Vitest configuration for the admin script.
 *
 * Excluded from the SVN deploy, so this never ships to users.
 */
export default {
	test: {
		environment: 'jsdom',
		include: [ 'tests/js/**/*.test.mjs' ],
	},
};
