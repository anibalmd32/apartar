import { cn } from '@/lib/utils';
import { type ButtonHTMLAttributes, type ReactNode } from 'react';

export type ButtonVariant = 'primary' | 'secondary' | 'soft' | 'ghost' | 'danger' | 'success' | 'link';
export type ButtonSize = 'sm' | 'md' | 'lg';

const VARIANTS: Record<ButtonVariant, string> = {
    primary: 'btn-primary',
    secondary: '',
    soft: 'btn-secondary',
    ghost: 'btn-ghost',
    danger: 'btn-error',
    success: 'btn-success',
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
