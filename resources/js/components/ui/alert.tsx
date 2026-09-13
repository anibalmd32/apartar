import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * Aviso daisyUI (`REQ-QAX-001` RB-5).
 *
 * Lleva `role="alert"` siempre: un aviso que solo se ve no sirve para quien
 * navega con lector de pantalla, y es justo quien más lo necesita cuando un
 * formulario acaba de fallar.
 */

export type AlertTone = 'info' | 'success' | 'warning' | 'error';

const TONES: Record<AlertTone, string> = {
    info: 'alert-info',
    success: 'alert-success',
    warning: 'alert-warning',
    error: 'alert-error',
};

interface AlertProps {
    tone?: AlertTone;
    children: ReactNode;
    className?: string;
}

export function Alert({ tone = 'info', children, className }: AlertProps) {
    return (
        <div role="alert" className={cn('alert', TONES[tone], className)}>
            {children}
        </div>
    );
}
