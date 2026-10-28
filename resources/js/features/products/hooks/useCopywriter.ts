import { JsonRequestError, postForm } from '@/lib/json';
import { type CopySuggestion, type CopywritingMode } from '@/types/product';
import { useState } from 'react';

export const COPYWRITER_URL = '/backoffice/productos/ia/sugerencia';

export const MAX_COPYWRITER_PHOTOS = 4;

export interface CopywriterInput {
    readonly name: string;
    readonly categoryName: string;
    readonly description: string;
    readonly photos: readonly File[];
    readonly productId: number | null;
}

export function remainingLabel(remaining: number): string {
    return remaining === 1 ? 'Te queda 1 sugerencia hoy' : `Te quedan ${remaining} sugerencias hoy`;
}

function formFor(mode: CopywritingMode, input: CopywriterInput): FormData {
    const body = new FormData();

    body.append('mode', mode);
    body.append('name', input.name);
    body.append('category_name', input.categoryName);
    body.append('description', input.description);

    if (input.productId !== null) {
        body.append('product_id', String(input.productId));
    }

    input.photos.slice(0, MAX_COPYWRITER_PHOTOS).forEach((photo) => body.append('images[]', photo));

    return body;
}

export function useCopywriter(initialRemaining: number | null) {
    const [pending, setPending] = useState<CopywritingMode | null>(null);
    const [suggestion, setSuggestion] = useState<CopySuggestion | null>(null);
    const [remaining, setRemaining] = useState<number | null>(initialRemaining);
    const [error, setError] = useState<string | null>(null);

    const request = async (mode: CopywritingMode, input: CopywriterInput) => {
        setPending(mode);
        setError(null);

        try {
            const result = await postForm<CopySuggestion>(COPYWRITER_URL, formFor(mode, input));

            setSuggestion(result);
            setRemaining(result.remaining);
        } catch (failure) {
            if (failure instanceof JsonRequestError && failure.status === 429) {
                setRemaining(0);
            }

            setError(failure instanceof JsonRequestError ? failure.message : 'No pudimos hablar con la IA. Revisa tu conexión e intenta de nuevo.');
        } finally {
            setPending(null);
        }
    };

    return { pending, suggestion, remaining, error, request, dismiss: () => setSuggestion(null) };
}
