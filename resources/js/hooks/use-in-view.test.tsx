import { useInView } from '@/hooks/use-in-view';
import { act, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

type Callback = (entries: { isIntersecting: boolean }[]) => void;

const observers: { callback: Callback; target: Element | null; disconnected: boolean }[] = [];

class FakeObserver {
    private record: (typeof observers)[number];

    constructor(callback: Callback) {
        this.record = { callback, target: null, disconnected: false };
        observers.push(this.record);
    }

    observe(target: Element) {
        this.record.target = target;
    }

    disconnect() {
        this.record.disconnected = true;
    }
}

afterEach(() => {
    observers.length = 0;
    vi.unstubAllGlobals();
});

function Probe() {
    const [ref, inView] = useInView<HTMLButtonElement>();

    return (
        <button ref={ref} type="button">
            {inView ? 'a la vista' : 'fuera de la vista'}
        </button>
    );
}

it('dice si el elemento está a la vista y deja de observarlo al desmontarse', () => {
    vi.stubGlobal('IntersectionObserver', FakeObserver);

    const { unmount } = render(<Probe />);

    expect(observers[0]?.target).toBe(screen.getByRole('button'));
    expect(screen.getByRole('button')).toHaveTextContent('fuera de la vista');

    act(() => observers[0]?.callback([{ isIntersecting: true }]));
    expect(screen.getByRole('button')).toHaveTextContent('a la vista');

    act(() => observers[0]?.callback([{ isIntersecting: false }]));
    expect(screen.getByRole('button')).toHaveTextContent('fuera de la vista');

    unmount();
    expect(observers[0]?.disconnected).toBe(true);
});

it('sin observador disponible lo da por fuera de la vista', () => {
    vi.stubGlobal('IntersectionObserver', undefined);

    render(<Probe />);

    expect(screen.getByRole('button')).toHaveTextContent('fuera de la vista');
});
