import { OrganizationFilters } from '@/features/platform/components/OrganizationFilters';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

it('«Suspendidas» pide estado=suspended y «Todas» lo quita', () => {
    const onChange = vi.fn();
    const { rerender } = render(<OrganizationFilters filters={{}} plans={{}} onChange={onChange} />);

    fireEvent.click(screen.getByRole('button', { name: 'Suspendidas' }));
    expect(onChange).toHaveBeenLastCalledWith({ estado: 'suspended' });

    rerender(<OrganizationFilters filters={{ estado: 'suspended' }} plans={{}} onChange={onChange} />);
    expect(screen.getByRole('button', { name: 'Suspendidas' })).toHaveAttribute('aria-pressed', 'true');

    fireEvent.click(screen.getByRole('button', { name: 'Todas' }));
    expect(onChange).toHaveBeenLastCalledWith(expect.objectContaining({ estado: null, vencidas: false }));
});

it('«Incluir cerradas» es un interruptor aparte de los filtros de riesgo', () => {
    const onChange = vi.fn();
    render(<OrganizationFilters filters={{}} plans={{ pro: 'Pro' }} onChange={onChange} />);

    expect(within(screen.getByRole('group', { name: 'Filtros rápidos' })).queryByText('Incluir cerradas')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('checkbox', { name: 'Incluir cerradas' }));

    expect(onChange).toHaveBeenCalledWith({ cerradas: true });
});

it('en la hoja del celular el plan y las cerradas se aplican juntos', () => {
    const onChange = vi.fn();
    render(<OrganizationFilters filters={{}} plans={{ pro: 'Pro' }} onChange={onChange} />);

    fireEvent.click(screen.getByRole('button', { name: 'Filtros' }));
    const sheet = within(screen.getByRole('dialog', { name: 'Filtros' }));
    fireEvent.change(sheet.getByLabelText('Plan'), { target: { value: 'pro' } });
    fireEvent.click(sheet.getByRole('checkbox', { name: 'Incluir cerradas' }));
    fireEvent.click(sheet.getByRole('button', { name: 'Aplicar' }));

    expect(onChange).toHaveBeenCalledExactlyOnceWith({ plan: 'pro', cerradas: true });
});

it('en el celular los filtros rápidos van arriba de la búsqueda, como en Cobranza', () => {
    render(<OrganizationFilters filters={{}} plans={{}} onChange={vi.fn()} />);

    expect(screen.getByRole('group', { name: 'Filtros rápidos' }).parentElement).toHaveClass('max-lg:order-first');
});
