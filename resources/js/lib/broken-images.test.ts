import { watchBrokenImages } from '@/lib/broken-images';
import { describe, expect, it } from 'vitest';

describe('las imágenes rotas', () => {
    it('se marcan para no mostrar el texto alternativo suelto', () => {
        watchBrokenImages();
        const image = document.createElement('img');
        image.alt = 'Sandalias de cuero';
        document.body.append(image);

        image.dispatchEvent(new Event('error'));

        expect(image.dataset.broken).toBe('true');
    });
});
