import CustomerShow from '@/pages/backoffice/customers/Show';
import { profileProps } from '@/test/t065-fixtures';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async (original) => ({
    ...(await original<object>()),
    usePage: () => ({ url: '/backoffice/clientes/7', props: { errors: {}, currency: { display: 'usd_primary', rate: { rate: null } } } }),
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ actions, children }: { actions: ReactNode; children: ReactNode }) => (
        <main>
            <header>{actions}</header>
            {children}
        </main>
    ),
}));

it.each([null, 12])(
    'en el celular la acción a la vista de un cliente es siempre «Nuevo apartado», tenga o no conversación (%s)',
    (conversationId) => {
        render(<CustomerShow {...profileProps({ conversationId })} />);

        expect(screen.getByRole('link', { name: 'Nuevo apartado' })).toHaveAttribute('data-page-primary');

        const promoted = document.querySelectorAll('[data-page-primary]');
        expect(promoted).toHaveLength(1);
    },
);

it('«Eliminar» bloqueado se ve apagado y explica por qué', () => {
    const props = profileProps();
    render(<CustomerShow {...props} manage={{ ...props.manage, liveLayaways: 1 }} />);

    const remove = screen.getAllByRole('button', { name: 'Eliminar' })[0]!;
    expect(remove).toBeDisabled();
    expect(remove).not.toHaveClass('text-error');

    fireEvent.click(screen.getByRole('button', { name: 'Por qué no está disponible' }));
    expect(screen.getByRole('tooltip')).toHaveTextContent('Tiene apartados en curso: cancélalos o espera a que terminen.');
});

it('las cuotas vencidas ponen el monto debajo de la cantidad y las pestañas se reparten el ancho', () => {
    render(<CustomerShow {...profileProps()} />);

    const overdue = screen.getByText('Cuotas vencidas').nextElementSibling!;
    expect(overdue.firstChild).toHaveTextContent('1');
    expect(overdue.querySelector('.block')).toHaveTextContent('$30,00');

    const tabs = screen.getByRole('group', { name: 'Secciones del cliente' });
    within(tabs)
        .getAllByRole('button')
        .forEach((tab) => expect(tab).toHaveClass('max-sm:flex-1'));
});
