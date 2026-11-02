import { TextField } from '@/components/ui/text-field';
import { failureMessage, Toaster, validationSummary } from '@/components/ui/toaster';
import { durationFor, resetToasts, toast } from '@/lib/toast';
import { type Page } from '@inertiajs/core';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

function page(component: string, status?: string): Page {
    return { component, props: { status, errors: {} }, url: '/', version: null, clearHistory: false, encryptHistory: false } as unknown as Page;
}

afterEach(() => {
    act(() => resetToasts());
});

describe('los avisos', () => {
    it('anuncian el mensaje del servidor al cargar la página', () => {
        render(<Toaster initialPage={page('backoffice/products/Index', 'Producto guardado.')} />);

        expect(screen.getByRole('status')).toHaveTextContent('Producto guardado.');
    });

    it('no repiten el mensaje en las pantallas que ya lo muestran en línea', () => {
        render(<Toaster initialPage={page('backoffice/auth/Login', 'Contraseña restablecida.')} />);

        expect(screen.queryByRole('status')).toBeNull();
    });

    it('muestran los errores como alerta y se pueden cerrar', () => {
        render(<Toaster initialPage={page('backoffice/Dashboard')} />);

        act(() => {
            toast.error('No hay conexión con el servidor.');
        });

        expect(screen.getByRole('alert')).toHaveTextContent('No hay conexión con el servidor.');

        fireEvent.click(screen.getByRole('button', { name: 'Cerrar aviso' }));

        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('no dejan un contenedor vacío en pantalla cuando no hay avisos', () => {
        const { container } = render(<Toaster initialPage={page('backoffice/Dashboard')} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('dejan pasar los toques alrededor del aviso y se puede tocar el aviso', () => {
        const { container } = render(<Toaster initialPage={page('backoffice/Dashboard')} />);

        act(() => {
            toast.success('Guardado.');
        });

        expect(container.firstElementChild).toHaveClass('pointer-events-none');
        expect(screen.getByRole('status')).toHaveClass('pointer-events-auto');
    });

    it('en el celular salen abajo, por encima de la barra de pestañas y del aviso de cookies', () => {
        const { container } = render(<Toaster initialPage={page('backoffice/Dashboard')} />);

        act(() => {
            toast.success('Guardado.');
        });

        expect(container.firstElementChild).toHaveClass('toast-bottom');
        expect(container.firstElementChild).not.toHaveClass('toast-top');
        expect(container.firstElementChild?.className).toContain('bottom-[calc(var(--overlay-bottom)+var(--consent-height)+1rem)]');
    });

    it('al fallar un envío llevan al primer campo con error y no repiten el aviso', async () => {
        render(
            <>
                <Toaster initialPage={page('backoffice/products/Create')} />
                <TextField label="Nombre" error="Completa el campo nombre." />
                <TextField label="Precio" error="Pon un precio." />
            </>,
        );

        act(() => {
            document.dispatchEvent(
                new CustomEvent('inertia:error', { detail: { errors: { name: 'Completa el campo nombre.', price: 'Pon un precio.' } } }),
            );
        });

        await waitFor(() => expect(screen.getByLabelText('Nombre')).toHaveFocus());
        expect(screen.queryByText('Revisa los 2 campos marcados en rojo.')).toBeNull();
    });

    it('solo muestran el aviso de error cuando el campo marcado no está a la vista', async () => {
        const { rerender } = render(<Toaster initialPage={page('backoffice/products/Create')} />);

        act(() => {
            document.dispatchEvent(new CustomEvent('inertia:error', { detail: { errors: { plan: 'Tu plan no permite más productos.' } } }));
        });

        expect(await screen.findByText('Tu plan no permite más productos.')).toBeInTheDocument();

        act(() => resetToasts());
        rerender(
            <>
                <Toaster initialPage={page('backoffice/products/Create')} />
                <TextField label="Nombre" error="Completa el campo nombre." />
            </>,
        );

        act(() => {
            document.dispatchEvent(new CustomEvent('inertia:error', { detail: { errors: { name: 'Completa el campo nombre.' } } }));
        });

        await waitFor(() => expect(screen.getByLabelText('Nombre')).toHaveFocus());
        expect(screen.queryByRole('status')).toBeNull();
        expect(screen.getAllByText('Completa el campo nombre.')).toHaveLength(1);
    });

    it('no apilan dos veces el mismo aviso', () => {
        render(<Toaster initialPage={page('backoffice/Dashboard')} />);

        act(() => {
            toast.success('Guardado.');
            toast.success('Guardado.');
        });

        expect(screen.getAllByRole('status')).toHaveLength(1);
    });
});

describe('los textos de error', () => {
    it('usan el mensaje del campo cuando solo hay uno', () => {
        expect(validationSummary({ name: 'Escribe el nombre.' })).toBe('Escribe el nombre.');
    });

    it('resumen cuántos campos hay que revisar cuando son varios', () => {
        expect(validationSummary({ name: 'Escribe el nombre.', price: 'Pon un precio.' })).toBe('Revisa los 2 campos marcados en rojo.');
    });

    it('explican qué hacer según la respuesta del servidor', () => {
        expect(failureMessage(419)).toContain('Recarga la página');
        expect(failureMessage(500)).toContain('Inténtalo de nuevo');
    });
});

describe('la duración de los avisos', () => {
    it('respeta el mínimo de cada tono en los mensajes cortos', () => {
        expect(durationFor('success', 'Guardado.')).toBe(4000);
        expect(durationFor('error', 'Falló.')).toBe(8000);
    });

    it('deja más tiempo para leer los mensajes largos, con un tope', () => {
        expect(durationFor('success', 'x'.repeat(150))).toBe(9000);
        expect(durationFor('success', 'x'.repeat(1000))).toBe(15000);
    });
});
