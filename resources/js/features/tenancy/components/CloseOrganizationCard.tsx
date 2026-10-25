import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

interface CloseOrganizationCardProps {
    readonly closeUrl: string;
}

export function CloseOrganizationCard({ closeUrl }: CloseOrganizationCardProps) {
    const form = useForm<{ confirmed: boolean }>({ confirmed: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(closeUrl, { preserveScroll: true });
    };

    const errors = form.errors as Partial<Record<'close' | 'confirmed', string>>;

    return (
        <section className="bg-error-soft border-error/25 rounded-2xl border" aria-labelledby="close-organization">
            <form className="flex flex-col gap-3 p-5" onSubmit={submit}>
                <h2 id="close-organization" className="text-error text-base font-bold">
                    Cerrar el negocio
                </h2>

                <p className="text-sm">
                    Tu equipo dejará de poder entrar y tus catálogos dejarán de mostrarse. Tus datos se conservan y tus clientes podrán seguir viendo
                    su historial. Antes tienes que completar o cancelar los apartados en curso.
                </p>

                {errors.close !== undefined ? (
                    <p role="alert" className="text-error text-sm">
                        {errors.close}
                    </p>
                ) : null}

                <label className="label cursor-pointer justify-start gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="checkbox checkbox-sm"
                        checked={form.data.confirmed}
                        onChange={(event) => form.setData('confirmed', event.target.checked)}
                    />
                    Entiendo que mi equipo pierde el acceso al cerrar
                </label>

                {errors.confirmed !== undefined ? <p className="text-error text-sm">{errors.confirmed}</p> : null}

                <div>
                    <button type="submit" className="btn btn-error btn-sm" disabled={!form.data.confirmed || form.processing}>
                        Cerrar el negocio
                    </button>
                </div>
            </form>
        </section>
    );
}
