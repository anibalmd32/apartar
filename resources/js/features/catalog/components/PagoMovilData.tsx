import { Icon } from '@/components/ui/icon';
import { CopyButton } from '@/features/catalog/components/CopyButton';
import { formatDocumentNumber, formatPhone } from '@/lib/identity-format';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { type PaymentAccountView } from '@/types/payment';
import { cilCheckAlt, cilCopy } from '@coreui/icons';
import { useEffect, useState } from 'react';

export function isPagoMovil(account: PaymentAccountView): boolean {
    return account.method === 'pago_movil';
}

export function pagoMovilFields(account: PaymentAccountView): { key: string; label: string; value: string; display: string }[] {
    const phone = account.identifier.replace(/\D/g, '');
    const document = account.holderDocument.toUpperCase().replace(/[^A-Z0-9]/g, '');
    const fields = [
        { key: 'phone', label: 'Teléfono', value: phone, display: formatPhone(phone) },
        { key: 'document', label: 'Cédula o RIF', value: document, display: formatDocumentNumber(document) },
        { key: 'bank', label: 'Banco', value: account.bankCode ?? '', display: account.bankCode ?? '' },
    ];

    return fields.filter((field) => field.value !== '');
}

export function pagoMovilText(account: PaymentAccountView): string {
    return pagoMovilFields(account)
        .map((field) => field.value)
        .join('\n');
}

function CopyAllButton({ value }: { value: string }) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = window.setTimeout(() => setCopied(false), 2000);

        return () => window.clearTimeout(timer);
    }, [copied]);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
        } catch {
            toast.error('No se pudo copiar. Mantén pulsado cada dato para copiarlo a mano.');
        }
    };

    return (
        <button
            type="button"
            onClick={() => void copy()}
            className={cn(
                'border-line hover:bg-base-200 inline-flex min-h-9 cursor-pointer items-center gap-1.5 self-start rounded-lg border px-2.5 text-xs font-semibold',
                copied ? 'text-success' : 'text-primary-ink',
            )}
        >
            <Icon icon={copied ? cilCheckAlt : cilCopy} size={13} />
            {copied ? 'Datos copiados' : 'Copiar todos los datos'}
        </button>
    );
}

export function PagoMovilData({ account, className }: { account: PaymentAccountView; className?: string }) {
    const fields = pagoMovilFields(account);

    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            <dl className="flex flex-col">
                {fields.map((field) => (
                    <div key={field.key} className="flex items-center gap-2 text-[13px]">
                        <dt className="text-ink-3 w-[88px] shrink-0">{field.label}</dt>
                        <dd className="flex min-w-0 grow items-baseline gap-1.5">
                            <span className="font-semibold tracking-wide whitespace-nowrap tabular-nums select-all">{field.display}</span>
                            {field.key === 'bank' && account.bank ? <span className="text-ink-3 truncate">{account.bank}</span> : null}
                        </dd>
                        <CopyButton value={field.value} label={`${field.label.toLowerCase()} del pago móvil`} size={13} />
                    </div>
                ))}
            </dl>
            <CopyAllButton value={pagoMovilText(account)} />
        </div>
    );
}
