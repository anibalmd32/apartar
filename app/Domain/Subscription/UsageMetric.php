<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Lo que una organización **consume** de su plan (`REQ-SUB-003` RB-2).
 *
 * Una métrica es algo que se gasta y que un día se acaba; una `PlanFeature` es
 * algo que se tiene o no se tiene. La prueba para saber de qué lado cae algo
 * es si tiene sentido decir «te quedan 3»: de los usuarios sí, del branding
 * no.
 *
 * **Son seis, y las columnas de límite del plan son siete.** La que sobra es
 * `max_products_per_catalog`, y no es un descuido: un contador vive en
 * `usage_counters` con `UNIQUE(organization_id, metric)`, o sea un valor por
 * organización, y «productos en este catálogo» es un valor **por catálogo**.
 * El tope existe y se comprueba al añadir un producto a un catálogo
 * (`REQ-SUB-004` RB-3, que lo nombra por su columna y no por una métrica),
 * contando los del catálogo en cuestión. Por eso `Plan` expone ese límite
 * aparte, con su propio accesor.
 *
 * **Los suscriptores de catálogo no están aquí** y tampoco es un olvido
 * (`REQ-SUB-001` RB-4, [03 §4.6]): son ilimitados en los tres planes. Cobrar
 * por tenerlos frenaría lo único que crece solo; la palanca de pago es
 * **avisarles**, que es la función `subscriber_digest`.
 */
enum UsageMetric: string
{
    /** Usuarios activos más invitaciones pendientes (`REQ-ORG-004` RB-2). */
    case Users = 'users';

    /** Catálogos publicados; los borradores no cuentan. */
    case Catalogs = 'catalogs';

    /** Productos no archivados ni borrados lógicamente. */
    case Products = 'products';

    /** Apartados en `pending_down_payment`, `active` u `overdue`. */
    case ActiveLayaways = 'active_layaways';

    /** Comprobantes + medios de producto + exportaciones vigentes. */
    case StorageMb = 'storage_mb';

    /** Cuentas de cobro activas. */
    case PaymentAccounts = 'payment_accounts';

    /**
     * Columna de `plans` que declara el techo de esta métrica.
     *
     * El mapa está aquí y no repartido por las consultas porque es lo que
     * permite que el código **nunca** compare contra el código de un plan
     * (`REQ-SUB-001` RB-1): se pregunta por la métrica, se lee la columna, y
     * ajustar la matriz es un `UPDATE` y no un despliegue.
     */
    public function planColumn(): string
    {
        return match ($this) {
            self::Users => 'max_users',
            self::Catalogs => 'max_catalogs',
            self::Products => 'max_products',
            self::ActiveLayaways => 'max_active_layaways',
            self::StorageMb => 'max_storage_mb',
            self::PaymentAccounts => 'max_payment_accounts',
        };
    }

    /**
     * Indica si el valor se recalcula por agregación en vez de por incremento.
     *
     * `storage_mb` es la única (`REQ-SUB-003` RB-5): las conversiones de
     * imagen y las purgas la mueven de forma asíncrona, así que sumar y restar
     * a mano acumularía deriva. Las demás cambian exactamente cuando alguien
     * crea o borra algo, dentro de la misma transacción.
     */
    public function isDerived(): bool
    {
        return match ($this) {
            self::StorageMb => true,

            self::Users, self::Catalogs, self::Products,
            self::ActiveLayaways, self::PaymentAccounts => false,
        };
    }

    /**
     * Indica si el consumo **baja solo con el reloj**, sin que nadie escriba
     * nada.
     *
     * Es `catalogs`: un catálogo caduca a su hora (`REQ-CAT-022` RB-6), sin job
     * y sin escritura, y en ese instante deja de contar. El contador lo mueven
     * publicar y despublicar, así que un catálogo caducado sigue sumando hasta
     * que el recálculo lo ve. Por eso el contador de esta métrica **puede estar
     * por encima** de lo medido sin que eso sea una deriva —lo es si está por
     * debajo—, y por eso se recalcula cada hora además de cada noche.
     */
    public function shrinksWithTime(): bool
    {
        return match ($this) {
            self::Catalogs => true,

            self::Users, self::Products, self::ActiveLayaways,
            self::StorageMb, self::PaymentAccounts => false,
        };
    }

    /**
     * Cómo se libera sitio sin cambiar de plan, para el mensaje del límite
     * (`REQ-SUB-004` RB-5, `REQ-BOF-024` SP-3, `T-067`).
     *
     * Ofrecer solo «sube de plan» a quien tiene un catálogo de la campaña
     * pasada todavía publicado es venderle algo que no necesita. Cada métrica
     * tiene su salida, y ninguna borra nada (`REQ-SUB-008`).
     */
    public function freeUpHint(): string
    {
        return match ($this) {
            self::Users => 'También puedes desactivar a alguien del equipo o revocar una invitación pendiente.',
            self::Catalogs => 'También puedes despublicar otro catálogo para liberar su sitio.',
            self::Products => 'También puedes archivar un producto que ya no vendas para liberar su sitio.',
            self::ActiveLayaways => 'El cupo se libera solo cuando un apartado se completa, se cancela o vence.',
            self::StorageMb => 'También puedes borrar imágenes que ya no uses.',
            self::PaymentAccounts => 'También puedes desactivar otra cuenta de cobro.',
        };
    }

    /**
     * Unidad en la que se cuenta, para que la UI no la invente.
     */
    public function unit(): string
    {
        return $this === self::StorageMb ? 'MB' : 'unidades';
    }

    /**
     * Nombre de la métrica tal como se le dice a una persona, concordado con
     * la cantidad de la que se habla.
     *
     * Hace falta porque el mensaje de `PlanLimitReachedException` lo lee el
     * dueño de un negocio (`REQ-SUB-004` RB-5), y «tu plan permite 1 users» no
     * es un mensaje: es el nombre de una columna. El plural va por defecto
     * porque es la forma en que se habla de un techo —«5 usuarios»— y el
     * singular solo aparece cuando el techo es exactamente uno, que es
     * justamente el caso de Free.
     */
    public function label(int $count = 2): string
    {
        return match ($this) {
            self::Users => $count === 1 ? 'usuario' : 'usuarios',
            self::Catalogs => $count === 1 ? 'catálogo publicado' : 'catálogos publicados',
            self::Products => $count === 1 ? 'producto' : 'productos',
            self::ActiveLayaways => $count === 1 ? 'apartado activo' : 'apartados activos',
            self::StorageMb => 'MB de almacenamiento',
            self::PaymentAccounts => $count === 1 ? 'cuenta de cobro' : 'cuentas de cobro',
        };
    }
}
