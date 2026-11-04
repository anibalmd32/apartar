import { ThemeToggle } from '@/components/shell/theme-toggle';
import { Alert } from '@/components/ui/alert';
import { Icon } from '@/components/ui/icon';
import { CodeAccess, LinkAccess } from '@/features/account/components/PortalAccessForms';
import { BoardBrand } from '@/features/marketing/board/BoardBrand';
import { cn } from '@/lib/utils';
import { type PortalAccessProps } from '@/types/portal';
import { cilLifeRing } from '@coreui/icons';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

type Way = 'code' | 'link';

const WAYS: { value: Way; label: string }[] = [
    { value: 'code', label: 'Con código' },
    { value: 'link', label: 'Por correo' },
];

function GoogleAccess({ googleUrl, secondary }: { googleUrl: string; secondary: boolean }) {
    return (
        <div className="flex flex-col gap-2">
            <a href={googleUrl} className={cn('btn btn-block', secondary ? 'btn-ghost border-line border' : 'btn-lg')}>
                <span aria-hidden="true" className="text-base font-extrabold text-[#4285f4]">
                    G
                </span>
                Entrar con Google
            </a>
            <p className="text-ink-3 text-xs">Ves juntos tus apartados de todos los negocios y lo nuevo de los catálogos que sigues.</p>
        </div>
    );
}

export default function Access({ status, code = null, businessName = null, googleUrl }: PortalAccessProps) {
    const [way, setWay] = useState<Way>(status ? 'link' : 'code');
    const paying = code !== null && code !== '';

    return (
        <main className="bg-base-200 flex min-h-screen flex-col items-center px-4 pt-6 pb-10 sm:pt-14">
            <Head title="Consulta tu apartado" />

            <div className="flex w-full max-w-[460px] flex-col gap-5">
                <div className="flex items-center justify-between gap-3">
                    {paying && businessName !== null ? (
                        <p className="text-[1.25rem] font-extrabold [font-stretch:90%]">{businessName}</p>
                    ) : (
                        <a href={route('home')} aria-label="Apartar, ir al inicio" className="touch:min-h-11 inline-flex items-center">
                            <BoardBrand />
                        </a>
                    )}
                    <ThemeToggle />
                </div>

                <header className="flex flex-col gap-2">
                    {paying && businessName !== null ? (
                        <p className="text-ink-2 text-[0.875rem] font-semibold">Tu apartado en {businessName}</p>
                    ) : null}
                    <h1 className="text-[1.875rem] leading-tight font-extrabold [font-stretch:88%] sm:text-[2.25rem]">
                        {code ? 'Paga tu apartado' : 'Mira tus apartados'}
                    </h1>
                    <p className="text-ink-2 text-[15px] leading-normal">
                        {code
                            ? `Escribe tu cédula para seguir: el código ${code} ya va puesto. Luego ves cuánto pagar, a dónde, y subes el comprobante.`
                            : 'Consulta tus pagos, tus cuotas y sube comprobantes de cualquier negocio donde hayas apartado.'}
                    </p>
                </header>

                {status ? <Alert tone="success">{status}</Alert> : null}

                <section className="border-line bg-base-100 flex flex-col gap-3.5 rounded-xl border p-5">
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
                                    'touch:h-11 h-[34px] rounded-lg px-2 text-[13px] transition-colors',
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
                        <CodeAccess initialCode={code} />
                    </div>
                    <div role="tabpanel" id="acceso-link-panel" aria-labelledby="acceso-link" hidden={way !== 'link'}>
                        <LinkAccess />
                    </div>
                </section>

                <div className="divider text-ink-3 my-0 text-xs">o, si tienes cuenta de Google</div>
                <GoogleAccess googleUrl={googleUrl} secondary />

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
