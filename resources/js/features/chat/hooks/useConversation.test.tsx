import { useConversation } from '@/features/chat/hooks/useConversation';
import { type ChatMessage, type ChatThread } from '@/types/chat';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

const { getJson, postJson } = vi.hoisted(() => ({ getJson: vi.fn(), postJson: vi.fn() }));

vi.mock('@/lib/echo', () => ({ echo: () => null }));
vi.mock('@/lib/json', () => ({ getJson, postJson, JsonRequestError: class extends Error {} }));

const FIRST: ChatMessage = { id: 1, sender: 'customer', senderName: 'Chelsi', body: 'Hola', sentAt: '2026-10-31T10:00:00-04:00' } as ChatMessage;

const THREAD = {
    channel: 'organization.1.conversation.3',
    messages: [FIRST],
    sendUrl: '/backoffice/chat/3',
    messagesUrl: '/backoffice/chat/3/mensajes',
    typingUrl: '/backoffice/chat/3/escribiendo',
} as unknown as ChatThread;

function wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={new QueryClient()}>{children}</QueryClientProvider>;
}

it('el mensaje enviado no desaparece ni se duplica mientras se confirma', async () => {
    const reply: ChatMessage = { ...FIRST, id: 2, sender: 'team', senderName: 'Anibal', body: 'que tal?' };
    getJson.mockResolvedValue([]);
    postJson.mockResolvedValue({ id: 2 });

    const counts: number[] = [];
    const { result } = renderHook(
        () => {
            const conversation = useConversation(THREAD, 'team');
            counts.push(conversation.messages.length + conversation.outgoing.length);

            return conversation;
        },
        { wrapper },
    );

    await waitFor(() => expect(getJson).toHaveBeenCalled());
    getJson.mockImplementation(() => new Promise((resolve) => setTimeout(() => resolve([reply]), 30)));
    counts.length = 0;

    act(() => result.current.send('que tal?'));

    await waitFor(() => expect(result.current.messages.map((message) => message.id)).toEqual([1, 2]));

    expect(result.current.outgoing).toEqual([]);
    expect(counts.every((count) => count === 2)).toBe(true);
});
