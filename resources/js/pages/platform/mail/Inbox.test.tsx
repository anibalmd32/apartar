import MailInboxPage from '@/pages/platform/mail/Inbox';
import { type MailThreadRow } from '@/types/mail';
import { render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@/layouts/platform-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('@/features/platform/mail/MailTabs', () => ({ MailTabs: () => null }));
vi.mock('@inertiajs/react', () => ({
    router: { get: vi.fn() },
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

const thread = (id: number, unread: number): MailThreadRow => ({
    id,
    subject: `Asunto ${id}`,
    counterpart: `cliente${id}@gmail.com`,
    snippet: 'Hola, quería saber…',
    messages: 2,
    unread,
    lastMessageAt: '2026-10-27T10:00:00-04:00',
});

it('resalta las conversaciones sin leer y enlaza cada una', () => {
    render(<MailInboxPage threads={[thread(1, 1), thread(2, 0)]} hasMore={false} page={1} filters={{ filtro: null, q: null }} unreadThreads={1} />);

    const list = screen.getByRole('region', { name: 'Conversaciones' });
    const unread = within(list).getByText('cliente1@gmail.com');
    const read = within(list).getByText('cliente2@gmail.com');

    expect(unread.className).toContain('font-bold');
    expect(read.className).not.toContain('font-bold');
    expect(within(list).getAllByText('Sin leer')).toHaveLength(1);
    expect(unread.closest('a')?.getAttribute('href')).toBe('/plataforma/correos/bandeja/1');
});

it('explica la bandeja vacía', () => {
    render(<MailInboxPage threads={[]} hasMore={false} page={1} filters={{ filtro: null, q: null }} unreadThreads={0} />);

    expect(screen.getByText('La bandeja está vacía')).toBeInTheDocument();
});
