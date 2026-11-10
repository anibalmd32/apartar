import { PhotoReviewDecision } from '@/features/platform/components/PhotoReviewDecision';
import { type PendingPhotoReview } from '@/types/platform';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { posts } = vi.hoisted(() => ({ posts: { calls: [] as string[] } }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState(initial);

            return {
                data,
                setData: (key: keyof T, value: unknown) => setState((previous) => ({ ...previous, [key]: value })),
                errors: {},
                processing: false,
                post: (url: string) => posts.calls.push(url),
            };
        },
    };
});

beforeEach(() => {
    posts.calls = [];
});

const REVIEW: PendingPhotoReview = {
    id: 7,
    organization: { id: 3, name: 'Mascotas Felices' },
    product: { id: 11, name: 'Gato persa', description: 'Un gato muy cariñoso.' },
    subject: 'un animal',
    observation: 'Un gato blanco en un sofá.',
    requestedAt: '2026-11-10T10:00:00-04:00',
    isLate: false,
    photos: [],
};

it('muestra lo que vio la IA y aprueba con un clic', () => {
    render(<PhotoReviewDecision review={REVIEW} />);

    expect(screen.getByText('Un gato blanco en un sofá.')).toBeInTheDocument();
    expect(screen.getByText('Un gato muy cariñoso.')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Aprobar: se puede vender' }));

    expect(posts.calls).toEqual(['/plataforma/revisiones/7/aprobar']);
});

it('rechazar pide el motivo antes de enviar', () => {
    render(<PhotoReviewDecision review={{ ...REVIEW, observation: null }} />);

    expect(screen.getByText('Fotos que parecen mostrar un animal.')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Rechazar' }));

    const submit = screen.getByRole('button', { name: 'Rechazar fotos' });
    expect(submit).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Motivo del rechazo (el negocio lo verá)'), { target: { value: 'No vendemos animales.' } });
    fireEvent.click(submit);

    expect(posts.calls).toEqual(['/plataforma/revisiones/7/rechazar']);
});
