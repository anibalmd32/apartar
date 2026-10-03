<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Settings\SettingKey;
use App\Models\Organization;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * Cuándo vence una cuota, **contado como lo cuenta el negocio**, para todo lo
 * que el portal le dice al cliente de sus vencimientos (`REQ-LAY-021` RB-4,
 * RB-5; `REQ-LAY-023` RB-1, RB-4).
 *
 * ## Un solo umbral de «vence pronto»
 *
 * Es el primer recordatorio del negocio: el offset más negativo de
 * `reminder_offsets_days` —3 días con el ajuste por defecto—. El ámbar del
 * cronograma y la alerta `due_soon` de la portada leen **este** número, y no
 * uno cada uno: si el negocio adelanta su recordatorio a cinco días, el aviso,
 * la fila y la alerta tienen que moverse juntos, o el portal y el correo se
 * contradicen, que es justo lo que `REQ-LAY-023` RB-1 y `REQ-NOT-002` RB-5
 * prohíben. Sin ningún recordatorio previo, «pronto» es solo el mismo día.
 *
 * ## Un solo «hoy»
 *
 * El de la zona de la organización, como la mora (`REQ-LAY-007` RB-1): a las
 * 23:30 de Caracas ya es el día siguiente en Madrid, y un cliente que mire
 * desde fuera vería «vence mañana» de una cuota que vence hoy.
 *
 * ## «Vencida» es fecha pasada, haya o no mora
 *
 * Para quien paga, el día 26 una cuota del 25 está vencida aunque siga dentro
 * de la gracia: la mora es una consecuencia que decide el negocio, el
 * vencimiento es una fecha. Es lo que pinta de rojo la fila del cronograma,
 * lo que cuenta el bloque de la portada y lo que dispara la alerta `overdue`.
 */
final readonly class DueCalendar
{
    /**
     * @param  CarbonImmutable  $today  el comienzo del día en la zona del negocio
     * @param  int  $soonDays  cuántos días antes cuenta como «pronto»; nunca negativo
     */
    public function __construct(public CarbonImmutable $today, public int $soonDays) {}

    /**
     * El calendario de ese negocio, hoy.
     *
     * Recibe la organización en vez de leerla del contexto: la portada compone
     * cada bloque con su tenant, y el calendario de un bloque no puede depender
     * de cuál quedó fijado el último.
     */
    public static function of(Organization $organization, Settings $settings): self
    {
        $offsets = $settings->forOrganization($organization)[SettingKey::ReminderOffsetsDays->value] ?? SettingKey::ReminderOffsetsDays->defaultValue();

        return new self(
            CarbonImmutable::now(new DateTimeZone($organization->timezone))->startOfDay(),
            self::soonDaysFrom($offsets),
        );
    }

    /**
     * El umbral que sale de `reminder_offsets_days`: el recordatorio más
     * temprano antes del vencimiento, en días. Sin recordatorios previos, 0.
     */
    public static function soonDaysFrom(mixed $offsets): int
    {
        $before = is_array($offsets)
            ? array_filter(array_map(fn (mixed $offset): int => is_numeric($offset) ? (int) $offset : 0, $offsets), fn (int $offset): bool => $offset < 0)
            : [];

        return $before === [] ? 0 : abs(min($before));
    }

    /**
     * Días de calendario hasta ese vencimiento, contados desde el «hoy» del
     * negocio. Negativo si ya pasó.
     *
     * Las fechas de vencimiento son `DATE` (`T-039`): no tienen zona, así que
     * se leen como un día del calendario del negocio, sin convertir.
     */
    public function daysUntil(CarbonInterface $dueDate): int
    {
        return (int) round($this->today->diffInDays($this->day($dueDate), false));
    }

    public function isPastDue(CarbonInterface $dueDate): bool
    {
        return $this->daysUntil($dueDate) < 0;
    }

    public function isDueSoon(CarbonInterface $dueDate): bool
    {
        $days = $this->daysUntil($dueDate);

        return $days >= 0 && $days <= $this->soonDays;
    }

    /**
     * El último día que todavía cuenta como «pronto».
     */
    public function lastSoonDay(): CarbonImmutable
    {
        return $this->today->addDays($this->soonDays);
    }

    /**
     * Ese vencimiento como el comienzo de su día en el calendario del negocio.
     */
    public function day(CarbonInterface $dueDate): CarbonImmutable
    {
        return CarbonImmutable::parse($dueDate->toDateString(), $this->today->getTimezone())->startOfDay();
    }
}
