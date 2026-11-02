import { type MouseEvent, type PointerEvent, useEffect, useRef } from 'react';

const HOLD_MS = 500;
const SLOP_PX = 10;

export interface LongPressHandlers {
    onPointerDown: (event: PointerEvent) => void;
    onPointerMove: (event: PointerEvent) => void;
    onPointerUp: () => void;
    onPointerCancel: () => void;
    onPointerLeave: () => void;
    onClickCapture: (event: MouseEvent) => void;
    onContextMenu: (event: MouseEvent) => void;
}

export function useLongPress(onLongPress: () => void, enabled = true): LongPressHandlers | Record<string, never> {
    const timer = useRef<number | null>(null);
    const origin = useRef<{ x: number; y: number } | null>(null);
    const fired = useRef(false);

    const cancel = () => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
            timer.current = null;
        }
    };

    useEffect(() => cancel, []);

    if (!enabled) {
        return {};
    }

    return {
        onPointerDown: (event) => {
            if (event.button !== 0) {
                return;
            }

            fired.current = false;
            origin.current = { x: event.clientX, y: event.clientY };
            cancel();
            timer.current = window.setTimeout(() => {
                timer.current = null;
                fired.current = true;
                onLongPress();
            }, HOLD_MS);
        },
        onPointerMove: (event) => {
            const start = origin.current;

            if (start && Math.hypot(event.clientX - start.x, event.clientY - start.y) > SLOP_PX) {
                cancel();
            }
        },
        onPointerUp: cancel,
        onPointerCancel: cancel,
        onPointerLeave: cancel,
        onClickCapture: (event) => {
            if (fired.current) {
                fired.current = false;
                event.preventDefault();
                event.stopPropagation();
            }
        },
        onContextMenu: (event) => {
            if (fired.current || timer.current !== null) {
                event.preventDefault();
            }
        },
    };
}
