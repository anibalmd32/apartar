import { useChatNotifications } from '@/features/chat/hooks/useChatNotifications';
import { renderHook } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { reload, listen, stopListening, info } = vi.hoisted(() => ({ reload: vi.fn(), listen: vi.fn(), stopListening: vi.fn(), info: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: { reload } }));
vi.mock('@/lib/toast', () => ({ toast: { info } }));
vi.mock('@/lib/echo', () => ({ echo: () => ({ private: () => ({ listen, stopListening }) }) }));

const PROPS = ['teamMember'];

beforeEach(() => {
    reload.mockReset();
    listen.mockReset();
    info.mockReset();
});

function handler(): (message: object) => void {
    return listen.mock.calls[0]?.[1] as (message: object) => void;
}

it('fuera del chat, un mensaje del otro lado actualiza el contador y avisa con quién escribió', () => {
    renderHook(() => useChatNotifications({ channels: ['organization.7.chat'], me: 'team', reloadProps: PROPS, chatPath: '/backoffice/chat' }));

    handler()({ conversationId: 3, sender: 'customer', senderName: 'María González', body: '¿Ya llegó mi pedido?' });

    expect(reload).toHaveBeenCalledWith({ only: ['teamMember'] });
    expect(info).toHaveBeenCalledWith('Nuevo mensaje de María González');
    expect(info.mock.calls[0]?.[0]).not.toContain('pedido');
});

it('lo que escribe uno mismo no avisa', () => {
    renderHook(() => useChatNotifications({ channels: ['organization.7.chat'], me: 'team', reloadProps: PROPS, chatPath: '/backoffice/chat' }));

    handler()({ conversationId: 3, sender: 'team', senderName: 'Ana', body: 'Hola' });

    expect(reload).not.toHaveBeenCalled();
    expect(info).not.toHaveBeenCalled();
});

it('dentro del chat actualiza el contador sin aviso emergente, y al salir deja de escuchar', () => {
    window.history.pushState({}, '', '/backoffice/chat/3');
    const { unmount } = renderHook(() =>
        useChatNotifications({ channels: ['organization.7.chat'], me: 'team', reloadProps: PROPS, chatPath: '/backoffice/chat' }),
    );

    handler()({ conversationId: 3, sender: 'customer', senderName: 'María', body: 'Hola' });

    expect(reload).toHaveBeenCalled();
    expect(info).not.toHaveBeenCalled();

    unmount();
    expect(stopListening).toHaveBeenCalledWith('MessageSent', handler());
    window.history.pushState({}, '', '/');
});
