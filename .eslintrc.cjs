module.exports = {
	// Vue 3 con TypeScript: la variante `vue3` usa @typescript-eslint/parser
	// anche dentro il blocco <script setup lang="ts"> dei componenti.
	extends: [
		'@nextcloud/eslint-config/vue3',
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
	},
}
