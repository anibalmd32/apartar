import { OrganizationProfile } from '@/features/platform/components/OrganizationProfile';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

it('muestra la zona horaria con su nombre y la vitrina como enlace corto', () => {
    render(
        <OrganizationProfile
            profile={{
                ownerName: 'Carlos Méndez',
                ownerEmail: 'carlos@apartar.test',
                phone: null,
                document: null,
                storefrontUrl: 'http://localhost:8000/tecno-hogar',
                timezone: 'America/Caracas',
            }}
        />,
    );

    expect(screen.getByText('Hora de Venezuela (Caracas)')).toBeInTheDocument();
    expect(screen.queryByText('America/Caracas')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'localhost:8000/tecno-hogar' })).toHaveAttribute('href', 'http://localhost:8000/tecno-hogar');
});
