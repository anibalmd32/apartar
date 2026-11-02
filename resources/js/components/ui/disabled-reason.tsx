import { cn } from '@/lib/utils';
import { type ReactNode, type PointerEvent as ReactPointerEvent, useEffect, useId, useLayoutEffect, useRef, useState } from 'react';

const EDGE = 12;

interface DisabledReasonProps {
    reason: string;
    children: ReactNode;
    className?: string;
}

export function DisabledReason({ reason, children, className }: DisabledReasonProps) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLSpanElement>(null);
    const pointer = useRef<string | null>(null);
    const bubble = useRef<HTMLSpanElement>(null);
    const [shift, setShift] = useState(0);

    useLayoutEffect(() => {
        if (!open || bubble.current === null) {
            setShift(0);

            return;
        }

        const { left, right } = bubble.current.getBoundingClientRect();

        if (left < EDGE) {
            setShift(EDGE - left);
        } else if (right > window.innerWidth - EDGE) {
            setShift(window.innerWidth - EDGE - right);
        }
    }, [open]);

    const hover = (next: boolean) => (event: ReactPointerEvent) => {
        if (event.pointerType === 'mouse') {
            setOpen(next);
        }
    };

    useEffect(() => {
        if (!open) {
            return;
        }

        const close = (event: PointerEvent) => {
            if (root.current && !root.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', close);

        return () => document.removeEventListener('pointerdown', close);
    }, [open]);

    return (
        <span ref={root} className={cn('relative inline-flex', className)} onPointerEnter={hover(true)} onPointerLeave={hover(false)}>
            {children}
            <button
                type="button"
                aria-label="Por qué no está disponible"
                aria-describedby={open ? id : undefined}
                aria-expanded={open}
                onPointerDown={(event) => {
                    pointer.current = event.pointerType;
                }}
                onClick={() => {
                    const viaMouse = pointer.current === 'mouse';

                    pointer.current = null;
                    setOpen((current) => viaMouse || !current);
                }}
                onFocus={() => {
                    if (pointer.current === null) {
                        setOpen(true);
                    }
                }}
                onBlur={() => setOpen(false)}
                className="hit-area bg-warning text-warning-content ring-base-100 absolute -top-2 -right-2 z-10 inline-flex size-5 cursor-pointer items-center justify-center rounded-full text-[11px] font-extrabold ring-2"
            >
                !
            </button>
            {open ? (
                <span
                    ref={bubble}
                    id={id}
                    role="tooltip"
                    style={shift === 0 ? undefined : { transform: `translateX(${shift}px)` }}
                    className="bg-neutral text-neutral-content shadow-pop absolute top-full right-0 z-50 mt-2 w-60 rounded-lg px-3 py-2 text-xs leading-snug"
                >
                    {reason}
                </span>
            ) : null}
        </span>
    );
}
