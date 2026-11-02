import { ChatThreadView } from '@/features/chat/components/ChatThreadView';
import { type ChatMessage, type ChatThread } from '@/types/chat';
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({ mode: 'polling', messages: [] as ChatMessage[] }));

vi.mock('@/features/chat/hooks/useConversation', () => ({
    useConversation: () => ({ ...state, outgoing: [], mine: (message: ChatMessage) => message.sender === 'team', send: vi.fn(), retry: vi.fn() }),
}));

vi.mock('@/features/chat/hooks/useTypingIndicator', () => ({
    useTypingIndicator: () => ({ typing: null, notify: vi.fn() }),
}));

function message(id: number, sender: ChatMessage['sender'], minute: number): ChatMessage {
    const now = new Date();

    return {
        id,
        conversationId: 1,
        sender,
        senderName: sender === 'team' ? 'Valentina' : 'José',
        body: `Mensaje ${id}`,
        sentAt: new Date(now.getFullYear(), now.getMonth(), now.getDate(), 9, minute).toISOString(),
    };
}

const thread = { channel: null, messages: [], sendUrl: '', messagesUrl: '', typingUrl: '' } as unknown as ChatThread;

it('agrupa los mensajes seguidos de la misma persona y muestra su nombre y hora una sola vez', () => {
    state.messages = [message(1, 'customer', 0), message(2, 'customer', 1), message(3, 'customer', 2), message(4, 'team', 5)];

    render(<ChatThreadView thread={thread} me="team" />);

    expect(screen.getAllByText(/^José · /)).toHaveLength(1);
    expect(screen.getAllByText(/^Valentina · /)).toHaveLength(1);
    expect(screen.getByText('Mensaje 3').closest('li')).toHaveTextContent(/José · 9:02/);
});

it('no muestra avisos técnicos cuando no hay conexión en vivo', () => {
    state.messages = [message(1, 'customer', 0)];

    render(<ChatThreadView thread={thread} me="team" />);

    expect(screen.queryByText(/sin conexión en vivo/)).toBeNull();
});
