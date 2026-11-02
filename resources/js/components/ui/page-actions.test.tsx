import { PageActions } from '@/components/ui/page-actions';
import { cilBan, cilPlus } from '@coreui/icons';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

it('en el celular la acción principal ocupa el ancho y las demás pasan al menú, con las destructivas aparte y en rojo', () => {
    const remove = vi.fn();

    render(
        <PageActions
            actions={[
                { label: 'Notificar', href: '/avisos', variant: 'soft' },
                { label: 'Eliminar', icon: cilBan, onClick: remove, variant: 'ghost', danger: true },
                { label: 'Nuevo apartado', icon: cilPlus, href: '/apartados/nuevo', variant: 'primary', primary: true },
            ]}
        />,
    );

    expect(screen.getByRole('link', { name: 'Nuevo apartado' })).toHaveClass('btn', 'btn-primary', 'max-lg:w-full');
    expect(screen.getByRole('link', { name: 'Notificar' })).toHaveClass('btn-secondary', 'max-lg:hidden');

    fireEvent.click(screen.getByRole('button', { name: 'Más acciones' }));

    const items = screen.getAllByRole('menuitem');
    expect(items.map((item) => item.textContent)).toEqual(['Notificar', 'Eliminar']);
    expect(items[1]).toHaveClass('text-error');
    expect(screen.getByRole('separator')).toBeInTheDocument();

    fireEvent.click(items[1]!);
    expect(remove).toHaveBeenCalledOnce();
});

it('sin acción principal en el celular, la fila se oculta y solo queda el menú', () => {
    render(
        <PageActions
            actions={[
                { label: 'Vista previa', onClick: vi.fn(), variant: 'ghost' },
                { label: 'Guardar cambios', form: 'product-form', variant: 'primary', mobile: 'hidden' },
            ]}
        />,
    );

    const save = screen.getByRole('button', { name: 'Guardar cambios' });
    expect(save).toHaveAttribute('type', 'submit');
    expect(save).toHaveAttribute('form', 'product-form');
    expect(save.parentElement).toHaveClass('max-lg:hidden');

    fireEvent.click(screen.getByRole('button', { name: 'Más acciones' }));
    expect(screen.getAllByRole('menuitem').map((item) => item.textContent)).toEqual(['Vista previa']);
});

it('una acción bloqueada explica por qué en el menú del celular', () => {
    render(
        <PageActions
            actions={[
                { label: 'Editar', onClick: vi.fn() },
                { label: 'Eliminar', onClick: vi.fn(), danger: true, disabled: true, disabledReason: 'Tiene apartados en curso.' },
            ]}
        />,
    );

    expect(screen.getByRole('button', { name: 'Eliminar' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Eliminar' })).toHaveAttribute('title', 'Tiene apartados en curso.');

    fireEvent.click(screen.getByRole('button', { name: 'Más acciones' }));

    expect(screen.getByRole('menuitem', { name: 'Eliminar' })).toHaveAttribute('aria-disabled', 'true');
    expect(screen.getByText('Tiene apartados en curso.')).toBeInTheDocument();
});
