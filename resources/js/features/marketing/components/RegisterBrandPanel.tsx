import { BrandMark } from '@/components/shell/brand';
import { Icon } from '@/components/ui/icon';
import { cilCheckCircle } from '@coreui/icons';
import { Link } from '@inertiajs/react';

const POINTS = ['Tus clientes apartan solos desde el enlace', 'Ves quién pagó, quién debe y cuánto', 'Recordatorios automáticos de cada cuota'];

export function RegisterBrandPanel() {
    return (
        <aside className="bg-primary text-primary-content hidden w-[600px] shrink-0 flex-col gap-7 px-16 py-12 lg:flex">
            <Link href={route('home')} className="inline-flex items-center gap-2.5 self-start" aria-label="Apartar, inicio">
                <BrandMark className="text-primary size-[34px] rounded-[10px] bg-white text-[21px]" />
                <span className="text-xl font-extrabold tracking-[-0.6px]">apartar</span>
            </Link>

            <div className="my-auto flex max-w-[470px] flex-col gap-5">
                <p className="text-[38px] leading-[1.1] font-extrabold tracking-[-0.04em]">Vende en cuotas sin perseguir a nadie por WhatsApp</p>
                <ul className="flex flex-col gap-5 text-base opacity-90">
                    {POINTS.map((point) => (
                        <li key={point} className="flex items-center gap-2.5">
                            <Icon icon={cilCheckCircle} size={18} />
                            {point}
                        </li>
                    ))}
                </ul>
            </div>
        </aside>
    );
}
