export interface SharedData {
    auth: {
        user: { name: string; email: string } | null;
    };
    [key: string]: unknown;
}
