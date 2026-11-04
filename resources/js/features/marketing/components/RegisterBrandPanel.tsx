import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilCheckAlt } from '@coreui/icons';

const POINTS = ['Tus clientes apartan solos desde el enlace', 'Ves quién pagó, quién debe y cuánto', 'Recordatorios automáticos de cada cuota'];

export function RegisterBrandPanel({ className }: { className?: string }) {
    return (
        <aside className={cn('flex flex-col gap-6', className)}>
            <p className="font-vinyl max-w-[12ch] text-[4.5rem] max-lg:hidden">
                Vende en cuotas sin perseguir a nadie por <span className="text-pz-blue">WhatsApp</span>
            </p>
            <ul className="font-hand flex flex-col gap-1.5 text-xl lg:gap-2 lg:text-2xl">
                {POINTS.map((point) => (
                    <li key={point} className="flex items-start gap-3">
                        <Icon icon={cilCheckAlt} size={22} className="text-pz-green mt-1 shrink-0" />
                        {point}
                    </li>
                ))}
            </ul>
        </aside>
    );
}
