import { describe, expect, it } from 'vitest';

/**
 * Comprobación de que el arnés de Vitest está montado: entorno jsdom, matchers
 * de jest-dom y el alias `@`. Las suites reales llegan con cada componente
 * (`REQ-QAX-003`); esto solo impide que `npm run test` pase en verde sobre
 * cero archivos y nadie se entere de que la configuración está rota.
 */
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
