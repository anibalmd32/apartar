import { useFilePreview } from '@/features/receipts/hooks/useFilePreview';

interface ReceiptFilePreviewProps {
    file: File | null;
}

export function ReceiptFilePreview({ file }: ReceiptFilePreviewProps) {
    const preview = useFilePreview(file);

    if (preview === null) {
        return null;
    }

    return (
        <figure className="bg-surface-2 flex items-center gap-3 rounded-xl p-3" aria-label="Archivo elegido">
            {preview.kind === 'image' ? (
                preview.url !== null ? (
                    <img src={preview.url} alt={preview.name} className="h-16 w-14 shrink-0 rounded-[10px] object-cover" />
                ) : (
                    <div className="skeleton h-16 w-14 shrink-0 rounded-[10px]" aria-hidden="true" />
                )
            ) : (
                <div
                    className="bg-base-100 text-ink-2 flex h-16 w-14 shrink-0 items-center justify-center rounded-[10px] text-xs font-bold"
                    aria-hidden="true"
                >
                    {preview.kind === 'pdf' ? 'PDF' : 'Archivo'}
                </div>
            )}

            <figcaption className="min-w-0 text-sm">
                <p className="truncate text-[13px] font-semibold" title={preview.name}>
                    {preview.name}
                </p>
                <p className="text-ink-3 text-xs">{preview.size}</p>
            </figcaption>
        </figure>
    );
}
