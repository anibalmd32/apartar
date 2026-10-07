import { useFilePreview } from '@/features/receipts/hooks/useFilePreview';

/**
 * Lo que se va a enviar, antes de enviarlo (`REQ-PAY-001`).
 *
 * La captura se ve en miniatura; un PDF se reconoce por su nombre y su peso. Va
 * **debajo** del selector y no a su lado: a 375 px una miniatura junto al
 * `file-input` lo deja sin sitio para decir qué archivo se eligió.
 *
 * El `alt` de la imagen es el nombre del archivo, como en `ImageUploader`: es
 * una foto local de la que no se sabe nada más.
 */

interface ReceiptFilePreviewProps {
    file: File | null;
}

export function ReceiptFilePreview({ file }: ReceiptFilePreviewProps) {
    const preview = useFilePreview(file);

    if (preview === null) {
        return null;
    }

    return (
        <figure className="bg-base-200 rounded-box flex items-center gap-3 p-3" aria-label="Archivo elegido">
            {preview.kind === 'image' ? (
                preview.url !== null ? (
                    <img src={preview.url} alt={preview.name} className="rounded-box h-24 w-24 shrink-0 object-cover" />
                ) : (
                    <div className="skeleton rounded-box h-24 w-24 shrink-0" aria-hidden="true" />
                )
            ) : (
                <div className="bg-base-100 rounded-box flex h-16 w-16 shrink-0 items-center justify-center text-sm font-semibold" aria-hidden="true">
                    {preview.kind === 'pdf' ? 'PDF' : 'Archivo'}
                </div>
            )}

            <figcaption className="min-w-0 text-sm">
                <p className="truncate font-medium" title={preview.name}>
                    {preview.name}
                </p>
                <p className="opacity-70">{preview.size}</p>
            </figcaption>
        </figure>
    );
}
