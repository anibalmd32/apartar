<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Funciones que un plan **tiene o no tiene** (`REQ-SUB-001` RB-2).
 *
 * Viven en `plans.features`, que es JSON, y no en columnas propias: a
 * diferencia de los límites —que se comparan contra un contador y se ordenan—
 * una función es una respuesta de sí o no, y añadir la siguiente no debería
 * costar una migración.
 *
 * Un plan **sin** `features` es un plan sin funciones de pago, no un error
 * (SP-1): la ausencia se lee como `false` en todas.
 *
 * El sello «Hecho con Apartar» no está en esta lista a propósito. La matriz de
 * [03 §5] lo declara como una fila más, pero `REQ-CAT-022` RB-5 dice que se
 * oculta **con la función `branding`**: es el complemento exacto de otra
 * función, no una independiente. Guardarlo aparte permitiría sembrar un plan
 * con branding y sello a la vez, que es un estado que la spec no admite y que
 * nadie detectaría hasta verlo en un catálogo.
 */
enum PlanFeature: string
{
    /** Logo y color propios en el catálogo; oculta el sello de plataforma. */
    case Branding = 'branding';

    /** Exportación a Excel del backoffice (`REQ-BOF-040`). */
    case ExcelExport = 'excel_export';

    /** Web Push a los clientes del negocio (`REQ-NOT-004`). */
    case WebPush = 'web_push';

    /** Avisos de novedades a los suscriptores del catálogo (`REQ-ACC-003`). */
    case SubscriberDigest = 'subscriber_digest';

    /** Analítica completa de catálogo y suscriptores; sin ella, la básica. */
    case FullAnalytics = 'full_analytics';

    /** Dominio propio para la vitrina. */
    case CustomDomain = 'custom_domain';

    /**
     * Todas en `false`: el punto de partida de un plan sin funciones de pago.
     *
     * @return array<string, bool>
     */
    public static function noneEnabled(): array
    {
        $features = [];

        foreach (self::cases() as $feature) {
            $features[$feature->value] = false;
        }

        return $features;
    }

    /**
     * Las indicadas en `true` y el resto en `false`.
     *
     * El seeder declara lo que cada plan **sí** incluye y esto rellena el
     * resto, para que ningún plan acabe con una función simplemente ausente
     * del JSON: ausente y `false` se leen igual, pero solo una de las dos se
     * puede comprobar de un vistazo en la fila.
     *
     * @param  list<self>  $enabled
     * @return array<string, bool>
     */
    public static function only(array $enabled): array
    {
        $features = self::noneEnabled();

        foreach ($enabled as $feature) {
            $features[$feature->value] = true;
        }

        return $features;
    }
}
