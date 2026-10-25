import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { usePushSubscription } from '@/features/notifications/hooks/usePushSubscription';

export function PushOptIn({ publicKey, subscribeUrl, hasEmail }: { publicKey: string | null; subscribeUrl: string; hasEmail: boolean }) {
    const { state, subscribe } = usePushSubscription(publicKey, subscribeUrl);

    if (state === 'unsupported' || state === 'unavailable' || state === 'subscribed') {
        return null;
    }

    if (state === 'denied') {
        return (
            <p className="text-xs opacity-70">
                {hasEmail
                    ? 'Te avisaremos por correo antes de cada vencimiento.'
                    : 'Revisa tu portal antes de cada vencimiento: no tenemos otro canal para avisarte.'}
            </p>
        );
    }

    return (
        <Alert tone="info">
            <span className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <span>¿Quieres que te avisemos en este teléfono antes de cada vencimiento y cuando el negocio te responda?</span>
                <Button size="sm" loading={state === 'subscribing'} onClick={() => void subscribe()}>
                    Activar avisos
                </Button>
            </span>
        </Alert>
    );
}
