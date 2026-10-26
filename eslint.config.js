import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import typescript from 'typescript-eslint';

/**
 * @type {import('eslint').Linter.Config[]}
 */
export default [
    js.configs.recommended,
    ...typescript.configs.recommended,
    {
        ...react.configs.flat.recommended,
        ...react.configs.flat['jsx-runtime'], // Required for React 17+
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react/no-unescaped-entities': 'off',
            'no-empty': ['error', { allowEmptyCatch: true }],
        },
        settings: {
            react: {
                version: 'detect',
            },
        },
    },
    {
        plugins: {
            'react-hooks': reactHooks,
        },
        rules: {
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },
    {
        files: ['*.config.{js,ts}'],
        languageOptions: {
            globals: {
                ...globals.node,
            },
        },
    },
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        rules: {
            'max-lines': ['error', { max: 200, skipBlankLines: true, skipComments: true }],

            '@typescript-eslint/no-explicit-any': 'error',

            '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
            '@typescript-eslint/consistent-type-imports': ['error', { prefer: 'type-imports', fixStyle: 'inline-type-imports' }],
            eqeqeq: ['error', 'always', { null: 'ignore' }],
            'no-console': ['error', { allow: ['warn', 'error'] }],
        },
    },
    {
        files: ['scripts/**/*.mjs'],
        languageOptions: {
            globals: {
                ...globals.node,
            },
        },
    },
    {
        ignores: ['vendor', 'node_modules', 'public', 'bootstrap/ssr', 'storage', 'docker'],
    },
    prettier, // Turn off all rules that might conflict with Prettier
];
