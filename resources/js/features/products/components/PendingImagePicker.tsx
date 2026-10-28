import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { IMAGE_TYPES, type PendingImages } from '@/features/products/hooks/usePendingImages';
import { cn } from '@/lib/utils';
import { cilCloudUpload, cilTrash } from '@coreui/icons';
import { type ChangeEventHandler } from 'react';

interface PendingImagePickerProps {
    photos: PendingImages;
    errors: Partial<Record<string, string>>;
}

function serverError(errors: Partial<Record<string, string>>): string | undefined {
    return errors.images ?? Object.entries(errors).find(([field]) => field.startsWith('images.'))?.[1];
}

export function PendingImagePicker({ photos, errors }: PendingImagePickerProps) {
    const remaining = photos.max - photos.images.length;
    const full = remaining <= 0;
    const error = photos.error ?? serverError(errors);

    const choose: ChangeEventHandler<HTMLInputElement> = (event) => {
        photos.add(Array.from(event.target.files ?? []));
        event.target.value = '';
    };

    return (
        <SectionCard title="Fotos" subtitle="La primera es la principal. Se guardan al crear el producto.">
            <label
                className={cn(
                    'border-line-strong text-ink-2 focus-within:border-primary flex flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed px-4 py-5 text-center',
                    full ? 'cursor-not-allowed opacity-60' : 'hover:border-primary cursor-pointer',
                )}
            >
                <span className="bg-primary-soft text-primary-ink inline-flex size-9 items-center justify-center rounded-full">
                    <Icon icon={cilCloudUpload} size={18} />
                </span>
                <span className="text-sm font-semibold">Elegir fotos</span>
                <input
                    type="file"
                    className="sr-only"
                    accept={IMAGE_TYPES.join(',')}
                    multiple
                    disabled={full}
                    onChange={choose}
                    aria-label="Elegir fotos"
                />
            </label>

            <p className="text-ink-3 text-xs">
                {full
                    ? 'Ya elegiste el máximo de fotos. Quita alguna para cambiarla.'
                    : `Puedes agregar ${remaining} ${remaining === 1 ? 'foto más' : 'fotos más'}. JPG, PNG o WebP, hasta 8 MB cada una. La IA también las mira para sugerir la descripción.`}
            </p>

            {error ? (
                <p role="alert" className="label text-error text-xs">
                    {error}
                </p>
            ) : null}

            {photos.images.length > 0 ? (
                <ul className="flex flex-wrap gap-3">
                    {photos.images.map((image, index) => (
                        <li key={image.key} className="relative flex w-24 flex-col gap-1">
                            <img src={image.src} alt={`Foto ${index + 1}`} className="size-24 rounded-xl object-cover" />
                            {index === 0 ? (
                                <Badge tone="primary" className="absolute top-1 left-1">
                                    Principal
                                </Badge>
                            ) : null}
                            <span className="text-ink-3 truncate text-xs" title={image.file.name}>
                                {image.file.name}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => photos.remove(image.key)}
                                aria-label={`Quitar la foto ${index + 1}`}
                                className="btn-square bg-base-100/90 hover:text-error absolute top-1 right-1"
                            >
                                <Icon icon={cilTrash} />
                            </Button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </SectionCard>
    );
}
