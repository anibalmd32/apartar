import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
 * ## Subir y bajar, no arrastrar
 *
 * La misma decisión que `T-026` tomó con las categorías y `T-028` con la ficha
 * técnica: el proyecto no tiene ninguna dependencia de *drag & drop* —llega
 * con `useSortableProducts` en `T-031`— y la API nativa no responde al dedo.
 * Lo que manda el formulario es **la lista entera en su orden**, así que
 * cambiar el gesto no toca el endpoint.
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

    const move = (index: number, direction: -1 | 1) => {
        setOrder((current) => {
            const next = [...current];
            const target = index + direction;

            const moved = next[index];
            const displaced = next[target];

            if (moved === undefined || displaced === undefined) {
                return current;
            }

            next[index] = displaced;
            next[target] = moved;

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
                        {order.map((entry, index) => {
                            const image = byId.get(entry.id);

                            if (image === undefined) {
                                return null;
                            }

                            return (
                                <li key={entry.id} className="border-base-300 grid gap-2 border-b pb-3 last:border-0 sm:grid-cols-12">
                                    <div className="sm:col-span-2">
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
                                            onClick={() => move(index, -1)}
                                            disabled={index === 0}
                                            aria-label={`Subir la imagen ${index + 1}`}
                                        >
                                            ↑
                                        </Button>

                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => move(index, 1)}
                                            disabled={index === order.length - 1}
                                            aria-label={`Bajar la imagen ${index + 1}`}
                                        >
                                            ↓
                                        </Button>

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
                            );
                        })}
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
