import { Alert } from '@/components/ui/alert';
import MarketingLayout from '@/layouts/marketing-layout';
import { cn } from '@/lib/utils';
import { type LegalProps } from '@/types/marketing';
import { Link } from '@inertiajs/react';

const DOCUMENTS = [
    { key: 'terms', label: 'Términos', route: 'legal.terms' },
    { key: 'privacy', label: 'Privacidad', route: 'legal.privacy' },
] as const;

export default function Legal({ document }: LegalProps) {
    return (
        <MarketingLayout title={document.title}>
            <article className="mx-auto flex max-w-[880px] flex-col gap-[18px] px-4 pt-6 pb-16 lg:px-6 lg:pt-12 lg:pb-20">
                <nav aria-label="Documentos legales" className="bg-surface-2 flex gap-0.5 self-start rounded-[10px] p-[3px]">
                    {DOCUMENTS.map((item) => {
                        const current = item.key === document.key;

                        return (
                            <Link
                                key={item.key}
                                href={route(item.route)}
                                aria-current={current ? 'page' : undefined}
                                className={cn(
                                    'rounded-lg px-3.5 py-2 text-[13px]',
                                    current ? 'bg-base-100 shadow-card font-bold' : 'text-ink-3 hover:text-base-content font-medium',
                                )}
                            >
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>

                <h1 className="text-[32px] leading-tight font-extrabold tracking-[-0.04em] lg:text-[44px]">{document.title}</h1>

                {!document.drafted ? (
                    <>
                        <Alert tone="warning" title="Documento pendiente de redacción">
                            Esta página todavía no contiene el texto de este documento y no tiene ningún valor legal. No aceptes nada en su nombre.
                        </Alert>

                        <section className="flex flex-col gap-3">
                            <h2 className="text-xl font-bold">Qué tendrá que cubrir</h2>
                            <ol className="text-ink-2 flex list-inside list-decimal flex-col gap-2 text-[15px] leading-[1.7]">
                                {document.mustCover.map((item) => (
                                    <li key={item}>{item}</li>
                                ))}
                            </ol>
                        </section>
                    </>
                ) : null}
            </article>
        </MarketingLayout>
    );
}
