import { PreviewDialog } from '@/features/products/components/ProductPreview';
import { type PreviewSource } from '@/features/products/lib/preview';
import { getJson } from '@/lib/json';
import { type ReactNode, useState } from 'react';

/**
 * Opens the preview of a saved product, loading it from the server.
 */
export function useStoredProductPreview(productId: number): { open: () => void; dialog: ReactNode } {
    const [visible, setVisible] = useState(false);
    const [source, setSource] = useState<PreviewSource | null>(null);
    const [error, setError] = useState<string | null>(null);

    const open = () => {
        setVisible(true);
        setSource(null);
        setError(null);

        getJson<PreviewSource>(`/backoffice/productos/${productId}/vista-previa`)
            .then(setSource)
            .catch(() => setError('No pudimos cargar la vista previa. Intenta de nuevo.'));
    };

    const dialog = (
        <PreviewDialog
            open={visible}
            onClose={() => setVisible(false)}
            source={source}
            error={error}
            description="Con lo que tiene guardado ahora este producto."
        />
    );

    return { open, dialog };
}
