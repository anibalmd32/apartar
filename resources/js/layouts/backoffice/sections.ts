import { bo } from '@/lib/surfaces';
import {
    cilBank,
    cilBasket,
    cilBullhorn,
    cilChartLine,
    cilChatBubble,
    cilCloudDownload,
    cilDescription,
    cilDiamond,
    cilFolder,
    cilGroup,
    cilHistory,
    cilLibrary,
    cilPeople,
    cilSettings,
    cilSpeedometer,
    cilTag,
    cilUserFollow,
    cilWallet,
} from '@coreui/icons';
import { ANY_CAPABILITY, type NavSection } from './nav';

export const RECEIPTS_HREF = bo('/comprobantes');

const RECEIPTS_TO_REVIEW = `${RECEIPTS_HREF}?status=pending_review`;
export const CHAT_HREF = bo('/chat');

export const SECTIONS: NavSection[] = [
    { label: 'Hoy', href: bo(''), capability: ANY_CAPABILITY, icon: cilSpeedometer },
    { label: 'Comprobantes', href: RECEIPTS_TO_REVIEW, capability: 'receipts', icon: cilDescription },
    { label: 'Cobranza', href: bo('/cobranza'), capability: 'collections', icon: cilWallet },
    { label: 'Clientes', href: bo('/clientes'), capability: 'customers', icon: cilPeople },
    { label: 'Chat', href: CHAT_HREF, capability: 'chat', icon: cilChatBubble },
    { label: 'Productos', href: bo('/productos'), capability: 'products', icon: cilBasket },
    { label: 'Catálogos', href: bo('/catalogos'), capability: 'products', icon: cilLibrary },
    { label: 'Categorías', href: bo('/categorias'), capability: 'products', icon: cilFolder, group: 'Catálogo' },
    { label: 'Etiquetas', href: bo('/etiquetas'), capability: 'products', icon: cilTag, group: 'Catálogo' },
    { label: 'Suscriptores', href: bo('/suscriptores'), capability: 'customers', icon: cilUserFollow, group: 'Crecimiento' },
    { label: 'Avisos', href: bo('/avisos'), capability: 'customers', icon: cilBullhorn, group: 'Crecimiento' },
    { label: 'Analítica', href: bo('/analitica'), capability: 'customers', icon: cilChartLine, group: 'Crecimiento' },
    { label: 'Equipo', href: bo('/equipo'), capability: 'team', icon: cilGroup, group: 'Negocio' },
    { label: 'Cuentas de cobro', href: bo('/cuentas-de-cobro'), capability: 'payment_accounts', icon: cilBank, group: 'Negocio' },
    { label: 'Suscripción', href: bo('/suscripcion'), capability: 'subscription', icon: cilDiamond, group: 'Negocio' },
    { label: 'Exportaciones', href: bo('/exportaciones'), capability: 'collections', icon: cilCloudDownload, group: 'Negocio' },
    { label: 'Auditoría', href: bo('/auditoria'), capability: 'audit', icon: cilHistory, group: 'Negocio' },
    { label: 'Ajustes', href: bo('/ajustes'), capability: 'settings', icon: cilSettings, group: 'Negocio' },
];

export const TABS: NavSection[] = [
    { label: 'Hoy', href: bo(''), capability: ANY_CAPABILITY, icon: cilSpeedometer },
    { label: 'Comprobantes', href: RECEIPTS_TO_REVIEW, capability: 'receipts', icon: cilDescription },
    { label: 'Cobranza', href: bo('/cobranza'), capability: 'collections', icon: cilWallet },
    { label: 'Clientes', href: bo('/clientes'), capability: 'customers', icon: cilPeople },
];

export const ROLE_LABELS = { owner: 'Dueño', member: 'Equipo' } as const;
