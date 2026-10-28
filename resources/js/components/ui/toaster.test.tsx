import { failureMessage, Toaster, validationSummary } from '@/components/ui/toaster';
import { durationFor, resetToasts, toast } from '@/lib/toast';
import { type Page } from '@inertiajs/core';
import { act, fireEvent, render, screen } from '@testing-library/react';
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
