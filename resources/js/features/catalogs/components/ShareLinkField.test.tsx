import { ShareLinkField } from '@/features/catalogs/components/ShareLinkField';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

/**
 * El enlace que el negocio difunde (`REQ-CAT-022` RB-1, `REQ-BOF-024` RB-5 y
 * RB-6).
 *
 * Dos cosas que solo se pueden comprobar aquí: que lo que se copia es la
 * **URL entera** —no el slug— y que la frase de `Unlisted` es **literalmente**
 * la que RB-6 exige. Esa frase existe porque es la confusión que hace que
 * alguien comparta lo que creía privado, así que una paráfrasis no vale y un
 * test que aceptara cualquier texto dejaría de protegerla.
 */

const URL = 'https://apartar.store/c/ofertas-de-septiembre-2';

describe('ShareLinkField', () => {
    it('enseña la URL completa, que es lo que se pega en WhatsApp', () => {
        render(<ShareLinkField url={URL} name="Ofertas de septiembre" visibility="unlisted" />);

        expect(screen.getByLabelText('Enlace del catálogo')).toHaveValue(URL);
    });

    it('explica un catálogo fuera de la vitrina con las palabras de RB-6', () => {
        render(<ShareLinkField url={URL} name="Ofertas de septiembre" visibility="unlisted" />);

        expect(screen.getByText('Cualquiera con el enlace puede verlo, aunque no aparezca en tu vitrina.')).toBeInTheDocument();
    });

    it('dice que uno en la vitrina además aparece en ella', () => {
        render(<ShareLinkField url={URL} name="Ofertas de septiembre" visibility="listed" />);

        expect(screen.getByText('Aparece en tu vitrina y cualquiera con el enlace puede verlo.')).toBeInTheDocument();
    });

    it('copia la URL y lo confirma', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);

        Object.assign(navigator, { clipboard: { writeText } });

        render(<ShareLinkField url={URL} name="Ofertas de septiembre" visibility="unlisted" />);

        fireEvent.click(screen.getByRole('button', { name: 'Copiar enlace' }));

        expect(writeText).toHaveBeenCalledWith(URL);

        await waitFor(() => expect(screen.getByRole('button', { name: 'Copiado' })).toBeInTheDocument());
    });

    it('sobrevive a un navegador sin portapapeles, porque el enlace sigue a la vista', async () => {
        Object.assign(navigator, { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } });

        render(<ShareLinkField url={URL} name="Ofertas de septiembre" visibility="unlisted" />);

        fireEvent.click(screen.getByRole('button', { name: 'Copiar enlace' }));

        await waitFor(() => expect(screen.getByRole('button', { name: 'Copiar enlace' })).toBeInTheDocument());
        expect(screen.getByLabelText('Enlace del catálogo')).toHaveValue(URL);
    });
});
