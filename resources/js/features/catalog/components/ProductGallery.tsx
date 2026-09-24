import { cn } from '@/lib/utils';
import { type ImageSet } from '@/types/product';
import { useState } from 'react';

/**
 * Las fotos del producto en su ficha (`REQ-CAT-003`, `REQ-CAT-005`).
 *
 * La grande es el tamaño `zoom` y las miniaturas el `thumb`: los dos existen
 * ya en `ImageSet`, así que la página no descarga la foto grande de las que
 * nadie ha pulsado. La primera va sin `loading="lazy"` porque es lo primero
 * que se ve; las miniaturas sí.
 *
 * ## Sin fotos, la inicial (SP-4)
 *
 * Nunca una imagen rota: una foto rota en la pantalla donde se decide pagar
 * dice del negocio lo que no es verdad. Sin ninguna, un recuadro con la
 * inicial del nombre, que ocupa el mismo sitio y no parece un fallo de carga.
 */

interface ProductGalleryProps {
    images: readonly ImageSet[];
    name: string;
}

export function ProductGallery({ images, name }: ProductGalleryProps) {
    const [selected, setSelected] = useState(0);
    const current = images[selected] ?? images[0];

    if (current === undefined) {
        return (
            <div
                role="img"
                aria-label={name}
                className="bg-base-300 rounded-box flex aspect-square w-full items-center justify-center text-7xl font-bold opacity-60"
            >
                {name.trim().charAt(0).toUpperCase()}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <img src={current.zoom} alt={current.alt} className="rounded-box bg-base-200 aspect-square w-full object-contain" />

            {images.length > 1 ? (
                <ul className="flex gap-2 overflow-x-auto" aria-label="Fotos del producto">
                    {images.map((image, index) => (
                        <li key={image.thumb} className="shrink-0">
                            <button
                                type="button"
                                aria-label={`Ver la foto ${index + 1}`}
                                aria-pressed={index === selected}
                                className={cn('rounded-box overflow-hidden border-2', index === selected ? 'border-primary' : 'border-transparent')}
                                onClick={() => setSelected(index)}
                            >
                                <img src={image.thumb} alt="" loading="lazy" className="size-16 object-cover" />
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
