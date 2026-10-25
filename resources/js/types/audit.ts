export type AuditActorKind = 'team' | 'platform' | 'system';

export interface AuditActor {
    readonly kind: AuditActorKind;
    readonly name: string;
    readonly id: number | null;
}

export interface AuditChange {
    readonly before: unknown;
    readonly after: unknown;
}

export interface AuditProperties {
    readonly changed?: Record<string, AuditChange>;
    readonly truncated?: boolean;
}

export interface AuditEntry {
    readonly id: number;
    readonly event: string | null;
    readonly eventLabel: string;
    readonly description: string;
    readonly actor: AuditActor;
    readonly properties: AuditProperties;
    readonly occurredAt: string | null;
}

export interface AuditEventOption {
    readonly value: string;
    readonly label: string;
}

export interface AuditEventGroup {
    readonly area: string;
    readonly label: string;
    readonly events: readonly AuditEventOption[];
}

export interface AuditActorOption {
    readonly id: number;
    readonly name: string;
    readonly isActive: boolean;
}

export interface AuditFilters {
    readonly event: string | null;
    readonly actor: string | null;
    readonly from: string | null;
    readonly to: string | null;
}
