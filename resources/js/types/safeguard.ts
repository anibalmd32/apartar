export interface SafeguardRecord {
    readonly destination: string;
    readonly destinationLabel: string;
    readonly reference: string | null;
    readonly note: string | null;
    readonly proofUrl: string | null;
    readonly recordedAt: string | null;
}

export interface SafeguardView {
    readonly equivalent: string | null;
    readonly gap: string | null;
    readonly parallelRate: string | null;
    readonly storeUrl: string;
    readonly destroyUrl: string | null;
    readonly record: SafeguardRecord | null;
}

export interface SafeguardOptions {
    readonly destinations: readonly { readonly value: string; readonly label: string }[];
    readonly file: { readonly accept: string; readonly maxMb: number };
}
