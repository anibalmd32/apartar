import { BillingBadge } from '@/features/platform/components/labels';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

it('el estado de cobro manda sobre el de la suscripción cuando hay un pago en revisión', () => {
    render(<BillingBadge row={{ subscriptionStatus: 'active', billingStatus: 'under_review' }} />);

    expect(screen.getByText('Pago en revisión')).toHaveClass('badge', 'badge-warning');
    expect(screen.queryByText('Al día')).toBeNull();
});

it('una organización al día no muestra insignia de cobro en la lista', () => {
    const { container } = render(<BillingBadge row={{ subscriptionStatus: 'active', billingStatus: 'active' }} />);

    expect(container).toBeEmptyDOMElement();
});
