import { Chip } from '@/components/ui/chip';
import { DisabledReason } from '@/components/ui/disabled-reason';
import { SectionCard } from '@/components/ui/section-card';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

it('el aviso de por qué no está disponible se puede tocar con el dedo aunque se vea pequeño', () => {
    render(
        <DisabledReason reason="Necesitas una cuenta de cobro">
            <button type="button" disabled>
                Publicar
            </button>
        </DisabledReason>,
    );

    expect(screen.getByRole('button', { name: 'Por qué no está disponible' })).toHaveClass('hit-area', 'absolute');
});

it('la acción de la cabecera de una tarjeta extiende su área táctil', () => {
    render(
        <SectionCard title="Comprobantes" action={<a href="/comprobantes">Ver todos</a>}>
            <p>Contenido</p>
        </SectionCard>,
    );

    expect(screen.getByRole('link', { name: 'Ver todos' }).parentElement).toHaveClass('hit-area-children');
});

it('los filtros rápidos miden 44 px de alto en pantallas de celular', () => {
    render(<Chip active={false}>Vencen hoy</Chip>);

    expect(screen.getByRole('button', { name: 'Vencen hoy' })).toHaveClass('touch:h-11');
});
