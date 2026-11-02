import { FieldError } from '@/components/ui/field-error';
import { Icon } from '@/components/ui/icon';
import { useFilePreview, type FilePreview } from '@/features/receipts/hooks/useFilePreview';
import { cn } from '@/lib/utils';
import { cilCloudUpload } from '@coreui/icons';
import { useId, useRef, type ReactNode } from 'react';

interface ReceiptUploadZoneProps {
    label: string;
    accept: string;
    file: File | null;
    onChange: (file: File | null) => void;
    hint: ReactNode;
    error?: string;
}

function Thumbnail({ preview }: { preview: FilePreview }) {
    if (preview.kind === 'image') {
        return preview.url !== null ? (
            <img src={preview.url} alt="" className="border-line h-20 w-16 shrink-0 rounded-[10px] border object-cover" />
        ) : (
            <span className="skeleton h-20 w-16 shrink-0 rounded-[10px]" aria-hidden="true" />
        );
    }

    return (
        <span
            aria-hidden="true"
            className="border-line bg-base-100 text-ink-2 flex h-20 w-16 shrink-0 items-center justify-center rounded-[10px] border text-xs font-bold"
        >
            {preview.kind === 'pdf' ? 'PDF' : 'Archivo'}
        </span>
    );
}

export function ReceiptUploadZone({ label, accept, file, onChange, hint, error }: ReceiptUploadZoneProps) {
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const preview = useFilePreview(file);
    const describedBy = [error ? `${id}-error` : null, `${id}-hint`].filter(Boolean).join(' ');

    const remove = () => {
        if (input.current) {
            input.current.value = '';
        }

        onChange(null);
    };

    return (
        <fieldset className="fieldset w-full min-w-0">
            <legend className="fieldset-legend">{label}</legend>

            <input
                ref={input}
                id={id}
                type="file"
                name="file"
                accept={accept}
                className="peer sr-only"
                aria-label={label}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                onChange={(event) => onChange(event.target.files?.[0] ?? null)}
            />

            {preview === null ? (
                <label
                    htmlFor={id}
                    className={cn(
                        'peer-focus-visible:outline-primary bg-surface-2 hover:border-primary flex min-h-36 cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-4 py-6 text-center transition-colors peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2',
                        error ? 'border-error' : 'border-line-strong',
                    )}
                >
                    <span aria-hidden="true" className="bg-primary-soft text-primary-ink flex size-11 items-center justify-center rounded-full">
                        <Icon icon={cilCloudUpload} size={20} />
                    </span>
                    <span className="text-[15px] font-semibold">Toca para subir la captura</span>
                    <span id={`${id}-hint`} className="text-ink-3 text-xs">
                        {hint}
                    </span>
                </label>
            ) : (
                <div className="border-line bg-surface-2 flex items-center gap-3 rounded-2xl border p-3" aria-label="Archivo elegido" role="group">
                    <Thumbnail preview={preview} />
                    <div className="flex min-w-0 flex-1 flex-col gap-2">
                        <p className="min-w-0 text-[13px]">
                            <span className="block truncate font-semibold" title={preview.name}>
                                {preview.name}
                            </span>
                            <span id={`${id}-hint`} className="text-ink-3 text-xs">
                                {preview.size}
                            </span>
                        </p>
                        <span className="flex flex-wrap gap-2">
                            <label htmlFor={id} className="btn btn-sm touch:min-h-11 cursor-pointer max-lg:min-h-11">
                                Cambiar
                            </label>
                            <button type="button" className="btn btn-ghost btn-sm text-error max-lg:min-h-11" onClick={remove}>
                                Quitar
                            </button>
                        </span>
                    </div>
                </div>
            )}

            {error ? <FieldError id={`${id}-error`}>{error}</FieldError> : null}
        </fieldset>
    );
}
