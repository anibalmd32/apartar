import { MailFrame, frameDocument, hasRemoteImages } from '@/features/platform/mail/MailFrame';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

it('pinta el HTML recibido en un iframe aislado, sin scripts ni mismo origen', () => {
    render(<MailFrame title="Correo de Ana" html={'<p>Hola</p><script>alert(1)</script>'} text={null} />);

    const frame = screen.getByTitle('Correo de Ana');

    expect(frame.tagName).toBe('IFRAME');
    expect(frame.getAttribute('sandbox')).toBe('allow-popups allow-popups-to-escape-sandbox');
    expect(frame.getAttribute('sandbox')).not.toContain('allow-scripts');
    expect(frame.getAttribute('sandbox')).not.toContain('allow-same-origin');
    expect(frame.getAttribute('srcdoc')).toContain("default-src 'none'");
    expect(frame.getAttribute('referrerpolicy')).toBe('no-referrer');
});

it('bloquea las imágenes remotas hasta que se piden', () => {
    render(<MailFrame title="Correo" html={'<img src="https://rastreo.example/pixel.gif">'} text={null} />);

    expect(screen.getByTitle('Correo').getAttribute('srcdoc')).toContain('img-src data:"');

    fireEvent.click(screen.getByRole('button', { name: 'Mostrar imágenes' }));

    expect(screen.getByTitle('Correo').getAttribute('srcdoc')).toContain('img-src https: data:');
    expect(screen.queryByRole('button', { name: 'Mostrar imágenes' })).not.toBeInTheDocument();
});

it('sin HTML muestra el texto plano tal cual', () => {
    render(<MailFrame title="Correo" html={null} text={'Hola <b>Ana</b>'} />);

    expect(screen.getByText('Hola <b>Ana</b>')).toBeInTheDocument();
    expect(screen.queryByTitle('Correo')).not.toBeInTheDocument();
});

it('detecta imágenes remotas en etiquetas y en estilos', () => {
    expect(hasRemoteImages('<img src="https://x.test/a.png">')).toBe(true);
    expect(hasRemoteImages('<td style="background:url(http://x.test/a.png)">')).toBe(true);
    expect(hasRemoteImages('<img src="data:image/png;base64,AAAA">')).toBe(false);
    expect(frameDocument('<p>x</p>', false)).toContain('<base target="_blank">');
});
