<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los centavos de la facturación de la suscripción, en `BIGINT` (`T-069`).
 *
 * Todo importe del esquema es `BIGINT` con sufijo `_cents`
 * (`MoneyColumnConventionsTest`), y las dos migraciones de `T-069` los
 * declararon `INT`. Ya estaban aplicadas en desarrollo, así que el arreglo es
 * esta migración aditiva y no reescribirlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('amount_cents')->change();
            $table->unsignedBigInteger('paid_cents')->default(0)->change();
        });

        Schema::table('subscription_receipts', function (Blueprint $table): void {
            $table->unsignedBigInteger('settled_cents_usd')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->unsignedInteger('amount_cents')->change();
            $table->unsignedInteger('paid_cents')->default(0)->change();
        });

        Schema::table('subscription_receipts', function (Blueprint $table): void {
            $table->unsignedInteger('settled_cents_usd')->nullable()->change();
        });
    }
};
