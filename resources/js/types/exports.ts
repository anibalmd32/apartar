/**
 * Las exportaciones a Excel (`REQ-BOF-043`, `T-070`): espejo de lo que envía
 * `ExportController`.
 */

export type ExportType = 'collections' | 'customers' | 'inventory';

export type ExportStatus = 'queued' | 'processing' | 'completed' | 'failed' | 'expired';

export interface ExportRow {
    readonly id: number;
    readonly type: ExportType;
    readonly typeLabel: string;
    readonly status: ExportStatus;
    readonly statusLabel: string;
    readonly requestedAt: string;
    /** Cuánto lleva esperando si sigue en curso (SP-10); `null` si ya terminó. */
    readonly waitingMinutes: number | null;
    readonly rowCount: number | null;
    readonly fileSize: number | null;
    readonly errorMessage: string | null;
    readonly expiresAt: string | null;
    /** El enlace firmado, solo si se puede descargar. */
    readonly downloadUrl: string | null;
}

export interface ExportsPageProps {
    readonly exports: readonly ExportRow[];
    readonly canExport: boolean;
    readonly types: readonly { readonly value: ExportType; readonly label: string }[];
}

/** Adónde se piden y dónde se listan. */
export const EXPORTS_URL = '/backoffice/exportaciones';
