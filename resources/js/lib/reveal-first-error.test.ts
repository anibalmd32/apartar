import { afterEach, describe, expect, it } from 'vitest';

import { firstInvalidField, revealFirstError } from './reveal-first-error';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('el primer campo con error', () => {
    it('es el primero en el orden de la página', () => {
        document.body.innerHTML = `
            <input id="name" />
            <input id="price" aria-invalid="true" />
            <input id="stock" aria-invalid="true" />
        `;

        expect(firstInvalidField()?.id).toBe('price');
    });

    it('si solo hay un mensaje en rojo, es el campo que ese mensaje describe', () => {
        document.body.innerHTML = `
            <input id="other" />
            <input id="phone" aria-describedby="phone-hint phone-error" />
            <p id="phone-error" class="field-error">Escribe un teléfono.</p>
        `;

        expect(firstInvalidField()?.id).toBe('phone');
    });

    it('si el mensaje no dice qué campo describe, es el control de su mismo grupo', () => {
        document.body.innerHTML = `
            <fieldset><select id="bank"></select><p class="field-error">Elige un banco.</p></fieldset>
        `;

        expect(firstInvalidField()?.id).toBe('bank');
    });

    it('salta los campos ocultos', () => {
        document.body.innerHTML = `
            <div hidden><input id="hidden" aria-invalid="true" /></div>
            <input id="visible" aria-invalid="true" />
        `;

        expect(firstInvalidField()?.id).toBe('visible');
    });

    it('recibe el foco y avisa si no había ninguno', () => {
        document.body.innerHTML = '<input id="name" aria-invalid="true" />';

        expect(revealFirstError()).toBe(true);
        expect(document.activeElement?.id).toBe('name');

        document.body.innerHTML = '<input id="name" />';

        expect(revealFirstError()).toBe(false);
    });
});
