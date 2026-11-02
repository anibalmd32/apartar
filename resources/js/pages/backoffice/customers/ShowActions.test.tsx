import CustomerShow from '@/pages/backoffice/customers/Show';
import { profileProps } from '@/test/t065-fixtures';
import { render, screen } from '@testing-library/react';
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
