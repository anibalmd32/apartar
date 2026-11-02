import { DisabledReason } from '@/components/ui/disabled-reason';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

function tapOn(element: HTMLElement) {
    fireEvent.pointerEnter(element, { pointerType: 'touch' });
    fireEvent.pointerDown(element, { pointerType: 'touch' });
    fireEvent.focus(element);
    fireEvent.click(element);
}

it('al tocar el aviso con el dedo se muestra la explicación', () => {
    render(
        <DisabledReason reason="Necesitas una foto o un nombre">
            <button type="button" disabled>
                Sugerir con IA
            </button>
        </DisabledReason>,
    );

    tapOn(screen.getByRole('button', { name: 'Por qué no está disponible' }));

    expect(screen.getByRole('tooltip')).toHaveTextContent('Necesitas una foto o un nombre');
});

it('un segundo toque cierra la explicación', () => {
    render(
        <DisabledReason reason="Necesitas una foto o un nombre">
            <button type="button" disabled>
                Sugerir con IA
            </button>
        </DisabledReason>,
    );

    const badge = screen.getByRole('button', { name: 'Por qué no está disponible' });

    tapOn(badge);
    fireEvent.pointerDown(badge, { pointerType: 'touch' });
    fireEvent.click(badge);

    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
});

it('con el teclado se muestra al enfocar el aviso', () => {
    render(
        <DisabledReason reason="Necesitas una foto o un nombre">
            <button type="button" disabled>
                Sugerir con IA
            </button>
        </DisabledReason>,
    );

    fireEvent.focus(screen.getByRole('button', { name: 'Por qué no está disponible' }));

    expect(screen.getByRole('tooltip')).toBeInTheDocument();
});
