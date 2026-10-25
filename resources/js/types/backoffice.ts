export type OrganizationRole = 'owner' | 'member';

export type OrganizationCapability =
    | 'subscription'
    | 'billing'
    | 'team'
    | 'settings'
    | 'payment_accounts'
    | 'branding'
    | 'audit'
    | 'products'
    | 'catalogs'
    | 'customers'
    | 'collections'
    | 'receipts'
    | 'chat';

export type OrganizationStatus = 'active' | 'suspended' | 'closed';

export interface TeamOrganization {
    readonly name: string;
    readonly slug: string;
    readonly role: OrganizationRole;
    readonly current: boolean;
    readonly switchUrl: string;
}

export interface OrganizationSummary {
    readonly id: number;
    readonly name: string;
    readonly slug: string;
    readonly status: OrganizationStatus;
}

export interface OrganizationBrand {
    readonly name: string;
    readonly slug: string;
    readonly logoUrl: string | null;
    readonly brandColor: string | null;
    readonly brandingEnabled: boolean;
    readonly tagline?: string | null;
}

export type SettingScope = 'immediate' | 'daily' | 'future_only';

export type SettingValue = string | number | boolean | null | number[];

export type OrganizationSettings = Record<string, SettingValue>;

export interface SettingDefinition {
    readonly key: string;
    readonly label: string;
    readonly scope: SettingScope;
    readonly scopeNote: string;
}

export interface OrganizationIdentity {
    readonly name: string;
    readonly slug: string;
    readonly document: string | null;
    readonly phone: string | null;
    readonly timezone: string;
    readonly brandColor: string | null;
    readonly storefrontEnabled: boolean;
    readonly tagline?: string | null;
    readonly description?: string | null;
    readonly address?: string | null;
}

export interface AuthenticatedTeamMember {
    readonly name: string;
    readonly email: string;
    readonly role: OrganizationRole;
    readonly capabilities: readonly OrganizationCapability[];
    readonly mustChangePassword: boolean;
    readonly pendingReceipts: number | null;
    readonly unreadChats?: number | null;
    readonly supportAccess?: readonly { readonly operator: string; readonly reason: string; readonly expiresAt: string }[];
    readonly organization: OrganizationSummary;
    readonly organizations?: readonly TeamOrganization[];
}

export interface TeamMember {
    readonly id: number;
    readonly name: string;
    readonly email: string;
    readonly role: OrganizationRole;
    readonly isActive: boolean;
    readonly invitedAt: string | null;
    readonly lastLoginAt?: string | null;
    readonly isPending: boolean;
    readonly isSelf: boolean;
    readonly isLastOwner: boolean;
}

export interface BackofficeSharedData {
    readonly teamMember: AuthenticatedTeamMember | null;
    readonly status: string | null;
    [key: string]: unknown;
}
