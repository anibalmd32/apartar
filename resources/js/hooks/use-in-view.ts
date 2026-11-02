import { useCallback, useEffect, useState } from 'react';

export function useInView<T extends Element>(): [(element: T | null) => void, boolean] {
    const [element, setElement] = useState<T | null>(null);
    const [inView, setInView] = useState(false);

    useEffect(() => {
        if (element === null || typeof IntersectionObserver === 'undefined') {
            setInView(false);

            return;
        }

        const observer = new IntersectionObserver(([entry]) => setInView(entry?.isIntersecting ?? false));

        observer.observe(element);

        return () => observer.disconnect();
    }, [element]);

    const ref = useCallback((node: T | null) => setElement(node), []);

    return [ref, inView];
}
