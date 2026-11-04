import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilArrowTop } from '@coreui/icons';
import { useEffect, useState } from 'react';

const THRESHOLD = 600;

export function BackToTop() {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const update = () => setVisible(window.scrollY > THRESHOLD);

        update();
        window.addEventListener('scroll', update, { passive: true });

        return () => window.removeEventListener('scroll', update);
    }, []);

    return (
        <button
            type="button"
            onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
            aria-label="Volver arriba"
            tabIndex={visible ? 0 : -1}
            aria-hidden={visible ? undefined : true}
            className={cn(
                'bg-pz-board text-pz-ink fixed right-3 bottom-[calc(var(--consent-height,0px)+1rem+env(safe-area-inset-bottom))] z-30 flex size-12 cursor-pointer items-center justify-center rounded-md shadow-[0_10px_24px_-10px_rgb(0_0_0/0.35),inset_0_0_0_2px_var(--pz-ink)] transition-[opacity,transform] duration-200 lg:right-8 lg:bottom-8',
                visible ? 'opacity-100' : 'pointer-events-none translate-y-2 opacity-0',
            )}
        >
            <Icon icon={cilArrowTop} size={18} />
        </button>
    );
}
