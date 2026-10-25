import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilCheckCircle, cilCircle } from '@coreui/icons';

interface PasswordRequirementsProps {
    id: string;
    value: string;
}

export function PasswordRequirements({ id, value }: PasswordRequirementsProps) {
    const rules: { label: string; met: boolean | null }[] = [
        { label: 'Al menos 12 caracteres', met: value.length >= 12 },
        { label: 'Mayúsculas y minúsculas', met: /[a-z]/.test(value) && /[A-Z]/.test(value) },
        { label: 'Al menos un número', met: /\d/.test(value) },
        { label: 'No aparece en filtraciones conocidas', met: null },
    ];

    return (
        <ul id={id} className="bg-surface-2 flex flex-col gap-1.5 rounded-[10px] p-3 text-xs">
            {rules.map((rule) => (
                <li key={rule.label} className={cn('flex items-center gap-2', rule.met === null ? 'text-ink-3' : 'text-ink-2')}>
                    <Icon icon={rule.met ? cilCheckCircle : cilCircle} size={14} className={rule.met ? 'text-success' : 'text-ink-3'} />
                    {rule.label}
                    {rule.met === null ? null : <span className="sr-only">{rule.met ? '(cumplido)' : '(pendiente)'}</span>}
                </li>
            ))}
        </ul>
    );
}
