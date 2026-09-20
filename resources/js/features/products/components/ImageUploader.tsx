import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { type ChangeEventHandler, useRef, useState } from 'react';

/**
 * La subida de fotos, con vista previa local y progreso por archivo
 * (`REQ-BOF-022` RB-5).
 *
 * ## La vista previa es local, y por eso aparece al instante
 *
 * `URL.createObjectURL()` sobre el archivo que ya está en el navegador: no hay
 * que esperar a que suba nada para ver qué se eligió. Es lo que convierte
 * «arrastra cuatro fotos» en una operación revisable antes de enviarla —se ve
 * la que sobra y se quita— en vez de una apuesta.
 *
 * Cada `objectURL` se revoca al soltar la selección. Sin eso, cada tanda deja
 * en memoria los ficheros completos y una sesión de trabajo con veinte fotos
 * de 8 MB acaba en un navegador que se arrastra.
 *
 * ## Y el progreso es de la petición, no de cada archivo por separado
 *
 * Inertia informa del progreso de **la subida entera**, que es una sola
 * petición con todos los archivos. Es lo que hay sin partir la tanda en N
 * peticiones, y partirla tendría un coste peor que la barra fina: cada archivo
 * llevaría su propia validación del límite de 10 y dos tandas simultáneas
 * podrían pasarse entre las dos.
 *
 * Lo que sí es por archivo es **el fallo**: SP-5 dice que una subida
 * interrumpida no persiste nada de ese archivo y los ya subidos permanecen.
 * Eso lo garantiza el servidor —cada archivo es su unidad de trabajo, sin una
 * transacción que los envuelva— y aquí se refleja recargando la galería con lo
 * que de verdad quedó guardado.
 */

interface ImageUploaderProps {
    /** A dónde se suben: `/backoffice/productos/{id}/imagenes`. */
    url: string;
    /** Cuántas caben todavía; cero deshabilita la subida con su motivo (SP-1). */
    remaining: number;
    /** Error del servidor sobre la tanda, si lo hubo. */
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
            <input
                ref={input}
                type="file"
                name="images"
                className="file-input file-input-sm w-full"
                accept="image/jpeg,image/png,image/webp"
                multiple
                disabled={full}
                onChange={choose}
                aria-label="Elegir imágenes"
            />

            <p className="text-xs opacity-70">
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
                                {/* La vista previa es del archivo local: no
                                    describe nada que el catálogo vaya a
                                    mostrar todavía, así que su `alt` es el
                                    nombre del fichero y no un texto inventado. */}
                                <img src={preview.src} alt={preview.name} className="rounded-box h-24 w-24 object-cover" />
                                <span className="truncate text-xs opacity-70" title={preview.name}>
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
