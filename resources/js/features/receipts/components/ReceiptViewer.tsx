import { Icon } from '@/components/ui/icon';
import { type ReceiptReviewProps } from '@/types/receipt';
import { cilCloudDownload, cilExternalLink, cilReload, cilZoomIn, cilZoomOut } from '@coreui/icons';
import { useState } from 'react';

const MIN_ZOOM = 0.5;
const MAX_ZOOM = 3;
const ZOOM_STEP = 0.25;

function ToolButton({ icon, label, onClick, disabled }: { icon: string[]; label: string; onClick: () => void; disabled?: boolean }) {
    return (
        <button type="button" className="btn btn-ghost btn-sm btn-square" onClick={onClick} disabled={disabled} aria-label={label} title={label}>
            <Icon icon={icon} size={14} />
        </button>
    );
}

export function ReceiptViewer({ receipt }: Pick<ReceiptReviewProps, 'receipt'>) {
    const isPdf = receipt.file.kind === 'pdf';
    const [zoom, setZoom] = useState(1);
    const [rotation, setRotation] = useState(0);
    const fromCorner = zoom > 1 && rotation === 0;

    return (
        <section className="bg-base-100 border-line shadow-card flex flex-col gap-3 rounded-2xl border p-3" aria-label="Comprobante">
            <div className="flex flex-wrap items-center gap-1.5 px-1 pt-1">
                <span className="text-ink-2 me-auto text-[13px] font-semibold">{isPdf ? 'Comprobante en PDF' : 'Captura del comprobante'}</span>
                {!isPdf ? (
                    <>
                        <ToolButton
                            icon={cilZoomOut}
                            label="Alejar"
                            disabled={zoom <= MIN_ZOOM}
                            onClick={() => setZoom((z) => Math.max(MIN_ZOOM, z - ZOOM_STEP))}
                        />
                        <span className="text-ink-3 w-10 text-center text-xs tabular-nums" aria-live="polite">
                            {Math.round(zoom * 100)} %
                        </span>
                        <ToolButton
                            icon={cilZoomIn}
                            label="Acercar"
                            disabled={zoom >= MAX_ZOOM}
                            onClick={() => setZoom((z) => Math.min(MAX_ZOOM, z + ZOOM_STEP))}
                        />
                        <ToolButton icon={cilReload} label="Girar" onClick={() => setRotation((r) => (r + 90) % 360)} />
                    </>
                ) : null}
                <a href={receipt.file.url} download className="btn btn-ghost btn-sm btn-square" aria-label="Descargar" title="Descargar">
                    <Icon icon={cilCloudDownload} size={14} />
                </a>
                <a
                    href={receipt.file.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="btn btn-ghost btn-sm btn-square"
                    aria-label="Abrir el archivo completo"
                    title="Abrir el archivo completo"
                >
                    <Icon icon={cilExternalLink} size={14} />
                </a>
            </div>
            <div
                className={`bg-base-300 flex max-h-[36rem] min-h-80 overflow-auto rounded-[14px] p-4 ${fromCorner ? 'items-start justify-start' : 'items-center justify-center'}`}
            >
                {isPdf ? (
                    <object
                        data={receipt.file.url}
                        type="application/pdf"
                        className="h-[30rem] w-full rounded-[10px]"
                        aria-label="Comprobante en PDF"
                    />
                ) : (
                    <img
                        src={receipt.file.url}
                        alt="Captura del comprobante"
                        className="shadow-pop max-h-[32rem] rounded-[10px] object-contain transition-transform"
                        style={{ transform: `scale(${zoom}) rotate(${rotation}deg)`, transformOrigin: fromCorner ? 'top left' : 'center' }}
                    />
                )}
            </div>
        </section>
    );
}
