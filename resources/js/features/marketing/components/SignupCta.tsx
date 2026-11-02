import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export const SIGNUP_LABEL = 'Empezar gratis';

export const CTA_SHAPE = 'btn h-12 rounded-full px-6 text-[15px] font-bold';

const TONES = {
    primary: 'btn-primary',
    secondary: '',
    inverse: 'border-0 bg-white text-[#4338ca] hover:bg-white/90',
} as const;

interface SignupCtaProps {
    tone?: keyof typeof TONES;
    className?: string;
}

export function SignupCta({ tone = 'primary', className }: SignupCtaProps) {
    return (
        <Link href={route('register')} className={cn(CTA_SHAPE, 'max-sm:w-full', TONES[tone], className)}>
            {SIGNUP_LABEL}
        </Link>
    );
}
