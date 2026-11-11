import { cn } from '@/lib/utils';
import { cilCloudDownload, cilReload, cilZoomIn, cilZoomOut } from '@coreui/icons';
import { useState } from 'react';
import { Icon } from './icon';

export type FileKind = 'image' | 'pdf';

const MIN_ZOOM = 0.5;
const MAX_ZOOM = 3;
const ZOOM_STEP = 0.25;

interface FileStageProps {
    url: string;
    label: string;
    kind?: FileKind | null;
    heading?: string;
    className?: string;
    stageClassName?: string;
}

function ToolButton({ icon, label, onClick, disabled }: { icon: string[]; label: string; onClick: () => void; disabled?: boolean }) {
    return (
        <button
            type="button"
            className="btn btn-ghost btn-sm btn-square max-lg:size-11"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
        >
            <Icon icon={icon} size={14} />
        </button>
    );
}

export function FileStage({ url, label, kind = null, heading, className, stageClassName }: FileStageProps) {
    const [detected, setDetected] = useState<FileKind>(kind ?? 'image');
    const [zoom, setZoom] = useState(1);
    const [rotation, setRotation] = useState(0);
    const isPdf = (kind ?? detected) === 'pdf';
    const fromCorner = zoom > 1 && rotation === 0;

    return (
        <div className={cn('flex min-h-0 flex-col gap-3', className)}>
            <div className="flex flex-wrap items-center gap-1.5 px-1 max-lg:justify-between max-lg:gap-0">
                <span className="text-ink-2 me-auto text-[13px] font-semibold max-lg:sr-only">
                    {heading ?? (isPdf ? 'Archivo en PDF' : 'Imagen')}
                </span>
                {!isPdf ? (
                    <>
                        <ToolButton
                            icon={cilZoomOut}
                            label="Alejar"
                            disabled={zoom <= MIN_ZOOM}
                            onClick={() => setZoom((current) => Math.max(MIN_ZOOM, current - ZOOM_STEP))}
                        />
                        <span className="text-ink-3 w-10 text-center text-xs tabular-nums" aria-live="polite">
                            {Math.round(zoom * 100)} %
                        </span>
                        <ToolButton
                            icon={cilZoomIn}
                            label="Acercar"
                            disabled={zoom >= MAX_ZOOM}
                            onClick={() => setZoom((current) => Math.min(MAX_ZOOM, current + ZOOM_STEP))}
                        />
                        <ToolButton icon={cilReload} label="Girar" onClick={() => setRotation((current) => (current + 90) % 360)} />
                    </>
                ) : null}
                <a href={url} download className="btn btn-ghost btn-sm btn-square max-lg:size-11" aria-label="Descargar" title="Descargar">
                    <Icon icon={cilCloudDownload} size={14} />
                </a>
            </div>
            <div
                className={cn(
                    'bg-base-300 flex min-h-80 overflow-auto rounded-[14px] p-4',
                    fromCorner ? 'items-start justify-start' : 'items-center justify-center',
                    stageClassName,
                )}
            >
                {isPdf ? (
                    <object data={url} type="application/pdf" className="h-[70dvh] w-full rounded-[10px]" aria-label={label}>
                        <a href={url} download className="btn btn-sm">
                            Descargar el PDF
                        </a>
                    </object>
                ) : (
                    <img
                        src={url}
                        alt={label}
                        onError={() => (kind === null ? setDetected('pdf') : undefined)}
                        className="shadow-pop max-h-[70dvh] rounded-[10px] object-contain transition-transform"
                        style={{ transform: `scale(${zoom}) rotate(${rotation}deg)`, transformOrigin: fromCorner ? 'top left' : 'center' }}
                    />
                )}
            </div>
        </div>
    );
}
