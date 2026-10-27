<x-mail::message>
@if (! empty($greeting))
# {{ $greeting }}
@elseif ($level === 'error')
# Algo salió mal
@endif

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-mail::button :url="$actionUrl" :color="match ($level) { 'success', 'error' => $level, default => 'primary' }">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
{{ $salutation }}
@endif

@isset($actionText)
<x-slot:subcopy>
Si el botón «{{ $actionText }}» no funciona, copia este enlace en tu navegador: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
