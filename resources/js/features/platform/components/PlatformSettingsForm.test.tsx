import { PlatformSettingsForm } from '@/features/platform/components/PlatformSettingsForm';
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    useForm: (data: unknown) => ({ data, errors: {}, isDirty: false, processing: false, setData: vi.fn(), put: vi.fn() }),
}));
vi.mock('@/hooks/use-leave-guard', () => ({ useLeaveGuard: () => null }));

it('la unidad va dentro del campo como sufijo', () => {
    render(
        <PlatformSettingsForm
            settings={[{ key: 'receipt_max_size_mb', label: 'Tamaño máximo de un comprobante', unit: 'MB', value: 5, default: 5, min: 1, max: 20 }]}
        />,
    );

    const field = screen.getByLabelText('Tamaño máximo de un comprobante');

    expect(field.closest('label')).toHaveTextContent('MB');
    expect(screen.getByText('De 1 a 20. Por defecto, 5.')).toBeInTheDocument();
});
