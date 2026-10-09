import { Alert } from '@/components/ui/alert';
import { TextField } from '@/components/ui/text-field';
import { FILTER_DEBOUNCE_MS } from '@/features/data-table/hooks/useDebouncedFilter';
import { type CustomerShowProps, type MergeCandidate } from '@/types/customers';
import { useForm } from '@inertiajs/react';
import { type FormEvent, useEffect, useState } from 'react';

/**
 * Fusionar otra ficha en esta (`REQ-IDN-006`): buscar la duplicada, ver qué se
 * movería y confirmar **escribiendo su documento** (RB-8): no se puede
 * deshacer, así que quien confirma demuestra que sabe cuál se va.
 *
 * Una pareja vinculada a dos cuentas distintas no se ofrece (RB-4): fusionarlas
 * dejaría a una persona sin acceso a su deuda.
 */
export function MergeCustomerDialog({ merge }: { merge: CustomerShowProps['merge'] }) {
    const [term, setTerm] = useState('');
    const [candidates, setCandidates] = useState<readonly MergeCandidate[]>([]);
    const [chosen, setChosen] = useState<MergeCandidate | null>(null);
    const form = useForm<{ absorbed: number | null; confirmation: string }>({ absorbed: null, confirmation: '' });

    useEffect(() => {
        if (term.trim() === '') {
            return;
        }

        const timer = window.setTimeout(() => {
            void fetch(`${merge.candidatesUrl}?${new URLSearchParams({ q: term }).toString()}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? (response.json() as Promise<{ candidates: MergeCandidate[] }>) : { candidates: [] }))
                .then((body) => setCandidates(body.candidates));
        }, FILTER_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [term, merge.candidatesUrl]);

    const choose = (candidate: MergeCandidate) => {
        setChosen(candidate);
        form.setData({ absorbed: candidate.id, confirmation: '' });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(merge.url, { preserveScroll: true });
    };

    return (
        <div className="flex flex-col gap-3">
            <TextField
                label="Buscar la ficha duplicada"
                name="merge-search"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                hint="Cédula, teléfono, correo o nombre."
            />

            {term.trim() !== '' && candidates.length === 0 ? <p className="text-sm opacity-70">Ninguna otra ficha coincide.</p> : null}

            <ul className="flex flex-col gap-2" aria-label="Fichas candidatas">
                {candidates.map((candidate) => (
                    <li key={candidate.id}>
                        <button
                            type="button"
                            className={`btn btn-sm h-auto w-full justify-start py-2 text-left ${chosen?.id === candidate.id ? 'btn-primary' : 'btn-outline'}`}
                            disabled={candidate.blocked}
                            onClick={() => choose(candidate)}
                        >
                            <span className="flex flex-col">
                                <span>
                                    {candidate.name} · {candidate.document}
                                </span>
                                <span className="text-xs opacity-80">
                                    {candidate.blocked
                                        ? 'Vinculada a otra cuenta: no se puede fusionar.'
                                        : `Movería ${candidate.moves.layaways} apartados y ${candidate.moves.receipts} comprobantes.`}
                                </span>
                            </span>
                        </button>
                    </li>
                ))}
            </ul>

            {chosen !== null ? (
                <form onSubmit={submit} className="flex flex-col gap-2" aria-label="Confirmar la fusión">
                    <Alert tone="warning">La ficha de {chosen.name} desaparecerá y todo lo suyo pasará a esta. No se puede deshacer.</Alert>
                    <TextField
                        label={`Para confirmar, escribe el documento ${chosen.document}`}
                        name="confirmation"
                        value={form.data.confirmation}
                        onChange={(event) => form.setData('confirmation', event.target.value)}
                        error={form.errors.confirmation}
                    />
                    <button type="submit" className="btn btn-error" disabled={form.processing || form.data.confirmation.trim() === ''}>
                        Fusionar las fichas
                    </button>
                </form>
            ) : null}
        </div>
    );
}
