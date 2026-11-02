import { ChatComposer } from '@/features/chat/components/ChatComposer';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

function touchScreen(touch: boolean) {
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: touch && query === '(pointer: coarse)', media: query }));
}

afterEach(() => {
    vi.unstubAllGlobals();
});

function typeAndPressEnter() {
    const onSend = vi.fn();
    render(<ChatComposer onSend={onSend} onType={vi.fn()} />);
    const field = screen.getByRole('textbox', { name: 'Mensaje' });
    fireEvent.change(field, { target: { value: 'Hola' } });
    fireEvent.keyDown(field, { key: 'Enter' });

    return { onSend, field };
}

it('en la computadora Enter envía el mensaje', () => {
    touchScreen(false);

    const { onSend, field } = typeAndPressEnter();

    expect(onSend).toHaveBeenCalledWith('Hola');
    expect(field).toHaveAttribute('enterkeyhint', 'send');
});

it('en una pantalla táctil Enter hace un salto de línea y solo el botón envía', () => {
    touchScreen(true);

    const { onSend, field } = typeAndPressEnter();

    expect(onSend).not.toHaveBeenCalled();
    expect(field).toHaveAttribute('enterkeyhint', 'enter');

    fireEvent.click(screen.getByRole('button', { name: 'Enviar' }));

    expect(onSend).toHaveBeenCalledWith('Hola');
});

it('el campo empieza en una línea, crece solo y no se puede estirar a mano', () => {
    touchScreen(false);
    render(<ChatComposer onSend={vi.fn()} onType={vi.fn()} />);

    const field = screen.getByRole('textbox', { name: 'Mensaje' });

    expect(field).toHaveAttribute('rows', '1');
    expect(field).toHaveClass('resize-none', 'max-h-40');
    expect(field.closest('form')?.className).toContain('env(safe-area-inset-bottom)');
});
