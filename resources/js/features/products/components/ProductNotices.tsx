import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field-error';
import { bo } from '@/lib/surfaces';
import { type ProductFormValues } from '@/types/product';
import { useForm } from '@inertiajs/react';

interface ProductNoticesProps {
    product: ProductFormValues | null;
    reviewRequested: boolean;
}

function RejectedPhotos({ productId, reason }: { productId: number; reason: string | null }) {
    const again = useForm({ photo_review: '' });

    return (
        <Alert tone="error" title="Apartar rechazó las fotos">
            <div className="flex flex-col items-start gap-2">
                <p>
                    {reason ?? 'Las fotos no muestran un producto que se pueda vender.'} Cambia las fotos y pide otra revisión para poder publicarlo.
                </p>
                <Button
                    size="sm"
                    variant="secondary"
                    loading={again.processing}
                    onClick={() => again.post(bo(`/productos/${productId}/revision-fotos`), { preserveScroll: true })}
                >
                    Pedir otra revisión
                </Button>
                {again.errors.photo_review ? <FieldError>{again.errors.photo_review}</FieldError> : null}
            </div>
        </Alert>
    );
}

export function ProductNotices({ product, reviewRequested }: ProductNoticesProps) {
    const review = product?.photoReview ?? null;

    return (
        <>
            {product?.status === 'archived' ? (
                <Alert tone="warning">
                    Este producto está archivado: no se muestra en tus catálogos, pero conserva su sitio en ellos y su historial.
                </Alert>
            ) : null}

            {review?.status === 'pending' || (product === null && reviewRequested) ? (
                <Alert tone="info" title="Fotos en revisión">
                    Las fotos parecen mostrar {review?.subject ?? 'algo que no es un producto'}. El equipo de Apartar las está revisando: puedes
                    seguir editando y guardarlo como borrador, y podrás publicarlo cuando las apruebe.
                </Alert>
            ) : null}

            {product !== null && review?.status === 'rejected' ? <RejectedPhotos productId={product.id} reason={review.rejectionReason} /> : null}
        </>
    );
}
