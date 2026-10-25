import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilCloudUpload, cilDescription } from '@coreui/icons';
import { type DragEvent, useId, useRef, useState } from 'react';

interface ReceiptDropzoneProps {
    label: string;
    accept: string;
    hint: string;
    file: File | null;
    onFile: (file: File | null) => void;
    error?: string;
}

export function ReceiptDropzone({ label, accept, hint, file, onFile, error }: ReceiptDropzoneProps) {
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const [over, setOver] = useState(false);

    const drop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setOver(false);
        onFile(event.dataTransfer.files[0] ?? null);
    };

    const describedBy = [`${id}-hint`, error ? `${id}-error` : null].filter(Boolean).join(' ');

    return (
        <fieldset className="fieldset w-full">
            <label className="fieldset-legend" htmlFor={id}>
                {label}
            </label>

            <input
                ref={input}
                id={id}
                type="file"
                accept={accept}
                className="peer sr-only"
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                onChange={(event) => onFile(event.target.files?.[0] ?? null)}
            />

            <div
                aria-hidden="true"
                onClick={() => input.current?.click()}
                onDragOver={(event) => {
                    event.preventDefault();
                    setOver(true);
                }}
                onDragLeave={() => setOver(false)}
                onDrop={drop}
                data-testid="receipt-dropzone"
                className={cn(
                    'bg-surface-2 border-line-strong peer-focus-visible:outline-primary flex cursor-pointer flex-col items-center justify-center gap-2 rounded-[14px] border border-dashed px-4 py-6 text-center outline-offset-2 peer-focus-visible:outline-2',
                    over && 'border-primary bg-primary-soft',
                    error && 'border-error',
                )}
            >
                <span className="bg-primary-soft text-primary-ink flex size-10 items-center justify-center rounded-xl">
                    <Icon icon={file === null ? cilCloudUpload : cilDescription} size={18} />
                </span>
                <span className="text-sm font-semibold break-all">{file === null ? 'Sube el comprobante' : file.name}</span>
                <span className="text-ink-3 text-xs">{file === null ? 'Arrástralo aquí o pulsa para elegirlo' : 'Pulsa para cambiarlo'}</span>
            </div>

            <p id={`${id}-hint`} className="label text-xs">
                {hint}
            </p>

            {error ? (
                <p id={`${id}-error`} role="alert" className="label text-error text-xs">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
