import { BrandMark } from '@/components/shell/brand';
import { Alert } from '@/components/ui/alert';
import { Icon } from '@/components/ui/icon';
import { CodeAccess, LinkAccess } from '@/features/account/components/PortalAccessForms';
import { cn } from '@/lib/utils';
import { type PortalAccessProps } from '@/types/portal';
import { cilLifeRing } from '@coreui/icons';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

type Way = 'code' | 'link';

const WAYS: { value: Way; label: string }[] = [
    { value: 'code', label: 'Con mi código' },
    { value: 'link', label: 'Recibir enlace por correo' },
];

export default function Access({ status, googleUrl }: PortalAccessProps) {
    const [way, setWay] = useState<Way>(status ? 'link' : 'code');

    return (
        <main className="bg-base-200 flex min-h-screen flex-col items-center px-4 pt-6 pb-10 sm:pt-14">
            <Head title="Consulta tu apartado" />

            <div className="flex w-full max-w-[460px] flex-col gap-5">
                <span className="inline-flex items-center gap-2.5">
                    <BrandMark className="size-9 rounded-[10px] text-[22px]" />
                    <span className="text-[19px] font-extrabold tracking-[-0.6px]">apartar</span>
                </span>

                <header className="flex flex-col gap-2">
                    <h1 className="text-[26px] leading-tight font-extrabold tracking-[-1px] sm:text-[32px]">Mira tus apartados</h1>
                    <p className="text-ink-2 text-[15px] leading-normal">
                        Consulta tus pagos, tus cuotas y sube comprobantes de cualquier negocio donde hayas apartado.
                    </p>
                </header>

                {status ? <Alert tone="success">{status}</Alert> : null}

                <div className="flex flex-col gap-2">
                    <a href={googleUrl} className="btn btn-lg btn-block">
                        <span aria-hidden="true" className="text-base font-extrabold text-[#4285f4]">
                            G
                        </span>
                        Entrar con Google
                    </a>
                    <p className="text-ink-3 text-xs">Ves juntos tus apartados de todos los negocios y lo nuevo de los catálogos que sigues.</p>
                </div>

                <div className="divider text-ink-3 my-0 text-xs">o entra sin cuenta</div>

                <section className="border-line bg-base-100 shadow-card flex flex-col gap-3.5 rounded-2xl border p-5">
                    <div role="tablist" aria-label="Cómo quieres entrar" className="bg-surface-2 grid grid-cols-2 gap-0.5 rounded-[10px] p-[3px]">
                        {WAYS.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="tab"
                                id={`acceso-${option.value}`}
                                aria-selected={way === option.value}
                                aria-controls={`acceso-${option.value}-panel`}
                                onClick={() => setWay(option.value)}
                                className={cn(
                                    'pointer-coarse:h-11 h-[34px] rounded-lg px-2 text-[13px] transition-colors',
                                    way === option.value
                                        ? 'bg-base-100 dark:bg-base-300 shadow-card font-bold'
                                        : 'text-ink-3 hover:text-base-content font-medium',
                                )}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>

                    <div role="tabpanel" id="acceso-code-panel" aria-labelledby="acceso-code" hidden={way !== 'code'}>
                        <CodeAccess />
                    </div>
                    <div role="tabpanel" id="acceso-link-panel" aria-labelledby="acceso-link" hidden={way !== 'link'}>
                        <LinkAccess />
                    </div>
                </section>

                <p className="bg-surface-2 text-ink-2 flex gap-2 rounded-xl p-3.5 text-xs leading-normal">
                    <Icon icon={cilLifeRing} className="mt-px" />
                    <span>
                        ¿No encuentras el código? Está en tu confirmación y en el correo, si dejaste uno. Si no, pídeselo al negocio donde apartaste.
                    </span>
                </p>
            </div>
        </main>
    );
}
