import { describe, expect, it } from 'vitest';

describe('arnés de pruebas del frontend', () => {
    it('corre sobre jsdom', () => {
        expect(typeof document).toBe('object');
        expect(document.createElement('div')).toBeInstanceOf(HTMLElement);
    });

    it('carga los matchers de jest-dom', () => {
        const element = document.createElement('p');
        element.textContent = 'apartar';
        document.body.appendChild(element);

        expect(element).toBeInTheDocument();
        expect(element).toHaveTextContent('apartar');
    });
});
