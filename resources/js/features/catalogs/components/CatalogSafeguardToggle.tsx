import { Alert } from '@/components/ui/alert';
import { CheckboxField } from '@/components/ui/checkbox-field';

interface CatalogSafeguardToggleProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
    rateLabel: string;
}

export function CatalogSafeguardToggle({ checked, onChange, rateLabel }: CatalogSafeguardToggleProps) {
    return (
        <div className="flex flex-col gap-2">
            <CheckboxField
                label="Activar resguardo en dólar paralelo"
                name="safeguard_enabled"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                hint="Para llevar la cuenta de cuánto vale en paralelo lo que cobras con este catálogo."
            />
            {checked ? (
                <Alert tone="warning" title="Qué significa el resguardo">
                    <p>
                        Tus clientes siguen pagando con la tasa de este catálogo ({rateLabel}). Tú, al recibir cada pago, lo cambias a algo que
                        mantiene su valor como el dólar paralelo: USDT, divisas en efectivo, Zelle u otro.
                    </p>
                    <p className="mt-1">
                        En cada pago vas a ver cuánto vale en paralelo, el día en que se hizo, y podrás anotar dónde lo guardaste, con su referencia o
                        su comprobante. El resumen del catálogo suma cuánto tienes resguardado. Los pagos que ya tienes también se calculan, hasta
                        donde llega el histórico del paralelo.
                    </p>
                </Alert>
            ) : null}
        </div>
    );
}
