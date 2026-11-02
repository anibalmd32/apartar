import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { Button } from './button';
import { Modal } from './modal';

function body(dialog: HTMLElement): HTMLElement {
    return dialog.querySelector('[data-slot="modal-body"]') as HTMLElement;
}

function footer(dialog: HTMLElement): HTMLElement {
    return dialog.querySelector('[data-slot="modal-footer"]') as HTMLElement;
}

it('la descripción se desplaza con el contenido y no queda fija en la cabecera', () => {
    render(
        <Modal open onClose={vi.fn()} title="Registrar pago manual" description="Úsalo para pagos que recibiste por fuera del portal.">
            <p>Campos</p>
        </Modal>,
    );

    const dialog = screen.getByRole('dialog', { name: 'Registrar pago manual' });

    expect(within(body(dialog)).getByText('Úsalo para pagos que recibiste por fuera del portal.')).toBeInTheDocument();
    expect(dialog).toHaveAccessibleDescription('Úsalo para pagos que recibiste por fuera del portal.');
});

it('con onSubmit la hoja es un formulario y el botón de enviar del pie lo envía', () => {
    const submit = vi.fn((event: { preventDefault: () => void }) => event.preventDefault());

    render(
        <Modal
            open
            onClose={vi.fn()}
            onSubmit={submit}
            title="Reprogramar la cuota 2"
            actions={
                <>
                    <Button variant="secondary">Volver</Button>
                    <Button type="submit">Reprogramar</Button>
                </>
            }
        >
            <input aria-label="Motivo" />
        </Modal>,
    );

    const dialog = screen.getByRole('dialog', { name: 'Reprogramar la cuota 2' });
    const confirm = within(footer(dialog)).getByRole('button', { name: 'Reprogramar' });

    expect(body(dialog)).not.toContainElement(confirm);
    fireEvent.click(confirm);
    expect(submit).toHaveBeenCalledOnce();
});

it('en el celular dos acciones se reparten el ancho y tres se apilan', () => {
    const { rerender } = render(
        <Modal
            open
            onClose={vi.fn()}
            title="Sugerencia de la IA"
            footnote="Te quedan 29 sugerencias hoy"
            actions={
                <>
                    <Button variant="secondary">Descartar</Button>
                    <Button>Usar sugerencia</Button>
                </>
            }
        >
            <p>Texto</p>
        </Modal>,
    );

    const group = () => screen.getByRole('button', { name: 'Usar sugerencia' }).parentElement as HTMLElement;

    expect(group()).toHaveClass('max-lg:grid', 'max-lg:w-full', 'max-lg:grid-flow-col', 'max-lg:*:w-full');
    expect(screen.getByText('Te quedan 29 sugerencias hoy')).toHaveClass('max-lg:w-full');

    rerender(
        <Modal
            open
            onClose={vi.fn()}
            title="Sugerencia de la IA"
            actions={
                <>
                    <Button variant="ghost">Cancelar</Button>
                    <Button variant="secondary">Solo mover</Button>
                    <Button>Usar sugerencia</Button>
                </>
            }
        >
            <p>Texto</p>
        </Modal>,
    );

    expect(group()).toHaveAttribute('data-stacked', 'true');
    expect(group()).toHaveClass('max-lg:flex-col-reverse', 'max-lg:*:w-full');
});

it('una confirmación destructiva lleva el icono de advertencia, no el de reloj', () => {
    render(
        <Modal open onClose={vi.fn()} tone="danger" title="Suspender Tecno Hogar" actions={<Button variant="danger">Suspender</Button>}>
            <p>Queda en la auditoría con tu nombre.</p>
        </Modal>,
    );

    const dialog = screen.getByRole('dialog', { name: 'Suspender Tecno Hogar' });

    expect(dialog.querySelector('.bg-error-soft.text-error svg')).not.toBeNull();
});
