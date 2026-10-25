import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

import { SupportAccessBanner } from './support-access-banner';

it('sin accesos vigentes no pinta nada', () => {
    const { container } = render(<SupportAccessBanner grants={[]} />);

    expect(container).toBeEmptyDOMElement();
});

it('dice quién, por qué y que es solo lectura', () => {
    render(<SupportAccessBanner grants={[{ operator: 'Ana Operadora', reason: 'Revisión del ticket 42', expiresAt: '2026-10-22T18:00:00Z' }]} />);

    expect(screen.getByText(/Ana Operadora/)).toHaveTextContent('solo leyendo');
    expect(screen.getByText(/Revisión del ticket 42/)).toBeInTheDocument();
});
