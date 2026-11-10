import { JsonRequestError, postForm, postJson } from '@/lib/json';
import { bo } from '@/lib/surfaces';
import { type ConfirmedCopy, type CopyAnswer, type CopywritingMode, type HeldCopy, type SuggestedCopy } from '@/types/product';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export const COPYWRITER_URL = bo('/productos/ia/sugerencia');

export const CONFIRM_URL = bo('/productos/ia/sugerencia/confirmar');

export const MAX_COPYWRITER_PHOTOS = 4;

const OFFLINE = 'No pudimos hablar con la IA. Revisa tu conexión e intenta de nuevo.';

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
    const [pending, setPending] = useState<CopywritingMode | 'confirm' | null>(null);
    const [suggestion, setSuggestion] = useState<SuggestedCopy | null>(null);
    const [held, setHeld] = useState<{ copy: HeldCopy; productId: number | null } | null>(null);
    const [reviewPass, setReviewPass] = useState<string | null>(null);
    const [remaining, setRemaining] = useState<number | null>(initialRemaining);
    const [error, setError] = useState<string | null>(null);

    const fail = (failure: unknown) => setError(failure instanceof JsonRequestError ? failure.message : OFFLINE);

    const request = async (mode: CopywritingMode, input: CopywriterInput) => {
        setPending(mode);
        setError(null);

        try {
            const result = await postForm<CopyAnswer>(COPYWRITER_URL, formFor(mode, input));

            setRemaining(result.remaining);

            if ('held' in result) {
                setHeld({ copy: result.held, productId: input.productId });
            } else {
                setSuggestion(result);
            }
        } catch (failure) {
            if (failure instanceof JsonRequestError && failure.status === 429) {
                setRemaining(0);
            }

            fail(failure);
        } finally {
            setPending(null);
        }
    };

    const confirm = async () => {
        if (held === null) {
            return;
        }

        setPending('confirm');
        setError(null);

        try {
            const confirmed = await postJson<ConfirmedCopy>(CONFIRM_URL, { token: held.copy.token });

            setSuggestion(confirmed);
            setReviewPass(confirmed.review_pass);

            if (held.productId !== null) {
                router.reload({ only: ['product'] });
            }
        } catch (failure) {
            fail(failure);
        } finally {
            setHeld(null);
            setPending(null);
        }
    };

    const dismiss = () => {
        setSuggestion(null);
        setHeld(null);
    };

    return { pending, suggestion, held: held?.copy ?? null, reviewPass, remaining, error, request, confirm, dismiss };
}
