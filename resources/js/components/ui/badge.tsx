import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * Etiqueta corta de estado daisyUI (`REQ-QAX-001` RB-5).
 *
 * Nace con la pantalla de Equipo, donde hace falta distinguir tres estados de
 * una persona —dueño, invitación pendiente e inactivo— en una fila estrecha.
 *
 * El color **nunca** es lo único que distingue: cada distintivo lleva su
 * texto. Un estado que solo se leyera por el color no existe para quien no
 * distingue esos dos, y «inactivo» es exactamente el dato que no se puede
 * perder cuando se mira quién aprobó un pago (`REQ-ORG-005` RB-7).
 */

export type BadgeTone = 'neutral' | 'info' | 'success' | 'warning' | 'error';

const TONES: Record<BadgeTone, string> = {
    neutral: 'badge-neutral',
    info: 'badge-info',
    success: 'badge-success',
    warning: 'badge-warning',
    error: 'badge-error',
};

interface BadgeProps {
    tone?: BadgeTone;
    /** Variante de contorno: menos peso visual cuando hay varios en una fila. */
    outline?: boolean;
    children: ReactNode;
    className?: string;
}

export function Badge({ tone = 'neutral', outline = false, children, className }: BadgeProps) {
    return (
        <span className={cn('badge badge-sm', TONES[tone], outline && 'badge-outline', className)}>{children}</span>
    );
}
