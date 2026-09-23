import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import typescript from 'typescript-eslint';

/**
 * Reglas de `REQ-QAX-002`. Las dos que no son negociables son `max-lines` como
 * error (RB-1) y la prohibición de `any` (RB-3): las demás son higiene.
 *
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
        // Los archivos de configuración de la raíz corren en Node, no en el
        // navegador: `process` y `__dirname` existen ahí.
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
            // RB-1: ningún componente pasa de 200 líneas, objetivo 150. Como
            // error, no como advertencia: una advertencia en CI no detiene nada.
            'max-lines': [
                'error',
                { max: 200, skipBlankLines: true, skipComments: true },
            ],

            // RB-3: `any` está prohibido; la alternativa es `unknown` con
            // estrechamiento.
            '@typescript-eslint/no-explicit-any': 'error',

            '@typescript-eslint/no-unused-vars': [
                'error',
                { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
            ],
            '@typescript-eslint/consistent-type-imports': [
                'error',
                { prefer: 'type-imports', fixStyle: 'inline-type-imports' },
            ],
            eqeqeq: ['error', 'always', { null: 'ignore' }],
            'no-console': ['error', { allow: ['warn', 'error'] }],
        },
    },
    {
        // Primitivos de shadcn/Radix heredados del starter kit. Se siguen
        // analizando —incluida la prohibición de `any`—, pero `max-lines` no
        // aplica: `sidebar.tsx` trae 609 líneas de upstream y trocearlo sería
        // reescribir código que T-010/T-012 van a borrar. La regla existe para
        // los componentes que escribimos nosotros, que van en components/ui.
        files: ['resources/js/components/vendor/**/*.{ts,tsx}'],
        rules: {
            'max-lines': 'off',
        },
    },
    {
        // Los scripts de las recetas (`T-003b`) corren en Node, no en el
        // navegador: `process`, `console` y los módulos de `node:` son sus
        // globales legítimas. Sin esto, `no-undef` los señala uno por uno
        // como si fueran errores, que es la forma de que alguien acabe
        // apagando la regla entera.
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
