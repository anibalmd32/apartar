import { Alert } from '@/components/ui/alert';
import { Link, usePage } from '@inertiajs/react';

export interface PastDueNotice {
    readonly amount: string | null;
    readonly deadline: string | null;
    readonly invoicesUrl: string | null;
}

export function PastDueBanner() {
    const { pastDue } = usePage<{ pastDue?: PastDueNotice | null }>().props;

    if (pastDue === undefined || pastDue === null) {
        return null;
    }

    const what =
        pastDue.amount !== null ? `Tienes ${pastDue.amount} pendientes de tu suscripción.` : 'Hay un pago pendiente de la suscripción del negocio.';
    const when =
        pastDue.deadline !== null
            ? ` Hay que pagarlo antes del ${pastDue.deadline} para que no se suspenda;`
            : ' Estamos revisando el pago o el plazo está a punto de acabar;';

    return (
        <Alert tone="warning" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
            <span>
                {what}
                {when} mientras tanto todo sigue funcionando y tus clientes no notan nada.
            </span>

            {pastDue.invoicesUrl !== null ? (
                <Link className="btn btn-sm btn-warning" href={pastDue.invoicesUrl}>
                    Pagar
                </Link>
            ) : null}
        </Alert>
    );
}
