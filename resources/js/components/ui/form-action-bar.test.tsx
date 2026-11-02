import { DOCKED_BAR_PADDING, FormActionBar } from '@/components/ui/form-action-bar';
import backofficeLayout from '@/layouts/backoffice-layout.tsx?raw';
import platformLayout from '@/layouts/platform-layout.tsx?raw';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

it('con cambios sin guardar, en el celular el botón de guardar queda fijo encima de la barra de pestañas', () => {
    render(
        <FormActionBar sticky>
            <button type="submit">Guardar ajustes</button>
        </FormActionBar>,
    );

    const bar = screen.getByRole('button', { name: 'Guardar ajustes' }).parentElement;
    expect(bar).toHaveClass('max-lg:sticky', 'max-lg:bottom-[calc(var(--overlay-bottom)+var(--consent-height))]', 'max-lg:[&>.btn]:w-full');
});

it('fija, es una barra lisa con solo el borde de arriba y no otra tarjeta', () => {
    render(
        <FormActionBar sticky>
            <button type="submit">Guardar ajustes</button>
        </FormActionBar>,
    );

    const bar = screen.getByRole('button', { name: 'Guardar ajustes' }).parentElement;
    expect(bar).toHaveClass('max-lg:border-t');
    expect(bar?.className).not.toMatch(/rounded|shadow|max-lg:border(\s|$)/);
});

it('sin cambios, el botón se queda al final del formulario', () => {
    render(
        <FormActionBar sticky={false}>
            <button type="submit">Guardar ajustes</button>
        </FormActionBar>,
    );

    expect(screen.getByRole('button', { name: 'Guardar ajustes' }).parentElement).not.toHaveClass('max-lg:sticky');
});

it.each([
    ['backoffice', backofficeLayout],
    ['platform', platformLayout],
])('al final de la página la barra fija se apoya en la barra de pestañas sin dejar una franja (%s)', (_name, source) => {
    expect(DOCKED_BAR_PADDING).toBe('max-lg:has-[[data-form-action-bar][data-sticky]]:pb-[calc(var(--overlay-bottom)+var(--consent-height))]');

    expect(source).toContain('`flex-1 px-4 pt-2 pb-32 lg:px-8 lg:pt-7 lg:pb-12 ${DOCKED_BAR_PADDING}`');
});
