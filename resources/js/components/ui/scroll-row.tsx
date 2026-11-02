import { cn } from '@/lib/utils';
import { type HTMLAttributes, useEffect, useRef, useState } from 'react';

interface ScrollRowProps extends HTMLAttributes<HTMLDivElement> {
    activeKey?: string | null;
}

export function ScrollRow({ activeKey, className, children, ...props }: ScrollRowProps) {
    const ref = useRef<HTMLDivElement>(null);
    const [edges, setEdges] = useState({ start: false, end: false });

    useEffect(() => {
        const node = ref.current;

        if (node === null) {
            return;
        }

        const update = () => setEdges({ start: node.scrollLeft > 1, end: Math.ceil(node.scrollLeft + node.clientWidth) < node.scrollWidth - 1 });

        update();
        node.addEventListener('scroll', update, { passive: true });
        const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(update);
        observer?.observe(node);

        return () => {
            node.removeEventListener('scroll', update);
            observer?.disconnect();
        };
    }, []);

    useEffect(() => {
        const node = ref.current;
        const active = node?.querySelector<HTMLElement>('[aria-pressed="true"]');

        if (!node || !active) {
            return;
        }

        const start = active.offsetLeft;
        const end = start + active.offsetWidth;

        if (start < node.scrollLeft || end > node.scrollLeft + node.clientWidth) {
            node.scrollLeft = Math.max(0, start - (node.clientWidth - active.offsetWidth) / 2);
        }
    }, [activeKey]);

    return (
        <div
            ref={ref}
            data-fade-start={edges.start || undefined}
            data-fade-end={edges.end || undefined}
            className={cn('scroll-row', className)}
            {...props}
        >
            {children}
        </div>
    );
}
