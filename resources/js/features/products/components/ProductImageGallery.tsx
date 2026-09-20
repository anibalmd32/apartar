import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SortableHandle, SortableList, SortableRow } from '@/components/ui/sortable';
import { TextField } from '@/components/ui/text-field';
import { ImageUploader } from '@/features/products/components/ImageUploader';
import { type ProductImageView } from '@/types/product';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * La galería del backoffice: subir, ordenar, describir y quitar
 * (`REQ-BOF-022`).
 *
 * ## La principal es la primera, y se ve que lo es
 *
 * RB-2: la imagen de la posición 1 es la de las cards del catálogo, la del
 * enlace que se comparte por WhatsApp y la del feed. No hay un botón de
 * «marcar como principal» porque no hay una bandera que pueda discrepar del
 * orden — moverla arriba **es** hacerla principal, y quitarla promueve a la
 * siguiente sola (RB-4).
 *
 * ## «Procesando» no significa «no se ve»
 *
 * RB-3: las conversiones se generan en cola y, mientras no existan, el
 * catálogo sirve el original. El distintivo dice qué está pasando para que el
 * negocio no crea que subió mal el archivo, pero la foto **ya está
 * funcionando**. Lo mismo con «revisar»: un job que falló (SP-6) deja la
 * imagen utilizable, y lo que se pide es una mirada, no una acción urgente.
 *
 * ## Se arrastra, desde `T-031`
 *
 * RB-5 pedía arrastre y hasta ahora eran dos botones de subir y bajar, por lo
 * mismo que en las categorías de `T-026` y la ficha técnica de `T-028`: no
 * había dependencia de *drag & drop* y la API nativa no responde al dedo. La
 * dependencia llegó y el gesto cambió; **el endpoint no se enteró**: el
 * `PATCH .../imagenes/orden` sigue recibiendo la lista entera con su `alt`,
 * que es lo que RB-6 puso en el mismo guardado.
 *
 * Y arrastrar la última foto hasta arriba **es** hacerla principal (RB-2),
 * como lo era pulsar «subir» cinco veces — solo que ahora es un movimiento.
 */

interface ProductImageGalleryProps {
    productId: number;
    images: readonly ProductImageView[];
    maxImages: number;
    errors: Partial<Record<string, string>>;
}

/**
 * Una imagen mientras se edita, con la forma que viaja en la petición.
 *
 * La firma de índice es lo que Inertia pide para serializar un objeto dentro
 * de una lista: sin ella el guardado no compila, y la alternativa —convertir
 * a mano al enviar— sería una segunda forma del mismo dato.
 */
interface EditableImage {
    [key: string]: number | string;
    id: number;
    alt: string;
}

export function ProductImageGallery({ productId, images, maxImages, errors }: ProductImageGalleryProps) {
    const url = `/backoffice/productos/${productId}/imagenes`;

    const [order, setOrder] = useState<EditableImage[]>(() => images.map((image) => ({ id: image.id, alt: image.set.alt })));

    // La galería vuelve del servidor tras subir, borrar o guardar: sin esto,
    // subir una foto dejaría la pantalla mostrando la lista de antes.
    useEffect(() => setOrder(images.map((image) => ({ id: image.id, alt: image.set.alt }))), [images]);

    const byId = new Map(images.map((image) => [image.id, image]));

    /**
     * Mueve una foto de una posición a otra.
     *
     * Desde `T-031` el gesto es un arrastre y no dos botones, así que se saca
     * y se vuelve a meter en lugar de intercambiar dos vecinas: arrastrar la
     * última hasta arriba es un solo movimiento, no *n* intercambios.
     */
    const move = (from: number, to: number) => {
        setOrder((current) => {
            const next = [...current];
            const [moved] = next.splice(from, 1);

            if (moved === undefined) {
                return current;
            }

            next.splice(to, 0, moved);

            return next;
        });
    };

    const describe = (id: number, alt: string) => {
        setOrder((current) => current.map((image) => (image.id === id ? { ...image, alt } : image)));
    };

    const save = () => router.patch(`${url}/orden`, { images: order }, { preserveScroll: true });

    const remove = (id: number) => router.delete(`${url}/${id}`, { preserveScroll: true });

    return (
        <section className="card bg-base-100 shadow-sm">
            <div className="card-body gap-4">
                <header className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="card-title text-base">Fotos</h2>

                    <Button type="button" size="sm" onClick={save} disabled={order.length === 0}>
                        Guardar galería
                    </Button>
                </header>

                <ImageUploader url={url} remaining={maxImages - images.length} error={errors.images} />

                {order.length === 0 ? (
                    <p className="text-sm opacity-70">
                        Todavía no tiene fotos. La primera que subas será la que vean tus clientes en el catálogo y en el enlace que compartas.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-3">
                        <SortableList
                            ids={order.map((entry) => entry.id)}
                            onReorder={move}
                            labelOf={(id) => `la imagen ${order.findIndex((entry) => entry.id === id) + 1}`}
                        >
                            {order.map((entry, index) => {
                                const image = byId.get(entry.id);

                                if (image === undefined) {
                                    return null;
                                }

                                return (
                                    <SortableRow key={entry.id} id={entry.id} label={`la imagen ${index + 1}`}>
                                        {({ containerProps, handleProps }) => (
                                            <li
                                                ref={containerProps.ref}
                                                style={containerProps.style}
                                                className={`border-base-300 grid gap-2 border-b pb-3 last:border-0 sm:grid-cols-12 ${containerProps.className ?? ''}`}
                                            >
                                                <div className="flex items-center gap-1 sm:col-span-2">
                                                    <SortableHandle handleProps={handleProps} />
                                                    {/* `loading="lazy"` · RB-3. Y el `src`
                                                        es `thumb`, que cae al original
                                                        mientras la conversión no exista:
                                                        nunca un hueco roto. */}
                                                    <img
                                                        src={image.set.thumb}
                                                        alt={image.set.alt}
                                                        loading="lazy"
                                                        className="rounded-box h-24 w-24 object-cover"
                                                    />
                                                </div>

                                                <div className="sm:col-span-6">
                                                    <TextField
                                                        label="Texto alternativo"
                                                        name={`images[${index}][alt]`}
                                                        value={entry.alt}
                                                        onChange={(event) => describe(entry.id, event.target.value)}
                                                        hint="Lo que lee quien no ve la imagen. Si lo dejas vacío, volvemos al texto por defecto."
                                                        maxLength={255}
                                                        autoComplete="off"
                                                    />
                                                </div>

                                                <div className="flex flex-wrap items-end gap-1 sm:col-span-4">
                                                    {index === 0 ? <Badge tone="success">Principal</Badge> : null}

                                                    {image.isProcessing ? <Badge tone="info">Procesando</Badge> : null}

                                                    {image.needsReview ? <Badge tone="warning">Revisar</Badge> : null}

                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => remove(entry.id)}
                                                        aria-label={`Quitar la imagen ${index + 1}`}
                                                    >
                                                        ✕
                                                    </Button>
                                                </div>
                                            </li>
                                        )}
                                    </SortableRow>
                                );
                            })}
                        </SortableList>
                    </ul>
                )}

                <p className="text-xs opacity-70">
                    La primera es la que se ve en el catálogo y en el enlace que compartes. Mientras aparezca «Procesando» se sirve la original, así
                    que tu ficha funciona igual.
                </p>
            </div>
        </section>
    );
}
