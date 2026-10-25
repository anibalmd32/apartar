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

export const RECEIPTS_HREF = '/backoffice/comprobantes';
export const CHAT_HREF = '/backoffice/chat';

export const SECTIONS: NavSection[] = [
    { label: 'Panel', href: '/backoffice', capability: ANY_CAPABILITY, icon: cilSpeedometer },
    { label: 'Comprobantes', href: RECEIPTS_HREF, capability: 'receipts', icon: cilDescription },
    { label: 'Cobranza', href: '/backoffice/cobranza', capability: 'collections', icon: cilWallet },
    { label: 'Clientes', href: '/backoffice/clientes', capability: 'customers', icon: cilPeople },
    { label: 'Chat', href: CHAT_HREF, capability: 'chat', icon: cilChatBubble },
    { label: 'Productos', href: '/backoffice/productos', capability: 'products', icon: cilBasket, group: 'Catálogo' },
    { label: 'Catálogos', href: '/backoffice/catalogos', capability: 'products', icon: cilLibrary, group: 'Catálogo' },
    { label: 'Categorías', href: '/backoffice/categorias', capability: 'products', icon: cilFolder, group: 'Catálogo' },
    { label: 'Etiquetas', href: '/backoffice/etiquetas', capability: 'products', icon: cilTag, group: 'Catálogo' },
    { label: 'Suscriptores', href: '/backoffice/suscriptores', capability: 'customers', icon: cilUserFollow, group: 'Crecimiento' },
    { label: 'Notificaciones', href: '/backoffice/avisos', capability: 'customers', icon: cilBullhorn, group: 'Crecimiento' },
    { label: 'Analítica', href: '/backoffice/analitica', capability: 'customers', icon: cilChartLine, group: 'Crecimiento' },
    { label: 'Equipo', href: '/backoffice/equipo', capability: 'team', icon: cilGroup, group: 'Negocio' },
    { label: 'Cuentas de cobro', href: '/backoffice/cuentas-de-cobro', capability: 'payment_accounts', icon: cilBank, group: 'Negocio' },
    { label: 'Suscripción', href: '/backoffice/suscripcion', capability: 'subscription', icon: cilDiamond, group: 'Negocio' },
    { label: 'Exportaciones', href: '/backoffice/exportaciones', capability: 'collections', icon: cilCloudDownload, group: 'Negocio' },
    { label: 'Auditoría', href: '/backoffice/auditoria', capability: 'audit', icon: cilHistory, group: 'Negocio' },
    { label: 'Ajustes', href: '/backoffice/ajustes', capability: 'settings', icon: cilSettings, group: 'Negocio' },
];

export const TABS: NavSection[] = [
    { label: 'Inicio', href: '/backoffice', capability: ANY_CAPABILITY, icon: cilSpeedometer },
    { label: 'Comprobantes', href: RECEIPTS_HREF, capability: 'receipts', icon: cilDescription },
    { label: 'Cobranza', href: '/backoffice/cobranza', capability: 'collections', icon: cilWallet },
    { label: 'Clientes', href: '/backoffice/clientes', capability: 'customers', icon: cilPeople },
];

export const ROLE_LABELS = { owner: 'Dueño', member: 'Equipo' } as const;
