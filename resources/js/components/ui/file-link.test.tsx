import { FileLink } from '@/components/ui/file-link';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it } from 'vitest';

const URL = '/backoffice/pagos/7/archivo?signature=x';

it('abre el archivo en un visor dentro de la app, no en otra pestaña', () => {
    render(
        <FileLink url={URL} title="Comprobante del pago" className="link">
            Ver comprobante
        </FileLink>,
    );

    expect(screen.queryByRole('dialog')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Ver comprobante' }));

    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('Comprobante del pago')).toBeInTheDocument();
    expect(within(dialog).getByRole('img', { name: 'Comprobante del pago' })).toHaveAttribute('src', URL);
    expect(within(dialog).getByRole('button', { name: 'Acercar' })).toBeInTheDocument();
    expect(document.querySelector('a[target="_blank"]')).toBeNull();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Cerrar' }));
    expect(screen.queryByRole('dialog')).toBeNull();
});

it('si no sabe el tipo y no es una imagen, lo muestra como PDF', () => {
    render(
        <FileLink url={URL} title="Comprobante del pago">
            Ver comprobante
        </FileLink>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Ver comprobante' }));
    fireEvent.error(screen.getByRole('img', { name: 'Comprobante del pago' }));

    expect(document.querySelector(`object[data="${URL}"]`)).not.toBeNull();
    expect(screen.queryByRole('button', { name: 'Acercar' })).toBeNull();
});
