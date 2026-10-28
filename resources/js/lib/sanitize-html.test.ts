import { plainText, sanitizeRichText } from '@/lib/sanitize-html';
import { expect, it } from 'vitest';

it('conserva solo el formato permitido y quita atributos', () => {
    expect(sanitizeRichText('<p class="x" onclick="alert(1)">Hola <strong>mundo</strong></p><ul><li>Uno</li></ul>')).toBe(
        '<p>Hola <strong>mundo</strong></p><ul><li>Uno</li></ul>',
    );
});

it('desenvuelve lo no permitido y descarta scripts y estilos', () => {
    expect(sanitizeRichText('<h2>Título</h2><a href="javascript:x">enlace</a><script>alert(1)</script><style>p{}</style><img src=x>')).toBe(
        'Títuloenlace',
    );
});

it('lee el texto de un HTML vacío como vacío', () => {
    expect(plainText('<p> </p><p><br></p>')).toBe('');
    expect(plainText('<p>Hola</p>')).toBe('Hola');
});
