import { ConversationList } from '@/features/chat/components/ConversationList';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

const thread = {
    id: 1,
    customerName: 'José Gregorio',
    customerDocument: 'V21234567',
    excerpt: 'Hola José, sí claro',
    lastMessageAt: null,
    unread: 0,
    url: '/backoffice/chat/1',
};

it('muestra la cédula con formato y sin partirla entre líneas', () => {
    render(<ConversationList threads={[thread]} activeId={null} />);

    const document = screen.getByText('V-21.234.567');

    expect(document).toHaveClass('whitespace-nowrap');
    expect(screen.getByRole('link')).toHaveTextContent('V-21.234.567 · Hola José, sí claro');
    expect(screen.queryByText(/V21234567/)).toBeNull();
});

it('sin cédula enseña solo el último mensaje', () => {
    render(<ConversationList threads={[{ ...thread, customerDocument: '' }]} activeId={null} />);

    expect(screen.getByText('Hola José, sí claro')).toHaveTextContent(/^Hola José, sí claro$/);
    expect(screen.getByRole('link')).not.toHaveTextContent('·');
});
