import { ChatThreadView } from '@/features/chat/components/ChatThreadView';
import { dayLabel } from '@/features/chat/lib/day-label';
import { type ChatMessage, type ChatThread } from '@/types/chat';
import { render } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const messages: ChatMessage[] = [];

vi.mock('@/features/chat/hooks/useConversation', () => ({
    useConversation: () => ({ mode: 'live', messages, outgoing: [], mine: () => false, send: vi.fn(), retry: vi.fn() }),
}));

vi.mock('@/features/chat/hooks/useTypingIndicator', () => ({
    useTypingIndicator: () => ({ typing: null, notify: vi.fn() }),
}));

function at(daysAgo: number, hour: number): string {
    const now = new Date();

    return new Date(now.getFullYear(), now.getMonth(), now.getDate() - daysAgo, hour).toISOString();
}

it('dice «Hoy», «Ayer» o el día de la semana con la fecha', () => {
    const now = new Date(2026, 9, 24, 12);

    expect(dayLabel(new Date(2026, 9, 24, 8).toISOString(), now)).toBe('Hoy');
    expect(dayLabel(new Date(2026, 9, 23, 22).toISOString(), now)).toBe('Ayer');
    expect(dayLabel(new Date(2026, 9, 20, 9).toISOString(), now)).toBe('Martes, 20 de octubre');
    expect(dayLabel(new Date(2025, 11, 31, 9).toISOString(), now)).toBe('31 de diciembre de 2025');
});

it('pone un separador solo donde cambia el día', () => {
    const message = (id: number, sentAt: string): ChatMessage => ({
        id,
        conversationId: 1,
        sender: 'customer',
        senderName: 'Ana',
        body: `m${id}`,
        sentAt,
    });
    messages.push(message(1, at(1, 9)), message(2, at(1, 10)), message(3, at(0, 9)));

    const thread = { channel: null, messages, sendUrl: '', messagesUrl: '', typingUrl: '' } as ChatThread;
    const { container } = render(<ChatThreadView thread={thread} me="team" />);

    expect([...container.querySelectorAll('[data-day-separator]')].map((item) => item.textContent)).toEqual(['Ayer', 'Hoy']);
});
