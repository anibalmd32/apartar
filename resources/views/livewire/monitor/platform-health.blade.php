@php
    $queue = $health['queue'] ?? null;
    $rate = $health['exchangeRate'] ?? null;
    $reconciliation = $health['reconciliation'] ?? null;
    $storage = $health['storage'] ?? null;
    $jobs = $health['scheduledJobs'] ?? [];
    $realtime = $health['realtime']['connected'] ?? false;
    $overdueJobs = collect($jobs)->where('isOverdue', true);
    $checks = [
        ['label' => 'Reconciliación', 'ok' => $reconciliation === null ? null : ($reconciliation['discrepancies'] ?? 0) === 0 && ($reconciliation['unresolved'] ?? 0) === 0, 'detail' => $reconciliation === null ? 'Sin datos' : sprintf('%d descuadres, %d sin resolver, %d de stock', $reconciliation['discrepancies'] ?? 0, $reconciliation['unresolved'] ?? 0, $reconciliation['stock'] ?? 0)],
        ['label' => 'Cola', 'ok' => $queue === null ? null : ($queue['failed'] ?? 0) === 0, 'detail' => $queue === null ? 'Sin datos' : sprintf('%s pendientes, %d fallidos', $queue['pending'] ?? '—', $queue['failed'] ?? 0)],
        ['label' => 'Tasa de cambio', 'ok' => $rate === null ? null : ! ($rate['isStale'] ?? true), 'detail' => $rate === null ? 'Sin datos' : sprintf('%s · hace %s h', strtoupper($rate['source'] ?? ''), $rate['ageHours'] ?? '—')],
        ['label' => 'Tiempo real (Reverb)', 'ok' => $realtime, 'detail' => $realtime ? 'El servidor responde' : 'El servidor no responde'],
        ['label' => 'Tareas programadas', 'ok' => $overdueJobs->isEmpty(), 'detail' => $overdueJobs->isEmpty() ? sprintf('%d al día', count($jobs)) : 'Atrasadas: '.$overdueJobs->pluck('command')->implode(', ')],
        ['label' => 'Almacenamiento', 'ok' => $storage === null ? null : true, 'detail' => $storage === null ? 'Sin datos' : sprintf('%s MB · %s comprobantes', number_format($storage['usedMb'] ?? 0, 0, ',', '.'), number_format($storage['receiptsCount'] ?? 0, 0, ',', '.'))],
    ];
@endphp
<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Salud de la plataforma" details="se actualiza cada 30 s">
        <x-slot:icon>
            <x-pulse::icons.sparkles />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        <ul class="grid grid-cols-1 gap-3 @lg:grid-cols-2 @3xl:grid-cols-3">
            @foreach ($checks as $check)
                <li class="flex items-start gap-3 rounded-xl bg-gray-50 dark:bg-gray-800/50 p-3">
                    <span @class([
                        'mt-1 h-2.5 w-2.5 shrink-0 rounded-full',
                        'bg-emerald-500' => $check['ok'] === true,
                        'bg-red-500' => $check['ok'] === false,
                        'bg-gray-400' => $check['ok'] === null,
                    ])></span>
                    <span class="flex min-w-0 flex-col">
                        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $check['label'] }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>

        @if (($reconciliation['discrepancies'] ?? 0) > 0)
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Para corregir: <code>{{ $reconciliation['command'] }}</code></p>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
