import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { type ReactNode } from 'react';

/**
 * Lo que va encima de una tabla server-driven: sus filtros, y los dos avisos
 * que no se pueden perder (`REQ-BOF-045` SP-4, SP-5).
 *
 * Los filtros los pone cada pantalla —son suyos—, y esta pieza solo les da
 * sitio. Lo común son los avisos:
 *
 *  - **Un filtro que no se pudo aplicar** (SP-4): el servidor lo ignoró —una
 *    categoría de otro negocio, un estado inventado, un enlace viejo— y la
 *    tabla enseña el resto. Sin el aviso, la persona creería estar viendo lo
 *    que pidió.
 *  - **La recarga falló** (SP-5): las filas de antes siguen ahí y se ofrece
 *    reintentar, en lugar de vaciar la vista.
 */

interface TableToolbarProps {
    children?: ReactNode;
    /** Los nombres legibles de los filtros ignorados. */
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
