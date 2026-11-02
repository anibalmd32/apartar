import { ChatDock } from '@/features/chat/components/ChatWidget';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';

const CHAT = { canWrite: false, sendUrl: '/c/ofertas/chat', threadUrl: '/mi-cuenta/chat/ferreteria' };

function scrollTo(y: number) {
    act(() => {
        Object.defineProperty(window, 'scrollY', { value: y, configurable: true });
        window.dispatchEvent(new Event('scroll'));
    });
}

afterEach(() => {
    scrollTo(0);
});

it('arriba enseña el texto y al bajar se recoge en un botón redondo', () => {
    render(<ChatDock chat={CHAT} />);

    const button = screen.getByRole('button', { name: 'Escribir al negocio' });
    expect(button).not.toHaveAttribute('data-collapsed');
    expect(button.closest('[data-chat-dock]')).not.toBeNull();

    scrollTo(400);

    expect(button).toHaveAttribute('data-collapsed');
    expect(button).toHaveClass('rounded-full');
});

it('abierto se puede cerrar y vuelve el botón', () => {
    render(<ChatDock chat={CHAT} />);

    fireEvent.click(screen.getByRole('button', { name: 'Escribir al negocio' }));
    expect(screen.getByRole('status')).toHaveTextContent('Para escribirle a este negocio');

    fireEvent.click(screen.getByRole('button', { name: 'Cerrar' }));
    expect(screen.getByRole('button', { name: 'Escribir al negocio' })).toBeInTheDocument();
});
