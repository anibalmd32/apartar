import { Alert } from '@/components/ui/alert';

export interface SupportAccessNotice {
    readonly operator: string;
    readonly reason: string;
    readonly expiresAt: string;
}

export function SupportAccessBanner({ grants }: { grants: readonly SupportAccessNotice[] }) {
    if (grants.length === 0) {
        return null;
    }

    const time = new Intl.DateTimeFormat('es', { dateStyle: 'medium', timeStyle: 'short' });

    return (
        <Alert tone="info" className="flex-col items-start gap-1">
            {grants.map((grant) => (
                <span key={`${grant.operator}:${grant.expiresAt}`}>
                    El soporte de Apartar ({grant.operator}) puede ver los datos de tu negocio, solo leyendo, hasta el{' '}
                    {time.format(new Date(grant.expiresAt))}. Motivo: {grant.reason}
                </span>
            ))}
        </Alert>
    );
}
