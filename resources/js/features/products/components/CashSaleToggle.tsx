import { Alert } from '@/components/ui/alert';
import { cn } from '@/lib/utils';

interface CashSaleToggleProps {
    checked: boolean;
    suggest: boolean;
    onChange: (checked: boolean) => void;
}

export function CashSaleToggle({ checked, suggest, onChange }: CashSaleToggleProps) {
    return (
        <div className="mb-3 flex flex-col gap-2">
            <label
                key={suggest ? 'suggested' : 'idle'}
                data-suggested={suggest || undefined}
                className={cn(
                    '-mx-2 flex cursor-pointer items-start gap-2.5 rounded-lg px-2 py-1.5',
                    suggest && 'bg-warning/10 ring-warning animate-pulse ring-2 [animation-iteration-count:4]',
                )}
            >
                <input
                    type="checkbox"
                    className="checkbox checkbox-sm checkbox-primary mt-0.5 rounded-[5px]"
                    checked={checked}
                    onChange={(event) => onChange(event.target.checked)}
                />
                <span className="flex flex-col">
                    <span className="text-sm font-semibold">Venta al contado</span>
                    <span className="text-ink-3 text-xs">Sin cuotas: tu cliente lo aparta pagando el precio completo de una vez.</span>
                </span>
            </label>

            {suggest ? (
                <Alert tone="warning">
                    La cuota inicial es igual al precio, así que no queda nada que pagar en cuotas. Si lo cobras completo de una vez, marca «Venta al
                    contado».
                </Alert>
            ) : null}
        </div>
    );
}
