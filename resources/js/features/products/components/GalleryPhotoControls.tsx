import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { TextField } from '@/components/ui/text-field';
import { type GalleryEntry } from '@/features/products/hooks/useGalleryDraft';
import { cilArrowLeft, cilArrowRight, cilStar, cilTrash } from '@coreui/icons';

interface GalleryPhotoControlsProps {
    entry: GalleryEntry;
    index: number;
    count: number;
    error?: string;
    onMove: (from: number, to: number) => void;
    onMakeMain: () => void;
    onDescribe: (alt: string) => void;
    onRemove: () => void;
}

const ACTION = 'touch:min-h-11 touch:min-w-11';

export function GalleryPhotoControls({ entry, index, count, error, onMove, onMakeMain, onDescribe, onRemove }: GalleryPhotoControlsProps) {
    const label = `la imagen ${index + 1}`;

    return (
        <div className="bg-base-200/60 border-line flex flex-col gap-3 rounded-xl border p-3" aria-label={`Ajustes de ${label}`} role="group">
            <div className="flex flex-wrap items-center gap-1">
                <span className="text-ink-2 mr-auto text-sm font-semibold">
                    Imagen {index + 1} de {count}
                </span>

                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    className={`${ACTION} btn-square`}
                    onClick={() => onMove(index, index - 1)}
                    disabled={index === 0}
                    aria-label={`Mover antes ${label}`}
                >
                    <Icon icon={cilArrowLeft} />
                </Button>

                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    className={`${ACTION} btn-square`}
                    onClick={() => onMove(index, index + 1)}
                    disabled={index === count - 1}
                    aria-label={`Mover después ${label}`}
                >
                    <Icon icon={cilArrowRight} />
                </Button>

                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    className={`${ACTION} btn-square text-ink-2 hover:text-error`}
                    onClick={onRemove}
                    aria-label={`Quitar ${label}`}
                >
                    <Icon icon={cilTrash} />
                </Button>
            </div>

            {index === 0 ? null : (
                <Button type="button" size="sm" variant="soft" className={ACTION} onClick={onMakeMain}>
                    <Icon icon={cilStar} />
                    Hacer principal
                </Button>
            )}

            <TextField
                label="Texto alternativo"
                name={`gallery[${index}][alt]`}
                value={entry.alt}
                onChange={(event) => onDescribe(event.target.value)}
                error={error}
                hint="Lo que lee quien no ve la imagen. Si lo dejas vacío, usamos uno por defecto."
                maxLength={255}
                autoComplete="off"
            />
        </div>
    );
}
