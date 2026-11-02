import ChatIndex from '@/pages/backoffice/chat/Index';
import { type BackofficeChatProps } from '@/types/chat';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { teamMember: { unreadChats: 0 } } }),
    router: { get: vi.fn() },
    Link: ({ children, ...props }: { children: ReactNode; href: string }) => <a {...props}>{children}</a>,
}));

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children, immersive }: { children: ReactNode; immersive?: boolean }) => (
        <main data-immersive={immersive ? 'si' : 'no'}>{children}</main>
    ),
}));

vi.mock('@/features/chat/hooks/useChatInbox', () => ({ useChatInbox: vi.fn() }));
vi.mock('@/features/chat/components/ChatThreadView', () => ({ ChatThreadView: () => <textarea aria-label="Mensaje" /> }));

const base: BackofficeChatProps = { threads: [], nextPageUrl: null, search: '', active: null, historyDays: null };

const active = {
    id: 3,
    channel: null,
    messages: [],
    sendUrl: '',
    messagesUrl: '',
    typingUrl: '',
    hiddenHistory: false,
    context: {
        customerName: 'José Gregorio',
        phone: null,
        activeLayaways: 1,
        remaining: '$40,00',
        overdueInstallments: 0,
        profileUrl: '/clientes/7',
    },
};

it('sin conversaciones explica cómo empiezan y el buscador mide 44px', () => {
    render(<ChatIndex {...base} />);

    expect(screen.getByRole('main')).toHaveAttribute('data-immersive', 'no');
    expect(screen.getByText('Todavía no hay conversaciones')).toBeInTheDocument();
    expect(screen.getByText(/empieza cuando un cliente te escribe desde tu catálogo o desde su portal/)).toBeInTheDocument();
    expect(screen.getByRole('searchbox', { name: 'Buscar conversaciones' }).closest('label')).toHaveClass('h-11');
});

it('en el celular una conversación abierta ocupa toda la pantalla con una cabecera para volver', () => {
    render(<ChatIndex {...base} active={active} />);

    expect(screen.getByRole('main')).toHaveAttribute('data-immersive', 'si');
    expect(screen.getByRole('region', { name: 'Conversación abierta' })).toHaveClass('max-lg:h-dvh');
    expect(screen.getByRole('link', { name: 'Volver a las conversaciones' })).toHaveClass('size-11');
    expect(screen.getByRole('heading', { name: 'José Gregorio' })).toBeInTheDocument();
});
