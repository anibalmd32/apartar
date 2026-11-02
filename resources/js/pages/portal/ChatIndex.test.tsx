import ChatIndex from '@/pages/portal/ChatIndex';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => ({
    usePage: () => ({ props: { auth: { portal: { fullName: 'José Gregorio Martínez' } } } }),
    Link: (await import('@/test/fakes')).FakeLink,
}));

vi.mock('@/layouts/portal-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

function today(hour: number, minute: number): string {
    const now = new Date();

    return new Date(now.getFullYear(), now.getMonth(), now.getDate(), hour, minute).toISOString();
}

it('cada conversación enseña el último mensaje y cuándo llegó', () => {
    render(
        <ChatIndex
            threads={[
                {
                    businessName: 'Moda Valentina',
                    excerpt: 'Tú: Ya pagué la cuota',
                    url: '/mi-cuenta/chat/moda',
                    unread: 2,
                    lastMessageAt: today(21, 45),
                },
            ]}
        />,
    );

    const row = screen.getByRole('link');

    expect(row).toHaveTextContent('Tú: Ya pagué la cuota');
    expect(row).toHaveTextContent(/Hoy 9:45\sp\.\sm\./);
    expect(screen.getByRole('heading', { level: 1, name: 'Tus conversaciones' })).toHaveClass('max-lg:sr-only');
});

it('sin conversaciones explica cómo empiezan', () => {
    render(<ChatIndex threads={[]} />);

    expect(screen.getByText('Aún no tienes conversaciones')).toBeInTheDocument();
    expect(screen.getByText(/Cuando apartes en un negocio podrás escribirle desde aquí/)).toBeInTheDocument();
});
