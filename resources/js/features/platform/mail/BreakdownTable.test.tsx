import { BreakdownTable } from '@/features/platform/mail/BreakdownTable';
import { render, screen, within } from '@testing-library/react';
import { expect, it } from 'vitest';

it('en el celular cada fila del desglose es un bloque con sus cuatro cifras etiquetadas', () => {
    render(
        <BreakdownTable
            title="Por plantilla"
            heading="Plantilla"
            empty="Sin envíos."
            rows={[{ key: 'reminder', label: 'Recordatorio de cuota', sent: 1200, delivered: 1000, opened: 400, clicked: 10, bounced: 3 }]}
        />,
    );

    const list = screen.getByRole('list', { name: 'Por plantilla' });
    const item = within(list).getByRole('listitem');

    expect(list).toHaveClass('md:hidden');
    expect(screen.getByRole('table').parentElement).toHaveClass('hidden', 'md:block');
    expect(item).toHaveTextContent('Recordatorio de cuota');
    expect(
        within(item)
            .getAllByRole('term')
            .map((term) => term.textContent),
    ).toEqual(['Enviados', 'Entrega', 'Apertura', 'Rebotes']);
    expect(
        within(item)
            .getAllByRole('definition')
            .map((value) => value.textContent),
    ).toEqual(['1.200', '83,3%', '40%', '3']);
});
