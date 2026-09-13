import { cn } from '@/lib/utils';
import { type ButtonHTMLAttributes, type ReactNode } from 'react';

/**
 * Botón daisyUI (`REQ-QAX-001` RB-5).
 *
 * Primer átomo del sistema: las páginas piden `<Button variant="primary">` y
 * nunca escriben `btn btn-primary` a mano. Es lo que permite cambiar el
 * sistema visual —o su tema— tocando este archivo y no ochenta pantallas.
 *
 * `loading` deshabilita además de pintar el spinner, a propósito: un botón que
 * gira pero se deja pulsar produce el doble envío que el spinner pretendía
 * evitar.
 */

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'link';
export type ButtonSize = 'sm' | 'md' | 'lg';

const VARIANTS: Record<ButtonVariant, string> = {
    primary: 'btn-primary',
    secondary: 'btn-secondary',
    ghost: 'btn-ghost',
    link: 'btn-link',
};

const SIZES: Record<ButtonSize, string> = {
    sm: 'btn-sm',
    md: '',
    lg: 'btn-lg',
};

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant;
    size?: ButtonSize;
    /** Ocupa todo el ancho. En móvil es lo normal, y este producto entra por teléfono. */
    block?: boolean;
    loading?: boolean;
    children: ReactNode;
}

export function Button({
    variant = 'primary',
    size = 'md',
    block = false,
    loading = false,
    disabled = false,
    className,
    children,
    type = 'button',
    ...props
}: ButtonProps) {
    return (
        <button
            type={type}
            className={cn('btn', VARIANTS[variant], SIZES[size], block && 'btn-block', className)}
            disabled={disabled || loading}
            aria-busy={loading || undefined}
            {...props}
        >
            {loading ? <span className="loading loading-spinner loading-sm" aria-hidden="true" /> : null}
            {children}
        </button>
    );
}
