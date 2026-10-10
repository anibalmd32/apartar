<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Subscription\PlanAllowance;
use App\Domain\Subscription\PlanFeature;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * La matriz de planes aprobada ([03 §5], `REQ-SUB-001` RB-3).
 *
 * Este archivo **es** el modelo de negocio, y por eso los números están aquí
 * literales y no calculados: cualquiera tiene que poder abrirlo y cotejarlo
 * fila a fila con la matriz de la especificación sin ejecutar nada.
 *
 * **Idempotente por `UNIQUE(code)`** (SP-5): sembrar dos veces no duplica ni
 * altera. `updateOrCreate` y no `firstOrCreate` porque una corrección de la
 * matriz tiene que llegar a los planes ya sembrados; lo que nunca toca son las
 * suscripciones vigentes ni las facturas emitidas (RB-7, `REQ-SUB-006` RB-3).
 *
 * El almacenamiento va en MB porque así se llama la columna: la matriz lo
 * escribe en MB y GB —200 MB, 5 GB, 25 GB— y aquí se convierte una sola vez,
 * donde se ve la cuenta.
 *
 * **Los precios los fijó el negocio en `T-069`**: Pro **$15** y Business
 * **$39** al mes, en dólares (`REQ-SUB-001` RB-3b, `REQ-SUB-006` RB-4). Hasta
 * entonces los tres valían cero, a propósito: la matriz aprobada no traía
 * cifras e inventarlas aquí habría sido decidir el precio del producto en un
 * seeder. Cambiarlo es un dato ajustable sin desplegar (RB-1) que no altera
 * las facturas ya emitidas (RB-7); pero que llegue también aquí, que es
 * donde la matriz se coteja (`PricingPageTest`).
 */
class PlanSeeder extends Seeder
{
    /**
     * Un gigabyte, para que la conversión de la matriz se lea.
     */
    private const MB_PER_GB = 1_024;

    public function run(): void
    {
        foreach ($this->matrix() as $plan) {
            Plan::query()->updateOrCreate(['code' => $plan['code']], $plan);
        }
    }

    /**
     * La matriz aprobada, plan a plan y en el orden en que se comparan.
     *
     * @return list<array<string, mixed>>
     */
    private function matrix(): array
    {
        return [
            [
                'code' => 'free',
                'name' => 'Free',
                'price_cents' => 0,
                'interval' => 'monthly',
                'is_public' => true,
                'position' => 1,

                'max_users' => 1,
                'max_catalogs' => 1,
                'max_products' => 30,
                'max_products_per_catalog' => 20,
                // Free incluye el sistema de apartado (RB-5): sin apartados, la
                // capa gratuita no demostraría el valor del producto.
                'max_active_layaways' => 15,
                'max_storage_mb' => 200,
                'max_payment_accounts' => 1,

                // Ninguna función de pago, y el sello «Hecho con Apartar»
                // visible por no tener `branding`.
                'features' => PlanFeature::only([]) + [
                    PlanAllowance::ChatHistoryDays->value => 30,
                    PlanAllowance::TelemetryRetentionDays->value => 30,
                ],
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'price_cents' => 1_500,
                'interval' => 'monthly',
                'is_public' => true,
                'position' => 2,

                'max_users' => 5,
                'max_catalogs' => 10,
                'max_products' => 300,
                'max_products_per_catalog' => 100,
                'max_active_layaways' => 200,
                'max_storage_mb' => 5 * self::MB_PER_GB,
                'max_payment_accounts' => 5,

                'features' => PlanFeature::only([
                    PlanFeature::Branding,
                    PlanFeature::ExcelExport,
                    PlanFeature::WebPush,
                    PlanFeature::SubscriberDigest,
                    PlanFeature::FullAnalytics,
                ]) + [
                    // Historial de chat completo: `null` es ilimitado.
                    PlanAllowance::ChatHistoryDays->value => null,
                    PlanAllowance::TelemetryRetentionDays->value => 180,
                ],
            ],
            [
                'code' => 'business',
                'name' => 'Business',
                'price_cents' => 3_900,
                'interval' => 'monthly',
                'is_public' => true,
                'position' => 3,

                'max_users' => 15,
                'max_catalogs' => 50,
                'max_products' => 2_000,
                'max_products_per_catalog' => 500,
                'max_active_layaways' => 2_000,
                'max_storage_mb' => 25 * self::MB_PER_GB,
                'max_payment_accounts' => 15,

                'features' => PlanFeature::only([
                    PlanFeature::Branding,
                    PlanFeature::ExcelExport,
                    PlanFeature::WebPush,
                    PlanFeature::SubscriberDigest,
                    PlanFeature::FullAnalytics,
                    PlanFeature::CustomDomain,
                ]) + [
                    PlanAllowance::ChatHistoryDays->value => null,
                    PlanAllowance::TelemetryRetentionDays->value => 365,
                ],
            ],
        ];
    }
}
