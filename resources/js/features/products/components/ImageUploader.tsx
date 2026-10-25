import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilCloudUpload } from '@coreui/icons';
import { router } from '@inertiajs/react';
import { type ChangeEventHandler, useRef, useState } from 'react';

interface ImageUploaderProps {
    url: string;
    remaining: number;
    error?: string;
}

interface Preview {
    readonly name: string;
    readonly src: string;
}

export function ImageUploader({ url, remaining, error }: ImageUploaderProps) {
    const input = useRef<HTMLInputElement>(null);

    const [previews, setPreviews] = useState<Preview[]>([]);
    const [progress, setProgress] = useState<number | null>(null);

    const release = (items: Preview[]) => items.forEach((preview) => URL.revokeObjectURL(preview.src));

    const clear = () => {
        setPreviews((current) => {
            release(current);

            return [];
        });

        if (input.current) {
            input.current.value = '';
        }
    };

    const choose: ChangeEventHandler<HTMLInputElement> = (event) => {
        const files = Array.from(event.target.files ?? []);

        setPreviews((current) => {
            release(current);

            return files.map((file) => ({ name: file.name, src: URL.createObjectURL(file) }));
        });
    };

    const upload = () => {
        const files = Array.from(input.current?.files ?? []);

        if (files.length === 0) {
            return;
        }

        router.post(
            url,
            { images: files },
            {
                forceFormData: true,
                preserveScroll: true,
                onProgress: (event) => setProgress(event?.percentage ?? null),
                onFinish: () => {
                    setProgress(null);
                    clear();
                },
            },
        );
    };

    const full = remaining <= 0;

    return (
        <div className="flex flex-col gap-2">
            <label
                className={cn(
                    'border-line-strong text-ink-2 focus-within:border-primary flex flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed px-4 py-5 text-center',
                    full ? 'cursor-not-allowed opacity-60' : 'hover:border-primary cursor-pointer',
                )}
            >
                <span className="bg-primary-soft text-primary-ink inline-flex size-9 items-center justify-center rounded-full">
                    <Icon icon={cilCloudUpload} size={18} />
                </span>
                <span className="text-sm font-semibold">Elegir imágenes</span>
                <input
                    ref={input}
                    type="file"
                    name="images"
                    className="sr-only"
                    accept="image/jpeg,image/png,image/webp"
                    multiple
                    disabled={full}
                    onChange={choose}
                    aria-label="Elegir imágenes"
                />
            </label>

            <p className="text-ink-3 text-xs">
                {full
                    ? 'Este producto ya tiene el máximo de imágenes. Quita alguna para subir otra.'
                    : `Puedes subir ${remaining} ${remaining === 1 ? 'imagen más' : 'imágenes más'}. JPG, PNG o WebP, hasta 8 MB cada una.`}
            </p>

            {error ? (
                <p role="alert" className="label text-error text-xs">
                    {error}
                </p>
            ) : null}

            {previews.length > 0 ? (
                <>
                    <ul className="flex flex-wrap gap-2">
                        {previews.map((preview) => (
                            <li key={preview.src} className="flex w-24 flex-col gap-1">
                                <img src={preview.src} alt={preview.name} className="size-24 rounded-xl object-cover" />
                                <span className="text-ink-3 truncate text-xs" title={preview.name}>
                                    {preview.name}
                                </span>
                            </li>
                        ))}
                    </ul>

                    {progress === null ? null : (
                        <progress className="progress progress-primary w-full" value={progress} max={100}>
                            {progress}%
                        </progress>
                    )}

                    <div className="flex gap-2">
                        <Button type="button" size="sm" onClick={upload} loading={progress !== null}>
                            Subir {previews.length === 1 ? 'la imagen' : `las ${previews.length} imágenes`}
                        </Button>

                        <Button type="button" size="sm" variant="ghost" onClick={clear} disabled={progress !== null}>
                            Quitar la selección
                        </Button>
                    </div>
                </>
            ) : null}
        </div>
    );
}
