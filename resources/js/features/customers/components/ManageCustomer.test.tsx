import { ManageCustomer } from '@/features/customers/components/ManageCustomer';
import { profileProps } from '@/test/t065-fixtures';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

const { put, visit } = vi.hoisted(() => ({ put: vi.fn(), visit: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        router: { visit },
        usePage: () => ({ props: { errors: {}, catalogs: { mobilePrefixes: [], banks: [], documentKinds: [] } } }),
        Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState(initial);

            return {
                data,
                setData: (key: keyof T, value: unknown) => setState((previous) => ({ ...previous, [key]: value })),
                errors: {},
                processing: false,
                put: (url: string) => put(url, data),
            };
        },
    };
});

it('edita nombre, teléfono y correo, sin tocar la cédula', () => {
    const { customer, manage } = profileProps();
    render(<ManageCustomer customer={customer} manage={manage} />);

    fireEvent.click(screen.getByRole('button', { name: 'Editar' }));

    const dialog = screen.getByRole('dialog', { name: 'Editar cliente' });
    expect(dialog).toHaveTextContent(customer.document);
    fireEvent.change(within(dialog).getByLabelText(/Nombre y apellido/), { target: { value: 'María G. González' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }));

    expect(put).toHaveBeenCalledWith('/backoffice/clientes/7', expect.objectContaining({ full_name: 'María G. González', phone: customer.phone }));
});

it('no deja eliminar a un cliente con apartados en curso', () => {
    const { customer, manage } = profileProps();
    render(<ManageCustomer customer={customer} manage={{ ...manage, liveLayaways: 1 }} />);

    expect(screen.getByRole('button', { name: 'Eliminar' })).toBeDisabled();
});

it('eliminar pide confirmación y explica qué pasa con su historial', () => {
    const { customer, manage } = profileProps();
    render(<ManageCustomer customer={customer} manage={manage} />);

    fireEvent.click(screen.getByRole('button', { name: 'Eliminar' }));

    const dialog = screen.getByRole('dialog', { name: `¿Eliminar a ${customer.name}?` });
    expect(dialog).toHaveTextContent('su historial se conserva');
    expect(within(dialog).getByRole('button', { name: 'Eliminar cliente' })).toBeInTheDocument();
});
