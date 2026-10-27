import { cn } from '@/lib/utils';

interface PasswordStrengthProps {
    id: string;
    value: string;
}

export function PasswordStrength({ id, value }: PasswordStrengthProps) {
    const checks = [value.length >= 6, /\p{L}/u.test(value), /\d/.test(value), value.length >= 10];
    const met = checks.filter(Boolean).length;
    const meetsRules = checks.slice(0, 3).every(Boolean);

    const status = value === '' ? null : meetsRules ? 'Cumple los requisitos' : 'Aún no cumple los requisitos';
    const tone = meetsRules ? 'bg-success' : met >= 2 ? 'bg-warning' : 'bg-error';

    return (
        <div id={id} className="flex flex-col gap-1.5">
            <div className="flex gap-1" aria-hidden="true">
                {checks.map((_, index) => (
                    <span key={index} className={cn('h-1 flex-1 rounded-sm', value !== '' && index < met ? tone : 'bg-base-300')} />
                ))}
            </div>
            <p className="text-ink-3 text-xs">{status ? `${status} · ` : null}mínimo 6 caracteres, con letras y números</p>
        </div>
    );
}
