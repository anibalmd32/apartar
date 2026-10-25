export type ExportType = 'collections' | 'customers' | 'inventory';

export type ExportStatus = 'queued' | 'processing' | 'completed' | 'failed' | 'expired';

export interface ExportRow {
    readonly id: number;
    readonly type: ExportType;
    readonly typeLabel: string;
    readonly status: ExportStatus;
    readonly statusLabel: string;
    readonly requestedAt: string;
    readonly requestedIso: string;
    readonly expiresIso: string | null;
    readonly retryFilters: Readonly<Record<string, string>> | null;
    readonly waitingMinutes: number | null;
    readonly rowCount: number | null;
    readonly fileSize: number | null;
    readonly errorMessage: string | null;
    readonly expiresAt: string | null;
    readonly downloadUrl: string | null;
}

export interface ExportsPageProps {
    readonly exports: readonly ExportRow[];
    readonly canExport: boolean;
    readonly types: readonly { readonly value: ExportType; readonly label: string; readonly allowed: boolean }[];
}

export const EXPORTS_URL = '/backoffice/exportaciones';
