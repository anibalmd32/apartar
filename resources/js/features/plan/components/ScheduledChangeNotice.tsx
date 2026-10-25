import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatPlanDate } from '@/lib/plan-change';
import { CHANGE_PLAN_URL, type ScheduledPlanChange } from '@/types/plan';
import { useForm } from '@inertiajs/react';

export function ScheduledChangeNotice({
    change,
    currentPlanCode,
    currentPlanName,
    canChange,
    timeZone,
}: {
    change: ScheduledPlanChange;
    currentPlanCode: string;
    currentPlanName: string;
    canChange: boolean;
    timeZone?: string;
}) {
    const { post, processing } = useForm({ plan: currentPlanCode });
    const when = change.effectiveAt === null ? 'al cerrar el período' : `el ${formatPlanDate(change.effectiveAt, timeZone)}`;

    return (
        <Alert tone="info" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
            <span>
                {change.isCancellation ? `Cancelaste ${currentPlanName}: ` : ''}
                pasarás a {change.planName} {when}. Hasta entonces conservas todo lo de {currentPlanName}.
            </span>
            {canChange ? (
                <Button size="sm" variant="ghost" loading={processing} onClick={() => post(CHANGE_PLAN_URL, { preserveScroll: true })}>
                    Mantener {currentPlanName}
                </Button>
            ) : null}
        </Alert>
    );
}
