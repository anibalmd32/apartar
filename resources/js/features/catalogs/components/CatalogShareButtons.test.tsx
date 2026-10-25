import { ShareButton, WhatsAppButton } from '@/features/catalogs/components/CatalogShareButtons';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

const URL = 'https://apartar.test/c/otono';

afterEach(() => vi.unstubAllGlobals());

it('WhatsApp lleva el nombre y el enlace ya escritos', () => {
    render(<WhatsAppButton url={URL} name="Colección Otoño" />);

    expect(screen.getByRole('link', { name: 'WhatsApp' })).toHaveAttribute(
        'href',
        `https://wa.me/?text=${encodeURIComponent(`Colección Otoño\n${URL}`)}`,
    );
});

it('con la hoja del sistema comparte por ella', async () => {
    const share = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { ...navigator, share });
    render(<ShareButton url={URL} name="Colección Otoño" />);

    fireEvent.click(screen.getByRole('button', { name: 'Compartir Colección Otoño' }));

    await waitFor(() => expect(share).toHaveBeenCalledWith({ title: 'Colección Otoño', url: URL }));
});

it('sin ella copia el enlace y lo confirma', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    render(<ShareButton url={URL} name="Colección Otoño" />);

    fireEvent.click(screen.getByRole('button', { name: 'Compartir Colección Otoño' }));

    await waitFor(() => expect(screen.getByRole('button', { name: 'Enlace copiado' })).toBeInTheDocument());
    expect(writeText).toHaveBeenCalledWith(URL);
});
