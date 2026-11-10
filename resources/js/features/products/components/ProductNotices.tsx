import { Alert } from '@/components/ui/alert';
import { type ProductFormValues } from '@/types/product';

interface ProductNoticesProps {
    product: ProductFormValues | null;
    reviewRequested: boolean;
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

            {review?.status === 'rejected' ? (
                <Alert tone="error" title="Apartar rechazó las fotos">
                    {review.rejectionReason ?? 'Las fotos no muestran un producto que se pueda vender.'} Cambia las fotos para poder publicarlo.
                </Alert>
            ) : null}
        </>
    );
}
