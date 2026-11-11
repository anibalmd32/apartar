import { FileStage } from '@/components/ui/file-stage';
import { type ReceiptReviewProps } from '@/types/receipt';

export function ReceiptViewer({ receipt }: Pick<ReceiptReviewProps, 'receipt'>) {
    const isPdf = receipt.file.kind === 'pdf';
    const label = isPdf ? 'Comprobante en PDF' : 'Captura del comprobante';

    return (
        <section className="bg-base-100 border-line shadow-card flex flex-col rounded-2xl border p-3 pt-4" aria-label="Comprobante">
            <FileStage url={receipt.file.url} kind={isPdf ? 'pdf' : 'image'} label={label} heading={label} stageClassName="max-h-[36rem]" />
        </section>
    );
}
