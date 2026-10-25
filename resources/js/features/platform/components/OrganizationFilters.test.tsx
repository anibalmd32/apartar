import { OrganizationFilters } from '@/features/platform/components/OrganizationFilters';
import { fireEvent, render, screen } from '@testing-library/react';
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
