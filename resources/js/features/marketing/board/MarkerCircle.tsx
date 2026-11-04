import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface MarkerCircleProps {
    children: ReactNode;
    className?: string;
    tone?: 'red' | 'blue' | 'green';
}

const TONES = {
    red: 'text-pz-red',
    blue: 'text-pz-blue',
    green: 'text-pz-green',
} as const;

export function MarkerCircle({ children, className, tone = 'red' }: MarkerCircleProps) {
    return (
        <span className={cn('relative inline-block px-[0.45em]', className)}>
            <svg
                aria-hidden="true"
                viewBox="0 0 200 80"
                preserveAspectRatio="none"
                className={cn('pz-circle absolute -inset-x-1 -inset-y-2 h-[calc(100%+1rem)] w-[calc(100%+0.5rem)] overflow-visible', TONES[tone])}
            >
                <path
                    d="M30 14 C 70 2, 160 4, 188 26 C 204 44, 170 72, 100 75 C 40 77, 4 62, 8 40 C 11 22, 48 10, 96 8"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="4"
                    strokeLinecap="round"
                    pathLength={1}
                    vectorEffect="non-scaling-stroke"
                />
            </svg>
            <span className="relative">{children}</span>
        </span>
    );
}
