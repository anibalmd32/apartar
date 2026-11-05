import { ChatDock, ChatWidget } from '@/features/chat/components/ChatWidget';
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

it('en el celular empieza como icono redondo y el texto solo se ve en escritorio', () => {
    render(<ChatDock chat={CHAT} />);

    const button = screen.getByRole('button', { name: 'Escribir al negocio' });
    const label = button.querySelector('[data-chat-label]');

    expect(button).not.toHaveClass('px-5');
    expect(button).toHaveClass('lg:px-5');
    expect(label).toHaveClass('sr-only', 'lg:not-sr-only');

    scrollTo(400);

    expect(button).not.toHaveClass('lg:px-5');
    expect(label).not.toHaveClass('lg:not-sr-only');
});

it('abierto se puede cerrar y vuelve el botón', () => {
    render(<ChatDock chat={CHAT} />);

    fireEvent.click(screen.getByRole('button', { name: 'Escribir al negocio' }));
    expect(screen.getByRole('status')).toHaveTextContent('Para escribirle a este negocio');

    fireEvent.click(screen.getByRole('button', { name: 'Cerrar' }));
    expect(screen.getByRole('button', { name: 'Escribir al negocio' })).toBeInTheDocument();
});

it('dentro de la ficha se ve como un botón con su texto y se abre en el mismo sitio', () => {
    render(<ChatWidget chat={CHAT} inline />);

    const button = screen.getByRole('button', { name: 'Escribir al negocio' });
    expect(button).toHaveClass('btn-block');
    expect(button).not.toHaveClass('rounded-full');

    fireEvent.click(button);
    expect(screen.getByRole('status').parentElement).toHaveClass('w-full');
});
