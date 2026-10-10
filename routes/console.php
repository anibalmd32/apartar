<?php

declare(strict_types=1);

use App\Domain\Subscription\UsageMetric;
use App\Jobs\DetectOverdueLayawaysJob;
use App\Jobs\ExpireLayawaysJob;
use App\Jobs\FetchExchangeRate;
use App\Jobs\IssueRenewalInvoices;
use App\Jobs\PurgeExpiredExports;
use App\Jobs\RecalculateUsage;
use App\Jobs\ReconcileLayaways;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Comandos de consola y tareas programadas
|--------------------------------------------------------------------------
|
| Las catorce entradas del schedule de REQ-INF-003 RB-5 entran con la tarea de
| cada job, no todas de golpe: `layaways:expire` con T-047,
| `notifications:dispatch-reminders` con T-083, y así hasta cerrar al final de
| H8.
|
| El servicio `queue` ya ejecuta `schedule:work`, de modo que cada entrada
| empieza a correr en cuanto se registra aquí.
|
*/

/*
| Ingesta de la tasa de cambio (REQ-FX-002 RB-0 y RB-1).
|
| Cada 30 minutos, **una sola ejecución para toda la plataforma**: el job no
| recibe ninguna organización ni las recorre, porque la tasa del BCV es la
| misma para todos y escalar a 10.000 negocios no puede multiplicar las
| llamadas a la API externa.
|
| `withoutOverlapping()` aquí es el cinturón sobre los tirantes del
| `WithoutOverlapping` del propio job: este impide que el scheduler despache
| encima de una ejecución viva, y el del job impide que lo haga un reintento
| con backoff de 300 s. Los dos casos son reales y ninguno cubre al otro.
*/
Schedule::job(new FetchExchangeRate)
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->name('exchange-rate:fetch');

/*
| Expiración de apartados sin inicial (REQ-LAY-006 RB-6).
|
| Cada hora, **por organización**: el job las recorre todas —también las
| suspendidas, RB-8— y fija el tenant en cada una. No es ⏰: un plazo vence a
| una hora concreta, no a una hora local del día, así que la pasada horaria es
| la granularidad real de la regla.
*/
Schedule::job(new ExpireLayawaysJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('layaways:expire');

/*
| Detección de mora (REQ-LAY-007 RB-5), ⏰ 06:00 de cada organización.
|
| Se despacha **cada hora** y el job visita solo las organizaciones cuya hora
| local es las 06:00 (REQ-INF-003 RB-5b): una ejecución diaria a hora fija
| evaluaría a los negocios de otros husos con el «hoy» equivocado.
*/
Schedule::job(new DetectOverdueLayawaysJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('layaways:detect-overdue');

/*
| `REQ-PAY-008` RB-6, `T-064` · la reconciliación de saldos, en modo informe,
| a las 05:30 de la plataforma (`APP_TIMEZONE`, [90] RB-5). Las correcciones
| son del comando con `--fix`, a mano.
*/
Schedule::job(new ReconcileLayaways)
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->name('layaways:reconcile');

/*
| `REQ-SUB-003` RB-4, `T-066` · el recálculo del consumo desde cero, a las
| 02:00 de la plataforma ([90] RB-5): corrige la deriva de las seis métricas.
| Y cada hora, solo `catalogs`, que bajan solos cuando un catálogo caduca
| (`UsageMetric::shrinksWithTime()`): esperar a la noche dejaría a un negocio
| con su cupo ocupado por una campaña que terminó a las nueve de la mañana.
*/
Schedule::job(new RecalculateUsage)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->name('subscriptions:recalculate-usage');

Schedule::job(new RecalculateUsage(metric: UsageMetric::Catalogs))
    ->hourlyAt(15)
    ->withoutOverlapping()
    ->name('subscriptions:recalculate-usage:catalogs');

/*
| `REQ-SUB-006` RB-8, `T-069` · las facturas de renovación, siete días antes
| del fin del período, a las 06:00 de la plataforma. Idempotente: un día que
| no corra lo recupera el siguiente.
*/
Schedule::job(new IssueRenewalInvoices)
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->name('subscriptions:issue-renewal-invoices');

/*
| `REQ-BOF-043` RB-7, `T-070` · las exportaciones se purgan a los siete días,
| a las 03:00 de la plataforma, y su espacio deja de contar en `storage_mb`.
*/
Schedule::job(new PurgeExpiredExports)
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->name('exports:purge-expired');
