import { cn } from '@/lib/utils';
import { type ReactNode, useEffect, useId, useRef, useState } from 'react';

interface DisabledReasonProps {
    reason: string;
    children: ReactNode;
    className?: string;
}

export function DisabledReason({ reason, children, className }: DisabledReasonProps) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLSpanElement>(null);

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
        <span ref={root} className={cn('relative inline-flex', className)} onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)}>
            {children}
            <button
                type="button"
                aria-label="Por qué no está disponible"
                aria-describedby={open ? id : undefined}
                aria-expanded={open}
                onClick={() => setOpen((current) => !current)}
                onFocus={() => setOpen(true)}
                onBlur={() => setOpen(false)}
                className="bg-warning text-warning-content ring-base-100 absolute -top-2 -right-2 z-10 inline-flex size-5 cursor-pointer items-center justify-center rounded-full text-[11px] font-extrabold ring-2"
            >
                !
            </button>
            {open ? (
                <span
                    id={id}
                    role="tooltip"
                    className="bg-neutral text-neutral-content shadow-pop absolute top-full right-0 z-50 mt-2 w-60 rounded-lg px-3 py-2 text-xs leading-snug"
                >
                    {reason}
                </span>
            ) : null}
        </span>
    );
}
