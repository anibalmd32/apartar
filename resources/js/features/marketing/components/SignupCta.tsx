import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export const SIGNUP_LABEL = 'Empezar gratis';

export const CTA_SHAPE = 'pz-btn';

const TONES = {
    primary: 'pz-btn-blue',
    secondary: 'pz-btn-line',
    inverse: 'pz-btn-paper',
} as const;

interface SignupCtaProps {
    tone?: keyof typeof TONES;
    className?: string;
    href?: string;
    label?: string;
    shortLabel?: string;
}

export function SignupCta({ tone = 'primary', className, href, label = SIGNUP_LABEL, shortLabel }: SignupCtaProps) {
    const rest = shortLabel !== undefined && label.startsWith(shortLabel) ? label.slice(shortLabel.length) : null;

    return (
        <Link href={href ?? route('register')} className={cn(CTA_SHAPE, 'max-sm:w-full', TONES[tone], className)}>
            {rest === null ? (
                label
            ) : (
                <>
                    {shortLabel}
                    <span className="max-[359px]:hidden">{rest}</span>
                </>
            )}
        </Link>
    );
}
