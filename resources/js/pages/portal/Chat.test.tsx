import Chat from '@/pages/portal/Chat';
import { type PortalChatProps } from '@/types/chat';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { portal: { fullName: 'José Gregorio Martínez' } } } }),
    Link: ({ children, ...props }: { children: ReactNode; href: string }) => <a {...props}>{children}</a>,
}));

vi.mock('@/layouts/portal-layout', () => ({
    default: ({ children, immersive }: { children: ReactNode; immersive?: boolean }) => (
        <main data-immersive={immersive ? 'si' : 'no'}>{children}</main>
    ),
}));

vi.mock('@/features/chat/components/ChatThreadView', () => ({ ChatThreadView: () => <textarea aria-label="Mensaje" /> }));

beforeEach(() => {
    vi.stubGlobal('route', (name: string) => `/${name}`);
});

it('en el celular la conversación ocupa toda la pantalla con una cabecera compacta para volver', () => {
    render(<Chat {...({ businessName: 'Moda Valentina', thread: {} } as unknown as PortalChatProps)} />);

    expect(screen.getByRole('main')).toHaveAttribute('data-immersive', 'si');

    const heading = screen.getByRole('heading', { level: 1, name: 'Moda Valentina' });
    const section = heading.closest('section')!;

    expect(section).toHaveClass('h-dvh', 'lg:h-[calc(100dvh-150px)]');
    expect(screen.getByRole('link', { name: 'Volver a tus conversaciones' })).toHaveAttribute('href', '/portal.chat.index');
    expect(screen.queryByText('Tus conversaciones')).toBeNull();
    expect(screen.queryByText(/Escríbele a/)).toBeNull();
});
