import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { type ReactNode } from 'react';

interface TableToolbarProps {
    children?: ReactNode;
    ignored: readonly string[];
    failed: boolean;
    onRetry: () => void;
}

export function TableToolbar({ children, ignored, failed, onRetry }: TableToolbarProps) {
    return (
        <div className="flex flex-col gap-3">
            {children}

            {ignored.length > 0 ? (
                <Alert tone="info">
                    {ignored.length === 1 ? 'Un filtro no se pudo aplicar' : 'Algunos filtros no se pudieron aplicar'} ({ignored.join(', ')}): su
                    valor ya no existe o no es de tu negocio. Se muestra el resto.
                </Alert>
            ) : null}

            {failed ? (
                <Alert tone="error">
                    <span>No se pudo actualizar la tabla. Lo que ves es lo de antes.</span>
                    <Button size="sm" variant="ghost" onClick={onRetry}>
                        Reintentar
                    </Button>
                </Alert>
            ) : null}
        </div>
    );
}
